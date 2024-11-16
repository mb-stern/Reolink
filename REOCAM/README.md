# Reolink für IP-Symcon

Folgende Module beinhaltet das REOCAM Symcon Repository:
- __Reolink__ ([Dokumentation](REOCAM))   

Integration von Reolink-Kameras in IP Symcon.
Dies ist kein ONVIF-Fähiges Modul.
Der Hauptnutzen dieses Moduls ist es, die intelligente Bewegungserennung für Personen, Tiere und Fahrzeuge zu nutzen, was über ONVIF aktuell nicht funktioniert.
Dieses Modul ist nur für Reolink Kameras ausgelegt, welche Webhhook unterstützen. 
Daher ist immer die aktuellste Firmware aufzuspielen und im Webinterface der Kamera in den Einstellungen unter Push der Pfad zum Webhook einzutragen. 
Der Pfad zum Webhook ist im Konfigurationsformular aufgeführt, es ist nur noch die IPvonSYMCON:3777 davor aufzuführen.
Beispiel: http://192.168.178.48:3777/hook/reolink_28009

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

Wenn eine Kamera mit dem Modul funktioniert würde ich mich über eine Info freuen. Ebenfalls wenn etwas nicht funktioniert, hier bitte eine Sequenz Debug senden und die iNfo, um welches Modell es sich handelt. Eventuell kann ich dieses dann ins Modul integrieren.



### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Software-Installation](#3-software-installation)
4. [Einrichten der Instanzen in IP-Symcon](#4-einrichten-der-instanzen-in-ip-symcon)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [WebFront](#6-webfront)
7. [PHP-Befehlsreferenz](#7-php-befehlsreferenz)
8. [Versionen](#8-versionen)

### 1. Funktionsumfang

* Abfrage der Istwerte und Steurung der Luxtronik, welche in verschiedenen Wärmepumpen als Steuerung verbaut ist.
* Es werden automatisch die gewünschten Variablen angelegt und die benötigten Profile erstellt.
* Es werden jedoch nicht restlos alle Werte in Variablen aufgeschlüsselt, bei Bedarf ist daher der Name manuell einzutragen.
* Ebenfalls werden je nach Wärmepumpen-Typ nicht alle Werte geliefert. Offensichtlich werden mit einer Software alle Wärmepumentypen abgedeckt.
* Es können Variablen für die Steuerung von Heizung, Warmwasser und Kühlung aktiviert werden, je nach Funktionsumfang der Wärmepumpe. Diese Variablen zur Steuerung werden nicht live synchronisiert, sondern immer erst dann, wenn Änderungen am Konfigurationsformular vorgenommen wurden.
* Die Anzeige des COP-Faktor ist nun unter Zuhilfenahme einer externen Leistungsmessung (kW) möglich. Die entsprechende Variable kann im Konfigurationsformular ausgewählt werden.
* Die Anzeige des JAZ-Faktor ist nun unter Zuhilfenahme einer externen Leistungsmessung (kWh) möglich. Die entsprechende Variable kann im Konfigurationsformular ausgewählt und die Berechnung bei Bedarf zurückgesetzt werden.
* Es kann die interne Timerfunktion der Luxtronik genutzt werden. Es kann ausgewählt werden, wie viele Variablen (Zeitfenster) erstellt werden sollen, um nicht unnötige Variablen zu verwenden. Die maximale Menge ist analog den Programmiermöglichkeiten über das Webinterface. Beim deaktivieren der Timerfenster bleiben die ursprünglich gespeicherten Werte aber erhalten.

### 2. Voraussetzungen

- IP-Symcon ab Version 7.0

### 3. Software-Installation

* Über den Module Store kann das Modul installiert werden.

### 4. Einrichten der Instanzen in IP-Symcon

- Unter 'Instanz hinzufügen' kann das 'Luxtronik'-Modul mithilfe des Schnellfilters gefunden werden.  
- Weitere Informationen zum Hinzufügen von Instanzen in der [Dokumentation der Instanzen](https://www.symcon.de/service/dokumentation/konzepte/instanzen/#Instanz_hinzufügen)

__Konfigurationsseite__:

Name     | Beschreibung
-------- | ------------------
IP-Adresse      |	IP-Adresse des Rechners auf dem der Libre Hardware Monitor läuft
Benutzername    |   Benutzername zur Anmeldung im Interface der Kamera
Passwort        |   Passwort zur Anmeldung im Interface der Kamera
Stream-Typ      |   Hier kann zwischen Main- und Substream gewählt werden. Achtung: Der Mainstream ist häufig H265 codiert, dies kann von IP-Symcon nicht abgespielt werden
IWehook-V      |	IP-Adresse des Rechners auf dem der Libre Hardware Monitor läuft
Benutzername    |   Benutzername zur Anmeldung im Interface der Kamera
Passwort        |   Passwort zur Anmeldung im Interface der Kamera
Stream-Typ      |   Hier kann zwischen Main- und Substraa



### 5. Statusvariablen und Profile

Die Statusvariablen/Kategorien werden automatisch angelegt. Das Löschen einzelner kann zu Fehlfunktionen führen.

#### Statusvariablen

Es werden Variablen/Typen je nach Wahl im Konfigurationsformuler erstellt.

#### Profile

Es werden keine Profile erstellt

### 6. WebFront

Integration von Kamastream, Variablen zur intelligenten Bewegungserkennung und Bildarchiv.

### 7. PHP-Befehlsreferenz


### 8. Versionen

Version 1.0 (16.11.2024)

- Initiale Version
