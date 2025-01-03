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
Dieses Modul ist optimal für Reolink Kameras ausgelegt, welche Webhook unterstützen. Daher ist immer die aktuellste Firmware aufzuspielen. 
Beherrscht die Kamera kein Webhook, kann sie aktiv gepollt werden. Dies bringt aber je nach Polling-Intervall eine kleine Verzögerung mit sich.

Das Modul kann folgendes:

- Schnappschüsse bei Bewegungen aufnehmen (Allgemeine Bewegungen, Personen, Tiere und Fahrzeuge).
- Ein Schnappschuss-Archiv zu den jeweiligen Bewegungen erstellen und die Anzahl der darin gespeicherten Bilder definieren.
- Die intelligente Bewegungserkennung als Variable darstellen.
- Den Pfad zum RTSP-Stream erstellen, um das Live-Bild darzustellen.
- Auswählen, ob Main- oder Substream angezeigt werden soll.

Aktuell getestete Reolink-Kameras welche mit Webhook funktionieren:
- Reolink Duo 2
- Reolink RLC-810A
- Reolink Doorbell (nur D340P)

Aktuell getestete Reolink-Kameras welche kein Webhook unterstützen und allenfalls über die Polling-Option abgefragt werden können:
- Reolink E1 Outdoor (inkl. Pro)
- Reolink Trackmix
- Argus 3 Pro (und wahrscheinlich sämtliche akkubetriebenen Kameras)


Wenn eine Kamera mit dem Modul funktioniert, würde ich mich um Angabe des Kameramodells freuen.
Wenn nicht, benötige ich eine Info mit Angabe des Kameramodells. Ebenfalls natürlich eine Sequenz Debug. Eventuell kann ich die Kamera dann ins Modul integrieren.

### 2. Voraussetzungen

- IP-Symcon ab Version 7.0
- Im Webinterface der Kamerakonfiguration, unter Push Notifications muss der Menupunkt 'Webhook' vorhanden sein. Wenn dieser fehlt ist zu prüfen, ob eine neue Firmware zur Verfügung steht unter https://reolink.com/de/download-center. Falls die Kamera keinen Webhook unterstützt kann im Konfigurationsformuler die Pollingfunktion aktiviert werden.

### 3. Software-Installation

* Über den Module Store kann das Modul, weil aktuell beta, nur unter dem genauen Namen 'Reolink' gefunden und installiert werden.

### 4. Einrichten der Instanzen in IP-Symcon

- Unter 'Instanz hinzufügen' kann das 'Reolink'-Modul mithilfe des Schnellfilters gefunden werden.  
- Weitere Informationen zum Hinzufügen von Instanzen in der [Dokumentation der Instanzen](https://www.symcon.de/service/dokumentation/konzepte/instanzen/#Instanz_hinzufügen)

__Konfigurationsseite__:

Name     | Beschreibung
-------- | ------------------
Webhook                             |	Hier wird der verwendete Webhook angezeigt. Diesen in der Kamerakonfiguration eintragen
IP-Adresse                          |	IP-Adresse der Kamera
Benutzername                        |   Benutzername zur Anmeldung im Interface der Kamera
Passwort                            |   Passwort zur Anmeldung im Interface der Kamera
Stream-Typ                          |   Standard ist Substream. Hier kann zwischen Main- und Substream gewählt werden. Achtung: Der Mainstream ist häufig H265 codiert, dies kann von IP-Symcon nicht abgespielt werden.
Polling aktivieren                  |   Den Schalter nur aktivieren, wenn die Kamera keinen Webhook unterstützt. Webhook ist immer zu bevorzugen. Wenn der Schalter aktiviert ist wird die Kamera aktiv im eingegebenen Intervall abgefragt, was eine entsprechende Verzögerung mit sich bringt. Es wird aktuell nur die intelligente Erkennung abgefragt (Personen, Tiere, Fahrzeuge).
Webhook-Daten                       |	Aktiviert die Anzeige der Variablen aus dem JSON des Webhooks. Dies ist nur für allfällige Tests und Diagnose nötig
Test-Elemente anzeigen              |   Aktiviert die Anzeige der Elemente wie Bildarchiv, Schnappschuss und Variable, um mit der Testfunktion des Webhook aus dem Kamerainterface zu arbeiten. Dies ist nur für allfällige Tests und Diagnose erforderlich.
Besucher-Erkennung                  |   Aktiviert die Anzeige der Elemente wie Bildarchiv, Schnappschuss und Variable für die Besucher-Erkennung (Nur Doorbell)
API-Funktionen                      |   Aktiviert die API-Funktionen. Diese Funktion ist im Aufbau, vorerst nur die Kamera-LED
Intelligente Bewegungserkennung     |   Aktiviert die intelligente Bewegungserkennung
Schnappschüsse anzeigen             |   Aktiviert den letzen Schnappschuss der intelligenten Bewegungserkennung zur allfälligen Weiterverabeitung. Solange noch kein Schnappschuss erstellt ist wird nichts angezeigt.
Bildarchive anzeigen                |   Aktiviert die Bildarchive. Beachte, dass die Bildarchive nur in der Visu nicht angezeigt werden, wenn diese separat verlinkt werden.
Anzahl Archivbilder                 |   Standard ist 20. Bestimmt die maximale Anzahl der Archivbilder. Nicht zuviele Bilder einstellen, da diese alle in IP-Symcon gespeichert werden.

### 5. Statusvariablen und Profile

Die Statusvariablen/Kategorien werden automatisch angelegt. Das Löschen einzelner kann zu Fehlfunktionen führen.

#### Statusvariablen

Es werden Variablen/Typen je nach Wahl im Konfigurationsformular erstellt.

#### Profile

Name     | Typ
-------- | ------------------
REOCAM.WLED   |	Integer

### 6. WebFront

Integration von Kamerastream, Schnappschüssen und Variablen zur intelligenten Bewegungserkennung und Bildarchiv.

### 7. Webhook

Es wird automatisch ein Webhook erstellt. Der Name des Webhook wird oben im Konfigurationsformular angezeigt. Dieser Pfad muss im Webinterface der Kamera in den Einstellungen unter Push eingetragen werden. Dort den Webhook-Pfad mit dem Default-Content hinzufügen.
Es ist nur noch die http://<ip-von-symcon>:3777 davor aufzuführen.
Beispiel: http://192.168.178.48:3777/hook/reolink_28009

### 8. Versionen

Version 2.0 (7.12.2024)
- Es geht Richtung Store-Kompatibilität, diverse interne Anpassungen.

Version 1.2 (19.11.2024)
- Unterstützung für Kameras ohne Webhook (pollen)

Version 1.1 (17.11.2024)
- Unterstützung der Doorbell
- API-Funktion (Steuerung des LED-Licht)

Version 1.0 (16.11.2024)
- Initiale Beta-Version
