<?php
/**
 * Admin endpoint: api/admin/moodle_api_usage.php
 * Returns per-user, per-endpoint, and per-scenario Moodle REST API call counts & cache efficiency.
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$NEED_PDO = false;
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../moodle/api_usage_logger.php';

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
ensureMoodleApiLogsTable($conn);

// Auto-prune logs older than 14 days at most once per hour
$pruneMarker = __DIR__ . '/../moodle/cache/api_usage_prune_14d.v';
if (!file_exists($pruneMarker) || (time() - filemtime($pruneMarker)) > 3600) {
    $cutoffIst = date('Y-m-d H:i:s', time() - (14 * 86400));
    @$conn->query("DELETE FROM onboard_moodle_api_logs WHERE created_at < '{$cutoffIst}'");
    $pDir = dirname($pruneMarker);
    if (!is_dir($pDir)) @mkdir($pDir, 0755, true);
    @file_put_contents($pruneMarker, (string)time());
}

$days = isset($_GET['days']) ? max(1, min(14, (int)$_GET['days'])) : 1;
$endpointFilter = trim((string)($_GET['endpoint'] ?? 'all'));
$limit = isset($_GET['limit']) ? max(10, min(200, (int)$_GET['limit'])) : 60;

$sinceIst = date('Y-m-d H:i:s', time() - ($days * 86400));
$since24h = date('Y-m-d H:i:s', time() - 86400);

// 1. Summary KPIs (Last 24h)
$summary = [
    'requests_24h' => 0,
    'live_calls_24h' => 0,
    'cached_calls_24h' => 0,
    'user_cache_hits_24h' => 0,
    'failed_calls_24h' => 0,
    'cache_hit_rate' => 0,
    'avg_live_per_sync' => 0,
];

$sumRes = $conn->query("
    SELECT
        COUNT(*) AS requests_24h,
        COALESCE(SUM(live_calls), 0) AS live_calls_24h,
        COALESCE(SUM(cached_calls), 0) AS cached_calls_24h,
        COALESCE(SUM(CASE WHEN scenario = 'user_cache_hit' THEN 1 ELSE 0 END), 0) AS user_cache_hits_24h,
        COALESCE(SUM(failed_calls), 0) AS failed_calls_24h,
        COALESCE(AVG(CASE WHEN live_calls > 0 THEN live_calls ELSE NULL END), 0) AS avg_live_per_sync
    FROM onboard_moodle_api_logs
    WHERE created_at >= '{$since24h}'
");
if ($sumRes && $row = $sumRes->fetch_assoc()) {
    $summary['requests_24h'] = (int)$row['requests_24h'];
    $summary['live_calls_24h'] = (int)$row['live_calls_24h'];
    $summary['cached_calls_24h'] = (int)$row['cached_calls_24h'];
    $summary['user_cache_hits_24h'] = (int)$row['user_cache_hits_24h'];
    $summary['failed_calls_24h'] = (int)$row['failed_calls_24h'];
    $summary['avg_live_per_sync'] = round((float)$row['avg_live_per_sync'], 1);

    $totalReq = $summary['requests_24h'];
    $zeroCallReqs = $summary['user_cache_hits_24h'];
    $summary['cache_hit_rate'] = $totalReq > 0 ? round(($zeroCallReqs / $totalReq) * 100, 1) : 0;
}

// 2. Breakdown by Endpoint & Scenario
$byScenario = [];
$scenRes = $conn->query("
    SELECT endpoint, scenario,
           COUNT(*) AS requests,
           COALESCE(SUM(live_calls), 0) AS total_live,
           COALESCE(SUM(cached_calls), 0) AS total_cached,
           COALESCE(SUM(failed_calls), 0) AS total_failed,
           ROUND(COALESCE(AVG(live_calls), 0), 1) AS avg_live,
           ROUND(COALESCE(AVG(duration_ms), 0), 0) AS avg_ms
    FROM onboard_moodle_api_logs
    WHERE created_at >= '{$sinceIst}'
    GROUP BY endpoint, scenario
    ORDER BY total_live DESC, requests DESC
");
if ($scenRes) {
    while ($sr = $scenRes->fetch_assoc()) {
        $byScenario[] = [
            'endpoint' => $sr['endpoint'],
            'scenario' => $sr['scenario'],
            'requests' => (int)$sr['requests'],
            'total_live' => (int)$sr['total_live'],
            'total_cached' => (int)$sr['total_cached'],
            'total_failed' => (int)$sr['total_failed'],
            'avg_live' => (float)$sr['avg_live'],
            'avg_ms' => (int)$sr['avg_ms'],
        ];
    }
}

// 3. Top Users by Live Moodle Calls
$byUser = [];
$usrRes = $conn->query("
    SELECT l.user_id,
           TRIM(CONCAT(IFNULL(u.firstname, ''), ' ', IFNULL(u.lastname, ''))) AS employee_name,
           u.username,
           COUNT(*) AS requests,
           COALESCE(SUM(l.live_calls), 0) AS total_live,
           COALESCE(SUM(l.cached_calls), 0) AS total_cached,
           COALESCE(SUM(CASE WHEN l.scenario = 'user_cache_hit' THEN 1 ELSE 0 END), 0) AS cache_hits
    FROM onboard_moodle_api_logs l
    LEFT JOIN user u ON u.uid = l.user_id
    WHERE l.created_at >= '{$sinceIst}'
    GROUP BY l.user_id
    ORDER BY total_live DESC, requests DESC
    LIMIT 20
");
if ($usrRes) {
    while ($ur = $usrRes->fetch_assoc()) {
        $byUser[] = [
            'user_id' => $ur['user_id'] ? (int)$ur['user_id'] : null,
            'employee_name' => trim((string)$ur['employee_name']) ?: ($ur['username'] ?: ($ur['user_id'] ? 'User #' . $ur['user_id'] : 'System')),
            'requests' => (int)$ur['requests'],
            'total_live' => (int)$ur['total_live'],
            'total_cached' => (int)$ur['total_cached'],
            'cache_hits' => (int)$ur['cache_hits'],
        ];
    }
}

// 4. Recent Request Logs
$whereParts = ["l.created_at >= ?"];
$bindTypes = "s";
$bindVals = [$sinceIst];

if ($endpointFilter !== '' && $endpointFilter !== 'all') {
    $whereParts[] = "l.endpoint = ?";
    $bindTypes .= "s";
    $bindVals[] = $endpointFilter;
}

$whereSql = implode(" AND ", $whereParts);
$bindTypes .= "i";
$bindVals[] = $limit;

$logs = [];
$sql = "
    SELECT l.id, l.user_id, l.moodle_user_id, l.endpoint, l.scenario,
           l.live_calls, l.cached_calls, l.failed_calls, l.duration_ms, l.ws_functions, l.created_at,
           TRIM(CONCAT(IFNULL(u.firstname, ''), ' ', IFNULL(u.lastname, ''))) AS employee_name,
           u.username
    FROM onboard_moodle_api_logs l
    LEFT JOIN user u ON u.uid = l.user_id
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
            'endpoint' => $r['endpoint'],
            'scenario' => $r['scenario'],
            'live_calls' => (int)$r['live_calls'],
            'cached_calls' => (int)$r['cached_calls'],
            'failed_calls' => (int)$r['failed_calls'],
            'duration_ms' => (int)$r['duration_ms'],
            'ws_functions' => $r['ws_functions'] ?? '—',
            'created_at' => !empty($r['created_at']) ? date('d M Y, h:i:s A', strtotime($r['created_at'])) . ' IST' : '',
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
    'by_scenario' => $byScenario,
    'by_user' => $byUser,
    'logs' => $logs
], JSON_UNESCAPED_SLASHES);

