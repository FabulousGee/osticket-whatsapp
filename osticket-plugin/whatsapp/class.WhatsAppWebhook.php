<?php
/**
 * WhatsApp Webhook Handler
 *
 * Verarbeitet eingehende WhatsApp-Nachrichten und erstellt/aktualisiert Tickets.
 * Diese Klasse ist der Haupteinstiegspunkt für alle eingehenden Webhook-Requests
 * vom WhatsApp Node.js Service.
 *
 * @package WhatsAppPlugin
 * @version 1.0.0
 */

require_once __DIR__ . '/class.WhatsAppConstants.php';
require_once __DIR__ . '/class.WhatsAppUtils.php';

class WhatsAppWebhook
{
    /**
     * Plugin-Konfiguration
     *
     * @var PluginConfig
     */
    private $config;

    /**
     * WhatsApp API Client
     *
     * @var WhatsAppApi
     */
    private $api;

    /**
     * Konstruktor
     *
     * Initialisiert den Webhook-Handler mit der Plugin-Konfiguration
     * und erstellt eine API-Client-Instanz.
     *
     * @param PluginConfig $config Plugin-Konfigurationsobjekt
     */
    public function __construct($config)
    {
        $this->config = $config;
        $this->api = new WhatsAppApi(
            $config->get('service_url'),
            $config->get('api_key')
        );
    }

    /**
     * Ersetzt Variablen in einem Template-String
     *
     * Delegiert an die zentrale WhatsAppUtils-Klasse.
     *
     * @param string $template Template mit {variable} Platzhaltern
     * @param array $contextVars Kontext-spezifische Variablen
     * @return string Template mit ersetzten Variablen
     */
    private function replaceVariables($template, $contextVars = [])
    {
        return WhatsAppUtils::replaceVariables($template, $this->config, $contextVars);
    }

    /**
     * Verarbeitet eingehende Webhook-Requests
     *
     * Hauptmethode die alle eingehenden Webhook-Events verarbeitet.
     * Unterstützte Events:
     * - message.received: Eingehende WhatsApp-Nachricht
     * - maintenance.cleanup: Verwaiste Mappings bereinigen
     *
     * @param array $payload Webhook-Payload mit 'event' und 'data'
     * @return array Response-Array mit 'success' und weiteren Feldern
     */
    public function handle($payload)
    {
        if (!isset($payload['event'])) {
            return ['success' => false, 'error' => 'Missing event'];
        }

        // Wartungs-Events verarbeiten
        if ($payload['event'] === 'maintenance.cleanup') {
            return $this->handleCleanup();
        }

        if ($payload['event'] !== 'message.received') {
            return ['success' => false, 'error' => 'Invalid event'];
        }

        $data = $payload['data'] ?? [];

        if (empty($data['phone'])) {
            return ['success' => false, 'error' => 'Missing phone number'];
        }

        $phone = $data['phone'];
        $text = $data['text'] ?? '';
        $type = $data['type'] ?? 'text';
        $name = $data['name'] ?? 'WhatsApp User';

        // Name bereinigen - "Unknown" oder leere Namen durch Fallback ersetzen
        $name = $this->sanitizeName($name, $phone);

        // Bestehendes Mapping für diese Telefonnummer suchen
        $mapping = WhatsAppMapping::findOpenByPhone($phone);

        // 1. Medien-Nachricht prüfen
        if ($this->isMediaMessage($type)) {
            return $this->handleMediaMessage($data, $mapping);
        }

        // 2. Schließen-Keyword prüfen - auch ohne Mapping (um kein neues Ticket zu erstellen)
        if ($this->isCloseKeyword($text)) {
            if ($mapping) {
                return $this->closeTicketByCustomer($mapping, $phone);
            } else {
                // Kein offenes Ticket - Hinweis senden, KEIN neues Ticket erstellen
                $template = $this->config->get('list_no_tickets_message')
                    ?: "Sie haben aktuell kein offenes Ticket.\n\nSenden Sie uns eine Nachricht um ein neues Ticket zu erstellen.";
                $message = $this->replaceVariables($template);
                $this->api->sendMessage($phone, $message);
                return [
                    'success' => true,
                    'action' => 'no_open_ticket',
                    'message' => 'Close keyword received but no open ticket',
                ];
            }
        }

        // 3. Neues-Ticket Keyword prüfen (Mapping aufheben ohne zu schließen)
        if ($this->isNewKeyword($text)) {
            if ($mapping) {
                return $this->unlinkMapping($mapping, $phone, $name);
            } else {
                // Kein offenes Ticket - Hinweis senden
                $template = $this->config->get('list_no_tickets_message')
                    ?: "Sie haben aktuell kein offenes Ticket.\n\nSenden Sie uns eine Nachricht um ein neues Ticket zu erstellen.";
                $message = $this->replaceVariables($template);
                $this->api->sendMessage($phone, $message);
                return [
                    'success' => true,
                    'action' => 'no_active_mapping',
                    'message' => 'New keyword received but no active mapping',
                ];
            }
        }

        // 4. Ticket-Liste Keyword prüfen
        if ($this->isListKeyword($text)) {
            return $this->listOpenTickets($phone);
        }

        // 5. Ticket-Wechsel-Befehl prüfen
        $switchTicketNumber = $this->parseTicketSwitch($text);
        if ($switchTicketNumber) {
            return $this->switchTicket($phone, $switchTicketNumber, $name);
        }

        // 6. Steuerwort am Anfang erkannt aber ungültiges Format?
        // Dies verhindert dass bei "WECHSEL 'FFK-123" ein neues Ticket erstellt wird
        $controlWord = $this->startsWithControlWord($text);
        if ($controlWord) {
            return $this->handleInvalidControlWord($text, $controlWord, $phone);
        }

        // 8. Normale Nachrichtenverarbeitung
        if (empty($text)) {
            return ['success' => false, 'error' => 'Empty message'];
        }

        // 9. Prüfen ob es sich um ein Signalwort handelt das kein Ticket erstellen soll
        if (!$mapping && $this->isSignalWord($text)) {
            // Signalwort ohne offenes Ticket - ignorieren oder Hinweis senden
            $template = $this->config->get('list_no_tickets_message')
                ?: "Sie haben aktuell kein offenes Ticket.\n\nSenden Sie uns eine Nachricht um ein neues Ticket zu erstellen.";
            $message = $this->replaceVariables($template);
            $this->api->sendMessage($phone, $message);
            return [
                'success' => true,
                'action' => 'signal_word_ignored',
                'message' => 'Signal word received but no open ticket',
            ];
        }

        // Bereinigten Namen in data übernehmen
        $data['name'] = $name;

        if ($mapping) {
            // Nachricht zu bestehendem Ticket hinzufügen
            return $this->addMessageToTicket($mapping, $data);
        } else {
            // Neues Ticket erstellen
            return $this->createTicket($data);
        }
    }

    /**
     * Prüft ob der Nachrichtentyp ein Medientyp ist
     *
     * Medien-Nachrichten (Bilder, Videos, etc.) können nicht direkt
     * verarbeitet werden und erfordern einen Email-Upload.
     *
     * @param string $type Nachrichtentyp aus WhatsApp
     * @return bool True wenn Medientyp
     */
    private function isMediaMessage($type)
    {
        $mediaTypes = ['image', 'video', 'audio', 'document', 'sticker'];
        return in_array($type, $mediaTypes);
    }

    /**
     * Verarbeitet Medien-Nachrichten
     *
     * Da Medien nicht direkt verarbeitet werden können, wird dem Kunden
     * ein Email-Link gesendet über den er Dateien einreichen kann.
     *
     * @param array $data Nachrichtendaten (phone, type, messageId)
     * @param array|null $mapping Bestehendes Mapping oder null
     * @return array Response mit action 'media_response_sent'
     */
    private function handleMediaMessage($data, $mapping)
    {
        $phone = $data['phone'];
        $ticketNumber = $mapping ? $mapping['ticket_number'] : 'NEU';

        // Email-Link erstellen
        $emailLink = $this->buildEmailLink($ticketNumber);

        // Nachrichtentemplate laden und Variablen ersetzen
        $template = $this->config->get('media_response_message')
            ?: "Dateien können nicht via WhatsApp eingereicht werden. Bitte senden Sie an: {support_email}";

        $message = $this->replaceVariables($template, [
            'ticket_number' => $ticketNumber,
            'email_link' => $emailLink,
        ]);

        // Antwort senden
        $this->api->sendMessage($phone, $message);

        // Versuch protokollieren falls Mapping existiert
        if ($mapping) {
            WhatsAppMapping::logMessage($mapping['id'], [
                'message_id' => $data['messageId'] ?? null,
                'direction' => 'in',
                'content' => '[Medien-Nachricht: ' . ($data['type'] ?? 'unknown') . ']',
                'status' => 'media_rejected',
            ]);
        }

        return [
            'success' => true,
            'action' => 'media_response_sent',
            'ticket_number' => $ticketNumber,
        ];
    }

    /**
     * Erstellt einen mailto-Link mit vorausgefüllter Ticketnummer
     *
     * @param string $ticketNumber Ticketnummer für den Betreff
     * @return string Vollständiger mailto-Link
     */
    private function buildEmailLink($ticketNumber)
    {
        $supportEmail = $this->config->get('support_email') ?: 'support@example.com';

        $subject = "Anhang zu Ticket #{$ticketNumber}";
        $body = "Ticketnummer: #{$ticketNumber}\n\nBitte haengen Sie Ihre Datei an diese Email an.";

        return 'mailto:' . $supportEmail
            . '?subject=' . rawurlencode($subject)
            . '&body=' . rawurlencode($body);
    }

    /**
     * Prüft ob der Text dem Schließen-Keyword entspricht
     *
     * Vergleicht den Text (case-insensitive) NUR mit dem konfigurierten
     * Haupt-Keyword. Signalwörter werden separat in isSignalWord() behandelt.
     *
     * @param string $text Nachrichtentext
     * @return bool True wenn Schließen-Keyword erkannt
     */
    private function isCloseKeyword($text)
    {
        $text = trim($text);
        if (empty($text)) {
            return false;
        }

        // NUR das Haupt-Keyword prüfen
        $closeKeyword = $this->config->get('close_keyword') ?: 'SCHLIESSEN';
        return strcasecmp($text, $closeKeyword) === 0;
    }

    /**
     * Prüft ob der Text dem Ticket-Liste-Keyword entspricht
     *
     * @param string $text Nachrichtentext
     * @return bool True wenn Liste-Keyword erkannt
     */
    private function isListKeyword($text)
    {
        $text = trim($text);
        if (empty($text)) {
            return false;
        }

        $listKeyword = $this->config->get('list_keyword') ?: 'OFFEN';
        return strcasecmp($text, $listKeyword) === 0;
    }

    /**
     * Prüft ob der Text dem Neues-Ticket-Keyword entspricht
     *
     * Das NEU-Keyword hebt die aktuelle Ticket-Verknüpfung auf,
     * ohne das Ticket zu schließen.
     *
     * @param string $text Nachrichtentext
     * @return bool True wenn Neues-Ticket-Keyword erkannt
     */
    private function isNewKeyword($text)
    {
        $text = trim($text);
        if (empty($text)) {
            return false;
        }

        $newKeyword = $this->config->get('new_keyword') ?: 'NEU';
        return strcasecmp($text, $newKeyword) === 0;
    }

    /**
     * Hebt die Ticket-Verknüpfung auf ohne das Ticket zu schließen
     *
     * Ermöglicht dem Kunden ein neues Ticket zu erstellen während
     * das aktuelle Ticket offen bleibt.
     *
     * @param array $mapping Aktives Mapping
     * @param string $phone Telefonnummer des Kunden
     * @param string $name Name des Kunden
     * @return array Response mit action 'mapping_unlinked'
     */
    private function unlinkMapping($mapping, $phone, $name)
    {
        $ticketNumber = $mapping['ticket_number'];

        // Mapping als "unlinked" markieren (Ticket bleibt offen)
        WhatsAppMapping::updateStatus($mapping['id'], 'unlinked');

        // Bestätigungsnachricht senden
        $template = $this->config->get('new_ticket_message')
            ?: "Ihre Verbindung zu Ticket #{ticket_number} wurde aufgehoben.\n\nSenden Sie jetzt Ihre nächste Nachricht um ein neues Ticket zu erstellen.";

        $message = $this->replaceVariables($template, [
            'ticket_number' => $ticketNumber,
            'name' => $name,
        ]);

        $this->api->sendMessage($phone, $message);

        // Aktion protokollieren
        WhatsAppMapping::logMessage($mapping['id'], [
            'direction' => 'in',
            'content' => '[Mapping aufgehoben durch Kunde - NEU Keyword]',
            'status' => 'unlinked',
        ]);

        return [
            'success' => true,
            'action' => 'mapping_unlinked',
            'ticket_number' => $ticketNumber,
        ];
    }

    /**
     * Listet alle offenen Tickets eines Users auf
     *
     * Sucht alle offenen Tickets die zu dieser Telefonnummer gehoeren,
     * inklusive Tickets die per Email/Web erstellt wurden.
     *
     * @param string $phone Telefonnummer des Users
     * @return array Response mit action 'tickets_listed'
     */
    private function listOpenTickets($phone)
    {
        // Alle offenen Tickets fuer diese Telefonnummer finden
        // (nicht nur WhatsApp-Mappings, sondern auch Email/Web-Tickets)
        $tickets = $this->findAllOpenTicketsForPhone($phone);

        if (empty($tickets)) {
            // Keine offenen Tickets
            $template = $this->config->get('list_no_tickets_message')
                ?: "Sie haben aktuell keine offenen Tickets.\n\nSenden Sie uns eine Nachricht um ein neues Ticket zu erstellen.";
            $message = $this->replaceVariables($template);
            $this->api->sendMessage($phone, $message);

            return [
                'success' => true,
                'action' => 'no_tickets',
                'count' => 0,
            ];
        }

        // Ticket-Liste formatieren (sortiert nach Erstelldatum, neuestes unten)
        $ticketList = [];
        foreach ($tickets as $ticketData) {
            $ticketList[] = "#{$ticketData['number']} - {$ticketData['subject']}";
        }

        // Liste zusammenbauen
        $listText = implode("\n", $ticketList);
        $count = count($tickets);

        // Nachricht aus Template erstellen
        $template = $this->config->get('list_tickets_message')
            ?: "Sie haben {count} offene Ticket(s):\n\n{ticket_list}\n\nUm zu einem Ticket zu wechseln, senden Sie:\n{switch_keyword} #[Ticketnummer]";

        $message = $this->replaceVariables($template, [
            'ticket_list' => $listText,
            'count' => $count,
        ]);

        $this->api->sendMessage($phone, $message);

        return [
            'success' => true,
            'action' => 'tickets_listed',
            'count' => $count,
        ];
    }

    /**
     * Findet alle offenen Tickets fuer eine Telefonnummer
     *
     * Sucht in osTicket nach allen offenen Tickets wo der User
     * diese Telefonnummer hat (WhatsApp, Email, Web-Tickets).
     *
     * @param string $phone Telefonnummer
     * @return array Array von Ticket-Daten [id, number, subject, created]
     */
    private function findAllOpenTicketsForPhone($phone)
    {
        $cleanPhone = preg_replace('/\D/', '', $phone);
        $prefix = TABLE_PREFIX;
        $tickets = [];
        $foundTicketIds = [];

        // 1. Tickets aus WhatsApp-Mappings (mit status='open')
        $mappings = WhatsAppMapping::findAllOpenByPhone($phone);
        foreach ($mappings as $mapping) {
            $ticket = Ticket::lookup($mapping['ticket_id']);
            if ($ticket && !$ticket->isClosed()) {
                $foundTicketIds[$ticket->getId()] = true;
                $tickets[] = [
                    'id' => $ticket->getId(),
                    'number' => $ticket->getNumber(),
                    'subject' => $ticket->getSubject(),
                    'created' => $ticket->getCreateDate(),
                ];
            }
        }

        // 2. Tickets von Usern mit dieser Telefonnummer (Email/Web-Tickets)
        // Suche User mit passender Telefonnummer
        $sql = "SELECT DISTINCT u.id as user_id
                FROM `{$prefix}user` u
                JOIN `{$prefix}user__cdata` c ON u.id = c.user_id
                WHERE c.phone LIKE " . db_input('%' . $cleanPhone . '%');

        $userResult = db_query($sql);
        $userIds = [];
        while ($row = db_fetch_array($userResult)) {
            $userIds[] = (int) $row['user_id'];
        }

        // Auch User mit generierter WhatsApp-Email finden
        $whatsappEmail = "whatsapp+{$cleanPhone}@tickets.local";
        $emailUser = User::lookupByEmail($whatsappEmail);
        if ($emailUser) {
            $userIds[] = $emailUser->getId();
        }

        $userIds = array_unique($userIds);

        // Finde alle offenen Tickets dieser User
        if (!empty($userIds)) {
            // SQL Injection Prevention: Sicherstellen dass alle IDs Integers sind
            $userIdList = WhatsAppUtils::idsToSqlList($userIds);

            // Offene Status-IDs finden (state != 'closed')
            $sql = "SELECT t.ticket_id, t.number, cd.subject, t.created
                    FROM `{$prefix}ticket` t
                    JOIN `{$prefix}ticket__cdata` cd ON t.ticket_id = cd.ticket_id
                    JOIN `{$prefix}ticket_status` ts ON t.status_id = ts.id
                    WHERE t.user_id IN ({$userIdList})
                    AND ts.state != 'closed'
                    ORDER BY t.created ASC";

            $ticketResult = db_query($sql);
            while ($row = db_fetch_array($ticketResult)) {
                $ticketId = (int) $row['ticket_id'];
                // Duplikate vermeiden (falls schon via Mapping gefunden)
                if (!isset($foundTicketIds[$ticketId])) {
                    $foundTicketIds[$ticketId] = true;
                    $tickets[] = [
                        'id' => $ticketId,
                        'number' => $row['number'],
                        'subject' => $row['subject'] ?: 'Kein Betreff',
                        'created' => $row['created'],
                    ];
                }
            }
        }

        // Nach Erstelldatum sortieren (aeltestes zuerst, neuestes unten)
        usort($tickets, function($a, $b) {
            return strcmp($a['created'], $b['created']);
        });

        return $tickets;
    }

    /**
     * Prueft ob eine Telefonnummer Zugriff auf ein Ticket hat
     *
     * Prueft verschiedene Kriterien:
     * - Existierendes WhatsApp-Mapping mit gleicher Telefonnummer
     * - Ticket-Eigentuemer hat gleiche Telefonnummer
     * - User mit dieser Telefonnummer ist Eigentuemer des Tickets
     *
     * @param Ticket $ticket Das zu pruefende Ticket
     * @param string $phone Telefonnummer des Anfragenden
     * @return bool True wenn Zugriff erlaubt
     */
    private function verifyTicketAccess($ticket, $phone)
    {
        $cleanPhone = preg_replace('/\D/', '', $phone);
        $prefix = TABLE_PREFIX;

        // 1. Pruefen ob WhatsApp-Mapping mit gleicher Telefonnummer existiert
        $existingMapping = WhatsAppMapping::findByTicketId($ticket->getId());
        if ($existingMapping) {
            $mappingPhone = preg_replace('/\D/', '', $existingMapping['phone']);
            if ($mappingPhone === $cleanPhone) {
                return true;
            }
        }

        // 2. Pruefen ob Ticket-Eigentuemer diese Telefonnummer hat
        $user = $ticket->getUser();
        if ($user) {
            $userPhone = preg_replace('/\D/', '', $user->getPhone() ?: '');
            // Flexibler Vergleich (Telefonnummer kann Teil sein)
            if ($userPhone && (
                strpos($userPhone, $cleanPhone) !== false ||
                strpos($cleanPhone, $userPhone) !== false
            )) {
                return true;
            }

            // Pruefen ob User-Email die WhatsApp-generierte Email ist
            $whatsappEmail = "whatsapp+{$cleanPhone}@tickets.local";
            if (strcasecmp($user->getEmail(), $whatsappEmail) === 0) {
                return true;
            }
        }

        // 3. Pruefen ob ein User mit dieser Telefonnummer das Ticket besitzt
        $sql = "SELECT u.id
                FROM `{$prefix}user` u
                JOIN `{$prefix}user__cdata` c ON u.id = c.user_id
                JOIN `{$prefix}ticket` t ON t.user_id = u.id
                WHERE t.ticket_id = " . (int) $ticket->getId() . "
                AND c.phone LIKE " . db_input('%' . $cleanPhone . '%') . "
                LIMIT 1";

        $result = db_query($sql);
        if (db_fetch_array($result)) {
            return true;
        }

        return false;
    }

    /**
     * Bereinigt den Kontaktnamen
     *
     * Ersetzt ungültige Namen wie "Unknown", leere Strings oder
     * nur Leerzeichen durch einen sinnvollen Fallback.
     *
     * @param string $name Original-Name aus WhatsApp
     * @param string $phone Telefonnummer als Fallback
     * @return string Bereinigter Name
     */
    private function sanitizeName($name, $phone)
    {
        // Liste von ungültigen Namen
        $invalidNames = [
            'unknown',
            'unbekannt',
            '',
            'null',
            'undefined',
        ];

        $cleanName = trim($name);

        // Prüfen ob Name ungültig ist
        if (empty($cleanName) || in_array(strtolower($cleanName), $invalidNames)) {
            // Fallback: Formatierte Telefonnummer verwenden
            $cleanPhone = preg_replace('/\D/', '', $phone);
            if (strlen($cleanPhone) > 6) {
                // Telefonnummer teilweise maskieren für Datenschutz
                return 'WhatsApp +' . substr($cleanPhone, 0, 2) . '***' . substr($cleanPhone, -4);
            }
            return 'WhatsApp User';
        }

        return $cleanName;
    }

    /**
     * Prüft ob der Text ein Signalwort ist das kein Ticket erstellen soll
     *
     * Signalwörter werden aus der Plugin-Konfiguration geladen.
     * Diese sind kurze Antworten die kein neues Ticket erfordern.
     *
     * @param string $text Nachrichtentext
     * @return bool True wenn Signalwort
     */
    private function isSignalWord($text)
    {
        $text = trim($text);

        // Zu kurze Nachrichten (1-2 Zeichen) könnten Tippfehler sein
        if (strlen($text) <= 2) {
            return true;
        }

        // Signalwörter aus Config laden
        $signalWordsList = $this->config->get('signal_words_list') ?: '';
        $signalWords = array_filter(array_map('trim', explode("\n", $signalWordsList)));

        $lowerText = strtolower($text);

        foreach ($signalWords as $word) {
            if (strtolower($word) === $lowerText) {
                return true;
            }
        }

        // Auch Steuerwörter als Signalwörter behandeln
        if ($this->isCloseKeyword($text)) {
            return true;
        }
        if ($this->isNewKeyword($text)) {
            return true;
        }
        if ($this->isListKeyword($text)) {
            return true;
        }

        return false;
    }

    /**
     * Prüft ob der Text mit einem Steuerwort beginnt
     *
     * Erkennt alle konfigurierten Steuerwörter am Anfang der Nachricht.
     * Dies ermöglicht die Erkennung von ungültigen Formaten.
     *
     * @param string $text Nachrichtentext
     * @return array|false Array mit 'type' und 'keyword' oder false
     */
    private function startsWithControlWord($text)
    {
        $text = trim($text);
        $lowerText = strtolower($text);

        $keywords = [
            'close' => strtolower($this->config->get('close_keyword') ?: 'SCHLIESSEN'),
            'switch' => strtolower($this->config->get('switch_keyword') ?: 'Ticket-Wechsel'),
            'new' => strtolower($this->config->get('new_keyword') ?: 'NEU'),
            'list' => strtolower($this->config->get('list_keyword') ?: 'OFFEN'),
        ];

        foreach ($keywords as $type => $keyword) {
            if (strpos($lowerText, $keyword) === 0) {
                return ['type' => $type, 'keyword' => $keyword];
            }
        }

        return false;
    }

    /**
     * Behandelt ungültige Steuerwort-Formate
     *
     * Wird aufgerufen wenn ein Steuerwort am Anfang erkannt wurde,
     * aber das Format ungültig ist. Sendet eine Fehlermeldung.
     *
     * @param string $text Original-Nachrichtentext
     * @param array $controlWord Erkanntes Steuerwort ['type', 'keyword']
     * @param string $phone Telefonnummer
     * @return array Response-Array
     */
    private function handleInvalidControlWord($text, $controlWord, $phone)
    {
        $expectedFormats = [
            'close' => $this->config->get('close_keyword') ?: 'SCHLIESSEN',
            'switch' => ($this->config->get('switch_keyword') ?: 'Ticket-Wechsel') . ' #[Ticketnummer]',
            'new' => $this->config->get('new_keyword') ?: 'NEU',
            'list' => $this->config->get('list_keyword') ?: 'OFFEN',
        ];

        $template = $this->config->get('control_word_error_message')
            ?: "Ungültiges Format für '{keyword}'.\n\nErwartetes Format: {expected_format}";

        $message = $this->replaceVariables($template, [
            'keyword' => $controlWord['keyword'],
            'expected_format' => $expectedFormats[$controlWord['type']] ?? '',
        ]);

        $this->api->sendMessage($phone, $message);

        return [
            'success' => true,
            'action' => 'control_word_format_error',
            'keyword' => $controlWord['keyword'],
            'original_text' => $text,
        ];
    }

    /**
     * Schließt ein Ticket auf Kundenwunsch
     *
     * Wird aufgerufen wenn der Kunde das Schließen-Keyword sendet.
     * Setzt den Ticket-Status auf 'closed' und aktualisiert das Mapping.
     *
     * @param array $mapping Aktives Mapping für den Kunden
     * @param string $phone Telefonnummer des Kunden
     * @return array Response mit action 'ticket_closed'
     */
    private function closeTicketByCustomer($mapping, $phone)
    {
        $ticketId = $mapping['ticket_id'];
        $ticketNumber = $mapping['ticket_number'];

        // Ticket laden
        $ticket = Ticket::lookup($ticketId);
        if (!$ticket) {
            WhatsAppMapping::updateStatus($mapping['id'], 'closed');
            $template = $this->config->get('ticket_not_found_message')
                ?: "Ticket #{ticket_number} wurde nicht gefunden.\n\nMöglicherweise wurde es bereits gelöscht.";
            $message = $this->replaceVariables($template, ['ticket_number' => $ticketNumber]);
            $this->api->sendMessage($phone, $message);
            return ['success' => false, 'error' => 'Ticket not found'];
        }

        // Prüfen ob Ticket bereits geschlossen ist
        if ($ticket->isClosed()) {
            WhatsAppMapping::updateStatus($mapping['id'], 'closed');
            $template = $this->config->get('ticket_already_closed_message')
                ?: "Ticket #{ticket_number} ist bereits geschlossen.\n\nSenden Sie eine neue Nachricht um ein neues Ticket zu erstellen.";
            $message = $this->replaceVariables($template, ['ticket_number' => $ticketNumber]);
            $this->api->sendMessage($phone, $message);
            return [
                'success' => true,
                'action' => 'ticket_already_closed',
                'ticket_number' => $ticketNumber,
            ];
        }

        $ticketSubject = $ticket->getSubject();
        $closeSuccessful = false;
        $closeError = null;

        // Ticket schließen
        try {
            // Closed-Status suchen - filter mit limit um mehrere Status zu handhaben
            $closedStatus = null;
            $statuses = TicketStatus::objects()->filter(['state' => 'closed'])->limit(1);
            foreach ($statuses as $status) {
                $closedStatus = $status;
                break;
            }

            if ($closedStatus) {
                $errors = [];
                $result = $ticket->setStatus($closedStatus, false, $errors);

                if ($result) {
                    $closeSuccessful = true;
                } else {
                    $closeError = !empty($errors) ? implode(', ', $errors) : 'Status konnte nicht gesetzt werden';
                    error_log('WhatsApp Plugin: Failed to set closed status - ' . $closeError);
                }
            } else {
                $closeError = 'Kein Closed-Status im System gefunden';
                error_log('WhatsApp Plugin: No closed status found in system');
            }
        } catch (Exception $e) {
            $closeError = $e->getMessage();
            error_log('WhatsApp Plugin: Exception closing ticket - ' . $e->getMessage());
        }

        // Nur bei Erfolg: Mapping aktualisieren und Bestätigung senden
        if ($closeSuccessful) {
            WhatsAppMapping::updateStatus($mapping['id'], 'closed');

            // Bestätigung senden
            $template = $this->config->get('closed_ticket_message')
                ?: "Ihr Ticket #{ticket_number} wurde geschlossen.";
            $message = $this->replaceVariables($template, [
                'ticket_number' => $ticketNumber,
                'ticket_subject' => $ticketSubject,
            ]);

            $this->api->sendMessage($phone, $message);

            // Aktion protokollieren
            WhatsAppMapping::logMessage($mapping['id'], [
                'direction' => 'in',
                'content' => '[Ticket geschlossen durch Kunde]',
                'status' => 'closed',
            ]);

            return [
                'success' => true,
                'action' => 'ticket_closed',
                'ticket_number' => $ticketNumber,
            ];
        } else {
            // Fehler beim Schließen - Keyword als Notiz hinzufügen
            $closeKeyword = $this->config->get('close_keyword') ?: 'SCHLIESSEN';
            $noteErrors = [];
            $ticket->postNote([
                'title' => 'WhatsApp: Kunde wollte Ticket schließen',
                'note' => "Der Kunde hat per WhatsApp versucht das Ticket zu schließen (Keyword: \"{$closeKeyword}\").\n\n"
                        . "Das automatische Schließen ist fehlgeschlagen.\n"
                        . "Fehler: " . ($closeError ?: 'Unbekannter Fehler'),
            ], $noteErrors, 'WhatsApp', false);

            // Nachricht an Kunden
            $template = $this->config->get('close_failed_message')
                ?: "Das Ticket #{ticket_number} konnte leider nicht automatisch geschlossen werden.\n\nIhr Anliegen wurde als Notiz hinterlegt. Ein Mitarbeiter wird sich darum kümmern.";
            $message = $this->replaceVariables($template, ['ticket_number' => $ticketNumber]);
            $this->api->sendMessage($phone, $message);

            // Fehler protokollieren
            WhatsAppMapping::logMessage($mapping['id'], [
                'direction' => 'in',
                'content' => '[Ticket-Schließung fehlgeschlagen: ' . ($closeError ?: 'Unbekannter Fehler') . '] - Notiz hinzugefügt',
                'status' => 'error',
            ]);

            return [
                'success' => false,
                'action' => 'ticket_close_failed',
                'ticket_number' => $ticketNumber,
                'error' => $closeError,
            ];
        }
    }

    /**
     * Parst einen Ticket-Wechsel-Befehl
     *
     * Erkennt Befehle im Format "Ticket-Wechsel #123456" oder "Ticket-Wechsel 123456"
     *
     * @param string $text Nachrichtentext
     * @return string|null Ticketnummer oder null wenn kein Wechsel-Befehl
     */
    private function parseTicketSwitch($text)
    {
        $text = trim($text);
        if (empty($text)) {
            return null;
        }

        $switchKeyword = $this->config->get('switch_keyword') ?: 'Ticket-Wechsel';

        // Pattern: "Ticket-Wechsel #123456" oder "Ticket-Wechsel 123456"
        $pattern = '/^' . preg_quote($switchKeyword, '/') . '\s*#?\s*([A-Za-z0-9-]+)$/i';

        if (preg_match($pattern, $text, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * Wechselt das aktive Ticket für eine Telefonnummer
     *
     * Ermöglicht Kunden zwischen mehreren eigenen Tickets zu wechseln.
     * Prüft ob der Kunde Zugriff auf das Ziel-Ticket hat.
     *
     * @param string $phone Telefonnummer des Kunden
     * @param string $ticketNumber Nummer des Ziel-Tickets
     * @param string $name Name des Kunden
     * @return array Response mit action 'ticket_switched' oder Fehler
     */
    private function switchTicket($phone, $ticketNumber, $name)
    {
        // Ticket suchen
        $ticket = Ticket::lookupByNumber($ticketNumber);

        if (!$ticket) {
            // Fehlermeldung senden
            $template = $this->config->get('switch_error_message')
                ?: "Ticket #{ticket_number} wurde nicht gefunden oder gehört nicht zu Ihnen.";
            $message = $this->replaceVariables($template, ['ticket_number' => $ticketNumber]);
            $this->api->sendMessage($phone, $message);

            return [
                'success' => false,
                'error' => 'Ticket not found',
                'ticket_number' => $ticketNumber,
            ];
        }

        // Zugangsberechtigung prüfen
        $hasAccess = $this->verifyTicketAccess($ticket, $phone);

        if (!$hasAccess) {
            // Fehlermeldung senden
            $template = $this->config->get('switch_error_message')
                ?: "Ticket #{ticket_number} wurde nicht gefunden oder gehört nicht zu Ihnen.";
            $message = $this->replaceVariables($template, ['ticket_number' => $ticketNumber]);
            $this->api->sendMessage($phone, $message);

            return [
                'success' => false,
                'error' => 'No access to ticket',
                'ticket_number' => $ticketNumber,
            ];
        }

        // Prüfen ob Ticket noch offen ist
        if ($ticket->isClosed()) {
            $template = $this->config->get('ticket_already_closed_message')
                ?: "Ticket #{ticket_number} ist bereits geschlossen.\n\nSenden Sie eine neue Nachricht um ein neues Ticket zu erstellen.";
            $message = $this->replaceVariables($template, ['ticket_number' => $ticketNumber]);
            $this->api->sendMessage($phone, $message);

            return [
                'success' => false,
                'error' => 'Ticket is closed',
                'ticket_number' => $ticketNumber,
            ];
        }

        // Wechsel durchführen
        $mappingId = WhatsAppMapping::switchActiveTicket($phone, $ticket->getId(), $ticketNumber, $name);

        // Erfolgsmeldung senden
        $template = $this->config->get('switch_success_message')
            ?: "Ticket gewechselt zu #{ticket_number} - {ticket_subject}";
        $message = $this->replaceVariables($template, [
            'ticket_number' => $ticketNumber,
            'ticket_subject' => $ticket->getSubject(),
        ]);

        $this->api->sendMessage($phone, $message);

        return [
            'success' => true,
            'action' => 'ticket_switched',
            'ticket_number' => $ticketNumber,
        ];
    }

    /**
     * Erstellt ein neues Ticket aus einer WhatsApp-Nachricht
     *
     * Wird aufgerufen wenn keine offenes Mapping für die Telefonnummer existiert.
     * Erstellt einen User (falls nicht vorhanden), das Ticket und das Mapping.
     *
     * @param array $data Nachrichtendaten (phone, text, name, messageId)
     * @return array Response mit action 'created' und ticket_id/ticket_number
     */
    private function createTicket($data)
    {
        $phone = $data['phone'];
        $text = $data['text'];
        $name = $data['name'] ?? 'WhatsApp User';

        // Email aus Telefonnummer generieren (osTicket erfordert Email)
        $email = "whatsapp+{$phone}@tickets.local";

        // User suchen oder erstellen
        $user = $this->getOrCreateUser($email, $name, $phone);
        if (!$user) {
            return ['success' => false, 'error' => 'Failed to create user'];
        }

        // Konfigurationswerte laden
        $topicIdRaw = $this->config->get('default_topic_id');
        $deptIdRaw = $this->config->get('default_dept_id');

        // osTicket ChoiceField speichert Werte als JSON: {"id":"label"}
        // Wir muessen die ID extrahieren
        $topicId = $this->extractChoiceId($topicIdRaw);
        $deptId = $this->extractChoiceId($deptIdRaw);

        // Konfigurationswerte für Debugging loggen
        //error_log('WhatsApp Plugin: Raw config values - topicIdRaw: ' . var_export($topicIdRaw, true)
        //    . ', deptIdRaw: ' . var_export($deptIdRaw, true));
        //error_log('WhatsApp Plugin: Extracted IDs - topicId: ' . $topicId . ', deptId: ' . $deptId);

        // Ticket-Daten vorbereiten
        $ticketData = [
            'name' => $name,
            'email' => $email,
            'phone' => '+' . $phone,
            'subject' => 'WhatsApp Anfrage von ' . $name,
            'message' => $text,
            'source' => 'API',
            'autorespond' => false,
        ];

        // topicId/deptId nur setzen wenn ein echter Wert vorhanden ist (nicht 0, nicht leer)
        // Note: empty("0") returns true in PHP, so we need explicit int conversion
        $topicIdInt = (int) $topicId;
        $deptIdInt = (int) $deptId;

        error_log('WhatsApp Plugin: After int conversion - topicIdInt: ' . $topicIdInt . ', deptIdInt: ' . $deptIdInt);

        if ($topicIdInt > 0) {
            // Verify topic exists and is active before using it
            if (class_exists('Topic')) {
                $topic = Topic::lookup($topicIdInt);
                if ($topic && $topic->isActive()) {
                    $ticketData['topicId'] = $topicIdInt;
                    error_log('WhatsApp Plugin: Topic verified and set: ' . $topicIdInt . ' (' . $topic->getName() . ')');
                } else {
                    error_log('WhatsApp Plugin: Topic ' . $topicIdInt . ' not found or inactive');
                }
            } else {
                $ticketData['topicId'] = $topicIdInt;
            }
        }
        if ($deptIdInt > 0) {
            $ticketData['deptId'] = $deptIdInt;
        }

        // Ticket erstellen
        $ticket = $this->createOsTicket($ticketData, $user);
        if (!$ticket) {
            return ['success' => false, 'error' => 'Failed to create ticket'];
        }

        // Mapping erstellen
        $mappingId = WhatsAppMapping::create([
            'phone' => $phone,
            'contact_name' => $name,
            'ticket_id' => $ticket->getId(),
            'ticket_number' => $ticket->getNumber(),
            'user_id' => $user->getId(),
        ]);

        // Eingehende Nachricht protokollieren
        if ($mappingId) {
            WhatsAppMapping::logMessage($mappingId, [
                'message_id' => $data['messageId'] ?? null,
                'direction' => 'in',
                'content' => $text,
                'status' => 'received',
            ]);
        }

        // Bestätigung senden
        if ($this->config->get('auto_response')) {
            $this->sendConfirmation($phone, $name, $ticket->getNumber());
        }

        return [
            'success' => true,
            'ticket_id' => $ticket->getId(),
            'ticket_number' => $ticket->getNumber(),
            'action' => 'created',
        ];
    }

    /**
     * Fügt eine Nachricht zu einem bestehenden Ticket hinzu
     *
     * Wird aufgerufen wenn ein offenes Mapping für die Telefonnummer existiert.
     * Falls das Ticket gelöscht oder geschlossen wurde, wird ein neues erstellt.
     *
     * @param array $mapping Aktives Mapping
     * @param array $data Nachrichtendaten
     * @return array Response mit action 'updated' oder 'created'
     */
    private function addMessageToTicket($mapping, $data)
    {
        $text = $data['text'];
        $ticketId = $mapping['ticket_id'];

        // Ticket laden
        $ticket = Ticket::lookup($ticketId);
        if (!$ticket) {
            // Ticket wurde gelöscht, neues erstellen
            WhatsAppMapping::updateStatus($mapping['id'], 'closed');
            return $this->createTicket($data);
        }

        // Prüfen ob Ticket geschlossen ist
        if ($ticket->isClosed()) {
            // Ticket ist geschlossen, neues erstellen
            WhatsAppMapping::updateStatus($mapping['id'], 'closed');
            return $this->createTicket($data);
        }

        // Nachricht als Thread-Eintrag hinzufügen
        // User fuer die Nachricht ermitteln
        $user = $ticket->getUser();
        $userId = $mapping['user_id'] ?: ($user ? $user->getId() : null);

        $vars = [
            'message' => $text,
            'userId' => $userId,
        ];

        // Nachricht zum Ticket hinzufügen
        // alert=false verhindert Collaborator-Benachrichtigungen die Fehler verursachen koennen
        $entry = $ticket->postMessage($vars, 'API', false);

        if (!$entry) {
            // Fallback: Als Notiz hinzufügen
            $noteErrors = [];
            $entry = $ticket->postNote([
                'note' => "WhatsApp Nachricht:\n\n" . $text,
            ], $noteErrors, 'WhatsApp', false);
        }

        // Mapping-Zeitstempel aktualisieren
        WhatsAppMapping::touch($mapping['id']);

        // Nachricht protokollieren
        WhatsAppMapping::logMessage($mapping['id'], [
            'message_id' => $data['messageId'] ?? null,
            'direction' => 'in',
            'content' => $text,
            'status' => 'received',
        ]);

        // Bestätigung senden wenn aktiviert
        if ($this->config->get('notify_on_message_added')) {
            $ticket = Ticket::lookup($ticketId);
            $template = $this->config->get('message_added_confirmation')
                ?: "Ihre Nachricht wurde zu Ticket #{ticket_number} hinzugefügt.";
            $confirmMessage = $this->replaceVariables($template, [
                'ticket_number' => $mapping['ticket_number'],
                'ticket_subject' => $ticket ? $ticket->getSubject() : 'WhatsApp Anfrage',
            ]);
            $this->api->sendMessage($data['phone'], $confirmMessage);
        }

        return [
            'success' => true,
            'ticket_id' => $ticketId,
            'ticket_number' => $mapping['ticket_number'],
            'action' => 'updated',
        ];
    }

    /**
     * Sucht oder erstellt einen osTicket-User anhand der Telefonnummer
     *
     * Sucht zuerst nach einem bestehenden User mit passender Telefonnummer,
     * dann nach der generierten Email, und erstellt einen neuen User falls
     * keiner gefunden wird.
     *
     * @param string $email Generierte Email-Adresse
     * @param string $name Name des Kunden
     * @param string $phone Telefonnummer
     * @return User|null User-Objekt oder null bei Fehler
     */
    private function getOrCreateUser($email, $name, $phone)
    {
        // Telefonnummer bereinigen
        $cleanPhone = preg_replace('/\D/', '', $phone);

        // Verschiedene Formatvarianten zum Suchen
        $phoneVariations = [
            '+' . $cleanPhone,
            $cleanPhone,
            '+' . substr($cleanPhone, 0, 2) . substr($cleanPhone, 2),
        ];

        // Bestehenden User per Telefonnummer suchen
        $user = null;

        foreach ($phoneVariations as $phoneFormat) {
            $user = $this->findUserByPhone($phoneFormat);
            if ($user) {
                break;
            }
        }

        if (!$user) {
            // Auch per generierter Email suchen (Abwärtskompatibilität)
            $user = User::lookupByEmail($email);
        }

        if (!$user) {
            // Neuen User mit Telefonnummer erstellen
            $userData = [
                'name' => $name,
                'email' => $email,
                'phone' => '+' . $cleanPhone,
            ];

            try {
                $user = User::fromVars($userData);
            } catch (Exception $e) {
                error_log('WhatsApp Plugin: Failed to create user - ' . $e->getMessage());
                return null;
            }
        }

        return $user;
    }

    /**
     * Sucht einen User anhand der Telefonnummer in der osTicket-Datenbank
     *
     * @param string $phone Telefonnummer (verschiedene Formate möglich)
     * @return User|null User-Objekt oder null wenn nicht gefunden
     */
    private function findUserByPhone($phone)
    {
        if (empty($phone)) {
            return null;
        }

        $prefix = TABLE_PREFIX;

        // Telefonnummer bereinigen - nur Ziffern und + erlauben
        $cleanPhone = preg_replace('/[^0-9+]/', '', $phone);

        // In user__cdata Tabelle suchen wo Telefonnummern gespeichert sind
        $sql = "SELECT u.* FROM `{$prefix}user` u
                JOIN `{$prefix}user__cdata` c ON u.id = c.user_id
                WHERE c.phone LIKE " . db_input('%' . $cleanPhone . '%') . "
                LIMIT 1";

        $result = db_query($sql);
        $row = db_fetch_array($result);

        if ($row && isset($row['id'])) {
            return User::lookup($row['id']);
        }

        return null;
    }

    /**
     * Erstellt ein osTicket-Ticket
     *
     * Wrapper um Ticket::create() mit erweitertem Fehlerhandling und Logging.
     * Verwendet das Default-Department von osTicket falls keins konfiguriert ist.
     *
     * @param array $data Ticket-Daten (name, email, subject, message, etc.)
     * @param User $user User-Objekt für das Ticket
     * @return Ticket|null Ticket-Objekt oder null bei Fehler
     */
    private function createOsTicket($data, $user)
    {
        global $cfg;

        $vars = [
            'name' => $data['name'],
            'email' => $data['email'],
            'subject' => $data['subject'],
            'message' => $data['message'],
            'source' => 'API',
            'uid' => $user->getId(),
        ];

        if (!empty($data['topicId'])) {
            $vars['topicId'] = (int) $data['topicId'];
            error_log('WhatsApp Plugin: Setting topicId: ' . $vars['topicId']);
        } else {
            // osTicket Default-Topic verwenden falls keins konfiguriert
            if ($cfg && method_exists($cfg, 'getDefaultTopicId')) {
                $defaultTopicId = $cfg->getDefaultTopicId();
                if ($defaultTopicId) {
                    $vars['topicId'] = (int) $defaultTopicId;
                    error_log('WhatsApp Plugin: Using osTicket default topic_id: ' . $defaultTopicId);
                }
            }
        }

        if (!empty($data['deptId'])) {
            $vars['deptId'] = (int) $data['deptId'];
            error_log('WhatsApp Plugin: Setting deptId: ' . $vars['deptId']);
        } else {
            // osTicket Default-Department verwenden falls keins konfiguriert
            if ($cfg && method_exists($cfg, 'getDefaultDeptId')) {
                $defaultDeptId = $cfg->getDefaultDeptId();
                if ($defaultDeptId) {
                    $vars['deptId'] = (int) $defaultDeptId;
                    error_log('WhatsApp Plugin: Using osTicket default dept_id: ' . $defaultDeptId);
                }
            }
        }

        $vars['autorespond'] = false;
        $errors = []; // Errors-Array explizit initialisieren

        try {
            error_log('WhatsApp Plugin: Creating ticket with vars - ' . print_r($vars, true));

            $ticket = Ticket::create($vars, $errors, 'API', false, false);

            // Fehler loggen auch wenn Ticket erstellt wurde
            if (!empty($errors)) {
                error_log('WhatsApp Plugin: Ticket creation errors - ' . print_r($errors, true));
            }

            if ($ticket instanceof Ticket) {
                error_log('WhatsApp Plugin: Ticket created - ID: ' . $ticket->getId() . ', Number: ' . $ticket->getNumber());

                // Verifizieren dass Ticket in Datenbank existiert
                $verifyTicket = Ticket::lookup($ticket->getId());
                if (!$verifyTicket) {
                    error_log('WhatsApp Plugin: WARNING - Ticket created but not found in DB! ID: ' . $ticket->getId());
                    return null;
                }

                return $ticket;
            }

            error_log('WhatsApp Plugin: Ticket creation returned non-Ticket type: ' . gettype($ticket));
            return null;

        } catch (Exception $e) {
            error_log('WhatsApp Plugin: Exception creating ticket - ' . $e->getMessage());
            error_log('WhatsApp Plugin: Exception trace - ' . $e->getTraceAsString());
            return null;
        }
    }

    /**
     * Sendet eine Bestätigungsnachricht nach Ticket-Erstellung
     *
     * Verwendet das konfigurierte Template und ersetzt Variablen.
     *
     * @param string $phone Telefonnummer des Kunden
     * @param string $name Name des Kunden
     * @param string $ticketNumber Erstellte Ticketnummer
     * @return void
     */
    private function sendConfirmation($phone, $name, $ticketNumber)
    {
        $template = $this->config->get('confirmation_message')
            ?: "Vielen Dank, {name}! Ihr Ticket #{ticket_number} wurde erstellt.";

        $message = $this->replaceVariables($template, [
            'ticket_number' => $ticketNumber,
            'name' => $name,
        ]);

        $this->api->sendMessage($phone, $message);
    }

    /**
     * Extrahiert die ID aus einem osTicket ChoiceField-Wert
     *
     * osTicket ChoiceField speichert ausgewaehlte Werte als JSON: {"id":"label"}
     * Diese Methode extrahiert die ID (den Key) aus diesem Format.
     *
     * @param mixed $value Der rohe Config-Wert
     * @return int Die extrahierte ID oder 0
     */
    private function extractChoiceId($value)
    {
        if (empty($value)) {
            return 0;
        }

        // Wenn es bereits eine Zahl ist, direkt zurueckgeben
        if (is_numeric($value)) {
            return (int) $value;
        }

        // Versuche JSON zu parsen: {"1":"Label"} oder {"4":"FFK_EDV"}
        if (is_string($value) && $value[0] === '{') {
            $decoded = json_decode($value, true);
            if (is_array($decoded) && !empty($decoded)) {
                // Der erste Key ist die ID
                $keys = array_keys($decoded);
                if (!empty($keys[0]) && is_numeric($keys[0])) {
                    return (int) $keys[0];
                }
            }
        }

        // Falls nichts funktioniert hat
        return 0;
    }

    /**
     * Verifiziert das Webhook-Secret
     *
     * Vergleicht das übergebene Secret mit dem konfigurierten Secret
     * unter Verwendung von timing-safe comparison.
     *
     * @param string $secret Secret aus dem Request-Header
     * @return bool True wenn Secret gültig
     */
    public function verifySecret($secret)
    {
        $expected = $this->config->get('webhook_secret');
        return $expected && hash_equals($expected, $secret);
    }

    /**
     * Verarbeitet Wartungs-Cleanup-Requests
     *
     * Bereinigt verwaiste Mappings (Tickets die gelöscht wurden).
     *
     * @return array Response mit Cleanup-Statistiken
     */
    private function handleCleanup()
    {
        $stats = WhatsAppMapping::cleanupOrphaned();

        return [
            'success' => true,
            'action' => 'cleanup',
            'stats' => $stats,
            'message' => sprintf(
                'Cleanup complete: %d checked, %d cleaned, %d errors',
                $stats['checked'],
                $stats['cleaned'],
                $stats['errors']
            ),
        ];
    }
}
