-- The SMTP server that emails go through (design section 13). At most one
-- row; no row means email is not set up. The password is encrypted with the
-- secret key (design section 9.3): the 24-byte nonce followed by the
-- ciphertext. It is NULL when username is '' (no sign-in, for example the
-- Google Workspace SMTP relay by IP address).
CREATE TABLE notifications_smtp_settings (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    host TEXT NOT NULL,
    port INTEGER NOT NULL,
    username TEXT NOT NULL,
    password_ciphertext BLOB,
    from_address TEXT NOT NULL,
    updated_at INTEGER NOT NULL
);
