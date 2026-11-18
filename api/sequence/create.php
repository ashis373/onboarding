<?php
header("Content-Type: application/json; charset=UTF-8");
require_once '../db.php';
require_once '../helpers.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { echo json_encode(['success' => false, 'message' => 'Not logged in']); exit; }
if (!in_array((string)$user_id, ['26', '64', '205'], true)) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$sectionId = intval($input['section_id'] ?? 0);
$itemType = trim($input['item_type'] ?? '');
$moduleId = isset($input['module_id']) ? intval($input['module_id']) : null;
$assessmentId = isset($input['assessment_id']) ? intval($input['assessment_id']) : null;
$required = isset($input['required']) ? (int)!!$input['required'] : 1;
$titleOverride = isset($input['title_override']) ? trim((string)$input['title_override']) : null;
if ($sectionId <= 0 || !in_array($itemType, ['module','assessment'], true)) { echo json_encode(['success' => false, 'message' => 'Invalid input']); exit; }

// Check if table exists
if (!tableExists($conn, 'onboard_sequence_items')) {
    echo json_encode(['success' => false, 'message' => 'Sequence feature not enabled yet.']);
    exit;
}

$stmt = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 AS next_pos FROM onboard_sequence_items WHERE section_id = ?');
$stmt->execute([$sectionId]);
$next = intval(($stmt->fetch()['next_pos'] ?? 0));

$stmt = $pdo->prepare('INSERT INTO onboard_sequence_items (section_id, item_type, module_id, assessment_id, position, title_override, required) VALUES (?,?,?,?,?,?,?)');
$stmt->execute([$sectionId, $itemType, $moduleId, $assessmentId, $next, $titleOverride, $required]);
$id = (int)$pdo->lastInsertId();
echo json_encode(['success' => true, 'id' => $id, 'position' => $next]);
?>


