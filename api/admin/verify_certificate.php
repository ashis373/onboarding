<?php
/**
 * api/admin/verify_certificate.php
 * Official HexaLearn Certificate Verification API
 * Validates authenticity of certificates by Certificate Number (e.g. HL-2026-00001) or Employee Code.
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

ensureCertificateTables($conn);

$queryParam = trim($_GET['cert_no'] ?? $_GET['q'] ?? $_POST['cert_no'] ?? $_POST['q'] ?? '');

if (empty($queryParam)) {
    echo json_encode([
        'success' => false,
        'is_valid' => false,
        'message' => 'Please provide a Certificate Number (e.g. HL-2026-00001) or Employee Code to verify.'
    ]);
    exit;
}

$escaped = $conn->real_escape_string($queryParam);

// Query candidate matching either certificate_number, emp_code, or user_id
$query = "
    SELECT 
        cr.id AS cert_record_id,
        cr.certificate_number,
        cr.user_id,
        cr.emp_code,
        cr.emp_name,
        cr.quiz_score,
        cr.attempt_number,
        cr.passed_at,
        cr.issued_at,
        u.ofc_mail,
        u.profile_img,
        IFNULL(d.dept_name, 'General') AS dept_name,
        IFNULL(dl.download_count, 0) AS download_count,
        dl.first_downloaded_at,
        dl.last_downloaded_at
    FROM onboard_certificate_records cr
    JOIN user u ON u.uid = cr.user_id
    LEFT JOIN department d ON d.dept_id = u.department
    LEFT JOIN (
        SELECT 
            certificate_number,
            COUNT(*) AS download_count,
            MIN(downloaded_at) AS first_downloaded_at,
            MAX(downloaded_at) AS last_downloaded_at
        FROM onboard_certificate_downloads
        GROUP BY certificate_number
    ) dl ON dl.certificate_number = cr.certificate_number
    WHERE cr.certificate_number = '{$escaped}'
       OR cr.emp_code = '{$escaped}'
       OR u.emp_code = '{$escaped}'
       OR CONCAT('HL', u.uid) = '{$escaped}'
       OR cr.certificate_number LIKE '%{$escaped}%'
    ORDER BY cr.issued_at DESC
    LIMIT 1
";

$res = $conn->query($query);
if ($res && $row = $res->fetch_assoc()) {
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

    echo json_encode([
        'success' => true,
        'is_valid' => true,
        'status' => 'GENUINE_AND_AUTHENTIC',
        'certificate' => [
            'certificate_number' => $row['certificate_number'],
            'user_id' => (int)$row['user_id'],
            'emp_name' => trim($row['emp_name']),
            'emp_code' => $row['emp_code'] ?: "HL{$row['user_id']}",
            'ofc_mail' => $row['ofc_mail'],
            'department_name' => $row['dept_name'],
            'photo' => !empty($row['profile_img']) ? '/internal/corhis/dist/img/user/' . $row['profile_img'] : '',
            'quiz_score' => (float)$row['quiz_score'],
            'attempt_number' => (int)$row['attempt_number'],
            'passed_at' => $passedRaw,
            'passed_date_fmt' => $passedDateFmt,
            'passed_datetime_ist' => $passedDateTimeIst,
            'download_count' => (int)$row['download_count'],
            'first_downloaded_fmt' => $firstDlFmt,
            'last_downloaded_fmt' => $lastDlFmt,
            'issuer' => 'HexaLearn Solutions Private Limited',
            'course_title' => 'HexaLearn Employee Onboarding & Induction Assessment',
            'authorized_signatory' => 'Satyabrata Das',
            'verified_at' => date('d-M-Y h:i A')
        ]
    ]);
    exit;
}

// Fallback check: maybe the user passed assessment but hasn't had their record created yet?
$chkAttempt = $conn->query("
    SELECT u.uid, u.emp_code, CONCAT(u.firstname, ' ', u.lastname) AS emp_name
    FROM onboard_induction_assessment_attempts a
    JOIN user u ON u.uid = a.user_id
    WHERE a.result = 'Passed' AND (u.emp_code = '{$escaped}' OR CONCAT('HL', u.uid) = '{$escaped}')
    LIMIT 1
");
if ($chkAttempt && $uRow = $chkAttempt->fetch_assoc()) {
    // Generate their permanent record on-demand
    $rec = getOrIssueCertificateRecord($conn, (int)$uRow['uid'], $uRow['emp_code'], $uRow['emp_name']);
    // Re-verify
    echo json_encode([
        'success' => true,
        'is_valid' => true,
        'status' => 'GENUINE_AND_AUTHENTIC',
        'certificate' => [
            'certificate_number' => $rec['certificate_number'],
            'user_id' => (int)$rec['user_id'],
            'emp_name' => $rec['emp_name'],
            'emp_code' => $rec['emp_code'],
            'quiz_score' => (float)$rec['quiz_score'],
            'attempt_number' => (int)$rec['attempt_number'],
            'passed_date_fmt' => date('F j, Y', strtotime($rec['passed_at'])),
            'download_count' => 0,
            'first_downloaded_fmt' => null,
            'last_downloaded_fmt' => null,
            'issuer' => 'HexaLearn Solutions Private Limited',
            'course_title' => 'HexaLearn Employee Onboarding & Induction Assessment',
            'authorized_signatory' => 'Satyabrata Das',
            'verified_at' => date('d-M-Y h:i A')
        ]
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'is_valid' => false,
    'status' => 'NOT_FOUND',
    'message' => "No authentic certificate record was found matching '{$queryParam}'. It may be uncertified, incorrect, or fraudulent."
]);
