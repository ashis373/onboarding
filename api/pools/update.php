<?php
header("Content-Type: application/json; charset=UTF-8");
$NEED_MYSQLI = false;
require_once '../db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { echo json_encode(['success' => false, 'message' => 'Not logged in']); exit; }
if (!in_array((string)$user_id, ['26', '64', '205'], true)) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }

$id = intval($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'id required']); exit; }

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$name = isset($input['name']) ? trim((string)$input['name']) : null;
$description = array_key_exists('description', $input) ? trim((string)$input['description']) : null;
$isActive = isset($input['is_active']) ? (int)!!$input['is_active'] : null;

$fields = []; $params = [];
if ($name !== null) { $fields[] = 'name = ?'; $params[] = $name; }
if ($description !== null) { $fields[] = 'description = ?'; $params[] = $description; }
if ($isActive !== null) { $fields[] = 'is_active = ?'; $params[] = $isActive; }
if (!$fields) { echo json_encode(['success' => false, 'message' => 'No updates provided']); exit; }

$params[] = $id;
$sql = 'UPDATE onboard_pools SET '.implode(', ', $fields).' WHERE id = ?';
$stmt = $pdo->prepare($sql);
$ok = $stmt->execute($params);
echo json_encode(['success' => $ok]);
?>


