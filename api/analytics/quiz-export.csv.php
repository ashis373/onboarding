<?php
$NEED_MYSQLI = false;
require_once '../db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id || !in_array((string)$user_id, ['26', '64', '205'], true)) { header('HTTP/1.1 403'); exit; }

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=quiz_stats.csv');

$out = fopen('php://output', 'w');
fputcsv($out, ['assessment_id','title','attempts','passes','pass_rate','avg_pct']);

$sql = 'SELECT a.id, a.title,
  COUNT(atm.id) AS attempts,
  SUM(CASE WHEN atm.is_passed = 1 THEN 1 ELSE 0 END) AS passes,
  AVG(CASE WHEN atm.max_score > 0 THEN (atm.score/atm.max_score)*100 ELSE NULL END) AS avg_pct
FROM onboard_assessments a
LEFT JOIN onboard_assessment_attempts atm ON atm.assessment_id = a.id
GROUP BY a.id, a.title ORDER BY a.id DESC';

foreach ($pdo->query($sql) as $row) {
  $attempts = intval($row['attempts']);
  $passes = intval($row['passes']);
  $passRate = $attempts > 0 ? round(($passes/$attempts)*100, 1) : 0;
  $avgPct = $row['avg_pct'] !== null ? round(floatval($row['avg_pct']), 1) : '';
  fputcsv($out, [$row['id'], $row['title'], $attempts, $passes, $passRate, $avgPct]);
}
fclose($out);
exit;
?>


