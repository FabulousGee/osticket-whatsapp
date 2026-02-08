/**
 * REST API for WhatsApp Service
 *
 * Provides endpoints for sending messages and checking status.
 * Uses centralized validation middleware for consistent error handling.
 */

const express = require('express');
const cors = require('cors');
const pino = require('pino');
const { sendMessage, sendInteractiveMessage, getStatus, getQrCode, isConnected } = require('./whatsapp');
const {
    validateSendRequest,
    validateInteractiveRequest,
    requireConnection,
    requireApiKey,
    requestLogger
} = require('./middleware/validation');
const constants = require('./config/constants');

const logger = pino({ level: process.env.LOG_LEVEL || 'info' });
const app = express();

// Middleware
app.use(cors());
app.use(express.json());
app.use(requestLogger);

// Connection check middleware (muss hier definiert werden wegen isConnected)
const checkConnection = requireConnection(isConnected);

/**
 * Health check endpoint
 */
app.get('/health', (req, res) => {
    res.json({ status: 'ok', timestamp: Date.now() });
});

/**
 * Get WhatsApp connection status
 */
app.get('/status', (req, res) => {
    const status = getStatus();
    res.json({
        success: true,
        ...status,
        timestamp: Date.now()
    });
});

/**
 * Get QR code for authentication
 * Returns QR code string that can be rendered
 */
app.get('/qr', (req, res) => {
    const qr = getQrCode();

    if (!qr) {
        const status = getStatus();
        if (status.state === constants.CONNECTION_STATES.CONNECTED) {
            return res.json({
                success: false,
                error: 'Already connected - no QR code needed'
            });
        }
        return res.json({
            success: false,
            error: 'QR code not available yet - wait for connection'
        });
    }

    res.json({
        success: true,
        qr: qr
    });
});

/**
 * Send a WhatsApp message
 * POST /send
 * Body: { phone: string, message: string }
 */
app.post('/send',
    validateSendRequest,
    checkConnection,
    async (req, res) => {
        const { phone, message } = req.body;

        try {
            const result = await sendMessage(phone, message);
            res.json(result);
        } catch (error) {
            logger.error({ error: error.message, phone }, 'Failed to send message');
            res.status(500).json({
                success: false,
                error: error.message
            });
        }
    }
);

/**
 * Send message with API key authentication
 * POST /send-secure
 * Headers: X-API-Key
 * Body: { phone: string, message: string }
 */
app.post('/send-secure',
    requireApiKey,
    validateSendRequest,
    checkConnection,
    async (req, res) => {
        const { phone, message } = req.body;

        try {
            const result = await sendMessage(phone, message);
            res.json(result);
        } catch (error) {
            logger.error({ error: error.message, phone }, 'Failed to send message');
            res.status(500).json({
                success: false,
                error: error.message
            });
        }
    }
);

/**
 * Send interactive message (buttons/list) for testing
 * POST /sendInteractive
 * Body: { phone: string, type: string, text: string, buttons: array, ... }
 *
 * Types:
 * - "buttons": Legacy button format
 * - "list": List/menu format
 * - "interactive": Native flow with quick_reply, cta_url, cta_copy
 */
app.post('/sendInteractive',
    validateInteractiveRequest,
    checkConnection,
    async (req, res) => {
        const { phone, type, text, footer, title, buttons, sections } = req.body;

        try {
            const result = await sendInteractiveMessage(phone, {
                type,
                text,
                footer,
                title,
                buttons,
                sections
            });
            res.json(result);
        } catch (error) {
            logger.error({ error: error.message, phone, type }, 'Failed to send interactive message');
            res.status(500).json({
                success: false,
                error: error.message
            });
        }
    }
);

/**
 * 404 handler
 */
app.use((req, res) => {
    res.status(404).json({
        success: false,
        error: 'Endpoint not found'
    });
});

/**
 * Error handler
 */
app.use((error, req, res, next) => {
    logger.error({ error: error.message, stack: error.stack }, 'Unhandled error');
    res.status(500).json({
        success: false,
        error: 'Internal server error'
    });
});

/**
 * Start the API server
 */
function startServer() {
    const port = process.env.PORT || constants.DEFAULT_PORT;
    const host = process.env.HOST || constants.DEFAULT_HOST;

    app.listen(port, host, () => {
        logger.info({ host, port }, 'API server started');
        console.log(`\nAPI Server running at http://${host}:${port}`);
        console.log('\nEndpoints:');
        console.log('  GET  /status          - Connection status');
        console.log('  GET  /qr              - Get QR code');
        console.log('  POST /send            - Send message');
        console.log('  POST /send-secure     - Send message (requires API key)');
        console.log('  POST /sendInteractive - Send interactive message (buttons/list)');
        console.log('  GET  /health          - Health check\n');
    });
}

module.exports = { app, startServer };
