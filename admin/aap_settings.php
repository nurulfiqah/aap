<?php
// Buffers everything so the AJAX action branches below (list_aap_superadmin /
// update_aap_superadmin) can discard the HTML lock_adv.php echoes before its
// redirect check runs and emit clean JSON instead - same trick used in
// aap_notifications.php.
ob_start();
require_once('../../lock_adv.php');
$connect = 1;
date_default_timezone_set('Asia/Kuala_Lumpur');
include('../../common/index_adv.php');
if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not available.");
}
require_once('../aap_lib.php');

// This page grants/revokes staff.aap (AAP admin access) itself, so only an
// existing AAP admin (staff.aap = 1, the only thing aapIsAdmin() checks as
// of 2026-10-19) can open it - there is no other path in any more
// (grade/department/OKR/ATEM no longer count toward AAP admin status).
$aap_identity = aapResolveIdentity($conn, $id_user, $grade, $department);
$grade = $aap_identity['grade'];
$department = $aap_identity['department'];
$aap_dept_ids = $aap_identity['dept_ids'];
$aap_is_admin = $aap_identity['is_admin'];
$aap_is_superadmin = $aap_identity['is_superadmin'];
if (!$aap_is_superadmin) {
    die("Admin access only. This page manages AAP Admin Access.");
}

// PHP's default session handler locks the session file for the whole
// request - this page is hit repeatedly via AJAX below (staff search, Bank
// Master CRUD) and never writes to $_SESSION itself, so releasing the lock
// here lets those requests (and everything else sharing this browser's
// session) run concurrently instead of queuing up behind whichever one
// happens to be mid-request.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$msg = "";
$msg_type = "";
$now = date('Y-m-d H:i:s');

// ---- AAP SuperAdmin Access panel - AJAX endpoints (JSON, mirrors ATEM's
// admin/backend.php getSuperAdminStaff/updateSuperAdmin), so the panel below
// can search/paginate/update live without a full page reload, same as the
// ATEM & OKR SuperAdmin panel it's modelled on. Only staff.aap is written
// here - staff.atem/okr belong to their own modules' admin panels
// (atem/admin/index.php), not this one.
if (isset($_GET['action']) && $_GET['action'] === 'list_aap_superadmin' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    ob_end_clean();
    header('Content-Type: application/json');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per_page = max(5, min(100, (int)($_GET['per_page'] ?? 30)));
    $offset = ($page - 1) * $per_page;
    $name_filter = trim($_GET['name_filter'] ?? '');
    $name_sql = $name_filter !== '' ? " AND nama_staff LIKE '%" . $conn->real_escape_string($name_filter) . "%'" : '';
    // With no name search, only show current full Admins (aap = 1) - a
    // roster, not the full staff directory. Department Managers (aap = 2)
    // are deliberately excluded here and managed on their own page
    // (admin/aap_department_managers.php) instead - this panel is Admin-only.
    // A name search reaches every staff member so a brand-new admin can
    // still be found and promoted.
    $admin_only_sql = $name_filter === '' ? ' AND aap = 1' : '';
    // Department Managers (aap = 2) never show here, even via name search -
    // this panel is Admin-only end to end; manage them on their own page
    // (admin/aap_department_managers.php) instead.
    $exclude_managers_sql = ' AND aap != 2';

    $count_res = $conn->query("SELECT COUNT(*) c FROM staff WHERE recycle != 1 $name_sql $admin_only_sql $exclude_managers_sql");
    $total = $count_res ? (int)$count_res->fetch_assoc()['c'] : 0;

    $res = $conn->query("
        SELECT s.id, s.nama_staff, s.aap, sd.depart_name
        FROM staff s
        LEFT JOIN staff_department sd ON sd.id = s.department
        WHERE s.recycle != 1 $name_sql $admin_only_sql $exclude_managers_sql
        ORDER BY s.nama_staff ASC
        LIMIT $per_page OFFSET $offset
    ");
    $staff = [];
    while ($res && $row = $res->fetch_assoc()) {
        $staff[] = [
            'id' => (int)$row['id'],
            'nama_staff' => $row['nama_staff'],
            'aap' => (int)$row['aap'],
            'department_name' => $row['depart_name'] ?: '—',
        ];
    }
    echo json_encode([
        'success' => true,
        'data' => $staff,
        'total' => $total,
        'page' => $page,
        'per_page' => $per_page,
        'total_pages' => max(1, (int)ceil($total / $per_page)),
    ]);
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'update_aap_superadmin' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_end_clean();
    header('Content-Type: application/json');
    $target_id = (int)($_POST['staff_id'] ?? 0);
    // staff.aap: 0 = no access, 1 = full AAP admin access (see
    // aapFetchAapLevel()/aapFetchIsSuperAdmin() in aap_lib.php). This panel
    // is Admin-only end to end and never writes 2 (Department Manager,
    // scoped to their own staff.department department(s) only via
    // aapFetchDeptManagerDepartmentIds()) - that's set from its own page
    // (admin/aap_department_managers.php) instead. Anything else posted
    // collapses to 0 rather than left as whatever garbage came in.
    $aap_val = (int)($_POST['aap'] ?? 0);
    if (!in_array($aap_val, [0, 1], true)) $aap_val = 0;

    if ($target_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid staff.']);
        exit;
    }
    $check = $conn->query("SELECT id, aap FROM staff WHERE id = $target_id AND recycle != 1");
    $check_row = $check ? $check->fetch_assoc() : null;
    if (!$check_row) {
        echo json_encode(['success' => false, 'message' => 'Staff not found.']);
        exit;
    }
    if ((int)$check_row['aap'] === 2) {
        echo json_encode(['success' => false, 'message' => 'This staff member is a Department Manager - manage that from the Department Managers page instead.']);
        exit;
    }
    if ($conn->query("UPDATE staff SET aap = $aap_val WHERE id = $target_id AND recycle != 1")) {
        echo json_encode(['success' => true, 'message' => 'Updated successfully.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    }
    exit;
}

// ---- Bank Master panel - AJAX endpoints. Lets a SuperAdmin add/rename/
// retire banks straight from the frontend instead of needing a DB script
// each time (see aap_bank_master table, aapFetchBankMasterOptions() in
// aap_lib.php - the Bank Detail dropdown on aap_add.php/aap_update.php reads
// from that same table). Soft-delete via `recycle`, same convention as
// aap_case_types, so a retired bank stays intact on historical cases that
// already reference its id.
if (isset($_GET['action']) && $_GET['action'] === 'list_banks' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    ob_end_clean();
    header('Content-Type: application/json');
    $rows = [];
    $res = $conn->query("SELECT id, bank_name, sort_order, recycle FROM aap_bank_master ORDER BY sort_order, bank_name");
    while ($res && $row = $res->fetch_assoc()) {
        $rows[] = [
            'id' => (int)$row['id'],
            'bank_name' => $row['bank_name'],
            'sort_order' => (int)$row['sort_order'],
            'recycle' => (int)$row['recycle'],
        ];
    }
    echo json_encode(['success' => true, 'data' => $rows]);
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'add_bank' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_end_clean();
    header('Content-Type: application/json');
    $bank_name = trim($_POST['bank_name'] ?? '');
    if ($bank_name === '') {
        echo json_encode(['success' => false, 'message' => 'Bank name is required.']);
        exit;
    }
    $dup = $conn->query("SELECT id FROM aap_bank_master WHERE bank_name = '" . $conn->real_escape_string($bank_name) . "' AND recycle = 0");
    if ($dup && $dup->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'That bank already exists.']);
        exit;
    }
    $max_res = $conn->query("SELECT COALESCE(MAX(sort_order), 0) m FROM aap_bank_master");
    $next_sort = ((int)$max_res->fetch_assoc()['m']) + 1;
    $stmt = $conn->prepare("INSERT INTO aap_bank_master (bank_name, sort_order, timestamp) VALUES (?, ?, ?)");
    $stmt->bind_param("sis", $bank_name, $next_sort, $now);
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Bank added.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    }
    $stmt->close();
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'rename_bank' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_end_clean();
    header('Content-Type: application/json');
    $bank_id = (int)($_POST['bank_id'] ?? 0);
    $bank_name = trim($_POST['bank_name'] ?? '');
    if ($bank_id <= 0 || $bank_name === '') {
        echo json_encode(['success' => false, 'message' => 'Invalid bank or name.']);
        exit;
    }
    $stmt = $conn->prepare("UPDATE aap_bank_master SET bank_name = ? WHERE id = ?");
    $stmt->bind_param("si", $bank_name, $bank_id);
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Bank updated.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    }
    $stmt->close();
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'toggle_bank_recycle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_end_clean();
    header('Content-Type: application/json');
    $bank_id = (int)($_POST['bank_id'] ?? 0);
    $recycle = (int)($_POST['recycle'] ?? 0) === 1 ? 1 : 0;
    if ($bank_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid bank.']);
        exit;
    }
    $stmt = $conn->prepare("UPDATE aap_bank_master SET recycle = ? WHERE id = ?");
    $stmt->bind_param("ii", $recycle, $bank_id);
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => $recycle ? 'Bank retired.' : 'Bank restored.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    }
    $stmt->close();
    exit;
}

// ---- Form Master panel - AJAX endpoints. Lets an admin configure, GLOBALLY
// (one config for every Case Type, not per-Case-Type), which of the fixed
// Case Details + Bank Detail fields on aap_add.php show at all, whether each
// is required, and its display label - see aapFormMasterFieldDefaults()/
// aapFetchFormMasterConfig()/aapSanitizeFormMasterFieldConfig()/
// aapSaveFormMasterConfig() in aap_lib.php. ----
if (isset($_GET['action']) && $_GET['action'] === 'get_form_master_config' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'config' => aapFetchFormMasterConfig($conn)]);
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'save_form_master_config' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_end_clean();
    header('Content-Type: application/json');
    $clean_json = aapSanitizeFormMasterFieldConfig($_POST['config_json'] ?? '');
    $ok = aapSaveFormMasterConfig($conn, $clean_json, $id_user);
    echo json_encode($ok
        ? ['success' => true, 'message' => 'Form Master updated.', 'config' => json_decode($clean_json, true)]
        : ['success' => false, 'message' => 'Database error: ' . $conn->error]);
    exit;
}
// Form Master's current global config, rendered directly on page load (no
// picker - there's only one config, not one per Case Type).
$form_master_config = aapFetchFormMasterConfig($conn);

// ---- Additional Fields - admin-defined custom fields (Number/Dropdown/
// Textarea), shown as rows inside the SAME Form Master table below (their
// own "Additional Fields" group) rather than a separate panel. ----

// Custom fields are rows inside the SAME Form Master table (their own
// "Additional Fields" group), not a separate panel - so one Save Form
// Master click persists both the fixed-field config AND every custom
// field's Visible(=active)/Required/Label in a single request, same as how
// the fixed fields' Visible/Required/Label were already saved together.
// Field TYPE/OPTIONS are set only at creation (add_custom_field below) -
// retire and re-add if those need to change.
if (isset($_POST['action']) && $_POST['action'] === 'save_form_master_all' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_end_clean();
    header('Content-Type: application/json');
    $clean_json = aapSanitizeFormMasterFieldConfig($_POST['config_json'] ?? '');
    $ok = aapSaveFormMasterConfig($conn, $clean_json, $id_user);

    $custom_rows = json_decode($_POST['custom_fields_json'] ?? '[]', true);
    if (is_array($custom_rows)) {
        $stmt = $conn->prepare("UPDATE aap_custom_fields SET label = ?, required = ?, recycle = ? WHERE id = ?");
        foreach ($custom_rows as $row) {
            $cf_id = (int)($row['id'] ?? 0);
            if ($cf_id <= 0) continue;
            $cf_label = trim((string)($row['label'] ?? ''));
            if ($cf_label === '') continue; // never blank out an existing field's label
            $cf_visible = !empty($row['visible']);
            $cf_required = $cf_visible && !empty($row['required']);
            $cf_recycle = $cf_visible ? 0 : 1;
            $stmt->bind_param("siii", $cf_label, $cf_required, $cf_recycle, $cf_id);
            $stmt->execute();
        }
        $stmt->close();
    }

    echo json_encode($ok
        ? ['success' => true, 'message' => 'Form Master updated.']
        : ['success' => false, 'message' => 'Database error: ' . $conn->error]);
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'add_custom_field' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_end_clean();
    header('Content-Type: application/json');
    $label = trim($_POST['label'] ?? '');
    $field_type = $_POST['field_type'] ?? '';
    $required = (int)($_POST['required'] ?? 0) === 1 ? 1 : 0;
    if ($label === '' || !in_array($field_type, ['number', 'dropdown', 'textarea', 'date'], true)) {
        echo json_encode(['success' => false, 'message' => 'Label and a valid Type are required.']);
        exit;
    }
    $options_json = null;
    if ($field_type === 'dropdown') {
        $options_json = aapSanitizeCustomFieldOptions($_POST['options'] ?? '');
        if (json_decode($options_json, true) === []) {
            echo json_encode(['success' => false, 'message' => 'A Dropdown field needs at least one option.']);
            exit;
        }
    }
    $max_res = $conn->query("SELECT COALESCE(MAX(sort_order), 0) m FROM aap_custom_fields");
    $next_sort = ((int)$max_res->fetch_assoc()['m']) + 1;
    $stmt = $conn->prepare("INSERT INTO aap_custom_fields (label, field_type, options, required, sort_order, timestamp) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssiis", $label, $field_type, $options_json, $required, $next_sort, $now);
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Field added.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    }
    $stmt->close();
    exit;
}
// Current custom field definitions, rendered directly on page load (same as
// Bank Master's own list, no AJAX GET needed for the initial render).
$custom_fields_all = aapFetchAllCustomFields($conn);

$aap_base = '../';
?>

<?php include('../aap_modern_head.php'); ?>

<div class="header">
  <b class="rtop"><b class="r1"></b><b class="r2"></b><b class="r3"></b><b class="r4"></b></b>
  <h1 class="headerH1"><img src="../img/logo.svg"> Settings (Admin)</h1>
  <b class="rbottom"><b class="r4"></b><b class="r3"></b><b class="r2"></b><b class="r1"></b></b>
</div>

<?php include('../aap_sidebar.php'); ?>

<?php if (!empty($msg)): ?>
    <div class="alpro-alert <?php echo $msg_type; ?> alpro-mt-20"><?php echo htmlspecialchars($msg); ?></div>
<?php endif; ?>

<!-- =====================  ===================== -->
<div class="alpro-box alpro-mt-20" style="text-align:left;">
    <h3 style="margin-top:0; text-align:left;">Manage AAP Admin Access</h3>

    <div class="aap-modern">
    <form onsubmit="return false;" class="sa-filter-form" style="display:flex; gap:8px; align-items:flex-end; margin-bottom:14px;">
        <div class="alpro-field" style="flex:1; max-width:220px;">
            <label style="font-size:10px; font-weight:600; color:#6c757d; text-transform:uppercase;">Staff Name</label>
            <input class="alpro-input" type="text" id="sa-filter-name" placeholder="Search name...">
        </div>
        <input class="alpro-btn alpro-btn-blue" type="button" id="sa-apply-filter" value="Apply">
        <input class="alpro-btn alpro-btn-grey" type="button" id="sa-reset-filter" value="Reset">
    </form>

    <div class="aap-card">
        <table class="alpro-table aap-sa-list-table" width="100%">
            <tr><th>Staff Name</th><th>Department</th><th>AAP Access</th><th></th></tr>
            <tbody id="sa-staff-tbody">
                <tr><td colspan="4" align="center" style="padding:15px;">Loading...</td></tr>
            </tbody>
        </table>
        <div class="sa-pager" id="sa-staff-pager"></div>
    </div>
    </div>

    <div id="sa-edit" class="aap-modern" style="display:none;">
    <div class="aap-card alpro-mt-20" style="max-width:420px;">
        <h6 class="aap-card-title" style="margin-bottom:14px;">Update Admin Access</h6>
        <p style="margin:0 0 4px;"><strong>Name:</strong> <span id="sa-info-name"></span></p>
        <p style="margin:0 0 14px;"><strong>Department:</strong> <span id="sa-info-dept"></span></p>
        <div class="alpro-field" style="margin-bottom:14px;">
            <label style="font-size:10px; font-weight:600; color:#6c757d; text-transform:uppercase;">AAP Access</label>
            <select class="alpro-input" id="sa-aap-level">
                <option value="0">No Access</option>
                <option value="1">Admin (full access)</option>
            </select>
        </div>
        <div id="sa-alert" class="alpro-alert" style="display:none; margin-bottom:10px;"></div>
        <div class="alpro-actions" style="justify-content:flex-end;">
            <button type="button" class="alpro-btn alpro-btn-grey" id="sa-cancel-btn">Cancel</button>
            <button type="button" class="alpro-btn alpro-btn-blue" id="sa-update-btn">Update</button>
        </div>
    </div>
    </div>
</div>

<!-- =====================  ===================== -->
<div class="alpro-box alpro-mt-20" style="text-align:left;">
    <h3 style="margin-top:0; text-align:left;">Bank Master</h3>
    <p class="alpro-muted" style="margin-top:-8px;">Banks listed here populate the Bank Name dropdown on Raise Case / Edit Case. Retiring a bank removes it from that dropdown but keeps it on any case that already used it.</p>

    <div class="aap-modern">
    <div class="aap-card">
        <form onsubmit="return false;" style="display:flex; gap:8px; align-items:flex-end; margin-bottom:14px;">
            <div class="alpro-field" style="flex:1; max-width:320px;">
                <label style="font-size:10px; font-weight:600; color:#6c757d; text-transform:uppercase;">New Bank Name</label>
                <input class="alpro-input" type="text" id="bank-new-name" placeholder="e.g. Bank Islam Malaysia Berhad">
            </div>
            <input class="alpro-btn alpro-btn-blue" type="button" id="bank-add-btn" value="Add Bank">
        </form>
        <div id="bank-alert" class="alpro-alert" style="display:none; margin-bottom:10px;"></div>

        <table class="alpro-table" width="100%">
            <tr><th>Bank Name</th><th style="width:120px;">Status</th><th style="width:160px;"></th></tr>
            <tbody id="bank-tbody">
                <tr><td colspan="3" align="center" style="padding:15px;">Loading...</td></tr>
            </tbody>
        </table>
    </div>
    </div>
</div>

<!-- =====================  ===================== -->
<div class="alpro-box alpro-mt-20" style="text-align:left;">
    <h3 style="margin-top:0; text-align:left;">Form Master</h3>
    <p class="alpro-muted" style="margin-top:-8px;">Control whether these fields show on Raise Case at all, whether each is required, and its display label - one setting applied to every Case Type. Customer Name/Membership ID always stay visible+required (the customer/staff search depends on both) - only their Label can be changed. The Customer/Staff toggle is fixed and not configurable here. "Additional Fields" below are brand-new fields you define yourself - add one, then control it the same way as everything else in this table.</p>

    <?php
    // Grouped purely for display (a section header row between each group's
    // fields) - saved/posted as one flat config either way. "Additional
    // Fields" isn't a fixed key list like the others - it's rendered
    // separately below from $custom_fields_all.
    $fm_groups = [
        'Customer Identity' => ['customer_name', 'customer_membership_id', 'staff_name'],
        'Case Details' => ['transaction_ref', 'calculated_value', 'value_type', 'recommended_outcome'],
        'Bank Detail' => ['bank_name', 'bank_account_number', 'bank_account_holder'],
    ];
    $fm_defaults = aapFormMasterFieldDefaults();
    $fm_locked = aapFormMasterLockedKeys();
    ?>

    <div class="aap-modern">
    <div class="aap-card">
        <!-- Add a new custom field - once added it appears as a row in the
        table below (Additional Fields group), controlled by the same
        Visible/Required/Label columns as every other field. Type/Options
        are only set here at creation - retire and re-add to change them. -->
        <form onsubmit="return false;" style="display:flex; gap:14px; align-items:flex-end; flex-wrap:wrap; margin-bottom:20px;">
            <div class="alpro-field" style="flex:2; min-width:220px;">
                <label style="font-size:10px; font-weight:600; color:#6c757d; text-transform:uppercase;">New Field Label</label>
                <input type="text" class="alpro-input" id="cf-new-label" placeholder="e.g. Serial Number">
            </div>
            <div class="alpro-field" style="flex:1; min-width:160px;">
                <label style="font-size:10px; font-weight:600; color:#6c757d; text-transform:uppercase;">Type</label>
                <select class="alpro-input" id="cf-new-type">
                    <option value="number">Number</option>
                    <option value="dropdown">Dropdown</option>
                    <option value="textarea">Textarea</option>
                    <option value="date">Date</option>
                </select>
            </div>
            <div class="alpro-field" style="flex:0 0 auto;">
                <label style="font-size:10px; font-weight:600; color:#6c757d; text-transform:uppercase; white-space:nowrap;">
                    <input type="checkbox" id="cf-new-required" style="vertical-align:middle;"> Required
                </label>
            </div>
            <div class="alpro-field" style="flex:1; min-width:140px;">
                <button type="button" class="alpro-btn alpro-btn-blue" id="cf-add-btn" style="height:38px; width:100%;">Add Field</button>
            </div>
        </form>
        <div class="alpro-field" id="cf-new-options-wrap" style="display:none; max-width:420px;">
            <label style="font-size:10px; font-weight:600; color:#6c757d; text-transform:uppercase;">Options (one per line)</label>
            <textarea class="alpro-input" id="cf-new-options" style="height:70px;" placeholder="Low&#10;Medium&#10;High"></textarea>
        </div>
        <div id="cf-add-alert" class="alpro-alert" style="display:none; margin:10px 0 16px;"></div>

        <?php
        // Display-only - every fixed Form Master field's actual HTML input
        // type (not itself configurable, unlike Additional Fields' own
        // Type picked at creation). Purely informational for this new Type
        // column; doesn't affect validation/storage anywhere.
        $fm_display_types = [
            'customer_name' => 'Text', 'customer_membership_id' => 'Text', 'staff_name' => 'Text',
            'transaction_ref' => 'Text', 'calculated_value' => 'Number', 'value_type' => 'Dropdown', 'recommended_outcome' => 'Text',
            'bank_name' => 'Dropdown', 'bank_account_number' => 'Text', 'bank_account_holder' => 'Text',
        ];
        ?>
        <table class="alpro-table" width="100%">
            <tr><th>Field</th><th style="width:110px;">Type</th><th style="width:90px;">Visible</th><th style="width:90px;">Required</th><th>Label</th></tr>
            <tbody id="fm-fields-tbody">
                <?php foreach ($fm_groups as $fm_group_name => $fm_keys): ?>
                    <tr><th colspan="5" style="background:#f7f9fb; font-size:11px; text-transform:uppercase; color:#6c757d;"><?php echo htmlspecialchars($fm_group_name); ?></th></tr>
                    <?php foreach ($fm_keys as $fm_key):
                        $fm_def = $fm_defaults[$fm_key];
                        $fm_c = $form_master_config[$fm_key];
                        $fm_is_locked = in_array($fm_key, $fm_locked, true); ?>
                        <tr data-key="<?php echo htmlspecialchars($fm_key); ?>">
                            <td><?php echo htmlspecialchars($fm_def['label']); ?></td>
                            <td class="alpro-muted"><?php echo htmlspecialchars($fm_display_types[$fm_key] ?? ''); ?></td>
                            <td align="center"><input type="checkbox" class="fm-visible" <?php echo ($fm_is_locked || $fm_c['visible']) ? 'checked' : ''; ?> <?php echo $fm_is_locked ? 'disabled' : ''; ?>></td>
                            <td align="center"><input type="checkbox" class="fm-required" <?php echo ($fm_is_locked || $fm_c['required']) ? 'checked' : ''; ?> <?php echo $fm_is_locked ? 'disabled' : ($fm_c['visible'] ? '' : 'disabled'); ?>></td>
                            <td><input type="text" class="alpro-input fm-label" value="<?php echo htmlspecialchars($fm_c['label']); ?>"></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>

                <?php if (!empty($custom_fields_all)): ?>
                    <tr><th colspan="5" style="background:#f7f9fb; font-size:11px; text-transform:uppercase; color:#6c757d;">Additional Fields</th></tr>
                    <?php foreach ($custom_fields_all as $cf): ?>
                        <tr data-custom-id="<?php echo (int)$cf['id']; ?>">
                            <td class="alpro-muted">—</td>
                            <td>
                                <?php echo htmlspecialchars(ucfirst($cf['field_type'])); ?>
                                <?php if ($cf['field_type'] === 'dropdown' && !empty($cf['options'])): ?>
                                    <div class="alpro-muted" style="font-size:11px;">Options: <?php echo htmlspecialchars(implode(', ', $cf['options'])); ?></div>
                                <?php endif; ?>
                            </td>
                            <td align="center"><input type="checkbox" class="fm-visible" <?php echo $cf['recycle'] ? '' : 'checked'; ?>></td>
                            <td align="center"><input type="checkbox" class="fm-required" <?php echo $cf['required'] ? 'checked' : ''; ?> <?php echo $cf['recycle'] ? 'disabled' : ''; ?>></td>
                            <td><input type="text" class="alpro-input fm-label" value="<?php echo htmlspecialchars($cf['label']); ?>"></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <div id="fm-alert" class="alpro-alert" style="display:none; margin:10px 0;"></div>
        <div class="alpro-actions alpro-mt-10" style="justify-content:flex-end;">
            <button type="button" class="alpro-btn alpro-btn-blue" id="fm-save-btn">Save Form Master</button>
        </div>
    </div>
    </div>
</div>

<?php $page_js = '../js/aap_settings.js'; include('../aap_footer.php'); ?>
