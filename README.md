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

**Version 1.0** · IP-Symcon ab 7.0 (Kachel ab 7.1)

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
10. [Datenschutz](#datenschutz)
11. [Fehlersuche](#fehlersuche)
12. [Lizenz, Marken und Dank](#lizenz-marken-und-dank)

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

**Karte**
- Kartenbild aller Etagen mit Räumen, Roboter (mit Blickrichtung), Station und gefahrener Strecke
- Eigene Raumnamen aus der App, in der App ausgeblendete Räume werden erkannt
- Verschlüsselte Karten neuerer Firmware werden entschlüsselt

**Automationen**
- Reinigen bei Abwesenheit über eine Anwesenheitsvariable
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

Anschließend über *Instanz hinzufügen* die Instanz **Saugroboter** anlegen.

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

- Zustand, Raum, Fortschritt und Akku
- Störungen mit Kurzhilfe (Hinweise lassen sich quittieren)
- die Karte mit Roboter, Station und Strecke
- passende Knöpfe je nach Lage (in Ruhe: *Alles reinigen*; während der Fahrt: *Pause*, *Stopp*, *Zur Station*)
- Etagen- und Raumwahl – während einer Fahrt wird aus *Auswahl reinigen* automatisch *Danach reinigen*
- Station mit Mopp waschen, Trocknen, Absaugen
- Vorwahlen, Verschleiß (mit Zurücksetzen), Verlauf und den Schalter für die Automatik

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

Gereinigt wird alles oder die unter *Räume* eingetragenen Räume („Küche, Flur“) – mit den aktuellen Vorwahlen.
Kommt jemand heim, fährt der Roboter auf Wunsch zurück zur Station. Die Variable **Automatik** zeigt jederzeit,
worauf die Automatik gerade wartet.

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
```

Alle Befehle geben `true`/`false` zurück; der Grund eines Fehlschlags steht in **Letzte Meldung**.

---

## Wie das Modul arbeitet

- **Anmeldung**: Das Passwort wird beim Speichern in den Hash umgewandelt, den die Cloud erwartet – im Klartext
  steht es danach nirgends mehr. Angemeldet wird einmal damit, danach mit dem Refresh-Token der Cloud. Lehnt die Cloud ein Token ab,
  meldet sich das Modul sofort neu an.
- **Abfrage**: alle *n* Sekunden im Ruhezustand, schneller während einer Reinigung und kurz nach jedem Befehl.
  Abgefragt werden nur Werte, die das Modell kennt – in Paketen, wie die Cloud sie annimmt.
- **Modellunabhängig**: Beim ersten Kontakt fragt das Modul alle bekannten Verschleiß- und Zusatzwerte ab und legt
  nur Variablen für die an, die der Roboter liefert. *Verbindung testen* ermittelt sie neu.
- **Karten**: Die Kartenliste kommt als Datei aus der Cloud. Das Modul entschlüsselt sie bei Bedarf, erkennt das
  Zellformat selbst und zeichnet die Karte mit PHP-GD. Während einer Fahrt wird die Live-Karte höchstens alle 30 s geladen.
- **Vorwahlen**: werden vor jedem Start ans Gerät geschickt. Ist am Gerät *CleanGenius* aktiv und werden Saugkraft,
  Feuchte oder Route vorgewählt, schaltet das Modul CleanGenius aus – sonst würde der Roboter die Vorwahl ignorieren.
  Ist in der App *Individuelle Raumeinstellungen* aktiv, gelten die Werte je Raum aus der App
  (siehe *Einstellungen am Gerät*).
- **Schonend**: mindestens 30 s Abstand im Ruhezustand und 10 s während einer Reinigung – nicht mehr Last als die App.
- **Robust**: Einzelne Aussetzer der Cloud führen nicht sofort zu „getrennt“ (erst ab dem dritten Fehlversuch in Folge).

---

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
| „Zertifikatsprüfung fehlgeschlagen“ | Dem System fehlen CA-Zertifikate – *TLS-Zertifikate prüfen* abschalten. |
| „Roboter antwortet nicht“ | Roboter im WLAN? In der App erreichbar? Die Cloud leitet Befehle nur an verbundene Geräte weiter. |
| Keine Karte | Karte in der App gespeichert? *Karten & Räume einlesen* erneut ausführen. |
| Karte gedreht/gespiegelt | Unter *Karte → Ausrichtung* anpassen. |
| Vorwahlen wirken nicht | *Einstellungen am Gerät* prüfen: individuelle Raumeinstellungen überstimmen die Vorwahlen. |
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
