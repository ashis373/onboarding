<?php
header("Content-Type: application/json; charset=UTF-8");
$NEED_PDO = false;
require_once '../db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { echo json_encode(['success' => false, 'message' => 'Not logged in']); exit; }

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$module_id = intval($input['module_id'] ?? 0);
$progress_pct = isset($input['progress_pct']) ? max(0, min(100, intval($input['progress_pct']))) : null;
$time_spent = isset($input['time_spent_seconds']) ? max(0, intval($input['time_spent_seconds'])) : null;
if ($module_id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid module']); exit; }

// Detect optional columns
$has_last_view = false; $has_progress = false; $has_time = false;
$colSql = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'onboard_module_progress'";
if ($cres = $conn->query($colSql)) {
    while ($c = $cres->fetch_assoc()) {
        $n = strtolower($c['COLUMN_NAME']);
        if ($n === 'last_viewed_at') $has_last_view = true;
        if ($n === 'progress_pct') $has_progress = true;
        if ($n === 'time_spent_seconds') $has_time = true;
    }
}

// Ensure row exists
$ensure = $conn->prepare("INSERT INTO onboard_module_progress (user_id, module_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE module_id = module_id");
$ensure->bind_param('ii', $user_id, $module_id);
$ensure->execute();
$ensure->close();

// Build update
$sets = [];
if ($has_last_view) { $sets[] = "last_viewed_at = NOW()"; }
if ($has_progress && $progress_pct !== null) { $sets[] = "progress_pct = GREATEST(IFNULL(progress_pct,0), ?)"; }
if ($has_time && $time_spent !== null) { $sets[] = "time_spent_seconds = IFNULL(time_spent_seconds,0) + ?"; }
if (!$sets) { echo json_encode(['success' => true]); $conn->close(); exit; }

$sql = "UPDATE onboard_module_progress SET ". implode(', ', $sets) ." WHERE user_id = ? AND module_id = ?";
$stmt = $conn->prepare($sql);
if ($has_progress && $progress_pct !== null && $has_time && $time_spent !== null) {
  $stmt->bind_param('iiii', $progress_pct, $time_spent, $user_id, $module_id);
} elseif ($has_progress && $progress_pct !== null) {
  $stmt->bind_param('iii', $progress_pct, $user_id, $module_id);
} elseif ($has_time && $time_spent !== null) {
  $stmt->bind_param('iii', $time_spent, $user_id, $module_id);
} else {
  $stmt->bind_param('ii', $user_id, $module_id);
}
$ok = $stmt->execute();
$stmt->close();

echo json_encode(['success' => $ok]);
$conn->close();
?>


