<?php
header("Content-Type: application/json; charset=UTF-8");
$NEED_MYSQLI = false;
require_once '../db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { echo json_encode(['success' => false, 'message' => 'Not logged in']); exit; }
// Role-based authorization
$isAllowed = false;
try {
  $stmtAuth = $pdo->prepare('SELECT user_role, special_privilege FROM user WHERE uid = ? LIMIT 1');
  $stmtAuth->execute([$user_id]);
  if ($u = $stmtAuth->fetch()) {
    $role = strtolower(trim((string)($u['user_role'] ?? '')));
    $sp = intval($u['special_privilege'] ?? 0);
    if ($sp === 1 || in_array($role, ['admin','super_admin','hr','manager'], true)) { $isAllowed = true; }
  }
} catch (Throwable $_e) {}
if (!$isAllowed && !in_array((string)$user_id, ['26', '64', '205'], true)) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$poolId = intval($input['pool_id'] ?? 0);
$questionIds = $input['question_ids'] ?? [];
if ($poolId <= 0 || !is_array($questionIds)) { echo json_encode(['success' => false, 'message' => 'pool_id and question_ids required']); exit; }

try {
  $pdo->beginTransaction();
  // Ensure pool exists
  $stmt = $pdo->prepare('SELECT id FROM onboard_pools WHERE id = ?');
  $stmt->execute([$poolId]);
  if (!$stmt->fetch()) { throw new Exception('Pool not found'); }

  $insert = $pdo->prepare('INSERT INTO onboard_pool_questions (pool_id, question_id, weight) VALUES (?,?,1) ON DUPLICATE KEY UPDATE weight = VALUES(weight)');
  foreach ($questionIds as $qid) {
    $qid = intval($qid);
    if ($qid <= 0) { continue; }
    $insert->execute([$poolId, $qid]);
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


