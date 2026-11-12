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
if ((int)$grade < 1 && !$aap_is_admin) {
    die("You do not have access to this module.");
}

// ---- Filters ----
$f_status      = isset($_GET['case_status']) ? trim($_GET['case_status']) : '';
$f_case_type   = isset($_GET['case_type_id']) && $_GET['case_type_id'] !== '' ? (int)$_GET['case_type_id'] : null;
$f_outlet      = isset($_GET['outlet_id']) && $_GET['outlet_id'] !== '' ? (int)$_GET['outlet_id'] : null;
$f_physical    = isset($_GET['physical_confirm_status']) ? trim($_GET['physical_confirm_status']) : '';
$f_approval    = isset($_GET['approval_status']) ? trim($_GET['approval_status']) : '';
$f_date        = isset($_GET['raised_date']) ? trim($_GET['raised_date']) : '';
$f_closed_date = isset($_GET['closed_date']) ? trim($_GET['closed_date']) : '';
$f_search      = isset($_GET['q']) ? trim($_GET['q']) : '';

// A case starts directly at 'open' (never 'draft') since cases now skip
// straight to Verification/Approval - the Case Status filter and Open Cases
// stat card below no longer carry a separate Investigation/draft option.
// aapCaseDisplayStatus() still recognizes 'draft' for any historical case
// that predates this, just nothing new is ever created that way.
//
// Split into a status-only clause and a "every other filter" clause so the
// stat strip below can share the latter (Case Type/Outlet/Verification
// Required/Approval/dates/search all narrow every tile) while deliberately
// leaving the former out (each tile already IS one specific status - if the
// Case Status filter also applied here, picking one status would zero out
// every other tile instead of showing where the filtered cases currently
// sit).
$status_where = '';
if ($f_status === 'rejected') {
    // Voided and Rejected are shown as one "Rejected" status (see
    // aapCaseDisplayStatus in aap_lib.php) - filtering by it must catch both.
    $status_where = " AND c.case_status IN ('rejected', 'voided')";
} elseif ($f_status === 'closed') {
    $status_where = " AND c.case_status = 'closed'";
} elseif ($f_status === 'closing') {
    // Matches aapCaseDisplayStatus's "Closing" branch - executed
    // but not yet notified/closed via the separate Close action.
    $status_where = " AND c.case_status = 'executed'";
} elseif ($f_status === 'confirming') {
    // Matches aapCaseDisplayStatus's "Verification" branch - one
    // of the three sub-statuses 'open' resolves into.
    $status_where = " AND c.case_status = 'open' AND c.physical_confirm_required = 1 AND c.physical_confirm_status IN ('pending', 'tagged')";
} elseif ($f_status === 'executing') {
    // Matches aapCaseDisplayStatus's "Execute" branch.
    $status_where = " AND c.case_status = 'open' AND c.approval_status IN ('approved', 'corrected') AND c.execution_status = 'pending'";
} elseif ($f_status === 'active') {
    // Matches aapCaseDisplayStatus's "Active" branch - ready (physical step
    // done, or none needed) but not yet submitted for approval.
    $status_where = " AND c.case_status = 'open'
                AND NOT (c.physical_confirm_required = 1 AND c.physical_confirm_status IN ('pending', 'tagged'))
                AND c.approval_status = 'pending' AND c.submitted_for_approval = 0";
} elseif ($f_status === 'pending_approval') {
    // Matches aapCaseDisplayStatus's "Approval" branch - whatever
    // 'open' case is left once confirming/executing/active are excluded.
    $status_where = " AND c.case_status = 'open'
                AND NOT (c.physical_confirm_required = 1 AND c.physical_confirm_status IN ('pending', 'tagged'))
                AND NOT (c.approval_status IN ('approved', 'corrected') AND c.execution_status = 'pending')
                AND NOT (c.approval_status = 'pending' AND c.submitted_for_approval = 0)";
} elseif ($f_status !== '' && in_array($f_status, ['open', 'draft'], true)) {
    $status_where = " AND c.case_status = '" . $conn->real_escape_string($f_status) . "'";
}

$common_filters = '';
if ($f_case_type !== null) {
    $common_filters .= " AND c.case_type_id = " . $f_case_type;
}
if ($f_outlet !== null) {
    // No join in this $where's own query (the COUNT below runs against
    // aap_cases alone) - a subquery on the linked Fixit ticket works in
    // both places instead of relying on aapCaseSelectSql's joins.
    $common_filters .= " AND c.fixit_record_id IN (SELECT id FROM fixit_record WHERE outlet = " . $f_outlet . ")";
}
if ($f_physical === 'required') {
    $common_filters .= " AND c.physical_confirm_required = 1";
} elseif ($f_physical === 'not_required') {
    $common_filters .= " AND c.physical_confirm_required = 0";
}
if ($f_approval === 'approved') {
    // Corrected is shown as one "Approved" status (see aapApprovalStatusDisplay
    // in aap_lib.php) - filtering by it must catch both.
    $common_filters .= " AND c.approval_status IN ('approved', 'corrected')";
} elseif ($f_approval !== '' && in_array($f_approval, ['pending','rejected'], true)) {
    $common_filters .= " AND c.approval_status = '" . $conn->real_escape_string($f_approval) . "'";
}
if ($f_date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_date)) {
    $common_filters .= " AND DATE(c.timestamp) = '" . $conn->real_escape_string($f_date) . "'";
}
if ($f_closed_date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_closed_date)) {
    $common_filters .= " AND DATE(c.closed_at) = '" . $conn->real_escape_string($f_closed_date) . "'";
}
if ($f_search !== '') {
    $s = $conn->real_escape_string($f_search);
    $common_filters .= " AND (c.case_ref LIKE '%$s%' OR c.transaction_ref LIKE '%$s%' OR c.customer_membership_id LIKE '%$s%' OR c.customer_name LIKE '%$s%')";
}

$where = aapScopeWhere($id_user, $aap_dept_ids, $aap_is_admin) . $status_where . $common_filters;

$filter_case_types = aapFetchCaseTypes($conn, true);
$filter_outlets = aapFetchCaseOutletOptions($conn, aapScopeWhere($id_user, $aap_dept_ids, $aap_is_admin));

// ---- Queue-position stat strip - reflects every filter except Case Status
// itself (see comment above $status_where). ----
$stat_where = aapScopeWhere($id_user, $aap_dept_ids, $aap_is_admin) . $common_filters;
$stats = ['pending_physical' => 0, 'pending_active' => 0, 'pending_approval' => 0, 'pending_execution' => 0, 'pending_close' => 0, 'closed_today' => 0];
// case_status = 'open' matters here - a case can be voided/rejected before
// ever being tagged (physical_confirm_status is left untouched by Void/
// Reject), which would otherwise keep inflating this tile forever for a
// case that's actually terminal, not really awaiting verification.
$res = $conn->query("SELECT COUNT(*) c FROM aap_cases c WHERE $stat_where AND case_status = 'open' AND physical_confirm_status IN ('pending','tagged')");
$stats['pending_physical'] = $res ? (int)$res->fetch_assoc()['c'] : 0;
$res = $conn->query("SELECT COUNT(*) c FROM aap_cases c WHERE $stat_where AND case_status = 'open' AND physical_confirm_status IN ('not_required','confirmed') AND approval_status = 'pending' AND submitted_for_approval = 0");
$stats['pending_active'] = $res ? (int)$res->fetch_assoc()['c'] : 0;
$res = $conn->query("SELECT COUNT(*) c FROM aap_cases c WHERE $stat_where AND case_status = 'open' AND physical_confirm_status IN ('not_required','confirmed') AND approval_status = 'pending' AND submitted_for_approval = 1");
$stats['pending_approval'] = $res ? (int)$res->fetch_assoc()['c'] : 0;
$res = $conn->query("SELECT COUNT(*) c FROM aap_cases c WHERE $stat_where AND approval_status IN ('approved','corrected') AND execution_status = 'pending'");
$stats['pending_execution'] = $res ? (int)$res->fetch_assoc()['c'] : 0;
$res = $conn->query("SELECT COUNT(*) c FROM aap_cases c WHERE $stat_where AND case_status = 'executed'");
$stats['pending_close'] = $res ? (int)$res->fetch_assoc()['c'] : 0;
$res = $conn->query("SELECT COUNT(*) c FROM aap_cases c WHERE $stat_where AND case_status = 'closed'");
$stats['closed_today'] = $res ? (int)$res->fetch_assoc()['c'] : 0;

// ---- List (paginated) ----
$limit = 20;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

$count_res = $conn->query("SELECT COUNT(*) c FROM aap_cases c WHERE $where");
$total_rows = $count_res ? (int)$count_res->fetch_assoc()['c'] : 0;
$total_pages = max(1, (int)ceil($total_rows / $limit));
// Clamp to the last valid page - otherwise a stale ?page= from before a
// filter/page-size change (e.g. fewer total pages now) requests an OFFSET
// past the end of the result set and silently returns nothing.
$page = min($page, $total_pages);
$offset = ($page - 1) * $limit;

$f_sort = isset($_GET['sort']) && in_array($_GET['sort'], ['timestamp', 'closed_at'], true) ? $_GET['sort'] : 'timestamp';
$f_dir  = isset($_GET['dir']) && $_GET['dir'] === 'asc' ? 'asc' : 'desc';
$list_sql = aapCaseSelectSql($where, "c.$f_sort $f_dir") . " LIMIT $limit OFFSET $offset";
$list_res = $conn->query($list_sql);

// Sortable "Raised"/"Closed" column headers - clicking toggles asc/desc for
// that column (defaulting to desc first), keeping every other active filter.
function aapSortHeaderLink($column, $label, $f_sort, $f_dir) {
    $qs = $_GET; unset($qs['page']);
    $next_dir = ($f_sort === $column && $f_dir === 'desc') ? 'asc' : 'desc';
    $qs['sort'] = $column;
    $qs['dir'] = $next_dir;
    $arrow = ($f_sort === $column) ? ($f_dir === 'asc' ? ' ▲' : ' ▼') : '';
    return '<a href="?' . htmlspecialchars(http_build_query($qs)) . '" style="color:inherit; text-decoration:none;">' . htmlspecialchars($label) . $arrow . '</a>';
}

// "Incoming from Fixit" tab removed (2026-10-22) - every AAP-linked
// category that matters now has an Instruction for User list, so Fixit
// reports under them auto-raise straight into the queue above
// (aapCreateCaseFromFixitRequiredInfo() in aap_lib.php, called from
// fixit/add_report.php) and never need manual review here. A category
// linked to a Case Type with no list would still be a dead end for this
// page's gone, so set one up first if that's ever needed again - see
// aapFetchIncomingFixitTickets()/aapCountIncomingFixitTickets()/
// aapIncomingFixitWhereSql() in aap_lib.php, kept but now unused here.
$active_tab = 'queue';
?>

<?php include('aap_modern_head.php'); ?>

<div class="header">
  <b class="rtop"><b class="r1"></b><b class="r2"></b><b class="r3"></b><b class="r4"></b></b>
  <h1 class="headerH1"><img src="img/logo.svg"> Case Queue</h1>
  <b class="rbottom"><b class="r4"></b><b class="r3"></b><b class="r2"></b><b class="r1"></b></b>
</div>

<?php include('aap_sidebar.php'); ?>

<div class="aap-modern">

<p class="aap-module-tag"><i class="bi bi-shield-check"></i> AAP — Alpro Approval Protocol</p>

<div class="aap-card" style="padding: 0;">
    <div class="aap-tabs" style="padding: 6px 20px 0; border-bottom-color: #e9ecef;">
        <button type="button" class="aap-tab-btn <?php echo $active_tab === 'queue' ? 'active' : ''; ?>" data-tab="queue">Case Queue <span class="aap-tab-count"><?php echo number_format($total_rows); ?></span></button>
    </div>

<div id="tab-queue" class="aap-tab-panel <?php echo $active_tab === 'queue' ? 'active' : ''; ?>">

    <div style="padding: 20px;">
        <form method="get" action="" class="aap-filter-compact">
            <div class="alpro-grid">
                <div class="alpro-field">
                    <label>Case Status</label>
                    <select class="alpro-input" name="case_status">
                        <option value="">All Statuses</option>
                        <?php foreach ([
                            'confirming' => 'Verification',
                            'active' => 'Active',
                            'pending_approval' => 'Approval',
                            'executing' => 'Execute',
                            'closing' => 'Closing in Progress',
                            'rejected' => 'Rejected',
                            'closed' => 'Closed',
                        ] as $k => $v): ?>
                            <option value="<?php echo $k; ?>" <?php echo ($f_status === $k) ? 'selected' : ''; ?>><?php echo $v; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="alpro-field">
                    <label>Case Type</label>
                    <select class="alpro-input" name="case_type_id">
                        <option value="">All Case Types</option>
                        <?php foreach ($filter_case_types as $ct): ?>
                            <option value="<?php echo $ct['id']; ?>" <?php echo ($f_case_type === (int)$ct['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($ct['case_type_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="alpro-field">
                    <label>Outlet</label>
                    <select class="alpro-input" name="outlet_id">
                        <option value="">All Outlets</option>
                        <?php foreach ($filter_outlets as $ol): ?>
                            <option value="<?php echo $ol['id']; ?>" <?php echo ($f_outlet === (int)$ol['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($ol['code']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="alpro-field">
                    <label>Verification Reference</label>
                    <select class="alpro-input" name="physical_confirm_status">
                        <option value="">All</option>
                        <option value="required" <?php echo ($f_physical === 'required') ? 'selected' : ''; ?>>Required</option>
                        <option value="not_required" <?php echo ($f_physical === 'not_required') ? 'selected' : ''; ?>>Not Required</option>
                    </select>
                </div>
                <div class="alpro-field">
                    <label>Approval</label>
                    <select class="alpro-input" name="approval_status">
                        <option value="">All</option>
                        <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $k => $v): ?>
                            <option value="<?php echo $k; ?>" <?php echo ($f_approval === $k) ? 'selected' : ''; ?>><?php echo $v; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="alpro-field">
                    <label>Date Raised</label>
                    <input class="alpro-input" type="date" name="raised_date" value="<?php echo htmlspecialchars($f_date); ?>">
                </div>
                <div class="alpro-field">
                    <label>Date Closed</label>
                    <input class="alpro-input" type="date" name="closed_date" value="<?php echo htmlspecialchars($f_closed_date); ?>">
                </div>
                <div class="alpro-field" style="grid-column: 1 / -1;">
                    <label>Search (Ref / Transaction / Membership ID)</label>
                    <input class="alpro-input" type="text" name="q" value="<?php echo htmlspecialchars($f_search); ?>" placeholder="AAP-000123...">
                </div>
                <div class="alpro-actions" style="grid-column: 1 / -1; justify-content:flex-end;">
                    <input class="alpro-btn alpro-btn-blue" type="submit" value="Filter">
                    <a href="index.php" class="alpro-btn alpro-btn-grey" style="text-decoration:none;">Reset</a>
                </div>
            </div>
        </form>

        <div class="aap-stats" style="margin-top:18px;">
            <!-- No "Open Cases"/"In Progress" tile - case_status stays
            'open' throughout Verification/Active/Approval/Execute (see
            aapCaseDisplayStatus() in aap_lib.php), so that tile was always
            just the total of the four tiles below it, never its own
            distinct bucket - removed as redundant rather than relabeled. -->
            <div class="aap-stat">
                <h3>Verification</h3>
                <div class="value" style="color:#f39c12;"><?php echo number_format($stats['pending_physical']); ?></div>
            </div>
            <div class="aap-stat">
                <h3>Active</h3>
                <div class="value" style="color:#6c5ce7;"><?php echo number_format($stats['pending_active']); ?></div>
            </div>
            <div class="aap-stat">
                <h3>Approval</h3>
                <div class="value" style="color:#fd7e14;"><?php echo number_format($stats['pending_approval']); ?></div>
            </div>
            <div class="aap-stat">
                <h3>Execute</h3>
                <div class="value" style="color:#0dcaf0;"><?php echo number_format($stats['pending_execution']); ?></div>
            </div>
            <div class="aap-stat">
                <h3>Closing in Progress</h3>
                <div class="value" style="color:#20c997;"><?php echo number_format($stats['pending_close']); ?></div>
            </div>
            <div class="aap-stat">
                <h3>Closed</h3>
                <div class="value" style="color:#6c757d;"><?php echo number_format($stats['closed_today']); ?></div>
            </div>
        </div>

        <div style="border-top: 1px solid #e9ecef; margin: 18px 0 0; padding-top: 18px;">
        <form method="post" action="aap_export_cases.php" target="_blank" id="export-form">
        <div style="display:flex; justify-content:flex-end; gap:8px; margin-bottom:10px;">
            <button type="submit" class="alpro-btn alpro-btn-grey" id="export-selected-btn" disabled><i class="bi bi-download"></i> Export</button>
        </div>
        <table class="alpro-table" width="100%">
            <tr>
                <th style="width:32px;"><input type="checkbox" id="export-select-all"></th>
                <th>Case Ref</th>
                <th>Case Type</th>
                <th>Report</th>
                <th style="text-align:center;">Verification Reference</th>
                <th>Approval</th>
                <th>Status</th>
                <th><?php echo aapSortHeaderLink('timestamp', 'Raised', $f_sort, $f_dir); ?></th>
                <th><?php echo aapSortHeaderLink('closed_at', 'Closed', $f_sort, $f_dir); ?></th>
                <th>Action</th>
            </tr>
            <?php if ($list_res && $list_res->num_rows > 0): ?>
                <?php while ($row = $list_res->fetch_assoc()): ?>
                <tr>
                    <td><input type="checkbox" class="export-row-checkbox" name="case_ids[]" value="<?php echo (int)$row['id']; ?>"></td>
                    <td class="alpro-mono">
                        <?php echo htmlspecialchars($row['case_ref']); ?>
                        <?php if (!empty($row['fixit_record_id'])): ?>
                            <br><a href="<?php echo htmlspecialchars(aapFixitTicketUrl($row['fixit_record_id'])); ?>" target="_blank" style="font-size:10px;">Fixit F<?php echo (int)$row['fixit_record_id']; ?></a>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php echo htmlspecialchars($row['case_type_name']); ?>
                        <?php $outlet_label = $row['fixit_outlet_code'] ?: $row['case_type_department_name']; ?>
                        <?php if (!empty($outlet_label)): ?>
                            <br><span class="alpro-muted" style="font-size:11px; color:#6c757d;"><?php echo htmlspecialchars($outlet_label); ?></span>
                        <?php endif; ?>
                    </td>
                    <?php // Report title straight from the linked Fixit ticket (fixit_record.report). ?>
                    <td><?php echo $row['fixit_report'] !== null && $row['fixit_report'] !== '' ? htmlspecialchars(stripslashes($row['fixit_report'])) : '—'; ?></td>
                    <td style="text-align:center;">
                        <?php list($pc_icon, $pc_color) = aapPhysicalStatusIcon($row['physical_confirm_status']); ?>
                        <i class="bi <?php echo $pc_icon; ?>" style="color:<?php echo $pc_color; ?>; font-size:16px;" title="<?php echo htmlspecialchars(aapPhysicalStatusLabel($row['physical_confirm_status'])); ?>"></i>
                    </td>
                    <td>
                        <?php $row_approval_display = aapApprovalStatusDisplay($row['approval_status']); ?>
                        <span class="alpro-badge alpro-badge-<?php echo $row_approval_display['slug']; ?>"><?php echo htmlspecialchars($row_approval_display['label']); ?></span>
                        <?php if ($row['approval_tier']): ?>
                            <span class="alpro-badge alpro-badge-<?php echo $row['approval_tier']; ?>"><?php echo ucfirst($row['approval_tier']); ?></span>
                        <?php endif; ?>
                    </td>
                    <?php $row_display_status = aapCaseDisplayStatus($row); ?>
                    <td><span class="alpro-badge alpro-badge-<?php echo $row_display_status['slug']; ?>"><?php echo htmlspecialchars($row_display_status['label']); ?></span></td>
                    <td><?php echo date('d-m-Y', strtotime($row['timestamp'])); ?></td>
                    <td><?php echo $row['closed_at'] ? date('d-m-Y', strtotime($row['closed_at'])) : '—'; ?></td>
                    <td><a href="aap_update.php?id=<?php echo $row['id']; ?>" class="alpro-btn alpro-btn-blue" style="text-decoration:none; padding:4px 10px; font-size:12px; display:inline-flex; align-items:center; justify-content:center;" title="Open"><i class="bi bi-box-arrow-up-right"></i></a></td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="10" align="center" style="padding: 15px;">No cases found for the current filter.</td>
                </tr>
            <?php endif; ?>
        </table>
        </form>
        </div>
    </div>

    <?php if ($total_pages > 1): ?>
    <div style="padding: 0 20px 20px;">
    <div style="display:flex; justify-content:center; gap:4px; margin-top:8px; flex-wrap:wrap;">
        <?php
        $qs = $_GET; unset($qs['page']);
        $base_qs = http_build_query($qs);
        for ($i = 1; $i <= $total_pages; $i++):
            $active = ($i == $page) ? 'background:#2980b9;color:white;border-color:#2980b9;' : 'background:white;color:#333;';
        ?>
            <a href="?<?php echo $base_qs; ?>&page=<?php echo $i; ?>" style="padding:3px 9px; border:1px solid #ddd; border-radius:4px; text-decoration:none; font-size:11px;<?php echo $active; ?>"><?php echo $i; ?></a>
        <?php endfor; ?>
    </div>
    <div style="text-align:center; font-size:11px; color:#7f8c8d; margin-top:4px;">
        Page <?php echo $page; ?> of <?php echo $total_pages; ?> &nbsp;|&nbsp; <?php echo $total_rows; ?> total cases
    </div>
    </div>
    <?php else: ?>
    <div style="padding-bottom: 4px;"></div>
    <?php endif; ?>

</div>

</div>
</div>

<?php $page_js = 'js/index.js'; include('aap_footer.php'); ?>
