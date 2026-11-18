<?php
// Endpoint: api/email/recipients.php
// GET: Retrieve eligible employee recipients strictly using ofc_mail and matching admin metrics

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

$NEED_PDO = false;
require_once __DIR__ . '/../db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentUserId = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$currentUserId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

// Verify Admin Role
$isAdmin = false;
if (in_array((string)$currentUserId, ['26', '64', '205'], true)) {
    $isAdmin = true;
} else {
    $roleCheck = $conn->prepare("SELECT user_role FROM user WHERE uid = ?");
    if ($roleCheck) {
        $roleCheck->bind_param('i', $currentUserId);
        $roleCheck->execute();
        $roleRes = $roleCheck->get_result()->fetch_assoc();
        if (($roleRes['user_role'] ?? 0) == 1) $isAdmin = true;
        $roleCheck->close();
    }
}

if (!$isAdmin) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized: Admin access required.']);
    exit;
}

session_write_close();

// 1. Total active modules in onboarding curriculum
$totalModules = 4;
$mRes = $conn->query("
    SELECT COUNT(*) AS cnt 
    FROM `onboard_modules` 
    WHERE `is_active` = 1 
      AND `moodle_course_id` > 0
      AND (type != 'assessment' OR type IS NULL)
      AND LOWER(title) NOT LIKE '%assessment%'
      AND LOWER(title) NOT LIKE '%quiz%'
");
if ($mRes && ($mRow = $mRes->fetch_assoc())) {
    $cnt = intval($mRow['cnt']);
    if ($cnt > 0) $totalModules = $cnt;
}

// 2. Discover available user columns
$userCols = [];
$cRes = $conn->query("SHOW COLUMNS FROM `user`");
if ($cRes) {
    while ($c = $cRes->fetch_assoc()) $userCols[] = strtolower($c['Field']);
}

$hasOfcMail = in_array('ofc_mail', $userCols, true);
$hasEmpCode = in_array('emp_code', $userCols, true);
$hasDept = in_array('department', $userCols, true);
$hasDesignation = in_array('designation', $userCols, true);
$hasProfileImg = in_array('profile_img', $userCols, true);
$hasJoiningDate = in_array('joiningdate', $userCols, true);

$ofcMailSelect = $hasOfcMail ? "u.ofc_mail" : "NULL AS ofc_mail";
$empCodeSelect = $hasEmpCode ? "u.emp_code" : "NULL AS emp_code";
$deptSelect = $hasDept ? "u.department" : "NULL AS department";
$desigSelect = $hasDesignation ? "u.designation" : "NULL AS designation";
$imgSelect = $hasProfileImg ? "u.profile_img" : "NULL AS profile_img";
$joinSelect = $hasJoiningDate ? "u.joiningdate" : "NULL AS joiningdate";

// 3. Header summary counts matching admin/employees
$mCountRes = $conn->query("
    SELECT 
        COUNT(DISTINCT CASE WHEN (IFNULL(u.status, 0) = 0 OR u.status = 'online') THEN m.hexa_uid END) AS current_enrolled
    FROM onboard_moodle_users m
    LEFT JOIN user u ON u.uid = m.hexa_uid
");
$mRow = $mCountRes ? $mCountRes->fetch_assoc() : [];
$currentEnrolled = intval($mRow['current_enrolled'] ?? 0);

$uCountRes = $conn->query("
    SELECT 
        COUNT(CASE WHEN (IFNULL(status, 0) = 0 OR status = 'online') THEN 1 END) AS current_staff
    FROM user
");
$uRow = $uCountRes ? $uCountRes->fetch_assoc() : [];
$totalStaff = intval($uRow['current_staff'] ?? 0);
$notEnrolled = max(0, $totalStaff - $currentEnrolled);

// 4. Batch progress query
$dbProgressMap = [];
$pRes = $conn->query("
    SELECT p.user_id, COUNT(DISTINCT m.id) AS done_cnt
    FROM onboard_module_progress p
    INNER JOIN onboard_modules m ON (m.id = p.module_id OR (m.moodle_cmid > 0 AND m.moodle_cmid = p.module_id))
    WHERE (p.is_completed = 1 OR p.status = 'completed')
      AND p.status NOT IN ('incomplete', 'in_progress', 'suspend', 'browsed')
      AND m.is_active = 1
      AND m.moodle_course_id > 0
      AND (m.type != 'assessment' OR m.type IS NULL)
      AND LOWER(m.title) NOT LIKE '%assessment%'
      AND LOWER(m.title) NOT LIKE '%quiz%'
    GROUP BY p.user_id
");
if ($pRes) {
    while ($pRow = $pRes->fetch_assoc()) {
        $dbProgressMap[(int)$pRow['user_id']] = (int)$pRow['done_cnt'];
    }
}

// Completed employees count
$completedCount = 0;
if ($totalModules > 0) {
    $compRes = $conn->query("
        SELECT p.user_id, COUNT(DISTINCT m.id) AS done_cnt
        FROM onboard_module_progress p
        JOIN onboard_moodle_users m_usr ON m_usr.hexa_uid = p.user_id
        JOIN user u ON u.uid = m_usr.hexa_uid AND (IFNULL(u.status, 0) = 0 OR u.status = 'online')
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
        $completedCount = $compRes->num_rows;
    }
}

// 5. Fetch all active employees directly using user.ofc_mail (strictly no hardcoded fallbacks)
$sql = "
    SELECT 
        u.uid,
        u.firstname,
        u.lastname,
        $ofcMailSelect,
        $empCodeSelect,
        $deptSelect,
        $desigSelect,
        $imgSelect,
        $joinSelect,
        m.id AS map_id,
        m.username AS moodle_username,
        m.email AS moodle_email,
        m.moodle_user_id,
        m.sync_status
    FROM user u
    LEFT JOIN onboard_moodle_users m ON m.hexa_uid = u.uid
    WHERE (IFNULL(u.status, 0) = 0 OR u.status = 'online')
    ORDER BY u.firstname ASC, u.lastname ASC
";

$res = $conn->query($sql);
$allEmployees = [];

if ($res) {
    while ($r = $res->fetch_assoc()) {
        $uid = (int)$r['uid'];
        
        $isEnrolled = !empty($r['map_id']) || !empty($r['moodle_user_id']);
        // Purely use user.ofc_mail from the database (strictly no fallbacks)
        $ofc = trim($r['ofc_mail'] ?? '');
        $email = (!empty($ofc) && filter_var($ofc, FILTER_VALIDATE_EMAIL)) ? $ofc : '';

        $fullName = trim(($r['firstname'] ?? '') . ' ' . ($r['lastname'] ?? ''));
        if (empty($fullName)) $fullName = "Employee #{$uid}";

        $completedMods = $dbProgressMap[$uid] ?? 0;
        if ($completedMods > $totalModules) $completedMods = $totalModules;

        $percent = ($isEnrolled && $totalModules > 0) ? min(100, (int)round(($completedMods / $totalModules) * 100)) : 0;
        $isComplete = $percent >= 100;

        $allEmployees[] = [
            'uid' => $uid,
            'name' => $fullName,
            'firstname' => $r['firstname'] ?? '',
            'lastname' => $r['lastname'] ?? '',
            'email' => $email, // Office email from user.ofc_mail only (blank if not present, shows Add Email in UI)
            'has_email' => !empty($email),
            'email_source' => !empty($email) ? 'ofc_mail' : 'none',
            'emp_code' => $r['emp_code'] ?? '',
            'department' => $r['department'] ?? '',
            'designation' => $r['designation'] ?? '',
            'photo' => !empty($r['profile_img']) ? (((isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/internal/corhis') !== false) ? '/internal/corhis/dist/img/user/' : '/dist/img/user/') . implode('/', array_map('rawurlencode', explode('/', trim($r['profile_img']))))) : '',
            'is_moodle_enrolled' => $isEnrolled,
            'is_enrolled' => $isEnrolled,
            'moodle_user_id' => !empty($r['moodle_user_id']) ? (int)$r['moodle_user_id'] : null,
            'completion_percent' => $percent,
            'progress_percent' => $percent,
            'completed_modules' => $completedMods,
            'total_modules' => $totalModules,
            'pending_modules' => max(0, $totalModules - $completedMods),
            'status' => !$isEnrolled ? 'not_enrolled' : ($isComplete ? 'completed' : ($percent > 0 ? 'in_progress' : 'not_started'))
        ];
    }
}

// 6. Filter recipients by requested cohort (matching admin/employees: 'all', 'enrolled', 'completed', 'not_enrolled')
$chosenCohort = trim($_GET['cohort'] ?? 'enrolled');
$recipients = [];

foreach ($allEmployees as $emp) {
    $match = false;
    switch ($chosenCohort) {
        case 'enrolled':
            $match = $emp['is_moodle_enrolled'];
            break;
        case 'completed':
            $match = ($emp['is_moodle_enrolled'] && $emp['completion_percent'] >= 100);
            break;
        case 'not_enrolled':
            $match = !$emp['is_moodle_enrolled'];
            break;
        case 'all':
        default:
            $match = true;
            break;
    }

    if ($match) {
        $recipients[] = $emp;
    }
}

// Summary object matching the exact 4 cards on admin/employees
$summary = [
    'all' => $totalStaff,
    'enrolled' => $currentEnrolled,
    'completed' => $completedCount,
    'not_enrolled' => $notEnrolled
];

echo json_encode([
    'success' => true,
    'cohort' => $chosenCohort,
    'summary' => $summary,
    'cohort_counts' => $summary,
    'total_modules' => $totalModules,
    'recipients' => $recipients,
    'employees' => $allEmployees
], JSON_UNESCAPED_UNICODE);
