<?php
$NEED_MYSQLI = false;
require_once '../db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Not logged in']); exit; }
if (!in_array((string)$user_id, ['26', '64', '205'], true)) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=onboard_results_export.csv');

$out = fopen('php://output', 'w');
fputcsv($out, ['attempt_id','assessment_id','user_id','started_at','submitted_at','score','max_score','is_passed','question_id','type_code','prompt','answer_text','selected_option_ids','matching_pairs','reorder_order','answer_is_correct','answer_score','answer_max_score','position']);

$stmt = $pdo->query('SELECT * FROM onboard_results_export ORDER BY attempt_id, position');
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
  fputcsv($out, [
    $row['attempt_id'], $row['assessment_id'], $row['user_id'], $row['started_at'], $row['submitted_at'],
    $row['score'], $row['max_score'], $row['is_passed'], $row['question_id'], $row['type_code'],
    preg_replace("/[\r\n\t]+/", ' ', $row['prompt']),
    $row['answer_text'], $row['selected_option_ids'], $row['matching_pairs'], $row['reorder_order'],
    $row['is_correct'], $row['answer_score'], $row['answer_max_score'], $row['position']
  ]);
}

fclose($out);
exit;
?>


