/**
 * Validation Middleware for WhatsApp Service API
 *
 * Zentrale Validierungslogik für alle API-Endpoints.
 * Vermeidet Code-Duplikation und gewährleistet konsistente Fehlerbehandlung.
 */

const pino = require('pino');
const constants = require('../config/constants');

const logger = pino({ level: process.env.LOG_LEVEL || 'info' });

/**
 * Validiert Send-Requests (phone + message)
 */
const validateSendRequest = (req, res, next) => {
    const { phone, message } = req.body;

    // Phone Validation
    if (!phone || typeof phone !== 'string' || phone.trim() === '') {
        return res.status(400).json({
            success: false,
            error: constants.ERRORS.PHONE_REQUIRED
        });
    }

    // Message Validation
    if (!message || typeof message !== 'string' || message.trim() === '') {
        return res.status(400).json({
            success: false,
            error: constants.ERRORS.MESSAGE_REQUIRED
        });
    }

    // Phone Format Validation
    const cleanPhone = phone.replace(/\D/g, '');
    if (cleanPhone.length < constants.MIN_PHONE_LENGTH ||
        cleanPhone.length > constants.MAX_PHONE_LENGTH) {
        return res.status(400).json({
            success: false,
            error: constants.ERRORS.INVALID_PHONE
        });
    }

    // Message Length Validation
    if (message.length > constants.MAX_MESSAGE_LENGTH) {
        return res.status(400).json({
            success: false,
            error: `Message too long (max ${constants.MAX_MESSAGE_LENGTH} characters)`
        });
    }

    next();
};

/**
 * Validiert Interactive Message Requests
 */
const validateInteractiveRequest = (req, res, next) => {
    const { phone, type, buttons } = req.body;

    if (!phone || typeof phone !== 'string' || phone.trim() === '') {
        return res.status(400).json({
            success: false,
            error: constants.ERRORS.PHONE_REQUIRED
        });
    }

    if (!type) {
        return res.status(400).json({
            success: false,
            error: 'Message type is required'
        });
    }

    if (!buttons || !Array.isArray(buttons) || buttons.length === 0) {
        return res.status(400).json({
            success: false,
            error: 'Buttons array is required'
        });
    }

    const cleanPhone = phone.replace(/\D/g, '');
    if (cleanPhone.length < constants.MIN_PHONE_LENGTH ||
        cleanPhone.length > constants.MAX_PHONE_LENGTH) {
        return res.status(400).json({
            success: false,
            error: constants.ERRORS.INVALID_PHONE
        });
    }

    next();
};

/**
 * Prüft WhatsApp-Verbindung
 */
const requireConnection = (isConnectedFn) => (req, res, next) => {
    if (!isConnectedFn()) {
        return res.status(503).json({
            success: false,
            error: constants.ERRORS.NOT_CONNECTED
        });
    }
    next();
};

/**
 * API Key Authentifizierung
 *
 * WICHTIG: Wenn kein API_KEY konfiguriert ist, wird der Request BLOCKIERT
 * (nicht erlaubt wie vorher!). Dies ist ein kritischer Security-Fix.
 */
const requireApiKey = (req, res, next) => {
    const apiKey = req.headers['x-api-key'];
    const expectedKey = process.env.API_KEY;

    // KRITISCH: Wenn kein API_KEY gesetzt, BLOCKIEREN (nicht erlauben!)
    if (!expectedKey) {
        logger.error('API_KEY environment variable not set - blocking request');
        return res.status(500).json({
            success: false,
            error: constants.ERRORS.API_KEY_NOT_SET
        });
    }

    // Timing-safe Vergleich wäre hier ideal, aber für API-Keys ausreichend
    if (apiKey !== expectedKey) {
        logger.warn({ ip: req.ip }, 'Invalid API key attempt');
        return res.status(401).json({
            success: false,
            error: constants.ERRORS.INVALID_API_KEY
        });
    }

    next();
};

/**
 * Optional API Key - erlaubt Request wenn kein Key konfiguriert
 * DEPRECATED: Sollte nicht mehr verwendet werden
 */
const optionalApiKey = (req, res, next) => {
    const apiKey = req.headers['x-api-key'];
    const expectedKey = process.env.API_KEY;

    // Wenn Key konfiguriert, muss er auch stimmen
    if (expectedKey && apiKey !== expectedKey) {
        return res.status(401).json({
            success: false,
            error: constants.ERRORS.INVALID_API_KEY
        });
    }

    next();
};

/**
 * Request Logging Middleware
 */
const requestLogger = (req, res, next) => {
    const start = Date.now();

    res.on('finish', () => {
        const duration = Date.now() - start;
        logger.debug({
            method: req.method,
            path: req.path,
            status: res.statusCode,
            duration: `${duration}ms`
        }, 'API request completed');
    });

    next();
};

module.exports = {
    validateSendRequest,
    validateInteractiveRequest,
    requireConnection,
    requireApiKey,
    optionalApiKey,
    requestLogger
};
