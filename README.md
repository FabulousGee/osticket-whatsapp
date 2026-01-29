# osTicket WhatsApp Integration

Bidirektionale WhatsApp-Integration fuer osTicket. Ermoeglicht es Kunden, Tickets via WhatsApp zu erstellen und Antworten direkt auf WhatsApp zu erhalten.

## Features

### Kern-Funktionen
- **Eingehende Nachrichten**: WhatsApp-Nachrichten erstellen automatisch neue Tickets
- **Ausgehende Nachrichten**: Agent-Antworten werden automatisch an WhatsApp gesendet
- **Ticket-Zuordnung**: Folgenachrichten werden dem bestehenden Ticket zugeordnet
- **Automatische Bestaetigung**: Kunden erhalten eine Bestaetigung mit Ticket-Nummer
- **User-Zuordnung via Telefonnummer**: Bestehende osTicket-Kunden werden automatisch erkannt

### Kunden-Befehle via WhatsApp
- **Ticket schliessen**: Kunden koennen ihr Ticket selbst schliessen (z.B. "SCHLIESSEN", "Danke", "Erledigt")
- **Ticket wechseln**: Zwischen mehreren Tickets wechseln (z.B. "Ticket-Wechsel #FFK-123456")
- **Ein-Ticket-Hinweis**: Automatische Info dass nur ein Ticket gleichzeitig bearbeitet werden kann

### Medien-Handling
- **Automatische Antwort bei Dateien**: Bei Bildern/Videos/Dokumenten wird ein Email-Link gesendet
- **mailto-Link**: Vorausgefuellte Email mit Ticketnummer fuer einfache Datei-Einreichung

### Weitere Features
- **Ticket-Info bei Antworten**: Jede Agent-Antwort zeigt Ticketnummer und Betreff
- **Konfigurierbar**: Alle Texte und Einstellungen ueber das osTicket Admin-Panel
- **Kostenlos**: Keine API-Gebuehren (basiert auf Baileys)

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
WEBHOOK_URL=https://support.deine-domain.de/api/whatsapp/webhook.php

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
mkdir -p /pfad/zu/osticket/api/whatsapp
cp osticket-plugin/whatsapp/webhook.php /pfad/zu/osticket/api/whatsapp/
```

### Schritt 4: Plugin in osTicket aktivieren

1. Melde dich im osTicket Admin-Panel an
2. Gehe zu **Admin Panel > Manage > Plugins**
3. Klicke auf **Add New Plugin**
4. Waehle **WhatsApp Integration** aus der Liste
5. Klicke auf **Install**
6. Aktiviere das Plugin

### Schritt 5: Plugin konfigurieren

1. Gehe zu **Admin Panel > Manage > Plugins**
2. Klicke auf **WhatsApp Integration**
3. Konfiguriere die Einstellungen (siehe Abschnitt "Plugin-Einstellungen")

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

## Kunden-Befehle

Kunden koennen folgende Befehle via WhatsApp nutzen:

### Ticket schliessen

Sende eines der konfigurierten Stichwoerter:
- `SCHLIESSEN` (Standard-Keyword)
- `Danke`
- `Erledigt`
- `Geloest`
- (weitere konfigurierbar im Admin-Panel)

### Ticket wechseln

Wenn ein Kunde mehrere Tickets hat:
```
Ticket-Wechsel #FFK-123456
```

Der Kunde kann nur zu Tickets wechseln, die seiner Telefonnummer zugeordnet sind.

### Bei Medien-Nachrichten

Wenn ein Kunde ein Bild, Video oder Dokument sendet, erhaelt er automatisch:
- Hinweis dass Dateien per Email eingereicht werden muessen
- mailto-Link mit vorausgefuellter Ticketnummer

## Plugin-Einstellungen

### Basis-Einstellungen

| Einstellung | Beschreibung |
|-------------|--------------|
| Service URL | URL des WhatsApp Service (z.B. `http://127.0.0.1:3000`) |
| API Key | Optional: API-Schluessel fuer sichere Kommunikation |
| Webhook Secret | Authentifizierung fuer eingehende Webhooks |
| Support Email | Email-Adresse fuer Datei-Einreichungen |

### Ticket-Einstellungen

| Einstellung | Beschreibung |
|-------------|--------------|
| Standard Help Topic | Help Topic fuer neue WhatsApp-Tickets |
| Standard Abteilung | Abteilung fuer neue WhatsApp-Tickets |
| Automatische Bestaetigung | Sendet Bestaetigung bei Ticket-Erstellung |
| Bestaetigungsnachricht | Nachricht mit Variablen: `{ticket_number}`, `{name}`, `{close_keyword}`, `{switch_keyword}` |

### Kunden-Befehle

| Einstellung | Beschreibung |
|-------------|--------------|
| Schliessen-Stichwort | Haupt-Keyword zum Schliessen (Standard: `SCHLIESSEN`) |
| Alternative Stichwoerter | Multiline-Liste weiterer Keywords (eins pro Zeile) |
| Ticket-Wechsel Stichwort | Keyword fuer Wechsel (Standard: `Ticket-Wechsel`) |
| Wechsel-Bestaetigung | Nachricht nach erfolgreichem Wechsel |
| Wechsel-Fehler | Nachricht wenn Ticket nicht gefunden |

### Medien-Einstellungen

| Einstellung | Beschreibung |
|-------------|--------------|
| Antwort bei Medien | Nachricht bei Bild/Video/Dokument mit `{ticket_number}`, `{email_link}`, `{support_email}` |

### Benachrichtigungen

| Einstellung | Beschreibung |
|-------------|--------------|
| Bei Agent-Antwort benachrichtigen | Sendet Agent-Antworten an WhatsApp |
| Bei Ticket-Schliessung benachrichtigen | Benachrichtigt bei Ticket-Schliessung |
| Ticket-geschlossen Nachricht | Nachricht mit `{ticket_number}`, `{ticket_subject}` |

## Nachrichtenformat

### Bestaetigungsnachricht (Beispiel)

```
Vielen Dank fuer Ihre Nachricht, Max!

Ihr Ticket wurde erstellt.
Ticket-Nummer: #FFK-123456

*Wichtig:* Sie koennen via WhatsApp immer nur ein Ticket gleichzeitig bearbeiten.
Alle Ihre Nachrichten werden diesem Ticket zugeordnet.

Um zu einem anderen Ticket zu wechseln, senden Sie:
Ticket-Wechsel #[Ihre-Ticketnummer]

Um dieses Ticket zu schliessen, senden Sie:
SCHLIESSEN

Wir melden uns schnellstmoeglich bei Ihnen.
```

### Agent-Antwort (Beispiel)

```
*Antwort zu Ticket #FFK-123456 - Drucker funktioniert nicht*

Vielen Dank fuer Ihre Anfrage. Haben Sie bereits versucht, den Drucker neu zu starten?

_Ihr Support-Team_
```

### Medien-Antwort (Beispiel)

```
Dateien (Bilder, Dokumente, Videos) koennen leider nicht via WhatsApp eingereicht werden.

Bitte senden Sie Ihre Datei per Email an:
support@example.com

Oder klicken Sie hier:
mailto:support@example.com?subject=Anhang%20zu%20Ticket%20%23FFK-123456

Ihre Ticketnummer #FFK-123456 wird automatisch zugeordnet.
```

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

## Datenbank

Das Plugin erstellt automatisch zwei Tabellen:

### `ost_whatsapp_mapping`

Verknuepft Telefonnummern mit Tickets.

| Spalte | Beschreibung |
|--------|--------------|
| phone | Telefonnummer (nur Ziffern) |
| ticket_id | osTicket Ticket-ID |
| ticket_number | Externe Ticket-Nummer |
| status | open/closed/inactive |

### `ost_whatsapp_messages`

Protokolliert alle Nachrichten.

| Spalte | Beschreibung |
|--------|--------------|
| mapping_id | Referenz zur Mapping-Tabelle |
| direction | in/out |
| content | Nachrichteninhalt |
| status | Zustellstatus |

## User-Zuordnung

Die Zuordnung von WhatsApp-Nachrichten zu osTicket-Kunden erfolgt via **Telefonnummer**:

1. Bei eingehender Nachricht wird die Telefonnummer extrahiert
2. osTicket wird nach einem bestehenden User mit dieser Telefonnummer durchsucht
3. **Wenn gefunden**: Der bestehende User wird verwendet
4. **Wenn nicht gefunden**: Ein neuer User wird angelegt mit:
   - Telefonnummer aus WhatsApp
   - Name aus dem WhatsApp-Profil
   - Generierte Email-Adresse (whatsapp+NUMMER@tickets.local)

## Troubleshooting

### QR-Code wird nicht angezeigt

- Stelle sicher, dass `qrcode-terminal` installiert ist
- Pruefe die Terminal-Einstellungen (muss Unicode unterstuetzen)
- Verwende `curl http://127.0.0.1:3000/qr` fuer den QR-Code als Text

### Verbindung bricht ab

- Pruefe die Internetverbindung
- Der Service versucht automatisch, sich wieder zu verbinden
- Bei dauerhaften Problemen: Loesche den `auth_info` Ordner und scanne erneut

### Webhook wird nicht empfangen

- Pruefe die Webhook-URL in der `.env`
- Stelle sicher, dass die URL von aussen erreichbar ist
- Pruefe das Webhook-Secret in beiden Konfigurationen
- Sieh in den osTicket/PHP Logs nach Fehlern

### Tickets werden nicht erstellt

- Pruefe ob das Plugin aktiviert ist
- Pruefe die osTicket System-Logs
- Stelle sicher, dass ein gueltiges Help Topic konfiguriert ist

### Kunde wird nicht erkannt

- Pruefe ob die Telefonnummer im osTicket-Kundenprofil korrekt hinterlegt ist
- Format sollte mit Landesvorwahl sein (z.B. +49...)

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
# Sichere Berechtigungen setzen
chmod 700 whatsapp-service/auth_info
```

## Bekannte Einschraenkungen

1. **Inoffizielle API**: Baileys ist eine inoffizielle WhatsApp-Implementierung
   - Kann bei WhatsApp-Updates brechen
   - Nicht fuer Massen-Messaging geeignet
   - WhatsApp koennte theoretisch die Nummer sperren

2. **Ein Account pro Service**: Nur eine WhatsApp-Nummer pro Installation

3. **Ein aktives Ticket pro Kunde**: Via WhatsApp kann nur ein Ticket gleichzeitig bearbeitet werden (Wechsel moeglich)

4. **Keine Medien-Weiterleitung**: Bilder/Videos werden nicht an osTicket uebertragen, stattdessen Email-Link

5. **24h-Limit entfaellt**: Im Gegensatz zur offiziellen API gibt es kein 24h-Fenster

## Updates

### Service aktualisieren

```bash
cd whatsapp-service
git pull
npm install
pm2 restart whatsapp-osticket
```

### Plugin aktualisieren

```bash
# Plugin-Dateien aktualisieren
cp -r osticket-plugin/whatsapp/* /pfad/zu/osticket/include/plugins/whatsapp/
```

**Hinweis**: Bei Datenbank-Aenderungen muss ggf. das SQL-Schema manuell aktualisiert werden (siehe `install.sql`).

## Mitwirken

Beitraege sind willkommen! Bitte:

1. Forke das Repository
2. Erstelle einen Feature-Branch (`git checkout -b feature/mein-feature`)
3. Committe deine Aenderungen (`git commit -am 'Feature hinzugefuegt'`)
4. Pushe den Branch (`git push origin feature/mein-feature`)
5. Erstelle einen Pull Request

## Lizenz

MIT License

Copyright (c) 2025

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.

## Credits

- [Baileys](https://github.com/WhiskeySockets/Baileys) - WhatsApp Web API fuer Node.js
- [osTicket](https://osticket.com/) - Open Source Support Ticket System
- [Express](https://expressjs.com/) - Node.js Web Framework

## Support

Bei Fragen oder Problemen:
- Erstelle ein [Issue](https://github.com/your-username/osticket-whatsapp/issues)
- Lies die [Dokumentation](./docs/)

## Disclaimer

Dieses Projekt ist nicht mit WhatsApp Inc. oder Meta Platforms Inc. verbunden oder wird von diesen unterstuetzt. Die Nutzung erfolgt auf eigene Gefahr. Stelle sicher, dass du die WhatsApp-Nutzungsbedingungen einhaltst.
