<?php
require_once('../../lock_adv.php');
$connect = 1;
date_default_timezone_set('Asia/Kuala_Lumpur');
include('../../common/index_adv.php');
if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not available.");
}
require_once('../aap_lib.php');

// Resolves the SuperAdmin/admin-level union used across every AAP page.
$aap_identity = aapResolveIdentity($conn, $id_user, $grade, $department);
$grade = $aap_identity['grade'];
$department = $aap_identity['department'];
$aap_dept_ids = $aap_identity['dept_ids'];
$aap_is_admin = $aap_identity['is_admin'];
$aap_is_superadmin = $aap_identity['is_superadmin'];
if (!$aap_is_admin) {
    die("Admin access only. This page documents the module's access model.");
}
$aap_base = '../';
?>

<?php include('../aap_modern_head.php'); ?>

<div class="header">
  <b class="rtop"><b class="r1"></b><b class="r2"></b><b class="r3"></b><b class="r4"></b></b>
  <h1 class="headerH1"><img src="../img/logo.svg"> Access &amp; Authorization (Admin)</h1>
  <b class="rbottom"><b class="r4"></b><b class="r3"></b><b class="r2"></b><b class="r1"></b></b>
</div>

<?php include('../aap_sidebar.php'); ?>

<div class="aap-modern">

<div class="aap-page-title-row">
    <h2 class="aap-page-title"><i class="bi bi-shield-lock"></i> Access &amp; Authorization</h2>
    <div class="aap-page-actions">
        <a href="aap_admin.php" class="alpro-btn alpro-btn-grey" style="text-decoration:none;"><i class="bi bi-arrow-left"></i> Back to Admin</a>
    </div>
</div>

<div class="aap-access-note" style="background:#e6f6ed; color:#146c37; border-color:#b7e4c7;">
    <strong>Admin, as of 2026-10-19:</strong> <code>staff.aap = 1</code> is the ONLY thing that grants AAP admin access - full stop. Grade ≥ 4, Digital Innovation (dept 16) membership, and the OKR/ATEM SuperAdmin union (<code>staff.okr</code>/<code>staff.atem</code>) used to also grant it, mirroring how OKR and ATEM union each other's flag - all three were removed, since none of them have anything to do with AAP specifically. Re-queried independently at every entry point, not session-cached.
</div>

<div class="aap-bento">

    <div class="aap-bento-item aap-span-12">
        <div class="aap-card aap-access-section">
            <h6 class="aap-card-title"><i class="bi bi-diagram-3"></i> The two inputs every check is built from</h6>
            <p class="aap-card-hint" style="margin-top:10px;">Every access decision in this module combines exactly two pieces of session state, both read from the outer <code>odb</code> app's shared <code>staff</code> table:</p>
            <ul style="margin: 0; padding-left: 20px; font-size: 14px; line-height: 1.9;">
                <li><strong><code>staff.grade</code></strong> (0–5) — determines Executive vs Manager tier eligibility on the Operations approval gate.</li>
                <li><strong><code>staff.department</code></strong> (comma-separated department ids) — determines which functional role applies: Operations or Customer Support.</li>
            </ul>
        </div>
    </div>

    <div class="aap-bento-item aap-span-12">
        <div class="aap-card aap-access-section">
            <h6 class="aap-card-title"><i class="bi bi-people"></i> Department-keyed roles</h6>
            <table class="alpro-table aap-access-table" width="100%">
                <tr><th>Constant</th><th>Dept ID</th><th>Meaning</th></tr>
                <tr>
                    <td><code>AAP_DEPT_OPERATION</code></td>
                    <td>13</td>
                    <td>Sole execution authority — tags/confirms physical returns, executes cases, closes cases.</td>
                </tr>
                <tr>
                    <td><code>AAP_DEPT_CUSTOMER_SUPPORT</code></td>
                    <td>27</td>
                    <td>Self-handle exception: a Customer Support staff member can always act on a case they raised themselves, at any value, without needing to be on that Case Type's Approval Staff Tier list — see <code>aapCanApprove()</code> below.</td>
                </tr>
            </table>
        </div>
    </div>

    <div class="aap-bento-item aap-span-12">
        <div class="aap-card aap-access-section">
            <h6 class="aap-card-title"><i class="bi bi-key"></i> Admin — <code>aapIsAdmin()</code></h6>
            <p style="margin:10px 0 0;"><code>staff.aap = 1</code>, full stop - as of 2026-10-19, grade and department have no bearing on admin status. Bypasses every other gate below.</p>
        </div>
    </div>

    <div class="aap-bento-item aap-span-12">
        <div class="aap-card aap-access-section">
            <h6 class="aap-card-title"><i class="bi bi-shield-lock"></i> <code>staff.aap</code> has three levels</h6>
            <p style="margin:10px 0 0;"><code>staff.aap</code> is 0 (no access), 1 (full AAP admin access — every admin page/feature, nothing held back), or 2 (Department Manager — see below, now a legacy/inert level). Only level 1 counts as admin (<code>aapFetchIsSuperAdmin()</code>/<code>aapIsAdmin()</code>); level 2 deliberately does not, even though the column is non-zero — it's a narrower, department-scoped role, not admin. As of 2026-10-19 this is the ONLY thing either function checks - grade, department, and the old OKR/ATEM union were all removed.</p>
            <p style="margin:8px 0 0;">Level 1 is set from <code>admin/aap_settings.php</code>'s AAP Access panel; level 2 is still set from its own page, <code>admin/aap_department_managers.php</code> (Grant Access panel, admin-only) — but as of 2026-10-19, holding level 2 no longer grants anything on <code>admin/aap_admin.php</code>, <code>admin/aap_grouping_master.php</code>, or <code>admin/aap_staff_assignments.php</code>; those three are strictly admin-only now (see below). The grant panel itself still works (read-only view for the manager, grant/revoke for admin) in case the role is reinstated later.</p>
            <p style="margin:8px 0 0;"><code>admin/aap_grouping_master.php</code> (Approval Unit Master) and <code>admin/aap_staff_assignments.php</code> no longer accept a Department Manager - admin-only throughout, same as <code>admin/aap_admin.php</code>'s Case Type Registry. Grade ≥ 4 does not get a company-wide pass on these three pages either any more (<code>aapCanManageAllDepartments()</code> is NOT used there - each page hardcodes <code>$aap_can_manage_all_depts = $aap_is_superadmin</code> instead); that function is still used elsewhere (e.g. <code>aap_add.php</code>'s Case Type picker when raising a case) where grade ≥ 4 still applies.</p>
        </div>
    </div>

    <div class="aap-bento-item aap-span-12">
        <div class="aap-card aap-access-section">
            <h6 class="aap-card-title"><i class="bi bi-person-badge"></i> Department Managers (<code>staff.aap = 2</code>) — legacy, no longer grants anything</h6>
            <p style="margin:10px 0 0;">As of 2026-10-19, <code>staff.aap = 2</code> no longer unlocks Case Types, Approval Unit Groups, or Staff Assignments for anyone - those three pages check <code>$aap_is_superadmin</code> alone, full stop. <code>admin/aap_department_managers.php</code>'s Grant Access panel still exists and still writes the same <code>staff.aap</code> column (admin-only), and a manager can still open that one page to see their own granted department(s) read-only, but the grant itself is currently inert everywhere else in the module.</p>
        </div>
    </div>

    <div class="aap-bento-item aap-span-12">
        <div class="aap-card aap-access-section">
            <h6 class="aap-card-title"><i class="bi bi-code-square"></i> Shared checks (<code>aap_lib.php</code>)</h6>
            <table class="alpro-table aap-access-table" width="100%">
                <tr><th>Function</th><th>Rule</th></tr>
                <tr><td><code>aapIsOperations()</code></td><td>Member of Operations (dept 13).</td></tr>
                <tr><td><code>aapIsCustomerSupport()</code></td><td>Member of Customer Support (dept 27).</td></tr>
                <tr><td><code>aapIsAdmin()</code></td><td><code>staff.aap === 1</code>, full stop. As of 2026-10-19, grade ≥ 4, Digital Innovation (dept 16), and the OKR/ATEM SuperAdmin union no longer count - removed since none of them have anything to do with AAP specifically.</td></tr>
                <tr><td><code>aapFetchIsSuperAdmin()</code></td><td>Returns true only if <code>staff.aap === 1</code> for the current user. Used to also union in <code>staff.okr</code>/<code>atem</code> = 1 - removed 2026-10-19, same reasoning as <code>aapIsAdmin()</code>.</td></tr>
                <tr><td><code>aapFetchAapLevel()</code></td><td>Returns the current user's raw <code>staff.aap</code> value (0, 1, or 2).</td></tr>
                <tr><td><code>aapCanExecute()</code></td><td>As of 2026-10-19, structurally identical to <code>aapCanApprove()</code> below, just gating Execute instead of Approve. Admin, or - if the Case Type has "HOD Approval Only" ticked for Level 3 - only the HOD(s) of whichever department actually raised THIS case may execute, at any value. Otherwise: explicitly on the Case Type's Execution Staff Tier list (Level 3, <code>admin/aap_admin.php</code>'s Case Type form, <code>aap_case_type_staff_tiers</code> <code>section='execution'</code>) with a tier covering the case's approved value. No "Operations department" fallback any more when nothing's configured - a Case Type with no Level 3 roster and no HOD-Only set has no eligible non-admin executor until one is set up. (Earlier the same day this was briefly a single <code>execution_department_id</code> department-only picker, before being made a full mirror of Level 2; that column no longer exists.)</td></tr>
                <tr><td><code>aapCanManageAllDepartments()</code></td><td>Grade ≥ 4 or admin. As of 2026-10-19 this is <strong>not</strong> used by the three admin/ pages any more (<code>admin/aap_admin.php</code>, <code>admin/aap_grouping_master.php</code>, <code>admin/aap_staff_assignments.php</code> each hardcode <code>$aap_can_manage_all_depts = $aap_is_superadmin</code> instead - admin-only, grade ≥ 4 no longer gets a company-wide pass there). Still used elsewhere for its original purpose, e.g. <code>aap_add.php</code>'s Case Type picker when raising a case.</td></tr>
                <tr><td><code>aapGetStaffThreshold()</code></td><td>Reads the current user's personal RM approval ceiling from <code>aap_staff_thresholds</code>. Returns <code>null</code> for unlimited, <code>false</code> for no row (no approval rights at all), or the numeric ceiling. Superseded by the per-Case-Type Staff Tier model below for approval decisions, but still used for the "Staff in Department" ceiling panel.</td></tr>
                <tr><td><code>aapCanApprove()</code></td><td>Admin, or - if the Case Type has "HOD Approval Only" ticked for Level 2 - only the HOD(s) (Alpro's own <code>iidas_department</code> list, read live) of whichever department actually raised THIS case (<code>aap_cases.requester_department_id</code>) may approve, at any value. Resolved fresh per case, not a department fixed on the Case Type - the same Case Type can route to a different HOD for each case depending on who raised it (e.g. a "Customer Refund" Case Type owned by Operation at Level 1 still routes to Customer Support's HOD for a case Customer Support raised). For Outlet specifically, every staff member with <code>staff.status_semasa = 'Area Manager'</code> is also eligible, added alongside Outlet's own <code>iidas_department</code> HOD(s) rather than instead of them - see <code>aapFetchDepartmentHodStaffIds()</code>. Otherwise: explicitly on the Case Type's Approval Staff Tier list (Level 2, <code>aap_admin.php</code>'s Case Type form) with a tier (Unlimited / Tier 1 &gt; RM5,000 / Tier 2 &le; RM5,000) covering the case's value. Replaced the old grade-based Operations tier / CS Level 1/2 / BU sign-off department rule entirely. Customer Support self-handling their own case is checked separately in <code>aap_update.php</code>, not inside this function. <strong>Until 2026-10-19</strong> there was also a Level 3 Exclusion list that overrode a tier match and a Level 3 HOD-Only mode that took priority over Level 2's - both removed the same day Level 3 was repurposed into its own independent Execution gate (see <code>aapCanExecute()</code> above, now structurally the Level-3 twin of this function rather than something that ever overrides it); the exclusion list had zero rows in production/dev at the time, so nothing relied on it.</td></tr>
                <tr><td><code>aapScopeWhere()</code></td><td>Visibility filter for case lists — Admin/Operations see every case; everyone else sees cases they personally raised, or whose Case Type belongs to their own department(s) - i.e. what the case is actually about, not who happened to submit it (so a CS/Operations/admin staff member raising a case on behalf of another department's Fixit ticket doesn't hide it from that department).</td></tr>
            </table>
        </div>
    </div>

    <div class="aap-bento-item aap-span-12">
        <div class="aap-card aap-access-section">
            <h6 class="aap-card-title"><i class="bi bi-file-earmark-code"></i> Page-level gates (<code>aap_update.php</code> / <code>aap_delete.php</code>)</h6>
            <p class="aap-card-hint" style="margin-top:10px;">Each combines the shared checks above with the case's current state (draft/open/pending/etc.) and issuer identity — these aren't reusable functions, they're computed per-page from the current case row.</p>
            <table class="alpro-table aap-access-table" width="100%">
                <tr><th>Flag</th><th>Gates</th></tr>
                <tr><td><code>$can_view</code></td><td>Who can open the case detail page at all.</td></tr>
                <tr><td><code>$can_edit_case</code></td><td>Case Details form + Add Evidence — issuer or admin, and case not locked/further along (draft or open, approval still pending, Verification Reference not past "pending").</td></tr>
                <tr><td><code>$can_open_case</code></td><td>Moving a Draft into the active approval workflow — issuer or admin, all required fields filled.</td></tr>
                <tr><td><code>$can_tag_physical</code></td><td>Tag Item Returned step — issuer, Operations, or admin.</td></tr>
                <tr><td><code>$can_confirm_physical</code></td><td>Confirm Receipt at ACMM — Operations or admin only.</td></tr>
                <tr><td><code>$can_execute</code></td><td>Execute &amp; Close Case — same as <code>aapCanExecute()</code>.</td></tr>
                <tr><td><code>$can_close</code></td><td>Notify Requester &amp; Close Case — admin, Operations, or the original issuer.</td></tr>
                <tr><td><code>$can_void</code></td><td>Void Case (<code>aap_delete.php</code>) — case still draft or open with approval pending, issuer or admin.</td></tr>
            </table>
        </div>
    </div>

</div>
</div>

<?php include('../aap_footer.php'); ?>
