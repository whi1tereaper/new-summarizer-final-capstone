######### Data dictionary — verified live schema

Metadata was read directly from information_schema without loading the application bootstrap or reading application rows. The snapshot contains 31 tables, 312 columns and 35 foreign-key constraints. SQL types, defaults, nullability, keys and relationships below are observed metadata. Field-purpose prose is an interpretation of names and inspected service use, not a claim that every field is actively used.

The current live schema differs from the fresh-install scripts for password_resets: live uses used_at and has no used/created_at columns, while database/schema.sql defines used and created_at and no checked-in migration adds used_at. Do not treat the base SQL alone as the deployed schema.

Tables are presented alphabetically. Each table has a manuscript caption and a stable dictionary identifier.

##### DD-01. admin_audit_logs

Administrative action evidence and target references.

Table 7. Data dictionary for admin_audit_logs.

| Field Name | Description | Data Type/Length | Constraints |
|---|---|---|---|
| id | Unique row identifier. | bigint(20) unsigned | NOT NULL; PRIMARY KEY; auto_increment; default SQL NULL / no explicit non-null default |
| admin_id | Admin id. | int(11) | NULL allowed; default NULL |
| action | Action. | varchar(100) | NOT NULL; default SQL NULL / no explicit non-null default |
| entity_type | Entity type. | varchar(100) | NULL allowed; default NULL |
| entity_id | Entity id. | bigint(20) unsigned | NULL allowed; default NULL |
| ip_address | Ip address. | varchar(45) | NULL allowed; default NULL |
| user_agent | User agent. | varchar(255) | NULL allowed; default NULL |
| details | Details. | text | NULL allowed; default NULL |
| created_at | Creation timestamp. | datetime | NOT NULL; default current_timestamp() |

Indexes and constraints:

- `PRIMARY`: UNIQUE (id).
- `idx_admin_audit_entity`: nonunique (entity_type, entity_id).
- `idx_admin_audit_logs_action`: nonunique (action).
- `idx_admin_audit_logs_admin_id`: nonunique (admin_id).
- `idx_admin_audit_logs_created_at`: nonunique (created_at).

The table supports administrative action evidence and target references. Its constraints describe enforced storage behavior; runtime verification is reported separately.


Table 7 records the observed columns and constraints for admin_audit_logs. The descriptions explain their storage purpose; runtime behavior remains subject to the separately stated verification limits.

##### DD-02. admin_security_challenges

Legacy administrator challenge storage; current login uses emailed codes.

Table 8. Data dictionary for admin_security_challenges.

| Field Name | Description | Data Type/Length | Constraints |
|---|---|---|---|
| id | Unique row identifier. | bigint(20) unsigned | NOT NULL; PRIMARY KEY; auto_increment; default SQL NULL / no explicit non-null default |
| user_id | Registered account identifier; an enforced relation only where listed below. | int(11) | NOT NULL; default SQL NULL / no explicit non-null default; FK → users.id; DELETE CASCADE; UPDATE CASCADE |
| challenge_hash | Challenge hash. | varchar(255) | NOT NULL; default SQL NULL / no explicit non-null default |
| failed_attempts | Failed attempts. | int(10) unsigned | NOT NULL; default 0 |
| locked_until | Locked until. | datetime | NULL allowed; default NULL |
| created_at | Creation timestamp. | datetime | NOT NULL; default current_timestamp() |
| updated_at | Most recent update timestamp. | datetime | NULL allowed; default NULL |

Indexes and constraints:

- `PRIMARY`: UNIQUE (id).
- `idx_admin_security_locked_until`: nonunique (locked_until).
- `uq_admin_security_user`: UNIQUE (user_id).

The table supports legacy administrator challenge storage; current login uses emailed codes. Its constraints describe enforced storage behavior; runtime verification is reported separately.


Table 8 records the observed columns and constraints for admin_security_challenges. The descriptions explain their storage purpose; runtime behavior remains subject to the separately stated verification limits.

##### DD-03. documents

Independent document API source content and nominal owner fields.

Table 9. Data dictionary for documents.

| Field Name | Description | Data Type/Length | Constraints |
|---|---|---|---|
| id | Unique row identifier. | int(11) | NOT NULL; PRIMARY KEY; auto_increment; default SQL NULL / no explicit non-null default |
| content | Stored source document text. | longtext | NOT NULL; default SQL NULL / no explicit non-null default |
| user_id | Registered account identifier; an enforced relation only where listed below. | int(11) | NULL allowed; default NULL |
| guest_token | Anonymous owner/session token; not a foreign key unless explicitly listed. | varchar(64) | NULL allowed; default NULL |
| created_at | Creation timestamp. | timestamp | NOT NULL; default current_timestamp() |

Indexes and constraints:

- `PRIMARY`: UNIQUE (id).
- `idx_documents_created_at`: nonunique (created_at).
- `idx_documents_guest`: nonunique (guest_token).
- `idx_documents_user`: nonunique (user_id).

The table supports independent document api source content and nominal owner fields. Its constraints describe enforced storage behavior; runtime verification is reported separately.


Table 9 records the observed columns and constraints for documents. The descriptions explain their storage purpose; runtime behavior remains subject to the separately stated verification limits.

##### DD-04. document_summaries

Cached summary for each document/profile/length combination.

Table 10. Data dictionary for document_summaries.

| Field Name | Description | Data Type/Length | Constraints |
|---|---|---|---|
| id | Unique row identifier. | int(11) | NOT NULL; PRIMARY KEY; auto_increment; default SQL NULL / no explicit non-null default |
| document_id | Related document identifier in this table’s domain. | int(11) | NOT NULL; default SQL NULL / no explicit non-null default; FK → documents.id; DELETE CASCADE; UPDATE RESTRICT |
| profile | Content emphasis/profile selection. | varchar(32) | NOT NULL; default SQL NULL / no explicit non-null default |
| length | Summary depth selection. | varchar(32) | NOT NULL; default SQL NULL / no explicit non-null default |
| summary | Summary. | longtext | NOT NULL; default SQL NULL / no explicit non-null default |
| word_count | Word count. | int(11) | NOT NULL; default SQL NULL / no explicit non-null default |
| source_word_count | Source word count. | int(11) | NOT NULL; default SQL NULL / no explicit non-null default |
| compression_ratio | Module-specific compression metric; check service semantics before comparisons. | decimal(5,2) | NOT NULL; default SQL NULL / no explicit non-null default |
| estimated_reading_time_seconds | Estimated reading time seconds. | int(11) | NOT NULL; default SQL NULL / no explicit non-null default |
| created_at | Creation timestamp. | timestamp | NOT NULL; default current_timestamp() |

Indexes and constraints:

- `PRIMARY`: UNIQUE (id).
- `idx_doc_summaries_created_at`: nonunique (created_at).
- `idx_document_id`: nonunique (document_id).
- `uq_doc_profile_length`: UNIQUE (document_id, profile, length).

The table supports cached summary for each document/profile/length combination. Its constraints describe enforced storage behavior; runtime verification is reported separately.


Table 10 records the observed columns and constraints for document_summaries. The descriptions explain their storage purpose; runtime behavior remains subject to the separately stated verification limits.

##### DD-05. evaluation_assignments

Blinded output assignments to registered evaluator accounts.

Table 11. Data dictionary for evaluation_assignments.

| Field Name | Description | Data Type/Length | Constraints |
|---|---|---|---|
| id | Unique row identifier. | bigint(20) unsigned | NOT NULL; PRIMARY KEY; auto_increment; default SQL NULL / no explicit non-null default |
| assignment_token | Assignment token. | char(48) | NOT NULL; default SQL NULL / no explicit non-null default |
| run_id | Related evaluation run identifier. | bigint(20) unsigned | NOT NULL; default SQL NULL / no explicit non-null default; FK → evaluation_runs.id; DELETE RESTRICT; UPDATE RESTRICT |
| output_id | Related generated evaluation output identifier. | bigint(20) unsigned | NOT NULL; default SQL NULL / no explicit non-null default; FK → evaluation_outputs.id; DELETE RESTRICT; UPDATE RESTRICT |
| evaluator_user_id | Evaluator user id. | int(11) | NOT NULL; default SQL NULL / no explicit non-null default; FK → users.id; DELETE RESTRICT; UPDATE RESTRICT |
| evaluator_code | Evaluator code. | varchar(20) | NOT NULL; default SQL NULL / no explicit non-null default |
| blind_label | Evaluator-visible label that conceals system identity. | varchar(50) | NOT NULL; default SQL NULL / no explicit non-null default |
| presentation_order | Presentation order. | int(10) unsigned | NOT NULL; default SQL NULL / no explicit non-null default |
| randomization_seed | Randomization seed. | char(64) | NOT NULL; default SQL NULL / no explicit non-null default |
| status | Domain status marker. | varchar(20) | NOT NULL; default 'assigned' |
| created_at | Creation timestamp. | datetime | NOT NULL; default current_timestamp() |
| submitted_at | Submitted at. | datetime | NULL allowed; default NULL |

Indexes and constraints:

- `PRIMARY`: UNIQUE (id).
- `assignment_token`: UNIQUE (assignment_token).
- `fk_eval_assignment_run`: nonunique (run_id).
- `idx_eval_assignment_user`: nonunique (evaluator_user_id, status, presentation_order).
- `uq_eval_assignment`: UNIQUE (output_id, evaluator_user_id).

The table supports blinded output assignments to registered evaluator accounts. Its constraints describe enforced storage behavior; runtime verification is reported separately.


Table 11 records the observed columns and constraints for evaluation_assignments. The descriptions explain their storage purpose; runtime behavior remains subject to the separately stated verification limits.

##### DD-06. evaluation_audit_log

Changes to evaluation records with old/new values and reasons.

Table 12. Data dictionary for evaluation_audit_log.

| Field Name | Description | Data Type/Length | Constraints |
|---|---|---|---|
| id | Unique row identifier. | bigint(20) unsigned | NOT NULL; PRIMARY KEY; auto_increment; default SQL NULL / no explicit non-null default |
| actor_id | Actor id. | int(11) | NULL allowed; default NULL; FK → users.id; DELETE SET NULL; UPDATE RESTRICT |
| action | Action. | varchar(100) | NOT NULL; default SQL NULL / no explicit non-null default |
| entity_type | Entity type. | varchar(50) | NOT NULL; default SQL NULL / no explicit non-null default |
| entity_id | Entity id. | bigint(20) unsigned | NOT NULL; default SQL NULL / no explicit non-null default |
| previous_json | Previous json. | longtext | NULL allowed; default NULL |
| replacement_json | Replacement json. | longtext | NULL allowed; default NULL |
| reason | Reason. | text | NULL allowed; default NULL |
| created_at | Creation timestamp. | datetime | NOT NULL; default current_timestamp() |

Indexes and constraints:

- `PRIMARY`: UNIQUE (id).
- `fk_eval_audit_user`: nonunique (actor_id).
- `idx_eval_audit_entity`: nonunique (entity_type, entity_id).

The table supports changes to evaluation records with old/new values and reasons. Its constraints describe enforced storage behavior; runtime verification is reported separately.


Table 12 records the observed columns and constraints for evaluation_audit_log. The descriptions explain their storage purpose; runtime behavior remains subject to the separately stated verification limits.

##### DD-07. evaluation_challenge_cases

Expected facts and prohibited distortions for challenge cases.

Table 13. Data dictionary for evaluation_challenge_cases.

| Field Name | Description | Data Type/Length | Constraints |
|---|---|---|---|
| id | Unique row identifier. | bigint(20) unsigned | NOT NULL; PRIMARY KEY; auto_increment; default SQL NULL / no explicit non-null default |
| document_id | Related document identifier in this table’s domain. | bigint(20) unsigned | NOT NULL; default SQL NULL / no explicit non-null default; FK → evaluation_documents.id; DELETE RESTRICT; UPDATE RESTRICT |
| category | Category. | varchar(100) | NOT NULL; default SQL NULL / no explicit non-null default |
| expected_facts_json | Expected facts json. | longtext | NOT NULL; default SQL NULL / no explicit non-null default |
| prohibited_distortions_json | Prohibited distortions json. | longtext | NOT NULL; default SQL NULL / no explicit non-null default |
| notes | Notes. | text | NULL allowed; default NULL |
| created_at | Creation timestamp. | datetime | NOT NULL; default current_timestamp() |

Indexes and constraints:

- `PRIMARY`: UNIQUE (id).
- `fk_eval_challenge_doc`: nonunique (document_id).

The table supports expected facts and prohibited distortions for challenge cases. Its constraints describe enforced storage behavior; runtime verification is reported separately.


Table 13 records the observed columns and constraints for evaluation_challenge_cases. The descriptions explain their storage purpose; runtime behavior remains subject to the separately stated verification limits.

##### DD-08. evaluation_content_units

Essential source facts to assess coverage.

Table 14. Data dictionary for evaluation_content_units.

| Field Name | Description | Data Type/Length | Constraints |
|---|---|---|---|
| id | Unique row identifier. | bigint(20) unsigned | NOT NULL; PRIMARY KEY; auto_increment; default SQL NULL / no explicit non-null default |
| document_id | Related document identifier in this table’s domain. | bigint(20) unsigned | NOT NULL; default SQL NULL / no explicit non-null default; FK → evaluation_documents.id; DELETE RESTRICT; UPDATE RESTRICT |
| unit_text | Unit text. | text | NOT NULL; default SQL NULL / no explicit non-null default |
| unit_type | Unit type. | varchar(100) | NOT NULL; default 'essential_information' |
| created_by | Created by. | int(11) | NULL allowed; default NULL; FK → users.id; DELETE SET NULL; UPDATE RESTRICT |
| created_at | Creation timestamp. | datetime | NOT NULL; default current_timestamp() |

Indexes and constraints:

- `PRIMARY`: UNIQUE (id).
- `fk_eval_unit_doc`: nonunique (document_id).
- `fk_eval_unit_user`: nonunique (created_by).

The table supports essential source facts to assess coverage. Its constraints describe enforced storage behavior; runtime verification is reported separately.


Table 14 records the observed columns and constraints for evaluation_content_units. The descriptions explain their storage purpose; runtime behavior remains subject to the separately stated verification limits.

##### DD-09. evaluation_content_unit_reviews

Human judgments of whether output represents a content unit.

Table 15. Data dictionary for evaluation_content_unit_reviews.

| Field Name | Description | Data Type/Length | Constraints |
|---|---|---|---|
| id | Unique row identifier. | bigint(20) unsigned | NOT NULL; PRIMARY KEY; auto_increment; default SQL NULL / no explicit non-null default |
| output_id | Related generated evaluation output identifier. | bigint(20) unsigned | NOT NULL; default SQL NULL / no explicit non-null default; FK → evaluation_outputs.id; DELETE RESTRICT; UPDATE RESTRICT |
| unit_id | Unit id. | bigint(20) unsigned | NOT NULL; default SQL NULL / no explicit non-null default; FK → evaluation_content_units.id; DELETE RESTRICT; UPDATE RESTRICT |
| represented | Represented. | tinyint(1) | NOT NULL; default SQL NULL / no explicit non-null default |
| notes | Notes. | text | NULL allowed; default NULL |
| reviewer_id | Reviewer id. | int(11) | NULL allowed; default NULL; FK → users.id; DELETE SET NULL; UPDATE RESTRICT |
| created_at | Creation timestamp. | datetime | NOT NULL; default current_timestamp() |

Indexes and constraints:

- `PRIMARY`: UNIQUE (id).
- `fk_eval_review_unit`: nonunique (unit_id).
- `fk_eval_review_user`: nonunique (reviewer_id).
- `uq_eval_unit_review`: UNIQUE (output_id, unit_id).

The table supports human judgments of whether output represents a content unit. Its constraints describe enforced storage behavior; runtime verification is reported separately.


Table 15 records the observed columns and constraints for evaluation_content_unit_reviews. The descriptions explain their storage purpose; runtime behavior remains subject to the separately stated verification limits.

##### DD-10. evaluation_datasets

Versioned evaluation collections and sealing state.

Table 16. Data dictionary for evaluation_datasets.

| Field Name | Description | Data Type/Length | Constraints |
|---|---|---|---|
| id | Unique row identifier. | bigint(20) unsigned | NOT NULL; PRIMARY KEY; auto_increment; default SQL NULL / no explicit non-null default |
| version | Version. | varchar(100) | NOT NULL; default SQL NULL / no explicit non-null default |
| name | Name. | varchar(255) | NOT NULL; default SQL NULL / no explicit non-null default |
| notes | Notes. | text | NULL allowed; default NULL |
| target_count | Target count. | int(10) unsigned | NOT NULL; default 48 |
| active | Enabled/active state. | tinyint(1) | NOT NULL; default 1 |
| sealed_at | Sealed at. | datetime | NULL allowed; default NULL |
| created_by | Created by. | int(11) | NULL allowed; default NULL; FK → users.id; DELETE SET NULL; UPDATE RESTRICT |
| created_at | Creation timestamp. | datetime | NOT NULL; default current_timestamp() |

Indexes and constraints:

- `PRIMARY`: UNIQUE (id).
- `fk_eval_dataset_user`: nonunique (created_by).
- `version`: UNIQUE (version).

The table supports versioned evaluation collections and sealing state. Its constraints describe enforced storage behavior; runtime verification is reported separately.


Table 16 records the observed columns and constraints for evaluation_datasets. The descriptions explain their storage purpose; runtime behavior remains subject to the separately stated verification limits.

##### DD-11. evaluation_documents

Dataset source documents, split, profile and provenance.

Table 17. Data dictionary for evaluation_documents.

| Field Name | Description | Data Type/Length | Constraints |
|---|---|---|---|
| id | Unique row identifier. | bigint(20) unsigned | NOT NULL; PRIMARY KEY; auto_increment; default SQL NULL / no explicit non-null default |
| dataset_id | Dataset id. | bigint(20) unsigned | NOT NULL; default SQL NULL / no explicit non-null default; FK → evaluation_datasets.id; DELETE RESTRICT; UPDATE RESTRICT |
| title | Title. | varchar(255) | NOT NULL; default SQL NULL / no explicit non-null default |
| category | Category. | varchar(100) | NOT NULL; default SQL NULL / no explicit non-null default |
| source_text | Source text. | longtext | NOT NULL; default SQL NULL / no explicit non-null default |
| source_hash | Hash used to identify source content. | char(64) | NOT NULL; default SQL NULL / no explicit non-null default |
| source_reference | Source reference. | varchar(500) | NULL allowed; default NULL |
| original_word_count | Source word count; main file path currently counts the empty input string. | int(10) unsigned | NOT NULL; default SQL NULL / no explicit non-null default |
| profile | Content emphasis/profile selection. | varchar(32) | NOT NULL; default SQL NULL / no explicit non-null default |
| dataset_split | Dataset split. | varchar(20) | NOT NULL; default SQL NULL / no explicit non-null default |
| provenance | Provenance. | text | NOT NULL; default SQL NULL / no explicit non-null default |
| notes | Notes. | text | NULL allowed; default NULL |
| active | Enabled/active state. | tinyint(1) | NOT NULL; default 1 |
| created_at | Creation timestamp. | datetime | NOT NULL; default current_timestamp() |

Indexes and constraints:

- `PRIMARY`: UNIQUE (id).
- `idx_eval_doc_dataset`: nonunique (dataset_id, dataset_split, active).

The table supports dataset source documents, split, profile and provenance. Its constraints describe enforced storage behavior; runtime verification is reported separately.


Table 17 records the observed columns and constraints for evaluation_documents. The descriptions explain their storage purpose; runtime behavior remains subject to the separately stated verification limits.

##### DD-12. evaluation_errors

Reviewer-classified error observations.

Table 18. Data dictionary for evaluation_errors.

| Field Name | Description | Data Type/Length | Constraints |
|---|---|---|---|
| id | Unique row identifier. | bigint(20) unsigned | NOT NULL; PRIMARY KEY; auto_increment; default SQL NULL / no explicit non-null default |
| output_id | Related generated evaluation output identifier. | bigint(20) unsigned | NOT NULL; default SQL NULL / no explicit non-null default; FK → evaluation_outputs.id; DELETE RESTRICT; UPDATE RESTRICT |
| category | Category. | varchar(100) | NOT NULL; default SQL NULL / no explicit non-null default |
| notes | Notes. | text | NULL allowed; default NULL |
| reviewer_id | Reviewer id. | int(11) | NULL allowed; default NULL; FK → users.id; DELETE SET NULL; UPDATE RESTRICT |
| created_at | Creation timestamp. | datetime | NOT NULL; default current_timestamp() |

Indexes and constraints:

- `PRIMARY`: UNIQUE (id).
- `fk_eval_error_output`: nonunique (output_id).
- `fk_eval_error_user`: nonunique (reviewer_id).
- `idx_eval_error_category`: nonunique (category).

The table supports reviewer-classified error observations. Its constraints describe enforced storage behavior; runtime verification is reported separately.


Table 18 records the observed columns and constraints for evaluation_errors. The descriptions explain their storage purpose; runtime behavior remains subject to the separately stated verification limits.

##### DD-13. evaluation_metrics

Metric values, metric versions and availability/error status.

Table 19. Data dictionary for evaluation_metrics.

| Field Name | Description | Data Type/Length | Constraints |
|---|---|---|---|
| id | Unique row identifier. | bigint(20) unsigned | NOT NULL; PRIMARY KEY; auto_increment; default SQL NULL / no explicit non-null default |
| output_id | Related generated evaluation output identifier. | bigint(20) unsigned | NOT NULL; default SQL NULL / no explicit non-null default; FK → evaluation_outputs.id; DELETE RESTRICT; UPDATE RESTRICT |
| metric_name | Metric name. | varchar(100) | NOT NULL; default SQL NULL / no explicit non-null default |
| metric_version | Metric version. | varchar(100) | NOT NULL; default SQL NULL / no explicit non-null default |
| status | Domain status marker. | varchar(30) | NOT NULL; default SQL NULL / no explicit non-null default |
| metric_value | Metric value, nullable when unavailable. | double | NULL allowed; default NULL |
| precision_value | Precision value. | double | NULL allowed; default NULL |
| recall_value | Recall value. | double | NULL allowed; default NULL |
| f1_value | F1 value. | double | NULL allowed; default NULL |
| details_json | Details json. | longtext | NULL allowed; default NULL |
| error_message | Error message. | text | NULL allowed; default NULL |
| created_at | Creation timestamp. | datetime | NOT NULL; default current_timestamp() |

Indexes and constraints:

- `PRIMARY`: UNIQUE (id).
- `idx_eval_metric_name`: nonunique (metric_name, status).
- `uq_eval_metric`: UNIQUE (output_id, metric_name).

The table supports metric values, metric versions and availability/error status. Its constraints describe enforced storage behavior; runtime verification is reported separately.


Table 19 records the observed columns and constraints for evaluation_metrics. The descriptions explain their storage purpose; runtime behavior remains subject to the separately stated verification limits.

##### DD-14. evaluation_outputs

Generated output for a run/document/mode/system/ablation combination.

Table 20. Data dictionary for evaluation_outputs.

| Field Name | Description | Data Type/Length | Constraints |
|---|---|---|---|
| id | Unique row identifier. | bigint(20) unsigned | NOT NULL; PRIMARY KEY; auto_increment; default SQL NULL / no explicit non-null default |
| run_id | Related evaluation run identifier. | bigint(20) unsigned | NOT NULL; default SQL NULL / no explicit non-null default; FK → evaluation_runs.id; DELETE RESTRICT; UPDATE RESTRICT |
| document_id | Related document identifier in this table’s domain. | bigint(20) unsigned | NOT NULL; default SQL NULL / no explicit non-null default; FK → evaluation_documents.id; DELETE RESTRICT; UPDATE RESTRICT |
| profile | Content emphasis/profile selection. | varchar(32) | NOT NULL; default SQL NULL / no explicit non-null default |
| mode | Mode. | varchar(32) | NOT NULL; default SQL NULL / no explicit non-null default |
| system_name | System name. | varchar(50) | NOT NULL; default SQL NULL / no explicit non-null default |
| ablation | Ablation. | varchar(100) | NOT NULL; default '' |
| status | Domain status marker. | varchar(30) | NOT NULL; default 'queued' |
| summary_text | Summary text. | longtext | NULL allowed; default NULL |
| word_count | Word count. | int(10) unsigned | NULL allowed; default NULL |
| source_word_count | Source word count. | int(10) unsigned | NULL allowed; default NULL |
| target_words | Target words. | int(10) unsigned | NULL allowed; default NULL |
| processing_seconds | Processing seconds. | double | NULL allowed; default NULL |
| timings_json | Timings json. | text | NULL allowed; default NULL |
| configuration_hash | Hash of canonical run configuration. | char(64) | NULL allowed; default NULL |
| configuration_json | Configuration json. | longtext | NULL allowed; default NULL |
| provenance_json | Serialized execution/source provenance. | longtext | NULL allowed; default NULL |
| warnings_json | Warnings json. | longtext | NULL allowed; default NULL |
| error_message | Error message. | text | NULL allowed; default NULL |
| generated_at | Generated at. | datetime | NULL allowed; default NULL |

Indexes and constraints:

- `PRIMARY`: UNIQUE (id).
- `fk_eval_output_doc`: nonunique (document_id).
- `idx_eval_output_run_status`: nonunique (run_id, status).
- `uq_eval_output`: UNIQUE (run_id, document_id, mode, system_name, ablation).

The table supports generated output for a run/document/mode/system/ablation combination. Its constraints describe enforced storage behavior; runtime verification is reported separately.


Table 20 records the observed columns and constraints for evaluation_outputs. The descriptions explain their storage purpose; runtime behavior remains subject to the separately stated verification limits.

##### DD-15. evaluation_ratings

Human criterion ratings for an assignment.

Table 21. Data dictionary for evaluation_ratings.

| Field Name | Description | Data Type/Length | Constraints |
|---|---|---|---|
| id | Unique row identifier. | bigint(20) unsigned | NOT NULL; PRIMARY KEY; auto_increment; default SQL NULL / no explicit non-null default |
| assignment_id | Assignment id. | bigint(20) unsigned | NOT NULL; default SQL NULL / no explicit non-null default; FK → evaluation_assignments.id; DELETE RESTRICT; UPDATE RESTRICT |
| relevance | Relevance. | tinyint(3) unsigned | NOT NULL; default SQL NULL / no explicit non-null default |
| factual_consistency | Factual consistency. | tinyint(3) unsigned | NOT NULL; default SQL NULL / no explicit non-null default |
| coverage | Coverage. | tinyint(3) unsigned | NOT NULL; default SQL NULL / no explicit non-null default |
| coherence | Coherence. | tinyint(3) unsigned | NOT NULL; default SQL NULL / no explicit non-null default |
| readability | Readability. | tinyint(3) unsigned | NOT NULL; default SQL NULL / no explicit non-null default |
| conciseness | Conciseness. | tinyint(3) unsigned | NOT NULL; default SQL NULL / no explicit non-null default |
| non_redundancy | Non redundancy. | tinyint(3) unsigned | NOT NULL; default SQL NULL / no explicit non-null default |
| overall_usefulness | Overall usefulness. | tinyint(3) unsigned | NULL allowed; default NULL |
| comments | Comments. | text | NULL allowed; default NULL |
| content_unit_verification_json | Content unit verification json. | longtext | NULL allowed; default NULL |
| created_at | Creation timestamp. | datetime | NOT NULL; default current_timestamp() |
| corrected_at | Corrected at. | datetime | NULL allowed; default NULL |

Indexes and constraints:

- `PRIMARY`: UNIQUE (id).
- `assignment_id`: UNIQUE (assignment_id).
- CHECK `ck_eval_rating_range`: ``relevance` between 1 and 5 and `factual_consistency` between 1 and 5 and `coverage` between 1 and 5 and `coherence` between 1 and 5 and `readability` between 1 and 5 and `conciseness` between 1 and 5 and `non_redundancy` between 1 and 5 and (`overall_usefulness` is null or `overall_usefulness` between 1 and 5)`.

The table supports human criterion ratings for an assignment. Its constraints describe enforced storage behavior; runtime verification is reported separately.


Table 21 records the observed columns and constraints for evaluation_ratings. The descriptions explain their storage purpose; runtime behavior remains subject to the separately stated verification limits.

##### DD-16. evaluation_references

Versioned reference summaries and review status.

Table 22. Data dictionary for evaluation_references.

| Field Name | Description | Data Type/Length | Constraints |
|---|---|---|---|
| id | Unique row identifier. | bigint(20) unsigned | NOT NULL; PRIMARY KEY; auto_increment; default SQL NULL / no explicit non-null default |
| document_id | Related document identifier in this table’s domain. | bigint(20) unsigned | NOT NULL; default SQL NULL / no explicit non-null default; FK → evaluation_documents.id; DELETE RESTRICT; UPDATE RESTRICT |
| reference_text | Reference text. | longtext | NOT NULL; default SQL NULL / no explicit non-null default |
| reference_version | Reference version. | varchar(100) | NOT NULL; default SQL NULL / no explicit non-null default |
| author_code | Author code. | varchar(100) | NULL allowed; default NULL |
| review_status | Reference-review lifecycle status. | varchar(20) | NOT NULL; default 'draft' |
| created_by | Created by. | int(11) | NULL allowed; default NULL; FK → users.id; DELETE SET NULL; UPDATE RESTRICT |
| created_at | Creation timestamp. | datetime | NOT NULL; default current_timestamp() |

Indexes and constraints:

- `PRIMARY`: UNIQUE (id).
- `fk_eval_reference_user`: nonunique (created_by).
- `uq_eval_reference`: UNIQUE (document_id, reference_version).

The table supports versioned reference summaries and review status. Its constraints describe enforced storage behavior; runtime verification is reported separately.


Table 22 records the observed columns and constraints for evaluation_references. The descriptions explain their storage purpose; runtime behavior remains subject to the separately stated verification limits.

##### DD-17. evaluation_runs

Frozen configuration and dataset snapshots for comparison runs.

Table 23. Data dictionary for evaluation_runs.

| Field Name | Description | Data Type/Length | Constraints |
|---|---|---|---|
| id | Unique row identifier. | bigint(20) unsigned | NOT NULL; PRIMARY KEY; auto_increment; default SQL NULL / no explicit non-null default |
| run_uuid | Run uuid. | char(32) | NOT NULL; default SQL NULL / no explicit non-null default |
| name | Name. | varchar(255) | NOT NULL; default SQL NULL / no explicit non-null default |
| dataset_id | Dataset id. | bigint(20) unsigned | NOT NULL; default SQL NULL / no explicit non-null default; FK → evaluation_datasets.id; DELETE RESTRICT; UPDATE RESTRICT |
| dataset_version | Dataset version. | varchar(100) | NOT NULL; default SQL NULL / no explicit non-null default |
| dataset_split | Dataset split. | varchar(20) | NOT NULL; default SQL NULL / no explicit non-null default |
| summarizer_version | Summarizer version. | varchar(100) | NOT NULL; default 'pending-worker' |
| evaluation_version | Evaluation version. | varchar(30) | NOT NULL; default '1.0.0' |
| configuration_hash | Hash of canonical run configuration. | char(64) | NOT NULL; default SQL NULL / no explicit non-null default |
| configuration_json | Configuration json. | longtext | NOT NULL; default SQL NULL / no explicit non-null default |
| dataset_snapshot_json | Frozen dataset content and reference snapshot. | longtext | NOT NULL; default SQL NULL / no explicit non-null default |
| snapshot_hash | Snapshot hash. | char(64) | NOT NULL; default SQL NULL / no explicit non-null default |
| provenance_json | Serialized execution/source provenance. | longtext | NULL allowed; default NULL |
| report_json | Report json. | longtext | NULL allowed; default NULL |
| report_pending | Report pending. | tinyint(1) | NOT NULL; default 1 |
| report_error | Report error. | text | NULL allowed; default NULL |
| status | Domain status marker. | varchar(30) | NOT NULL; default 'queued' |
| documents_total | Documents total. | int(10) unsigned | NOT NULL; default SQL NULL / no explicit non-null default |
| documents_processed | Documents processed. | int(10) unsigned | NOT NULL; default 0 |
| outputs_total | Outputs total. | int(10) unsigned | NOT NULL; default SQL NULL / no explicit non-null default |
| generated_summaries | Generated summaries. | int(10) unsigned | NOT NULL; default 0 |
| failed_summaries | Failed summaries. | int(10) unsigned | NOT NULL; default 0 |
| created_by | Created by. | int(11) | NULL allowed; default NULL; FK → users.id; DELETE SET NULL; UPDATE RESTRICT |
| notes | Notes. | text | NULL allowed; default NULL |
| error_message | Error message. | text | NULL allowed; default NULL |
| started_at | Started at. | datetime | NULL allowed; default NULL |
| completed_at | Completed at. | datetime | NULL allowed; default NULL |
| heartbeat_at | Heartbeat at. | datetime | NULL allowed; default NULL |
| created_at | Creation timestamp. | datetime | NOT NULL; default current_timestamp() |

Indexes and constraints:

- `PRIMARY`: UNIQUE (id).
- `fk_eval_run_dataset`: nonunique (dataset_id).
- `fk_eval_run_user`: nonunique (created_by).
- `idx_eval_run_status`: nonunique (status, created_at).
- `run_uuid`: UNIQUE (run_uuid).

The table supports frozen configuration and dataset snapshots for comparison runs. Its constraints describe enforced storage behavior; runtime verification is reported separately.


Table 23 records the observed columns and constraints for evaluation_runs. The descriptions explain their storage purpose; runtime behavior remains subject to the separately stated verification limits.

##### DD-18. feedback

Latest owner-associated rating, reasons and optional legacy identifying fields.

Table 24. Data dictionary for feedback.

| Field Name | Description | Data Type/Length | Constraints |
|---|---|---|---|
| id | Unique row identifier. | int(11) | NOT NULL; PRIMARY KEY; auto_increment; default SQL NULL / no explicit non-null default |
| summary_id | Related summary identifier. | int(11) | NOT NULL; default SQL NULL / no explicit non-null default; FK → summaries.id; DELETE CASCADE; UPDATE RESTRICT |
| user_id | Registered account identifier; an enforced relation only where listed below. | int(11) | NULL allowed; default NULL; FK → users.id; DELETE CASCADE; UPDATE RESTRICT |
| guest_token | Anonymous owner/session token; not a foreign key unless explicitly listed. | varchar(64) | NULL allowed; default NULL |
| rating | Numeric feedback rating. | tinyint(4) | NOT NULL; default SQL NULL / no explicit non-null default |
| name | Name. | varchar(100) | NULL allowed; default NULL |
| email | Email. | varchar(150) | NULL allowed; default NULL |
| impression | Impression. | varchar(50) | NULL allowed; default NULL |
| reasons | Serialized selected feedback reason codes. | longtext | NULL allowed; default NULL |
| comment | Free-text feedback comment. | text | NULL allowed; default NULL |
| created_at | Creation timestamp. | timestamp | NOT NULL; default current_timestamp() |
| updated_at | Most recent update timestamp. | timestamp | NOT NULL; on update current_timestamp(); default current_timestamp() |

Indexes and constraints:

- `PRIMARY`: UNIQUE (id).
- `idx_guest_owner`: nonunique (guest_token, user_id).
- `idx_summary`: nonunique (summary_id).
- `uq_guest_summary`: UNIQUE (summary_id, guest_token).
- `uq_user_summary`: UNIQUE (summary_id, user_id).
- `user_id`: nonunique (user_id).

The table supports latest owner-associated rating, reasons and optional legacy identifying fields. Its constraints describe enforced storage behavior; runtime verification is reported separately.


Table 24 records the observed columns and constraints for feedback. The descriptions explain their storage purpose; runtime behavior remains subject to the separately stated verification limits.

##### DD-19. feedback_comments

Feedback comment/reason event ledger without direct user/guest columns.

Table 25. Data dictionary for feedback_comments.

| Field Name | Description | Data Type/Length | Constraints |
|---|---|---|---|
| id | Unique row identifier. | int(11) | NOT NULL; PRIMARY KEY; auto_increment; default SQL NULL / no explicit non-null default |
| summary_id | Related summary identifier. | int(11) | NOT NULL; default SQL NULL / no explicit non-null default; FK → summaries.id; DELETE CASCADE; UPDATE RESTRICT |
| rating | Numeric feedback rating. | tinyint(4) | NULL allowed; default NULL |
| reasons | Serialized selected feedback reason codes. | longtext | NULL allowed; default NULL |
| comment | Free-text feedback comment. | text | NOT NULL; default SQL NULL / no explicit non-null default |
| created_at | Creation timestamp. | timestamp | NOT NULL; default current_timestamp() |

Indexes and constraints:

- `PRIMARY`: UNIQUE (id).
- `idx_fc_created`: nonunique (created_at).
- `idx_fc_summary`: nonunique (summary_id).

The table supports feedback comment/reason event ledger without direct user/guest columns. Its constraints describe enforced storage behavior; runtime verification is reported separately.


Table 25 records the observed columns and constraints for feedback_comments. The descriptions explain their storage purpose; runtime behavior remains subject to the separately stated verification limits.

##### DD-20. guest_sessions

Anonymous session identity and inactivity expiry.

Table 26. Data dictionary for guest_sessions.

| Field Name | Description | Data Type/Length | Constraints |
|---|---|---|---|
| guest_token | Anonymous owner/session token; not a foreign key unless explicitly listed. | varchar(64) | NOT NULL; PRIMARY KEY; default SQL NULL / no explicit non-null default |
| created_at | Creation timestamp. | datetime | NOT NULL; default current_timestamp() |
| last_activity | Last activity. | datetime | NOT NULL; default current_timestamp() |
| expires_at | Expires at. | datetime | NOT NULL; default SQL NULL / no explicit non-null default |

Indexes and constraints:

- `PRIMARY`: UNIQUE (guest_token).
- `idx_guest_sessions_activity`: nonunique (last_activity).
- `idx_guest_sessions_expires`: nonunique (expires_at).

The table supports anonymous session identity and inactivity expiry. Its constraints describe enforced storage behavior; runtime verification is reported separately.


Table 26 records the observed columns and constraints for guest_sessions. The descriptions explain their storage purpose; runtime behavior remains subject to the separately stated verification limits.

##### DD-21. landing_page_events

Individual landing-page events associated with a session.

Table 27. Data dictionary for landing_page_events.

| Field Name | Description | Data Type/Length | Constraints |
|---|---|---|---|
| id | Unique row identifier. | bigint(20) unsigned | NOT NULL; PRIMARY KEY; auto_increment; default SQL NULL / no explicit non-null default |
| session_id | Session id. | varchar(64) | NOT NULL; default SQL NULL / no explicit non-null default; FK → landing_page_sessions.session_id; DELETE CASCADE; UPDATE RESTRICT |
| event_type | Event type. | varchar(50) | NOT NULL; default SQL NULL / no explicit non-null default |
| event_label | Event label. | varchar(100) | NULL allowed; default NULL |
| event_value | Event value. | int(11) | NULL allowed; default NULL |
| created_at | Creation timestamp. | timestamp | NOT NULL; default current_timestamp() |

Indexes and constraints:

- `PRIMARY`: UNIQUE (id).
- `idx_lpe_created`: nonunique (created_at).
- `idx_lpe_session`: nonunique (session_id, event_type).

The table supports individual landing-page events associated with a session. Its constraints describe enforced storage behavior; runtime verification is reported separately.


Table 27 records the observed columns and constraints for landing_page_events. The descriptions explain their storage purpose; runtime behavior remains subject to the separately stated verification limits.

##### DD-22. landing_page_sessions

Aggregated visitor engagement, device and web-vital measurements.

Table 28. Data dictionary for landing_page_sessions.

| Field Name | Description | Data Type/Length | Constraints |
|---|---|---|---|
| session_id | Session id. | varchar(64) | NOT NULL; PRIMARY KEY; default SQL NULL / no explicit non-null default |
| user_id | Registered account identifier; an enforced relation only where listed below. | int(11) | NULL allowed; default NULL |
| guest_token | Anonymous owner/session token; not a foreign key unless explicitly listed. | varchar(64) | NULL allowed; default NULL |
| ip_hash | Ip hash. | char(64) | NOT NULL; default SQL NULL / no explicit non-null default |
| user_agent | User agent. | varchar(255) | NULL allowed; default NULL |
| device_category | Device category. | enum('mobile','tablet','desktop') | NOT NULL; default 'desktop' |
| referrer | Referrer. | varchar(512) | NULL allowed; default NULL |
| lcp_ms | Lcp ms. | int(11) | NULL allowed; default NULL |
| fid_ms | Fid ms. | int(11) | NULL allowed; default NULL |
| inp_ms | Inp ms. | int(11) | NULL allowed; default NULL |
| cls_score | Cls score. | decimal(6,4) | NULL allowed; default NULL |
| max_scroll_percent | Max scroll percent. | tinyint(3) unsigned | NOT NULL; default 0 |
| dwell_time_seconds | Dwell time seconds. | smallint(5) unsigned | NOT NULL; default 0 |
| converted | Converted. | tinyint(1) | NOT NULL; default 0 |
| created_at | Creation timestamp. | timestamp | NOT NULL; default current_timestamp() |
| updated_at | Most recent update timestamp. | timestamp | NOT NULL; on update current_timestamp(); default current_timestamp() |

Indexes and constraints:

- `PRIMARY`: UNIQUE (session_id).
- `idx_lps_converted`: nonunique (converted).
- `idx_lps_created`: nonunique (created_at).
- `idx_lps_device`: nonunique (device_category).

The table supports aggregated visitor engagement, device and web-vital measurements. Its constraints describe enforced storage behavior; runtime verification is reported separately.


Table 28 records the observed columns and constraints for landing_page_sessions. The descriptions explain their storage purpose; runtime behavior remains subject to the separately stated verification limits.

##### DD-23. nutshell_generations

Completed/failed short-summary generation ledger and generation metrics.

Table 29. Data dictionary for nutshell_generations.

| Field Name | Description | Data Type/Length | Constraints |
|---|---|---|---|
| id | Unique row identifier. | bigint(20) unsigned | NOT NULL; PRIMARY KEY; auto_increment; default SQL NULL / no explicit non-null default |
| summary_id | Related summary identifier. | int(11) | NULL allowed; default NULL; FK → summaries.id; DELETE SET NULL; UPDATE RESTRICT |
| user_id | Registered account identifier; an enforced relation only where listed below. | int(11) | NULL allowed; default NULL |
| guest_token | Anonymous owner/session token; not a foreign key unless explicitly listed. | varchar(64) | NULL allowed; default NULL |
| input_type | Input type. | varchar(20) | NOT NULL; default 'text' |
| nutshell_text | Nutshell text. | text | NULL allowed; default NULL |
| status | Domain status marker. | enum('completed','failed') | NOT NULL; default 'completed' |
| failure_reason | Failure reason. | varchar(80) | NULL allowed; default NULL |
| analysis_mode | Analysis mode. | varchar(24) | NOT NULL; default 'general' |
| output_format | Output format. | varchar(24) | NOT NULL; default 'paragraph' |
| word_count | Word count. | int(10) unsigned | NOT NULL; default 0 |
| source_word_count | Source word count. | int(10) unsigned | NOT NULL; default 0 |
| primary_summary_word_count | Primary summary word count. | int(10) unsigned | NOT NULL; default 0 |
| compression_ratio | Module-specific compression metric; check service semantics before comparisons. | decimal(8,4) | NOT NULL; default 0.0000 |
| processing_time_ms | Processing time ms. | int(10) unsigned | NOT NULL; default 0 |
| algorithm_version | Algorithm version. | varchar(32) | NOT NULL; default 'nutshell-extractive-v2' |
| idempotency_key | Idempotency key. | char(64) | NULL allowed; default NULL |
| created_at | Creation timestamp. | timestamp | NOT NULL; default current_timestamp() |

Indexes and constraints:

- `PRIMARY`: UNIQUE (id).
- `idx_ng_created`: nonunique (created_at).
- `idx_ng_status_created`: nonunique (status, created_at).
- `idx_ng_summary`: nonunique (summary_id).
- `idx_ng_user_created`: nonunique (user_id, created_at).
- `uq_ng_idempotency_key`: UNIQUE (idempotency_key).

The table supports completed/failed short-summary generation ledger and generation metrics. Its constraints describe enforced storage behavior; runtime verification is reported separately.


Table 29 records the observed columns and constraints for nutshell_generations. The descriptions explain their storage purpose; runtime behavior remains subject to the separately stated verification limits.

##### DD-24. password_resets

Hashed recovery codes and expiry/consumption state.

Table 30. Data dictionary for password_resets.

| Field Name | Description | Data Type/Length | Constraints |
|---|---|---|---|
| id | Unique row identifier. | int(11) | NOT NULL; PRIMARY KEY; auto_increment; default SQL NULL / no explicit non-null default |
| user_id | Registered account identifier; an enforced relation only where listed below. | int(11) | NOT NULL; default SQL NULL / no explicit non-null default; FK → users.id; DELETE CASCADE; UPDATE RESTRICT |
| token_hash | One-way recovery-code verifier. | varchar(64) | NOT NULL; default SQL NULL / no explicit non-null default |
| expires_at | Expires at. | datetime | NOT NULL; default SQL NULL / no explicit non-null default |
| used_at | Used at. | datetime | NULL allowed; default NULL |

Indexes and constraints:

- `PRIMARY`: UNIQUE (id).
- `idx_token`: nonunique (token_hash).
- `user_id`: nonunique (user_id).

The table supports hashed recovery codes and expiry/consumption state. Its constraints describe enforced storage behavior; runtime verification is reported separately.


Table 30 records the observed columns and constraints for password_resets. The descriptions explain their storage purpose; runtime behavior remains subject to the separately stated verification limits.

##### DD-25. rate_limits

Per-action identity counters and time windows.

Table 31. Data dictionary for rate_limits.

| Field Name | Description | Data Type/Length | Constraints |
|---|---|---|---|
| id | Unique row identifier. | int(11) | NOT NULL; PRIMARY KEY; auto_increment; default SQL NULL / no explicit non-null default |
| action_type | Action type. | varchar(30) | NOT NULL; default SQL NULL / no explicit non-null default |
| identifier | Identifier. | varchar(255) | NOT NULL; default SQL NULL / no explicit non-null default |
| attempts | Attempts. | int(11) | NULL allowed; default 0 |
| window_start | Window start. | timestamp | NOT NULL; default current_timestamp() |

Indexes and constraints:

- `PRIMARY`: UNIQUE (id).
- `idx_window`: nonunique (window_start).
- `uq_action_id`: UNIQUE (action_type, identifier).

The table supports per-action identity counters and time windows. Its constraints describe enforced storage behavior; runtime verification is reported separately.


Table 31 records the observed columns and constraints for rate_limits. The descriptions explain their storage purpose; runtime behavior remains subject to the separately stated verification limits.

##### DD-26. schema_migrations

Migration application bookkeeping.

Table 32. Data dictionary for schema_migrations.

| Field Name | Description | Data Type/Length | Constraints |
|---|---|---|---|
| id | Unique row identifier. | int(11) | NOT NULL; PRIMARY KEY; auto_increment; default SQL NULL / no explicit non-null default |
| migration | Migration. | varchar(255) | NOT NULL; default SQL NULL / no explicit non-null default |
| applied_at | Applied at. | timestamp | NOT NULL; default current_timestamp() |

Indexes and constraints:

- `PRIMARY`: UNIQUE (id).
- `migration`: UNIQUE (migration).

The table supports migration application bookkeeping. Its constraints describe enforced storage behavior; runtime verification is reported separately.


Table 32 records the observed columns and constraints for schema_migrations. The descriptions explain their storage purpose; runtime behavior remains subject to the separately stated verification limits.

##### DD-27. summaries

Summary ownership, sharing capability, metadata, aggregate metrics and cached Nutshell.

Table 33. Data dictionary for summaries.

| Field Name | Description | Data Type/Length | Constraints |
|---|---|---|---|
| id | Unique row identifier. | int(11) | NOT NULL; PRIMARY KEY; auto_increment; default SQL NULL / no explicit non-null default |
| user_id | Registered account identifier; an enforced relation only where listed below. | int(11) | NULL allowed; default NULL; FK → users.id; DELETE CASCADE; UPDATE RESTRICT |
| guest_token | Anonymous owner/session token; not a foreign key unless explicitly listed. | varchar(64) | NULL allowed; default NULL |
| share_token | Random capability token used by shared-summary links. | varchar(64) | NOT NULL; default SQL NULL / no explicit non-null default |
| article_title | Article title. | varchar(255) | NOT NULL; default SQL NULL / no explicit non-null default |
| author_name | Author name. | varchar(255) | NULL allowed; default NULL |
| input_type | Input type. | enum('text','pdf','docx','url') | NOT NULL; default SQL NULL / no explicit non-null default |
| summary_style | Summary style. | enum('standard_paragraph','bullet_points','hybrid','academic_summary','simple_summary','executive_summary') | NULL allowed; default 'standard_paragraph' |
| summary_length | Summary length. | varchar(32) | NULL allowed; default NULL |
| original_word_count | Source word count; main file path currently counts the empty input string. | int(10) unsigned | NULL allowed; default NULL |
| summary_word_count | Generated summary word count. | int(10) unsigned | NULL allowed; default NULL |
| processing_time | Elapsed summary processing seconds; current main caller does not supply this value. | decimal(10,3) | NULL allowed; default NULL |
| article_category | Article category. | varchar(100) | NULL allowed; default NULL |
| status | Domain status marker. | enum('pending','processing','completed','failed') | NOT NULL; default 'completed' |
| error_message | Error message. | text | NULL allowed; default NULL |
| created_at | Creation timestamp. | timestamp | NOT NULL; default current_timestamp() |
| nutshell_text | Nutshell text. | text | NULL allowed; default NULL |
| nutshell_word_count | Nutshell word count. | int(10) unsigned | NULL allowed; default NULL |
| nutshell_generated_at | Nutshell generated at. | timestamp | NULL allowed; default NULL |

Indexes and constraints:

- `PRIMARY`: UNIQUE (id).
- `idx_created_owner`: nonunique (created_at, user_id).
- `idx_guest`: nonunique (guest_token).
- `idx_guest_owner`: nonunique (guest_token, user_id).
- `idx_status`: nonunique (status).
- `idx_summaries_analytics_user_created`: nonunique (user_id, created_at).
- `idx_summaries_created_at`: nonunique (created_at).
- `idx_summaries_guest_token`: nonunique (guest_token).
- `idx_summaries_nutshell_generated`: nonunique (nutshell_generated_at).
- `idx_summaries_status_created`: nonunique (status, created_at).
- `idx_summaries_user_id`: nonunique (user_id).
- `idx_user`: nonunique (user_id).
- `uq_share_token`: UNIQUE (share_token).

The table supports summary ownership, sharing capability, metadata, aggregate metrics and cached nutshell. Its constraints describe enforced storage behavior; runtime verification is reported separately.


Table 33 records the observed columns and constraints for summaries. The descriptions explain their storage purpose; runtime behavior remains subject to the separately stated verification limits.

##### DD-28. summary_artifacts

Source text and structured generated output stored apart from summary metadata.

Table 34. Data dictionary for summary_artifacts.

| Field Name | Description | Data Type/Length | Constraints |
|---|---|---|---|
| id | Unique row identifier. | int(11) | NOT NULL; PRIMARY KEY; auto_increment; default SQL NULL / no explicit non-null default |
| summary_id | Related summary identifier. | int(11) | NOT NULL; default SQL NULL / no explicit non-null default; FK → summaries.id; DELETE CASCADE; UPDATE RESTRICT |
| original_text | Persisted original/source text (main summary service applies pattern masking). | longtext | NOT NULL; default SQL NULL / no explicit non-null default |
| generated_summary | Structured summary JSON/text payload. | longtext | NOT NULL; default SQL NULL / no explicit non-null default |

Indexes and constraints:

- `PRIMARY`: UNIQUE (id).
- `fk_summary_artifacts_summary_id`: nonunique (summary_id).

The table supports source text and structured generated output stored apart from summary metadata. Its constraints describe enforced storage behavior; runtime verification is reported separately.


Table 34 records the observed columns and constraints for summary_artifacts. The descriptions explain their storage purpose; runtime behavior remains subject to the separately stated verification limits.

##### DD-29. summary_jobs

Durable background work state, owner, payload, retry and result references.

Table 35. Data dictionary for summary_jobs.

| Field Name | Description | Data Type/Length | Constraints |
|---|---|---|---|
| id | Unique row identifier. | bigint(20) unsigned | NOT NULL; PRIMARY KEY; auto_increment; default SQL NULL / no explicit non-null default |
| job_id | Job id. | varchar(64) | NOT NULL; default SQL NULL / no explicit non-null default |
| user_id | Registered account identifier; an enforced relation only where listed below. | int(11) | NULL allowed; default NULL; FK → users.id; DELETE SET NULL; UPDATE RESTRICT |
| guest_token | Anonymous owner/session token; not a foreign key unless explicitly listed. | varchar(64) | NULL allowed; default NULL |
| state | Current durable work state. | enum('queued','processing','completed','failed','cancelled','timed_out') | NOT NULL; default 'queued' |
| payload | Job input JSON retained according to processing/retry lifecycle. | longtext | NOT NULL; default SQL NULL / no explicit non-null default |
| idempotency_key | Idempotency key. | varchar(128) | NOT NULL; default SQL NULL / no explicit non-null default |
| result_id | Result id. | int(11) | NULL allowed; default NULL; FK → summaries.id; DELETE SET NULL; UPDATE RESTRICT |
| error_message | Error message. | text | NULL allowed; default NULL |
| error_details | Error details. | text | NULL allowed; default NULL |
| worker_pid | Worker pid. | int(11) | NULL allowed; default NULL |
| retry_count | Retry count. | tinyint(3) unsigned | NOT NULL; default 0 |
| started_at | Started at. | datetime | NULL allowed; default NULL |
| completed_at | Completed at. | datetime | NULL allowed; default NULL |
| created_at | Creation timestamp. | timestamp | NOT NULL; default current_timestamp() |
| updated_at | Most recent update timestamp. | timestamp | NOT NULL; on update current_timestamp(); default current_timestamp() |

Indexes and constraints:

- `PRIMARY`: UNIQUE (id).
- `idx_summary_jobs_created`: nonunique (created_at).
- `idx_summary_jobs_guest`: nonunique (guest_token).
- `idx_summary_jobs_result`: nonunique (result_id).
- `idx_summary_jobs_state`: nonunique (state).
- `idx_summary_jobs_user`: nonunique (user_id).
- `uq_summary_jobs_idempotency`: UNIQUE (idempotency_key).
- `uq_summary_jobs_job_id`: UNIQUE (job_id).

The table supports durable background work state, owner, payload, retry and result references. Its constraints describe enforced storage behavior; runtime verification is reported separately.


Table 35 records the observed columns and constraints for summary_jobs. The descriptions explain their storage purpose; runtime behavior remains subject to the separately stated verification limits.

##### DD-30. users

Registered accounts, role, active status, password hash and acceptance state.

Table 36. Data dictionary for users.

| Field Name | Description | Data Type/Length | Constraints |
|---|---|---|---|
| id | Unique row identifier. | int(11) | NOT NULL; PRIMARY KEY; auto_increment; default SQL NULL / no explicit non-null default |
| username | Username. | varchar(50) | NOT NULL; default SQL NULL / no explicit non-null default |
| email | Email. | varchar(100) | NOT NULL; default SQL NULL / no explicit non-null default |
| password_hash | One-way password verifier, not a readable password. | varchar(255) | NOT NULL; default SQL NULL / no explicit non-null default |
| role | Account access category. | enum('user','admin') | NULL allowed; default 'user' |
| active | Enabled/active state. | tinyint(1) | NULL allowed; default 1 |
| terms_accepted | Whether the account accepted terms. | tinyint(1) | NOT NULL; default 0 |
| terms_accepted_at | Recorded acceptance time. | datetime | NULL allowed; default NULL |
| created_at | Creation timestamp. | timestamp | NOT NULL; default current_timestamp() |

Indexes and constraints:

- `PRIMARY`: UNIQUE (id).
- `email`: UNIQUE (email).
- `idx_role`: nonunique (role).
- `username`: UNIQUE (username).

The table supports registered accounts, role, active status, password hash and acceptance state. Its constraints describe enforced storage behavior; runtime verification is reported separately.


Table 36 records the observed columns and constraints for users. The descriptions explain their storage purpose; runtime behavior remains subject to the separately stated verification limits.

##### DD-31. user_tokens

Persistent sign-in selectors and hashed validators.

Table 37. Data dictionary for user_tokens.

| Field Name | Description | Data Type/Length | Constraints |
|---|---|---|---|
| id | Unique row identifier. | int(11) | NOT NULL; PRIMARY KEY; auto_increment; default SQL NULL / no explicit non-null default |
| user_id | Registered account identifier; an enforced relation only where listed below. | int(11) | NOT NULL; default SQL NULL / no explicit non-null default; FK → users.id; DELETE CASCADE; UPDATE RESTRICT |
| selector | Public lookup part of a persistent sign-in token. | varchar(255) | NOT NULL; default SQL NULL / no explicit non-null default |
| hashed_validator | Hash of persistent sign-in secret. | varchar(255) | NOT NULL; default SQL NULL / no explicit non-null default |
| expires | Expires. | datetime | NOT NULL; default SQL NULL / no explicit non-null default |

Indexes and constraints:

- `PRIMARY`: UNIQUE (id).
- `selector_idx`: UNIQUE (selector).
- `user_id`: nonunique (user_id).

The table supports persistent sign-in selectors and hashed validators. Its constraints describe enforced storage behavior; runtime verification is reported separately.

Table 37 records the observed columns and constraints for user_tokens. The descriptions explain their storage purpose; runtime behavior remains subject to the separately stated verification limits.
