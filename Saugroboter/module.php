<?php

/*
 * Saugroboter – IP-Symcon-Modul für Saugroboter mit Dreame-Cloud-Anbindung (X50, X60 u. a.).
 * Inoffiziell: nicht mit Dreame verbunden, nicht von Dreame unterstützt.
 *
 * Aufbau
 *   libs/SaugroboterApi.php    Anmeldung, MiOT-Befehle, Dateien, Ereignisse (Trait)
 *   libs/SaugroboterKarte.php    Kartenblöcke dekodieren (inkl. AES) und als PNG zeichnen
 *   libs/SaugroboterTexte.php  Zustände, Fehler mit Kurzhilfe, Raumtypen, Verschleißteile
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

class X60Ultra extends IPSModule
{
    use SaugroboterApi;

    // AES-IV der Kartendaten aktueller Dreame-Modelle (X40/X50/X60)
    const MAP_IV = 'NRwnBj5FsNPgBNbT';

    // Befehle der Variable "Befehl"
    const CMD = [
        1 => 'Alles reinigen', 2 => 'Pause', 3 => 'Fortsetzen', 4 => 'Stopp', 5 => 'Zur Station',
        6 => 'Auswahl reinigen', 7 => 'Auswahl anhängen', 8 => 'Auswahl leeren', 9 => 'Vormerkung verwerfen',
        10 => 'Mopp waschen', 11 => 'Mopp trocknen', 12 => 'Trocknen beenden', 13 => 'Staub absaugen',
        14 => 'Hinweis quittieren', 15 => 'Orten'
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
        $this->RegisterPropertyString('Rooms', '[]');
        $this->RegisterPropertyBoolean('MapImage', true);
        $this->RegisterPropertyInteger('MapRotate', -1);        // -1 = wie in der App
        $this->RegisterPropertyBoolean('MapPath', true);
        $this->RegisterPropertyString('MapFormat', 'auto');     // Zellformat der Karte (auto/shift/low6/low5)
        $this->RegisterPropertyInteger('WearWarn', 10);
        $this->RegisterPropertyInteger('HistoryCount', 5);
        $this->RegisterPropertyBoolean('DashboardBox', false);
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
        $this->RegisterAttributeString('LastLog', '');      // Startzeit der Fahrt hinter "Letzte Reinigung"

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

        // Anmeldung und Gerät nach Konfigurationsänderung frisch ermitteln
        $this->WriteAttributeString('Token', '');
        $this->WriteAttributeString('Device', '');

        $this->SyncCapabilities();
        $this->SyncRooms();
        if ($this->ReadPropertyBoolean('DashboardBox')) {
            $this->RegisterVariableString('Dashboard', 'Übersicht', '~HTMLBox', 1);
        } elseif (@$this->GetIDForIdent('Dashboard')) {
            $this->UnregisterVariable('Dashboard');
        }
        $this->WatchPresence();

        // Passwort nie im Klartext speichern: die Cloud erwartet ohnehin nur einen Hash.
        // Einmalig umwandeln, danach steht in den Einstellungen (und Backups) nur noch "hash:...".
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
            return;
        }
        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SetStatus(104);
            $this->SetTimerInterval('Poll', 0);
            $this->SetTimerInterval('Auto', 0);
            return;
        }
        if (trim($this->ReadPropertyString('Email')) === '' || $this->ReadPropertyString('Password') === '') {
            $this->SetStatus(201);
            $this->SetTimerInterval('Poll', 0);
            $this->SetTimerInterval('Auto', 0);
            return;
        }
        $this->SetStatus(102);
        $this->SetPollInterval();
        $this->UpdateAutoTimer();
        $this->RefreshViews();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
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
        // Versionszeile ganz unten
        $lib = json_decode(@file_get_contents(__DIR__ . '/../library.json'), true);
        if (is_array($lib)) {
            $form['actions'][] = ['type' => 'Label', 'italic' => true, 'caption' => $lib['name'] . ' · Version ' . $lib['version']
                . ' · Build ' . $lib['build'] . ' · ' . substr(strval($lib['date']), 6, 2) . '.' . substr(strval($lib['date']), 4, 2) . '.'
                . substr(strval($lib['date']), 0, 4) . ' · © ' . $lib['author']];
        }
        return json_encode($form);
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
                $this->SetVal($Ident, intval($Value));
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
            case 'TileRoom':
                // Kachel: Raum in der Auswahl umschalten (Wert = Raumcode)
                $id = @$this->GetIDForIdent('Sel' . intval($Value));
                if ($id) $this->RequestAction('Sel' . intval($Value), !GetValueBoolean($id));
                return;
        }
        if (strpos($Ident, 'Sel') === 0) {
            $code = intval(substr($Ident, 3));
            if ((bool)$Value && intdiv($code, 100) != $this->ActiveFloor()) {
                $this->Note('Der Raum liegt auf einer anderen Etage – erst die Etage wechseln.');
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
    public function AcknowledgeWarning() { return $this->Send('Hinweis quittieren', 4, 3); }

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
        return $this->Locked(function () {
            if (!$this->PrepareFloor($this->ActiveFloor())) return false;
            $this->ApplyPresets();
            $ok = $this->MiotAction(2, 1);
            $this->Result($ok, 'Alles reinigen');
            if ($ok) { $this->WriteAttributeInteger('JobRooms', 0); $this->PollSoon(); }
            return $ok;
        });
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
        if (count($codes) == 0) { $this->Note('Keine passenden Räume gefunden.'); return false; }
        $floors = array_unique(array_map(function ($c) { return intdiv($c, 100); }, $codes));
        if (count($floors) > 1) { $this->Note('Ein Durchgang kann nur Räume einer Etage reinigen.'); return false; }
        if ($this->ReadAttributeInteger('Job') == 1 && count($over)) {
            $q = $this->ReadQueue();
            foreach ($codes as $c) if (!in_array($c, $q, true)) $q[] = $c;
            $this->WriteAttributeString('QueueSettings', json_encode($over));
            $this->WriteQueue($q);
            $this->Note('Vorgemerkt: ' . $this->RoomNames($q));
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
        return is_array($over) && isset($over[$name]) ? intval($over[$name]) : $this->GetValue($name);
    }

    public function CleanSelection()
    {
        $sel = $this->SelectedRooms();
        if (count($sel) == 0) { $this->Note('Es ist kein Raum ausgewählt.'); return false; }
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
        if (count($sel) == 0) { $this->Note('Es ist kein Raum ausgewählt.'); return false; }
        if ($this->ReadAttributeInteger('Job') == 0) return $this->CleanSelection();
        $q = $this->ReadQueue();
        foreach ($sel as $c) if (!in_array($c, $q, true)) $q[] = $c;
        $this->WriteQueue($q);
        $this->ClearSelection();
        $this->Note('Vorgemerkt: ' . $this->RoomNames($q));
        return true;
    }

    // Etage wählen. Während eines Auftrags wird nur die Anzeige umgestellt – der Wechsel am
    // Gerät würde die laufende Reinigung abbrechen. Das Gerät folgt beim nächsten Start.
    public function SelectFloor($MapID)
    {
        $maps = $this->Maps();
        if (!isset($maps[$MapID])) { $this->Note('Unbekannte Etage ' . $MapID . '.'); return false; }
        $this->WriteAttributeInteger('Floor', intval($MapID));
        $this->ShowFloor();
        if ($this->ReadAttributeInteger('Job') == 0 && $this->ReadPropertyBoolean('Active')) {
            $this->Locked(function () use ($MapID) { return $this->PrepareFloor(intval($MapID)); });
        } else {
            $this->Note('Etage in der Anzeige gewechselt – das Gerät folgt beim nächsten Start.');
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
        $this->ApplyPresets($over);

        $fan = $this->Setting('Suction', $over);
        $water = $this->Setting('Wetness', $over);
        if ($fan < 0 || $water < 1) {
            $cur = $this->MiotGet([[4, 4], [4, 5]]);
            if ($fan < 0) $fan = isset($cur['4.4']) ? intval($cur['4.4']) : 1;
            if ($water < 1) $water = isset($cur['4.5']) ? intval($cur['4.5']) : 2;
        }
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
    private function ApplyPresets($over = [])
    {
        $fan = $this->Setting('Suction', $over);
        $wet = $this->Setting('Wetness', $over);
        $route = $this->Setting('Route', $over);
        $cg = $this->Setting('CleanGenius', $over);
        $mode = $this->Setting('Mode', $over);

        $need = [[4, 23], [4, 50]];
        $cur = $this->MiotGet($need);
        if ($cur === null) $cur = [];

        // CleanGenius bestimmt Saugkraft, Feuchte und Route selbst. Wer diese Werte vorwählt,
        // meint sie auch – dann CleanGenius für diese Fahrt ausschalten.
        $devCg = isset($cur['4.50']) ? $this->AutoSwitch($cur['4.50'], 'SmartHost') : null;
        if ($cg < 0 && $devCg !== null && $devCg > 0 && ($fan >= 0 || $wet >= 1 || $route >= 1)) $cg = 0;
        if ($cg >= 0 && $devCg !== null && $cg != $devCg) {
            $this->MiotSet([[4, 50, json_encode(['k' => 'SmartHost', 'v' => $cg])]]);
        }

        if ($mode >= 0 && isset($cur['4.23'])) {
            // Moduswert ist gepackt: unterste zwei Bits = Modus (Geräte mit Mopp-Anhebung)
            $bits = [0 => 2, 1 => 1, 2 => 0, 3 => 3][$mode];
            $raw = intval($cur['4.23']);
            if (($raw & 3) != $bits) $this->MiotSet([[4, 23, ($raw & ~3) | $bits]]);
        }
        $set = [];
        if ($fan >= 0) $set[] = [4, 4, $fan];
        if ($wet >= 1) $set[] = [4, 5, $wet];
        if (count($set)) $this->MiotSet($set);
        if ($route >= 1) $this->MiotSet([[4, 50, json_encode(['k' => 'CleanRoute', 'v' => $route])]]);
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
                if ($this->ReadPropertyBoolean('MapImage')) $this->StoreMapImage($b, $b['mapId'], 'MapFloor' . $b['mapId']);
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
            $r[] = 'Live-Karte ' . preg_replace('#^.*/#', '…/', $o) . ': ' . $this->BlockReport($this->CloudFile($o));
        }
        $out = implode("\n", $r);
        $this->SendDebug('Kartendiagnose', $out, 0);
        return $out;
    }

    private function BlockReport($text)
    {
        if ($text === null) return 'nicht ladbar';
        $b = SaugroboterKarte::Decode($text, self::MAP_IV);
        if ($b === null) return 'nicht dekodierbar (' . strlen($text) . ' Zeichen, ' . (strpos($text, ',') !== false ? 'mit' : 'ohne') . ' Schlüssel)';
        $segs = isset($b['info']['seg_inf']) && is_array($b['info']['seg_inf']) ? implode(',', array_keys($b['info']['seg_inf'])) : '–';
        $hist = [];
        for ($i = 0; $i < strlen($b['cells']); $i++) { $c = ord($b['cells'][$i]); if ($c) $hist[$c] = (isset($hist[$c]) ? $hist[$c] : 0) + 1; }
        arsort($hist);
        $top = [];
        foreach (array_slice($hist, 0, 8, true) as $v => $c) $top[] = $v . '×' . $c;
        $sc = SaugroboterKarte::FormatScores($b);
        return 'Typ ' . $b['type'] . ', Karte ' . $b['mapId'] . ', ' . $b['w'] . '×' . $b['h'] . ', Raster ' . $b['grid']
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

    public function Poll()
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
                foreach (SaugroboterTexte::Consumables() as $c) if ($this->HasCap($caps, $c[1], $c[2])) $keys[] = [$c[1], $c[2]];
                $this->SetBuffer('SlowAt', strval(time()));
            }
            $v = $this->MiotGet($keys);
            if ($v === null || !isset($v['2.1'])) { $this->Online(false); return false; }
            $this->Online(true);
            if ($this->dcFromCache) $this->Note('Roboter antwortet nicht direkt – Werte aus dem Cloud-Speicher.');

            $state = intval($v['2.1']);
            $this->SetVal('State', $state);
            $err = isset($v['2.2']) ? intval($v['2.2']) : 0;
            $this->SetVal('Error', $err);
            $this->SetVal('ErrorHint', SaugroboterTexte::ErrorHint($err));
            if (isset($v['3.1'])) $this->SetVal('Battery', intval($v['3.1']));
            if (isset($v['4.2'])) $this->SetVal('CleanTime', intval($v['4.2']));
            if (isset($v['4.3'])) $this->SetVal('CleanArea', intval($v['4.3']));

            // Station: 27/1 meldet nur "Tank steckt", leer steht in 4/41
            $cw = isset($v['27.1']) ? intval($v['27.1']) : -1;
            $low = isset($v['4.41']) ? intval($v['4.41']) : 0;
            if ($cw == 0 && $low >= 2) $cw = 3;
            $this->SetVal('CleanWater', $cw);
            $this->SetVal('DirtyWater', isset($v['27.2']) ? intval($v['27.2']) : -1);
            $this->SetVal('DustBag', isset($v['27.3']) ? intval($v['27.3']) : -1);

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

            $this->SetVal('DeviceSettings', $this->DescribeSettings($v));

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
                $ended = $group == 'docked' || in_array($state, [8, 22, 35, 104], true);
                if ($ended) {
                    $this->WriteAttributeInteger('Job', 0);
                    $done = true;
                }
            }

            $this->TrackRoom($state, $group);
            // Nach einem Update fehlt die Raumlage zum vorhandenen Kartenbild (Beschriftung/Antippen) –
            // dann die Karte einmal frisch holen, auch wenn der Roboter an der Station steht
            if ($this->ReadPropertyBoolean('MapImage') && $this->MapMeta('Map') === null && intval($this->GetBuffer('MetaTry')) < time() - 300) {
                $this->SetBuffer('MetaTry', strval(time()));
                $this->FetchLiveMap(true);
            }
            if ($done) {
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
        }, true);
        if ($ok === null) return false;   // übersprungen: anderer Zugriff läuft
        if ($done) {
            $this->JobFinished();
        }
        $this->SetPollInterval();
        $this->RefreshViews();
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
        if ($b === null) return;
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
        $last = intval($this->GetBuffer('LiveAt'));
        if (!$force && time() - $last < 20) return $this->LiveBlock();
        $this->SetBuffer('LiveAt', strval(time()));

        $base = $this->LiveBlock();
        $got = null;
        foreach ($this->LiveMapObjects() as $obj) {
            $text = $this->CloudFile($obj);
            $b = $text === null ? null : SaugroboterKarte::Decode($text, self::MAP_IV);
            if ($b === null) continue;
            if ($b['type'] === 'I') { $got = $b; break; }
            if ($b['type'] === 'P' && $base !== null && $b['mapId'] == $base['mapId']) {
                if (strval($b['frameId']) === $this->GetBuffer('LiveFrame')) { $got = $base; break; }   // schon angewendet
                $got = SaugroboterKarte::Merge($base, $b, $this->IsV2());
                break;
            }
        }
        if ($got === null) return $base;
        $this->SetBuffer('LiveFrame', strval($got['frameId']));
        $this->SetBuffer('LiveBlock', base64_encode(gzcompress(serialize($got))));
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
    private function LiveMapObjects()
    {
        $out = [];
        $v = $this->MapKeys();
        if (isset($v['6.3'])) {
            $o = $v['6.3'];
            if (is_string($o) && is_array($j = json_decode($o, true))) $o = $j;
            if (is_array($o)) $o = reset($o);
            if (is_string($o) && $o !== '') $out[] = explode(',', $o)[0];
        }
        if (isset($v['6.8'])) {
            $l = $this->Json($v['6.8']);
            if (isset($l['object_name']) && ($p = strrpos($l['object_name'], '/')) !== false) {
                $out[] = substr($l['object_name'], 0, $p + 1) . '0';
            }
        }
        return array_values(array_unique($out));
    }

    private function StoreMapImage($b, $mapId, $ident)
    {
        $rot = $this->ReadPropertyInteger('MapRotate');
        if ($rot < 0) {
            $maps = $this->Maps();
            $rot = isset($maps[$mapId]) ? $maps[$mapId]['angle'] : 0;
        }
        $format = $this->CellFormat($b);
        $png = SaugroboterKarte::Render($b, $format, [
            'rotate' => $rot, 'path' => $this->ReadPropertyBoolean('MapPath') || $ident == 'MapLast',
            'selected' => array_map(function ($c) { return $c % 100; }, $this->SelectedRooms())
        ]);
        if ($png === null) return;
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

    private function MapDataUri($ident = null)
    {
        if ($ident === null) $ident = $this->MapIdent();
        if ($ident === '') return '';
        $mid = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        $c = $mid ? @IPS_GetMediaContent($mid) : '';
        return $c ? 'data:image/png;base64,' . $c : '';
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
            $it = trim(strval($it));
            if ($it === '') continue;
            $hit = null;
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

    private function Home()
    {
        $vid = $this->ReadPropertyInteger('PresenceVariable');
        if ($vid <= 0 || !IPS_VariableExists($vid)) return null;
        $v = (bool)GetValue($vid);
        return $this->ReadPropertyBoolean('PresenceInverted') ? !$v : $v;
    }

    private function PresenceChanged()
    {
        if ($this->Home() === true && $this->ReadAttributeInteger('JobByAuto') == 1
            && $this->ReadAttributeInteger('Job') == 1 && $this->ReadPropertyBoolean('AutoReturn')) {
            $this->Dock();
            $this->WriteAttributeInteger('JobByAuto', 0);
            $this->Push('Automatik', 'Jemand ist zurück – Roboter fährt zur Station.');
        }
        $this->UpdateAutoTimer();
    }

    private function UpdateAutoTimer()
    {
        $on = $this->GetValue('AutoAway') && $this->ReadPropertyBoolean('Active') && $this->Home() !== null;
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

        $rooms = $this->AutoRoomsToday();
        $ok = $rooms === '' ? $this->CleanAll() : $this->CleanRooms($rooms);
        if ($ok) {
            $this->WriteAttributeInteger('LastAuto', time());
            $this->WriteAttributeInteger('JobByAuto', 1);
            $what = $rooms === '' ? 'alles' : $rooms;
            $this->SetVal('AutoStatus', 'gestartet ' . date('H:i') . ' (' . $what . ')');
            if ($this->ReadPropertyBoolean('NotifyAuto')) $this->Push('Automatik', 'Niemand zu Hause – Reinigung gestartet: ' . $what . '.');
        } else {
            $this->SetBuffer('AutoRetry', strval(time() + 1800));
            $this->SetVal('AutoStatus', 'Start fehlgeschlagen, neuer Versuch ' . date('H:i', time() + 1800));
        }
        $this->RefreshViews();
        return $ok;
    }

    /**
     * Räume für heute laut Raumplan: '' = alles, '-' = heute nicht, sonst Raumliste.
     * Genaueste Zeile gewinnt: bestimmter Tag vor Mo–Fr/Sa+So vor täglich. Ohne passende Zeile gilt "Räume".
     */
    private function AutoRoomsToday()
    {
        $rows = json_decode($this->ReadPropertyString('AutoPlan'), true);
        $dow = intval(date('N'));
        $best = null; $rank = -1;
        if (is_array($rows)) foreach ($rows as $r) {
            $d = isset($r['day']) ? intval($r['day']) : 0;
            $match = $d == $dow ? 3 : (($d == 8 && $dow <= 5) || ($d == 9 && $dow >= 6) ? 2 : ($d == 0 ? 1 : 0));
            if ($match > $rank && $match > 0) { $rank = $match; $best = trim(isset($r['rooms']) ? strval($r['rooms']) : ''); }
        }
        return $best !== null ? $best : trim($this->ReadPropertyString('AutoRooms'));
    }

    // '' = darf starten, sonst der Grund
    private function AutoBlocker()
    {
        if (!$this->GetValue('AutoAway')) return 'aus';
        $home = $this->Home();
        if ($home === null) return 'keine Anwesenheitsvariable gewählt';
        if ($home) return 'wartet – jemand ist zu Hause';
        $vid = $this->ReadPropertyInteger('PresenceVariable');
        $since = IPS_GetVariable($vid)['VariableChanged'];
        $delay = $this->ReadPropertyInteger('AutoDelay') * 60;
        if (time() - $since < $delay) return 'wartet bis ' . date('H:i', $since + $delay);
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
        return str_replace('"__INIT__"', json_encode(['view' => $this->ViewModel(), 'map' => $this->MapDataUri(),
            'mapLast' => $this->MapDataUri('MapLast')]), $html);
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
                $msg['mapLast'] = $this->MapDataUri('MapLast');
                $this->SetBuffer('LastSent', $last);
            }
            $stamp = $this->GetBuffer('MapStamp');
            if ($stamp !== $this->GetBuffer('MapSent')) {
                $msg['map'] = $this->MapDataUri();
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
            'autoConfigured' => $this->Home() !== null,
            'message' => $this->GetValue('Message'),
            'mapMeta' => $this->MapMeta($this->MapIdent()),
            'lastMeta' => $this->MapMeta('MapLast'),
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
        if (!$this->ReadPropertyBoolean('Active') || !$this->ReadPropertyBoolean('Consent')) { $this->Note('Instanz ist deaktiviert.'); return false; }
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
                $this->Note('Instanz beschäftigt – bitte erneut versuchen.');
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
        if ($ok) { $this->Online(true); $this->Note($label . ' – gesendet.'); }
        else $this->Note($label . ' fehlgeschlagen: ' . ($this->dcLastError !== '' ? $this->dcLastError : 'unbekannter Fehler'));
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

    private function Note($text)
    {
        $this->SetVal('Message', date('H:i') . ' ' . $text);
        $this->SendDebug('Meldung', $text, 0);
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
        if ($this->ReadAttributeInteger('Job') == 1) $s = max(10, min($s, $this->ReadPropertyInteger('IntervalBusy')));
        if (intval($this->GetBuffer('FastUntil')) > time()) $s = 10;
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
