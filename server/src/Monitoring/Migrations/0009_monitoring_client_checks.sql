-- The checks each instance's client runs, defined in the web app and sent to
-- the client in heartbeat responses when they change (design section 6.1.3).
-- type is service (from phase 2 step 2.2), local_http or database; target is
-- the unit name, URL or database name, and host_name is set only for
-- local_http ('' otherwise). instance_id is Fleet's instance ID, with no
-- foreign key (design section 2.1).
CREATE TABLE monitoring_client_checks (
    id INTEGER PRIMARY KEY,
    instance_id INTEGER NOT NULL,
    type TEXT NOT NULL,
    target TEXT NOT NULL,
    host_name TEXT NOT NULL DEFAULT '',
    added_at INTEGER NOT NULL,
    UNIQUE (instance_id, type, target, host_name)
);

-- Failed results in a row of a check whose outcome is not fail yet: the
-- client's checks fail only after 2 in a row (design section 6.3 item 7).
-- Gone when the check passes, fails for good or is withdrawn.
CREATE TABLE monitoring_check_failure_streaks (
    instance_id INTEGER NOT NULL,
    check_name TEXT NOT NULL,
    subject TEXT NOT NULL,
    failures INTEGER NOT NULL,
    PRIMARY KEY (instance_id, check_name, subject)
);
