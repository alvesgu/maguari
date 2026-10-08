-- What on the instance a result is about, for checks with more than one
-- result per instance: a certificate's domain (design section 9.2). Empty for
-- the disk size check, which has one result per instance.
ALTER TABLE monitoring_check_results ADD COLUMN subject TEXT NOT NULL DEFAULT '';
