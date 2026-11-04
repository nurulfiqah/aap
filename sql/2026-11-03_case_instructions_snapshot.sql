-- Each case keeps its own copy of its Case Type's "Instruction for User"
-- list as it was when the case was created, so later edits to the Case Type
-- (adding/removing/reordering points) don't change what existing cases show.
-- Only needed on a DB created before this column was added to aap_master.sql.
ALTER TABLE `aap_cases`
  ADD COLUMN `instructions_snapshot` TEXT NULL DEFAULT NULL AFTER `case_type_id`;

-- Existing cases: freeze them at their Case Type's list as it is today (the
-- original list at creation time wasn't stored anywhere before this).
UPDATE `aap_cases` c
JOIN `aap_case_types` ct ON ct.id = c.case_type_id
SET c.instructions_snapshot = ct.instructions
WHERE c.instructions_snapshot IS NULL;
