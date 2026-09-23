-- Keep the normalized schema aligned with the UI and history persistence code.
ALTER TABLE `summaries`
  MODIFY COLUMN `summary_style` ENUM('standard_paragraph','bullet_points','hybrid','academic_summary','simple_summary','executive_summary') DEFAULT 'standard_paragraph';

ALTER TABLE `summaries`
  ADD COLUMN IF NOT EXISTS `nutshell_text` TEXT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `nutshell_word_count` INT UNSIGNED DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `nutshell_generated_at` TIMESTAMP NULL DEFAULT NULL,
  ADD INDEX IF NOT EXISTS `idx_summaries_nutshell_generated` (`nutshell_generated_at`);

ALTER TABLE `nutshell_generations`
  ADD COLUMN IF NOT EXISTS `nutshell_text` TEXT DEFAULT NULL AFTER `input_type`;