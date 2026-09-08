-- Create the new table for artifacts to normalize large text blobs
CREATE TABLE IF NOT EXISTS `summary_artifacts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `summary_id` int(11) NOT NULL,
  `original_text` longtext NOT NULL,
  `generated_summary` longtext NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_summary_artifacts_summary_id` FOREIGN KEY (`summary_id`) REFERENCES `summaries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Migrate existing data from the summaries table into the new artifacts table
INSERT INTO `summary_artifacts` (`summary_id`, `original_text`, `generated_summary`)
SELECT `id`, `original_text`, `generated_summary` FROM `summaries`;

-- Drop the large text columns from the original table to reduce row size
ALTER TABLE `summaries` 
DROP COLUMN `original_text`,
DROP COLUMN `generated_summary`;

-- Add useful indexes for history and retrieval workflows
ALTER TABLE `summaries`
ADD INDEX `idx_summaries_user_id` (`user_id`),
ADD INDEX `idx_summaries_guest_token` (`guest_token`),
ADD INDEX `idx_summaries_created_at` (`created_at`);
