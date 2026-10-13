-- Runs of Notifications' daily reconciliation with Remediation's open
-- incidents (design section 10.4), with how many notifications each created
-- and resolved.
CREATE TABLE notifications_reconciliations (
    id INTEGER PRIMARY KEY,
    ran_at INTEGER NOT NULL,
    created INTEGER NOT NULL,
    resolved INTEGER NOT NULL
);
