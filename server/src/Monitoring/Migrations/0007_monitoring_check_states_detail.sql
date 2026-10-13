-- The latest pass or fail sentence of each check outcome (design section
-- 6.3 item 7), so an incident opened by Remediation's reconciliation has one.
-- NULL when none was stored. Outcomes of the daily job's checks get the
-- sentence of their latest stored result with the same outcome; the
-- heartbeat-age check stores none until its next tick.
ALTER TABLE monitoring_check_states ADD COLUMN detail TEXT;

UPDATE monitoring_check_states SET detail = (
    SELECT result.detail FROM monitoring_check_results AS result
    WHERE result.instance_id = monitoring_check_states.instance_id
        AND result.check_name = monitoring_check_states.check_name
        AND result.subject = monitoring_check_states.subject
        AND result.outcome = monitoring_check_states.outcome
    ORDER BY result.id DESC LIMIT 1
);
