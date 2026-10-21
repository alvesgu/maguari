-- Public web apps the server requests on an interval, per instance (design
-- section 6.2.1). The URL is the results' subject. next_due_at is when the
-- next run of maguari-server run-http-checks checks it. instance_id is
-- Fleet's instance ID, with no foreign key (design section 2.1).
CREATE TABLE monitoring_http_checks (
    id INTEGER PRIMARY KEY,
    instance_id INTEGER NOT NULL,
    url TEXT NOT NULL,
    interval_seconds INTEGER NOT NULL,
    added_at INTEGER NOT NULL,
    next_due_at INTEGER NOT NULL,
    UNIQUE (instance_id, url)
);

CREATE INDEX monitoring_http_checks_due ON monitoring_http_checks (next_due_at);

-- The latest result's time for each check outcome, so pages can show when a
-- check last ran (design section 9.2). NULL for outcomes stored before it.
ALTER TABLE monitoring_check_states ADD COLUMN checked_at INTEGER;
