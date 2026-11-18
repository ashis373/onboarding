<?php
header("Content-Type: application/json; charset=UTF-8");
require_once '../db.php';
require_once '../helpers.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { echo json_encode(['success' => false, 'message' => 'Not logged in']); exit; }
if (!in_array((string)$user_id, ['26', '64', '205'], true)) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$name = trim($input['name'] ?? '');
$description = isset($input['description']) ? trim((string)$input['description']) : null;
$tags = $input['tags'] ?? null;
if ($name === '' || strlen($name) < 3) { echo json_encode(['success' => false, 'message' => 'Name too short']); exit; }

// Check if table exists
if (!tableExists($conn, 'onboard_groups')) {
    echo json_encode(['success' => false, 'message' => 'Groups feature not enabled yet.']);
    exit;
}

$stmt = $pdo->prepare('INSERT INTO onboard_groups (name, description, tags, is_active) VALUES (?,?,?,1)');
$stmt->execute([$name, $description, $tags !== null ? json_encode($tags, JSON_UNESCAPED_UNICODE) : null]);
$id = (int)$pdo->lastInsertId();
echo json_encode(['success' => true, 'id' => $id]);
?>


