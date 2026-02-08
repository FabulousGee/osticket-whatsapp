/**
 * Webhook sender for outgoing notifications to osTicket
 *
 * Handles message forwarding to osTicket with retry logic
 * to prevent message loss during temporary outages.
 */

const pino = require('pino');
const constants = require('./config/constants');

const logger = pino({ level: process.env.LOG_LEVEL || 'info' });

/**
 * Send incoming message data to osTicket webhook
 *
 * @param {Object} data Message data to send
 * @returns {Promise<boolean>} Success status
 */
async function sendWebhook(data) {
    const webhookUrl = process.env.WEBHOOK_URL;
    const webhookSecret = process.env.WEBHOOK_SECRET;

    if (!webhookUrl) {
        logger.error(constants.ERRORS.WEBHOOK_URL_NOT_SET);
        return false;
    }

    const payload = {
        event: 'message.received',
        timestamp: Date.now(),
        data: {
            messageId: data.messageId,
            phone: data.phone,
            name: data.name,
            type: data.type,
            text: data.text,
            mediaUrl: data.mediaUrl || null,
            originalTimestamp: data.timestamp
        }
    };

    try {
        const response = await fetch(webhookUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Webhook-Secret': webhookSecret || '',
                'User-Agent': 'WhatsApp-osTicket-Service/1.0'
            },
            body: JSON.stringify(payload)
        });

        if (!response.ok) {
            const text = await response.text();
            logger.error({
                status: response.status,
                body: text.substring(0, 200),
                messageId: data.messageId
            }, 'Webhook request failed');
            return false;
        }

        logger.debug({ messageId: data.messageId }, 'Webhook sent successfully');
        return true;

    } catch (error) {
        logger.error({
            error: error.message,
            url: webhookUrl,
            messageId: data.messageId
        }, 'Webhook request error');
        return false;
    }
}

/**
 * Send webhook with retry logic
 *
 * Retries failed webhook requests with exponential backoff
 * to handle temporary network issues or server unavailability.
 *
 * @param {Object} data Message data to send
 * @param {number} attempt Current attempt number (internal)
 * @returns {Promise<boolean>} Success status
 */
async function sendWebhookWithRetry(data, attempt = 1) {
    const maxRetries = constants.WEBHOOK_MAX_RETRIES;
    const baseDelay = constants.WEBHOOK_RETRY_DELAY_MS;

    try {
        const success = await sendWebhook(data);

        if (success) {
            if (attempt > 1) {
                logger.info({ attempt, messageId: data.messageId }, 'Webhook succeeded after retry');
            }
            return true;
        }

        // Webhook returned false (failed but no exception)
        if (attempt < maxRetries) {
            const delay = baseDelay * attempt; // Exponential backoff
            logger.warn({
                attempt,
                nextAttemptIn: `${delay}ms`,
                messageId: data.messageId
            }, 'Webhook failed, scheduling retry');

            await sleep(delay);
            return sendWebhookWithRetry(data, attempt + 1);
        }

        // Max retries reached
        logger.error({
            messageId: data.messageId,
            phone: data.phone,
            attempts: attempt
        }, 'Webhook failed after max retries - MESSAGE MAY BE LOST');

        // TODO: Implement dead letter queue for failed messages
        // Could store in file, Redis, or database for later recovery

        return false;

    } catch (error) {
        // Exception thrown
        if (attempt < maxRetries) {
            const delay = baseDelay * attempt;
            logger.warn({
                attempt,
                error: error.message,
                nextAttemptIn: `${delay}ms`,
                messageId: data.messageId
            }, 'Webhook error, scheduling retry');

            await sleep(delay);
            return sendWebhookWithRetry(data, attempt + 1);
        }

        logger.error({
            error: error.message,
            stack: error.stack,
            messageId: data.messageId,
            attempts: attempt
        }, 'Webhook failed with exception after max retries');

        return false;
    }
}

/**
 * Sleep helper function
 * @param {number} ms Milliseconds to sleep
 */
function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

/**
 * Check if webhook is configured
 * @returns {boolean} True if webhook URL is set
 */
function isWebhookConfigured() {
    return !!process.env.WEBHOOK_URL;
}

module.exports = {
    sendWebhook,
    sendWebhookWithRetry,
    isWebhookConfigured
};
