<?php
/**
 * WhatsApp Plugin Konstanten
 *
 * Zentrale Definition aller Konstanten für das WhatsApp-Plugin.
 * Vermeidet Magic Numbers und Strings im gesamten Code.
 *
 * @package WhatsAppPlugin
 * @version 1.0.0
 */

class WhatsAppConstants
{
    // ==========================================
    // MAPPING STATUS
    // ==========================================
    const STATUS_OPEN = 'open';
    const STATUS_CLOSED = 'closed';
    const STATUS_INACTIVE = 'inactive';

    // ==========================================
    // MESSAGE TYPES
    // ==========================================
    const MSG_TYPE_TEXT = 'text';
    const MSG_TYPE_IMAGE = 'image';
    const MSG_TYPE_DOCUMENT = 'document';
    const MSG_TYPE_AUDIO = 'audio';
    const MSG_TYPE_VIDEO = 'video';
    const MSG_TYPE_STICKER = 'sticker';
    const MSG_TYPE_CONTACT = 'contact';
    const MSG_TYPE_LOCATION = 'location';

    // Media-Types die nicht direkt verarbeitet werden können
    const MEDIA_TYPES = [
        self::MSG_TYPE_IMAGE,
        self::MSG_TYPE_DOCUMENT,
        self::MSG_TYPE_AUDIO,
        self::MSG_TYPE_VIDEO,
        self::MSG_TYPE_STICKER,
    ];

    // ==========================================
    // DEFAULT KEYWORDS
    // ==========================================
    const DEFAULT_CLOSE_KEYWORD = 'SCHLIESSEN';
    const DEFAULT_SWITCH_KEYWORD = 'Ticket-Wechsel';
    const DEFAULT_NEW_KEYWORD = 'NEU';
    const DEFAULT_LIST_KEYWORD = 'OFFEN';
    const DEFAULT_SIGNATURE = 'Ihr Support-Team';
    const DEFAULT_SUPPORT_EMAIL = 'support@example.com';

    // ==========================================
    // LIMITS
    // ==========================================
    const MAX_MESSAGE_LENGTH = 4096;
    const MIN_PHONE_LENGTH = 10;
    const MAX_PHONE_LENGTH = 15;
    const MIN_TICKET_NUMBER_LENGTH = 1;
    const MAX_TICKET_NUMBER_LENGTH = 20;

    // ==========================================
    // DATABASE
    // ==========================================
    const EMAIL_DOMAIN = 'tickets.local';
    const EMAIL_PREFIX = 'whatsapp+';

    // ==========================================
    // MESSAGE DIRECTIONS
    // ==========================================
    const DIRECTION_IN = 'in';
    const DIRECTION_OUT = 'out';

    // ==========================================
    // TICKET SOURCE
    // ==========================================
    const TICKET_SOURCE = 'API';
    const TICKET_SUBJECT_PREFIX = 'WhatsApp Anfrage von';

    /**
     * Generiert die WhatsApp-Email für eine Telefonnummer
     *
     * @param string $phone Telefonnummer (bereinigt)
     * @return string Email-Adresse
     */
    public static function generateEmail($phone)
    {
        $cleanPhone = preg_replace('/\D/', '', $phone);
        return self::EMAIL_PREFIX . $cleanPhone . '@' . self::EMAIL_DOMAIN;
    }

    /**
     * Generiert den Ticket-Betreff für WhatsApp-Anfragen
     *
     * @param string $name Name des Absenders
     * @return string Ticket-Betreff
     */
    public static function generateTicketSubject($name)
    {
        return self::TICKET_SUBJECT_PREFIX . ' ' . $name;
    }

    /**
     * Prüft ob ein Nachrichtentyp ein Media-Typ ist
     *
     * @param string $type Nachrichtentyp
     * @return bool True wenn Media-Typ
     */
    public static function isMediaType($type)
    {
        return in_array($type, self::MEDIA_TYPES, true);
    }
}
