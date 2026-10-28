<?php
// NAS credentials load from .env (gitignored, same convention as
// okr/mailer.php's getOkrMailConfig() - parse_ini_file, no library needed).
// This file is tracked in git, so it must never hold the real login itself,
// not even as a fallback: without a .env, NAS_USER/NAS_PASSWORD are simply
// empty and corpNasConnect() fails to log in (a clear error) instead of
// working off a password committed to the repo. Copy .env.example to .env.
$aap_env_path = __DIR__ . '/.env';
$aap_env = (is_file($aap_env_path) && is_readable($aap_env_path))
    ? parse_ini_file($aap_env_path, false, INI_SCANNER_RAW)
    : false;

define('CORP_NAS_IP',       !empty($aap_env['NAS_IP']) ? $aap_env['NAS_IP'] : '172-18-28-55.alprodc.direct.quickconnect.to');
define('CORP_NAS_PORT',     !empty($aap_env['NAS_PORT']) ? (int)$aap_env['NAS_PORT'] : 5101);
define('CORP_NAS_USER',     !empty($aap_env['NAS_USER']) ? $aap_env['NAS_USER'] : '');
define('CORP_NAS_PASSWORD', !empty($aap_env['NAS_PASSWORD']) ? $aap_env['NAS_PASSWORD'] : '');
define('CORP_NAS_FOLDER',   !empty($aap_env['NAS_FOLDER']) ? $aap_env['NAS_FOLDER'] : '/DigitalInnovation/aap_approval_payout');
unset($aap_env_path, $aap_env);

function corpNasConnect() {
    // Self-contained within this repo (aap/lib/synologynas.php), same
    // pattern as okr's own nas_config.php - not the outer shared odb/lib/
    // path, which production's git-cloned aap/ folder never receives since
    // it lives outside this repo's tracked files.
    require_once __DIR__ . '/lib/synologynas.php';
    return new CorporateNAS(CORP_NAS_IP, CORP_NAS_PORT, CORP_NAS_USER, CORP_NAS_PASSWORD);
}
