# Reolink für IP-Symcon

Folgende Module beinhaltet das REOCAM Symcon Repository:
- __Reolink__ ([Dokumentation](REOCAM))   

Integration von Reolink-Kameras in IP Symcon. Bei Verwendung mehrerer Reolink-Kameras kann das Modul mehrmals installiert werden. Dies ist kein ONVIF-Fähiges Modul. Der Hauptnutzen dieses Moduls ist es, die intelligente Bewegungserkennung für Personen, Tiere, Besucher und Fahrzeuge zu nutzen, was über ONVIF aktuell nicht funktioniert. Dieses Modul ist nur für Reolink Kameras ausgelegt, welche Webhook unterstützen. Daher ist immer die aktuellste Firmware aufzuspielen.

Das Modul kann folgendes:

- Schnappschüsse bei Bewegungen aufnehmen (Allgemeine Bewegungen, Personen, Tiere und Fahrzeuge).
- Ein Schnappschuss-Archiv zu den jeweiligen Bewegungen erstellen und die Anzahl der darin gespeicherten Bilder definieren.
- Die intelligente Bewegungserkennung als Variable darstellen.
- Den Pfad zum RTSP-Stream erstellen, um das Live-Bild darzustellen.
- Auswählen, ob Main- oder Substream angezeigt werden soll.

Das Modul kann nicht:
- Alle Reolink-Kameras abdecken
- Einstellungen an der Kamerakonfiguration vornehmen. Dies muss immer am Webinterface der Kamera geschehen.

Aktuell getestete Reolink-Kameras:
- Reolink Duo 2

Wenn eine Kamera mit dem Modul funktioniert, würde ich mich um Angabe des Kameramodells freuen.
Wenn nicht, benötige ich eine Info mit Angabe des Kameramodells. Ebenfalls natürlich eine Sequenz Debug. Eventuell kann ich die Kamera dann ins Modul integrieren.