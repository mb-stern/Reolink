# Zusätzlicher Bewegungsmelder

Basis: mb-stern/Reolink, Branch `development`, Commit `7306ca5fdef07974775a8937c46850388d8f5495`.

Die Erweiterung ist direkt in der Reolink-Instanz konfigurierbar. Sie ist zunächst deaktiviert. Die vorhandenen Erkennungsvariablen, 5-Sekunden-Rücksetztimer, Schnappschüsse und Archive behalten ihre bisherige Logik.

## Einbau

Diese Erweiterung ist für das bestehende Reolink-Modul bestimmt. Für den parallelen Live-Test zum Store-Modul den Branch `test/reolink-v2` dieses Forks verwenden. Dieser Feature-Branch behält die Original-IDs und darf nicht parallel zur Store-Bibliothek installiert werden.

## Einstellungen

| Feld | Bedeutung |
| --- | --- |
| Zusätzlichen Bewegungsmelder aktivieren | Standardmäßig aus; aktiviert nur die neue Lichtsteuerung. |
| Mensch, Tier, Fahrzeug | Ein-/Aus-Schalter; die jeweiligen Variablen dieser Kamera werden automatisch gefunden. Jede Kombination ist möglich. |
| Schaltvariable | Boolean-Variable mit Standardaktion oder eigenem Aktionsskript. `true` schaltet ein, `false` aus. |
| Helligkeitsvariable | Integer- oder Float-Variable des Helligkeitssensors. |
| Einschalten unter Schwellwert | Nur Werte **kleiner** als dieser Wert erlauben Einschalten. Helligkeitsvariable und Schwellwert verwenden lux. Standard: 30. |
| Nachlaufzeit | Sekunden seit der letzten positiven Erkennung, 1–86400; Standard: 120. |

Die Variablen der eigenen Kamera werden anhand der eingeschalteten Erkennungsarten automatisch verwendet. Dafür müssen die Bewegungsvariablen in der bestehenden Modulkonfiguration aktiviert bleiben. Alle gewählten Quellen wirken gemeinsam auf dieselbe Schaltvariable.

## Verhalten

- Eine positive Erkennung schaltet bei ausreichender Dunkelheit ein.
- Nur weitere positive Erkennungen unterhalb des Lux-Schwellwerts verlängern die Nachlaufzeit.
- Bei der eigenen Kamera werden Webhook-Erkennungen und alle positiven Polling-Antworten berücksichtigt, auch ohne Änderung des Boolean-Wertes.
- Die Erkennungsarten Mensch, Tier und Fahrzeug lassen sich einzeln ein- und ausschalten; ihre Kamera-Variablen werden automatisch gefunden.
- Negative Erkennungen und die bestehenden 5-Sekunden-Rücksetzungen schalten das Ziel nicht aus und verlängern die Nachlaufzeit nicht.
- Der eigene Timer prüft die Frist jede Sekunde. Das Ausschalten erfolgt bei der nächsten Ausführung nach Fristablauf; Symcon-Auslastung kann dies verzögern.
- Helligkeitsänderungen allein schalten nicht ein; es ist eine neue Erkennung erforderlich.
- Bereits vor der Erkennung eingeschaltete Ziele werden nicht übernommen und deshalb auch nicht durch diese Automatik ausgeschaltet.
- Wird ein automatisch eingeschaltetes Ziel während der Nachlaufzeit manuell bedient, hat diese Bedienung keine separate Vorranglogik: Die geplante Ausschaltung bleibt bestehen. Pro Ziel sollte nur eine solche Automatik zuständig sein.
- Deaktivieren der Automatik oder der Instanz, ungültige Konfiguration und Wechsel der Zielvariable schalten das bisher von der Automatik eingeschaltete Ziel aus.
- Eine geänderte Nachlaufzeit wird ab der letzten gespeicherten Erkennung berechnet. Ein Symcon-Neustart erhält die ausstehende Ausschaltung.
- Bei Fehlern beim Ausschalten wird nach fünf Sekunden erneut versucht. Fehlermeldungen erscheinen im Debug der Instanz.

## Änderungen am bestehenden Modul

Die neue Datei `MotionLighting.php` kapselt Einstellungen, Formular, Variablenmeldungen und Timer. `module.php` bindet sie ein und ergänzt Aufrufe bei Erstellung, Konfigurationsübernahme, Formularaufbau sowie Webhook und Polling. `SetMoveTimer()` und `ResetMoveTimer()` sind unverändert.

Schalten erfolgt über Symcons `RequestAction`, damit die hinterlegte Geräteaktion ausgeführt wird: [Symcon-Dokumentation](https://www.symcon.de/de/service/dokumentation/befehlsreferenz/variablenzugriff/requestaction/).

## Prüfung

PHP 8.3.31: Syntaxprüfung beider Moduldateien bestanden. 85 automatisierte Prüfungen mit simulierten Symcon-Funktionen bestanden, einschließlich Webhook und Polling im tatsächlichen Modul, Nachtriggern, Schwellenwert, unverändertem 5-Sekunden-Reset, Erkennungskombinationen und Zielwechsel, Neustart und Aktionsfehlern.

Ausführen mit PHP 8.2 oder neuer:

```sh
php -l REOCAM/module.php
php -l REOCAM/MotionLighting.php
php tests/motion-lighting.php
```

Der Anwender hat die separate V2-Testversion in seiner Linux-Symcon-Installation getestet und am 24.09.2026 die Einreichung zur Übernahme freigegeben. Ein vollständiges Protokoll aller Live-Testfälle liegt nicht vor.


## Statusanzeige

Die Boolean-Variable **Bewegungsmelder aktiv** ist eine reine Anzeige ohne Schaltaktion. Sie ist An, wenn der Hauptschalter und mindestens eine Erkennungsart eingeschaltet sind. Sie zeigt die gespeicherte Auswahl nach Änderungen übernehmen; keine Bewegung, keinen Lichtzustand und keine Prüfung der Betriebsbereitschaft. 85 automatisierte Prüfungen mit simulierten Symcon-Funktionen bestanden, einschließlich aller Schalterkombinationen.


## Hauptschalter der Lichtsteuerung

**„Zusätzlichen Bewegungsmelder aktivieren“ ist der Hauptschalter.** Nur wenn dieser eingeschaltet ist, darf die neue Automatik das Licht schalten. Die Schalter Mensch, Tier und Fahrzeug legen zusätzlich fest, welche Erkennungsarten auslösen dürfen. Nach dem Einstellen **Änderungen übernehmen**.

Ist der Hauptschalter aus, bleiben die normale Kamera-Erkennung und die bisherigen Erkennungsvariablen aktiv; die zusätzliche Lichtsteuerung ist ausgeschaltet. Die Statusvariable „Bewegungsmelder aktiv“ zeigt An, wenn der Hauptschalter und mindestens eine Erkennungsart eingeschaltet sind. Sie zeigt die Aktivierung, nicht den aktuellen Lichtzustand.

## Beispiel: smarter Bewegungsmelder für die Hauseinfahrt

Das Einfahrtslicht soll bei Menschen oder Fahrzeugen einschalten, aber nicht bei einer als Tier erkannten Katze oder einem Hund.

| Einstellung | Beispiel |
| --- | --- |
| Zusätzlichen Bewegungsmelder aktivieren | An |
| Mensch als Auslöser verwenden | An |
| Tier als Auslöser verwenden | Aus |
| Fahrzeug als Auslöser verwenden | An |
| Schaltvariable | Einfahrtslicht: Boolean-Variable mit Geräteaktion |
| Helligkeitsvariable | Außen-Helligkeitssensor in lux |
| Einschalten unter Schwellwert | 30 lux, als anpassbarer Startwert |
| Nachlaufzeit | 120 Sekunden |

Unter 30 lux schaltet eine erkannte Person oder ein erkanntes Fahrzeug das zuvor ausgeschaltete Einfahrtslicht ein. Jede weitere passende Erkennung unterhalb des Lux-Schwellwerts startet die Nachlaufzeit erneut. Nach 120 Sekunden ohne weitere passende Erkennung sendet die Automatik false an die Schaltaktion.

Eine ausschließlich als Tier gemeldete Erkennung schaltet nicht ein und verlängert die Nachlaufzeit nicht. Die Tier-Erkennungsvariable der Kamera funktioniert trotzdem weiter. Die Unterscheidung hängt von der Klassifizierung der Kamera ab: Fehlklassifizierungen sind möglich; werden gleichzeitig eine Person oder ein Fahrzeug erkannt, darf das Licht einschalten. Es ist daher keine Garantie, dass Katzen oder Hunde unter allen Umständen ausgeschlossen werden.


## Helligkeit während der Nachlaufzeit (ab Testversion 0.6)

Der Lux-Schwellwert gilt sowohl zum Einschalten als auch zum Verlängern. Beispiel: Bei 20 lux wird eingeschaltet. Steigt die Helligkeit auf mindestens 30 lux, verlängern neue Erkennungen die laufende Nachlaufzeit nicht mehr. Das Licht geht nach Ablauf der Zeit seit der letzten passenden Erkennung unterhalb von 30 lux aus – auch bei fortlaufender Personen- oder Fahrzeugerkennung. Die Lux-Überschreitung startet keine neue Frist und schaltet nicht sofort aus. Erst unterhalb von 30 lux dürfen passende Erkennungen wieder einschalten oder verlängern. Dies gilt auch, wenn das eingeschaltete Licht selbst den Sensor aufhellt.
