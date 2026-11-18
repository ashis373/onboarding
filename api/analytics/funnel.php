<?php
header("Content-Type: application/json; charset=UTF-8");
require_once '../db.php';
require_once '../helpers.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { echo json_encode(['success' => false, 'message' => 'Not logged in']); exit; }
if (!in_array((string)$user_id, ['26', '64', '205'], true)) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }

// Funnel across groups -> sections -> items completion
// Needs module progress + attempts passed

$groupId = isset($_GET['group_id']) ? intval($_GET['group_id']) : null;

$data = [
  'groups' => 0,
  'sections' => 0,
  'items' => 0,
  'module_completions' => 0,
  'assessment_passes' => 0,
];

// counts
if (tableExists($conn, 'onboard_groups')) {
    $data['groups'] = intval($pdo->query('SELECT COUNT(*) FROM onboard_groups')->fetchColumn());
}
if (tableExists($conn, 'onboard_sections')) {
    $data['sections'] = intval($pdo->query('SELECT COUNT(*) FROM onboard_sections'.($groupId?' WHERE group_id='.(int)$groupId:''))->fetchColumn());
}
if (tableExists($conn, 'onboard_sequence_items')) {
    $data['items'] = intval($pdo->query('SELECT COUNT(*) FROM onboard_sequence_items'.($groupId?' WHERE section_id IN (SELECT id FROM onboard_sections WHERE group_id='.(int)$groupId.')':''))->fetchColumn());
}

// module completions from onboard_module_progress
if (tableExists($conn, 'onboard_module_progress')) {
    $mc = intval($pdo->query('SELECT COUNT(*) FROM onboard_module_progress WHERE is_completed=1')->fetchColumn());
    $data['module_completions'] = $mc;
}

if (tableExists($conn, 'onboard_assessment_attempts')) {
    $data['assessment_passes'] = intval($pdo->query('SELECT COUNT(*) FROM onboard_assessment_attempts WHERE is_passed = 1')->fetchColumn());
}

echo json_encode(['success' => true, 'funnel' => $data]);
?>


