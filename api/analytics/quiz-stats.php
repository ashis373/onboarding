<?php
header("Content-Type: application/json; charset=UTF-8");
$NEED_MYSQLI = false;
require_once '../db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { echo json_encode(['success' => false, 'message' => 'Not logged in']); exit; }
if (!in_array((string)$user_id, ['26', '64', '205'], true)) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }

$assessmentId = isset($_GET['assessment_id']) ? intval($_GET['assessment_id']) : null;

$sql = 'SELECT a.id, a.title,
  COUNT(atm.id) AS attempts,
  SUM(CASE WHEN atm.is_passed = 1 THEN 1 ELSE 0 END) AS passes,
  AVG(NULLIF(atm.score, 0)) AS avg_raw_score,
  AVG(CASE WHEN atm.max_score > 0 THEN (atm.score/atm.max_score)*100 ELSE NULL END) AS avg_pct
FROM onboard_assessments a
LEFT JOIN onboard_assessment_attempts atm ON atm.assessment_id = a.id
';
$params = [];
if ($assessmentId) { $sql .= 'WHERE a.id = ? '; $params[] = $assessmentId; }
$sql .= 'GROUP BY a.id, a.title ORDER BY a.id DESC LIMIT 200';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
foreach ($rows as &$r) {
  $attempts = intval($r['attempts']);
  $passes = intval($r['passes']);
  $r['pass_rate'] = $attempts > 0 ? round(($passes/$attempts)*100, 1) : 0;
  $r['avg_pct'] = $r['avg_pct'] !== null ? round(floatval($r['avg_pct']), 1) : null;
}

echo json_encode(['success' => true, 'items' => $rows]);
?>


