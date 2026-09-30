<?php

/**
 * Saugroboter – Live-Verbindung zur Hersteller-Cloud (MQTT 3.1.1 über TLS).
 *
 * Die App bekommt Zustand, Position und Kartenänderungen nicht per Abfrage, sondern als
 * Push-Nachrichten ("properties_changed") über MQTT. Genau das macht dieses Modul auch:
 * Ein Symcon-Client-Socket (TLS) hält die Verbindung, das MQTT-Protokoll selbst
 * (CONNECT, SUBSCRIBE, PUBLISH, PING) ist hier von Hand umgesetzt – ohne Fremdbibliothek.
 *
 * Verbindungsdaten (Server, Client-Kennung, Thema) entsprechen dem offengelegten Protokoll
 * (Tasshack/dreame-vacuum, MIT). Anmeldung: Benutzer = Konto-ID, Passwort = Zugangstoken.
 *
 * Fällt die Verbindung aus, arbeitet das Modul wie bisher mit regelmäßiger Abfrage weiter.
 */
trait SaugroboterLive
{
    private static $LV_SOCKET = '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}';   // Client Socket
    private static $LV_TX     = '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}';   // an den Socket
    private static $LV_KEEP   = 60;     // MQTT-Keepalive in Sekunden
    private static $LV_SILENT = 150;    // so lange ohne Daten -> Verbindung neu aufbauen

    // ---- Einrichtung ----------------------------------------------------------

    // Aus ApplyChanges: Nachrichten anmelden, Prüfung anstoßen bzw. Verbindung schließen
    protected function LiveApply()
    {
        $this->RegisterMessage($this->InstanceID, FM_CONNECT);
        $this->RegisterMessage($this->InstanceID, FM_DISCONNECT);
        $this->LiveWatchParent();
        $was = $this->GetBuffer('MqttState');
        // Nichts an Zugang/Verbindung geändert und Sitzung steht: einfach weiterlaufen lassen
        if (!$this->credChanged && $this->LiveWanted() && $was === '2') return;
        $this->SetBuffer('MqttState', '0');
        if ($this->LiveWanted()) {
            // Bestehende Sitzung sauber beenden, damit die Anmeldung mit den neuen Einstellungen läuft
            if ($was === '1' || $was === '2') $this->LiveReconnect('Einstellungen übernommen');
            $this->SetBuffer('LiveHold', '0');
            $this->SetBuffer('LiveFails', '0');
            $this->SetTimerInterval('LiveCheck', 3000);   // erste Prüfung gleich, danach alle 30 s
        } else {
            $this->SetTimerInterval('LiveCheck', 0);
            $this->SetTimerInterval('LiveWork', 0);
            $this->SetBuffer('WorkDue', '0');
            $this->LiveSocketOpen(false);
            $this->SetVal('Live', false);
        }
    }

    protected function LiveWanted()
    {
        return $this->ReadPropertyBoolean('Live') && $this->ReadPropertyBoolean('Active') && $this->ReadPropertyBoolean('Consent')
            && trim($this->ReadPropertyString('Email')) !== '' && $this->ReadPropertyString('Password') !== '';
    }

    // Live-Daten kommen gerade an (dann braucht es keine Kartendateien und kein schnelles Abfragen)
    protected function LiveOk()
    {
        return $this->GetBuffer('MqttState') === '2' && time() - intval($this->GetBuffer('LiveRx')) < self::$LV_SILENT;
    }

    private function LiveParent()
    {
        $i = @IPS_GetInstance($this->InstanceID);
        return is_array($i) ? intval($i['ConnectionID']) : 0;
    }

    private function LiveIsSocket($pid)
    {
        if ($pid <= 0 || !IPS_InstanceExists($pid)) return false;
        $i = IPS_GetInstance($pid);
        return strcasecmp($i['ModuleInfo']['ModuleID'], self::$LV_SOCKET) == 0;
    }

    // Statusmeldungen des Sockets verfolgen (verbunden -> MQTT-Anmeldung senden)
    private function LiveWatchParent()
    {
        $pid = $this->LiveParent();
        $old = intval($this->GetBuffer('LiveParent'));
        if ($old == $pid) return;
        if ($old > 0 && IPS_InstanceExists($old)) @$this->UnregisterMessage($old, IM_CHANGESTATUS);
        if ($pid > 0) $this->RegisterMessage($pid, IM_CHANGESTATUS);
        $this->SetBuffer('LiveParent', strval($pid));
    }

    protected function LiveMessageSink($SenderID, $Message, $Data)
    {
        if ($Message == FM_CONNECT || $Message == FM_DISCONNECT) {
            $this->LiveWatchParent();
            $this->SetBuffer('MqttState', '0');
            if ($this->LiveWanted()) $this->SetTimerInterval('LiveCheck', 2000);
            return true;
        }
        if ($Message == IM_CHANGESTATUS && $SenderID == $this->LiveParent()) {
            $this->SetBuffer('MqttState', '0');
            $this->SetBuffer('LiveIn', '');
            if (intval($Data[0]) == 102 && $this->LiveWanted()) $this->LiveConnect();
            elseif ($this->GetBuffer('LiveWasUp') === '1') {
                $this->SetBuffer('LiveWasUp', '0');
                $this->LiveCountDrop('Server hat die Verbindung getrennt');
                $this->SetTimerInterval('LiveCheck', 5000);          // gleich prüfen statt erst in 30 s
                $this->SetVal('Live', false);
                $this->SetPollInterval();
            }
            $this->UpdateStatusForm();
            return true;
        }
        return false;
    }

    // Zustand der Live-Verbindung in Worten (für den Statusblock der Instanz)
    protected function LiveStatusText()
    {
        if (!$this->ReadPropertyBoolean('Live')) return 'ausgeschaltet';
        if (!$this->LiveWanted()) return 'wartet (Instanz nicht aktiv oder Zugangsdaten fehlen)';
        $d = json_decode($this->GetBuffer('LiveDrops'), true);
        $drops = is_array($d) && ($d['day'] ?? '') === date('Ymd') && $d['n'] > 0
            ? ' · heute ' . $d['n'] . '× neu aufgebaut (zuletzt ' . date('H:i', $d['at']) . ': ' . $d['why'] . ')' : '';
        return $this->LiveStatusCore() . $drops;
    }

    private function LiveStatusCore()
    {
        if ($this->LiveOk()) {
            $since = intval($this->GetBuffer('LiveSince'));
            $last = intval($this->GetBuffer('LiveDevAt'));
            $fmt = function ($t) { return date(date('Ymd', $t) == date('Ymd') ? 'H:i' : 'd.m. H:i', $t); };
            return '✅ verbunden' . ($since > 0 ? ' seit ' . $fmt($since) : '') . ' – '
                . ($last > 0 ? 'letzte Meldung vom Roboter ' . $fmt($last) . ' (' . intval($this->GetBuffer('LiveDevCnt')) . ' seit Verbindungsaufbau)'
                    : 'vom Roboter kam noch keine Meldung');
        }
        $hold = intval($this->GetBuffer('LiveHold'));
        if ($hold > time() + 86400) return '⛔ gestoppt – unbekanntes Server-Zertifikat (siehe Letzte Meldung)';
        if ($hold > time()) return '⏸ pausiert bis ' . date('H:i', $hold) . ' (Anmeldung abgelehnt) – solange normale Abfrage';
        $pid = $this->LiveParent();
        if (!$this->LiveIsSocket($pid)) return 'wird eingerichtet …';
        if (IPS_GetInstance($pid)['InstanceStatus'] != 102) return '⚠️ Socket nicht verbunden – solange normale Abfrage';
        if ($this->GetBuffer('MqttState') === '1') return 'Anmeldung läuft …';
        if ($this->GetBuffer('MqttState') === '2') return '⚠️ verbunden, aber seit über 2 Minuten keine Daten';
        return 'verbindet …';
    }

    // ---- Wächter (Timer "LiveCheck", alle 30 s) --------------------------------

    public function LiveCheck()
    {
        $this->SetTimerInterval('LiveCheck', 30000);
        $this->Trace('Prüfung', 'Live ' . ($this->LiveOk() ? 'ok' : 'nicht bereit') . ', letzte Meldung vor ' . (time() - intval($this->GetBuffer('LiveDevAt'))) . ' s'
            . ', Abruf zuletzt ' . (intval($this->GetBuffer('PollAt')) ? date('H:i:s', intval($this->GetBuffer('PollAt'))) : '–')
            . ', Nacharbeit zuletzt ' . (intval($this->GetBuffer('WorkRanAt')) ? date('H:i:s', intval($this->GetBuffer('WorkRanAt'))) : '–')
            . ', Zeitgeber Poll ' . $this->TimerInfo('Poll'));
        $this->CmdPendingCheck(false);
        // Wächter: Nacharbeit angestoßen, aber seit über 20 s nicht gelaufen -> jetzt selbst ausführen
        $due = floatval($this->GetBuffer('WorkDue'));
        if ($due > 0 && microtime(true) - $due > 20) { $this->SetBuffer('WorkLate', strval(intval($this->GetBuffer('WorkLate')) + 1)); $this->LiveWork(); }
        $this->UpdateStatusForm();
        if (!$this->LiveWanted()) { $this->SetTimerInterval('LiveCheck', 0); return false; }

        // Nach wiederholter Ablehnung eine Weile Ruhe geben
        if (intval($this->GetBuffer('LiveHold')) > time()) return false;

        $pid = $this->LiveParent();
        if ($pid == 0) {
            $pid = IPS_CreateInstance(self::$LV_SOCKET);
            IPS_SetName($pid, 'Saugroboter Live (' . IPS_GetName($this->InstanceID) . ')');
            IPS_ConnectInstance($this->InstanceID, $pid);
            $this->LiveWatchParent();
            $this->SendDebug('Live', 'Client Socket #' . $pid . ' angelegt', 0);
        }
        if (!$this->LiveIsSocket($pid)) {
            $this->Note('Live-Verbindung: Die übergeordnete Instanz ist kein Client Socket.');
            return false;
        }

        // Zieladresse: der MQTT-Server, an dem das Gerät hängt ("bindDomain" aus der Geräteliste)
        $dev = $this->Locked(function () { return $this->CloudDevice(); }, true);
        if (!is_array($dev)) return false;        // gerade beschäftigt oder Cloud nicht erreichbar
        if (empty($dev['host']) || strpos($dev['host'], ':') === false) {
            $this->Note('Live-Verbindung nicht möglich: Die Cloud nennt keinen Server für das Gerät.');
            return false;
        }
        list($host, $port) = explode(':', $dev['host'], 2);
        if ($this->CloudRegion() == 'kr') $host = str_replace('10100', '10000', $host);
        // Der Live-Server hat kein öffentlich prüfbares Zertifikat (die App prüft es deshalb gar nicht).
        // Der Socket verschlüsselt nur; geschützt wird das Token durch die Zertifikatsbindung in LiveConnect().
        $this->SetBuffer('LiveHost', $host . ':' . intval($port));
        $this->LiveSocketConfig($pid, ['Host' => $host, 'Port' => intval($port), 'UseSSL' => true,
            'VerifyPeer' => false, 'VerifyHost' => false, 'Open' => true]);

        // Socket getrennt: nicht endlos warten, sondern selbst neu öffnen (wachsende Abstände 60 s … 10 min)
        if (IPS_GetInstance($pid)['InstanceStatus'] != 102) {
            $down = intval($this->GetBuffer('SockDownAt'));
            if ($down == 0) { $this->SetBuffer('SockDownAt', strval(time())); return false; }
            $wait = min(600, 60 * (1 << min(4, intval($this->GetBuffer('SockRetries')))));
            if (time() - $down >= $wait) {
                $this->SetBuffer('SockRetries', strval(intval($this->GetBuffer('SockRetries')) + 1));
                $this->SetBuffer('SockDownAt', strval(time()));
                $this->LiveReconnect('Verbindung zum Server getrennt');
            }
            return false;
        }
        $this->SetBuffer('SockDownAt', '0');
        $state = $this->GetBuffer('MqttState');
        if ($state === '' || $state === '0') { $this->LiveConnect(); return true; }
        if ($state === '1' && time() - intval($this->GetBuffer('LiveConnectAt')) > 20) { $this->LiveReconnect('keine Antwort auf die Anmeldung'); return false; }
        if ($state === '2') {
            if (time() - intval($this->GetBuffer('LiveRx')) > self::$LV_SILENT) { $this->LiveReconnect('keine Daten mehr'); return false; }
            if ($this->LiveQuietCheck()) return false;
            // Zugangstoken läuft bald ab: erneuern und in Ruhe neu anmelden, bevor der Server trennt
            $t = json_decode($this->ReadAttributeString('Token'), true);
            if (is_array($t) && intval($t['until'] ?? 0) > 0 && intval($t['until']) - time() < 300) {
                $t['until'] = 0;
                $this->WriteAttributeString('Token', json_encode($t));
                if ($this->CloudLogin()) { $this->LiveReconnect('Zugangstoken erneuert'); return false; }
            }
            if (time() - intval($this->GetBuffer('LiveTx')) >= 25) $this->LiveSend("\xC0\x00");   // PINGREQ
        }
        return true;
    }

    // Button "Live-Verbindung neu aufbauen"
    public function LiveRestart()
    {
        $this->SetBuffer('LiveHold', '0');
        $this->SetBuffer('LiveFails', '0');
        if (!$this->LiveWanted()) { echo 'Die Live-Verbindung ist ausgeschaltet.'; return false; }
        $this->LiveReconnect('von Hand');
        $this->SetTimerInterval('LiveCheck', 3000);
        return true;
    }

    // ---- Zertifikatsbindung ---------------------------------------------------------
    // Beim ersten Kontakt wird die Zertifikatskette des Live-Servers gemerkt (oberstes Zertifikat).
    // Vor jeder Anmeldung muss der Server eine Kette vorzeigen, die zu diesem Zertifikat passt –
    // sonst geht das Token nicht raus. Schützt vor Mitlesern, auch ohne öffentliche Zertifizierungsstelle.

    private function LivePinOk($bindDomain)
    {
        if (!$this->ReadPropertyBoolean('VerifyTLS')) return true;      // bewusst abgeschaltet
        $parts = explode(':', $bindDomain, 2);
        $host = $parts[0];
        if ($this->CloudRegion() == 'kr') $host = str_replace('10100', '10000', $host);
        $port = isset($parts[1]) ? intval($parts[1]) : 0;

        // Ergebnis kurz merken (Wiederverbinden in Folge)
        if (intval($this->GetBuffer('PinOkAt')) > time() - 300 && $this->GetBuffer('PinOkHost') === $host) return true;

        $chain = $this->LiveFetchChain($host, $port);
        if (!is_array($chain) || !count($chain)) {
            $this->SendDebug('Live', 'Zertifikat des Servers nicht lesbar – Anmeldung verschoben', 0);
            return false;
        }
        $top = end($chain);
        $fp = openssl_x509_fingerprint($top, 'sha256');
        $info = openssl_x509_parse($top);
        $name = is_array($info) && isset($info['name']) ? $info['name'] : '?';
        $pin = json_decode($this->ReadAttributeString('LivePin'), true);

        if (!is_array($pin) || empty($pin['fp'])) {
            $this->WriteAttributeString('LivePin', json_encode(['fp' => $fp, 'name' => $name, 'at' => time()]));
            $this->SendDebug('Live', 'Server-Zertifikat gemerkt: ' . $name . ' ' . $fp, 0);
            $this->Note('Live-Verbindung: Zertifikat des Servers gemerkt.');
        } elseif (!hash_equals($pin['fp'], $fp) || !self::ChainValid($chain)) {
            $this->SetBuffer('LiveHold', strval(time() + 86400 * 365));
            $this->LiveSocketOpen(false);
            $this->SetVal('Live', false);
            $this->SendDebug('Live', 'Zertifikat passt NICHT: ' . $name . ' ' . $fp . ' (gemerkt: ' . $pin['name'] . ' ' . $pin['fp'] . ')', 0);
            $this->Note('Live-Verbindung gestoppt: Der Server zeigt ein anderes Zertifikat als bisher. '
                . 'Wenn Dreame es erneuert hat: „Server-Zertifikat neu übernehmen“. Sonst könnte jemand die Verbindung umleiten.');
            if ($this->ReadPropertyBoolean('NotifyError')) $this->Push('Saugroboter', 'Live-Verbindung gestoppt: unbekanntes Server-Zertifikat.');
            return false;
        }
        $this->SetBuffer('PinOkAt', strval(time()));
        $this->SetBuffer('PinOkHost', $host);
        return true;
    }

    // Jedes Zertifikat der Kette muss vom nächsten unterschrieben sein
    public static function ChainValid($chain)
    {
        $chain = array_values($chain);
        for ($i = 0; $i < count($chain) - 1; $i++) {
            $key = openssl_pkey_get_public($chain[$i + 1]);
            if ($key === false || openssl_x509_verify($chain[$i], $key) !== 1) return false;
        }
        return true;
    }

    // Zertifikatskette des Servers holen (nur ansehen, keine Daten senden)
    protected function LiveFetchChain($host, $port)
    {
        $ctx = stream_context_create(['ssl' => [
            'capture_peer_cert_chain' => true, 'verify_peer' => false, 'verify_peer_name' => false,
            'allow_self_signed' => true, 'SNI_enabled' => true, 'peer_name' => $host
        ]]);
        $fp = @stream_socket_client('ssl://' . $host . ':' . $port, $errno, $errstr, 8, STREAM_CLIENT_CONNECT, $ctx);
        if ($fp === false) { $this->SendDebug('Live', 'Zertifikatsabruf: ' . $errstr, 0); return null; }
        $p = stream_context_get_params($fp);
        fclose($fp);
        return isset($p['options']['ssl']['peer_certificate_chain']) ? $p['options']['ssl']['peer_certificate_chain'] : null;
    }

    // Button "Server-Zertifikat neu übernehmen"
    public function LiveTrustCertificate()
    {
        $this->WriteAttributeString('LivePin', '');
        $this->SetBuffer('PinOkAt', '0');
        return $this->LiveRestart();
    }

    private function LiveSocketConfig($pid, $want)
    {
        $changed = false;
        $conf = json_decode(@IPS_GetConfiguration($pid), true);
        if (!is_array($conf)) return false;
        foreach ($want as $k => $v) {
            if (!array_key_exists($k, $conf)) continue;      // Eigenschaft gibt es in dieser Symcon-Version nicht
            if ($conf[$k] !== $v) { IPS_SetProperty($pid, $k, $v); $changed = true; }
        }
        if ($changed) @IPS_ApplyChanges($pid);
        return $changed;
    }

    private function LiveSocketOpen($open)
    {
        $pid = $this->LiveParent();
        if ($this->LiveIsSocket($pid)) $this->LiveSocketConfig($pid, ['Open' => (bool)$open]);
    }

    private function LiveReconnect($why)
    {
        $this->SendDebug('Live', 'Neu verbinden: ' . $why, 0);
        $this->Trace('Verbindung', 'neu verbinden: ' . $why);
        $this->LiveCountDrop($why);
        $this->SetBuffer('MqttState', '0');
        $pid = $this->LiveParent();
        if (!$this->LiveIsSocket($pid)) return;
        $this->LiveSocketConfig($pid, ['Open' => false]);
        $this->LiveSocketConfig($pid, ['Open' => true]);
    }

    // Neuaufbauten des Tages zählen (für den Status-Block)
    private function LiveCountDrop($why)
    {
        $d = json_decode($this->GetBuffer('LiveDrops'), true);
        if (!is_array($d) || ($d['day'] ?? '') !== date('Ymd')) $d = ['day' => date('Ymd'), 'n' => 0];
        $d['n']++;
        $d['at'] = time();
        $d['why'] = $why;
        $this->SetBuffer('LiveDrops', json_encode($d));
    }

    // ---- MQTT senden -------------------------------------------------------------

    private function LiveConnect()
    {
        if (!$this->CloudLogin()) { $this->SendDebug('Live', 'Keine Anmeldung: ' . $this->dcLastError, 0); return false; }
        $dev = $this->CloudDevice();
        if (!is_array($dev) || empty($dev['host'])) return false;
        // Token erst senden, wenn der Server sein bekanntes Zertifikat vorzeigt
        if (!$this->LivePinOk($dev['host'])) return false;
        $uid = $this->CloudToken('uid');
        $master = !empty($dev['master']) ? $dev['master'] : $uid;
        $rand = '';
        for ($i = 0; $i < 13; $i++) $rand .= 'ABCDEF'[mt_rand(0, 5)];
        $client = 'p_' . $master . '_' . $rand . '_' . explode(':', $dev['host'])[0];

        $var = self::MqttStr('MQTT') . "\x04" . "\xC2" . pack('n', self::$LV_KEEP);   // 3.1.1, Benutzer+Passwort, Clean Session
        $pay = self::MqttStr($client) . self::MqttStr($uid) . self::MqttStr($this->CloudToken('access'));
        $this->SetBuffer('LiveIn', '');
        $this->SetBuffer('MqttState', '1');
        $this->SetBuffer('LiveConnectAt', strval(time()));
        $this->SendDebug('Live', 'CONNECT ' . $client, 0);
        return $this->LiveSend(self::MqttPacket(0x10, $var . $pay));
    }

    private function LiveTopics()
    {
        $dev = $this->CloudDevice();
        if (!is_array($dev)) return [];
        $master = !empty($dev['master']) ? $dev['master'] : $this->CloudToken('uid');
        $region = $this->CloudRegion();
        $base = '/status/' . $dev['did'] . '/' . $master . '/' . $dev['model'] . '/';
        // Geräte am KR-Server melden sich (noch) unter SG
        return $region == 'kr' ? [$base . 'sg/', $base . 'kr/'] : [$base . $region . '/'];
    }

    private function LiveSend($bin)
    {
        try {
            $this->SendDataToParent(json_encode([
                'DataID' => self::$LV_TX,
                'Buffer' => mb_convert_encoding($bin, 'UTF-8', 'ISO-8859-1')
            ]));
            $this->SetBuffer('LiveTx', strval(time()));
            return true;
        } catch (Throwable $e) {
            $this->SendDebug('Live', 'Senden fehlgeschlagen: ' . $e->getMessage(), 0);
            return false;
        }
    }

    public static function MqttStr($s)
    {
        return pack('n', strlen($s)) . $s;
    }

    public static function MqttPacket($type, $body)
    {
        $len = strlen($body);
        $enc = '';
        do {
            $b = $len % 128;
            $len = intdiv($len, 128);
            if ($len > 0) $b |= 128;
            $enc .= chr($b);
        } while ($len > 0);
        return chr($type) . $enc . $body;
    }

    // Vollständige Pakete aus dem Datenstrom lösen: [[Typ, Flags, Inhalt], ...], Rest bleibt stehen
    public static function MqttSplit(&$buf)
    {
        $out = [];
        while (strlen($buf) >= 2) {
            $mul = 1; $len = 0; $i = 1;
            do {
                if ($i >= strlen($buf)) return $out;       // Längenangabe noch unvollständig
                $b = ord($buf[$i++]);
                $len += ($b & 127) * $mul;
                $mul *= 128;
            } while (($b & 128) && $i < 5);
            if (strlen($buf) < $i + $len) return $out;     // Paket noch nicht vollständig
            $out[] = [ord($buf[0]) >> 4, ord($buf[0]) & 15, substr($buf, $i, $len)];
            $buf = substr($buf, $i + $len);
        }
        return $out;
    }

    // ---- MQTT empfangen ------------------------------------------------------------

    public function ReceiveData($JSONString)
    {
        $d = json_decode($JSONString, true);
        if (!is_array($d)) return '';
        if (isset($d['BufferHex'])) $raw = hex2bin($d['BufferHex']);
        elseif (isset($d['Buffer'])) $raw = mb_convert_encoding($d['Buffer'], 'ISO-8859-1', 'UTF-8');
        else return '';

        // Pakete können über mehrere Aufrufe verteilt ankommen – nacheinander verarbeiten
        $key = 'SAUG_RX_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($key, 5000)) return '';
        try {
            $buf = base64_decode($this->GetBuffer('LiveIn')) . $raw;
            $packets = self::MqttSplit($buf);
            // Schutz gegen Datenmüll: ein unvollständiger Rest über 4 MB wird verworfen
            $this->SetBuffer('LiveIn', strlen($buf) > 4194304 ? '' : base64_encode($buf));
            $this->SetBuffer('LiveRx', strval(time()));
            foreach ($packets as $p) $this->LivePacket($p[0], $p[1], $p[2]);
        } finally {
            IPS_SemaphoreLeave($key);
        }
        return '';
    }

    private function LivePacket($type, $flags, $body)
    {
        switch ($type) {
            case 2: // CONNACK
                $rc = strlen($body) >= 2 ? ord($body[1]) : 255;
                if ($rc == 0) {
                    $this->SetBuffer('MqttState', '2');
                    $this->SetBuffer('LiveFails', '0');
                    $this->SetBuffer('LiveWasUp', '1');
                    $this->SetBuffer('LiveSince', strval(time()));
                    $this->SetBuffer('LiveDevCnt', '0');
                    $this->SetBuffer('SockRetries', '0');
                    $sub = '';
                    foreach ($this->LiveTopics() as $t) $sub .= self::MqttStr($t) . "\x01";
                    if ($sub !== '') $this->LiveSend(self::MqttPacket(0x82, pack('n', 1) . $sub));
                    $this->SetVal('Live', true);
                    $this->SendDebug('Live', 'Verbunden, abonniert: ' . implode(' ', $this->LiveTopics()), 0);
                    $this->Trace('Verbindung', 'angemeldet, abonniert');
                    $this->SetPollInterval();
                    $this->UpdateStatusForm();
                    return;
                }
                $n = intval($this->GetBuffer('LiveFails')) + 1;
                $this->SetBuffer('LiveFails', strval($n));
                $this->SetBuffer('MqttState', '0');
                $this->SendDebug('Live', 'Anmeldung abgelehnt (Code ' . $rc . ')', 0);
                // 4/5 = Zugang abgelehnt: beim nächsten Versuch frisches Token holen
                if ($rc == 4 || $rc == 5) {
                    $t = json_decode($this->ReadAttributeString('Token'), true);
                    if (is_array($t)) { $t['until'] = 0; $this->WriteAttributeString('Token', json_encode($t)); }
                }
                if ($n >= 3) {
                    $this->SetBuffer('LiveHold', strval(time() + 600));
                    $this->LiveSocketOpen(false);
                    $this->Note('Live-Verbindung abgelehnt – neuer Versuch in 10 Minuten, bis dahin normale Abfrage.');
                }
                return;
            case 3: // PUBLISH
                if (strlen($body) < 2) return;
                $tl = unpack('n', substr($body, 0, 2))[1];
                $pos = 2 + $tl;
                $qos = ($flags >> 1) & 3;
                if ($qos > 0) {
                    $pid = substr($body, $pos, 2);
                    $pos += 2;
                    if ($qos == 1) $this->LiveSend("\x40\x02" . $pid);            // PUBACK
                }
                $this->LiveMessage(substr($body, $pos));
                return;
            case 9: // SUBACK
                if (strpos(substr($body, 2), "\x80") !== false) {
                    $this->Note('Live-Verbindung: Das Abonnement wurde abgelehnt.');
                } elseif ($this->ReadPropertyBoolean('Active')) {
                    $this->Trace('Verbindung', 'Abo bestätigt');
                    // Während der Lücke kann ein Zustandswechsel verloren gegangen sein -> gleich einmal abfragen
                    $this->SetTimerInterval('Poll', 3000);
                }
                return;
            case 13: // PINGRESP
                return;
        }
    }

    // Nachricht vom Gerät: {"data":{"method":"properties_changed","params":[{siid,piid,value},...]}}
    private function LiveMessage($payload)
    {
        $m = json_decode($payload, true);
        if (!is_array($m)) return;
        $data = isset($m['data']) && is_array($m['data']) ? $m['data'] : $m;
        // Nur Nachrichten des eigenen Geräts
        $dev = $this->CloudDevice();
        if (!is_array($dev)) return;
        foreach ([$m, $data] as $x) {
            if (isset($x['did']) && strval($x['did']) !== strval($dev['did'])) return;
        }
        if (!isset($data['method']) || $data['method'] !== 'properties_changed' || !isset($data['params']) || !is_array($data['params'])) return;

        $v = [];
        foreach ($data['params'] as $p) {
            if (!is_array($p) || !isset($p['siid'], $p['piid']) || !array_key_exists('value', $p)) continue;
            $v[intval($p['siid']) . '.' . intval($p['piid'])] = $p['value'];
        }
        if (!count($v)) return;
        $this->SendDebug('Live', json_encode(array_map(function ($x) {
            return is_string($x) && strlen($x) > 60 ? substr($x, 0, 60) . '…' : $x;
        }, $v), JSON_UNESCAPED_UNICODE), 0);
        if ($this->TraceOn()) {
            $this->Trace('Live', implode(' ', array_map(function ($k, $x) {
                return $k . '=' . (is_string($x) && strlen($x) > 24 ? '[' . strlen($x) . ' Z.]' : json_encode($x));
            }, array_keys($v), $v)));
        }
        $this->Online(true);
        $this->SetBuffer('LiveDevAt', strval(time()));
        $this->SetBuffer('LiveDevCnt', strval(intval($this->GetBuffer('LiveDevCnt')) + 1));
        $this->SetBuffer('FreshAt', strval(time()));

        // merken, welche Werte live kamen (die haben Vorrang vor dem Cloud-Speicher)
        foreach (['2.1', '2.2', '3.1', '4.2', '4.3', '4.63'] as $k) if (isset($v[$k])) $this->SetBuffer('LiveSeen' . $k, strval(time()));
        $poll = false;
        if (isset($v['2.1'])) {
            $state = intval($v['2.1']);
            if ($this->GetValue('State') !== $state) { $poll = true; $this->SetVal('State', $state); $this->CmdPendingCheck(true); }
            // Fahrt beginnt: sofort als Auftrag führen (nicht erst beim nächsten Abruf)
            if (in_array(SaugroboterTexte::StateGroup($state), ['working', 'paused'], true) && $this->ReadAttributeInteger('Job') == 0) {
                $this->WriteAttributeInteger('Job', 1);
                $this->Trace('Live', 'Fahrt erkannt – Auftrag läuft');
            }
        }
        if (isset($v['2.2'])) {
            $err = intval($v['2.2']);
            $errChanged = $this->GetValue('Error') !== $err;
            if ($errChanged) $poll = true;
            $this->SetVal('Error', $err);
            $this->SetVal('ErrorHint', SaugroboterTexte::ErrorHint($err));
            if ($errChanged) $this->CmdPendingCheck(true);   // z. B. Hinweis quittiert
        }
        if (isset($v['3.1'])) $this->SetVal('Battery', intval($v['3.1']));
        if (isset($v['3.2']) && @$this->GetIDForIdent('Charging')) $this->SetVal('Charging', intval($v['3.2']) == 1);
        if (isset($v['4.2'])) $this->SetVal('CleanTime', intval($v['4.2']));
        if (isset($v['4.3'])) $this->SetVal('CleanArea', intval($v['4.3']));
        if (isset($v['4.63']) && @$this->GetIDForIdent('Progress')) $this->SetVal('Progress', intval($v['4.63']));
        foreach (['4.41', '27.1', '27.2', '27.3'] as $k) if (isset($v[$k])) $poll = true;
        // Geräte-Einstellungen mitschreiben (spart beim nächsten Start das Nachfragen)
        $cfg = array_intersect_key($v, array_flip(['4.4', '4.5', '4.23', '4.50']));
        if (count($cfg)) $this->DevCfg($cfg);

        // Karte: 6/1 = Kartenbild (Voll- oder Differenzbild) direkt in der Nachricht,
        //        6/3 = Name einer neu abgelegten Kartendatei
        if (isset($v['6.1']) && is_string($v['6.1']) && $v['6.1'] !== '') $this->LiveMapFrame($v['6.1']);
        if (isset($v['6.3']) && $v['6.3'] !== '') {
            $o = $v['6.3'];
            if (is_string($o) && is_array($j = json_decode($o, true))) $o = $j;
            if (is_array($o)) $o = isset($o['object_name']) ? $o['object_name'] : (isset($o['obj_name']) ? $o['obj_name'] : '');
            if (is_string($o) && $o !== '') $this->SetBuffer('LiveObject', $o);
        }

        // Zustandswechsel: vollständige Abfrage gleich hinterher (Auftrag, Station, Warteschlange …)
        if ($poll) $this->SetTimerInterval('Poll', 1000);
        $this->SetBuffer('ViewDirty', '1');
        $this->LiveKick(500);
    }

    // Nacharbeit anstoßen. Ein schon früher geplanter Lauf wird NICHT verschoben – sonst würde der Timer
    // bei vielen Live-Nachrichten (der X60 schickt mehrere pro Sekunde) immer wieder neu gestellt und liefe nie.
    private function LiveKick($ms)
    {
        $now = microtime(true);
        $due = $now + $ms / 1000;
        $cur = floatval($this->GetBuffer('WorkDue'));
        // Lauf bereits geplant (und noch nicht gelaufen): nicht neu stellen – Symcon-Zeitgeber feuern teils
        // verspätet, ein erneutes Stellen würde ihn dann wieder hinausschieben. Nur nach 15 s neu anstoßen.
        if ($cur > 0 && $cur <= $due && $now - $cur < 15) return;
        if ($cur > 0 && $now - $cur >= 15) $this->Trace('Zeitgeber', 'Nacharbeit war für ' . date('H:i:s', intval($cur)) . ' geplant und ist nicht gelaufen – neu gestellt');
        $this->SetBuffer('WorkDue', strval($due));
        $this->SetTimerInterval('LiveWork', max(1, $ms));
    }

    // Kartenbild aus der Nachricht übernehmen (Vollbild ersetzt, Differenzbild wird aufgelegt)
    private function LiveMapFrame($text)
    {
        $b = SaugroboterKarte::Decode($text, self::MAP_IV);
        if ($b === null) { $this->SetBuffer('LiveCntX', strval(intval($this->GetBuffer('LiveCntX')) + 1)); $this->Trace('Bild', 'nicht dekodierbar (' . strlen($text) . ' Zeichen)'); return false; }
        // für die Kartendiagnose: letztes Voll- und Teilbild im Rohformat merken
        $this->LiveRobotMove($b);
        $this->Trace('Bild', $b['type'] . ' Nr. ' . $b['frameId'] . ', Karte ' . $b['mapId'] . ', ' . $b['w'] . '×' . $b['h'] . ', Roboter ' . implode('/', $b['robot']));
        $t = $b['type'] === 'I' ? 'I' : 'P';
        $this->SetBuffer('LiveRaw' . $t, base64_encode(gzcompress($text)));
        $this->SetBuffer('LiveCnt' . $t, strval(intval($this->GetBuffer('LiveCnt' . $t)) + 1));
        $ok = $this->LiveTakeBlock($b, true);
        if (!$ok) $this->SetBuffer('LiveCntX', strval(intval($this->GetBuffer('LiveCntX')) + 1));
        $this->Trace('Bild', $ok ? 'in Live-Karte übernommen' : 'verworfen (' . ($this->LiveBlock() === null ? 'keine Grundkarte – Datei wird geholt' : 'älter/doppelt') . ')');
        return $ok;
    }

    /**
     * Unterwegs meldet sich der Roboter live mehrmals pro Sekunde. Ist er laut Zustand unterwegs (auch wenn
     * das der Abruf aus dem Cloud-Speicher erfahren hat), aber live kommt seit 40 s nichts, ist das Abo
     * eingeschlafen (kommt nach langer Ruhe an der Station vor) -> sofort neu verbinden, höchstens alle 3 Minuten.
     */
    protected function LiveQuietCheck()
    {
        if (!$this->LiveOk()) return false;
        $group = SaugroboterTexte::StateGroup(intval($this->GetValue('State')));
        $moving = in_array($group, ['working', 'moving'], true) || $this->ReadAttributeInteger('Job') == 1 && $group !== 'docked' && $group !== 'station';
        if (!$moving) return false;
        $dev = max(intval($this->GetBuffer('LiveSince')), intval($this->GetBuffer('LiveDevAt')));
        if (time() - $dev <= 40 || time() - intval($this->GetBuffer('LiveQuietFix')) <= 180) return false;
        $this->SetBuffer('LiveQuietFix', strval(time()));
        $this->LiveReconnect('Roboter ist unterwegs, aber live kam nichts');
        return true;
    }

    // ---- Testprotokoll ------------------------------------------------------------
    // Schreibt Live-Meldungen, Zeitgeber-Läufe und was die Kachel anzeigt in eine Textdatei im
    // Symcon-Log-Ordner. Läuft nur, wenn in der Instanz gestartet, und endet nach 2 Stunden selbst.

    protected function TraceOn()
    {
        return $this->ReadAttributeInteger('TraceUntil') > time();
    }

    protected function TraceFile()
    {
        $dir = function_exists('IPS_GetLogDir') ? IPS_GetLogDir() : (function_exists('IPS_GetKernelDir') ? IPS_GetKernelDir() . 'logs' . DIRECTORY_SEPARATOR : '');
        if ($dir === '' || !is_dir($dir) || !is_writable($dir)) $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR;
        return rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . 'saugroboter_' . $this->InstanceID . '_test.log';
    }

    protected function Trace($what, $text)
    {
        if (!$this->TraceOn()) return;
        $t = microtime(true);
        $line = date('H:i:s', intval($t)) . sprintf('.%03d', ($t - floor($t)) * 1000) . ' ' . str_pad($what, 9) . ' ' . $text . "\n";
        $f = $this->TraceFile();
        if (@filesize($f) > 3000000) @rename($f, $f . '.alt');
        @file_put_contents($f, $line, FILE_APPEND | LOCK_EX);
    }

    public function TraceStart()
    {
        $this->WriteAttributeInteger('TraceUntil', time() + 7200);
        @unlink($this->TraceFile());
        $this->Trace('Start', 'Testprotokoll gestartet (läuft bis ' . date('H:i', time() + 7200) . ') – Live ' . ($this->LiveOk() ? 'verbunden' : 'aus')
            . ', Zustand ' . intval($this->GetValue('State')) . ', Auftrag ' . $this->ReadAttributeInteger('Job')
            . ', Zeitgeber Poll ' . $this->TimerInfo('Poll') . ', LiveWork ' . $this->TimerInfo('LiveWork') . ', LiveCheck ' . $this->TimerInfo('LiveCheck'));
        $this->RefreshViews();
        echo "Testprotokoll läuft (2 Stunden). Datei: " . $this->TraceFile() . "\nJetzt den Roboter fahren lassen und danach „Testprotokoll anzeigen“ drücken.";
    }

    public function TraceStop()
    {
        $this->Trace('Ende', 'Testprotokoll beendet');
        $this->WriteAttributeInteger('TraceUntil', 0);
        $this->RefreshViews();
        echo 'Testprotokoll beendet. Die Datei bleibt liegen: ' . $this->TraceFile();
    }

    public function TraceShow()
    {
        $f = $this->TraceFile();
        $txt = @file_get_contents($f);
        if ($txt === false || $txt === '') { echo 'Noch kein Testprotokoll vorhanden (' . $f . ').'; return; }
        $lines = explode("\n", rtrim($txt));
        $n = count($lines);
        // Zusammenfassung über die ganze Datei: alle wichtigen Ereignisse, Position und Kachel höchstens alle 15 s
        $out = []; $last = [];
        foreach ($lines as $l) {
            $sec = substr($l, 0, 8); $cat = trim(substr($l, 13, 10));
            $keep = in_array($cat, ['Start', 'Ende', 'Verbindung', 'FEHLER', 'Zeitgeber', 'Prüfung'], true)
                || ($cat === 'Live' && preg_match('/\b2\.1=/', $l))
                || ($cat === 'Abruf' && (strpos($l, 'übersprungen') !== false || strpos($l, 'fertig') !== false || strpos($l, 'ohne Ergebnis') !== false))
                || ($cat === 'Bild' && strpos($l, 'verworfen') !== false);
            if (!$keep && in_array($cat, ['Symbol', 'Kachel'], true)) {
                $t = strtotime($sec);
                if (!isset($last[$cat]) || $t - $last[$cat] >= 15 || $t < $last[$cat]) { $keep = true; $last[$cat] = $t; }
            }
            if ($keep) $out[] = $l;
        }
        $cut = count($out) > 400 ? array_merge(array_slice($out, 0, 200), ['…'], array_slice($out, -200)) : $out;
        echo 'Datei: ' . $f . ' (' . $n . " Zeilen)\nZusammenfassung: Zustandswechsel, Verbindung, Abrufe, Fehler; Position und Kachel alle 15 s\n\n" . implode("\n", $cut);
    }

    private function TimerInfo($ident)
    {
        if (!function_exists('IPS_GetTimerList') || !function_exists('IPS_GetTimer')) return '?';
        foreach (@IPS_GetTimerList() ?: [] as $tid) {
            $t = @IPS_GetTimer($tid);
            if (is_array($t) && ($t['InstanceID'] ?? 0) == $this->InstanceID && ($t['Name'] ?? '') == $ident) {
                return ($t['Interval'] ?? 0) . ' ms' . (isset($t['NextRun']) && $t['NextRun'] ? ', nächster ' . date('H:i:s', $t['NextRun']) : '');
            }
        }
        return '?';
    }

    // Abbrüche (Zeitlimit, Speicher) festhalten – die kann try/catch nicht fangen
    protected function CatchFatal($where)
    {
        // Symcon schaltet einige PHP-Funktionen ab (u. a. set_time_limit) – nur nutzen, wenn vorhanden
        if (function_exists('set_time_limit')) @set_time_limit(120);
        if (!function_exists('register_shutdown_function') || !function_exists('error_get_last')) return;
        $id = $this->InstanceID;
        register_shutdown_function(function () use ($where, $id) {
            $e = error_get_last();
            if ($e && in_array($e['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true) && IPS_InstanceExists($id)) {
                $this->SetBuffer('LastErr', date('d.m. H:i:s') . ' ' . $where . ': ' . $e['message'] . ' (' . basename($e['file']) . ':' . $e['line'] . ')');
                $this->Trace('FEHLER', $this->GetBuffer('LastErr'));
            }
        });
    }

    // Roboter-Symbol sofort versetzen: Position aus dem Live-Bild über die Umrechnung des angezeigten
    // Kartenbilds – unabhängig davon, ob die Karte selbst gerade neu gezeichnet werden kann.
    private function LiveRobotMove($b)
    {
        if (empty($b['robot']) || ($b['robot'][0] == 0 && $b['robot'][1] == 0)) return;
        $meta = json_decode($this->ReadAttributeString('MapMeta'), true);
        $ident = $this->MapIdent();
        if (!is_array($meta) || !isset($meta[$ident]['tf'])) { $this->Trace('Symbol', 'keine Umrechnung für Bild „' . $ident . '“ – Karte noch nicht neu gezeichnet'); return; }
        $p = SaugroboterKarte::Place($meta[$ident]['tf'], $b['robot'][0], $b['robot'][1], $b['robot'][2]);
        if ($p === null || $p[0] < -0.2 || $p[0] > 1.2 || $p[1] < -0.2 || $p[1] > 1.2) { $this->Trace('Symbol', 'Position außerhalb des Bilds: ' . json_encode($p)); return; }   // passt nicht zu diesem Bild
        $this->SetBuffer('LiveRobot', json_encode(['ident' => $ident, 'robot' => $p, 'at' => time()]));
        $this->Trace('Symbol', 'Roboter -> ' . implode('/', $p) . ' (Bild ' . $ident . ')');
        // Kachel höchstens alle 2 s direkt auffrischen – ohne auf die Nacharbeit zu warten
        if (microtime(true) - floatval($this->GetBuffer('RobotSentAt')) >= 2) {
            $this->SetBuffer('RobotSentAt', strval(microtime(true)));
            try { $this->RefreshViews(); } catch (\Throwable $e) { $this->SetBuffer('LastErr', date('H:i:s') . ' Anzeige: ' . $e->getMessage()); }
        }
    }

    /**
     * Neues Kartenbild übernehmen.
     * $live = true: Bild kam gerade über die Live-Verbindung (6/1) – es ist immer der neueste Stand,
     * Roboterposition und Strecke werden übernommen, egal welche Zeitstempel die Dateien tragen.
     * $live = false: Kartendatei aus der Cloud. Sie liefert Wände, Möbelumrisse und Strecke; solange
     * Live-Bilder kommen, bleibt die Roboterposition die aus der Live-Verbindung.
     * Die schnellen Live-Bilder des X60 enthalten teils keine Wände – dann bleiben die Details aus der Datei.
     */
    protected function LiveTakeBlock($b, $live = false)
    {
        $base = $this->LiveBlock();
        $same = $base !== null && $base['mapId'] == $b['mapId'];
        // kamen in der letzten Minute Live-Kartenbilder? Dann ist deren Position maßgeblich
        $liveRecent = time() - intval($this->GetBuffer('LiveMapAt')) < 60;
        if ($b['type'] === 'I') {
            $got = $b;
            if ($same) {
                $f = $this->CellFormat($b);
                $dNew = SaugroboterKarte::DetailCount($b, $f);
                $dOld = SaugroboterKarte::DetailCount($base, $f);
                $thin = $dOld > 50 && $dNew < $dOld * 0.5;
                $rich = $dOld <= 50 || $dNew >= $dOld * 0.8;
                if ($live) {
                    $got = $thin ? SaugroboterKarte::Overlay($base, $b) : $b;              // Position immer übernehmen
                } elseif ($liveRecent) {
                    if (!$rich) return false;                                                 // Datei bringt nichts Neues
                    $got = SaugroboterKarte::Overlay($b, $base);                              // Details ja, Position bleibt live
                } else {
                    $newer = !isset($base['info']['timestamp_ms'], $b['info']['timestamp_ms'])
                        || floatval($b['info']['timestamp_ms']) >= floatval($base['info']['timestamp_ms']);
                    if ($newer && $thin) $got = SaugroboterKarte::Overlay($base, $b);
                    elseif ($newer) $got = $b;
                    elseif ($rich) $got = SaugroboterKarte::Overlay($b, $base);
                    else return false;
                }
                // Strecke nicht verlieren, wenn das neue Bild keine mitbringt
                if (empty($got['info']['tr']) && !empty($base['info']['tr'])) $got['info']['tr'] = $base['info']['tr'];
            }
        } elseif ($b['type'] === 'P') {
            if (!$same) {
                // Kein passendes Vollbild da: Kartendatei holen
                $this->SetBuffer('NeedFull', '1');
                return false;
            }
            $fid = intval($b['frameId']);
            if ($live) {
                // nur doppelte oder verspätete Teilbilder verwerfen – gezählt wird nach den Live-Bildern,
                // nicht nach der Datei (deren Bildnummern laufen anders)
                $seq = $this->GetBuffer('LiveSeq');
                // Neue Fahrt/Karte: Bildnummern beginnen von vorn -> Zähler zurücksetzen
                if ($this->GetBuffer('LiveSeqMap') !== strval($b['mapId']) || time() - intval($this->GetBuffer('LiveMapAt')) > 120) $seq = '';
                if ($seq !== '' && $fid <= intval($seq) && intval($seq) - $fid < 1000) return false;
            } elseif ($fid <= intval($base['frameId']) && intval($base['frameId']) - $fid < 1000) {
                return false;
            }
            $got = SaugroboterKarte::Merge($base, $b, $this->IsV2());
        } else {
            return false;
        }
        if ($live) {
            $this->SetBuffer('LiveSeq', strval($b['frameId']));
            $this->SetBuffer('LiveSeqMap', strval($b['mapId']));
            $this->SetBuffer('LiveMapAt', strval(time()));
        }
        return $this->LiveStore($got);
    }

    // Nur behalten, was Zeichnen und Raumlage brauchen: die Kartendateien des X60 tragen viele große
    // Anhänge (Hindernisse, Möbel-Listen, Teppiche …) – zusammen sprengen sie sonst das Puffer-Limit
    // von Symcon (1 MB), und die Karte wird gar nicht gespeichert.
    private function LiveStore($got)
    {
        if (isset($got['info']) && is_array($got['info'])) $got['info'] = array_intersect_key($got['info'], array_flip(['timestamp_ms', 'tr', 'seg_inf', 'fsm', 'sa', 'delsr', 'curid']));
        $data = base64_encode(gzcompress(serialize($got)));
        if (strlen($data) > 900000) {
            $this->SetBuffer('LiveStoreErr', date('H:i:s') . ': Karte zu groß (' . round(strlen($data) / 1024) . ' kB)');
            return false;
        }
        $this->SetBuffer('LiveBlock', $data);
        if ($this->GetBuffer('LiveBlock') !== $data) {
            $this->SetBuffer('LiveStoreErr', date('H:i:s') . ': Puffer nicht geschrieben (' . round(strlen($data) / 1024) . ' kB)');
            return false;
        }
        $this->SetBuffer('LiveStoreErr', '');
        $this->SetBuffer('LiveFrame', strval($got['frameId']));
        $this->SetBuffer('MapDirty', '1');
        return true;
    }

    // ---- Nacharbeit (Timer "LiveWork") --------------------------------------------
    // Zeichnen und Dateiabrufe laufen hier, damit der Datenempfang nie blockiert.

    public function LiveWork()
    {
        $this->SetTimerInterval('LiveWork', 0);
        $this->SetBuffer('WorkDue', '0');
        $this->SetBuffer('WorkRanAt', strval(time()));
        $this->CatchFatal('Nacharbeit');
        $t0 = microtime(true);
        $this->Trace('Nacharbeit', 'Start (NeedFull ' . $this->GetBuffer('NeedFull') . ', MapDirty ' . $this->GetBuffer('MapDirty') . ', Objekt ' . ($this->GetBuffer('LiveObject') !== '' ? 'ja' : 'nein') . ')');
        if (!$this->ReadPropertyBoolean('Active')) return;

        $obj = $this->GetBuffer('LiveObject');
        // Während der Reinigung alle 2 Minuten die ausführliche Kartendatei (Wände, Möbel, Strecke) nachladen
        // Kommen keine Live-Kartenbilder (nur Zustand), die Datei alle 30 s holen, damit der Roboter trotzdem fährt
        $every = time() - intval($this->GetBuffer('LiveMapAt')) < 60 ? 120 : 30;
        if ($this->ReadAttributeInteger('Job') == 1 && intval($this->GetBuffer('FullTry')) < time() - $every) $this->SetBuffer('NeedFull', '1');
        $full = $this->GetBuffer('NeedFull') === '1' && intval($this->GetBuffer('FullTry')) < time() - 20;
        if ($obj !== '' || $full) {
            $ok = $this->Locked(function () use ($obj, $full) {
              try {
                if ($obj !== '') {
                    // Objektname kann den Schlüssel mitbringen: "pfad/datei,schlüssel"
                    $parts = explode(',', $obj, 2);
                    $text = $this->CloudFile($parts[0]);
                    if ($text !== null && isset($parts[1]) && strpos($text, ',') === false) $text = trim($text) . ',' . $parts[1];
                    $b = $text === null ? null : SaugroboterKarte::Decode($text, self::MAP_IV);
                    if ($b !== null) $this->LiveTakeBlock($b);
                }
                if ($full) {
                    $this->SetBuffer('FullTry', strval(time()));
                    $this->FetchLiveMap(true);
                    $this->SetBuffer('NeedFull', '0');
                }
              } catch (\Throwable $e) {
                $this->SetBuffer('LastErr', date('H:i:s') . ' Karte: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
                $this->Trace('FEHLER', $this->GetBuffer('LastErr'));
              }
                return true;
            }, true);
            if ($ok === null) { $this->Trace('Nacharbeit', 'Instanz belegt (Abruf/Befehl läuft) – in 2 s nochmal'); $this->LiveKick(2000); return; }   // anderer Zugriff läuft – gleich nochmal
            $this->SetBuffer('LiveObject', '');
        }

        if ($this->GetBuffer('MapDirty') === '1') {
            // höchstens alle 3 s neu zeichnen
            $wait = 3 - (microtime(true) - floatval($this->GetBuffer('LiveDrawAt')));
            if ($wait > 0) { $this->Trace('Nacharbeit', 'Zeichnen erst in ' . round($wait, 1) . ' s (höchstens alle 3 s)'); $this->LiveKick(intval($wait * 1000) + 50); return; }
            $b = $this->LiveBlock();
            $this->SetBuffer('MapDirty', '0');
            if ($b !== null) {
                $this->SetBuffer('LiveDrawAt', strval(microtime(true)));
                if ($this->ReadPropertyBoolean('MapImage')) $this->StoreMapImage($b, $b['mapId'], 'Map');
                $this->RoomFromBlock($b);
            }
            $this->SetBuffer('ViewDirty', '1');
        }
        if ($this->GetBuffer('ViewDirty') === '1') {
            $this->SetBuffer('ViewDirty', '0');
            $this->RefreshViews();
        }
        $this->Trace('Nacharbeit', 'Ende nach ' . round(microtime(true) - $t0, 1) . ' s, Live-Karte ' . ($this->LiveBlock() !== null ? 'vorhanden' : 'fehlt'));
    }
}
