-- Persist the current Terms and Conditions acceptance state for every account.
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `terms_accepted` TINYINT(1) NOT NULL DEFAULT 0 AFTER `active`,
  ADD COLUMN IF NOT EXISTS `terms_accepted_at` DATETIME NULL AFTER `terms_accepted`;

-- Backfill legacy accounts created before the terms requirement was introduced
UPDATE `users`
SET `terms_accepted` = 1,
    `terms_accepted_at` = COALESCE(`terms_accepted_at`, `created_at`, NOW())
WHERE COALESCE(`terms_accepted`, 0) = 0
  AND `terms_accepted_at` IS NULL
  AND `created_at` < '2026-05-05 00:00:00';

