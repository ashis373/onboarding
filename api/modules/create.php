<?php
header("Content-Type: application/json; charset=UTF-8");
$NEED_PDO = false;
require_once '../db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { echo json_encode(['success' => false, 'message' => 'Not logged in']); exit; }
if (!in_array((string)$user_id, ['26', '64', '205'], true)) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$title = trim($input['title'] ?? '');
$type = trim($input['type'] ?? 'video');
$description = trim($input['description'] ?? '');
$duration = intval($input['duration_minutes'] ?? 0);
$video_url = $input['video_url'] ?? null;
$file_url = $input['file_url'] ?? null;

if ($title === '') { echo json_encode(['success' => false, 'message' => 'Title required']); exit; }

$sql = "INSERT INTO onboard_modules (title, type, description, duration_minutes, video_url, file_url, is_active) VALUES (?,?,?,?,?,?,1)";
if ($stmt = $conn->prepare($sql)) {
  $stmt->bind_param('sssiss', $title, $type, $description, $duration, $video_url, $file_url);
  $ok = $stmt->execute();
  $id = $conn->insert_id;
  $stmt->close();
  echo json_encode(['success' => $ok, 'id' => $id]);
  $conn->close();
  exit;
}
echo json_encode(['success' => false, 'message' => 'DB error']);
?>


