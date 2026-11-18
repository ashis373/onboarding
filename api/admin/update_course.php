<?php
// Endpoint: api/admin/update_course.php
// Updates course name / title, description, passing score in onboard_modules table

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

$moduleId = isset($input['module_id']) ? (int)$input['module_id'] : (isset($input['id']) ? (int)$input['id'] : 0);
$moodleCourseId = isset($input['moodle_course_id']) ? (int)$input['moodle_course_id'] : 0;
$title = isset($input['title']) ? trim((string)$input['title']) : '';
$description = isset($input['description']) ? trim((string)$input['description']) : null;
$durationMinutes = isset($input['duration_minutes']) ? (int)$input['duration_minutes'] : null;
$passingScore = isset($input['passing_score']) ? (int)$input['passing_score'] : null;

if (empty($title)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Course name / title cannot be empty.']);
    exit;
}

if ($moduleId <= 0 && $moodleCourseId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Module ID or Moodle Course ID is required.']);
    exit;
}

// Update onboard_modules
$updated = false;
if ($moduleId > 0) {
    $stmt = $conn->prepare("
        UPDATE onboard_modules 
        SET title = ?, 
            description = COALESCE(?, description), 
            duration_minutes = COALESCE(?, duration_minutes),
            passing_score = COALESCE(?, passing_score)
        WHERE id = ?
    ");
    $stmt->bind_param("ssiii", $title, $description, $durationMinutes, $passingScore, $moduleId);
    $stmt->execute();
    $updated = $stmt->affected_rows >= 0;
    $stmt->close();
} elseif ($moodleCourseId > 0) {
    $stmt = $conn->prepare("
        UPDATE onboard_modules 
        SET title = ?, 
            description = COALESCE(?, description), 
            duration_minutes = COALESCE(?, duration_minutes),
            passing_score = COALESCE(?, passing_score)
        WHERE moodle_course_id = ?
    ");
    $stmt->bind_param("ssiii", $title, $description, $durationMinutes, $passingScore, $moodleCourseId);
    $stmt->execute();
    $updated = $stmt->affected_rows >= 0;
    $stmt->close();
}

// Clear any cached courses session data in PHP session
foreach ($_SESSION as $k => $v) {
    if (strpos($k, 'moodle_cached_courses_') === 0 || strpos($k, 'moodle_enrol_checked_') === 0) {
        unset($_SESSION[$k]);
    }
}

echo json_encode([
    'success' => true,
    'message' => 'Course name and details successfully updated.',
    'course' => [
        'id' => $moduleId,
        'moodle_course_id' => $moodleCourseId,
        'title' => $title,
        'description' => $description,
        'duration_minutes' => $durationMinutes,
        'passing_score' => $passingScore
    ]
]);
$conn->close();
