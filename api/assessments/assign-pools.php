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
$assessmentId = intval($input['assessment_id'] ?? 0);
$pools = $input['pools'] ?? [];
if ($assessmentId <= 0 || !is_array($pools)) { echo json_encode(['success' => false, 'message' => 'assessment_id and pools required']); exit; }

try {
  $pdo->beginTransaction();
  $pdo->prepare('DELETE FROM onboard_assessment_pools WHERE assessment_id = ?')->execute([$assessmentId]);
  $insert = $pdo->prepare('INSERT INTO onboard_assessment_pools (assessment_id, pool_id, questions_to_draw, position) VALUES (?,?,?,?)');
  $position = 0;
  foreach ($pools as $p) {
    $poolId = intval($p['pool_id'] ?? 0);
    $draw = intval($p['questions_to_draw'] ?? 0);
    $pos = isset($p['position']) ? intval($p['position']) : $position++;
    if ($poolId > 0 && $draw > 0) { $insert->execute([$assessmentId, $poolId, $draw, $pos]); }
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


