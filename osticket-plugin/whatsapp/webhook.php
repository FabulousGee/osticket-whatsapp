<?php
/**
 * WhatsApp Webhook Endpoint
 *
 * This file should be accessible at: /api/whatsapp/webhook.php
 * Copy to: {osticket}/api/whatsapp/webhook.php
 *
 * Or include this in your osTicket API routing.
 */

// Adjust path based on your osTicket installation
// This assumes the file is at: osticket/api/whatsapp/webhook.php
$osticketRoot = realpath(__DIR__ . '/../../');

// Try different paths
$possiblePaths = [
    $osticketRoot . '/main.inc.php',
    $osticketRoot . '/include/main.inc.php',
    dirname(__DIR__, 2) . '/main.inc.php',
    dirname(__DIR__, 3) . '/main.inc.php',
];

$mainIncluded = false;
foreach ($possiblePaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $mainIncluded = true;
        break;
    }
}

if (!$mainIncluded) {
    http_response_code(500);
    die(json_encode(['error' => 'osTicket not found - check installation path']));
}

// Load plugin classes
$pluginPath = INCLUDE_DIR . 'plugins/whatsapp/';
if (!file_exists($pluginPath . 'class.WhatsAppWebhook.php')) {
    // Try alternative path
    $pluginPath = __DIR__ . '/../include/plugins/whatsapp/';
}

require_once $pluginPath . 'class.WhatsAppApi.php';
require_once $pluginPath . 'class.WhatsAppMapping.php';
require_once $pluginPath . 'class.WhatsAppWebhook.php';

// Set content type
header('Content-Type: application/json');

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['error' => 'Method not allowed']));
}

// Get plugin configuration
$plugin = null;
$plugins = PluginManager::getActivePlugins();
foreach ($plugins as $p) {
    if ($p->getId() === 'osticket:whatsapp') {
        $plugin = $p;
        break;
    }
}

if (!$plugin) {
    http_response_code(503);
    die(json_encode(['error' => 'WhatsApp plugin not active']));
}

$config = $plugin->getConfig();

// Verify webhook secret
$providedSecret = $_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? '';
$expectedSecret = $config->get('webhook_secret');

if ($expectedSecret && !hash_equals($expectedSecret, $providedSecret)) {
    http_response_code(401);
    die(json_encode(['error' => 'Invalid webhook secret']));
}

// Parse request body
$input = file_get_contents('php://input');
$payload = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    die(json_encode(['error' => 'Invalid JSON']));
}

// Process webhook
try {
    $handler = new WhatsAppWebhook($config);
    $result = $handler->handle($payload);

    http_response_code($result['success'] ? 200 : 400);
    echo json_encode($result);

} catch (Exception $e) {
    error_log('WhatsApp Webhook Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal error']);
}
