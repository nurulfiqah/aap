-- Per-Case-Type "Skip Level 2 (Approval)": cases of this type are
-- auto-approved at their calculated value once submitted and go straight
-- to Level 3 (Execution). Not re-runnable (fails with "Duplicate column").
ALTER TABLE aap_case_types
  ADD COLUMN skip_approval TINYINT(1) NOT NULL DEFAULT 0 AFTER level3_hod_only;
