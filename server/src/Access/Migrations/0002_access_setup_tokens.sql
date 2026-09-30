-- At most one row: issuing a token deletes any previous one. Only the SHA-256
-- hash of the token is stored.
CREATE TABLE access_setup_tokens (
    token_hash TEXT PRIMARY KEY,
    created_at INTEGER NOT NULL,
    expires_at INTEGER NOT NULL
);
