-- What each client's last heartbeat said about its check settings (design
-- section 5.2): check_settings_supported 1 when it sent check_settings_version
-- at all, and check_settings_version the version it has (NULL for none).
ALTER TABLE clients_clients ADD COLUMN check_settings_supported INTEGER NOT NULL DEFAULT 0;
ALTER TABLE clients_clients ADD COLUMN check_settings_version TEXT;
