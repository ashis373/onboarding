<?php
// Endpoint: api/moodle/track_certificate_download.php
// Tracks when an employee or admin downloads their official completion certificate

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

$sessionUid = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$sessionUid) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);
if (!is_array($data)) {
    $data = $_POST;
}

// Target User ID: employee himself or specified by Admin
$targetUid = isset($data['user_id']) ? (int)$data['user_id'] : (int)$sessionUid;
if ($targetUid <= 0) {
    $targetUid = (int)$sessionUid;
}

try {
    $res = recordCertificateDownload($conn, $targetUid, (int)$sessionUid);
    
    $lastDlFmt = date('d-M-Y h:i A', strtotime($res['last_downloaded_at']));

    echo json_encode([
        'success' => true,
        'user_id' => $targetUid,
        'certificate_number' => $res['certificate_number'],
        'download_count' => (int)$res['download_count'],
        'last_downloaded_at' => $res['last_downloaded_at'],
        'last_downloaded_fmt' => $lastDlFmt,
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to record download: ' . $e->getMessage()
    ]);
}
