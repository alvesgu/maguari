-- The latest "not checked" result of each client check (design section 6.3
-- item 7), only so the instance page can show its sentence and time. It
-- changes no outcome, counts in no failure streak and writes no event. Gone
-- when the check passes or fails again or is withdrawn. instance_id is
-- Fleet's instance ID, with no foreign key (design section 2.1).
CREATE TABLE monitoring_check_not_checked (
    instance_id INTEGER NOT NULL,
    check_name TEXT NOT NULL,
    subject TEXT NOT NULL,
    detail TEXT NOT NULL,
    checked_at INTEGER NOT NULL,
    PRIMARY KEY (instance_id, check_name, subject)
);
