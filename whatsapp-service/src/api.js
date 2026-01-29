/**
 * REST API for WhatsApp Service
 * Provides endpoints for sending messages and checking status
 */

const express = require('express');
const cors = require('cors');
const pino = require('pino');
const { sendMessage, getStatus, getQrCode, isConnected } = require('./whatsapp');

const logger = pino({ level: process.env.LOG_LEVEL || 'info' });
const app = express();

// Middleware
app.use(cors());
app.use(express.json());

// Request logging
app.use((req, res, next) => {
    logger.debug({ method: req.method, path: req.path }, 'API request');
    next();
});

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
        if (status.state === 'connected') {
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
app.post('/send', async (req, res) => {
    const { phone, message } = req.body;

    // Validate input
    if (!phone || !message) {
        return res.status(400).json({
            success: false,
            error: 'Missing required fields: phone, message'
        });
    }

    // Check connection
    if (!isConnected()) {
        return res.status(503).json({
            success: false,
            error: 'WhatsApp not connected'
        });
    }

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
});

/**
 * Send message with authentication check
 * POST /send-secure
 * Headers: X-API-Key
 * Body: { phone: string, message: string }
 */
app.post('/send-secure', async (req, res) => {
    const apiKey = req.headers['x-api-key'];
    const expectedKey = process.env.API_KEY;

    if (expectedKey && apiKey !== expectedKey) {
        return res.status(401).json({
            success: false,
            error: 'Invalid API key'
        });
    }

    // Forward to regular send endpoint logic
    const { phone, message } = req.body;

    if (!phone || !message) {
        return res.status(400).json({
            success: false,
            error: 'Missing required fields: phone, message'
        });
    }

    if (!isConnected()) {
        return res.status(503).json({
            success: false,
            error: 'WhatsApp not connected'
        });
    }

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
});

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
    logger.error({ error: error.message }, 'Unhandled error');
    res.status(500).json({
        success: false,
        error: 'Internal server error'
    });
});

/**
 * Start the API server
 */
function startServer() {
    const port = process.env.PORT || 3000;
    const host = process.env.HOST || '127.0.0.1';

    app.listen(port, host, () => {
        logger.info({ host, port }, 'API server started');
        console.log(`\nAPI Server running at http://${host}:${port}`);
        console.log('\nEndpoints:');
        console.log('  GET  /status     - Connection status');
        console.log('  GET  /qr         - Get QR code');
        console.log('  POST /send       - Send message');
        console.log('  GET  /health     - Health check\n');
    });
}

module.exports = { app, startServer };
