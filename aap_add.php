<?php
require_once('../lock_adv.php');
$connect = 1;
date_default_timezone_set('Asia/Kuala_Lumpur');
include('../common/index_adv.php');
if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not available.");
}
require_once('aap_lib.php');

// Resolves the SuperAdmin/admin-level union used across every AAP page.
$aap_identity = aapResolveIdentity($conn, $id_user, $grade, $department);
$grade = $aap_identity['grade'];
$department = $aap_identity['department'];
$aap_dept_ids = $aap_identity['dept_ids'];
$aap_is_admin = $aap_identity['is_admin'];
$aap_is_superadmin = $aap_identity['is_superadmin'];
$aap_can_manage_all_depts = aapCanManageAllDepartments($grade, $aap_dept_ids, $aap_is_superadmin);
$my_dept_id = !empty($aap_dept_ids) ? $aap_dept_ids[0] : null;
if ((int)$grade < 1 && !$aap_is_admin) {
    die("You do not have access to this module.");
}

$msg = "";
$msg_type = "";

//Handoff from Fixit (fixit/add_report.php): carries the originating ticket
//id/ref through to the insert. No Family pre-filter - there's no mapping
//yet from a Fixit category to an AAP Family, so the requester just picks
//the Case Type directly from the full list.
$prefill_fixit_id  = isset($_GET['fixit_id']) && $_GET['fixit_id'] !== '' ? (int)$_GET['fixit_id'] : null;
$prefill_fixit_ref = isset($_GET['fixit_ref']) ? trim($_GET['fixit_ref']) : '';

// Cases can no longer be self-raised - every case must originate from a
// Fixit ticket (fixit/add_report.php's "Continue to Raise Approval Case"
// handoff, or the Incoming from Fixit queue on index.php), both of which
// pass fixit_id through. No fixit_id means someone reached this page
// directly, so bounce them back to the queue instead of allowing a raise.
if (!$prefill_fixit_id) {
    header("Location: index.php");
    exit;
}

$fixit_record = aapFetchFixitRecord($conn, $prefill_fixit_id);

// Raising is scoped to the Fixit report's own department, same rule as
// admin/aap_admin.php's Case Type management - any staff member belonging
// to that department can raise the case (no value/tier check here at all;
// that's decided later, live, at the approval gate via each Case Type's
// Level 2/3 Staff Tier lists - see aapCanApprove() in aap_lib.php).
// Full-list here is grade>=4/SuperAdmin/Customer Support
// (aapCanManageAllDepartments()) PLUS Operations - Operations is not part
// of that function's set (it doesn't manage Case Types generally), but they
// already see and act on every department's ticket in the whole "Incoming
// from Fixit" queue on index.php ($aap_can_see_incoming there), so they need
// the same cross-department raise ability here to keep that workflow working.
// A ticket lodged under the wildcard "AAP" department (see
// AAP_FIXIT_WILDCARD_DEPARTMENT_ID) was deliberately designed so ANY staff
// member can pick ANY department's Case Type for it (no department match
// enforced at submit time either - see the save handler below) - the
// per-department gate below would otherwise block everyone except
// full-list roles from ever opening this page for one, since no real
// staff.department ever literally contains the synthetic wildcard id.
$aap_can_raise_any_dept = $aap_can_manage_all_depts || aapIsOperations($aap_dept_ids);
$is_wildcard_fixit_ticket = $fixit_record && (int)$fixit_record['department_id'] === AAP_FIXIT_WILDCARD_DEPARTMENT_ID;
if (!$fixit_record || (!$is_wildcard_fixit_ticket && !aapDeptInScope($fixit_record['department_id'], $aap_dept_ids, $aap_can_raise_any_dept))) {
    die("You can only raise a case for a Fixit report lodged against your own department.");
}

$fixit_attachments = aapFetchFixitAttachments($conn, $prefill_fixit_id);
$fixit_custom_fields = aapFetchFixitCustomFieldAnswers($conn, $prefill_fixit_id);

// Evidence Note stays blank by default — the original Fixit report is shown
// in its own card above the form instead of being duplicated in here.
$default_evidence_note = isset($_POST['evidence_note']) ? $_POST['evidence_note'] : '';

// The Department filter defaults to (and is locked to) the originating
// Fixit report's own department, not the requester's own - a case raised
// from a Fixit ticket lodged against Academy must pick from Academy's Case
// Types even if the staff member raising it personally belongs to a
// different department (e.g. Customer Support handling the handoff). Every
// visit here has a fixit record (aap_add.php redirects to index.php
// otherwise, see above), so this is always set.
$prefill_dept_id = $fixit_record['department_id'] ?? null;

// Fetched pre-filtered to that one department, not just hidden client-side -
// other departments' Case Type names/descriptions must never reach the page
// at all, since the Department picker is only disabled (read-only), not a
// real access boundary, and anyone can read the page source. The one
// exception is AAP_FIXIT_WILDCARD_DEPARTMENT_ID ("AAP" in Fixit's own
// Department dropdown) - a synthetic department no real Case Type ever
// belongs to, so filtering to it literally would always return nothing;
// every active Case Type (any real department) is shown instead.
$is_wildcard_dept = (int)$prefill_dept_id === AAP_FIXIT_WILDCARD_DEPARTMENT_ID;
$case_types = aapFetchCaseTypes($conn, false, $is_wildcard_dept ? null : $prefill_dept_id);
if (empty($case_types)) {
    $msg = "No active Case Types are configured yet for this department. Ask an admin to set up the Case Type Registry first.";
    $msg_type = "alpro-warn";
}

$departments = aapFetchDepartmentsWithCaseTypes($conn);
// "Form Master" (admin/aap_settings.php) - single global Show/Hide,
// Required/Optional, Label config for the fixed Case Details + Bank Detail
// fields, applied regardless of which Case Type is picked.
$form_master_config = aapFetchFormMasterConfig($conn);
// "Additional Fields" (admin/aap_settings.php's Form Master section) -
// admin-defined custom fields (Number/Dropdown/Textarea) shown inline in
// Case Details, beyond the fixed Form Master fields above.
$custom_fields = aapFetchActiveCustomFields($conn);

if (isset($_POST['raise_case'])) {
    $case_type_id = (int)$_POST['case_type_id'];
    $ct = aapFetchCaseType($conn, $case_type_id);
    $fixit_record_id = isset($_POST['fixit_record_id']) && $_POST['fixit_record_id'] !== '' ? (int)$_POST['fixit_record_id'] : null;
    // "Form Master" (admin/aap_settings.php) - single global Show/Hide,
    // Required/Optional, custom Label for these fields, UNLESS this Case
    // Type has its own "Instruction for User" list, in which case it fully
    // REPLACES Form Master's field set (only listed fields show, all
    // required, in list order - see aapCaseTypeReplaceFieldConfig() in
    // aap_lib.php); re-checked here since the client-side handling in
    // js/aap_add.js never stops a tampered/direct POST.
    $ordered_points = $ct ? aapCaseTypeFieldOrder($ct) : [];
    $field_cfg = aapCaseTypeReplaceFieldConfig($form_master_config, $ordered_points);
    $extra_fields = array_values(array_filter($ordered_points, function ($p) { return $p['matched_field_key'] === null; }));
    // HTML form field names can't carry a literal '.' (PHP mangles it to
    // '_'), so instruction_answer[] POST keys arrive as "4_1" - translate
    // back to the "4.1" point_key form used everywhere else.
    $instruction_answers_posted = [];
    foreach ($_POST['instruction_answer'] ?? [] as $k => $v) {
        $instruction_answers_posted[str_replace('_', '.', $k)] = $v;
    }
    // Same '_' -> '.' translation as above, for a point flagged
    // "Attach" - keyed by point_key => whether a real file was actually
    // chosen (js/aap_add.js's buildExtraFieldWrap() marks the input
    // `required`, but that alone never stops a tampered/direct POST).
    $extra_attachment_present = [];
    foreach (($_FILES['extra_attachment']['error'] ?? []) as $k => $err) {
        $extra_attachment_present[str_replace('_', '.', $k)] = ($err === UPLOAD_ERR_OK);
    }

    // Cases no longer sit in Draft waiting for a separate "Open Case" step
    // (see aapCaseOpenReadyErrors() in aap_lib.php, the old gate this
    // replaces) - raising a case now goes straight to the approval
    // pipeline, so Calculated Value/Value Type/Report Description must be
    // filled right here whenever they're actually shown on the form
    // (Form Master's own `required` toggle is independent of this - these
    // three are never silently skippable once visible, same guarantee the
    // old Open Case gate gave, just enforced one step earlier now).
    $open_ready_missing = [];
    foreach (['calculated_value', 'value_type', 'recommended_outcome'] as $ork) {
        if ($field_cfg[$ork]['visible'] && trim($_POST[$ork] ?? '') === '') {
            $open_ready_missing[] = $field_cfg[$ork]['label'];
        }
    }

    // The Department picker is locked client-side to the Fixit report's own
    // department (see $prefill_dept_id above) purely as a convenience/UI
    // guarantee - it does not itself stop a tampered POST from submitting a
    // case_type_id belonging to a different department, so that match is
    // re-verified here server-side.
    if (!$ct || (int)$ct['recycle'] === 1) {
        $msg = "Invalid or retired Case Type selected.";
        $msg_type = "alpro-danger";
    } elseif ((int)$fixit_record['department_id'] !== AAP_FIXIT_WILDCARD_DEPARTMENT_ID && (int)$ct['department_id'] !== (int)$fixit_record['department_id']) {
        // The wildcard "AAP" department (see above) accepts any valid,
        // active Case Type regardless of its own real department - every
        // other department still enforces the exact match.
        $msg = "That Case Type does not belong to this Fixit report's department.";
        $msg_type = "alpro-danger";
    } elseif (trim($_POST['customer_name'] ?? '') === '' || trim($_POST['customer_membership_id'] ?? '') === '') {
        // The client-side `required` attribute on both fields already
        // blocks this in normal use - re-checked here since that alone
        // never stops a tampered/direct POST.
        $msg = $form_master_config['customer_name']['label'] . " and " . $form_master_config['customer_membership_id']['label'] . " are required - search and pick a customer first.";
        $msg_type = "alpro-danger";
    } elseif (
        ($field_cfg['transaction_ref']['required'] && trim($_POST['transaction_ref'] ?? '') === '')
        || ($field_cfg['calculated_value']['required'] && trim($_POST['calculated_value'] ?? '') === '')
        || ($field_cfg['value_type']['required'] && trim($_POST['value_type'] ?? '') === '')
        || ($field_cfg['recommended_outcome']['required'] && trim($_POST['recommended_outcome'] ?? '') === '')
        || ($field_cfg['bank_name']['required'] && trim($_POST['bank_id'] ?? '') === '')
        || ($field_cfg['bank_account_number']['required'] && trim($_POST['bank_account_number'] ?? '') === '')
        || ($field_cfg['bank_account_holder']['required'] && trim($_POST['bank_account_holder'] ?? '') === '')
    ) {
        $msg = "Please fill in all required fields in Case Details / Bank Detail.";
        $msg_type = "alpro-danger";
    } elseif (!empty($open_ready_missing)) {
        $msg = "Please fill in all required fields before this case can be submitted for approval: " . implode(', ', $open_ready_missing) . ".";
        $msg_type = "alpro-danger";
    } elseif (($custom_field_error = aapValidateCustomFieldSubmission($custom_fields, $_POST['custom_field'] ?? [])) !== null) {
        $msg = $custom_field_error;
        $msg_type = "alpro-danger";
    } elseif (($instruction_field_error = aapValidateExtraInstructionFields($extra_fields, $instruction_answers_posted, $extra_attachment_present)) !== null) {
        $msg = $instruction_field_error;
        $msg_type = "alpro-danger";
    } else {
        // Requester Type / Requesting Channel are no longer collected on this
        // form - Requester Type falls back to 'customer' (Case Type no longer
        // carries its own default_requester_type - dropped from aap_case_types
        // when Case Types moved to the Staff Tier approval model); Requesting
        // Channel has no equivalent default, so it's left blank.
        $requester_type      = in_array($_POST['requester_type'] ?? null, ['customer','outlet','bu'], true) ? $_POST['requester_type'] : 'customer';
        $requesting_channel  = trim($_POST['requesting_channel'] ?? '');
        $customer_membership_id = trim($_POST['customer_membership_id']);
        $customer_name       = trim($_POST['customer_name'] ?? '');
        $bank_id              = !empty($_POST['bank_id']) ? (int)$_POST['bank_id'] : null;
        $bank_account_number  = trim($_POST['bank_account_number'] ?? '');
        $bank_account_holder  = trim($_POST['bank_account_holder'] ?? '');
        $transaction_ref     = trim($_POST['transaction_ref']);
        $evidence_note       = trim($_POST['evidence_note']);
        $calculated_value    = ($_POST['calculated_value'] !== '') ? (float)$_POST['calculated_value'] : null;
        $value_type           = in_array($_POST['value_type'], ['cash','points'], true) ? $_POST['value_type'] : 'cash';
        $recommended_outcome = trim($_POST['recommended_outcome']);

        // Hidden fields never persist whatever was posted for them (e.g. a
        // forged devtools value) - a field this Case Type's Form Master
        // config hides stays blank regardless of client-side state.
        if (!$field_cfg['transaction_ref']['visible']) $transaction_ref = '';
        if (!$field_cfg['calculated_value']['visible']) $calculated_value = null;
        if (!$field_cfg['value_type']['visible']) $value_type = 'cash';
        if (!$field_cfg['recommended_outcome']['visible']) $recommended_outcome = '';
        if (!$field_cfg['bank_name']['visible']) $bank_id = null;
        if (!$field_cfg['bank_account_number']['visible']) $bank_account_number = '';
        if (!$field_cfg['bank_account_holder']['visible']) $bank_account_holder = '';

        $now = date('Y-m-d H:i:s');

        $physical_required = (int)$ct['physical_confirm_required'];
        $physical_status = $physical_required ? 'pending' : 'not_required';

        // No more Draft stage - a case starts directly at 'open', skipping
        // the old manual "Open Case" click (see the $open_ready_missing
        // check above, which now enforces what aapCaseOpenReadyErrors()
        // used to gate at that later step). It then either waits here in
        // Verification for physical confirmation, or - if none is needed -
        // is submitted for approval immediately below.
        $stmt = $conn->prepare("
            INSERT INTO aap_cases
                (case_ref, case_type_id, requester_type, requesting_channel, requester_staff_id, requester_department_id,
                 customer_membership_id, customer_name, bank_id, bank_account_number, bank_account_holder, transaction_ref, evidence_note, calculated_value, value_type, recommended_outcome,
                 physical_confirm_required, physical_confirm_status, approval_status, execution_status, case_status,
                 created_by, timestamp, updated_at, fixit_record_id)
            VALUES
                ('', ?, ?, ?, ?, ?,
                 ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                 ?, ?, 'pending', 'pending', 'open',
                 ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            "issiississssdssisissi",
            $case_type_id, $requester_type, $requesting_channel, $id_user, $my_dept_id,
            $customer_membership_id, $customer_name, $bank_id, $bank_account_number, $bank_account_holder, $transaction_ref, $evidence_note, $calculated_value, $value_type, $recommended_outcome,
            $physical_required, $physical_status,
            $id_user, $now, $now, $fixit_record_id
        );

        if ($stmt->execute()) {
            $new_id = $stmt->insert_id;
            $stmt->close();

            $case_ref = aapGenerateCaseRef($new_id);
            $conn->query("UPDATE aap_cases SET case_ref = '" . $conn->real_escape_string($case_ref) . "' WHERE id = " . $new_id);

            // Evidence uploads - stored on the corporate NAS, see aapUploadEvidenceFiles()
            $evidence_upload_result = aapUploadEvidenceFiles($conn, $new_id, $_FILES['evidence'] ?? null, $id_user, $now);

            // Per-point "Attach" files (admin/aap_admin.js's builder) - same
            // upload path/NAS storage as general evidence, just a separate
            // $_FILES entry keyed by point_key (js/aap_add.js's
            // buildExtraFieldWrap()) rather than the general Evidence
            // Attachments picker.
            $extra_attachment_upload_result = aapUploadEvidenceFiles($conn, $new_id, $_FILES['extra_attachment'] ?? null, $id_user, $now);

            // AAP-LINK: carry the original Fixit report's own attachments
            // over as this case's evidence too - same thing
            // aapCreateCaseFromFixitRequiredInfo() already does for the
            // auto-raised path, so a manually-raised case (this flow) stops
            // being the only place they're NOT copied, and the "Original
            // Fixit Report" card on aap_update.php no longer needs its own
            // separate attachment list (removed there - see that file).
            if ($fixit_record_id) {
                foreach ($fixit_attachments as $fa) {
                    $fa_local_path = 'C:/webroot/fixit/' . $fa['path'];
                    aapAttachExistingFileAsEvidence($conn, $new_id, $fa_local_path, $fa['name'], $id_user, $now);
                }
            }

            aapSaveCaseCustomValues($conn, $new_id, $custom_fields, $_POST['custom_field'] ?? []);
            aapSaveCaseInstructionAnswers($conn, $new_id, $extra_fields, $instruction_answers_posted);

            $audit_note = "Case raised as $case_ref (" . $ct['case_type_name'] . ", " . aapRequesterTypeLabel($requester_type) . ")";
            if ($fixit_record_id) $audit_note .= ", linked from Fixit ticket F$fixit_record_id";
            aapLogAudit($conn, $new_id, 'case_raised', $id_user, $audit_note);

            // No physical verification to wait on - submit for approval
            // immediately, same as a physical-required case does the moment
            // it's tagged/confirmed (aap_update.php's 'tag_physical').
            if (!$physical_required) {
                aapSubmitCaseForApproval($conn, $new_id, $case_type_id, $calculated_value, $my_dept_id, $id_user, $now);
            }

            // Case creation itself redirects straight to aap_update.php rather
            // than showing a message here - a failed upload still needs to
            // surface somewhere, so it rides along as that page's own flash
            // message (aap_update.php already reads $_SESSION['aap_flash_msg']
            // on load) instead of silently vanishing.
            $upload_warning = aapUploadFailureNote([
                'attempted' => $evidence_upload_result['attempted'] + $extra_attachment_upload_result['attempted'],
                'uploaded'  => $evidence_upload_result['uploaded'] + $extra_attachment_upload_result['uploaded'],
            ]);
            if ($upload_warning !== '') {
                $_SESSION['aap_flash_msg'] = "Case raised." . $upload_warning;
                $_SESSION['aap_flash_msg_type'] = 'alpro-warn';
            }

            header("Location: aap_update.php?id=$new_id&created=1");
            exit;
        } else {
            $msg = "Error raising case: " . $stmt->error;
            $msg_type = "alpro-danger";
        }
    }
}
?>

<?php include('aap_modern_head.php'); ?>

<div class="header">
  <b class="rtop"><b class="r1"></b><b class="r2"></b><b class="r3"></b><b class="r4"></b></b>
  <h1 class="headerH1"><img src="img/logo.svg"> Raise New Case</h1>
  <b class="rbottom"><b class="r4"></b><b class="r3"></b><b class="r2"></b><b class="r1"></b></b>
</div>

<?php include('aap_sidebar.php'); ?>

<div class="aap-modern">

    <div class="aap-page-title-row">
        <h2 class="aap-page-title"><i class="bi bi-plus-circle"></i> Raise New Case</h2>
        <div class="aap-page-actions">
            <a href="index.php" class="alpro-btn alpro-btn-grey" style="text-decoration:none;"><i class="bi bi-arrow-left"></i> Back to Case Queue</a>
        </div>
    </div>

    <?php if (!empty($msg)): ?>
        <div class="alpro-alert <?php echo $msg_type; ?>"><?php echo htmlspecialchars($msg); ?></div>
    <?php endif; ?>

    <?php if ($prefill_fixit_id): ?>
    <div class="aap-card aap-fixit-card" style="margin-bottom: 1rem;">
        <div class="aap-card-title-row">
            <h6 class="aap-card-title"><i class="bi bi-life-preserver"></i> Original Fixit Report — <?php echo htmlspecialchars($prefill_fixit_ref ?: ('F' . $prefill_fixit_id)); ?></h6>
            <a href="<?php echo htmlspecialchars(aapFixitTicketUrl($prefill_fixit_id)); ?>" target="_blank" style="font-size:12px;">View in Fixit <i class="bi bi-box-arrow-up-right"></i></a>
        </div>
        <?php if ($fixit_record): ?>
            <div class="aap-fact-row">
                <div class="alpro-field"><label>Category</label><div><?php echo htmlspecialchars($fixit_record['category_name'] ?: '—'); ?></div></div>
                <div class="alpro-field"><label>Department</label><div><?php echo htmlspecialchars($fixit_record['department_name'] ?: '—'); ?></div></div>
                <div class="alpro-field"><label>Outlet</label><div><?php echo htmlspecialchars($fixit_record['outlet_code'] ?: '—'); ?></div></div>
                <div class="alpro-field"><label>Lodged By</label><div><?php echo htmlspecialchars($fixit_record['lodged_by_name'] ?: '—'); ?> on <?php echo $fixit_record['lodge'] ? date('d-m-Y H:i', strtotime($fixit_record['lodge'])) : '—'; ?></div></div>
            </div>
            <div class="alpro-field aap-fact-block"><label>Report</label><div><?php echo nl2br(htmlspecialchars($fixit_record['report'])); ?></div></div>
            <?php // Remark is skipped whenever Required Information exists below -
            // add_report.php's buildRemarkFromCustomFields() auto-fills remark
            // from those exact same answers (so email notifications/older
            // views that only read `remark` still show something), which would
            // otherwise duplicate the Required Information block here verbatim. ?>
            <?php if (!empty($fixit_record['remark']) && empty($fixit_custom_fields)): ?>
            <div class="alpro-field aap-fact-block"><label>Remark</label><div><?php echo nl2br(htmlspecialchars($fixit_record['remark'])); ?></div></div>
            <?php endif; ?>
            <?php if (!empty($fixit_custom_fields)): ?>
            <div class="alpro-field aap-fact-block">
                <label>Required Information</label>
                <div>
                    <?php foreach ($fixit_custom_fields as $cf): ?>
                        <b><?php echo htmlspecialchars($cf['field_label']); ?>:</b> <?php echo htmlspecialchars($cf['field_value']); ?><br>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            <?php if (!empty($fixit_attachments)): ?>
            <div class="alpro-field aap-fact-block">
                <label>Attachment(s)</label>
                <ul class="aap-attach-list">
                    <?php foreach ($fixit_attachments as $fa): ?>
                        <li>
                            <a href="<?php echo htmlspecialchars(aapFixitAttachmentUrl($fa['id'])); ?>" target="_blank"><i class="bi bi-file-earmark-arrow-down"></i> <?php echo htmlspecialchars($fa['name']); ?></a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        <?php else: ?>
            <p class="aap-card-hint" style="margin-bottom:0;">Ticket details could not be loaded.</p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($case_types)): ?>
    <form method="post" action="" enctype="multipart/form-data">
        <?php if ($prefill_fixit_id): ?>
            <input type="hidden" name="fixit_record_id" value="<?php echo (int)$prefill_fixit_id; ?>">
        <?php endif; ?>

        <div class="aap-bento">
            <div class="aap-bento-item aap-span-12">
                <div class="aap-card">
                    <h6 class="aap-card-title"><i class="bi bi-signpost-split"></i> Case Type</h6>
                    <div class="alpro-grid" style="flex-wrap:nowrap;">
                        <div class="alpro-field" style="flex:1; min-width:0;">
                            <label>Department <span class="aap-req">*</span></label>
                            <select class="alpro-input" id="department_id_filter" required disabled style="height:38px;">
                                <option value="">Select Department</option>
                                <?php // "AAP" (the wildcard) never owns any Case Type itself, so it
                                // never appears in $departments (aapFetchDepartmentsWithCaseTypes()
                                // only lists departments that actually have one) - injected here
                                // directly so the locked dropdown still shows it instead of landing
                                // on the blank placeholder. ?>
                                <?php if ($is_wildcard_dept): ?>
                                    <option value="<?php echo AAP_FIXIT_WILDCARD_DEPARTMENT_ID; ?>" selected>AAP</option>
                                <?php endif; ?>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo $dept['id']; ?>" <?php echo ($prefill_dept_id == $dept['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($dept['depart_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="alpro-muted" style="font-size:12px; margin:6px 0 0;" id="department_filter_hint" data-wildcard="<?php echo $is_wildcard_dept ? '1' : '0'; ?>">
                                <?php echo $is_wildcard_dept
                                    ? 'Raised under the wildcard "AAP" department - pick a Case Type below to see which real department the case is filed under.'
                                    : 'Fixed to the originating Fixit report\'s department - pick the Case Type from that department\'s list.'; ?>
                            </p>
                        </div>

                        <div class="alpro-field" style="flex:2; min-width:0;">
                            <label>Case Type <span class="aap-req">*</span></label>
                            <select class="alpro-input" name="case_type_id" id="case_type_id" required style="height:38px;" data-form-master-config="<?php echo htmlspecialchars(json_encode($form_master_config)); ?>">
                                <option value="">Select Case Type</option>
                                <?php // A Fixit Category raised under the wildcard "AAP" department is
                                // itself already linked to one specific Case Type
                                // (fixit_category.aap_case_type_id - see aapFetchFixitRecord()) - that's
                                // not just a suggestion, it's the exact Case Type the reporter's Category
                                // pick in Fixit resolved to, so pre-select it here rather than making the
                                // requester re-pick the same thing from the full list again. Still a plain
                                // <select>, not locked/disabled - they can change it if genuinely needed. ?>
                                <?php foreach ($case_types as $ct):
                                    // Per-Case-Type "Instruction for User" list (see
                                    // aapCaseTypeFieldOrder() in aap_lib.php) - when non-empty it fully
                                    // REPLACES Form Master's field set for Case Details/Bank Detail (not
                                    // additive), so the whole ordered/tagged point list is handed to the
                                    // client as one payload; js/aap_add.js's applyCaseDetailsLayout()
                                    // falls back to the base Form Master config (on the <select> itself)
                                    // whenever this is empty.
                                    //
                                    // This Fixit report's own "Required Information" answers
                                    // ($fixit_custom_fields - same point list, same order, already
                                    // submitted once in Fixit) auto-fill this list's fields rather than
                                    // making the requester retype what they already gave - only for the
                                    // Case Type Fixit actually linked this report to, matched by position
                                    // (both built from the identical instructions JSON, so sort_order
                                    // lines up with the point's own order 1:1).
                                    $opt_is_linked = !empty($fixit_record['aap_case_type_id']) && (int)$fixit_record['aap_case_type_id'] === (int)$ct['id'];
                                    $opt_ordered_points = aapCaseTypeFieldOrder($ct);
                                    if ($opt_is_linked && !empty($fixit_custom_fields)) {
                                        foreach ($opt_ordered_points as $opi => &$opp) {
                                            $opp['value'] = $fixit_custom_fields[$opi]['field_value'] ?? '';
                                        }
                                        unset($opp);
                                    } ?>
                                    <option value="<?php echo $ct['id']; ?>"
                                        data-department-id="<?php echo (int)$ct['department_id']; ?>"
                                        data-physical="<?php echo (int)$ct['physical_confirm_required']; ?>"
                                        data-desc="<?php echo htmlspecialchars($ct['description'] ?? ''); ?>"
                                        data-ordered-points="<?php echo htmlspecialchars(json_encode($opt_ordered_points)); ?>"
                                        data-templates="<?php echo htmlspecialchars(json_encode(aapFetchCaseTypeTemplates($conn, $ct['id']))); ?>"
                                        <?php echo $opt_is_linked ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($ct['case_type_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div id="ct_info" class="aap-card-hint" style="display:none; background:#f7f9fb; border:1px solid #e5e9ec; border-radius:6px; padding:10px; margin-top:10px;"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="aap-bento-item aap-span-12">
                <div class="aap-card">
                    <div class="aap-card-title-row">
                        <h6 class="aap-card-title"><i class="bi bi-file-earmark-text"></i> Case Details</h6>
                        <button type="button" id="case-details-refresh-btn" class="alpro-btn alpro-btn-grey" title="Clear every field in this section" style="padding:4px 12px; font-size:12px;"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
                    </div>

                    <div class="alpro-field" style="margin-bottom:14px;">
                        <label>This case belongs to</label>
                        <div class="aap-toggle-group" role="group">
                            <button type="button" class="aap-toggle-btn active" id="beneficiary-type-customer" data-type="customer">Customer</button>
                            <button type="button" class="aap-toggle-btn" id="beneficiary-type-staff" data-type="staff">Staff</button>
                        </div>
                        <input type="hidden" id="beneficiary-type-input" value="customer">
                    </div>

                    <!-- One shared, orderable container for every Case
                    Details field - when a Case Type has its own
                    "Instruction for User" list, js/aap_add.js's
                    applyCaseDetailsLayout() reorders/hides/relabels these
                    (and may pull Bank Detail's Account Number/Holder in
                    here too) to match that list exactly; with no list, it
                    falls back to Form Master's config in this same default
                    order. Identity fields (Customer Name/Membership ID)
                    always stay visible+required regardless. -->
                    <div class="alpro-grid" id="case-details-fields-container">
                        <div class="alpro-field" id="field-wrap-customer_name" style="position:relative;">
                            <label id="customer-name-label" data-customer-label="<?php echo htmlspecialchars($form_master_config['customer_name']['label']); ?>" data-staff-label="<?php echo htmlspecialchars($form_master_config['staff_name']['label']); ?>"><?php echo htmlspecialchars($form_master_config['customer_name']['label']); ?> <span class="aap-req">*</span>
                                <span class="aap-admin-info-icon">i<span class="aap-admin-tooltip">Type a name, IC, or membership/staff ID here or in the ID field to search. Picking a result fills both fields and locks them - use Refresh above to clear this whole section and search again.</span></span>
                            </label>
                            <input class="alpro-input" type="text" name="customer_name" id="customer-name-input" autocomplete="off" required>
                            <ul id="customer-name-results" class="aap-attach-list" style="display:none; position:absolute; z-index:20; left:0; right:0; margin-top:4px; max-height:260px; overflow-y:auto; box-shadow:0 8px 20px rgba(0,0,0,.1);"></ul>
                        </div>

                        <div class="alpro-field" id="customer-membership-field" style="position:relative;">
                            <label id="customer-membership-label"><?php echo htmlspecialchars($form_master_config['customer_membership_id']['label']); ?> <span class="aap-req">*</span></label>
                            <input class="alpro-input" type="text" name="customer_membership_id" id="customer-membership-input" autocomplete="off" inputmode="numeric" pattern="[0-9]*" required>
                            <ul id="customer-membership-results" class="aap-attach-list" style="display:none; position:absolute; z-index:20; left:0; right:0; margin-top:4px; max-height:260px; overflow-y:auto; box-shadow:0 8px 20px rgba(0,0,0,.1);"></ul>
                        </div>

                        <div class="alpro-field" id="field-wrap-transaction_ref">
                            <label id="field-label-transaction_ref">Transaction No</label>
                            <input class="alpro-input" type="text" name="transaction_ref" id="case-details-transaction-ref" inputmode="numeric" pattern="[0-9]*">
                        </div>

                        <div class="alpro-field" id="field-wrap-calculated_value">
                            <label id="field-label-calculated_value">Calculated Value (Requestor)</label>
                            <input class="alpro-input" type="number" step="0.01" min="0" name="calculated_value" id="case-details-calculated-value">
                        </div>

                        <div class="alpro-field" id="field-wrap-value_type">
                            <label id="field-label-value_type">Value Type</label>
                            <select class="alpro-input" name="value_type" id="value_type">
                                <option value="cash">Cash (RM)</option>
                                <option value="points">Points</option>
                            </select>
                        </div>

                        <div class="alpro-field" id="field-wrap-recommended_outcome" style="grid-column: 1 / -1;">
                            <label id="field-label-recommended_outcome">Report Description</label>
                            <textarea class="alpro-input" name="recommended_outcome" id="case-details-recommended-outcome" placeholder="e.g. Points, Cash refund, Exchange first" style="height:38px; max-height:150px; resize:vertical; overflow-y:auto;"></textarea>
                        </div>
                    </div>

                    <?php if (!empty($custom_fields)): ?>
                    <!-- Additional Fields (admin/aap_settings.php's Form Master
                    section) - admin-defined custom fields, rendered inline
                    within Case Details rather than their own card. Separate,
                    global, Form-Master-governed concern - unaffected by a
                    Case Type's own Instruction for User list. -->
                    <div class="alpro-grid alpro-mt-10">
                        <?php foreach ($custom_fields as $cf): ?>
                            <div class="alpro-field">
                                <label><?php echo htmlspecialchars($cf['label']); ?> <?php if ($cf['required']): ?><span class="aap-req">*</span><?php endif; ?></label>
                                <?php if ($cf['field_type'] === 'number'): ?>
                                    <input class="alpro-input" type="number" step="any" name="custom_field[<?php echo (int)$cf['id']; ?>]" <?php echo $cf['required'] ? 'required' : ''; ?>>
                                <?php elseif ($cf['field_type'] === 'dropdown'): ?>
                                    <select class="alpro-input" name="custom_field[<?php echo (int)$cf['id']; ?>]" <?php echo $cf['required'] ? 'required' : ''; ?>>
                                        <option value="">Select</option>
                                        <?php foreach ($cf['options'] as $opt): ?>
                                            <option value="<?php echo htmlspecialchars($opt); ?>"><?php echo htmlspecialchars($opt); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <textarea class="alpro-input" name="custom_field[<?php echo (int)$cf['id']; ?>]" <?php echo $cf['required'] ? 'required' : ''; ?> style="height:38px; max-height:150px; resize:vertical; overflow-y:auto;"></textarea>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="aap-bento-item aap-span-12" id="bank-detail-card">
                <div class="aap-card">
                    <h6 class="aap-card-title"><i class="bi bi-bank"></i> Bank Detail</h6>
                    <div class="alpro-grid" id="bank-detail-fields-container">
                        <div class="alpro-field" id="field-wrap-bank_name">
                            <label id="field-label-bank_name">Bank Name</label>
                            <select class="alpro-input" name="bank_id" id="bank-name-input">
                                <option value="">Select Bank</option>
                                <?php foreach (aapFetchBankMasterOptions($conn) as $bank): ?>
                                    <option value="<?php echo $bank['id']; ?>"><?php echo htmlspecialchars($bank['bank_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="alpro-field" id="field-wrap-bank_account_number">
                            <label id="field-label-bank_account_number">Account Number</label>
                            <input class="alpro-input" type="text" name="bank_account_number" id="bank-account-number-input" inputmode="numeric" pattern="[0-9]*">
                        </div>
                        <div class="alpro-field" id="field-wrap-bank_account_holder">
                            <label id="field-label-bank_account_holder">Account Holder Name</label>
                            <input class="alpro-input" type="text" name="bank_account_holder" id="bank-account-holder-input">
                        </div>
                    </div>
                </div>
            </div>

            <div class="aap-bento-item aap-span-12">
                <div class="aap-card">
                    <h6 class="aap-card-title"><i class="bi bi-paperclip"></i> Evidence</h6>
                    <div class="alpro-grid">
                        <div class="alpro-field">
                            <label>Evidence Note</label>
                            <textarea class="alpro-input" name="evidence_note" style="height:100px;" placeholder="Receipt/SO match, photo/video, CCTV reference, ID proof, etc."><?php echo htmlspecialchars($default_evidence_note); ?></textarea>
                        </div>
                        <div class="alpro-field">
                            <label>Evidence Upload</label>
                            <input class="alpro-input" type="file" name="evidence[]" id="evidence-file-input" multiple>
                            <ul class="aap-attach-list" id="evidence-file-preview" style="margin-top:8px;"></ul>
                        </div>
                    </div>
                </div>
            </div>

            <div class="aap-bento-item aap-span-12">
                <div class="alpro-actions" style="padding-top: 4px; justify-content:flex-end;">
                    <button class="alpro-btn alpro-btn-blue" type="submit" name="raise_case" style="flex:0 0 auto; padding:8px 20px; font-size:14px; border-radius:8px;">Raise Case</button>
                    <button class="alpro-btn alpro-btn-grey" type="button" onclick="window.location.reload();" style="flex:0 0 auto; padding:8px 20px; font-size:14px; border-radius:8px; border:1px solid #000;">Refresh</button>
                </div>
            </div>
        </div>
    </form>
    <?php endif; ?>
</div>

<?php $page_js = 'js/aap_add.js'; include('aap_footer.php'); ?>
