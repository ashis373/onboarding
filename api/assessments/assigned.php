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

$assessmentId = intval($_GET['assessment_id'] ?? $_POST['assessment_id'] ?? 0);
if ($assessmentId <= 0) { echo json_encode(['success' => false, 'message' => 'assessment_id required']); exit; }

try {
  $pstmt = $pdo->prepare('SELECT ap.pool_id, ap.questions_to_draw, ap.position, p.name, p.description FROM onboard_assessment_pools ap JOIN onboard_pools p ON p.id = ap.pool_id WHERE ap.assessment_id = ? ORDER BY ap.position');
  $pstmt->execute([$assessmentId]);
  $pools = $pstmt->fetchAll();

  $qp = $pdo->prepare('SELECT q.id, q.type_code AS type, q.prompt FROM onboard_pool_questions pq JOIN onboard_questions q ON q.id = pq.question_id WHERE pq.pool_id = ? ORDER BY q.id DESC');
  foreach ($pools as &$p) {
    $qp->execute([intval($p['pool_id'])]);
    $p['questions'] = $qp->fetchAll();
  }

  echo json_encode(['success' => true, 'pools' => $pools]);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>


