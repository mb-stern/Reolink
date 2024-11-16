# Reolink

Folgende Module beinhaltet das WPLUX Symcon Repository:
- __Reolink__ ([Dokumentation](REOCAM))   

Integration von Reolink-Kameras in IP Symcon.
Dies ist kein ONVIF-Fähiges Modul.
Der Hauptnutzen dieses Moduls ist es, die intelligente Bewegungserennung für Personen, Tiere und Fahrzeuge zu nutzen, was über ONVIF aktuell nicht funktioniert.
Dieses Modul ist nur für Reolink Kameras ausgelegt, welche Webhhook unterstützen. 
Daher ist immer die aktuellste Firmware aufzuspielen und im Webinterface der Kamera in den Einstellungen unter Push der Pfad zum Webhook einzutragen. 
Der Pfad zum Webhook ist im Konfigurationsformular aufgeführt, es ist nur noch die IPvonSYMCON:3777 davor aufzuführen.
Beispiel: http://192.168.178.48:3777/hook/reolink_28009

Das Modul kann folgendes:

-Schnappschüsse bei Bewegungen aufnehmen (Allgemeine Bewegungen, Personen, Tiere und Fahrezeuge).
-Ein Schnappschuss-Archiv zu den jeweiligen Bewegungen erstellen und die Anzahl der darin gespeicherten Bilder definieren.
-Die intelligente Bewegungserkennung als Variable darstellen.
-Den Pfad zum RTSP-Stream erstellen, um das Live-Bild darzustellen.
-Auswählen, ob Main- oder Substream angezigt werden soll.
