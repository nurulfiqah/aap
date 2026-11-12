-- Remembers a tier's last finite value before it was set to Unlimited, so
-- unticking Unlimited in Grouping Master restores it. Not re-runnable.
ALTER TABLE aap_approval_unit_tiers
  ADD COLUMN prev_tier_value DECIMAL(12,2) NULL DEFAULT NULL AFTER tier_value;
