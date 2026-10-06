<?php

/**
 * Saugroboter – Zugriff auf die Hersteller-Cloud (Dreamehome).
 *
 * Endpunkte, Kopfzeilen und App-Kennung entsprechen dem offengelegten Protokoll
 * (Tasshack/dreame-vacuum, MIT; Stand der App-Umstellung Ende September 2026: Dart-Client,
 * „dreame-meta“/„dreame-rlc“, signierte Anfragen). Befehle an den Roboter laufen als MiOT-Aufrufe
 * (get_properties / set_properties / action) über ".../device/sendCommand"; die Cloud
 * reicht sie an das Gerät weiter.
 *
 * Erwartet in der nutzenden Klasse: Properties Email, Password, Region, DeviceFilter,
 * VerifyTLS sowie die Attribute Token und Device (JSON).
 */
trait SaugroboterApi
{
    private static $DC_SALT  = 'RAylYC%fmSKp7%Tq';
    // Seit Ende September 2026 erwartet die Cloud die Kennung der aktuellen App: Dart-Client, App-Version
    // in „dreame-meta“, verschlüsselte Region in „dreame-rlc“ und signierte Anfragen (sign + timestamp).
    private static $DC_AGENT = 'Dart/3.9 (dart:io)';
    private static $DC_APPV  = '102060300';
    private static $DC_CID   = 'EETjszu*XI5znHsI';
    private static $DC_BASIC = 'Basic ZHJlYW1lX2FwcHYxOkFQXmR2QHpAU1FZVnhOODg=';
    private static $DC_PORT  = 13267;
    // Die Cloud nimmt je get_properties-Aufruf nur eine begrenzte Zahl Kennungen an
    private static $DC_BATCH = 15;

    private $dcLastError = '';
    // true, wenn die letzten Werte aus dem Cloud-Speicher statt direkt vom Roboter kamen
    protected $dcFromCache = false;
    protected $dcSkipDirect = false;
    protected $dcCacheTimes = [];      // Cloud-Speicher: Zeitpunkt je Wert (Sekunden), falls geliefert   // Abruf: Roboter antwortete zuletzt nicht direkt -> gleich Cloud-Speicher

    // ---- Anmeldung --------------------------------------------------------

    /**
     * Stellt ein gültiges Token sicher. Reihenfolge: Cache -> Refresh-Token -> Passwort.
     * $password = true erzwingt die Anmeldung mit Passwort (Button "Verbindung testen").
     */
    protected function CloudLogin($password = false)
    {
        $t = json_decode($this->ReadAttributeString('Token'), true);
        if (!$password && is_array($t) && !empty($t['access']) && intval($t['until']) > time() + 120) return true;

        $user = trim($this->ReadPropertyString('Email'));
        $pass = $this->ReadPropertyString('Password');
        if ($user === '' || $pass === '') { $this->dcLastError = 'Zugangsdaten fehlen.'; return false; }

        if (!$password && is_array($t) && !empty($t['refresh'])) {
            $r = $this->CloudTokenRequest('grant_type=refresh_token&scope=all&platform=ANDROID&type=account&refresh_token='
                . rawurlencode($t['refresh']), $t);
            if ($r) return true;
        }
        $body = 'grant_type=password&scope=all&platform=ANDROID&type=account&username=' . rawurlencode($user)
            . '&password=' . $this->PasswordHash($pass);
        if (is_array($t) && !empty($t['country']) && !empty($t['lang'])) $body .= '&country=' . rawurlencode($t['country']) . '&lang=' . rawurlencode($t['lang']);
        return $this->CloudTokenRequest($body, $t);
    }

    // Kopfzeilen wie die aktuelle App
    private function CloudHeaders($contentType, $old = null)
    {
        $t = is_array($old) ? $old : json_decode($this->ReadAttributeString('Token'), true);
        $vs = $this->ReadAttributeString('LiveVs');
        if (!preg_match('/^[0-9a-f]{32}$/', $vs)) { $vs = md5(random_bytes(16)); $this->WriteAttributeString('LiveVs', $vs); }
        $h = [
            'User-Agent: ' . self::$DC_AGENT,
            'dreame-meta: cv=a_' . self::$DC_APPV . ';canvasHash=' . substr(md5(trim($this->ReadPropertyString('Email')) . 'c'), 0, 8)
                . ';webglHash=' . substr(md5(trim($this->ReadPropertyString('Email')) . 'w'), 0, 8) . ';visitorIdHash=' . $vs,
            'Accept-Encoding: gzip'
        ];
        if (is_array($t) && !empty($t['region']) && !empty($t['lang']) && !empty($t['country'])) {
            $plain = $t['region'] . '|' . $t['lang'] . '|' . $t['country'];
            $enc = openssl_encrypt($plain, 'aes-128-ecb', self::$DC_CID, OPENSSL_RAW_DATA);   // PKCS#7-Auffüllung
            if ($enc !== false) $h[] = 'dreame-rlc: ' . base64_encode($enc);
        }
        $h[] = 'Tenant-Id: ' . (is_array($t) && !empty($t['tenant']) ? $t['tenant'] : '000000');
        $h[] = 'Authorization: ' . self::$DC_BASIC;
        $h[] = 'Content-Type: ' . $contentType;
        return $h;
    }

    // Signatur: md5(sortierte Felder + Zeitstempel in ms + App-Schlüssel), wie die App sie mitschickt
    private function CloudSigned(array $params)
    {
        $ms = (int) round(microtime(true) * 1000);
        $params['sign'] = md5(self::Spliced($params, true) . $ms . self::$DC_CID);
        $params['timestamp'] = $ms;
        return $params;
    }

    private static function Spliced(array $obj, $top)
    {
        $parts = [];
        $keys = array_keys($obj);
        sort($keys, SORT_STRING);
        $js = function ($v) { return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); };
        foreach ($keys as $k) {
            $v = $obj[$k];
            if (is_array($v) && $v !== [] && array_keys($v) !== range(0, count($v) - 1)) {
                $inner = self::Spliced($v, false);
                $parts[] = $inner !== '' ? $k . '=[' . $inner . ']' : $k . '=]';
            } elseif (is_object($v)) {
                $inner = self::Spliced((array) $v, false);
                $parts[] = $inner !== '' ? $k . '=[' . $inner . ']' : $k . '=]';
            } elseif (is_array($v)) {
                if ($top) $parts[] = $k . '=' . $js(self::SortDeep($v));
            } elseif (is_bool($v)) {
                $parts[] = $k . '=' . ($v ? 'true' : 'false');
            } elseif ($v === null) {
                $parts[] = $k . '=null';
            } elseif ($top) {
                $parts[] = $k . '=' . $v;
            } else {
                $parts[] = $k . '=' . $js($v);
            }
        }
        return implode('&', $parts);
    }

    private static function SortDeep($v)
    {
        if (!is_array($v)) return $v;
        $assoc = $v !== [] && array_keys($v) !== range(0, count($v) - 1);
        foreach ($v as $k => $x) $v[$k] = self::SortDeep($x);
        if ($assoc) ksort($v, SORT_STRING);
        return $v;
    }

    // Die Cloud erwartet md5(Passwort + Salz). Gespeichert wird nur dieser Wert ("hash:...").
    protected function PasswordHash($pass)
    {
        return strpos($pass, 'hash:') === 0 ? substr($pass, 5) : md5($pass . self::$DC_SALT);
    }

    private function CloudTokenRequest($grant, $old)
    {
        $headers = $this->CloudHeaders('application/x-www-form-urlencoded', is_array($old) ? $old : []);
        list($code, $body) = $this->CloudHttp('POST', $this->CloudBase() . '/dreame-auth/oauth/token', $headers, $grant);
        $d = json_decode($body, true);
        if ($code == 200 && is_array($d) && !empty($d['access_token'])) {
            $this->WriteAttributeString('Token', json_encode([
                'access'  => $d['access_token'],
                'refresh' => isset($d['refresh_token']) ? $d['refresh_token'] : '',
                'uid'     => isset($d['uid']) ? strval($d['uid']) : '',
                'tenant'  => isset($d['tenant_id']) ? strval($d['tenant_id']) : '000000',
                'region'  => isset($d['region']) ? strval($d['region']) : (is_array($old) ? strval($old['region'] ?? '') : ''),
                'lang'    => isset($d['lang']) ? strval($d['lang']) : (is_array($old) ? strval($old['lang'] ?? '') : ''),
                'country' => isset($d['country']) ? strval($d['country']) : (is_array($old) ? strval($old['country'] ?? '') : ''),
                'until'   => time() + (isset($d['expires_in']) ? intval($d['expires_in']) : 3600)
            ]));
            $this->SendDebug('Cloud', 'Anmeldung ok (' . (strpos($grant, 'refresh') === 0 ? 'Refresh' : 'Passwort') . ')', 0);
            return true;
        }
        if ($code > 0) {
            $this->dcLastError = 'Anmeldung abgelehnt: ' . (is_array($d) && isset($d['error_description']) ? $d['error_description'] : ('HTTP ' . $code));
        }
        $this->SendDebug('Cloud', $this->dcLastError, 0);
        return false;
    }

    private function CloudToken($key)
    {
        $t = json_decode($this->ReadAttributeString('Token'), true);
        return is_array($t) && isset($t[$key]) ? $t[$key] : '';
    }

    // ---- Anfragen -----------------------------------------------------------

    // JSON-Anfrage an die Cloud. Bei HTTP 401 einmal neu anmelden und wiederholen.
    protected function CloudCall($path, $payload, $again = true)
    {
        if (!$this->CloudLogin()) return null;
        $headers = $this->CloudHeaders('application/json');
        $headers[] = 'Dreame-Auth: ' . $this->CloudToken('access');
        // Anfragen mit Feldern werden signiert (wie die App); leere Anfragen gehen unverändert
        $send = is_array($payload) && $payload !== [] ? $this->CloudSigned($payload) : $payload;
        list($code, $body) = $this->CloudHttp('POST', $this->CloudBase() . '/' . $path, $headers, json_encode($send, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($code == 401 && $again) {
            $t = json_decode($this->ReadAttributeString('Token'), true);
            if (is_array($t)) { $t['until'] = 0; $this->WriteAttributeString('Token', json_encode($t)); }
            $this->SendDebug('Cloud', '401 – Token erneuern', 0);
            return $this->CloudCall($path, $payload, false);
        }
        if ($code != 200) {
            if ($code > 0) $this->dcLastError = 'Cloud antwortet mit HTTP ' . $code;
            return null;
        }
        $d = json_decode($body, true);
        if (!is_array($d)) { $this->dcLastError = 'Ungültige Antwort der Cloud'; return null; }
        return $d;
    }

    protected function CloudHttp($method, $url, $headers = [], $body = null, $timeout = 20)
    {
        $ch = curl_init($url);
        $verify = $this->ReadPropertyBoolean('VerifyTLS');
        $opt = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0
        ];
        // Automatisches Entpacken nur für die Cloud-Schnittstelle, nicht für Dateien (Größenlimit greift sonst nicht)
        if ($method == 'POST') { $opt[CURLOPT_POST] = true; $opt[CURLOPT_POSTFIELDS] = $body; $opt[CURLOPT_ENCODING] = ''; }
        // Nur HTTPS (auch Download-Adressen kommen aus der Cloud) und höchstens 20 MB Antwort
        if (defined('CURLOPT_PROTOCOLS')) { $opt[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS; $opt[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS; }
        $opt[CURLOPT_MAXFILESIZE] = 20971520;
        $opt[CURLOPT_NOPROGRESS] = false;
        $opt[CURLOPT_PROGRESSFUNCTION] = function ($ch, $dlTotal, $dl) { return $dl > 20971520 ? 1 : 0; };
        curl_setopt_array($ch, $opt);
        // Verschlüsselung in der Reihenfolge der App anbieten (TLS 1.3: ChaCha20 zuerst). Einzeln gesetzt:
        // kennt die curl-Version eine Option nicht, bleibt alles andere wirksam.
        if (defined('CURLOPT_TLS13_CIPHERS')) @curl_setopt($ch, CURLOPT_TLS13_CIPHERS, 'TLS_CHACHA20_POLY1305_SHA256:TLS_AES_128_GCM_SHA256:TLS_AES_256_GCM_SHA384');
        @curl_setopt($ch, CURLOPT_SSL_CIPHER_LIST, 'ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:ECDHE-ECDSA-AES128-GCM-SHA256:'
            . 'ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-AES128-SHA:ECDHE-RSA-AES128-SHA:'
            . 'ECDHE-ECDSA-AES256-SHA:ECDHE-RSA-AES256-SHA:AES128-GCM-SHA256:AES256-GCM-SHA384:AES128-SHA:AES256-SHA');
        $res = curl_exec($ch);
        $code = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        unset($ch);   // curl_close() ist seit PHP 8.0 wirkungslos und ab 8.5 veraltet
        if ($res === false) {
            $this->dcLastError = in_array($errno, [35, 51, 58, 60, 77], true)
                ? 'Zertifikatsprüfung fehlgeschlagen (' . $err . ') – Systemzeit und CA-Zertifikate des Symcon-Systems prüfen. „TLS-Zertifikate prüfen“ nur als letzten Ausweg abschalten: dann sind Token und Passwort-Hash nicht mehr vor Mitlesern geschützt.'
                : 'Netzwerkfehler: ' . $err;
            $this->SendDebug('HTTP', $method . ' ' . strtok($url, '?') . ' -> ' . $this->dcLastError, 0);
            return [0, ''];
        }
        if ($code != 200) $this->SendDebug('HTTP', $method . ' ' . strtok($url, '?') . ' -> ' . $code . ' ' . substr($res, 0, 200), 0);
        return [$code, $res];
    }

    private function CloudBase()
    {
        return 'https://' . $this->CloudRegion() . '.iot.dreame.tech:' . self::$DC_PORT;
    }

    // Nur bekannte Regionen – Zugangsdaten gehen nie an einen frei eingetragenen Server
    private function CloudRegion()
    {
        $r = strtolower(trim($this->ReadPropertyString('Region')));
        return in_array($r, ['eu', 'us', 'cn', 'sg', 'kr', 'ru'], true) ? $r : 'eu';
    }

    // ---- Geräte -------------------------------------------------------------

    // Alle Saugroboter im Konto: [[did, model, name, host], ...] oder null
    protected function CloudDevices()
    {
        $d = $this->CloudCall('dreame-user-iot/iotuserbind/device/listV2', new stdClass());
        if (!isset($d['data']['page']['records']) || !is_array($d['data']['page']['records'])) return null;
        $out = [];
        foreach ($d['data']['page']['records'] as $r) {
            if (empty($r['did']) || empty($r['model'])) continue;
            $name = !empty($r['customName']) ? $r['customName']
                : (isset($r['deviceInfo']['displayName']) ? $r['deviceInfo']['displayName'] : $r['model']);
            $out[] = [
                'did' => strval($r['did']), 'model' => strval($r['model']), 'name' => strval($name),
                'host' => isset($r['bindDomain']) ? strval($r['bindDomain']) : '',
                'master' => isset($r['masterUid']) ? strval($r['masterUid']) : '',
                'vacuum' => strpos($r['model'], '.vacuum.') !== false,
                'online' => isset($r['online']) ? (bool)$r['online'] : null
            ];
        }
        return $out;
    }

    // Das gesteuerte Gerät (gecacht). Filter: did, Teil des Modells oder des Namens.
    protected function CloudDevice($refresh = false)
    {
        if (!$refresh) {
            $dev = json_decode($this->ReadAttributeString('Device'), true);
            if (is_array($dev) && !empty($dev['did'])) return $dev;
        }
        $list = $this->CloudDevices();
        if ($list === null) return null;
        $filter = mb_strtolower(trim($this->ReadPropertyString('DeviceFilter')));
        $hits = [];
        foreach ($list as $dev) {
            $hit = $filter === '' ? $dev['vacuum']
                : ($dev['did'] === $filter || strpos(mb_strtolower($dev['model']), $filter) !== false
                    || strpos(mb_strtolower($dev['name']), $filter) !== false);
            if ($hit) $hits[] = $dev;
        }
        // Mehrere Treffer (z. B. alter Roboter noch im Konto): den erreichbaren nehmen
        usort($hits, function ($a, $b) { return intval($b['online'] === true) - intval($a['online'] === true); });
        if (count($hits)) {
            $this->WriteAttributeString('Device', json_encode($hits[0]));
            return $hits[0];
        }
        $this->dcLastError = $filter === '' ? 'Kein Saugroboter im Konto gefunden.' : 'Kein Gerät passt zum Filter „' . $filter . '“.';
        return null;
    }

    private function CloudCommand($method, $params)
    {
        $dev = $this->CloudDevice();
        if ($dev === null) return null;
        $host = $dev['host'] !== '' ? '-' . explode('.', $dev['host'])[0] : '';
        // get/set_properties: Liste von Einträgen; action: ein einzelnes Objekt
        if (isset($params['siid'])) {
            $params['did'] = $dev['did'];
        } else {
            foreach ($params as &$p) $p['did'] = $dev['did'];
            unset($p);
        }
        $payload = [
            'did' => $dev['did'], 'id' => mt_rand(1, 9999),
            'data' => ['did' => $dev['did'], 'id' => mt_rand(1, 9999), 'method' => $method, 'params' => $params]
        ];
        // Ein Zeitüberschreiten kommt gelegentlich einmalig vor (Roboter wacht gerade auf) -> ein Wiederholungsversuch
        for ($try = 0; $try < 2; $try++) {
            $d = $this->CloudCall('dreame-iot-com' . $host . '/device/sendCommand', $payload);
            if ($d === null) return null;
            if (isset($d['data']['result'])) return $d['data']['result'];
            $this->dcLastError = 'Roboter antwortet nicht direkt (' . $this->CloudMessage($d) . ').';
            $this->SendDebug('MiOT', $method . ': ' . json_encode($d, JSON_UNESCAPED_UNICODE), 0);
            if ($try == 0) IPS_Sleep(1500);
        }
        return null;
    }

    // Meldungen der Cloud kommen teils auf Chinesisch – die bekannten übersetzen
    private function CloudMessage($d)
    {
        $m = isset($d['msg']) ? strval($d['msg']) : '';
        if ($m === '') return 'keine Rückmeldung';
        if (strpos($m, '不在线') !== false || strpos($m, 'offline') !== false) return 'Gerät laut Cloud offline oder Zeitüberschreitung';
        if (strpos($m, '超时') !== false || stripos($m, 'timeout') !== false) return 'Zeitüberschreitung';
        if (preg_match('/\p{Han}/u', $m)) return 'Cloud-Fehler ' . (isset($d['code']) ? $d['code'] : '');
        return $m;
    }

    // Letzte vom Roboter gemeldete Werte aus dem Speicher der Cloud. Funktioniert auch, wenn der
    // Roboter gerade nicht direkt antwortet (z. B. im Energiesparmodus an der Station).
    protected function CloudCachedProps($keys)
    {
        $dev = $this->CloudDevice();
        if ($dev === null) return null;
        $list = [];
        foreach ($keys as $k) $list[] = intval($k[0]) . '.' . intval($k[1]);
        $d = $this->CloudCall('dreame-user-iot/iotstatus/props', ['did' => $dev['did'], 'keys' => implode(',', $list)]);
        if (!isset($d['data']) || !is_array($d['data'])) return null;
        $out = [];
        $this->dcCacheTimes = [];
        foreach ($d['data'] as $k => $e) {
            if (is_array($e) && isset($e['key'])) {
                $key = strval($e['key']); $val = isset($e['value']) ? $e['value'] : null;
                foreach (['updateTime', 'update_time', 'time', 'ts'] as $tk) {
                    if (isset($e[$tk]) && is_numeric($e[$tk]) && $e[$tk] > 0) { $t = floatval($e[$tk]); $this->dcCacheTimes[$key] = $t > 1e11 ? $t / 1000 : $t; break; }
                }
            }
            else { $key = strval($k); $val = $e; }
            if ($val === null || !preg_match('/^\d+\.\d+$/', $key)) continue;
            $out[$key] = $val;
        }
        $this->SendDebug('Cloud-Speicher', json_encode($out, JSON_UNESCAPED_UNICODE), 0);
        return count($out) ? $out : null;
    }

    // ---- MiOT ---------------------------------------------------------------

    // $keys = [[siid, piid], ...] -> ['siid.piid' => Wert] (fehlende Werte fehlen im Ergebnis)
    protected function MiotGet($keys)
    {
        $out = [];
        $any = false;
        $this->dcFromCache = false;
        foreach ($this->dcSkipDirect ? [] : array_chunk($keys, self::$DC_BATCH) as $i => $chunk) {
            $params = [];
            foreach ($chunk as $k) $params[] = ['siid' => intval($k[0]), 'piid' => intval($k[1])];
            $res = $this->CloudCommand('get_properties', $params);
            if (!is_array($res)) {
                if ($i == 0) break;      // erstes Paket ohne Antwort: Roboter nicht erreichbar, Rest sparen
                continue;
            }
            $any = true;
            foreach ($res as $r) {
                if (isset($r['code']) && intval($r['code']) == 0 && array_key_exists('value', $r)) {
                    $out[$r['siid'] . '.' . $r['piid']] = $r['value'];
                }
            }
        }
        if ($any) return $out;
        // Rückfall: letzte bekannte Werte aus dem Cloud-Speicher
        $cached = $this->CloudCachedProps($keys);
        if ($cached !== null) $this->dcFromCache = true;
        return $cached;
    }

    // $items = [[siid, piid, Wert], ...] -> true nur, wenn das Gerät jeden Wert angenommen hat
    protected function MiotSet($items)
    {
        $params = [];
        foreach ($items as $i) $params[] = ['siid' => $i[0], 'piid' => $i[1], 'value' => $i[2]];
        $res = $this->CloudCommand('set_properties', $params);
        if (!is_array($res)) return false;
        foreach ($res as $r) {
            if (isset($r['code']) && intval($r['code']) != 0) {
                $this->dcLastError = 'Gerät lehnt ' . $r['siid'] . '/' . $r['piid'] . ' ab (Code ' . $r['code'] . ').';
                $this->SendDebug('MiOT', $this->dcLastError, 0);
                return false;
            }
        }
        return true;
    }

    // Wie MiotAction, liefert aber die Antwort des Geräts (u. a. "out") oder null
    protected function MiotActionResult($siid, $aiid, $in = [])
    {
        $res = $this->CloudCommand('action', ['siid' => $siid, 'aiid' => $aiid, 'in' => $in]);
        if (!is_array($res) || (isset($res['code']) && intval($res['code']) != 0)) return null;
        return $res;
    }

    protected function MiotAction($siid, $aiid, $in = [])
    {
        $res = $this->CloudCommand('action', ['siid' => $siid, 'aiid' => $aiid, 'in' => $in]);
        if (!is_array($res)) return false;
        if (isset($res['code']) && intval($res['code']) != 0) {
            $this->dcLastError = 'Befehl ' . $siid . '/' . $aiid . ' abgelehnt (Code ' . $res['code'] . ').';
            return false;
        }
        return true;
    }

    // ---- Dateien & Ereignisse -------------------------------------------------

    // Lädt eine Gerätedatei (Karten, Reinigungsprotokolle) über eine kurzlebige Download-URL.
    protected function CloudFile($objectName)
    {
        $dev = $this->CloudDevice();
        if ($dev === null || $objectName === '') return null;
        $d = $this->CloudCall('dreame-user-iot/iotfile/getDownloadUrl', [
            'did' => $dev['did'], 'model' => $dev['model'], 'filename' => $objectName, 'region' => $this->CloudRegion()
        ]);
        $url = '';
        if (isset($d['data']) && is_string($d['data'])) $url = $d['data'];
        elseif (isset($d['data']['url'])) $url = $d['data']['url'];
        if ($url === '') return null;
        list($code, $body) = $this->CloudHttp('GET', $url, [], null, 25);
        return $code == 200 ? $body : null;
    }

    // Letzte Ereignisse eines Dienstes (Reinigungsabschlüsse u. a.)
    protected function CloudEvents($siid, $eiid, $limit)
    {
        $dev = $this->CloudDevice();
        if ($dev === null) return null;
        $d = $this->CloudCall('dreame-user-iot/iotstatus/history', [
            'uid' => $this->CloudToken('uid'), 'did' => $dev['did'], 'from' => 1687019188, 'limit' => $limit,
            'siid' => $siid, 'eiid' => $eiid, 'region' => $this->CloudRegion(), 'type' => 3
        ]);
        return isset($d['data']['list']) && is_array($d['data']['list']) ? $d['data']['list'] : null;
    }
}
