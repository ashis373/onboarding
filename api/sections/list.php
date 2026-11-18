<?php
header("Content-Type: application/json; charset=UTF-8");
require_once '../db.php';
require_once '../helpers.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$groupId = intval($_GET['group_id'] ?? 0);
if ($groupId <= 0) { echo json_encode(['success' => false, 'message' => 'group_id required']); exit; }

// Check if table exists
if (!tableExists($conn, 'onboard_sections')) {
    echo json_encode(['success' => true, 'items' => []]);
    exit;
}

$stmt = $pdo->prepare('SELECT id, group_id, name, description, position, is_active FROM onboard_sections WHERE group_id = ? ORDER BY position');
$stmt->execute([$groupId]);
$rows = $stmt->fetchAll();
echo json_encode(['success' => true, 'items' => $rows]);
?>


