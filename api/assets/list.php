<?php
header('Content-Type: application/json; charset=UTF-8');
$NEED_MYSQLI = false;
require_once '../db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { echo json_encode(['success' => false, 'message' => 'Not logged in']); exit; }
if (!in_array((string)$user_id, ['26', '64', '205'], true)) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }

$stmt = $pdo->query('SELECT id, filename, mime, size_bytes, sha256, url, created_by, created_at FROM onboard_assets ORDER BY id DESC LIMIT 200');
echo json_encode(['success' => true, 'items' => $stmt->fetchAll()]);
?>


