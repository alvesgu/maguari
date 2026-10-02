-- Runs of the daily job (design section 6.3) and the results of their checks.
-- finished_at is NULL while a run is in progress, or if it crashed.
-- instance_id is Fleet's instance ID, without a foreign key (design section
-- 2.1). detail holds only Maguari's own sentences, never raw API messages.
CREATE TABLE monitoring_daily_job_runs (
    id INTEGER PRIMARY KEY,
    triggered_by TEXT NOT NULL,
    started_at INTEGER NOT NULL,
    finished_at INTEGER
);

CREATE TABLE monitoring_check_results (
    id INTEGER PRIMARY KEY,
    job_run_id INTEGER NOT NULL REFERENCES monitoring_daily_job_runs (id),
    instance_id INTEGER NOT NULL,
    check_name TEXT NOT NULL,
    outcome TEXT NOT NULL,
    detail TEXT NOT NULL,
    checked_at INTEGER NOT NULL
);

CREATE INDEX monitoring_check_results_job_run ON monitoring_check_results (job_run_id);
