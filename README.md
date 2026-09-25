# Reolink für IP-Symcon

Folgende Module beinhaltet das Reolink Repository:
- __Reolink__ ([Dokumentation](REOCAM))

Integration von Reolink-Kameras in IP-Symcon. Bei Verwendung mehrerer Reolink-Kameras kann das Modul mehrfach installiert werden.

Der Schwerpunkt des Moduls liegt auf der intelligenten Bewegungserkennung für Personen, Tiere, Besucher und Fahrzeuge sowie der Verarbeitung dieser Ereignisse über Webhook oder Polling. Zusätzlich stehen verschiedene Funktionen zur Kamerasteuerung und eine optionale Bewegungsmelder-/Lichtsteuerung zur Verfügung.

Das Modul ist für Reolink-Kameras mit Webhook-Unterstützung optimiert, funktioniert aber auch mit unterstützten Kameras ohne Webhook über Polling. Für den bestmöglichen Funktionsumfang sollte die Kamera mit der aktuellen Firmware betrieben werden.

Das Modul kann unter anderem:

- Bewegungen, Personen, Tiere, Fahrzeuge und Besucher (Doorbell) erkennen und als IP-Symcon-Variablen bereitstellen.
- Bei erkannten Ereignissen automatisch Schnappschüsse aufnehmen.
- Schnappschuss-Archive mit einstellbarer Anzahl gespeicherter Bilder erstellen.
- Einen RTSP-Stream als Main- oder Substream für das Live-Bild bereitstellen.
- Verschiedene Reolink-API-Funktionen zur Kamerasteuerung verwenden.
- Eine zusätzliche Bewegungsmelder-/Lichtsteuerung nutzen, die abhängig von Mensch, Tier oder Fahrzeug sowie einem Helligkeitsschwellwert ein Licht schalten kann.

Eine ausführliche Beschreibung aller Funktionen und Einstellungen befindet sich in der [Dokumentation](REOCAM).
