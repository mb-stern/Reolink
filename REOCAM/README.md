# Reolink für IP-Symcon

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Software-Installation](#3-software-installation)
4. [Einrichten der Instanzen in IP-Symcon](#4-einrichten-der-instanzen-in-ip-symcon)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [WebFront](#6-webfront)
7. [Webhook](#7-webhook)
8. [Versionen](#8-versionen)

### 1. Funktionsumfang

Integration von Reolink-Kameras in IP Symcon. Bei Verwendung mehrerer Reolink-Kameras kann das Modul mehrmals installiert werden. Dies ist kein ONVIF-Fähiges Modul. Der Hauptnutzen dieses Moduls ist es, die intelligente Bewegungserkennung für Personen, Tiere, Besucher und Fahrzeuge zu nutzen, was über ONVIF aktuell nicht funktioniert. 
Dieses Modul ist optimal für Reolink Kameras ausgelegt, welche Webhook unterstützen, funktioniert aber auch mit anderen Reolink-Kameras.
Daher ist immer die aktuellste Firmware aufzuspielen. Die neuste Firmware muss im Reolink Download-Center gesucht werden, da die App meist keine Neue anzeigt.
Der Webhook ist nur über das Webinterface der Kamera sichtbar, in der App für Windows ist diese Funktion ausgeblendet.
Beherrscht die Kamera kein Webhook, kann sie aktiv gepollt werden. Dies bringt aber je nach Polling-Intervall eine kleine Verzögerung mit sich.

Das Modul kann folgendes:

- Schnappschüsse bei Bewegungen aufnehmen (Allgemeine Bewegungen, Personen, Tiere, Fahrzeuge und Besucher (Doorbell)).
- Ein Schnappschuss-Archiv zu den jeweiligen Bewegungen erstellen und die Anzahl der darin gespeicherten Bilder definieren.
- Die intelligente Bewegungserkennung als Variable darstellen.
- Den Pfad zum RTSP-Stream erstellen, um das Live-Bild darzustellen.
- Main- oder Substream angezeigt.
- API-Funktionen, aktuell Ansteuerung des LED-Scheinwerfers und steuern von Mailfunktionen.

Aktuell getestete Reolink-Kameras welche mit Webhook funktionieren (immer mit der neusten Firmware):
- Reolink Duo 2
- Reolink RLC-810A
- Reolink Doorbell
- Reolink E1 Outdoor (nicht alle Hardware-Versionen)
- Reolink RLC-520A
- Reolink E1 ZOOM
- Reolink E540

Akkubetriebenen Reolink-Kameras (insbsondere Argus Modelle) unterstützen nach meinem Wissensstand kein Webhook und könnten bestenfalls über die Pollingfunktion eingebunden werden. Ich habe aber dazu noch keine oder zu wenig Feedback erhalten.

Wenn eine Kamera mit dem Modul funktioniert, würde ich mich um Angabe des Kameramodells freuen.
Wenn nicht, benötige ich eine Info mit Angabe des Kameramodells. Ebenfalls natürlich eine Sequenz Debug. Eventuell kann ich die Kamera dann ins Modul integrieren.

### 2. Voraussetzungen

- IP-Symcon ab Version 7.0
- Im Webinterface der Kamerakonfiguration, unter Push Notifications muss der Menupunkt 'Webhook' vorhanden sein. Wenn dieser fehlt ist zu prüfen, ob eine neue Firmware zur Verfügung steht unter https://reolink.com/de/download-center. Falls die Kamera keinen Webhook unterstützt kann im Konfigurationsformular die Pollingfunktion aktiviert werden.

### 3. Software-Installation

* Über den Module Store kann das Modul installiert werden.

### 4. Einrichten der Instanzen in IP-Symcon

- Unter 'Instanz hinzufügen' kann das 'Reolink'-Modul mithilfe des Schnellfilters gefunden werden.  
- Weitere Informationen zum Hinzufügen von Instanzen in der [Dokumentation der Instanzen](https://www.symcon.de/service/dokumentation/konzepte/instanzen/#Instanz_hinzufügen)

__Konfigurationsseite__:

Name     | Beschreibung
-------- | ------------------
Webhook                             |	Hier wird der verwendete Webhook angezeigt. Diesen in der Kamerakonfiguration eintragen
IP-Adresse                          |	IP-Adresse der Kamera
Benutzername                        |   Benutzername zur Anmeldung im Interface der Kamera
Passwort                            |   Passwort zur Anmeldung im Interface der Kamera. Es dürfen keine Sonderzeichen wie +, @, :, /, ?, #, [, ] verwendet werden.
Stream-Typ                          |   Standard ist Substream. Hier kann zwischen Main- und Substream gewählt werden. Achtung: Der Mainstream ist häufig H265 codiert, dies kann von IP-Symcon nicht abgespielt werden.
Polling aktivieren                  |   Den Schalter nur aktivieren, wenn die Kamera keinen Webhook unterstützt. Webhook ist immer zu bevorzugen. Wenn der Schalter aktiviert ist wird die Kamera aktiv im eingegebenen Intervall abgefragt, was eine entsprechende Verzögerung mit sich bringt. Es wird aktuell nur die intelligente Erkennung abgefragt (Personen, Tiere, Fahrzeuge).
Test-Elemente anzeigen              |   Aktiviert die Anzeige der Elemente wie Bildarchiv, Schnappschuss und Variable, um mit der Testfunktion des Webhook aus dem Kamerainterface zu arbeiten. Dies ist nur für allfällige Tests und Diagnose erforderlich.
Besucher-Erkennung                  |   Aktiviert die Anzeige der Elemente wie Bildarchiv, Schnappschuss und Variable für die Besucher-Erkennung (Nur Doorbell)
Intelligente Bewegungserkennung     |   Aktiviert die intelligente Bewegungserkennung
Schnappschüsse anzeigen             |   Aktiviert den letzen Schnappschuss der intelligenten Bewegungserkennung zur allfälligen Weiterverarbeitung. Solange noch kein Schnappschuss erstellt ist wird nichts angezeigt.
Bildarchive anzeigen                |   Aktiviert die Bildarchive. Beachte, dass die Bildarchive nur in der Visu angezeigt werden, wenn diese separat verlinkt werden.
Anzahl Archivbilder                 |   Standard ist 20. Bestimmt die maximale Anzahl der Archivbilder. Nicht zu viele Bilder einstellen, da diese alle in IP-Symcon gespeichert werden.
API-Funktionen                      |   Unterhalb diesem Menu befinden sich die API-Funktionen. Die Funktionen werden laufend erweitert.

### 5. Statusvariablen und Profile

Die Statusvariablen/Kategorien werden automatisch angelegt. Das Löschen einzelner kann zu Fehlfunktionen führen.

#### Statusvariablen

Es werden Variablen/Typen je nach Wahl im Konfigurationsformular erstellt.

#### Profile

Name     | Typ
-------- | ------------------
REOCAM.WLED              |	Integer
REOCAM.EmailInterval     |	Integer
REOCAM.EmailContent      |	Integer

### 6. WebFront

Integration von Kamerastream, Schnappschüssen und Variablen zur intelligenten Bewegungserkennung und Bildarchiv.

### 7. Webhook

Es wird automatisch ein Webhook erstellt. Der Name des Webhook wird oben im Konfigurationsformular angezeigt. Dieser Pfad muss im Webinterface der Kamera in den Einstellungen unter Push eingetragen werden. Dort den Webhook-Pfad mit dem Default-Content hinzufügen.
Es ist nur noch die http://<ip-von-symcon>:3777 davor aufzuführen.
Beispiel: http://192.168.178.48:3777/hook/reolink_28009

### 8. Versionen

Version 2.7 (31.08.2025)
- Neue API-Funktion 'PTZ-Steuerung'. Dies enthält ein html-Element mit dem Steuerkreuz und der Möglichkeit, Presets abzurufen oder zu speichern.
- Konfigurationsformular angepasst, die API-Funktionen haben eine eigene Rubrik und können nun einzeln ausgewählt werden.

Version 2.6 (25.08.2025)
- Neue API-Funktion 'Mailversand'. Die SMTP-Konfiguration ist im Kameraintrface vorzunehmen. Im Modul kann der Mailversand de/aktiviert (zb bei Abwesenheit), das Versand-Intervall eingestellt und der Mailinhalt bestimmt werden.
- Einige Code Modifikationen

Version 2.5 (15.06.2025)
- Codeoptimierung im Bereich der LED-Parameter.
- Rechtschreibung korrigiert.

Version 2.4 (14.02.2025)
- urlencode hinzugefügt, um auch Benutzernamen und Passwörter mit Sonderzeichen zu erlauben.

Version 2.3 (02.01.2025)
- Fehlermeldung beim Erstellen des Moduls behoben.
- Erstellung der Webhook-Variablen entfernt (war nur zu Testzwecken). Im Fehlerfall ist das JSON aus dem Debug zu bewerten.
- Code überarbeitet, einzelne Funktionen zusammengefasst und unnötige public Funktionen auf private gesetzt.
- API-Variablen werden nur noch aktualisiert wenn sich deren Zustand geändert hat.

Version 2.2 (28.12.2024)
- Verbesserte Fehlerbehandlung und Debugausgabe der 'SendApiRequest' Funktion
- Die API-Istwerte werden nun alle 60 Sekunden abgefragt, um diese im Falle einer externen Änderung zu aktualisieren.

Version 2.1 (22.12.2024)
- Anpassung Modulname
- Anpassung Readme mit geänderter URL

Version 2.0 (7.12.2024)
- Es geht Richtung Store-Kompatibilität, diverse interne Anpassungen.

Version 1.2 (19.11.2024)
- Unterstützung für Kameras ohne Webhook (pollen)

Version 1.1 (17.11.2024)
- Unterstützung der Doorbell
- API-Funktion (Steuerung des LED-Licht)

Version 1.0 (16.11.2024)
- Initiale Beta-Version
