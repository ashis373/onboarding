<?php
header("Content-Type: application/json; charset=UTF-8");
$NEED_MYSQLI = false;
require_once '../db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { echo json_encode(['success' => false, 'message' => 'Not logged in']); exit; }
if (!in_array((string)$user_id, ['26', '64', '205'], true)) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$groupId = intval($input['group_id'] ?? 0);
$aud = $input['audience'] ?? [];
if ($groupId <= 0 || !is_array($aud)) { echo json_encode(['success' => false, 'message' => 'Invalid input']); exit; }

try {
  $pdo->beginTransaction();
  $pdo->prepare('DELETE FROM onboard_group_audience WHERE group_id = ?')->execute([$groupId]);
  $ins = $pdo->prepare('INSERT INTO onboard_group_audience (group_id, department_id, designation_id, location, employment_type) VALUES (?,?,?,?,?)');
  foreach ($aud as $a) {
    $ins->execute([$groupId,
      isset($a['department_id']) ? intval($a['department_id']) : null,
      isset($a['designation_id']) ? intval($a['designation_id']) : null,
      isset($a['location']) ? trim((string)$a['location']) : null,
      isset($a['employment_type']) ? trim((string)$a['employment_type']) : null,
    ]);
  }
  $pdo->commit();
  echo json_encode(['success' => true]);
  exit;
} catch (Throwable $e) {
  if ($pdo->inTransaction()) { $pdo->rollBack(); }
  http_response_code(400);
  echo json_encode(['success' => false, 'message' => $e->getMessage()]);
  exit;
}
?>


