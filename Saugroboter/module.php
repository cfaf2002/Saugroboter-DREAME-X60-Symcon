<?php

/*
 * Saugroboter – IP-Symcon-Modul für Saugroboter mit Dreame-Cloud-Anbindung (X50, X60 u. a.).
 * Inoffiziell: nicht mit Dreame verbunden, nicht von Dreame unterstützt.
 *
 * Aufbau
 *   libs/SaugroboterApi.php    Anmeldung, MiOT-Befehle, Dateien, Ereignisse (Trait)
 *   libs/SaugroboterKarte.php    Kartenblöcke dekodieren (inkl. AES) und als PNG zeichnen
 *   libs/SaugroboterTexte.php  Zustände, Fehler mit Kurzhilfe, Raumtypen, Verschleißteile
 *   libs/SaugroboterLive.php   Live-Verbindung (MQTT über den Client Socket) wie in der App
 *   module.html                Kachel für die Kachel-Visualisierung (HTML-SDK)
 *
 * Grundsätze
 *   - Alles, was modellabhängig ist (Verschleißteile, Statistik, Fortschritt), wird am Gerät
 *     abgefragt und nur angelegt, wenn der Roboter den Wert kennt.
 *   - Jeder Cloud-Zugriff läuft unter einer Instanzsperre; Fehler landen in "Letzte Meldung",
 *     nie als Abbruch des Skripts.
 *   - Variablen werden nur bei echter Änderung geschrieben.
 */

require_once __DIR__ . '/../libs/SaugroboterApi.php';
require_once __DIR__ . '/../libs/SaugroboterKarte.php';
require_once __DIR__ . '/../libs/SaugroboterTexte.php';
require_once __DIR__ . '/../libs/SaugroboterLive.php';

class X60Ultra extends IPSModule
{
    // true, wenn ApplyChanges geänderte Zugangsdaten/Verbindungseinstellungen erkannt hat
    protected $credChanged = true;
    use SaugroboterApi;
    use SaugroboterLive;

    // Stand der Kartendarstellung: ändert sich die Zeichnung, wird die Karte nach dem Update neu gezeichnet
    const RENDER_VERSION = 5;

    // AES-IV der Kartendaten aktueller Dreame-Modelle (X40/X50/X60)
    const MAP_IV = 'NRwnBj5FsNPgBNbT';

    // Befehle der Variable "Befehl"
    const CMD = [
        1 => 'Alles reinigen', 2 => 'Pause', 3 => 'Fortsetzen', 4 => 'Stopp', 5 => 'Zur Station',
        6 => 'Auswahl reinigen', 7 => 'Auswahl anhängen', 8 => 'Auswahl leeren', 9 => 'Vormerkung verwerfen',
        10 => 'Mopp waschen', 11 => 'Mopp trocknen', 12 => 'Trocknen beenden', 13 => 'Staub absaugen',
        14 => 'Hinweis quittieren', 15 => 'Orten'
    ];

    // Reinigungsprogramme für die Automatik: Name => Einstellungen (fehlende Werte = wie am Gerät)
    const PROGRAMS = [
        0 => ['Eigene Einstellungen', null],
        1 => ['Schnell saugen', ['Mode' => 0, 'Suction' => 1, 'Route' => 4]],
        2 => ['Gründlich saugen', ['Mode' => 0, 'Suction' => 3, 'Passes' => 2, 'Route' => 2]],
        3 => ['Saugen und wischen', ['Mode' => 2, 'Suction' => 1, 'Wetness' => 2]],
        4 => ['Erst saugen, dann wischen', ['Mode' => 3, 'Suction' => 2, 'Wetness' => 2]],
        5 => ['Nur wischen', ['Mode' => 1, 'Wetness' => 2]],
        6 => ['Leise (Nachtruhe)', ['Mode' => 0, 'Suction' => 0, 'Route' => 1]],
        7 => ['CleanGenius Routine', ['Mode' => 2, 'CleanGenius' => 1]],
        8 => ['CleanGenius Tiefenreinigung', ['Mode' => 2, 'CleanGenius' => 2]]
    ];

    // Zusatzwerte je nach Modell: Ident => [Name, siid, piid, Typ, Profil, Position]
    const EXTRAS = [
        'Charging'   => ['Lädt', 3, 2, 0, '~Switch', 14],
        'Progress'   => ['Fortschritt', 4, 63, 1, 'SAUG.Percent', 16],
        'TotalHours' => ['Reinigungszeit gesamt', 12, 2, 2, 'SAUG.Hours', 180],
        'TotalRuns'  => ['Reinigungen gesamt', 12, 3, 1, '', 181],
        'TotalArea'  => ['Fläche gesamt', 12, 4, 1, 'SAUG.Area', 182]
    ];

    public function Create()
    {
        parent::Create();

        // ---- Konfiguration ----
        $this->RegisterPropertyBoolean('Active', false);
        // Bestätigung des Nutzungshinweises (inoffizielle Schnittstelle, eigenes Konto)
        $this->RegisterPropertyBoolean('Consent', false);
        $this->RegisterPropertyString('Email', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyString('Region', 'eu');
        $this->RegisterPropertyString('DeviceFilter', '');
        $this->RegisterPropertyBoolean('VerifyTLS', true);
        $this->RegisterPropertyInteger('Interval', 60);
        $this->RegisterPropertyInteger('IntervalBusy', 15);
        $this->RegisterPropertyBoolean('Live', true);           // Echtzeit über MQTT (wie die App)
        $this->RegisterPropertyString('Rooms', '[]');
        $this->RegisterPropertyBoolean('MapImage', true);
        $this->RegisterPropertyInteger('MapRotate', -1);        // -1 = wie in der App
        $this->RegisterPropertyBoolean('MapPath', true);
        $this->RegisterPropertyString('MapFormat', 'auto');     // Zellformat der Karte (auto/shift/low6/low5)
        $this->RegisterPropertyInteger('WearWarn', 10);
        $this->RegisterPropertyInteger('HistoryCount', 5);
        $this->RegisterPropertyBoolean('DashboardBox', false);
        $this->RegisterPropertyString('BgUpload', '');           // Hintergrundbild der Kachel (Upload, wird sofort übernommen)
        $this->RegisterPropertyInteger('BgDim', 25);             // Abdunkeln des Hintergrunds in %
        $this->RegisterPropertyInteger('BgBlur', 0);             // Weichzeichnen des Hintergrunds in px
        $this->RegisterPropertyInteger('CardOpacity', 62);       // Deckkraft der Felder über dem Hintergrund in %
        $this->RegisterPropertyInteger('Theme', 0);              // Design der Kachel: 0 dunkel, 1 wie Gerät, 2 hell
        $this->RegisterPropertyInteger('CardGlass', 18);         // Milchglas-Stärke hinter den Feldern in px
        // Automatik
        $this->RegisterPropertyInteger('PresenceVariable', 0);
        $this->RegisterPropertyBoolean('PresenceInverted', false);
        $this->RegisterPropertyInteger('AutoDelay', 10);
        $this->RegisterPropertyInteger('AutoFrom', 9);
        $this->RegisterPropertyInteger('AutoTo', 19);
        $this->RegisterPropertyString('AutoDays', '12345');
        $this->RegisterPropertyInteger('AutoGap', 20);
        $this->RegisterPropertyInteger('AutoBattery', 60);
        $this->RegisterPropertyString('AutoRooms', '');
        $this->RegisterPropertyBoolean('AutoReturn', true);
        $this->RegisterPropertyInteger('AutoProgram', 0);        // Reinigungsprogramm der Automatik (siehe PROGRAMS)
        $this->RegisterPropertyString('AutoPlan', '[]');         // [{day, rooms}] – Räume je Wochentag
        // Benachrichtigungen
        $this->RegisterPropertyInteger('NotifyTarget', 0);
        $this->RegisterPropertyBoolean('NotifyDone', true);
        $this->RegisterPropertyBoolean('NotifyError', true);
        $this->RegisterPropertyBoolean('NotifyMaintenance', true);
        $this->RegisterPropertyBoolean('NotifyAuto', true);

        // ---- interner Zustand ----
        $this->RegisterAttributeString('Token', '');
        $this->RegisterAttributeString('Device', '');
        $this->RegisterAttributeString('Caps', '');         // {"18.1":1,...}; leer = noch nicht ermittelt
        $this->RegisterAttributeString('Maps', '[]');       // eingelesene Karten mit Räumen
        $this->RegisterAttributeInteger('Floor', -1);       // gewählte Karte
        $this->RegisterAttributeInteger('DeviceFloor', -1); // Karte, auf der das Gerät zuletzt stand
        $this->RegisterAttributeString('Queue', '[]');
        $this->RegisterAttributeString('QueueSettings', '{}'); // Einstellungen für die vorgemerkten Räume
        $this->RegisterAttributeInteger('Job', 0);          // 1 = Auftrag läuft
        $this->RegisterAttributeString('JobInfo', '{}');    // Werte des laufenden Auftrags
        $this->RegisterAttributeInteger('JobByAuto', 0);
        $this->RegisterAttributeInteger('JobRooms', 0);     // 1 = Raumauftrag (Auswahl danach leeren)
        $this->RegisterAttributeInteger('LastAuto', 0);
        $this->RegisterAttributeInteger('LastErrorCode', 0);
        $this->RegisterAttributeString('LastMaintenance', '[]');
        $this->RegisterAttributeString('LogRooms', '{}');   // Startzeit -> gereinigte Räume
        $this->RegisterAttributeInteger('PresenceWatched', 0);
        $this->RegisterAttributeString('MapMeta', '{}');    // Lage der Räume je Kartenbild (Beschriftung/Antippen)
        $this->RegisterAttributeString('LastLog', '');
        $this->RegisterAttributeString('LivePin', '');
        $this->RegisterAttributeString('BgType', 'jpeg');
        $this->RegisterAttributeString('CredKey', '');
        $this->RegisterAttributeInteger('RenderVersion', 0);
        $this->RegisterAttributeString('AutoDone', '{}');   // Zeitplan-Einträge, die heute schon gelaufen sind      // Prüfsumme der Zugangsdaten (Neuanmeldung nur bei Änderung)     // gemerktes Zertifikat des Live-Servers      // Startzeit der Fahrt hinter "Letzte Reinigung"

        // ---- Profile ----
        $this->Profile('SAUG.State', 1, 'Robot', '', '', SaugroboterTexte::States());
        $err = [];
        foreach (SaugroboterTexte::Errors() as $c => $e) $err[$c] = $e[0];
        $this->Profile('SAUG.Error', 1, 'Warning', '', '', $err);
        $this->Profile('SAUG.Command', 1, 'Execute', '', '', [0 => '–'] + self::CMD);
        $this->Profile('SAUG.Water', 1, 'Drops', '', '', [-1 => 'unbekannt', 0 => 'OK', 1 => 'fehlt', 2 => 'fast leer', 3 => 'leer']);
        $this->Profile('SAUG.Dirty', 1, 'Drops', '', '', [-1 => 'unbekannt', 0 => 'OK', 1 => 'voll oder fehlt']);
        $this->Profile('SAUG.Bag', 1, 'Container', '', '', [-1 => 'unbekannt', 0 => 'OK', 1 => 'fehlt', 2 => 'prüfen']);
        $this->Profile('SAUG.Mode', 1, 'Robot', '', '', [-1 => 'wie am Gerät', 0 => 'Saugen', 1 => 'Wischen', 2 => 'Saugen und wischen', 3 => 'Erst saugen, dann wischen']);
        $this->Profile('SAUG.Route', 1, 'Move', '', '', [-1 => 'wie am Gerät', 1 => 'Standard', 2 => 'Intensiv', 3 => 'Tief', 4 => 'Schnell']);
        $this->Profile('SAUG.Suction', 1, 'Speedo', '', '', [-1 => 'wie am Gerät', 0 => 'Leise', 1 => 'Standard', 2 => 'Stark', 3 => 'Turbo']);
        $this->Profile('SAUG.Wetness', 1, 'Drops', '', '', [-1 => 'wie am Gerät', 1 => 'Leicht feucht', 2 => 'Feucht', 3 => 'Nass']);
        $this->Profile('SAUG.Passes', 1, 'Repeat', '', '', [1 => '1×', 2 => '2×', 3 => '3×']);
        $this->Profile('SAUG.CleanGenius', 1, 'Bulb', '', '', [-1 => 'wie am Gerät', 0 => 'Aus', 1 => 'Routine', 2 => 'Tiefenreinigung']);
        $this->Profile('SAUG.Percent', 1, 'Intensity', '', ' %', null, 0, 100);
        $this->Profile('SAUG.Wear', 1, 'Gauge', '', ' %', null, 0, 100);
        $this->Profile('SAUG.Minutes', 1, 'Clock', '', ' min');
        $this->Profile('SAUG.Area', 1, 'Distance', '', ' m²');
        $this->Profile('SAUG.Hours', 2, 'Clock', '', ' h');
        $this->Profile($this->FloorProfile(), 1, 'Stairs', '', '', [-1 => '–']);
        $this->Profile($this->RoomProfile(), 1, 'Move', '', '', [0 => 'Raum wählen …']);

        // ---- Status ----
        $this->RegisterVariableInteger('State', 'Zustand', 'SAUG.State', 10);
        $this->RegisterVariableInteger('Error', 'Fehler', 'SAUG.Error', 11);
        $this->RegisterVariableString('ErrorHint', 'Fehler – was tun?', '', 12);
        $this->RegisterVariableInteger('Battery', 'Akku', '~Battery.100', 13);
        $this->RegisterVariableString('Room', 'Aktueller Raum', '', 15);
        $this->RegisterVariableInteger('CleanTime', 'Reinigungsdauer', 'SAUG.Minutes', 17);
        $this->RegisterVariableInteger('CleanArea', 'Gereinigte Fläche', 'SAUG.Area', 18);
        $this->RegisterVariableInteger('CleanWater', 'Frischwasser', 'SAUG.Water', 20);
        $this->RegisterVariableInteger('DirtyWater', 'Schmutzwasser', 'SAUG.Dirty', 21);
        $this->RegisterVariableInteger('DustBag', 'Staubbeutel', 'SAUG.Bag', 22);

        // ---- Steuerung ----
        $this->RegisterVariableInteger('Command', 'Befehl', 'SAUG.Command', 30);
        $this->EnableAction('Command');
        $this->RegisterVariableInteger('Floor', 'Etage', $this->FloorProfile(), 31);
        $this->EnableAction('Floor');
        $this->RegisterVariableInteger('CleanRoom', 'Raum reinigen', $this->RoomProfile(), 32);
        $this->EnableAction('CleanRoom');
        $this->RegisterVariableString('Queue', 'Vorgemerkt', '', 33);
        $presets = [
            'Mode' => ['Reinigungsmodus', 'SAUG.Mode', 40], 'Route' => ['Reinigungsroute', 'SAUG.Route', 41],
            'Suction' => ['Saugkraft', 'SAUG.Suction', 42], 'Wetness' => ['Wischfeuchte', 'SAUG.Wetness', 43],
            'Passes' => ['Durchgänge', 'SAUG.Passes', 44], 'CleanGenius' => ['CleanGenius', 'SAUG.CleanGenius', 45]
        ];
        foreach ($presets as $ident => $p) {
            $new = @$this->GetIDForIdent($ident) === false;
            $this->RegisterVariableInteger($ident, $p[0], $p[1], $p[2]);
            $this->EnableAction($ident);
            // neue Vorwahl steht auf "wie am Gerät" (0 wäre z. B. bei der Saugkraft "Leise")
            if ($new) $this->SetVal($ident, $ident == 'Passes' ? 1 : -1);
        }

        // ---- Automatik ----
        $this->RegisterVariableBoolean('AutoAway', 'Reinigen bei Abwesenheit', '~Switch', 50);
        $this->EnableAction('AutoAway');
        $this->RegisterVariableString('AutoStatus', 'Automatik', '', 51);
        $progs = [];
        foreach (self::PROGRAMS as $id => $pr) $progs[$id] = $pr[0];
        $this->Profile('SAUG.Program', 1, 'Robot', '', '', $progs);
        $newProg = @$this->GetIDForIdent('AutoProgram') === false;
        $this->RegisterVariableInteger('AutoProgram', 'Automatik-Programm', 'SAUG.Program', 52);
        $this->EnableAction('AutoProgram');
        $this->Profile('SAUG.AutoMode', 1, 'Clock', '', '', [0 => 'bei Abwesenheit', 1 => 'zur Uhrzeit']);
        $this->RegisterVariableInteger('AutoMode', 'Automatik startet', 'SAUG.AutoMode', 53);
        $newTime = @$this->GetIDForIdent('AutoTime') === false;
        $this->RegisterVariableString('AutoTime', 'Automatik-Uhrzeit', '', 54);
        $this->EnableAction('AutoTime');
        if ($newTime) $this->SetVal('AutoTime', '10:00');
        $this->EnableAction('AutoMode');
        $this->RegisterVariableInteger('CleanProgram', 'Programm', 'SAUG.Program', 39);
        $this->EnableAction('CleanProgram');
        // bisher als Instanz-Einstellung gespeichert: einmalig übernehmen
        if ($newProg) $this->SetVal('AutoProgram', $this->ReadPropertyInteger('AutoProgram'));

        // ---- Wartung, Historie, Diagnose ----
        $this->RegisterVariableString('Maintenance', 'Wartung fällig', '', 150);
        $this->RegisterVariableString('LastRun', 'Letzte Reinigung', '', 190);
        $this->RegisterVariableString('History', 'Verlauf', '~TextBox', 191);
        $this->RegisterVariableBoolean('Online', 'Verbunden', '~Switch', 200);
        $this->RegisterVariableString('Message', 'Letzte Meldung', '', 201);
        $this->RegisterVariableString('Model', 'Gerät', '', 202);
        $this->RegisterVariableString('DeviceSettings', 'Einstellungen am Gerät', '', 203);

        $this->RegisterTimer('Poll', 0, 'SAUG_Poll($_IPS["TARGET"]);');
        $this->RegisterTimer('Auto', 0, 'SAUG_AutoCheck($_IPS["TARGET"]);');
        $this->RegisterTimer('LiveCheck', 0, 'SAUG_LiveCheck($_IPS["TARGET"]);');
        $this->RegisterTimer('LiveWork', 0, 'SAUG_LiveWork($_IPS["TARGET"]);');

        if (method_exists($this, 'SetVisualizationType')) $this->SetVisualizationType(1);
    }

    public function Destroy()
    {
        if (!IPS_InstanceExists($this->InstanceID)) {
            foreach ([$this->FloorProfile(), $this->RoomProfile()] as $p) {
                if (IPS_VariableProfileExists($p)) IPS_DeleteVariableProfile($p);
            }
        }
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Anmeldung und Gerät nur frisch ermitteln, wenn sich Zugangsdaten/Verbindung geändert haben
        // (Änderungen am Raumplan o. Ä. – auch aus der Kachel – lassen Anmeldung und Live-Verbindung in Ruhe)
        $cred = md5(implode('|', [$this->ReadPropertyString('Email'), $this->ReadPropertyString('Password'), $this->ReadPropertyString('Region'),
            $this->ReadPropertyString('DeviceFilter'), intval($this->ReadPropertyBoolean('VerifyTLS')), intval($this->ReadPropertyBoolean('Live')),
            intval($this->ReadPropertyBoolean('Active')), intval($this->ReadPropertyBoolean('Consent'))]));
        $this->credChanged = $cred !== $this->ReadAttributeString('CredKey');
        if ($this->credChanged) {
            $this->WriteAttributeString('Token', '');
            $this->WriteAttributeString('Device', '');
            $this->WriteAttributeString('CredKey', $cred);
        }

        $this->SyncCapabilities();
        $this->SyncRooms();
        if ($this->ReadPropertyBoolean('DashboardBox')) {
            $this->RegisterVariableString('Dashboard', 'Übersicht', '~HTMLBox', 1);
        } elseif (@$this->GetIDForIdent('Dashboard')) {
            $this->UnregisterVariable('Dashboard');
        }
        $this->WatchPresence();
        if ($this->ReadPropertyBoolean('Live')) {
            $this->RegisterVariableBoolean('Live', 'Live-Verbindung', '~Switch', 199);
        } elseif (@$this->GetIDForIdent('Live')) {
            $this->UnregisterVariable('Live');
        }

        // Passwort nie im Klartext speichern: die Cloud erwartet ohnehin nur einen Hash.
        // Einmalig umwandeln, danach steht in den Einstellungen (und Backups) nur noch "hash:...".
        // Hochgeladenes Hintergrundbild verkleinert als Medienobjekt ablegen und das Upload-Feld leeren
        // (sonst stünde das ganze Bild in den Einstellungen und jedem Backup)
        if ($this->ReadPropertyString('BgUpload') !== '') {
            $this->StoreBackground($this->ReadPropertyString('BgUpload'));
            IPS_SetProperty($this->InstanceID, 'BgUpload', '');
            IPS_ApplyChanges($this->InstanceID);
            return;
        }

        $pw = $this->ReadPropertyString('Password');
        if ($pw !== '' && strpos($pw, 'hash:') !== 0) {
            IPS_SetProperty($this->InstanceID, 'Password', 'hash:' . $this->PasswordHash($pw));
            IPS_ApplyChanges($this->InstanceID);
            return;
        }

        if (!$this->ReadPropertyBoolean('Consent')) {
            $this->SetStatus(202);
            $this->SetTimerInterval('Poll', 0);
            $this->SetTimerInterval('Auto', 0);
            $this->LiveApply();
            return;
        }
        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SetStatus(104);
            $this->SetTimerInterval('Poll', 0);
            $this->SetTimerInterval('Auto', 0);
            $this->LiveApply();
            return;
        }
        if (trim($this->ReadPropertyString('Email')) === '' || $this->ReadPropertyString('Password') === '') {
            $this->SetStatus(201);
            $this->SetTimerInterval('Poll', 0);
            $this->SetTimerInterval('Auto', 0);
            $this->LiveApply();
            return;
        }
        $this->SetStatus(102);
        $this->LiveApply();
        $this->SetPollInterval();
        $this->UpdateAutoTimer();
        $this->RefreshViews();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($this->LiveMessageSink($SenderID, $Message, $Data)) return;
        if ($Message == IPS_KERNELSTARTED) { $this->WatchPresence(); return; }
        if ($Message == VM_UPDATE && $SenderID == $this->ReadPropertyInteger('PresenceVariable') && !empty($Data[1])) {
            $this->PresenceChanged();
        }
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $rows = [];
        foreach ($this->RoomList(true) as $r) {
            $rows[] = ['use' => $r['use'], 'floor' => $r['floor'], 'map' => $r['map'], 'seg' => $r['seg'],
                'app' => $r['app'], 'alias' => $r['alias']];
        }
        $this->FormSetValues($form['elements'], 'Rooms', $rows);
        $this->FormRoomPlan($form['elements']);
        $this->FormStatus($form['elements']);
        $this->FormCustomDays($form['elements']);
        // Versionszeile ganz unten
        $lib = json_decode(@file_get_contents(__DIR__ . '/../library.json'), true);
        if (is_array($lib)) {
            $form['actions'][] = ['type' => 'Label', 'italic' => true, 'caption' => $lib['name'] . ' · Version ' . $lib['version']
                . ' · Build ' . $lib['build'] . ' · ' . substr(strval($lib['date']), 6, 2) . '.' . substr(strval($lib['date']), 4, 2) . '.'
                . substr(strval($lib['date']), 0, 4) . ' · © ' . $lib['author']];
        }
        return json_encode($form);
    }

    // Tage-Auswahl: eigene, ältere Angaben (z. B. "1357") als zusätzliche Option erhalten
    private function FormCustomDays(&$elements)
    {
        $cur = preg_replace('/[^1-7]/', '', $this->ReadPropertyString('AutoDays'));
        foreach ($elements as &$e) {
            if (isset($e['items'])) $this->FormCustomDays($e['items']);
            if (!isset($e['name']) || $e['name'] !== 'AutoDays') continue;
            if ($cur === '' || in_array($cur, array_column($e['options'], 'value'), true)) continue;
            $names = [1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 7 => 'So'];
            $e['options'][] = ['caption' => implode(', ', array_map(function ($d) use ($names) { return $names[intval($d)]; }, str_split($cur))), 'value' => $cur];
        }
        unset($e);
    }

    // Statusblock oben in der Instanz: Texte je Zeile
    private function StatusLines()
    {
        $online = @$this->GetIDForIdent('Online') ? $this->GetValue('Online') : false;
        $dev = json_decode($this->ReadAttributeString('Device'), true);
        $cloud = !$this->ReadPropertyBoolean('Active') ? 'Instanz nicht aktiv'
            : (($online ? '✅ verbunden' : '⚠️ keine Verbindung') . (is_array($dev) && !empty($dev['name']) ? ' – ' . $dev['name'] . ' (' . $dev['model'] . ')' : ''));
        $pin = json_decode($this->ReadAttributeString('LivePin'), true);
        $cert = is_array($pin) && !empty($pin['fp'])
            ? '🔒 gemerkt am ' . date('d.m.Y H:i', intval($pin['at'])) . ' – ' . substr($pin['fp'], 0, 23) . '…'
            : 'noch nicht gemerkt (geschieht beim ersten Live-Kontakt)';
        $msg = @$this->GetIDForIdent('Message') ? strval($this->GetValue('Message')) : '';
        return [
            'StatusCloud' => 'Cloud: ' . $cloud,
            'StatusLive' => 'Live-Verbindung: ' . $this->LiveStatusText(),
            'StatusCert' => 'Server-Zertifikat: ' . $cert,
            'StatusMessage' => 'Letzte Meldung: ' . ($msg !== '' ? $msg : '–')
        ];
    }

    private function FormStatus(&$elements)
    {
        $lines = $this->StatusLines();
        foreach ($elements as &$e) {
            if (isset($e['items'])) $this->FormStatus($e['items']);
            if (isset($e['name']) && isset($lines[$e['name']])) $e['caption'] = $lines[$e['name']];
        }
        unset($e);
    }

    // Offenes Konfigurationsfenster nachführen (ohne offenes Fenster wirkungslos)
    protected function UpdateStatusForm()
    {
        foreach ($this->StatusLines() as $name => $text) $this->UpdateFormField($name, 'caption', $text);
    }

    // Raumplan: je Raum eine Spalte zum Anhaken statt eines Textfelds
    private function FormRoomPlan(&$elements)
    {
        $rooms = $this->RoomList();
        if (!count($rooms)) return;                    // noch keine Räume eingelesen: Textfeld bleibt
        $multi = count($this->Maps()) > 1;
        foreach ($elements as &$e) {
            if (isset($e['items'])) $this->FormRoomPlan($e['items']);
            if (!isset($e['name']) || $e['name'] !== 'AutoPlan') continue;
            $cols = [];
            foreach ($e['columns'] as $c) if ($c['name'] !== 'rooms') $cols[] = $c;
            foreach ($rooms as $r) {
                $cols[] = ['caption' => $r['name'] . ($multi ? ' (' . $r['floor'] . ')' : ''), 'name' => 'r' . $r['code'],
                    'width' => '110px', 'add' => false, 'edit' => ['type' => 'CheckBox']];
            }
            $cols[] = ['caption' => 'frei', 'name' => 'off', 'width' => '70px', 'add' => false, 'edit' => ['type' => 'CheckBox']];
            $e['columns'] = $cols;
            // Gespeicherte Zeilen (auch alte mit Textfeld) in Häkchen umsetzen
            $vals = [];
            $plan = json_decode($this->ReadPropertyString('AutoPlan'), true);
            if (is_array($plan)) foreach ($plan as $row) {
                $v = ['day' => isset($row['day']) ? intval($row['day']) : 0, 'time' => strval($row['time'] ?? ''), 'prog' => isset($row['prog']) ? intval($row['prog']) : -1, 'off' => false];
                $codes = $this->PlanCodes($row);
                if ($codes === '-') $v['off'] = true;
                foreach ($rooms as $r) $v['r' . $r['code']] = is_array($codes) && in_array($r['code'], $codes, true);
                $vals[] = $v;
            }
            $e['values'] = $vals;
            // Uhrzeiten außerhalb des Viertelstunden-Rasters (z. B. aus der Kachel) als Auswahl erhalten
            foreach ($e['columns'] as &$c) {
                if ($c['name'] !== 'time' || !isset($c['edit']['options'])) continue;
                $have = array_column($c['edit']['options'], 'value');
                foreach ($vals as $vv) {
                    if (!empty($vv['time']) && !in_array($vv['time'], $have, true)) { $c['edit']['options'][] = ['caption' => $vv['time'], 'value' => $vv['time']]; $have[] = $vv['time']; }
                }
            }
            unset($c);
        }
        unset($e);
    }

    // Räume einer Raumplan-Zeile: '-' = frei, [] = alles, sonst Raumcodes
    private function PlanCodes($row)
    {
        if (!empty($row['off'])) return '-';
        $codes = [];
        foreach ($row as $k => $v) {
            if ($v === true && preg_match('/^r(\d+)$/', $k, $m)) $codes[] = intval($m[1]);
        }
        if (count($codes)) return $codes;
        $text = isset($row['rooms']) ? trim(strval($row['rooms'])) : '';
        if ($text === '-') return '-';
        return $text === '' ? [] : $this->ResolveRooms($text);
    }

    private function FormSetValues(&$elements, $name, $values)
    {
        foreach ($elements as &$e) {
            if (isset($e['name']) && $e['name'] == $name) $e['values'] = $values;
            if (isset($e['items'])) $this->FormSetValues($e['items'], $name, $values);
        }
    }

    // =========================================================================
    // Bedienung
    // =========================================================================

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Command':
                $this->RunCommand(intval($Value));
                return;
            case 'Floor':
                $this->SelectFloor(intval($Value));
                return;
            case 'CleanRoom':
                if (intval($Value) > 0) $this->CleanRooms(strval(intval($Value)));
                return;
            case 'Mode': case 'Route': case 'Suction': case 'Wetness': case 'Passes': case 'CleanGenius':
                if (!$this->ValidPreset($Ident, $Value)) return;
                $this->SetVal($Ident, intval($Value));
                $this->RefreshViews();
                return;
            case 'AutoTime':
                if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim(strval($Value)), $t) || intval($t[1]) > 23 || intval($t[2]) > 59) {
                    $this->Note('Uhrzeit bitte als HH:MM angeben.', 'err'); $this->RefreshViews(); return;
                }
                $this->SetVal('AutoTime', sprintf('%02d:%02d', intval($t[1]), intval($t[2])));
                $this->UpdateAutoTimer();
                $this->Note('Automatik startet um ' . $this->GetValue('AutoTime') . ' Uhr.', 'ok');
                $this->RefreshViews();
                return;
            case 'AutoMode':
                $this->SetVal('AutoMode', intval($Value) == 1 ? 1 : 0);
                $this->UpdateAutoTimer();
                $this->RefreshViews();
                return;
            case 'CleanProgram':
                if (!isset(self::PROGRAMS[intval($Value)])) return;
                $this->SetVal('CleanProgram', intval($Value));
                $this->RefreshViews();
                return;
            case 'TileStart':
                // Kachel: Hauptknopf – ausgewählte Räume, sonst alles; mit dem gewählten Programm
                $over = $this->ProgramSettings(intval($this->GetValue('CleanProgram')));
                if ($over === null) $over = $this->PresetValues();
                $sel = $this->SelectedRooms();
                if (count($sel)) $this->CleanRoomsWith($sel, json_encode($over));
                elseif ($this->ReadAttributeInteger('Job') == 1) $this->Note('Es läuft bereits eine Reinigung.', 'err');
                else $this->Locked(function () use ($over) { return $this->StartAll($over); });
                $this->RefreshViews();
                return;
            case 'AutoProgram':
                if (!isset(self::PROGRAMS[intval($Value)])) return;
                $this->SetVal('AutoProgram', intval($Value));
                $this->Note('Automatik-Programm: ' . self::PROGRAMS[intval($Value)][0], 'ok');
                $this->RefreshViews();
                return;
            case 'TilePlan':
                // Kachel: Raumplan speichern (gleiche Form wie in der Instanz)
                $rows = $this->Json($Value);
                if (!is_array($rows)) return;
                $clean = [];
                foreach ($rows as $r) {
                    if (!is_array($r)) continue;
                    $day = intval($r['day'] ?? 0);
                    if ($day < 0 || $day > 9) continue;
                    $prog = intval($r['prog'] ?? -1);
                    $tm = isset($r['time']) && preg_match('/^(\d{1,2}):(\d{2})$/', trim(strval($r['time'])), $mm) && intval($mm[1]) < 24 && intval($mm[2]) < 60
                        ? sprintf('%02d:%02d', intval($mm[1]), intval($mm[2])) : '';
                    $row = ['day' => $day, 'time' => $tm, 'prog' => ($prog >= 0 && isset(self::PROGRAMS[$prog])) ? $prog : -1, 'off' => !empty($r['off'])];
                    foreach ($r as $k => $v) if (preg_match('/^r\d{1,5}$/', $k)) $row[$k] = (bool)$v;
                    $clean[] = $row;
                    if (count($clean) >= 20) break;
                }
                IPS_SetProperty($this->InstanceID, 'AutoPlan', json_encode($clean));
                IPS_ApplyChanges($this->InstanceID);
                $this->Note('Raumplan gespeichert.', 'ok');
                $this->RefreshViews();
                return;
            case 'TileProgram':
                // Kachel: Programm jetzt starten – für die ausgewählten Räume, sonst alles
                $d = $this->Json($Value);
                $over = is_array($d) ? $d : [];
                $sel = $this->SelectedRooms();
                if (count($sel)) $this->CleanRoomsWith($sel, json_encode($over));
                else $this->Locked(function () use ($over) { return $this->StartAll($over); });
                $this->RefreshViews();
                return;
            case 'AutoAway':
                $this->SetVal('AutoAway', (bool)$Value);
                $this->UpdateAutoTimer();
                $this->RefreshViews();
                return;
            case 'TileReset':
                $this->ResetConsumable(strval($Value));
                return;
            case 'TileClean':
                // Kachel: Raum per Antippen mit Einstellungen aus der Nachfrage reinigen
                $d = $this->Json($Value);
                if (!is_array($d) || empty($d['code'])) return;
                $code = intval($d['code']);
                unset($d['code']);
                $this->CleanRoomsWith([$code], json_encode($d));
                $this->RefreshViews();
                return;
            case 'TileCleanSel':
                // Kachel: ausgewählte Räume mit Einstellungen aus der Nachfrage reinigen
                $d = $this->Json($Value);
                $sel = $this->SelectedRooms();
                if (count($sel) == 0) { $this->Note('Es ist kein Raum ausgewählt.', 'err'); $this->RefreshViews(); return; }
                $this->CleanRoomsWith($sel, json_encode(is_array($d) ? $d : []));
                $this->RefreshViews();
                return;
            case 'TileRoom':
                // Kachel: Raum in der Auswahl umschalten (Wert = Raumcode)
                $id = @$this->GetIDForIdent('Sel' . intval($Value));
                if ($id) $this->RequestAction('Sel' . intval($Value), !GetValueBoolean($id));
                return;
        }
        if (strpos($Ident, 'Sel') === 0) {
            $code = intval(substr($Ident, 3));
            if ((bool)$Value && intdiv($code, 100) != $this->ActiveFloor()) {
                $this->Note('Der Raum liegt auf einer anderen Etage – erst die Etage wechseln.', 'err');
                return;
            }
            $this->SetVal($Ident, (bool)$Value);
            $this->RefreshViews();
            return;
        }
        throw new Exception('Unbekannte Aktion: ' . $Ident);
    }

    private function RunCommand($cmd)
    {
        $this->SetVal('Command', $cmd);
        switch ($cmd) {
            case 1: $this->CleanAll(); break;
            case 2: $this->Pause(); break;
            case 3: $this->Resume(); break;
            case 4: $this->Stop(); break;
            case 5: $this->Dock(); break;
            case 6: $this->CleanSelection(); break;
            case 7: $this->QueueSelection(); break;
            case 8: $this->ClearSelection(); break;
            case 9: $this->WriteQueue([]); break;
            case 10: $this->WashMop(); break;
            case 11: $this->DryMop(true); break;
            case 12: $this->DryMop(false); break;
            case 13: $this->EmptyDustBin(); break;
            case 14: $this->AcknowledgeWarning(); break;
            case 15: $this->Locate(); break;
        }
        $this->SetVal('Command', 0);
        $this->RefreshViews();
    }

    // ---- einfache Befehle ----
    public function Pause()  { return $this->Send('Pause', 2, 2); }
    public function Resume() { return $this->Send('Fortsetzen', 2, 1); }
    public function Stop()   { return $this->Send('Stopp', 4, 2); }
    public function Dock()   { return $this->Send('Zur Station', 3, 1); }
    public function Locate() { return $this->Send('Orten', 7, 1); }
    public function WashMop() { return $this->Send('Mopp waschen', 4, 4, [['piid' => 10, 'value' => '2,1']]); }
    public function DryMop($On) { return $this->Send($On ? 'Mopp trocknen' : 'Trocknen beenden', 4, 4, [['piid' => 10, 'value' => $On ? '3,1' : '3,0']]); }
    public function EmptyDustBin() { return $this->Send('Staub absaugen', 15, 1); }
    // Hinweis quittieren: das Gerät erwartet den Code des Hinweises (CLEANING_PROPERTIES = "[68]").
    // Die Meldung "Frischwasser fast leer" wird stattdessen über 4/41 bestätigt.
    public function AcknowledgeWarning()
    {
        $code = $this->GetValue('Error');
        return $this->Locked(function () use ($code) {
            if (in_array($code, SaugroboterTexte::ClearableCodes(), true)) {
                $ok = $this->MiotAction(4, 3, [['piid' => 10, 'value' => '[' . $code . ']']]);
            } else {
                $v = $this->MiotGet([[4, 41]]);
                $ok = isset($v['4.41']) && intval($v['4.41']) >= 2 ? $this->MiotSet([[4, 41, 1]]) : false;
                if (!$ok && $this->dcLastError === '') $this->dcLastError = 'dieser Hinweis lässt sich nur am Gerät bzw. in der App beheben';
            }
            $this->Result($ok, 'Hinweis quittieren');
            if ($ok) {
                $this->SetVal('Error', 0);
                $this->SetVal('ErrorHint', '');
                $this->PollSoon();
            }
            return $ok;
        });
    }

    private function Send($label, $siid, $aiid, $in = [])
    {
        return $this->Locked(function () use ($label, $siid, $aiid, $in) {
            $ok = $this->MiotAction($siid, $aiid, $in);
            $this->Result($ok, $label);
            if ($ok) $this->PollSoon();
            return $ok;
        });
    }

    // Alles reinigen (auf der gewählten Etage)
    public function CleanAll()
    {
        return $this->Locked(function () { return $this->StartAll([]); });
    }

    // Alles reinigen; $over = Einstellungen nur für diese Fahrt (leer = Vorwahlen)
    private function StartAll($over)
    {
        if (!$this->PrepareFloor($this->ActiveFloor())) return false;
        $this->ApplyPresets($over);
        $ok = $this->MiotAction(2, 1);
        $this->Result($ok, 'Alles reinigen');
        if ($ok) { $this->WriteAttributeInteger('JobRooms', 0); $this->PollSoon(); }
        return $ok;
    }

    /**
     * Räume in einem Durchgang reinigen.
     * $Rooms: Raumcodes (Etage*100+Segment), Segment-IDs der gewählten Etage oder Raumnamen,
     * als Liste oder durch Komma getrennt – z. B. "Küche, Flur" oder "5,6" oder [105, 106].
     */
    public function CleanRooms($Rooms)
    {
        return $this->CleanRoomsWith($Rooms, '{}');
    }

    /**
     * Wie CleanRooms, aber mit eigenen Einstellungen nur für diese Fahrt (die Vorwahlen bleiben unverändert).
     * $Settings: JSON, z. B. '{"Mode":1,"Suction":2,"Wetness":3,"Route":2,"Passes":2,"CleanGenius":0}'
     * – fehlende Werte kommen aus den Vorwahlen, -1 = wie am Gerät.
     * Läuft gerade eine Reinigung, werden die Räume samt Einstellungen für danach vorgemerkt.
     */
    public function CleanRoomsWith($Rooms, $Settings)
    {
        $over = $this->Json($Settings);
        if (!is_array($over)) $over = [];
        $codes = $this->ResolveRooms($Rooms);
        if (count($codes) == 0) { $this->Note('Keine passenden Räume gefunden.', 'err'); return false; }
        $floors = array_unique(array_map(function ($c) { return intdiv($c, 100); }, $codes));
        if (count($floors) > 1) { $this->Note('Ein Durchgang kann nur Räume einer Etage reinigen.', 'err'); return false; }
        if ($this->ReadAttributeInteger('Job') == 1 && count($over)) {
            $q = $this->ReadQueue();
            foreach ($codes as $c) if (!in_array($c, $q, true)) $q[] = $c;
            $this->WriteAttributeString('QueueSettings', json_encode($over));
            $this->WriteQueue($q);
            $this->Note('Vorgemerkt: ' . $this->RoomNames($q), 'ok');
            $this->RefreshViews();
            return true;
        }
        return $this->Locked(function () use ($codes, $floors, $over) {
            return $this->StartRooms(reset($floors), $codes, $over);
        });
    }

    // Einstellung für diese Fahrt: Übersteuerung, sonst Vorwahl
    private function Setting($name, $over)
    {
        $v = is_array($over) && isset($over[$name]) ? intval($over[$name]) : $this->GetValue($name);
        return $this->ValidPreset($name, $v) ? $v : ($name == 'Passes' ? 1 : -1);
    }

    // Nur Werte, die das Profil der Vorwahl kennt (Kachel und Skripte können beliebige Zahlen schicken)
    private function ValidPreset($name, $v)
    {
        $ok = ['Mode' => [-1, 0, 1, 2, 3], 'Route' => [-1, 1, 2, 3, 4], 'Suction' => [-1, 0, 1, 2, 3],
            'Wetness' => [-1, 1, 2, 3], 'Passes' => [1, 2, 3], 'CleanGenius' => [-1, 0, 1, 2]];
        return isset($ok[$name]) && in_array(intval($v), $ok[$name], true);
    }

    public function CleanSelection()
    {
        $sel = $this->SelectedRooms();
        if (count($sel) == 0) { $this->Note('Es ist kein Raum ausgewählt.', 'err'); return false; }
        return $this->CleanRooms($sel);
    }

    public function ClearSelection()
    {
        foreach ($this->RoomList() as $r) {
            $id = @$this->GetIDForIdent('Sel' . $r['code']);
            if ($id && GetValueBoolean($id)) SetValueBoolean($id, false);
        }
        $this->RefreshViews();
        return true;
    }

    // Auswahl vormerken: läuft gerade ein Auftrag, startet sie danach als eigener Durchgang
    public function QueueSelection()
    {
        $sel = $this->SelectedRooms();
        if (count($sel) == 0) { $this->Note('Es ist kein Raum ausgewählt.', 'err'); return false; }
        if ($this->ReadAttributeInteger('Job') == 0) return $this->CleanSelection();
        $q = $this->ReadQueue();
        foreach ($sel as $c) if (!in_array($c, $q, true)) $q[] = $c;
        $this->WriteQueue($q);
        $this->ClearSelection();
        $this->Note('Vorgemerkt: ' . $this->RoomNames($q), 'ok');
        return true;
    }

    // Etage wählen. Während eines Auftrags wird nur die Anzeige umgestellt – der Wechsel am
    // Gerät würde die laufende Reinigung abbrechen. Das Gerät folgt beim nächsten Start.
    public function SelectFloor($MapID)
    {
        $maps = $this->Maps();
        if (!isset($maps[$MapID])) { $this->Note('Unbekannte Etage ' . $MapID . '.', 'err'); return false; }
        $this->WriteAttributeInteger('Floor', intval($MapID));
        $this->ShowFloor();
        if ($this->ReadAttributeInteger('Job') == 0 && $this->ReadPropertyBoolean('Active')) {
            $this->Locked(function () use ($MapID) { return $this->PrepareFloor(intval($MapID)); });
        } else {
            $this->Note('Etage in der Anzeige gewechselt – das Gerät folgt beim nächsten Start.', 'info');
        }
        return true;
    }

    public function ResetConsumable($Part)
    {
        foreach (SaugroboterTexte::Consumables() as $ident => $c) {
            if (strcasecmp($ident, $Part) != 0 && strcasecmp('Wear' . $ident, $Part) != 0 && strcasecmp($c[0], $Part) != 0) continue;
            $ok = $this->Send($c[0] . ' zurücksetzen', $c[1], 1);
            if ($ok) $this->SetBuffer('SlowAt', '0');   // neuen Wert beim nächsten Abruf holen
            return $ok;
        }
        $this->Note('Unbekanntes Verschleißteil: ' . $Part);
        return false;
    }

    private function StartRooms($floor, $codes, $over = [])
    {
        if (!$this->PrepareFloor($floor)) return false;
        $cur = $this->ApplyPresets($over, true);

        // Saugkraft und Feuchte gehen je Raum im Startbefehl mit; "wie am Gerät" = aktueller Gerätewert
        $fan = $this->Setting('Suction', $over);
        $water = $this->Setting('Wetness', $over);
        if ($fan < 0) $fan = isset($cur['4.4']) ? intval($cur['4.4']) : 1;
        if ($water < 1) $water = isset($cur['4.5']) ? intval($cur['4.5']) : 2;
        $passes = max(1, min(3, $this->Setting('Passes', $over)));
        $list = [];
        foreach ($codes as $c) {
            // letztes Feld = Reihenfolge; aktuelle Modelle erwarten hier 1 (Reihenfolge aus der App)
            $list[] = [$c % 100, $passes, $fan, $water, 1];
        }
        $ok = $this->MiotAction(4, 1, [
            ['piid' => 1, 'value' => 18],
            ['piid' => 10, 'value' => json_encode(['selects' => $list])]
        ]);
        $this->Result($ok, 'Raumreinigung: ' . $this->RoomNames($codes));
        if ($ok) {
            $this->WriteAttributeInteger('JobRooms', 1);
            $this->PollSoon();
        }
        return $ok;
    }

    // Schaltet das Gerät bei Bedarf auf die Karte der Etage.
    private function PrepareFloor($floor)
    {
        if ($floor < 0 || count($this->Maps()) < 2) return true;
        if ($this->ReadAttributeInteger('DeviceFloor') == $floor) return true;
        $ok = $this->MiotAction(6, 2, [['piid' => 4, 'value' => json_encode(['sm' => new stdClass(), 'mapid' => $floor])]]);
        if (!$ok) { $this->Result(false, 'Etagenwechsel'); return false; }
        IPS_Sleep(3000);   // das Gerät braucht einen Moment, bis die Karte geladen ist
        $this->WriteAttributeInteger('DeviceFloor', $floor);
        $this->WriteAttributeInteger('Floor', $floor);
        $this->ShowFloor();
        return true;
    }

    // Vorwahlen vor einem Start ans Gerät schicken
    /**
     * Vorwahlen vor dem Start ans Gerät geben – mit so wenig Cloud-Aufrufen wie möglich:
     * aktuelle Gerätewerte kommen aus dem Zwischenspeicher (Abfrage/Live), alle Änderungen
     * gehen in einem einzigen set_properties. Bei Raumreinigung stecken Saugkraft und Feuchte
     * ohnehin im Startbefehl und werden nicht extra gesetzt.
     * Rückgabe: Gerätewerte ['4.4' => …, '4.5' => …] für den Startbefehl.
     */
    private function ApplyPresets($over = [], $rooms = false)
    {
        $fan = $this->Setting('Suction', $over);
        $wet = $this->Setting('Wetness', $over);
        $route = $this->Setting('Route', $over);
        $cg = $this->Setting('CleanGenius', $over);
        $mode = $this->Setting('Mode', $over);

        $cur = $this->DevCfg();
        $need = ($cg >= 0 || $fan >= 0 || $wet >= 1 || $route >= 1) && !isset($cur['4.50'])
            || ($mode >= 0 && !isset($cur['4.23'])) || ($rooms && ($fan < 0 || $wet < 1) && !isset($cur['4.4'], $cur['4.5']));
        if ($need) {
            $got = $this->MiotGet([[4, 23], [4, 50], [4, 4], [4, 5]]);
            if (is_array($got)) { $cur = $got + $cur; $this->DevCfg($got); }
        }

        $set = [];
        // CleanGenius bestimmt Saugkraft, Feuchte und Route selbst. Wer diese Werte vorwählt,
        // meint sie auch – dann CleanGenius für diese Fahrt ausschalten.
        $devCg = isset($cur['4.50']) ? $this->AutoSwitch($cur['4.50'], 'SmartHost') : null;
        if ($cg < 0 && $devCg !== null && $devCg > 0 && ($fan >= 0 || $wet >= 1 || $route >= 1)) $cg = 0;
        if ($cg >= 0 && $devCg !== null && $cg != $devCg) $set[] = [4, 50, json_encode(['k' => 'SmartHost', 'v' => $cg])];
        if ($mode >= 0 && isset($cur['4.23'])) {
            // Moduswert ist gepackt: unterste zwei Bits = Modus (Geräte mit Mopp-Anhebung)
            $bits = [0 => 2, 1 => 1, 2 => 0, 3 => 3][$mode];
            $raw = intval($cur['4.23']);
            if (($raw & 3) != $bits) $set[] = [4, 23, ($raw & ~3) | $bits];
        }
        if (!$rooms) {
            if ($fan >= 0 && (!isset($cur['4.4']) || intval($cur['4.4']) != $fan)) $set[] = [4, 4, $fan];
            if ($wet >= 1 && (!isset($cur['4.5']) || intval($cur['4.5']) != $wet)) $set[] = [4, 5, $wet];
        }
        $devRoute = isset($cur['4.50']) ? $this->AutoSwitch($cur['4.50'], 'CleanRoute') : null;
        $routeSet = $route >= 1 && $devRoute !== $route;
        // zwei Einträge für 4/50 in einem Aufruf nimmt nicht jedes Gerät an – Route dann getrennt
        if ($routeSet && !count(array_filter($set, function ($x) { return $x[1] == 50; }))) {
            $set[] = [4, 50, json_encode(['k' => 'CleanRoute', 'v' => $route])];
            $routeSet = false;
        }
        if (count($set)) {
            $this->MiotSet($set);
            $mem = [];
            foreach ($set as $x) if ($x[1] != 50) $mem['4.' . $x[1]] = $x[2];
            $this->DevCfg($mem + ['4.50' => null]);    // 4/50 ist ein Sammelwert – beim nächsten Mal frisch lesen
        }
        if ($routeSet) $this->MiotSet([[4, 50, json_encode(['k' => 'CleanRoute', 'v' => $route])]]);
        return $cur;
    }

    // Zwischenspeicher der Geräte-Einstellungen (4/4, 4/5, 4/23, 4/50), höchstens 15 Minuten alt.
    // Mit $put werden Werte ergänzt (null löscht einen Eintrag).
    private function DevCfg($put = null)
    {
        $c = json_decode($this->GetBuffer('DevCfg'), true);
        if (!is_array($c) || intval($c['_at'] ?? 0) < time() - 900) $c = [];
        if ($put !== null) {
            foreach ($put as $k => $v) {
                if (!in_array($k, ['4.4', '4.5', '4.23', '4.50'], true)) continue;
                if ($v === null) unset($c[$k]); else $c[$k] = $v;
            }
            $c['_at'] = time();
            $this->SetBuffer('DevCfg', json_encode($c));
        }
        unset($c['_at']);
        return $c;
    }

    // Wert aus der Sammel-Property 4/50 (Liste von {k, v})
    private function AutoSwitch($raw, $key)
    {
        $d = is_array($raw) ? $raw : json_decode(strval($raw), true);
        if (!is_array($d)) return null;
        if (isset($d['k'])) $d = [$d];
        foreach ($d as $e) {
            if (is_array($e) && isset($e['k'], $e['v']) && $e['k'] == $key) return intval($e['v']);
        }
        return null;
    }

    // =========================================================================
    // Verbindung, Karten, Diagnose (Buttons)
    // =========================================================================

    public function TestConnection()
    {
        return $this->Locked(function () {
            $this->WriteAttributeString('Device', '');
            if (!$this->CloudLogin(true)) { echo 'Anmeldung fehlgeschlagen: ' . $this->dcLastError; return false; }
            $all = $this->CloudDevices();
            $dev = $this->CloudDevice(true);
            if ($dev === null) {
                echo 'Angemeldet, aber: ' . $this->dcLastError;
                if (is_array($all)) foreach ($all as $d) echo "\n  • " . $d['name'] . ' (' . $d['model'] . ', did ' . $d['did'] . ')';
                return false;
            }
            $this->SetVal('Model', $dev['name'] . ' (' . $dev['model'] . ')');
            $on = function ($d) { return $d['online'] === null ? '' : ($d['online'] ? ', online' : ', OFFLINE'); };
            echo "Anmeldung OK\nGerät: " . $dev['name'] . ' – ' . $dev['model'] . $on($dev) . ' (did ' . $dev['did'] . ')';
            $others = array_filter(is_array($all) ? $all : [], function ($d) use ($dev) { return $d['did'] != $dev['did']; });
            if (count($others)) {
                echo "\n\nWeitere Geräte im Konto (Auswahl über Feld „Gerät“):";
                foreach ($others as $d) echo "\n  • " . $d['name'] . ' (' . $d['model'] . $on($d) . ', did ' . $d['did'] . ')';
            }

            // Direkter Draht zum Roboter?
            $v = $this->MiotGet([[2, 1], [3, 1]]);
            if ($v !== null && !$this->dcFromCache) {
                echo "\n\nRoboter antwortet direkt: " . $this->StateName(isset($v['2.1']) ? $v['2.1'] : -1) . ', Akku ' . (isset($v['3.1']) ? intval($v['3.1']) : '?') . ' %';
            } elseif ($v !== null) {
                echo "\n\nDer Roboter antwortet nicht direkt – Status kommt aus dem Cloud-Speicher (Zustand: "
                    . $this->StateName(isset($v['2.1']) ? $v['2.1'] : -1) . ').'
                    . "\nBefehle brauchen den direkten Draht. Prüfen: Ist der Roboter in der App erreichbar? "
                    . 'Stimmt das Gerät oben (bei mehreren Geräten das Feld „Gerät“ setzen)?';
            } else {
                echo "\n\nDer Roboter antwortet nicht: " . $this->dcLastError
                    . "\nPrüfen: Ist der Roboter in der App erreichbar? Stimmt das Gerät oben?";
            }

            $caps = $this->ProbeCapabilities();
            if (is_array($caps)) {
                $parts = [];
                foreach (SaugroboterTexte::Consumables() as $c) if (!empty($caps[$c[1] . '.' . $c[2]])) $parts[] = $c[0];
                echo "\n\nVerschleißteile: " . (count($parts) ? implode(', ', $parts) : 'keine gemeldet');
            }
            $this->Online(true);
            return true;
        });
    }

    // Karten und Räume aus der Cloud einlesen
    public function ReadMaps()
    {
        return $this->Locked(function () {
            $ml = $this->MapKeys();
            $info = isset($ml['6.8']) ? $this->Json($ml['6.8']) : null;
            $list = null;
            if (!isset($info['object_name'])) {
                $this->SendDebug('Karten', 'Kartenliste (6/8) fehlt: ' . json_encode($ml) . ' ' . $this->dcLastError, 0);
            } else {
                $file = $this->CloudFile($info['object_name']);
                $list = $file !== null ? $this->Json($file) : null;
                if (!isset($list['mapstr'])) $this->SendDebug('Karten', 'Kartenliste nicht lesbar: ' . substr(strval($file), 0, 300), 0);
            }
            if (!isset($list['mapstr']) || !is_array($list['mapstr'])) {
                // Rückfall: Räume der aktuellen Etage aus der Live-Karte
                if ($this->MapsFromLive()) return true;
                echo "Keine Karten gefunden.\n\n" . $this->MapReport();
                return false;
            }

            $maps = [];
            $n = 0;
            foreach ($list['mapstr'] as $entry) {
                $n++;
                $text = !empty($entry['map']) ? $entry['map'] : null;
                if ($text === null && !empty($entry['rismobj'])) $text = $this->CloudFile($entry['rismobj']);
                if ($text === null) continue;
                $b = SaugroboterKarte::Decode($text, self::MAP_IV);
                if ($b === null) { $this->SendDebug('Karte', 'Etage ' . $n . ' nicht dekodierbar', 0); continue; }
                $rooms = SaugroboterKarte::Rooms($b, SaugroboterTexte::RoomTypes());
                $name = isset($entry['name']) && trim($entry['name']) !== '' ? trim($entry['name']) : ('Etage ' . $n);
                $maps[$b['mapId']] = [
                    'id' => $b['mapId'], 'name' => $name,
                    'angle' => isset($entry['angle']) ? intval($entry['angle']) : 0,
                    'left' => $b['left'], 'top' => $b['top'], 'rooms' => $rooms
                ];
                // Bild der Etage schon jetzt, damit die Kachel nicht leer ist
                if ($this->ReadPropertyBoolean('MapImage') && $b['mapId'] >= 0) $this->StoreMapImage($b, $b['mapId'], 'MapFloor' . $b['mapId']);
            }
            if (count($maps) == 0) {
                if ($this->MapsFromLive()) return true;
                echo "Keine Karte dekodierbar.\n\n" . $this->MapReport();
                return false;
            }
            $this->WriteAttributeString('Maps', json_encode(array_values($maps)));
            if (!isset($maps[$this->ReadAttributeInteger('Floor')])) {
                $cur = isset($list['curr_id']) && isset($maps[intval($list['curr_id'])]) ? intval($list['curr_id']) : array_keys($maps)[0];
                $this->WriteAttributeInteger('Floor', $cur);
                $this->WriteAttributeInteger('DeviceFloor', $cur);
            }
            $this->SyncRooms();
            $this->UpdateFormField('Rooms', 'values', json_encode(array_map(function ($r) {
                return ['use' => $r['use'], 'floor' => $r['floor'], 'map' => $r['map'], 'seg' => $r['seg'], 'app' => $r['app'], 'alias' => $r['alias']];
            }, $this->RoomList(true))));
            $this->FetchLiveMap(true);
            $this->RefreshViews();

            echo count($maps) . ' Etage(n) eingelesen:';
            foreach ($maps as $m) {
                $names = [];
                foreach ($m['rooms'] as $seg => $r) $names[] = $r['name'] . ($r['hidden'] ? ' (ausgeblendet)' : '');
                echo "\n  • " . $m['name'] . ': ' . implode(', ', $names);
            }
            echo "\n\nRäume, die es nicht gibt, in der Liste abwählen und „Änderungen übernehmen“.";
            return true;
        });
    }

    // Nur die Räume der aktuellen Etage aus der Live-Karte übernehmen (wenn die Kartenliste fehlt)
    private function MapsFromLive()
    {
        $b = $this->FetchLiveMap(true);
        if ($b === null) return false;
        $rooms = SaugroboterKarte::Rooms($b, SaugroboterTexte::RoomTypes());
        if (count($rooms) == 0) return false;
        $maps = $this->Maps();
        $maps[$b['mapId']] = ['id' => $b['mapId'], 'name' => isset($maps[$b['mapId']]) ? $maps[$b['mapId']]['name'] : 'Etage 1',
            'angle' => isset($maps[$b['mapId']]) ? $maps[$b['mapId']]['angle'] : 0, 'left' => $b['left'], 'top' => $b['top'], 'rooms' => $rooms];
        $this->WriteAttributeString('Maps', json_encode(array_values($maps)));
        $this->WriteAttributeInteger('Floor', $b['mapId']);
        $this->WriteAttributeInteger('DeviceFloor', $b['mapId']);
        $this->SyncRooms();
        $this->UpdateFormField('Rooms', 'values', json_encode(array_map(function ($r) {
            return ['use' => $r['use'], 'floor' => $r['floor'], 'map' => $r['map'], 'seg' => $r['seg'], 'app' => $r['app'], 'alias' => $r['alias']];
        }, $this->RoomList(true))));
        $this->FetchLiveMap(true);
        $this->RefreshViews();
        $names = [];
        foreach ($rooms as $r) $names[] = $r['name'];
        echo "Kartenliste nicht verfügbar – Räume der aktuellen Etage aus der Live-Karte übernommen:\n  • " . implode(', ', $names)
            . "\n\nWeitere Etagen erscheinen, sobald die Kartenliste lesbar ist („Kartendiagnose“).";
        return true;
    }

    // Kartendiagnose: was liefert die Cloud? (ohne Kartenbild, nur Aufbau und Kennzahlen)
    public function MapDiagnosis()
    {
        return $this->Locked(function () {
            echo $this->MapReport();
            return true;
        });
    }

    private function MapReport()
    {
        $r = [];
        $ml = $this->MapKeys();
        $r[] = 'Quelle: ' . ($ml === null ? 'keine Antwort (' . $this->dcLastError . ')' : ($this->dcFromCache ? 'Cloud-Speicher' : 'Roboter direkt'));
        foreach (['6.3', '6.8'] as $k) {
            $v = isset($ml[$k]) ? (is_scalar($ml[$k]) ? strval($ml[$k]) : json_encode($ml[$k])) : '–';
            $r[] = $k . ' = ' . substr(preg_replace('/[A-Za-z0-9+\/_-]{40,}/', '…', $v), 0, 160);
        }
        $info = isset($ml['6.8']) ? $this->Json($ml['6.8']) : null;
        if (isset($info['object_name'])) {
            $file = $this->CloudFile($info['object_name']);
            $list = $file !== null ? $this->Json($file) : null;
            $r[] = 'Kartenliste: ' . ($file === null ? 'Download fehlgeschlagen (' . $this->dcLastError . ')' : strlen($file) . ' Bytes, Schlüssel: '
                . (is_array($list) ? implode(', ', array_keys($list)) : 'kein JSON'));
            if (isset($list['mapstr']) && is_array($list['mapstr'])) {
                foreach ($list['mapstr'] as $i => $e) {
                    $keys = is_array($e) ? implode(', ', array_keys($e)) : gettype($e);
                    $text = !empty($e['map']) ? $e['map'] : (!empty($e['rismobj']) ? $this->CloudFile($e['rismobj']) : null);
                    $r[] = '  Karte ' . ($i + 1) . ' [' . $keys . '] ' . $this->BlockReport($text);
                }
            }
        }
        foreach ($this->LiveMapObjects() as $o) {
            $r[] = 'Abgelegte Karte ' . preg_replace('#^.*/#', '…/', $o) . ': ' . $this->BlockReport($this->CloudFile($o));
        }
        // Live-Verbindung: zuletzt empfangene Bilder und das daraus zusammengesetzte
        $r[] = 'Live empfangen: ' . intval($this->GetBuffer('LiveCntI')) . ' Vollbilder, ' . intval($this->GetBuffer('LiveCntP')) . ' Teilbilder, '
            . intval($this->GetBuffer('LiveCntX')) . ' verworfen';
        $at = intval($this->GetBuffer('LiveMapAt'));
        $r[] = 'Letztes Live-Kartenbild: ' . ($at ? date('d.m. H:i:s', $at) . ' (vor ' . (time() - $at) . ' s)' : 'noch keins – Karte kommt aus den Cloud-Dateien');
        foreach (['LiveRawI' => 'Live-Vollbild', 'LiveRawP' => 'Live-Teilbild'] as $buf => $label) {
            $raw = $this->GetBuffer($buf);
            if ($raw !== '') $r[] = $label . ': ' . $this->BlockReport(gzuncompress(base64_decode($raw)));
        }
        $lb = $this->LiveBlock();
        if ($lb !== null) $r[] = 'Live-Karte (zusammengesetzt): ' . $this->BlockReport(null, $lb);
        $out = implode("\n", $r);
        $this->SendDebug('Kartendiagnose', $out, 0);
        return $out;
    }

    private function BlockReport($text, $b = null)
    {
        if ($b === null) {
            if ($text === null) return 'nicht ladbar';
            $b = SaugroboterKarte::Decode($text, self::MAP_IV);
            if ($b === null) return 'nicht dekodierbar (' . strlen($text) . ' Zeichen, ' . (strpos($text, ',') !== false ? 'mit' : 'ohne') . ' Schlüssel)';
        }
        $segs = isset($b['info']['seg_inf']) && is_array($b['info']['seg_inf']) ? implode(',', array_keys($b['info']['seg_inf'])) : '–';
        $hist = [];
        for ($i = 0; $i < strlen($b['cells']); $i++) { $c = ord($b['cells'][$i]); if ($c) $hist[$c] = (isset($hist[$c]) ? $hist[$c] : 0) + 1; }
        arsort($hist);
        $top = [];
        foreach (array_slice($hist, 0, 8, true) as $v => $c) $top[] = $v . '×' . $c;
        $sc = SaugroboterKarte::FormatScores($b);
        $age = isset($b['info']['timestamp_ms']) ? ', Stand ' . date('H:i:s', intval($b['info']['timestamp_ms'] / 1000))
            . ' (vor ' . max(0, time() - intval($b['info']['timestamp_ms'] / 1000)) . ' s)' : '';
        return 'Typ ' . $b['type'] . $age . ', Karte ' . $b['mapId'] . ', ' . $b['w'] . '×' . $b['h'] . ', Raster ' . $b['grid']
            . ', fsm ' . (isset($b['info']['fsm']) ? $b['info']['fsm'] : '–') . ', Räume ' . $segs
            . "\n      Anhang: " . implode(', ', array_keys($b['info']))
            . "\n      Häufigste Bytes: " . implode(' ', $top)
            . "\n      Formate: " . json_encode($sc) . ' → ' . $this->CellFormat($b);
    }

    // Objektnamen der Karten (6/3 aktuelle Karte, 6/8 Kartenliste). Manche Modelle (X60) liefern sie
    // nicht auf direkte Anfrage, sondern nur als gemeldeten Wert im Cloud-Speicher – dann dort nachsehen.
    private function MapKeys()
    {
        $v = $this->MiotGet([[6, 3], [6, 8]]);
        if ($v === null) $v = [];
        if (!isset($v['6.3']) || !isset($v['6.8'])) {
            $c = $this->CloudCachedProps([[6, 3], [6, 8]]);
            if (is_array($c)) foreach ($c as $k => $x) if (!isset($v[$k])) $v[$k] = $x;
        }
        return $v;
    }

    private function Json($v)
    {
        if (is_array($v)) $d = $v;
        else $d = json_decode(strval($v), true);
        if (!is_array($d)) return null;
        // Die Kartenliste (6/8) heißt je nach Firmware "object_name" (X50) oder "obj_name" (X60)
        if (!isset($d['object_name']) && isset($d['obj_name'])) $d['object_name'] = $d['obj_name'];
        return $d;
    }

    // Zellformat: Einstellung oder automatisch (mit den bekannten Räumen der Etage als Hilfe)
    private function CellFormat($b)
    {
        $f = $this->ReadPropertyString('MapFormat');
        if (in_array($f, ['shift', 'low6', 'low5'], true)) return $f;
        // Für Modelle mit Kartenformat 2 (X60 u. a.) steht das Format fest, außer der Block ist ein Rahmenbild (fsm)
        $dev = json_decode($this->ReadAttributeString('Device'), true);
        $model = is_array($dev) && isset($dev['model']) ? substr($dev['model'], strrpos($dev['model'], '.') + 1) : '';
        // Kartenformat 2 hat Vorrang vor dem Rahmenbild-Kennzeichen "fsm" (so auch in der Referenz):
        // der X60 setzt fsm = 1, liest sich aber nur als 'low5' richtig.
        if (in_array($model, SaugroboterKarte::MAP_V2_MODELS, true)) return 'low5';
        $maps = $this->Maps();
        $known = isset($maps[$b['mapId']]) ? array_keys($maps[$b['mapId']]['rooms']) : [];
        return SaugroboterKarte::Detect($b, $known);
    }

    // Diagnose: listet alles, was der Roboter liefert (siid 1–40, piid 1–70)
    public function ScanDevice()
    {
        return $this->Locked(function () {
            $keys = [];
            for ($s = 1; $s <= 40; $s++) for ($p = 1; $p <= 70; $p++) $keys[] = [$s, $p];
            $v = $this->MiotGet($keys);
            if ($v === null) { echo 'Keine Antwort: ' . $this->dcLastError; return false; }
            uksort($v, function ($a, $b) { return version_compare($a, $b); });
            $dev = $this->CloudDevice();
            $out = ($dev ? $dev['model'] : '') . ' – ' . count($v) . " Werte\n";
            foreach ($v as $k => $x) {
                $t = is_scalar($x) ? strval($x) : json_encode($x);
                $out .= str_pad($k, 7) . '= ' . (strlen($t) > 100 ? substr($t, 0, 97) . '…' : $t) . "\n";
            }
            $this->SendDebug('Scan', $out, 0);
            echo $out;
            return true;
        });
    }

    // =========================================================================
    // Abruf
    // =========================================================================

    // Button "Status abrufen": mit Rückmeldung, wartet notfalls auf einen laufenden Abruf
    public function Refresh()
    {
        $this->SetBuffer('SlowAt', '0');
        $ok = $this->DoPoll(true);
        $fresh = intval($this->GetBuffer('FreshAt'));
        if ($ok && time() - $fresh < 30) $msg = 'Status aktualisiert – Werte direkt vom Roboter.';
        elseif ($ok) $msg = 'Roboter antwortet nicht – ' . ($fresh > 0 ? 'letzter frischer Stand ' . date('d.m. H:i', $fresh) . '. ' : '')
            . 'Angezeigt werden Werte aus dem Cloud-Speicher; die können veraltet sein. Ist der Roboter im WLAN und in der App erreichbar?';
        else $msg = 'Kein Abruf möglich: ' . ($this->dcLastError !== '' ? $this->dcLastError : 'Instanz beschäftigt oder Roboter nicht erreichbar.');
        $this->Note($msg, $ok && time() - $fresh < 30 ? 'ok' : 'err');
        echo $msg;
        return $ok;
    }

    public function Poll()
    {
        return $this->DoPoll(false);
    }

    // $User = true: vom Button – wartet auf einen laufenden Abruf statt ihn zu überspringen
    private function DoPoll($User)
    {
        if (!$this->ReadPropertyBoolean('Active')) return false;
        $done = false;
        $ok = $this->Locked(function () use (&$done) {
            if ($this->ReadAttributeString('Caps') === '') $this->ProbeCapabilities();

            // Laufend nötig: Zustand, Fehler, Akku, Auftrag, Station. Verschleiß und Statistik ändern
            // sich langsam – die nur alle 10 Minuten (spart während der Reinigung viel Zeit).
            $keys = [[2, 1], [2, 2], [3, 1], [4, 2], [4, 3], [4, 23], [4, 26], [4, 41], [4, 50], [27, 1], [27, 2], [27, 3]];
            $caps = $this->Caps();
            $slow = time() - intval($this->GetBuffer('SlowAt')) >= 600;
            foreach (self::EXTRAS as $ident => $x) {
                if (!$this->HasCap($caps, $x[1], $x[2])) continue;
                if ($slow || in_array($ident, ['Charging', 'Progress'], true)) $keys[] = [$x[1], $x[2]];
            }
            if ($slow) {
                $keys[] = [4, 4]; $keys[] = [4, 5];     // Saugkraft/Feuchte am Gerät (für schnellen Start)
                foreach (SaugroboterTexte::Consumables() as $c) if ($this->HasCap($caps, $c[1], $c[2])) $keys[] = [$c[1], $c[2]];
                $this->SetBuffer('SlowAt', strval(time()));
            }
            $v = $this->MiotGet($keys);
            $partial = false;
            if ($v === null || !isset($v['2.1'])) {
                // Roboter antwortet nicht (z. B. beim Trocknen in der Station). Steht die Live-Verbindung,
                // kennen wir Zustand, Fehler und Akku trotzdem – dann Auftrag und Karte weiter verfolgen.
                if (!$this->LiveOk()) { $this->Online(false); return false; }
                $v = ['2.1' => $this->GetValue('State'), '2.2' => $this->GetValue('Error'), '3.1' => $this->GetValue('Battery')];
                $partial = true;
            } else {
                $this->Online(true);
                if ($this->dcFromCache) $this->Note('Roboter antwortet nicht direkt – Werte aus dem Cloud-Speicher.');
                else $this->SetBuffer('FreshAt', strval(time()));     // Werte direkt vom Roboter
            }

            $state = intval($v['2.1']);
            $this->SetVal('State', $state);
            $err = isset($v['2.2']) ? intval($v['2.2']) : 0;
            $this->SetVal('Error', $err);
            $this->SetVal('ErrorHint', SaugroboterTexte::ErrorHint($err));
            if (isset($v['3.1'])) $this->SetVal('Battery', intval($v['3.1']));
            if (isset($v['4.2'])) $this->SetVal('CleanTime', intval($v['4.2']));
            if (isset($v['4.3'])) $this->SetVal('CleanArea', intval($v['4.3']));

            // Station: 27/1 meldet nur "Tank steckt", leer steht in 4/41
            if (!$partial) {
                $cw = isset($v['27.1']) ? intval($v['27.1']) : -1;
                $low = isset($v['4.41']) ? intval($v['4.41']) : 0;
                if ($cw == 0 && $low >= 2) $cw = 3;
                $this->SetVal('CleanWater', $cw);
                $this->SetVal('DirtyWater', isset($v['27.2']) ? intval($v['27.2']) : -1);
                $this->SetVal('DustBag', isset($v['27.3']) ? intval($v['27.3']) : -1);
            }

            foreach (SaugroboterTexte::Consumables() as $ident => $c) {
                $k = $c[1] . '.' . $c[2];
                if (isset($v[$k]) && @$this->GetIDForIdent('Wear' . $ident)) $this->SetVal('Wear' . $ident, intval($v[$k]));
            }
            foreach (self::EXTRAS as $ident => $x) {
                $k = $x[1] . '.' . $x[2];
                if (!isset($v[$k]) || !@$this->GetIDForIdent($ident)) continue;
                if ($ident == 'Charging') $this->SetVal($ident, intval($v[$k]) == 1);
                elseif ($ident == 'TotalHours') $this->SetVal($ident, round(intval($v[$k]) / 60, 1));
                else $this->SetVal($ident, intval($v[$k]));
            }

            if (!$partial) $this->SetVal('DeviceSettings', $this->DescribeSettings($v));
            if (!$partial && !$this->dcFromCache) $this->DevCfg(array_intersect_key($v, array_flip(['4.4', '4.5', '4.23', '4.50'])));

            // Auftrag verfolgen
            $group = SaugroboterTexte::StateGroup($state);
            $job = $this->ReadAttributeInteger('Job');
            if (in_array($group, ['working', 'paused'], true) && $job == 0) {
                $this->WriteAttributeInteger('Job', 1);
                $job = 1;
            }
            if ($job == 1) {
                $this->WriteAttributeString('JobInfo', json_encode([
                    'min' => $this->GetValue('CleanTime'), 'area' => $this->GetValue('CleanArea')
                ]));
                // Ende: angedockt bzw. typische Abschluss-Zustände – oder seit über 10 Minuten durchgehend an der Station
                // (Zwischenstopps zum Mopp-Waschen während der Fahrt dauern kürzer)
                $atStation = in_array($group, ['docked', 'station'], true);
                if (!$atStation) $this->SetBuffer('AtStationSince', '0');
                elseif (intval($this->GetBuffer('AtStationSince')) == 0) $this->SetBuffer('AtStationSince', strval(time()));
                $ended = $group == 'docked' || in_array($state, [8, 22, 35, 104], true)
                    || ($atStation && time() - intval($this->GetBuffer('AtStationSince')) > 600);
                if ($ended) {
                    $this->WriteAttributeInteger('Job', 0);
                    $done = true;
                }
            }

            $this->TrackRoom($state, $group);
            // Nach einem Update fehlt die Raumlage zum vorhandenen Kartenbild (Beschriftung/Antippen) –
            // dann die Karte einmal frisch holen, auch wenn der Roboter an der Station steht
            $stale = $this->ReadAttributeInteger('RenderVersion') != self::RENDER_VERSION;
            if ($this->ReadPropertyBoolean('MapImage') && ($this->MapMeta('Map') === null || $stale) && intval($this->GetBuffer('MetaTry')) < time() - 300) {
                $this->WriteAttributeInteger('RenderVersion', self::RENDER_VERSION);
                $this->SetBuffer('MetaTry', strval(time()));
                $this->FetchLiveMap(true);
            }
            if ($done) {
                $this->SetBuffer('AtStationSince', '0');
                $this->FetchLiveMap(true);
                $this->LoadHistory();
                if ($this->ReadAttributeInteger('JobRooms') == 1) {
                    $this->WriteAttributeInteger('JobRooms', 0);
                    $this->ClearSelection();
                    $this->SetVal('CleanRoom', 0);
                }
                $this->StartQueue();
            }
            $this->CheckError($err);
            $this->CheckMaintenance();
            return true;
        }, !$User);
        if ($ok === null) return false;   // übersprungen: anderer Zugriff läuft
        if ($done) {
            $this->JobFinished();
        }
        $this->SetPollInterval();
        $this->RefreshViews();
        $this->UpdateStatusForm();
        return $ok;
    }

    private function DescribeSettings($v)
    {
        $t = [];
        if (isset($v['4.23'])) {
            $m = [0 => 'Saugen und wischen', 1 => 'Wischen', 2 => 'Saugen', 3 => 'Erst saugen, dann wischen'];
            $t[] = $m[intval($v['4.23']) & 3];
        }
        if (isset($v['4.50'])) {
            $cgText = [0 => 'aus', 1 => 'Routine', 2 => 'Tief'];
            $routeText = [1 => 'Standard', 2 => 'Intensiv', 3 => 'Tief', 4 => 'Schnell'];
            $cg = $this->AutoSwitch($v['4.50'], 'SmartHost');
            if ($cg !== null) $t[] = 'CleanGenius ' . (isset($cgText[$cg]) ? $cgText[$cg] : $cg);
            $r = $this->AutoSwitch($v['4.50'], 'CleanRoute');
            if ($r !== null && $r > 0) $t[] = 'Route ' . (isset($routeText[$r]) ? $routeText[$r] : $r);
        }
        if (!empty($v['4.26'])) $t[] = 'Raumeinstellungen aus der App aktiv';
        return implode(' · ', $t);
    }

    // Aktueller Raum aus der Live-Karte (während eines Auftrags höchstens alle 30 s)
    private function TrackRoom($state, $group)
    {
        if ($group == 'station') { $this->SetVal('Room', 'Station'); return; }
        if (!in_array($group, ['working', 'paused', 'moving'], true)) { $this->SetVal('Room', '–'); return; }
        $b = $this->FetchLiveMap(false);
        if ($b !== null) $this->RoomFromBlock($b, $group);
    }

    // Raum, in dem der Roboter laut Kartenbild steht
    private function RoomFromBlock($b, $group = null)
    {
        if ($group === null) $group = SaugroboterTexte::StateGroup(intval($this->GetValue('State')));
        if (!in_array($group, ['working', 'paused', 'moving'], true)) return;
        $maps = $this->Maps();
        $mapId = isset($maps[$b['mapId']]) ? $b['mapId'] : $this->ActiveFloor();
        $seg = SaugroboterKarte::RobotRoom($b, $this->CellFormat($b));
        $this->SetVal('Room', $seg > 0 ? $this->RoomName($mapId * 100 + $seg) : 'unterwegs');
    }

    // Live-Karte laden und Bild aktualisieren. Rückgabe Block oder null.
    // Live-Karte laden und Bild aktualisieren. Rückgabe Block oder null.
    // Der Roboter meldet abwechselnd Vollbilder ('I') und Differenzbilder ('P'). Differenzbilder
    // werden auf das letzte Vollbild gelegt – sonst stünde die Karte während der Fahrt still.
    private function FetchLiveMap($force)
    {
        // Live-Verbindung liefert die Karte laufend – dann keine Dateien abrufen.
        // Nur wenn tatsächlich Kartenbilder kommen: manche Geräte melden live nur den Zustand.
        if (!$force && $this->LiveOk() && time() - intval($this->GetBuffer('LiveMapAt')) < 60 && ($lb = $this->LiveBlock()) !== null) return $lb;
        $last = intval($this->GetBuffer('LiveAt'));
        if (!$force && time() - $last < 12) return $this->LiveBlock();
        $this->SetBuffer('LiveAt', strval(time()));

        $base = $this->LiveBlock();
        $got = null;
        $best = -1;
        foreach ($this->LiveMapObjects() as $obj) {
            $text = $this->CloudFile($obj);
            $b = $text === null ? null : SaugroboterKarte::Decode($text, self::MAP_IV);
            if ($b === null) continue;
            if ($b['type'] === 'I') {
                $ts = isset($b['info']['timestamp_ms']) ? floatval($b['info']['timestamp_ms']) : 0;
                if ($ts > $best) { $best = $ts; $got = $b; }
            } elseif ($b['type'] === 'P' && $got === null && $base !== null && $b['mapId'] == $base['mapId']
                && strval($b['frameId']) !== $this->GetBuffer('LiveFrame')) {
                $got = SaugroboterKarte::Merge($base, $b, $this->IsV2());
            }
        }
        // Nichts Neueres als das, was schon angezeigt wird? Dann nicht neu zeichnen.
        if (!$force && $got !== null && $base !== null && $best > 0 && isset($base['info']['timestamp_ms'])
            && $best <= floatval($base['info']['timestamp_ms'])) return $base;
        if ($got === null) return $base;
        // gemeinsame Übernahme mit den Live-Bildern (Details und Position richtig zusammenführen)
        $got['type'] = 'I';
        if (!$this->LiveTakeBlock($got)) return $base;
        $got = $this->LiveBlock();
        if ($this->ReadPropertyBoolean('MapImage')) $this->StoreMapImage($got, $got['mapId'], 'Map');
        return $got;
    }

    private function LiveBlock()
    {
        $raw = $this->GetBuffer('LiveBlock');
        if ($raw === '') return null;
        $b = @unserialize(@gzuncompress(base64_decode($raw)), ['allowed_classes' => false]);
        return is_array($b) ? $b : null;
    }

    private function IsV2()
    {
        $dev = json_decode($this->ReadAttributeString('Device'), true);
        $model = is_array($dev) && isset($dev['model']) ? substr($dev['model'], strrpos($dev['model'], '.') + 1) : '';
        return in_array($model, SaugroboterKarte::MAP_V2_MODELS, true);
    }

    // Kandidaten für die aktuelle Karte: gemeldeter Objektname (6/3), sonst die neueste Datei
    // im Kartenordner des Geräts.
    // Kandidaten für die aktuelle Karte. Der X60 legt die laufende Karte abwechselnd als ".../0" und
    // ".../1" im Kartenordner ab (etwa alle 30 s); 6/3 im Cloud-Speicher hinkt dabei oft eine Runde hinterher.
    // Deshalb beide Dateien ansehen und die neuere nehmen.
    private function LiveMapObjects()
    {
        $out = [];
        $dir = $this->GetBuffer('MapDir');
        if ($dir === '') {
            $v = $this->MapKeys();
            foreach (['6.3', '6.8'] as $k) {
                if (!isset($v[$k])) continue;
                $o = $v[$k];
                if (is_string($o) && is_array($j = json_decode($o, true))) $o = $j;
                if (is_array($o)) $o = isset($o['object_name']) ? $o['object_name'] : (isset($o['obj_name']) ? $o['obj_name'] : reset($o));
                if (is_string($o) && ($p = strrpos($o, '/')) !== false) { $dir = substr(explode(',', $o)[0], 0, $p + 1); break; }
            }
            if ($dir !== '') $this->SetBuffer('MapDir', $dir);
        }
        if ($dir !== '') { $out[] = $dir . '0'; $out[] = $dir . '1'; }
        return $out;
    }

    private function StoreMapImage($b, $mapId, $ident)
    {
        $rot = $this->ReadPropertyInteger('MapRotate');
        if ($rot < 0) {
            $maps = $this->Maps();
            $rot = isset($maps[$mapId]) ? $maps[$mapId]['angle'] : 0;
        }
        $format = $this->CellFormat($b);
        // Zwei Fassungen: mit Roboter/Station (Medienobjekt, WebFront, HTML-Box) und ohne
        // (Kachel – dort liegen Roboter und Station als eigene, animierte Symbole darüber)
        $out = SaugroboterKarte::Render($b, $format, [
            'rotate' => $rot, 'path' => $this->ReadPropertyBoolean('MapPath') || $ident == 'MapLast',
            'selected' => array_map(function ($c) { return $c % 100; }, $this->SelectedRooms()), 'both' => true
        ]);
        if ($out === null) return;
        list($png, $clean) = $out;
        $this->SetBuffer('Clean' . $ident, base64_encode($clean));
        $names = ['Map' => 'Karte', 'MapLast' => 'Karte letzte Reinigung'];
        $mid = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if (!$mid) {
            $mid = IPS_CreateMedia(1);
            IPS_SetParent($mid, $this->InstanceID);
            IPS_SetIdent($mid, $ident);
            IPS_SetName($mid, isset($names[$ident]) ? $names[$ident] : ('Karte ' . $mapId));
            IPS_SetPosition($mid, $ident == 'MapLast' ? 3 : 2);
            IPS_SetHidden($mid, $ident != 'Map');
            IPS_SetMediaFile($mid, 'media/Saugroboter_' . $this->InstanceID . '_' . $ident . '.png', false);
        }
        IPS_SetMediaContent($mid, base64_encode($png));

        $meta = json_decode($this->ReadAttributeString('MapMeta'), true);
        if (!is_array($meta)) $meta = [];
        $layout = SaugroboterKarte::Layout($b, $format, $rot);
        if ($layout !== null) {
            if (!isset($this->Maps()[$layout['mapId']])) $layout['mapId'] = $this->ActiveFloor();
            $meta[$ident] = $layout;
            $this->WriteAttributeString('MapMeta', json_encode($meta));
        }
        $this->SetBuffer($ident == 'MapLast' ? 'LastStamp' : 'MapStamp', strval(microtime(true)));
    }

    // Bild für die Anzeige: Live-Karte, sonst das Bild der gewählten Etage
    // Welches Bild die Anzeige zeigt: Live-Karte, sonst das Bild der gewählten Etage
    private function MapIdent()
    {
        foreach (['Map', 'MapFloor' . $this->ActiveFloor()] as $ident) {
            if (@IPS_GetObjectIDByIdent($ident, $this->InstanceID)) return $ident;
        }
        return '';
    }

    private function MapDataUri($ident = null, $clean = false)
    {
        if ($ident === null) $ident = $this->MapIdent();
        if ($ident === '') return '';
        if ($clean && ($c = $this->GetBuffer('Clean' . $ident)) !== '') return 'data:image/png;base64,' . $c;
        $mid = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        $c = $mid ? @IPS_GetMediaContent($mid) : '';
        return $c ? 'data:image/png;base64,' . $c : '';
    }

    // Letzte Rückmeldung für die Kachel (id, Text, Art, Alter in Sekunden)
    private function ToastView()
    {
        $t = json_decode($this->GetBuffer('Toast'), true);
        if (!is_array($t)) return null;
        return ['id' => $t['id'], 'text' => $t['text'], 'kind' => $t['kind'], 'age' => time() - intval($t['at'])];
    }

    // Modellname für die Anzeige, z. B. "X60 Ultra" aus der Geräteliste
    private function ModelShort()
    {
        $dev = json_decode($this->ReadAttributeString('Device'), true);
        return is_array($dev) && !empty($dev['model']) ? strval($dev['model']) : '';
    }

    private function MapMeta($ident)
    {
        $meta = json_decode($this->ReadAttributeString('MapMeta'), true);
        return is_array($meta) && isset($meta[$ident]) ? $meta[$ident] : null;
    }

    // ---- Verlauf ----
    private function LoadHistory()
    {
        $n = max(1, min(20, $this->ReadPropertyInteger('HistoryCount')));
        $list = $this->CloudEvents(4, 1, $n + 5);
        if ($list === null) return;
        $cache = json_decode($this->ReadAttributeString('LogRooms'), true);
        if (!is_array($cache)) $cache = [];
        $lines = [];
        $seen = [];
        $downloads = 0;
        $newest = null;   // jüngste Fahrt mit Reinigungsprotokoll
        foreach ($list as $item) {
            $raw = isset($item['history']) ? $item['history'] : (isset($item['value']) ? $item['value'] : '');
            $data = json_decode($raw, true);
            if (!is_array($data)) continue;
            $f = [];
            foreach ($data as $d) {
                if (isset($d['piid'])) $f[intval($d['piid'])] = isset($d['value']) ? $d['value'] : (isset($d['val']) ? $d['val'] : null);
            }
            if (empty($f[8]) || isset($seen[$f[8]])) continue;
            $seen[$f[8]] = true;
            $start = intval($f[8]);
            if ($newest === null && !empty($f[9])) $newest = ['start' => $start, 'log' => $f[9]];

            // gereinigte Räume aus dem Reinigungsprotokoll (je Fahrt einmal laden)
            $key = strval($start);
            if (!isset($cache[$key]) && !empty($f[9]) && $downloads < 3) {
                $downloads++;
                $cache[$key] = '';
                $obj = strval($f[9]);
                $txt = $this->CloudFile(explode(',', $obj)[0]);
                if ($txt !== null) {
                    // Protokoll = Kartenblock; ein Schlüssel hinter dem Komma gehört zum Objektnamen
                    $k = strpos($obj, ',') !== false ? explode(',', $obj)[1] : null;
                    $b = SaugroboterKarte::Decode($k !== null && strpos($txt, ',') === false ? $txt . ',' . $k : $txt, self::MAP_IV);
                    if ($b !== null && isset($b['info']['sa']) && is_array($b['info']['sa'])) {
                        $mapId = isset($this->Maps()[$b['mapId']]) ? $b['mapId'] : $this->ActiveFloor();
                        $names = [];
                        foreach ($b['info']['sa'] as $s) $names[] = $this->RoomName($mapId * 100 + intval(is_array($s) ? $s[0] : $s), false);
                        $cache[$key] = implode(', ', $names);
                    }
                }
            }
            $result = [0 => 'unterbrochen', 1 => 'fertig', 2 => 'manuell beendet', 3 => 'fehlgeschlagen'];
            $res = isset($f[13]) && isset($result[intval($f[13])]) ? $result[intval($f[13])] : '';
            if (isset($f[10])) {
                $p = json_decode(strval($f[10]), true);
                if (isset($p['abnormal_end'])) {
                    $a = json_decode(strval($p['abnormal_end']), true);
                    if (is_array($a) && !empty($a[0])) $res .= ' (' . SaugroboterTexte::InterruptReason(intval($a[0])) . ')';
                }
            }
            $what = !empty($cache[$key]) ? $cache[$key] : ((isset($f[1]) && intval($f[1]) == 18) ? 'Räume' : 'Komplett');
            $lines[] = $this->Weekday($start) . ' ' . date('d.m. H:i', $start) . ' · ' . (isset($f[2]) ? intval($f[2]) : 0) . ' min · '
                . (isset($f[3]) ? intval($f[3]) : 0) . ' m² · ' . $what . ($res !== '' ? ' · ' . $res : '');
            if (count($lines) >= $n) break;
        }
        if (count($cache) > 60) { krsort($cache); $cache = array_slice($cache, 0, 60, true); }

        // Karte der letzten Fahrt (einmal je Fahrt laden und zeichnen)
        if ($newest !== null && $this->ReadAttributeString('LastLog') !== strval($newest['start'])) {
            $obj = strval($newest['log']);
            $txt = $this->CloudFile(explode(',', $obj)[0]);
            $k = strpos($obj, ',') !== false ? explode(',', $obj)[1] : null;
            $b = $txt === null ? null : SaugroboterKarte::Decode($k !== null && strpos($txt, ',') === false ? $txt . ',' . $k : $txt, self::MAP_IV);
            if ($b !== null) {
                $this->StoreMapImage($b, $b['mapId'], 'MapLast');
                $this->WriteAttributeString('LastLog', strval($newest['start']));
            }
        }
        $this->WriteAttributeString('LogRooms', json_encode($cache));
        $this->SetVal('LastRun', count($lines) ? $lines[0] : '');
        $this->SetVal('History', implode("\n", $lines));
    }

    private function Weekday($ts)
    {
        return ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'][intval(date('w', $ts))];
    }

    // =========================================================================
    // Fähigkeiten des Modells
    // =========================================================================

    private function Caps()
    {
        $c = json_decode($this->ReadAttributeString('Caps'), true);
        return is_array($c) ? $c : null;
    }

    // Ohne ermittelte Fähigkeiten nur die Teile, die praktisch jedes Modell hat
    private function HasCap($caps, $siid, $piid)
    {
        if ($caps === null) return in_array($siid . '.' . $piid, ['9.2', '10.2', '11.1', '16.1'], true);
        return !empty($caps[$siid . '.' . $piid]);
    }

    private function ProbeCapabilities()
    {
        $keys = [];
        foreach (SaugroboterTexte::Consumables() as $c) $keys[] = [$c[1], $c[2]];
        foreach (self::EXTRAS as $x) $keys[] = [$x[1], $x[2]];
        $v = $this->MiotGet($keys);
        if ($v === null || count($v) == 0) return null;   // Gerät schläft – später erneut
        $caps = [];
        foreach ($v as $k => $x) $caps[$k] = 1;
        $this->WriteAttributeString('Caps', json_encode($caps));
        $this->SyncCapabilities();
        return $caps;
    }

    private function SyncCapabilities()
    {
        $caps = $this->Caps();
        $pos = 151;
        foreach (SaugroboterTexte::Consumables() as $ident => $c) {
            if ($this->HasCap($caps, $c[1], $c[2])) $this->RegisterVariableInteger('Wear' . $ident, $c[0], 'SAUG.Wear', $pos);
            elseif (@$this->GetIDForIdent('Wear' . $ident)) $this->UnregisterVariable('Wear' . $ident);
            $pos++;
        }
        foreach (self::EXTRAS as $ident => $x) {
            if ($this->HasCap($caps, $x[1], $x[2])) {
                if ($x[3] == 0) $this->RegisterVariableBoolean($ident, $x[0], $x[4], $x[5]);
                elseif ($x[3] == 1) $this->RegisterVariableInteger($ident, $x[0], $x[4], $x[5]);
                else $this->RegisterVariableFloat($ident, $x[0], $x[4], $x[5]);
            } elseif (@$this->GetIDForIdent($ident)) {
                $this->UnregisterVariable($ident);
            }
        }
    }

    // =========================================================================
    // Etagen und Räume
    // =========================================================================

    // [mapId => Karte]
    private function Maps()
    {
        $out = [];
        $l = json_decode($this->ReadAttributeString('Maps'), true);
        if (is_array($l)) foreach ($l as $m) $out[intval($m['id'])] = $m;
        return $out;
    }

    private function ActiveFloor()
    {
        $maps = $this->Maps();
        $f = $this->ReadAttributeInteger('Floor');
        if (isset($maps[$f])) return $f;
        return count($maps) ? array_keys($maps)[0] : 0;
    }

    /**
     * Alle Räume mit Einstellungen aus der Liste "Räume".
     * $all = false: nur verwendete Räume. Code = Etage*100 + Segment.
     */
    private function RoomList($all = false)
    {
        $cfg = [];
        $rows = json_decode($this->ReadPropertyString('Rooms'), true);
        if (is_array($rows)) foreach ($rows as $r) {
            if (isset($r['map'], $r['seg'])) $cfg[intval($r['map']) * 100 + intval($r['seg'])] = $r;
        }
        $maps = $this->Maps();
        $out = [];
        foreach ($maps as $id => $m) {
            foreach ($m['rooms'] as $seg => $r) {
                $code = $id * 100 + intval($seg);
                $c = isset($cfg[$code]) ? $cfg[$code] : null;
                $use = $c !== null ? (bool)$c['use'] : !$r['hidden'];
                $alias = $c !== null && isset($c['alias']) ? trim(strval($c['alias'])) : '';
                if (!$use && !$all) continue;
                $out[] = [
                    'code' => $code, 'map' => $id, 'seg' => intval($seg), 'floor' => $m['name'],
                    'app' => $r['name'], 'alias' => $alias, 'name' => $alias !== '' ? $alias : $r['name'], 'use' => $use
                ];
            }
        }
        return $out;
    }

    // Raumcode -> Anzeigename (alle verwendeten Räume, für die Kartenbeschriftung)
    private function RoomNameMap()
    {
        $out = [];
        foreach ($this->RoomList() as $r) $out[strval($r['code'])] = $r['name'];
        return $out;
    }

    private function RoomName($code, $withFloor = true)
    {
        foreach ($this->RoomList(true) as $r) {
            if ($r['code'] == $code) return $r['name'] . ($withFloor && count($this->Maps()) > 1 ? ' (' . $r['floor'] . ')' : '');
        }
        return 'Raum ' . ($code % 100);
    }

    private function RoomNames($codes)
    {
        return implode(', ', array_map(function ($c) { return $this->RoomName($c, false); }, $codes));
    }

    // Übergabe aus Skripten/Variablen in Raumcodes übersetzen
    private function ResolveRooms($rooms)
    {
        $items = is_array($rooms) ? $rooms : preg_split('/\s*[,;]\s*/', trim(strval($rooms)));
        $list = $this->RoomList(true);
        $floor = $this->ActiveFloor();
        $out = [];
        foreach ($items as $it) {
            $hit = null;
            // Ganzzahl aus einer Liste = Raumcode (Etage * 100 + Nummer), auch auf Etage 0
            if (is_int($it)) {
                foreach ($list as $r) if ($r['code'] == $it) { $hit = $r['code']; break; }
                if ($hit !== null && !in_array($hit, $out, true)) $out[] = $hit;
                continue;
            }
            $it = trim(strval($it));
            if ($it === '') continue;
            if (ctype_digit($it)) {
                $n = intval($it);
                foreach ($list as $r) {
                    if (($n >= 100 && $r['code'] == $n) || ($n < 100 && $r['map'] == $floor && $r['seg'] == $n)) { $hit = $r['code']; break; }
                }
            } else {
                foreach ($list as $r) {
                    if (mb_strtolower($r['name']) == mb_strtolower($it) || mb_strtolower($r['app']) == mb_strtolower($it)) {
                        $hit = $r['code'];
                        if ($r['map'] == $floor) break;   // gleichnamige Räume: gewählte Etage bevorzugen
                    }
                }
            }
            if ($hit !== null && !in_array($hit, $out, true)) $out[] = $hit;
        }
        return $out;
    }

    private function SelectedRooms()
    {
        $out = [];
        foreach ($this->RoomList() as $r) {
            $id = @$this->GetIDForIdent('Sel' . $r['code']);
            if ($id && GetValueBoolean($id)) $out[] = $r['code'];
        }
        return $out;
    }

    // Etagen-/Raumprofile und Auswahlschalter an die eingelesenen Karten anpassen
    private function SyncRooms()
    {
        $maps = $this->Maps();
        $assoc = [];
        foreach ($maps as $id => $m) $assoc[$id] = $m['name'];
        $this->Profile($this->FloorProfile(), 1, 'Stairs', '', '', count($assoc) ? $assoc : [-1 => '–']);

        $keep = [];
        $pos = 100;
        foreach ($this->RoomList() as $r) {
            $ident = 'Sel' . $r['code'];
            $keep[] = $ident;
            $label = 'Auswahl ' . $r['name'] . (count($maps) > 1 ? ' (' . $r['floor'] . ')' : '');
            $exists = @$this->GetIDForIdent($ident);
            $this->RegisterVariableBoolean($ident, $label, '~Switch', $pos++);
            $this->EnableAction($ident);
            if ($exists && IPS_GetName($exists) != $label && strpos(IPS_GetName($exists), 'Auswahl ') === 0) IPS_SetName($exists, $label);
        }
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $cid) {
            $o = IPS_GetObject($cid);
            if ($o['ObjectType'] == 2 && preg_match('/^Sel\d+$/', $o['ObjectIdent']) && !in_array($o['ObjectIdent'], $keep, true)) {
                $this->UnregisterVariable($o['ObjectIdent']);
            }
        }
        $this->ShowFloor();
    }

    // Alles Raumbezogene auf die gewählte Etage ausrichten
    private function ShowFloor()
    {
        $floor = $this->ActiveFloor();
        $multi = count($this->Maps()) > 1;
        $assoc = [0 => 'Raum wählen …'];
        foreach ($this->RoomList() as $r) {
            $other = $multi && $r['map'] != $floor;
            if (!$other) $assoc[$r['code']] = $r['name'];
            $id = @$this->GetIDForIdent('Sel' . $r['code']);
            if (!$id) continue;
            if (IPS_GetObject($id)['ObjectIsHidden'] != $other) IPS_SetHidden($id, $other);
            if ($other && GetValueBoolean($id)) SetValueBoolean($id, false);
        }
        $this->Profile($this->RoomProfile(), 1, 'Move', '', '', $assoc);
        if (@$this->GetIDForIdent('Floor')) $this->SetVal('Floor', count($this->Maps()) ? $floor : -1);
    }

    // ---- Vormerkung ----
    private function ReadQueue()
    {
        $q = json_decode($this->ReadAttributeString('Queue'), true);
        return is_array($q) ? array_values(array_map('intval', $q)) : [];
    }

    private function WriteQueue($q)
    {
        $this->WriteAttributeString('Queue', json_encode(array_values($q)));
        $this->SetVal('Queue', count($q) ? $this->RoomNames($q) : '–');
    }

    // Nach Auftragsende: vorgemerkte Räume der ersten Etage starten (läuft unter Sperre)
    private function StartQueue()
    {
        $q = $this->ReadQueue();
        if (count($q) == 0) return;
        $floor = intdiv($q[0], 100);
        $now = array_values(array_filter($q, function ($c) use ($floor) { return intdiv($c, 100) == $floor; }));
        $rest = array_values(array_diff($q, $now));
        $over = $this->Json($this->ReadAttributeString('QueueSettings'));
        $this->WriteAttributeString('QueueSettings', '{}');
        $this->WriteQueue($rest);
        $this->StartRooms($floor, $now, is_array($over) ? $over : []);
    }

    // =========================================================================
    // Wartung und Benachrichtigungen
    // =========================================================================

    private function CheckMaintenance()
    {
        $items = [];
        $limit = $this->ReadPropertyInteger('WearWarn');
        foreach (SaugroboterTexte::Consumables() as $ident => $c) {
            $id = @$this->GetIDForIdent('Wear' . $ident);
            if (!$id || IPS_GetVariable($id)['VariableUpdated'] == 0) continue;
            if (GetValueInteger($id) <= $limit) $items[$c[0]] = $c[0] . ' ' . GetValueInteger($id) . ' %';
        }
        $cw = $this->GetValue('CleanWater');
        if ($cw == 1) $items['cw'] = 'Frischwassertank fehlt';
        if ($cw >= 2) $items['cw'] = 'Frischwasser auffüllen';
        if ($this->GetValue('DirtyWater') == 1) $items['dw'] = 'Schmutzwasser leeren';
        $bag = $this->GetValue('DustBag');
        if ($bag == 1) $items['bag'] = 'Staubbeutel fehlt';
        if ($bag == 2) $items['bag'] = 'Staubbeutel prüfen';
        $this->SetVal('Maintenance', count($items) ? implode(', ', $items) : '–');

        $old = json_decode($this->ReadAttributeString('LastMaintenance'), true);
        if (!is_array($old)) $old = [];
        $new = array_diff(array_keys($items), $old);
        $this->WriteAttributeString('LastMaintenance', json_encode(array_keys($items)));
        if (count($new) && $this->ReadPropertyBoolean('NotifyMaintenance')) {
            $this->Push('Wartung', implode(', ', array_intersect_key($items, array_flip($new))));
        }
    }

    private function CheckError($code)
    {
        if ($code == $this->ReadAttributeInteger('LastErrorCode')) return;
        $this->WriteAttributeInteger('LastErrorCode', $code);
        if ($code == 0 || !$this->ReadPropertyBoolean('NotifyError')) return;
        $hint = SaugroboterTexte::ErrorHint($code);
        $this->Push(in_array($code, SaugroboterTexte::WarningCodes(), true) ? 'Hinweis' : 'Störung',
            SaugroboterTexte::ErrorText($code) . ($hint !== '' ? ' – ' . $hint : ''));
    }

    // außerhalb der Sperre aufgerufen
    private function JobFinished()
    {
        $wasAuto = $this->ReadAttributeInteger('JobByAuto') == 1;
        $this->WriteAttributeInteger('JobByAuto', 0);
        if (!$this->ReadPropertyBoolean('NotifyDone')) return;
        $last = $this->GetValue('LastRun');
        $info = json_decode($this->ReadAttributeString('JobInfo'), true);
        $text = $last !== '' ? preg_replace('/^\S+ \S+ \S+ · /', '', $last)
            : (intval($info['min']) . ' min, ' . intval($info['area']) . ' m²');
        $this->Push($wasAuto ? 'Automatik fertig' : 'Reinigung fertig', $text);
    }

    // Push über Kachel-Visualisierung oder WebFront; immer auch ins Meldungsfenster
    private function Push($title, $text)
    {
        $name = IPS_GetName($this->InstanceID);
        $this->LogMessage($name . ' – ' . $title . ': ' . $text, KL_NOTIFY);
        $target = $this->ReadPropertyInteger('NotifyTarget');
        if ($target <= 0 || !IPS_InstanceExists($target)) return;
        try {
            $module = IPS_GetInstance($target)['ModuleInfo']['ModuleName'];
            if (stripos($module, 'WebFront') !== false) {
                @WFC_PushNotification($target, mb_substr($name . ': ' . $title, 0, 32), mb_substr($text, 0, 256), '', 0);
            } elseif (function_exists('VISU_PostNotification')) {
                @VISU_PostNotification($target, $name . ': ' . $title, $text, 'Robot', $this->InstanceID);
            }
        } catch (Throwable $e) {
            $this->SendDebug('Push', $e->getMessage(), 0);
        }
    }

    // =========================================================================
    // Automatik: reinigen, wenn niemand zu Hause ist
    // =========================================================================

    private function WatchPresence()
    {
        $old = $this->ReadAttributeInteger('PresenceWatched');
        $new = $this->ReadPropertyInteger('PresenceVariable');
        if ($old > 0 && $old != $new) @$this->UnregisterMessage($old, VM_UPDATE);
        if ($new > 0 && IPS_VariableExists($new)) $this->RegisterMessage($new, VM_UPDATE);
        $this->WriteAttributeInteger('PresenceWatched', $new > 0 && IPS_VariableExists($new) ? $new : 0);
        if (IPS_GetKernelRunlevel() != KR_READY) $this->RegisterMessage(0, IPS_KERNELSTARTED);
    }

    /**
     * Zeitplan-Einträge für heute ("zur Uhrzeit"): jeder Eintrag mit Tag, Uhrzeit, Räumen, Programm.
     * Ohne Einträge gilt ein täglicher Standard-Eintrag (Standard-Uhrzeit, Räume/Programm der Automatik).
     * Rückgabe: [['key','ts','time','rooms','prog','done'], …] nach Uhrzeit sortiert; null = heute frei.
     */
    private function EntriesToday()
    {
        $rows = json_decode($this->ReadPropertyString('AutoPlan'), true);
        $dow = intval(date('N'));
        $done = json_decode($this->ReadAttributeString('AutoDone'), true);
        if (!is_array($done)) $done = [];
        $std = $this->AutoStartToday();
        $out = [];
        if (!is_array($rows) || !count($rows)) {
            $days = preg_replace('/[^1-7]/', '', $this->ReadPropertyString('AutoDays'));
            if ($days !== '' && strpos($days, strval($dow)) === false) return [];
            $text = trim($this->ReadPropertyString('AutoRooms'));
            $rows = [['day' => 0, 'time' => '', 'prog' => -1, 'rooms' => $text]];
        }
        foreach ($rows as $i => $r) {
            $d = intval($r['day'] ?? 0);
            if (!($d == 0 || $d == $dow || ($d == 8 && $dow <= 5) || ($d == 9 && $dow >= 6))) continue;
            $codes = $this->PlanCodes($r);
            if ($codes === '-') return null;          // "frei" an diesem Tag schlägt alles
            $t = isset($r['time']) && preg_match('/^(\d{1,2}):(\d{2})$/', trim(strval($r['time'])), $m) ? mktime(intval($m[1]), intval($m[2]), 0) : $std;
            $p = intval($r['prog'] ?? -1);
            if ($p < 0 || !isset(self::PROGRAMS[$p])) $p = intval($this->GetValue('AutoProgram'));
            $key = md5($i . '|' . $d . '|' . date('H:i', $t));
            $out[] = ['key' => $key, 'ts' => $t, 'time' => date('H:i', $t), 'rooms' => $codes, 'prog' => $p, 'done' => ($done[$key] ?? '') === date('Ymd')];
        }
        usort($out, function ($a, $b) { return $a['ts'] - $b['ts']; });
        return $out;
    }

    // Fälliger Eintrag: Uhrzeit erreicht, heute noch nicht gelaufen, höchstens 3 Stunden her
    private function DueEntry()
    {
        $list = $this->EntriesToday();
        if (!is_array($list)) return null;
        foreach ($list as $e) {
            if (!$e['done'] && $e['ts'] <= time() && time() <= $e['ts'] + 3 * 3600) return $e;
        }
        return null;
    }

    // Automatik "zur Uhrzeit": '' = jetzt starten, sonst der Grund
    private function AutoTimeBlocker()
    {
        $list = $this->EntriesToday();
        if ($list === null) return 'heute frei';
        if (!count($list)) return 'heute kein Zeitplan';
        $due = $this->DueEntry();
        if ($due === null) {
            foreach ($list as $e) if (!$e['done'] && $e['ts'] > time()) return 'nächster Start heute um ' . $e['time'];
            $doneAny = count(array_filter($list, function ($e) { return $e['done']; }));
            return $doneAny ? 'heute erledigt' : 'heute verpasst';
        }
        if (!$this->GetValue('Online')) return 'Roboter nicht erreichbar';
        if ($this->ReadAttributeInteger('Job') == 1) return 'Roboter ist unterwegs – Start ' . $due['time'] . ' folgt danach';
        if ($this->GetValue('Battery') < $this->ReadPropertyInteger('AutoBattery')) return 'wartet auf Akku (' . $this->GetValue('Battery') . ' %)';
        if ($this->GetValue('Error') != 0 && !in_array($this->GetValue('Error'), SaugroboterTexte::WarningCodes(), true)) return 'Störung am Gerät';
        if (intval($this->GetBuffer('AutoRetry')) > time()) return 'neuer Versuch ' . date('H:i', intval($this->GetBuffer('AutoRetry')));
        return '';
    }

    // Heutiger Startzeitpunkt der Automatik "zur Uhrzeit"
    private function AutoStartToday()
    {
        $t = @$this->GetIDForIdent('AutoTime') ? strval($this->GetValue('AutoTime')) : '10:00';
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $t, $m)) $m = [0, 10, 0];
        return mktime(intval($m[1]), intval($m[2]), 0);
    }

    private function AutoByPlan()
    {
        return @$this->GetIDForIdent('AutoMode') && intval($this->GetValue('AutoMode')) == 1;
    }

    private function Home()
    {
        $vid = $this->ReadPropertyInteger('PresenceVariable');
        if ($vid <= 0 || !IPS_VariableExists($vid)) return null;
        $v = (bool)GetValue($vid);
        return $this->ReadPropertyBoolean('PresenceInverted') ? !$v : $v;
    }

    private function PresenceChanged()
    {
        if (!$this->AutoByPlan() && $this->Home() === true && $this->ReadAttributeInteger('JobByAuto') == 1
            && $this->ReadAttributeInteger('Job') == 1 && $this->ReadPropertyBoolean('AutoReturn')) {
            $this->Dock();
            $this->WriteAttributeInteger('JobByAuto', 0);
            $this->Push('Automatik', 'Jemand ist zurück – Roboter fährt zur Station.');
        }
        $this->UpdateAutoTimer();
    }

    private function UpdateAutoTimer()
    {
        $on = $this->GetValue('AutoAway') && $this->ReadPropertyBoolean('Active') && ($this->AutoByPlan() || $this->Home() !== null);
        $this->SetTimerInterval('Auto', $on ? 60000 : 0);
        $why = $this->AutoBlocker();
        $this->SetVal('AutoStatus', $why === '' ? 'startet bei der nächsten Prüfung' : $why);
    }

    // Timer: jede Minute, solange die Automatik an ist
    public function AutoCheck()
    {
        $why = $this->AutoBlocker();
        $this->SetVal('AutoStatus', $why === '' ? 'startet …' : $why);
        if ($why !== '') return false;

        $entry = null;
        if ($this->AutoByPlan()) {
            // "zur Uhrzeit": der fällige Zeitplan-Eintrag bestimmt Räume und Programm
            $entry = $this->DueEntry();
            if ($entry === null) return false;
            $rooms = $entry['rooms'];
            $prog = $entry['prog'];
        } else {
            $rooms = $this->AutoRoomsToday();
            $prog = $this->AutoProgramToday();
        }
        $over = $this->ProgramSettings($prog);
        if (!count($rooms)) $ok = $over === null ? $this->CleanAll() : $this->Locked(function () use ($over) { return $this->StartAll($over); });
        else $ok = $this->CleanRoomsWith($rooms, json_encode($over === null ? [] : $over));
        if ($ok) {
            $this->WriteAttributeInteger('LastAuto', time());
            $this->WriteAttributeInteger('JobByAuto', 1);
            if ($entry !== null) {
                $done = json_decode($this->ReadAttributeString('AutoDone'), true);
                if (!is_array($done)) $done = [];
                $done[$entry['key']] = date('Ymd');
                $this->WriteAttributeString('AutoDone', json_encode($done));
            }
            $what = (!count($rooms) ? 'alles' : $this->RoomNames($rooms)) . ($prog > 0 ? ', ' . self::PROGRAMS[$prog][0] : '');
            $this->SetVal('AutoStatus', 'gestartet ' . date('H:i') . ' (' . $what . ')');
            if ($this->ReadPropertyBoolean('NotifyAuto')) $this->Push('Automatik', ($entry !== null ? 'Zeitplan ' . $entry['time'] : 'Niemand zu Hause') . ' – Reinigung gestartet: ' . $what . '.');
        } else {
            $this->SetBuffer('AutoRetry', strval(time() + 1800));
            $this->SetVal('AutoStatus', 'Start fehlgeschlagen, neuer Versuch ' . date('H:i', time() + 1800));
        }
        $this->RefreshViews();
        return $ok;
    }

    /**
     * Räume für heute laut Raumplan: [] = alles, '-' = heute nicht, sonst Raumcodes.
     * Genaueste Zeile gewinnt: bestimmter Tag vor Mo–Fr/Sa+So vor täglich. Ohne passende Zeile gilt "Räume".
     */
    // Raumplan für die Kachel: Zeilen in Häkchenform (alte Textzeilen umgesetzt)
    private function PlanView()
    {
        $rows = json_decode($this->ReadPropertyString('AutoPlan'), true);
        $out = [];
        if (is_array($rows)) foreach ($rows as $r) {
            $codes = $this->PlanCodes($r);
            $v = ['day' => intval($r['day'] ?? 0), 'time' => strval($r['time'] ?? ''), 'prog' => intval($r['prog'] ?? -1), 'off' => $codes === '-'];
            if (is_array($codes)) foreach ($codes as $c) $v['r' . $c] = true;
            $out[] = $v;
        }
        return $out;
    }

    // "Heute: Küche, Bad · Gründlich saugen" für die Kachel
    private function AutoTodayText()
    {
        if ($this->AutoByPlan()) {
            $list = $this->EntriesToday();
            if ($list === null) return 'Heute frei';
            if (!count($list)) return 'Heute kein Zeitplan';
            return 'Heute: ' . implode(', ', array_map(function ($e) {
                return $e['time'] . ' ' . (count($e['rooms']) ? $this->RoomNames($e['rooms']) : 'alles') . ($e['done'] ? ' ✓' : '');
            }, $list));
        }
        $rooms = $this->AutoRoomsToday();
        if ($rooms === '-') return 'Heute frei';
        $p = $this->AutoProgramToday();
        return 'Heute: ' . (count($rooms) ? $this->RoomNames($rooms) : 'alles') . ' · ' . self::PROGRAMS[$p][0];
    }

    // Passende Zeile des Raumplans für heute (genaueste gewinnt) oder null
    private function PlanRowToday()
    {
        $rows = json_decode($this->ReadPropertyString('AutoPlan'), true);
        $dow = intval(date('N'));
        $best = null; $rank = -1;
        if (is_array($rows)) foreach ($rows as $r) {
            $d = isset($r['day']) ? intval($r['day']) : 0;
            $match = $d == $dow ? 3 : (($d == 8 && $dow <= 5) || ($d == 9 && $dow >= 6) ? 2 : ($d == 0 ? 1 : 0));
            if ($match > $rank && $match > 0) { $rank = $match; $best = $r; }
        }
        return $best;
    }

    // Programm für heute: aus dem Raumplan, sonst das allgemeine der Automatik
    private function AutoProgramToday()
    {
        $row = $this->PlanRowToday();
        $p = $row !== null && isset($row['prog']) ? intval($row['prog']) : -1;
        if ($p < 0 || !isset(self::PROGRAMS[$p])) $p = intval($this->GetValue('AutoProgram'));
        return isset(self::PROGRAMS[$p]) ? $p : 0;
    }

    // Aktuelle Vorwahlen als Einstellungen ("Eigene Einstellungen")
    private function PresetValues()
    {
        $o = [];
        foreach (['Mode', 'Suction', 'Wetness', 'Passes', 'Route', 'CleanGenius'] as $k) $o[$k] = intval($this->GetValue($k));
        return $o;
    }

    // Einstellungen eines Programms (vollständig, damit keine Vorwahl hineinrutscht); null = Vorwahlen
    private function ProgramSettings($p)
    {
        if (!isset(self::PROGRAMS[$p]) || self::PROGRAMS[$p][1] === null) return null;
        return self::PROGRAMS[$p][1] + ['Mode' => -1, 'Suction' => -1, 'Wetness' => -1, 'Passes' => 1, 'Route' => -1, 'CleanGenius' => -1];
    }

    private function AutoRoomsToday()
    {
        $row = $this->PlanRowToday();
        $best = $row === null ? null : $this->PlanCodes($row);
        if ($best === null) {
            $text = trim($this->ReadPropertyString('AutoRooms'));
            $best = $text === '-' ? '-' : ($text === '' ? [] : $this->ResolveRooms($text));
        }
        return $best;
    }

    // '' = darf starten, sonst der Grund
    private function AutoBlocker()
    {
        if (!$this->GetValue('AutoAway')) return 'aus';
        // "zur Uhrzeit": einmal am Tag zur festen Zeit, egal ob jemand zu Hause ist
        if ($this->AutoByPlan()) return $this->AutoTimeBlocker();
        {
            $home = $this->Home();
            if ($home === null) return 'keine Anwesenheitsvariable gewählt';
            if ($home) return 'wartet – jemand ist zu Hause';
            $vid = $this->ReadPropertyInteger('PresenceVariable');
            $since = IPS_GetVariable($vid)['VariableChanged'];
            $delay = $this->ReadPropertyInteger('AutoDelay') * 60;
            if (time() - $since < $delay) return 'wartet bis ' . date('H:i', $since + $delay);
        }
        $last = $this->ReadAttributeInteger('LastAuto');
        $gap = $this->ReadPropertyInteger('AutoGap') * 3600;
        if ($last > 0 && time() - $last < $gap) return 'erledigt (' . $this->Weekday($last) . ' ' . date('H:i', $last) . ')';
        $days = preg_replace('/[^1-7]/', '', $this->ReadPropertyString('AutoDays'));
        if ($days !== '' && strpos($days, date('N')) === false) return 'heute nicht';
        $from = $this->ReadPropertyInteger('AutoFrom');
        $to = $this->ReadPropertyInteger('AutoTo');
        $h = intval(date('G'));
        if ($from != $to && !($from < $to ? ($h >= $from && $h < $to) : ($h >= $from || $h < $to))) {
            return 'außerhalb ' . $from . '–' . $to . ' Uhr';
        }
        if ($this->AutoRoomsToday() === '-') return 'heute laut Raumplan frei';
        if (!$this->GetValue('Online')) return 'Roboter nicht erreichbar';
        if ($this->ReadAttributeInteger('Job') == 1) return 'Roboter ist unterwegs';
        if ($this->GetValue('Battery') < $this->ReadPropertyInteger('AutoBattery')) {
            return 'wartet auf Akku (' . $this->GetValue('Battery') . ' %)';
        }
        if ($this->GetValue('Error') != 0 && !in_array($this->GetValue('Error'), SaugroboterTexte::WarningCodes(), true)) {
            return 'Störung am Gerät';
        }
        if (intval($this->GetBuffer('AutoRetry')) > time()) return 'neuer Versuch ' . date('H:i', intval($this->GetBuffer('AutoRetry')));
        return '';
    }

    // =========================================================================
    // Visualisierung
    // =========================================================================

    public function GetVisualizationTile()
    {
        $html = file_get_contents(__DIR__ . '/module.html');
        $html = str_replace('/*STYLE*/', file_get_contents(__DIR__ . '/tile.css'), $html);
        return str_replace('"__INIT__"', json_encode(['view' => $this->ViewModel(), 'map' => $this->MapDataUri(null, true),
            'mapLast' => $this->MapDataUri('MapLast', true), 'bg' => $this->BackgroundUri()], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), $html);
    }

    // ---- Hintergrundbild der Kachel ----
    // Bild auf höchstens 1600 px verkleinern, als JPEG speichern (klein, schnell geladen)
    private function StoreBackground($b64)
    {
        $raw = base64_decode(preg_replace('#^data:[^,]*,#', '', $b64), true);
        if ($raw === false || strlen($raw) < 100 || strlen($raw) > 25 * 1048576) { $this->Note('Hintergrundbild nicht lesbar.', 'err'); return; }
        $out = $raw; $type = 'jpeg';
        if (function_exists('imagecreatefromstring') && ($img = @imagecreatefromstring($raw)) !== false) {
            $w = imagesx($img); $h = imagesy($img);
            $f = min(1, 1600 / max($w, $h));
            $nw = max(1, intval($w * $f)); $nh = max(1, intval($h * $f));
            $dst = imagecreatetruecolor($nw, $nh);
            imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
            ob_start(); imagejpeg($dst, null, 80); $out = ob_get_clean();
            imagedestroy($img); imagedestroy($dst);
        } else {
            // ohne GD: nur kleine Bilder unverändert übernehmen
            if (strlen($raw) > 3 * 1048576) { $this->Note('Hintergrundbild zu groß (max. 3 MB ohne Bildbearbeitung).', 'err'); return; }
            $type = substr($raw, 0, 4) === "\x89PNG" ? 'png' : 'jpeg';
        }
        $mid = @IPS_GetObjectIDByIdent('TileBackground', $this->InstanceID);
        if (!$mid) {
            $mid = IPS_CreateMedia(1);
            IPS_SetParent($mid, $this->InstanceID);
            IPS_SetIdent($mid, 'TileBackground');
            IPS_SetName($mid, 'Kachel-Hintergrund');
            IPS_SetPosition($mid, 4);
            IPS_SetHidden($mid, true);
        }
        IPS_SetMediaFile($mid, 'media/Saugroboter_' . $this->InstanceID . '_Hintergrund.' . ($type == 'png' ? 'png' : 'jpg'), false);
        IPS_SetMediaContent($mid, base64_encode($out));
        $this->WriteAttributeString('BgType', $type);
        $this->SetBuffer('BgStamp', strval(microtime(true)));
        $this->Note('Hintergrundbild übernommen (' . round(strlen($out) / 1024) . ' KB).', 'ok');
    }

    // Button "Hintergrund entfernen"
    public function RemoveBackground()
    {
        $mid = @IPS_GetObjectIDByIdent('TileBackground', $this->InstanceID);
        if ($mid) IPS_DeleteMedia($mid, true);
        $this->SetBuffer('BgStamp', strval(microtime(true)));
        $this->Note('Hintergrundbild entfernt.', 'ok');
        $this->RefreshViews();
        return true;
    }

    private function BackgroundUri()
    {
        $mid = @IPS_GetObjectIDByIdent('TileBackground', $this->InstanceID);
        $c = $mid ? @IPS_GetMediaContent($mid) : '';
        return $c ? 'data:image/' . ($this->ReadAttributeString('BgType') ?: 'jpeg') . ';base64,' . $c : '';
    }

    // Kachel und HTML-Box auffrischen. Das Kartenbild geht nur nach einer Änderung mit.
    private function RefreshViews()
    {
        if (!$this->ReadPropertyBoolean('Active')) return;
        $vm = $this->ViewModel();
        if (method_exists($this, 'UpdateVisualizationValue')) {
            $msg = ['view' => $vm];
            $last = $this->GetBuffer('LastStamp');
            if ($last !== $this->GetBuffer('LastSent')) {
                $msg['mapLast'] = $this->MapDataUri('MapLast', true);
                $this->SetBuffer('LastSent', $last);
            }
            $bs = $this->GetBuffer('BgStamp');
            if ($bs !== $this->GetBuffer('BgSent')) {
                $msg['bg'] = $this->BackgroundUri();
                $this->SetBuffer('BgSent', $bs);
            }
            $stamp = $this->GetBuffer('MapStamp');
            if ($stamp !== $this->GetBuffer('MapSent')) {
                $msg['map'] = $this->MapDataUri(null, true);
                $this->SetBuffer('MapSent', $stamp);
            }
            $this->UpdateVisualizationValue(json_encode($msg));
        }
        if (@$this->GetIDForIdent('Dashboard')) $this->SetVal('Dashboard', $this->RenderBox($vm));
    }

    // Alles, was die Kachel anzeigt, als ein Datenpaket (die Kachel zeichnet selbst)
    private function ViewModel()
    {
        $state = $this->GetValue('State');
        $err = $this->GetValue('Error');
        $floor = $this->ActiveFloor();
        $multi = count($this->Maps()) > 1;
        $rooms = [];
        foreach ($this->RoomList() as $r) {
            if ($multi && $r['map'] != $floor) continue;
            $id = @$this->GetIDForIdent('Sel' . $r['code']);
            $rooms[] = ['code' => $r['code'], 'name' => $r['name'], 'sel' => $id ? GetValueBoolean($id) : false];
        }
        $floors = [];
        foreach ($this->Maps() as $id => $m) $floors[] = ['id' => $id, 'name' => $m['name']];
        $wear = [];
        foreach (SaugroboterTexte::Consumables() as $ident => $c) {
            $id = @$this->GetIDForIdent('Wear' . $ident);
            if ($id) $wear[] = ['id' => $ident, 'name' => $c[0], 'v' => GetValueInteger($id)];
        }
        $opt = function ($ident) {
            $o = [];
            foreach (IPS_GetVariableProfile('SAUG.' . $ident)['Associations'] as $a) $o[] = [$a['Value'], $a['Name']];
            return ['v' => $this->GetValue($ident), 'o' => $o];
        };
        return [
            'name' => IPS_GetName($this->InstanceID),
            'online' => $this->GetValue('Online'),
            // Seit wann nichts Frisches mehr vom Roboter kam (weder direkt noch live) – dann ist der Stand veraltet
            'staleSince' => intval($this->GetBuffer('FreshAt')) > 0 && time() - intval($this->GetBuffer('FreshAt')) > 300 ? date('H:i', intval($this->GetBuffer('FreshAt'))) : '',
            'live' => $this->ReadPropertyBoolean('Live') ? $this->LiveOk() : null,
            'state' => $state, 'stateText' => GetValueFormatted($this->GetIDForIdent('State')),
            'group' => SaugroboterTexte::StateGroup($state),
            'job' => $this->ReadAttributeInteger('Job') == 1,
            'battery' => $this->GetValue('Battery'),
            'charging' => @$this->GetIDForIdent('Charging') ? $this->GetValue('Charging') : false,
            'room' => $this->GetValue('Room'),
            'progress' => @$this->GetIDForIdent('Progress') ? $this->GetValue('Progress') : null,
            'time' => $this->GetValue('CleanTime'), 'area' => $this->GetValue('CleanArea'),
            'error' => $err, 'errorText' => $err ? SaugroboterTexte::ErrorText($err) : '', 'errorHint' => SaugroboterTexte::ErrorHint($err),
            'warning' => in_array($err, SaugroboterTexte::WarningCodes(), true),
            'clearable' => in_array($err, SaugroboterTexte::ClearableCodes(), true),
            'station' => [
                ['Frischwasser', $this->GetValue('CleanWater'), GetValueFormatted($this->GetIDForIdent('CleanWater'))],
                ['Schmutzwasser', $this->GetValue('DirtyWater'), GetValueFormatted($this->GetIDForIdent('DirtyWater'))],
                ['Staubbeutel', $this->GetValue('DustBag'), GetValueFormatted($this->GetIDForIdent('DustBag'))]
            ],
            'floors' => $floors, 'floor' => $floor, 'rooms' => $rooms,
            'queue' => $this->GetValue('Queue'),
            'presets' => ['Mode' => $opt('Mode'), 'Suction' => $opt('Suction'), 'Wetness' => $opt('Wetness'),
                'Route' => $opt('Route'), 'Passes' => $opt('Passes'), 'CleanGenius' => $opt('CleanGenius')],
            'wear' => $wear, 'wearWarn' => $this->ReadPropertyInteger('WearWarn'),
            'maintenance' => $this->GetValue('Maintenance'),
            'history' => array_values(array_filter(explode("\n", $this->GetValue('History')))),
            'auto' => $this->GetValue('AutoAway'), 'autoStatus' => $this->GetValue('AutoStatus'),
            'autoConfigured' => $this->AutoByPlan() || $this->Home() !== null,
            'autoMode' => $this->AutoByPlan() ? 1 : 0,
            'hasPresence' => $this->Home() !== null,
            'autoTime' => @$this->GetIDForIdent('AutoTime') ? strval($this->GetValue('AutoTime')) : '10:00',
            'cleanProgram' => intval($this->GetValue('CleanProgram')),
            'programs' => array_map(function ($id) {
                $s = $this->ProgramSettings($id);
                return ['id' => $id, 'name' => self::PROGRAMS[$id][0], 'set' => $s === null ? $this->PresetValues() : $s, 'custom' => $s === null];
            }, array_keys(self::PROGRAMS)),
            'programNames' => array_map(function ($p) { return $p[0]; }, self::PROGRAMS),
            'autoProgram' => intval($this->GetValue('AutoProgram')),
            'plan' => $this->PlanView(),
            'planRooms' => array_map(function ($r) { return ['code' => $r['code'], 'name' => $r['name'] . (count($this->Maps()) > 1 ? ' (' . $r['floor'] . ')' : '')]; }, $this->RoomList()),
            'autoToday' => $this->AutoTodayText(),
            'bgDim' => max(0, min(90, $this->ReadPropertyInteger('BgDim'))),
            'bgBlur' => max(0, min(20, $this->ReadPropertyInteger('BgBlur'))),
            'cardOpacity' => max(0, min(100, $this->ReadPropertyInteger('CardOpacity'))),
            'cardGlass' => max(0, min(40, $this->ReadPropertyInteger('CardGlass'))),
            'theme' => max(0, min(2, $this->ReadPropertyInteger('Theme'))),
            'message' => $this->GetValue('Message'),
            'mapMeta' => $this->MapMeta($this->MapIdent()),
            'lastMeta' => $this->MapMeta('MapLast'),
            // Roboter/Station als Symbole über die Karte legen, wenn es die Kartenfassung ohne sie gibt
            'overlay' => $this->GetBuffer('Clean' . $this->MapIdent()) !== '',
            'toast' => $this->ToastView(),
            'overlayLast' => $this->GetBuffer('CleanMapLast') !== '',
            'model' => $this->ModelShort(),
            'lastRun' => $this->GetValue('LastRun'),
            'roomNames' => $this->RoomNameMap()
        ];
    }

    // Kompakte Übersicht als HTML-Box (WebFront/IPSView, nur Anzeige)
    private function RenderBox($vm)
    {
        $h = function ($s) { return htmlspecialchars(strval($s), ENT_QUOTES, 'UTF-8'); };
        $css = 'font-family:inherit;color:inherit;max-width:520px';
        $o = '<div style="' . $css . '">';
        $o .= '<div style="display:flex;justify-content:space-between;align-items:baseline"><b style="font-size:1.2em">' . $h($vm['stateText']) . '</b>'
            . '<span>' . intval($vm['battery']) . ' %' . ($vm['charging'] ? ' ⚡' : '') . '</span></div>';
        if ($vm['job']) $o .= '<div style="opacity:.75">' . $h($vm['room']) . ' · ' . intval($vm['time']) . ' min · ' . intval($vm['area']) . ' m²</div>';
        if ($vm['error']) $o .= '<div style="margin:6px 0;padding:6px 8px;border-radius:6px;background:rgba(214,98,86,.18)">' . $h($vm['errorText']) . ($vm['errorHint'] ? '<br><small>' . $h($vm['errorHint']) . '</small>' : '') . '</div>';
        $map = $this->MapDataUri();
        if ($map !== '') $o .= '<img src="' . $map . '" style="width:100%;margin:8px 0;image-rendering:pixelated">';
        $o .= '<div style="opacity:.8">';
        foreach ($vm['station'] as $s) $o .= $h($s[0]) . ': ' . $h($s[2]) . ' &nbsp; ';
        $o .= '</div>';
        if ($vm['maintenance'] !== '–') $o .= '<div style="color:#d9a13b;margin-top:4px">' . $h($vm['maintenance']) . '</div>';
        if (count($vm['history'])) $o .= '<div style="opacity:.7;margin-top:6px;font-size:.9em">' . $h($vm['history'][0]) . '</div>';
        return $o . '</div>';
    }

    // =========================================================================
    // Hilfen
    // =========================================================================

    /**
     * Führt $fn unter der Instanzsperre aus (immer nur ein Cloud-Zugriff gleichzeitig).
     * $background = true (Timer-Abruf): nicht warten, sondern überspringen, wenn gerade etwas läuft
     * oder ein Bedienbefehl ansteht – Bedienung hat Vorrang vor dem Abruf.
     */
    private function Locked($fn, $background = false)
    {
        if (!$this->ReadPropertyBoolean('Active') || !$this->ReadPropertyBoolean('Consent')) { $this->Note('Instanz ist deaktiviert.', 'err'); return false; }
        $key = 'SAUG_' . $this->InstanceID;
        if ($background) {
            if (intval($this->GetBuffer('UserWaiting')) > time() - 90) return null;
            if (!IPS_SemaphoreEnter($key, 0)) return null;
        } else {
            // Abruf bitten, Platz zu machen, und bis zu 60 s auf ihn warten
            $this->SetBuffer('UserWaiting', strval(time()));
            $got = false;
            for ($i = 0; $i < 240 && !$got; $i++) $got = IPS_SemaphoreEnter($key, 250);
            $this->SetBuffer('UserWaiting', '0');
            if (!$got) {
                $this->Note('Instanz beschäftigt – bitte erneut versuchen.', 'err');
                echo 'Der Roboter antwortet gerade sehr langsam – bitte gleich noch einmal versuchen.';
                return false;
            }
        }
        try {
            $this->dcLastError = '';
            return $fn();
        } finally {
            IPS_SemaphoreLeave($key);
        }
    }

    private function Result($ok, $label)
    {
        if ($ok) { $this->Online(true); $this->Note($label . ' – gesendet.', 'ok'); }
        else $this->Note($label . ' fehlgeschlagen: ' . ($this->dcLastError !== '' ? $this->dcLastError : 'unbekannter Fehler'), 'err');
    }

    // Kurzzeitige Aussetzer der Cloud nicht sofort als "getrennt" melden (erst ab dem 3. Fehlversuch)
    private function Online($ok)
    {
        if ($ok) {
            $this->SetBuffer('Fails', '0');
            $this->SetVal('Online', true);
            return;
        }
        $n = intval($this->GetBuffer('Fails')) + 1;
        $this->SetBuffer('Fails', strval($n));
        if ($n >= 3) $this->SetVal('Online', false);
        if ($this->dcLastError !== '') $this->Note($this->dcLastError);
    }

    // $kind: null = nur "Letzte Meldung"; ok/err/info = zusätzlich als Rückmeldung in der Kachel
    private function Note($text, $kind = null)
    {
        $this->SetVal('Message', date('H:i') . ' ' . $text);
        if ($kind !== null) $this->SetBuffer('Toast', json_encode(['id' => uniqid(), 'at' => time(), 'text' => $text, 'kind' => $kind]));
        $this->SendDebug('Meldung', $text, 0);
        $this->UpdateStatusForm();
    }

    private function PollSoon()
    {
        $this->SetBuffer('FastUntil', strval(time() + 30));
        $this->SetTimerInterval('Poll', 10000);
    }

    private function SetPollInterval()
    {
        if (!$this->ReadPropertyBoolean('Active') || !$this->ReadPropertyBoolean('Consent')) { $this->SetTimerInterval('Poll', 0); return; }
        // Mindestabstände schonen die Cloud (und das Konto)
        $s = max(30, $this->ReadPropertyInteger('Interval'));
        // Während eines Auftrags schneller abfragen – außer die Live-Verbindung liefert ohnehin alles sofort
        // (Kartenbilder eingeschlossen – meldet das Gerät live nur den Zustand, Karte im kurzen Takt holen)
        $liveMap = $this->LiveOk() && time() - intval($this->GetBuffer('LiveMapAt')) < 60;
        if ($this->ReadAttributeInteger('Job') == 1) $s = $liveMap ? min($s, 60) : max(10, min($s, $this->ReadPropertyInteger('IntervalBusy')));
        if (intval($this->GetBuffer('FastUntil')) > time() && !$this->LiveOk()) $s = 10;
        $this->SetTimerInterval('Poll', $s * 1000);
    }

    // Variable nur bei Änderung schreiben (spart Ereignisse und Archiveinträge)
    private function SetVal($Ident, $Value)
    {
        $id = @$this->GetIDForIdent($Ident);
        if (!$id) return false;
        if (GetValue($id) !== $Value) $this->SetValue($Ident, $Value);
        return true;
    }

    private function StateName($code)
    {
        $s = SaugroboterTexte::States();
        return isset($s[intval($code)]) ? $s[intval($code)] : ('Zustand ' . intval($code));
    }

    private function FloorProfile() { return 'SAUG.Floors.' . $this->InstanceID; }
    private function RoomProfile()  { return 'SAUG.Rooms.' . $this->InstanceID; }

    // Profil anlegen/aktualisieren. $assoc = [Wert => Text] oder null
    private function Profile($name, $type, $icon, $prefix, $suffix, $assoc = null, $min = 0, $max = 0)
    {
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, $type);
            IPS_SetVariableProfileIcon($name, $icon);
            IPS_SetVariableProfileText($name, $prefix, $suffix);
            if ($type == 2) IPS_SetVariableProfileDigits($name, 1);
            if ($max > $min) IPS_SetVariableProfileValues($name, $min, $max, 1);
        }
        if ($assoc === null) return;
        $p = IPS_GetVariableProfile($name);
        foreach ($p['Associations'] as $a) {
            if (!array_key_exists($a['Value'], $assoc)) IPS_SetVariableProfileAssociation($name, $a['Value'], '', '', -1);
        }
        foreach ($assoc as $v => $t) IPS_SetVariableProfileAssociation($name, $v, $t, '', -1);
    }
}
