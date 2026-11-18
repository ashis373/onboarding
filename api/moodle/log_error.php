<?php
/**
 * Authenticated endpoint for logging player-side Moodle load retries / errors
 * into Onboard MySQL (`onboard_moodle_error_logs`).
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$NEED_PDO = false;
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/error_logger.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$userId = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$errorType = trim((string)($input['error_type'] ?? 'player_retry'));
$allowedTypes = ['player_retry', 'player_load_error', 'db_connection_failed', 'http_5xx', 'timeout'];
if (!in_array($errorType, $allowedTypes, true)) {
    $errorType = 'player_retry';
}

$courseId = !empty($input['course_id']) ? (int)$input['course_id'] : null;
$cmid = !empty($input['cmid']) ? (int)$input['cmid'] : null;
$courseTitle = !empty($input['course_title']) ? trim((string)$input['course_title']) : null;
$message = !empty($input['message']) ? trim((string)$input['message']) : 'User refreshed course player window';
$httpCode = !empty($input['http_code']) ? (int)$input['http_code'] : null;

$moodleUid = null;
if ($stmtM = $conn->prepare("SELECT moodle_user_id FROM onboard_moodle_users WHERE hexa_uid = ? LIMIT 1")) {
    $stmtM->bind_param("i", $userId);
    $stmtM->execute();
    $mRow = $stmtM->get_result()->fetch_assoc();
    $stmtM->close();
    if ($mRow && !empty($mRow['moodle_user_id'])) {
        $moodleUid = (int)$mRow['moodle_user_id'];
    }
}

logMoodleError($conn, [
    'user_id' => (int)$userId,
    'moodle_user_id' => $moodleUid,
    'course_id' => $courseId,
    'cmid' => $cmid,
    'course_title' => $courseTitle,
    'endpoint' => 'ScormPlayerPage',
    'ws_function' => 'mod/scorm/player.php',
    'error_type' => $errorType,
    'http_code' => $httpCode,
    'error_message' => $message,
]);

if (isset($conn) && $conn instanceof mysqli) {
    @$conn->close();
}

echo json_encode(['success' => true]);

