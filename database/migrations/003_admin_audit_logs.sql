CREATE TABLE IF NOT EXISTS `admin_audit_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `admin_id` int(11) NULL,
    `action` VARCHAR(100) NOT NULL,
    `entity_type` VARCHAR(100) NULL,
    `entity_id` BIGINT UNSIGNED NULL,
    `ip_address` VARCHAR(45) NULL,
    `user_agent` VARCHAR(255) NULL,
    `details` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX `idx_admin_audit_logs_admin_id` (`admin_id`),
    INDEX `idx_admin_audit_logs_action` (`action`),
    INDEX `idx_admin_audit_logs_created_at` (`created_at`),
    INDEX `idx_admin_audit_entity` (`entity_type`, `entity_id`),
    CONSTRAINT `fk_admin_audit_logs_admin`
        FOREIGN KEY (`admin_id`) REFERENCES `users`(`id`)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
