<?php
// Endpoint: api/admin/toggle_sequential_locking.php
// Toggles the global sequential course locking setting in onboard_settings

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

// Read input
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

// If explicit enabled/value passed, use it; otherwise toggle current value
$newVal = null;
if (isset($input['enabled'])) {
    $newVal = (bool)$input['enabled'] ? 1 : 0;
} elseif (isset($input['sequential_locking'])) {
    $newVal = (bool)$input['sequential_locking'] ? 1 : 0;
} else {
    // Read current value and toggle
    $currentVal = 0;
    $chk = $conn->query("SELECT `value` FROM onboard_settings WHERE `key` = 'sequential_locking' LIMIT 1");
    if ($chk && $r = $chk->fetch_assoc()) {
        $currentVal = ((string)$r['value'] === '1' || strtolower((string)$r['value']) === 'true') ? 1 : 0;
    }
    $newVal = ($currentVal === 1) ? 0 : 1;
}

// Upsert setting in onboard_settings
$strVal = (string)$newVal;
$stmt = $conn->prepare("
    INSERT INTO onboard_settings (`key`, `value`) 
    VALUES ('sequential_locking', ?) 
    ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)
");
if ($stmt) {
    $stmt->bind_param('s', $strVal);
    $stmt->execute();
    $stmt->close();
}

// Invalidate cached Moodle courses in session so employees immediately see lock status
foreach ($_SESSION as $k => $v) {
    if (strpos($k, 'moodle_cached_courses_') === 0 || strpos($k, 'moodle_tracks_') === 0) {
        unset($_SESSION[$k]);
    }
}

$statusLabel = ($newVal === 1) ? 'ENABLED' : 'DISABLED';
$explanation = ($newVal === 1) 
    ? 'Employees must complete courses one by one in sequence. Next course unlocks only after completing the previous course.'
    : 'Employees can complete courses in any order without prerequisites.';

echo json_encode([
    'success' => true,
    'sequential_locking' => (bool)$newVal,
    'status_label' => $statusLabel,
    'message' => "Sequential course progression is now {$statusLabel}. {$explanation}"
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

$conn->close();
?>
