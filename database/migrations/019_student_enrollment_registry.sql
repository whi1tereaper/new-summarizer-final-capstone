-- Additive only. No student-submitted numbers are imported into the trusted registry.
CREATE TABLE IF NOT EXISTS student_registry_gate (
 id TINYINT NOT NULL PRIMARY KEY
) ENGINE=InnoDB;
INSERT IGNORE INTO student_registry_gate (id) VALUES (1);

CREATE TABLE IF NOT EXISTS student_registry_batches (
 id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 batch_ref CHAR(32) NOT NULL UNIQUE,
 actor_user_id INT NULL,
 source VARCHAR(12) NOT NULL,
 state VARCHAR(12) NOT NULL DEFAULT 'preview',
 verification_period VARCHAR(120) NOT NULL,
 eligible_until_epoch BIGINT NOT NULL,
 created_epoch BIGINT NOT NULL,
 preview_expires_epoch BIGINT NOT NULL,
 committed_epoch BIGINT NULL,
 total_count INT NOT NULL DEFAULT 0,
 inserted_count INT NOT NULL DEFAULT 0,
 skipped_count INT NOT NULL DEFAULT 0,
 invalid_count INT NOT NULL DEFAULT 0,
 duplicate_count INT NOT NULL DEFAULT 0,
 approved_count INT NOT NULL DEFAULT 0,
 conflict_count INT NOT NULL DEFAULT 0,
 preview_payload MEDIUMTEXT NULL,
 issues_json TEXT NULL,
 CONSTRAINT fk_registry_batch_admin FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
 KEY idx_registry_batch_time (created_epoch),
 CHECK (state IN ('preview','committed','expired'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS student_enrollment_registry (
 id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 registry_ref CHAR(32) NOT NULL UNIQUE,
 normalized_student_number VARCHAR(40) COLLATE utf8mb4_bin NOT NULL UNIQUE,
 student_number_fingerprint CHAR(64) NOT NULL UNIQUE,
 eligibility_status VARCHAR(12) NOT NULL DEFAULT 'active',
 verification_period VARCHAR(120) NOT NULL,
 eligible_until_epoch BIGINT NOT NULL,
 import_batch_id BIGINT NOT NULL,
 imported_by INT NULL,
 created_epoch BIGINT NOT NULL,
 updated_epoch BIGINT NOT NULL,
 version INT NOT NULL DEFAULT 1,
 status_changed_by INT NULL,
 status_changed_epoch BIGINT NULL,
 status_reason VARCHAR(500) NULL,
 CONSTRAINT fk_registry_batch FOREIGN KEY (import_batch_id) REFERENCES student_registry_batches(id),
 CONSTRAINT fk_registry_admin FOREIGN KEY (imported_by) REFERENCES users(id) ON DELETE SET NULL,
 CONSTRAINT fk_registry_status_admin FOREIGN KEY (status_changed_by) REFERENCES users(id) ON DELETE SET NULL,
 KEY idx_registry_eligible (eligibility_status,eligible_until_epoch),
 CHECK (eligibility_status IN ('active','inactive','revoked'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS student_registry_events (
 id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 actor_user_id INT NULL,
 registry_id BIGINT NULL,
 batch_id BIGINT NULL,
 affected_user_id INT NULL,
 action VARCHAR(40) NOT NULL,
 -- HMAC only: no raw identifiers in conflict logs.
 identity_fingerprint CHAR(64) NULL,
 detail VARCHAR(500) NULL,
 created_epoch BIGINT NOT NULL,
 CONSTRAINT fk_registry_event_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
 CONSTRAINT fk_registry_event_record FOREIGN KEY (registry_id) REFERENCES student_enrollment_registry(id),
 CONSTRAINT fk_registry_event_batch FOREIGN KEY (batch_id) REFERENCES student_registry_batches(id),
 CONSTRAINT fk_registry_event_user FOREIGN KEY (affected_user_id) REFERENCES users(id) ON DELETE SET NULL,
 KEY idx_registry_event_time (created_epoch),
 KEY idx_registry_event_batch (batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE student_applications ADD COLUMN IF NOT EXISTS approval_method VARCHAR(24) NULL;
ALTER TABLE student_applications ADD COLUMN IF NOT EXISTS registry_id BIGINT NULL;
-- Existing manual approvals remain manual; never infer registry eligibility from applications.
SET @registry_fk_sql = IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='student_applications' AND CONSTRAINT_NAME='fk_application_registry')=0,
 'ALTER TABLE student_applications ADD CONSTRAINT fk_application_registry FOREIGN KEY (registry_id) REFERENCES student_enrollment_registry(id)', 'SELECT 1');
PREPARE registry_fk_stmt FROM @registry_fk_sql;
EXECUTE registry_fk_stmt;
DEALLOCATE PREPARE registry_fk_stmt;
