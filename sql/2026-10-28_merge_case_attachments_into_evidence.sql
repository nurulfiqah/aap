-- Merges aap_case_attachments into the existing notes/links table and
-- renames it, so all three kinds of case evidence (note / file / link) live
-- in one table: aap_case_evidence. See aap_master.sql for the final shape.
--
-- Only needed on a DB that already has aap_case_evidence_notes +
-- aap_case_attachments. A fresh install from aap_master.sql already has
-- aap_case_evidence and needs none of this.
--
-- Run in order, in one session. Back up both tables first.

RENAME TABLE `aap_case_evidence_notes` TO `aap_case_evidence`;

ALTER TABLE `aap_case_evidence`
  MODIFY COLUMN `type` ENUM('note','file','link') NOT NULL,
  ADD COLUMN `file_name` VARCHAR(255) NULL AFTER `note`,
  ADD COLUMN `stored_name` VARCHAR(255) NULL AFTER `file_name`,
  RENAME INDEX `idx_aap_evidence_notes_case` TO `idx_aap_evidence_case`,
  RENAME INDEX `idx_aap_evidence_notes_type` TO `idx_aap_evidence_type`;

INSERT INTO `aap_case_evidence` (case_id, type, file_name, stored_name, created_by, timestamp)
SELECT case_id, 'file', file_name, stored_name, uploaded_by, timestamp
FROM `aap_case_attachments`
ORDER BY id;

-- Check the counts match before dropping:
--   SELECT COUNT(*) FROM aap_case_attachments;
--   SELECT COUNT(*) FROM aap_case_evidence WHERE type = 'file';
DROP TABLE `aap_case_attachments`;
