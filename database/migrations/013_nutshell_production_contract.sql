-- Add an idempotent, auditable Nutshell ledger. Only completed rows count as generations.
ALTER TABLE `nutshell_generations`
  MODIFY COLUMN `nutshell_text` TEXT NULL,
  ADD COLUMN `status` ENUM('completed','failed') NOT NULL DEFAULT 'completed' AFTER `nutshell_text`,
  ADD COLUMN `failure_reason` VARCHAR(80) DEFAULT NULL AFTER `status`,
  ADD COLUMN `analysis_mode` VARCHAR(24) NOT NULL DEFAULT 'general' AFTER `failure_reason`,
  ADD COLUMN `output_format` VARCHAR(24) NOT NULL DEFAULT 'paragraph' AFTER `analysis_mode`,
  ADD COLUMN `primary_summary_word_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `source_word_count`,
  ADD COLUMN `compression_ratio` DECIMAL(8,4) NOT NULL DEFAULT 0 AFTER `primary_summary_word_count`,
  ADD COLUMN `processing_time_ms` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `compression_ratio`,
  ADD COLUMN `algorithm_version` VARCHAR(32) NOT NULL DEFAULT 'nutshell-extractive-v2' AFTER `processing_time_ms`,
  ADD COLUMN `idempotency_key` CHAR(64) DEFAULT NULL AFTER `algorithm_version`,
  ADD UNIQUE KEY `uq_ng_idempotency_key` (`idempotency_key`),
  ADD KEY `idx_ng_status_created` (`status`,`created_at`),
  ADD CONSTRAINT `fk_ng_summary` FOREIGN KEY (`summary_id`) REFERENCES `summaries` (`id`) ON DELETE SET NULL;
