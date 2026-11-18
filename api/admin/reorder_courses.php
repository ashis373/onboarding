<?php
// Endpoint: api/admin/reorder_courses.php
// Saves the custom sequence / sort_order of course modules in onboard_modules

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

$NEED_PDO = false;
require_once __DIR__ . '/../db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentUserId = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$currentUserId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

// Admin authorization check
$isAdmin = false;
if (in_array((string)$currentUserId, ['26', '64', '205'], true)) {
    $isAdmin = true;
} else {
    $roleCheck = $conn->prepare("SELECT user_role FROM user WHERE uid = ?");
    if ($roleCheck) {
        $roleCheck->bind_param('i', $currentUserId);
        $roleCheck->execute();
        $roleRes = $roleCheck->get_result()->fetch_assoc();
        if (($roleRes['user_role'] ?? 0) == 1) $isAdmin = true;
        $roleCheck->close();
    }
}

if (!$isAdmin) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Admin access required.']);
    exit;
}

// Release session lock early to prevent blocking concurrent API requests
session_write_close();

// Read JSON input
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

// Ensure sort_order column exists
$colCheck = $conn->query("SHOW COLUMNS FROM onboard_modules LIKE 'sort_order'");
if ($colCheck && $colCheck->num_rows === 0) {
    $conn->query("ALTER TABLE onboard_modules ADD COLUMN sort_order INT(11) NOT NULL DEFAULT 0 AFTER roadmap_day");
}

$orderedList = [];

// Support format 1: { "ordered_ids": [3, 5, 2, 8] }
if (!empty($input['ordered_ids']) && is_array($input['ordered_ids'])) {
    foreach ($input['ordered_ids'] as $idx => $idVal) {
        $orderedList[] = [
            'id' => (int)$idVal,
            'sort_order' => $idx + 1
        ];
    }
}
// Support format 2: { "order": [ { "id": 3, "sort_order": 1 }, ... ] }
elseif (!empty($input['order']) && is_array($input['order'])) {
    foreach ($input['order'] as $item) {
        if (isset($item['id'])) {
            $orderedList[] = [
                'id' => (int)$item['id'],
                'sort_order' => isset($item['sort_order']) ? (int)$item['sort_order'] : (count($orderedList) + 1)
            ];
        }
    }
}

if (empty($orderedList)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No order data provided.']);
    exit;
}

$stmt = $conn->prepare("UPDATE onboard_modules SET sort_order = ? WHERE id = ?");
$count = 0;
if ($stmt) {
    foreach ($orderedList as $row) {
        $orderVal = $row['sort_order'];
        $modId = $row['id'];
        $stmt->bind_param('ii', $orderVal, $modId);
        $stmt->execute();
        $count++;
    }
    $stmt->close();
}

// Clear Moodle courses cache in session so employee views immediately re-order
foreach ($_SESSION as $k => $v) {
    if (strpos($k, 'moodle_cached_courses_') === 0 || strpos($k, 'moodle_tracks_') === 0) {
        unset($_SESSION[$k]);
    }
}

echo json_encode([
    'success' => true,
    'message' => "Updated order for {$count} courses successfully.",
    'count' => $count
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

$conn->close();
?>
