-- Optional per-template remark (admin/aap_admin.php's Template File(s) list),
-- shown under the template's file name on aap_add.php and on Fixit's
-- add_report.php Files section. Only needed on a DB created before this
-- column was added to aap_master.sql.
ALTER TABLE `aap_case_type_templates`
  ADD COLUMN `remark` VARCHAR(255) NULL DEFAULT NULL AFTER `file_name`;
