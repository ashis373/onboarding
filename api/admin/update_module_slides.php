<?php
// Endpoint: api/admin/update_module_slides.php
// Updates the total slide count for a SCORM course module in onboard_modules

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

// Read JSON input
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$moduleId = isset($input['module_id']) ? (int)$input['module_id'] : 0;
$moodleCourseId = isset($input['moodle_course_id']) ? (int)$input['moodle_course_id'] : 0;
$totalSlides = isset($input['total_slides']) ? (int)$input['total_slides'] : 0;

if ($totalSlides <= 0 || $totalSlides > 500) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid slide count. Please enter a positive number between 1 and 500.'
    ]);
    exit;
}

if ($moduleId <= 0 && $moodleCourseId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Module ID or Moodle Course ID is required.'
    ]);
    exit;
}

// Check if total_slides column exists in onboard_modules; if not, create it on-the-fly safely
$colCheck = $conn->query("SHOW COLUMNS FROM onboard_modules LIKE 'total_slides'");
if ($colCheck && $colCheck->num_rows === 0) {
    $conn->query("ALTER TABLE onboard_modules ADD COLUMN total_slides INT(11) NOT NULL DEFAULT 10 AFTER duration_minutes");
}

$targetModuleId = $moduleId;
if ($targetModuleId <= 0 && $moodleCourseId > 0) {
    $mFind = $conn->prepare("SELECT id FROM onboard_modules WHERE moodle_course_id = ? LIMIT 1");
    if ($mFind) {
        $mFind->bind_param('i', $moodleCourseId);
        $mFind->execute();
        $mRow = $mFind->get_result()->fetch_assoc();
        $mFind->close();
        if ($mRow) $targetModuleId = (int)$mRow['id'];
    }
}

// Update onboard_modules
$updated = false;
if ($targetModuleId > 0) {
    $stmt = $conn->prepare("UPDATE onboard_modules SET total_slides = ?, updated_at = NOW() WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param('ii', $totalSlides, $targetModuleId);
        $stmt->execute();
        $updated = true;
        $stmt->close();
    }
} elseif ($moodleCourseId > 0) {
    $stmt = $conn->prepare("UPDATE onboard_modules SET total_slides = ?, updated_at = NOW() WHERE moodle_course_id = ?");
    if ($stmt) {
        $stmt->bind_param('ii', $totalSlides, $moodleCourseId);
        $stmt->execute();
        $updated = true;
        $stmt->close();
    }
}

// Also update onboard_module_progress and recalculate progress_percent immediately
if ($targetModuleId > 0) {
    $progStmt = $conn->prepare("
        UPDATE onboard_module_progress 
        SET total_slides = ?,
            progress_percent = IF(is_completed = 1, 100, IF(slides_viewed > 0, LEAST(95, GREATEST(5, ROUND((slides_viewed / ?) * 100))), LEAST(95, GREATEST(5, ROUND((1 / ?) * 100)))))
        WHERE module_id = ?
    ");
    if ($progStmt) {
        $progStmt->bind_param('iiii', $totalSlides, $totalSlides, $totalSlides, $targetModuleId);
        $progStmt->execute();
        $progStmt->close();
    }

    // Also update recent SCORM sessions
    $conn->query("
        UPDATE onboard_scorm_sessions 
        SET progress_percent = LEAST(95, GREATEST(5, ROUND((slides_viewed / {$totalSlides}) * 100))) 
        WHERE module_id = {$targetModuleId} AND status != 'completed' AND slides_viewed > 0
    ");
}

// Clear Moodle courses cache in session so recalculation immediately uses new total_slides
foreach ($_SESSION as $k => $v) {
    if (strpos($k, 'moodle_courses_') === 0 || strpos($k, 'moodle_tracks_') === 0) {
        unset($_SESSION[$k]);
    }
}

echo json_encode([
    'success' => true,
    'message' => "Total slides updated to {$totalSlides} successfully.",
    'module_id' => $moduleId,
    'moodle_course_id' => $moodleCourseId,
    'total_slides' => $totalSlides
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

$conn->close();
?>
