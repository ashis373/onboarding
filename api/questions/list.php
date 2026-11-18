<?php
header("Content-Type: application/json; charset=UTF-8");
$NEED_MYSQLI = false;
require_once '../db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { echo json_encode(['success' => false, 'message' => 'Not logged in']); exit; }
// Role-based auth
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

$q = trim($_GET['q'] ?? '');
$type = trim($_GET['type'] ?? '');
$limit = max(1, min(100, intval($_GET['limit'] ?? 50)));
$offset = max(0, intval($_GET['offset'] ?? 0));

$sql = 'SELECT id, type_code AS type, prompt, explanation, tags, is_active, created_at, updated_at FROM onboard_questions WHERE 1=1';
$params = [];
if ($q !== '') { $sql .= ' AND prompt LIKE ?'; $params[] = '%'.$q.'%'; }
if ($type !== '') { $sql .= ' AND type_code = ?'; $params[] = $type; }
$sql .= ' ORDER BY id DESC LIMIT ? OFFSET ?';
$params[] = $limit; $params[] = $offset;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// decode tags JSON
foreach ($rows as &$r) {
  if (isset($r['tags']) && is_string($r['tags'])) {
    $decoded = json_decode($r['tags'], true);
    if (json_last_error() === JSON_ERROR_NONE) { $r['tags'] = $decoded; }
  }
}

echo json_encode(['success' => true, 'items' => $rows]);
?>


