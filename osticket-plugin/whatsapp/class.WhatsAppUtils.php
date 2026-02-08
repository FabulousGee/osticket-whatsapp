<?php
/**
 * WhatsApp Plugin Utilities
 *
 * Zentrale Utility-Klasse für das WhatsApp-Plugin.
 * Enthält gemeinsam genutzte Funktionen für Plugin, Webhook und API-Client.
 *
 * @package WhatsAppPlugin
 * @version 1.0.0
 */

require_once __DIR__ . '/class.WhatsAppConstants.php';

class WhatsAppUtils
{
    /**
     * Ersetzt Variablen in einem Template-String
     *
     * Alle Variablen sind in allen Templates verfügbar.
     * Nicht gesetzte Variablen werden durch leere Strings ersetzt.
     *
     * @param string $template Template mit {variable} Platzhaltern
     * @param PluginConfig $config Plugin-Konfigurationsobjekt
     * @param array $contextVars Kontext-spezifische Variablen
     * @return string Template mit ersetzten Variablen
     */
    public static function replaceVariables($template, $config, $contextVars = [])
    {
        // ALLE verfügbaren Variablen zusammenstellen
        $vars = [
            // Keywords und Signatur aus Config
            'close_keyword' => $config->get('close_keyword') ?: WhatsAppConstants::DEFAULT_CLOSE_KEYWORD,
            'switch_keyword' => $config->get('switch_keyword') ?: WhatsAppConstants::DEFAULT_SWITCH_KEYWORD,
            'new_keyword' => $config->get('new_keyword') ?: WhatsAppConstants::DEFAULT_NEW_KEYWORD,
            'list_keyword' => $config->get('list_keyword') ?: WhatsAppConstants::DEFAULT_LIST_KEYWORD,
            'support_email' => $config->get('support_email') ?: WhatsAppConstants::DEFAULT_SUPPORT_EMAIL,
            'signature' => $config->get('signature') ?: WhatsAppConstants::DEFAULT_SIGNATURE,

            // Kontext-spezifische Variablen (Defaults)
            'ticket_number' => '',
            'ticket_subject' => '',
            'name' => '',
            'message' => '',
            'agent_name' => '',
            'ticket_list' => '',
            'count' => '',
            'email_link' => '',
            'keyword' => '',
            'expected_format' => '',
        ];

        // Kontext-Variablen überschreiben die Defaults
        $vars = array_merge($vars, $contextVars);

        // Alle Variablen ersetzen
        foreach ($vars as $key => $value) {
            $template = str_replace('{' . $key . '}', $value, $template);
        }

        return $template;
    }

    /**
     * Konvertiert HTML zu WhatsApp-kompatiblem Text
     *
     * Wandelt HTML-Tags in entsprechende Textformatierungen um.
     * Berücksichtigt osTicket-spezifische HTML-Strukturen.
     *
     * @param string $html HTML-String
     * @return string Bereinigter Text für WhatsApp
     */
    public static function htmlToWhatsAppText($html)
    {
        // 1. Paragraph-Ende (</p>) durch doppelte Zeilenumbrüche ersetzen
        $text = preg_replace('/<\/p>\s*/i', "\n\n", $html);

        // 2. Paragraph-Anfang (<p>) entfernen
        $text = preg_replace('/<p[^>]*>/i', '', $text);

        // 3. <br>-Tags (alle Varianten) in Zeilenumbrüche umwandeln
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text);

        // 4. <div>-Tags als Zeilenumbrüche behandeln
        $text = preg_replace('/<\/div>\s*/i', "\n", $text);
        $text = preg_replace('/<div[^>]*>/i', '', $text);

        // 5. Restliche HTML-Tags entfernen
        $text = strip_tags($text);

        // 6. HTML-Entities dekodieren
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');

        // 7. Non-breaking spaces durch normale Leerzeichen ersetzen
        $text = str_replace("\xc2\xa0", ' ', $text);

        // 8. Mehrfache Zeilenumbrüche reduzieren (max. 2)
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        // 9. Whitespace trimmen
        return trim($text);
    }

    /**
     * Bereinigt und normalisiert Telefonnummern
     *
     * Entfernt alle Nicht-Ziffern und fügt bei deutschen Nummern
     * den Ländercode hinzu falls fehlend.
     *
     * @param string $phone Telefonnummer in beliebigem Format
     * @return string Bereinigte Telefonnummer (nur Ziffern mit Ländercode)
     */
    public static function normalizePhone($phone)
    {
        // Nur Ziffern behalten
        $cleaned = preg_replace('/\D/', '', $phone);

        // Leere oder zu kurze Nummern
        if (strlen($cleaned) < WhatsAppConstants::MIN_PHONE_LENGTH) {
            return $cleaned;
        }

        // Deutsche Nummer ohne Ländercode?
        if (strlen($cleaned) >= 10 && strlen($cleaned) <= 12) {
            // Nummer beginnt mit 0 (lokale deutsche Nummer)
            if (substr($cleaned, 0, 1) === '0') {
                $cleaned = '49' . substr($cleaned, 1);
            }
            // Nummer beginnt mit deutscher Vorwahl ohne 49
            elseif (preg_match('/^1[5-7]\d/', $cleaned)) {
                $cleaned = '49' . $cleaned;
            }
        }

        return $cleaned;
    }

    /**
     * Formatiert eine Telefonnummer für die Anzeige
     *
     * @param string $phone Telefonnummer (bereinigt)
     * @return string Formatierte Telefonnummer
     */
    public static function formatPhoneForDisplay($phone)
    {
        $cleaned = self::normalizePhone($phone);

        // Deutsche Nummer formatieren
        if (substr($cleaned, 0, 2) === '49' && strlen($cleaned) >= 12) {
            return '+49 ' . substr($cleaned, 2, 3) . ' ' . substr($cleaned, 5);
        }

        // Allgemein: mit + Prefix
        return '+' . $cleaned;
    }

    /**
     * Validiert eine Telefonnummer
     *
     * @param string $phone Telefonnummer
     * @return bool True wenn gültig
     */
    public static function isValidPhone($phone)
    {
        $cleaned = preg_replace('/\D/', '', $phone);
        $length = strlen($cleaned);

        return $length >= WhatsAppConstants::MIN_PHONE_LENGTH
            && $length <= WhatsAppConstants::MAX_PHONE_LENGTH;
    }

    /**
     * Sanitized einen Namen für die Verwendung in osTicket
     *
     * Entfernt ungültige Zeichen und begrenzt die Länge.
     *
     * @param string $name Eingabename
     * @param int $maxLength Maximale Länge (default: 64)
     * @return string Bereinigter Name
     */
    public static function sanitizeName($name, $maxLength = 64)
    {
        // Whitespace normalisieren
        $name = preg_replace('/\s+/', ' ', trim($name));

        // Nur alphanumerische Zeichen, Leerzeichen und einfache Sonderzeichen
        $name = preg_replace('/[^\p{L}\p{N}\s\-_.@]/u', '', $name);

        // Länge begrenzen
        if (strlen($name) > $maxLength) {
            $name = substr($name, 0, $maxLength);
        }

        // Fallback wenn leer
        return $name ?: 'WhatsApp User';
    }

    /**
     * Erstellt einen Email-Link für Datei-Uploads
     *
     * @param string $supportEmail Support-Email-Adresse
     * @param string $ticketNumber Ticketnummer
     * @return string mailto-Link
     */
    public static function createEmailLink($supportEmail, $ticketNumber)
    {
        $subject = urlencode("Zu Ticket #{$ticketNumber}");
        return "mailto:{$supportEmail}?subject={$subject}";
    }

    /**
     * Führt eine sichere Datenbankabfrage aus
     *
     * @param string $sql SQL-Statement
     * @param string $errorMessage Fehlermeldung für Log
     * @return resource|false Datenbankresult oder false bei Fehler
     * @throws Exception Bei Datenbankfehler
     */
    public static function executeQuery($sql, $errorMessage = 'Database query failed')
    {
        $result = db_query($sql);

        if (!$result) {
            error_log("WhatsApp Plugin: {$errorMessage} - SQL: " . substr($sql, 0, 500));
            throw new Exception($errorMessage);
        }

        return $result;
    }

    /**
     * Konvertiert ein Array von IDs in einen sicheren SQL-String
     *
     * @param array $ids Array von IDs
     * @return string Komma-separierter String von Integers
     */
    public static function idsToSqlList(array $ids)
    {
        // Sicherstellen dass alle Werte Integers sind (SQL Injection Prevention)
        return implode(',', array_map('intval', $ids));
    }
}
