<?php
header("Content-Type: application/json; charset=UTF-8");
$NEED_PDO = false;
require_once '../db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { echo json_encode(['success' => false, 'message' => 'Not logged in']); exit; }

// Check if table exists
$tblCheck = $conn->query("SHOW TABLES LIKE 'onboard_feedback_pulse'");
if (!$tblCheck || $tblCheck->num_rows === 0) {
    echo json_encode(['success' => true, 'today_sentiment' => null]);
    $conn->close();
    exit;
}

// Check if user already submitted today
$today = date('Y-m-d');
$sql = "SELECT id, sentiment FROM onboard_feedback_pulse WHERE user_id = ? AND DATE(submitted_at) = ? LIMIT 1";
$already = null;
if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param('is', $user_id, $today);
    if ($stmt->execute()) {
        $row = $stmt->get_result()->fetch_assoc();
        if ($row) $already = (int)$row['sentiment'];
    }
    $stmt->close();
}

echo json_encode(['success' => true, 'today_sentiment' => $already]);
$conn->close();
?>
