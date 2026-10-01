-- Server-wide settings owned by Access, one row per setting. The first one is
-- base_url, the server's own address (scheme, host and optional port), set
-- at setup. Enroll commands use it instead of the request's Host header.
CREATE TABLE access_settings (
    name TEXT PRIMARY KEY,
    value TEXT NOT NULL,
    updated_at INTEGER NOT NULL
);
