<?php
header("Content-Type: application/json");
$NEED_PDO = false;
require_once 'db.php';
session_start();

// Bypass for CLI/External debug if key matches
$debug_key = $_GET['debug_key'] ?? '';
$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;

if ($debug_key !== 'hexdebug123' && !$user_id) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
if ($debug_key === 'hexdebug123' && !$user_id) $user_id = 64; // Default to admin for debug

$debug = [];
$debug['session_user_id'] = $user_id;

// Check user role
$role = null;
if ($user_id) {
    if ($stmt = $conn->prepare("SELECT user_role, firstname, lastname FROM user WHERE uid = ?")) {
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $role = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}
$debug['user_info'] = $role;

// Check table casing
$tables = [];
if ($res = $conn->query("SHOW TABLES")) {
    while ($row = $res->fetch_array()) {
        $tables[] = $row[0];
    }
}
$debug['all_tables'] = $tables;

// Counts with different casing and status
$debug['count_user_status_0'] = null;
if (in_array('user', $tables)) {
    if ($res = $conn->query("SELECT COUNT(*) as cnt FROM user WHERE status = 0")) {
        $debug['count_user_status_0'] = intval($res->fetch_assoc()['cnt']);
    }
}

$debug['count_USER_status_0'] = null;
if (in_array('USER', $tables)) {
    if ($res = $conn->query("SELECT COUNT(*) as cnt FROM USER WHERE status = 0")) {
        $debug['count_USER_status_0'] = intval($res->fetch_assoc()['cnt']);
    }
}

$debug['count_user_no_where'] = null;
if (in_array('user', $tables)) {
    if ($res = $conn->query("SELECT COUNT(*) as cnt FROM user")) {
        $debug['count_user_no_where'] = intval($res->fetch_assoc()['cnt']);
    }
}

echo json_encode($debug);
