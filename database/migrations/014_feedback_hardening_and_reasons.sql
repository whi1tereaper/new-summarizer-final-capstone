-- Migration 014: Feedback Hardening, Reasons Taxonomy, and Anonymous Comment Storage

-- 1. Add structured reasons column to feedback table
ALTER TABLE `feedback`
  ADD COLUMN `reasons` LONGTEXT DEFAULT NULL AFTER `impression`;

-- 2. Decoupled anonymous feedback comments table (zero user/guest/ip identifiers)
CREATE TABLE IF NOT EXISTS `feedback_comments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `summary_id` INT NOT NULL,
  `rating` TINYINT NULL,
  `reasons` LONGTEXT NULL,
  `comment` TEXT NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_fc_summary` (`summary_id`),
  INDEX `idx_fc_created` (`created_at`),
  CONSTRAINT `fk_fc_summary` FOREIGN KEY (`summary_id`) REFERENCES `summaries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
