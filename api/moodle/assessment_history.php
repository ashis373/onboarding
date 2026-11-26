<?php
/**
 * api/moodle/assessment_history.php
 * Lightweight, dedicated real-time assessment history endpoint.
 * Directly queries `onboard_induction_assessment_attempts` table in MySQL.
 * Fast (<5ms response time), zero external Moodle API network calls, zero cache deletion.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// CORS Support
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (!empty($origin)) {
    header("Access-Control-Allow-Origin: $origin");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Methods: GET, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
} else {
    header("Access-Control-Allow-Origin: *");
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$NEED_PDO = false;
require_once __DIR__ . '/../db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$sessionUserId = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;

// Allow GET override for admin inspection / preview / testing
$targetUid = 0;
if (isset($_GET['uid']) && is_numeric($_GET['uid'])) {
    $targetUid = (int)$_GET['uid'];
} elseif (isset($_GET['user_id']) && is_numeric($_GET['user_id'])) {
    $targetUid = (int)$_GET['user_id'];
}

$userId = ($targetUid > 0) ? $targetUid : $sessionUserId;

// Release session lock early so other requests do not block
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

if (!$userId) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized: Please log in to view assessment attempt history.'
    ]);
    exit;
}

// 1. Fetch employee details
$empStmt = $conn->prepare("
    SELECT u.uid, u.emp_code, u.firstname, u.lastname, u.ofc_mail, u.department, d.name AS department_name, u.status 
    FROM user u 
    LEFT JOIN department d ON d.id = u.department 
    WHERE u.uid = ? LIMIT 1
");
$empStmt->bind_param('i', $userId);
$empStmt->execute();
$employee = $empStmt->get_result()->fetch_assoc();
$empStmt->close();

if (!$employee) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'message' => 'Employee record not found.'
    ]);
    exit;
}

// 2. Query attempts from onboard_induction_assessment_attempts
$hasPhaseCol = false;
$chkPh = $conn->query("SHOW COLUMNS FROM `onboard_induction_assessment_attempts` LIKE 'phase'");
if ($chkPh && $chkPh->num_rows > 0) {
    $hasPhaseCol = true;
}

$phaseSelect = $hasPhaseCol ? "phase," : "1 AS phase,";
$attStmt = $conn->prepare("
    SELECT id, {$phaseSelect} attempt, Quiz_score, passing_score, result, is_locked, email_sent, attempted_at 
    FROM onboard_induction_assessment_attempts 
    WHERE user_id = ? 
    ORDER BY phase ASC, attempt ASC, id ASC
");
$attStmt->bind_param('i', $userId);
$attStmt->execute();
$attResult = $attStmt->get_result();

$attemptsList = [];
$isPassedOverall = false;
$latestScore = null;
$passingScoreThreshold = 80;
$dbIsLocked = 0;

while ($row = $attResult->fetch_assoc()) {
    $score = (float)$row['Quiz_score'];
    $pScore = !empty($row['passing_score']) ? (int)$row['passing_score'] : 80;
    $passingScoreThreshold = $pScore;
    $isPassed = ($row['result'] === 'Passed' || $score >= $pScore);
    if ($isPassed) {
        $isPassedOverall = true;
    }
    $latestScore = $score;
    $dbIsLocked = (int)$row['is_locked'];

    $formattedDate = '';
    if (!empty($row['attempted_at'])) {
        try {
            $dt = new DateTime($row['attempted_at'], new DateTimeZone('UTC'));
            $dt->setTimezone(new DateTimeZone('Asia/Kolkata'));
            $formattedDate = $dt->format('M d, Y, h:i A');
        } catch (\Exception $e) {
            $formattedDate = date('M d, Y, h:i A', strtotime($row['attempted_at']));
        }
    }

    $attemptsList[] = [
        'id' => (int)$row['id'],
        'phase' => (int)($row['phase'] ?? 1),
        'attempt' => (int)$row['attempt'],
        'score' => $score,
        'result' => $isPassed ? 'Passed' : 'Failed',
        'is_passed' => $isPassed,
        'is_failed' => !$isPassed,
        'passing_score' => $pScore,
        'date' => $formattedDate,
        'attempted_at' => $row['attempted_at'],
        'total_time' => '—',
        'formattedDuration' => '—',
        'status' => $isPassed ? 'completed' : 'failed'
    ];
}
$attStmt->close();

// 3. Check Admin Unlock Override
$hasAdminOverride = false;
$ovrChk = $conn->query("
    SELECT id FROM onboard_course_unlock_overrides 
    WHERE user_id = {$userId} AND (moodle_course_id = 3 OR module_id = 6) AND is_unlocked = 1 
    ORDER BY id DESC LIMIT 1
");
if ($ovrChk && $ovrChk->num_rows > 0) {
    $hasAdminOverride = true;
}

// 4. Calculate Final Lockout & Retake Eligibility
$allowCertDownload = false;
if (file_exists(__DIR__ . '/../certificate_helper.php')) {
    require_once __DIR__ . '/../certificate_helper.php';
    if (isset($conn) && $conn instanceof mysqli && function_exists('isEmployeeCertificateDownloadAllowed')) {
        try {
            $allowCertDownload = isEmployeeCertificateDownloadAllowed($conn);
        } catch (\Throwable $e) {
            $allowCertDownload = false;
        }
    }
}

$totalAttempts = count($attemptsList);
$maxAttempts = 2;
$isLocked = false;
$canRetake = false;

if ($isPassedOverall) {
    $isLocked = true; // Permanently locked once passed
    $canRetake = false;
    $status = 'completed';
} elseif ($totalAttempts >= $maxAttempts || $dbIsLocked === 1) {
    if ($hasAdminOverride) {
        $isLocked = false;
        $canRetake = true;
        $status = 'failed';
    } else {
        $isLocked = true;
        $canRetake = false;
        $status = 'failed';
    }
} else {
    $isLocked = false;
    $canRetake = true;
    $status = ($totalAttempts === 0) ? 'available' : 'in_progress';
}

// 5. Construct Launch / Retake / Review URLs
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$httpHost = $_SERVER['HTTP_HOST'] ?? 'corhis.hexalearn.com';
$baseUrl = $scheme . $httpHost . '/onboard/api';

$assessmentModule = [
    'id' => 6,
    'cmid' => 4,
    'course_id' => 3,
    'scoid' => 32,
    'title' => 'Employee Assessment',
    'subtitle' => 'Final Induction Assessment',
    'duration' => '11 min',
    'duration_minutes' => 11,
    'is_assessment' => true,
    'status' => $status,
    'passing_score' => $passingScoreThreshold,
    'score' => $latestScore,
    'attempts' => $totalAttempts,
    'max_attempts' => $maxAttempts,
    'is_passed' => $isPassedOverall,
    'is_locked' => $isLocked,
    'can_retake' => $canRetake,
    'admin_unlocked' => $hasAdminOverride,
    'url' => $baseUrl . '/moodle/launch.php?cmid=4&courseid=3',
    'retake_url' => $baseUrl . '/moodle/launch.php?cmid=4&courseid=3&newattempt=on',
    'review_url' => $baseUrl . '/moodle/launch.php?cmid=4&courseid=3&mode=review',
    'attempts_history' => $attemptsList
];

echo json_encode([
    'success' => true,
    'user_id' => $userId,
    'employee' => [
        'uid' => (int)$employee['uid'],
        'emp_code' => $employee['emp_code'],
        'name' => trim($employee['firstname'] . ' ' . $employee['lastname']),
        'ofc_mail' => $employee['ofc_mail'],
        'department' => $employee['department_name']
    ],
    'attempts_count' => $totalAttempts,
    'max_attempts' => $maxAttempts,
    'passing_score' => $passingScoreThreshold,
    'latest_score' => $latestScore,
    'is_passed' => $isPassedOverall,
    'allow_employee_certificate_download' => $allowCertDownload,
    'is_locked' => $isLocked,
    'can_retake' => $canRetake,
    'admin_unlocked' => $hasAdminOverride,
    'attempts' => $attemptsList,
    'module' => $assessmentModule
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
