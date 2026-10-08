-- Hostnames whose served certificate the daily job checks, per instance
-- (design section 6.2). instance_id is Fleet's instance ID, with no foreign
-- key (design section 2.1).
CREATE TABLE monitoring_certificate_hostnames (
    id INTEGER PRIMARY KEY,
    instance_id INTEGER NOT NULL,
    hostname TEXT NOT NULL,
    added_at INTEGER NOT NULL,
    UNIQUE (instance_id, hostname)
);
