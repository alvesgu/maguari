-- Readings stored as runs (design section 9.1): one row per stretch of
-- consecutive equal readings of one metric on one instance. metric is
-- "<kind>:<subject>", as in the heartbeat (for example "disk_used_bytes:/").
-- instance_id is fleet_instances.id, without a foreign key (design section
-- 2.1 rules 2 and 3), so re-enrolling an instance keeps its runs. value is
-- NUMERIC so later metrics can be fractional; integers stay integers.
CREATE TABLE monitoring_metric_runs (
    id INTEGER PRIMARY KEY,
    instance_id INTEGER NOT NULL,
    metric TEXT NOT NULL,
    value NUMERIC NOT NULL,
    start_at INTEGER NOT NULL,
    end_at INTEGER NOT NULL
);

-- Finds the current run of a metric and serves time-range reads.
CREATE INDEX monitoring_metric_runs_series ON monitoring_metric_runs (instance_id, metric, start_at);
