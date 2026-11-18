<?php
header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

require_once __DIR__ . '/../db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) {
    echo json_encode(['authenticated' => false]);
    exit;
}

// Cache table columns in session to eliminate repeated SHOW COLUMNS queries
$userCols = [];
if (!empty($_SESSION['user_table_cols']) && is_array($_SESSION['user_table_cols'])) {
    $userCols = $_SESSION['user_table_cols'];
} else {
    $cRes = $conn->query("SHOW COLUMNS FROM `user`");
    if ($cRes) {
        while ($cRow = $cRes->fetch_assoc()) {
            $userCols[] = strtolower($cRow['Field']);
        }
    }
    $_SESSION['user_table_cols'] = $userCols;
}

// Select only essential fields (omits unneeded heavy fields: qualification, personal_email, phone, address, emp_type, birthdate, etc.)
$selectFields = ['u.uid', 'u.username', 'u.firstname', 'u.lastname', 'u.profile_img', 'u.joiningdate', 'u.user_role'];
if (in_array('emp_code', $userCols, true)) {
    $selectFields[] = 'u.emp_code';
}
if (in_array('ofc_mail', $userCols, true)) {
    $selectFields[] = 'u.ofc_mail';
}

$sql = "SELECT " . implode(', ', $selectFields) . " FROM user u WHERE u.uid = ? LIMIT 1";
$user = null;
if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param("i", $user_id);
    if ($stmt->execute()) {
        $user = $stmt->get_result()->fetch_assoc();
    }
    $stmt->close();
}

if (!$user) {
    echo json_encode(['authenticated' => false]);
    exit;
}

// Clean profile image URL
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

// Lightweight online presence
$user['status'] = 'online';
$user['presence_status'] = 'online';
$user['presence_status_message'] = null;
$user['status_message'] = null;

// Admin privileges
$isAdmin = in_array((string)$user_id, ['26', '64', '205'], true) || ((int)($user['user_role'] ?? 0) === 1);
$user['is_admin'] = $isAdmin;
if ($isAdmin) {
    $user['user_role'] = 1;
}

echo json_encode(['authenticated' => true, 'user' => $user], JSON_UNESCAPED_SLASHES);
$conn->close();
