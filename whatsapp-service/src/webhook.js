/**
 * Webhook sender for outgoing notifications to osTicket
 */

const pino = require('pino');
const logger = pino({ level: process.env.LOG_LEVEL || 'info' });

/**
 * Send incoming message data to osTicket webhook
 */
async function sendWebhook(data) {
    const webhookUrl = process.env.WEBHOOK_URL;
    const webhookSecret = process.env.WEBHOOK_SECRET;

    if (!webhookUrl) {
        logger.warn('WEBHOOK_URL not configured - message not forwarded');
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
                body: text.substring(0, 200)
            }, 'Webhook request failed');
            return false;
        }

        logger.debug({ messageId: data.messageId }, 'Webhook sent successfully');
        return true;

    } catch (error) {
        logger.error({ error: error.message, url: webhookUrl }, 'Webhook request error');
        return false;
    }
}

module.exports = { sendWebhook };
