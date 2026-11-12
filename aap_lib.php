<?php
/**
 * AAP shared helpers — query builders, scope/permission logic, formatters.
 * Required by every page in this module (never routed to directly).
 */
require_once __DIR__ . '/nas_config.php';

// Department IDs (staff_department) this module keys off:
define('AAP_DEPT_OPERATION', 13);          // "Operation" — sole execution authority
define('AAP_DEPT_CUSTOMER_SUPPORT', 27);    // "Customer Support" — CS-tier approval gate
define('AAP_DEPT_OUTLET', 1);               // "Outlet" — HOD Approval Only also includes Area Managers here, see aapFetchDepartmentHodStaffIds()
// "AAP" — a synthetic wildcard department (no real staff belong to it) added
// 2026-10-19 so a Fixit reporter can pick Department = AAP directly instead
// of needing their own department to have pre-registered a linked Fixit
// Category for each Case Type. When a Fixit report's department_id is this
// value: aap_add.php shows every AAP Case Type (not just one department's)
// and skips the department-match check on submit. Also linked into
// fixit_department (id 38) so it appears in Fixit's own Department dropdown
// - see aapSyncFixitCategoryForCaseType() below for how Case Types get
// mirrored into fixit_category under this department.
define('AAP_FIXIT_WILDCARD_DEPARTMENT_ID', 48);

function aapDeptIdsFromCsv($csv) {
    if (empty($csv)) return [];
    $parts = explode(',', $csv);
    $ids = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '' && is_numeric($p)) $ids[] = (int)$p;
    }
    return $ids;
}

// Every department Case Types can be scoped to (staff_department, the outer
// app's shared table - no separate aap_department table). Includes
// departments with no Case Type yet, since aap_admin.php uses this to let an
// admin assign a first Case Type to a department - see
// aapFetchDepartmentsWithCaseTypes() for the requester-facing subset.
function aapFetchDepartments($conn) {
    $rows = [];
    $res = $conn->query("SELECT id, depart_name FROM staff_department ORDER BY depart_name");
    while ($res && $row = $res->fetch_assoc()) $rows[] = $row;
    return $rows;
}

// Departments that actually have at least one active Case Type - used by the
// Department picker on aap_add.php so requesters never see a department with
// nothing behind it.
function aapFetchDepartmentsWithCaseTypes($conn) {
    $rows = [];
    $res = $conn->query("
        SELECT DISTINCT sd.id, sd.depart_name
        FROM staff_department sd
        INNER JOIN aap_case_types ct ON ct.department_id = sd.id AND ct.recycle = 0
        ORDER BY sd.depart_name
    ");
    while ($res && $row = $res->fetch_assoc()) $rows[] = $row;
    return $rows;
}

// staff_grade (outer app's shared table, id 0-5) - used by
// admin/aap_grouping_master.php's Approval Unit grid so a grade renamed/added
// there (via ATEM's masterlist.php "+ Add Grade") shows up here with no code
// change.
function aapFetchGrades($conn) {
    $rows = [];
    $res = $conn->query("SELECT id, grade_name FROM staff_grade WHERE is_active = 1 ORDER BY id ASC");
    while ($res && $row = $res->fetch_assoc()) $rows[] = $row;
    return $rows;
}

function aapIsOperations($dept_ids) {
    return in_array(AAP_DEPT_OPERATION, $dept_ids, true);
}

function aapIsCustomerSupport($dept_ids) {
    return in_array(AAP_DEPT_CUSTOMER_SUPPORT, $dept_ids, true);
}

// As of 2026-10-19, AAP admin is ONLY staff.aap = 1 - full stop. Grade >= 4,
// Digital Innovation (dept 16) membership, and the OKR/ATEM SuperAdmin union
// (staff.okr/staff.atem) used to also grant this, but none of them have
// anything to do with AAP specifically, so they were removed. $is_superadmin
// is now just staff.aap === 1 too (see aapFetchIsSuperAdmin()) - $grade/
// $dept_ids/$is_superadmin stay as parameters only so every existing caller
// (aapResolveIdentity() below) doesn't need its own signature change; only
// $aap_level is actually read.
function aapIsAdmin($grade, $dept_ids, $is_superadmin = false, $aap_level = 0) {
    return (int)$aap_level === 1;
}

// Whether this staff member manages Case Types / Approval Unit settings for
// EVERY department, versus being scoped to only their own - grade >= 4 or
// SuperAdmin only. Customer Support (dept 27) used to also get this
// automatically (they route/handle cases across every department), but that
// meant any CS staff could see/touch every OTHER department's Case
// Types/Approval Unit Groups/Staff Assignments even when not actually
// involved - removed so CS is scoped like anyone else: their own
// department, plus whatever they're explicitly granted via
// aap_department_managers (aapFetchDeptManagerDepartmentIds()). Deliberately
// NOT the same set as aapIsAdmin(): Digital Innovation staff (one of
// aapIsAdmin's paths, since they administer this module's settings pages
// generally) are still scoped to their own department here unless they
// separately qualify via grade/SuperAdmin - the two functions answer
// different questions (who can open an admin page vs. whose department data
// they see once inside it).
function aapCanManageAllDepartments($grade, $dept_ids, $is_superadmin = false) {
    return ((int)$grade >= 4) || $is_superadmin;
}

// Whether $department_id is within a staff member's own scope - either they
// manage every department (aapCanManageAllDepartments()), or it's one of
// their own (staff.department, via aapDeptIdsFromCsv()). Shared by
// admin/aap_admin.php's Case Type Level 1 Department picker/save/edit/list
// and admin/aap_grouping_master.php's Department Tiers list/AJAX actions.
function aapDeptInScope($department_id, $dept_ids, $can_manage_all) {
    return $can_manage_all || in_array((int)$department_id, $dept_ids, true);
}

// Re-queried independently at every entry point rather than cached in
// session, same convention as OKR's $_is_superadmin / ATEM's
// $db_is_superadmin.
// staff.aap = 1 is the ONLY thing that counts as AAP admin, as of
// 2026-10-19 - 2 is Department Manager (aapFetchDeptManagerDepartmentIds()
// above), a department-scoped role that must NOT get full admin access just
// because the column is non-zero. staff.okr/staff.atem used to also union
// in here (a SuperAdmin from either sibling module got full AAP access too,
// mirroring how OKR and ATEM union each other's flag) - removed, since
// being a SuperAdmin of a different module has nothing to do with AAP
// specifically. grade >= 4 and Digital Innovation (dept 16) were likewise
// removed from aapIsAdmin() the same day, for the same reason.
function aapFetchIsSuperAdmin($conn, $staff_id) {
    if (empty($staff_id)) return false;
    $stmt = $conn->prepare("SELECT aap FROM staff WHERE id = ?");
    $stmt->bind_param("i", $staff_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return false;
    return (int)$row['aap'] === 1;
}

// The raw staff.aap level (0 = no access, 1 = full admin, 2 = Department
// Manager - see aapFetchIsSuperAdmin()/aapFetchDeptManagerDepartmentIds()
// above) - needed wherever "has full AAP admin access" (level 1
// specifically) must be distinguished from "no access"/"Department Manager
// only", which a plain is-SuperAdmin boolean can't express on its own (a
// grade>=4/Digital Innovation/OKR-or-ATEM-SuperAdmin account is_superadmin=
// true without ever having a staff.aap row value). Passed into aapIsAdmin()
// below as its own OR path, same weight as grade>=4/Digital Innovation/
// SuperAdmin - but only when it's exactly 1.
function aapFetchAapLevel($conn, $staff_id) {
    if (empty($staff_id)) return 0;
    $stmt = $conn->prepare("SELECT aap FROM staff WHERE id = ?");
    $stmt->bind_param("i", $staff_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (int)$row['aap'] : 0;
}

// Resolves a staff member's grade/department/admin-level identity for the
// AAP module - every page calls this once right after lock_adv.php/
// common/index_adv.php resolve the real $grade/$department, in place of the
// old aapDeptIdsFromCsv()+aapIsAdmin() two-liner.
function aapResolveIdentity($conn, $id_user, $real_grade, $real_department) {
    $is_superadmin = aapFetchIsSuperAdmin($conn, $id_user);
    $aap_level = aapFetchAapLevel($conn, $id_user);

    $grade = $real_grade;
    $department = $real_department;
    $dept_ids = aapDeptIdsFromCsv($department);
    $is_admin = aapIsAdmin($grade, $dept_ids, $is_superadmin, $aap_level);

    return [
        'grade' => $grade,
        'department' => $department,
        'dept_ids' => $dept_ids,
        'is_admin' => $is_admin,
        'is_superadmin' => $is_superadmin,
        'aap_level' => $aap_level,
    ];
}

// Execution gate - as of 2026-10-19, Level 3 is structurally identical to
// Level 2/aapCanApprove() (own Department/Group/Staff-Tier roster, own HOD
// Approval Only toggle), just gating Execute instead of Approve. A staff
// member can execute a case only if they're explicitly on that Case Type's
// Execution Staff Tier list (Level 3, section='execution') with a tier that
// covers the case's value. Admins bypass this. No "Operations" fallback any
// more when nothing's configured (same as Level 2 has none) - a Case Type
// with no Level 3 roster and no HOD-Only set simply has no eligible
// non-admin executor until one is configured.
//
// HOD Approval Only overrides the above when Level 3 has it set: the sole
// eligible executor(s) become the CURRENT case's own
// requester_department_id's HOD(s) (iidas_department) - resolved fresh per
// case, exactly like Level 2's own HOD-Only (aapCanApprove()).
function aapCanExecute($conn, $staff_id, $case_type_id, $value, $is_admin, $requester_department_id = null) {
    if ($is_admin) return true;

    $ct = aapFetchCaseType($conn, $case_type_id);
    if ($ct && !empty($ct['level3_hod_only'])) {
        return in_array((int)$staff_id, aapFetchDepartmentHodStaffIds($conn, $requester_department_id), true);
    }

    $stmt = $conn->prepare("SELECT tier, department_id, group_id FROM aap_case_type_staff_tiers WHERE case_type_id = ? AND section = 'execution' AND staff_id = ?");
    $stmt->bind_param("ii", $case_type_id, $staff_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return false;

    return aapTierNameCoversValue($conn, (int)$row['department_id'], $row['tier'], $value, $row['group_id'] !== null ? (int)$row['group_id'] : null);
}

// aapGetStaffThreshold() / aap_staff_thresholds removed 2026-09 - a
// per-staff flat RM ceiling from an earlier design, superseded by the
// per-Case-Type Staff Tier model below. Its own save UI ("Staff in Pool"
// panel) had already been removed from admin/aap_admin.php and
// aapCanApprove() never consulted it, so it was a second, non-functional
// "ceiling" concept sitting next to the real one - removed rather than
// left to confuse anyone reading the admin pages. If a flat per-staff RM
// ceiling (independent of Case Type/department) is wanted again later,
// reintroduce it deliberately with a real UI and wire it into
// aapCanApprove(), rather than resurrecting the orphaned table.

// The Case Type Staff Tier picker (admin/aap_admin.php) used to work off a
// fixed Unlimited/Tier 1/Tier 2 vocabulary with a hardcoded $5,000 split -
// dropped in favour of directly mirroring whatever named tiers actually
// exist in the Approval Unit Master (admin/aap_grouping_master.php), so a
// department's real tier names/values (including any it added beyond the
// standard 3) show up correctly instead of being silently bucketed into the
// wrong-looking name. aap_case_type_staff_tiers.tier now stores that tier's
// real name (VARCHAR, no longer an ENUM) rather than a bucket label.

// Tier list to populate the picker's Tier dropdown for one department -
// that department's own tiers if it has customized the Approval Unit
// Master, otherwise the shared Default list. Same fallback rule as
// aapFetchApprovalUnitTiers() in admin/aap_grouping_master.php.
function aapFetchTierOptionsForDepartment($conn, $department_id) {
    $stmt = $conn->prepare("SELECT tier_name, tier_value FROM aap_approval_unit_tiers WHERE department_id = ? ORDER BY sort_order ASC, id ASC");
    $stmt->bind_param("i", $department_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    if (empty($rows)) {
        $res = $conn->query("SELECT tier_name, tier_value FROM aap_approval_unit_tiers WHERE department_id IS NULL ORDER BY sort_order ASC, id ASC");
        $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    }
    foreach ($rows as &$r) $r['tier_value'] = $r['tier_value'] !== null ? (float)$r['tier_value'] : null;
    return $rows;
}

// The RM value a given tier NAME actually grants - resolved against one
// specific Group when $group_id is given (exact match, no ambiguity even if
// the department has multiple Groups sharing the same tier names), or the
// old department/Universal fallback chain when it's null (legacy
// aap_case_type_staff_tiers rows assigned before group_id existed, or ones
// added via the "Default (Universal)" picker entry). Returns null if no
// tier by that name exists anywhere (treated as "covers nothing" by
// aapTierNameCoversValue() below, not as Unlimited).
function aapTierValueByName($conn, $department_id, $tier_name, $group_id = null) {
    if ($group_id) {
        $stmt = $conn->prepare("SELECT tier_value FROM aap_approval_unit_tiers WHERE group_id = ? AND tier_name = ?");
        $stmt->bind_param("is", $group_id, $tier_name);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) return ['found' => true, 'value' => $row['tier_value'] !== null ? (float)$row['tier_value'] : null];
        return ['found' => false, 'value' => null];
    }

    $stmt = $conn->prepare("SELECT tier_value FROM aap_approval_unit_tiers WHERE department_id = ? AND tier_name = ?");
    $stmt->bind_param("is", $department_id, $tier_name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) return ['found' => true, 'value' => $row['tier_value'] !== null ? (float)$row['tier_value'] : null];

    $stmt = $conn->prepare("SELECT tier_value FROM aap_approval_unit_tiers WHERE department_id IS NULL AND tier_name = ?");
    $stmt->bind_param("s", $tier_name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) return ['found' => true, 'value' => $row['tier_value'] !== null ? (float)$row['tier_value'] : null];

    return ['found' => false, 'value' => null];
}

// A tier's RM ceiling covers a case value if the tier is Unlimited (value
// null) or the case value doesn't exceed it. A tier name that no longer
// exists anywhere (e.g. removed from Approval Unit Master after being
// assigned) covers nothing - fails closed rather than silently granting
// approval.
function aapTierNameCoversValue($conn, $department_id, $tier_name, $value, $group_id = null) {
    $tier = aapTierValueByName($conn, $department_id, $tier_name, $group_id);
    if (!$tier['found']) return false;
    return $tier['value'] === null || (float)$value <= $tier['value'];
}

// Groups belonging to a department (id + name only) - feeds the "Select
// Group" dropdown on the Level 2/3 Staff Tier picker in admin/aap_admin.php.
// Full Group detail (tiers/staff) lives in admin/aap_grouping_master.php's
// aapFetchDepartmentGroups() - this is the lightweight picker-only version.
// A department that hasn't set up any Group yet (Approval Unit Master's
// Department Tiers not customized) gets a synthetic "Default (Universal)"
// entry (id 0) instead of an empty list, so the picker still works - see
// aapFetchGroupStaffTiers()'s id-0 branch for what it resolves to.
function aapFetchDepartmentGroupNames($conn, $department_id) {
    $stmt = $conn->prepare("SELECT id, group_name, description FROM aap_approval_unit_tier_groups WHERE department_id = ? ORDER BY sort_order ASC, id ASC");
    $stmt->bind_param("i", $department_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($rows as &$r) $r['id'] = (int)$r['id'];
    // Default (Universal) is always offered alongside any custom Group(s) -
    // a Case Type can still be assigned to a department's grade-based
    // default roster even after that department has since set up its own
    // Group(s), not just as a fallback when it has none at all.
    array_unshift($rows, ['id' => 0, 'group_name' => 'Default (Universal)', 'description' => '']);
    return $rows;
}

// Every staff member belonging to one Group, each with the tier NAME they
// occupy there (grade-default or manually overridden - same resolution as
// admin/aap_grouping_master.php's aapFetchDepartmentTierStaff(), flattened
// across all of the Group's tier rows instead of one row at a time). Feeds
// the "Add" button on the Level 2/3 Staff Tier picker in admin/aap_admin.php,
// which bulk-adds every row returned here.
//
// group_id 0 is the synthetic "Default (Universal)" entry
// (aapFetchDepartmentGroupNames() above) for a department with no Groups set
// up at all - every staff member in the department, bucketed straight off
// the Universal list by grade, with no per-Group override to apply (there is
// no Group).
function aapFetchGroupStaffTiers($conn, $department_id, $group_id) {
    if ($group_id === 0) {
        $stmt = $conn->prepare("
            SELECT s.id, s.nama_staff AS name, t.tier_name
            FROM staff s
            JOIN aap_approval_unit_tier_grades tg ON tg.grade_id = s.grade
            JOIN aap_approval_unit_tiers t ON t.id = tg.tier_id AND t.department_id IS NULL
            WHERE s.recycle != 1 AND FIND_IN_SET(?, s.department)
            ORDER BY s.nama_staff ASC
        ");
        $stmt->bind_param("i", $department_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['staff_id' => (int)$r['id'], 'staff_name' => $r['name'], 'tier' => $r['tier_name'], 'group_id' => null];
        }
        return $out;
    }

    $stmt = $conn->prepare("SELECT id, tier_name FROM aap_approval_unit_tiers WHERE group_id = ? ORDER BY sort_order ASC, id ASC");
    $stmt->bind_param("i", $group_id);
    $stmt->execute();
    $tiers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $out = [];
    foreach ($tiers as $t) {
        $tier_row_id = (int)$t['id'];
        $stmt = $conn->prepare("
            SELECT s.id, s.nama_staff AS name
            FROM staff s
            WHERE s.recycle != 1
              AND FIND_IN_SET(?, s.department)
              AND s.grade IN (
                  SELECT tg.grade_id
                  FROM aap_approval_unit_tier_grades tg
                  JOIN aap_approval_unit_tiers ut ON ut.id = tg.tier_id
                  WHERE ut.department_id IS NULL AND ut.tier_name = ?
              )
              AND s.id NOT IN (
                  SELECT ts.staff_id
                  FROM aap_approval_unit_tier_staff ts
                  JOIN aap_approval_unit_tiers t2 ON t2.id = ts.tier_id
                  WHERE t2.group_id = ?
              )
            UNION
            SELECT s2.id, s2.nama_staff AS name
            FROM aap_approval_unit_tier_staff ts2
            JOIN staff s2 ON s2.id = ts2.staff_id
            WHERE ts2.tier_id = ?
        ");
        $stmt->bind_param("isii", $department_id, $t['tier_name'], $group_id, $tier_row_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($rows as $r) {
            $out[] = ['staff_id' => (int)$r['id'], 'staff_name' => $r['name'], 'tier' => $t['tier_name'], 'group_id' => $group_id];
        }
    }
    return $out;
}

// The department currently assigned to a Case Type's Level 2 ('approval')
// or Level 3 ('execution') section, or null if nothing's been assigned
// yet. All rows in a section always share the same department_id - the
// Staff Tier picker only ever adds one Group (one department) per level -
// so the first row's value stands in for the whole section. ('exclusion'
// was an earlier, retired "Level 3" design - see that table's note in
// sql/aap_master.sql.)
//
// HOD Approval Only mode (aap_case_types.level2_hod_only/level3_hod_only)
// has no aap_case_type_staff_tiers rows at all while active - the
// department is resolved fresh per case instead
// (aapFetchDepartmentHodStaffIds()) - so that's checked first by callers
// before falling back to this lookup.
function aapCaseTypeSectionDepartment($conn, $case_type_id, $section) {
    if (!$case_type_id) return null;
    $stmt = $conn->prepare("SELECT department_id FROM aap_case_type_staff_tiers WHERE case_type_id = ? AND section = ? LIMIT 1");
    $stmt->bind_param("is", $case_type_id, $section);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (int)$row['department_id'] : null;
}

// Staff currently holding Head of Department for a department, per Alpro's
// existing HOD registry (iidas_department - not owned by AAP, kept live
// here rather than copied so an HOD change there takes effect immediately).
// A department can have more than one active row (co-heads/backups) -
// every one of them qualifies. recycle=1 rows are retired HODs, excluded.
//
// Outlet (AAP_DEPT_OUTLET) is a special case: outlets don't really have a
// single corporate "HOD" the way a head-office department does, so Area
// Managers (staff.status_semasa = 'Area Manager') are added to the eligible
// set alongside whatever's in iidas_department for Outlet, not instead of
// it - any Area Manager can approve an Outlet-raised case, regardless of
// which specific outlet/region it's from (no per-outlet/region routing).
function aapFetchDepartmentHodStaffIds($conn, $department_id) {
    $department_id = (int)$department_id;
    if ($department_id <= 0) return [];
    $stmt = $conn->prepare("SELECT DISTINCT hod_id FROM iidas_department WHERE department = ? AND recycle = 0");
    $stmt->bind_param("i", $department_id);
    $stmt->execute();
    $ids = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) { $ids[] = (int)$row['hod_id']; }
    $stmt->close();

    if ($department_id === AAP_DEPT_OUTLET) {
        $am_res = $conn->query("SELECT id FROM staff WHERE status_semasa = 'Area Manager' AND recycle != 1");
        while ($am_res && $row = $am_res->fetch_assoc()) { $ids[] = (int)$row['id']; }
    }

    return array_values(array_unique($ids));
}

// Per-Case-Type edit rights - each is independently authorized, both for
// rendering admin/aap_admin.php's edit form and for the save handler's own
// server-side check (never trust the client's disabled/hidden fields alone).
//
// Level 1 (name/department/description/physical-confirm) belongs to
// whoever manages the Case Type's own department, same as before this
// feature existed.
//
// Level 2/3 are each a single delegated department's Group+staff roster -
// as of 2026-10-19 Level 3 (Execution Team) is structurally identical to
// Level 2 (Approval), just gating Execute instead of Approve (see
// aapCanExecute() below). Deciding WHICH department holds a level
// (assigning it for the first time, or reassigning it to a different
// department later) is Level 1's call (or a full-scope admin's) - the
// receiving department doesn't get to hand itself a level, only curate its
// own roster once it's been handed one. Curating that roster (picking a
// specific Group, changing tiers) belongs to whichever department
// currently holds the level; if nothing's been assigned yet, that falls to
// whoever can do the assigning, since they're the one initializing it.
function aapCaseTypeEditRights($conn, $case_type_id, $ct_department_id, $dept_ids, $can_manage_all) {
    $can_edit_level1 = $can_manage_all || aapDeptInScope($ct_department_id, $dept_ids, $can_manage_all);
    $level2_dept = aapCaseTypeSectionDepartment($conn, $case_type_id, 'approval');
    $level3_dept = aapCaseTypeSectionDepartment($conn, $case_type_id, 'execution');

    $can_reassign_level2 = $can_edit_level1;
    $can_reassign_level3 = $can_edit_level1;
    $can_edit_level2_group = $can_manage_all || ($level2_dept !== null ? aapDeptInScope($level2_dept, $dept_ids, $can_manage_all) : $can_reassign_level2);
    $can_edit_level3_group = $can_manage_all || ($level3_dept !== null ? aapDeptInScope($level3_dept, $dept_ids, $can_manage_all) : $can_reassign_level3);

    return [
        'can_edit_level1' => $can_edit_level1,
        'can_reassign_level2' => $can_reassign_level2,
        'can_reassign_level3' => $can_reassign_level3,
        'can_edit_level2_group' => $can_edit_level2_group,
        'can_edit_level3_group' => $can_edit_level3_group,
        'level2_dept' => $level2_dept,
        'level3_dept' => $level3_dept,
    ];
}

// Whether $acting_dept_ids may hand-pick a specific Group when assigning a
// Case Type level to $target_department_id - only when it's their own
// department, or they're a full-scope admin. Handing a level to a
// department they don't belong to must fall back to that department's
// Default (Universal) roster (see aapDefaultUniversalTierRows()) instead of
// trusting whatever specific Group/staff the assigner posted - only the
// receiving department should ever curate a specific Group for itself.
function aapCaseTypeAllowedGroupForAssignment($target_department_id, $dept_ids, $can_manage_all) {
    return $can_manage_all || in_array((int)$target_department_id, $dept_ids, true);
}

// Builds Staff Tier rows (same shape as the client's serialized JSON) for a
// department's Default (Universal) roster - used to force that roster
// server-side instead of trusting a submitted Group/staff pick, whenever
// aapCaseTypeAllowedGroupForAssignment() says the assigner isn't entitled to
// hand-pick a specific Group for the department they're assigning a level to.
function aapDefaultUniversalTierRows($conn, $department_id) {
    $staff = aapFetchGroupStaffTiers($conn, $department_id, 0);
    $rows = [];
    foreach ($staff as $s) {
        $rows[] = ['staff_id' => $s['staff_id'], 'department_id' => $department_id, 'group_id' => null, 'tier' => $s['tier']];
    }
    return $rows;
}

// Every staff+tier row assigned to a Case Type for the 'approval' section -
// see admin/aap_admin.php's Level 2 Staff Tier table.
function aapFetchCaseTypeStaffTiers($conn, $case_type_id, $section) {
    $stmt = $conn->prepare("
        SELECT t.id, t.department_id, t.group_id, t.staff_id, t.tier, s.nama_staff AS staff_name
        FROM aap_case_type_staff_tiers t
        LEFT JOIN staff s ON s.id = t.staff_id
        WHERE t.case_type_id = ? AND t.section = ?
        ORDER BY t.id ASC
    ");
    $stmt->bind_param("is", $case_type_id, $section);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// approval gate — replaced the old approver_mode/department-pool/grade
// system entirely. A staff member can approve a case only if they're
// explicitly on that Case Type's Approval Staff Tier list (Level 2 in
// admin/aap_admin.php) with a tier that covers the case's value. Admins
// bypass this. (Until 2026-10-19 there was also a Level 3 Exclusion list
// that overrode this - removed when Level 3 was repurposed into Execution
// Team routing, see aapCanExecute(); it had zero rows in production/dev at
// the time, so nothing relied on it.)
//
// HOD Approval Only overrides the above when Level 2 has it set: the sole
// eligible approver(s) become the CURRENT case's own
// requester_department_id's HOD(s) (iidas_department) - resolved fresh per
// case, not a department fixed on the Case Type, so the same Case Type can
// route to a different HOD for each case depending on who actually raised
// it (e.g. a "Customer Refund" Case Type owned by Operation at Level 1
// still routes to Customer Support's HOD for a case Customer Support
// raised) - covers every value from 0 to Unlimited, and the Staff Tier
// roster is irrelevant while this is on.
//
// Customer Support is a deliberate exception (not handled in here): CS staff
// raise and self-handle their own cases at any value, so callers should
// check that case first (requester is CS and is the acting staff member)
// before ever calling this function - see aap_update.php.
function aapCanApprove($conn, $staff_id, $case_type_id, $value, $is_admin, $requester_department_id = null) {
    if ($is_admin) return true;

    $ct = aapFetchCaseType($conn, $case_type_id);
    if ($ct && !empty($ct['level2_hod_only'])) {
        return in_array((int)$staff_id, aapFetchDepartmentHodStaffIds($conn, $requester_department_id), true);
    }

    $stmt = $conn->prepare("SELECT tier, department_id, group_id FROM aap_case_type_staff_tiers WHERE case_type_id = ? AND section = 'approval' AND staff_id = ?");
    $stmt->bind_param("ii", $case_type_id, $staff_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return false;

    return aapTierNameCoversValue($conn, (int)$row['department_id'], $row['tier'], $value, $row['group_id'] !== null ? (int)$row['group_id'] : null);
}

// Visibility scope: Operations/admin see everything; everyone else sees cases
// they raised themselves or that were raised by their own department.
// Visibility: a staff member sees a case if they personally raised it, OR
// the case's Case Type belongs to their own department - i.e. what the
// case is actually ABOUT, not who happened to submit the form. Deliberately
// keyed off aap_case_types.department_id (via a subquery, so this stays
// usable in a plain `aap_cases c` query with no join needed) rather than
// aap_cases.requester_department_id, which is just the raiser's own
// department at raise time (still shown separately as "Requester
// Department" - see aapCaseSelectSql()'s requester_department_name) - a
// Customer Support/Operations/admin staff member raising a case on behalf
// of another department's Fixit ticket (see aap_add.php's aapDeptInScope()
// gate) must not silently hide that case from the department it's actually
// for.
function aapScopeWhere($staff_id, $dept_ids, $is_admin) {
    if ($is_admin || aapIsOperations($dept_ids)) return '1=1';
    $conds = ["c.created_by = " . (int)$staff_id];
    if (!empty($dept_ids)) {
        $ids = implode(',', array_map('intval', $dept_ids));
        $conds[] = "c.case_type_id IN (SELECT id FROM aap_case_types WHERE department_id IN ($ids))";
    }
    return '(' . implode(' OR ', $conds) . ')';
}

function aapGenerateCaseRef($id) {
    return 'AAP' . (int)$id;
}

function aapLogAudit($conn, $case_id, $event, $actor_id, $summary, $changes = null) {
    // aap_audit_logs.summary is varchar(255) - several call sites append a
    // free-text remark/reason the user typed (unbounded length) onto a short
    // fixed prefix, so a long remark can overflow the column and crash the
    // whole request. The remark itself is already stored in full elsewhere
    // (e.g. aap_cases.approval_remark) - this is just a log line - so
    // truncate here rather than fixing every call site individually.
    if (mb_strlen($summary) > 255) {
        $summary = mb_substr($summary, 0, 252) . '...';
    }
    $stmt = $conn->prepare("INSERT INTO aap_audit_logs (case_id, event, actor_staff_id, summary, changes, timestamp) VALUES (?, ?, ?, ?, ?, ?)");
    $changes_json = $changes !== null ? json_encode($changes) : null;
    $now = date('Y-m-d H:i:s');
    $stmt->bind_param("isisss", $case_id, $event, $actor_id, $summary, $changes_json, $now);
    $stmt->execute();
    $stmt->close();
}

// Additional notes added after case creation - separate from the original
// aap_cases.evidence_note captured at raise time. Shares its storage table
// (aap_case_evidence, `type`='note') with the reference-link functions
// below - both are the same shape of data (case_id, author, timestamp, plus
// content) and are always rendered together under the same "Add New
// Evidence" panel on aap_update.php, so there was no reason to keep them as
// two physical tables (see sql/2026-10-27_merge_case_notes_links.sql).
function aapFetchCaseNotes($conn, $case_id) {
    $stmt = $conn->prepare("
        SELECT n.*, s.nama_staff AS created_by_name
        FROM aap_case_evidence n
        LEFT JOIN staff s ON s.id = n.created_by
        WHERE n.case_id = ? AND n.type = 'note'
        ORDER BY n.timestamp ASC
    ");
    $stmt->bind_param("i", $case_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function aapAddCaseNote($conn, $case_id, $note, $created_by) {
    $now = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("INSERT INTO aap_case_evidence (case_id, type, note, created_by, timestamp) VALUES (?, 'note', ?, ?, ?)");
    $stmt->bind_param("isis", $case_id, $note, $created_by, $now);
    $stmt->execute();
    $stmt->close();
}

function aapFetchCaseNote($conn, $note_id, $case_id) {
    $stmt = $conn->prepare("SELECT * FROM aap_case_evidence WHERE id = ? AND case_id = ? AND type = 'note'");
    $stmt->bind_param("ii", $note_id, $case_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row;
}

function aapUpdateCaseNote($conn, $note_id, $case_id, $note) {
    $stmt = $conn->prepare("UPDATE aap_case_evidence SET note = ? WHERE id = ? AND case_id = ? AND type = 'note'");
    $stmt->bind_param("sii", $note, $note_id, $case_id);
    $stmt->execute();
    $stmt->close();
}

function aapDeleteCaseNote($conn, $note_id, $case_id) {
    $stmt = $conn->prepare("DELETE FROM aap_case_evidence WHERE id = ? AND case_id = ? AND type = 'note'");
    $stmt->bind_param("ii", $note_id, $case_id);
    $stmt->execute();
    $stmt->close();
}

// Named URL references added after case creation (Add New Evidence panel,
// alongside notes/attachments) - e.g. a link to an external document,
// ticket, or resource, when a file upload or note isn't the right fit.
// Subject to the same evidence-freeze rule as notes/attachments
// (aapCaseEvidenceItemEditable()) - see aap_update.php's 'add_evidence'/
// 'delete_reference_link' actions. Shares aap_case_evidence with
// the note functions above (`type`='link') - see the comment there.
function aapFetchCaseReferenceLinks($conn, $case_id) {
    $stmt = $conn->prepare("
        SELECT l.*, s.nama_staff AS created_by_name
        FROM aap_case_evidence l
        LEFT JOIN staff s ON s.id = l.created_by
        WHERE l.case_id = ? AND l.type = 'link'
        ORDER BY l.timestamp ASC
    ");
    $stmt->bind_param("i", $case_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function aapAddCaseReferenceLink($conn, $case_id, $url, $label, $created_by) {
    $now = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("INSERT INTO aap_case_evidence (case_id, type, url, label, created_by, timestamp) VALUES (?, 'link', ?, ?, ?, ?)");
    $stmt->bind_param("issis", $case_id, $url, $label, $created_by, $now);
    $stmt->execute();
    $stmt->close();
}

function aapFetchCaseReferenceLink($conn, $link_id, $case_id) {
    $stmt = $conn->prepare("SELECT * FROM aap_case_evidence WHERE id = ? AND case_id = ? AND type = 'link'");
    $stmt->bind_param("ii", $link_id, $case_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row;
}

function aapDeleteCaseReferenceLink($conn, $link_id, $case_id) {
    $stmt = $conn->prepare("DELETE FROM aap_case_evidence WHERE id = ? AND case_id = ? AND type = 'link'");
    $stmt->bind_param("ii", $link_id, $case_id);
    $stmt->execute();
    $stmt->close();
}

// Evidence files - third `type` ('file') sharing aap_case_evidence with the
// notes/links above (merged from the former aap_case_attachments, see
// sql/2026-10-28_merge_case_attachments_into_evidence.sql). `stored_name` is
// the NAS path; the file itself never stays on local disk. Execution-only
// files are NOT here - they keep their own table, see
// aapUploadExecutionAttachments().
function aapFetchCaseAttachments($conn, $case_id) {
    $stmt = $conn->prepare("
        SELECT a.*, s.nama_staff AS created_by_name,
               t.remark AS template_remark, t.file_name AS template_file_name
        FROM aap_case_evidence a
        LEFT JOIN staff s ON s.id = a.created_by
        LEFT JOIN aap_case_type_templates t ON t.id = a.template_id
        WHERE a.case_id = ? AND a.type = 'file'
        ORDER BY a.timestamp ASC
    ");
    $stmt->bind_param("i", $case_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// $point_key ('5', '4.1') links the file to the Instruction point whose
// "Attach" input it came through; $template_id to the Case Type template
// whose upload slot it came from - both null for general evidence.
function aapAddCaseAttachment($conn, $case_id, $file_name, $stored_name, $created_by, $now, $point_key = null, $template_id = null) {
    $stmt = $conn->prepare("INSERT INTO aap_case_evidence (case_id, type, file_name, stored_name, point_key, template_id, created_by, timestamp) VALUES (?, 'file', ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("isssiis", $case_id, $file_name, $stored_name, $point_key, $template_id, $created_by, $now);
    $stmt->execute();
    $stmt->close();
}

function aapFetchCaseAttachment($conn, $att_id, $case_id) {
    $stmt = $conn->prepare("SELECT * FROM aap_case_evidence WHERE id = ? AND case_id = ? AND type = 'file'");
    $stmt->bind_param("ii", $att_id, $case_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row;
}

function aapDeleteCaseAttachment($conn, $att_id, $case_id) {
    $stmt = $conn->prepare("DELETE FROM aap_case_evidence WHERE id = ? AND case_id = ? AND type = 'file'");
    $stmt->bind_param("ii", $att_id, $case_id);
    $stmt->execute();
    $stmt->close();
}

// In-app notifications (mirrors okr_notifications) - one row per event a
// staff member should be told about: the issuer on approved/rejected/closed,
// or an eligible approver on pending_approval (see
// aapFetchEligibleApproverIds() below). $type is a free-form string
// (aap_notifications.type has no ENUM constraint) rendered by
// js/aap-sidebar.js's snippet map.
function aapNotifyStaff($conn, $case_id, $recipient_staff_id, $type) {
    $stmt = $conn->prepare("INSERT INTO aap_notifications (staff_id, case_id, type) VALUES (?, ?, ?)");
    $stmt->bind_param("iis", $recipient_staff_id, $case_id, $type);
    $stmt->execute();
    $stmt->close();
}

// Every staff member currently eligible to approve a case at this Case
// Type/value - mirrors aapCanApprove()'s Staff Tier logic, but returns the
// full matching set instead of checking one staff member, so all of them
// can be notified once the case reaches the approval gate (see the
// open_case/tag_physical handlers in aap_update.php). Admins are excluded on
// purpose - there's no fixed "admin pool" to enumerate, and admins already
// see every case regardless of notifications.
function aapFetchEligibleApproverIds($conn, $case_type_id, $value, $requester_department_id = null) {
    $value = (float)$value;

    // HOD Approval Only (see aapCanApprove() above) - the eligible approver
    // set IS this specific case's requester_department_id's HOD(s), full
    // stop, no tier roster involved.
    $ct = aapFetchCaseType($conn, $case_type_id);
    if ($ct && !empty($ct['level2_hod_only'])) {
        return aapFetchDepartmentHodStaffIds($conn, $requester_department_id);
    }

    $approval = aapFetchCaseTypeStaffTiers($conn, $case_type_id, 'approval');

    $ids = [];
    foreach ($approval as $row) {
        $group_id = $row['group_id'] !== null ? (int)$row['group_id'] : null;
        if (aapTierNameCoversValue($conn, (int)$row['department_id'], $row['tier'], $value, $group_id)) $ids[] = (int)$row['staff_id'];
    }
    return array_unique($ids);
}

// Unread only - the bell's dropdown is a to-do list, not a permanent history
// (aap_audit_logs on the case itself is where the full history lives). Once
// a notification is marked read - either by clicking it directly, "Mark all
// read", or simply opening the case it's about (see
// aapMarkCaseNotificationsRead()) - it drops out of this list entirely
// rather than lingering with just a different background color.
function aapFetchNotifications($conn, $staff_id, $limit = 20) {
    $stmt = $conn->prepare("
        SELECT n.id, n.case_id, n.type, n.read_at, n.created_at, c.case_ref
        FROM aap_notifications n
        JOIN aap_cases c ON c.id = n.case_id
        WHERE n.staff_id = ? AND n.read_at IS NULL
        ORDER BY n.created_at DESC
        LIMIT ?
    ");
    $stmt->bind_param("ii", $staff_id, $limit);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function aapUnreadNotificationCount($conn, $staff_id) {
    $stmt = $conn->prepare("SELECT COUNT(*) AS n FROM aap_notifications WHERE staff_id = ? AND read_at IS NULL");
    $stmt->bind_param("i", $staff_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (int)$row['n'] : 0;
}

function aapMarkNotificationRead($conn, $notification_id, $staff_id) {
    $stmt = $conn->prepare("UPDATE aap_notifications SET read_at = NOW() WHERE id = ? AND staff_id = ? AND read_at IS NULL");
    $stmt->bind_param("ii", $notification_id, $staff_id);
    $stmt->execute();
    $stmt->close();
}

// Called from aap_update.php on every page load - opening a case (by any
// route: clicking its notification, the Case Queue, a direct link) clears
// every one of this viewer's own unread notifications about it, not just
// the one they happened to click through. Scoped to $staff_id so viewing a
// case never marks some OTHER staff member's notification about the same
// case as read.
function aapMarkCaseNotificationsRead($conn, $case_id, $staff_id) {
    $stmt = $conn->prepare("UPDATE aap_notifications SET read_at = NOW() WHERE case_id = ? AND staff_id = ? AND read_at IS NULL");
    $stmt->bind_param("ii", $case_id, $staff_id);
    $stmt->execute();
    $stmt->close();
}

function aapMarkAllNotificationsRead($conn, $staff_id) {
    $stmt = $conn->prepare("UPDATE aap_notifications SET read_at = NOW() WHERE staff_id = ? AND read_at IS NULL");
    $stmt->bind_param("i", $staff_id);
    $stmt->execute();
    $stmt->close();
}

function aapFetchAuditLogs($conn, $case_id) {
    $stmt = $conn->prepare("
        SELECT al.*, s.nama_staff AS actor_name
        FROM aap_audit_logs al
        LEFT JOIN staff s ON s.id = al.actor_staff_id
        WHERE al.case_id = ?
        ORDER BY al.timestamp ASC, al.id ASC
    ");
    $stmt->bind_param("i", $case_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    return $rows;
}

// General-purpose audit log for admin pages whose actions aren't scoped to
// one case (aap_audit_logs.case_id is NOT NULL, so it can't be reused here)
// - e.g. admin/aap_staff_assignments.php's Remove/Reassign/Reassign All/
// Remove All actions, which act on a staff member's rows across many
// departments/Case Types at once, not a single case.
function aapLogAdminAudit($conn, $page, $event, $actor_id, $target_staff_id, $summary) {
    $now = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("INSERT INTO aap_admin_audit_logs (page, event, actor_staff_id, target_staff_id, summary, timestamp) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssiiss", $page, $event, $actor_id, $target_staff_id, $summary, $now);
    $stmt->execute();
    $stmt->close();
}

function aapFetchAdminAuditLogs($conn, $page, $limit = 50) {
    $stmt = $conn->prepare("
        SELECT al.*, actor.nama_staff AS actor_name, target.nama_staff AS target_name
        FROM aap_admin_audit_logs al
        LEFT JOIN staff actor ON actor.id = al.actor_staff_id
        LEFT JOIN staff target ON target.id = al.target_staff_id
        WHERE al.page = ?
        ORDER BY al.timestamp DESC, al.id DESC
        LIMIT ?
    ");
    $stmt->bind_param("si", $page, $limit);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    return $rows;
}

// Canonical case SELECT — every page that reads a case (or list of cases)
// goes through this so joined labels never drift.
function aapCaseSelectSql($where = '1=1', $order = 'c.timestamp DESC') {
    return "
        SELECT c.*,
               ct.case_type_name,
               ct.department_id AS case_type_department_id,
               ctdept.depart_name AS case_type_department_name,
               fixit_o.code AS fixit_outlet_code,
               fixit_r.report AS fixit_report,
               dept.depart_name AS requester_department_name,
               req.nama_staff AS requester_name,
               app.nama_staff AS approver_name,
               exe.nama_staff AS executor_name,
               tag.nama_staff AS physical_tagged_by_name,
               cfm.nama_staff AS physical_confirmed_by_name,
               susp.nama_staff AS suspended_by_name,
               bm.bank_name AS bank_name
        FROM aap_cases c
        INNER JOIN aap_case_types ct ON ct.id = c.case_type_id
        LEFT JOIN staff_department ctdept ON ctdept.id = ct.department_id
        LEFT JOIN fixit_record fixit_r ON fixit_r.id = c.fixit_record_id
        LEFT JOIN outlet fixit_o ON fixit_o.id = fixit_r.outlet
        LEFT JOIN staff_department dept ON dept.id = c.requester_department_id
        LEFT JOIN staff req ON req.id = c.requester_staff_id
        LEFT JOIN staff app ON app.id = c.approver_staff_id
        LEFT JOIN staff exe ON exe.id = c.executor_staff_id
        LEFT JOIN staff tag ON tag.id = c.physical_tagged_by
        LEFT JOIN staff cfm ON cfm.id = c.physical_confirmed_by
        LEFT JOIN staff susp ON susp.id = c.suspended_by
        LEFT JOIN aap_bank_master bm ON bm.id = c.bank_id
        WHERE $where
        ORDER BY $order
    ";
}

function aapFetchCase($conn, $id) {
    $sql = aapCaseSelectSql('c.id = ' . (int)$id);
    $res = $conn->query($sql);
    return $res ? $res->fetch_assoc() : null;
}

// Distinct outlets among cases visible in this scope (via their linked
// Fixit ticket) - index.php's Outlet filter only lists outlets that
// actually have a case here, not the entire outlet master list.
function aapFetchCaseOutletOptions($conn, $scope_where) {
    $sql = "
        SELECT DISTINCT o.id, o.code
        FROM aap_cases c
        INNER JOIN fixit_record fr ON fr.id = c.fixit_record_id
        INNER JOIN outlet o ON o.id = fr.outlet
        WHERE $scope_where
        ORDER BY o.code ASC
    ";
    $res = $conn->query($sql);
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

function aapFetchCaseTypes($conn, $include_recycled = false, $department_id = null) {
    $where = $include_recycled ? '1=1' : 'ct.recycle = 0';
    if ($department_id !== null) $where .= ' AND ct.department_id = ' . (int)$department_id;
    $res = $conn->query("
        SELECT ct.*
        FROM aap_case_types ct
        WHERE $where
        ORDER BY ct.case_type_name ASC, ct.id ASC
    ");
    $rows = [];
    while ($res && $row = $res->fetch_assoc()) $rows[] = $row;
    return $rows;
}

// The originating Fixit report (fixit_record) for a case raised via the
// Fixit "Continue to Raise Approval Case" handoff (fixit/add_report.php).
// Surfaced read-only in AAP so approvers see what the requester actually
// reported in Fixit, not just the AAP-side fields re-entered on top of it.
function aapFetchFixitRecord($conn, $fixit_id) {
    if (empty($fixit_id)) return null;
    $stmt = $conn->prepare("
        SELECT fr.id, fr.report, fr.remark, fr.lodge, fr.lodge_op, fr.department_id, fr.category_id, fr.outlet AS outlet_id, fr.fix_status,
               fc.category AS category_name,
               fc.aap_case_type_id,
               sd.depart_name AS department_name,
               o.code AS outlet_code,
               s.nama_staff AS lodged_by_name
        FROM fixit_record fr
        LEFT JOIN fixit_category fc ON fc.id = fr.category_id
        LEFT JOIN staff_department sd ON sd.id = fr.department_id
        LEFT JOIN outlet o ON o.id = fr.outlet
        LEFT JOIN staff s ON s.id = fr.lodge_op
        WHERE fr.id = ?
    ");
    $stmt->bind_param("i", $fixit_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

// Files attached to the originating Fixit report (fixit_attachment) -
// surfaced alongside aapFetchFixitRecord() so the requester's original
// photos/evidence are visible here too, not just the AAP-side uploads.
// Streamed via Fixit's own downloaded.php (id-keyed, not path-based), so no
// duplicate download endpoint is needed on the AAP side.
function aapFetchFixitAttachments($conn, $fixit_id) {
    if (empty($fixit_id)) return [];
    $stmt = $conn->prepare("SELECT id, name, size, type, path FROM fixit_attachment WHERE fixit_record_id = ? ORDER BY id");
    $stmt->bind_param("i", $fixit_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// AAP-LINK: answers to the originating Fixit report's "Required
// Information" fields (fixit/add_report.php's loadCustomFields(), rendered
// from this Case Type's own Instruction for User points at the time the
// report was lodged) - shown read-only on aap_add.php's Fixit report card so
// whoever raises the AAP case sees what the reporter already filled in.
function aapFetchFixitCustomFieldAnswers($conn, $fixit_id) {
    if (empty($fixit_id)) return [];
    $stmt = $conn->prepare("SELECT field_label, field_value FROM aap_custom_field_answers WHERE fixit_record_id = ? ORDER BY sort_order ASC");
    $stmt->bind_param("i", $fixit_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function aapFixitAttachmentUrl($attachment_id) {
    return '../fixit/downloaded.php?id=' . (int)$attachment_id;
}

function aapFixitTicketUrl($fixit_id) {
    return '../fixit/index.php?key=F' . (int)$fixit_id;
}

// AAP-LINK: mirrors the status-update logic fixit/index_specific.php itself
// runs when a staff member manually marks a ticket Completed/Cancelled (see
// fixit/changedforAAP.md) - `complete`/`complete_op`/`efficiency` set the
// same way, `handled_by` forced to '1' (Staff In Charge) with `incharge_id`
// set to the ticket's own raiser (lodge_op), since there's no real Fixit
// contractor here - AAP resolved it. No-op if the ticket is missing or
// already resolved (fix_status != 0), so this never overwrites a manual
// completion/cancellation that already happened on the Fixit side.
function aapSetLinkedFixitStatus($conn, $fixit_record_id, $actor_staff_id, $fix_status) {
    $fixit_record_id = (int)$fixit_record_id;
    if ($fixit_record_id <= 0) return;
    $stmt = $conn->prepare("SELECT lodge, lodge_op, fix_status FROM fixit_record WHERE id = ?");
    $stmt->bind_param("i", $fixit_record_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || (int)$row['fix_status'] !== 0) return;

    $complete = date('Y-m-d H:i:s');
    // AAP-LINK: efficiency freezes at the moment the AAP case was raised, not
    // whenever it eventually closes/rejects/voids - the approval/execution
    // workflow afterward can involve many people over a much longer stretch
    // of time than a normal Fixit resolution, none of which is the
    // outlet/raising staff's own turnaround, and this feeds their
    // incentive/performance metric. Falls back to now() if the case row
    // can't be found for some reason, matching the old always-live-clock
    // behavior rather than failing the whole update.
    $case_stmt = $conn->prepare("SELECT timestamp, physical_confirmed_by, physical_confirm_status FROM aap_cases WHERE fixit_record_id = ? ORDER BY id DESC LIMIT 1");
    $case_stmt->bind_param("i", $fixit_record_id);
    $case_stmt->execute();
    $case_row = $case_stmt->get_result()->fetch_assoc();
    $case_stmt->close();
    $frozen_at = $case_row ? strtotime($case_row['timestamp']) : time();

    $efficiency = $frozen_at - strtotime($row['lodge']);
    // Staff In Charge = whoever confirmed the Verification Reference (RFID
    // tag) on the AAP case, when there was one - see
    // aapSetLinkedFixitInCharge(). Falls back to the ticket's own raiser.
    $incharge_id = ($case_row && $case_row['physical_confirm_status'] === 'confirmed' && !empty($case_row['physical_confirmed_by']))
        ? (int)$case_row['physical_confirmed_by']
        : (int)$row['lodge_op'];
    $actor_staff_id = (int)$actor_staff_id;
    $fix_status = (int)$fix_status;

    $stmt = $conn->prepare("UPDATE fixit_record SET fix_status = ?, complete = ?, complete_op = ?, efficiency = ?, handled_by = 1, incharge_id = ? WHERE id = ?");
    $stmt->bind_param("isiiii", $fix_status, $complete, $actor_staff_id, $efficiency, $incharge_id, $fixit_record_id);
    $stmt->execute();
    $stmt->close();
}

// AAP-LINK: shows the staff member who confirmed the AAP case's
// Verification Reference as the linked Fixit ticket's "Staff In Charge"
// (handled_by = 1, incharge_id) right away, instead of the empty Contractor
// picker. Only while the ticket is still open (fix_status 0), so a ticket
// already resolved on the Fixit side is never touched.
function aapSetLinkedFixitInCharge($conn, $fixit_record_id, $staff_id) {
    $fixit_record_id = (int)$fixit_record_id;
    $staff_id = (int)$staff_id;
    if ($fixit_record_id <= 0 || $staff_id <= 0) return;
    $stmt = $conn->prepare("UPDATE fixit_record SET handled_by = 1, incharge_id = ? WHERE id = ? AND fix_status = 0");
    $stmt->bind_param("ii", $staff_id, $fixit_record_id);
    $stmt->execute();
    $stmt->close();
}

function aapCompleteLinkedFixitTicket($conn, $fixit_record_id, $completed_by_staff_id) {
    aapSetLinkedFixitStatus($conn, $fixit_record_id, $completed_by_staff_id, 1);
}

function aapCancelLinkedFixitTicket($conn, $fixit_record_id, $cancelled_by_staff_id) {
    aapSetLinkedFixitStatus($conn, $fixit_record_id, $cancelled_by_staff_id, 2);
}

// AAP-LINK: Fixit tickets that should surface in AAP automatically, without
// anyone needing to click a handoff link back in Fixit: lodged under a
// category ticked "AAP - Payout Approval" (fixit_category.aap_link) and not
// yet converted into an aap_cases row. AAP can't auto-create the case itself
// — case type, calculated value, etc. aren't captured at Fixit intake, and
// there's no category -> AAP Family mapping yet either — so this just
// surfaces the queue; a human still picks the Family/Case Type and raises it
// via aap_add.php (pre-filled with this ticket's id/ref only, no family_id).
// $filters (all optional): category_id (int), outlet_id (int), lodged_by
// (string, matched against staff.nama_staff), date (string 'YYYY-MM-DD',
// matched against DATE(fr.lodge)) - feeds the Incoming from Fixit tab's
// filter bar on index.php. department_ids (int[] or null) scopes the
// queue to only tickets lodged against one of those departments - null
// means no scoping (full-list roles see every department). Every value is
// validated/escaped here rather than trusted from the caller.
// Department scope condition shared by aapIncomingFixitWhereSql() and the
// Category/Outlet option-list fetchers below - all three query from
// `fixit_record fr` joined to `fixit_category fc`, so this assumes those
// two aliases are in scope wherever it's used.
//
// A ticket lodged under a REAL department matches on fr.department_id
// directly, same as always. One lodged under the wildcard "AAP" department
// (AAP_FIXIT_WILDCARD_DEPARTMENT_ID) never matches that way - no staff
// member's own staff.department ever literally contains the synthetic
// wildcard id - so it ALSO matches when the linked AAP Case Type
// (fc.aap_case_type_id) belongs to one of the scoped departments, since
// that's the department the resulting case will actually be filed under
// (aapCaseTypeEditRights()/aap_add.php's own department display - see
// 2026-10-19's wildcard department work). A category not linked to any
// Case Type (aap_case_type_id NULL) only ever matches the first branch,
// same as before the wildcard department existed.
function aapIncomingFixitDeptScopeSql($department_ids) {
    if (!is_array($department_ids)) return '';
    $ids = array_map('intval', $department_ids);
    // An empty scope (staff.department blank) must match nothing, not "no
    // filter at all" - a bare "IN ()" is invalid SQL, so fail closed with
    // an always-false condition instead.
    if (!$ids) return " AND 1=0";
    $id_list = implode(',', $ids);
    return " AND (fr.department_id IN ($id_list)
                  OR EXISTS (SELECT 1 FROM aap_case_types ct2 WHERE ct2.id = fc.aap_case_type_id AND ct2.department_id IN ($id_list)))";
}

// via aap_add.php (pre-filled with this ticket's id/ref only, no family_id).
// $filters (all optional): category_id (int), outlet_id (int), lodged_by
// (string, matched against staff.nama_staff), date (string 'YYYY-MM-DD',
// matched against DATE(fr.lodge)) - feeds the Incoming from Fixit tab's
// filter bar on index.php. department_ids (int[] or null) scopes the
// queue to only tickets lodged against one of those departments (or, for a
// wildcard "AAP" ticket, whose linked Case Type belongs to one - see
// aapIncomingFixitDeptScopeSql() above) - null means no scoping (full-list
// roles see every department). Every value is validated/escaped here
// rather than trusted from the caller.
function aapIncomingFixitWhereSql($conn, $filters = []) {
    $where = "fc.aap_link = 1
          AND NOT EXISTS (SELECT 1 FROM aap_cases c WHERE c.fixit_record_id = fr.id)";
    if (!empty($filters['category_id'])) {
        $where .= " AND fc.id = " . (int)$filters['category_id'];
    }
    if (!empty($filters['outlet_id'])) {
        $where .= " AND fr.outlet = " . (int)$filters['outlet_id'];
    }
    if (!empty($filters['lodged_by'])) {
        $where .= " AND s.nama_staff LIKE '%" . $conn->real_escape_string($filters['lodged_by']) . "%'";
    }
    if (!empty($filters['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date'])) {
        $where .= " AND DATE(fr.lodge) = '" . $conn->real_escape_string($filters['date']) . "'";
    }
    if (isset($filters['department_ids'])) {
        $where .= aapIncomingFixitDeptScopeSql($filters['department_ids']);
    }
    return $where;
}

function aapFetchIncomingFixitTickets($conn, $limit = 20, $offset = 0, $filters = []) {
    $limit = (int)$limit;
    $offset = max(0, (int)$offset);
    $where = aapIncomingFixitWhereSql($conn, $filters);
    $res = $conn->query("
        SELECT fr.id, fr.report, fr.remark, fr.lodge, fr.lodge_op, fr.outlet AS outlet_id,
               fc.category AS category_name,
               o.code AS outlet_code,
               s.nama_staff AS lodged_by_name
        FROM fixit_record fr
        INNER JOIN fixit_category fc ON fc.id = fr.category_id
        LEFT JOIN outlet o ON o.id = fr.outlet
        LEFT JOIN staff s ON s.id = fr.lodge_op
        WHERE $where
        ORDER BY fr.lodge DESC
        LIMIT $limit OFFSET $offset
    ");
    $rows = [];
    while ($res && $row = $res->fetch_assoc()) $rows[] = $row;
    return $rows;
}

// Category/Outlet dropdown options for the Incoming from Fixit filter bar -
// only ones that actually appear among incoming tickets (aap_link=1, not
// yet cased), not every category/outlet in the system.
// $department_ids (int[] or null) scopes the option list to only what a
// department-scoped user would actually see in the queue itself (null =
// every department, for full-list roles).
function aapFetchIncomingFixitCategoryOptions($conn, $department_ids = null) {
    $dept_sql = aapIncomingFixitDeptScopeSql($department_ids);
    $rows = [];
    $res = $conn->query("
        SELECT DISTINCT fc.id, fc.category
        FROM fixit_category fc
        JOIN fixit_record fr ON fr.category_id = fc.id
        WHERE fc.aap_link = 1 AND NOT EXISTS (SELECT 1 FROM aap_cases c WHERE c.fixit_record_id = fr.id) $dept_sql
        ORDER BY fc.category ASC
    ");
    while ($res && $row = $res->fetch_assoc()) $rows[] = ['id' => (int)$row['id'], 'category' => $row['category']];
    return $rows;
}

function aapFetchIncomingFixitOutletOptions($conn, $department_ids = null) {
    $dept_sql = aapIncomingFixitDeptScopeSql($department_ids);
    $rows = [];
    $res = $conn->query("
        SELECT DISTINCT o.id, o.code
        FROM outlet o
        JOIN fixit_record fr ON fr.outlet = o.id
        JOIN fixit_category fc ON fc.id = fr.category_id
        WHERE fc.aap_link = 1 AND NOT EXISTS (SELECT 1 FROM aap_cases c WHERE c.fixit_record_id = fr.id) $dept_sql
        ORDER BY o.code ASC
    ");
    while ($res && $row = $res->fetch_assoc()) $rows[] = ['id' => (int)$row['id'], 'code' => $row['code']];
    return $rows;
}

function aapCountIncomingFixitTickets($conn, $filters = []) {
    $where = aapIncomingFixitWhereSql($conn, $filters);
    $res = $conn->query("
        SELECT COUNT(*) c
        FROM fixit_record fr
        INNER JOIN fixit_category fc ON fc.id = fr.category_id
        LEFT JOIN outlet o ON o.id = fr.outlet
        LEFT JOIN staff s ON s.id = fr.lodge_op
        WHERE $where
    ");
    return $res ? (int)$res->fetch_assoc()['c'] : 0;
}

function aapFetchCaseType($conn, $id) {
    $stmt = $conn->prepare("
        SELECT ct.*
        FROM aap_case_types ct
        WHERE ct.id = ?
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row;
}

// "Form Master" (admin/aap_settings.php) - the fixed Case Details + Bank
// Detail fields on aap_add.php that can be independently Show/Hide, marked
// Required/Optional, and relabeled - ONE global config applied to every
// Case Type alike (not per-Case-Type). Fixed field key -> default shape;
// keys match aap_cases/$_POST field names directly so callers never need a
// separate lookup table.
function aapFormMasterFieldDefaults() {
    return [
        'customer_name' => ['visible' => true, 'required' => true, 'label' => 'Customer Name'],
        'customer_membership_id' => ['visible' => true, 'required' => true, 'label' => 'Membership ID'],
        'staff_name' => ['visible' => true, 'required' => true, 'label' => 'Staff Name'],
        'transaction_ref' => ['visible' => true, 'required' => false, 'label' => 'Transaction No'],
        'calculated_value' => ['visible' => true, 'required' => false, 'label' => 'Calculated Value (Requestor)'],
        'value_type' => ['visible' => true, 'required' => false, 'label' => 'Value Type'],
        'recommended_outcome' => ['visible' => true, 'required' => false, 'label' => 'Report Description'],
        'bank_name' => ['visible' => true, 'required' => false, 'label' => 'Bank Name'],
        'bank_account_number' => ['visible' => true, 'required' => false, 'label' => 'Account Number'],
        'bank_account_holder' => ['visible' => true, 'required' => false, 'label' => 'Account Holder'],
    ];
}

// `customer_name`/`customer_membership_id`/`staff_name` drive the
// customer/staff typeahead search (aap_add.php) - hiding or making any of
// them optional there would break that lookup, so unlike every other Form
// Master field they stay permanently visible+required. `staff_name` is the
// same underlying #customer-name-label/input as `customer_name`, just its
// Staff-mode label text (see js/aap_add.js's setBeneficiaryType()) - listed
// separately here only so its label is independently editable. Only Label is
// actually admin-editable; aapSanitizeFormMasterFieldConfig() enforces this
// regardless of what's posted, and admin/aap_settings.php renders their
// Visible/Required checkboxes locked (checked, disabled) to match.
function aapFormMasterLockedKeys() {
    return ['customer_name', 'customer_membership_id', 'staff_name'];
}

// Per-Case-Type "Instruction for User" (admin/aap_admin.php's builder,
// aap_case_types.instructions JSON) also drives extra fields on
// aap_add.php's own Case Details card, not just Fixit's "Required
// Information" - see sql/2026-10-20_case_instruction_answers.sql's header
// comment for the full picture. ----

// Pure - decodes a Case Type's `instructions` JSON (same
// [{text, subs:[...]}] shape aapSanitizeCaseTypeInstructions() in
// admin/aap_admin.php already validates) into a flat list of
// ['key' => '6'|'6.1', 'label' => text], skipping blank text. Numbered
// exactly like the display already does elsewhere (main index, then '.' +
// sub index) so a point's key is stable as long as the list order doesn't
// change.
// `type`/`options` (Text/Number/Dropdown, admin/aap_admin.php's builder)
// only ever matter for a point that ends up NOT matching a Form Master
// field - see aapCaseTypeFieldOrder() below. Backward compatible with the
// pre-type schema, where a sub was just a plain string and a main point had
// no type/options keys at all (both default to 'text'/[]).
function aapNormalizeInstructionPoint($row) {
    $row = is_array($row) ? $row : ['text' => (string)$row];
    $type = in_array($row['type'] ?? null, ['text', 'number', 'dropdown'], true) ? $row['type'] : 'text';
    $options = ($type === 'dropdown' && is_array($row['options'] ?? null)) ? array_values(array_filter(array_map('trim', $row['options']))) : [];
    if ($type === 'dropdown' && empty($options)) $type = 'text';
    // Defaults to true for backward compatibility - every point saved before
    // this Required toggle existed has no 'required' key at all and keeps
    // behaving exactly as before (always required).
    $required = !array_key_exists('required', $row) ? true : (bool)$row['required'];
    // Defaults false - unlike `required`, no point saved before this
    // existed ever needed a supporting file, so there's no
    // backward-compatible "on" default the way `required` has.
    $requires_attachment = !empty($row['requires_attachment']);
    return ['text' => trim((string)($row['text'] ?? '')), 'type' => $type, 'options' => $options, 'required' => $required, 'requires_attachment' => $requires_attachment];
}

function aapCaseTypeInstructionPoints($ct) {
    $rows = json_decode($ct['instructions'] ?? '', true);
    if (!is_array($rows)) return [];
    $points = [];
    foreach ($rows as $mi => $main) {
        $main_num = $mi + 1;
        $main_n = aapNormalizeInstructionPoint($main);
        if ($main_n['text'] !== '') {
            $points[] = ['key' => (string)$main_num, 'label' => $main_n['text'], 'type' => $main_n['type'], 'options' => $main_n['options'], 'required' => $main_n['required'], 'requires_attachment' => $main_n['requires_attachment']];
        }
        foreach ((array)($main['subs'] ?? []) as $si => $sub) {
            $sub_n = aapNormalizeInstructionPoint($sub);
            if ($sub_n['text'] === '') continue;
            $points[] = ['key' => $main_num . '.' . ($si + 1), 'label' => $sub_n['text'], 'type' => $sub_n['type'], 'options' => $sub_n['options'], 'required' => $sub_n['required'], 'requires_attachment' => $sub_n['requires_attachment']];
        }
    }
    return $points;
}

// Pure - returns a Case Type's instruction points in order, each tagged
// with the Form Master field key it matches (by exact, case-insensitive
// text against that field's DEFAULT label from aapFormMasterFieldDefaults()
// - not the admin's possibly-renamed Form Master label, so this stays
// stable regardless of relabeling), or null if it doesn't match any -
// meaning it's a brand-new field specific to this Case Type. This ordered,
// tagged list is the single source of truth for both "which fields show
// and in what order" (aapCaseTypeReplaceFieldConfig() below) and "which
// points are extra, unmatched fields" (filter where matched_field_key is
// null) - everywhere a Case Type's Instruction for User list needs to
// drive the UI, it goes through this one function.
// $form_master_config (aapFetchFormMasterConfig()): when given, a matched
// point's `label` becomes Form Master's own (possibly renamed) label, so
// renaming e.g. Transaction No -> Invoice No in Form Master shows everywhere
// without editing every Case Type's list. Matching itself still uses the
// default label, so the rename never breaks the match.
function aapCaseTypeFieldOrder($ct, $form_master_config = null) {
    $points = aapCaseTypeInstructionPoints($ct);
    $label_to_key = [];
    foreach (aapFormMasterFieldDefaults() as $key => $def) {
        $label_to_key[mb_strtolower($def['label'])] = $key;
    }
    foreach ($points as &$p) {
        $p['matched_field_key'] = $label_to_key[mb_strtolower($p['label'])] ?? null;
        if ($p['matched_field_key'] !== null && !empty($form_master_config[$p['matched_field_key']]['label'])) {
            $p['label'] = $form_master_config[$p['matched_field_key']]['label'];
        }
    }
    unset($p);
    return $points;
}

// Pure - when a Case Type has a non-empty Instruction for User list, it
// REPLACES Form Master's field set for Case Details/Bank Detail entirely
// (not additive): only listed fields show, every one required, in the
// list's own order - exactly mirroring what Fixit's "Required Information"
// already shows for the same list. An empty $ordered_points (no list)
// falls back to $form_master_config completely unchanged - today's
// original behavior. Locked keys (aapFormMasterLockedKeys() -
// customer_name/customer_membership_id/staff_name) always stay
// visible+required even when not matched, since the case still needs to
// be linked to a real customer/staff regardless of what the list says.
function aapCaseTypeReplaceFieldConfig($form_master_config, $ordered_points) {
    if (empty($ordered_points)) return $form_master_config;
    $locked = aapFormMasterLockedKeys();
    $matched = [];
    foreach ($ordered_points as $p) {
        if ($p['matched_field_key'] !== null) $matched[$p['matched_field_key']] = $p['label'];
    }
    $field_cfg = $form_master_config;
    foreach ($field_cfg as $key => $def) {
        if (isset($matched[$key])) {
            $field_cfg[$key] = ['visible' => true, 'required' => true, 'label' => $matched[$key]];
        } elseif (in_array($key, $locked, true)) {
            // Stays visible+required with its Form Master label - unchanged.
        } else {
            $field_cfg[$key]['visible'] = false;
            $field_cfg[$key]['required'] = false;
        }
    }
    return $field_cfg;
}

// Pure - each extra instruction field's own Required toggle
// (admin/aap_admin.php's builder, defaults true for backward compatibility -
// see aapNormalizeInstructionPoint()) decides whether it's enforced here.
// $posted is keyed by point_key with '.' replaced by '_' (PHP mangles
// literal dots in form field names), already translated back to the '.'
// form by the caller before this is called. $attachment_present is the same
// key shape, keyed by point_key (NOT underscore-mangled - it comes straight
// from $_FILES['extra_attachment'], an associative array that never goes
// through a form-field-name translation) => bool, whether a file was
// actually uploaded for a point whose own `requires_attachment` is set;
// omit it (default null) for a caller that doesn't support per-point
// attachments at all (aap_update.php's open_case/edit_case - this feature
// is Raise Case-only for now, see aap_add.php) - null skips the attachment
// check entirely rather than treating every requires_attachment point as
// permanently unmet. Returns an error string or null.
function aapValidateExtraInstructionFields($extra_fields, $posted, $attachment_present = null) {
    foreach ($extra_fields as $f) {
        $value = trim((string)($posted[$f['key']] ?? ''));
        if ($value === '') {
            if ($f['required'] ?? true) {
                return "Please fill in all required fields - " . $f['label'] . " is required.";
            }
            continue;
        }
        if (($f['type'] ?? 'text') === 'dropdown' && !in_array($value, $f['options'] ?? [], true)) {
            return "Invalid value selected for " . $f['label'] . ".";
        }
        if ($attachment_present !== null && !empty($f['requires_attachment']) && empty($attachment_present[$f['key']])) {
            return "Please attach a supporting file for " . $f['label'] . ".";
        }
    }
    return null;
}

// Same "Attach" check as aapValidateExtraInstructionFields() above, for
// points that match a Form Master field (those never go through that
// function - their answer lives in its own aap_cases column). $ordered_points
// is aapCaseTypeFieldOrder()'s output; $attachment_present the same
// point_key => bool map. Returns an error string or null.
function aapValidateMatchedPointAttachments($ordered_points, $attachment_present) {
    foreach ($ordered_points as $p) {
        if ($p['matched_field_key'] !== null && !empty($p['requires_attachment']) && empty($attachment_present[$p['key']])) {
            return "Please attach a supporting file for " . $p['label'] . ".";
        }
    }
    return null;
}

// Upserts one aap_case_additional row per extra field present in $posted
// (same '.' -> key translation as aapValidateExtraInstructionFields()).
// Re-validates a dropdown field's value against its own options
// independently of aapValidateExtraInstructionFields() (never trust the
// client, same pattern as aapSaveCaseCustomValues()) - a forged/invalid
// dropdown value is stored blank, not whatever was posted.
function aapSaveCaseInstructionAnswers($conn, $case_id, $extra_fields, $posted) {
    if (empty($extra_fields)) return;
    $stmt = $conn->prepare("
        INSERT INTO aap_case_additional (case_id, point_key, point_label, point_value, sort_order, timestamp)
        VALUES (?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE point_label = VALUES(point_label), point_value = VALUES(point_value), sort_order = VALUES(sort_order)
    ");
    foreach ($extra_fields as $i => $f) {
        if (!array_key_exists($f['key'], $posted)) continue;
        $value = trim((string)$posted[$f['key']]);
        if (($f['type'] ?? 'text') === 'dropdown' && !in_array($value, $f['options'] ?? [], true)) {
            $value = '';
        }
        $sort = $i;
        $stmt->bind_param("isssi", $case_id, $f['key'], $f['label'], $value, $sort);
        $stmt->execute();
    }
    $stmt->close();
}

// For aap_update.php's view card / edit prefill - every answer this case
// has, in the order they were originally presented.
function aapFetchCaseInstructionAnswers($conn, $case_id) {
    $stmt = $conn->prepare("SELECT point_key, point_label, point_value FROM aap_case_additional WHERE case_id = ? ORDER BY sort_order, id");
    $stmt->bind_param("i", $case_id);
    $stmt->execute();
    $rows = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    return $rows;
}

// Pure (no DB) - decodes a stored `config_json` value and merges it over
// aapFormMasterFieldDefaults(), so a NULL/empty/corrupted value, or one
// missing a key (e.g. saved before a new field is ever added later), still
// returns a complete, safe config.
function aapMergeFormMasterFieldConfig($raw_json) {
    $defaults = aapFormMasterFieldDefaults();
    $locked = aapFormMasterLockedKeys();
    $stored = json_decode($raw_json ?? '', true);
    if (!is_array($stored)) return $defaults;
    foreach ($defaults as $key => $def) {
        if (!isset($stored[$key]) || !is_array($stored[$key])) continue;
        $label = trim((string)($stored[$key]['label'] ?? ''));
        $defaults[$key] = [
            'visible' => in_array($key, $locked, true) ? true : !empty($stored[$key]['visible']),
            'required' => in_array($key, $locked, true) ? true : !empty($stored[$key]['required']),
            'label' => $label !== '' ? $label : $def['label'],
        ];
    }
    return $defaults;
}

// Reads the single global Form Master row (`aap_form_master_config`, id=1)
// and merges it over the defaults. The one DB-touching read - every caller
// (aap_add.php's render + POST handler, admin/aap_settings.php's prefill)
// goes through this rather than querying the table directly.
function aapFetchFormMasterConfig($conn) {
    $result = $conn->query("SELECT config_json FROM aap_form_master_config WHERE id = 1");
    $row = $result ? $result->fetch_assoc() : null;
    return aapMergeFormMasterFieldConfig($row['config_json'] ?? null);
}

// Upserts the single global Form Master row with an already-sanitized
// config JSON string (see aapSanitizeFormMasterFieldConfig()).
function aapSaveFormMasterConfig($conn, $clean_json, $actor_staff_id) {
    $stmt = $conn->prepare("
        INSERT INTO aap_form_master_config (id, config_json, updated_by, updated_at)
        VALUES (1, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE config_json = VALUES(config_json), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)
    ");
    $stmt->bind_param("si", $clean_json, $actor_staff_id);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

// Re-validates Form Master's posted config before saving - never trusts it
// as-is, same reasoning as aapSanitizeCaseTypeInstructions()
// (admin/aap_admin.php). Whitelists the known keys only (anything else
// posted is dropped), coerces visible/required to real booleans, and forces
// required back to false whenever visible is false - a hidden field can
// never block submission. Blank label falls back to that field's own
// default label rather than saving an empty one.
function aapSanitizeFormMasterFieldConfig($posted_json) {
    $defaults = aapFormMasterFieldDefaults();
    $locked = aapFormMasterLockedKeys();
    $posted = json_decode($posted_json ?? '', true);
    $posted = is_array($posted) ? $posted : [];
    $clean = [];
    foreach ($defaults as $key => $def) {
        $is_locked = in_array($key, $locked, true);
        $visible = $is_locked ? true : !empty($posted[$key]['visible']);
        $required = $is_locked ? true : ($visible && !empty($posted[$key]['required']));
        $label = is_array($posted[$key] ?? null) ? trim((string)($posted[$key]['label'] ?? '')) : '';
        $clean[$key] = ['visible' => $visible, 'required' => $required, 'label' => $label !== '' ? $label : $def['label']];
    }
    return json_encode($clean);
}

// "Additional Fields" (admin/aap_settings.php's Form Master section) -
// admin-defined custom fields (Number/Dropdown/Textarea) shown on
// aap_add.php and viewable/editable on aap_update.php, beyond the 6 fixed
// fields aapFormMasterFieldDefaults() covers. `options` is decoded from its
// stored JSON into a plain array for every caller, so no caller ever
// json_decode()s it themselves.
function aapFetchActiveCustomFields($conn) {
    $rows = [];
    $res = $conn->query("SELECT * FROM aap_custom_fields WHERE recycle = 0 ORDER BY sort_order, id");
    while ($row = $res->fetch_assoc()) {
        $row['options'] = json_decode($row['options'] ?? '', true) ?: [];
        $rows[] = $row;
    }
    return $rows;
}

// Same as aapFetchActiveCustomFields() but includes retired fields too - for
// the admin list, which needs to show/manage retired fields as well.
function aapFetchAllCustomFields($conn) {
    $rows = [];
    $res = $conn->query("SELECT * FROM aap_custom_fields ORDER BY sort_order, id");
    while ($row = $res->fetch_assoc()) {
        $row['options'] = json_decode($row['options'] ?? '', true) ?: [];
        $rows[] = $row;
    }
    return $rows;
}

// View-card query for aap_update.php - every active field (even if this case
// never answered it) PLUS any retired field this specific case already has
// a value for, so a case's historical answer never silently disappears just
// because the field was later retired. Active fields the admin hasn't
// retired always show, answered or not; a retired field only shows if there
// is actually a stored value to show.
function aapFetchCaseCustomFieldsForDisplay($conn, $case_id) {
    $stmt = $conn->prepare("
        SELECT cf.*, v.value
        FROM aap_custom_fields cf
        LEFT JOIN aap_case_custom_values v ON v.custom_field_id = cf.id AND v.case_id = ?
        WHERE cf.recycle = 0 OR v.id IS NOT NULL
        ORDER BY cf.sort_order, cf.id
    ");
    $stmt->bind_param("i", $case_id);
    $stmt->execute();
    $rows = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $row['options'] = json_decode($row['options'] ?? '', true) ?: [];
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

// Pure - checks a posted custom_field[] submission against the currently
// active fields (as returned by aapFetchActiveCustomFields()) and returns an
// error message, or null if everything required is present and every
// dropdown value posted is actually one of that field's own options.
// Re-checked server-side since the client-side `required` attribute alone
// never stops a tampered/direct POST.
function aapValidateCustomFieldSubmission($active_fields, $posted) {
    foreach ($active_fields as $field) {
        $value = trim((string)($posted[$field['id']] ?? ''));
        if ($field['required'] && $value === '') {
            return "Please fill in all required fields - " . $field['label'] . " is required.";
        }
        if ($field['field_type'] === 'dropdown' && $value !== '' && !in_array($value, $field['options'], true)) {
            return "Invalid value selected for " . $field['label'] . ".";
        }
    }
    return null;
}

// Upserts one aap_case_custom_values row per active field present in
// $posted - re-validates dropdown values against that field's own options
// independently of aapValidateCustomFieldSubmission() (never trust the
// client, same reasoning as aapSanitizeFormMasterFieldConfig()): a
// forged/invalid dropdown value is stored blank, not whatever was posted.
// Retired fields are never written here - their past values stay exactly as
// they were, untouched by a submission that no longer offers them.
function aapSaveCaseCustomValues($conn, $case_id, $active_fields, $posted) {
    if (empty($active_fields)) return;
    $stmt = $conn->prepare("
        INSERT INTO aap_case_custom_values (case_id, custom_field_id, value)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE value = VALUES(value)
    ");
    foreach ($active_fields as $field) {
        $value = trim((string)($posted[$field['id']] ?? ''));
        if ($field['field_type'] === 'dropdown' && $value !== '' && !in_array($value, $field['options'], true)) {
            $value = '';
        }
        if (!array_key_exists($field['id'], $posted)) continue;
        $field_id = (int)$field['id'];
        $stmt->bind_param("iis", $case_id, $field_id, $value);
        $stmt->execute();
    }
    $stmt->close();
}

// Admin-side (admin/aap_settings.php's add_custom_field handler) -
// newline-separated textarea input -> trimmed, de-duplicated,
// blank-stripped JSON array string ready for aap_custom_fields.options.
function aapSanitizeCustomFieldOptions($raw_textarea) {
    $lines = preg_split('/\r\n|\r|\n/', (string)$raw_textarea);
    $clean = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || in_array($line, $clean, true)) continue;
        $clean[] = $line;
    }
    return json_encode($clean);
}

// Mirrors one Case Type into fixit_category (department_id =
// AAP_FIXIT_WILDCARD_DEPARTMENT_ID, aap_link = 1) so it appears in Fixit's
// own Category dropdown once a reporter picks the wildcard "AAP" department
// (see add_report.php's getCategory()/report_category.php in the Fixit
// module). aap_case_type_id is the reliable link back to this Case Type
// (unique-keyed, see sql/2026-10-19_fixit_category_aap_link.sql) - an
// existing synced row is updated in place rather than matching on the
// category name text, which the Case Type's own name/active state can
// change freely without ever creating a duplicate or orphaned row. Called
// from admin/aap_admin.php right after a Case Type is created, renamed, or
// its Active/Inactive toggle flips.
function aapSyncFixitCategoryForCaseType($conn, $case_type_id, $actor_id) {
    $ct = aapFetchCaseType($conn, $case_type_id);
    if (!$ct) return;
    $name = mb_substr($ct['case_type_name'], 0, 50);
    $description = $ct['description'] ?? '';
    $recycle = (int)$ct['recycle'];

    $stmt = $conn->prepare("SELECT id FROM fixit_category WHERE aap_case_type_id = ?");
    $stmt->bind_param("i", $case_type_id);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing) {
        $stmt = $conn->prepare("UPDATE fixit_category SET category = ?, job_description = ?, recycle = ? WHERE id = ?");
        $stmt->bind_param("ssii", $name, $description, $recycle, $existing['id']);
        $stmt->execute();
        $stmt->close();
    } else {
        $dept_id = AAP_FIXIT_WILDCARD_DEPARTMENT_ID;
        $stmt = $conn->prepare("
            INSERT INTO fixit_category
                (department_id, category, job_description, attachment, payment, privacy, access, aap_link, aap_case_type_id, creator, npra, recycle)
            VALUES (?, ?, ?, 1, 0, 0, 0, 1, ?, ?, 0, ?)
        ");
        $stmt->bind_param("issiii", $dept_id, $name, $description, $case_type_id, $actor_id, $recycle);
        $stmt->execute();
        $stmt->close();
    }
}

// Fields left optional at raise time (aap_add.php) so evidence gathering can
// continue while a case is a Draft, but required before Open Case can move
// it into the approval workflow. Returns a list of missing field labels -
// empty means ready to open.
function aapCaseOpenReadyErrors($case) {
    $missing = [];
    if ($case['calculated_value'] === null || $case['calculated_value'] === '') $missing[] = 'Calculated Value (Requestor)';
    if (empty($case['value_type'])) $missing[] = 'Value Type';
    if (trim((string)$case['recommended_outcome']) === '') $missing[] = 'Report Description';
    // Bank Detail (Bank Name/Account Number/Account Holder Name) is
    // deliberately not required here - not every case pays out to a bank
    // account (e.g. points, or a non-monetary outcome).
    return $missing;
}

// The one place eligible approvers ever get notified - extracted from
// aap_update.php's 'submit_for_approval' action so it can also run
// automatically: right after a new case is raised (aap_add.php, when no
// physical verification is needed) or right after physical confirmation
// completes (aap_update.php's 'tag_physical'), as well as from the manual
// Submit for Approval button itself (kept as a fallback for the rare case
// that somehow reaches Active without having been auto-submitted).
function aapSubmitCaseForApproval($conn, $case_id, $case_type_id, $calculated_value, $requester_department_id, $actor_id, $now) {
    $stmt = $conn->prepare("UPDATE aap_cases SET submitted_for_approval = 1, updated_at = ? WHERE id = ?");
    $stmt->bind_param("si", $now, $case_id);
    $stmt->execute(); $stmt->close();
    aapLogAudit($conn, $case_id, 'submitted_for_approval', $actor_id, "Submitted for approval.");
    $eligible = aapFetchEligibleApproverIds($conn, $case_type_id, $calculated_value, $requester_department_id);
    foreach ($eligible as $approver_id) {
        aapNotifyStaff($conn, $case_id, $approver_id, 'pending_approval');
    }
}

// Stores $ct's current Instruction for User list on the case
// (aap_cases.instructions_snapshot) - called when a case is created and when
// its Case Type changes, so later edits to the Case Type's list never alter
// an existing case's fields.
function aapSnapshotCaseInstructions($conn, $case_id, $ct) {
    $snapshot = $ct['instructions'] ?? null;
    $stmt = $conn->prepare("UPDATE aap_cases SET instructions_snapshot = ? WHERE id = ?");
    $stmt->bind_param("si", $snapshot, $case_id);
    $stmt->execute();
    $stmt->close();
}

// $ct with its `instructions` swapped for the case's own snapshot, when the
// case still has that Case Type and a snapshot exists - pass the result to
// aapCaseTypeFieldOrder() wherever an EXISTING case's fields are built.
function aapCaseTypeAsCreated($ct, $case) {
    if ($ct && $case['instructions_snapshot'] !== null && (int)$ct['id'] === (int)$case['case_type_id']) {
        $ct['instructions'] = $case['instructions_snapshot'];
    }
    return $ct;
}

// Shared by aap_update.php's 'edit_case' and 'open_case' action handlers -
// Open Case now submits the same Case Summary form as Save Changes (so the
// requester isn't forced to save first just to unlock the Open Case button),
// so both actions need to persist the same fields. Returns the saved field
// values (for the caller to re-check open-readiness against) or null if the
// submitted Case Type is invalid/retired.
function aapSaveCaseEditFields($conn, $id, $case, $post, $now) {
    $case_type_id = (int)($post['case_type_id'] ?? 0);
    $new_ct = aapFetchCaseType($conn, $case_type_id);
    if (!$new_ct || (int)$new_ct['recycle'] === 1) {
        return null;
    }

    $requester_type         = in_array($post['requester_type'] ?? null, ['customer', 'outlet', 'bu'], true) ? $post['requester_type'] : 'customer';
    $requesting_channel     = $case['requesting_channel'];
    $customer_membership_id = trim($post['customer_membership_id'] ?? '');
    $customer_name          = trim($post['customer_name'] ?? '');
    $bank_id                = !empty($post['bank_id']) ? (int)$post['bank_id'] : null;
    $bank_account_number    = trim($post['bank_account_number'] ?? '');
    $bank_account_holder    = trim($post['bank_account_holder'] ?? '');
    $transaction_ref        = trim($post['transaction_ref'] ?? '');
    $calculated_value       = (($post['calculated_value'] ?? '') !== '') ? (float)$post['calculated_value'] : null;
    $value_type             = in_array($post['value_type'] ?? null, ['cash', 'points'], true) ? $post['value_type'] : $case['value_type'];
    $recommended_outcome    = trim($post['recommended_outcome'] ?? '');

    $new_physical_required = (int)$new_ct['physical_confirm_required'];
    $new_physical_status   = $new_physical_required ? 'pending' : 'not_required';

    $stmt = $conn->prepare("
        UPDATE aap_cases SET
            case_type_id=?, requester_type=?, requesting_channel=?, customer_membership_id=?, customer_name=?,
            bank_id=?, bank_account_number=?, bank_account_holder=?, transaction_ref=?,
            calculated_value=?, value_type=?, recommended_outcome=?,
            physical_confirm_required=?, physical_confirm_status=?,
            updated_at=?
        WHERE id=?
    ");
    $stmt->bind_param(
        "issssisssdssissi",
        $case_type_id, $requester_type, $requesting_channel, $customer_membership_id, $customer_name,
        $bank_id, $bank_account_number, $bank_account_holder, $transaction_ref,
        $calculated_value, $value_type, $recommended_outcome,
        $new_physical_required, $new_physical_status,
        $now, $id
    );
    $stmt->execute(); $stmt->close();
    if ($case_type_id !== (int)$case['case_type_id']) {
        aapSnapshotCaseInstructions($conn, $id, $new_ct);
    }

    return [
        'case_type_id' => $case_type_id,
        'case_type_name' => $new_ct['case_type_name'],
        'calculated_value' => $calculated_value,
        'value_type' => $value_type,
        'recommended_outcome' => $recommended_outcome,
        'bank_id' => $bank_id,
        'bank_account_number' => $bank_account_number,
        'bank_account_holder' => $bank_account_holder,
        'physical_confirm_required' => $new_physical_required,
    ];
}

// Master list of banks for the Bank Detail dropdown (aap_add.php / aap_update.php).
function aapFetchBankMasterOptions($conn) {
    $rows = [];
    $res = $conn->query("SELECT id, bank_name FROM aap_bank_master WHERE recycle = 0 ORDER BY sort_order, bank_name");
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    return $rows;
}

function aapFormatValue($value, $type) {
    if ($value === null || $value === '') return '—';
    if ($type === 'points') return number_format((float)$value, 0) . ' pts';
    return 'RM ' . number_format((float)$value, 2);
}

// The moment a case actually entered the Approval stage - used to tell
// evidence added during Approval (still editable/removable by whoever added
// it) apart from evidence carried over from Draft/Verification (frozen once
// Approval starts, see aapCaseEvidenceItemEditable()). There's no dedicated
// column for this, so it's read off whichever event marks that transition:
// physical_confirmed_at directly on the case when a physical return was
// required, or the audit log's 'case_opened' event when it wasn't (Open Case
// goes straight to Approval in that case).
function aapCaseApprovalStageStartedAt($conn, $case) {
    if ((int)$case['physical_confirm_required'] === 1) {
        return $case['physical_confirmed_at'];
    }
    return aapCaseOpenedAt($conn, $case['id']);
}

// When Open Case actually ran (the 'case_opened' audit event) - the start of
// the Verification stage (or, when no physical return is required, of the
// Approval stage directly - see aapCaseApprovalStageStartedAt above).
function aapCaseOpenedAt($conn, $case_id) {
    $stmt = $conn->prepare("SELECT `timestamp` FROM aap_audit_logs WHERE case_id = ? AND event = 'case_opened' ORDER BY id DESC LIMIT 1");
    $stmt->bind_param("i", $case_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? $row['timestamp'] : null;
}

// Start timestamp of whichever phase the case is CURRENTLY sitting in -
// mirrors aapCaseDisplayStatus()'s own phase classification (Investigation /
// Verification / Approval / Execution), one boundary earlier: the freeze
// below needs to know not just what phase this is, but exactly when it
// began, so that only evidence added since then counts as "added during the
// current phase". Null for a terminal case (closed/rejected/voided) - there
// is no "current phase" left to add anything into.
function aapCaseCurrentPhaseStartedAt($conn, $case) {
    $cs = $case['case_status'];
    if ($cs === 'draft') return $case['timestamp'];
    if (in_array($cs, ['closed', 'rejected', 'voided'], true)) return null;

    if ($cs === 'executed') {
        $phase_started_at = $case['executed_at'] ?: $case['approved_at'];
    } elseif ((int)$case['physical_confirm_required'] === 1 && in_array($case['physical_confirm_status'], ['pending', 'tagged'], true)) {
        // $cs === 'open' from here.
        $phase_started_at = aapCaseOpenedAt($conn, $case['id']);
    } elseif (in_array($case['approval_status'], ['approved', 'corrected'], true) && $case['execution_status'] === 'pending') {
        $phase_started_at = $case['approved_at'];
    } else {
        $phase_started_at = aapCaseApprovalStageStartedAt($conn, $case);
    }

    // Suspend (Execution sending a case back to Verification/Approval, see
    // aap_update.php's 'suspend_case' action) never re-triggers 'case_opened'
    // - case_status stays 'open' throughout, it never goes back to 'draft' -
    // so the phase-boundary lookups above would otherwise still point at the
    // ORIGINAL case_opened/approved_at from before the suspend, letting
    // evidence from that earlier run look like it's still "this phase".
    // Clamping to suspended_at floors it at the suspend itself instead,
    // which is always safe (a fresh cycle's own boundaries land after it).
    if (!empty($case['suspended_at']) && ($phase_started_at === null || strtotime($case['suspended_at']) > strtotime($phase_started_at))) {
        $phase_started_at = $case['suspended_at'];
    }
    return $phase_started_at;
}

// Per-item edit/delete gate for one note or attachment. $can_add_evidence is
// the outer active-case-window check (case not yet closed/rejected/voided) -
// once the case is terminal, nothing is editable regardless of who's asking,
// admin included. Inside that window, only the item's own author can touch
// it, and only if they added it during the CURRENT phase (see
// aapCaseCurrentPhaseStartedAt) - evidence carried over from an earlier
// phase (e.g. added during Approval) is frozen the moment the case moves
// into the next one (e.g. Execution), even for its own author, since
// whoever acted on the previous phase already reviewed it as-is. This freeze
// applies to everyone with no admin override - same person, same
// SuperAdmin/admin level, every phase: once the phase that added an item has
// ended, it's locked for good. $is_admin is accepted only so callers don't
// need special-casing, but it's intentionally unused here.
function aapCaseEvidenceItemEditable($item_created_by, $item_timestamp, $can_add_evidence, $id_user, $is_admin, $phase_started_at) {
    if (!$can_add_evidence) return false;
    if ($phase_started_at === null) return false;
    return (int)$item_created_by === (int)$id_user && strtotime($item_timestamp) >= strtotime($phase_started_at);
}

// 'corrected' is just an approved case whose value was adjusted on the way -
// shown as one "Approved" status everywhere (label + badge/pill slug), even
// though approval_status itself keeps them distinct internally.
function aapApprovalStatusDisplay($approval_status) {
    if ($approval_status === 'corrected') return ['label' => 'Approved', 'slug' => 'approved'];
    return ['label' => ucfirst($approval_status), 'slug' => $approval_status];
}

// Single unified display status — collapses case_status plus the
// physical/approval/execution sub-statuses into one label + CSS slug, so
// every list/badge/pill in the module shows the same 7-state vocabulary
// instead of raw case_status. 'open' itself is never shown directly; it's
// always resolved further into Verification / Approval / Execute depending
// on where the case actually sits.
function aapCaseDisplayStatus($case) {
    $cs = $case['case_status'];
    if ($cs === 'draft')    return ['label' => 'Investigation', 'slug' => 'investigating'];
    // Voided and Rejected both mean the same thing to anyone looking at the
    // case from outside the approval workflow (it didn't proceed) - shown as
    // one "Rejected" status everywhere, even though case_status itself keeps
    // them distinct internally (different actor/trigger, see $can_void).
    if ($cs === 'voided' || $cs === 'rejected') return ['label' => 'Rejected', 'slug' => 'rejected'];
    if ($cs === 'closed') return ['label' => 'Closed', 'slug' => 'closed'];
    // Execute and Close are separate steps/actions - 'executed' means the
    // case has been executed but is still waiting on the separate "Notify
    // Requester & Close Case" step (aap_update.php's 'close' action), not
    // finished yet, so it gets its own status rather than folding into
    // 'Closed'.
    if ($cs === 'executed') return ['label' => 'Closing in Progress', 'slug' => 'closing'];

    // $cs === 'open' from here on.
    if ((int)$case['physical_confirm_required'] === 1 && in_array($case['physical_confirm_status'], ['pending', 'tagged'], true)) {
        return ['label' => 'Verification', 'slug' => 'confirming'];
    }
    if (in_array($case['approval_status'], ['approved', 'corrected'], true) && $case['execution_status'] === 'pending') {
        return ['label' => 'Execute', 'slug' => 'executing'];
    }
    // Ready (physical step done, or none needed) but not yet pushed onto an
    // approver's desk - see $can_submit_for_approval/'submit_for_approval'
    // in aap_update.php.
    if ($case['approval_status'] === 'pending' && empty($case['submitted_for_approval'])) {
        return ['label' => 'Active', 'slug' => 'active'];
    }
    return ['label' => 'Approval', 'slug' => 'pending_approval'];
}

function aapRequesterTypeLabel($type) {
    $map = ['customer' => 'Customer-Type', 'outlet' => 'Outlet-Type', 'bu' => 'BU-Type'];
    return isset($map[$type]) ? $map[$type] : ucfirst($type);
}

function aapPhysicalStatusLabel($status) {
    $map = [
        'not_required' => 'Not Required',
        'pending'      => 'Pending Verification',
        'tagged'       => 'Tagged — Awaiting Confirmation',
        'confirmed'    => 'Confirmed',
    ];
    return isset($map[$status]) ? $map[$status] : ucfirst($status);
}

// Bootstrap Icons class + color for a compact, symbol-only rendering of
// physical_confirm_status (list tables) - the full label from
// aapPhysicalStatusLabel() is still used as the title/tooltip.
function aapPhysicalStatusIcon($status) {
    $map = [
        'not_required' => ['bi-dash-circle', '#adb5bd'],
        'pending'      => ['bi-hourglass-split', '#fd7e14'],
        'tagged'       => ['bi-tag-fill', '#0d6efd'],
        'confirmed'    => ['bi-check-circle-fill', '#198754'],
    ];
    return $map[$status] ?? ['bi-question-circle', '#adb5bd'];
}

// Evidence attachments live on the corporate NAS, never permanently under
// uploads/ (see nas_config.php / lib/synologynas.php) - uploads/tmp/ is only
// a transient stop because CorporateNAS::upload() needs a real filesystem
// path (CURLFile). Handles a standard multi-file $_FILES['evidence'] entry;
// the case row must already exist ($case_id known) before calling this, so
// unlike OKR's create flow there's no session-staging step needed here.
// Returns ['attempted' => n, 'uploaded' => n] so the caller can tell a real
// success from a NAS/network failure that silently dropped the file - a
// selected-but-failed file used to look identical to "nothing selected" to
// the user (both just produced no attachment row), with the audit log and
// success banner still claiming "Evidence attachment(s) added" either way.
// $keyed_by_point: $files is aap_add.php's extra_attachment[] entry, keyed
// by point_key with '.' mangled to '_' - each file gets that point_key.
function aapUploadEvidenceFiles($conn, $case_id, $files, $uploaded_by, $now, $keyed_by_point = false) {
    $result = ['attempted' => 0, 'uploaded' => 0];
    if (empty($files) || !is_array($files['name'])) {
        return $result;
    }

    $tmp_dir = __DIR__ . '/uploads/tmp/';
    if (!is_dir($tmp_dir)) {
        mkdir($tmp_dir, 0755, true);
    }

    $nas = corpNasConnect();
    foreach ($files['name'] as $i => $fname) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK || $fname === '') {
            continue;
        }
        $result['attempted']++;
        $safe_name  = preg_replace('/[^A-Za-z0-9._-]/', '_', $fname);
        $tmp_name   = $case_id . '_' . time() . '_' . $i . '_' . $safe_name;
        $tmp_path   = $tmp_dir . $tmp_name;
        if (!move_uploaded_file($files['tmp_name'][$i], $tmp_path)) {
            continue;
        }

        $nas_path = $nas->upload($tmp_path, CORP_NAS_FOLDER, $tmp_name);
        unlink($tmp_path);
        if ($nas_path === false) {
            continue;
        }

        aapAddCaseAttachment($conn, $case_id, $fname, $nas_path, $uploaded_by, $now, $keyed_by_point ? str_replace('_', '.', (string)$i) : null);
        $result['uploaded']++;
    }
    return $result;
}

// Reference template file(s) an admin attaches to a Case Type
// (admin/aap_admin.php's Case Type form) - not tied to any one case, lives
// for as long as the Case Type does. Same upload mechanics as
// aapUploadEvidenceFiles() above (NAS push via corpNasConnect()), just a
// different owning table/id.
function aapUploadCaseTypeTemplates($conn, $case_type_id, $files, $uploaded_by, $now) {
    $result = ['attempted' => 0, 'uploaded' => 0];
    if (empty($files) || !is_array($files['name'])) {
        return $result;
    }

    $tmp_dir = __DIR__ . '/uploads/tmp/';
    if (!is_dir($tmp_dir)) {
        mkdir($tmp_dir, 0755, true);
    }

    $nas = corpNasConnect();
    foreach ($files['name'] as $i => $fname) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK || $fname === '') {
            continue;
        }
        $result['attempted']++;
        $safe_name = preg_replace('/[^A-Za-z0-9._-]/', '_', $fname);
        $tmp_name  = 'template_' . $case_type_id . '_' . time() . '_' . $i . '_' . $safe_name;
        $tmp_path  = $tmp_dir . $tmp_name;
        if (!move_uploaded_file($files['tmp_name'][$i], $tmp_path)) {
            continue;
        }

        $nas_path = $nas->upload($tmp_path, CORP_NAS_FOLDER, $tmp_name);
        unlink($tmp_path);
        if ($nas_path === false) {
            continue;
        }

        $stmt = $conn->prepare("INSERT INTO aap_case_type_templates (case_type_id, file_name, stored_name, uploaded_by, timestamp) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("issis", $case_type_id, $fname, $nas_path, $uploaded_by, $now);
        $stmt->execute();
        $stmt->close();
        $result['uploaded']++;
    }
    return $result;
}

function aapFetchCaseTypeTemplates($conn, $case_type_id) {
    $stmt = $conn->prepare("SELECT id, file_name, remark, timestamp FROM aap_case_type_templates WHERE case_type_id = ? ORDER BY id");
    $stmt->bind_param("i", $case_type_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// Optional admin note shown under a template's file name wherever staff
// download it (aap_add.php, Fixit's add_report.php). Empty string clears it.
function aapUpdateCaseTypeTemplateRemark($conn, $template_id, $case_type_id, $remark) {
    $remark = trim($remark);
    $remark = $remark === '' ? null : mb_substr($remark, 0, 255);
    $stmt = $conn->prepare("UPDATE aap_case_type_templates SET remark = ? WHERE id = ? AND case_type_id = ?");
    $stmt->bind_param("sii", $remark, $template_id, $case_type_id);
    $stmt->execute();
    $stmt->close();
}

function aapDeleteCaseTypeTemplate($conn, $template_id, $case_type_id) {
    $stmt = $conn->prepare("SELECT stored_name FROM aap_case_type_templates WHERE id = ? AND case_type_id = ?");
    $stmt->bind_param("ii", $template_id, $case_type_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return false;
    corpNasConnect()->delete($row['stored_name']);
    $dstmt = $conn->prepare("DELETE FROM aap_case_type_templates WHERE id = ?");
    $dstmt->bind_param("i", $template_id);
    $dstmt->execute();
    $dstmt->close();
    return true;
}

function aapCaseTypeTemplateUrl($template_id) {
    return 'aap_template_download.php?id=' . (int)$template_id;
}

// Like aapUploadEvidenceFiles() above, but for a file that's already on
// local disk (e.g. a Fixit report's own attachment, already saved under
// fixit's own upload folder) rather than a fresh $_FILES entry - copy()s it
// instead of move_uploaded_file()ing it (which only works on an actual HTTP
// upload for this request), same NAS push + aap_case_evidence 'file' row
// otherwise. Used by aapCreateCaseFromFixitRequiredInfo() below to carry a
// Fixit report's attachments over as the auto-created case's own evidence.
function aapAttachExistingFileAsEvidence($conn, $case_id, $local_path, $display_name, $uploaded_by, $now, $point_key = null, $template_id = null) {
    if (!is_file($local_path)) return false;
    $tmp_dir = __DIR__ . '/uploads/tmp/';
    if (!is_dir($tmp_dir)) {
        mkdir($tmp_dir, 0755, true);
    }
    $safe_name = preg_replace('/[^A-Za-z0-9._-]/', '_', $display_name);
    $tmp_name = $case_id . '_' . time() . '_' . mt_rand(1000, 9999) . '_' . $safe_name;
    $tmp_path = $tmp_dir . $tmp_name;
    if (!copy($local_path, $tmp_path)) return false;

    $nas = corpNasConnect();
    $nas_path = $nas->upload($tmp_path, CORP_NAS_FOLDER, $tmp_name);
    unlink($tmp_path);
    if ($nas_path === false) return false;

    aapAddCaseAttachment($conn, $case_id, $display_name, $nas_path, $uploaded_by, $now, $point_key, $template_id);
    return true;
}

// AAP-LINK: creates the aap_cases row directly from a Fixit report's own
// "Required Information" answers (fixit/add_report.php, right after the
// fixit_record/aap_custom_field_answers/fixit_attachment rows are saved) -
// skips the old manual "Incoming from Fixit" -> aap_add.php handoff
// entirely for a Case Type that has an Instruction for User list (there's
// nothing to auto-create from otherwise, that flow is untouched). Mirrors
// aap_add.php's own `raise_case` handler end to end: same field-matching
// (aapCaseTypeFieldOrder()/aapCaseTypeReplaceFieldConfig()), same
// hidden-field-never-persists rule, same "no Draft, open directly, auto-
// submit-for-approval unless physical confirmation is required" flow this
// session already built for aap_add.php. $fixit_answers (from
// aapFetchFixitCustomFieldAnswers(), already ordered by sort_order) is
// zipped against aapCaseTypeFieldOrder()'s own list by position - both are
// built from the exact same instructions JSON in the exact same order, so
// position is a safe, simple correspondence (no need to re-parse the
// numbered "N. label" prefix fixit/ajax.php snapshots into field_label).
// Returns the new case id, or null if the Case Type is invalid/retired
// (defensive - shouldn't happen since the category was already matched to
// it, but never trust stale data).
// $evidence_notes (list of note strings) and $reference_links (list of
// ['url' => .., 'label' => ..]) come straight from fixit/add_report.php's own
// Notes/Link rows - saved directly into this case's own AAP tables below,
// with no Fixit-side copy (that box only shows for a category that
// auto-creates its case here in the same submit).
// $point_attachment_ids maps fixit_attachment.id => the Required Information
// field index its "Attach" file was uploaded under (fixit/ajax.php's
// custom_attachment[$field_index]) - same position-zip as $fixit_answers,
// so that index is also the point's position in aapCaseTypeFieldOrder().
// $template_attachment_ids maps fixit_attachment.id => the
// aap_case_type_templates.id whose Files row it was uploaded in.
function aapCreateCaseFromFixitRequiredInfo($conn, $fixit_record_id, $case_type_id, $requester_staff_id, $requester_department_id, $now, $evidence_notes = [], $reference_links = [], $point_attachment_ids = [], $template_attachment_ids = []) {
    $ct = aapFetchCaseType($conn, $case_type_id);
    if (!$ct || (int)$ct['recycle'] === 1) return null;

    $form_master_config = aapFetchFormMasterConfig($conn);
    $ordered_points = aapCaseTypeFieldOrder($ct, $form_master_config);
    $field_cfg = aapCaseTypeReplaceFieldConfig($form_master_config, $ordered_points);

    $fixit_answers = aapFetchFixitCustomFieldAnswers($conn, $fixit_record_id);
    $fixit_answers = array_values($fixit_answers);

    $v = [
        'customer_name' => '', 'customer_membership_id' => '', 'transaction_ref' => '',
        'calculated_value' => null, 'value_type' => 'cash', 'recommended_outcome' => '',
        'bank_id' => null, 'bank_account_number' => '', 'bank_account_holder' => '',
    ];
    $extra_posted = [];
    foreach ($ordered_points as $i => $p) {
        $raw = trim((string)($fixit_answers[$i]['field_value'] ?? ''));
        $mk = $p['matched_field_key'];
        if ($mk === null) {
            $extra_posted[$p['key']] = $raw;
            continue;
        }
        switch ($mk) {
            case 'customer_name': case 'staff_name': $v['customer_name'] = $raw; break;
            case 'customer_membership_id': $v['customer_membership_id'] = $raw; break;
            case 'transaction_ref': $v['transaction_ref'] = $raw; break;
            case 'calculated_value': $v['calculated_value'] = ($raw !== '') ? (float)$raw : null; break;
            case 'value_type': $v['value_type'] = in_array($raw, ['cash', 'points'], true) ? $raw : 'cash'; break;
            case 'recommended_outcome': $v['recommended_outcome'] = $raw; break;
            case 'bank_name': $v['bank_id'] = ($raw !== '') ? (int)$raw : null; break;
            case 'bank_account_number': $v['bank_account_number'] = $raw; break;
            case 'bank_account_holder': $v['bank_account_holder'] = $raw; break;
        }
    }

    // Hidden fields never persist - same rule aap_add.php applies from the
    // same $field_cfg.
    if (!$field_cfg['transaction_ref']['visible']) $v['transaction_ref'] = '';
    if (!$field_cfg['calculated_value']['visible']) $v['calculated_value'] = null;
    if (!$field_cfg['value_type']['visible']) $v['value_type'] = 'cash';
    if (!$field_cfg['recommended_outcome']['visible']) $v['recommended_outcome'] = '';
    if (!$field_cfg['bank_name']['visible']) $v['bank_id'] = null;
    if (!$field_cfg['bank_account_number']['visible']) $v['bank_account_number'] = '';
    if (!$field_cfg['bank_account_holder']['visible']) $v['bank_account_holder'] = '';

    // Extra (non-matched) points - defensive re-validation of a dropdown
    // answer against its own options, same "never trust" reasoning as
    // aapSaveCaseInstructionAnswers() itself (Fixit's own form already
    // enforces this, but this function never assumes that held).
    $extra_fields = array_values(array_filter($ordered_points, function ($p) { return $p['matched_field_key'] === null; }));
    foreach ($extra_fields as $f) {
        if (($f['type'] ?? 'text') === 'dropdown' && !in_array($extra_posted[$f['key']] ?? '', $f['options'] ?? [], true)) {
            $extra_posted[$f['key']] = '';
        }
    }

    $physical_required = (int)$ct['physical_confirm_required'];
    $physical_status = $physical_required ? 'pending' : 'not_required';
    $requester_type = 'customer';
    $requesting_channel = '';
    // Same split AAP itself uses: the first note is the case's original
    // aap_cases.evidence_note, any further ones become aap_case_evidence rows (type 'note')
    // (inserted after the case row exists, below).
    $evidence_notes = array_values(array_filter(array_map('trim', (array)$evidence_notes), function ($n) { return $n !== ''; }));
    $evidence_note = $evidence_notes[0] ?? '';

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
        $case_type_id, $requester_type, $requesting_channel, $requester_staff_id, $requester_department_id,
        $v['customer_membership_id'], $v['customer_name'], $v['bank_id'], $v['bank_account_number'], $v['bank_account_holder'], $v['transaction_ref'], $evidence_note, $v['calculated_value'], $v['value_type'], $v['recommended_outcome'],
        $physical_required, $physical_status,
        $requester_staff_id, $now, $now, $fixit_record_id
    );
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }
    $new_id = $stmt->insert_id;
    $stmt->close();

    $case_ref = aapGenerateCaseRef($new_id);
    $conn->query("UPDATE aap_cases SET case_ref = '" . $conn->real_escape_string($case_ref) . "' WHERE id = " . $new_id);
    aapSnapshotCaseInstructions($conn, $new_id, $ct);

    aapSaveCaseInstructionAnswers($conn, $new_id, $extra_fields, $extra_posted);

    // Carry the Fixit report's own attachments over as this case's evidence.
    // Only trust a posted template id that really belongs to this Case Type.
    $valid_template_ids = array_map('intval', array_column(aapFetchCaseTypeTemplates($conn, $case_type_id), 'id'));
    $att_res = $conn->query("SELECT id, name, path FROM fixit_attachment WHERE fixit_record_id = " . (int)$fixit_record_id);
    while ($att_res && $att_row = $att_res->fetch_assoc()) {
        // fixit/add_report.php saves uploads under this fixed local folder
        // ($uploadDir2 there) - `path` is just the stored filename.
        $local_path = 'C:/webroot/fixit/' . $att_row['path'];
        $field_index = $point_attachment_ids[(int)$att_row['id']] ?? null;
        $point_key = ($field_index !== null) ? ($ordered_points[$field_index]['key'] ?? null) : null;
        $template_id = $template_attachment_ids[(int)$att_row['id']] ?? null;
        if ($template_id !== null && !in_array((int)$template_id, $valid_template_ids, true)) $template_id = null;
        aapAttachExistingFileAsEvidence($conn, $new_id, $local_path, $att_row['name'], $requester_staff_id, $now, $point_key, $template_id);
    }

    // Second and later Fixit notes -> this case's additional notes.
    foreach (array_slice($evidence_notes, 1) as $extra_note) {
        aapAddCaseNote($conn, $new_id, $extra_note, $requester_staff_id);
    }

    // The Fixit report's own Reference Link(s) -> this case's reference links.
    $link_stmt = $conn->prepare("INSERT INTO aap_case_evidence (case_id, type, url, label, created_by, timestamp) VALUES (?, 'link', ?, ?, ?, ?)");
    foreach ((array)$reference_links as $link) {
        $link_url = trim((string)($link['url'] ?? ''));
        if ($link_url === '') continue;
        $link_label = mb_substr(trim((string)($link['label'] ?? '')), 0, 150);
        $link_stmt->bind_param("issis", $new_id, $link_url, $link_label, $requester_staff_id, $now);
        $link_stmt->execute();
    }
    $link_stmt->close();

    aapLogAudit($conn, $new_id, 'case_raised', $requester_staff_id, "Case raised as $case_ref (" . $ct['case_type_name'] . ") - auto-created from Fixit report F$fixit_record_id's Required Information.");

    if (!$physical_required) {
        aapSubmitCaseForApproval($conn, $new_id, $case_type_id, $v['calculated_value'], $requester_department_id, $requester_staff_id, $now);
    }

    return $new_id;
}

// Execution-only attachments (aap_case_execution_attachments) - kept as a
// separate table from aap_case_evidence's 'file' rows (rather than a visibility flag on
// the same one) so a query that forgets to filter by visibility can never
// accidentally leak these to everyone else. Only shown/uploadable/
// downloadable to whoever can execute the case ($can_execute in
// aap_update.php) - the requester, approver, and everyone else viewing the
// case never sees this list exists.
// Returns ['attempted' => n, 'uploaded' => n] - same reasoning as
// aapUploadEvidenceFiles() above: lets the caller tell a real success from a
// NAS/network failure that silently dropped the file.
function aapUploadExecutionAttachments($conn, $case_id, $files, $uploaded_by, $now) {
    $result = ['attempted' => 0, 'uploaded' => 0];
    if (empty($files) || !is_array($files['name'])) {
        return $result;
    }

    $tmp_dir = __DIR__ . '/uploads/tmp/';
    if (!is_dir($tmp_dir)) {
        mkdir($tmp_dir, 0755, true);
    }

    $nas = corpNasConnect();
    foreach ($files['name'] as $i => $fname) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK || $fname === '') {
            continue;
        }
        $result['attempted']++;
        $safe_name  = preg_replace('/[^A-Za-z0-9._-]/', '_', $fname);
        $tmp_name   = $case_id . '_exec_' . time() . '_' . $i . '_' . $safe_name;
        $tmp_path   = $tmp_dir . $tmp_name;
        if (!move_uploaded_file($files['tmp_name'][$i], $tmp_path)) {
            continue;
        }

        $nas_path = $nas->upload($tmp_path, CORP_NAS_FOLDER, $tmp_name);
        unlink($tmp_path);
        if ($nas_path === false) {
            continue;
        }

        $stmt = $conn->prepare("INSERT INTO aap_case_execution_attachments (case_id, file_name, stored_name, uploaded_by, timestamp) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("issis", $case_id, $fname, $nas_path, $uploaded_by, $now);
        $stmt->execute();
        $stmt->close();
        $result['uploaded']++;
    }
    return $result;
}

// Builds a one-line warning to append to the flash message when one or more
// selected files failed to actually reach the NAS (see
// aapUploadEvidenceFiles()/aapUploadExecutionAttachments() above) - without
// this, a NAS/network failure looked identical to "nothing selected" from
// the user's side: no error shown, the audit log and success banner both
// still said the attachment was added, and the file was just silently gone.
function aapUploadFailureNote($upload_result) {
    if (!empty($upload_result['attempted']) && $upload_result['uploaded'] < $upload_result['attempted']) {
        $failed = $upload_result['attempted'] - $upload_result['uploaded'];
        return " Warning: $failed file(s) failed to upload — check your connection and try again.";
    }
    return '';
}

// Shared by aap_export_cases.php and aap_export_close_cases.php - the CSV
// header and column order for a Case Queue export must stay identical
// between the two so a "closed" export just adds one extra trailing column
// rather than reordering everything.
function aapCaseExportCsvHeader() {
    return [
        'Case Ref', 'Fixit Ref', 'Case Type', 'Department/Outlet', 'Verification Reference', 'Approval', 'Status', 'Raised', 'Closed',
        'Fixit Report', 'Fixit Report Description', 'Fixit Category', 'Fixit Outlet', 'Fixit Lodged By',
        'Calculated Value (Requestor)', 'Approved Value', 'Value Type',
        'Bank Name', 'Bank Account Number', 'Bank Account Holder Name',
        'Notes', 'Attachments',
    ];
}

// One CSV row for one case (a row from aapCaseSelectSql's result). Notes and
// attachments are flattened into single cells - a CSV row can't hold a
// nested repeating group. Execution-only attachments (aap_case_execution_
// attachments) are deliberately excluded - a CSV export has no equivalent of
// aap_update.php's $can_execute gate, so including them here would leak them
// to anyone who can export the case.
// Neutralizes CSV/formula injection (OWASP CSV Injection) - a free-text
// value starting with =, +, -, or @ is interpreted as a formula by Excel/
// Sheets when the exported file is opened, which could run arbitrary
// formulas (including ones that reach out to a URL) using nothing more than
// a case note or Fixit report someone typed. Prefixing with a tab keeps the
// visible text intact while stopping it from being read as a formula.
function aapCsvSafe($value) {
    if ($value === null || $value === '') return $value;
    $value = (string)$value;
    if (preg_match('/^[=+\-@]/', $value)) {
        return "\t" . $value;
    }
    return $value;
}

function aapCaseExportCsvRow($conn, $row) {
    $display_status = aapCaseDisplayStatus($row);
    $approval_display = aapApprovalStatusDisplay($row['approval_status']);
    $fixit = !empty($row['fixit_record_id']) ? aapFetchFixitRecord($conn, $row['fixit_record_id']) : null;

    $note_parts = [];
    // Not empty()/truthy - a note whose entire text is literally "0" is a
    // real note (empty()/"0" are both falsy for the string "0" in PHP).
    if ((string)$row['evidence_note'] !== '') {
        $note_parts[] = '[' . date('d-m-Y', strtotime($row['timestamp'])) . '] ' . $row['evidence_note'];
    }
    foreach (aapFetchCaseNotes($conn, $row['id']) as $note) {
        $note_parts[] = '[' . date('d-m-Y', strtotime($note['timestamp'])) . ' - ' . ($note['created_by_name'] ?: 'Unknown') . '] ' . $note['note'];
    }

    $attach_parts = [];
    $att_stmt = $conn->prepare("SELECT file_name FROM aap_case_evidence WHERE case_id = ? AND type = 'file' ORDER BY timestamp ASC");
    $att_stmt->bind_param("i", $row['id']);
    $att_stmt->execute();
    $att_res = $att_stmt->get_result();
    while ($att_row = $att_res->fetch_assoc()) {
        $attach_parts[] = $att_row['file_name'];
    }
    $att_stmt->close();

    return [
        $row['case_ref'],
        !empty($row['fixit_record_id']) ? 'F' . $row['fixit_record_id'] : '',
        $row['case_type_name'],
        $row['fixit_outlet_code'] ?: $row['case_type_department_name'],
        aapPhysicalStatusLabel($row['physical_confirm_status']),
        $approval_display['label'],
        $display_status['label'],
        date('d-m-Y', strtotime($row['timestamp'])),
        $row['closed_at'] ? date('d-m-Y', strtotime($row['closed_at'])) : '',
        aapCsvSafe($fixit['report'] ?? ''),
        aapCsvSafe($fixit['remark'] ?? ''),
        $fixit['category_name'] ?? '',
        $fixit['outlet_code'] ?? '',
        $fixit['lodged_by_name'] ?? '',
        $row['calculated_value'] !== null ? $row['calculated_value'] : '',
        $row['approved_value'] !== null ? $row['approved_value'] : '',
        $row['value_type'] ? ucfirst($row['value_type']) : '',
        $row['bank_name'] ?: '',
        $row['bank_account_number'] ?: '',
        aapCsvSafe($row['bank_account_holder'] ?: ''),
        aapCsvSafe(implode(' | ', $note_parts)),
        aapCsvSafe(implode(' | ', $attach_parts)),
    ];
}