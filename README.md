# Reolink für IP-Symcon

Folgende Module beinhaltet das Reolink Repository:
- __Reolink__ ([Dokumentation](REOCAM))   

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
- API-Funktionen, aktuell Ansteuerung des LED-Scheinwerfers.
