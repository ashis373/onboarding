<?php
header("Content-Type: application/json; charset=UTF-8");
$NEED_PDO = false;
require_once '../db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { echo json_encode(['success' => false, 'message' => 'Not logged in']); exit; }
if (!in_array((string)$user_id, ['26', '64', '205'], true)) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$employee_id = intval($input['employee_id'] ?? 0);
$buddy_id = intval($input['buddy_id'] ?? 0);

if ($employee_id <= 0 || $buddy_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}

// Check if table exists
$tblCheck = $conn->query("SHOW TABLES LIKE 'onboard_buddies'");
if (!$tblCheck || $tblCheck->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Buddy feature not enabled yet. Please run the schema migration first.']);
    $conn->close();
    exit;
}

$sql = "INSERT INTO onboard_buddies (employee_id, buddy_id, assigned_by) 
        VALUES (?, ?, ?) 
        ON DUPLICATE KEY UPDATE buddy_id = VALUES(buddy_id), assigned_by = VALUES(assigned_by)";

if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param('iii', $employee_id, $buddy_id, $user_id);
    $ok = $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => $ok]);
    $conn->close();
    exit;
}

echo json_encode(['success' => false, 'message' => 'DB error']);
?>
