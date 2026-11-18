<?php
header("Content-Type: application/json; charset=UTF-8");
$NEED_MYSQLI = false;
require_once '../db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { echo json_encode(['success' => false, 'message' => 'Not logged in']); exit; }
if (!in_array((string)$user_id, ['26', '64', '205'], true)) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }

// Drop-offs: users who started but didn't submit/pass
$stmt = $pdo->query('SELECT assessment_id, COUNT(*) AS started, SUM(CASE WHEN submitted_at IS NULL THEN 1 ELSE 0 END) AS not_submitted, SUM(CASE WHEN is_passed = 0 AND submitted_at IS NOT NULL THEN 1 ELSE 0 END) AS failed FROM onboard_assessment_attempts GROUP BY assessment_id ORDER BY assessment_id DESC');
$rows = $stmt->fetchAll();
echo json_encode(['success' => true, 'items' => $rows]);
?>


