# Reolink für IP-Symcon

## Inhaltsverzeichnis
1. [Funktionsumfang](#1-funktionsumfang)  
2. [Voraussetzungen](#2-voraussetzungen)  
3. [Software-Installation](#3-software-installation)  
4. [Einrichtung der Instanzen in IP-Symcon](#4-einrichtung-der-instanzen-in-ip-symcon)  
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)  
6. [WebFront](#6-webfront)  
7. [Webhook](#7-webhook)  
8. [Versionen](#8-versionen)

---

## 1. Funktionsumfang

Dieses Modul integriert **Reolink-Kameras** vollständig in **IP-Symcon**, mit Schwerpunkt auf der **Webhook-Integration**.  
Die Webhook-Funktion erlaubt es, Ereignisse der Kamera (z. B. Bewegung, Person, Tier, Fahrzeug, Besucher) in Echtzeit an IP-Symcon zu übertragen — ohne Polling und nahezu verzögerungsfrei.

### Hauptfunktionen
- **Echtzeit-Verarbeitung über Webhook**: Auslösen bei Personen-, Tier-, Fahrzeug- und Bewegungsereignissen  
- **Automatische Schnappschüsse** bei jeder erkannten Bewegung  
- **Bildarchiv-Funktion** mit frei definierbarer Anzahl gespeicherter Bilder  
- **Intelligente Bewegungserkennung** (Person, Tier, Fahrzeug, Besucher) als IP-Symcon-Variablen  
- **RTSP-Stream-Integration** (Main- oder Substream auswählbar)  
- **API-Funktionen** zur Kamerasteuerung:
  - LED-Licht (Ein/Aus, Helligkeit, Automatik)
  - E-Mail-Benachrichtigung
  - PTZ-Steuerung mit Presets und Zoom  
- **Automatische Webhook-Erstellung** in IP-Symcon  
- **Optimierte IP-Adress-Erkennung** (Sollte nun auch unter Linux-Systemen funktionieren)

### Unterstützte Kameras (getestet)
| Modell | Webhook | Bemerkung |
|--------|----------|-----------|
| Reolink Duo 2 | ✅ | Voll unterstützt |
| Reolink RLC-810A | ✅ | Empfohlen |
| Reolink Doorbell | ✅ | Besucher-Erkennung aktivierbar |
| Reolink E1 Outdoor | ⚠️ | Nur bestimmte Hardware-Revisionen |
| Reolink RLC-520A | ✅ | Voll unterstützt |
| Reolink E1 Zoom | ✅ | Unterstützt PTZ |
| Reolink E540 | ✅ | Voll unterstützt |

> ⚠️ **Akkubetriebene Modelle** (z. B. *Argus-Reihe*) unterstützen keine Webhooks.  
> Diese können nur über Polling angebunden werden (eingeschränkt getestet).

---

## 2. Voraussetzungen

- IP-Symcon **ab Version 7.0**  
- Kamera muss **HTTP / HTTPS-Zugriff** zulassen  
- Im Kameramenü unter *Push Notifications → Webhook* muss ein Eintrag möglich sein  
- Aktuellste Firmware über das [Reolink Download-Center](https://reolink.com/de/download-center)  
- Bei Kameras ohne Webhook kann **Polling** aktiviert werden (mit Latenz)

---

## 3. Software-Installation

Das Modul kann direkt über den **IP-Symcon Module Store** installiert werden.

---

## 4. Einrichtung der Instanzen in IP-Symcon

### Instanz hinzufügen
- Unter *Instanz hinzufügen* das Modul **Reolink** auswählen.

### Konfigurationsparameter

| Name | Beschreibung |
|------|---------------|
| **Webhook** | Wird automatisch erzeugt. Dieser Pfad muss exakt im Kamera-Menü unter *Push → Webhook* eingetragen werden. |
| **Instanz aktivieren** | Deaktiviert die Instanz temporär, um Fehlermeldungen zu vermeiden. |
| **IP-Adresse** | IP-Adresse der Kamera. |
| **Benutzername** | Benutzername für den Zugriff. |
| **Passwort** | Passwort für den Zugriff. **Sonderzeichen wie + & @ : / ? # [ ] dürfen nicht verwendet werden**, da Reolink diese in URLs nicht korrekt verarbeitet. Verwende ausschließlich alphanumerische Zeichen. |
| **Stream-Typ** | Auswahl zwischen *Mainstream* und *Substream*. Achtung: *Mainstream* ist oft H.265-codiert und kann von IP-Symcon nicht direkt angezeigt werden. |
| **API-Funktionen** | Aktivien der API-Funktionen für LED-Scheinwerfer, E-Mail Alarm und PTZ-Steuerung, FTP-Upload, Sirene, Kameraaufzeichnung und Sensitivität der Bewegungserkennung. Eine Rücksetzung des API-Versins Cache ist möglich, falls die Firmware der Kamera nach einem Update die neue API unterstützt|
| **Polling aktivieren** | Nur aktivieren, wenn die Kamera keinen Webhook unterstützt. |
| **Intelligente Bewegungserkennung** | Erstellt Variablen für Personen, Tiere, Fahrzeuge, Bewegung und Besucher. |
| **Schnappschüsse anzeigen** | Zeigt den letzten Schnappschuss jeder Erkennungsart. |
| **Bildarchive anzeigen** | Erstellt Archive mit Schnappschüssen; Anzeige über separate Verlinkung im WebFront. |
| **Anzahl Archivbilder** | Maximale Bildanzahl pro Archiv (Standard 20). |
| **Test-Elemente anzeigen** | Fügt Test-Variablen und Test-Snapshots hinzu (nur zur Diagnose). |
| **Besucher-Erkennung** | Aktiviert Klingel-Erkennung (nur Doorbell-Modelle). |

---

## 5. Statusvariablen und Profile

### Statusvariablen
Je nach Konfiguration werden automatisch angelegt:

| Variable | Typ | Beschreibung |
|-----------|-----|--------------|
| Person | Boolean | Bewegung durch Person erkannt |
| Tier | Boolean | Bewegung durch Tier erkannt |
| Fahrzeug | Boolean | Bewegung durch Fahrzeug erkannt |
| Bewegung | Boolean | Allgemeine Bewegung erkannt |
| Besucher | Boolean | Besucher erkannt (Doorbell) |
| WhiteLed / Mode / Bright | Integer / Boolean | LED-Licht-Parameter |
| EmailNotify / Interval / Content | Integer / Boolean | E-Mail-Steuerung |
| PTZ_HTML | String | HTML-Element für PTZ-Steuerung |

### Profile

| Profilname | Typ | Beschreibung |
|-------------|-----|--------------|
| `REOCAM.WLED` | Integer | LED-Modus (Aus / Auto / Zeit) |
| `REOCAM.EmailInterval` | Integer | Versandintervall |
| `REOCAM.EmailContent` | Integer | E-Mail-Inhalt (Text / Bild / Video) |

---

## 6. WebFront

- Anzeige des **Live-Streams** über RTSP-Medienobjekt  
- Darstellung der **Schnappschüsse** und **Bildarchive**  
- Direkte **PTZ-Steuerung** über ein integriertes HTML-Element  
- Klare Trennung der Ereignis-Kategorien (Person, Tier, Fahrzeug, Besucher)

---

## 7. Webhook

- Der Webhook wird bei Instanz-Erstellung automatisch registriert.  
- Der vollständige Pfad wird im Formular angezeigt.  
- Dieser muss in der Kamera unter *Push → Webhook* eingetragen werden.  
- Unterstützt POST-Payloads der Reolink-API sowie zusätzliche Status-Updates.  
- Funktioniert über **Symcon Connect** (extern) und **lokal**.  
- Unter Linux wird automatisch die **korrekte lokale IP** ermittelt (nicht 127.0.1.1).

---

## 8. Versionen

### Version 2.11 (28.10.2025)
- API-Abfrage und Debug-Log weiter umgebaut und vereinheitlicht.
- Zurücksetzen des Versions-Cache in die API-Funktionen eingefügt, falls Kammera nach Update die neue API unterstützt.
- Konfiguratisonsformular überarbeitet.

### Version 2.10 (26.10.2025)
- Einige Variablen konnten nicht über das Konfigurationsformular gelöscht werden.
- API-Punkt 'FTP-Upload', 'Sensitivität', 'Kameraaufzeichnung' und 'Sirene' hinzugefügt.
- Weitere Code-Optimierungen

### Version 2.9 (23.10.2025)
- Verbesserte Erkennung der Server-IP-Adresse im Konfigurationsformular
- Einige Variablen konnten nicht über das Konfigurationsformular gelöscht werden
- API-Punkt 'FTP-Upload' hinzugefügt

### Version 2.8 (30.09.2025)
- Neuer Schalter *Instanz deaktivieren*  
- Vollständiger Webhook-Pfad im Formular  
- Überarbeitete Debug-Ausgabe  
- Optimierte API-Abfragen

### Version 2.7 (04.09.2025)
- Neue API-Funktion *PTZ-Steuerung* (Zoom & Presets)  
- Eigene Rubrik für API-Funktionen im Formular

### Version 2.6 (25.08.2025)
- Neue API-Funktion *E-Mail-Versand*  
- Diverse interne Code-Anpassungen

### Version 2.5 (15.06.2025)
- Code-Optimierungen für LED-Parameter

### Version 2.4 (14.02.2025)
- `urlencode()`-Erweiterung für einfache Sonderzeichen

### Version 2.3 (02.01.2025)
- Fehlerbehebung bei Instanz-Erstellung  
- Verbesserte Debug-Ausgabe und API-Verarbeitung

### Version 2.2 (28.12.2024)
- Verbesserte Fehlerbehandlung in `SendApiRequest`  
- Automatische Aktualisierung der API-Werte

### Version 2.1 (22.12.2024)
- Anpassung Modulname  
- Überarbeitete Readme-URL

### Version 2.0 (07.12.2024)
- Vorbereitung auf Store-Kompatibilität  
- Diverse interne Anpassungen

### Version 1.2 (19.11.2024)
- Unterstützung für Kameras ohne Webhook (Polling)

### Version 1.1 (17.11.2024)
- Unterstützung Doorbell  
- Neue API-Funktion LED-Licht

### Version 1.0 (16.11.2024)
- Initiale Beta-Version

---

9. Lizenz
Dieses Modul steht unter der MIT-Lizenz.
© 2025 Stefan Künzli
https://opensource.org/licenses/MIT