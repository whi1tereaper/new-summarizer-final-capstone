-- ============================================================
-- Landing Page Analytics Schema
-- Run once against the ai_summarizer database.
-- ============================================================

-- One row per unique browser session that lands on index.php.
-- Session ID is generated client-side and stored in sessionStorage
-- so it survives in-page navigations but resets on a new tab/visit.
CREATE TABLE IF NOT EXISTS `landing_page_sessions` (
  `session_id`         VARCHAR(64)                          NOT NULL,
  `user_id`            INT(11)                              DEFAULT NULL,
  `guest_token`        VARCHAR(64)                          DEFAULT NULL,
  `ip_hash`            CHAR(64)                             NOT NULL,
  `user_agent`         VARCHAR(255)                         DEFAULT NULL,
  `device_category`    ENUM('mobile','tablet','desktop')    NOT NULL DEFAULT 'desktop',
  `referrer`           VARCHAR(512)                         DEFAULT NULL,
  -- Core Web Vitals (all nullable; set on first beacon that carries them)
  `lcp_ms`             INT(11)                              DEFAULT NULL,
  `fid_ms`             INT(11)                              DEFAULT NULL,
  `cls_score`          DECIMAL(6,4)                         DEFAULT NULL,
  -- Engagement metrics aggregated across all beacons for this session
  `max_scroll_percent` TINYINT(3) UNSIGNED                  NOT NULL DEFAULT 0,
  `dwell_time_seconds` SMALLINT(5) UNSIGNED                 NOT NULL DEFAULT 0,
  -- 1 = visitor clicked "Get Started" or "Register" at least once
  `converted`          TINYINT(1)                           NOT NULL DEFAULT 0,
  `created_at`         TIMESTAMP                            NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at`         TIMESTAMP                            NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  PRIMARY KEY (`session_id`),
  KEY `idx_lps_created`   (`created_at`),
  KEY `idx_lps_converted` (`converted`),
  KEY `idx_lps_device`    (`device_category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Append-only event log. One row per discrete interaction.
-- event_type values: pageview | cta_click | scroll_milestone | web_vitals | page_exit
CREATE TABLE IF NOT EXISTS `landing_page_events` (
  `id`           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_id`   VARCHAR(64)         NOT NULL,
  `event_type`   VARCHAR(50)         NOT NULL,
  `event_label`  VARCHAR(100)        DEFAULT NULL,
  `event_value`  INT(11)             DEFAULT NULL,
  `created_at`   TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  KEY `idx_lpe_session` (`session_id`, `event_type`),
  KEY `idx_lpe_created` (`created_at`),
  CONSTRAINT `fk_lpe_session`
    FOREIGN KEY (`session_id`)
    REFERENCES `landing_page_sessions` (`session_id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
