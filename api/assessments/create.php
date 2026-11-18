<?php
header("Content-Type: application/json; charset=UTF-8");
$NEED_MYSQLI = false;
require_once '../db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { echo json_encode(['success' => false, 'message' => 'Not logged in']); exit; }

// Authorization: allow special_privilege or admin-like roles; keep legacy UID fallback
$isAllowed = false;
try {
  $stmtAuth = $pdo->prepare('SELECT user_role, special_privilege FROM user WHERE uid = ? LIMIT 1');
  $stmtAuth->execute([$user_id]);
  if ($u = $stmtAuth->fetch()) {
    $role = strtolower(trim((string)($u['user_role'] ?? '')));
    $sp = intval($u['special_privilege'] ?? 0);
    if ($sp === 1 || in_array($role, ['admin','super_admin','hr','manager'], true)) {
      $isAllowed = true;
    }
  }
} catch (Throwable $_e) { /* ignore and fallback to legacy allowlist */ }
if (!$isAllowed && !in_array((string)$user_id, ['26', '64', '205'], true)) {
  echo json_encode(['success' => false, 'message' => 'Unauthorized']);
  exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$title = trim($input['title'] ?? '');
$description = isset($input['description']) ? trim((string)$input['description']) : null;
$timeLimit = isset($input['time_limit_minutes']) ? intval($input['time_limit_minutes']) : null;
$attemptLimit = isset($input['attempt_limit']) ? intval($input['attempt_limit']) : null;
$passingScore = isset($input['passing_score']) ? intval($input['passing_score']) : null;
$randomize = array_key_exists('randomize_questions', $input) ? (int)!!$input['randomize_questions'] : 1;
$isActive = array_key_exists('is_active', $input) ? (int)!!$input['is_active'] : 1;
$pools = $input['pools'] ?? null; // [{pool_id, questions_to_draw, position}]

if ($title === '') { echo json_encode(['success' => false, 'message' => 'Title required']); exit; }

try {
  $pdo->beginTransaction();

  // Detect existing columns for resilient insert
  $cols = [];
  $placeholders = [];
  $values = [];
  $existing = [];
  $cstmt = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'onboard_assessments'");
  foreach ($cstmt->fetchAll() as $c) { $existing[strtolower($c['COLUMN_NAME'])] = true; }

  $cols[] = 'title'; $placeholders[] = '?'; $values[] = $title;
  if ($description !== null && isset($existing['description'])) { $cols[] = 'description'; $placeholders[] = '?'; $values[] = $description; }
  if ($timeLimit !== null && isset($existing['time_limit_minutes'])) { $cols[] = 'time_limit_minutes'; $placeholders[] = '?'; $values[] = $timeLimit; }
  if ($attemptLimit !== null && isset($existing['attempt_limit'])) { $cols[] = 'attempt_limit'; $placeholders[] = '?'; $values[] = $attemptLimit; }
  if ($passingScore !== null && isset($existing['passing_score'])) { $cols[] = 'passing_score'; $placeholders[] = '?'; $values[] = $passingScore; }
  if (isset($existing['randomize_questions'])) { $cols[] = 'randomize_questions'; $placeholders[] = '?'; $values[] = $randomize; }
  if (isset($existing['is_active'])) { $cols[] = 'is_active'; $placeholders[] = '?'; $values[] = $isActive; }

  $sql = 'INSERT INTO onboard_assessments (' . implode(',', $cols) . ') VALUES (' . implode(',', $placeholders) . ')';
  $stmt = $pdo->prepare($sql);
  $stmt->execute($values);
  $assessmentId = (int)$pdo->lastInsertId();

  // Optionally insert pools if table exists
  if (is_array($pools)) {
    $hasPools = false;
    $chk = $pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'onboard_assessment_pools'");
    if ($chk && $chk->fetch()) { $hasPools = true; }
    if ($hasPools) {
      $insertPool = $pdo->prepare('INSERT INTO onboard_assessment_pools (assessment_id, pool_id, questions_to_draw, position) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE questions_to_draw = VALUES(questions_to_draw), position = VALUES(position)');
      $position = 0;
      foreach ($pools as $p) {
        $poolId = intval($p['pool_id'] ?? 0);
        $draw = intval($p['questions_to_draw'] ?? 0);
        $pos = isset($p['position']) ? intval($p['position']) : $position++;
        if ($poolId > 0 && $draw > 0) { $insertPool->execute([$assessmentId, $poolId, $draw, $pos]); }
      }
    }
  }

  $pdo->commit();
  echo json_encode(['success' => true, 'id' => $assessmentId]);
  exit;
} catch (Throwable $e) {
  if ($pdo->inTransaction()) { $pdo->rollBack(); }
  http_response_code(400);
  echo json_encode(['success' => false, 'message' => $e->getMessage()]);
  exit;
}
?>


