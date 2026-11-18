<?php
/**
 * Admin endpoint: api/admin/moodle_errors.php
 * Returns Moodle DB connection, timeout, 5xx, rate-limit, and player retry logs + frequency metrics.
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$NEED_PDO = false;
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../moodle/error_logger.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$userId = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

// Verify Admin Role
$isAdmin = false;
if (in_array((string)$userId, ['26', '64', '205'], true)) {
    $isAdmin = true;
} else {
    $roleCheck = $conn->prepare("SELECT user_role FROM user WHERE uid = ?");
    if ($roleCheck) {
        $roleCheck->bind_param('i', $userId);
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
    echo json_encode(['success' => false, 'message' => 'Unauthorized: Admin access required.']);
    exit;
}

session_write_close();

date_default_timezone_set('Asia/Kolkata');
@$conn->query("SET time_zone = '+05:30'");
ensureMoodleErrorLogsTable($conn);

// Auto-prune logs older than 30 days at most once per hour
$pruneMarker = __DIR__ . '/../moodle/cache/err_prune_30d.v';
if (!file_exists($pruneMarker) || (time() - filemtime($pruneMarker)) > 3600) {
    @$conn->query("DELETE FROM onboard_moodle_error_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $pDir = dirname($pruneMarker);
    if (!is_dir($pDir)) @mkdir($pDir, 0755, true);
    @file_put_contents($pruneMarker, (string)time());
}

// Optional cleanup action for old logs
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    if ($action === 'clear_old') {
        $days = isset($input['days']) ? max(1, (int)$input['days']) : 30;
        $stmtDel = $conn->prepare("DELETE FROM onboard_moodle_error_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
        if ($stmtDel) {
            $stmtDel->bind_param("i", $days);
            $stmtDel->execute();
            $deleted = $stmtDel->affected_rows;
            $stmtDel->close();
            echo json_encode(['success' => true, 'deleted' => $deleted]);
            exit;
        }
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid action.']);
    exit;
}

$days = isset($_GET['days']) ? max(1, min(90, (int)$_GET['days'])) : 7;
$errorTypeFilter = trim((string)($_GET['error_type'] ?? 'all'));
$limit = isset($_GET['limit']) ? max(10, min(200, (int)$_GET['limit'])) : 50;

// 1. Summary Metrics
$summary = [
    'total_24h' => 0,
    'total_7d' => 0,
    'db_errors_24h' => 0,
    'db_errors_7d' => 0,
    'player_retries_7d' => 0,
    'by_type' => []
];

$sumRes = $conn->query("
    SELECT
        SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 ELSE 0 END) AS total_24h,
        SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS total_7d,
        SUM(CASE WHEN error_type = 'db_connection_failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 ELSE 0 END) AS db_errors_24h,
        SUM(CASE WHEN error_type = 'db_connection_failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS db_errors_7d,
        SUM(CASE WHEN error_type IN ('player_retry', 'player_load_error') AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS player_retries_7d
    FROM onboard_moodle_error_logs
");
if ($sumRes && $row = $sumRes->fetch_assoc()) {
    $summary['total_24h'] = (int)($row['total_24h'] ?? 0);
    $summary['total_7d'] = (int)($row['total_7d'] ?? 0);
    $summary['db_errors_24h'] = (int)($row['db_errors_24h'] ?? 0);
    $summary['db_errors_7d'] = (int)($row['db_errors_7d'] ?? 0);
    $summary['player_retries_7d'] = (int)($row['player_retries_7d'] ?? 0);
}

$typeRes = $conn->query("
    SELECT error_type, COUNT(*) AS cnt
    FROM onboard_moodle_error_logs
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
    GROUP BY error_type
    ORDER BY cnt DESC
");
if ($typeRes) {
    while ($tr = $typeRes->fetch_assoc()) {
        $summary['by_type'][$tr['error_type']] = (int)$tr['cnt'];
    }
}

// 2. Recent Logs
$whereParts = ["l.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)"];
$bindTypes = "i";
$bindVals = [$days];

if ($errorTypeFilter !== '' && $errorTypeFilter !== 'all') {
    $whereParts[] = "l.error_type = ?";
    $bindTypes .= "s";
    $bindVals[] = $errorTypeFilter;
}

$whereSql = implode(" AND ", $whereParts);
$bindTypes .= "i";
$bindVals[] = $limit;

$logs = [];
$sql = "
    SELECT l.id, l.user_id, l.moodle_user_id, l.course_id, l.cmid,
           COALESCE(l.course_title, m.title, CASE WHEN l.course_id > 0 THEN CONCAT('Course #', l.course_id) ELSE '—' END) AS course_name,
           l.endpoint, l.ws_function, l.error_type, l.http_code, l.error_message, l.ip_address, l.created_at,
           TRIM(CONCAT(IFNULL(u.firstname, ''), ' ', IFNULL(u.lastname, ''))) AS employee_name,
           u.username
    FROM onboard_moodle_error_logs l
    LEFT JOIN user u ON u.uid = l.user_id
    LEFT JOIN onboard_modules m ON (l.course_id > 0 AND m.moodle_course_id = l.course_id)
    WHERE {$whereSql}
    ORDER BY l.id DESC
    LIMIT ?
";

$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param($bindTypes, ...$bindVals);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $logs[] = [
            'id' => (int)$r['id'],
            'user_id' => $r['user_id'] ? (int)$r['user_id'] : null,
            'employee_name' => trim((string)$r['employee_name']) ?: ($r['username'] ?: ($r['user_id'] ? 'User #' . $r['user_id'] : 'System')),
            'username' => $r['username'] ?? '',
            'moodle_user_id' => $r['moodle_user_id'] ? (int)$r['moodle_user_id'] : null,
            'course_id' => $r['course_id'] ? (int)$r['course_id'] : null,
            'cmid' => $r['cmid'] ? (int)$r['cmid'] : null,
            'course_name' => $r['course_name'],
            'endpoint' => $r['endpoint'],
            'ws_function' => $r['ws_function'],
            'error_type' => $r['error_type'],
            'http_code' => $r['http_code'] ? (int)$r['http_code'] : null,
            'error_message' => $r['error_message'],
            'created_at' => !empty($r['created_at'])
                ? date('d M Y, h:i:s A', (strtotime($r['created_at']) > time() + 300 ? strtotime($r['created_at']) - 19800 : strtotime($r['created_at']))) . ' IST'
                : '',
        ];
    }
    $stmt->close();
}

if (isset($conn) && $conn instanceof mysqli) {
    @$conn->close();
}

echo json_encode([
    'success' => true,
    'summary' => $summary,
    'logs' => $logs
], JSON_UNESCAPED_SLASHES);

