<?php
header("Content-Type: application/json; charset=UTF-8");
$NEED_PDO = false;
require_once '../db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { echo json_encode(['success' => false, 'message' => 'Not logged in']); exit; }

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$module_id = intval($input['module_id'] ?? 0);
if ($module_id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid module']); exit; }

// Detect optional columns safely
$has_started = false; $has_last_view = false; $has_progress = false; $has_time = false;
$colSql = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'onboard_module_progress'";
if ($cres = $conn->query($colSql)) {
    while ($c = $cres->fetch_assoc()) {
        $n = strtolower($c['COLUMN_NAME']);
        if ($n === 'started_at') $has_started = true;
        if ($n === 'last_viewed_at') $has_last_view = true;
        if ($n === 'progress_pct') $has_progress = true;
        if ($n === 'time_spent_seconds') $has_time = true;
    }
}

// Build dynamic UPSERT
$fields = "user_id, module_id"; $place = "?, ?"; $updates = [];
if ($has_started) { $fields .= ", started_at"; $place .= ", NOW()"; $updates[] = "started_at = COALESCE(started_at, VALUES(started_at))"; }
if ($has_last_view) { $fields .= ", last_viewed_at"; $place .= ", NOW()"; $updates[] = "last_viewed_at = VALUES(last_viewed_at)"; }
if ($has_progress) { $fields .= ", progress_pct"; $place .= ", 1"; $updates[] = "progress_pct = GREATEST(progress_pct, VALUES(progress_pct))"; }
if ($has_time) { $fields .= ", time_spent_seconds"; $place .= ", 0"; $updates[] = "time_spent_seconds = COALESCE(time_spent_seconds,0)"; }
// Always ensure a row exists even if optional cols don't
if ($fields === "user_id, module_id") { $updates[] = "module_id = module_id"; }

$sql = "INSERT INTO onboard_module_progress ($fields) VALUES ($place) ON DUPLICATE KEY UPDATE ". implode(', ', $updates);

if ($stmt = $conn->prepare($sql)) {
  $stmt->bind_param('ii', $user_id, $module_id);
  $ok = $stmt->execute();
  $stmt->close();
  echo json_encode(['success' => $ok]);
  $conn->close();
  exit;
}

echo json_encode(['success' => false, 'message' => 'Failed to start module']);
$conn->close();
?>


