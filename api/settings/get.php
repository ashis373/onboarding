<?php
header("Content-Type: application/json; charset=UTF-8");
$NEED_PDO = false;
require_once '../db.php';
require_once '../helpers.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { echo json_encode(['success' => false, 'message' => 'Not logged in']); exit; }
if (!in_array((string)$user_id, ['26', '64', '205'], true)) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }

// Check if table exists
if (!tableExists($conn, 'onboard_settings')) {
  echo json_encode(['success' => true, 'settings' => []]);
  $conn->close();
  exit;
}

$res = $conn->query("SELECT `key`, `value` FROM onboard_settings");
$settings = [];
if ($res) {
  while ($row = $res->fetch_assoc()) { $settings[$row['key']] = $row['value']; }
}
echo json_encode(['success' => true, 'settings' => $settings]);
$conn->close();
?>


