-- ============================================================
-- Migration 011: Combinable Document Summaries & Cache Table
-- Provides on-demand, cached (documentId, profile, length) storage
-- ============================================================

CREATE TABLE IF NOT EXISTS `documents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `content` longtext NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `guest_token` varchar(64) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_documents_user` (`user_id`),
  KEY `idx_documents_guest` (`guest_token`),
  KEY `idx_documents_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `document_summaries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `document_id` int(11) NOT NULL,
  `profile` varchar(32) NOT NULL,
  `length` varchar(32) NOT NULL,
  `summary` longtext NOT NULL,
  `word_count` int(11) NOT NULL,
  `source_word_count` int(11) NOT NULL,
  `compression_ratio` decimal(5,2) NOT NULL,
  `estimated_reading_time_seconds` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_doc_profile_length` (`document_id`, `profile`, `length`),
  KEY `idx_document_id` (`document_id`),
  KEY `idx_doc_summaries_created_at` (`created_at`),
  CONSTRAINT `fk_doc_summaries_document` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
