/**
 * WhatsApp-osTicket Bridge Service
 * Main entry point
 */

require('dotenv').config();

const pino = require('pino');
const { connectWhatsApp } = require('./whatsapp');
const { startServer } = require('./api');

const logger = pino({ level: process.env.LOG_LEVEL || 'info' });

async function main() {
    console.log('========================================');
    console.log('  WhatsApp-osTicket Bridge Service');
    console.log('========================================\n');

    // Check required environment
    if (!process.env.WEBHOOK_URL) {
        logger.warn('WEBHOOK_URL not set - incoming messages will not be forwarded');
    }

    try {
        // Start REST API server
        startServer();

        // Connect to WhatsApp
        console.log('Connecting to WhatsApp...');
        console.log('Scan the QR code with your WhatsApp app\n');

        await connectWhatsApp();

    } catch (error) {
        logger.error({ error: error.message }, 'Failed to start service');
        process.exit(1);
    }
}

// Handle shutdown gracefully
process.on('SIGINT', () => {
    console.log('\nShutting down...');
    process.exit(0);
});

process.on('SIGTERM', () => {
    console.log('\nShutting down...');
    process.exit(0);
});

// Start the service
main();
