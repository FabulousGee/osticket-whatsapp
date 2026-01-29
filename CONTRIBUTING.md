# Contributing

Vielen Dank fuer dein Interesse, zu diesem Projekt beizutragen!

## Wie du beitragen kannst

### Bugs melden

1. Pruefe zuerst, ob der Bug bereits als Issue existiert
2. Erstelle ein neues Issue mit:
   - Klarer Beschreibung des Problems
   - Schritten zur Reproduktion
   - Erwartetes vs. tatsaechliches Verhalten
   - Node.js, PHP und osTicket Versionen
   - Relevante Log-Ausgaben

### Feature Requests

1. Erstelle ein Issue mit dem Label "enhancement"
2. Beschreibe das gewuenschte Feature
3. Erklaere den Use Case

### Code beitragen

1. Forke das Repository
2. Erstelle einen Feature-Branch:
   ```bash
   git checkout -b feature/mein-feature
   ```
3. Mache deine Aenderungen
4. Teste gruendlich
5. Committe mit aussagekraeftiger Message:
   ```bash
   git commit -m "feat: Beschreibung der Aenderung"
   ```
6. Pushe deinen Branch:
   ```bash
   git push origin feature/mein-feature
   ```
7. Erstelle einen Pull Request

## Commit Convention

Wir verwenden [Conventional Commits](https://www.conventionalcommits.org/):

- `feat:` - Neues Feature
- `fix:` - Bugfix
- `docs:` - Dokumentation
- `style:` - Formatierung (kein Code-Change)
- `refactor:` - Code-Refactoring
- `test:` - Tests
- `chore:` - Maintenance

## Code Style

### JavaScript (Node.js)
- ES6+ Syntax
- Async/await fuer asynchronen Code
- JSDoc Kommentare fuer Funktionen
- Keine Semicolons (optional, aber konsistent)

### PHP
- PSR-12 Coding Standard
- PHPDoc Kommentare
- osTicket Coding Conventions beachten
- Verwendung von prepared statements fuer DB-Queries

## Projektstruktur

```
osticket-whatsapp/
|
|-- whatsapp-service/           # Node.js WhatsApp Service
|   |-- src/
|   |   |-- index.js            # Einstiegspunkt
|   |   |-- whatsapp.js         # Baileys Client
|   |   |-- api.js              # REST API
|   |   |-- webhook.js          # Webhook-Sender
|   |-- package.json
|   |-- ecosystem.config.js     # PM2 Config
|
|-- osticket-plugin/            # osTicket Plugin
|   |-- whatsapp/
|       |-- plugin.php          # Plugin-Definition
|       |-- config.php          # Admin-Einstellungen
|       |-- class.WhatsAppPlugin.php
|       |-- class.WhatsAppApi.php
|       |-- class.WhatsAppMapping.php
|       |-- class.WhatsAppWebhook.php
|       |-- webhook.php         # Webhook-Endpunkt
|       |-- install.sql         # DB-Schema
```

## Pull Request Checkliste

- [ ] Code folgt dem Style Guide
- [ ] Alle Tests bestehen (manuell getestet)
- [ ] Dokumentation aktualisiert (README.md falls noetig)
- [ ] CHANGELOG.md aktualisiert
- [ ] Keine sensiblen Daten committed
- [ ] Neue Konfigurationsoptionen dokumentiert

## Entwicklungsumgebung

### Setup
```bash
# Repository klonen
git clone https://github.com/your-username/osticket-whatsapp.git

# Dependencies installieren
cd whatsapp-service
npm install

# Konfiguration erstellen
cp .env.example .env
# .env bearbeiten mit Test-Webhook-URL

# Entwicklungsumgebung starten
npm run dev
```

### Testen

#### WhatsApp Service testen
```bash
# Service mit Watch-Mode starten
npm run dev

# Status pruefen
curl http://127.0.0.1:3000/status

# Nachricht senden (nach QR-Scan)
curl -X POST http://127.0.0.1:3000/send \
  -H "Content-Type: application/json" \
  -d '{"phone":"49123456789","message":"Test"}'
```

#### Plugin testen
1. Plugin in osTicket installieren
2. Webhook-URL auf lokalen Service zeigen
3. Test-Nachrichten von echtem WhatsApp senden
4. Logs pruefen: osTicket System Logs + Node.js Console

### Debugging

#### Node.js Service
```bash
# Mit Debug-Logging starten
LOG_LEVEL=debug npm start
```

#### PHP Plugin
```php
// In Plugin-Code temporaer:
error_log('WhatsApp Plugin Debug: ' . print_r($variable, true));
```

## Wichtige Hinweise

### Datenbank-Aenderungen
- Bei Schema-Aenderungen: `install.sql` aktualisieren
- Migration fuer bestehende Installationen dokumentieren
- Enum-Erweiterungen sind abwaertskompatibel

### Neue Konfigurationsoptionen
- In `config.php` hinzufuegen mit sinnvollem Default
- In README.md dokumentieren
- In CHANGELOG.md erwaehnen

### Baileys Updates
- Baileys ist inoffiziell und kann brechen
- Bei Problemen: Check GitHub Issues von Baileys
- Package-Version in package.json pinnen wenn stabil

## Fragen?

Erstelle ein Issue mit dem Label "question" oder kontaktiere uns direkt.

Danke fuer deinen Beitrag!
