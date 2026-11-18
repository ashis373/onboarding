<?php
header("Content-Type: application/json; charset=UTF-8");
require_once '../db.php';
require_once '../helpers.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$sectionId = intval($_GET['section_id'] ?? 0);
if ($sectionId <= 0) { echo json_encode(['success' => false, 'message' => 'section_id required']); exit; }

// Check if table exists
if (!tableExists($conn, 'onboard_sequence_items')) {
    echo json_encode(['success' => true, 'items' => []]);
    exit;
}

$stmt = $pdo->prepare('SELECT id, section_id, item_type, module_id, assessment_id, position, title_override, required FROM onboard_sequence_items WHERE section_id = ? ORDER BY position');
$stmt->execute([$sectionId]);
$rows = $stmt->fetchAll();
echo json_encode(['success' => true, 'items' => $rows]);
?>


