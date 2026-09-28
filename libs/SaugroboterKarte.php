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
    // Obergrenzen gegen manipulierte oder kaputte Kartendaten (echte Karten liegen weit darunter)
    const MAX_RAW = 8388608;      // entpackt höchstens 8 MB
    const MAX_SIDE = 4000;        // Zellen je Seite
    const MAX_CELLS = 6000000;    // Zellen gesamt
    const MAX_TRACK = 400000;     // Zeichen der gefahrenen Strecke

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
        if (strlen($bin) > self::MAX_RAW) return null;
        $raw = @gzuncompress($bin, self::MAX_RAW);
        if ($raw === false) $raw = @gzinflate($bin, self::MAX_RAW);
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
        if ($b['grid'] <= 0 || $b['grid'] > 1000 || ($b['type'] !== 'I' && $b['type'] !== 'P')) return null;
        if ($b['w'] < 0 || $b['h'] < 0 || $b['w'] > self::MAX_SIDE || $b['h'] > self::MAX_SIDE || $cells > self::MAX_CELLS) return null;
        if (strlen($raw) < self::HEADER + $cells) return null;
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

    /**
     * Differenzbild ('P') auf das letzte Vollbild legen. Das P-Bild enthält nur den geänderten
     * Ausschnitt; Roboter, Station und neue Strecke kommen aus dem P-Bild.
     * $v2: Kartenformat 2 (X60) – geänderte Zellen werden direkt ersetzt, sonst aufaddiert.
     */
    public static function Merge($base, $p, $v2)
    {
        $g = $base['grid'];
        if ($p['grid'] != $g) return $base;
        $left = min($base['left'], $p['left']);
        $top = min($base['top'], $p['top']);
        $right = max($base['left'] + $base['w'] * $g, $p['left'] + $p['w'] * $g);
        $bottom = max($base['top'] + $base['h'] * $g, $p['top'] + $p['h'] * $g);
        $w = intval(($right - $left) / $g);
        $h = intval(($bottom - $top) / $g);
        // Unplausibel großes Gesamtbild (Differenzbild weit außerhalb): verwerfen
        if ($w > self::MAX_SIDE || $h > self::MAX_SIDE || $w * $h > self::MAX_CELLS) return $base;
        $cells = str_repeat("\0", $w * $h);
        $ox = intval(($base['left'] - $left) / $g); $oy = intval(($base['top'] - $top) / $g);
        for ($y = 0; $y < $base['h']; $y++) {
            $cells = substr_replace($cells, substr($base['cells'], $y * $base['w'], $base['w']), ($oy + $y) * $w + $ox, $base['w']);
        }
        $ox = intval(($p['left'] - $left) / $g); $oy = intval(($p['top'] - $top) / $g);
        for ($y = 0; $y < $p['h']; $y++) {
            for ($x = 0; $x < $p['w']; $x++) {
                $n = ord($p['cells'][$y * $p['w'] + $x]);
                if ($n == 0) continue;
                $i = ($oy + $y) * $w + $ox + $x;
                $cells[$i] = chr($v2 ? $n : ((ord($cells[$i]) + $n) & 0xFF));
            }
        }
        $info = $base['info'];
        foreach ($p['info'] as $k => $v) {
            if ($k == 'tr') continue;
            $info[$k] = $v;
        }
        // Strecke fortschreiben
        if (!empty($p['info']['tr'])) {
            $info['tr'] = (isset($base['info']['tr']) ? $base['info']['tr'] : '') . $p['info']['tr'];
            if (strlen($info['tr']) > self::MAX_TRACK) $info['tr'] = substr($info['tr'], -self::MAX_TRACK);
        }
        return array_merge($base, [
            'frameId' => $p['frameId'], 'robot' => $p['robot'], 'dock' => $p['dock'],
            'w' => $w, 'h' => $h, 'left' => $left, 'top' => $top, 'cells' => $cells, 'info' => $info, 'type' => 'I'
        ]);
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

        // Ruhige Pastelltöne, die auf hellem und dunklem Hintergrund funktionieren
        $palette = [
            [0x8F, 0xB8, 0xEA], [0x92, 0xD4, 0xB0], [0xF1, 0xC8, 0x8E], [0xEB, 0xA9, 0xB6], [0xB9, 0xAB, 0xE8],
            [0x86, 0xD0, 0xCB], [0xF0, 0xB4, 0x96], [0xC9, 0xD9, 0x8F], [0xA6, 0xBD, 0xF2], [0xDD, 0xAE, 0xD6]
        ];
        $hl  = isset($opt['highlight']) ? intval($opt['highlight']) : 0;
        $sel = isset($opt['selected']) && is_array($opt['selected']) ? $opt['selected'] : [];
        $col = [];
        $wall = imagecolorallocate($img, 0x5B, 0x64, 0x72);
        $floor = imagecolorallocate($img, 0xC4, 0xCB, 0xD4);
        $edge = [];          // etwas dunklere Randfarbe je Raum
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
                        $on = ($c == $hl || in_array($c, $sel, true));
                        // ausgewählte Räume kräftiger (Richtung Akzentblau), sonst Pastell
                        if ($on) $p = [intval($p[0] * .55 + 0x4C * .45), intval($p[1] * .55 + 0x8D * .45), intval($p[2] * .55 + 0xFF * .45)];
                        $col[$c] = imagecolorallocate($img, $p[0], $p[1], $p[2]);
                        $edge[$c] = imagecolorallocate($img, intval($p[0] * .78), intval($p[1] * .78), intval($p[2] * .78));
                    }
                    $color = $col[$c];
                }
                $px = ($x - $x0) * $k;
                if ($k == 1) imagesetpixel($img, $px, $py, $color);
                else imagefilledrectangle($img, $px, $py, $px + $k - 1, $py + $k - 1, $color);
            }
        }
        // Feine Linien zwischen Räumen bzw. zur Außenkante (erst ab lesbarer Größe)
        if ($k >= 3) {
            for ($y = $y0; $y <= $y1; $y++) {
                $py = ($y1 - $y) * $k;
                for ($x = $x0; $x <= $x1; $x++) {
                    $c = self::Cell(ord($cells[$y * $w + $x]), $format);
                    if (!isset($edge[$c])) continue;
                    $px = ($x - $x0) * $k;
                    $r = $x < $x1 ? self::Cell(ord($cells[$y * $w + $x + 1]), $format) : 0;
                    $u = $y < $y1 ? self::Cell(ord($cells[($y + 1) * $w + $x]), $format) : 0;
                    $l = $x > $x0 ? self::Cell(ord($cells[$y * $w + $x - 1]), $format) : 0;
                    $d = $y > $y0 ? self::Cell(ord($cells[($y - 1) * $w + $x]), $format) : 0;
                    if ($r != $c && $r != self::WALL) imageline($img, $px + $k - 1, $py, $px + $k - 1, $py + $k - 1, $edge[$c]);
                    if ($l != $c && $l != self::WALL && !isset($edge[$l])) imageline($img, $px, $py, $px, $py + $k - 1, $edge[$c]);
                    if ($u != $c && $u != self::WALL) imageline($img, $px, $py, $px + $k - 1, $py, $edge[$c]);
                    if ($d != $c && $d != self::WALL && !isset($edge[$d])) imageline($img, $px, $py + $k - 1, $px + $k - 1, $py + $k - 1, $edge[$c]);
                }
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
            $pc = imagecolorallocatealpha($img, 0xFF, 0xFF, 0xFF, 45);
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

        $r = max(10, $k * 4);
        $white = imagecolorallocate($img, 0xFF, 0xFF, 0xFF);
        $shadow = imagecolorallocatealpha($img, 0x10, 0x14, 0x1A, 85);
        $blue = imagecolorallocate($img, 0x4C, 0x8D, 0xFF);

        // Station: grüner, abgerundeter Sockel mit weißem Rand
        list($sx, $sy) = $toPx($b['dock'][0], $b['dock'][1]);
        imagefilledellipse($img, $sx, $sy + 2, $r + 8, $r + 8, $shadow);
        imagefilledellipse($img, $sx, $sy, $r + 6, $r + 6, $white);
        imagefilledellipse($img, $sx, $sy, $r + 1, $r + 1, imagecolorallocate($img, 0x34, 0xC7, 0x7B));
        imagefilledrectangle($img, $sx - intval($r / 5), $sy - intval($r / 5), $sx + intval($r / 5), $sy + intval($r / 5), $white);

        // Roboter: Schatten, weißer Körper, blauer Ring, Blickrichtung als Punkt
        list($rx, $ry) = $toPx($b['robot'][0], $b['robot'][1]);
        $rr = $r + 8;
        imagefilledellipse($img, $rx, $ry + 3, $rr + 4, $rr + 4, $shadow);
        imagefilledellipse($img, $rx, $ry, $rr + 2, $rr + 2, $blue);
        imagefilledellipse($img, $rx, $ry, $rr - 3, $rr - 3, $white);
        $a = deg2rad(-$b['robot'][2]);
        $d = $rr / 2 - max(3, intval($rr / 5));
        $dot = max(3, intval($rr / 4));
        imagefilledellipse($img, intval($rx + cos($a) * $d), intval($ry + sin($a) * $d), $dot, $dot, $blue);

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
