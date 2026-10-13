-- Notifications on the dashboard and the Logs screen (design section 10.4):
-- one per incident, from Remediation's events. incident_id and instance_id
-- are Remediation's and Fleet's IDs, with no foreign key (design section
-- 2.1). resolved_at is NULL while the incident is open. Only facts are
-- stored; the text is built when a page is shown.
CREATE TABLE notifications_notifications (
    id INTEGER PRIMARY KEY,
    incident_id INTEGER NOT NULL UNIQUE,
    instance_id INTEGER NOT NULL,
    check_name TEXT NOT NULL,
    subject TEXT NOT NULL,
    opened_at INTEGER NOT NULL,
    resolved_at INTEGER
);

-- Serves the dashboard's unresolved notifications.
CREATE INDEX notifications_notifications_unresolved ON notifications_notifications (resolved_at, id);

-- Which administrator hid which notification. Hiding is per administrator:
-- one hiding it never hides it from the others. administrator_id is
-- Access's ID, with no foreign key.
CREATE TABLE notifications_hidden (
    notification_id INTEGER NOT NULL REFERENCES notifications_notifications (id),
    administrator_id INTEGER NOT NULL,
    hidden_at INTEGER NOT NULL,
    PRIMARY KEY (notification_id, administrator_id)
);
