-- WhatsApp Integration Plugin for osTicket
-- Database Schema
--
-- Note: The TABLE_PREFIX (usually 'ost_') will be added automatically by osTicket
-- Run these queries manually if tables are not created on plugin installation

-- Mapping table: Links WhatsApp phone numbers to osTicket tickets
CREATE TABLE IF NOT EXISTS `%TABLE_PREFIX%whatsapp_mapping` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `phone` VARCHAR(20) NOT NULL COMMENT 'Phone number without formatting (digits only)',
    `phone_formatted` VARCHAR(25) NOT NULL COMMENT 'Phone number formatted for display',
    `contact_name` VARCHAR(255) DEFAULT NULL COMMENT 'WhatsApp contact name',
    `ticket_id` INT UNSIGNED NOT NULL COMMENT 'osTicket internal ticket ID',
    `ticket_number` VARCHAR(20) NOT NULL COMMENT 'osTicket external ticket number',
    `user_id` INT UNSIGNED DEFAULT NULL COMMENT 'osTicket user ID',
    `status` ENUM('open', 'closed', 'inactive') DEFAULT 'open' COMMENT 'Mapping status (inactive = switched to another ticket)',
    `created` DATETIME NOT NULL COMMENT 'Record creation timestamp',
    `updated` DATETIME NOT NULL COMMENT 'Last update timestamp',
    INDEX `idx_phone_status` (`phone`, `status`),
    INDEX `idx_ticket` (`ticket_id`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Messages table: Logs all WhatsApp messages for auditing
CREATE TABLE IF NOT EXISTS `%TABLE_PREFIX%whatsapp_messages` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `mapping_id` INT UNSIGNED NOT NULL COMMENT 'Reference to mapping table',
    `message_id` VARCHAR(100) DEFAULT NULL COMMENT 'WhatsApp message ID',
    `direction` ENUM('in', 'out') NOT NULL COMMENT 'Message direction',
    `content` TEXT COMMENT 'Message content',
    `status` VARCHAR(20) DEFAULT 'sent' COMMENT 'Delivery status',
    `created` DATETIME NOT NULL COMMENT 'Message timestamp',
    INDEX `idx_mapping` (`mapping_id`),
    INDEX `idx_direction` (`direction`),
    FOREIGN KEY (`mapping_id`) REFERENCES `%TABLE_PREFIX%whatsapp_mapping`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
