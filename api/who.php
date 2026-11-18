<?php
header("Content-Type: application/json");
$NEED_PDO = false;
require_once 'db.php';
session_start();

$uid = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
$username = $_SESSION['username'] ?? 'guest';

$isAdmin = false;
$user_role = null;

if ($uid) {
    if (in_array((string)$uid, ['26', '64', '205'], true)) {
        $isAdmin = true;
        $user_role = 1;
    } else {
        if ($stmt = $conn->prepare("SELECT user_role FROM user WHERE uid = ?")) {
            $stmt->bind_param('i', $uid);
            if ($stmt->execute()) {
                $row = $stmt->get_result()->fetch_assoc();
                $user_role = $row['user_role'] ?? 0;
                if ($user_role == 1) $isAdmin = true;
            }
            $stmt->close();
        }
    }
}

// Check row count for status 0
$total_s0 = 0;
if ($res = $conn->query("SELECT COUNT(*) as cnt FROM user WHERE status = 0")) {
    $total_s0 = intval($res->fetch_assoc()['cnt']);
}

echo json_encode([
    'session_uid' => $uid,
    'session_username' => $username,
    'db_user_role' => $user_role,
    'isAdmin' => $isAdmin,
    'count_status_0' => $total_s0,
    'db_name' => defined('DB_NAME') ? DB_NAME : 'NOT_DEFINED'
]);
