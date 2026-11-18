<?php
header("Content-Type: application/json; charset=UTF-8");
$NEED_PDO = false;
require_once '../db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { echo json_encode(['success' => false, 'message' => 'Not logged in']); exit; }
if (!in_array((string)$user_id, ['26', '64', '205'], true)) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }

$q = trim($_GET['q'] ?? '');
$sql = "SELECT u.uid, u.firstname, u.lastname, u.ofc_mail, u.profile_img 
        FROM user u
        WHERE u.status = 0";
if ($q !== '') {
    $s = $conn->real_escape_string($q);
    $sql .= " AND (u.firstname LIKE '%$s%' OR u.lastname LIKE '%$s%' OR u.ofc_mail LIKE '%$s%')";
}
$sql .= " ORDER BY u.firstname ASC LIMIT 20";

$buddies = [];
if ($res = $conn->query($sql)) {
    while ($row = $res->fetch_assoc()) {
        $buddies[] = [
            'id' => $row['uid'],
            'name' => trim($row['firstname'] . ' ' . $row['lastname']),
            'email' => $row['ofc_mail'],
            'photo' => $row['profile_img'] ? (((isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/internal/corhis') !== false) ? '/internal/corhis/dist/img/user/' : '/dist/img/user/') . implode('/', array_map('rawurlencode', explode('/', trim($row['profile_img']))))) : ''
        ];
    }
}

echo json_encode(['success' => true, 'potential_buddies' => $buddies]);
$conn->close();
?>
