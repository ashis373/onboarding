<?php
header("Content-Type: application/json; charset=UTF-8");
$NEED_PDO = false;
require_once '../db.php';
require_once '../helpers.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { echo json_encode(['success' => false, 'message' => 'Not logged in']); exit; }
if (!in_array((string)$user_id, ['26', '64', '205'], true)) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$key = trim($input['key'] ?? '');
$value = isset($input['value']) ? (string)$input['value'] : '';
if ($key === '') { echo json_encode(['success' => false, 'message' => 'Key required']); exit; }

// Check if table exists
if (!tableExists($conn, 'onboard_settings')) {
    echo json_encode(['success' => false, 'message' => 'Settings feature not enabled yet.']);
    exit;
}

$conn->query("CREATE TABLE IF NOT EXISTS onboard_settings (
  `key` VARCHAR(128) PRIMARY KEY,
  `value` TEXT
) ENGINE=InnoDB");

if ($stmt = $conn->prepare("INSERT INTO onboard_settings (`key`, `value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)")) {
  $stmt->bind_param('ss', $key, $value);
  $ok = $stmt->execute();
  $stmt->close();
  echo json_encode(['success' => $ok]);
  $conn->close();
  exit;
}
echo json_encode(['success' => false, 'message' => 'DB error']);
?>


