CREATE TABLE fleet_projects (
    id INTEGER PRIMARY KEY,
    gcp_project_id TEXT NOT NULL UNIQUE,
    created_at INTEGER NOT NULL
);
