-- ============================================================
-- AI Summarizer - Base Database Schema for PostgreSQL (Neon)
-- ============================================================

CREATE TABLE IF NOT EXISTS schema_migrations (
    id SERIAL PRIMARY KEY,
    migration VARCHAR(255) NOT NULL UNIQUE,
    applied_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'user' CHECK (role IN ('user', 'admin')),
    active SMALLINT NOT NULL DEFAULT 1,
    terms_accepted SMALLINT NOT NULL DEFAULT 0,
    terms_accepted_at TIMESTAMP WITH TIME ZONE NULL,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_users_role ON users(role);

CREATE TABLE IF NOT EXISTS guest_sessions (
    guest_token VARCHAR(64) PRIMARY KEY,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_activity TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP WITH TIME ZONE NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_guest_sessions_activity ON guest_sessions(last_activity);
CREATE INDEX IF NOT EXISTS idx_guest_sessions_expires ON guest_sessions(expires_at);

CREATE TABLE IF NOT EXISTS password_resets (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash VARCHAR(64) NOT NULL,
    expires_at TIMESTAMP WITH TIME ZONE NOT NULL,
    used SMALLINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_password_resets_user ON password_resets(user_id);
CREATE INDEX IF NOT EXISTS idx_password_resets_token ON password_resets(token_hash);

CREATE TABLE IF NOT EXISTS rate_limits (
    id SERIAL PRIMARY KEY,
    action_type VARCHAR(30) NOT NULL,
    identifier VARCHAR(255) NOT NULL,
    attempts INT DEFAULT 0,
    window_start TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_action_id UNIQUE (action_type, identifier)
);
CREATE INDEX IF NOT EXISTS idx_rate_limits_window ON rate_limits(window_start);

CREATE TABLE IF NOT EXISTS summaries (
    id SERIAL PRIMARY KEY,
    user_id INT NULL REFERENCES users(id) ON DELETE CASCADE,
    guest_token VARCHAR(64) NULL,
    share_token VARCHAR(64) NOT NULL UNIQUE,
    article_title VARCHAR(255) NOT NULL,
    author_name VARCHAR(255) NULL,
    input_type VARCHAR(20) NOT NULL CHECK (input_type IN ('text', 'pdf', 'docx', 'url')),
    original_text TEXT NOT NULL,
    generated_summary TEXT NOT NULL,
    summary_style VARCHAR(50) DEFAULT 'standard_paragraph',
    status VARCHAR(20) NOT NULL DEFAULT 'completed' CHECK (status IN ('pending', 'processing', 'completed', 'failed')),
    error_message TEXT NULL,
    summary_length VARCHAR(32) NULL,
    nutshell_text TEXT NULL,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_summaries_user ON summaries(user_id);
CREATE INDEX IF NOT EXISTS idx_summaries_guest ON summaries(guest_token);
CREATE INDEX IF NOT EXISTS idx_summaries_guest_owner ON summaries(guest_token, user_id);
CREATE INDEX IF NOT EXISTS idx_summaries_created_owner ON summaries(created_at, user_id);
CREATE INDEX IF NOT EXISTS idx_summaries_status ON summaries(status);

CREATE TABLE IF NOT EXISTS summary_artifacts (
    id SERIAL PRIMARY KEY,
    summary_id INT NOT NULL REFERENCES summaries(id) ON DELETE CASCADE,
    artifact_type VARCHAR(50) NOT NULL,
    storage_path VARCHAR(255) NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_sa_summary ON summary_artifacts(summary_id);

CREATE TABLE IF NOT EXISTS feedback (
    id SERIAL PRIMARY KEY,
    summary_id INT NOT NULL REFERENCES summaries(id) ON DELETE CASCADE,
    user_id INT NULL REFERENCES users(id) ON DELETE CASCADE,
    guest_token VARCHAR(64) NULL,
    rating SMALLINT NOT NULL,
    name VARCHAR(100) NULL,
    email VARCHAR(150) NULL,
    impression VARCHAR(50) NULL,
    reasons TEXT NULL,
    comment TEXT NULL,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_user_summary UNIQUE (summary_id, user_id),
    CONSTRAINT uq_guest_summary UNIQUE (summary_id, guest_token)
);
CREATE INDEX IF NOT EXISTS idx_feedback_user ON feedback(user_id);
CREATE INDEX IF NOT EXISTS idx_feedback_summary ON feedback(summary_id);
CREATE INDEX IF NOT EXISTS idx_feedback_guest_owner ON feedback(guest_token, user_id);

CREATE TABLE IF NOT EXISTS feedback_comments (
    id SERIAL PRIMARY KEY,
    summary_id INT NOT NULL REFERENCES summaries(id) ON DELETE CASCADE,
    rating SMALLINT NULL,
    reasons TEXT NULL,
    comment TEXT NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_fc_summary ON feedback_comments(summary_id);
CREATE INDEX IF NOT EXISTS idx_fc_created ON feedback_comments(created_at);

CREATE TABLE IF NOT EXISTS documents (
    id SERIAL PRIMARY KEY,
    content TEXT NOT NULL,
    user_id INT NULL,
    guest_token VARCHAR(64) NULL,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_documents_user ON documents(user_id);
CREATE INDEX IF NOT EXISTS idx_documents_guest ON documents(guest_token);
CREATE INDEX IF NOT EXISTS idx_documents_created_at ON documents(created_at);

CREATE TABLE IF NOT EXISTS document_summaries (
    id SERIAL PRIMARY KEY,
    document_id INT NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
    profile VARCHAR(32) NOT NULL,
    length VARCHAR(32) NOT NULL,
    summary TEXT NOT NULL,
    word_count INT NOT NULL,
    source_word_count INT NOT NULL,
    compression_ratio DECIMAL(5,2) NOT NULL,
    estimated_reading_time_seconds INT NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_doc_profile_length UNIQUE (document_id, profile, length)
);
CREATE INDEX IF NOT EXISTS idx_document_id ON document_summaries(document_id);
CREATE INDEX IF NOT EXISTS idx_doc_summaries_created_at ON document_summaries(created_at);

CREATE TABLE IF NOT EXISTS admin_security_challenges (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    challenge_type VARCHAR(50) NOT NULL,
    secret_hash VARCHAR(255) NOT NULL,
    is_active SMALLINT NOT NULL DEFAULT 1,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_asc_user ON admin_security_challenges(user_id);

CREATE TABLE IF NOT EXISTS admin_audit_logs (
    id SERIAL PRIMARY KEY,
    user_id INT NULL REFERENCES users(id) ON DELETE SET NULL,
    action VARCHAR(100) NOT NULL,
    details TEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_aal_user ON admin_audit_logs(user_id);
CREATE INDEX IF NOT EXISTS idx_aal_created ON admin_audit_logs(created_at);

CREATE TABLE IF NOT EXISTS nutshell_generations (
    id SERIAL PRIMARY KEY,
    summary_id INT NULL REFERENCES summaries(id) ON DELETE SET NULL,
    user_id INT NULL REFERENCES users(id) ON DELETE SET NULL,
    guest_token VARCHAR(64) NULL,
    source_word_count INT NOT NULL DEFAULT 0,
    nutshell_text TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'completed' CHECK (status IN ('completed', 'failed')),
    failure_reason VARCHAR(80) NULL,
    analysis_mode VARCHAR(24) NOT NULL DEFAULT 'general',
    output_format VARCHAR(24) NOT NULL DEFAULT 'paragraph',
    primary_summary_word_count INT NOT NULL DEFAULT 0,
    compression_ratio DECIMAL(8,4) NOT NULL DEFAULT 0,
    processing_time_ms INT NOT NULL DEFAULT 0,
    algorithm_version VARCHAR(32) NOT NULL DEFAULT 'nutshell-extractive-v2',
    idempotency_key CHAR(64) NULL UNIQUE,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_ng_status_created ON nutshell_generations(status, created_at);
CREATE INDEX IF NOT EXISTS idx_ng_user ON nutshell_generations(user_id);

CREATE TABLE IF NOT EXISTS user_tokens (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash VARCHAR(64) NOT NULL UNIQUE,
    token_type VARCHAR(32) NOT NULL,
    expires_at TIMESTAMP WITH TIME ZONE NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_user_tokens_lookup ON user_tokens(user_id, token_type);

CREATE TABLE IF NOT EXISTS landing_page_sessions (
    session_id VARCHAR(64) PRIMARY KEY,
    user_id INT NULL,
    guest_token VARCHAR(64) NULL,
    ip_hash CHAR(64) NOT NULL,
    user_agent VARCHAR(255) NULL,
    device_category VARCHAR(20) NOT NULL DEFAULT 'desktop' CHECK (device_category IN ('mobile','tablet','desktop')),
    referrer VARCHAR(512) NULL,
    lcp_ms INT NULL,
    fid_ms INT NULL,
    cls_score DECIMAL(6,4) NULL,
    inp_ms INT NULL,
    max_scroll_percent SMALLINT NOT NULL DEFAULT 0,
    dwell_time_seconds INT NOT NULL DEFAULT 0,
    converted SMALLINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_lps_created ON landing_page_sessions(created_at);
CREATE INDEX IF NOT EXISTS idx_lps_converted ON landing_page_sessions(converted);
CREATE INDEX IF NOT EXISTS idx_lps_device ON landing_page_sessions(device_category);

CREATE TABLE IF NOT EXISTS landing_page_events (
    id BIGSERIAL PRIMARY KEY,
    session_id VARCHAR(64) NOT NULL REFERENCES landing_page_sessions(session_id) ON DELETE CASCADE,
    event_type VARCHAR(50) NOT NULL,
    event_label VARCHAR(100) NULL,
    event_value INT NULL,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_lpe_session ON landing_page_events(session_id, event_type);
CREATE INDEX IF NOT EXISTS idx_lpe_created ON landing_page_events(created_at);

CREATE TABLE IF NOT EXISTS summary_jobs (
    id BIGSERIAL PRIMARY KEY,
    job_id VARCHAR(64) NOT NULL UNIQUE,
    user_id INT NULL REFERENCES users(id) ON DELETE SET NULL,
    guest_token VARCHAR(64) NULL,
    state VARCHAR(20) NOT NULL DEFAULT 'queued' CHECK (state IN ('queued','processing','completed','failed','cancelled','timed_out')),
    payload TEXT NOT NULL,
    idempotency_key VARCHAR(128) NOT NULL UNIQUE,
    result_id INT NULL REFERENCES summaries(id) ON DELETE SET NULL,
    error_message TEXT NULL,
    error_details TEXT NULL,
    retry_count SMALLINT NOT NULL DEFAULT 0,
    worker_pid INT NULL,
    started_at TIMESTAMP WITH TIME ZONE NULL,
    completed_at TIMESTAMP WITH TIME ZONE NULL,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_summary_jobs_state ON summary_jobs(state);
CREATE INDEX IF NOT EXISTS idx_summary_jobs_user ON summary_jobs(user_id);
CREATE INDEX IF NOT EXISTS idx_summary_jobs_guest ON summary_jobs(guest_token);
CREATE INDEX IF NOT EXISTS idx_summary_jobs_result ON summary_jobs(result_id);
CREATE INDEX IF NOT EXISTS idx_summary_jobs_created ON summary_jobs(created_at);
