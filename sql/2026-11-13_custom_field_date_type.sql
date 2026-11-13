-- Form Master "Additional Fields": new Date type (calendar picker).
-- Safe to re-run.
ALTER TABLE aap_custom_fields
  MODIFY COLUMN field_type ENUM('number','dropdown','textarea','date') NOT NULL;
