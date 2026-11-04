-- Links an evidence file to the Instruction point whose "Attach" input it
-- was uploaded through ('5', '4.1'), so aap_update.php can show it under
-- that point. NULL = general evidence. Only needed on a DB created before
-- this column was added to aap_master.sql.
ALTER TABLE `aap_case_evidence`
  ADD COLUMN `point_key` VARCHAR(20) NULL DEFAULT NULL AFTER `stored_name`;
