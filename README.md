# osTicket WhatsApp Integration

Bidirektionale WhatsApp-Integration fuer osTicket. Ermoeglicht es Kunden, Tickets via WhatsApp zu erstellen und Antworten direkt auf WhatsApp zu erhalten.

## Inhaltsverzeichnis

- [Features](#features)
- [Architektur](#architektur)
- [Voraussetzungen](#voraussetzungen)
- [Installation](#installation)
- [Konfiguration](#konfiguration)
- [Kunden-Befehle](#kunden-befehle)
- [Nachrichten-Templates](#nachrichten-templates)
- [API Endpunkte](#api-endpunkte)
- [Datenbank](#datenbank)
- [Wartung](#wartung)
- [Troubleshooting](#troubleshooting)
- [Sicherheit](#sicherheit)
- [Changelog](#changelog)

---

## Features

### Eingehende Kommunikation (WhatsApp → osTicket)
- Automatische Ticket-Erstellung bei neuen WhatsApp-Nachrichten
- Zuordnung weiterer Nachrichten zum bestehenden Ticket
- Kunden koennen Tickets per Keyword schliessen
- Kunden koennen zwischen mehreren Tickets wechseln
- Kunden koennen ihre offenen Tickets auflisten
- Kunden koennen die Verknuepfung aufheben ohne zu schliessen
- Signalwoerter (Danke, Ok, etc.) erstellen keine neuen Tickets
- Ungueltige Steuerwort-Formate werden erkannt und gemeldet
- Medien-Nachrichten werden mit Email-Upload-Link beantwortet
- User-Zuordnung via Telefonnummer (bestehende Kunden werden erkannt)

### Ausgehende Kommunikation (osTicket → WhatsApp)
- Agent-Antworten werden automatisch per WhatsApp gesendet
- Benachrichtigung bei Ticket-Schliessung
- Bestaetigung bei Ticket-Erstellung
- Bestaetigung bei Nachricht zu bestehendem Ticket

### Verwaltung
- Telefon-zu-Ticket Mapping mit History
- Nachrichtenprotokoll fuer Audit-Zwecke
- Automatische Bereinigung verwaister Mappings
- Alle Texte und Einstellungen ueber das Admin-Panel konfigurierbar
- Kostenlos (basiert auf Baileys, keine API-Gebuehren)

---

## Architektur

```
+------------------+                    +-------------------+
|  WhatsApp User   | <-- WebSocket --> |  WhatsApp Service |
|  (Smartphone)    |                    |  (Node.js)        |
+------------------+                    +---------+---------+
                                                  |
                                           REST API / Webhooks
                                                  |
                                        +---------v---------+
                                        |  osTicket         |
                                        |  + WhatsApp Plugin|
                                        |  (PHP)            |
                                        +-------------------+
```

Die Integration besteht aus zwei Komponenten:

1. **WhatsApp Service** (Node.js): Verwaltet die WhatsApp-Verbindung via Baileys
2. **osTicket Plugin** (PHP): Verarbeitet Nachrichten und verwaltet Tickets

### Komponenten des Plugins

| Datei | Beschreibung |
|-------|--------------|
| `plugin.php` | Plugin-Manifest und Metadaten |
| `class.WhatsAppPlugin.php` | Hauptklasse mit Event-Hooks |
| `class.WhatsAppWebhook.php` | Webhook-Handler fuer eingehende Nachrichten |
| `class.WhatsAppApi.php` | API-Client fuer den WhatsApp-Service |
| `class.WhatsAppMapping.php` | Datenbank-Abstraktionsschicht |
| `class.WhatsAppUtils.php` | Zentrale Utility-Funktionen |
| `class.WhatsAppConstants.php` | Konstanten und Enums |
| `config.php` | Plugin-Konfigurationsformular |
| `whatsapp-webhook.php` | Webhook-Einstiegspunkt |

### Komponenten des Services

| Datei | Beschreibung |
|-------|--------------|
| `src/index.js` | Einstiegspunkt |
| `src/api.js` | REST API Endpoints |
| `src/whatsapp.js` | Baileys WhatsApp Client |
| `src/webhook.js` | Webhook-Sender zu osTicket (mit Retry-Logik) |
| `src/config/constants.js` | Zentrale Konstanten |
| `src/middleware/validation.js` | Input-Validierung und API-Key-Authentifizierung |

---

## Voraussetzungen

### Server

| Komponente | Mindestversion | Empfohlen |
|------------|---------------|-----------|
| Node.js | 18.x | 20.x LTS |
| PHP | 7.4 | 8.1+ |
| osTicket | 1.15 | 1.18+ |
| MySQL/MariaDB | 5.7 / 10.3 | 8.0 / 10.6 |

### WhatsApp

- Ein Smartphone mit WhatsApp installiert
- Eine Telefonnummer, die fuer den Service verwendet wird
- **Wichtig**: Diese Nummer wird exklusiv fuer den Service genutzt

---

## Installation

### Schritt 1: Repository klonen

```bash
git clone https://github.com/your-username/osticket-whatsapp.git
cd osticket-whatsapp
```

### Schritt 2: WhatsApp Service installieren

```bash
cd whatsapp-service

# Dependencies installieren
npm install

# Konfiguration erstellen
cp .env.example .env
```

Bearbeite die `.env` Datei:

```env
# Port fuer die REST API
PORT=3000

# Host (127.0.0.1 = nur lokal erreichbar)
HOST=127.0.0.1

# Webhook URL - deine osTicket Installation
WEBHOOK_URL=https://support.deine-domain.de/api/whatsapp-webhook.php

# Webhook Secret (muss mit Plugin-Einstellung uebereinstimmen)
WEBHOOK_SECRET=dein-geheimer-schluessel

# Log Level (trace, debug, info, warn, error)
LOG_LEVEL=info
```

### Schritt 3: osTicket Plugin installieren

```bash
# Plugin-Ordner kopieren
cp -r osticket-plugin/whatsapp /pfad/zu/osticket/include/plugins/

# Webhook-Endpunkt kopieren
cp osticket-plugin/whatsapp/whatsapp-webhook.php /pfad/zu/osticket/api/whatsapp-webhook.php
```

### Schritt 4: Plugin in osTicket aktivieren

1. Melde dich im osTicket Admin-Panel an
2. Gehe zu **Admin Panel > Manage > Plugins**
3. Klicke auf **Add New Plugin**
4. Waehle **WhatsApp Integration** aus der Liste
5. Klicke auf **Install**
6. Aktiviere das Plugin

**Hinweis:** Die Datenbank-Tabellen werden automatisch beim Plugin-Install erstellt.

### Schritt 5: Plugin konfigurieren

1. Gehe zu **Admin Panel > Manage > Plugins**
2. Klicke auf **WhatsApp Integration**
3. Konfiguriere die Einstellungen (siehe Abschnitt "Konfiguration")

### Schritt 6: WhatsApp Service starten

```bash
cd whatsapp-service

# Direkt starten (fuer Tests)
npm start

# Oder mit PM2 (empfohlen fuer Produktion)
npm install -g pm2
pm2 start ecosystem.config.js
pm2 save
pm2 startup
```

### Schritt 7: WhatsApp verbinden

1. Starte den Service: `npm start`
2. Ein QR-Code wird im Terminal angezeigt
3. Oeffne WhatsApp auf deinem Smartphone
4. Gehe zu **Einstellungen > Verknuepfte Geraete > Geraet hinzufuegen**
5. Scanne den QR-Code

Nach erfolgreichem Scan zeigt der Service:
```
WhatsApp connected successfully
```

---

## Konfiguration

### Basis-Einstellungen

| Einstellung | Beschreibung | Standard |
|-------------|--------------|----------|
| **Service URL** | URL des WhatsApp Node.js Service | `http://127.0.0.1:3000` |
| **API Key** | Optionaler API-Schluessel fuer Authentifizierung | - |
| **Webhook Secret** | Secret zur Validierung eingehender Webhooks | - |
| **Standard Help Topic** | Help Topic fuer neue WhatsApp-Tickets | System-Default |
| **Standard Abteilung** | Abteilung fuer neue WhatsApp-Tickets | System-Default |
| **Support Email** | Email-Adresse fuer Datei-Uploads | `support@example.com` |
| **Automatische Bestaetigung** | Bestaetigung bei Ticket-Erstellung senden | Aktiviert |

### Kunden-Befehle Konfiguration

| Einstellung | Beschreibung | Standard |
|-------------|--------------|----------|
| **Schliessen-Stichwort** | Keyword zum Schliessen von Tickets | `SCHLIESSEN` |
| **Signalwoerter** | Woerter die ignoriert werden (kein neues Ticket) | Danke, Ok, Erledigt, ... |
| **Ticket-Wechsel Stichwort** | Keyword zum Wechseln des aktiven Tickets | `Ticket-Wechsel` |
| **Ticket-Liste Stichwort** | Keyword zum Auflisten offener Tickets | `OFFEN` |
| **Neues-Ticket Stichwort** | Keyword zum Aufheben der Verknuepfung | `NEU` |

### Benachrichtigungen

| Einstellung | Beschreibung | Standard |
|-------------|--------------|----------|
| **Bei Agent-Antwort benachrichtigen** | Agent-Antworten an WhatsApp senden | Aktiviert |
| **Bei Ticket-Schliessung benachrichtigen** | Kunde bei Schliessung informieren | Aktiviert |
| **Bei Nachricht-Hinzufuegung benachrichtigen** | Bestaetigung bei Nachricht zu Ticket | Aktiviert |
| **Signatur** | Signatur am Ende von Agent-Antworten | `Ihr Support-Team` |

---

## Kunden-Befehle

Kunden koennen folgende Befehle per WhatsApp senden:

### Ticket schliessen
```
SCHLIESSEN
```
Schliesst das aktuelle Ticket. Nur das Haupt-Keyword schliesst tatsaechlich.

### Offene Tickets auflisten
```
OFFEN
```
Zeigt alle offenen Tickets des Kunden an.

### Ticket wechseln
```
Ticket-Wechsel #123456
```
Wechselt zum Ticket mit der angegebenen Nummer (nur eigene Tickets).

### Neues Ticket starten
```
NEU
```
Hebt die aktuelle Ticket-Verknuepfung auf, ohne das Ticket zu schliessen. Die naechste Nachricht erstellt ein neues Ticket.

### Signalwoerter

Signalwoerter wie `Danke`, `Ok`, `Erledigt`, etc. erstellen **kein neues Ticket** wenn kein Ticket offen ist. Dies verhindert, dass z.B. ein "Danke" nach Ticket-Schliessung versehentlich ein neues Ticket oeffnet.

Die Liste der Signalwoerter ist in der Plugin-Konfiguration anpassbar.

### Ungueltige Steuerwort-Formate

Wenn ein Steuerwort am Anfang einer Nachricht erkannt wird, aber das Format ungueltig ist (z.B. `WECHSEL 'FFK-123` statt `Ticket-Wechsel #FFK-123`), wird eine Fehlermeldung gesendet und **kein neues Ticket erstellt**.

### Medien-Nachrichten

Wenn ein Kunde ein Bild, Video oder Dokument sendet, erhaelt er automatisch:
- Hinweis dass Dateien per Email eingereicht werden muessen
- mailto-Link mit vorausgefuellter Ticketnummer

---

## Nachrichten-Templates

### Verfuegbare Variablen

**Alle Variablen sind in allen Templates verfuegbar.** Nicht verwendete Variablen werden automatisch durch leere Strings ersetzt.

| Variable | Beschreibung |
|----------|-------------|
| `{ticket_number}` | Ticketnummer (z.B. "FFK-12345") |
| `{ticket_subject}` | Ticket-Betreff |
| `{name}` | Name des Kunden |
| `{message}` | Nachrichteninhalt (bei Agent-Antworten) |
| `{agent_name}` | Name des antwortenden Agents |
| `{ticket_list}` | Formatierte Liste der offenen Tickets |
| `{count}` | Anzahl der offenen Tickets |
| `{email_link}` | Mailto-Link fuer Datei-Uploads |
| `{support_email}` | Support Email-Adresse |
| `{close_keyword}` | Konfiguriertes Schliessen-Keyword |
| `{switch_keyword}` | Konfiguriertes Wechsel-Keyword |
| `{new_keyword}` | Konfiguriertes Neues-Ticket-Keyword |
| `{list_keyword}` | Konfiguriertes Liste-Keyword |
| `{signature}` | Konfigurierte Signatur (z.B. "Ihr Support-Team") |
| `{keyword}` | Das erkannte (fehlerhafte) Steuerwort |
| `{expected_format}` | Erwartetes Format fuer Steuerwort |

### Konfigurierbare Templates

| Template | Verwendung |
|----------|------------|
| **Bestaetigungsnachricht** | Bei Ticket-Erstellung |
| **Ticket-geschlossen Nachricht** | Bei Ticket-Schliessung |
| **Nachricht-hinzugefuegt Bestaetigung** | Bei Nachricht zu bestehendem Ticket |
| **Agent-Antwort Format** | Format fuer Agent-Antworten |
| **Ticket-Wechsel Bestaetigung** | Nach erfolgreichem Wechsel |
| **Ticket-Wechsel Fehler** | Wenn Ticket nicht gefunden |
| **Ticket-Liste Nachricht** | Liste der offenen Tickets |
| **Keine Tickets Nachricht** | Wenn keine Tickets vorhanden |
| **Neues-Ticket Bestaetigung** | Bei Aufhebung der Verknuepfung |
| **Steuerwort-Fehler Nachricht** | Bei ungueltigem Steuerwort-Format |
| **Ticket nicht gefunden** | Wenn Ticket nicht existiert |
| **Ticket bereits geschlossen** | Wenn Ticket schon geschlossen |
| **Schliessen fehlgeschlagen** | Bei Fehler beim Schliessen |
| **Antwort bei Medien-Nachricht** | Bei Bildern/Dokumenten |

### Beispiel-Nachrichten

#### Bestaetigungsnachricht
```
Vielen Dank fuer Ihre Nachricht, Max!

Ihr Ticket wurde erstellt.
Ticket-Nummer: #FFK-123456

*Wichtig:* Sie koennen via WhatsApp immer nur ein Ticket gleichzeitig bearbeiten.
Alle Ihre Nachrichten werden diesem Ticket zugeordnet.

Um zu einem anderen Ticket zu wechseln, senden Sie:
Ticket-Wechsel #[Ihre-Ticketnummer]

Um Ihre offenen Tickets anzuzeigen, senden Sie:
OFFEN

Um dieses Ticket zu schliessen, senden Sie:
SCHLIESSEN

Wir melden uns schnellstmoeglich bei Ihnen.
```

#### Agent-Antwort
```
*Antwort zu Ticket #FFK-123456 - Drucker funktioniert nicht*

Vielen Dank fuer Ihre Anfrage. Haben Sie bereits versucht, den Drucker neu zu starten?

_Ihr Support-Team_
```

---

## API Endpunkte

### WhatsApp Service (Node.js)

| Methode | Endpunkt | Beschreibung |
|---------|----------|--------------|
| GET | `/status` | Verbindungsstatus |
| GET | `/qr` | QR-Code abrufen |
| GET | `/health` | Health-Check |
| POST | `/send` | Nachricht senden |
| POST | `/send-secure` | Nachricht senden (mit API-Key) |

### Nachricht senden

```bash
curl -X POST http://127.0.0.1:3000/send \
  -H "Content-Type: application/json" \
  -d '{"phone":"49123456789","message":"Hallo!"}'
```

### Webhook API (osTicket)

**Endpoint:**
```
POST /api/whatsapp-webhook.php
```

**Authentifizierung:**
```
Header: X-Webhook-Secret: <webhook_secret>
```

**Events:**

| Event | Beschreibung |
|-------|--------------|
| `message.received` | Eingehende WhatsApp-Nachricht |
| `maintenance.cleanup` | Verwaiste Mappings bereinigen |

---

## Datenbank

Das Plugin erstellt automatisch zwei Tabellen:

### `ost_whatsapp_mapping`

Verknuepft Telefonnummern mit Tickets.

| Spalte | Typ | Beschreibung |
|--------|-----|--------------|
| `id` | INT | Primaerschluessel |
| `phone` | VARCHAR(20) | Telefonnummer (nur Ziffern) |
| `phone_formatted` | VARCHAR(25) | Formatierte Nummer (+XX XXX...) |
| `contact_name` | VARCHAR(255) | Name des Kontakts |
| `ticket_id` | INT | osTicket Ticket-ID |
| `ticket_number` | VARCHAR(20) | osTicket Ticketnummer |
| `user_id` | INT | osTicket User-ID |
| `status` | ENUM | `open`, `closed`, `inactive`, `unlinked` |
| `created` | DATETIME | Erstellungszeitpunkt |
| `updated` | DATETIME | Letzte Aktualisierung |

### `ost_whatsapp_messages`

Protokolliert alle Nachrichten fuer Audit-Zwecke.

| Spalte | Typ | Beschreibung |
|--------|-----|--------------|
| `id` | INT | Primaerschluessel |
| `mapping_id` | INT | Fremdschluessel zu `whatsapp_mapping` |
| `message_id` | VARCHAR(100) | WhatsApp Message-ID |
| `direction` | ENUM | `in` (eingehend), `out` (ausgehend) |
| `content` | TEXT | Nachrichteninhalt |
| `status` | VARCHAR(20) | Status (`sent`, `received`, etc.) |
| `created` | DATETIME | Zeitstempel |

### Status-Werte fuer Mappings

| Status | Beschreibung |
|--------|--------------|
| `open` | Aktives Mapping, Nachrichten werden diesem Ticket zugeordnet |
| `closed` | Ticket wurde geschlossen, neues Ticket wird bei naechster Nachricht erstellt |
| `inactive` | Mapping pausiert (z.B. nach Ticket-Wechsel) |
| `unlinked` | Verknuepfung aufgehoben via NEU-Keyword (Ticket bleibt offen) |

---

## Wartung

### Verwaiste Mappings bereinigen

Mappings deren Tickets geloescht wurden koennen bereinigt werden:

**Per HTTP-Request:**
```bash
curl -X POST https://your-domain.com/api/whatsapp-webhook.php \
  -H "Content-Type: application/json" \
  -H "X-Webhook-Secret: YOUR_SECRET" \
  -d '{"event": "maintenance.cleanup"}'
```

**Per Cron-Job (taeglich):**
```bash
0 2 * * * curl -X POST https://your-domain.com/api/whatsapp-webhook.php \
  -H "Content-Type: application/json" \
  -H "X-Webhook-Secret: YOUR_SECRET" \
  -d '{"event": "maintenance.cleanup"}' >> /var/log/whatsapp-cleanup.log 2>&1
```

### Service aktualisieren

```bash
cd whatsapp-service
git pull
npm install
pm2 restart whatsapp-osticket
```

### Plugin aktualisieren

```bash
cp -r osticket-plugin/whatsapp/* /pfad/zu/osticket/include/plugins/whatsapp/
```

---

## Troubleshooting

### Logging

Das Plugin schreibt Logs in das PHP Error-Log:

```bash
tail -f /var/log/php-fpm/error.log | grep "WhatsApp Plugin"
```

### Haeufige Probleme

#### QR-Code wird nicht angezeigt
- Stelle sicher, dass `qrcode-terminal` installiert ist
- Pruefe die Terminal-Einstellungen (muss Unicode unterstuetzen)
- Verwende `curl http://127.0.0.1:3000/qr` fuer den QR-Code als Text

#### Verbindung bricht ab
- Pruefe die Internetverbindung
- Der Service versucht automatisch, sich wieder zu verbinden
- Bei dauerhaften Problemen: Loesche den `auth_info` Ordner und scanne erneut

#### Webhook wird nicht empfangen
- Pruefe die Webhook-URL in der `.env`
- Stelle sicher, dass die URL von aussen erreichbar ist
- Pruefe das Webhook-Secret in beiden Konfigurationen
- Sieh in den osTicket/PHP Logs nach Fehlern

#### Tickets werden nicht erstellt
- Pruefe ob das Plugin aktiviert ist
- Pruefe die osTicket System-Logs
- Stelle sicher, dass ein gueltiges Help Topic konfiguriert ist

#### Ticket wird erstellt aber nicht angezeigt
- Ticket ist moeglicherweise in einer Abteilung auf die der Agent keinen Zugriff hat
- Plugin-Konfiguration pruefen (Standard Abteilung)
- Agent-Berechtigungen pruefen

---

## Sicherheit

### Empfehlungen

1. **Service nur lokal**: Setze `HOST=127.0.0.1` um den Service nur lokal erreichbar zu machen
2. **HTTPS verwenden**: osTicket sollte ueber HTTPS erreichbar sein
3. **Webhook-Secret**: Verwende ein starkes, zufaelliges Secret
4. **API-Key**: Aktiviere den API-Key fuer zusaetzliche Sicherheit
5. **Firewall**: Blockiere Port 3000 von aussen

### Session-Sicherheit

Die WhatsApp-Session wird im `auth_info` Ordner gespeichert. Dieser Ordner enthaelt sensible Daten:

```bash
chmod 700 whatsapp-service/auth_info
```

---

## Bekannte Einschraenkungen

1. **Inoffizielle API**: Baileys ist eine inoffizielle WhatsApp-Implementierung
   - Kann bei WhatsApp-Updates brechen
   - Nicht fuer Massen-Messaging geeignet
   - WhatsApp koennte theoretisch die Nummer sperren

2. **Ein Account pro Service**: Nur eine WhatsApp-Nummer pro Installation

3. **Ein aktives Ticket pro Kunde**: Via WhatsApp kann nur ein Ticket gleichzeitig bearbeitet werden (Wechsel moeglich)

4. **Keine Medien-Weiterleitung**: Bilder/Videos werden nicht an osTicket uebertragen, stattdessen Email-Link

---

## Changelog

### Version 1.2.0
- **Code-Qualitaet:** Umfassendes Refactoring fuer bessere Wartbarkeit
- **Signatur-Feld:** Neue konfigurierbare Signatur (`{signature}` Variable)
- **Zentrale Utilities:** Neue `WhatsAppUtils` Klasse fuer gemeinsam genutzte Funktionen
- **Konstanten:** Neue `WhatsAppConstants` Klasse (PHP) und `constants.js` (Node.js)
- **Security Fixes:**
  - SQL Injection Prevention in User-ID-Listen
  - API Key jetzt obligatorisch fuer `/send-secure` (blockiert wenn nicht konfiguriert)
- **Webhook Retry:** Automatische Wiederholungsversuche bei Webhook-Fehlern (bis zu 3x)
- **Validation Middleware:** Zentrale Input-Validierung fuer alle API-Endpoints
- **Vereinfachte Config:** Sektionen "Kunden-Befehle", "Medien-Einstellungen" und "Erweiterte Templates" zusammengefasst
- **Code-Duplikation entfernt:** `replaceVariables()` und `htmlToWhatsAppText()` jetzt zentral

### Version 1.1.0
- **Signalwoerter:** Konfigurierbares Signal-Woerter-System (ersetzt hardcodierte Liste)
- **Signalwoerter schliessen nicht:** "Danke", "Erledigt" etc. schliessen das Ticket nicht mehr, sondern werden ignoriert
- **Steuerwort-Validierung:** Ungueltige Formate (z.B. `WECHSEL 'FFK-123`) werden erkannt und gemeldet
- **Nachricht-Bestaetigung:** Optionale Bestaetigung wenn Kunde Nachricht zu bestehendem Ticket sendet
- **Alle Variablen verfuegbar:** Alle Template-Variablen in allen Templates verwendbar
- **Konfigurierbares Agent-Format:** Agent-Antwort-Format jetzt konfigurierbar
- **Neue Templates:** Steuerwort-Fehler, Ticket nicht gefunden, bereits geschlossen, Schliessen fehlgeschlagen
- **Entfernt:** Redundante "Plugin aktiviert" Checkbox (osTicket verwaltet dies selbst)

### Version 1.0.0
- Initiale Version
- Bidirektionale Kommunikation
- Ticket-Erstellung und -Aktualisierung
- Kunden-Befehle (Schliessen, Wechseln)
- Medien-Handling mit Email-Upload
- Agent-Antworten per WhatsApp
- Mapping-Verwaltung und Cleanup

---

## Lizenz

MIT License - Copyright (c) 2025

---

## Credits

- [Baileys](https://github.com/WhiskeySockets/Baileys) - WhatsApp Web API fuer Node.js
- [osTicket](https://osticket.com/) - Open Source Support Ticket System
- [Express](https://expressjs.com/) - Node.js Web Framework

---

## Disclaimer

Dieses Projekt ist nicht mit WhatsApp Inc. oder Meta Platforms Inc. verbunden oder wird von diesen unterstuetzt. Die Nutzung erfolgt auf eigene Gefahr. Stelle sicher, dass du die WhatsApp-Nutzungsbedingungen einhaltst.
