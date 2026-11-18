<?php
// Endpoint: api/admin/toggle_course_visibility.php
// Toggles course visibility (is_active: 1 or 0) for employees in onboard_modules

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

$moduleId = isset($input['module_id']) ? (int)$input['module_id'] : 0;
$moodleCourseId = isset($input['moodle_course_id']) ? (int)$input['moodle_course_id'] : 0;
$isActive = isset($input['is_active']) ? ((bool)$input['is_active'] ? 1 : 0) : null;

if ($moduleId <= 0 && $moodleCourseId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Module ID or Moodle Course ID is required.']);
    exit;
}

// Check or ensure is_active column exists
$colCheck = $conn->query("SHOW COLUMNS FROM onboard_modules LIKE 'is_active'");
if ($colCheck && $colCheck->num_rows === 0) {
    $conn->query("ALTER TABLE onboard_modules ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1");
}

// If isActive wasn't explicitly supplied, toggle current status
if ($isActive === null) {
    $currentVal = 1;
    if ($moduleId > 0) {
        $chk = $conn->prepare("SELECT is_active FROM onboard_modules WHERE id = ? LIMIT 1");
        $chk->bind_param('i', $moduleId);
        $chk->execute();
        $r = $chk->get_result()->fetch_assoc();
        $chk->close();
        if ($r) $currentVal = (int)$r['is_active'];
    } elseif ($moodleCourseId > 0) {
        $chk = $conn->prepare("SELECT is_active FROM onboard_modules WHERE moodle_course_id = ? LIMIT 1");
        $chk->bind_param('i', $moodleCourseId);
        $chk->execute();
        $r = $chk->get_result()->fetch_assoc();
        $chk->close();
        if ($r) $currentVal = (int)$r['is_active'];
    }
    $isActive = ($currentVal === 1) ? 0 : 1;
}

// Execute update
if ($moduleId > 0) {
    $stmt = $conn->prepare("UPDATE onboard_modules SET is_active = ? WHERE id = ?");
    $stmt->bind_param('ii', $isActive, $moduleId);
    $stmt->execute();
    $stmt->close();
} elseif ($moodleCourseId > 0) {
    $stmt = $conn->prepare("UPDATE onboard_modules SET is_active = ? WHERE moodle_course_id = ?");
    $stmt->bind_param('ii', $isActive, $moodleCourseId);
    $stmt->execute();
    $stmt->close();
}

// Clear Moodle courses cache in session and disk so employee endpoints refresh immediately with 0 delay
foreach ($_SESSION as $k => $v) {
    if (strpos($k, 'moodle_cached_courses_') === 0 || strpos($k, 'moodle_tracks_') === 0) {
        unset($_SESSION[$k]);
    }
}

$cacheDir = __DIR__ . '/../moodle/cache';
if (is_dir($cacheDir)) {
    $cacheFiles = glob($cacheDir . '/user_courses_*.json');
    if ($cacheFiles) {
        foreach ($cacheFiles as $cf) {
            @unlink($cf);
        }
    }
}

$statusText = ($isActive === 1) ? 'visible to employees' : 'hidden from employees';

echo json_encode([
    'success' => true,
    'module_id' => $moduleId,
    'moodle_course_id' => $moodleCourseId,
    'is_active' => (bool)$isActive,
    'message' => "Course is now {$statusText}."
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

$conn->close();
?>
