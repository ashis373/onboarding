<?php
header("Content-Type: application/json; charset=UTF-8");
$NEED_MYSQLI = false;
require_once '../db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { echo json_encode(['success' => false, 'message' => 'Not logged in']); exit; }
// Role-based authorization
$isAllowed = false;
try {
  $stmtAuth = $pdo->prepare('SELECT user_role, special_privilege FROM user WHERE uid = ? LIMIT 1');
  $stmtAuth->execute([$user_id]);
  if ($u = $stmtAuth->fetch()) {
    $role = strtolower(trim((string)($u['user_role'] ?? '')));
    $sp = intval($u['special_privilege'] ?? 0);
    if ($sp === 1 || in_array($role, ['admin','super_admin','hr','manager'], true)) { $isAllowed = true; }
  }
} catch (Throwable $_e) {}
if (!$isAllowed && !in_array((string)$user_id, ['26', '64', '205'], true)) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$type = trim($input['type'] ?? $input['type_code'] ?? '');
$prompt = trim($input['prompt'] ?? '');
$explanation = isset($input['explanation']) ? trim((string)$input['explanation']) : null;
$tags = $input['tags'] ?? null; // array or object

if ($type === '' || $prompt === '') {
  echo json_encode(['success' => false, 'message' => 'type and prompt are required']);
  exit;
}

try {
  $pdo->beginTransaction();

  // Validate type exists
  $stmt = $pdo->prepare('SELECT code FROM onboard_question_types WHERE code = ?');
  $stmt->execute([$type]);
  if (!$stmt->fetch()) {
    throw new Exception('Invalid question type');
  }

  $tagsJson = $tags !== null ? json_encode($tags, JSON_UNESCAPED_UNICODE) : null;
  $stmt = $pdo->prepare('INSERT INTO onboard_questions (type_code, prompt, explanation, tags, is_active) VALUES (?,?,?,?,1)');
  $stmt->execute([$type, $prompt, $explanation, $tagsJson]);
  $questionId = (int)$pdo->lastInsertId();

  switch ($type) {
    case 'single':
    case 'multiple':
    case 'boolean':
      $options = $input['options'] ?? [];
      if (!is_array($options) || count($options) === 0) {
        throw new Exception('options must be a non-empty array');
      }
      $position = 0;
      $insertOpt = $pdo->prepare('INSERT INTO onboard_question_options (question_id, label, is_correct, position) VALUES (?,?,?,?)');
      $numCorrect = 0;
      foreach ($options as $opt) {
        $label = trim((string)($opt['label'] ?? ''));
        $isCorrect = (int)(!empty($opt['is_correct']));
        if ($label === '') { throw new Exception('option label required'); }
        $insertOpt->execute([$questionId, $label, $isCorrect, $position++]);
        if ($isCorrect) { $numCorrect++; }
      }
      if ($type === 'single' || $type === 'boolean') {
        if ($numCorrect !== 1) { throw new Exception('single/boolean requires exactly one correct option'); }
      } else if ($type === 'multiple') {
        if ($numCorrect < 1) { throw new Exception('multiple requires at least one correct option'); }
      }
      break;

    case 'matching':
      $pairs = $input['pairs'] ?? $input['matching_pairs'] ?? [];
      if (!is_array($pairs) || count($pairs) === 0) { throw new Exception('pairs must be a non-empty array'); }
      $position = 0;
      $insertPair = $pdo->prepare('INSERT INTO onboard_question_matching_pairs (question_id, left_text, right_text, position) VALUES (?,?,?,?)');
      foreach ($pairs as $p) {
        $left = trim((string)($p['left'] ?? $p['left_text'] ?? ''));
        $right = trim((string)($p['right'] ?? $p['right_text'] ?? ''));
        if ($left === '' || $right === '') { throw new Exception('matching pair requires left and right'); }
        $insertPair->execute([$questionId, $left, $right, $position++]);
      }
      break;

    case 'reorder':
      $items = $input['items'] ?? $input['reorder_items'] ?? [];
      if (!is_array($items) || count($items) === 0) { throw new Exception('items must be a non-empty array'); }
      $position = 0;
      $insertItem = $pdo->prepare('INSERT INTO onboard_question_reorder_items (question_id, item_text, position) VALUES (?,?,?)');
      foreach ($items as $text) {
        $text = trim((string)$text);
        if ($text === '') { throw new Exception('reorder item text required'); }
        $insertItem->execute([$questionId, $text, $position++]);
      }
      break;

    case 'text':
      // free-form: nothing extra
      break;

    default:
      throw new Exception('Unhandled type');
  }

  $pdo->commit();
  echo json_encode(['success' => true, 'id' => $questionId]);
  exit;
} catch (Throwable $e) {
  if ($pdo->inTransaction()) { $pdo->rollBack(); }
  http_response_code(400);
  echo json_encode(['success' => false, 'message' => $e->getMessage()]);
  exit;
}
?>


