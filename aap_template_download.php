<?php
// Streams a Case Type reference template (aap_case_type_templates, see
// aapUploadCaseTypeTemplates()/aapFetchCaseTypeTemplates() in aap_lib.php)
// from the NAS - not tied to any one case and not sensitive case data, just
// a blank form/doc, so this only needs the outer app's own login
// (lock_adv.php), not AAP module access specifically. That matters because
// Fixit's own add_report.php links here too (loadCaseTypeTemplates()) for
// an AAP-linked category, and the reporter filling that in may have no AAP
// grade/access at all. Separate small file rather than folding this into
// aap_update.php's own download branch, since that one is keyed by a case
// id this file doesn't have.
ob_start();
require_once('../lock_adv.php');
$connect = 1;
include('../common/index_adv.php');
if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not available.");
}
require_once('aap_lib.php');
require_once('nas_config.php');

$template_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$stmt = $conn->prepare("SELECT file_name, stored_name FROM aap_case_type_templates WHERE id = ?");
$stmt->bind_param("i", $template_id);
$stmt->execute();
$tpl = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($tpl) {
    $content = corpNasConnect()->download($tpl['stored_name']);
    if ($content !== false) {
        ob_end_clean();
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($tpl['file_name']) . '"');
        header('Content-Length: ' . strlen($content));
        echo $content;
        exit;
    }
}
die("Template not found.");
