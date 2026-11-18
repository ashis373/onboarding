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
$order = $input['order'] ?? [];
if (!is_array($order) || count($order) === 0) { echo json_encode(['success' => false, 'message' => 'order required']); exit; }

try {
  // Ensure position column exists
  $hasPos = false;
  $c = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'onboard_assessments'")->fetchAll();
  foreach ($c as $col) { if (strtolower($col['COLUMN_NAME']) === 'position') { $hasPos = true; break; } }
  if (!$hasPos) {
    $pdo->exec("ALTER TABLE onboard_assessments ADD COLUMN position INT NULL DEFAULT NULL");
  }

  $pdo->beginTransaction();
  $upd = $pdo->prepare('UPDATE onboard_assessments SET position = ? WHERE id = ?');
  $pos = 0;
  foreach ($order as $id) {
    $id = intval($id);
    if ($id > 0) { $upd->execute([$pos++, $id]); }
  }
  $pdo->commit();
  echo json_encode(['success' => true]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) { $pdo->rollBack(); }
  http_response_code(400);
  echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>

<?php
header("Content-Type: application/json; charset=UTF-8");
require_once '../db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { echo json_encode(['success' => false, 'message' => 'Not logged in']); exit; }
if (!in_array((string)$user_id, ['26', '64', '205'], true)) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$id = intval($input['id'] ?? 0);
if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid id']); exit; }

$fields = ['title','description','time_limit_minutes','attempt_limit','passing_score','randomize_questions','is_active'];
$sets = [];$params = [];
foreach ($fields as $f) {
  if (array_key_exists($f, $input)) {
    $sets[] = "$f = ?";
    $params[] = $input[$f];
  }
}
if (!$sets) { echo json_encode(['success' => false, 'message' => 'No changes']); exit; }

try {
  $pdo->beginTransaction();

  $sql = 'UPDATE onboard_assessments SET '.implode(', ', $sets).' WHERE id = ?';
  $params[] = $id;
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);

  if (isset($input['pools']) && is_array($input['pools'])) {
    // Replace pool mappings
    $pdo->prepare('DELETE FROM onboard_assessment_pools WHERE assessment_id = ?')->execute([$id]);
    $insert = $pdo->prepare('INSERT INTO onboard_assessment_pools (assessment_id, pool_id, questions_to_draw, position) VALUES (?,?,?,?)');
    $position = 0;
    foreach ($input['pools'] as $p) {
      $poolId = intval($p['pool_id'] ?? 0);
      $draw = intval($p['questions_to_draw'] ?? 0);
      $pos = isset($p['position']) ? intval($p['position']) : $position++;
      if ($poolId > 0 && $draw > 0) { $insert->execute([$id, $poolId, $draw, $pos]); }
    }
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


