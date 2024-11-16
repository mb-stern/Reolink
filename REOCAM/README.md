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

Integration von Reolink-Kameras in IP Symcon.
Dies ist kein ONVIF-Fähiges Modul.
Der Hauptnutzen dieses Moduls ist es, die intelligente Bewegungserennung für Personen, Tiere und Fahrzeuge zu nutzen, was über ONVIF aktuell nicht funktioniert.
Dieses Modul ist nur für Reolink Kameras ausgelegt, welche Webhhook unterstützen. 
Daher ist immer die aktuellste Firmware aufzuspielen.

Das Modul kann folgendes:

- Schnappschüsse bei Bewegungen aufnehmen (Allgemeine Bewegungen, Personen, Tiere und Fahrezeuge).
- Ein Schnappschuss-Archiv zu den jeweiligen Bewegungen erstellen und die Anzahl der darin gespeicherten Bilder definieren.
- Die intelligente Bewegungserkennung als Variable darstellen.
- Den Pfad zum RTSP-Stream erstellen, um das Live-Bild darzustellen.
- Auswählen, ob Main- oder Substream angezigt werden soll.

Das Modul kann nicht:
- Alle Reolink-Kameras abdecken
- Einstellungen an der Kamerakonfiguration vornehmen

Aktuell getestete Reolink-Kameras:
- Reolink Duo 2

Wenn eine Kamera mit dem Modul funktioniert würde ich mich über eine Info freuen. Ebenfalls wenn etwas nicht funktioniert, hier bitte eine Sequenz Debug senden und die Info, um welches Modell es sich handelt. Eventuell kann ich dieses dann ins Modul integrieren.

### 2. Voraussetzungen

- IP-Symcon ab Version 7.0

### 3. Software-Installation

* Über den Module Store kann das Modul unter dem genauen Namen gefunden und installiert werden.

### 4. Einrichten der Instanzen in IP-Symcon

- Unter 'Instanz hinzufügen' kann das 'Reolink'-Modul mithilfe des Schnellfilters gefunden werden.  
- Weitere Informationen zum Hinzufügen von Instanzen in der [Dokumentation der Instanzen](https://www.symcon.de/service/dokumentation/konzepte/instanzen/#Instanz_hinzufügen)

__Konfigurationsseite__:

Name     | Beschreibung
-------- | ------------------
IP-Adresse                          |	IP-Adresse des Rechners auf dem der Libre Hardware Monitor läuft
Benutzername                        |   Benutzername zur Anmeldung im Interface der Kamera
Passwort                            |   Passwort zur Anmeldung im Interface der Kamera
Stream-Typ                          |   Hier kann zwischen Main- und Substream gewählt werden. Achtung: Der Mainstream ist häufig H265 codiert, dies kann von IP-Symcon nicht abgespielt werden. Standard ist Substream.
Webhook-Daten                       |	Aktiviert die Anzeige des JSON des Webhooks in Variablen. Dies ist nur für allfällige Diagnose nötig
Intelligente Bewegungserkennung     |   Aktiviert die intelligente Bewegungserkennung
Schnappschüsse anzeigen             |   Aktiviert den letzen Schnappschuss der intelligenten Bewegungserkennung zur allfälligen weiterferabeitung. Solange noch kein Schnappschuss erstellt ist wird nichts angezeigt
Bildarchive anzeien                 |   Aktiviert die Bildarchive
Anzahl Archivbilder                 |   Maximale Anzahl der Archivbilder. Nicht zuviele Bilder einstellen, da diese alle in IP-Symcon gespeichert werden. Standard ist 20.

### 5. Statusvariablen und Profile

Die Statusvariablen/Kategorien werden automatisch angelegt. Das Löschen einzelner kann zu Fehlfunktionen führen.

#### Statusvariablen

Es werden Variablen/Typen je nach Wahl im Konfigurationsformuler erstellt.

#### Profile

Es werden keine Profile erstellt

### 6. WebFront

Integration von Kamastream, Variablen zur intelligenten Bewegungserkennung und Bildarchiv.

### 7. Webhook

Es wird automatisch ein Webhook erstellt. Der Name des Webhook wird oben im Konfiguratinsformular angezeigt. Dieser Pfad muss im Webinterface der Kamera in den Einstellungen unter Push eingetragen werden. 
Es ist nur noch die IPvonSYMCON:3777 davor aufzuführen.
Beispiel: http://192.168.178.48:3777/hook/reolink_28009

### 8. Versionen

Version 1.0 (16.11.2024)

- Initiale Version
