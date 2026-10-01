-- Instances an administrator picked for enrollment (design section 8.1 item 2).
-- Keyed on project, zone and name, which is what an enrollment token is bound
-- to (design section 5.6). gcp_instance_id is refreshed on every pick, so an
-- instance recreated with the same name keeps its row.
CREATE TABLE fleet_instances (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES fleet_projects (id),
    gcp_instance_id TEXT NOT NULL,
    zone TEXT NOT NULL,
    name TEXT NOT NULL,
    picked_at INTEGER NOT NULL,
    UNIQUE (project_id, zone, name)
);
