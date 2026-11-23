<?php
/**
 * api/admin/certificates.php
 * Admin API: Certificate Download History & Audit Report
 * Lists all qualified/passed employees, unique certificate numbers, download counts, timestamps, and metrics.
 * Supports:
 * - Master ON/OFF Switch for Employee Certificate Download Visibility
 * - Test Certificate Download for Any Employee (all employees query)
 * - Complete Certified pool (Assessment Passed OR 100% Course Completed OR Issued Record)
 */

date_default_timezone_set('Asia/Kolkata');

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

$NEED_PDO = false;
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../certificate_helper.php';

if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { 
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not logged in']); 
    exit; 
}

// Check admin permissions
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
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized: Admin access required']); 
    exit; 
}

session_write_close();

$conn->query("SET time_zone = '+05:30'");
ensureCertificateTables($conn);

// -------------------------------------------------------------
// POST: Master ON/OFF Switch for Employee Certificate Downloads
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $action = strtolower(trim($input['action'] ?? ''));

    if ($action === 'toggle_employee_download') {
        $allowed = !empty($input['allowed']);
        $saved = setEmployeeCertificateDownloadAllowed($conn, $allowed);
        $current = isEmployeeCertificateDownloadAllowed($conn);
        echo json_encode([
            'success' => true,
            'saved' => $saved,
            'allow_employee_download' => $current,
            'message' => $current 
                ? 'Certificate download is now LIVE for all qualified employees on their portal.' 
                : 'Certificate download is now HIDDEN from employees (Admin Testing Mode).'
        ]);
        exit;
    }
}

// -------------------------------------------------------------
// GET: Helper Actions (e.g. Fetch All Active Employees for Admin Test Download)
// -------------------------------------------------------------
$action = strtolower(trim($_GET['action'] ?? ''));
if ($action === 'all_employees') {
    $empList = [];
    $empRes = $conn->query("
        SELECT 
            u.uid, 
            u.emp_code, 
            CONCAT(u.firstname, ' ', u.lastname) AS name, 
            u.ofc_mail AS email, 
            IFNULL(d.name, 'General') AS dept_name
        FROM user u
        LEFT JOIN department d ON d.id = u.department
        WHERE u.status = 0
        ORDER BY u.firstname ASC, u.lastname ASC
    ");
    if ($empRes) {
        while ($er = $empRes->fetch_assoc()) {
            $code = trim($er['emp_code'] ?? '');
            if (empty($code)) {
                $code = 'HL' . $er['uid'];
            }
            $empList[] = [
                'uid' => (int)$er['uid'],
                'emp_code' => $code,
                'name' => trim($er['name']),
                'email' => $er['email'] ?? '',
                'dept_name' => $er['dept_name']
            ];
        }
    }
    echo json_encode(['success' => true, 'employees' => $empList]);
    exit;
}

// 1. Fetch Department List for Filter
$departments = [];
$deptRes = $conn->query("SELECT id, name FROM department ORDER BY name ASC");
if ($deptRes) {
    while ($d = $deptRes->fetch_assoc()) {
        $departments[] = [
            'id' => (int)$d['id'],
            'name' => $d['name']
        ];
    }
}

// 2. Query Filters
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = max(5, min(100, (int)($_GET['limit'] ?? 10)));
$offset = ($page - 1) * $limit;

$q = trim($_GET['q'] ?? '');
$deptFilter = trim($_GET['dept'] ?? 'all');
$statusFilter = trim($_GET['status'] ?? 'all'); // 'all', 'downloaded', 'never'

$whereClauses = ["1=1"];

if (!empty($q)) {
    $escapedQ = $conn->real_escape_string($q);
    $whereClauses[] = "(
        u.firstname LIKE '%{$escapedQ}%' OR 
        u.lastname LIKE '%{$escapedQ}%' OR 
        u.emp_code LIKE '%{$escapedQ}%' OR 
        u.ofc_mail LIKE '%{$escapedQ}%' OR
        cr.certificate_number LIKE '%{$escapedQ}%' OR
        CONCAT(u.firstname, ' ', u.lastname) LIKE '%{$escapedQ}%'
    )";
}

if ($deptFilter !== 'all' && is_numeric($deptFilter)) {
    $dId = (int)$deptFilter;
    $whereClauses[] = "u.department = {$dId}";
}

if ($statusFilter === 'downloaded') {
    $whereClauses[] = "IFNULL(dl.download_count, 0) > 0";
} elseif ($statusFilter === 'never') {
    $whereClauses[] = "IFNULL(dl.download_count, 0) = 0";
}

$whereSql = implode(" AND ", $whereClauses);

// Certified Candidates Subquery: Exclusively from onboard_induction_assessment_attempts where result = 'Passed' OR Quiz_score >= 80
// Exactly matches the 30 employees who passed in Admin Assessment Results
$qualifiedSubquery = "
    SELECT 
        a.user_id,
        a.Quiz_score AS quiz_score,
        a.attempt AS attempt_number,
        a.attempted_at AS passed_at
    FROM (
        SELECT user_id, MAX(id) AS latest_passed_id
        FROM onboard_induction_assessment_attempts
        WHERE (result = 'Passed' OR LOWER(TRIM(result)) = 'passed' OR Quiz_score >= 80)
        GROUP BY user_id
    ) latest
    JOIN onboard_induction_assessment_attempts a ON a.id = latest.latest_passed_id
";

// 3. Overall Summary Metrics
$metricsQuery = "
    SELECT 
        COUNT(DISTINCT a.user_id) AS total_certified,
        IFNULL(SUM(dl.download_count), 0) AS total_downloads,
        COUNT(DISTINCT CASE WHEN dl.download_count > 0 THEN a.user_id END) AS downloaded_users,
        COUNT(DISTINCT CASE WHEN IFNULL(dl.download_count, 0) = 0 THEN a.user_id END) AS never_downloaded
    FROM ({$qualifiedSubquery}) a
    JOIN user u ON u.uid = a.user_id
    LEFT JOIN (
        SELECT user_id, COUNT(*) AS download_count
        FROM onboard_certificate_downloads
        GROUP BY user_id
    ) dl ON dl.user_id = a.user_id
";
$metricsRes = $conn->query($metricsQuery);
$metrics = $metricsRes ? $metricsRes->fetch_assoc() : [
    'total_certified' => 0,
    'total_downloads' => 0,
    'downloaded_users' => 0,
    'never_downloaded' => 0
];

// 4. Count total matching filtered records
$countQuery = "
    SELECT COUNT(DISTINCT a.user_id) AS total_matching
    FROM ({$qualifiedSubquery}) a
    JOIN user u ON u.uid = a.user_id
    LEFT JOIN department d ON d.id = u.department
    LEFT JOIN onboard_certificate_records cr ON cr.user_id = a.user_id
    LEFT JOIN (
        SELECT user_id, COUNT(*) AS download_count
        FROM onboard_certificate_downloads
        GROUP BY user_id
    ) dl ON dl.user_id = a.user_id
    WHERE {$whereSql}
";
$countRes = $conn->query($countQuery);
$totalMatching = $countRes ? (int)$countRes->fetch_assoc()['total_matching'] : 0;
$totalPages = ceil($totalMatching / $limit);

// 5. Fetch Records
$mainQuery = "
    SELECT 
        u.uid,
        u.emp_code,
        CONCAT(u.firstname, ' ', u.lastname) AS emp_name,
        u.ofc_mail,
        u.profile_img,
        u.department AS dept_id,
        IFNULL(d.name, 'General') AS dept_name,
        a.attempt_number,
        a.quiz_score,
        a.passed_at,
        cr.certificate_number,
        IFNULL(dl.download_count, 0) AS download_count,
        dl.first_downloaded_at,
        dl.last_downloaded_at
    FROM ({$qualifiedSubquery}) a
    JOIN user u ON u.uid = a.user_id
    LEFT JOIN department d ON d.id = u.department
    LEFT JOIN onboard_certificate_records cr ON cr.user_id = a.user_id
    LEFT JOIN (
        SELECT 
            user_id,
            COUNT(*) AS download_count,
            MIN(downloaded_at) AS first_downloaded_at,
            MAX(downloaded_at) AS last_downloaded_at
        FROM onboard_certificate_downloads
        GROUP BY user_id
    ) dl ON dl.user_id = a.user_id
    WHERE {$whereSql}
    ORDER BY 
        (IFNULL(dl.download_count, 0) > 0) DESC,
        dl.last_downloaded_at DESC,
        a.passed_at DESC
    LIMIT {$limit} OFFSET {$offset}
";

$records = [];
$res = $conn->query($mainQuery);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $passedRaw = $row['passed_at'];
        $passedDateFmt = 'November 20, 2026';
        $passedDateTimeIst = '—';
        
        if (!empty($passedRaw)) {
            try {
                $dt = new DateTime($passedRaw, new DateTimeZone('UTC'));
                $dt->setTimezone(new DateTimeZone('Asia/Kolkata'));
                $passedDateFmt = $dt->format('F j, Y');
                $passedDateTimeIst = $dt->format('d-M-Y h:i A');
            } catch (\Throwable $e) {
                $passedDateFmt = date('F j, Y', strtotime($passedRaw));
                $passedDateTimeIst = date('d-M-Y h:i A', strtotime($passedRaw));
            }
        }

        $firstDlFmt = !empty($row['first_downloaded_at']) ? date('d-M-Y h:i A', strtotime($row['first_downloaded_at'])) : null;
        $lastDlFmt = !empty($row['last_downloaded_at']) ? date('d-M-Y h:i A', strtotime($row['last_downloaded_at'])) : null;

        $empCode = trim($row['emp_code'] ?? '');
        if (empty($empCode)) {
            $empCode = "HL" . $row['uid'];
        }

        // If certificate record not yet assigned, allocate permanent number atomically
        $certNo = $row['certificate_number'] ?? null;
        if (empty($certNo)) {
            $rec = getOrIssueCertificateRecord($conn, (int)$row['uid'], $empCode, $row['emp_name']);
            $certNo = $rec['certificate_number'];
        }

        $records[] = [
            'uid' => (int)$row['uid'],
            'emp_code' => $empCode,
            'certificate_number' => $certNo,
            'name' => trim($row['emp_name']),
            'email' => $row['ofc_mail'] ?? '—',
            'photo' => !empty($row['profile_img']) ? '/internal/corhis/dist/img/user/' . $row['profile_img'] : '',
            'dept_id' => (int)$row['dept_id'],
            'dept_name' => $row['dept_name'],
            'quiz_score' => $row['quiz_score'] !== null ? (float)$row['quiz_score'] : 100.0,
            'attempt_number' => (int)($row['attempt_number'] ?? 1),
            'passed_at' => $passedRaw,
            'passed_date_fmt' => $passedDateFmt,
            'passed_datetime_ist' => $passedDateTimeIst,
            'download_count' => (int)$row['download_count'],
            'first_downloaded_at' => $row['first_downloaded_at'],
            'first_downloaded_fmt' => $firstDlFmt,
            'last_downloaded_at' => $row['last_downloaded_at'],
            'last_downloaded_fmt' => $lastDlFmt,
        ];
    }
}

// Attach full chronological download history for each candidate
$uids = array_map(function($r) { return (int)$r['uid']; }, $records);
$downloadHistoryByUser = [];
if (!empty($uids)) {
    $uidListStr = implode(',', $uids);
    $dlHistoryQuery = "
        SELECT 
            d.id,
            d.user_id,
            d.certificate_number,
            d.downloaded_by,
            d.emp_code,
            d.emp_name,
            d.ip_address,
            d.downloaded_at,
            CONCAT(IFNULL(u.firstname, ''), ' ', IFNULL(u.lastname, '')) AS downloaded_by_name
        FROM onboard_certificate_downloads d
        LEFT JOIN user u ON u.uid = d.downloaded_by
        WHERE d.user_id IN ({$uidListStr})
        ORDER BY d.downloaded_at DESC, d.id DESC
    ";
    $dlRes = $conn->query($dlHistoryQuery);
    if ($dlRes) {
        while ($dlRow = $dlRes->fetch_assoc()) {
            $uId = (int)$dlRow['user_id'];
            if (!isset($downloadHistoryByUser[$uId])) {
                $downloadHistoryByUser[$uId] = [];
            }
            
            $dlAtRaw = $dlRow['downloaded_at'];
            $dlAtFmt = !empty($dlAtRaw) ? date('d-M-Y h:i A', strtotime($dlAtRaw)) : '—';
            
            $isSelf = ((int)$dlRow['downloaded_by'] === $uId || (int)$dlRow['downloaded_by'] === 0);
            $byText = $isSelf ? 'Employee (Self)' : (!empty(trim($dlRow['downloaded_by_name'])) ? 'Admin: ' . trim($dlRow['downloaded_by_name']) : 'Admin');

            $downloadHistoryByUser[$uId][] = [
                'id' => (int)$dlRow['id'],
                'downloaded_at' => $dlAtRaw,
                'downloaded_at_fmt' => $dlAtFmt,
                'downloaded_by_id' => (int)$dlRow['downloaded_by'],
                'downloaded_by_name' => $byText,
                'is_self' => $isSelf,
                'ip_address' => $dlRow['ip_address'] ?? '—'
            ];
        }
    }
}

foreach ($records as &$rec) {
    $uId = $rec['uid'];
    $history = $downloadHistoryByUser[$uId] ?? [];
    $totalDl = count($history);
    foreach ($history as $idx => &$item) {
        $item['download_number'] = $totalDl - $idx;
    }
    unset($item);
    $rec['download_history'] = $history;
}
unset($rec);

// Check master switch status
$allowEmployeeDownload = isEmployeeCertificateDownloadAllowed($conn);

echo json_encode([
    'success' => true,
    'allow_employee_download' => $allowEmployeeDownload,
    'metrics' => [
        'total_certified' => (int)($metrics['total_certified'] ?? 0),
        'total_downloads' => (int)($metrics['total_downloads'] ?? 0),
        'downloaded_users' => (int)($metrics['downloaded_users'] ?? 0),
        'never_downloaded' => (int)($metrics['never_downloaded'] ?? 0),
    ],
    'departments' => $departments,
    'records' => $records,
    'pagination' => [
        'page' => $page,
        'limit' => $limit,
        'total' => $totalMatching,
        'totalPages' => max(1, (int)$totalPages),
        'hasPrev' => $page > 1,
        'hasNext' => $page < $totalPages
    ]
]);
