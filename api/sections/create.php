<?php
header("Content-Type: application/json; charset=UTF-8");
require_once '../db.php';
require_once '../helpers.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { echo json_encode(['success' => false, 'message' => 'Not logged in']); exit; }
if (!in_array((string)$user_id, ['26', '64', '205'], true)) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$groupId = intval($input['group_id'] ?? 0);
$name = trim($input['name'] ?? '');
if ($groupId <= 0 || strlen($name) < 2) { echo json_encode(['success' => false, 'message' => 'Invalid input']); exit; }

// Check if table exists
if (!tableExists($conn, 'onboard_sections')) {
    echo json_encode(['success' => false, 'message' => 'Sections feature not enabled yet.']);
    exit;
}

// next position
$stmt = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 AS next_pos FROM onboard_sections WHERE group_id = ?');
$stmt->execute([$groupId]);
$next = intval(($stmt->fetch()['next_pos'] ?? 0));

$stmt = $pdo->prepare('INSERT INTO onboard_sections (group_id, name, position, is_active) VALUES (?,?,?,1)');
$stmt->execute([$groupId, $name, $next]);
$id = (int)$pdo->lastInsertId();
echo json_encode(['success' => true, 'id' => $id, 'position' => $next]);
?>


