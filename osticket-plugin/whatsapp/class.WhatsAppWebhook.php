<?php
/**
 * WhatsApp Webhook Handler
 *
 * Processes incoming WhatsApp messages and creates/updates tickets
 */

class WhatsAppWebhook
{
    private $config;
    private $api;

    public function __construct($config)
    {
        $this->config = $config;
        $this->api = new WhatsAppApi(
            $config->get('service_url'),
            $config->get('api_key')
        );
    }

    /**
     * Handle incoming webhook request
     *
     * @param array $payload Webhook payload
     * @return array Response
     */
    public function handle($payload)
    {
        if (!isset($payload['event']) || $payload['event'] !== 'message.received') {
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

        // Get existing mapping for this phone
        $mapping = WhatsAppMapping::findOpenByPhone($phone);

        // 1. Check if media message
        if ($this->isMediaMessage($type)) {
            return $this->handleMediaMessage($data, $mapping);
        }

        // 2. Check for close keyword
        if ($mapping && $this->isCloseKeyword($text)) {
            return $this->closeTicketByCustomer($mapping, $phone);
        }

        // 3. Check for ticket switch command
        $switchTicketNumber = $this->parseTicketSwitch($text);
        if ($switchTicketNumber) {
            return $this->switchTicket($phone, $switchTicketNumber, $name);
        }

        // 4. Normal message handling
        if (empty($text)) {
            return ['success' => false, 'error' => 'Empty message'];
        }

        if ($mapping) {
            // Add message to existing ticket
            return $this->addMessageToTicket($mapping, $data);
        } else {
            // Create new ticket
            return $this->createTicket($data);
        }
    }

    /**
     * Check if message type is a media type
     */
    private function isMediaMessage($type)
    {
        $mediaTypes = ['image', 'video', 'audio', 'document', 'sticker'];
        return in_array($type, $mediaTypes);
    }

    /**
     * Handle media message - send email link
     */
    private function handleMediaMessage($data, $mapping)
    {
        $phone = $data['phone'];
        $ticketNumber = $mapping ? $mapping['ticket_number'] : 'NEU';

        // Build email link
        $emailLink = $this->buildEmailLink($ticketNumber);

        // Get message template
        $message = $this->config->get('media_response_message');
        $supportEmail = $this->config->get('support_email') ?: 'support@example.com';

        // Replace variables
        $message = str_replace('{ticket_number}', $ticketNumber, $message);
        $message = str_replace('{email_link}', $emailLink, $message);
        $message = str_replace('{support_email}', $supportEmail, $message);

        // Send response
        $this->api->sendMessage($phone, $message);

        // Log the attempt if we have a mapping
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
     * Build mailto link with pre-filled ticket number
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
     * Check if text matches a close keyword
     */
    private function isCloseKeyword($text)
    {
        $text = trim($text);
        if (empty($text)) {
            return false;
        }

        // Check main close keyword
        $closeKeyword = $this->config->get('close_keyword') ?: 'SCHLIESSEN';
        if (strcasecmp($text, $closeKeyword) === 0) {
            return true;
        }

        // Check alternative keywords
        $keywordsList = $this->config->get('close_keywords_list') ?: '';
        $keywords = array_filter(array_map('trim', explode("\n", $keywordsList)));

        foreach ($keywords as $keyword) {
            if (strcasecmp($text, $keyword) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Close ticket initiated by customer
     */
    private function closeTicketByCustomer($mapping, $phone)
    {
        $ticketId = $mapping['ticket_id'];
        $ticketNumber = $mapping['ticket_number'];

        // Get ticket
        $ticket = Ticket::lookup($ticketId);
        if (!$ticket) {
            WhatsAppMapping::updateStatus($mapping['id'], 'closed');
            return ['success' => false, 'error' => 'Ticket not found'];
        }

        $ticketSubject = $ticket->getSubject();

        // Close the ticket
        try {
            // Get closed status
            $closedStatus = TicketStatus::lookup(['state' => 'closed']);
            if ($closedStatus) {
                $ticket->setStatus($closedStatus);
            }
        } catch (Exception $e) {
            error_log('WhatsApp Plugin: Failed to close ticket - ' . $e->getMessage());
        }

        // Update mapping
        WhatsAppMapping::updateStatus($mapping['id'], 'closed');

        // Send confirmation
        $message = $this->config->get('closed_ticket_message');
        $message = str_replace('{ticket_number}', $ticketNumber, $message);
        $message = str_replace('{ticket_subject}', $ticketSubject, $message);

        $this->api->sendMessage($phone, $message);

        // Log the close action
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
    }

    /**
     * Parse ticket switch command
     * Returns ticket number if valid switch command, null otherwise
     */
    private function parseTicketSwitch($text)
    {
        $text = trim($text);
        if (empty($text)) {
            return null;
        }

        $switchKeyword = $this->config->get('switch_keyword') ?: 'Ticket-Wechsel';

        // Pattern: "Ticket-Wechsel #123456" or "Ticket-Wechsel 123456"
        $pattern = '/^' . preg_quote($switchKeyword, '/') . '\s*#?\s*([A-Za-z0-9-]+)$/i';

        if (preg_match($pattern, $text, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * Switch active ticket for phone number
     */
    private function switchTicket($phone, $ticketNumber, $name)
    {
        // Find the ticket
        $ticket = Ticket::lookupByNumber($ticketNumber);

        if (!$ticket) {
            // Send error message
            $message = $this->config->get('switch_error_message');
            $message = str_replace('{ticket_number}', $ticketNumber, $message);
            $this->api->sendMessage($phone, $message);

            return [
                'success' => false,
                'error' => 'Ticket not found',
                'ticket_number' => $ticketNumber,
            ];
        }

        // Check if user has access to this ticket (by email/phone)
        $user = $ticket->getUser();
        $userPhone = '';
        if ($user) {
            $userPhone = preg_replace('/\D/', '', $user->getPhone() ?: '');
        }

        $cleanPhone = preg_replace('/\D/', '', $phone);

        // Verify ownership (phone must match or be existing mapping)
        $existingMapping = WhatsAppMapping::findByTicketNumber($ticketNumber);
        $hasAccess = false;

        if ($existingMapping && $existingMapping['phone'] === $cleanPhone) {
            $hasAccess = true;
        } elseif ($userPhone && strpos($userPhone, $cleanPhone) !== false) {
            $hasAccess = true;
        } elseif ($userPhone && strpos($cleanPhone, $userPhone) !== false) {
            $hasAccess = true;
        }

        if (!$hasAccess) {
            // Send error message
            $message = $this->config->get('switch_error_message');
            $message = str_replace('{ticket_number}', $ticketNumber, $message);
            $this->api->sendMessage($phone, $message);

            return [
                'success' => false,
                'error' => 'No access to ticket',
                'ticket_number' => $ticketNumber,
            ];
        }

        // Check if ticket is still open
        if ($ticket->isClosed()) {
            $this->api->sendMessage($phone,
                "Ticket #{$ticketNumber} ist bereits geschlossen.\n\nSenden Sie eine neue Nachricht um ein neues Ticket zu erstellen.");

            return [
                'success' => false,
                'error' => 'Ticket is closed',
                'ticket_number' => $ticketNumber,
            ];
        }

        // Perform the switch
        $mappingId = WhatsAppMapping::switchActiveTicket($phone, $ticket->getId(), $ticketNumber, $name);

        // Send success message
        $message = $this->config->get('switch_success_message');
        $message = str_replace('{ticket_number}', $ticketNumber, $message);
        $message = str_replace('{ticket_subject}', $ticket->getSubject(), $message);

        $this->api->sendMessage($phone, $message);

        return [
            'success' => true,
            'action' => 'ticket_switched',
            'ticket_number' => $ticketNumber,
        ];
    }

    /**
     * Create a new ticket from WhatsApp message
     */
    private function createTicket($data)
    {
        $phone = $data['phone'];
        $text = $data['text'];
        $name = $data['name'] ?? 'WhatsApp User';

        // Generate email from phone (osTicket requires email)
        $email = "whatsapp+{$phone}@tickets.local";

        // Get or create user
        $user = $this->getOrCreateUser($email, $name, $phone);
        if (!$user) {
            return ['success' => false, 'error' => 'Failed to create user'];
        }

        // Prepare ticket data
        $ticketData = [
            'name' => $name,
            'email' => $email,
            'phone' => '+' . $phone,
            'subject' => 'WhatsApp Anfrage von ' . $name,
            'message' => $text,
            'source' => 'API',
            'topicId' => $this->config->get('default_topic_id') ?: null,
            'deptId' => $this->config->get('default_dept_id') ?: null,
            'autorespond' => false,
        ];

        // Create ticket
        $ticket = $this->createOsTicket($ticketData, $user);
        if (!$ticket) {
            return ['success' => false, 'error' => 'Failed to create ticket'];
        }

        // Create mapping
        $mappingId = WhatsAppMapping::create([
            'phone' => $phone,
            'contact_name' => $name,
            'ticket_id' => $ticket->getId(),
            'ticket_number' => $ticket->getNumber(),
            'user_id' => $user->getId(),
        ]);

        // Log the incoming message
        if ($mappingId) {
            WhatsAppMapping::logMessage($mappingId, [
                'message_id' => $data['messageId'] ?? null,
                'direction' => 'in',
                'content' => $text,
                'status' => 'received',
            ]);
        }

        // Send confirmation
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
     * Add message to existing ticket
     */
    private function addMessageToTicket($mapping, $data)
    {
        $text = $data['text'];
        $ticketId = $mapping['ticket_id'];

        // Get ticket
        $ticket = Ticket::lookup($ticketId);
        if (!$ticket) {
            // Ticket was deleted, create new one
            WhatsAppMapping::updateStatus($mapping['id'], 'closed');
            return $this->createTicket($data);
        }

        // Check if ticket is closed
        if ($ticket->isClosed()) {
            // Ticket is closed, create new one
            WhatsAppMapping::updateStatus($mapping['id'], 'closed');
            return $this->createTicket($data);
        }

        // Add message as ticket thread entry
        $poster = $ticket->getOwner() ?: $ticket->getUser();

        $vars = [
            'message' => $text,
            'userId' => $mapping['user_id'],
            'poster' => $poster,
        ];

        // Try to add message to ticket
        $entry = $ticket->postMessage($vars, 'API');

        if (!$entry) {
            // Fallback: Try adding as note
            $entry = $ticket->postNote([
                'note' => "WhatsApp Nachricht:\n\n" . $text,
                'poster' => 'WhatsApp',
            ]);
        }

        // Update mapping timestamp
        WhatsAppMapping::touch($mapping['id']);

        // Log the message
        WhatsAppMapping::logMessage($mapping['id'], [
            'message_id' => $data['messageId'] ?? null,
            'direction' => 'in',
            'content' => $text,
            'status' => 'received',
        ]);

        return [
            'success' => true,
            'ticket_id' => $ticketId,
            'ticket_number' => $mapping['ticket_number'],
            'action' => 'updated',
        ];
    }

    /**
     * Get or create osTicket user by phone number
     * First searches for existing user with matching phone, then creates new if not found
     */
    private function getOrCreateUser($email, $name, $phone)
    {
        // Clean phone number for searching
        $cleanPhone = preg_replace('/\D/', '', $phone);

        // Format variations to search for
        $phoneVariations = [
            '+' . $cleanPhone,
            $cleanPhone,
            '+' . substr($cleanPhone, 0, 2) . ' ' . substr($cleanPhone, 2),
        ];

        // Try to find existing user by phone number
        $user = null;

        foreach ($phoneVariations as $phoneFormat) {
            $user = $this->findUserByPhone($phoneFormat);
            if ($user) {
                break;
            }
        }

        if (!$user) {
            // Also try by the generated email (for backwards compatibility)
            $user = User::lookupByEmail($email);
        }

        if (!$user) {
            // Create new user with phone number
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
     * Find user by phone number in osTicket database
     */
    private function findUserByPhone($phone)
    {
        if (empty($phone)) {
            return null;
        }

        $prefix = TABLE_PREFIX;

        // Search in user__cdata table where phone numbers are typically stored
        $sql = "SELECT u.* FROM `{$prefix}user` u
                JOIN `{$prefix}user__cdata` c ON u.id = c.user_id
                WHERE c.phone LIKE '%" . db_real_escape_string($phone) . "%'
                LIMIT 1";

        $result = db_query($sql);
        $row = db_fetch_array($result);

        if ($row && isset($row['id'])) {
            return User::lookup($row['id']);
        }

        return null;
    }

    /**
     * Create osTicket ticket
     */
    private function createOsTicket($data, $user)
    {
        $vars = [
            'name' => $data['name'],
            'email' => $data['email'],
            'subject' => $data['subject'],
            'message' => $data['message'],
            'source' => 'API',
            'uid' => $user->getId(),
        ];

        if (!empty($data['topicId'])) {
            $vars['topicId'] = $data['topicId'];
        }

        if (!empty($data['deptId'])) {
            $vars['deptId'] = $data['deptId'];
        }

        $vars['autorespond'] = false;

        try {
            $ticket = Ticket::create($vars, $errors, 'API', false, false);

            if ($ticket instanceof Ticket) {
                return $ticket;
            }

            error_log('WhatsApp Plugin: Ticket creation failed - ' . print_r($errors, true));
            return null;

        } catch (Exception $e) {
            error_log('WhatsApp Plugin: Exception creating ticket - ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Send confirmation message
     */
    private function sendConfirmation($phone, $name, $ticketNumber)
    {
        $message = $this->config->get('confirmation_message');

        // Replace variables
        $message = str_replace('{ticket_number}', $ticketNumber, $message);
        $message = str_replace('{name}', $name, $message);
        $message = str_replace('{close_keyword}', $this->config->get('close_keyword') ?: 'SCHLIESSEN', $message);
        $message = str_replace('{switch_keyword}', $this->config->get('switch_keyword') ?: 'Ticket-Wechsel', $message);

        $this->api->sendMessage($phone, $message);
    }

    /**
     * Verify webhook secret
     */
    public function verifySecret($secret)
    {
        $expected = $this->config->get('webhook_secret');
        return $expected && hash_equals($expected, $secret);
    }
}
