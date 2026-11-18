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

try {
  $pdo->beginTransaction();

  // Fetch existing
  $stmt = $pdo->prepare('SELECT * FROM onboard_questions WHERE id = ?');
  $stmt->execute([$id]);
  $existing = $stmt->fetch();
  if (!$existing) { throw new Exception('Question not found'); }

  $type = $existing['type_code'];
  $prompt = isset($input['prompt']) ? trim((string)$input['prompt']) : $existing['prompt'];
  $explanation = array_key_exists('explanation', $input) ? trim((string)$input['explanation']) : $existing['explanation'];
  $tags = array_key_exists('tags', $input) ? $input['tags'] : $existing['tags'];
  $isActive = isset($input['is_active']) ? (int)!!$input['is_active'] : (int)$existing['is_active'];
  $tagsJson = is_string($tags) ? $tags : ($tags !== null ? json_encode($tags, JSON_UNESCAPED_UNICODE) : null);

  $stmt = $pdo->prepare('UPDATE onboard_questions SET prompt = ?, explanation = ?, tags = ?, is_active = ? WHERE id = ?');
  $stmt->execute([$prompt, $explanation, $tagsJson, $isActive, $id]);

  // If options/pairs/items provided, replace current definitions to keep consistency
  if (array_key_exists('options', $input) && in_array($type, ['single','multiple','boolean'], true)) {
    $pdo->prepare('DELETE FROM onboard_question_options WHERE question_id = ?')->execute([$id]);
    $options = $input['options'];
    if (!is_array($options) || count($options) === 0) { throw new Exception('options must be non-empty array'); }
    $insertOpt = $pdo->prepare('INSERT INTO onboard_question_options (question_id, label, is_correct, position) VALUES (?,?,?,?)');
    $position = 0; $numCorrect = 0;
    foreach ($options as $opt) {
      $label = trim((string)($opt['label'] ?? ''));
      $isCorrect = (int)(!empty($opt['is_correct']));
      if ($label === '') { throw new Exception('option label required'); }
      $insertOpt->execute([$id, $label, $isCorrect, $position++]);
      if ($isCorrect) { $numCorrect++; }
    }
    if ($type === 'single' || $type === 'boolean') {
      if ($numCorrect !== 1) { throw new Exception('single/boolean requires exactly one correct option'); }
    } else if ($type === 'multiple') {
      if ($numCorrect < 1) { throw new Exception('multiple requires at least one correct option'); }
    }
  }

  if (array_key_exists('pairs', $input) && $type === 'matching') {
    $pdo->prepare('DELETE FROM onboard_question_matching_pairs WHERE question_id = ?')->execute([$id]);
    $pairs = $input['pairs'];
    if (!is_array($pairs) || count($pairs) === 0) { throw new Exception('pairs must be non-empty array'); }
    $insertPair = $pdo->prepare('INSERT INTO onboard_question_matching_pairs (question_id, left_text, right_text, position) VALUES (?,?,?,?)');
    $position = 0;
    foreach ($pairs as $p) {
      $left = trim((string)($p['left'] ?? $p['left_text'] ?? ''));
      $right = trim((string)($p['right'] ?? $p['right_text'] ?? ''));
      if ($left === '' || $right === '') { throw new Exception('matching pair requires left and right'); }
      $insertPair->execute([$id, $left, $right, $position++]);
    }
  }

  if (array_key_exists('items', $input) && $type === 'reorder') {
    $pdo->prepare('DELETE FROM onboard_question_reorder_items WHERE question_id = ?')->execute([$id]);
    $items = $input['items'];
    if (!is_array($items) || count($items) === 0) { throw new Exception('items must be non-empty array'); }
    $insertItem = $pdo->prepare('INSERT INTO onboard_question_reorder_items (question_id, item_text, position) VALUES (?,?,?)');
    $position = 0;
    foreach ($items as $text) {
      $text = trim((string)$text);
      if ($text === '') { throw new Exception('reorder item text required'); }
      $insertItem->execute([$id, $text, $position++]);
    }
  }

  $pdo->commit();
  echo json_encode(['success' => true]);
  exit;
} catch (Throwable $e) {
  if ($pdo->inTransaction()) { $pdo->rollBack(); }
  http_response_code(400);
  echo json_encode(['success' => false, 'message' => $e->getMessage()]);
  exit;
}
?>


