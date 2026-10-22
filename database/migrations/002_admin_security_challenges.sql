CREATE TABLE IF NOT EXISTS `admin_security_challenges` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` int(11) NOT NULL,
    `challenge_hash` VARCHAR(255) NOT NULL,
    `failed_attempts` INT UNSIGNED NOT NULL DEFAULT 0,
    `locked_until` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NULL DEFAULT NULL,

    CONSTRAINT `fk_admin_security_challenges_user`
        FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    UNIQUE KEY `uq_admin_security_user` (`user_id`),
    INDEX `idx_admin_security_locked_until` (`locked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Legacy challenge storage retained for existing installations; current admin sign-in
-- uses a one-time email code and does not read or write this table.
