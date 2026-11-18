<?php
header("Content-Type: application/json; charset=UTF-8");
require_once '../db.php';
require_once '../helpers.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { echo json_encode(['success' => false, 'message' => 'Not logged in']); exit; }
if (!in_array((string)$user_id, ['26', '64', '205'], true)) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$sectionId = intval($input['section_id'] ?? 0);
$orderedIds = $input['ordered_ids'] ?? [];
if ($sectionId <= 0 || !is_array($orderedIds)) { echo json_encode(['success' => false, 'message' => 'Invalid input']); exit; }

// Check if table exists
if (!tableExists($conn, 'onboard_sequence_items')) {
    echo json_encode(['success' => false, 'message' => 'Sequence feature not enabled yet.']);
    exit;
}

try {
  $pdo->beginTransaction();
  $pos = 0;
  $stmt = $pdo->prepare('UPDATE onboard_sequence_items SET position = ? WHERE id = ? AND section_id = ?');
  foreach ($orderedIds as $id) {
    $id = intval($id);
    if ($id > 0) { $stmt->execute([$pos++, $id, $sectionId]); }
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


