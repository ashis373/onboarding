<?php
header("Content-Type: application/json; charset=UTF-8");
$NEED_MYSQLI = false;
require_once '../db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { echo json_encode(['success' => false, 'message' => 'Not logged in']); exit; }

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$attemptId = intval($input['attempt_id'] ?? 0);
$answers = $input['answers'] ?? [];
if ($attemptId <= 0 || !is_array($answers)) { echo json_encode(['success' => false, 'message' => 'attempt_id and answers required']); exit; }

try {
  // Ensure attempt belongs to user and not already submitted
  $stmt = $pdo->prepare('SELECT a.*, s.passing_score, s.time_limit_minutes FROM onboard_assessment_attempts a JOIN onboard_assessments s ON s.id = a.assessment_id WHERE a.id = ? AND a.user_id = ?');
  $stmt->execute([$attemptId, $user_id]);
  $attempt = $stmt->fetch();
  if (!$attempt) { throw new Exception('Attempt not found'); }
  if (!empty($attempt['submitted_at'])) { throw new Exception('Attempt already submitted'); }

  $pdo->beginTransaction();

  $score = 0; $maxScore = 0;

  // Index answers by question_id for quick lookup
  $byQ = [];
  foreach ($answers as $ans) {
    $qid = intval($ans['question_id'] ?? 0);
    if ($qid > 0) { $byQ[$qid] = $ans; }
  }

  // Fetch questions from recorded answer placeholders to preserve order
  $qstmt = $pdo->prepare('SELECT aa.id AS aa_id, aa.question_id, q.type_code AS type FROM onboard_assessment_answers aa JOIN onboard_questions q ON q.id = aa.question_id WHERE aa.attempt_id = ? ORDER BY aa.position');
  $qstmt->execute([$attemptId]);
  $rows = $qstmt->fetchAll();

  $optSel = $pdo->prepare('SELECT id, is_correct FROM onboard_question_options WHERE question_id = ?');
  $pairSel = $pdo->prepare('SELECT id, left_text, right_text FROM onboard_question_matching_pairs WHERE question_id = ?');
  $itemSel = $pdo->prepare('SELECT id FROM onboard_question_reorder_items WHERE question_id = ? ORDER BY position');

  $ansUpd = $pdo->prepare('UPDATE onboard_assessment_answers SET answer_text = ?, selected_option_ids = ?, matching_pairs = ?, reorder_order = ?, is_correct = ?, score = ?, max_score = ? WHERE id = ?');

  foreach ($rows as $r) {
    $aaId = intval($r['aa_id']);
    $qid = intval($r['question_id']);
    $type = $r['type'];
    $given = $byQ[$qid] ?? null;
    $isCorrect = 0; $qScore = 0; $qMax = 1;
    $answerText = null; $selectedJson = null; $matchingJson = null; $reorderJson = null;

    if ($type === 'single' || $type === 'boolean') {
      $optSel->execute([$qid]);
      $opts = $optSel->fetchAll();
      $correct = array_values(array_filter($opts, function($o){ return intval($o['is_correct']) === 1; }));
      $correctIds = array_map(function($o){ return intval($o['id']); }, $correct);
      $chosen = isset($given['selected_option_ids']) ? (array)$given['selected_option_ids'] : [];
      $chosen = array_map('intval', $chosen);
      $selectedJson = json_encode($chosen, JSON_UNESCAPED_UNICODE);
      $isCorrect = count($chosen) === 1 && $correctIds && $chosen[0] === $correctIds[0] ? 1 : 0;
      $qScore = $isCorrect ? 1 : 0;
    } elseif ($type === 'multiple') {
      $optSel->execute([$qid]);
      $opts = $optSel->fetchAll();
      $correctIds = array_map(function($o){ return intval($o['id']); }, array_values(array_filter($opts, function($o){ return intval($o['is_correct']) === 1; })));
      sort($correctIds);
      $chosen = isset($given['selected_option_ids']) ? (array)$given['selected_option_ids'] : [];
      $chosen = array_map('intval', $chosen);
      sort($chosen);
      $selectedJson = json_encode($chosen, JSON_UNESCAPED_UNICODE);
      $isCorrect = ($chosen === $correctIds) ? 1 : 0;
      $qScore = $isCorrect ? 1 : 0; // simple scoring; can be partial later
    } elseif ($type === 'text') {
      $answerText = isset($given['answer_text']) ? trim((string)$given['answer_text']) : null;
      $isCorrect = null; // manual review or zero-weight by default
      $qScore = 0;
    } elseif ($type === 'matching') {
      $pairSel->execute([$qid]);
      $pairs = $pairSel->fetchAll();
      $correctPairs = [];
      foreach ($pairs as $p) { $correctPairs[intval($p['id'])] = $p['right_text']; }
      $userPairs = isset($given['matching_pairs']) ? (array)$given['matching_pairs'] : [];
      // expected [{left_id,right_id}] or [{left_id,right_text}]
      $correctCount = 0; $total = count($pairs);
      foreach ($userPairs as $up) {
        $leftId = intval($up['left_id'] ?? 0);
        $rightText = isset($up['right_text']) ? (string)$up['right_text'] : null;
        if ($leftId && isset($correctPairs[$leftId])) {
          if ($rightText !== null) {
            if ($correctPairs[$leftId] === $rightText) { $correctCount++; }
          }
        }
      }
      $matchingJson = json_encode($userPairs, JSON_UNESCAPED_UNICODE);
      $isCorrect = ($total > 0 && $correctCount === $total) ? 1 : 0;
      $qScore = $isCorrect ? 1 : 0;
    } elseif ($type === 'reorder') {
      $itemSel->execute([$qid]);
      $correctOrder = array_map(function($r){ return intval($r['id']); }, $itemSel->fetchAll());
      $userOrder = isset($given['reorder_order']) ? (array)$given['reorder_order'] : [];
      $userOrder = array_map('intval', $userOrder);
      $reorderJson = json_encode($userOrder, JSON_UNESCAPED_UNICODE);
      $isCorrect = ($userOrder === $correctOrder) ? 1 : 0;
      $qScore = $isCorrect ? 1 : 0;
    }

    $score += $qScore; $maxScore += $qMax;
    $ansUpd->execute([$answerText, $selectedJson, $matchingJson, $reorderJson, $isCorrect, $qScore, $qMax, $aaId]);
  }

  // finalize attempt (mark as submitted and no longer draft)
  $isPassed = null;
  if ($attempt['passing_score'] !== null) {
    $pct = ($maxScore > 0) ? intval(round(($score / $maxScore) * 100)) : 0;
    $isPassed = $pct >= intval($attempt['passing_score']) ? 1 : 0;
  }
  $elapsedSec = time() - strtotime($attempt['started_at']);
  $upd = $pdo->prepare('UPDATE onboard_assessment_attempts SET submitted_at = NOW(), score = ?, max_score = ?, is_passed = ?, elapsed_seconds = ?, is_draft = 0 WHERE id = ?');
  $upd->execute([$score, $maxScore, $isPassed, $elapsedSec, $attemptId]);

  $pdo->commit();
  echo json_encode(['success' => true, 'score' => $score, 'max_score' => $maxScore, 'is_passed' => $isPassed]);
  exit;
} catch (Throwable $e) {
  if ($pdo->inTransaction()) { $pdo->rollBack(); }
  http_response_code(400);
  echo json_encode(['success' => false, 'message' => $e->getMessage()]);
  exit;
}
?>


