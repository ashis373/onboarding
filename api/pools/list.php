<?php
header("Content-Type: application/json; charset=UTF-8");
$NEED_MYSQLI = false;
require_once '../db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { echo json_encode(['success' => false, 'message' => 'Not logged in']); exit; }
// Role-based authorization similar to others
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
$limit = max(1, min(100, intval($_GET['limit'] ?? 50)));
$offset = max(0, intval($_GET['offset'] ?? 0));

$sql = 'SELECT id, name, description, is_active, created_at, updated_at FROM onboard_pools WHERE 1=1';
$params = [];
if ($q !== '') { $sql .= ' AND name LIKE ?'; $params[] = '%'.$q.'%'; }
$sql .= ' ORDER BY id DESC LIMIT ? OFFSET ?';
$params[] = $limit; $params[] = $offset;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
echo json_encode(['success' => true, 'items' => $rows]);
?>


