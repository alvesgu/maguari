-- The latest failed result's reason code, for checks that have one. It can
-- change while the outcome stays fail, which writes no event, so Remediation
-- reads it when it decides (design section 7.1).
ALTER TABLE monitoring_check_states ADD COLUMN reason TEXT;
