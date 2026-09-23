-- Store generated Nutshell output and lightweight metrics for analytics.
ALTER TABLE `summaries`
  ADD COLUMN `nutshell_text` TEXT DEFAULT NULL,
  ADD COLUMN `nutshell_word_count` INT UNSIGNED DEFAULT NULL AFTER `nutshell_text`,
  ADD COLUMN `nutshell_generated_at` TIMESTAMP NULL DEFAULT NULL AFTER `nutshell_word_count`,
  ADD INDEX `idx_summaries_nutshell_generated` (`nutshell_generated_at`);