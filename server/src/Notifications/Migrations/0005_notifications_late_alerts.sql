-- An incident still open whose "opened" email was never sent gets it late
-- (design section 13.3 item 5), so a notification can have a given-up
-- "opened" alert and a later one. At most one alert per notification and
-- kind that is not given up, instead of one per notification and kind.
-- late marks an email that says the incident was detected late. SQLite
-- cannot drop a UNIQUE constraint, so the table is rebuilt.
CREATE TABLE notifications_alerts_new (
    id INTEGER PRIMARY KEY,
    notification_id INTEGER NOT NULL REFERENCES notifications_notifications (id),
    kind TEXT NOT NULL CHECK (kind IN ('opened', 'resolved')),
    created_at INTEGER NOT NULL,
    sent_at INTEGER,
    given_up_at INTEGER,
    attempts INTEGER NOT NULL DEFAULT 0,
    last_failure TEXT,
    summary_id INTEGER REFERENCES notifications_alert_summaries (id),
    late INTEGER NOT NULL DEFAULT 0
);

INSERT INTO notifications_alerts_new (id, notification_id, kind, created_at, sent_at, given_up_at, attempts, last_failure, summary_id)
    SELECT id, notification_id, kind, created_at, sent_at, given_up_at, attempts, last_failure, summary_id FROM notifications_alerts;

DROP TABLE notifications_alerts;

ALTER TABLE notifications_alerts_new RENAME TO notifications_alerts;

CREATE UNIQUE INDEX notifications_alerts_current ON notifications_alerts (notification_id, kind) WHERE given_up_at IS NULL;

CREATE INDEX notifications_alerts_pending ON notifications_alerts (sent_at, given_up_at, id);

-- How many "opened" emails each reconciliation queued again, late.
ALTER TABLE notifications_reconciliations ADD COLUMN late_alerts INTEGER NOT NULL DEFAULT 0;
