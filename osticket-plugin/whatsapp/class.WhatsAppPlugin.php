<?php
/**
 * WhatsApp Integration Plugin Main Class
 *
 * Handles plugin lifecycle and event hooks
 */

require_once 'config.php';
require_once 'class.WhatsAppApi.php';
require_once 'class.WhatsAppMapping.php';

class WhatsAppPlugin extends Plugin
{
    var $config_class = 'WhatsAppPluginConfig';

    /**
     * Plugin bootstrap - register event hooks
     */
    function bootstrap()
    {
        // Only register hooks if plugin is enabled
        if (!$this->isEnabled()) {
            return;
        }

        // Hook: When a staff member replies to a ticket
        Signal::connect('threadentry.created', [$this, 'onThreadEntryCreated']);

        // Hook: When a ticket is closed
        Signal::connect('ticket.closed', [$this, 'onTicketClosed']);

        // Hook: When a ticket status changes
        Signal::connect('model.updated', [$this, 'onModelUpdated']);
    }

    /**
     * Check if plugin is enabled
     */
    function isEnabled()
    {
        $config = $this->getConfig();
        return $config && $config->get('enabled');
    }

    /**
     * Handle new thread entry (staff reply)
     */
    function onThreadEntryCreated($entry, $data = [])
    {
        if (!$this->isEnabled()) {
            return;
        }

        $config = $this->getConfig();
        if (!$config->get('notify_on_reply')) {
            return;
        }

        // Only process staff responses (type 'R' = Response)
        if ($entry->getType() !== 'R') {
            return;
        }

        $ticket = $entry->getTicket();
        if (!$ticket) {
            return;
        }

        // Check if this ticket was created via WhatsApp
        $mapping = WhatsAppMapping::findByTicketId($ticket->getId());
        if (!$mapping) {
            return; // Not a WhatsApp ticket
        }

        // Format the reply message
        $body = $entry->getBody();
        if (is_object($body) && method_exists($body, 'getClean')) {
            $body = $body->getClean();
        }

        // Clean HTML and format
        $message = $this->formatReplyMessage($body, $ticket, $entry);

        // Send via WhatsApp
        $api = new WhatsAppApi($config->get('service_url'), $config->get('api_key'));
        $result = $api->sendMessage($mapping['phone'], $message);

        if ($result['success']) {
            // Log the outgoing message
            WhatsAppMapping::logMessage($mapping['id'], [
                'message_id' => $result['messageId'] ?? null,
                'direction' => 'out',
                'content' => $message,
                'status' => 'sent',
            ]);
        } else {
            error_log('WhatsApp Plugin: Failed to send reply - ' . ($result['error'] ?? 'Unknown error'));
        }
    }

    /**
     * Handle ticket closed event
     */
    function onTicketClosed($ticket)
    {
        if (!$this->isEnabled()) {
            return;
        }

        $config = $this->getConfig();
        if (!$config->get('notify_on_close')) {
            return;
        }

        // Check if this ticket was created via WhatsApp
        $mapping = WhatsAppMapping::findByTicketId($ticket->getId());
        if (!$mapping) {
            return;
        }

        // Update mapping status
        WhatsAppMapping::updateStatus($mapping['id'], 'closed');

        // Send closed notification
        $message = $config->get('closed_ticket_message');
        $message = str_replace('{ticket_number}', $ticket->getNumber(), $message);
        $message = str_replace('{ticket_subject}', $ticket->getSubject(), $message);

        $api = new WhatsAppApi($config->get('service_url'), $config->get('api_key'));
        $api->sendMessage($mapping['phone'], $message);
    }

    /**
     * Handle model updates (for status changes)
     */
    function onModelUpdated($model, $data = [])
    {
        // Check if it's a ticket and status changed to closed
        if (!($model instanceof Ticket)) {
            return;
        }

        if (isset($data['status_id'])) {
            $status = TicketStatus::lookup($data['status_id']);
            if ($status && $status->isCloseable()) {
                // Ticket was closed - handled by onTicketClosed
            }
        }
    }

    /**
     * Format reply message for WhatsApp
     */
    private function formatReplyMessage($body, $ticket, $entry)
    {
        // Strip HTML tags
        $text = strip_tags($body);

        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');

        // Trim whitespace
        $text = trim($text);

        // Get agent name
        $poster = $entry->getPoster();
        if (is_object($poster)) {
            $agentName = $poster->getName();
        } else {
            $agentName = $poster ?: 'Support';
        }

        // Format with ticket number and subject
        $ticketNumber = $ticket->getNumber();
        $ticketSubject = $ticket->getSubject();

        return "*Antwort zu Ticket #{$ticketNumber} - {$ticketSubject}*\n\n{$text}\n\n_Ihr Support-Team_";
    }

    /**
     * Plugin installation
     */
    function install()
    {
        // Create database tables
        $this->createTables();
        return parent::install();
    }

    /**
     * Plugin uninstall
     */
    function uninstall(&$errors)
    {
        // Optionally remove tables (commented out for safety)
        // $this->dropTables();
        return parent::uninstall($errors);
    }

    /**
     * Create database tables
     */
    private function createTables()
    {
        $prefix = TABLE_PREFIX;

        $sql = "CREATE TABLE IF NOT EXISTS `{$prefix}whatsapp_mapping` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `phone` VARCHAR(20) NOT NULL,
            `phone_formatted` VARCHAR(25) NOT NULL,
            `contact_name` VARCHAR(255) DEFAULT NULL,
            `ticket_id` INT UNSIGNED NOT NULL,
            `ticket_number` VARCHAR(20) NOT NULL,
            `user_id` INT UNSIGNED DEFAULT NULL,
            `status` ENUM('open', 'closed', 'inactive') DEFAULT 'open',
            `created` DATETIME NOT NULL,
            `updated` DATETIME NOT NULL,
            INDEX `idx_phone_status` (`phone`, `status`),
            INDEX `idx_ticket` (`ticket_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

        db_query($sql);

        $sql = "CREATE TABLE IF NOT EXISTS `{$prefix}whatsapp_messages` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `mapping_id` INT UNSIGNED NOT NULL,
            `message_id` VARCHAR(100) DEFAULT NULL,
            `direction` ENUM('in', 'out') NOT NULL,
            `content` TEXT,
            `status` VARCHAR(20) DEFAULT 'sent',
            `created` DATETIME NOT NULL,
            INDEX `idx_mapping` (`mapping_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

        db_query($sql);
    }

    /**
     * Drop database tables
     */
    private function dropTables()
    {
        $prefix = TABLE_PREFIX;
        db_query("DROP TABLE IF EXISTS `{$prefix}whatsapp_messages`");
        db_query("DROP TABLE IF EXISTS `{$prefix}whatsapp_mapping`");
    }
}
