-- fixit_category is owned by the Fixit module, not this repo - but it
-- already carries an `aap_link` flag built specifically for the AAP
-- integration (see aap_lib.php's "Incoming from Fixit" queries), so this
-- extends that existing precedent rather than starting a new one.
--
-- New wildcard Fixit department "AAP" (staff_department.id = 48,
-- fixit_department.id = 38, both inserted directly, not via a migration
-- file - see AAP_FIXIT_WILDCARD_DEPARTMENT_ID in aap_lib.php) lets a Fixit
-- reporter pick Department = AAP, at which point the Category dropdown
-- should list every AAP Case Type (any department), not just one
-- department's. Each such Case Type gets an auto-synced fixit_category row
-- (department_id = 48, aap_link = 1) - aap_case_type_id is the reliable
-- link back to aap_case_types.id so the sync (admin/aap_admin.php's
-- save_case_type/toggle_case_type_recycle, see
-- aapSyncFixitCategoryForCaseType() in aap_lib.php) can find/update the
-- right row instead of matching on the category name text.
ALTER TABLE `fixit_category`
    ADD COLUMN `aap_case_type_id` INT NULL DEFAULT NULL AFTER `aap_link`,
    ADD UNIQUE KEY `uq_fixit_category_aap_case_type` (`aap_case_type_id`);
