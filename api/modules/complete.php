<?php
header("Content-Type: application/json; charset=UTF-8");
$NEED_PDO = false;
require_once '../db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { echo json_encode(['success' => false, 'message' => 'Not logged in']); exit; }

$module_id = isset($_POST['module_id']) ? intval($_POST['module_id']) : 0;
if ($module_id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid module']); exit; }

// Upsert progress
$sql = "INSERT INTO onboard_module_progress (user_id, module_id, is_completed, completed_at)
        VALUES (?, ?, 1, NOW())
        ON DUPLICATE KEY UPDATE is_completed = VALUES(is_completed), completed_at = VALUES(completed_at)";

// If progress_pct column exists, also set it to 100
$hasProgress = false;
if ($res = $conn->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'onboard_module_progress' AND COLUMN_NAME = 'progress_pct'")) {
  $hasProgress = $res->num_rows > 0;
}

if ($stmt = $conn->prepare($sql)) {
  $stmt->bind_param('ii', $user_id, $module_id);
  $ok = $stmt->execute();
  $stmt->close();
  echo json_encode(['success' => $ok]);
  $conn->close();
  exit;
}

echo json_encode(['success' => false, 'message' => 'Failed to update progress']);
$conn->close();
?>

