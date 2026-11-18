<?php
header("Content-Type: application/json; charset=UTF-8");
$NEED_MYSQLI = false;
require_once '../db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { echo json_encode(['success' => false, 'message' => 'Not logged in']); exit; }
if (!in_array((string)$user_id, ['26', '64', '205'], true)) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$groupId = intval($input['group_id'] ?? 0);
$publishAt = isset($input['publish_at']) ? trim((string)$input['publish_at']) : null; // 'YYYY-MM-DD HH:MM:SS'
$unpublishAt = isset($input['unpublish_at']) ? trim((string)$input['unpublish_at']) : null;
$isPublished = isset($input['is_published']) ? (int)!!$input['is_published'] : 0;
if ($groupId <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid group_id']); exit; }

$pdo->prepare('INSERT INTO onboard_group_schedule (group_id, publish_at, unpublish_at, is_published) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE publish_at = VALUES(publish_at), unpublish_at = VALUES(unpublish_at), is_published = VALUES(is_published)')
    ->execute([$groupId, $publishAt, $unpublishAt, $isPublished]);
echo json_encode(['success' => true]);
?>


