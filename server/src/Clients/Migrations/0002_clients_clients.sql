-- Enrolled clients, at most one per instance (design section 5.6).
-- client_id is a random public identifier. The HMAC secret is encrypted with
-- the server's secret key (design section 9.3): a 24-byte nonce followed by
-- the ciphertext. instance_id is fleet_instances.id, without a foreign key
-- (design section 2.1 rules 2 and 3).
CREATE TABLE clients_clients (
    client_id TEXT PRIMARY KEY,
    instance_id INTEGER NOT NULL UNIQUE,
    secret_ciphertext BLOB NOT NULL,
    enrolled_at INTEGER NOT NULL,
    last_heartbeat_at INTEGER,
    client_version TEXT,
    protocol_version INTEGER
);
