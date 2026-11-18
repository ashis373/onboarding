<?php
header("Content-Type: application/json; charset=UTF-8");
$NEED_PDO = false;
require_once '../db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { echo json_encode(['success' => false, 'message' => 'Not logged in']); exit; }

$desigs = [];
$sql = "SELECT id, designation as name FROM designation ORDER BY designation ASC";
if ($res = $conn->query($sql)) {
    while ($row = $res->fetch_assoc()) { $desigs[] = $row; }
}

echo json_encode(['success' => true, 'designations' => $desigs]);
$conn->close();
?>
