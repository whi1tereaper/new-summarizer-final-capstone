-- Store lightweight metrics used by the summarization analytics dashboard.
ALTER TABLE `summaries`
  ADD COLUMN `summary_length` VARCHAR(32) DEFAULT NULL AFTER `summary_style`,
  ADD COLUMN `article_category` VARCHAR(100) DEFAULT NULL AFTER `summary_length`,
  ADD COLUMN `original_word_count` INT UNSIGNED DEFAULT NULL AFTER `summary_length`,
  ADD COLUMN `summary_word_count` INT UNSIGNED DEFAULT NULL AFTER `original_word_count`,
  ADD COLUMN `processing_time` DECIMAL(10,3) DEFAULT NULL AFTER `summary_word_count`,
  ADD INDEX `idx_summaries_status_created` (`status`, `created_at`),
  ADD INDEX `idx_summaries_analytics_user_created` (`user_id`, `created_at`);