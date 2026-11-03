-- Retire assigned summary reviews. Keep automated evaluation runs and metrics.
-- Drop the dependent ratings table before its assignment table.
DROP TABLE IF EXISTS evaluation_ratings;
DROP TABLE IF EXISTS evaluation_assignments;

-- Cached reports used the retired review scores. Rebuild from remaining metrics.
UPDATE evaluation_runs
SET report_json = NULL, report_error = NULL, report_pending = 1;
