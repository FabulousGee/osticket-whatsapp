<?php
/**
 * WhatsApp Integration Plugin for osTicket
 *
 * Enables bidirectional communication between WhatsApp and osTicket:
 * - Incoming WhatsApp messages create tickets
 * - Agent replies are sent back via WhatsApp
 *
 * @package    osTicket-WhatsApp
 * @version    1.0.0
 * @author     Community
 * @license    MIT
 */

return [
    'id'          => 'osticket:whatsapp',
    'version'     => '1.0.0',
    'name'        => 'WhatsApp Integration',
    'author'      => 'Community',
    'description' => 'Enables WhatsApp messaging integration with osTicket. '
                   . 'Automatically creates tickets from WhatsApp messages and '
                   . 'sends agent replies back to WhatsApp.',
    'url'         => 'https://github.com/your-repo/osticket-whatsapp',
    'plugin'      => 'class.WhatsAppPlugin.php:WhatsAppPlugin',
];
