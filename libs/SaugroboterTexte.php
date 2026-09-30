<?php

/**
 * Saugroboter – Klartexte und Kennungstabellen.
 *
 * Die Codes und ihre Bedeutung stammen aus dem offengelegten Protokollwissen des Projekts
 * Tasshack/dreame-vacuum (MIT-Lizenz). Übersetzung und Kurzhilfen: eigene Formulierung.
 */
class SaugroboterTexte
{
    // Gerätezustand (MiOT 2/1)
    public static function States()
    {
        return [
            -1 => 'Unbekannt', 1 => 'Saugt', 2 => 'Bereit', 3 => 'Pausiert', 4 => 'Störung',
            5 => 'Fährt zur Station', 6 => 'Lädt', 7 => 'Wischt', 8 => 'Mopp trocknet', 9 => 'Mopp wird gewaschen',
            10 => 'Fährt zur Moppwäsche', 11 => 'Erstellt Karte', 12 => 'Saugt und wischt', 13 => 'Voll geladen',
            14 => 'Firmware-Update', 15 => 'Wird gerufen', 16 => 'Station setzt sich zurück',
            17 => 'Holt Mopp', 18 => 'Legt Mopp ab', 19 => 'Prüft Wasserzufuhr', 20 => 'Wäscht Mopp, füllt Wasser nach',
            21 => 'Moppwäsche pausiert', 22 => 'Staub wird abgesaugt', 23 => 'Fernsteuerung', 24 => 'Intelligentes Laden',
            25 => 'Zweite Reinigung', 26 => 'Folgt Person', 27 => 'Punktreinigung', 28 => 'Fährt zum Absaugen',
            29 => 'Wartet auf Auftrag', 30 => 'Station reinigt sich', 31 => 'Fährt zum Entleeren', 32 => 'Entleert Wasser',
            33 => 'Wasserzufuhr wird entleert', 34 => 'Entleert', 35 => 'Staubbeutel trocknet', 36 => 'Staubbeutel-Trocknung pausiert',
            37 => 'Fährt zur Nachreinigung', 38 => 'Nachreinigung', 95 => 'Haustiersuche pausiert', 96 => 'Sucht Haustier',
            97 => 'Kurzbefehl läuft', 98 => 'Überwachung', 99 => 'Überwachung pausiert',
            101 => 'Erste Tiefenreinigung', 102 => 'Erste Tiefenreinigung pausiert', 103 => 'Desinfiziert',
            104 => 'Desinfiziert und trocknet', 105 => 'Wechselt Mopp', 106 => 'Moppwechsel pausiert',
            107 => 'Bodenpflege', 108 => 'Bodenpflege pausiert', 109 => 'Hebt Gegenstand auf', 113 => 'Räumt auf',
            114 => 'Haustierwache', 115 => 'Haustierwache pausiert', 116 => 'Montiert Mopp', 117 => 'Nimmt Mopp ab',
            118 => 'Lädt nach', 120 => 'Assistierte Reinigung', 121 => 'Fährt in die Station', 122 => 'Verlässt die Station',
            140 => 'Fährt zum Treppenmodul', 141 => 'Dockt an Treppenmodul an', 142 => 'Am Treppenmodul',
            143 => 'Treppenmodul fährt', 144 => 'Steigt Treppe', 145 => 'Treppe geschafft'
        ];
    }

    // Einordnung eines Zustands für Logik und Anzeige
    public static function StateGroup($state)
    {
        $working = [1, 7, 11, 12, 15, 23, 25, 26, 27, 37, 38, 101, 107, 109, 113, 120, 144];
        $paused  = [3, 21, 36, 95, 99, 102, 106, 108, 115];
        $moving  = [5, 10, 17, 18, 28, 31, 121, 122, 140, 141, 143];
        $station = [8, 9, 16, 19, 20, 22, 30, 32, 33, 34, 35, 103, 104, 105, 116, 117];
        $docked  = [2, 6, 13, 24, 29, 118, 142, 145];
        if (in_array($state, $working, true)) return 'working';
        if (in_array($state, $paused, true)) return 'paused';
        if (in_array($state, $moving, true)) return 'moving';
        if (in_array($state, $station, true)) return 'station';
        if (in_array($state, $docked, true)) return 'docked';
        if ($state == 4) return 'error';
        return 'unknown';
    }

    // Fehler (MiOT 2/2): Code => [Meldung, Kurzhilfe]
    public static function Errors()
    {
        $restart = 'Roboter neu starten.';
        $stuck   = 'Roboter in einen freien Bereich stellen und Auftrag neu starten.';
        $int     = ['Interner Fehler', $restart];
        return [
            0 => ['Kein Fehler', ''],
            1 => ['Räder hängen in der Luft', 'Roboter neu aufsetzen und starten.'],
            2 => ['Absturzsensor', 'Absturzsensoren abwischen, nicht an Treppen starten.'],
            3 => ['Stoßfänger klemmt', 'Stoßfänger reinigen und leicht antippen.'],
            4 => ['Roboter steht schräg', 'Auf ebenen Boden stellen.'],
            5 => ['Stoßfänger klemmt', 'Stoßfänger reinigen und leicht antippen.'],
            6 => ['Räder hängen in der Luft', 'Roboter neu aufsetzen und starten.'],
            7 => ['Fehler optischer Bodensensor', $restart],
            8 => ['Staubbehälter fehlt', 'Staubbehälter mit Filter einsetzen.'],
            9 => ['Wassertank fehlt', 'Wassertank einsetzen.'],
            10 => ['Wassertank leer', 'Wassertank füllen.'],
            11 => ['Filter nass oder verstopft', 'Filter trocknen lassen bzw. reinigen.'],
            12 => ['Hauptbürste verheddert', 'Hauptbürste ausbauen, Borsten und Lager reinigen.'],
            13 => ['Seitenbürste verheddert', 'Seitenbürste abnehmen und reinigen.'],
            14 => ['Filter nass oder verstopft', 'Filter trocknen lassen bzw. reinigen.'],
            15 => ['Linkes Rad blockiert', 'Rad von Fremdkörpern befreien, an anderer Stelle starten.'],
            16 => ['Rechtes Rad blockiert', 'Rad von Fremdkörpern befreien, an anderer Stelle starten.'],
            17 => ['Roboter kann sich nicht drehen', $stuck],
            18 => ['Roboter kommt nicht vorwärts', $stuck],
            19 => ['Station nicht gefunden', 'Stromkabel der Station prüfen.'],
            20 => ['Akku fast leer', 'Roboter laden.'],
            21 => ['Ladefehler', 'Ladekontakte an Roboter und Station trocken abwischen.'],
            22 => ['Akkustand fehlerhaft', ''],
            23 => $int,
            24 => ['Kamera-Positionssensor', 'Sensorfenster reinigen.'],
            25 => ['Bewegungssensor', $restart],
            26 => ['Optischer Sensor', 'Sensor abwischen und neu starten.'],
            27 => ['Infrarotsensor abgedeckt', $restart],
            28 => ['Station ohne Strom', 'Stromkabel der Station prüfen.'],
            29 => ['Akkutemperatur', 'Warten, bis sich die Temperatur normalisiert.'],
            30 => ['Lüftersensor', $restart],
            31 => ['Linkes Rad blockiert', 'Rad von Fremdkörpern befreien.'],
            32 => ['Rechtes Rad blockiert', 'Rad von Fremdkörpern befreien.'],
            33 => ['Beschleunigungssensor', $restart],
            34 => ['Gyroskop', $restart],
            35 => ['Gyroskop', $restart],
            36 => ['Linker Magnetsensor', $restart],
            37 => ['Rechter Magnetsensor', $restart],
            38 => ['Durchflusssensor', $restart],
            39 => ['Infrarot', $restart],
            40 => ['Kamera', $restart],
            41 => ['Starkes Magnetfeld', 'Abseits von Magnetstreifen starten.'],
            42 => ['Wasserpumpe', $restart],
            43 => ['Echtzeituhr', $restart],
            44 => $int, 45 => $int, 46 => $int,
            47 => ['Weg blockiert', 'Roboter fährt zur Station zurück.'],
            48 => ['Laser-Abstandssensor', 'Laserturm auf eingeklemmte Gegenstände prüfen.'],
            49 => ['Stoßschutz Laserturm', 'Stoßschutz des Laserturms prüfen.'],
            50 => ['Wasserpumpe', $restart],
            51 => ['Filter nass oder verstopft', 'Filter trocknen lassen bzw. reinigen.'],
            54 => ['Kantensensor', 'Kantensensor prüfen und reinigen.'],
            55 => ['Teppich unter dem Roboter', 'Zum Wischen auf Hartboden starten.'],
            56 => ['3D-Hinderniserkennung', 'Sensor reinigen.'],
            57 => ['Kantensensor', 'Kantensensor prüfen und reinigen.'],
            58 => ['Ultraschallsensor', $restart],
            59 => ['Sperrzone oder virtuelle Wand', 'Roboter aus dem Bereich holen und neu starten.'],
            61 => ['Ziel nicht erreichbar', 'Türen öffnen, Hindernisse entfernen.'],
            62 => ['Ziel nicht erreichbar', 'Sperrzone auf dem Weg entfernen.'],
            63 => ['Weg blockiert', 'Türen öffnen, Hindernisse entfernen.'],
            64 => ['Weg blockiert', 'Sperrzone entfernen oder Roboter umsetzen.'],
            65 => ['Roboter in Sperrzone', 'Roboter aus dem Bereich holen.'],
            66 => ['Roboter in Sperrzone', 'Roboter aus dem Bereich holen.'],
            67 => ['Roboter in Sperrzone', 'Roboter aus dem Bereich holen.'],
            68 => ['Wischen beendet', 'Mopp abnehmen und reinigen.'],
            69 => ['Mopp-Pad abgefallen', 'Mopp-Pads montieren, dann fortsetzen.'],
            70 => ['Mopp-Pad abgefallen', 'Mopp-Pads montieren, dann fortsetzen.'],
            71 => ['Mopp-Pad dreht nicht', 'Mopp-Pads prüfen.'],
            72 => ['Mopp-Pad dreht nicht', 'Mopp-Pads prüfen.'],
            74 => ['Mopp-Montage fehlgeschlagen', 'Mopp-Pads von Hand montieren.'],
            75 => ['Akku fast leer', 'Roboter schaltet gleich ab.'],
            76 => ['Schmutzwasserbehälter im Roboter fehlt', 'Behälter korrekt einsetzen.'],
            78 => ['Bereich ausgeblendet', 'Roboter in einen sichtbaren Bereich stellen.'],
            79 => ['Laserturm fährt nicht aus', 'Umgebung des Laserturms freiräumen.'],
            80 => ['Positionierung nicht möglich', 'In einen freien Bereich stellen und fortsetzen.'],
            81 => ['Positionierung nicht möglich', 'In einen freien Bereich stellen und fortsetzen.'],
            82 => ['Rutschiger Boden', 'Später erneut versuchen.'],
            84 => ['Unbekannter Fehler', ''],
            85 => ['Mopp-Montage prüfen', 'Sitz der Mopps prüfen.'],
            86 => ['Schmutzwasserbehälter verschmutzt', 'Behälter reinigen.'],
            88 => ['Hubbeine verheddert', 'Hubbeine prüfen.'],
            89 => $int,
            90 => ['Roboter steckt fest', $stuck],
            91 => ['Festgefahren zwischen Tisch und Stühlen', $stuck],
            92 => ['Festgefahren in Engstelle', $stuck],
            93 => ['Festgefahren an Schwelle', $stuck],
            94 => ['Festgefahren unter niedrigem Möbel', $stuck],
            95 => ['Kippgefährliche Rampe erkannt', 'Ggf. Schwelle als befahrbar einstellen.'],
            96 => ['Hindernis im Weg', 'Hindernis entfernen und neu starten.'],
            97 => ['Person oder Tier im Weg', 'Weg freimachen und neu starten.'],
            98 => ['Räder rutschen durch', 'Antriebsräder reinigen.'],
            99 => ['Rutscht auf Teppich', 'Vom Teppich wegstellen und neu starten.'],
            101 => ['Staubbeutel voll / Luftkanal verstopft', 'Staubbeutel wechseln, Kanal prüfen.'],
            102 => ['Stationsdeckel offen oder Beutel fehlt', 'Deckel schließen, Beutel einsetzen.'],
            103 => ['Stationsdeckel offen oder Beutel fehlt', 'Deckel schließen, Beutel einsetzen.'],
            104 => ['Staubbeutel voll / Luftkanal verstopft', 'Staubbeutel wechseln, Kanal prüfen.'],
            105 => ['Frischwassertank fehlt', 'Frischwassertank einsetzen.'],
            106 => ['Schmutzwassertank voll oder fehlt', 'Schmutzwassertank leeren und einsetzen.'],
            107 => ['Frischwasser fast leer', 'Frischwassertank füllen.'],
            108 => ['Schmutzwassertank voll', 'Schmutzwassertank leeren.'],
            109 => ['Schmutzwasserkanal blockiert', $restart],
            110 => ['Schmutzwasserpumpe', $restart],
            111 => ['Waschbrett nicht eingesetzt', 'Waschbrett korrekt einsetzen.'],
            112 => ['Wasserstand im Waschbrett', 'Waschbrett reinigen.'],
            114 => ['Reinigung fertig', 'Waschbrett der Station reinigen.'],
            116 => ['Frischwasser nachfüllen', 'Frischwassertank füllen.'],
            117 => ['Station ohne Strom', 'Stromversorgung der Station prüfen.'],
            118 => ['Schmutzwassertank zu voll', 'Schmutzwassertank leeren.'],
            119 => ['Waschbrett zu voll', 'Schmutzwassertank und Waschbrett reinigen.'],
            120 => ['Mopp-Pad nicht in der Station', 'Mopp in die Station legen oder am Roboter montieren.'],
            121 => ['Staubbeutel prüfen', 'Ggf. wechseln, Absaugöffnungen reinigen.'],
            122 => ['Unbekannte Warnung', ''],
            123 => ['Selbsttest: kein Wasser', 'Frischwassertank füllen.'],
            124 => ['Waschbrett steht', 'Waschbrett auf Verhedderungen prüfen.'],
            125 => ['Schmutzwasser läuft nicht ab', 'Kundendienst kontaktieren.'],
            126 => ['Mopp nicht erkannt', 'Mopp montieren und fortsetzen.'],
            127 => ['Mopp-Halter in der Station', 'Halter einsetzen bzw. richtig platzieren.'],
            128 => ['Stationsfehler', 'Klappe schließen, Mopps prüfen.'],
            129 => ['Moppwäsche fehlgeschlagen', 'Mopp von Hand montieren, Station prüfen.'],
            200 => ['Festgefahren im Vorhang', 'Vom Vorhang wegstellen und neu starten.'],
            201 => ['Kantenmopp dreht nicht', 'Auf Teppich/Verhedderung prüfen.'],
            202 => ['Kantenmopp fehlt', 'Kantenmopp montieren.'],
            203 => ['Chassis-Hub gestört', 'Auftrag neu starten.'],
            207 => $int,
            209 => ['Moppabdeckung', 'Walzenmopp und Abdeckung reinigen.'],
            210 => ['Walzenmopp', 'Walzenmopp und Abdeckung reinigen.'],
            212 => ['Roboterarm gestoppt', 'Stations- und Arm-Taste gedrückt halten (Reset).'],
            213 => ['Frischwasser im Roboter fast leer', 'Nachfüllen.'],
            214 => ['Schmutzwasser im Roboter voll', 'Behälter leeren oder zur Station schicken.'],
            215 => ['Mopp nicht montiert', 'Walzenmopp einsetzen.'],
            217 => ['Laser-Abstandssensor', 'Laserturm auf eingeklemmte Gegenstände prüfen.'],
            218 => ['Walzenmopp', 'Walzenmopp und Abdeckung reinigen.'],
            222 => ['Aufplusterwalze', 'Walze ausbauen und reinigen.'],
            223 => ['Moppabdeckung', 'Walzenmopp und Abdeckung reinigen.'],
            224 => ['Moppabdeckung', 'Walzenmopp und Abdeckung reinigen.'],
            225 => ['Walzenmopp', 'Walzenmopp und Abdeckung reinigen.'],
            226 => ['Durch Hindernis blockiert', 'Hindernis vor dem Roboter entfernen.'],
            227 => ['Abwasserfilter verstopft', 'Abwasserfilter des Roboters reinigen.'],
            228 => ['Antriebsräder', 'Räder prüfen, dann fortsetzen.'],
            229 => $int, 230 => $int,
            1000 => ['Rückkehr zur Station fehlgeschlagen', 'Rampe und Umgebung der Station prüfen.']
        ];
    }

    // Codes, die das Gerät nur als quittierbaren Hinweis führt (keine echte Störung)
    public static function WarningCodes()
    {
        return [9, 10, 20, 47, 51, 56, 68, 70, 71, 72, 75, 82, 85, 107, 114, 117, 121, 122, 123, 129, 213, 214];
    }

    // Reine Info-Meldungen, die die Dreame-App gar nicht anzeigt (der X60 meldet z. B. 68 "Wischen beendet"
    // nach jeder Wischfahrt und hält sie stehen). Werden weder in der Kachel noch als Benachrichtigung gezeigt.
    public static function SilentCodes()
    {
        return [68];
    }

    // Hinweise, die sich am Gerät quittieren lassen (wie in der App)
    public static function ClearableCodes()
    {
        return [20, 68, 70, 75, 82, 84, 114, 117, 121, 123, 213, 214];
    }

    public static function ErrorText($code)
    {
        $e = self::Errors();
        return isset($e[$code]) ? $e[$code][0] : ('Code ' . $code);
    }

    public static function ErrorHint($code)
    {
        $e = self::Errors();
        return isset($e[$code]) ? $e[$code][1] : '';
    }

    // Raumtyp aus seg_inf.type
    public static function RoomTypes()
    {
        return [
            1 => 'Wohnzimmer', 2 => 'Schlafzimmer', 3 => 'Arbeitszimmer', 4 => 'Küche', 5 => 'Esszimmer',
            6 => 'Bad', 7 => 'Balkon', 8 => 'Flur', 9 => 'Hauswirtschaftsraum', 10 => 'Ankleide',
            11 => 'Besprechungsraum', 12 => 'Büro', 13 => 'Fitnessraum', 14 => 'Spielzimmer', 15 => 'Kinder-/Gästezimmer'
        ];
    }

    // Abbruchgrund in der Historie
    public static function InterruptReason($code)
    {
        $r = [
            11 => 'Roboter angehoben', 12 => 'Roboter umgekippt', 13 => 'Absturzsensor', 14 => 'Mopp entfernt',
            15 => 'Mopp abgefallen', 16 => 'Mopp verklemmt', 21 => 'Bürste blockiert', 22 => 'Bürste im Teppich',
            24 => 'Laserturm', 25 => 'an Schwelle festgefahren', 26 => 'an Hindernis festgefahren',
            27 => 'Station ohne Strom', 101 => 'Andocken fehlgeschlagen', 102 => 'Station nicht gefunden'
        ];
        return isset($r[$code]) ? $r[$code] : ('Grund ' . $code);
    }

    /*
     * Verschleißteile: Ident => [Name, siid, piid Rest-%, piid Rest-Stunden (0 = keiner)]
     * Zurückgesetzt wird immer per Action siid/1.
     */
    public static function Consumables()
    {
        return [
            'MainBrush'     => ['Hauptbürste', 9, 2, 1],
            'SideBrush'     => ['Seitenbürste', 10, 2, 1],
            'Filter'        => ['Filter', 11, 1, 2],
            'Sensors'       => ['Sensoren', 16, 1, 2],
            'TankFilter'    => ['Wassertankfilter', 17, 1, 2],
            'MopPad'        => ['Mopp-Pads', 18, 1, 2],
            'SilverIon'     => ['Silberionen', 19, 2, 1],
            'Detergent'     => ['Reinigungsmittel', 20, 1, 2],
            'Squeegee'      => ['Abstreifer', 24, 1, 2],
            'DirtyChannel'  => ['Schmutzwasserkanal', 25, 2, 1],
            'Deodorizer'    => ['Geruchsneutralisierer', 29, 2, 1],
            'Wheels'        => ['Antriebsräder', 30, 2, 1],
            'ScaleInhibitor'=> ['Entkalker', 31, 2, 1],
            'FluffRoller'   => ['Aufplusterwalze', 32, 1, 2],
            'RollerFilter'  => ['Walzenmopp-Filter', 33, 2, 1],
            'OutletFilter'  => ['Wasserauslassfilter', 35, 2, 1],
            'Washboard'     => ['Waschbrett', 37, 2, 1],
            'FilterClean'   => ['Filterreinigung', 38, 2, 1]
        ];
    }
}
