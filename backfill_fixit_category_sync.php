<?php
// One-time backfill - run once on each environment (dev already done, this
// is for production) after sql/2026-10-19_fixit_category_aap_link.sql has
// been applied. Seeds fixit_category for every AAP Case Type that already
// existed before the auto-sync existed, so they show up under Fixit's "AAP"
// wildcard department immediately instead of only after the next edit/
// toggle in admin/aap_admin.php.
//
// Delete this file after running it once.
ob_start();
require_once('../lock_adv.php');
$connect = 1;
include('../common/index_adv.php');
ob_end_clean();

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not available.");
}
require_once('aap_lib.php');

$res = $conn->query("SELECT id FROM aap_case_types");
while ($row = $res->fetch_assoc()) {
    aapSyncFixitCategoryForCaseType($conn, (int)$row['id'], (int)$id_user);
    echo "Synced case type {$row['id']}<br>";
}
echo "Done. Delete this file now.";
