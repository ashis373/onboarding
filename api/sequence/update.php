<?php
header("Content-Type: application/json; charset=UTF-8");
require_once '../db.php';
require_once '../helpers.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}
if (!in_array((string)$user_id, ['26', '64', '205'], true)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$id = intval($input['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid ID']);
    exit;
}

// Check if table exists
if (!tableExists($conn, 'onboard_sequence_items')) {
    echo json_encode(['success' => false, 'message' => 'Sequence feature not enabled yet.']);
    exit;
}

$fields = [];
$params = [];

if (isset($input['required'])) {
    $fields[] = 'required = ?';
    $params[] = (int)!!$input['required'];
}

if (array_key_exists('title_override', $input)) {
    $title = trim((string)($input['title_override'] ?? ''));
    $fields[] = 'title_override = ?';
    $params[] = $title === '' ? null : $title;
}

if (empty($fields)) {
    echo json_encode(['success' => false, 'message' => 'No changes provided']);
    exit;
}

$params[] = $id;
$stmt = $pdo->prepare('UPDATE onboard_sequence_items SET ' . implode(', ', $fields) . ' WHERE id = ?');
$stmt->execute($params);

echo json_encode(['success' => true]);
?>


