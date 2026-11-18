<?php
// Endpoint: api/admin/employees.php
// Returns paginated employees with local Onboard/Moodle mapping, department, and advanced multi-dimensional filtering
// Fully optimized for zero Moodle API calls during search, pagination, and filter interactions.

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

$NEED_PDO = false;
require_once __DIR__ . '/../db.php';

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
    } elseif ($statusFilter === 'not_enrolled') {
        $whereParts[] = "m.id IS NULL";
    }
} else {
    // Apply legacy filter tab if status_filter is 'all'
    if ($filter === 'not_enrolled') {
        $whereParts[] = "m.id IS NULL";
    } elseif ($filter === 'completed') {
        $whereParts[] = "m.id IS NOT NULL AND IFNULL(prg.done_cnt, 0) >= $totalModules";
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

if ($res) {
    while ($r = $res->fetch_assoc()) {
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
            'progress' => [ 
                'completed' => $completed, 
                'total' => $totalModules, 
                'percent' => $percent,
                'status' => $statusStr
            ]
        ];
    }
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