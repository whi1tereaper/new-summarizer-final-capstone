-- Additive migration. Never grants verification to existing accounts.
CREATE TABLE IF NOT EXISTS student_applications (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 application_ref CHAR(32) NOT NULL UNIQUE,
 user_id INT NOT NULL UNIQUE,
 full_name VARCHAR(160) NULL,
 student_number VARCHAR(64) NULL,
 normalized_student_number VARCHAR(64) NULL UNIQUE,
 student_number_fingerprint CHAR(64) NULL UNIQUE,
 campus VARCHAR(32) NOT NULL DEFAULT 'NEUST_PENARANDA',
 status VARCHAR(24) NOT NULL DEFAULT 'pending',
 evidence_kind VARCHAR(40) NULL, evidence_reference VARCHAR(1000) NULL,
 ownership_reference VARCHAR(1000) NULL, review_reason VARCHAR(2000) NULL,
 verification_period VARCHAR(120) NULL, expires_epoch BIGINT NULL,
 reviewed_by INT NULL, reviewed_epoch BIGINT NULL,
 submitted_epoch BIGINT NOT NULL, created_epoch BIGINT NOT NULL, updated_epoch BIGINT NOT NULL,
 details_delete_epoch BIGINT NOT NULL, details_erased_epoch BIGINT NULL,
 version INT NOT NULL DEFAULT 1,
 terms_version VARCHAR(40) NOT NULL, privacy_version VARCHAR(40) NOT NULL, consent_epoch BIGINT NOT NULL,
 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
 FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
 CHECK (status IN ('pending','verified','declined','resubmission_required','suspended','expired')),
 CHECK (status <> 'verified' OR (student_number_fingerprint IS NOT NULL AND expires_epoch IS NOT NULL)),
 INDEX idx_student_queue (status,submitted_epoch), INDEX idx_student_retention (details_delete_epoch),
 INDEX idx_student_reviewer (reviewed_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS student_review_events (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 application_id BIGINT NOT NULL, actor_user_id INT NULL,
 action VARCHAR(32) NOT NULL, previous_status VARCHAR(24) NULL, new_status VARCHAR(24) NOT NULL,
 notes VARCHAR(2000) NULL, student_message VARCHAR(2000) NULL, created_epoch BIGINT NOT NULL,
 FOREIGN KEY (application_id) REFERENCES student_applications(id) ON DELETE CASCADE,
 FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
 INDEX idx_student_event_history (application_id,id), INDEX idx_student_event_actor (actor_user_id),
 INDEX idx_student_event_retention (created_epoch)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS summary_access_subjects (
 subject_key CHAR(64) PRIMARY KEY,
 completed_count INT NOT NULL DEFAULT 0 CHECK (completed_count BETWEEN 0 AND 3),
 request_window_epoch BIGINT NOT NULL, request_count INT NOT NULL DEFAULT 0,
 created_epoch BIGINT NOT NULL, last_activity_epoch BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS student_trial_links (
 user_id INT PRIMARY KEY, subject_key CHAR(64) NOT NULL,
 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
 FOREIGN KEY (subject_key) REFERENCES summary_access_subjects(subject_key) ON DELETE RESTRICT,
 INDEX idx_trial_subject (subject_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS summary_access_reservations (
 attempt_id CHAR(64) PRIMARY KEY, request_ref VARCHAR(100) NOT NULL,
 subject_key CHAR(64) NOT NULL, user_id INT NULL, privileged SMALLINT NOT NULL DEFAULT 0,
 state VARCHAR(16) NOT NULL, expires_epoch BIGINT NOT NULL, created_epoch BIGINT NOT NULL,
 FOREIGN KEY (subject_key) REFERENCES summary_access_subjects(subject_key) ON DELETE RESTRICT,
 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
 CHECK (state IN ('reserved','completed','released')),
 INDEX idx_access_active (subject_key,state,expires_epoch), INDEX idx_access_request (request_ref),
 INDEX idx_access_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
