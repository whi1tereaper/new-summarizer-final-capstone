-- Evaluation data is deliberately separate from production summaries and analytics.
-- Additive and rerunnable. Rollback: drop evaluation_* tables in reverse FK order
-- only after exporting research records; never remove populated research data automatically.
CREATE TABLE IF NOT EXISTS evaluation_datasets (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 version VARCHAR(100) NOT NULL UNIQUE, name VARCHAR(255) NOT NULL,
 notes TEXT NULL, target_count INT UNSIGNED NOT NULL DEFAULT 48,
 active TINYINT(1) NOT NULL DEFAULT 1, sealed_at DATETIME NULL,
 created_by INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_eval_dataset_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS evaluation_documents (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, dataset_id BIGINT UNSIGNED NOT NULL,
 title VARCHAR(255) NOT NULL, category VARCHAR(100) NOT NULL,
 source_text LONGTEXT NOT NULL, source_hash CHAR(64) NOT NULL,
 source_reference VARCHAR(500) NULL, original_word_count INT UNSIGNED NOT NULL,
 profile VARCHAR(32) NOT NULL, dataset_split VARCHAR(20) NOT NULL,
 provenance TEXT NOT NULL, notes TEXT NULL, active TINYINT(1) NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_eval_doc_dataset (dataset_id,dataset_split,active),
 CONSTRAINT fk_eval_doc_dataset FOREIGN KEY (dataset_id) REFERENCES evaluation_datasets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS evaluation_references (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, document_id BIGINT UNSIGNED NOT NULL,
 reference_text LONGTEXT NOT NULL, reference_version VARCHAR(100) NOT NULL,
 author_code VARCHAR(100) NULL, review_status VARCHAR(20) NOT NULL DEFAULT 'draft',
 created_by INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_eval_reference (document_id,reference_version),
 CONSTRAINT fk_eval_reference_doc FOREIGN KEY (document_id) REFERENCES evaluation_documents(id),
 CONSTRAINT fk_eval_reference_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS evaluation_content_units (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, document_id BIGINT UNSIGNED NOT NULL,
 unit_text TEXT NOT NULL, unit_type VARCHAR(100) NOT NULL DEFAULT 'essential_information',
 created_by INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_eval_unit_doc FOREIGN KEY (document_id) REFERENCES evaluation_documents(id),
 CONSTRAINT fk_eval_unit_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS evaluation_challenge_cases (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, document_id BIGINT UNSIGNED NOT NULL,
 category VARCHAR(100) NOT NULL, expected_facts_json LONGTEXT NOT NULL,
 prohibited_distortions_json LONGTEXT NOT NULL, notes TEXT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_eval_challenge_doc FOREIGN KEY (document_id) REFERENCES evaluation_documents(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS evaluation_runs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, run_uuid CHAR(32) NOT NULL UNIQUE,
 name VARCHAR(255) NOT NULL, dataset_id BIGINT UNSIGNED NOT NULL,
 dataset_version VARCHAR(100) NOT NULL, dataset_split VARCHAR(20) NOT NULL,
 summarizer_version VARCHAR(100) NOT NULL DEFAULT 'pending-worker',
 evaluation_version VARCHAR(30) NOT NULL DEFAULT '1.0.0',
 configuration_hash CHAR(64) NOT NULL, configuration_json LONGTEXT NOT NULL,
 dataset_snapshot_json LONGTEXT NOT NULL, snapshot_hash CHAR(64) NOT NULL,
 provenance_json LONGTEXT NULL, report_json LONGTEXT NULL,
 report_pending TINYINT(1) NOT NULL DEFAULT 1, report_error TEXT NULL,
 status VARCHAR(30) NOT NULL DEFAULT 'queued',
 documents_total INT UNSIGNED NOT NULL, documents_processed INT UNSIGNED NOT NULL DEFAULT 0,
 outputs_total INT UNSIGNED NOT NULL, generated_summaries INT UNSIGNED NOT NULL DEFAULT 0,
 failed_summaries INT UNSIGNED NOT NULL DEFAULT 0,
 created_by INT NULL, notes TEXT NULL, error_message TEXT NULL,
 started_at DATETIME NULL, completed_at DATETIME NULL, heartbeat_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_eval_run_status (status,created_at),
 CONSTRAINT fk_eval_run_dataset FOREIGN KEY (dataset_id) REFERENCES evaluation_datasets(id),
 CONSTRAINT fk_eval_run_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS evaluation_outputs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, run_id BIGINT UNSIGNED NOT NULL,
 document_id BIGINT UNSIGNED NOT NULL, profile VARCHAR(32) NOT NULL,
 mode VARCHAR(32) NOT NULL, system_name VARCHAR(50) NOT NULL,
 ablation VARCHAR(100) NOT NULL DEFAULT '', status VARCHAR(30) NOT NULL DEFAULT 'queued',
 summary_text LONGTEXT NULL, word_count INT UNSIGNED NULL,
 source_word_count INT UNSIGNED NULL, target_words INT UNSIGNED NULL,
 processing_seconds DOUBLE NULL, timings_json TEXT NULL,
 configuration_hash CHAR(64) NULL, configuration_json LONGTEXT NULL,
 provenance_json LONGTEXT NULL, warnings_json LONGTEXT NULL, error_message TEXT NULL,
 generated_at DATETIME NULL,
 UNIQUE KEY uq_eval_output (run_id,document_id,mode,system_name,ablation),
 KEY idx_eval_output_run_status (run_id,status),
 CONSTRAINT fk_eval_output_run FOREIGN KEY (run_id) REFERENCES evaluation_runs(id),
 CONSTRAINT fk_eval_output_doc FOREIGN KEY (document_id) REFERENCES evaluation_documents(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS evaluation_metrics (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, output_id BIGINT UNSIGNED NOT NULL,
 metric_name VARCHAR(100) NOT NULL, metric_version VARCHAR(100) NOT NULL,
 status VARCHAR(30) NOT NULL, metric_value DOUBLE NULL,
 precision_value DOUBLE NULL, recall_value DOUBLE NULL, f1_value DOUBLE NULL,
 details_json LONGTEXT NULL, error_message TEXT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_eval_metric (output_id,metric_name), KEY idx_eval_metric_name (metric_name,status),
 CONSTRAINT fk_eval_metric_output FOREIGN KEY (output_id) REFERENCES evaluation_outputs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS evaluation_assignments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, assignment_token CHAR(48) NOT NULL UNIQUE,
 run_id BIGINT UNSIGNED NOT NULL, output_id BIGINT UNSIGNED NOT NULL,
 evaluator_user_id INT NOT NULL, evaluator_code VARCHAR(20) NOT NULL,
 blind_label VARCHAR(50) NOT NULL, presentation_order INT UNSIGNED NOT NULL,
 randomization_seed CHAR(64) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'assigned',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, submitted_at DATETIME NULL,
 UNIQUE KEY uq_eval_assignment (output_id,evaluator_user_id),
 KEY idx_eval_assignment_user (evaluator_user_id,status,presentation_order),
 CONSTRAINT fk_eval_assignment_run FOREIGN KEY (run_id) REFERENCES evaluation_runs(id),
 CONSTRAINT fk_eval_assignment_output FOREIGN KEY (output_id) REFERENCES evaluation_outputs(id),
 CONSTRAINT fk_eval_assignment_user FOREIGN KEY (evaluator_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS evaluation_ratings (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, assignment_id BIGINT UNSIGNED NOT NULL UNIQUE,
 relevance TINYINT UNSIGNED NOT NULL, factual_consistency TINYINT UNSIGNED NOT NULL,
 coverage TINYINT UNSIGNED NOT NULL, coherence TINYINT UNSIGNED NOT NULL,
 readability TINYINT UNSIGNED NOT NULL, conciseness TINYINT UNSIGNED NOT NULL,
 non_redundancy TINYINT UNSIGNED NOT NULL, overall_usefulness TINYINT UNSIGNED NULL,
 comments TEXT NULL, content_unit_verification_json LONGTEXT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, corrected_at DATETIME NULL,
 CONSTRAINT fk_eval_rating_assignment FOREIGN KEY (assignment_id) REFERENCES evaluation_assignments(id),
 CONSTRAINT ck_eval_rating_range CHECK (relevance BETWEEN 1 AND 5 AND factual_consistency BETWEEN 1 AND 5 AND coverage BETWEEN 1 AND 5 AND coherence BETWEEN 1 AND 5 AND readability BETWEEN 1 AND 5 AND conciseness BETWEEN 1 AND 5 AND non_redundancy BETWEEN 1 AND 5 AND (overall_usefulness IS NULL OR overall_usefulness BETWEEN 1 AND 5))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS evaluation_errors (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, output_id BIGINT UNSIGNED NOT NULL,
 category VARCHAR(100) NOT NULL, notes TEXT NULL, reviewer_id INT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_eval_error_category (category),
 CONSTRAINT fk_eval_error_output FOREIGN KEY (output_id) REFERENCES evaluation_outputs(id),
 CONSTRAINT fk_eval_error_user FOREIGN KEY (reviewer_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS evaluation_content_unit_reviews (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, output_id BIGINT UNSIGNED NOT NULL,
 unit_id BIGINT UNSIGNED NOT NULL, represented TINYINT(1) NOT NULL,
 notes TEXT NULL, reviewer_id INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_eval_unit_review (output_id,unit_id),
 CONSTRAINT fk_eval_review_output FOREIGN KEY (output_id) REFERENCES evaluation_outputs(id),
 CONSTRAINT fk_eval_review_unit FOREIGN KEY (unit_id) REFERENCES evaluation_content_units(id),
 CONSTRAINT fk_eval_review_user FOREIGN KEY (reviewer_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS evaluation_audit_log (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, actor_id INT NULL,
 action VARCHAR(100) NOT NULL, entity_type VARCHAR(50) NOT NULL, entity_id BIGINT UNSIGNED NOT NULL,
 previous_json LONGTEXT NULL, replacement_json LONGTEXT NULL, reason TEXT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_eval_audit_entity (entity_type,entity_id),
 CONSTRAINT fk_eval_audit_user FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
