<?php

/**
 * Saugroboter – Zugriff auf die Hersteller-Cloud (Dreamehome).
 *
 * Endpunkte, Kopfzeilen und App-Kennung entsprechen dem offengelegten Protokoll
 * (Tasshack/dreame-vacuum, MIT). Befehle an den Roboter laufen als MiOT-Aufrufe
 * (get_properties / set_properties / action) über ".../device/sendCommand"; die Cloud
 * reicht sie an das Gerät weiter.
 *
 * Erwartet in der nutzenden Klasse: Properties Email, Password, Region, DeviceFilter,
 * VerifyTLS sowie die Attribute Token und Device (JSON).
 */
trait SaugroboterApi
{
    private static $DC_SALT  = 'RAylYC%fmSKp7%Tq';
    private static $DC_AGENT = 'Dreame_Smarthome/2.1.9 (iPhone; iOS 18.4.1; Scale/3.00)';
    private static $DC_BASIC = 'Basic ZHJlYW1lX2FwcHYxOkFQXmR2QHpAU1FZVnhOODg=';
    private static $DC_PORT  = 13267;
    // Die Cloud nimmt je get_properties-Aufruf nur eine begrenzte Zahl Kennungen an
    private static $DC_BATCH = 15;

    private $dcLastError = '';
    // true, wenn die letzten Werte aus dem Cloud-Speicher statt direkt vom Roboter kamen
    protected $dcFromCache = false;

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
            $r = $this->CloudTokenRequest('grant_type=refresh_token&refresh_token=' . rawurlencode($t['refresh']), $t);
            if ($r) return true;
        }
        return $this->CloudTokenRequest('grant_type=password&username=' . rawurlencode($user)
            . '&password=' . $this->PasswordHash($pass) . '&type=account', $t);
    }

    // Die Cloud erwartet md5(Passwort + Salz). Gespeichert wird nur dieser Wert ("hash:...").
    protected function PasswordHash($pass)
    {
        return strpos($pass, 'hash:') === 0 ? substr($pass, 5) : md5($pass . self::$DC_SALT);
    }

    private function CloudTokenRequest($grant, $old)
    {
        $headers = [
            'Accept: */*',
            'Content-Type: application/x-www-form-urlencoded',
            'User-Agent: ' . self::$DC_AGENT,
            'Authorization: ' . self::$DC_BASIC,
            'Tenant-Id: ' . (is_array($old) && !empty($old['tenant']) ? $old['tenant'] : '000000')
        ];
        list($code, $body) = $this->CloudHttp('POST', $this->CloudBase() . '/dreame-auth/oauth/token', $headers,
            'platform=IOS&scope=all&' . $grant);
        $d = json_decode($body, true);
        if ($code == 200 && is_array($d) && !empty($d['access_token'])) {
            $this->WriteAttributeString('Token', json_encode([
                'access'  => $d['access_token'],
                'refresh' => isset($d['refresh_token']) ? $d['refresh_token'] : '',
                'uid'     => isset($d['uid']) ? strval($d['uid']) : '',
                'tenant'  => isset($d['tenant_id']) ? strval($d['tenant_id']) : '000000',
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
        $headers = [
            'Accept: */*',
            'Content-Type: application/json',
            'User-Agent: ' . self::$DC_AGENT,
            'Authorization: ' . self::$DC_BASIC,
            'Tenant-Id: ' . ($this->CloudToken('tenant') ?: '000000'),
            'Dreame-Auth: ' . $this->CloudToken('access')
        ];
        list($code, $body) = $this->CloudHttp('POST', $this->CloudBase() . '/' . $path, $headers, json_encode($payload));
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
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0
        ];
        if ($method == 'POST') { $opt[CURLOPT_POST] = true; $opt[CURLOPT_POSTFIELDS] = $body; }
        curl_setopt_array($ch, $opt);
        $res = curl_exec($ch);
        $code = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($res === false) {
            $this->dcLastError = in_array($errno, [35, 51, 58, 60, 77], true)
                ? 'Zertifikatsprüfung fehlgeschlagen (' . $err . ') – ggf. „TLS-Zertifikate prüfen“ abschalten.'
                : 'Netzwerkfehler: ' . $err;
            $this->SendDebug('HTTP', $method . ' ' . $url . ' -> ' . $this->dcLastError, 0);
            return [0, ''];
        }
        if ($code != 200) $this->SendDebug('HTTP', $method . ' ' . $url . ' -> ' . $code . ' ' . substr($res, 0, 200), 0);
        return [$code, $res];
    }

    private function CloudBase()
    {
        $r = strtolower(trim($this->ReadPropertyString('Region')));
        return 'https://' . ($r === '' ? 'eu' : $r) . '.iot.dreame.tech:' . self::$DC_PORT;
    }

    private function CloudRegion()
    {
        $r = strtolower(trim($this->ReadPropertyString('Region')));
        return $r === '' ? 'eu' : $r;
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
        foreach ($d['data'] as $k => $e) {
            if (is_array($e) && isset($e['key'])) { $key = strval($e['key']); $val = isset($e['value']) ? $e['value'] : null; }
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
        foreach (array_chunk($keys, self::$DC_BATCH) as $i => $chunk) {
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
