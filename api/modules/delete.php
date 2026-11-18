<?php
header("Content-Type: application/json; charset=UTF-8");
$NEED_PDO = false;
require_once '../db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { echo json_encode(['success' => false, 'message' => 'Not logged in']); exit; }
if (!in_array((string)$user_id, ['26', '64', '205'], true)) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }

$id = intval($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid id']); exit; }

if ($stmt = $conn->prepare("DELETE FROM onboard_modules WHERE id = ?")) {
  $stmt->bind_param('i', $id);
  $ok = $stmt->execute();
  $stmt->close();
  echo json_encode(['success' => $ok]);
  $conn->close();
  exit;
}
echo json_encode(['success' => false, 'message' => 'DB error']);
?>


