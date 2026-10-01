-- One-time enrollment tokens (design section 5.6), at most one per instance:
-- issuing a new one replaces the previous one. Only the SHA-256 hash of the
-- token is stored. instance_id is fleet_instances.id, without a foreign key
-- (design section 2.1 rules 2 and 3).
CREATE TABLE clients_enrollment_tokens (
    token_hash TEXT PRIMARY KEY,
    instance_id INTEGER NOT NULL UNIQUE,
    created_at INTEGER NOT NULL,
    expires_at INTEGER NOT NULL
);
