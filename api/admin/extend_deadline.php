<?php
// Endpoint: api/admin/extend_deadline.php
// Grants an individual deadline extension to a specific employee
// Unlocks only that specific employee while keeping all other incomplete employees locked

date_default_timezone_set('Asia/Kolkata');

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

$NEED_PDO = false;
require_once __DIR__ . '/../db.php';
if (file_exists(__DIR__ . '/../deadline_helper.php')) {
    require_once __DIR__ . '/../deadline_helper.php';
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentUserId = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$currentUserId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

// 1. Verify Admin Role (user_role = 1 or specific UIDs)
$isAdmin = false;
if (in_array((string)$currentUserId, ['26', '64', '205'], true)) {
    $isAdmin = true;
} else {
    $roleCheck = $conn->prepare("SELECT user_role FROM user WHERE uid = ?");
    if ($roleCheck) {
        $roleCheck->bind_param('i', $currentUserId);
        $roleCheck->execute();
        $roleRes = $roleCheck->get_result()->fetch_assoc();
        if (($roleRes['user_role'] ?? 0) == 1) {
            $isAdmin = true;
        }
        $roleCheck->close();
    }
}

if (!$isAdmin) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized: Admin privileges required.']);
    exit;
}

// Release session lock
session_write_close();

// 2. Parse JSON Input
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);
if (!is_array($data)) {
    $data = $_POST;
}

$targetUserId = isset($data['user_id']) ? (int)$data['user_id'] : 0;
$newDeadlineRaw = trim($data['new_deadline'] ?? '');
$reason = trim($data['reason'] ?? 'Manager approval');

if ($targetUserId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing or invalid user_id parameter.']);
    exit;
}

if (empty($newDeadlineRaw)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please provide a valid new_deadline date.']);
    exit;
}

// Validate and normalize target deadline to end of day (23:59:59 IST)
try {
    $dtObj = new DateTime($newDeadlineRaw, new DateTimeZone('Asia/Kolkata'));
    // If only date was passed without time or time is 00:00:00, set to 23:59:59
    $formattedDeadline = $dtObj->format('Y-m-d') . ' 23:59:59';
} catch (\Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid date format: ' . $e->getMessage()]);
    exit;
}

// 3. Ensure employee deadline record exists
ensureEmployeeDeadlineRecord($targetUserId, $conn);

// 4. Update extended_deadline for this individual employee
$stmt = $conn->prepare("UPDATE onboard_employee_deadlines 
    SET extended_deadline = ?, extended_by_admin_id = ?, extension_reason = ?, updated_at = NOW() 
    WHERE user_id = ?");

if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database prepare failed: ' . $conn->error]);
    exit;
}

$stmt->bind_param("sisi", $formattedDeadline, $currentUserId, $reason, $targetUserId);
$executed = $stmt->execute();
$stmt->close();

if (!$executed) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to update employee deadline: ' . $conn->error]);
    exit;
}

// 5. Invalidate disk cache for this employee so changes take effect immediately
$cacheDirs = [
    __DIR__ . '/../moodle/cache',
    __DIR__ . '/moodle/cache',
    dirname(__DIR__) . '/moodle/cache'
];

foreach ($cacheDirs as $cd) {
    if (is_dir($cd)) {
        // Clean user specific cache
        $pattern = $cd . "/user_{$targetUserId}_*.json";
        foreach (glob($pattern) as $cf) {
            @unlink($cf);
        }
    }
}

// 6. Return updated deadline status
$updatedStatus = getEmployeeDeadlineStatus($targetUserId, $conn);

echo json_encode([
    'success' => true,
    'message' => 'Deadline successfully extended to ' . date('d-M-Y h:i A', strtotime($formattedDeadline)),
    'deadline_info' => $updatedStatus
]);
