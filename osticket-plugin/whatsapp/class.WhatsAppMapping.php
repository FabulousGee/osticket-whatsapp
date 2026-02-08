<?php
/**
 * WhatsApp Mapping Database Handler
 *
 * Verwaltet die Zuordnung zwischen Telefonnummern und Tickets.
 * Diese Klasse ist die Datenbankabstraktionsschicht für alle
 * WhatsApp-spezifischen Daten.
 *
 * @package WhatsAppPlugin
 * @version 1.0.0
 */

require_once __DIR__ . '/class.WhatsAppConstants.php';
require_once __DIR__ . '/class.WhatsAppUtils.php';

class WhatsAppMapping
{
    /**
     * Findet ein offenes Mapping für eine Telefonnummer
     *
     * Sucht nach einem Mapping mit Status 'open' für die angegebene
     * Telefonnummer. Es wird immer das neueste Mapping zurückgegeben.
     *
     * @param string $phone Telefonnummer (wird automatisch bereinigt)
     * @return array|null Mapping-Datensatz oder null wenn nicht gefunden
     */
    public static function findOpenByPhone($phone)
    {
        $phone = self::cleanPhone($phone);
        $prefix = TABLE_PREFIX;

        $sql = "SELECT * FROM `{$prefix}whatsapp_mapping`
                WHERE `phone` = " . db_input($phone) . "
                AND `status` = 'open'
                ORDER BY `created` DESC
                LIMIT 1";

        $result = db_query($sql);
        return db_fetch_array($result) ?: null;
    }

    /**
     * Findet alle offenen Mappings für eine Telefonnummer
     *
     * Gibt alle Mappings mit Status 'open' für die angegebene
     * Telefonnummer zurück, sortiert nach Erstelldatum (ältestes zuerst).
     *
     * @param string $phone Telefonnummer (wird automatisch bereinigt)
     * @return array Array von Mapping-Datensätzen (leer wenn keine gefunden)
     */
    public static function findAllOpenByPhone($phone)
    {
        $phone = self::cleanPhone($phone);
        $prefix = TABLE_PREFIX;

        $sql = "SELECT * FROM `{$prefix}whatsapp_mapping`
                WHERE `phone` = " . db_input($phone) . "
                AND `status` = 'open'
                ORDER BY `created` ASC";

        $result = db_query($sql);
        $mappings = [];

        while ($row = db_fetch_array($result)) {
            $mappings[] = $row;
        }

        return $mappings;
    }

    /**
     * Findet ein Mapping anhand der Ticket-ID
     *
     * @param int $ticketId osTicket Ticket-ID
     * @return array|null Mapping-Datensatz oder null wenn nicht gefunden
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
     * Findet ein Mapping anhand der Ticketnummer
     *
     * @param string $ticketNumber osTicket Ticketnummer
     * @return array|null Mapping-Datensatz oder null wenn nicht gefunden
     */
    public static function findByTicketNumber($ticketNumber)
    {
        $prefix = TABLE_PREFIX;

        $sql = "SELECT * FROM `{$prefix}whatsapp_mapping`
                WHERE `ticket_number` = " . db_input($ticketNumber) . "
                LIMIT 1";

        $result = db_query($sql);
        return db_fetch_array($result) ?: null;
    }

    /**
     * Erstellt ein neues Mapping
     *
     * Verknüpft eine Telefonnummer mit einem Ticket. Das Mapping wird
     * automatisch mit Status 'open' erstellt.
     *
     * @param array $data Mapping-Daten:
     *   - phone: Telefonnummer (erforderlich)
     *   - contact_name: Name des Kontakts
     *   - ticket_id: osTicket Ticket-ID (erforderlich)
     *   - ticket_number: osTicket Ticketnummer (erforderlich)
     *   - user_id: osTicket User-ID
     * @return int|false Eingefügte ID oder false bei Fehler
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
                    " . db_input($phone) . ",
                    " . db_input($phoneFormatted) . ",
                    " . db_input($data['contact_name'] ?? '') . ",
                    " . (int) $data['ticket_id'] . ",
                    " . db_input($data['ticket_number']) . ",
                    " . (isset($data['user_id']) ? (int) $data['user_id'] : 'NULL') . ",
                    'open',
                    " . db_input($now) . ",
                    " . db_input($now) . "
                )";

        if (db_query($sql)) {
            return db_insert_id();
        }

        return false;
    }

    /**
     * Aktualisiert den Status eines Mappings
     *
     * Gültige Status-Werte:
     * - 'open': Aktives Mapping, Nachrichten werden diesem Ticket zugeordnet
     * - 'closed': Ticket wurde geschlossen
     * - 'inactive': Mapping pausiert (z.B. nach Ticket-Wechsel)
     *
     * @param int $id Mapping-ID
     * @param string $status Neuer Status ('open', 'closed', 'inactive')
     * @return bool True bei Erfolg
     */
    public static function updateStatus($id, $status)
    {
        $prefix = TABLE_PREFIX;
        $now = date('Y-m-d H:i:s');

        $sql = "UPDATE `{$prefix}whatsapp_mapping`
                SET `status` = " . db_input($status) . ",
                    `updated` = " . db_input($now) . "
                WHERE `id` = " . (int) $id;

        return db_query($sql) !== false;
    }

    /**
     * Protokolliert eine Nachricht
     *
     * Speichert eine ein- oder ausgehende Nachricht in der Messages-Tabelle
     * für Audit- und Debugging-Zwecke.
     *
     * @param int $mappingId Zugehörige Mapping-ID
     * @param array $data Nachrichtendaten:
     *   - message_id: WhatsApp Message-ID (optional)
     *   - direction: 'in' oder 'out' (erforderlich)
     *   - content: Nachrichteninhalt
     *   - status: Status der Nachricht (default: 'sent')
     * @return int|false Eingefügte ID oder false bei Fehler
     */
    public static function logMessage($mappingId, $data)
    {
        $prefix = TABLE_PREFIX;
        $now = date('Y-m-d H:i:s');

        $sql = "INSERT INTO `{$prefix}whatsapp_messages`
                (`mapping_id`, `message_id`, `direction`, `content`, `status`, `created`)
                VALUES (
                    " . (int) $mappingId . ",
                    " . (isset($data['message_id']) ? db_input($data['message_id']) : 'NULL') . ",
                    " . db_input($data['direction']) . ",
                    " . db_input($data['content'] ?? '') . ",
                    " . db_input($data['status'] ?? 'sent') . ",
                    " . db_input($now) . "
                )";

        if (db_query($sql)) {
            return db_insert_id();
        }

        return false;
    }

    /**
     * Aktualisiert den Zeitstempel eines Mappings
     *
     * Wird aufgerufen wenn eine neue Nachricht zu einem bestehenden
     * Ticket hinzugefügt wird.
     *
     * @param int $id Mapping-ID
     * @return bool True bei Erfolg
     */
    public static function touch($id)
    {
        $prefix = TABLE_PREFIX;
        $now = date('Y-m-d H:i:s');

        $sql = "UPDATE `{$prefix}whatsapp_mapping`
                SET `updated` = " . db_input($now) . "
                WHERE `id` = " . (int) $id;

        return db_query($sql) !== false;
    }

    /**
     * Wechselt das aktive Ticket für eine Telefonnummer
     *
     * Setzt alle bestehenden offenen Mappings für diese Telefonnummer
     * auf 'inactive' und aktiviert oder erstellt das Mapping für das
     * neue Ticket.
     *
     * @param string $phone Telefonnummer
     * @param int $newTicketId Neue Ticket-ID
     * @param string $newTicketNumber Neue Ticketnummer
     * @param string $contactName Kontaktname (optional)
     * @return int Mapping-ID des aktivierten/erstellten Mappings
     */
    public static function switchActiveTicket($phone, $newTicketId, $newTicketNumber, $contactName = '')
    {
        $prefix = TABLE_PREFIX;
        $now = date('Y-m-d H:i:s');
        $phone = self::cleanPhone($phone);

        // 1. Alle aktuellen offenen Mappings für diese Telefonnummer auf 'inactive' setzen
        $sql = "UPDATE `{$prefix}whatsapp_mapping`
                SET `status` = 'inactive', `updated` = " . db_input($now) . "
                WHERE `phone` = " . db_input($phone) . "
                AND `status` = 'open'";
        db_query($sql);

        // 2. Prüfen ob bereits ein Mapping für dieses Ticket existiert
        $existingMapping = self::findByTicketId($newTicketId);

        if ($existingMapping) {
            // Bestehendes Mapping reaktivieren
            $sql = "UPDATE `{$prefix}whatsapp_mapping`
                    SET `status` = 'open', `updated` = " . db_input($now) . "
                    WHERE `id` = " . (int) $existingMapping['id'];
            db_query($sql);
            return $existingMapping['id'];
        }

        // 3. Neues Mapping erstellen
        return self::create([
            'phone' => $phone,
            'contact_name' => $contactName,
            'ticket_id' => $newTicketId,
            'ticket_number' => $newTicketNumber,
        ]);
    }

    /**
     * Findet alle Mappings für eine Telefonnummer
     *
     * Gibt alle Mappings (unabhängig vom Status) für eine Telefonnummer
     * zurück, sortiert nach Aktualisierungsdatum.
     *
     * @param string $phone Telefonnummer
     * @return array Liste aller Mappings
     */
    public static function findAllByPhone($phone)
    {
        $phone = self::cleanPhone($phone);
        $prefix = TABLE_PREFIX;

        $sql = "SELECT * FROM `{$prefix}whatsapp_mapping`
                WHERE `phone` = " . db_input($phone) . "
                ORDER BY `updated` DESC";

        $result = db_query($sql);
        $mappings = [];

        while ($row = db_fetch_array($result)) {
            $mappings[] = $row;
        }

        return $mappings;
    }

    /**
     * Bereinigt die Telefonnummer
     *
     * Entfernt alle Nicht-Ziffern aus der Telefonnummer für
     * konsistente Speicherung und Suche.
     *
     * @param string $phone Telefonnummer in beliebigem Format
     * @return string Bereinigte Telefonnummer (nur Ziffern)
     */
    private static function cleanPhone($phone)
    {
        return preg_replace('/\D/', '', $phone);
    }

    /**
     * Formatiert die Telefonnummer für die Anzeige
     *
     * Konvertiert die Telefonnummer in ein lesbares Format: +XX XXX XXXXXXX
     *
     * @param string $phone Telefonnummer
     * @return string Formatierte Telefonnummer
     */
    private static function formatPhone($phone)
    {
        $clean = self::cleanPhone($phone);

        // Format als +XX XXX XXXXXXX für Anzeige
        if (strlen($clean) >= 10) {
            return '+' . substr($clean, 0, 2) . ' ' . substr($clean, 2, 3) . ' ' . substr($clean, 5);
        }

        return '+' . $clean;
    }

    /**
     * Bereinigt verwaiste Mappings
     *
     * Findet alle Mappings deren Tickets nicht mehr existieren und
     * setzt deren Status auf 'closed'. Dies ermöglicht die Erstellung
     * neuer Tickets bei der nächsten Nachricht.
     *
     * Sollte regelmäßig ausgeführt werden (z.B. via Cron oder
     * maintenance.cleanup Webhook-Event).
     *
     * @return array Statistiken:
     *   - checked: Anzahl geprüfter Mappings
     *   - cleaned: Anzahl bereinigter Mappings
     *   - errors: Anzahl Fehler
     */
    public static function cleanupOrphaned()
    {
        $prefix = TABLE_PREFIX;
        $now = date('Y-m-d H:i:s');
        $stats = ['checked' => 0, 'cleaned' => 0, 'errors' => 0];

        // Alle offenen/inaktiven Mappings finden
        $sql = "SELECT m.id, m.ticket_id, m.ticket_number
                FROM `{$prefix}whatsapp_mapping` m
                WHERE m.status IN ('open', 'inactive')";

        $result = db_query($sql);

        while ($row = db_fetch_array($result)) {
            $stats['checked']++;

            // Prüfen ob Ticket noch existiert
            $ticketSql = "SELECT ticket_id FROM `{$prefix}ticket` WHERE ticket_id = " . (int) $row['ticket_id'];
            $ticketResult = db_query($ticketSql);
            $ticketExists = db_fetch_array($ticketResult);

            if (!$ticketExists) {
                // Ticket existiert nicht mehr, Mapping schließen
                $updateSql = "UPDATE `{$prefix}whatsapp_mapping`
                              SET `status` = 'closed', `updated` = " . db_input($now) . "
                              WHERE `id` = " . (int) $row['id'];

                if (db_query($updateSql)) {
                    $stats['cleaned']++;
                    error_log('WhatsApp Plugin: Cleaned orphaned mapping ID: ' . $row['id'] . ' (Ticket #' . $row['ticket_number'] . ' deleted)');
                } else {
                    $stats['errors']++;
                }
            }
        }

        return $stats;
    }

    /**
     * Löscht ein Mapping vollständig
     *
     * Entfernt das Mapping und alle zugehörigen Nachrichten aus der
     * Datenbank. Verwenden Sie diese Methode mit Vorsicht, da alle
     * Protokolldaten verloren gehen.
     *
     * @param int $id Mapping-ID
     * @return bool True bei Erfolg
     */
    public static function delete($id)
    {
        $prefix = TABLE_PREFIX;
        $id = (int) $id;

        // Zuerst zugehörige Nachrichten löschen
        $sql = "DELETE FROM `{$prefix}whatsapp_messages` WHERE `mapping_id` = {$id}";
        db_query($sql);

        // Dann das Mapping löschen
        $sql = "DELETE FROM `{$prefix}whatsapp_mapping` WHERE `id` = {$id}";
        return db_query($sql) !== false;
    }

    /**
     * Löscht alle Mappings für ein Ticket
     *
     * Entfernt alle Mappings und zugehörigen Nachrichten für ein
     * bestimmtes Ticket. Nützlich wenn ein Ticket manuell bereinigt
     * werden soll.
     *
     * @param int $ticketId Ticket-ID
     * @return int Anzahl gelöschter Mappings
     */
    public static function deleteByTicketId($ticketId)
    {
        $prefix = TABLE_PREFIX;
        $ticketId = (int) $ticketId;
        $deleted = 0;

        // Alle Mappings für dieses Ticket finden
        $sql = "SELECT id FROM `{$prefix}whatsapp_mapping` WHERE `ticket_id` = {$ticketId}";
        $result = db_query($sql);

        while ($row = db_fetch_array($result)) {
            if (self::delete($row['id'])) {
                $deleted++;
            }
        }

        return $deleted;
    }
}
