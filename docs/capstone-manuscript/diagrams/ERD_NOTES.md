# ERD evidence and interpretation

These diagrams are generated from live-schema.json. Only actual foreign keys appear as edges. Nullable foreign keys allow zero or one parent; non-null keys require a parent. A parent permits zero or many child rows unless the child foreign-key column is independently unique. Primary/foreign-key columns are shown; the full dictionary supplies all attributes.

Figure interpretation: the core ERD illustrates registered accounts, persistent tokens, summaries, artifacts, feedback, jobs and document caches. The evaluation ERD illustrates datasets, source documents, runs, outputs, metrics, reviewer assignments, ratings, and audit records. The combined source contains every observed relationship.

Important boundaries: guest_token is not an enforced relationship to guest_sessions. documents.user_id, nutshell_generations.user_id and landing_page_sessions.user_id do not have foreign keys. summary_artifacts.summary_id is not unique, so the database allows multiple artifacts per summary even though the normal writer inserts one. Evaluation assignment/ratings have real uniqueness constraints. No historical normalization sequence is claimed.
