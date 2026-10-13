-- What an alert email says that only the events knew: the failed result's
-- sentence when the incident opened (from Monitoring, through Remediation;
-- a fixed sentence when the reconciliation created the notification), and
-- why it resolved (passed, withdrawn or reconciled; NULL while open or when
-- Notifications' own reconciliation resolved it).
ALTER TABLE notifications_notifications ADD COLUMN detail TEXT NOT NULL DEFAULT '';
ALTER TABLE notifications_notifications ADD COLUMN resolution TEXT;

-- One summary email that replaces individual alerts when many instances
-- fail or recover at once (design section 13.3).
CREATE TABLE notifications_alert_summaries (
    id INTEGER PRIMARY KEY,
    kind TEXT NOT NULL CHECK (kind IN ('opened', 'resolved')),
    created_at INTEGER NOT NULL
);

-- Alert emails (design section 13.3): one when a notification's incident
-- opens and one when it resolves. Pending while sent_at and given_up_at are
-- NULL; tried on each tick for up to 60 minutes. last_failure holds
-- Maguari's own sentence, never the server's text. summary_id is set when a
-- summary email replaces this alert.
CREATE TABLE notifications_alerts (
    id INTEGER PRIMARY KEY,
    notification_id INTEGER NOT NULL REFERENCES notifications_notifications (id),
    kind TEXT NOT NULL CHECK (kind IN ('opened', 'resolved')),
    created_at INTEGER NOT NULL,
    sent_at INTEGER,
    given_up_at INTEGER,
    attempts INTEGER NOT NULL DEFAULT 0,
    last_failure TEXT,
    summary_id INTEGER REFERENCES notifications_alert_summaries (id),
    UNIQUE (notification_id, kind)
);

CREATE INDEX notifications_alerts_pending ON notifications_alerts (sent_at, given_up_at, id);
