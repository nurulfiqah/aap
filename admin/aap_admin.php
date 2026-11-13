<?php
ob_start();
require_once('../../lock_adv.php');
$connect = 1;
date_default_timezone_set('Asia/Kuala_Lumpur');
include('../../common/index_adv.php');
if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not available.");
}
require_once('../aap_lib.php');

// Admin-only (staff.aap = 1, full stop - see aapFetchIsSuperAdmin() in
// aap_lib.php) - neither a plain department/grade match, a Department
// Manager grant (staff.aap = 2, aap_department_managers), nor an OKR/ATEM
// SuperAdmin gets in here. Department Managers were removed from managing
// Case Types/Approval Units/Staff Assignments entirely (2026-10-13); grade
// >= 4, Digital Innovation, and the OKR/ATEM union were removed from AAP
// admin status entirely the same way (2026-10-19) - only staff.aap = 1
// counts now.
// Resolves the SuperAdmin/admin-level union used across every AAP page.
$aap_identity = aapResolveIdentity($conn, $id_user, $grade, $department);
$grade = $aap_identity['grade'];
$department = $aap_identity['department'];
$aap_dept_ids = $aap_identity['dept_ids'];
$aap_is_admin = $aap_identity['is_admin'];
$aap_is_superadmin = $aap_identity['is_superadmin'];
// Always true here - everyone who reaches this page is already an admin
// (see the die() below), so every department is in scope for them. Kept as
// its own variable (rather than inlining $aap_is_superadmin everywhere)
// since aapDeptInScope()/aapCaseTypeEditRights() etc. below still take it as
// a parameter.
$aap_can_manage_all_depts = $aap_is_superadmin;

if (!$aap_is_superadmin) {
    die("You don't have access to this page. Ask an admin to grant you AAP admin access.");
}

// PHP's default session handler locks the session file for the whole
// request - this page is hit repeatedly via AJAX below and never writes to
// $_SESSION itself, so releasing the lock here lets those requests (and
// everything else sharing this browser's session) run concurrently instead
// of queuing up behind whichever one happens to be mid-request.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// ---- Tier options for a picked Department - AJAX endpoint (JSON) feeding
// the Staff Tier picker's Tier dropdown with that department's own tiers
// (Approval Unit Master), or the shared Default list if it hasn't
// customized any. ----
if (isset($_GET['action']) && $_GET['action'] === 'get_tier_options' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    ob_end_clean();
    header('Content-Type: application/json');
    $dept_id = (int)($_GET['department_id'] ?? 0);
    if ($dept_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid department.']);
        exit;
    }
    echo json_encode(['success' => true, 'tiers' => aapFetchTierOptionsForDepartment($conn, $dept_id)]);
    exit;
}

// ---- Groups for a picked Department - AJAX endpoint (JSON) feeding the
// "Select Group" dropdown on the Level 2/3 Staff Tier picker, populated from
// that department's Groups (admin/aap_grouping_master.php). Deliberately
// restricted server-side (not just hidden in the form) - a full-scope admin
// gets every Group for any department, but a department-scoped user only
// gets the real list for their OWN department(s); for any other department
// (e.g. a Level 1 owner assigning Level 2/3 to a department they don't
// belong to) they get back only the Default (Universal) entry, same as if
// that department had no custom Groups at all - see
// aapCaseTypeAllowedGroupForAssignment() in aap_lib.php, which the
// save_case_type handler enforces again independently either way. ----
if (isset($_GET['action']) && $_GET['action'] === 'get_dept_groups' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    ob_end_clean();
    header('Content-Type: application/json');
    $dept_id = (int)($_GET['department_id'] ?? 0);
    if ($dept_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid department.']);
        exit;
    }
    $groups = aapCaseTypeAllowedGroupForAssignment($dept_id, $aap_dept_ids, $aap_can_manage_all_depts)
        ? aapFetchDepartmentGroupNames($conn, $dept_id)
        : [['id' => 0, 'group_name' => 'Default (Universal)', 'description' => '']];
    echo json_encode(['success' => true, 'groups' => $groups]);
    exit;
}

// ---- Staff + resolved Tier for a picked Group - AJAX endpoint (JSON) that
// the "Add" button uses to bulk-add every staff member in the Group into the
// Staff Tier table, each pre-filled at the tier they hold there. ----
if (isset($_GET['action']) && $_GET['action'] === 'get_group_staff_tiers' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    ob_end_clean();
    header('Content-Type: application/json');
    $dept_id = (int)($_GET['department_id'] ?? 0);
    // group_id 0 is the valid "Default (Universal)" sentinel for a
    // department with no Groups set up - only a missing/non-numeric param is
    // invalid, not 0 itself (unlike department_id, which is never 0).
    if ($dept_id <= 0 || !isset($_GET['group_id']) || !is_numeric($_GET['group_id'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid department or group.']);
        exit;
    }
    $group_id = (int)$_GET['group_id'];
    // Same restriction as get_dept_groups above, re-checked independently
    // here since this is a separate request a crafted call could hit
    // directly with an arbitrary group_id - a department-scoped user
    // requesting a specific Group they're not entitled to always falls back
    // to that department's Default (Universal) roster instead.
    if (!aapCaseTypeAllowedGroupForAssignment($dept_id, $aap_dept_ids, $aap_can_manage_all_depts)) {
        $group_id = 0;
    }
    echo json_encode(['success' => true, 'staff' => aapFetchGroupStaffTiers($conn, $dept_id, $group_id)]);
    exit;
}

// "Group 1: description" label for a Group id, or "Default (Universal)" for
// null/0 (no custom Group assigned) - used by the Case Type Registry list
// below to show which Group a section actually resolves to, not just how
// many staff rows it has.
function aapGroupLabel($conn, $group_id) {
    if (empty($group_id)) return 'Default (Universal)';
    $stmt = $conn->prepare("SELECT group_name, description FROM aap_approval_unit_tier_groups WHERE id = ?");
    $stmt->bind_param("i", $group_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return 'Default (Universal)';
    return $row['description'] !== '' ? ($row['group_name'] . ': ' . $row['description']) : $row['group_name'];
}

// "Department (Group label)" for a section's first row - all rows in one
// section always share the same department_id/group_id (only one Group per
// level), so the first row stands in for the whole section. '—' when the
// section has nobody assigned yet. HOD Approval Only has no Staff Tier rows
// at all (see aapSaveCaseTypeHodOnly() above) and no department stored
// either (resolved per case at approval time - see aapCanApprove() in
// aap_lib.php) - $hod_only covers that case instead of falling through to '—'.
function aapCaseTypeSectionLabel($conn, $tiers, $dept_names, $hod_only = false) {
    if ($hod_only) {
        return 'HOD Only (per case - whoever raised it)';
    }
    if (empty($tiers)) return '—';
    $dept_name = $dept_names[$tiers[0]['department_id']] ?? '—';
    return $dept_name . ' (' . aapGroupLabel($conn, $tiers[0]['group_id']) . ')';
}

$msg = "";
$msg_type = "";
// One-time flash from the save_case_type Post/Redirect/Get below - same
// pattern as aap_update.php's $_SESSION['aap_flash_msg'].
if (isset($_SESSION['aap_flash_msg'])) {
    $msg = $_SESSION['aap_flash_msg'];
    $msg_type = $_SESSION['aap_flash_msg_type'];
    unset($_SESSION['aap_flash_msg'], $_SESSION['aap_flash_msg_type']);
}
$now = date('Y-m-d H:i:s');

// A Case Type's Approval/Exclusion Staff Tier tables are serialized to JSON
// by js/aap_admin.js just before submit (see the form's submit listener) -
// each entry is {staff_id, department_id, group_id, tier}. Decoded here and
// replaced wholesale into aap_case_type_staff_tiers for that section -
// simpler and safer than diffing against the previous set row by row.
// group_id (from the Select Group picker) is stamped onto the row so the
// live approval gate (aapTierValueByName()/aapCanApprove() in aap_lib.php)
// can resolve the tier NAME against that exact Group instead of an
// ambiguous department-wide match - see sql/aap_master.sql's note on
// aap_case_type_staff_tiers. null (the "Default (Universal)" picker entry,
// or a legacy row) falls back to that lookup's old department/Universal
// chain.
function aapSaveCaseTypeStaffTiers($conn, $case_type_id, $section, $json, $actor_id, $now) {
    $conn->query("DELETE FROM aap_case_type_staff_tiers WHERE case_type_id = " . (int)$case_type_id . " AND section = '" . $conn->real_escape_string($section) . "'");
    $rows = json_decode($json ?? '', true);
    if (!is_array($rows)) return;
    $stmt = $conn->prepare("INSERT INTO aap_case_type_staff_tiers (case_type_id, section, department_id, group_id, staff_id, tier, created_by, timestamp) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($rows as $r) {
        $staff_id = (int)($r['staff_id'] ?? 0);
        $dept_id = (int)($r['department_id'] ?? 0);
        $group_id = isset($r['group_id']) && $r['group_id'] !== null && $r['group_id'] !== '' ? (int)$r['group_id'] : null;
        $tier_name = trim($r['tier'] ?? '');
        // Real tier names are Approval Unit Master names, not a fixed
        // Unlimited/Tier 1/Tier 2 enum - validate against whichever tier
        // names that exact Group offers (or the department/Universal chain
        // when there's no Group).
        $tier = ($dept_id > 0 && $tier_name !== '' && aapTierValueByName($conn, $dept_id, $tier_name, $group_id)['found']) ? $tier_name : null;
        if ($staff_id <= 0 || $dept_id <= 0 || $tier === null) continue;
        $stmt->bind_param("isiiisis", $case_type_id, $section, $dept_id, $group_id, $staff_id, $tier, $actor_id, $now);
        $stmt->execute();
    }
    $stmt->close();
}

// Authorizes + saves one Case Type's Level 2 ('approval') or Level 3
// ('execution') section against aapCaseTypeEditRights()'s rules - never
// trusts the client's disabled/hidden form fields alone, since a submission
// can be forged regardless of what the rendered form allowed.
//
// $existing_dept/$can_reassign/$can_edit_group come straight from
// aapCaseTypeEditRights() (aap_lib.php). Reassigning the section to a
// different department (or assigning it for the first time) requires
// $can_reassign; updating the roster within whichever department already
// holds it requires $can_edit_group instead. Not entitled at all -> the
// section is silently left untouched.
//
// Returns an error message to show the user, or null on success (including
// "not entitled, nothing to do").
function aapSaveCaseTypeStaffTierSection($conn, $case_type_id, $section, $json, $existing_dept, $can_reassign, $can_edit_group, $dept_ids, $can_manage_all, $actor_id, $now, $level_label) {
    $submitted_rows = json_decode($json ?? '', true);
    $submitted_rows = is_array($submitted_rows) ? $submitted_rows : [];
    $submitted_dept = !empty($submitted_rows) ? (int)($submitted_rows[0]['department_id'] ?? 0) : null;

    $is_reassignment_or_initial = ($existing_dept === null) || ($submitted_dept !== null && $submitted_dept !== $existing_dept);
    $allowed = $is_reassignment_or_initial ? $can_reassign : $can_edit_group;
    if (!$allowed) return null;

    if (empty($submitted_rows)) {
        return "At least one Group must be assigned under $level_label.";
    }

    if ($is_reassignment_or_initial && !aapCaseTypeAllowedGroupForAssignment($submitted_dept, $dept_ids, $can_manage_all)) {
        // Handing this level to a department the assigner doesn't belong to
        // - force that department's Default (Universal) roster instead of
        // trusting whatever specific Group/staff was posted.
        $forced_json = json_encode(aapDefaultUniversalTierRows($conn, $submitted_dept));
        aapSaveCaseTypeStaffTiers($conn, $case_type_id, $section, $forced_json, $actor_id, $now);
    } else {
        aapSaveCaseTypeStaffTiers($conn, $case_type_id, $section, $json, $actor_id, $now);
    }
    return null;
}

// Persists one level's "HOD Approval Only" tick (see aap_lib.php's
// aapCanApprove()/aapCanExecute()/aapFetchEligibleApproverIds()) - gated on
// the same $can_reassign right as the Department/Group picker itself (both
// are disabled together in the form), so a submitter without it can never
// flip this on/off; their submission is silently ignored here and whatever
// was already stored wins. No department is stored for this any more - HOD
// mode resolves the department fresh per case (whoever raised it), not one
// fixed on the Case Type. Returns whether the level ends up HOD-only after
// this save, so the caller knows whether to also touch the Staff Tier
// roster for that section.
// $level is 2 or 3 - both get their own independent HOD Approval Only
// toggle again as of 2026-10-19 (Level 3's own was briefly removed earlier
// the same day along with its old Exclusion-list design, before Level 3
// was made a full mirror of Level 2 - see aapCanExecute() in aap_lib.php).
function aapSaveCaseTypeHodOnly($conn, $case_type_id, $level, $posted_only, $can_reassign, $existing_only) {
    $final_only = $can_reassign ? (int)$posted_only : (int)$existing_only;
    $col_only = "level{$level}_hod_only";
    $stmt = $conn->prepare("UPDATE aap_case_types SET $col_only = ? WHERE id = ?");
    $stmt->bind_param("ii", $final_only, $case_type_id);
    $stmt->execute();
    $stmt->close();
    return (bool)$final_only;
}

// A point not matched to a Form Master field (aapCaseTypeFieldOrder() in
// aap_lib.php) becomes its own field on aap_add.php/aap_update.php, and on
// Fixit's "Required Information" (fixit/ajax.php) - this is its answer
// type, same Number/Dropdown/Textarea choice Form Master's own Additional
// Fields use (admin/aap_settings.php), just picked per-point here instead
// of per-field there. A point that DOES match a Form Master field ignores
// its own type entirely - that field's real structural input (text/number/
// select) is used instead, since it's bound to an existing aap_cases
// column (see aapCaseTypeReplaceFieldConfig() in aap_lib.php).
function aapSanitizeInstructionPointTypeOptions($row) {
    $type = in_array($row['type'] ?? null, ['text', 'number', 'dropdown', 'date', 'tick'], true) ? $row['type'] : 'text';
    $options = [];
    // js/aap_admin.js sends options as an array; a plain newline-separated
    // string is accepted too. (Casting the array straight to string used to
    // save every dropdown's options as just "Array".)
    $raw_options = $row['options'] ?? '';
    $raw_options = is_array($raw_options) ? $raw_options : preg_split('/\r\n|\r|\n/', (string)$raw_options);
    if ($type === 'dropdown') {
        foreach ($raw_options as $opt) {
            $opt = trim((string)$opt);
            if ($opt !== '' && !in_array($opt, $options, true)) $options[] = $opt;
        }
        // A dropdown with no real options left can't be answered - fall
        // back to Text rather than saving an unusable empty dropdown.
        if (empty($options)) $type = 'text';
    }
    // Defaults true - matches aapNormalizeInstructionPoint()'s own default
    // in aap_lib.php so a point saved before this Required toggle existed
    // keeps behaving exactly as before.
    $required = !array_key_exists('required', $row) ? true : !empty($row['required']);
    // Defaults false - matches aapNormalizeInstructionPoint()'s own default.
    $requires_attachment = !empty($row['requires_attachment']);
    return ['type' => $type, 'options' => $options, 'required' => $required, 'requires_attachment' => $requires_attachment];
}

// Re-validates the "Instruction for User" builder's serialized JSON
// (js/aap_admin.js's collectInstructions()) rather than trusting it as-is -
// a tampered/direct POST could submit anything. Expected shape: a list of
// {text, type, options, subs: [{text, type, options}, ...]} objects - 'text'
// is the main point, 'subs' its sub-points, each carrying its own
// type/options (see aapSanitizeInstructionPointTypeOptions() above);
// numbering (1, 1.1, 1.2, 2, ...) is derived from array order at display
// time (aap_add.php), never stored. Drops a main point entirely only if it
// has no text AND no non-empty sub-points - an empty main with real
// sub-points under it is kept (the admin may be using it purely as a
// grouping heading).
function aapSanitizeCaseTypeInstructions($json) {
    $rows = json_decode($json ?? '', true);
    if (!is_array($rows)) return '[]';
    $clean = [];
    foreach ($rows as $row) {
        $text = trim((string)($row['text'] ?? ''));
        $subs = [];
        foreach ((array)($row['subs'] ?? []) as $sub) {
            // Backward compatible with the pre-type schema, where a sub was
            // just a plain string.
            $sub_row = is_array($sub) ? $sub : ['text' => $sub];
            $sub_text = trim((string)($sub_row['text'] ?? ''));
            if ($sub_text === '') continue;
            $sub_type_opts = aapSanitizeInstructionPointTypeOptions($sub_row);
            $subs[] = ['text' => $sub_text, 'type' => $sub_type_opts['type'], 'options' => $sub_type_opts['options'], 'required' => $sub_type_opts['required'], 'requires_attachment' => $sub_type_opts['requires_attachment']];
        }
        if ($text === '' && empty($subs)) continue;
        $type_opts = aapSanitizeInstructionPointTypeOptions($row);
        $clean[] = ['text' => $text, 'type' => $type_opts['type'], 'options' => $type_opts['options'], 'required' => $type_opts['required'], 'requires_attachment' => $type_opts['requires_attachment'], 'subs' => $subs];
    }
    return json_encode($clean);
}

// ---- Case Type save (add or update) ----
if (isset($_POST['save_case_type'])) {
    $ctid = (int)$_POST['case_type_id'];
    $department_id = (int)$_POST['department_id_ct'];
    $name = trim($_POST['ct_name']);
    $physical_confirm_required = isset($_POST['physical_confirm_required']) ? 1 : 0;
    $description = trim($_POST['ct_description']);
    $instructions = aapSanitizeCaseTypeInstructions($_POST['ct_instructions_json'] ?? '');

    $existing_ct = $ctid > 0 ? aapFetchCaseType($conn, $ctid) : null;
    if ($ctid > 0 && !$existing_ct) {
        $msg = "Case Type not found."; $msg_type = "alpro-danger";
    } else {
        // Rights are computed against the Case Type's EXISTING department on
        // an update (aapCaseTypeEditRights() below) - can_edit_level1 must
        // not hinge on whatever department the submitter tried to move it
        // to. For a brand new Case Type there's no existing department yet,
        // so the newly-picked one is what's checked instead.
        $rights_ct_department = $existing_ct ? $existing_ct['department_id'] : $department_id;
        $rights = aapCaseTypeEditRights($conn, $ctid, $rights_ct_department, $aap_dept_ids, $aap_can_manage_all_depts);
        $any_right = $rights['can_edit_level1'] || $rights['can_reassign_level2'] || $rights['can_edit_level2_group']
                   || $rights['can_reassign_level3'] || $rights['can_edit_level3_group'];

        if (!$any_right) {
            $msg = "You don't have permission to manage this Case Type."; $msg_type = "alpro-danger";
        } elseif ($rights['can_edit_level1'] && ($name === '' || $department_id <= 0)) {
            $msg = "Case Type name and department are required."; $msg_type = "alpro-danger";
        } else {
            // Level 1 core fields - only touched if actually entitled; a
            // Level 2/3-only editor's submission leaves these columns
            // exactly as they already were.
            if ($ctid > 0) {
                if ($rights['can_edit_level1']) {
                    $stmt = $conn->prepare("UPDATE aap_case_types SET case_type_name=?, department_id=?, physical_confirm_required=?, description=?, instructions=? WHERE id=?");
                    $stmt->bind_param("siissi", $name, $department_id, $physical_confirm_required, $description, $instructions, $ctid);
                    $stmt->execute();
                    $stmt->close();
                }
                $target_ctid = $ctid;
            } else {
                // A brand new Case Type always requires Level 1 rights
                // (enforced by the required-field checks above, which only
                // fire when can_edit_level1 is true) - aapCaseTypeEditRights()
                // ties every right to can_edit_level1 when nothing exists
                // yet, so this branch is unreachable without it.
                $stmt = $conn->prepare("INSERT INTO aap_case_types (case_type_name, department_id, physical_confirm_required, description, instructions, recycle, timestamp) VALUES (?, ?, ?, ?, ?, 0, ?)");
                $stmt->bind_param("siisss", $name, $department_id, $physical_confirm_required, $description, $instructions, $now);
                $stmt->execute();
                $target_ctid = $stmt->insert_id;
                $stmt->close();
            }

            $level2_is_hod = aapSaveCaseTypeHodOnly(
                $conn, $target_ctid, 2,
                isset($_POST['level2_hod_only']), $rights['can_reassign_level2'], $existing_ct['level2_hod_only'] ?? 0
            );
            $level3_is_hod = aapSaveCaseTypeHodOnly(
                $conn, $target_ctid, 3,
                isset($_POST['level3_hod_only']), $rights['can_reassign_level3'], $existing_ct['level3_hod_only'] ?? 0
            );

            // Skip Level 2 - same population as the HOD toggle above.
            // Leaving Level 2 empty (no Group, not HOD-only) also means skip.
            $posted_level2_rows = json_decode($_POST['approval_tiers_json'] ?? '', true);
            $level2_left_empty = empty($posted_level2_rows) && !$level2_is_hod;
            $skip_approval = $rights['can_reassign_level2']
                ? (int)(isset($_POST['skip_approval']) || $level2_left_empty)
                : (int)($existing_ct['skip_approval'] ?? 0);
            $stmt = $conn->prepare("UPDATE aap_case_types SET skip_approval = ? WHERE id = ?");
            $stmt->bind_param("ii", $skip_approval, $target_ctid);
            $stmt->execute();
            $stmt->close();

            $err = null;
            if ($skip_approval) {
                // No Level 2 at all - nothing to save for its roster.
            } elseif ($level2_is_hod) {
                // No Staff Tier roster while HOD-only - clear any leftover
                // rows from before it was turned on so nothing stale lingers.
                $conn->query("DELETE FROM aap_case_type_staff_tiers WHERE case_type_id = " . (int)$target_ctid . " AND section = 'approval'");
            } else {
                $err = aapSaveCaseTypeStaffTierSection(
                    $conn, $target_ctid, 'approval', $_POST['approval_tiers_json'] ?? '',
                    $rights['level2_dept'], $rights['can_reassign_level2'], $rights['can_edit_level2_group'],
                    $aap_dept_ids, $aap_can_manage_all_depts, $id_user, $now, 'Level 2 - Approval Mode Assign'
                );
            }
            if ($err === null) {
                if ($level3_is_hod) {
                    $conn->query("DELETE FROM aap_case_type_staff_tiers WHERE case_type_id = " . (int)$target_ctid . " AND section = 'execution'");
                } else {
                    $err = aapSaveCaseTypeStaffTierSection(
                        $conn, $target_ctid, 'execution', $_POST['execution_tiers_json'] ?? '',
                        $rights['level3_dept'], $rights['can_reassign_level3'], $rights['can_edit_level3_group'],
                        $aap_dept_ids, $aap_can_manage_all_depts, $id_user, $now, 'Level 3 - Execution Team'
                    );
                }
            }

            if ($err !== null) {
                $msg = $err; $msg_type = "alpro-danger";
            } else {
                // Keeps Fixit's "AAP" wildcard Category dropdown in sync with
                // this Case Type's current name/active state - see
                // aapSyncFixitCategoryForCaseType() in aap_lib.php.
                aapSyncFixitCategoryForCaseType($conn, $target_ctid, $id_user);

                // Reference template file(s) - same Level 1 population as the
                // other core fields above (Instruction for User, physical
                // confirm, etc.), since these are part of what the Case Type
                // itself hands to whoever raises a case under it.
                if ($rights['can_edit_level1']) {
                    foreach ((array)($_POST['ct_template_remarks'] ?? []) as $tpl_id => $tpl_remark) {
                        aapUpdateCaseTypeTemplateRemark($conn, (int)$tpl_id, $target_ctid, (string)$tpl_remark);
                    }
                    foreach ((array)($_POST['ct_remove_templates'] ?? []) as $remove_id) {
                        aapDeleteCaseTypeTemplate($conn, (int)$remove_id, $target_ctid);
                    }
                    aapUploadCaseTypeTemplates($conn, $target_ctid, $_FILES['ct_templates'] ?? null, $id_user, $now);
                }

                $msg = ($ctid > 0) ? "Case Type updated." : "Case Type created."; $msg_type = "alpro-success";
            }
        }
    }

    // Post/Redirect/Get: bounce to a plain GET on the same Case Type so a
    // page refresh after submitting never resubmits this form - it was
    // re-running aapUploadCaseTypeTemplates() (and the staff-tier saves)
    // on every refresh, duplicating the uploaded Template Files each time.
    // $target_ctid only exists once the save actually got past validation;
    // fall back to the submitted $ctid (still correct for an update that
    // failed validation) or no id at all (a brand-new Case Type that never
    // got created).
    $_SESSION['aap_flash_msg'] = $msg;
    $_SESSION['aap_flash_msg_type'] = $msg_type;
    $redirect_ctid = $target_ctid ?? ($ctid > 0 ? $ctid : 0);
    header('Location: aap_admin.php' . ($redirect_ctid > 0 ? ('?edit_case_type=' . $redirect_ctid) : ''));
    exit;
}

// ---- Case Type recycle toggle ----
if (isset($_POST['toggle_case_type_recycle'])) {
    $ctid = (int)$_POST['case_type_id'];
    $toggle_ct = aapFetchCaseType($conn, $ctid);
    if ($toggle_ct && aapDeptInScope($toggle_ct['department_id'], $aap_dept_ids, $aap_can_manage_all_depts)) {
        $conn->query("UPDATE aap_case_types SET recycle = 1 - recycle WHERE id = " . $ctid);
        aapSyncFixitCategoryForCaseType($conn, $ctid, $id_user);
        $msg = "Case Type visibility updated."; $msg_type = "alpro-success";
    } else {
        $msg = "You can only manage Case Types for your own department."; $msg_type = "alpro-danger";
    }
}

// $edit_rights covers every section independently (aapCaseTypeEditRights() -
// aap_lib.php) - opening the edit form no longer requires owning Level 1;
// holding Level 2 or Level 3 alone is enough to get in, just with whichever
// sections aren't yours locked/read-only in the form below.
$edit_case_type = null;
$edit_rights = null;
$edit_case_type_templates = [];
if (isset($_GET['edit_case_type'])) {
    $edit_case_type = aapFetchCaseType($conn, (int)$_GET['edit_case_type']);
    if ($edit_case_type) {
        $edit_rights = aapCaseTypeEditRights($conn, $edit_case_type['id'], $edit_case_type['department_id'], $aap_dept_ids, $aap_can_manage_all_depts);
        $any_right = $edit_rights['can_edit_level1'] || $edit_rights['can_reassign_level2'] || $edit_rights['can_edit_level2_group']
                   || $edit_rights['can_reassign_level3'] || $edit_rights['can_edit_level3_group'];
        if (!$any_right) {
            $edit_case_type = null;
            $edit_rights = null;
            $msg = "You don't have permission to manage this Case Type."; $msg_type = "alpro-danger";
        } else {
            $edit_case_type_templates = aapFetchCaseTypeTemplates($conn, $edit_case_type['id']);
        }
    }
}
// No $edit_case_type (either no ?edit_case_type param, or access was denied
// above) means the form below renders in "create new" mode - full rights
// over everything, since whichever department the creator picks for Level 1
// is by definition their own (aapDeptInScope() at save time enforces this
// for real - this default is only ever used to decide what the form SHOWS).
if ($edit_rights === null) {
    $edit_rights = [
        'can_edit_level1' => true,
        'can_reassign_level2' => true,
        'can_reassign_level3' => true,
        'can_edit_level2_group' => true,
        'can_edit_level3_group' => true,
        'level2_dept' => null,
        'level3_dept' => null,
    ];
}

// Decoded once here (not left as a raw string for JS to parse) so a
// corrupted/pre-feature value in the DB (NULL, or old free-text from before
// this builder existed) degrades to an empty list instead of a JS parse
// error breaking the whole edit page.
$edit_instructions = [];
if ($edit_case_type) {
    $decoded = json_decode($edit_case_type['instructions'] ?? '', true);
    if (is_array($decoded)) $edit_instructions = $decoded;
}

$case_types = aapFetchCaseTypes($conn, true);
$departments = aapFetchDepartments($conn);

// Level 1's Department picker only offers departments a scoped user
// actually manages - full-list roles (grade>=4/SuperAdmin/Customer Support)
// still see every department; everyone else sees only their own. Level 2's
// Staff Tier picker, the Execution Team picker, and the $dept_names lookup
// below all stay on the unfiltered $departments - only who's ALLOWED TO OWN
// a Case Type is scoped, not who can be picked as its approver/executor.
$level1_departments = $aap_can_manage_all_depts
    ? $departments
    : array_values(array_filter($departments, function ($d) use ($aap_dept_ids) {
        return in_array((int)$d['id'], $aap_dept_ids, true);
    }));

// A department-scoped user who actually OWNS Level 1 here (creating a new
// Case Type, or editing one of their own) and has only ONE department they
// could ever pick has nothing to actually choose - show it as a plain fixed
// value instead of a "Select Department" + one real option dropdown.
// Someone managing more than one department still gets the dropdown, since
// there's a real choice to make there. Deliberately excludes an editor who
// DOESN'T own Level 1 (Level 2-only access) - their case is handled
// separately below, showing the Case Type's actual department (which may be
// outside this editor's own scope entirely, e.g. Academy while they only
// manage Finance) rather than substituting their own.
$level1_dept_locked = $edit_rights['can_edit_level1'] && !$aap_can_manage_all_depts && count($level1_departments) === 1;
$dept_names_all = array_column($departments, 'depart_name', 'id');

// The Case Type Registry table below lists every Case Type the current user
// is actually involved in - not just the ones they own at Level 1, but also
// any where their own department currently holds Level 2 (Approval) or is
// the assigned Level 3 Execution Team, since that department's own staff
// need to see (and, per aapCaseTypeEditRights() below, edit their own
// roster within) it too.
if (!$aap_can_manage_all_depts) {
    $case_types = array_values(array_filter($case_types, function ($ct) use ($conn, $aap_dept_ids) {
        if (in_array((int)$ct['department_id'], $aap_dept_ids, true)) return true;
        $level2_dept = aapCaseTypeSectionDepartment($conn, $ct['id'], 'approval');
        if ($level2_dept !== null && in_array($level2_dept, $aap_dept_ids, true)) return true;
        $level3_dept = aapCaseTypeSectionDepartment($conn, $ct['id'], 'execution');
        if ($level3_dept !== null && in_array($level3_dept, $aap_dept_ids, true)) return true;
        return false;
    }));
}

$aap_base = '../';
?>

<?php include('../aap_modern_head.php'); ?>

<div class="header">
  <b class="rtop"><b class="r1"></b><b class="r2"></b><b class="r3"></b><b class="r4"></b></b>
  <h1 class="headerH1"><img src="../img/logo.svg"> Case Type Registry (Admin)</h1>
  <b class="rbottom"><b class="r4"></b><b class="r3"></b><b class="r2"></b><b class="r1"></b></b>
</div>

<?php include('../aap_sidebar.php'); ?>

<?php if (!empty($msg)): ?>
    <div class="alpro-alert <?php echo $msg_type; ?> alpro-mt-20"><?php echo htmlspecialchars($msg); ?></div>
<?php endif; ?>

<!-- ===================== CASE TYPES ===================== -->
<div class="alpro-mt-20 aap-ct-card<?php echo $edit_case_type ? ' aap-ct-card-editing' : ''; ?>">
    <h3><?php echo $edit_case_type ? 'Edit Case Type' : 'Add Case Type'; ?></h3>
    <form method="post" action="" enctype="multipart/form-data">
        <input type="hidden" name="case_type_id" value="<?php echo $edit_case_type ? $edit_case_type['id'] : 0; ?>">
        <!-- Serialized by js/aap_admin.js just before submit from the Level 2/3
             Staff Tier tables below - see aapSaveCaseTypeStaffTiers() in the
             save_case_type handler above. -->
        <input type="hidden" name="approval_tiers_json" id="ct_approval_tiers_json">
        <input type="hidden" name="execution_tiers_json" id="ct_execution_tiers_json">

        <!-- Level 1 - locked read-only (with a hidden field carrying the
        existing value through submission) unless $edit_rights['can_edit_level1']
        - a Level 2/3-only editor (holds a level, but doesn't own Level 1)
        can open this form but must not be able to touch these fields; the
        save handler re-checks this server-side regardless. -->
        <p class="aap-ct-level-title">Level 1 - Case Department Assign<?php if (!$edit_rights['can_edit_level1']): ?> <span class="aap-admin-info-icon">i<span class="aap-admin-tooltip">Only the department that owns this Case Type can change these fields.</span></span><?php endif; ?></p>
        <div class="aap-ct-level aap-ct-level-1">
            <div class="aap-ct-field">
                <label>Case Type Name <span style="color:red;">*</span></label>
                <input class="alpro-input" type="text" name="ct_name" id="ct_name" value="<?php echo htmlspecialchars($edit_case_type['case_type_name'] ?? ''); ?>" required <?php echo $edit_rights['can_edit_level1'] ? '' : 'disabled'; ?>>
                <?php if (!$edit_rights['can_edit_level1']): ?><input type="hidden" name="ct_name" value="<?php echo htmlspecialchars($edit_case_type['case_type_name'] ?? ''); ?>"><?php endif; ?>
            </div>
            <div class="aap-ct-field">
                <label>Department <span style="color:red;">*</span></label>
                <?php if ($level1_dept_locked): ?>
                    <!-- Actor owns Level 1 and has only one department to ever
                    pick anyway (new Case Type, or editing their own) - no
                    real choice to make, so just show it. -->
                    <div class="alpro-input" style="background:#f1f3f5; display:flex; align-items:center;"><?php echo htmlspecialchars($level1_departments[0]['depart_name']); ?></div>
                    <input type="hidden" name="department_id_ct" id="ct-level1-department" value="<?php echo (int)$level1_departments[0]['id']; ?>">
                <?php elseif (!$edit_rights['can_edit_level1']): ?>
                    <!-- Editing via Level 2/3-only access - show the Case
                    Type's ACTUAL department (looked up against the full,
                    unfiltered department list, since it may be outside this
                    editor's own scope entirely), not a disabled dropdown
                    whose options are filtered to the editor's own
                    department(s) and so would never actually contain it. -->
                    <div class="alpro-input" style="background:#f1f3f5; display:flex; align-items:center;"><?php echo htmlspecialchars($dept_names_all[$edit_case_type['department_id']] ?? '—'); ?></div>
                    <input type="hidden" name="department_id_ct" id="ct-level1-department" value="<?php echo (int)($edit_case_type['department_id'] ?? 0); ?>">
                <?php else: ?>
                    <select class="alpro-input" name="department_id_ct" id="ct-level1-department" required>
                        <option value="">Select Department</option>
                        <?php foreach ($level1_departments as $dept): ?>
                            <option value="<?php echo $dept['id']; ?>" <?php echo (isset($edit_case_type['department_id']) && $edit_case_type['department_id'] == $dept['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($dept['depart_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </div>
            <div class="aap-ct-field">
                <label>Verification Reference
                    <span class="aap-admin-info-icon">i<span class="aap-admin-tooltip">When checked, a case raised under this Case Type must have the returned item tagged and confirmed at ACMM before it can reach the approval gate. Leave unchecked for cases with no physical item to return (e.g. points/credit adjustments).</span></span>
                </label>
                <div class="aap-ct-field-inline">
                    <input type="checkbox" name="physical_confirm_required" value="1" <?php echo !empty($edit_case_type['physical_confirm_required']) ? 'checked' : ''; ?> <?php echo $edit_rights['can_edit_level1'] ? '' : 'disabled'; ?>>
                    <?php if (!$edit_rights['can_edit_level1'] && !empty($edit_case_type['physical_confirm_required'])): ?><input type="hidden" name="physical_confirm_required" value="1"><?php endif; ?>
                </div>
            </div>
            <div class="aap-ct-field" style="grid-column: 1 / -1;">
                <!-- Boxed as its own sub-section within Level 1 (same visual
                weight as a standalone .aap-card) rather than blending in with
                the other plain Level 1 fields around it - position unchanged. -->
                <div class="aap-card" style="padding:16px;">
                    <label>Instruction for User <span class="aap-admin-info-icon">i<span class="aap-admin-tooltip">Shown to whoever raises a case under this Case Type, so they know what to prepare before submitting. Add main points and, under each, sub-points - numbering (1, 1.1, 1.2, 2, 2.1...) is automatic from the order you add them in.</span></span></label>

                    <?php if ($edit_rights['can_edit_level1']): ?>
                    <!-- Quick-add: tick a Form Master field to add it as a Main
                    Point without typing it by hand - untick removes that exact
                    Main Point again (js/aap_admin.js keeps this in sync with
                    the builder below, both ways). Customer Name/Membership ID
                    and Staff Name are mutually exclusive groups (a case is
                    either Customer or Staff, never both) - ticking one side
                    disables the other via data-group. -->
                    <?php $fm_instr_groups = ['customer_name' => 'customer', 'customer_membership_id' => 'customer', 'staff_name' => 'staff']; ?>
                    <?php $fm_config_labels = aapFetchFormMasterConfig($conn); // display only - data-field-label keeps the default for matching ?>
                    <div class="aap-modern">
                    <table class="alpro-table" width="100%" style="margin-bottom:10px;">
                        <tr><th>Field</th><th style="width:90px;">Add</th></tr>
                        <?php foreach (aapFormMasterFieldDefaults() as $fm_key => $fm_def): ?>
                            <tr>
                                <td style="font-size:13px; padding-top:6px; padding-bottom:6px;"><?php echo htmlspecialchars($fm_config_labels[$fm_key]['label'] ?? $fm_def['label']); ?></td>
                                <td align="center" style="padding-top:6px; padding-bottom:6px;"><input type="checkbox" class="ct-instr-field-toggle" data-field-label="<?php echo htmlspecialchars($fm_def['label']); ?>" <?php echo isset($fm_instr_groups[$fm_key]) ? 'data-group="' . htmlspecialchars($fm_instr_groups[$fm_key]) . '"' : ''; ?>></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php // Not Form Master fields - quick-add a point already set to the Date (calendar) type, see js/aap_admin.js. ?>
                        <?php foreach (['Start Date', 'End Date'] as $date_label): ?>
                            <tr>
                                <td style="font-size:13px; padding-top:6px; padding-bottom:6px;"><?php echo htmlspecialchars($date_label); ?> <span class="alpro-muted" style="font-size:11px;">(calendar)</span></td>
                                <td align="center" style="padding-top:6px; padding-bottom:6px;"><input type="checkbox" class="ct-instr-date-toggle" data-field-label="<?php echo htmlspecialchars($date_label); ?>"></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                    </div>
                    <?php endif; ?>

                    <div id="ct_instructions_builder" data-readonly="<?php echo $edit_rights['can_edit_level1'] ? '0' : '1'; ?>"></div>
                    <?php if ($edit_rights['can_edit_level1']): ?>
                    <button type="button" class="alpro-btn alpro-btn-grey" id="ct_instructions_add_main" style="margin-top:8px; padding:4px 12px; font-size:12px;">+ Add Main Point</button>
                    <?php endif; ?>
                    <input type="hidden" name="ct_instructions_json" id="ct_instructions_json" value="<?php echo htmlspecialchars($edit_case_type['instructions'] ?? '[]'); ?>">
                </div>
            </div>
            <div class="aap-ct-field" style="grid-column: 1 / -1;">
                <!-- A reference form/doc staff should download, fill in, and
                upload back as evidence - shown to whoever raises a case
                under this Case Type (aap_add.php, and Fixit's add_report.php
                for an AAP-linked category), right next to where they upload
                their own evidence/attachments. Same Level 1 population as
                Instruction for User above - see aapUploadCaseTypeTemplates()/
                aapFetchCaseTypeTemplates()/aapDeleteCaseTypeTemplate() in
                aap_lib.php. -->
                <div class="aap-card" style="padding:16px;">
                    <label>Template File(s) <span class="aap-admin-info-icon">i<span class="aap-admin-tooltip">Optional - a form or doc staff should download, fill in, and upload back as their evidence when raising a case under this Case Type. Shown as a download link on the Raise Case form and on Fixit's report form for an AAP-linked category.</span></span></label>

                    <?php if (!empty($edit_case_type_templates)): ?>
                    <ul class="aap-attach-list" style="margin-bottom:10px;">
                        <?php foreach ($edit_case_type_templates as $tpl): ?>
                            <li style="justify-content:flex-start;">
                                <?php if ($edit_rights['can_edit_level1']): ?>
                                    <input class="alpro-input" type="text" name="ct_template_remarks[<?php echo (int)$tpl['id']; ?>]" value="<?php echo htmlspecialchars($tpl['remark'] ?? ''); ?>" maxlength="255" placeholder="Remark (optional)" style="flex:1; min-width:0; margin:0;">
                                <?php elseif (!empty($tpl['remark'])): ?>
                                    <span style="flex:1; min-width:0; font-size:12px; color:#6c757d;"><?php echo htmlspecialchars($tpl['remark']); ?></span>
                                <?php endif; ?>
                                <a href="<?php echo htmlspecialchars(aapCaseTypeTemplateUrl($tpl['id'])); ?>" target="_blank" style="white-space:nowrap;"><i class="bi bi-file-earmark-arrow-down"></i> <?php echo htmlspecialchars($tpl['file_name']); ?></a>
                                <?php if ($edit_rights['can_edit_level1']): ?>
                                    <label style="font-weight:normal; font-size:12px; white-space:nowrap; margin:0;"><input type="checkbox" name="ct_remove_templates[]" value="<?php echo (int)$tpl['id']; ?>"> Remove</label>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>

                    <?php if ($edit_rights['can_edit_level1']): ?>
                        <input class="alpro-input" type="file" name="ct_templates[]" multiple>
                        <div style="font-size:12px; color:#6c757d; margin-top:4px;">A remark box appears next to each file once it's uploaded (Save, then edit again).</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Level 2 - the Department dropdown is locked to whichever
        department currently holds this level unless
        $edit_rights['can_reassign_level2'] (Level 1 owner/admin only, see
        aapCaseTypeEditRights() in aap_lib.php); the Group dropdown itself
        stays open whenever $edit_rights['can_edit_level2_group'] - the
        get_dept_groups AJAX endpoint below only ever offers a full Group
        list for a department the requester actually belongs to, silently
        falling back to Default (Universal) for any other, so a Level 1
        owner handing this level to a department they're not part of can
        never hand-pick that department's specific Group. -->
        <p class="aap-ct-level-title">Level 2 - Approval Mode Assign<?php if (!$edit_rights['can_reassign_level2'] && !$edit_rights['can_edit_level2_group']): ?> <span class="aap-admin-info-icon">i<span class="aap-admin-tooltip">Only the department holding Level 2 can change this.</span></span><?php endif; ?></p>
        <div class="aap-ct-level aap-ct-level-2">
            <div class="aap-ct-field" style="grid-column: 1 / -1;">
                <div class="aap-ct-field-inline" style="margin-bottom:8px;">
                    <input type="checkbox" name="skip_approval" id="ct_skip_approval" value="1" <?php echo !empty($edit_case_type['skip_approval']) ? 'checked' : ''; ?> <?php echo $edit_rights['can_reassign_level2'] ? '' : 'disabled'; ?>>
                    <label for="ct_skip_approval" style="display:inline; font-weight:normal;">Skip Level 2 <span class="aap-admin-info-icon">i<span class="aap-admin-tooltip">No approval step for this Case Type. Once a case is submitted it's auto-approved at its calculated value and goes straight to Level 3 - Execution Team. Leaving Level 2 empty (no Group, HOD Approval Only unticked) also skips it.</span></span></label>
                </div>
                <div id="ct_skip_note" style="display:none; padding:8px 12px; background:#f1f3f5; border-radius:6px; font-size:13px; color:#495057;">Level 2 is skipped - cases go straight to Level 3 (Execution).</div>
                <div id="ct_level2_body">
                <label>Staff Tier <span style="color:red;">*</span></label>
                <div class="aap-ct-field-inline" style="margin-bottom:8px;">
                    <input type="checkbox" name="level2_hod_only" id="ct_level2_hod_only" value="1" <?php echo !empty($edit_case_type['level2_hod_only']) ? 'checked' : ''; ?> <?php echo $edit_rights['can_reassign_level2'] ? '' : 'disabled'; ?>>
                    <label for="ct_level2_hod_only" style="display:inline; font-weight:normal;">HOD Approval Only <span class="aap-admin-info-icon">i<span class="aap-admin-tooltip">The Head of Department (from Alpro's HOD list) of whichever department actually raised the case becomes the sole approver for this level, for any value from 0 to Unlimited - resolved per case, not fixed here, so the same Case Type can route to a different HOD depending on who raised it. The Department/Group/Staff Tier roster below is ignored while this is checked.</span></span></label>
                </div>
                <div id="ct_hod_note" style="display:none; padding:8px 12px; background:#f1f3f5; border-radius:6px; font-size:13px; color:#495057; margin-bottom:8px;">Approver is resolved per case (the HOD of whoever raised it) - no department to pick here.</div>
                <div style="display:flex; gap:8px; align-items:center; margin-bottom:8px;" id="ct_deptgroup_row">
                    <select class="alpro-input" name="department_id_ct_lvl2" id="ct_pool_department" style="flex:1;" <?php echo $edit_rights['can_reassign_level2'] ? '' : 'disabled'; ?>>
                        <option value="">Select Department</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo $dept['id']; ?>" <?php echo ($edit_rights['level2_dept'] !== null && $edit_rights['level2_dept'] == $dept['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($dept['depart_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select class="alpro-input" id="ct_pool_group" style="flex:1;" <?php echo $edit_rights['can_edit_level2_group'] ? '' : 'disabled'; ?>>
                        <option value="">Select Group</option>
                    </select>
                </div>
                <div class="aap-modern" id="ct_staff_tier_wrap" <?php echo $edit_rights['can_edit_level2_group'] ? '' : 'style="display:none;"'; ?>>
                <table class="alpro-table aap-ct-list-table" id="ct_staff_tier_table" width="100%">
                    <thead><tr><th>Staff</th><th>Tier</th></tr></thead>
                    <tbody id="ct_staff_tier_tbody"></tbody>
                </table>
                </div>
                <?php if (!$edit_rights['can_edit_level2_group']): ?>
                <div style="padding:8px 12px; background:#f1f3f5; border-radius:6px; font-size:13px; color:#495057;">Staff list hidden - only the department holding Level 2 (or an admin) can view it.</div>
                <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Level 3 - Execution Team. As of 2026-10-19, structurally
        identical to Level 2 above (own HOD Approval Only toggle, own
        Department/Group/Staff Tier roster via aap_case_type_staff_tiers,
        section='execution') - just gates Execute instead of Approve, see
        aapCanExecute() in aap_lib.php. Independently delegable like Level
        2: the department holding Level 3 can curate its own roster once
        handed it, same $can_reassign_level3/$can_edit_level3_group rules
        as Level 2's own pair. -->
        <p class="aap-ct-level-title">Level 3 - Execution Team<?php if (!$edit_rights['can_reassign_level3'] && !$edit_rights['can_edit_level3_group']): ?> <span class="aap-admin-info-icon">i<span class="aap-admin-tooltip">Only the department holding Level 3 can change this.</span></span><?php endif; ?></p>
        <div class="aap-ct-level aap-ct-level-3">
            <div class="aap-ct-field" style="grid-column: 1 / -1;">
                <label>Staff Tier <span style="color:red;">*</span></label>
                <div class="aap-ct-field-inline" style="margin-bottom:8px;">
                    <input type="checkbox" name="level3_hod_only" id="ct_level3_hod_only" value="1" <?php echo !empty($edit_case_type['level3_hod_only']) ? 'checked' : ''; ?> <?php echo $edit_rights['can_reassign_level3'] ? '' : 'disabled'; ?>>
                    <label for="ct_level3_hod_only" style="display:inline; font-weight:normal;">HOD Execution Only <span class="aap-admin-info-icon">i<span class="aap-admin-tooltip">The Head of Department (from Alpro's HOD list) of whichever department actually raised the case becomes the sole executor for this level, for any value from 0 to Unlimited - resolved per case, not fixed here, so the same Case Type can route to a different HOD depending on who raised it. The Department/Group/Staff Tier roster below is ignored while this is checked.</span></span></label>
                </div>
                <div id="ct_hod_note_lvl3" style="display:none; padding:8px 12px; background:#f1f3f5; border-radius:6px; font-size:13px; color:#495057; margin-bottom:8px;">Executor is resolved per case (the HOD of whoever raised it) - no department to pick here.</div>
                <div style="display:flex; gap:8px; align-items:center; margin-bottom:8px;" id="ct_deptgroup_row_lvl3">
                    <select class="alpro-input" name="department_id_ct_lvl3" id="ct_pool_department_lvl3" style="flex:1;" <?php echo $edit_rights['can_reassign_level3'] ? '' : 'disabled'; ?>>
                        <option value="">Select Department</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo $dept['id']; ?>" <?php echo ($edit_rights['level3_dept'] !== null && $edit_rights['level3_dept'] == $dept['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($dept['depart_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select class="alpro-input" id="ct_pool_group_lvl3" style="flex:1;" <?php echo $edit_rights['can_edit_level3_group'] ? '' : 'disabled'; ?>>
                        <option value="">Select Group</option>
                    </select>
                </div>
                <div class="aap-modern" id="ct_staff_tier_wrap_lvl3" <?php echo $edit_rights['can_edit_level3_group'] ? '' : 'style="display:none;"'; ?>>
                <table class="alpro-table aap-ct-list-table" id="ct_staff_tier_table_lvl3" width="100%">
                    <thead><tr><th>Staff</th><th>Tier</th></tr></thead>
                    <tbody id="ct_staff_tier_tbody_lvl3"></tbody>
                </table>
                </div>
                <?php if (!$edit_rights['can_edit_level3_group']): ?>
                <div style="padding:8px 12px; background:#f1f3f5; border-radius:6px; font-size:13px; color:#495057;">Staff list hidden - only the department holding Level 3 (or an admin) can view it.</div>
                <?php endif; ?>
            </div>
            <div class="aap-ct-field" style="grid-column: 1 / -1;">
                <label>Description</label>
                <input class="alpro-input" type="text" name="ct_description" value="<?php echo htmlspecialchars($edit_case_type['description'] ?? ''); ?>" <?php echo $edit_rights['can_edit_level1'] ? '' : 'disabled'; ?>>
                <?php if (!$edit_rights['can_edit_level1']): ?><input type="hidden" name="ct_description" value="<?php echo htmlspecialchars($edit_case_type['description'] ?? ''); ?>"><?php endif; ?>
            </div>
        </div>

        <div class="alpro-actions alpro-mt-10" style="justify-content:flex-end;">
            <input class="alpro-btn alpro-btn-blue" type="submit" name="save_case_type" value="<?php echo $edit_case_type ? 'Update Case Type' : 'Create Case Type'; ?>">
            <?php if ($edit_case_type): ?><a href="aap_admin.php" class="alpro-btn alpro-btn-grey" style="text-decoration:none;">Cancel</a><?php endif; ?>
        </div>
    </form>
</div>

<div class="aap-modern">
<div class="aap-card alpro-mt-20">
    <h3 class="aap-card-title">Case Type Registry</h3>

    <style>
    /* Scoped to #ct_list_table only - tighter row height for this list, does
       not affect the Level 2/3 Staff Tier tables above which share the same
       .aap-ct-list-table class. */
    #ct_list_table th, #ct_list_table td { padding: 4px 10px !important; }
    #ct_list_table .alpro-badge { padding: 0 6px !important; font-size: 10px !important; }
    #ct_list_table .alpro-btn { padding: 2px 8px !important; font-size: 11px !important; }
    </style>

    <div id="ct_filter_bar" class="aap-filter-bar" style="display:grid; grid-template-columns:repeat(6, 1fr); padding-bottom:14px; border-bottom:1px solid #e5e9ec;">
        <input class="alpro-input aap-filter-input" type="text" id="ct_filter_name" placeholder="Search case type name...">
        <!-- Shown for every role now, not just full-list admins - a
             department-scoped user can still be in scope for more than one
             department ($aap_dept_ids), so this isn't always a single-value
             no-op the way the old gate assumed. -->
        <select class="alpro-input aap-filter-input" id="ct_filter_dept">
            <option value="">All Departments</option>
            <?php foreach ($level1_departments as $dept): ?>
            <option value="<?php echo htmlspecialchars($dept['depart_name']); ?>"><?php echo htmlspecialchars($dept['depart_name']); ?></option>
            <?php endforeach; ?>
        </select>
        <input class="alpro-input aap-filter-input" type="text" id="ct_filter_approval" placeholder="Approval staff...">
        <input class="alpro-input aap-filter-input" type="text" id="ct_filter_execution" placeholder="Execution staff...">
        <select class="alpro-input aap-filter-input" id="ct_filter_physical">
            <option value="">Physical: All</option>
            <option value="1">Required</option>
            <option value="0">Not Required</option>
        </select>
        <select class="alpro-input aap-filter-input" id="ct_filter_status">
            <option value="">Status: All</option>
            <option value="active">Active</option>
            <option value="retired">Inactive</option>
        </select>
    </div>

    <table class="alpro-table aap-ct-list-table" id="ct_list_table" width="100%">
        <thead><tr><th>ID</th><th>Case Type Name</th><th>Department</th><th>Approval Staff</th><th>Execution Team</th><th>Physical</th><th>Status</th><th>Action</th></tr></thead>
        <tbody id="ct_list_tbody">
        <?php $dept_names = array_column($departments, 'depart_name', 'id'); ?>
        <?php foreach ($case_types as $ct): ?>
        <?php
            $approval_tiers = aapFetchCaseTypeStaffTiers($conn, $ct['id'], 'approval');
            $execution_tiers = aapFetchCaseTypeStaffTiers($conn, $ct['id'], 'execution');
            $dept_name = $dept_names[$ct['department_id']] ?? '';
            $approval_names = implode(', ', array_column($approval_tiers, 'staff_name'));
            $execution_names = implode(', ', array_column($execution_tiers, 'staff_name'));
        ?>
        <tr class="ct-list-row"
            data-name="<?php echo htmlspecialchars(strtolower($ct['case_type_name'])); ?>"
            data-dept="<?php echo htmlspecialchars($dept_name); ?>"
            data-approval="<?php echo htmlspecialchars(strtolower($approval_names)); ?>"
            data-execution="<?php echo htmlspecialchars(strtolower($execution_names)); ?>"
            data-physical="<?php echo $ct['physical_confirm_required'] ? '1' : '0'; ?>"
            data-status="<?php echo $ct['recycle'] ? 'retired' : 'active'; ?>">
            <td class="alpro-mono"><?php echo (int)$ct['id']; ?></td>
            <td><?php echo htmlspecialchars($ct['case_type_name']); ?></td>
            <td><?php echo htmlspecialchars($dept_name ?: '—'); ?></td>
            <td><?php echo !empty($ct['skip_approval']) ? 'Skipped' : htmlspecialchars(aapCaseTypeSectionLabel($conn, $approval_tiers, $dept_names, !empty($ct['level2_hod_only']))); ?></td>
            <td><?php echo htmlspecialchars(aapCaseTypeSectionLabel($conn, $execution_tiers, $dept_names, !empty($ct['level3_hod_only']))); ?></td>
            <td><?php echo $ct['physical_confirm_required'] ? 'Required' : '—'; ?></td>
            <td><?php echo $ct['recycle'] ? '<span class="alpro-badge alpro-badge-voided">Inactive</span>' : '<span class="alpro-badge alpro-badge-approved">Active</span>'; ?></td>
            <td>
                <a href="?edit_case_type=<?php echo $ct['id']; ?>" class="alpro-btn alpro-btn-blue" style="text-decoration:none; padding:4px 10px; font-size:12px;">Edit</a>
                <?php if (aapDeptInScope($ct['department_id'], $aap_dept_ids, $aap_can_manage_all_depts)): ?>
                <form method="post" style="display:inline;">
                    <input type="hidden" name="case_type_id" value="<?php echo $ct['id']; ?>">
                    <input class="alpro-btn alpro-btn-grey" style="padding:4px 10px; font-size:12px;" type="submit" name="toggle_case_type_recycle" value="<?php echo $ct['recycle'] ? 'Restore' : 'Inactive'; ?>">
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div id="ct_pagination" class="aap-pagination-bar">
        <div class="aap-page-size-group">
            Show
            <select class="alpro-input aap-page-size-select" id="ct_page_size">
                <option value="10">10</option>
                <option value="25">25</option>
                <option value="30">30</option>
                <option value="50">50</option>
            </select>
            entries
        </div>
        <div class="aap-page-info">
            <span id="ct_page_showing" class="aap-page-showing"></span>
            <div id="ct_page_numbers" class="aap-page-numbers"></div>
        </div>
    </div>
</div>
</div>

<script>
var AAP_ADMIN = {
    isNew: <?php echo $edit_case_type ? 'false' : 'true'; ?>,
    // Withheld (empty) rather than sent and merely hidden by CSS, for
    // whoever can't edit that level's roster - e.g. Academy owning Level 1
    // must not see People Management's actual staff list for Level 2/3 just
    // because they can open this Case Type's edit page at all. Matches
    // can_edit_level2_group/can_edit_level3_group exactly: true for both the
    // department that actually holds the level AND any full-scope admin.
    approvalTiers: <?php echo json_encode($edit_case_type && $edit_rights['can_edit_level2_group'] ? aapFetchCaseTypeStaffTiers($conn, $edit_case_type['id'], 'approval') : []); ?>,
    executionTiers: <?php echo json_encode($edit_case_type && $edit_rights['can_edit_level3_group'] ? aapFetchCaseTypeStaffTiers($conn, $edit_case_type['id'], 'execution') : []); ?>,
    instructions: <?php echo json_encode($edit_instructions); ?>
};
</script>
<?php $page_js = '../js/aap_admin.js'; include('../aap_footer.php'); ?>