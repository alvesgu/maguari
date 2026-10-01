-- One row per accepted signed request, for replay protection (design section
-- 5.5) and the per-client rate limit (design section 10.2). A nonce is kept
-- until expires_at = max(timestamp + 300, received_at + 60): as long as its
-- request could pass the clock check, and at least as long as the rate
-- limit window. Expired rows are deleted on every accepted request.
CREATE TABLE clients_nonces (
    client_id TEXT NOT NULL REFERENCES clients_clients (client_id) ON DELETE CASCADE,
    nonce TEXT NOT NULL,
    received_at INTEGER NOT NULL,
    expires_at INTEGER NOT NULL,
    PRIMARY KEY (client_id, nonce)
) WITHOUT ROWID;

CREATE INDEX clients_nonces_expires_at ON clients_nonces (expires_at);
