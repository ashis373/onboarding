<?php
header('Content-Type: application/json; charset=UTF-8');
$NEED_MYSQLI = false;
require_once '../db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { echo json_encode(['success' => false, 'message' => 'Not logged in']); exit; }

$moduleId = intval($_GET['module_id'] ?? 0);
if ($moduleId <= 0) { echo json_encode(['success' => false, 'message' => 'module_id required']); exit; }

$stmt = $pdo->prepare('SELECT module_id, content, updated_at FROM onboard_module_drafts WHERE module_id = ?');
$stmt->execute([$moduleId]);
$row = $stmt->fetch();
echo json_encode(['success' => true, 'draft' => ($row ? $row : null)]);
?>


