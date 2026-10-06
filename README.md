# Saugroboter für IP-Symcon

[![IP-Symcon ab 8.1](https://img.shields.io/badge/IP--Symcon-ab_8.1-0b6fb3.svg)](https://www.symcon.de)
[![Optimiert für Symcon 9.0](https://img.shields.io/badge/optimiert_f%C3%BCr-Symcon_9.0-0b6fb3.svg)](https://www.symcon.de/de/service/dokumentation/installation/migrationen/v81-v90-q1-2026/)
[![Modul-Version 1.4 (Build 34)](https://img.shields.io/badge/Modul--Version-1.4_(Build_34)-informational.svg)](library.json)
[![Tests](https://github.com/cfaf2002/Saugroboter-DREAME-X60-Symcon/actions/workflows/tests.yml/badge.svg)](https://github.com/cfaf2002/Saugroboter-DREAME-X60-Symcon/actions/workflows/tests.yml)
[![PHP 8.3 und 8.5](https://img.shields.io/badge/PHP-8.3_%7C_8.5-777bb4.svg?logo=php&logoColor=white)](https://www.php.net)
[![SDK: IPSModuleStrict](https://img.shields.io/badge/SDK-IPSModuleStrict-success.svg)](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/)
[![Variablen: Darstellungen](https://img.shields.io/badge/Variablen-Darstellungen-success.svg)](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/darstellungen/)
[![Kachel-Visualisierung: HTML-SDK](https://img.shields.io/badge/Kachel--Visualisierung-HTML--SDK-orange.svg)](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/html-sdk/)
[![Farbschema: Symcon-Design, Dunkel, Hell](https://img.shields.io/badge/Farbschema-Symcon--Design_%7C_Dunkel_%7C_Hell-blueviolet.svg)](STYLEGUIDE.md)
![Sprache: Deutsch](https://img.shields.io/badge/Sprache-Deutsch-blueviolet.svg)
[![Lizenz: MIT](https://img.shields.io/badge/Lizenz-MIT-green.svg)](LICENSE)
[![Cloud: Dreame (inoffiziell)](https://img.shields.io/badge/Cloud-Dreame_(inoffiziell)-lightgrey.svg)](https://www.dreame.tech)

Inoffizielles Modul zur Einbindung von Saugrobotern **kompatibel mit Dreame**¹ in IP-Symcon – entwickelt mit dem
X60 Ultra, passend für die aktuellen Modelle der X-Serie (X40, X50, X60) und verwandte Geräte.

> **Nutzungshinweis:** Privates Projekt, nicht mit Dreame verbunden und von Dreame weder unterstützt noch geprüft.
> Das Modul nutzt die nicht dokumentierte Cloud-Schnittstelle der Dreamehome-App – mit deinem eigenen Konto, für
> dein eigenes Gerät. Dreame kann die Schnittstelle jederzeit ändern oder die Nutzung untersagen. Ob der Einsatz mit
> den Nutzungsbedingungen von Dreame vereinbar ist, liegt in deiner Verantwortung. Die Instanz startet erst, wenn
> dieser Hinweis in der Konfiguration bestätigt wurde.

Die X-Modelle bieten keinen lokalen Zugang. Das Modul spricht deshalb wie die Dreamehome-App mit der
Hersteller-Cloud – es läuft selbst aber vollständig in deiner Symcon-Instanz, ohne zusätzliche Dienste.

**Version 1.4** · IP-Symcon ab 8.1, optimiert für 9.0 · Version und Build stehen unten in der Instanzkonfiguration

---

## Inhalt

1. [Funktionen](#funktionen)
2. [Installation](#installation)
3. [Einrichtung](#einrichtung)
4. [Variablen](#variablen)
5. [Kachel und Übersicht](#kachel-und-übersicht)
6. [Automatik: reinigen, wenn niemand zu Hause ist](#automatik-reinigen-wenn-niemand-zu-hause-ist)
7. [Benachrichtigungen](#benachrichtigungen)
8. [Skriptbefehle](#skriptbefehle)
9. [Wie das Modul arbeitet](#wie-das-modul-arbeitet)
10. [Sicherheit](#sicherheit)
11. [Datenschutz](#datenschutz)
12. [Fehlersuche](#fehlersuche)
13. [Umstieg von Version 1.3](#umstieg-von-version-13)
14. [Changelog](#changelog)
15. [Lizenz, Marken und Dank](#lizenz-marken-und-dank)

---

## Funktionen

**Status**
- Zustand (≈ 70 Zustände im Klartext), Akku, Laden, Fortschritt in Prozent
- Fehler im Klartext **mit Kurzhilfe** („Hauptbürste verheddert – Hauptbürste ausbauen, Borsten und Lager reinigen.“)
- Aktueller Raum während der Reinigung, Dauer und Fläche
- Station: Frischwasser, Schmutzwasser, Staubbeutel
- Verschleißteile in Prozent – **automatisch passend zum Modell** (Hauptbürste, Seitenbürste, Filter, Sensoren,
  Mopp-Pads, Silberionen, Reinigungsmittel, Wassertankfilter, Abstreifer, Antriebsräder, Entkalker u. a.)
- „Wartung fällig“ als Sammelmeldung
- Gesamtstatistik (Stunden, Anzahl, Fläche) und Verlauf der letzten Reinigungen **mit den gereinigten Räumen**

**Steuerung**
- Alles reinigen, Pause, Fortsetzen, Stopp, Zur Station, Orten
- Station: Mopp waschen, Mopp trocknen / beenden, Staub absaugen, Hinweis quittieren
- **Etagen**: mehrere Karten, Wechsel am Gerät (während einer Fahrt nur in der Anzeige – der Wechsel würde sonst die Reinigung abbrechen)
- **Räume**: einzeln per Auswahlliste, mehrere per Schalter oder direkt per Name im Skript („Küche, Flur“)
- **Vormerken**: Räume während einer laufenden Fahrt auswählen – sie werden danach als eigener Durchgang gereinigt
- **Vorwahlen** vor dem Start: Modus, Saugkraft, Wischfeuchte, Route, Durchgänge, CleanGenius
- Zähler eines Verschleißteils zurücksetzen

**Live-Verbindung (Echtzeit wie in der App)**
- Zustand, Akku, Fortschritt, Position und Karte kommen **sofort**, sobald sich am Roboter etwas ändert –
  über dieselbe Push-Verbindung, die auch die App nutzt
- Die Karte folgt dem Roboter während der Fahrt (neu gezeichnet höchstens alle 3 s), die Kachel zeigt dann **LIVE**
- Fällt die Verbindung aus, fragt das Modul automatisch wieder regelmäßig ab

**Karte**
- Kartenbild aller Etagen mit Räumen, Roboter (mit Blickrichtung), Station und gefahrener Strecke
- **Raumnamen in der Karte**; **Raum antippen** fragt nach, wie gereinigt werden soll (Modus, Saugkraft, Wischfeuchte, Durchgänge, Route, CleanGenius) – nur für diese Fahrt
- **Karte der letzten Reinigung** mit der gefahrenen Strecke (Umschalter in der Kachel)
- Eigene Raumnamen aus der App, in der App ausgeblendete Räume werden erkannt
- Verschlüsselte Karten neuerer Firmware werden entschlüsselt

**Automationen**
- Reinigen bei Abwesenheit über eine Anwesenheitsvariable
- **Raumplan**: je Wochentag andere Räume (z. B. werktags nur die Küche, am Wochenende alles)
- Push-Benachrichtigungen über Kachel-Visualisierung oder WebFront

**Visualisierung**
- Eigene **Kachel** für die Kachel-Visualisierung mit Karte, Bedienung, Raumwahl, Vorwahlen, Verschleiß und Verlauf
- Optional eine HTML-Box für WebFront und IPSView

---

## Installation

**Über das Module Control**
1. In der Verwaltungskonsole *Kern Instanzen → Modules* öffnen.
2. Über *Hinzufügen* diese URL eintragen:
   `https://github.com/cfaf2002/Saugroboter-DREAME-X60-Symcon`

**Umzug von der alten Adresse:** Wer das Modul noch über die frühere Repository-Adresse eingebunden hat, entfernt im
Module Control zuerst den alten Eintrag und fügt dann die neue URL hinzu – nie beide gleichzeitig, weil sie dieselben
Modul-IDs tragen. Die Modul-IDs sind unverändert, vorhandene Instanzen werden dadurch wieder zugeordnet. Vorher ein
Backup anlegen schadet nicht.

**Ohne Git**
1. Das Repository als ZIP herunterladen (https://github.com/cfaf2002/Saugroboter-DREAME-X60-Symcon) und den Ordner in das `modules`-Verzeichnis von Symcon kopieren
   (z. B. `/var/lib/symcon/modules/` bzw. `C:\ProgramData\Symcon\modules\`).
2. In der Verwaltungskonsole *Kern Instanzen → Modules* das Modulverzeichnis neu laden.

Anschließend über *Instanz hinzufügen* unter Hersteller **Dreame** das Gerät **X60 Ultra** anlegen. Die Instanz funktioniert
auch mit X40/X50 und verwandten Modellen.

---

## Einrichtung

1. **Nutzungshinweis** lesen und bestätigen – vorher bleibt die Instanz gesperrt.
2. **Konto & Gerät**: E-Mail und Passwort der Dreamehome-App eintragen, Region wählen (Deutschland: *Europa*).
   Gibt es mehrere Roboter im Konto, unter *Gerät* den Namen aus der App, das Modell (z. B. `r9515`) oder die did eintragen.
3. **Instanz aktiv** anhaken und *Änderungen übernehmen*.
4. **Verbindung testen** – zeigt das Gerät, weitere Roboter im Konto und die erkannten Verschleißteile.
5. **Etagen & Räume → Karten & Räume einlesen** – liest alle gespeicherten Karten mit Räumen und zeichnet die Karte.
6. In der Raumliste Räume abwählen, die es nicht gibt (Dreame erkennt gern Räume hinter Glastüren), oder unter
   *Eigener Name* umbenennen. *Änderungen übernehmen*.
7. Optional: Automatik, Benachrichtigungen, Kartenausrichtung.

**Status auf einen Blick:** Ganz oben in der Instanz zeigt der Block *Status*, ob die Cloud erreichbar ist, ob die
Live-Verbindung steht (bzw. warum nicht), wann das Server-Zertifikat gemerkt wurde und die letzte Meldung. Er
aktualisiert sich, solange das Fenster offen ist.

**Live-Verbindung:** ist ab Werk eingeschaltet (*Konto & Gerät → Live-Verbindung*). Das Modul legt dafür beim ersten
Mal selbst einen **Client Socket** als übergeordnete Instanz an („Saugroboter Live (…)“) und stellt ihn ein – Server,
Port und TLS kommen aus der Cloud, dort ist nichts von Hand einzutragen. „Überprüfe Peer/Host“ bleiben im Socket aus – geprüft wird stattdessen das gemerkte Server-Zertifikat (siehe *Sicherheit*). Die Variable **Live-Verbindung** zeigt, ob
gerade Echtzeitdaten ankommen. Wird die Instanz gelöscht, bleibt der Client Socket stehen und kann mit gelöscht werden.

> **Tipp für neue Modelle:** *Gerät scannen (Diagnose)* listet alle Werte, die der Roboter liefert (auch im Debug-Fenster).
> Damit lässt sich prüfen, ob ein Wert fehlt oder anders heißt.

---

## Variablen

| Variable | Typ | Bedeutung |
|---|---|---|
| Zustand | Integer | Gerätezustand als Zahl, Klartext über die Darstellung – ideal für Ereignisse |
| Fehler / Fehler – was tun? | Integer / String | Fehlercode im Klartext und Kurzhilfe |
| Akku, Lädt | Integer / Boolean | Ladestand; *Lädt* nur, wenn das Modell es meldet |
| Aktueller Raum | String | Raum unter dem Roboter (während einer Fahrt) |
| Fortschritt | Integer | Prozent des Auftrags (modellabhängig) |
| Reinigungsdauer, Gereinigte Fläche | Integer | laufender bzw. letzter Auftrag |
| Frischwasser, Schmutzwasser, Staubbeutel | Integer | Zustand der Station |
| Befehl | Integer | alle Befehle als Auswahlliste |
| Etage | Integer | gewählte Karte |
| Raum reinigen | Integer | Auswahl startet den Raum sofort |
| Auswahl *Raum* | Boolean | Raumauswahl für einen gemeinsamen Durchgang |
| Vorgemerkt | String | Räume, die nach der laufenden Fahrt folgen |
| Reinigungsmodus, Saugkraft, Wischfeuchte, Route, Durchgänge, CleanGenius | Integer | Vorwahlen; „wie am Gerät“ ändert nichts |
| Reinigen bei Abwesenheit, Automatik | Boolean / String | Automatik ein/aus und was sie gerade tut |
| Wartung fällig | String | alle fälligen Punkte |
| *Verschleißteil* | Integer | Restlaufzeit in Prozent (je nach Modell) |
| Reinigungszeit/Reinigungen/Fläche gesamt | Float / Integer | Gesamtstatistik |
| Letzte Reinigung, Verlauf | String | letzte Fahrten mit Räumen und Ergebnis |
| Verbunden, Letzte Meldung, Gerät, Einstellungen am Gerät | – | Diagnose |
| Karte | Medienobjekt | Kartenbild (PNG) |

---

## Kachel und Übersicht

In der **Kachel-Visualisierung** die Instanz direkt als Kachel hinzufügen. Die Kachel zeigt:

- Zustand, Raum, Fortschritt und Akku; bei aktiver Live-Verbindung das Zeichen **LIVE**
- Störungen mit Kurzhilfe (Hinweise lassen sich quittieren)
- die Karte mit Raumnamen, Strecke, Station und dem Roboter, der in Echtzeit über die Karte gleitet.
  **Raum antippen** öffnet die Nachfrage *Wie soll gereinigt werden?* mit *Jetzt reinigen* (während einer Fahrt:
  *Nach der laufenden Fahrt*) und *Nur auswählen*. Die Einstellungen gelten nur für diese Fahrt.
- Umschalter *Live* / *Letzte* (Karte der letzten Reinigung) und Etagenwahl direkt über der Karte
- passende Knöpfe je nach Lage (in Ruhe: *Alles reinigen*; während der Fahrt: *Pause*, *Stopp*, *Station*)
- **Reinigen**: Räume antippen (oder keinen = alles) und darunter das **Programm** wählen (Untermenü mit allen Programmen;
  *Eigene Einstellungen* = deine Vorwahlen, über das Regler-Symbol anpassbar). Der große Startknopf zeigt, was passiert:
  „Küche reinigen · Schnell saugen“ bzw. „Alles reinigen · …“. Während einer Fahrt: *Danach reinigen*.
- Station mit Mopp waschen, Trocknen, Absaugen
- **Automatik** kompakt: Ein/Aus, *Bei Abwesenheit* oder *Zur Uhrzeit*, Standard-Uhrzeit, Programm und Zeitpläne
- **Verlauf** und **Verschleiß** (mit Zurücksetzen) als Zeilen in der Karte *Reinigen*, Details per Tipp
- **Rückmeldung** nach jedem Befehl: grün „… gesendet“, rot mit dem Grund, wenn etwas nicht geklappt hat

**Hintergrundbild:** Unter *Visualisierung → Hintergrundbild der Kachel* ein Foto hochladen (JPG, PNG, WebP). Das Modul
verkleinert es auf höchstens 1600 Pixel, legt es als verstecktes Medienobjekt „Kachel-Hintergrund“ ab und leert das
Upload-Feld wieder – das Bild landet also nicht in den Einstellungen oder Backups. Die Felder liegen wie Milchglas darüber: ringsum bleibt
das Bild scharf, hinter den Feldern wird es weichgezeichnet. *Hintergrund abdunkeln* (Standard 25 %) und *Hintergrund
weichzeichnen* (Standard 0 = scharf) passen das Bild an, *Deckkraft der Felder* (Standard 62 %, weniger = durchsichtiger)
und *Milchglas-Stärke* (Standard 18 px, 0 = klares Glas) die Felder darüber; *Hintergrund entfernen* nimmt es wieder heraus.

**Farbschema der Kachel** (*Visualisierung → Farbschema der Kachel*, wie in allen meinen Modulen): *Symcon-Design*
(Standard) übernimmt Schrift- und Akzentfarbe der Visualisierung, *Dunkel* und *Hell* setzen einen festen Hintergrund,
*Wie Gerät* folgt der Hell-/Dunkel-Einstellung des jeweiligen Geräts.

Für **WebFront/IPSView** gibt es unter *Visualisierung* die HTML-Box „Übersicht“ (nur Anzeige) sowie das Medienobjekt „Karte“.

---

### Design der Kachel und Symcon 9.0

- **Farbschema** (*Visualisierung → Farbschema der Kachel*): **Symcon-Design** (Standard), *Dunkel*, *Hell* oder
  *Wie Gerät*. Symcon-Design übernimmt das in der Kachel-Visualisierung gewählte Design: Symcon stellt Schrift- und
  Akzentfarbe als CSS-Variablen bereit (`--content-color`, `--accent-color`). Helle Schrift → dunkle Kachel, dunkle
  Schrift → helle Kachel; Knöpfe, Auswahl und Fortschritt nehmen die Akzentfarbe an. Farben, Schrift und Radien kommen
  aus der gemeinsamen Kachel-Grundlage (siehe [STYLEGUIDE.md](STYLEGUIDE.md)).
- **Karte:** Räume in kräftigen, weichen Farben mit leichtem Verlauf, klare dunkle Außenkontur, Möbel und Innenkanten im
  dunkleren Ton des jeweiligen Raums statt in Grau, feine helle Linien zwischen Räumen. Die gefahrene Strecke erscheint
  als zarter heller Streifen in etwa Roboterbreite mit feiner Linie darauf. Die Karte schwebt mit Schatten über einem
  dezenten Punktraster; Raumnamen tragen ein passendes Symbol (Bad, Küche, Wohnzimmer, Schlafzimmer, Kinderzimmer,
  Büro, Flur, Esszimmer, Hauswirtschaft). Gezeichnet wird höher aufgelöst, damit die Karte auch groß scharf bleibt.
- **Symcon 9.0:** Basisklasse `IPSModuleStrict` mit typisierten Befehlen, Variablen mit **Darstellungen** statt eigener
  Profile (Etagen und Räume als Aufzählung, die sich mit den Karten ändert). Das Modul läuft unter Symcon 9.0 und PHP 8.5
  ohne Veraltet-Warnungen (u. a. keine `curl_close()`/`imagedestroy()`-Aufrufe mehr). Die Kachel nutzt nur das HTML-SDK (`handleMessage`, `requestAction`) und
  funktioniert in der Web-Visualisierung wie in den neuen *Symcon Visualization*-Apps.

## Automatik: reinigen, wenn niemand zu Hause ist – oder zur festen Uhrzeit

Zwei Arten, wählbar in der Kachel oder über die Variable **Automatik startet**:

- **Bei Abwesenheit**: startet, sobald niemand zu Hause ist. Dafür braucht es eine Anwesenheitsvariable
  (Boolean, *true* = jemand zu Hause; umkehrbar). Kommt jemand heim, fährt der Roboter auf Wunsch zurück.
- **Zur Uhrzeit**: startet einmal am Tag zur eingestellten Uhrzeit (Variable **Automatik-Uhrzeit**, z. B. 10:00) an den
  gewählten Tagen – auch wenn jemand zu Hause ist, ganz ohne Anwesenheitsvariable. Ist der Roboter gerade beschäftigt,
  offline oder der Akku zu leer, wird der Start bis zu 3 Stunden nachgeholt.

Eingeschaltet wird über die Variable **Reinigen bei Abwesenheit** (in der Kachel: Schalter *Automatik*).

Gestartet wird, wenn **alle** Bedingungen erfüllt sind:

- *Bei Abwesenheit*: niemand ist seit mindestens *n* Minuten zu Hause
- die Uhrzeit liegt im Zeitfenster, der Wochentag ist freigegeben
- die letzte Automatik-Fahrt liegt mindestens *n* Stunden zurück
- der Akku hat mindestens *n* %, der Roboter ist erreichbar, frei und ohne Störung
- der Raumplan sieht heute eine Reinigung vor

Gereinigt wird alles oder die unter *Räume* eingetragenen Räume („Küche, Flur“) – mit dem gewählten **Programm**:

| Programm | Einstellungen |
|---|---|
| Eigene Einstellungen | deine Vorwahlen (in der Kachel über das Regler-Symbol anpassbar) |
| Schnell saugen | Saugen, Standard, Route *Schnell* |
| Gründlich saugen | Saugen, Turbo, 2 Durchgänge, Route *Intensiv* |
| Saugen und wischen | beides gleichzeitig, Standard, feucht |
| Erst saugen, dann wischen | nacheinander, stark, feucht |
| Nur wischen | Wischen, feucht |
| Leise (Nachtruhe) | Saugen, leise, Route *Standard* |
| CleanGenius Routine / Tiefenreinigung | Roboter entscheidet selbst (Saugen und wischen) |

Nicht genannte Werte bleiben wie am Gerät eingestellt. Die Vorwahlen selbst werden dabei nicht verändert.

Auch der **Raumplan** lässt sich in der Kachel ansehen und bearbeiten (Karte *Automatik → Zeitpläne*):
je Zeile Tag, Programm und Räume antippen, *frei* für Tage ohne Automatik, *Speichern*. Er ist derselbe wie in der Instanz.

Das **Programm der Automatik** wählst du direkt in der Kachel (Karte *Automatik → Programm*)
oder über die Variable **Automatik-Programm**. Dieselben Programme wählst du in der Kachel auch fürs manuelle Reinigen (Karte *Reinigen → Programm*).

**Zeitpläne (mehrere Automatik-Einträge):** Jeder Eintrag hat Tag, **Uhrzeit**, Programm und Räume – z. B.
*Mo–Fr 10:00 Küche · Schnell saugen* und *Sa 14:30 alles · Gründlich saugen*; auch mehrere Einträge am selben Tag.
Bei *Zur Uhrzeit* startet jeder Eintrag einmal zu seiner Uhrzeit (leer = Standard-Uhrzeit), verpasste Starts werden bis zu
3 Stunden nachgeholt; ein Eintrag *frei* sperrt den ganzen Tag. Ohne Einträge gilt täglich die Standard-Uhrzeit mit dem
Standard-Programm. Bei *Bei Abwesenheit* zählen Tag, Räume und Programm, die Uhrzeit nicht. Bearbeiten in der Instanz
(Liste *Zeitpläne*, Spalte *Uhrzeit*) oder in der Kachel (*Automatik → Zeitpläne*).

**Raumplan:** Im Raumplan hat jeder Raum eine eigene Spalte zum Anhaken (sobald die Räume eingelesen sind), dazu
je Zeile ein eigenes *Programm* (oder *Standard* = Programm der Automatik). Nichts angehakt = alles, *frei* = an diesem Tag nicht reinigen. Ein bestimmter Tag geht vor *Mo–Fr* bzw. *Sa + So*,
das vor *täglich*. Gibt der Plan für heute nichts vor, gilt *Räume*. Die Räume einer Zeile müssen auf derselben Etage liegen.

| Tag | Programm | Wohnzimmer | Küche | Flur | frei |
|---|---|---|---|---|---|
| Mo–Fr | Schnell saugen |  | ✓ |  |  |
| Samstag | Gründlich saugen |  |  |  |  |
| Sonntag | Standard |  |  |  | ✓ |

Kommt jemand heim, fährt der Roboter auf Wunsch zurück zur Station. Die Variable **Automatik** zeigt jederzeit,
worauf die Automatik gerade wartet; die Kachel zeigt zusätzlich, was heute geplant ist („Heute: Küche · Schnell saugen“).

---

## Benachrichtigungen

Unter *Benachrichtigungen* eine Kachel-Visualisierung oder ein WebFront wählen. Gemeldet werden – jeweils abschaltbar:

- Reinigung fertig (mit Räumen, Dauer, Fläche und Ergebnis)
- Störungen und Hinweise (mit Kurzhilfe, jeder Fehler nur einmal)
- Wartung fällig (nur neu hinzugekommene Punkte)
- Start durch die Automatik

Alle Meldungen stehen zusätzlich im Meldungsfenster von Symcon.

---

## Skriptbefehle

```php
SAUG_CleanAll($id);                          // alles (gewählte Etage)
SAUG_CleanRooms($id, 'Küche, Flur');         // Räume per Name …
SAUG_CleanRooms($id, '5,6');                 // … per Nummer auf der gewählten Etage
SAUG_CleanRooms($id, [105, 106]);            // … oder per Code (Etage * 100 + Nummer)
SAUG_CleanRoomsWith($id, 'Küche', '{"Mode":0,"Suction":3,"Passes":2}');  // eigene Einstellungen nur für diese Fahrt
SAUG_CleanSelection($id);                    // alle angehakten Räume
SAUG_QueueSelection($id);                    // Auswahl nach der laufenden Fahrt reinigen
SAUG_ClearSelection($id);
SAUG_SelectFloor($id, 1);                    // Etage (Karten-ID)

SAUG_Pause($id);  SAUG_Resume($id);  SAUG_Stop($id);  SAUG_Dock($id);  SAUG_Locate($id);
SAUG_WashMop($id);  SAUG_DryMop($id, true);  SAUG_EmptyDustBin($id);  SAUG_AcknowledgeWarning($id);
SAUG_ResetConsumable($id, 'Mopp-Pads');      // Name oder Kennung (MopPad)

SAUG_Poll($id);                              // Status sofort abrufen
SAUG_ReadMaps($id);                          // Karten neu einlesen
SAUG_TestConnection($id);
SAUG_ScanDevice($id);                        // Diagnose: alle Gerätewerte
SAUG_LiveRestart($id);                       // Live-Verbindung neu aufbauen
SAUG_LiveTrustCertificate($id);              // erneuertes Server-Zertifikat übernehmen
```

Alle Befehle geben `true`/`false` zurück; der Grund eines Fehlschlags steht in **Letzte Meldung**.

---

## Wie das Modul arbeitet

- **Anmeldung**: Das Passwort wird beim Speichern in den Hash umgewandelt, den die Cloud erwartet – im Klartext
  steht es danach nirgends mehr. Angemeldet wird einmal damit, danach mit dem Refresh-Token der Cloud. Lehnt die Cloud ein Token ab,
  meldet sich das Modul sofort neu an.
- **Live-Verbindung**: MQTT über TLS zum Server, an dem der Roboter hängt – angemeldet mit Konto-ID und Zugangstoken,
  abonniert wird nur das eigene Gerät. Das Protokoll (Anmelden, Abonnieren, Empfangen, Keepalive) ist im Modul selbst
  umgesetzt, es braucht keine Zusatzbibliothek und keinen eigenen MQTT-Server. Kartenbilder kommen als Voll- oder
  Differenzbild direkt in der Nachricht; Differenzbilder werden auf das letzte Vollbild gelegt. Enthalten die Live-Bilder keine Wände/Möbel/Strecke,
  liefern sie nur Position und Zeit; die Details kommen aus der Kartendatei, die während der Reinigung alle 2 Minuten
  nachgeladen wird. Die Roboterposition kommt immer aus dem jüngsten Live-Bild – eine Kartendatei mit abweichender
  Uhrzeit oder Bildnummer kann sie nicht mehr „einfrieren“. Schickt das Gerät live nur den Zustand und keine
  Kartenbilder, holt das Modul während der Reinigung die Kartendatei im kurzen Takt (*Abfrage während Reinigung*,
  spätestens alle 30 s), damit der Roboter in der Kachel trotzdem fährt. Die *Kartendiagnose* zeigt dazu
  „Letztes Live-Kartenbild“. Die Verbindung heilt sich selbst: nach 150 s ohne Daten, bei getrenntem Socket (nach 10 s, dann in
  wachsenden Abständen bis 5 min), wenn der Roboter unterwegs ist, aber 40 s lang live nichts kommt (höchstens alle 3 Minuten), und kurz vor Ablauf des Zugangstokens
  baut das Modul sie neu auf. Der Status-Block zeigt, wie oft das heute nötig war und warum; wird die Anmeldung dreimal abgelehnt, pausiert es 10 Minuten.
- **Testprotokoll** (zur Fehlersuche): In der Instanz unten „Testprotokoll starten (2 h)“ – danach werden Live-Meldungen,
  Kartenbilder, Roboterposition, Läufe von Abruf und Nacharbeit, Verbindungswechsel und das, was die Kachel tatsächlich anzeigt,
  mit Uhrzeit in eine Textdatei im Symcon-Log-Ordner geschrieben (`saugroboter_<ID>_test.log`). „Testprotokoll anzeigen“ zeigt die
  letzten 300 Zeilen. Endet nach 2 Stunden von selbst; es werden keine Zugangsdaten protokolliert.
- **Abfrage**: alle *n* Sekunden im Ruhezustand, schneller während einer Reinigung und kurz nach jedem Befehl.
  Steht die Live-Verbindung und kommen darüber Kartenbilder, reicht eine ruhige Abfrage (höchstens jede Minute) für
  Station und Verschleiß.
  Abgefragt werden nur Werte, die das Modell kennt – in Paketen, wie die Cloud sie annimmt.
- **Modellunabhängig**: Beim ersten Kontakt fragt das Modul alle bekannten Verschleiß- und Zusatzwerte ab und legt
  nur Variablen für die an, die der Roboter liefert. *Verbindung testen* ermittelt sie neu.
- **Karten**: Die Kartenliste kommt als Datei aus der Cloud. Das Modul entschlüsselt sie bei Bedarf, erkennt das
  Zellformat selbst und zeichnet die Karte mit PHP-GD. Ohne Live-Verbindung wird die laufende Karte während einer Fahrt als Datei geladen (etwa alle 30 s neu abgelegt).
- **Vorwahlen**: werden vor jedem Start ans Gerät geschickt. Ist am Gerät *CleanGenius* aktiv und werden Saugkraft,
  Feuchte oder Route vorgewählt, schaltet das Modul CleanGenius aus – sonst würde der Roboter die Vorwahl ignorieren.
  Ist in der App *Individuelle Raumeinstellungen* aktiv, gelten die Werte je Raum aus der App
  (siehe *Einstellungen am Gerät*).
- **Schonend**: mindestens 30 s Abstand im Ruhezustand und 10 s während einer Reinigung – nicht mehr Last als die App.
- **Robust**: Einzelne Aussetzer der Cloud führen nicht sofort zu „getrennt“ (erst ab dem dritten Fehlversuch in Folge).

---

## Sicherheit

- **Verschlüsselt und geprüft**: Die Cloud-Zugriffe laufen über TLS mit Zertifikatsprüfung (*TLS-Zertifikate
  prüfen*, ab Werk an). Abschalten nur als letzten Ausweg: dann könnte jemand im Netz das Zugangstoken mitlesen.
- **Live-Verbindung mit Zertifikatsbindung**: Der Live-Server von Dreame hat kein Zertifikat einer öffentlichen
  Zertifizierungsstelle (die App prüft es deshalb gar nicht). Das Modul merkt sich beim ersten Kontakt die
  Zertifikatskette des Servers und schickt die Anmeldung mit dem Token nur, wenn der Server später eine dazu
  passende, korrekt unterschriebene Kette vorzeigt. Passt sie nicht, stoppt die Live-Verbindung mit einer Meldung
  (und Push bei Störungen). Hat Dreame das Zertifikat erneuert, übernimmst du es mit *Server-Zertifikat neu übernehmen*.
- **Passwort**: gespeichert wird nur der Hash, den die Cloud zur Anmeldung erwartet. Das schützt das Klartext-Passwort
  (wichtig, falls du es auch woanders nutzt) – der Hash selbst reicht aber zur Anmeldung bei Dreame. Einstellungen und
  Backups von Symcon deshalb wie Zugangsdaten behandeln. Am sichersten: ein eigenes, nur hier genutztes Passwort.
- **Token**: liegen nur in internen Attributen der Instanz, nie in Variablen, Meldungen oder im Debug der Instanz.
  Das Debug-Fenster des *Client Sockets* zeigt allerdings die gesendeten Rohdaten – darin steht beim Verbindungsaufbau
  das Token. Dieses Debug-Fenster nicht in Foren posten.
- **Nur feste Server**: Zugangsdaten gehen nur an die bekannten Regionen der Dreame-Cloud, Dateien nur über HTTPS.
- **Robust gegen kaputte Daten**: Kartendaten haben Obergrenzen (Größe, Zellen, Strecke); Nachrichten anderer Geräte
  werden verworfen; Vorwahlen aus Kachel oder Skript werden auf gültige Werte geprüft.
- **Testprotokoll**: nur auf Knopfdruck, endet nach 2 Stunden selbst und schreibt keine Zugangsdaten oder Token –
  wohl aber Zustände, Uhrzeiten und Roboterpositionen. Rückmeldungen der Kachel werden von Steuerzeichen bereinigt
  und gekürzt, bevor sie in die Datei gehen.
- **Kachel**: alle Texte (Raumnamen, Meldungen) werden vor der Anzeige maskiert; die Kommunikation zwischen Kachel und
  Modul ist laut Symcon mit dem Passwort der Visualisierung abgesichert.

**Geschwindigkeit:** Live-Positionen setzen nur das Roboter-Symbol (ohne Neuzeichnen), die Karte wird höchstens alle
3 Sekunden neu gezeichnet und nur dann an die Kachel geschickt, wenn sich das Bild wirklich geändert hat. Die Raumlage
wird laufend im Speicher gehalten und nur bei Änderungen bzw. höchstens jede Minute in die Einstellungen geschrieben.
Antwortet der Roboter nicht direkt, fragt der Abruf 5 Minuten lang gleich den Cloud-Speicher, statt jedes Mal auf eine
Zeitüberschreitung zu warten.

## Datenschutz

- Das Modul überträgt Daten ausschließlich zwischen deiner Symcon-Installation und der Hersteller-Cloud – an keinen
  weiteren Dienst.
- Das Passwort wird nur als Hash gespeichert. Zugangstoken liegen in den internen Attributen der Instanz.
- Kartenbilder (Grundriss deiner Wohnung) werden als Medienobjekt in Symcon abgelegt. Wer Zugriff auf deine
  Visualisierung hat, sieht sie – bei öffentlich erreichbaren Visualisierungen ggf. *Kartenbild erzeugen* abschalten.

## Fehlersuche

| Problem | Lösung |
|---|---|
| „Anmeldung abgelehnt“ | E-Mail/Passwort der Dreamehome-App und Region prüfen. Bei Anmeldung per Google/Apple in der App ein Passwort vergeben. |
| „Zertifikatsprüfung fehlgeschlagen“ | Systemzeit und CA-Zertifikate des Symcon-Systems prüfen (Update). *TLS-Zertifikate prüfen* nur als letzten Ausweg abschalten. |
| „Roboter antwortet nicht“ | Roboter im WLAN? In der App erreichbar? Die Cloud leitet Befehle nur an verbundene Geräte weiter. |
| Keine Karte | Karte in der App gespeichert? *Karten & Räume einlesen* erneut ausführen. |
| Karte gedreht/gespiegelt | Unter *Karte → Ausrichtung* anpassen. |
| Vorwahlen wirken nicht | *Einstellungen am Gerät* prüfen: individuelle Raumeinstellungen überstimmen die Vorwahlen. |
| Karte/Zustand hinken hinterher | Ist *Live-Verbindung* an und die gleichnamige Variable auf *An*? Sonst *Live-Verbindung neu aufbauen*; Details im Debug-Fenster (Einträge „Live“). Der Client Socket muss ins Internet dürfen (ausgehend, Port laut Cloud, z. B. 19973). |
| Wert fehlt | *Gerät scannen (Diagnose)* und Debug-Fenster der Instanz prüfen. |

---

## Umstieg von Version 1.3

- Das Modul braucht jetzt **IP-Symcon 8.1** oder neuer.
- Die bisherigen Profile `SAUG.*` (auch die je Instanz für Etagen und Räume) werden beim Übernehmen der Instanz
  entfernt, sobald keine Variable sie mehr nutzt. Eigene Skripte, die diese Profile auslesen, auf die Variablen umstellen.
- Die bisherige Einstellung *Design* wird einmalig in das neue *Farbschema der Kachel* übernommen
  (Dunkel bleibt Dunkel, Hell bleibt Hell, *Wie Symcon-Visualisierung* wird *Symcon-Design*).

## Changelog

| Version | Build | Datum | Beschreibung |
|---|---|---|---|
| 1.4 | 34 | 06.10.2026 | **Live-Verbindung repariert:** Seit dem Umstieg auf `IPSModuleStrict` (Build 28) erwartet Symcon die Daten zum Client Socket HEX-kodiert; das Modul schickte sie noch UTF-8-kodiert, der Server bekam Datenmüll und trennte sofort („End of file“). Senden und Empfangen jetzt HEX-kodiert |
| 1.4 | 33 | 06.10.2026 | Neuer Knopf „Live-Anmeldung prüfen“: baut selbst eine Verbindung zum Live-Server auf (mit/ohne Servernamen, mit/ohne Anmelde-Kennzeichen) und zeigt, ob der Server die Anmeldung annimmt; dazu PHP-, OpenSSL- und Krypto-Ausstattung des Systems |
| 1.4 | 32 | 06.10.2026 | Cloud-Zugriff an die App-Umstellung von Dreame (Ende September 2026) angepasst: Anmeldung als aktuelle App (Dart-Client, Plattform Android, Land/Sprache), Kopfzeilen „dreame-meta“ und „dreame-rlc“, alle Anfragen mit Signatur und Zeitstempel, TLS-Verschlüsselung in der Reihenfolge der App. Behebt „Roboter antwortet nicht direkt“ (80001), wenn die Cloud unsignierte Befehle nicht mehr weiterleitet |
| 1.4 | 31 | 06.10.2026 | Live-Verbindung an die Umstellung des Dreame-Servers (Ende September 2026) angepasst: neue Client-Kennung (`p_` + md5 aus Gerät, „mqtt“ und einer festen Zufallskennung der Installation) und das zusätzliche Anmelde-Kennzeichen, das der Server jetzt erwartet – ohne beides trennte er direkt nach der Anmeldung („End of file“) |
| 1.4 | 30 | 06.10.2026 | Live-Verbindung: trennt der Server direkt nach der Anmeldung, holt das Modul höchstens alle 30 Minuten ein frisches Zugangstoken und die aktuelle Serveradresse; hilft das nicht, pausiert die Live-Verbindung 15 Minuten statt im Sekundentakt neu zu verbinden (Zustand kommt solange über die normale Abfrage, „Live neu verbinden“ versucht es sofort) |
| 1.4 | 29 | 06.10.2026 | Karte in der breiten Kachel deutlich größer, neuer Knopf „Karte groß“ (füllt die ganze Kachel); neuer Kartenstil wird nach dem Update sicher gezeichnet; Live-Socket nach einem Fehler schneller neu öffnen (10 s, 30 s, 1, 2, dann alle 5 min); trennt der Server direkt nach der Anmeldung („End of file“), holt das Modul ein frisches Zugangstoken und verbindet sofort neu |
| 1.4 | 28 | 06.10.2026 | Einheitliches Design nach `STYLEGUIDE.md`: Kachel-Grundlage (Farben, Schrift, Radien, Zustandsfarben) und Einstellung „Farbschema der Kachel“ (Symcon-Design, Dunkel, Hell, wie Gerät; die alte Einstellung „Design“ wird übernommen); Kachel-Datei heißt `tile.html`; Basisklasse `IPSModuleStrict` (ab Symcon 8.1) mit typisierten Befehlen; Darstellungen statt Profile, alte `SAUG.*`-Profile werden aufgeräumt; einheitliche Badges; gemeinsamer Test-Workflow mit Struktur- und Ladetest |

## Lizenz, Marken und Dank

MIT-Lizenz, siehe [LICENSE](LICENSE). Nutzung auf eigene Gefahr, ohne Gewährleistung.

Die Kenntnis des Cloud-Protokolls (Endpunkte, MiOT-Kennungen, Kartenformat) stammt aus dem
Open-Source-Projekt [Tasshack/dreame-vacuum](https://github.com/Tasshack/dreame-vacuum) (MIT-Lizenz) –
herzlichen Dank dafür.

¹ „Dreame“ und „Dreamehome“ sind Marken ihrer jeweiligen Inhaber. Sie werden hier ausschließlich verwendet, um
anzugeben, mit welchen Geräten das Modul zusammenarbeitet. Es besteht keine Verbindung zu Dreame; das Modul
enthält keine Logos, Bilder oder Software des Herstellers.

© 2026 Armin Frohwerk
