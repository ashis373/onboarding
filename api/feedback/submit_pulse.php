<?php
header("Content-Type: application/json; charset=UTF-8");
$NEED_PDO = false;
require_once '../db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { echo json_encode(['success' => false, 'message' => 'Not logged in']); exit; }

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$sentiment = intval($input['sentiment'] ?? 0);
$note = trim($input['note'] ?? '');

// Validate sentiment: 1-5
if ($sentiment < 1 || $sentiment > 5) {
    echo json_encode(['success' => false, 'message' => 'Invalid sentiment value']);
    exit;
}

// Check if table exists
$tblCheck = $conn->query("SHOW TABLES LIKE 'onboard_feedback_pulse'");
if (!$tblCheck || $tblCheck->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Feedback feature not available yet']);
    $conn->close();
    exit;
}

$sql = "INSERT INTO onboard_feedback_pulse (user_id, sentiment, note) VALUES (?, ?, ?)";
if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param('iis', $user_id, $sentiment, $note);
    $ok = $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => $ok]);
    $conn->close();
    exit;
}
echo json_encode(['success' => false, 'message' => 'DB error']);
?>
