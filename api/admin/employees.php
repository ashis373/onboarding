<?php
// Endpoint: api/admin/employees.php
// Returns paginated employees with local Onboard/Moodle mapping, department, and advanced multi-dimensional filtering
// Fully optimized for zero Moodle API calls during search, pagination, and filter interactions.

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

$NEED_PDO = false;
require_once __DIR__ . '/../db.php';
if (file_exists(__DIR__ . '/../deadline_helper.php')) {
    require_once __DIR__ . '/../deadline_helper.php';
}
if (file_exists(__DIR__ . '/../certificate_helper.php')) {
    require_once __DIR__ . '/../certificate_helper.php';
}

if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { 
    echo json_encode(['success' => false, 'message' => 'Not logged in']); 
    exit; 
}

// Check if user is admin (user_role = 1 OR specific UIDs)
$isAdmin = false;
if (in_array((string)$user_id, ['26', '64', '205'], true)) {
    $isAdmin = true;
} else {
    $roleCheck = $conn->prepare("SELECT user_role FROM user WHERE uid = ?");
    if ($roleCheck) {
        $roleCheck->bind_param('i', $user_id);
        if ($roleCheck->execute()) {
            $roleRes = $roleCheck->get_result()->fetch_assoc();
            if (($roleRes['user_role'] ?? 0) == 1) $isAdmin = true;
        }
        $roleCheck->close();
    }
}

if (!$isAdmin) { 
    echo json_encode(['success' => false, 'message' => 'Unauthorized']); 
    exit; 
}

// Release session lock early to prevent blocking concurrent API requests
session_write_close();

// -------------------------------------------------------------
// Request Parameters
// -------------------------------------------------------------
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limitRaw = $_GET['limit'] ?? '10';
$limit = ($limitRaw === 'all' || $limitRaw === '0') ? 0 : max(1, min(100, intval($limitRaw)));
$search = trim($_GET['q'] ?? '');
$filter = strtolower(trim($_GET['filter'] ?? 'enrolled')); // Legacy tab: 'enrolled' | 'all' | 'not_enrolled' | 'completed'
$empType = strtolower(trim($_GET['emp_type'] ?? 'current')); // 'current' (default) | 'ex' | 'all'
$deptFilter = isset($_GET['dept']) ? intval($_GET['dept']) : 0;
$statusFilter = strtolower(trim($_GET['status_filter'] ?? $_GET['status'] ?? 'all')); // 'all' | 'not_started' | 'in_progress' | 'completed' | 'not_enrolled'
$lockedSubFilter = strtolower(trim($_GET['locked_subfilter'] ?? $_GET['subfilter'] ?? 'all')); // 'all' | 'courses_done' | 'courses_incomplete' | 'assess_attempted' | 'assess_never'
$subFilter = strtolower(trim($_GET['subfilter'] ?? $_GET['locked_subfilter'] ?? 'all'));
$assessmentFilter = strtolower(trim($_GET['assessment_status'] ?? 'all')); // 'all' | 'passed' | 'attempted' | 'never_attempted'

// Completion range parameters
$compRange = strtolower(trim($_GET['comp_range'] ?? 'all')); // 'all' | '0' | '1-25' | '26-50' | '51-75' | '76-99' | '100' | 'custom'
$compMin = null;
$compMax = null;

if (isset($_GET['comp_min']) && $_GET['comp_min'] !== '' && is_numeric($_GET['comp_min'])) {
    $compMin = max(0, min(100, intval($_GET['comp_min'])));
}
if (isset($_GET['comp_max']) && $_GET['comp_max'] !== '' && is_numeric($_GET['comp_max'])) {
    $compMax = max(0, min(100, intval($_GET['comp_max'])));
}

if ($compRange !== 'all') {
    switch ($compRange) {
        case '0':
            $compMin = 0;
            $compMax = 0;
            break;
        case '1-25':
            $compMin = 1;
            $compMax = 25;
            break;
        case '26-50':
            $compMin = 26;
            $compMax = 50;
            break;
        case '51-75':
            $compMin = 51;
            $compMax = 75;
            break;
        case '76-99':
            $compMin = 76;
            $compMax = 99;
            break;
        case '100':
            $compMin = 100;
            $compMax = 100;
            break;
    }
}

// -------------------------------------------------------------
// 1. Total Active Onboarding Modules (Excluding Quiz/Assessments)
// -------------------------------------------------------------
$totalModules = 0;
$mRes = $conn->query("
    SELECT COUNT(*) AS cnt 
    FROM `onboard_modules` 
    WHERE `is_active` = 1 
      AND `moodle_course_id` IS NOT NULL 
      AND `moodle_course_id` > 0
      AND (type != 'assessment' OR type IS NULL)
      AND LOWER(title) NOT LIKE '%assessment%'
      AND LOWER(title) NOT LIKE '%quiz%'
");
if ($mRes && $row = $mRes->fetch_assoc()) {
    $totalModules = intval($row['cnt']);
}
if ($totalModules <= 0) {
    $fallbackRes = $conn->query("SELECT COUNT(*) AS cnt FROM `onboard_modules` WHERE `is_active` = 1 AND `moodle_course_id` > 0");
    if ($fallbackRes && $fbRow = $fallbackRes->fetch_assoc()) {
        $totalModules = intval($fbRow['cnt']);
    }
}
if ($totalModules <= 0) {
    $totalModules = 3;
}

// -------------------------------------------------------------
// 2. Discover Available User Columns Dynamically
// -------------------------------------------------------------
$userCols = [];
if (!empty($_SESSION['user_table_cols']) && is_array($_SESSION['user_table_cols'])) {
    $userCols = $_SESSION['user_table_cols'];
} else {
    $cRes = $conn->query("SHOW COLUMNS FROM `user`");
    if ($cRes) {
        while ($cRow = $cRes->fetch_assoc()) {
            $userCols[] = strtolower($cRow['Field']);
        }
    }
    $_SESSION['user_table_cols'] = $userCols;
}

$hasLastActive = in_array('last_active_time', $userCols, true);
$lastActiveField = $hasLastActive ? "u.last_active_time" : "NULL AS last_active_time";

$hasOfcMail = in_array('ofc_mail', $userCols, true);
$mailField = $hasOfcMail ? "u.ofc_mail" : (in_array('email', $userCols, true) ? "u.email AS ofc_mail" : (in_array('personal_email', $userCols, true) ? "u.personal_email AS ofc_mail" : "'' AS ofc_mail"));

$hasProfileImg = in_array('profile_img', $userCols, true);
$imgField = $hasProfileImg ? "u.profile_img" : "NULL AS profile_img";

$hasJoiningDate = in_array('joiningdate', $userCols, true);
$joinField = $hasJoiningDate ? "u.joiningdate" : "NULL AS joiningdate";

$hasEmpCode = in_array('emp_code', $userCols, true);
$empCodeField = $hasEmpCode ? "u.emp_code" : "'' AS emp_code";

// -------------------------------------------------------------
// 3. Employment Status Condition (HexaLearn standard: status=0 active, status=1 ex-employee)
// -------------------------------------------------------------
if ($filter === 'not_enrolled' || $statusFilter === 'not_enrolled') {
    // Not Enrolled / Unlinked strictly refers to active current company staff who have not yet started onboarding
    $empStatusCond = "(IFNULL(u.status, 0) = 0 OR u.status = 'online')";
} elseif ($empType === 'ex') {
    $empStatusCond = "(IFNULL(u.status, 0) = 1 OR (u.status != 0 AND u.status != 'online'))";
} elseif ($empType === 'all') {
    $empStatusCond = "1=1";
} else {
    // Default: current employees only
    $empStatusCond = "(IFNULL(u.status, 0) = 0 OR u.status = 'online')";
}

// -------------------------------------------------------------
// 4. Fast Summary Metrics For Header Cards
// -------------------------------------------------------------
$mCountRes = $conn->query("
    SELECT 
        COUNT(DISTINCT CASE WHEN (IFNULL(u.status, 0) = 0 OR u.status = 'online') THEN m.hexa_uid END) AS current_enrolled,
        COUNT(DISTINCT CASE WHEN (IFNULL(u.status, 0) = 1 OR (u.status != 0 AND u.status != 'online')) THEN m.hexa_uid END) AS ex_enrolled,
        COUNT(DISTINCT m.hexa_uid) AS total_enrolled
    FROM onboard_moodle_users m
    LEFT JOIN user u ON u.uid = m.hexa_uid
");
$mRow = $mCountRes ? $mCountRes->fetch_assoc() : [];
$currentEnrolled = intval($mRow['current_enrolled'] ?? 0);
$exEnrolled = intval($mRow['ex_enrolled'] ?? 0);
$moodleEnrolled = $empType === 'ex' ? $exEnrolled : ($empType === 'all' ? intval($mRow['total_enrolled'] ?? 0) : $currentEnrolled);

$uCountRes = $conn->query("
    SELECT 
        COUNT(CASE WHEN (IFNULL(status, 0) = 0 OR status = 'online') THEN 1 END) AS current_staff,
        COUNT(CASE WHEN (IFNULL(status, 0) = 1 OR (status != 0 AND status != 'online')) THEN 1 END) AS ex_staff,
        COUNT(*) AS total_staff
    FROM user
");
$uRow = $uCountRes ? $uCountRes->fetch_assoc() : [];
$totalStaff = $empType === 'ex' ? intval($uRow['ex_staff'] ?? 0) : ($empType === 'all' ? intval($uRow['total_staff'] ?? 0) : intval($uRow['current_staff'] ?? 0));
$notEnrolled = max(0, intval($uRow['current_staff'] ?? 0) - $currentEnrolled);

$completedEmployees = 0;
if ($totalModules > 0) {
    $compRes = $conn->query("
        SELECT p.user_id, COUNT(DISTINCT m.id) AS done_cnt
        FROM onboard_module_progress p
        JOIN onboard_moodle_users m_usr ON m_usr.hexa_uid = p.user_id
        JOIN user u ON u.uid = m_usr.hexa_uid AND $empStatusCond
        INNER JOIN onboard_modules m ON (m.id = p.module_id OR (m.moodle_cmid > 0 AND m.moodle_cmid = p.module_id))
        WHERE (p.is_completed = 1 OR p.status = 'completed')
          AND p.status NOT IN ('incomplete', 'in_progress', 'suspend', 'browsed')
          AND m.is_active = 1
          AND m.moodle_course_id > 0
          AND (m.type != 'assessment' OR m.type IS NULL)
          AND LOWER(m.title) NOT LIKE '%assessment%'
          AND LOWER(m.title) NOT LIKE '%quiz%'
        GROUP BY p.user_id
        HAVING done_cnt >= $totalModules
    ");
    if ($compRes) {
        $completedEmployees = $compRes->num_rows;
    }
}

// Additional KPI Metrics for Unified Dashboard
$certifiedEmployees = 0;
$certRes = $conn->query("
    SELECT COUNT(DISTINCT a.user_id) AS cnt 
    FROM onboard_induction_assessment_attempts a
    JOIN user u ON u.uid = a.user_id AND $empStatusCond
    WHERE a.result = 'Passed'
");
if ($certRes && $cRow = $certRes->fetch_assoc()) {
    $certifiedEmployees = intval($cRow['cnt']);
}

$lockedEmployees = 0;
$lockedRes = $conn->query("
    SELECT COUNT(DISTINCT d.user_id) AS cnt
    FROM onboard_employee_deadlines d
    JOIN user u ON u.uid = d.user_id AND $empStatusCond
    LEFT JOIN (
        SELECT user_id, 1 AS passed 
        FROM onboard_induction_assessment_attempts 
        WHERE result = 'Passed' 
        GROUP BY user_id
    ) a ON a.user_id = d.user_id
    WHERE a.passed IS NULL
      AND (
        (d.extended_deadline IS NOT NULL AND d.extended_deadline < NOW())
        OR (d.extended_deadline IS NULL AND d.deadline_date < NOW())
      )
");
if ($lockedRes && $lRow = $lockedRes->fetch_assoc()) {
    $lockedEmployees = intval($lRow['cnt']);
}

$downloadedEmployees = 0;
$dlRes = $conn->query("
    SELECT COUNT(DISTINCT dl.user_id) AS cnt 
    FROM onboard_certificate_downloads dl
    JOIN user u ON u.uid = dl.user_id AND $empStatusCond
");
if ($dlRes && $dlRow = $dlRes->fetch_assoc()) {
    $downloadedEmployees = intval($dlRow['cnt']);
}

// Sub-breakdown for Deadline Locked employees (Courses done vs incomplete, assessment attempted vs never)
$lockedBreakdown = [
    'all' => $lockedEmployees,
    'courses_done' => 0,
    'courses_incomplete' => 0,
    'assess_attempted' => 0,
    'assess_never' => 0
];
try {
    $lockedSubRes = $conn->query("
        SELECT 
            COUNT(*) AS total_locked,
            SUM(CASE WHEN IFNULL(prg.done_cnt, 0) >= $totalModules THEN 1 ELSE 0 END) AS cnt_courses_done,
            SUM(CASE WHEN IFNULL(prg.done_cnt, 0) < $totalModules THEN 1 ELSE 0 END) AS cnt_courses_incomplete,
            SUM(CASE WHEN att.user_id IS NOT NULL THEN 1 ELSE 0 END) AS cnt_assess_attempted,
            SUM(CASE WHEN att.user_id IS NULL THEN 1 ELSE 0 END) AS cnt_assess_never
        FROM onboard_employee_deadlines d
        JOIN user u ON u.uid = d.user_id AND $empStatusCond
        LEFT JOIN (
            SELECT user_id FROM onboard_induction_assessment_attempts WHERE result = 'Passed' OR Quiz_score >= 80 GROUP BY user_id
        ) p ON p.user_id = d.user_id
        LEFT JOIN (
            SELECT p.user_id, COUNT(DISTINCT om.id) AS done_cnt
            FROM onboard_module_progress p
            INNER JOIN onboard_modules om ON (om.id = p.module_id OR (om.moodle_cmid > 0 AND om.moodle_cmid = p.module_id))
            WHERE (p.is_completed = 1 OR p.status = 'completed')
              AND p.status NOT IN ('incomplete', 'in_progress', 'suspend', 'browsed')
              AND om.is_active = 1
              AND om.moodle_course_id > 0
              AND (om.type != 'assessment' OR om.type IS NULL)
              AND LOWER(om.title) NOT LIKE '%assessment%'
              AND LOWER(om.title) NOT LIKE '%quiz%'
            GROUP BY p.user_id
        ) prg ON prg.user_id = u.uid
        LEFT JOIN (
            SELECT DISTINCT user_id FROM onboard_induction_assessment_attempts
        ) att ON att.user_id = u.uid
        WHERE p.user_id IS NULL
          AND (
            (d.extended_deadline IS NOT NULL AND d.extended_deadline < NOW())
            OR (d.extended_deadline IS NULL AND d.deadline_date < NOW())
          )
    ");
    if ($lockedSubRes && $sbRow = $lockedSubRes->fetch_assoc()) {
        $lockedBreakdown['all'] = intval($sbRow['total_locked']);
        $lockedBreakdown['courses_done'] = intval($sbRow['cnt_courses_done']);
        $lockedBreakdown['courses_incomplete'] = intval($sbRow['cnt_courses_incomplete']);
        $lockedBreakdown['assess_attempted'] = intval($sbRow['cnt_assess_attempted']);
        $lockedBreakdown['assess_never'] = intval($sbRow['cnt_assess_never']);
    }
} catch (\Throwable $e) {
    // Graceful fallback
}

// Sub-breakdown for Enrolled employees
$enrolledBreakdown = [
    'all' => $currentEnrolled,
    'courses_done' => 0,
    'courses_incomplete' => 0,
    'assess_passed' => 0,
    'assess_failed' => 0,
    'assess_never' => 0
];
try {
    $enrSubRes = $conn->query("
        SELECT 
            COUNT(*) AS total_enrolled,
            SUM(CASE WHEN IFNULL(prg.done_cnt, 0) >= $totalModules THEN 1 ELSE 0 END) AS cnt_courses_done,
            SUM(CASE WHEN IFNULL(prg.done_cnt, 0) < $totalModules THEN 1 ELSE 0 END) AS cnt_courses_incomplete,
            SUM(CASE WHEN pass.user_id IS NOT NULL THEN 1 ELSE 0 END) AS cnt_assess_passed,
            SUM(CASE WHEN att.user_id IS NOT NULL AND pass.user_id IS NULL THEN 1 ELSE 0 END) AS cnt_assess_failed,
            SUM(CASE WHEN att.user_id IS NULL THEN 1 ELSE 0 END) AS cnt_assess_never
        FROM onboard_moodle_users m
        JOIN user u ON u.uid = m.hexa_uid AND $empStatusCond
        LEFT JOIN (
            SELECT p.user_id, COUNT(DISTINCT om.id) AS done_cnt
            FROM onboard_module_progress p
            INNER JOIN onboard_modules om ON (om.id = p.module_id OR (om.moodle_cmid > 0 AND om.moodle_cmid = p.module_id))
            WHERE (p.is_completed = 1 OR p.status = 'completed')
              AND p.status NOT IN ('incomplete', 'in_progress', 'suspend', 'browsed')
              AND om.is_active = 1
              AND om.moodle_course_id > 0
              AND (om.type != 'assessment' OR om.type IS NULL)
              AND LOWER(om.title) NOT LIKE '%assessment%'
              AND LOWER(om.title) NOT LIKE '%quiz%'
            GROUP BY p.user_id
        ) prg ON prg.user_id = u.uid
        LEFT JOIN (
            SELECT user_id FROM onboard_induction_assessment_attempts WHERE result = 'Passed' OR Quiz_score >= 80 GROUP BY user_id
        ) pass ON pass.user_id = u.uid
        LEFT JOIN (
            SELECT DISTINCT user_id FROM onboard_induction_assessment_attempts
        ) att ON att.user_id = u.uid
    ");
    if ($enrSubRes && $erRow = $enrSubRes->fetch_assoc()) {
        $enrolledBreakdown['all'] = intval($erRow['total_enrolled']);
        $enrolledBreakdown['courses_done'] = intval($erRow['cnt_courses_done']);
        $enrolledBreakdown['courses_incomplete'] = intval($erRow['cnt_courses_incomplete']);
        $enrolledBreakdown['assess_passed'] = intval($erRow['cnt_assess_passed']);
        $enrolledBreakdown['assess_failed'] = intval($erRow['cnt_assess_failed']);
        $enrolledBreakdown['assess_never'] = intval($erRow['cnt_assess_never']);
    }
} catch (\Throwable $e) {
}

// Sub-breakdown for Courses Completed (45 finished all 10 courses)
$completedBreakdown = [
    'all' => $completedEmployees,
    'assess_passed' => 0,
    'assess_failed' => 0,
    'assess_never' => 0
];
try {
    $compSubRes = $conn->query("
        SELECT 
            COUNT(*) AS total_completed,
            SUM(CASE WHEN pass.user_id IS NOT NULL THEN 1 ELSE 0 END) AS cnt_assess_passed,
            SUM(CASE WHEN att.user_id IS NOT NULL AND pass.user_id IS NULL THEN 1 ELSE 0 END) AS cnt_assess_failed,
            SUM(CASE WHEN att.user_id IS NULL THEN 1 ELSE 0 END) AS cnt_assess_never
        FROM onboard_moodle_users m
        JOIN user u ON u.uid = m.hexa_uid AND $empStatusCond
        INNER JOIN (
            SELECT p.user_id, COUNT(DISTINCT om.id) AS done_cnt
            FROM onboard_module_progress p
            INNER JOIN onboard_modules om ON (om.id = p.module_id OR (om.moodle_cmid > 0 AND om.moodle_cmid = p.module_id))
            WHERE (p.is_completed = 1 OR p.status = 'completed')
              AND p.status NOT IN ('incomplete', 'in_progress', 'suspend', 'browsed')
              AND om.is_active = 1
              AND om.moodle_course_id > 0
              AND (om.type != 'assessment' OR om.type IS NULL)
              AND LOWER(om.title) NOT LIKE '%assessment%'
              AND LOWER(om.title) NOT LIKE '%quiz%'
            GROUP BY p.user_id
            HAVING done_cnt >= $totalModules
        ) prg ON prg.user_id = u.uid
        LEFT JOIN (
            SELECT user_id FROM onboard_induction_assessment_attempts WHERE result = 'Passed' OR Quiz_score >= 80 GROUP BY user_id
        ) pass ON pass.user_id = u.uid
        LEFT JOIN (
            SELECT DISTINCT user_id FROM onboard_induction_assessment_attempts
        ) att ON att.user_id = u.uid
    ");
    if ($compSubRes && $cpRow = $compSubRes->fetch_assoc()) {
        $completedBreakdown['all'] = intval($cpRow['total_completed']);
        $completedBreakdown['assess_passed'] = intval($cpRow['cnt_assess_passed']);
        $completedBreakdown['assess_failed'] = intval($cpRow['cnt_assess_failed']);
        $completedBreakdown['assess_never'] = intval($cpRow['cnt_assess_never']);
    }
} catch (\Throwable $e) {
}

// -------------------------------------------------------------
// 5. Construct Filtered WHERE Clauses in SQL
// -------------------------------------------------------------
$whereParts = [$empStatusCond];

// A. Department filter
if ($deptFilter > 0) {
    $whereParts[] = "u.department = " . $deptFilter;
}

// B. Search filter (multi-field with debounce on frontend)
if ($search !== '') {
    $s = $conn->real_escape_string($search);
    $searchParts = [
        "u.firstname LIKE '%$s%'",
        "u.lastname LIKE '%$s%'",
        "CONCAT(u.firstname, ' ', u.lastname) LIKE '%$s%'",
        "m.username LIKE '%$s%'"
    ];
    if ($hasOfcMail) {
        $searchParts[] = "u.ofc_mail LIKE '%$s%'";
    }
    if (in_array('personal_email', $userCols, true)) {
        $searchParts[] = "u.personal_email LIKE '%$s%'";
    }
    if (in_array('email', $userCols, true)) {
        $searchParts[] = "u.email LIKE '%$s%'";
    }
    if ($hasEmpCode) {
        $searchParts[] = "u.emp_code LIKE '%$s%'";
    }
    if (is_numeric($s)) {
        $searchParts[] = "u.uid = " . intval($s);
    }
    $whereParts[] = "(" . implode(' OR ', $searchParts) . ")";
}

// C. Status Filter (takes precedence over legacy tab if specified)
if ($statusFilter !== 'all') {
    if ($statusFilter === 'completed') {
        $whereParts[] = "m.id IS NOT NULL AND IFNULL(prg.done_cnt, 0) >= $totalModules";
    } elseif ($statusFilter === 'in_progress') {
        $whereParts[] = "m.id IS NOT NULL AND ((IFNULL(prg.done_cnt, 0) > 0 AND IFNULL(prg.done_cnt, 0) < $totalModules) OR (IFNULL(prg.done_cnt, 0) = 0 AND IFNULL(act.has_started, 0) = 1))";
    } elseif ($statusFilter === 'not_started') {
        $whereParts[] = "m.id IS NOT NULL AND IFNULL(prg.done_cnt, 0) = 0 AND IFNULL(act.has_started, 0) = 0";
    } elseif ($statusFilter === 'certified') {
        $whereParts[] = "u.uid IN (SELECT user_id FROM onboard_induction_assessment_attempts WHERE result = 'Passed')";
    } elseif ($statusFilter === 'locked') {
        $whereParts[] = "u.uid IN (SELECT d.user_id FROM onboard_employee_deadlines d LEFT JOIN (SELECT user_id FROM onboard_induction_assessment_attempts WHERE result = 'Passed') a ON a.user_id = d.user_id WHERE a.user_id IS NULL AND ((d.extended_deadline IS NOT NULL AND d.extended_deadline < NOW()) OR (d.extended_deadline IS NULL AND d.deadline_date < NOW())))";
    } elseif ($statusFilter === 'downloaded') {
        $whereParts[] = "u.uid IN (SELECT user_id FROM onboard_certificate_downloads)";
    } elseif ($statusFilter === 'not_enrolled') {
        $whereParts[] = "m.id IS NULL";
    }
} else {
    // Apply legacy filter tab if status_filter is 'all'
    if ($filter === 'not_enrolled') {
        $whereParts[] = "m.id IS NULL";
    } elseif ($filter === 'completed') {
        $whereParts[] = "m.id IS NOT NULL AND IFNULL(prg.done_cnt, 0) >= $totalModules";
    } elseif ($filter === 'certified') {
        $whereParts[] = "u.uid IN (SELECT user_id FROM onboard_induction_assessment_attempts WHERE result = 'Passed')";
    } elseif ($filter === 'locked') {
        $whereParts[] = "u.uid IN (SELECT d.user_id FROM onboard_employee_deadlines d LEFT JOIN (SELECT user_id FROM onboard_induction_assessment_attempts WHERE result = 'Passed') a ON a.user_id = d.user_id WHERE a.user_id IS NULL AND ((d.extended_deadline IS NOT NULL AND d.extended_deadline < NOW()) OR (d.extended_deadline IS NULL AND d.deadline_date < NOW())))";
    } elseif ($filter === 'downloaded') {
        $whereParts[] = "u.uid IN (SELECT user_id FROM onboard_certificate_downloads)";
    } elseif ($filter === 'all') {
        // Show all
    } else {
        // Default 'enrolled'
        $whereParts[] = "m.id IS NOT NULL";
    }
}

// D. Completion Percentage Filter
if ($compMin !== null && $compMax !== null) {
    $pctExpr = "IF(m.id IS NULL, 0, LEAST(100, ROUND((IFNULL(prg.done_cnt, 0) / $totalModules) * 100)))";
    $whereParts[] = "($pctExpr BETWEEN $compMin AND $compMax)";
    if ($compMin > 0) {
        $whereParts[] = "m.id IS NOT NULL";
    }
}

// E. Subfilter (Works across Enrolled, Completed, Locked, and All Staff)
if (!empty($subFilter) && $subFilter !== 'all') {
    if ($subFilter === 'enrolled') {
        $whereParts[] = "m.id IS NOT NULL";
    } elseif ($subFilter === 'not_enrolled') {
        $whereParts[] = "m.id IS NULL";
    } elseif ($subFilter === 'courses_done') {
        $whereParts[] = "IFNULL(prg.done_cnt, 0) >= $totalModules";
    } elseif ($subFilter === 'courses_incomplete') {
        $whereParts[] = "IFNULL(prg.done_cnt, 0) < $totalModules";
    } elseif ($subFilter === 'assess_passed') {
        $whereParts[] = "u.uid IN (SELECT user_id FROM onboard_induction_assessment_attempts WHERE result = 'Passed' OR Quiz_score >= 80)";
    } elseif ($subFilter === 'assess_failed') {
        $whereParts[] = "u.uid IN (SELECT user_id FROM onboard_induction_assessment_attempts WHERE result = 'Failed' AND user_id NOT IN (SELECT user_id FROM onboard_induction_assessment_attempts WHERE result = 'Passed' OR Quiz_score >= 80))";
    } elseif ($subFilter === 'assess_attempted') {
        $whereParts[] = "u.uid IN (SELECT user_id FROM onboard_induction_assessment_attempts)";
    } elseif ($subFilter === 'assess_never') {
        $whereParts[] = "u.uid NOT IN (SELECT user_id FROM onboard_induction_assessment_attempts)";
    } elseif ($subFilter === 'locked') {
        $whereParts[] = "u.uid IN (SELECT d.user_id FROM onboard_employee_deadlines d LEFT JOIN (SELECT user_id FROM onboard_induction_assessment_attempts WHERE result = 'Passed') a ON a.user_id = d.user_id WHERE a.user_id IS NULL AND ((d.extended_deadline IS NOT NULL AND d.extended_deadline < NOW()) OR (d.extended_deadline IS NULL AND d.deadline_date < NOW())))";
    }
}

// F. Assessment Status Filter
if (!empty($assessmentFilter) && $assessmentFilter !== 'all') {
    if ($assessmentFilter === 'passed') {
        $whereParts[] = "u.uid IN (SELECT user_id FROM onboard_induction_assessment_attempts WHERE result = 'Passed' OR Quiz_score >= 80)";
    } elseif ($assessmentFilter === 'attempted') {
        $whereParts[] = "u.uid IN (SELECT user_id FROM onboard_induction_assessment_attempts)";
    } elseif ($assessmentFilter === 'never_attempted') {
        $whereParts[] = "u.uid NOT IN (SELECT user_id FROM onboard_induction_assessment_attempts)";
    }
}

$whereClause = "WHERE " . implode(' AND ', $whereParts);

// Common SQL Subquery Joins for progress and activity tracking
$fromAndJoins = "
    FROM user u
    LEFT JOIN department d ON d.id = u.department
    LEFT JOIN onboard_moodle_users m ON m.hexa_uid = u.uid
    LEFT JOIN (
        SELECT p.user_id, COUNT(DISTINCT om.id) AS done_cnt
        FROM onboard_module_progress p
        INNER JOIN onboard_modules om ON (om.id = p.module_id OR (om.moodle_cmid > 0 AND om.moodle_cmid = p.module_id))
        WHERE (p.is_completed = 1 OR p.status = 'completed')
          AND p.status NOT IN ('incomplete', 'in_progress', 'suspend', 'browsed')
          AND om.is_active = 1
          AND om.moodle_course_id > 0
          AND (om.type != 'assessment' OR om.type IS NULL)
          AND LOWER(om.title) NOT LIKE '%assessment%'
          AND LOWER(om.title) NOT LIKE '%quiz%'
        GROUP BY p.user_id
    ) prg ON (prg.user_id = u.uid OR (m.hexa_uid IS NOT NULL AND prg.user_id = m.hexa_uid))
    LEFT JOIN (
        SELECT p.user_id, 1 AS has_started
        FROM onboard_module_progress p
        INNER JOIN onboard_modules om ON (om.id = p.module_id OR (om.moodle_cmid > 0 AND om.moodle_cmid = p.module_id))
        WHERE om.is_active = 1 AND om.moodle_course_id > 0
          AND (p.attempts > 0 OR p.progress_percent > 0 OR p.status = 'in_progress' OR p.time_spent_seconds > 0)
        GROUP BY p.user_id
    ) act ON (act.user_id = u.uid OR (m.hexa_uid IS NOT NULL AND act.user_id = m.hexa_uid))
";

// -------------------------------------------------------------
// 6. Total Count of Filtered Records for Server-Side Pagination
// -------------------------------------------------------------
$countSql = "SELECT COUNT(DISTINCT u.uid) AS total_count $fromAndJoins $whereClause";
$totalCountRes = $conn->query($countSql);
$totalMatching = $totalCountRes ? intval($totalCountRes->fetch_assoc()['total_count']) : 0;

$totalPages = $limit > 0 ? max(1, (int)ceil($totalMatching / $limit)) : 1;
if ($page > $totalPages && $totalPages > 0) {
    $page = $totalPages;
}
$offset = $limit > 0 ? ($page - 1) * $limit : 0;

// -------------------------------------------------------------
// 7. Fetch Paginated Records
// -------------------------------------------------------------
$sql = "
    SELECT 
        u.uid,
        u.firstname,
        u.lastname,
        u.department AS department_id,
        d.name AS department_name,
        u.status AS user_status,
        $empCodeField,
        $mailField,
        $joinField,
        $lastActiveField,
        $imgField,
        m.id AS map_id,
        m.hexa_uid,
        m.username AS moodle_username,
        m.moodle_user_id,
        m.sync_status,
        m.last_synced_at AS moodle_last_synced,
        m.notes,
        IFNULL(prg.done_cnt, 0) AS completed_cnt,
        IFNULL(act.has_started, 0) AS has_started_activity
    $fromAndJoins
    $whereClause
    ORDER BY (CASE WHEN m.id IS NOT NULL THEN 0 ELSE 1 END) ASC, u.firstname ASC, u.lastname ASC
";

if ($limit > 0) {
    $sql .= " LIMIT $limit OFFSET $offset";
}

$rows = [];
$res = $conn->query($sql);

$rawRows = [];
$pageUids = [];
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $rawRows[] = $r;
        $pageUids[] = intval($r['uid']);
    }
}

// Bulk Lookup for Assessment Attempts, Certificates, and Download History
$assessmentMap = [];
$certRecordMap = [];
$certDlMap = [];

if (!empty($pageUids)) {
    $uidListStr = implode(',', $pageUids);

    // 1. Bulk Assessment Attempts
    $attQ = $conn->query("
        SELECT user_id, attempt, Quiz_score, passing_score, result, is_locked, attempted_at 
        FROM onboard_induction_assessment_attempts 
        WHERE user_id IN ($uidListStr) 
        ORDER BY attempt ASC, id ASC
    ");
    if ($attQ) {
        while ($att = $attQ->fetch_assoc()) {
            $uId = intval($att['user_id']);
            if (!isset($assessmentMap[$uId])) {
                $assessmentMap[$uId] = [
                    'attempts_count' => 0,
                    'latest_attempt' => 1,
                    'latest_score' => null,
                    'result' => 'Not Attempted',
                    'is_passed' => false,
                    'is_locked' => false,
                    'passed_at' => null,
                    'passed_date_fmt' => null,
                    'attempts' => []
                ];
            }
            $sVal = (float)$att['Quiz_score'];
            $pVal = (float)($att['passing_score'] ?: 80);
            $rVal = trim($att['result'] ?? '');
            if (empty($rVal)) {
                $rVal = ($sVal >= $pVal) ? 'Passed' : 'Failed';
            }
            $isPass = (strtolower($rVal) === 'passed' || $sVal >= $pVal);
            $assessmentMap[$uId]['attempts_count']++;
            $assessmentMap[$uId]['latest_attempt'] = (int)$att['attempt'];
            $assessmentMap[$uId]['attempt_number'] = (int)$att['attempt'];
            $assessmentMap[$uId]['latest_score'] = (int)round($sVal);
            $assessmentMap[$uId]['score'] = (int)round($sVal);
            $assessmentMap[$uId]['submitted_at_fmt'] = !empty($att['attempted_at']) ? date('d-M-Y h:i A', strtotime($att['attempted_at'])) : null;
            if ($isPass) {
                $assessmentMap[$uId]['is_passed'] = true;
                $assessmentMap[$uId]['result'] = 'Passed';
                $assessmentMap[$uId]['passed_at'] = $att['attempted_at'];
                $assessmentMap[$uId]['passed_date_fmt'] = date('F j, Y', strtotime($att['attempted_at']));
            } elseif (!$assessmentMap[$uId]['is_passed']) {
                $assessmentMap[$uId]['result'] = 'Failed';
            }
            if ((int)$att['is_locked'] === 1) {
                $assessmentMap[$uId]['is_locked'] = true;
            }
            $assessmentMap[$uId]['attempts'][] = [
                'attempt' => (int)$att['attempt'],
                'score' => (int)round($sVal),
                'result' => $rVal,
                'date' => !empty($att['attempted_at']) ? date('d-M-Y h:i A', strtotime($att['attempted_at'])) : null
            ];
        }
    }

    // 2. Bulk Certificate Records (ONLY for candidates who actually passed assessment)
    $recQ = $conn->query("
        SELECT cr.user_id, cr.certificate_number, cr.issued_at 
        FROM onboard_certificate_records cr
        JOIN (
            SELECT user_id FROM onboard_induction_assessment_attempts 
            WHERE result = 'Passed' OR Quiz_score >= 80 
            GROUP BY user_id
        ) p ON p.user_id = cr.user_id
        WHERE cr.user_id IN ($uidListStr)
    ");
    if ($recQ) {
        while ($rec = $recQ->fetch_assoc()) {
            $certRecordMap[intval($rec['user_id'])] = [
                'certificate_number' => $rec['certificate_number'],
                'issued_at' => $rec['issued_at'],
                'issued_fmt' => !empty($rec['issued_at']) ? date('d-M-Y', strtotime($rec['issued_at'])) : null
            ];
        }
    }

    // 3. Bulk Certificate Downloads
    $dlQ = $conn->query("
        SELECT user_id, COUNT(*) AS dl_cnt, MAX(downloaded_at) AS last_dl 
        FROM onboard_certificate_downloads 
        WHERE user_id IN ($uidListStr) 
        GROUP BY user_id
    ");
    if ($dlQ) {
        while ($dl = $dlQ->fetch_assoc()) {
            $certDlMap[intval($dl['user_id'])] = [
                'download_count' => intval($dl['dl_cnt']),
                'last_downloaded_at' => $dl['last_dl'],
                'last_downloaded_fmt' => !empty($dl['last_dl']) ? date('d-M-Y h:i A', strtotime($dl['last_dl'])) : null
            ];
        }
    }
}

foreach ($rawRows as $r) {
    $uid = intval($r['uid']);
    $isEnrolled = !empty($r['map_id']) || !empty($r['moodle_user_id']);
    
    $completed = intval($r['completed_cnt']);
    if ($totalModules > 0 && $completed > $totalModules) {
        $completed = $totalModules;
    }

    $percent = 0;
    $statusStr = 'Not Enrolled';

    if ($isEnrolled) {
        $percent = $totalModules > 0 ? min(100, (int)round(($completed / $totalModules) * 100)) : 0;
        $hasStarted = intval($r['has_started_activity']) === 1;
        if ($percent >= 100) {
            $statusStr = 'Completed';
        } elseif ($percent > 0 || $hasStarted) {
            $statusStr = 'In Progress';
        } else {
            $statusStr = 'Not Started';
        }
    }

    // Format joining date
    $joinRaw = $r['joiningdate'] ?? '';
    $joinTs = $joinRaw ? strtotime($joinRaw) : null;
    $joining_fmt = $joinTs ? date('d-M-Y', $joinTs) : $joinRaw;

    // Build friendly display name
    $fullName = trim(($r['firstname'] ?? '') . ' ' . ($r['lastname'] ?? ''));
    if ($fullName === '') {
        $fullName = $r['moodle_username'] ?: ('Employee #' . $uid);
    }

    $rawStatus = $r['user_status'];
    $isEx = ($rawStatus !== null && $rawStatus != 0 && $rawStatus !== '0' && $rawStatus !== 'online');

    $deadlineInfo = null;
    if ($isEnrolled) {
        if (function_exists('getEmployeeDeadlineStatus')) {
            try {
                $deadlineInfo = getEmployeeDeadlineStatus($uid, $conn);
            } catch (Throwable $e) {
                $deadlineInfo = null;
            }
        }
    }

    // Assessment Info from Bulk Map
    $attInfo = $assessmentMap[$uid] ?? [
        'attempts_count' => 0,
        'latest_attempt' => 0,
        'latest_score' => null,
        'result' => 'Not Attempted',
        'is_passed' => false,
        'is_locked' => false,
        'passed_at' => null,
        'passed_date_fmt' => null,
        'attempts' => []
    ];

    // Certificate Info from Bulk Maps (ONLY if trainee passed assessment)
    $isUserCertified = !empty($attInfo['is_passed']) || !empty($deadlineInfo['has_passed']);
    $certRec = $isUserCertified ? ($certRecordMap[$uid] ?? null) : null;
    $certDl = $isUserCertified ? ($certDlMap[$uid] ?? null) : null;

    $certCombined = [
        'certificate_number' => $certRec['certificate_number'] ?? null,
        'issued_fmt' => $certRec['issued_fmt'] ?? null,
        'download_count' => $certDl['download_count'] ?? 0,
        'last_downloaded_at' => $certDl['last_downloaded_at'] ?? null,
        'last_downloaded_fmt' => $certDl['last_downloaded_fmt'] ?? null,
        'has_downloaded' => !empty($certDl['download_count'])
    ];

    $rows[] = [
        'uid' => $uid,
        'name' => $fullName,
        'email' => $r['ofc_mail'] ?? '',
        'emp_code' => $r['emp_code'] ?? '',
        'department_id' => $r['department_id'] !== null ? (int)$r['department_id'] : null,
        'department_name' => $r['department_name'] ?? 'General',
        'joiningdate' => $r['joiningdate'] ?? '',
        'joiningdate_fmt' => $joining_fmt,
        'photo' => !empty($r['profile_img']) ? (((isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/internal/corhis') !== false) ? '/internal/corhis/dist/img/user/' : '/dist/img/user/') . implode('/', array_map('rawurlencode', explode('/', trim($r['profile_img']))))) : '',
        'last_active_time' => $r['last_active_time'] ?? null,
        'moodle_user_id' => $r['moodle_user_id'] ? (int)$r['moodle_user_id'] : null,
        'moodle_username' => $r['moodle_username'] ?? null,
        'sync_status' => $isEnrolled ? ($r['sync_status'] ?? 'Linked') : 'Not Enrolled',
        'is_moodle_enrolled' => $isEnrolled,
        'is_ex_employee' => $isEx,
        'user_status' => $rawStatus,
        'deadline_info' => $deadlineInfo,
        'assessment' => $attInfo,
        'certificate' => $certCombined,
        'certificate_info' => $certCombined,
        'passed_date_fmt' => $attInfo['passed_date_fmt'],
        'progress' => [ 
            'completed' => $completed, 
            'total' => $totalModules, 
            'percent' => $percent,
            'status' => $statusStr
        ]
    ];
}

echo json_encode([
    'success' => true,
    'employees' => $rows,
    'pagination' => [
        'page' => $page,
        'limit' => $limit,
        'total' => $totalMatching,
        'totalPages' => $totalPages,
        'hasPrev' => $page > 1,
        'hasNext' => $page < $totalPages
    ],
    'summary' => [
        'totalStaff' => $totalStaff,
        'moodleEnrolled' => $moodleEnrolled,
        'notEnrolled' => $notEnrolled,
        'completed' => $completedEmployees,
        'certified' => $certifiedEmployees,
        'deadlineLocked' => $lockedEmployees,
        'certificatesDownloaded' => $downloadedEmployees,
        'enrolledBreakdown' => $enrolledBreakdown,
        'completedBreakdown' => $completedBreakdown,
        'lockedBreakdown' => $lockedBreakdown,
        'currentEnrolled' => $currentEnrolled,
        'exEnrolled' => $exEnrolled,
        'empType' => $empType
    ],
    'activeFilters' => [
        'q' => $search,
        'dept' => $deptFilter,
        'status' => $statusFilter,
        'compRange' => $compRange,
        'compMin' => $compMin,
        'compMax' => $compMax
    ],
    'totalModules' => $totalModules,
    'totalEmployees' => $totalStaff,
    'totalMoodleEnrolled' => $moodleEnrolled
], JSON_UNESCAPED_SLASHES);

$conn->close();
?>