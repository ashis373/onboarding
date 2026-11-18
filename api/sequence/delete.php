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

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT section_id FROM onboard_sequence_items WHERE id = ? FOR UPDATE');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('Sequence item not found');
    }
    $sectionId = (int)$row['section_id'];

    $del = $pdo->prepare('DELETE FROM onboard_sequence_items WHERE id = ?');
    $del->execute([$id]);

    $fetch = $pdo->prepare('SELECT id FROM onboard_sequence_items WHERE section_id = ? ORDER BY position');
    $fetch->execute([$sectionId]);
    $pos = 0;
    $update = $pdo->prepare('UPDATE onboard_sequence_items SET position = ? WHERE id = ?');
    while ($item = $fetch->fetch()) {
        $update->execute([$pos++, (int)$item['id']]);
    }

    $pdo->commit();
    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>


