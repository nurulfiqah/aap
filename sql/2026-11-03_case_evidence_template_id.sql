-- Links an evidence file to the Case Type template (aap_case_type_templates)
-- whose upload slot it came from, so aap_update.php's Evidence list can show
-- that template's remark above it. NULL = not uploaded for a template. Only
-- needed on a DB created before this column was added to aap_master.sql.
ALTER TABLE `aap_case_evidence`
  ADD COLUMN `template_id` INT UNSIGNED NULL DEFAULT NULL AFTER `point_key`;
