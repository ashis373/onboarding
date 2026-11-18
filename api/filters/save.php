<?php
header('Content-Type: application/json; charset=UTF-8');
$NEED_MYSQLI = false;
require_once '../db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { echo json_encode(['success' => false, 'message' => 'Not logged in']); exit; }

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$name = trim($input['name'] ?? '');
$kind = trim($input['kind'] ?? 'analytics');
$payload = $input['payload'] ?? null;
if ($name === '' || !$payload) { echo json_encode(['success' => false, 'message' => 'Invalid input']); exit; }

$stmt = $pdo->prepare('INSERT INTO onboard_saved_filters (name, owner_id, kind, payload) VALUES (?,?,?,?)');
$ok = $stmt->execute([$name, $user_id, $kind, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
echo json_encode(['success' => $ok, 'id' => (int)$pdo->lastInsertId()]);
?>


