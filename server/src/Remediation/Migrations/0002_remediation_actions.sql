-- Each instance's remediation mode (design section 7.2 item 8). No row means
-- alert-only, so Remediation needs no event when an instance is picked.
-- maintenance_until is kept after it passes: it is when the last maintenance
-- ended, and the wait before acting starts over then. instance_id is Fleet's
-- instance ID, with no foreign key (design section 2.1).
CREATE TABLE remediation_instances (
    instance_id INTEGER PRIMARY KEY,
    mode TEXT NOT NULL CHECK (mode IN ('automatic', 'alert_only')),
    mode_changed_at INTEGER NOT NULL,
    maintenance_until INTEGER
);

-- What the ladder decided (design section 7.1). unit is set only for a
-- restart. A dry-run action finishes as soon as it is decided.
CREATE TABLE remediation_actions (
    id INTEGER PRIMARY KEY,
    instance_id INTEGER NOT NULL,
    action TEXT NOT NULL CHECK (action IN ('restart', 'reboot', 'reset')),
    unit TEXT,
    dry_run INTEGER NOT NULL CHECK (dry_run IN (0, 1)),
    decided_at INTEGER NOT NULL,
    finished_at INTEGER,
    outcome TEXT
);

-- Serves an instance's recent actions and the Logs screen's paging.
CREATE INDEX remediation_actions_instance ON remediation_actions (instance_id, decided_at);

-- One action can serve several incidents: two incidents asking for the same
-- restart get one restart.
CREATE TABLE remediation_action_incidents (
    action_id INTEGER NOT NULL REFERENCES remediation_actions (id),
    incident_id INTEGER NOT NULL REFERENCES remediation_incidents (id),
    PRIMARY KEY (action_id, incident_id)
);

CREATE INDEX remediation_action_incidents_incident ON remediation_action_incidents (incident_id);

-- The unit to restart when a local HTTP or database check fails (design
-- section 7.1). A service check restarts its own unit and needs no row here.
CREATE TABLE remediation_check_units (
    instance_id INTEGER NOT NULL,
    check_name TEXT NOT NULL,
    subject TEXT NOT NULL,
    unit TEXT NOT NULL,
    PRIMARY KEY (instance_id, check_name, subject)
);

-- The step of the ladder an incident reached, when the ladder had nothing
-- left to try, and why it is waiting, for its page.
ALTER TABLE remediation_incidents ADD COLUMN ladder_step INTEGER NOT NULL DEFAULT 0;
ALTER TABLE remediation_incidents ADD COLUMN exhausted_at INTEGER;
ALTER TABLE remediation_incidents ADD COLUMN ladder_hold TEXT;
