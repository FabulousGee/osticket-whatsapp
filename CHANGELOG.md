# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2025-01-29

### Added
- **Ticket schliessen via WhatsApp**: Kunden koennen Tickets mit konfigurierbaren Stichwoertern schliessen
  - Haupt-Keyword (Standard: "SCHLIESSEN")
  - Alternative Keywords als Multiline-Liste (z.B. "Danke", "Erledigt", "Geloest")
  - Case-insensitive Matching

- **Medien-Handling mit Email-Link**: Bei Bildern/Videos/Dokumenten
  - Automatische Antwort mit Hinweis auf Email-Einreichung
  - mailto-Link mit vorausgefuellter Ticketnummer im Betreff
  - Konfigurierbare Antwort-Nachricht

- **Ticket-Wechsel**: Kunden mit mehreren Tickets koennen wechseln
  - Format: "Ticket-Wechsel #TICKETNUMMER"
  - Pruefung der Berechtigung (nur eigene Tickets)
  - Neuer Mapping-Status "inactive" fuer inaktive Zuordnungen

- **Ein-Ticket-Hinweis**: In der Bestaetigungsnachricht
  - Erklaerung dass nur ein Ticket gleichzeitig bearbeitet werden kann
  - Anleitung fuer Ticket-Wechsel und Schliessen

- **Ticket-Info bei Antworten**: Jede Agent-Antwort zeigt
  - Ticketnummer
  - Ticket-Betreff
  - Format: "*Antwort zu Ticket #XXX - Betreff*"

- **User-Zuordnung via Telefonnummer**
  - Suche nach bestehendem User anhand der Telefonnummer
  - Automatische Zuordnung zu existierenden Kunden
  - Fallback: Neuer User mit WhatsApp-Profilname

### Changed
- Erweiterte Bestaetigungsnachricht mit neuen Variablen: `{close_keyword}`, `{switch_keyword}`
- Ticket-geschlossen Nachricht mit neuer Variable: `{ticket_subject}`
- Datenbank-Schema: Status-Enum erweitert um "inactive"

### New Configuration Options
- `support_email` - Email-Adresse fuer Datei-Einreichungen
- `close_keyword` - Haupt-Stichwort zum Schliessen
- `close_keywords_list` - Alternative Stichwoerter (Multiline)
- `switch_keyword` - Stichwort fuer Ticket-Wechsel
- `switch_success_message` - Bestaetigung nach Wechsel
- `switch_error_message` - Fehlermeldung bei Wechsel
- `media_response_message` - Antwort bei Medien-Nachrichten

## [1.0.0] - 2025-01-29

### Added
- Initial release
- WhatsApp Service (Node.js) with Baileys integration
- osTicket Plugin for bidirectional communication
- Automatic ticket creation from WhatsApp messages
- Agent replies forwarded to WhatsApp
- Phone-to-ticket mapping
- Configurable confirmation messages
- Webhook security with secret token
- PM2 configuration for production deployment
- Comprehensive documentation

### Features
- QR code authentication for WhatsApp
- Session persistence (no re-scan after restart)
- Automatic reconnection on connection loss
- Message logging for audit trail
- Support for text messages
- Placeholder support for images, documents, voice messages

### Security
- Webhook secret verification
- Local-only API binding option
- API key authentication support
