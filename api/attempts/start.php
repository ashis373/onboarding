<?php
header("Content-Type: application/json; charset=UTF-8");
$NEED_MYSQLI = false;
require_once '../db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { echo json_encode(['success' => false, 'message' => 'Not logged in']); exit; }

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$assessmentId = intval($input['assessment_id'] ?? 0);
if ($assessmentId <= 0) { echo json_encode(['success' => false, 'message' => 'assessment_id required']); exit; }

try {
  // Load assessment
  $stmt = $pdo->prepare('SELECT * FROM onboard_assessments WHERE id = ? AND is_active = 1');
  $stmt->execute([$assessmentId]);
  $assessment = $stmt->fetch();
  if (!$assessment) { throw new Exception('Assessment not found or inactive'); }

  // Check attempt limit (only count submitted attempts)
  if (!empty($assessment['attempt_limit'])) {
    $stmt = $pdo->prepare('SELECT COUNT(*) AS cnt FROM onboard_assessment_attempts WHERE assessment_id = ? AND user_id = ? AND submitted_at IS NOT NULL');
    $stmt->execute([$assessmentId, $user_id]);
    $cnt = intval(($stmt->fetch()['cnt'] ?? 0));
    if ($cnt >= intval($assessment['attempt_limit'])) { throw new Exception('Attempt limit reached'); }
  }

  // If there is an existing draft attempt for this assessment, resume it
  $existing = null;
  try {
    $chk = $pdo->prepare('SELECT id FROM onboard_assessment_attempts WHERE assessment_id = ? AND user_id = ? AND submitted_at IS NULL ORDER BY id DESC LIMIT 1');
    $chk->execute([$assessmentId, $user_id]);
    $existing = $chk->fetch();
  } catch (Throwable $_e) { $existing = null; }

  if ($existing) {
    $attemptId = (int)$existing['id'];

    // Load questions from placeholders for this attempt preserving order
    $qstmt = $pdo->prepare('SELECT aa.position, q.id, q.type_code AS type, q.prompt FROM onboard_assessment_answers aa JOIN onboard_questions q ON q.id = aa.question_id WHERE aa.attempt_id = ? ORDER BY aa.position');
    $qstmt->execute([$attemptId]);
    $rows = $qstmt->fetchAll();

    $optSel = $pdo->prepare('SELECT id, label FROM onboard_question_options WHERE question_id = ? ORDER BY position');
    $pairSel = $pdo->prepare('SELECT id, left_text, right_text FROM onboard_question_matching_pairs WHERE question_id = ? ORDER BY position');
    $itemSel = $pdo->prepare('SELECT id, item_text FROM onboard_question_reorder_items WHERE question_id = ? ORDER BY position');
    $ansSel = $pdo->prepare('SELECT answer_text, selected_option_ids, matching_pairs, reorder_order FROM onboard_assessment_answers WHERE attempt_id = ? AND question_id = ? LIMIT 1');

    $questions = [];
    $answersMap = [];
    foreach ($rows as $r) {
      $qid = intval($r['id']);
      $payload = ['id' => $qid, 'type' => $r['type'], 'prompt' => $r['prompt']];
      if (in_array($r['type'], ['single','multiple','boolean'], true)) { $optSel->execute([$qid]); $payload['options'] = $optSel->fetchAll(); }
      elseif ($r['type'] === 'matching') { $pairSel->execute([$qid]); $payload['pairs'] = $pairSel->fetchAll(); }
      elseif ($r['type'] === 'reorder') { $itemSel->execute([$qid]); $payload['items'] = $itemSel->fetchAll(); }
      $questions[] = $payload;

      $ansSel->execute([$attemptId, $qid]);
      $ans = $ansSel->fetch();
      if ($ans) {
        $answersMap[$qid] = [
          'answer_text' => $ans['answer_text'],
          'selected_option_ids' => $ans['selected_option_ids'] ? json_decode($ans['selected_option_ids'], true) : null,
          'matching_pairs' => $ans['matching_pairs'] ? json_decode($ans['matching_pairs'], true) : null,
          'reorder_order' => $ans['reorder_order'] ? json_decode($ans['reorder_order'], true) : null,
        ];
      }
    }

    echo json_encode(['success' => true, 'attempt_id' => $attemptId, 'assessment' => [
      'id' => $assessmentId,
      'time_limit_minutes' => $assessment['time_limit_minutes'],
      'passing_score' => $assessment['passing_score'],
    ], 'questions' => $questions, 'answers' => $answersMap, 'resumed' => true]);
    exit;
  }

  $pdo->beginTransaction();

  // Create draft attempt (not counted until submitted)
  $stmt = $pdo->prepare('INSERT INTO onboard_assessment_attempts (assessment_id, user_id, started_at, is_draft) VALUES (?,?,NOW(),1)');
  $stmt->execute([$assessmentId, $user_id]);
  $attemptId = (int)$pdo->lastInsertId();

  // Build question set from pools
  $pstmt = $pdo->prepare('SELECT ap.pool_id, ap.questions_to_draw, ap.position FROM onboard_assessment_pools ap WHERE ap.assessment_id = ? ORDER BY ap.position');
  $pstmt->execute([$assessmentId]);
  $pools = $pstmt->fetchAll();
  $allQuestions = [];
  foreach ($pools as $pool) {
    $poolId = intval($pool['pool_id']);
    $draw = intval($pool['questions_to_draw']);
    // gather candidate question ids
    $qstmt = $pdo->prepare('SELECT pq.question_id FROM onboard_pool_questions pq JOIN onboard_questions q ON q.id = pq.question_id AND q.is_active = 1 WHERE pq.pool_id = ?');
    $qstmt->execute([$poolId]);
    $qidList = array_map(function($r){ return intval($r['question_id']); }, $qstmt->fetchAll());
    if (!$qidList) { continue; }
    // random sample without weights for now
    shuffle($qidList);
    $selected = array_slice($qidList, 0, min($draw, count($qidList)));
    foreach ($selected as $qid) { $allQuestions[] = $qid; }
  }

  // Load questions data
  if ($assessment['randomize_questions']) { shuffle($allQuestions); }
  $questions = [];
  $position = 0;
  $qSel = $pdo->prepare('SELECT id, type_code AS type, prompt FROM onboard_questions WHERE id = ?');
  $optSel = $pdo->prepare('SELECT id, label FROM onboard_question_options WHERE question_id = ? ORDER BY position');
  $pairSel = $pdo->prepare('SELECT id, left_text, right_text FROM onboard_question_matching_pairs WHERE question_id = ? ORDER BY position');
  $itemSel = $pdo->prepare('SELECT id, item_text FROM onboard_question_reorder_items WHERE question_id = ? ORDER BY position');

  $ansInsert = $pdo->prepare('INSERT INTO onboard_assessment_answers (attempt_id, question_id, position, max_score) VALUES (?,?,?,1)');

  foreach ($allQuestions as $qid) {
    $qSel->execute([$qid]);
    $q = $qSel->fetch();
    if (!$q) { continue; }
    // Prepare payload
    $qPayload = ['id' => intval($q['id']), 'type' => $q['type'], 'prompt' => $q['prompt']];
    if (in_array($q['type'], ['single','multiple','boolean'], true)) {
      $optSel->execute([$q['id']]);
      $opts = $optSel->fetchAll();
      if (!empty($assessment['randomize_questions']) && is_array($opts)) { shuffle($opts); }
      $qPayload['options'] = $opts;
    } elseif ($q['type'] === 'matching') {
      $pairSel->execute([$q['id']]);
      $pairs = $pairSel->fetchAll();
      if (!empty($assessment['randomize_questions']) && is_array($pairs)) { shuffle($pairs); }
      $qPayload['pairs'] = $pairs;
    } elseif ($q['type'] === 'reorder') {
      $itemSel->execute([$q['id']]);
      $items = $itemSel->fetchAll();
      // For reorder, we present a shuffled initial order while keeping correct order in DB
      if (!empty($assessment['randomize_questions']) && is_array($items)) { shuffle($items); }
      $qPayload['items'] = $items;
    }
    $questions[] = $qPayload;
    $ansInsert->execute([$attemptId, $q['id'], $position++]);
  }

  $pdo->commit();
  echo json_encode(['success' => true, 'attempt_id' => $attemptId, 'assessment' => [
    'id' => $assessmentId,
    'time_limit_minutes' => $assessment['time_limit_minutes'],
    'passing_score' => $assessment['passing_score'],
  ], 'questions' => $questions, 'answers' => new stdClass(), 'resumed' => false]);
  exit;
} catch (Throwable $e) {
  if ($pdo->inTransaction()) { $pdo->rollBack(); }
  http_response_code(400);
  echo json_encode(['success' => false, 'message' => $e->getMessage()]);
  exit;
}
?>


