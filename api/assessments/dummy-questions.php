<?php
header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
$NEED_MYSQLI = false;
require_once '../db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { echo json_encode(['success' => false, 'message' => 'Not logged in']); exit; }

$assessment_id = isset($_GET['assessment_id']) ? intval($_GET['assessment_id']) : 0;
if ($assessment_id <= 0) { echo json_encode(['success' => false, 'message' => 'assessment_id required']); exit; }

// Try to fetch assessment basic info
$title = 'Assessment'; $duration = 15; $passing = 75;
try {
  $stmt = $pdo->prepare('SELECT title, time_limit_minutes, passing_score FROM onboard_assessments WHERE id = ?');
  $stmt->execute([$assessment_id]);
  if ($row = $stmt->fetch()) {
    $title = $row['title'] ?: $title;
    if ($row['time_limit_minutes'] !== null) { $duration = intval($row['time_limit_minutes']); }
    if ($row['passing_score'] !== null) { $passing = intval($row['passing_score']); }
  }
} catch (Throwable $e) {}

// Build deterministic dummy questions based on assessment_id
mt_srand($assessment_id);

$topics = [
  'Security', 'Company Policy', 'Best Practices', 'Compliance', 'Communication', 'Tools', 'Process'
];

function pick($arr) { return $arr[array_rand($arr)]; }

$questions = [];
$count = 8;
for ($i = 1; $i <= $count; $i++) {
  $kind = $i % 4; // rotate types
  $topic = pick($topics);
  if ($kind === 1) {
    // single choice
    $opts = ["True", "False", "Not Applicable", "Depends"];
    $correctIdx = mt_rand(0, 3);
    $questions[] = [
      'id' => $i,
      'type' => 'single',
      'prompt' => "$topic: Select the most accurate statement ($i)",
      'options' => $opts,
      'correctIndex' => $correctIdx,
    ];
  } elseif ($kind === 2) {
    // multiple choice
    $opts = ["Email", "Teams", "Slack", "Personal WhatsApp", "Ticket System"];
    $correct = [1, 2, 4];
    $questions[] = [
      'id' => $i,
      'type' => 'multiple',
      'prompt' => "$topic: Choose all approved channels ($i)",
      'options' => $opts,
      'correctIndices' => $correct,
    ];
  } elseif ($kind === 3) {
    // boolean
    $bool = (mt_rand(0, 1) === 1);
    $questions[] = [
      'id' => $i,
      'type' => 'boolean',
      'prompt' => "$topic: Is the following acceptable? ($i)",
      'correct' => $bool,
    ];
  } else {
    // text answer (keyword based)
    $kw = strtolower(pick(['report', 'policy', 'secure', 'vpn', '2fa', 'encrypt']));
    $questions[] = [
      'id' => $i,
      'type' => 'text',
      'prompt' => "$topic: Briefly describe the correct approach ($i)",
      'keywords' => [$kw],
    ];
  }
}

echo json_encode([
  'success' => true,
  'assessment' => [
    'id' => $assessment_id,
    'title' => $title,
    'duration' => $duration,
    'passingScore' => $passing,
  ],
  'questions' => $questions,
]);
exit;
?>


