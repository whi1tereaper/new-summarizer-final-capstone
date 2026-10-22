-- Durable safe failure envelopes and bounded retry attempts.
ALTER TABLE summary_jobs
  ADD COLUMN error_details TEXT NULL DEFAULT NULL AFTER error_message,
  ADD COLUMN retry_count TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER worker_pid;
