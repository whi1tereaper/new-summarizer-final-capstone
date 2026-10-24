-- Additive registry-only policy metadata. Historical evidence remains intact.
CREATE TABLE IF NOT EXISTS student_registry_policy (
 id TINYINT NOT NULL PRIMARY KEY,
 policy_version VARCHAR(40) NOT NULL DEFAULT 'registry-only-v1',
 initial_allowlist_sha256 CHAR(64) NULL,
 initial_applied_epoch BIGINT NULL,
 initialized_by INT NULL,
 initialized_source VARCHAR(40) NULL,
 latest_reconciliation_epoch BIGINT NULL,
 latest_reconciliation_json TEXT NULL,
 CONSTRAINT fk_registry_policy_admin FOREIGN KEY (initialized_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO student_registry_policy(id) VALUES(1);
ALTER TABLE student_enrollment_registry ADD COLUMN IF NOT EXISTS claim_conflict TINYINT NOT NULL DEFAULT 0;

-- NULL is allowed for preserved unlisted legacy applications. A real registry
-- claim can belong to only one account, including after detail-retention jobs.
SET @registry_claim_sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='student_applications' AND INDEX_NAME='uq_student_registry_claim')=0,
 'ALTER TABLE student_applications ADD UNIQUE INDEX uq_student_registry_claim(registry_id)', 'SELECT 1');
PREPARE registry_claim_stmt FROM @registry_claim_sql;
EXECUTE registry_claim_stmt;
DEALLOCATE PREPARE registry_claim_stmt;

SET @registry_rejection_sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='student_registry_events' AND INDEX_NAME='idx_registry_event_action')=0,
 'ALTER TABLE student_registry_events ADD INDEX idx_registry_event_action(action,created_epoch)', 'SELECT 1');
PREPARE registry_rejection_stmt FROM @registry_rejection_sql;
EXECUTE registry_rejection_stmt;
DEALLOCATE PREPARE registry_rejection_stmt;
