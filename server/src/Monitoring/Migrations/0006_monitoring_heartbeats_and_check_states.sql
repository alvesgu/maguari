-- Each instance's last heartbeat, as Monitoring received it from Clients
-- (design section 6.2): the time the heartbeat-age check judges. instance_id
-- is Fleet's instance ID, with no foreign key (design section 2.1).
CREATE TABLE monitoring_heartbeats (
    instance_id INTEGER PRIMARY KEY,
    last_at INTEGER NOT NULL
);

-- When the tick last judged heartbeat ages, and when ticks last resumed after
-- a gap: ages are measured from then at the earliest, so server downtime is
-- not judged as instance downtime (design section 6.2). At most one row.
CREATE TABLE monitoring_ticks (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    last_at INTEGER NOT NULL,
    resumed_at INTEGER NOT NULL
);

-- The current outcome of each check per instance and subject, pass or fail
-- (design section 6.3): a result that changes it writes an event. "Not
-- checked" never changes it. since is when it changed.
CREATE TABLE monitoring_check_states (
    instance_id INTEGER NOT NULL,
    check_name TEXT NOT NULL,
    subject TEXT NOT NULL,
    outcome TEXT NOT NULL CHECK (outcome IN ('pass', 'fail')),
    since INTEGER NOT NULL,
    PRIMARY KEY (instance_id, check_name, subject)
);
