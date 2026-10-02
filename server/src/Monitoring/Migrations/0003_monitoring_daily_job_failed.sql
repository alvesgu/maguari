-- A run that failed with an error the job could catch is finished and marked
-- failed (the error goes to the log). A run that is unfinished was killed:
-- only those are covered by the 15 minute rule (design section 6.3).
ALTER TABLE monitoring_daily_job_runs ADD COLUMN failed INTEGER NOT NULL DEFAULT 0;
