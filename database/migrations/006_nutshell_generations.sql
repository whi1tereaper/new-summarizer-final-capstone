-- Record every successful Nutshell generation, including standalone workspace use.
CREATE TABLE IF NOT EXISTS `nutshell_generations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `summary_id` INT(11) DEFAULT NULL,
  `user_id` INT(11) DEFAULT NULL,
  `guest_token` VARCHAR(64) DEFAULT NULL,
  `input_type` VARCHAR(20) NOT NULL DEFAULT 'text',
  `nutshell_text` TEXT DEFAULT NULL,
  `word_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `source_word_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ng_created` (`created_at`),
  KEY `idx_ng_user_created` (`user_id`, `created_at`),
  KEY `idx_ng_summary` (`summary_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;