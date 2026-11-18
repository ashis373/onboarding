<?php
header("Content-Type: application/json; charset=UTF-8");
$NEED_PDO = false;
require_once '../db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { echo json_encode(['success' => false, 'message' => 'Not logged in']); exit; }
if (!in_array((string)$user_id, ['26', '64', '205'], true)) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$id = intval($input['id'] ?? 0);
if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid id']); exit; }

$fields = ['title','type','description','duration_minutes','video_url','file_url','is_active'];
$sets = [];$vals = [];$types = '';
foreach ($fields as $f) {
  if (array_key_exists($f, $input)) {
    $sets[] = "$f = ?";
    $vals[] = $input[$f];
    $types .= is_int($input[$f]) ? 'i' : 's';
  }
}
if (empty($sets)) { echo json_encode(['success' => false, 'message' => 'No changes']); exit; }
$sql = "UPDATE onboard_modules SET ".implode(', ', $sets)." WHERE id = ?";
if ($stmt = $conn->prepare($sql)) {
  $types .= 'i';
  $vals[] = $id;
  $stmt->bind_param($types, ...$vals);
  $ok = $stmt->execute();
  $stmt->close();
  echo json_encode(['success' => $ok]);
  $conn->close();
  exit;
}
echo json_encode(['success' => false, 'message' => 'DB error']);
?>


