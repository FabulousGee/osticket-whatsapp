/**
 * WhatsApp Service Constants
 *
 * Zentrale Definition aller Konstanten für den WhatsApp-Service.
 * Vermeidet Magic Numbers und Strings im gesamten Code.
 */

module.exports = {
    // ==========================================
    // RECONNECTION
    // ==========================================
    MAX_RECONNECT_ATTEMPTS: 5,
    RECONNECT_DELAY_MS: 250,

    // ==========================================
    // CACHING
    // ==========================================
    MESSAGE_CACHE_TTL_MS: 60000,
    MESSAGE_CACHE_CLEANUP_INTERVAL_MS: 30000,
    RETRY_COUNTER_TTL_SEC: 600,
    RETRY_CHECK_PERIOD_SEC: 60,
    MESSAGE_STORE_TTL_SEC: 3600,

    // ==========================================
    // TIMEOUTS
    // ==========================================
    QUERY_TIMEOUT_MS: 60000,
    CONNECT_TIMEOUT_MS: 60000,
    KEEPALIVE_INTERVAL_MS: 30000,

    // ==========================================
    // SERVER
    // ==========================================
    DEFAULT_PORT: 3000,
    DEFAULT_HOST: '127.0.0.1',

    // ==========================================
    // WEBHOOK
    // ==========================================
    WEBHOOK_MAX_RETRIES: 3,
    WEBHOOK_RETRY_DELAY_MS: 1000,

    // ==========================================
    // VALIDATION
    // ==========================================
    MIN_PHONE_LENGTH: 10,
    MAX_PHONE_LENGTH: 15,
    MAX_MESSAGE_LENGTH: 4096,

    // ==========================================
    // MESSAGE TYPES
    // ==========================================
    MESSAGE_TYPES: {
        TEXT: 'text',
        IMAGE: 'image',
        DOCUMENT: 'document',
        AUDIO: 'audio',
        VIDEO: 'video',
        STICKER: 'sticker',
        CONTACT: 'contact',
        LOCATION: 'location'
    },

    // ==========================================
    // FALLBACK LABELS (German)
    // ==========================================
    LABELS: {
        IMAGE: '[Bild]',
        DOCUMENT: '[Dokument]',
        AUDIO: '[Sprachnachricht]',
        VIDEO: '[Video]',
        STICKER: '[Sticker]',
        CONTACT: '[Kontakt]',
        LOCATION: '[Standort]',
        UNKNOWN: '[Unbekannter Nachrichtentyp]'
    },

    // ==========================================
    // CONNECTION STATES
    // ==========================================
    CONNECTION_STATES: {
        DISCONNECTED: 'disconnected',
        CONNECTING: 'connecting',
        CONNECTED: 'connected',
        QR_READY: 'qr_ready'
    },

    // ==========================================
    // GERMAN COUNTRY CODE
    // ==========================================
    GERMAN_COUNTRY_CODE: '49',
    GERMAN_MOBILE_PREFIXES: ['15', '16', '17'],

    // ==========================================
    // ERROR MESSAGES
    // ==========================================
    ERRORS: {
        PHONE_REQUIRED: 'Phone number is required',
        MESSAGE_REQUIRED: 'Message is required',
        INVALID_PHONE: 'Invalid phone number format',
        NOT_CONNECTED: 'WhatsApp not connected',
        INVALID_API_KEY: 'Invalid API key',
        API_KEY_NOT_SET: 'Server misconfiguration: API_KEY not set',
        WEBHOOK_URL_NOT_SET: 'WEBHOOK_URL not configured'
    }
};
