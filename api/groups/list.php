<?php
header("Content-Type: application/json; charset=UTF-8");
require_once '../db.php';
require_once '../helpers.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { echo json_encode(['success' => false, 'message' => 'Not logged in']); exit; }

// Check if table exists
if (!tableExists($conn, 'onboard_groups')) {
    echo json_encode(['success' => true, 'items' => []]);
    exit;
}

$stmt = $pdo->query('SELECT id, name, description, tags, is_active, created_at, updated_at FROM onboard_groups ORDER BY id DESC');
$rows = $stmt->fetchAll();
foreach ($rows as &$r) { if (is_string($r['tags'])) { $d = json_decode($r['tags'], true); if (json_last_error()===JSON_ERROR_NONE) $r['tags']=$d; } }
echo json_encode(['success' => true, 'items' => $rows]);
?>


