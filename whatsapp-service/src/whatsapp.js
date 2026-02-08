/**
 * WhatsApp Client using Baileys
 * Handles connection, authentication, and message handling
 */

// ============================================
// Console Filter for Non-Critical Errors
// ============================================
// Baileys/libsignal sometimes logs directly to console.error
// Filter out known non-critical Signal protocol errors
const originalConsoleError = console.error;
const originalConsoleWarn = console.warn;

const nonCriticalPatterns = [
    'Bad MAC',
    'Failed to decrypt',
    'decrypt',
    'no matching sessions',
    'MessageCounterError',
    'invalid PreKey',
    'Session error',
    'Error: Bad MAC',
];

function shouldFilter(args) {
    const message = args.map(a => String(a)).join(' ');
    return nonCriticalPatterns.some(pattern =>
        message.toLowerCase().includes(pattern.toLowerCase())
    );
}

console.error = (...args) => {
    if (!shouldFilter(args)) {
        originalConsoleError.apply(console, args);
    }
};

console.warn = (...args) => {
    if (!shouldFilter(args)) {
        originalConsoleWarn.apply(console, args);
    }
};

const {
    default: makeWASocket,
    DisconnectReason,
    useMultiFileAuthState,
    fetchLatestBaileysVersion,
    makeCacheableSignalKeyStore,
    proto,
    isJidBroadcast,
    isJidNewsletter,
    isJidGroup
} = require('@whiskeysockets/baileys');
const pino = require('pino');
const path = require('path');
const fs = require('fs');
const qrcodeTerminal = require('qrcode-terminal');
const NodeCache = require('node-cache');
const { sendWebhook } = require('./webhook');

// Store for connection state
let sock = null;
let qrCode = null;
let connectionState = 'disconnected';
let reconnectAttempts = 0;
const MAX_RECONNECT_ATTEMPTS = 5;

// Message deduplication cache (Message-ID -> timestamp)
const processedMessages = new Map();
const MESSAGE_CACHE_TTL = 60000; // 60 seconds
const MESSAGE_CACHE_CLEANUP_INTERVAL = 30000; // 30 seconds

// ============================================
// Baileys Store & Retry Configuration
// ============================================
// Using NodeCache as recommended by Baileys for retry handling
// This helps with Bad MAC errors by properly tracking retry attempts

// Message retry counter cache - MUST be external to prevent retry loops
const msgRetryCounterCache = new NodeCache({
    stdTTL: 600,        // 10 minutes TTL
    checkperiod: 60,    // Check for expired entries every 60 seconds
    useClones: false
});

// Simple message store for retry mechanism
// Stores messages by JID and message ID for getMessage callback
const messageStore = new NodeCache({
    stdTTL: 3600,       // 1 hour TTL
    checkperiod: 120,   // Check every 2 minutes
    useClones: false
});

/**
 * Store a message for potential retry requests
 */
function storeMessage(msg) {
    if (msg?.key?.remoteJid && msg?.key?.id && msg?.message) {
        const key = `${msg.key.remoteJid}_${msg.key.id}`;
        messageStore.set(key, msg.message);
    }
}

/**
 * Retrieve a stored message for retry
 */
function getStoredMessage(key) {
    const storeKey = `${key.remoteJid}_${key.id}`;
    return messageStore.get(storeKey);
}

// Cleanup old entries periodically (only processedMessages, NodeCache handles its own cleanup)
setInterval(() => {
    const now = Date.now();
    // Cleanup processed messages cache
    for (const [id, timestamp] of processedMessages) {
        if (now - timestamp > MESSAGE_CACHE_TTL) {
            processedMessages.delete(id);
        }
    }
}, MESSAGE_CACHE_CLEANUP_INTERVAL);

const logger = pino({ level: process.env.LOG_LEVEL || 'info' });
const authFolder = path.join(__dirname, '..', 'auth_info');

// ============================================
// Signal Protocol Error Handling
// ============================================
// "Bad MAC" errors are non-critical and occur due to:
// - Session mismatches after reconnects
// - Out-of-order messages
// - Duplicate decryption attempts
// - Key rotation with pending messages
// These are handled internally by Baileys and don't affect functionality.

/**
 * Check if an error is a non-critical Signal protocol error
 */
function isNonCriticalSignalError(error) {
    if (!error) return false;
    const message = error.message || error.toString() || '';
    const nonCriticalPatterns = [
        'Bad MAC',
        'decrypt',
        'Failed to decrypt',
        'no matching sessions',
        'MessageCounterError',
        'invalid PreKey',
    ];
    return nonCriticalPatterns.some(pattern =>
        message.toLowerCase().includes(pattern.toLowerCase())
    );
}

// Global handler for unhandled promise rejections
process.on('unhandledRejection', (reason, promise) => {
    if (isNonCriticalSignalError(reason)) {
        logger.debug({ reason: reason?.message }, 'Ignored non-critical Signal protocol error');
        return;
    }
    logger.error({ reason }, 'Unhandled promise rejection');
});

// Global handler for uncaught exceptions (safety net)
process.on('uncaughtException', (error) => {
    if (isNonCriticalSignalError(error)) {
        logger.debug({ error: error.message }, 'Ignored non-critical Signal protocol exception');
        return;
    }
    logger.error({ error }, 'Uncaught exception');
    // Don't exit for non-critical errors, but exit for critical ones
    process.exit(1);
});

/**
 * Initialize WhatsApp connection
 */
async function connectWhatsApp() {
    const { state, saveCreds } = await useMultiFileAuthState(authFolder);
    const { version } = await fetchLatestBaileysVersion();

    sock = makeWASocket({
        version,
        logger: pino({ level: 'silent' }),
        auth: {
            creds: state.creds,
            keys: makeCacheableSignalKeyStore(state.keys, pino({ level: 'silent' }))
        },
        generateHighQualityLinkPreview: false,
        // Skip JIDs that we don't need - reduces Bad MAC errors significantly
        // See: https://github.com/WhiskeySockets/Baileys/issues/1769#issuecomment-3702277925
        shouldIgnoreJid: jid => isJidBroadcast(jid) || isJidNewsletter(jid) || isJidGroup(jid),
        // Message retry mechanism for Bad MAC errors
        // NodeCache is recommended by Baileys to prevent retry loops
        msgRetryCounterCache,
        // getMessage is called when the OTHER party requests a retry
        // or when we need to retry decryption
        getMessage: async (key) => {
            // Try to get message from our store
            const storedMsg = getStoredMessage(key);
            if (storedMsg) {
                logger.info({ id: key.id }, 'Retrieved message from store for retry');
                return storedMsg;
            }
            // Fallback: return empty proto message to allow retry mechanism to work
            logger.debug({ id: key.id }, 'Message not in store, returning empty proto');
            return proto.Message.fromObject({});
        },
        // Retry and timeout settings
        retryRequestDelayMs: 250,         // Delay before requesting retry
        defaultQueryTimeoutMs: 60000,     // Query timeout
        connectTimeoutMs: 60000,          // Connection timeout
        keepAliveIntervalMs: 30000,       // Keep-alive interval
        markOnlineOnConnect: true,        // Mark as online when connected
        emitOwnEvents: true,
    });

    // Handle WebSocket/Baileys errors
    sock.ev.on('error', (error) => {
        if (isNonCriticalSignalError(error)) {
            logger.debug({ error: error?.message }, 'Non-critical Baileys error (ignored)');
            return;
        }
        logger.error({ error }, 'Baileys WebSocket error');
    });

    // Handle connection updates
    sock.ev.on('connection.update', async (update) => {
        const { connection, lastDisconnect, qr } = update;

        if (qr) {
            qrCode = qr;
            connectionState = 'qr_ready';
            logger.info('QR Code ready - scan with WhatsApp');
            console.log('\n========================================');
            console.log('Scanne diesen QR-Code mit WhatsApp:');
            console.log('========================================\n');
            qrcodeTerminal.generate(qr, { small: true });
            console.log('\n========================================\n');
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
        // Store all messages for retry mechanism
        for (const msg of messages) {
            storeMessage(msg);
        }

        if (type !== 'notify') return;

        for (const msg of messages) {
            // Skip messages from self and status broadcasts
            if (msg.key.fromMe) continue;
            if (msg.key.remoteJid === 'status@broadcast') continue;

            await handleIncomingMessage(msg);
        }
    });

    // Handle message history (for session sync)
    // Note: Messages are automatically stored by store.bind(sock.ev)
    sock.ev.on('messaging-history.set', ({ messages }) => {
        logger.info({ count: messages.length }, 'Received message history sync');
    });

    // Handle message updates (includes retry events)
    sock.ev.on('messages.update', (updates) => {
        for (const update of updates) {
            // Log retry-related updates
            if (update.update?.messageStubType) {
                logger.info({
                    messageId: update.key?.id,
                    stubType: update.update.messageStubType
                }, 'Message update received');
            }
        }
    });

    // Handle message receipt updates (delivery, read status)
    sock.ev.on('message-receipt.update', (updates) => {
        for (const update of updates) {
            logger.debug({
                messageId: update.key?.id,
                receipt: update.receipt
            }, 'Receipt update');
        }
    });

    return sock;
}

/**
 * Extract phone number from message (handles LID and regular JID)
 * Priority: remoteJidAlt/participantAlt > regular JID > LID mapping > LID fallback
 */
async function extractPhoneNumber(jid, msg = null) {
    // Priority 1: Use remoteJidAlt (for DMs) or participantAlt (for groups) - Baileys v7
    if (msg) {
        const altJid = msg.key?.remoteJidAlt || msg.key?.participantAlt;
        if (altJid && altJid.includes('@s.whatsapp.net')) {
            const phone = altJid.replace('@s.whatsapp.net', '');
            logger.debug({ altJid, phone }, 'Using remoteJidAlt/participantAlt for phone number');
            return phone;
        }
    }

    // Priority 2: Regular phone number JID
    if (jid.endsWith('@s.whatsapp.net')) {
        return jid.replace('@s.whatsapp.net', '');
    }

    // Priority 3: LID format - try to get phone number from mapping
    if (jid.endsWith('@lid')) {
        const lid = jid.replace('@lid', '');

        try {
            // Try to get phone number from Baileys LID mapping cache
            if (sock && sock.signalRepository && sock.signalRepository.lidMapping) {
                const pn = await sock.signalRepository.lidMapping.getPNForLID(lid);
                if (pn) {
                    logger.debug({ lid, pn }, 'Resolved LID to phone number via mapping');
                    return pn.replace('@s.whatsapp.net', '');
                }
            }
        } catch (error) {
            logger.debug({ lid, error: error.message }, 'Could not resolve LID to phone number');
        }

        // Fallback: return LID if phone number not found
        logger.warn({ lid }, 'Using LID as fallback - phone number not available');
        return lid;
    }

    // Unknown format - return as-is
    return jid.replace(/@.*$/, '');
}

/**
 * Handle incoming WhatsApp message
 */
async function handleIncomingMessage(msg) {
    try {
        const messageId = msg.key.id;

        // Deduplication: Skip if we've already processed this message
        if (processedMessages.has(messageId)) {
            logger.debug({ messageId }, 'Skipping duplicate message');
            return;
        }

        // Mark message as processed
        processedMessages.set(messageId, Date.now());

        const jid = msg.key.remoteJid;
        const isGroup = jid.endsWith('@g.us');

        // Skip group messages (optional - can be enabled later)
        if (isGroup) {
            logger.debug({ jid }, 'Skipping group message');
            return;
        }

        // Extract phone number - handle remoteJidAlt, LID and regular JID
        const phone = await extractPhoneNumber(jid, msg);

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

    // Note: Message is automatically stored by store.bind(sock.ev)

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

/**
 * Send an interactive message (buttons/list) for testing
 * Supports multiple message types to test what works
 */
async function sendInteractiveMessage(phone, options) {
    if (!sock || connectionState !== 'connected') {
        throw new Error('WhatsApp not connected');
    }

    const jid = formatPhoneToJid(phone);
    const { type, text, footer, title, buttons, sections } = options;

    let message;
    let result;

    try {
        switch (type) {
            case 'buttons':
                // Legacy buttons message format
                message = {
                    text: text || 'Bitte wählen Sie eine Option:',
                    footer: footer || '',
                    buttons: buttons.map((btn, i) => ({
                        buttonId: btn.id || `btn_${i}`,
                        buttonText: { displayText: btn.text },
                        type: 1
                    })),
                    headerType: 1
                };
                result = await sock.sendMessage(jid, message);
                break;

            case 'list':
                // List message format
                message = {
                    text: text || 'Bitte wählen Sie aus der Liste:',
                    footer: footer || '',
                    title: title || 'Menü',
                    buttonText: 'Optionen anzeigen',
                    sections: sections || [{
                        title: 'Verfügbare Optionen',
                        rows: buttons.map((btn, i) => ({
                            title: btn.text,
                            rowId: btn.id || `row_${i}`,
                            description: btn.description || ''
                        }))
                    }]
                };
                result = await sock.sendMessage(jid, message);
                break;

            case 'interactive':
                // Native flow interactive message (WhatsApp Business style)
                const interactiveButtons = buttons.map(btn => {
                    if (btn.type === 'url') {
                        return {
                            name: 'cta_url',
                            buttonParamsJson: JSON.stringify({
                                display_text: btn.text,
                                url: btn.url
                            })
                        };
                    } else if (btn.type === 'copy') {
                        return {
                            name: 'cta_copy',
                            buttonParamsJson: JSON.stringify({
                                display_text: btn.text,
                                copy_code: btn.code
                            })
                        };
                    } else {
                        // Default: quick_reply
                        return {
                            name: 'quick_reply',
                            buttonParamsJson: JSON.stringify({
                                display_text: btn.text,
                                id: btn.id || `qr_${buttons.indexOf(btn)}`
                            })
                        };
                    }
                });

                message = {
                    interactiveMessage: {
                        body: { text: text || 'Bitte wählen Sie:' },
                        footer: { text: footer || '' },
                        header: { title: title || '', hasMediaAttachment: false },
                        nativeFlowMessage: {
                            buttons: interactiveButtons
                        }
                    }
                };

                // Use generateWAMessageFromContent for interactive messages
                const { generateWAMessageFromContent, proto } = require('@whiskeysockets/baileys');
                const msg = generateWAMessageFromContent(jid, {
                    viewOnceMessage: {
                        message: {
                            interactiveMessage: proto.Message.InteractiveMessage.create({
                                body: proto.Message.InteractiveMessage.Body.create({ text: text || 'Bitte wählen Sie:' }),
                                footer: proto.Message.InteractiveMessage.Footer.create({ text: footer || '' }),
                                header: proto.Message.InteractiveMessage.Header.create({
                                    title: title || '',
                                    hasMediaAttachment: false
                                }),
                                nativeFlowMessage: proto.Message.InteractiveMessage.NativeFlowMessage.create({
                                    buttons: interactiveButtons
                                })
                            })
                        }
                    }
                }, { userJid: sock.user.id });

                result = await sock.relayMessage(jid, msg.message, { messageId: msg.key.id });
                result = { key: msg.key };
                break;

            default:
                throw new Error(`Unknown interactive message type: ${type}`);
        }

        logger.info({ phone, type, messageId: result?.key?.id }, 'Interactive message sent');

        return {
            success: true,
            messageId: result?.key?.id,
            type: type
        };

    } catch (error) {
        logger.error({ error: error.message, type, phone }, 'Failed to send interactive message');
        throw error;
    }
}

module.exports = {
    connectWhatsApp,
    sendMessage,
    sendInteractiveMessage,
    getStatus,
    getQrCode,
    isConnected
};
