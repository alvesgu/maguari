-- Incidents (design section 7.4): one per check of one instance and one
-- subject, opened when the check fails and resolved after it has passed for
-- 5 minutes, when its subject is withdrawn, or by the daily reconciliation.
-- instance_id is Fleet's instance ID, with no foreign key (design section
-- 2.1). passing_since is when the check started passing while open.
CREATE TABLE remediation_incidents (
    id INTEGER PRIMARY KEY,
    instance_id INTEGER NOT NULL,
    check_name TEXT NOT NULL,
    subject TEXT NOT NULL,
    opened_at INTEGER NOT NULL,
    passing_since INTEGER,
    resolved_at INTEGER,
    resolution TEXT CHECK (resolution IN ('passed', 'withdrawn', 'reconciled')),
    CHECK ((resolved_at IS NULL) = (resolution IS NULL))
);

-- At most one open incident per instance, check and subject.
CREATE UNIQUE INDEX remediation_incidents_open ON remediation_incidents (instance_id, check_name, subject)
    WHERE resolved_at IS NULL;

-- Serves an instance's incidents, open and recently resolved.
CREATE INDEX remediation_incidents_instance ON remediation_incidents (instance_id, resolved_at);

-- Runs of the daily reconciliation with Monitoring's outcomes (design
-- section 7.4), with how many incidents each opened and resolved.
CREATE TABLE remediation_reconciliations (
    id INTEGER PRIMARY KEY,
    ran_at INTEGER NOT NULL,
    opened INTEGER NOT NULL,
    resolved INTEGER NOT NULL
);
