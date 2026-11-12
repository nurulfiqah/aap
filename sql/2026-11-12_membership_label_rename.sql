-- Form Master: rename the "Membership ID" field's display label to
-- "Client Code / Member Card". Display only - matching still uses the
-- default label "Membership ID" (aapFormMasterFieldDefaults() in
-- aap_lib.php), so existing Case Types keep working. Safe to re-run.
-- No effect if the Form Master row doesn't exist yet (never saved) - then
-- just rename it once in admin/aap_settings.php instead.
UPDATE aap_form_master_config
SET config_json = JSON_SET(config_json, '$.customer_membership_id.label', 'Client Code / Member Card')
WHERE id = 1;
