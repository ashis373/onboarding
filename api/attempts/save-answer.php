<?php
header("Content-Type: application/json; charset=UTF-8");
$NEED_MYSQLI = false;
require_once '../db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { echo json_encode(['success' => false, 'message' => 'Not logged in']); exit; }

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$attemptId = intval($input['attempt_id'] ?? 0);
$questionId = intval($input['question_id'] ?? 0);
$payload = $input['answer'] ?? null;
if ($attemptId <= 0 || $questionId <= 0) { echo json_encode(['success' => false, 'message' => 'attempt_id and question_id required']); exit; }

try {
  // verify ownership and draft
  $stmt = $pdo->prepare('SELECT a.* FROM onboard_assessment_attempts a WHERE a.id = ? AND a.user_id = ? AND a.submitted_at IS NULL');
  $stmt->execute([$attemptId, $user_id]);
  $attempt = $stmt->fetch();
  if (!$attempt) { throw new Exception('Attempt not found or already submitted'); }

  $answerText = null; $selectedJson = null; $matchingJson = null; $reorderJson = null;
  if (is_array($payload)) {
    if (isset($payload['selected_option_ids'])) { $selectedJson = json_encode(array_map('intval', (array)$payload['selected_option_ids']), JSON_UNESCAPED_UNICODE); }
    if (isset($payload['matching_pairs'])) { $matchingJson = json_encode($payload['matching_pairs'], JSON_UNESCAPED_UNICODE); }
    if (isset($payload['reorder_order'])) { $reorderJson = json_encode(array_map('intval', (array)$payload['reorder_order']), JSON_UNESCAPED_UNICODE); }
    if (isset($payload['answer_text'])) { $answerText = trim((string)$payload['answer_text']); }
  } else if (is_string($payload)) {
    $answerText = trim($payload);
  }

  $upd = $pdo->prepare('UPDATE onboard_assessment_answers SET answer_text = ?, selected_option_ids = ?, matching_pairs = ?, reorder_order = ? WHERE attempt_id = ? AND question_id = ?');
  $upd->execute([$answerText, $selectedJson, $matchingJson, $reorderJson, $attemptId, $questionId]);

  echo json_encode(['success' => true]);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>


