<?php
/**
 * WhatsApp Plugin Configuration
 */

require_once INCLUDE_DIR . 'class.plugin.php';
require_once INCLUDE_DIR . 'class.forms.php';

class WhatsAppPluginConfig extends PluginConfig
{
    function getOptions()
    {
        return [
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
                'hint' => 'API-Schluessel fuer sichere Kommunikation mit dem Service',
                'configuration' => [
                    'size' => 60,
                    'length' => 255,
                ],
            ]),

            'webhook_settings' => new SectionBreakField([
                'label' => 'Webhook Einstellungen',
            ]),

            'webhook_secret' => new TextboxField([
                'label' => 'Webhook Secret',
                'hint' => 'Geheimer Schluessel zur Authentifizierung eingehender Webhooks',
                'default' => bin2hex(random_bytes(16)),
                'configuration' => [
                    'size' => 60,
                    'length' => 64,
                ],
            ]),

            'ticket_settings' => new SectionBreakField([
                'label' => 'Ticket Einstellungen',
            ]),

            'default_topic_id' => new ChoiceField([
                'label' => 'Standard Help Topic',
                'hint' => 'Help Topic fuer neue WhatsApp-Tickets',
                'choices' => self::getTopicChoices(),
                'default' => 0,
            ]),

            'default_dept_id' => new ChoiceField([
                'label' => 'Standard Abteilung',
                'hint' => 'Abteilung fuer neue WhatsApp-Tickets',
                'choices' => self::getDeptChoices(),
                'default' => 0,
            ]),

            'support_email' => new TextboxField([
                'label' => 'Support Email-Adresse',
                'hint' => 'Email-Adresse fuer Datei-Einreichungen per Email',
                'default' => 'support@example.com',
                'configuration' => [
                    'size' => 60,
                    'length' => 255,
                ],
            ]),

            'auto_response' => new BooleanField([
                'label' => 'Automatische Bestaetigung',
                'hint' => 'Sendet eine Bestaetigung wenn ein neues Ticket erstellt wird',
                'default' => true,
            ]),

            'confirmation_message' => new TextareaField([
                'label' => 'Bestaetigungsnachricht',
                'hint' => 'Nachricht bei Ticket-Erstellung. Variablen: {ticket_number}, {name}, {close_keyword}, {switch_keyword}',
                'default' => "Vielen Dank fuer Ihre Nachricht, {name}!\n\nIhr Ticket wurde erstellt.\nTicket-Nummer: #{ticket_number}\n\n*Wichtig:* Sie koennen via WhatsApp immer nur ein Ticket gleichzeitig bearbeiten. Alle Ihre Nachrichten werden diesem Ticket zugeordnet.\n\nUm zu einem anderen Ticket zu wechseln, senden Sie:\n{switch_keyword} #[Ihre-Ticketnummer]\n\nUm dieses Ticket zu schliessen, senden Sie:\n{close_keyword}\n\nWir melden uns schnellstmoeglich bei Ihnen.",
                'configuration' => [
                    'rows' => 12,
                    'cols' => 60,
                ],
            ]),

            'closed_ticket_message' => new TextareaField([
                'label' => 'Ticket-geschlossen Nachricht',
                'hint' => 'Nachricht wenn ein Ticket geschlossen wird. Variablen: {ticket_number}, {ticket_subject}',
                'default' => "Ihr Ticket #{ticket_number} - {ticket_subject} wurde geschlossen.\n\nFalls Sie weitere Fragen haben, senden Sie uns einfach eine neue Nachricht.",
                'configuration' => [
                    'rows' => 4,
                    'cols' => 60,
                ],
            ]),

            'customer_commands' => new SectionBreakField([
                'label' => 'Kunden-Befehle',
            ]),

            'close_keyword' => new TextboxField([
                'label' => 'Schliessen-Stichwort',
                'hint' => 'Wort das Kunden senden um ihr Ticket zu schliessen',
                'default' => 'SCHLIESSEN',
                'configuration' => [
                    'size' => 30,
                    'length' => 50,
                ],
            ]),

            'close_keywords_list' => new TextareaField([
                'label' => 'Alternative Schliessen-Stichwoerter',
                'hint' => 'Zusaetzliche Woerter die ein Ticket schliessen (eins pro Zeile, case-insensitive)',
                'default' => "Danke\nErledigt\nGeloest\nProblem geloest",
                'configuration' => [
                    'rows' => 5,
                    'cols' => 40,
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

            'switch_success_message' => new TextareaField([
                'label' => 'Ticket-Wechsel Bestaetigung',
                'hint' => 'Nachricht nach erfolgreichem Ticket-Wechsel. Variablen: {ticket_number}, {ticket_subject}',
                'default' => "Ticket gewechselt!\n\nSie bearbeiten jetzt:\nTicket #{ticket_number} - {ticket_subject}\n\nAlle Ihre Nachrichten werden diesem Ticket zugeordnet.",
                'configuration' => [
                    'rows' => 4,
                    'cols' => 60,
                ],
            ]),

            'switch_error_message' => new TextareaField([
                'label' => 'Ticket-Wechsel Fehler',
                'hint' => 'Nachricht wenn Ticket nicht gefunden. Variablen: {ticket_number}',
                'default' => "Ticket #{ticket_number} wurde nicht gefunden oder gehoert nicht zu Ihrer Telefonnummer.\n\nBitte pruefen Sie die Ticketnummer.",
                'configuration' => [
                    'rows' => 3,
                    'cols' => 60,
                ],
            ]),

            'media_settings' => new SectionBreakField([
                'label' => 'Medien-Einstellungen',
            ]),

            'media_response_message' => new TextareaField([
                'label' => 'Antwort bei Medien-Nachricht',
                'hint' => 'Nachricht wenn Bilder/Dokumente gesendet werden. Variablen: {ticket_number}, {email_link}, {support_email}',
                'default' => "Dateien (Bilder, Dokumente, Videos) koennen leider nicht via WhatsApp eingereicht werden.\n\nBitte senden Sie Ihre Datei per Email an:\n{support_email}\n\nOder klicken Sie hier:\n{email_link}\n\nIhre Ticketnummer #{ticket_number} wird automatisch zugeordnet.",
                'configuration' => [
                    'rows' => 6,
                    'cols' => 60,
                ],
            ]),

            'notification_settings' => new SectionBreakField([
                'label' => 'Benachrichtigungen',
            ]),

            'notify_on_reply' => new BooleanField([
                'label' => 'Bei Agent-Antwort benachrichtigen',
                'hint' => 'Sendet Agent-Antworten automatisch an WhatsApp',
                'default' => true,
            ]),

            'notify_on_close' => new BooleanField([
                'label' => 'Bei Ticket-Schliessung benachrichtigen',
                'hint' => 'Benachrichtigt den Kunden wenn das Ticket geschlossen wird',
                'default' => true,
            ]),

            'enabled' => new BooleanField([
                'label' => 'Plugin aktiviert',
                'hint' => 'Aktiviert oder deaktiviert die WhatsApp-Integration',
                'default' => true,
            ]),
        ];
    }

    /**
     * Get available help topics
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
     * Get available departments
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

    function pre_save(&$config, &$errors)
    {
        // Validate service URL
        $url = $config['service_url'];
        if ($url && !filter_var($url, FILTER_VALIDATE_URL)) {
            $errors['err'] = 'Ungueltige Service URL';
            return false;
        }

        return true;
    }
}
