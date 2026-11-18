<?php
header('Content-Type: application/json; charset=UTF-8');
$NEED_MYSQLI = false;
require_once '../db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { echo json_encode(['success' => false, 'message' => 'Not logged in']); exit; }
if (!in_array((string)$user_id, ['26', '64', '205'], true)) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$moduleId = intval($input['module_id'] ?? 0);
$content = $input['content'] ?? '';
if ($moduleId <= 0) { echo json_encode(['success' => false, 'message' => 'module_id required']); exit; }

$stmt = $pdo->prepare('INSERT INTO onboard_module_drafts (module_id, content, updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE content = VALUES(content), updated_by = VALUES(updated_by)');
$ok = $stmt->execute([$moduleId, $content, $user_id]);
echo json_encode(['success' => $ok]);
?>


