<?php
header('Content-Type: application/json; charset=UTF-8');
$NEED_MYSQLI = false;
require_once '../db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { echo json_encode(['success' => false, 'message' => 'Not logged in']); exit; }

$kind = isset($_GET['kind']) ? trim($_GET['kind']) : null;
$sql = 'SELECT id, name, owner_id, kind, payload, created_at FROM onboard_saved_filters WHERE owner_id = ?';
$params = [$user_id];
if ($kind) { $sql .= ' AND kind = ?'; $params[] = $kind; }
$sql .= ' ORDER BY id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
foreach ($rows as &$r) { if (is_string($r['payload'])) { $d = json_decode($r['payload'], true); if (json_last_error()===JSON_ERROR_NONE) $r['payload']=$d; } }
echo json_encode(['success' => true, 'items' => $rows]);
?>


