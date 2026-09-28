<?php

/**
 * Saugroboter – Kartendaten dekodieren und als Bild zeichnen.
 *
 * Aufbau eines Kartenblocks (little endian):
 *   0 Karten-ID (int16)   2 Frame-ID (int16)   4 Frame-Typ ('I' Vollbild / 'P' Differenz)
 *   5/7/9  Roboter x/y/Winkel   11/13/15 Station x/y/Winkel
 *   17 Rastergröße (mm)   19/21 Breite/Höhe (Zellen)   23/25 Ursprung links/oben (mm)
 *   27 … ein Byte je Rasterzelle, danach ein JSON-Anhang (Räume, Pfade, Einstellungen).
 * Übertragen wird der Block zlib-komprimiert und URL-sicher base64-kodiert. Steht hinter
 * dem base64-Text ",<schlüssel>", ist er zusätzlich AES-256-CBC-verschlüsselt.
 *
 * Wie die Rasterbytes zu lesen sind, hängt vom Modell ab. Statt eine Modellliste zu pflegen,
 * probiert Detect() die bekannten Formate aus und nimmt das, bei dem die meisten Zellen auf
 * Räume fallen, die der JSON-Anhang auch kennt.
 */
class SaugroboterKarte
{
    const HEADER = 27;

    // Modelle mit Kartenformat 2 (u. a. alle X60-Varianten): Vollbilder im Format 'low5'.
    // Quelle: Geräteliste von Tasshack/dreame-vacuum (Fähigkeit MAP_V2).
    const MAP_V2_MODELS = ['r500c', 'r500c1', 'r501da', 'r501w', 'r501wu', 'r5089b', 'r5089u', 'r5090a', 'r5104h',
        'r510c', 'r510c1', 'r512g', 'r5189j', 'r5189u', 'r520c', 'r520c1', 'r6001a', 'r6011', 'r6012', 'r6015a',
        'r6111', 'r6112', 'r9515a', 'r9515e'];

    // Zellarten nach dem Dekodieren
    const WALL = 255;
    const FLOOR = 254;

    /**
     * Rohtext -> Block (Array) oder null.
     * $iv: AES-IV des Modells (bei den aktuellen X-Modellen einheitlich, siehe Modul).
     */
    public static function Decode($text, $iv = '')
    {
        $text = trim(strval($text));
        if (strlen($text) < 8) return null;
        $key = null;
        $comma = strpos($text, ',');
        if ($comma !== false) {
            $key = substr($text, $comma + 1);
            $text = substr($text, 0, $comma);
        }
        $bin = base64_decode(strtr($text, '-_', '+/'));
        if ($bin === false || $bin === '') return null;

        if ($key !== null && $key !== '') {
            if (!function_exists('openssl_decrypt')) return null;
            $aesKey = substr(hash('sha256', $key), 0, 32);
            $plain = openssl_decrypt($bin, 'aes-256-cbc', $aesKey, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, str_pad(substr($iv, 0, 16), 16, "\0"));
            if ($plain === false) return null;
            $bin = $plain;
        }
        $raw = @gzuncompress($bin);
        if ($raw === false) $raw = @gzinflate($bin);
        if ($raw === false || strlen($raw) < self::HEADER) return null;

        $b = [
            'mapId' => self::I16($raw, 0),
            'frameId' => self::I16($raw, 2),
            'type' => $raw[4],
            'robot' => [self::I16($raw, 5), self::I16($raw, 7), self::I16($raw, 9)],
            'dock' => [self::I16($raw, 11), self::I16($raw, 13), self::I16($raw, 15)],
            'grid' => self::I16($raw, 17),
            'w' => self::I16($raw, 19),
            'h' => self::I16($raw, 21),
            'left' => self::I16($raw, 23),
            'top' => self::I16($raw, 25)
        ];
        $cells = $b['w'] * $b['h'];
        if ($b['grid'] <= 0 || $b['w'] < 0 || $b['h'] < 0 || strlen($raw) < self::HEADER + $cells) return null;
        $b['cells'] = substr($raw, self::HEADER, $cells);
        $json = json_decode(substr($raw, self::HEADER + $cells), true);
        $b['info'] = is_array($json) ? $json : [];
        return $b;
    }

    private static function I16($s, $o)
    {
        $v = unpack('v', substr($s, $o, 2))[1];
        return $v >= 0x8000 ? $v - 0x10000 : $v;
    }

    // ---- Zellformate -------------------------------------------------------
    // Rückgabe je Byte: 0 = leer, 1..62 = Raum, WALL, FLOOR (Boden ohne Raumzuordnung)

    // 'shift': Raum in Bit 2..7 (61 unbekannt, 62 Boden, 63 Wand)
    // 'low6' : Raum in Bit 0..5, Bit 7 = Wand/Rand
    // 'low5' : Raum in Bit 0..4 (31 = Sonderwert), Bit 5..6 = Wand
    public static function Cell($byte, $format, $area = false)
    {
        if ($byte == 0) return 0;
        if ($format == 'shift') {
            $s = $byte >> 2;
            if ($s == 63) return self::WALL;
            if ($s == 62) return self::FLOOR;
            if ($s == 61 || $s == 0) return 0;
            return $s;
        }
        if ($format == 'low5') {
            // Bit 7 = Teppich, Bit 5..6 = Art: 0 = Raumfläche, 1/2 = Wand am Raum, 3 = Fläche, die
            // die App nicht zeichnet (unerkundet/verdeckt, gehört aber zum Raum). 31 = kein Raum.
            // $area = true: Zugehörigkeit zum Raum (für "aktueller Raum"), nicht die Darstellung.
            $s = $byte & 0x1F;
            $kind = ($byte >> 5) & 0x03;
            if ($s == 0) return 0;
            if ($s == 31) return $kind == 3 ? 0 : ($kind > 0 ? self::WALL : self::FLOOR);
            if ($kind == 3) return $area ? $s : 0;
            if ($kind > 0) return $area ? $s : self::WALL;
            return $s;
        }
        // low6
        $s = $byte & 0x3F;
        if ($byte >> 7) return $s ? $s : self::WALL;
        return $s;
    }

    // Wählt das Zellformat. "fsm" = 1 im Anhang kennzeichnet das Schiebeformat eindeutig.
    /**
     * $extra: bekannte Raumnummern (z. B. aus der gespeicherten Karte), falls der Block selbst keine nennt.
     * Rückgabe 'shift' | 'low6' | 'low5'.
     */
    public static function Detect($b, $extra = [])
    {
        $s = self::FormatScores($b, $extra);
        // "fsm" deutet auf das Schiebeformat – aber nur, wenn die Zahlen nicht klar dagegen sprechen
        // (Kartenformat-2-Geräte setzen fsm ebenfalls).
        if (!empty($b['info']['fsm']) && $s['shift'] >= max($s['low6'], $s['low5']) * 0.5) return 'shift';
        arsort($s);
        reset($s);
        return key($s);
    }

    // Bewertung je Zellformat (auch für die Diagnose)
    public static function FormatScores($b, $extra = [])
    {
        $known = [];
        if (isset($b['info']['seg_inf']) && is_array($b['info']['seg_inf'])) {
            foreach (array_keys($b['info']['seg_inf']) as $k) $known[intval($k)] = true;
        }
        foreach ($extra as $k) $known[intval($k)] = true;
        $n = strlen($b['cells']);
        $step = max(1, intval($n / 40000));   // Stichprobe reicht
        $scores = [];
        foreach (['shift', 'low6', 'low5'] as $f) {
            $hist = [];
            $room = 0;
            for ($i = 0; $i < $n; $i += $step) {
                $c = self::Cell(ord($b['cells'][$i]), $f, true);
                if ($c > 0 && $c < 250) { $room++; $hist[$c] = (isset($hist[$c]) ? $hist[$c] : 0) + 1; }
            }
            if (count($known)) {
                // Treffer auf bekannte Räume zählen, unbekannte Nummern bestrafen
                $score = 0;
                foreach ($hist as $c => $cnt) $score += isset($known[$c]) ? 2 * $cnt : -$cnt;
            } else {
                // Ohne Vorwissen: echte Karten haben wenige, große Räume. Kleinstflächen
                // (unter 0,5 % der Raumfläche) deuten auf ein falsch gelesenes Format.
                $score = 0;
                foreach ($hist as $c => $cnt) $score += ($room > 0 && $cnt / $room >= 0.005) ? $cnt : -3 * $cnt;
                if (count($hist) > 40) $score -= $room;
            }
            $scores[$f] = $score;
        }
        return $scores;
    }

    // Räume aus dem Anhang: [seg => ['name' => ..., 'type' => ..., 'hidden' => bool]]
    public static function Rooms($b, $roomTypes)
    {
        $out = [];
        if (!isset($b['info']['seg_inf']) || !is_array($b['info']['seg_inf'])) return $out;
        $hidden = isset($b['info']['delsr']) && is_array($b['info']['delsr']) ? array_map('intval', $b['info']['delsr']) : [];
        $ids = array_map('intval', array_keys($b['info']['seg_inf']));
        sort($ids);
        foreach ($ids as $seg) {
            $s = $b['info']['seg_inf'][strval($seg)];
            $type = isset($s['type']) ? intval($s['type']) : 0;
            $index = isset($s['index']) ? intval($s['index']) : 0;
            $custom = '';
            if (!empty($s['name'])) {
                $dec = base64_decode(strval($s['name']), true);
                $custom = ($dec !== false && mb_check_encoding($dec, 'UTF-8')) ? trim($dec) : trim(strval($s['name']));
            }
            // Wie in der App: fester Raumtyp vor eigenem Namen
            if ($type > 0 && isset($roomTypes[$type])) $name = $roomTypes[$type] . ($index > 0 ? ' ' . ($index + 1) : '');
            elseif ($custom !== '') $name = $custom;
            else $name = 'Raum ' . $seg;
            $out[$seg] = ['name' => $name, 'type' => $type, 'hidden' => in_array($seg, $hidden, true)];
        }
        return $out;
    }

    // Rasterzelle einer Kartenkoordinate (mm) oder null
    public static function CellAt($b, $x, $y)
    {
        $cx = intval(floor(($x - $b['left']) / $b['grid']));
        $cy = intval(floor(($y - $b['top']) / $b['grid']));
        if ($cx < 0 || $cy < 0 || $cx >= $b['w'] || $cy >= $b['h']) return null;
        return [$cx, $cy];
    }

    // Raum unter dem Roboter: >0 Raum, 0 kein Raum, -1 außerhalb
    public static function RobotRoom($b, $format)
    {
        $c = self::CellAt($b, $b['robot'][0], $b['robot'][1]);
        if ($c === null) return -1;
        // Der Roboter steht oft auf einer Wand-/Randzelle -> kleine Umgebung mit auswerten
        $votes = [];
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                $x = $c[0] + $dx; $y = $c[1] + $dy;
                if ($x < 0 || $y < 0 || $x >= $b['w'] || $y >= $b['h']) continue;
                $v = self::Cell(ord($b['cells'][$y * $b['w'] + $x]), $format, true);
                if ($v > 0 && $v < 250) $votes[$v] = (isset($votes[$v]) ? $votes[$v] : 0) + (($dx == 0 && $dy == 0) ? 3 : 1);
            }
        }
        if (count($votes) == 0) return 0;
        arsort($votes);
        reset($votes);
        return intval(key($votes));
    }

    /**
     * Zeichnet den Block als PNG. $opt: rotate (0/90/180/270), highlight (Raum), size (px),
     * selected (Liste Räume), path (bool). Rückgabe PNG-Binärdaten oder null.
     */
    // Sichtbarer Ausschnitt (Rasterzellen) mit kleinem Rand: [x0, y0, x1, y1]; x1 < 0 = leer
    public static function Bounds($b, $format)
    {
        $w = $b['w']; $h = $b['h']; $cells = $b['cells'];
        $x0 = $w; $y0 = $h; $x1 = -1; $y1 = -1;
        for ($y = 0; $y < $h; $y++) {
            $row = $y * $w;
            for ($x = 0; $x < $w; $x++) {
                if (self::Cell(ord($cells[$row + $x]), $format) == 0) continue;
                if ($x < $x0) $x0 = $x;
                if ($x > $x1) $x1 = $x;
                if ($y < $y0) $y0 = $y;
                if ($y > $y1) $y1 = $y;
            }
        }
        if ($x1 < 0) return [0, 0, -1, -1];
        return [max(0, $x0 - 3), max(0, $y0 - 3), min($w - 1, $x1 + 3), min($h - 1, $y1 + 3)];
    }

    /**
     * Lage der Räume im gezeichneten Bild – für Beschriftung und Antippen in der Kachel.
     * Koordinaten als Anteil (0..1) des fertigen, ggf. gedrehten Bildes.
     * grid: grobes Raster des ungedrehten Bildes, je Zeichen ein Raum (chr(48 + Nr.)) oder '.'.
     */
    public static function Layout($b, $format, $rotate = 0)
    {
        list($x0, $y0, $x1, $y1) = self::Bounds($b, $format);
        if ($x1 < 0) return null;
        $cw = $x1 - $x0 + 1; $ch = $y1 - $y0 + 1;
        $w = $b['w']; $cells = $b['cells'];
        $rot = ($rotate % 360 + 360) % 360;
        $sum = [];
        for ($y = $y0; $y <= $y1; $y++) {
            for ($x = $x0; $x <= $x1; $x++) {
                $c = self::Cell(ord($cells[$y * $w + $x]), $format, true);
                if ($c <= 0 || $c >= 250) continue;
                if (!isset($sum[$c])) $sum[$c] = [0, 0, 0];
                $sum[$c][0] += $x; $sum[$c][1] += $y; $sum[$c][2]++;
            }
        }
        $rooms = [];
        foreach ($sum as $c => $v) {
            if ($v[2] < 20) continue;   // Krümel nicht beschriften
            // Bild ist vertikal gespiegelt (Kartenachse nach oben)
            $fx = (($v[0] / $v[2]) - $x0 + 0.5) / $cw;
            $fy = ($y1 - ($v[1] / $v[2]) + 0.5) / $ch;
            $rooms[$c] = self::Rotate($fx, $fy, $rot);
        }
        $gw = min(120, $cw); $gh = max(1, intval(round($ch * $gw / $cw)));
        $grid = '';
        for ($gy = 0; $gy < $gh; $gy++) {
            for ($gx = 0; $gx < $gw; $gx++) {
                $x = $x0 + intval(($gx + 0.5) * $cw / $gw);
                $y = $y1 - intval(($gy + 0.5) * $ch / $gh);
                $c = self::Cell(ord($cells[$y * $w + $x]), $format, true);
                $grid .= ($c > 0 && $c < 63) ? chr(48 + $c) : '.';
            }
        }
        return ['mapId' => $b['mapId'], 'rot' => $rot, 'rooms' => $rooms, 'gw' => $gw, 'gh' => $gh, 'grid' => $grid];
    }

    // Anteilskoordinaten im Uhrzeigersinn drehen (passend zu Render)
    public static function Rotate($fx, $fy, $rot)
    {
        if ($rot == 90) return [round(1 - $fy, 4), round($fx, 4)];
        if ($rot == 180) return [round(1 - $fx, 4), round(1 - $fy, 4)];
        if ($rot == 270) return [round($fy, 4), round(1 - $fx, 4)];
        return [round($fx, 4), round($fy, 4)];
    }

    public static function Render($b, $format, $opt = [])
    {
        if (!function_exists('imagecreatetruecolor') || $b['w'] <= 0 || $b['h'] <= 0) return null;
        $w = $b['w']; $h = $b['h']; $cells = $b['cells'];

        list($x0, $y0, $x1, $y1) = self::Bounds($b, $format);
        if ($x1 < 0) return null;
        $cw = $x1 - $x0 + 1; $ch = $y1 - $y0 + 1;
        $size = isset($opt['size']) ? intval($opt['size']) : 560;
        $k = max(1, min(10, intval(floor($size / max($cw, $ch)))));

        $img = imagecreatetruecolor($cw * $k, $ch * $k);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));

        $palette = [
            [0x6C, 0x9B, 0xC9], [0x79, 0xB8, 0x8E], [0xD9, 0xA8, 0x5B], [0xC7, 0x84, 0x8F], [0x93, 0x86, 0xC4],
            [0x5F, 0xB3, 0xAB], [0xD0, 0x8C, 0x6A], [0xA8, 0xAE, 0x62], [0x7E, 0x94, 0xD6], [0xBC, 0x8A, 0xB6]
        ];
        $hl  = isset($opt['highlight']) ? intval($opt['highlight']) : 0;
        $sel = isset($opt['selected']) && is_array($opt['selected']) ? $opt['selected'] : [];
        $col = [];
        $wall = imagecolorallocate($img, 0x4A, 0x50, 0x5A);
        $floor = imagecolorallocate($img, 0xA9, 0xB0, 0xB9);
        for ($y = $y0; $y <= $y1; $y++) {
            $row = $y * $w;
            $py = ($y1 - $y) * $k;                 // Kartenachse zeigt nach oben
            for ($x = $x0; $x <= $x1; $x++) {
                $c = self::Cell(ord($cells[$row + $x]), $format);
                if ($c == 0) continue;
                if ($c == self::WALL) $color = $wall;
                elseif ($c == self::FLOOR) $color = $floor;
                else {
                    if (!isset($col[$c])) {
                        $p = $palette[($c - 1) % count($palette)];
                        $f = ($c == $hl || in_array($c, $sel, true)) ? 1.12 : 0.9;
                        $col[$c] = imagecolorallocate($img, min(255, intval($p[0] * $f)), min(255, intval($p[1] * $f)), min(255, intval($p[2] * $f)));
                    }
                    $color = $col[$c];
                }
                $px = ($x - $x0) * $k;
                if ($k == 1) imagesetpixel($img, $px, $py, $color);
                else imagefilledrectangle($img, $px, $py, $px + $k - 1, $py + $k - 1, $color);
            }
        }
        imagealphablending($img, true);

        $toPx = function ($mx, $my) use ($b, $x0, $y1, $k) {
            return [
                intval((($mx - $b['left']) / $b['grid'] - $x0) * $k),
                intval(($y1 - ($my - $b['top']) / $b['grid']) * $k)
            ];
        };

        // Gefahrene Strecke aus dem Anhang "tr": M/S/W = neuer Abschnitt (absolut),
        // L = Linie relativ zum letzten Punkt, l = Linie zu einem absoluten Punkt.
        if (!empty($opt['path']) && isset($b['info']['tr']) && is_string($b['info']['tr'])
            && preg_match_all('/([MWSLl])(-?\d+),(-?\d+)/', $b['info']['tr'], $m, PREG_SET_ORDER)) {
            $pc = imagecolorallocatealpha($img, 0xFF, 0xFF, 0xFF, 60);
            imagesetthickness($img, max(1, intval($k / 2)));
            $cx = 0; $cy = 0; $last = null;
            foreach ($m as $seg) {
                if ($seg[1] == 'L') { $cx += intval($seg[2]); $cy += intval($seg[3]); }
                else { $cx = intval($seg[2]); $cy = intval($seg[3]); }
                $p = $toPx($cx, $cy);
                if (($seg[1] == 'L' || $seg[1] == 'l') && $last !== null) imageline($img, $last[0], $last[1], $p[0], $p[1], $pc);
                $last = $p;
            }
            imagesetthickness($img, 1);
        }

        $r = max(8, $k * 4);
        $dark = imagecolorallocate($img, 0x1C, 0x22, 0x2B);
        list($sx, $sy) = $toPx($b['dock'][0], $b['dock'][1]);
        imagefilledellipse($img, $sx, $sy, $r + 4, $r + 4, $dark);
        imagefilledellipse($img, $sx, $sy, $r, $r, imagecolorallocate($img, 0x3F, 0xB9, 0x7A));

        list($rx, $ry) = $toPx($b['robot'][0], $b['robot'][1]);
        imagefilledellipse($img, $rx, $ry, $r + 8, $r + 8, $dark);
        imagefilledellipse($img, $rx, $ry, $r + 4, $r + 4, imagecolorallocate($img, 0xF5, 0xF7, 0xFA));
        // Blickrichtung
        $a = deg2rad(-$b['robot'][2]);
        imageline($img, $rx, $ry, intval($rx + cos($a) * ($r / 2 + 2)), intval($ry + sin($a) * ($r / 2 + 2)), $dark);

        $rot = isset($opt['rotate']) ? (intval($opt['rotate']) % 360 + 360) % 360 : 0;
        if ($rot != 0) {
            $t = imagecolorallocatealpha($img, 0, 0, 0, 127);
            $img2 = imagerotate($img, -$rot, $t);   // imagerotate dreht gegen den Uhrzeigersinn
            imagedestroy($img);
            $img = $img2;
            imagesavealpha($img, true);
        }

        ob_start();
        imagepng($img, null, 6);
        $png = ob_get_clean();
        imagedestroy($img);
        return $png;
    }
}
