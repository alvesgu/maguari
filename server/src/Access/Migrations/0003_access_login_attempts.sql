-- Failed login and setup submissions, for per-IP rate limiting on /auth/*.
CREATE TABLE access_login_attempts (
    id INTEGER PRIMARY KEY,
    ip TEXT NOT NULL,
    attempted_at INTEGER NOT NULL
);

CREATE INDEX access_login_attempts_ip_attempted_at ON access_login_attempts (ip, attempted_at);
