<?php
/**
 * WhatsApp Plugin Konfiguration
 *
 * Definiert das Konfigurationsformular für das WhatsApp-Plugin
 * im osTicket Admin-Panel. Alle Einstellungen werden über dieses
 * Formular verwaltet.
 *
 * @package WhatsAppPlugin
 * @version 1.0.0
 */

require_once INCLUDE_DIR . 'class.plugin.php';
require_once INCLUDE_DIR . 'class.forms.php';

/**
 * Plugin-Konfigurationsklasse
 *
 * Erweitert PluginConfig und definiert alle verfügbaren
 * Konfigurationsoptionen für das WhatsApp-Plugin.
 */
class WhatsAppPluginConfig extends PluginConfig
{
    /**
     * Einheitlicher Hint-Text für alle Nachrichten-Vorlagen
     */
    const VARIABLES_HINT = 'Verfügbare Variablen: {ticket_number}, {ticket_subject}, {name}, {message}, {agent_name}, {signature}, {close_keyword}, {switch_keyword}, {list_keyword}, {new_keyword}, {support_email}, {email_link}, {ticket_list}, {count}';

    /**
     * Gibt die Konfigurationsfelder zurück
     *
     * Definiert alle Formularfelder für die Plugin-Konfiguration
     * im Admin-Panel.
     *
     * @return array Array von FormField-Objekten
     */
    function getOptions()
    {
        return [
            // ==========================================
            // WHATSAPP SERVICE EINSTELLUNGEN
            // ==========================================
            'whatsapp_service' => new SectionBreakField([
                'label' => 'WhatsApp Service Einstellungen',
            ]),

            'service_url' => new TextboxField([
                'label' => 'Service URL',
                'hint' => 'URL des WhatsApp-Service (z.B. http://127.0.0.1:3000)',
                'default' => 'http://127.0.0.1:3000',
                'configuration' => [
                    'size' => 60,
                    'length' => 255,
                ],
            ]),

            'api_key' => new TextboxField([
                'label' => 'API Key (optional)',
                'hint' => 'API-Schlüssel für sichere Kommunikation mit dem Service',
                'configuration' => [
                    'size' => 60,
                    'length' => 255,
                ],
            ]),

            // ==========================================
            // WEBHOOK EINSTELLUNGEN
            // ==========================================
            'webhook_settings' => new SectionBreakField([
                'label' => 'Webhook Einstellungen',
            ]),

            'webhook_secret' => new TextboxField([
                'label' => 'Webhook Secret',
                'hint' => 'Geheimer Schlüssel zur Authentifizierung eingehender Webhooks',
                'default' => '',
                'configuration' => [
                    'size' => 60,
                    'length' => 64,
                ],
            ]),

            // ==========================================
            // TICKET EINSTELLUNGEN
            // ==========================================
            'ticket_settings' => new SectionBreakField([
                'label' => 'Ticket Einstellungen',
            ]),

            'default_topic_id' => new ChoiceField([
                'label' => 'Standard Help Topic',
                'hint' => 'Help Topic für neue WhatsApp-Tickets',
                'choices' => self::getTopicChoices(),
                'default' => 0,
            ]),

            'default_dept_id' => new ChoiceField([
                'label' => 'Standard Abteilung',
                'hint' => 'Abteilung für neue WhatsApp-Tickets',
                'choices' => self::getDeptChoices(),
                'default' => 0,
            ]),

            'support_email' => new TextboxField([
                'label' => 'Support Email-Adresse',
                'hint' => 'Email-Adresse für Datei-Einreichungen per Email',
                'default' => 'support@example.com',
                'configuration' => [
                    'size' => 60,
                    'length' => 255,
                ],
            ]),

            'auto_response' => new BooleanField([
                'label' => 'Automatische Bestätigung',
                'hint' => 'Sendet eine Bestätigung wenn ein neues Ticket erstellt wird',
                'default' => true,
            ]),

            // ==========================================
            // BENACHRICHTIGUNGEN
            // ==========================================
            'notification_settings' => new SectionBreakField([
                'label' => 'Benachrichtigungen',
            ]),

            'notify_on_reply' => new BooleanField([
                'label' => 'Bei Agent-Antwort benachrichtigen',
                'hint' => 'Sendet Agent-Antworten automatisch an WhatsApp',
                'default' => true,
            ]),

            'notify_on_close' => new BooleanField([
                'label' => 'Bei Ticket-Schließung benachrichtigen',
                'hint' => 'Benachrichtigt den Kunden wenn das Ticket geschlossen wird',
                'default' => true,
            ]),

            'notify_on_message_added' => new BooleanField([
                'label' => 'Bei Nachricht-Hinzufügung benachrichtigen',
                'hint' => 'Sendet eine kurze Bestätigung wenn der Kunde eine Nachricht zu einem bestehenden Ticket sendet',
                'default' => true,
            ]),

            'signature' => new TextboxField([
                'label' => 'Signatur',
                'hint' => 'Signatur am Ende von Agent-Antworten. Verwendbar als {signature} in allen Vorlagen.',
                'default' => 'Ihr Support-Team',
                'configuration' => [
                    'size' => 40,
                    'length' => 100,
                ],
            ]),

            // ==========================================
            // KUNDEN-BEFEHLE (nur Keywords)
            // ==========================================
            'customer_commands' => new SectionBreakField([
                'label' => 'Kunden-Befehle',
            ]),

            'close_keyword' => new TextboxField([
                'label' => 'Schließen-Stichwort',
                'hint' => 'Wort das Kunden senden um ihr Ticket zu schließen',
                'default' => 'SCHLIESSEN',
                'configuration' => [
                    'size' => 30,
                    'length' => 50,
                ],
            ]),

            'switch_keyword' => new TextboxField([
                'label' => 'Ticket-Wechsel Stichwort',
                'hint' => 'Stichwort zum Wechseln des aktiven Tickets (Format: STICHWORT #Ticketnummer)',
                'default' => 'Ticket-Wechsel',
                'configuration' => [
                    'size' => 30,
                    'length' => 50,
                ],
            ]),

            'list_keyword' => new TextboxField([
                'label' => 'Ticket-Liste Stichwort',
                'hint' => 'Stichwort um alle offenen Tickets anzuzeigen',
                'default' => 'OFFEN',
                'configuration' => [
                    'size' => 30,
                    'length' => 50,
                ],
            ]),

            'new_keyword' => new TextboxField([
                'label' => 'Neues-Ticket Stichwort',
                'hint' => 'Wort das Kunden senden um ein neues Ticket zu starten (ohne aktuelles zu schließen)',
                'default' => 'NEU',
                'configuration' => [
                    'size' => 30,
                    'length' => 50,
                ],
            ]),

            'signal_words_list' => new TextareaField([
                'label' => 'Signalwörter (ignorieren)',
                'hint' => 'Wörter die kein neues Ticket erstellen sollen (eins pro Zeile, case-insensitive). Diese Wörter werden ignoriert wenn kein Ticket offen ist.',
                'default' => "Danke\nDankeschön\nVielen Dank\nErledigt\nGelöst\nProblem gelöst\nOk\nOkay\nAlles klar\nVerstanden\nSuper\nPerfekt\nTop\nPasst\nJa\nNein\nGut\nPrima\nFertig\nFunktioniert\nGeht wieder\nHat geklappt\nTschüss\nBye\nCiao",
                'configuration' => [
                    'rows' => 8,
                    'cols' => 40,
                ],
            ]),

            // ==========================================
            // NACHRICHTEN-VORLAGEN
            // ==========================================
            'message_templates' => new SectionBreakField([
                'label' => 'Nachrichten-Vorlagen',
            ]),

            'confirmation_message' => new TextareaField([
                'label' => 'Bestätigungsnachricht (neues Ticket)',
                'hint' => self::VARIABLES_HINT,
                'default' => "Vielen Dank für Ihre Nachricht, {name}!\n\nIhr Ticket wurde erstellt.\nTicket-Nummer: #{ticket_number}\n\n*Wichtig:* Sie können via WhatsApp immer nur ein Ticket gleichzeitig bearbeiten. Alle Ihre Nachrichten werden diesem Ticket zugeordnet.\n\nUm zu einem anderen Ticket zu wechseln, senden Sie:\n{switch_keyword} #[Ihre-Ticketnummer]\n\nUm Ihre offenen Tickets anzuzeigen, senden Sie:\n{list_keyword}\n\nUm ein neues Ticket zu erstellen (ohne dieses zu schließen), senden Sie:\n{new_keyword}\n\nUm dieses Ticket zu schließen, senden Sie:\n{close_keyword}\n\nWir melden uns schnellstmöglich bei Ihnen.",
                'configuration' => [
                    'rows' => 12,
                    'cols' => 60,
                ],
            ]),

            'closed_ticket_message' => new TextareaField([
                'label' => 'Ticket-geschlossen Nachricht',
                'hint' => self::VARIABLES_HINT,
                'default' => "Ihr Ticket #{ticket_number} - {ticket_subject} wurde geschlossen.\n\nFalls Sie weitere Fragen haben, senden Sie uns einfach eine neue Nachricht.",
                'configuration' => [
                    'rows' => 4,
                    'cols' => 60,
                ],
            ]),

            'message_added_confirmation' => new TextareaField([
                'label' => 'Nachricht-hinzugefügt Bestätigung',
                'hint' => self::VARIABLES_HINT,
                'default' => "Ihre Nachricht wurde zu Ticket #{ticket_number} hinzugefügt.\n\nWir melden uns schnellstmöglich bei Ihnen.",
                'configuration' => [
                    'rows' => 3,
                    'cols' => 60,
                ],
            ]),

            'agent_reply_format' => new TextareaField([
                'label' => 'Agent-Antwort Format',
                'hint' => self::VARIABLES_HINT,
                'default' => "*Antwort zu Ticket #{ticket_number} - {ticket_subject}*\n\n{message}\n\n_{signature}_",
                'configuration' => [
                    'rows' => 5,
                    'cols' => 60,
                ],
            ]),

            'media_response_message' => new TextareaField([
                'label' => 'Antwort bei Medien-Nachricht',
                'hint' => self::VARIABLES_HINT,
                'default' => "Dateien (Bilder, Dokumente, Videos) können leider nicht via WhatsApp eingereicht werden.\n\nBitte senden Sie Ihre Datei per Email an:\n{support_email}\n\nOder klicken Sie hier:\n{email_link}\n\nIhre Ticketnummer #{ticket_number} wird automatisch zugeordnet.",
                'configuration' => [
                    'rows' => 6,
                    'cols' => 60,
                ],
            ]),

            'switch_success_message' => new TextareaField([
                'label' => 'Ticket-Wechsel Bestätigung',
                'hint' => self::VARIABLES_HINT,
                'default' => "Ticket gewechselt!\n\nSie bearbeiten jetzt:\nTicket #{ticket_number} - {ticket_subject}\n\nAlle Ihre Nachrichten werden diesem Ticket zugeordnet.",
                'configuration' => [
                    'rows' => 4,
                    'cols' => 60,
                ],
            ]),

            'switch_error_message' => new TextareaField([
                'label' => 'Ticket-Wechsel Fehler',
                'hint' => self::VARIABLES_HINT,
                'default' => "Ticket #{ticket_number} wurde nicht gefunden oder gehört nicht zu Ihrer Telefonnummer.\n\nBitte prüfen Sie die Ticketnummer.",
                'configuration' => [
                    'rows' => 3,
                    'cols' => 60,
                ],
            ]),

            'list_tickets_message' => new TextareaField([
                'label' => 'Ticket-Liste Nachricht',
                'hint' => self::VARIABLES_HINT,
                'default' => "Sie haben {count} offene Ticket(s):\n\n{ticket_list}\n\nUm zu einem Ticket zu wechseln, senden Sie:\n{switch_keyword} #[Ticketnummer]",
                'configuration' => [
                    'rows' => 5,
                    'cols' => 60,
                ],
            ]),

            'list_no_tickets_message' => new TextareaField([
                'label' => 'Keine Tickets Nachricht',
                'hint' => self::VARIABLES_HINT,
                'default' => "Sie haben aktuell keine offenen Tickets.\n\nSenden Sie uns eine Nachricht um ein neues Ticket zu erstellen.",
                'configuration' => [
                    'rows' => 3,
                    'cols' => 60,
                ],
            ]),

            'new_ticket_message' => new TextareaField([
                'label' => 'Neues-Ticket Bestätigung',
                'hint' => self::VARIABLES_HINT,
                'default' => "Ihre Verbindung zu Ticket #{ticket_number} wurde aufgehoben.\n\nSenden Sie jetzt Ihre nächste Nachricht um ein neues Ticket zu erstellen.",
                'configuration' => [
                    'rows' => 3,
                    'cols' => 60,
                ],
            ]),

            'control_word_error_message' => new TextareaField([
                'label' => 'Steuerwort-Fehler Nachricht',
                'hint' => self::VARIABLES_HINT . ', {keyword}, {expected_format}',
                'default' => "Ungültiges Format für '{keyword}'.\n\nErwartetes Format: {expected_format}\n\nBeispiele:\n- {switch_keyword} #12345\n- {close_keyword}\n- {list_keyword}",
                'configuration' => [
                    'rows' => 6,
                    'cols' => 60,
                ],
            ]),

            'ticket_not_found_message' => new TextareaField([
                'label' => 'Ticket nicht gefunden',
                'hint' => self::VARIABLES_HINT,
                'default' => "Ticket #{ticket_number} wurde nicht gefunden.\n\nMöglicherweise wurde es bereits gelöscht.",
                'configuration' => [
                    'rows' => 3,
                    'cols' => 60,
                ],
            ]),

            'ticket_already_closed_message' => new TextareaField([
                'label' => 'Ticket bereits geschlossen',
                'hint' => self::VARIABLES_HINT,
                'default' => "Ticket #{ticket_number} ist bereits geschlossen.\n\nSenden Sie eine neue Nachricht um ein neues Ticket zu erstellen.",
                'configuration' => [
                    'rows' => 3,
                    'cols' => 60,
                ],
            ]),

            'close_failed_message' => new TextareaField([
                'label' => 'Schließen fehlgeschlagen',
                'hint' => self::VARIABLES_HINT,
                'default' => "Das Ticket #{ticket_number} konnte leider nicht automatisch geschlossen werden.\n\nIhr Anliegen wurde als Notiz hinterlegt. Ein Mitarbeiter wird sich darum kümmern.",
                'configuration' => [
                    'rows' => 4,
                    'cols' => 60,
                ],
            ]),

        ];
    }

    /**
     * Lädt verfügbare Help Topics als Auswahloptionen
     *
     * Wird für das Dropdown-Feld 'default_topic_id' verwendet.
     *
     * @return array Assoziatives Array [id => name]
     */
    static function getTopicChoices()
    {
        $choices = [0 => '-- Standard verwenden --'];

        if (class_exists('Topic')) {
            $topics = Topic::getHelpTopics(false, Topic::DISPLAY_DISABLED);
            foreach ($topics as $id => $name) {
                $choices[$id] = $name;
            }
        }

        return $choices;
    }

    /**
     * Lädt verfügbare Abteilungen als Auswahloptionen
     *
     * Wird für das Dropdown-Feld 'default_dept_id' verwendet.
     *
     * @return array Assoziatives Array [id => name]
     */
    static function getDeptChoices()
    {
        $choices = [0 => '-- Standard verwenden --'];

        if (class_exists('Dept')) {
            $depts = Dept::getDepartments();
            foreach ($depts as $id => $name) {
                $choices[$id] = $name;
            }
        }

        return $choices;
    }

    /**
     * Validiert die Konfiguration vor dem Speichern
     *
     * Wird aufgerufen bevor die Konfiguration gespeichert wird.
     * Prüft ob die Service-URL gültig ist.
     *
     * @param array $config Konfigurationsdaten (by reference)
     * @param array $errors Fehler-Array (by reference)
     * @return bool True wenn Validierung erfolgreich
     */
    function pre_save(&$config, &$errors)
    {
        // Service-URL validieren
        $url = $config['service_url'];
        if ($url && !filter_var($url, FILTER_VALIDATE_URL)) {
            $errors['err'] = 'Ungültige Service URL';
            return false;
        }

        return true;
    }
}
