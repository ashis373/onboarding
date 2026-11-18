<?php
header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
$NEED_PDO = false;
require_once '../db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}
// Dynamically discover columns in user table to avoid fatal errors if columns differ
$userCols = [];
$cRes = $conn->query("SHOW COLUMNS FROM `user`");
if ($cRes) {
    while ($cRow = $cRes->fetch_assoc()) {
        $userCols[] = $cRow['Field'];
    }
}
$selectFields = ['u.uid', 'u.username', 'u.firstname', 'u.lastname', 'u.profile_img'];
$optionalFields = [
    'emp_code', 'gender', 'birthdate', 'ofc_mail', 'ofc_location',
    'joiningdate', 'qualification', 'personal_email', 'phone',
    'address', 'emp_type', 'status', 'session_key', 'user_role', 'special_privilege',
    'account_manager', 'last_active_time', 'reporting_to'
];
foreach ($optionalFields as $f) {
    if (in_array($f, $userCols, true)) {
        $selectFields[] = "u.`$f`";
    }
}

$user = null;
$sql = "SELECT " . implode(', ', $selectFields) . " FROM user u WHERE u.uid = ?";
if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param("i", $user_id);
    if ($stmt->execute()) {
        $user = $stmt->get_result()->fetch_assoc();
    }
    $stmt->close();
}

if (!$user) {
    echo json_encode(['success' => false, 'message' => 'User not found']);
    exit;
}

// Build profile image URL with rawurlencode to safely support spaces and special characters
$img = isset($user['profile_img']) ? trim((string)$user['profile_img']) : '';
if ($img !== '') {
    $encoded = implode('/', array_map('rawurlencode', explode('/', $img)));
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if (stripos($host, 'hexalearn.com') !== false) {
        $user['profile_img_url'] = ((isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/internal/corhis') !== false) ? '/internal/corhis/dist/img/user/' : '/dist/img/user/') . $encoded;
    } else {
        $user['profile_img_url'] = ((isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/internal/corhis') !== false) ? '/internal/corhis/dist/img/user/' : '/dist/img/user/') . $encoded;
    }
} else {
    $user['profile_img_url'] = '';
}

// Presence status lookup
$user['presence_status'] = 'offline';
$user['presence_status_message'] = null;
$pCheck = $conn->query("SHOW TABLES LIKE 'user_presence'");
if ($pCheck && $pCheck->num_rows > 0) {
    if ($stmtP = $conn->prepare("SELECT status, status_message FROM user_presence WHERE user_id = ? LIMIT 1")) {
        $stmtP->bind_param("i", $user_id);
        if ($stmtP->execute()) {
            $resP = $stmtP->get_result()->fetch_assoc();
            if ($resP) {
                $user['presence_status'] = $resP['status'] ?? 'offline';
                $user['presence_status_message'] = $resP['status_message'] ?? null;
            }
        }
        $stmtP->close();
    }
}
$user['status'] = $user['presence_status'];
$user['status_message'] = $user['presence_status_message'];

if (in_array((string)$user_id, ['26', '64', '205'], true)) {
    $user['is_admin'] = true;
    $user['user_role'] = 1;
}

echo json_encode(['success' => true, 'user' => $user]);
$conn->close();
?>