<?php
/**
 * WhatsApp Mapping Database Handler
 *
 * Manages the phone-to-ticket mapping
 */

class WhatsAppMapping
{
    /**
     * Find open mapping by phone number
     *
     * @param string $phone Phone number
     * @return array|null Mapping record or null
     */
    public static function findOpenByPhone($phone)
    {
        $phone = self::cleanPhone($phone);
        $prefix = TABLE_PREFIX;

        $sql = "SELECT * FROM `{$prefix}whatsapp_mapping`
                WHERE `phone` = '" . db_real_escape_string($phone) . "'
                AND `status` = 'open'
                ORDER BY `created` DESC
                LIMIT 1";

        $result = db_query($sql);
        return db_fetch_array($result) ?: null;
    }

    /**
     * Find mapping by ticket ID
     *
     * @param int $ticketId Ticket ID
     * @return array|null Mapping record or null
     */
    public static function findByTicketId($ticketId)
    {
        $prefix = TABLE_PREFIX;
        $ticketId = (int) $ticketId;

        $sql = "SELECT * FROM `{$prefix}whatsapp_mapping`
                WHERE `ticket_id` = {$ticketId}
                LIMIT 1";

        $result = db_query($sql);
        return db_fetch_array($result) ?: null;
    }

    /**
     * Find mapping by ticket number
     *
     * @param string $ticketNumber Ticket number
     * @return array|null Mapping record or null
     */
    public static function findByTicketNumber($ticketNumber)
    {
        $prefix = TABLE_PREFIX;

        $sql = "SELECT * FROM `{$prefix}whatsapp_mapping`
                WHERE `ticket_number` = '" . db_real_escape_string($ticketNumber) . "'
                LIMIT 1";

        $result = db_query($sql);
        return db_fetch_array($result) ?: null;
    }

    /**
     * Create new mapping
     *
     * @param array $data Mapping data
     * @return int|false Inserted ID or false
     */
    public static function create($data)
    {
        $prefix = TABLE_PREFIX;
        $now = date('Y-m-d H:i:s');

        $phone = self::cleanPhone($data['phone']);
        $phoneFormatted = self::formatPhone($data['phone']);

        $sql = "INSERT INTO `{$prefix}whatsapp_mapping`
                (`phone`, `phone_formatted`, `contact_name`, `ticket_id`, `ticket_number`, `user_id`, `status`, `created`, `updated`)
                VALUES (
                    '" . db_real_escape_string($phone) . "',
                    '" . db_real_escape_string($phoneFormatted) . "',
                    '" . db_real_escape_string($data['contact_name'] ?? '') . "',
                    " . (int) $data['ticket_id'] . ",
                    '" . db_real_escape_string($data['ticket_number']) . "',
                    " . (isset($data['user_id']) ? (int) $data['user_id'] : 'NULL') . ",
                    'open',
                    '{$now}',
                    '{$now}'
                )";

        if (db_query($sql)) {
            return db_insert_id();
        }

        return false;
    }

    /**
     * Update mapping status
     *
     * @param int $id Mapping ID
     * @param string $status New status
     * @return bool Success
     */
    public static function updateStatus($id, $status)
    {
        $prefix = TABLE_PREFIX;
        $now = date('Y-m-d H:i:s');

        $sql = "UPDATE `{$prefix}whatsapp_mapping`
                SET `status` = '" . db_real_escape_string($status) . "',
                    `updated` = '{$now}'
                WHERE `id` = " . (int) $id;

        return db_query($sql) !== false;
    }

    /**
     * Log a message
     *
     * @param int $mappingId Mapping ID
     * @param array $data Message data
     * @return int|false Inserted ID or false
     */
    public static function logMessage($mappingId, $data)
    {
        $prefix = TABLE_PREFIX;
        $now = date('Y-m-d H:i:s');

        $sql = "INSERT INTO `{$prefix}whatsapp_messages`
                (`mapping_id`, `message_id`, `direction`, `content`, `status`, `created`)
                VALUES (
                    " . (int) $mappingId . ",
                    " . (isset($data['message_id']) ? "'" . db_real_escape_string($data['message_id']) . "'" : 'NULL') . ",
                    '" . db_real_escape_string($data['direction']) . "',
                    '" . db_real_escape_string($data['content'] ?? '') . "',
                    '" . db_real_escape_string($data['status'] ?? 'sent') . "',
                    '{$now}'
                )";

        if (db_query($sql)) {
            return db_insert_id();
        }

        return false;
    }

    /**
     * Update last activity timestamp
     *
     * @param int $id Mapping ID
     * @return bool Success
     */
    public static function touch($id)
    {
        $prefix = TABLE_PREFIX;
        $now = date('Y-m-d H:i:s');

        $sql = "UPDATE `{$prefix}whatsapp_mapping`
                SET `updated` = '{$now}'
                WHERE `id` = " . (int) $id;

        return db_query($sql) !== false;
    }

    /**
     * Switch active ticket for a phone number
     * Sets all other open mappings for this phone to 'inactive'
     * and activates or creates mapping for the new ticket
     *
     * @param string $phone Phone number
     * @param int $newTicketId New ticket ID
     * @param string $newTicketNumber New ticket number
     * @param string $contactName Contact name
     * @return int Mapping ID
     */
    public static function switchActiveTicket($phone, $newTicketId, $newTicketNumber, $contactName = '')
    {
        $prefix = TABLE_PREFIX;
        $now = date('Y-m-d H:i:s');
        $phone = self::cleanPhone($phone);

        // 1. Set all current open mappings for this phone to 'inactive'
        $sql = "UPDATE `{$prefix}whatsapp_mapping`
                SET `status` = 'inactive', `updated` = '{$now}'
                WHERE `phone` = '" . db_real_escape_string($phone) . "'
                AND `status` = 'open'";
        db_query($sql);

        // 2. Check if mapping for this ticket already exists
        $existingMapping = self::findByTicketId($newTicketId);

        if ($existingMapping) {
            // Reactivate existing mapping
            $sql = "UPDATE `{$prefix}whatsapp_mapping`
                    SET `status` = 'open', `updated` = '{$now}'
                    WHERE `id` = " . (int) $existingMapping['id'];
            db_query($sql);
            return $existingMapping['id'];
        }

        // 3. Create new mapping
        return self::create([
            'phone' => $phone,
            'contact_name' => $contactName,
            'ticket_id' => $newTicketId,
            'ticket_number' => $newTicketNumber,
        ]);
    }

    /**
     * Find all mappings for a phone number
     *
     * @param string $phone Phone number
     * @return array List of mappings
     */
    public static function findAllByPhone($phone)
    {
        $phone = self::cleanPhone($phone);
        $prefix = TABLE_PREFIX;

        $sql = "SELECT * FROM `{$prefix}whatsapp_mapping`
                WHERE `phone` = '" . db_real_escape_string($phone) . "'
                ORDER BY `updated` DESC";

        $result = db_query($sql);
        $mappings = [];

        while ($row = db_fetch_array($result)) {
            $mappings[] = $row;
        }

        return $mappings;
    }

    /**
     * Clean phone number (remove non-digits)
     *
     * @param string $phone Phone number
     * @return string Cleaned phone number
     */
    private static function cleanPhone($phone)
    {
        return preg_replace('/\D/', '', $phone);
    }

    /**
     * Format phone number for display
     *
     * @param string $phone Phone number
     * @return string Formatted phone number
     */
    private static function formatPhone($phone)
    {
        $clean = self::cleanPhone($phone);

        // Format as +XX XXX XXXXXXX for display
        if (strlen($clean) >= 10) {
            return '+' . substr($clean, 0, 2) . ' ' . substr($clean, 2, 3) . ' ' . substr($clean, 5);
        }

        return '+' . $clean;
    }
}
