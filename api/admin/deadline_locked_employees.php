<?php
/**
 * api/admin/deadline_locked_employees.php
 * Dedicated endpoint for viewing and managing employees tracked in `onboard_employee_deadlines`.
 * Accurately determines deadline lockout status based on base/extended deadlines vs IST cutoff.
 */

date_default_timezone_set('Asia/Kolkata');

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

$NEED_PDO = false;
require_once __DIR__ . '/../db.php';
if (file_exists(__DIR__ . '/../deadline_helper.php')) {
    require_once __DIR__ . '/../deadline_helper.php';
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentUserId = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$currentUserId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

// 1. Verify Admin Role (user_role = 1 or specific UIDs)
$isAdmin = false;
if (in_array((string)$currentUserId, ['26', '64', '205'], true)) {
    $isAdmin = true;
} else {
    $roleCheck = $conn->prepare("SELECT user_role FROM user WHERE uid = ?");
    if ($roleCheck) {
        $roleCheck->bind_param('i', $currentUserId);
        $roleCheck->execute();
        $roleRes = $roleCheck->get_result()->fetch_assoc();
        if (($roleRes['user_role'] ?? 0) == 1) {
            $isAdmin = true;
        }
        $roleCheck->close();
    }
}

if (!$isAdmin) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized: Admin privileges required.']);
    exit;
}

session_write_close();

ensureDeadlinesTable($conn);

// 2. Query Params
$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? 'locked'); // Default to 'locked': 'locked', 'all', 'extended', 'pending', 'passed'
$empType = strtolower(trim($_GET['emp_type'] ?? 'current')); // 'current' | 'ex' | 'all'
$batchFilter = isset($_GET['batch']) && is_numeric($_GET['batch']) ? (int)$_GET['batch'] : 0;
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = max(1, min(100, (int)($_GET['limit'] ?? 15)));
$offset = ($page - 1) * $limit;

// Employment Status Condition (HexaLearn standard: status=0 or 'online' is active, status=1 or other is ex-employee)
if ($empType === 'ex') {
    $empStatusCond = "(IFNULL(u.status, 0) = 1 OR (u.status != 0 AND u.status != 'online'))";
} elseif ($empType === 'all') {
    $empStatusCond = "1=1";
} else {
    $empStatusCond = "(IFNULL(u.status, 0) = 0 OR u.status = 'online')";
}

$nowIst = (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d H:i:s');

// Global counts of current vs ex in onboard_employee_deadlines
$globalCountsRes = $conn->query("
    SELECT 
        SUM(CASE WHEN (IFNULL(u.status, 0) = 0 OR u.status = 'online') THEN 1 ELSE 0 END) AS cnt_current,
        SUM(CASE WHEN (IFNULL(u.status, 0) = 1 OR (u.status != 0 AND u.status != 'online')) THEN 1 ELSE 0 END) AS cnt_ex
    FROM onboard_employee_deadlines d
    INNER JOIN user u ON u.uid = d.user_id
");
$globalCounts = $globalCountsRes ? $globalCountsRes->fetch_assoc() : [];
$cntCurrent = (int)($globalCounts['cnt_current'] ?? 0);
$cntEx = (int)($globalCounts['cnt_ex'] ?? 0);

// 3. Main Query: Join onboard_employee_deadlines with user, department, and pass status
$baseSql = "
    FROM onboard_employee_deadlines d
    INNER JOIN user u ON u.uid = d.user_id
    LEFT JOIN department dept ON dept.id = u.department
    LEFT JOIN user admin_u ON admin_u.uid = d.extended_by_admin_id
    LEFT JOIN (
        SELECT 
            user_id,
            COUNT(*) AS total_attempts,
            MAX(CASE WHEN (result = 'Passed' OR Quiz_score >= 80) THEN 1 ELSE 0 END) AS has_passed,
            MAX(CASE WHEN (result = 'Passed' OR Quiz_score >= 80) THEN Quiz_score ELSE NULL END) AS passed_score,
            MAX(Quiz_score) AS latest_score
        FROM onboard_induction_assessment_attempts
        GROUP BY user_id
    ) att ON att.user_id = d.user_id
";

// Compute total metrics matching the selected emp_type
$metricsQuery = "
    SELECT 
        COUNT(*) AS total_records,
        SUM(CASE WHEN d.batch_id = 1 THEN 1 ELSE 0 END) AS total_batch1,
        SUM(CASE WHEN IFNULL(att.has_passed, 0) = 1 THEN 1 ELSE 0 END) AS total_passed,
        SUM(CASE WHEN d.extended_deadline IS NOT NULL THEN 1 ELSE 0 END) AS total_extended,
        SUM(CASE 
            WHEN IFNULL(att.has_passed, 0) = 0 AND '{$nowIst}' > IFNULL(d.extended_deadline, d.deadline_date) THEN 1 
            ELSE 0 
        END) AS total_locked,
        SUM(CASE 
            WHEN IFNULL(att.has_passed, 0) = 0 AND '{$nowIst}' <= IFNULL(d.extended_deadline, d.deadline_date) THEN 1 
            ELSE 0 
        END) AS total_pending
    {$baseSql}
    WHERE {$empStatusCond}
";
$metricsRes = $conn->query($metricsQuery);
$metrics = $metricsRes ? $metricsRes->fetch_assoc() : [];

// Quick count only mode (for layout badge)
if (isset($_GET['count_only']) && $_GET['count_only'] == '1') {
    echo json_encode([
        'success' => true,
        'total_locked' => (int)($metrics['total_locked'] ?? 0),
        'total_records' => (int)($metrics['total_records'] ?? 0)
    ]);
    exit;
}

// 4. Build Where Filters
$where = ["1=1"];
$where[] = $empStatusCond;

if (!empty($search)) {
    $esc = $conn->real_escape_string($search);
    $where[] = "(u.firstname LIKE '%{$esc}%' OR u.lastname LIKE '%{$esc}%' OR u.emp_code LIKE '%{$esc}%' OR u.ofc_mail LIKE '%{$esc}%' OR dept.name LIKE '%{$esc}%')";
}

if ($batchFilter > 0) {
    $where[] = "d.batch_id = {$batchFilter}";
}

if ($statusFilter === 'locked') {
    // Locked: deadline passed AND not passed
    $where[] = "IFNULL(att.has_passed, 0) = 0 AND '{$nowIst}' > IFNULL(d.extended_deadline, d.deadline_date)";
} elseif ($statusFilter === 'pending') {
    // In progress: deadline not yet passed AND not passed
    $where[] = "IFNULL(att.has_passed, 0) = 0 AND '{$nowIst}' <= IFNULL(d.extended_deadline, d.deadline_date)";
} elseif ($statusFilter === 'extended') {
    $where[] = "d.extended_deadline IS NOT NULL";
} elseif ($statusFilter === 'passed') {
    $where[] = "IFNULL(att.has_passed, 0) = 1";
}

$whereClause = implode(' AND ', $where);

// Count filtered
$cntRes = $conn->query("SELECT COUNT(*) AS cnt {$baseSql} WHERE {$whereClause}");
$totalFiltered = $cntRes ? (int)$cntRes->fetch_assoc()['cnt'] : 0;
$totalPages = ceil($totalFiltered / $limit);

// 5. Select Records
$recordsQuery = "
    SELECT 
        d.id,
        d.user_id,
        d.batch_id,
        d.first_opened_at,
        d.deadline_date,
        d.extended_deadline,
        d.extended_by_admin_id,
        d.extension_reason,
        d.created_at,
        d.updated_at,
        u.emp_code,
        u.firstname,
        u.lastname,
        u.ofc_mail,
        u.profile_img,
        u.status AS user_status,
        dept.name AS department_name,
        CONCAT(IFNULL(admin_u.firstname, ''), ' ', IFNULL(admin_u.lastname, '')) AS extended_by_admin_name,
        IFNULL(att.has_passed, 0) AS has_passed,
        att.passed_score,
        att.latest_score,
        IFNULL(att.total_attempts, 0) AS total_attempts
    {$baseSql}
    WHERE {$whereClause}
    ORDER BY 
        CASE WHEN IFNULL(att.has_passed, 0) = 0 AND '{$nowIst}' > IFNULL(d.extended_deadline, d.deadline_date) THEN 0 ELSE 1 END,
        IFNULL(d.extended_deadline, d.deadline_date) ASC,
        d.id ASC
    LIMIT {$limit} OFFSET {$offset}
";

$recordsRes = $conn->query($recordsQuery);
$employees = [];

$nowDt = new DateTime('now', new DateTimeZone('Asia/Kolkata'));

if ($recordsRes) {
    while ($row = $recordsRes->fetch_assoc()) {
        $hasPassed = (bool)$row['has_passed'];
        $effDeadline = !empty($row['extended_deadline']) ? $row['extended_deadline'] : $row['deadline_date'];
        $dlDt = new DateTime($effDeadline, new DateTimeZone('Asia/Kolkata'));
        $isPast = ($nowDt > $dlDt);
        $isLocked = ($isPast && !$hasPassed);

        // Calculate time diff
        $diff = $nowDt->diff($dlDt);
        $diffText = '';
        if ($isPast) {
            $diffText = "Expired " . ($diff->days > 0 ? "{$diff->days}d " : "") . "{$diff->h}h ago";
        } else {
            $diffText = ($diff->days > 0 ? "{$diff->days}d " : "") . "{$diff->h}h remaining";
        }

        $empName = trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? ''));
        if (empty($empName)) {
            $empName = $row['emp_code'] ?? 'Employee';
        }

        $rawStatus = $row['user_status'];
        $isEx = ($rawStatus !== null && $rawStatus != 0 && $rawStatus !== '0' && $rawStatus !== 'online');

        $employees[] = [
            'id' => (int)$row['id'],
            'user_id' => (int)$row['user_id'],
            'emp_code' => $row['emp_code'] ?? '—',
            'name' => $empName,
            'email' => $row['ofc_mail'] ?? '—',
            'department' => $row['department_name'] ?? 'General',
            'profile_img' => !empty($row['profile_img']) ? '/internal/corhis/dist/img/user/' . $row['profile_img'] : '',
            'is_ex_employee' => $isEx,
            'batch_id' => (int)$row['batch_id'],
            'batch_label' => ((int)$row['batch_id'] === 1) ? 'Batch 1 (Existing)' : 'Batch 2 (New Joiner)',
            'base_deadline' => $row['deadline_date'],
            'base_deadline_fmt' => date('d-M-Y h:i A', strtotime($row['deadline_date'])),
            'extended_deadline' => $row['extended_deadline'],
            'extended_deadline_fmt' => !empty($row['extended_deadline']) ? date('d-M-Y h:i A', strtotime($row['extended_deadline'])) : null,
            'effective_deadline' => $effDeadline,
            'effective_deadline_fmt' => date('d-M-Y h:i A', strtotime($effDeadline)),
            'is_extended' => !empty($row['extended_deadline']),
            'extension_reason' => $row['extension_reason'],
            'extended_by_admin_name' => trim($row['extended_by_admin_name'] ?? ''),
            'is_locked' => $isLocked,
            'is_past_deadline' => $isPast,
            'time_diff_text' => $diffText,
            'has_passed' => $hasPassed,
            'passed_score' => $row['passed_score'] !== null ? (float)$row['passed_score'] : null,
            'latest_score' => $row['latest_score'] !== null ? (float)$row['latest_score'] : null,
            'total_attempts' => (int)$row['total_attempts'],
            'first_opened_at' => $row['first_opened_at'] ? date('d-M-Y h:i A', strtotime($row['first_opened_at'])) : null,
            'updated_at' => $row['updated_at'] ? date('d-M-Y h:i A', strtotime($row['updated_at'])) : null,
        ];
    }
}

echo json_encode([
    'success' => true,
    'metrics' => [
        'total_records' => (int)($metrics['total_records'] ?? 0),
        'total_batch1' => (int)($metrics['total_batch1'] ?? 0),
        'total_passed' => (int)($metrics['total_passed'] ?? 0),
        'total_locked' => (int)($metrics['total_locked'] ?? 0),
        'total_pending' => (int)($metrics['total_pending'] ?? 0),
        'total_extended' => (int)($metrics['total_extended'] ?? 0),
        'cnt_current' => $cntCurrent,
        'cnt_ex' => $cntEx,
        'emp_type' => $empType
    ],
    'employees' => $employees,
    'pagination' => [
        'page' => $page,
        'limit' => $limit,
        'total' => $totalFiltered,
        'totalPages' => $totalPages,
        'hasPrev' => $page > 1,
        'hasNext' => $page < $totalPages
    ]
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
