<?php
/**
 * WhatsApp Integration Plugin Main Class
 *
 * Hauptklasse des WhatsApp-Plugins für osTicket.
 * Verwaltet den Plugin-Lifecycle und registriert Event-Hooks
 * für die bidirektionale Kommunikation zwischen osTicket und WhatsApp.
 *
 * @package WhatsAppPlugin
 * @version 1.0.0
 */

require_once 'config.php';
require_once 'class.WhatsAppApi.php';
require_once 'class.WhatsAppMapping.php';
require_once 'class.WhatsAppConstants.php';
require_once 'class.WhatsAppUtils.php';

class WhatsAppPlugin extends Plugin
{
    /**
     * Konfigurationsklasse für das Plugin
     *
     * @var string
     */
    var $config_class = 'WhatsAppPluginConfig';

    /**
     * Gecachte Plugin-Konfiguration
     *
     * osTicket löscht den internen Config-Cache nach dem Bootstrap
     * (siehe class.plugin.php Zeile 206: $p->config = null).
     * Wir cachen die Config hier, um sie in Signal-Hooks verwenden zu können.
     *
     * @var PluginConfig|null
     */
    private $_cachedConfig = null;

    /**
     * Plugin Bootstrap
     *
     * Wird beim Laden des Plugins aufgerufen. Registriert alle
     * notwendigen Event-Hooks für die WhatsApp-Integration.
     *
     * Registrierte Hooks:
     * - threadentry.created: Agent-Antworten an WhatsApp senden
     * - ticket.closed: Schließungsbenachrichtigung senden
     * - model.updated: Status-Änderungen überwachen
     * - ticket.deleted: Mappings bereinigen bei Ticket-Löschung
     * - model.deleted: Fallback für Ticket-Löschung
     *
     * @return void
     */
    function bootstrap()
    {
        // Config HIER cachen, bevor osTicket den internen Cache löscht!
        // osTicket führt nach bootstrap() "$p->config = null" aus (class.plugin.php Zeile 206)
        $this->_cachedConfig = $this->getConfig();

        // Hook: Wenn ein Agent auf ein Ticket antwortet
        Signal::connect('threadentry.created', [$this, 'onThreadEntryCreated']);

        // Hook: Wenn ein Ticket geschlossen wird
        Signal::connect('ticket.closed', [$this, 'onTicketClosed']);

        // Hook: Wenn sich der Ticket-Status ändert
        Signal::connect('model.updated', [$this, 'onModelUpdated']);

        // Hook: Wenn ein Ticket gelöscht wird
        Signal::connect('ticket.deleted', [$this, 'onTicketDeleted']);
        Signal::connect('model.deleted', [$this, 'onModelDeleted']);
    }

    /**
     * Gibt die gecachte Plugin-Konfiguration zurück
     *
     * osTicket löscht den internen Config-Cache nach dem Bootstrap
     * (siehe class.plugin.php Zeile 206). Diese Methode gibt die
     * in bootstrap() gecachte Config zurück.
     *
     * @return PluginConfig Plugin-Konfiguration
     */
    private function getCachedConfig()
    {
        return $this->_cachedConfig ?: $this->getConfig();
    }

    /**
     * Verarbeitet neue Thread-Einträge (Agent-Antworten)
     *
     * Wird aufgerufen wenn ein Agent auf ein Ticket antwortet.
     * Prüft ob das Ticket via WhatsApp erstellt wurde und sendet
     * die Antwort an den Kunden.
     *
     * @param ThreadEntry $entry Der neue Thread-Eintrag
     * @param array $data Zusätzliche Daten (optional)
     * @return void
     */
    function onThreadEntryCreated($entry, $data = [])
    {
        $config = $this->getCachedConfig();
        if (!$config->get('notify_on_reply')) {
            return;
        }

        // Nur Agent-Antworten verarbeiten (Typ 'R' = Response)
        if ($entry->getType() !== 'R') {
            return;
        }

        // Ticket laden - verschiedene Methoden für osTicket-Kompatibilität
        $ticket = null;
        if (method_exists($entry, 'getTicket')) {
            $ticket = $entry->getTicket();
        } elseif (method_exists($entry, 'getThread')) {
            $thread = $entry->getThread();
            if ($thread && method_exists($thread, 'getObject')) {
                $ticket = $thread->getObject();
            }
        }

        if (!$ticket || !($ticket instanceof Ticket)) {
            return;
        }

        // Prüfen ob dieses Ticket via WhatsApp erstellt wurde
        $mapping = WhatsAppMapping::findByTicketId($ticket->getId());
        if (!$mapping) {
            return; // Kein WhatsApp-Ticket
        }

        // Antwort formatieren
        $body = $entry->getBody();
        if (is_object($body) && method_exists($body, 'getClean')) {
            $body = $body->getClean();
        }

        // HTML bereinigen und formatieren
        $message = $this->formatReplyMessage($body, $ticket, $entry);

        // Via WhatsApp senden
        $api = new WhatsAppApi($config->get('service_url'), $config->get('api_key'));
        $result = $api->sendMessage($mapping['phone'], $message);

        if ($result['success']) {
            // Ausgehende Nachricht protokollieren
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
     * Verarbeitet Ticket-Schließungen
     *
     * Wird aufgerufen wenn ein Ticket geschlossen wird.
     * Sendet eine Benachrichtigung an den Kunden und aktualisiert
     * das Mapping.
     *
     * @param Ticket $ticket Das geschlossene Ticket
     * @return void
     */
    function onTicketClosed($ticket)
    {
        $config = $this->getCachedConfig();
        if (!$config->get('notify_on_close')) {
            return;
        }

        // Prüfen ob dieses Ticket via WhatsApp erstellt wurde
        $mapping = WhatsAppMapping::findByTicketId($ticket->getId());
        if (!$mapping) {
            return;
        }

        // Mapping-Status aktualisieren
        WhatsAppMapping::updateStatus($mapping['id'], 'closed');

        // Schließungsbenachrichtigung senden
        $template = $config->get('closed_ticket_message')
            ?: "Ihr Ticket #{ticket_number} wurde geschlossen.";
        $message = $this->replaceVariables($template, [
            'ticket_number' => $ticket->getNumber(),
            'ticket_subject' => $ticket->getSubject(),
        ]);

        $api = new WhatsAppApi($config->get('service_url'), $config->get('api_key'));
        $api->sendMessage($mapping['phone'], $message);
    }

    /**
     * Verarbeitet Model-Updates (Status-Änderungen)
     *
     * Überwacht Ticket-Status-Änderungen für zusätzliche Logik.
     *
     * @param object $model Das aktualisierte Model
     * @param array $data Änderungsdaten
     * @return void
     */
    function onModelUpdated($model, $data = [])
    {
        // Prüfen ob es ein Ticket ist und der Status geändert wurde
        if (!($model instanceof Ticket)) {
            return;
        }

        if (isset($data['status_id'])) {
            $status = TicketStatus::lookup($data['status_id']);
            if ($status && $status->isCloseable()) {
                // Ticket wurde geschlossen - wird von onTicketClosed behandelt
            }
        }
    }

    /**
     * Verarbeitet Ticket-Löschungen
     *
     * Wird aufgerufen wenn ein Ticket gelöscht wird.
     * Bereinigt das zugehörige WhatsApp-Mapping.
     *
     * @param Ticket $ticket Das gelöschte Ticket
     * @return void
     */
    function onTicketDeleted($ticket)
    {
        if (!($ticket instanceof Ticket)) {
            return;
        }

        $this->cleanupMappingForTicket($ticket->getId());
    }

    /**
     * Verarbeitet Model-Löschungen (Fallback für Ticket-Löschung)
     *
     * Fallback-Handler falls ticket.deleted nicht ausgelöst wird.
     *
     * @param object $model Das gelöschte Model
     * @return void
     */
    function onModelDeleted($model)
    {
        if (!($model instanceof Ticket)) {
            return;
        }

        $this->cleanupMappingForTicket($model->getId());
    }

    /**
     * Bereinigt WhatsApp-Mappings für ein gelöschtes Ticket
     *
     * Setzt den Mapping-Status auf 'closed' damit bei der nächsten
     * Nachricht ein neues Ticket erstellt werden kann.
     *
     * @param int $ticketId ID des gelöschten Tickets
     * @return void
     */
    private function cleanupMappingForTicket($ticketId)
    {
        if (!$ticketId) {
            return;
        }

        $mapping = WhatsAppMapping::findByTicketId($ticketId);
        if ($mapping) {
            $config = $this->getCachedConfig();

            // Benachrichtigung senden (gleiche Nachricht wie bei Ticket-Schließung)
            if ($config && $config->get('notify_on_close')) {
                $template = $config->get('closed_ticket_message')
                    ?: "Ihr Ticket #{ticket_number} wurde geschlossen.";
                $message = $this->replaceVariables($template, [
                    'ticket_number' => $mapping['ticket_number'],
                    'ticket_subject' => 'WhatsApp Anfrage',
                ]);

                $api = new WhatsAppApi($config->get('service_url'), $config->get('api_key'));
                $api->sendMessage($mapping['phone'], $message);
            }

            // Mapping auf 'closed' setzen damit ein neues Ticket erstellt werden kann
            WhatsAppMapping::updateStatus($mapping['id'], 'closed');
            error_log('WhatsApp Plugin: Mapping closed for deleted ticket ID: ' . $ticketId);
        }
    }

    /**
     * Formatiert eine Agent-Antwort für WhatsApp
     *
     * Konvertiert HTML in Plain-Text und verwendet das konfigurierte Template.
     * Nutzt die zentrale WhatsAppUtils-Klasse für HTML-Konvertierung.
     *
     * @param string $body HTML-Body der Antwort
     * @param Ticket $ticket Das zugehörige Ticket
     * @param ThreadEntry $entry Der Thread-Eintrag
     * @return string Formatierte Nachricht für WhatsApp
     */
    private function formatReplyMessage($body, $ticket, $entry)
    {
        // HTML zu Text konvertieren (zentrale Utility-Funktion)
        $text = WhatsAppUtils::htmlToWhatsAppText($body);

        // Agent-Namen ermitteln
        $poster = $entry->getPoster();
        if (is_object($poster)) {
            $agentName = $poster->getName();
        } else {
            $agentName = $poster ?: 'Support';
        }

        // Template aus Config laden
        $config = $this->getCachedConfig();
        $template = $config->get('agent_reply_format')
            ?: "*Antwort zu Ticket #{ticket_number} - {ticket_subject}*\n\n{message}\n\n_{signature}_";

        // Variablen ersetzen (zentrale Utility-Funktion)
        return WhatsAppUtils::replaceVariables($template, $config, [
            'ticket_number' => $ticket->getNumber(),
            'ticket_subject' => $ticket->getSubject(),
            'message' => $text,
            'agent_name' => $agentName,
        ]);
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
        return WhatsAppUtils::replaceVariables($template, $this->getCachedConfig(), $contextVars);
    }

    /**
     * Plugin-Installation
     *
     * Wird bei der Installation des Plugins aufgerufen.
     * Erstellt die notwendigen Datenbanktabellen.
     *
     * @return bool Erfolgsstatus
     */
    function install()
    {
        // Datenbanktabellen erstellen
        $this->createTables();
        return parent::install();
    }

    /**
     * Plugin-Deinstallation
     *
     * Wird bei der Deinstallation des Plugins aufgerufen.
     * Tabellen werden aus Sicherheitsgründen NICHT automatisch gelöscht.
     *
     * @param array $errors Fehler-Array (by reference)
     * @return bool Erfolgsstatus
     */
    function uninstall(&$errors)
    {
        // Tabellen optional entfernen (auskommentiert für Sicherheit)
        // $this->dropTables();
        return parent::uninstall($errors);
    }

    /**
     * Erstellt die Datenbanktabellen
     *
     * Erstellt die Tabellen whatsapp_mapping und whatsapp_messages
     * falls diese noch nicht existieren.
     *
     * @return void
     */
    private function createTables()
    {
        $prefix = TABLE_PREFIX;

        // Mapping-Tabelle: Verknüpft Telefonnummern mit Tickets
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

        // Messages-Tabelle: Protokolliert alle Nachrichten
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
     * Löscht die Datenbanktabellen
     *
     * ACHTUNG: Löscht alle WhatsApp-Daten unwiderruflich!
     * Nur für vollständige Deinstallation verwenden.
     *
     * @return void
     */
    private function dropTables()
    {
        $prefix = TABLE_PREFIX;
        db_query("DROP TABLE IF EXISTS `{$prefix}whatsapp_messages`");
        db_query("DROP TABLE IF EXISTS `{$prefix}whatsapp_mapping`");
    }
}
