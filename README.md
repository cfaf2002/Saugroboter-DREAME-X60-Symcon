# Saugroboter für IP-Symcon

Inoffizielles Modul zur Einbindung von Saugrobotern **kompatibel mit Dreame**¹ in IP-Symcon – entwickelt mit dem
X60 Ultra, passend für die aktuellen Modelle der X-Serie (X40, X50, X60) und verwandte Geräte.

> **Nutzungshinweis:** Privates Projekt, nicht mit Dreame verbunden und von Dreame weder unterstützt noch geprüft.
> Das Modul nutzt die nicht dokumentierte Cloud-Schnittstelle der Dreamehome-App – mit deinem eigenen Konto, für
> dein eigenes Gerät. Dreame kann die Schnittstelle jederzeit ändern oder die Nutzung untersagen. Ob der Einsatz mit
> den Nutzungsbedingungen von Dreame vereinbar ist, liegt in deiner Verantwortung. Die Instanz startet erst, wenn
> dieser Hinweis in der Konfiguration bestätigt wurde.

Die X-Modelle bieten keinen lokalen Zugang. Das Modul spricht deshalb wie die Dreamehome-App mit der
Hersteller-Cloud – es läuft selbst aber vollständig in deiner Symcon-Instanz, ohne zusätzliche Dienste.

**Version 1.2** · IP-Symcon ab 7.0 (Kachel ab 7.1) · Version und Build stehen unten in der Instanzkonfiguration

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
13. [Lizenz, Marken und Dank](#lizenz-marken-und-dank)

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
2. Die Git-URL dieses Repositorys hinzufügen.

**Ohne Git**
1. Den Ordner `SymconSaugroboter` in das `modules`-Verzeichnis von Symcon kopieren
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
| Zustand | Integer | Gerätezustand, Text über das Profil `SAUG.State` – ideal für Ereignisse |
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
- **Räume** zum Anhaken, darunter die **Einstellungen** (Vorwahlen) als eine Zeile – antippen zum Ändern.
  *Auswahl reinigen* fragt ebenfalls nach, wie gereinigt werden soll (während einer Fahrt: *Danach reinigen*).
- Station mit Mopp waschen, Trocknen, Absaugen
- Verschleiß (mit Zurücksetzen), Verlauf und den Schalter für die Automatik
- **Rückmeldung** nach jedem Befehl: grün „… gesendet“, rot mit dem Grund, wenn etwas nicht geklappt hat

**Hintergrundbild:** Unter *Visualisierung → Hintergrundbild der Kachel* ein Foto hochladen (JPG, PNG, WebP). Das Modul
verkleinert es auf höchstens 1600 Pixel, legt es als verstecktes Medienobjekt „Kachel-Hintergrund“ ab und leert das
Upload-Feld wieder – das Bild landet also nicht in den Einstellungen oder Backups. Die Felder liegen wie Milchglas darüber: ringsum bleibt
das Bild scharf, hinter den Feldern wird es weichgezeichnet. *Hintergrund abdunkeln* (Standard 25 %) und *Hintergrund
weichzeichnen* (Standard 0 = scharf) passen das Bild an, *Deckkraft der Felder* (Standard 62 %, weniger = durchsichtiger)
und *Milchglas-Stärke* (Standard 18 px, 0 = klares Glas) die Felder darüber; *Hintergrund entfernen* nimmt es wieder heraus.

Für **WebFront/IPSView** gibt es unter *Visualisierung* die HTML-Box „Übersicht“ (nur Anzeige) sowie das Medienobjekt „Karte“.

---

## Automatik: reinigen, wenn niemand zu Hause ist

Voraussetzung ist eine Anwesenheitsvariable (Boolean, *true* = jemand zu Hause; umkehrbar).
Eingeschaltet wird über die Variable **Reinigen bei Abwesenheit** – auch direkt in der Kachel.

Gestartet wird, wenn **alle** Bedingungen erfüllt sind:

- niemand ist seit mindestens *n* Minuten zu Hause
- die Uhrzeit liegt im Zeitfenster, der Wochentag ist freigegeben
- die letzte Automatik-Fahrt liegt mindestens *n* Stunden zurück
- der Akku hat mindestens *n* %, der Roboter ist erreichbar, frei und ohne Störung
- der Raumplan sieht heute eine Reinigung vor

Gereinigt wird alles oder die unter *Räume* eingetragenen Räume („Küche, Flur“) – mit dem gewählten **Programm**:

| Programm | Einstellungen |
|---|---|
| wie Vorwahlen | die Vorwahlen aus Kachel bzw. Variablen |
| Schnell saugen | Saugen, Standard, Route *Schnell* |
| Gründlich saugen | Saugen, Turbo, 2 Durchgänge, Route *Intensiv* |
| Saugen und wischen | beides gleichzeitig, Standard, feucht |
| Erst saugen, dann wischen | nacheinander, stark, feucht |
| Nur wischen | Wischen, feucht |
| Leise (Nachtruhe) | Saugen, leise, Route *Standard* |
| CleanGenius Routine / Tiefenreinigung | Roboter entscheidet selbst (Saugen und wischen) |

Nicht genannte Werte bleiben wie am Gerät eingestellt. Die Vorwahlen selbst werden dabei nicht verändert.

Das **Programm der Automatik** wählst du direkt in der Kachel (Karte *Reinigen bei Abwesenheit → Programm der Automatik*)
oder über die Variable **Automatik-Programm**. In der Kachel stehen die Programme außerdem als **Schnellstart** bereit:
antippen, Einstellungen bei Bedarf anpassen, *Jetzt starten* – für die ausgewählten Räume oder, ohne Auswahl, für alles.

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
  Differenzbild direkt in der Nachricht; Differenzbilder werden auf das letzte Vollbild gelegt. Nach 150 s ohne Daten
  baut das Modul die Verbindung neu auf; wird die Anmeldung dreimal abgelehnt, pausiert es 10 Minuten.
- **Abfrage**: alle *n* Sekunden im Ruhezustand, schneller während einer Reinigung und kurz nach jedem Befehl.
  Steht die Live-Verbindung, reicht eine ruhige Abfrage (höchstens jede Minute) für Station und Verschleiß, und
  Kartendateien werden gar nicht mehr geladen.
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

## Lizenz, Marken und Dank

MIT-Lizenz, siehe [LICENSE](LICENSE). Nutzung auf eigene Gefahr, ohne Gewährleistung.

Die Kenntnis des Cloud-Protokolls (Endpunkte, MiOT-Kennungen, Kartenformat) stammt aus dem
Open-Source-Projekt [Tasshack/dreame-vacuum](https://github.com/Tasshack/dreame-vacuum) (MIT-Lizenz) –
herzlichen Dank dafür.

¹ „Dreame“ und „Dreamehome“ sind Marken ihrer jeweiligen Inhaber. Sie werden hier ausschließlich verwendet, um
anzugeben, mit welchen Geräten das Modul zusammenarbeitet. Es besteht keine Verbindung zu Dreame; das Modul
enthält keine Logos, Bilder oder Software des Herstellers.

© 2026 Armin Frohwerk
