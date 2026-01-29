/**
 * WhatsApp Client using Baileys
 * Handles connection, authentication, and message handling
 */

const {
    default: makeWASocket,
    DisconnectReason,
    useMultiFileAuthState,
    fetchLatestBaileysVersion,
    makeCacheableSignalKeyStore
} = require('@whiskeysockets/baileys');
const pino = require('pino');
const path = require('path');
const { sendWebhook } = require('./webhook');

// Store for connection state
let sock = null;
let qrCode = null;
let connectionState = 'disconnected';
let reconnectAttempts = 0;
const MAX_RECONNECT_ATTEMPTS = 5;

const logger = pino({ level: process.env.LOG_LEVEL || 'info' });
const authFolder = path.join(__dirname, '..', 'auth_info');

/**
 * Initialize WhatsApp connection
 */
async function connectWhatsApp() {
    const { state, saveCreds } = await useMultiFileAuthState(authFolder);
    const { version } = await fetchLatestBaileysVersion();

    sock = makeWASocket({
        version,
        logger: pino({ level: 'silent' }),
        printQRInTerminal: true,
        auth: {
            creds: state.creds,
            keys: makeCacheableSignalKeyStore(state.keys, pino({ level: 'silent' }))
        },
        generateHighQualityLinkPreview: false,
        getMessage: async () => undefined
    });

    // Handle connection updates
    sock.ev.on('connection.update', async (update) => {
        const { connection, lastDisconnect, qr } = update;

        if (qr) {
            qrCode = qr;
            connectionState = 'qr_ready';
            logger.info('QR Code ready - scan with WhatsApp');
        }

        if (connection === 'close') {
            const statusCode = lastDisconnect?.error?.output?.statusCode;
            const shouldReconnect = statusCode !== DisconnectReason.loggedOut;

            logger.warn({ statusCode }, 'Connection closed');
            connectionState = 'disconnected';
            qrCode = null;

            if (shouldReconnect && reconnectAttempts < MAX_RECONNECT_ATTEMPTS) {
                reconnectAttempts++;
                logger.info({ attempt: reconnectAttempts }, 'Reconnecting...');
                setTimeout(connectWhatsApp, 3000);
            } else if (statusCode === DisconnectReason.loggedOut) {
                logger.error('Logged out - delete auth_info folder and restart');
            }
        }

        if (connection === 'open') {
            connectionState = 'connected';
            qrCode = null;
            reconnectAttempts = 0;
            logger.info('WhatsApp connected successfully');
        }
    });

    // Save credentials on update
    sock.ev.on('creds.update', saveCreds);

    // Handle incoming messages
    sock.ev.on('messages.upsert', async ({ messages, type }) => {
        if (type !== 'notify') return;

        for (const msg of messages) {
            // Skip messages from self and status broadcasts
            if (msg.key.fromMe) continue;
            if (msg.key.remoteJid === 'status@broadcast') continue;

            await handleIncomingMessage(msg);
        }
    });

    return sock;
}

/**
 * Handle incoming WhatsApp message
 */
async function handleIncomingMessage(msg) {
    try {
        const jid = msg.key.remoteJid;
        const isGroup = jid.endsWith('@g.us');

        // Skip group messages (optional - can be enabled later)
        if (isGroup) {
            logger.debug({ jid }, 'Skipping group message');
            return;
        }

        // Extract phone number (remove @s.whatsapp.net)
        const phone = jid.replace('@s.whatsapp.net', '');

        // Get sender name
        const pushName = msg.pushName || 'Unknown';

        // Extract message content
        const content = extractMessageContent(msg);
        if (!content) {
            logger.debug('No content to process');
            return;
        }

        logger.info({
            phone,
            name: pushName,
            type: content.type,
            preview: content.text?.substring(0, 50)
        }, 'Incoming message');

        // Send to webhook (osTicket)
        await sendWebhook({
            messageId: msg.key.id,
            phone,
            name: pushName,
            type: content.type,
            text: content.text,
            mediaUrl: content.mediaUrl,
            timestamp: msg.messageTimestamp
        });

    } catch (error) {
        logger.error({ error: error.message }, 'Error handling message');
    }
}

/**
 * Extract message content based on type
 */
function extractMessageContent(msg) {
    const message = msg.message;
    if (!message) return null;

    // Text message
    if (message.conversation) {
        return { type: 'text', text: message.conversation };
    }

    if (message.extendedTextMessage?.text) {
        return { type: 'text', text: message.extendedTextMessage.text };
    }

    // Image
    if (message.imageMessage) {
        return {
            type: 'image',
            text: message.imageMessage.caption || '[Bild]',
            mediaUrl: null // Media download not implemented yet
        };
    }

    // Document
    if (message.documentMessage) {
        return {
            type: 'document',
            text: `[Dokument: ${message.documentMessage.fileName || 'Unbekannt'}]`
        };
    }

    // Audio/Voice
    if (message.audioMessage) {
        return { type: 'audio', text: '[Sprachnachricht]' };
    }

    // Video
    if (message.videoMessage) {
        return {
            type: 'video',
            text: message.videoMessage.caption || '[Video]'
        };
    }

    // Location
    if (message.locationMessage) {
        const loc = message.locationMessage;
        return {
            type: 'location',
            text: `[Standort: ${loc.degreesLatitude}, ${loc.degreesLongitude}]`
        };
    }

    // Contact
    if (message.contactMessage) {
        return {
            type: 'contact',
            text: `[Kontakt: ${message.contactMessage.displayName || 'Unbekannt'}]`
        };
    }

    // Sticker
    if (message.stickerMessage) {
        return { type: 'sticker', text: '[Sticker]' };
    }

    // Unknown type
    logger.debug({ messageTypes: Object.keys(message) }, 'Unknown message type');
    return { type: 'unknown', text: '[Nicht unterstuetzter Nachrichtentyp]' };
}

/**
 * Send a text message
 */
async function sendMessage(phone, text) {
    if (!sock || connectionState !== 'connected') {
        throw new Error('WhatsApp not connected');
    }

    // Format phone number to JID
    const jid = formatPhoneToJid(phone);

    const result = await sock.sendMessage(jid, { text });
    logger.info({ phone, messageId: result.key.id }, 'Message sent');

    return {
        success: true,
        messageId: result.key.id
    };
}

/**
 * Format phone number to WhatsApp JID
 */
function formatPhoneToJid(phone) {
    // Remove all non-numeric characters
    let cleaned = phone.replace(/\D/g, '');

    // Remove leading zeros
    cleaned = cleaned.replace(/^0+/, '');

    // Add country code if missing (assuming German numbers)
    if (cleaned.length === 10 || cleaned.length === 11) {
        // Could be missing country code
        if (!cleaned.startsWith('49') && !cleaned.startsWith('43') && !cleaned.startsWith('41')) {
            // Assume German number if starts with typical mobile prefix
            if (cleaned.startsWith('1') || cleaned.startsWith('15') || cleaned.startsWith('16') || cleaned.startsWith('17')) {
                cleaned = '49' + cleaned;
            }
        }
    }

    return `${cleaned}@s.whatsapp.net`;
}

/**
 * Get current connection status
 */
function getStatus() {
    return {
        state: connectionState,
        hasQr: !!qrCode
    };
}

/**
 * Get QR code for authentication
 */
function getQrCode() {
    return qrCode;
}

/**
 * Check if connected
 */
function isConnected() {
    return connectionState === 'connected';
}

module.exports = {
    connectWhatsApp,
    sendMessage,
    getStatus,
    getQrCode,
    isConnected
};
