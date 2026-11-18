<?php
/**
 * api/moodle/track_slide.php
 * Real-time SCORM Slide & Progress Heartbeat Endpoint
 * Receives exact current slide, total slides, and progress percent directly from the player
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
// Enable CORS for Storyline player hosted on Moodle LMS
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (!empty($origin)) {
    header("Access-Control-Allow-Origin: $origin");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Methods: POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
} else {
    header("Access-Control-Allow-Origin: *");
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$NEED_PDO = false;
require_once __DIR__ . '/../db.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$userId = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;

// Resolve employee cleanly without cross-user hexa_uid/moodle_user_id collisions
if (!$userId) {
    if (!empty($input['user_id']) && is_numeric($input['user_id'])) {
        $userId = (int)$input['user_id'];
    } elseif (!empty($input['moodle_user_id']) && is_numeric($input['moodle_user_id'])) {
        $mId = (int)$input['moodle_user_id'];
        $chkU = $conn->prepare("SELECT hexa_uid FROM onboard_moodle_users WHERE moodle_user_id = ? LIMIT 1");
        if ($chkU) {
            $chkU->bind_param('i', $mId);
            $chkU->execute();
            $uRow = $chkU->get_result()->fetch_assoc();
            $chkU->close();
            if ($uRow) $userId = (int)$uRow['hexa_uid'];
        }
    } else {
        $rawId = trim((string)($input['student_id'] ?? $input['username'] ?? ''));
        if (!empty($rawId)) {
            if (is_numeric($rawId)) {
                $mId = (int)$rawId;
                $chkU = $conn->prepare("SELECT hexa_uid FROM onboard_moodle_users WHERE moodle_user_id = ? LIMIT 1");
                if ($chkU) {
                    $chkU->bind_param('i', $mId);
                    $chkU->execute();
                    $uRow = $chkU->get_result()->fetch_assoc();
                    $chkU->close();
                    if ($uRow) $userId = (int)$uRow['hexa_uid'];
                }
            } else {
                // Moodle SCORM passes username string as student_id (e.g. "ashisr")
                $chkU = $conn->prepare("SELECT uid AS hexa_uid FROM user WHERE username = ? LIMIT 1");
                if ($chkU) {
                    $chkU->bind_param('s', $rawId);
                    $chkU->execute();
                    $uRow = $chkU->get_result()->fetch_assoc();
                    $chkU->close();
                    if ($uRow) $userId = (int)$uRow['hexa_uid'];
                }
            }
        }
    }
}

if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

$moduleId = isset($input['module_id']) ? (int)$input['module_id'] : 0;
$cmid = isset($input['cmid']) ? (int)$input['cmid'] : 0;
$courseId = isset($input['course_id']) ? (int)$input['course_id'] : 0;

$currentSlide = isset($input['current_slide']) ? (int)$input['current_slide'] : (isset($input['slide']) ? (int)$input['slide'] : 0);
$totalSlides = isset($input['total_slides']) ? (int)$input['total_slides'] : 0;
$progressPercent = isset($input['progress_percent']) ? (int)$input['progress_percent'] : (isset($input['progress']) ? (int)$input['progress'] : null);

// Resolve target module in onboard_modules
// Notice: Storyline Module 6 (POSH) frequently reports module_id = 6 (its curriculum sequence number)
// In onboard_modules, id=6 is inactive Employee Assessment, while id=11 is POSH (20 slides, moodle_course_id=9)
if ($moduleId === 6 && $totalSlides === 20) {
    $moduleId = 11;
}

$targetModId = $moduleId;
if ($targetModId <= 0) {
    if ($cmid > 0) {
        $chk = $conn->prepare("SELECT id, total_slides FROM onboard_modules WHERE moodle_cmid = ? LIMIT 1");
        if ($chk) {
            $chk->bind_param('i', $cmid);
            $chk->execute();
            $r = $chk->get_result()->fetch_assoc();
            $chk->close();
            if ($r) {
                $targetModId = (int)$r['id'];
                if ($totalSlides <= 0 && !empty($r['total_slides'])) {
                    $totalSlides = (int)$r['total_slides'];
                }
            }
        }
    }
    if ($targetModId <= 0 && $courseId > 0) {
        $chk = $conn->prepare("SELECT id, total_slides FROM onboard_modules WHERE moodle_course_id = ? LIMIT 1");
        if ($chk) {
            $chk->bind_param('i', $courseId);
            $chk->execute();
            $r = $chk->get_result()->fetch_assoc();
            $chk->close();
            if ($r) {
                $targetModId = (int)$r['id'];
                if ($totalSlides <= 0 && !empty($r['total_slides'])) {
                    $totalSlides = (int)$r['total_slides'];
                }
            }
        }
    }
}

if ($targetModId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Module could not be identified.']);
    exit;
}

// Automatically sync detected total_slides into onboard_modules if verified from player (e.g. 17)
if ($totalSlides > 0) {
    $updMod = $conn->prepare("UPDATE onboard_modules SET total_slides = ?, updated_at = NOW() WHERE id = ?");
    if ($updMod) {
        $updMod->bind_param('ii', $totalSlides, $targetModId);
        $updMod->execute();
        $updMod->close();
    }
}

// Compute progress percent if not provided explicitly (capped at 99; completion comes only from Moodle LMS)
if ($progressPercent === null && $currentSlide > 0 && $totalSlides > 0) {
    $progressPercent = min(95, max(5, (int)round(($currentSlide / $totalSlides) * 100)));
}
$progressPercent = $progressPercent !== null ? min(99, max(0, (int)$progressPercent)) : 0;
$isCompleted = 0;

// Ensure visited_slides column exists in onboard_module_progress
static $hasVisitedCol = null;
if ($hasVisitedCol === null) {
    $hasVisitedCol = false;
    $chkCol = $conn->query("SHOW COLUMNS FROM onboard_module_progress LIKE 'visited_slides'");
    if ($chkCol && $chkCol->num_rows > 0) {
        $hasVisitedCol = true;
    } else {
        $conn->query("ALTER TABLE onboard_module_progress ADD COLUMN visited_slides TEXT DEFAULT NULL AFTER total_slides");
        $hasVisitedCol = true;
    }
}

// Update or insert into onboard_module_progress
$checkProg = $conn->prepare("SELECT id, progress_percent, slides_viewed, total_slides, visited_slides, is_completed FROM onboard_module_progress WHERE user_id = ? AND module_id = ? LIMIT 1");
$existing = null;
if ($checkProg) {
    $checkProg->bind_param('ii', $userId, $targetModId);
    $checkProg->execute();
    $existing = $checkProg->get_result()->fetch_assoc();
    $checkProg->close();
}

// 1. Parse persistent list of unique slides visited from MySQL (Persistent across devices & sessions)
$visitedList = [];
if (!empty($existing['visited_slides'])) {
    $parts = explode(',', (string)$existing['visited_slides']);
    foreach ($parts as $p) {
        $pVal = (int)trim($p);
        if ($pVal > 0 && !in_array($pVal, $visitedList, true)) {
            $visitedList[] = $pVal;
        }
    }
}

// 2. Add current slide to persistent visited set
if ($currentSlide > 0 && !in_array($currentSlide, $visitedList, true)) {
    $visitedList[] = $currentSlide;
}
sort($visitedList);
$visitedSlidesStr = implode(',', $visitedList);
$uniqueCount = count($visitedList);

// 3. Resolve authoritative total_slides
$effTotalSlides = $totalSlides > 0 ? $totalSlides : (!empty($existing['total_slides']) ? (int)$existing['total_slides'] : 10);

// 4. Server-Side Progress Calculation (Zero Client Trust)
// CRITICAL RULE: Slide viewing alone tracks engagement but NEVER grants completion or 100%.
// Moodle LMS course/SCORM completion is the primary completion authority.
$alreadyCompleted = !empty($existing['is_completed']) && (int)$existing['is_completed'] === 1;

if ($alreadyCompleted) {
    // Preserve Moodle-verified completion
    $serverProgressPercent = 100;
    $isCompleted = 1;
    $statusStr = 'completed';
    $uniqueCount = $effTotalSlides;
    $currentSlide = $effTotalSlides;
    $visitedList = range(1, $effTotalSlides);
    $visitedSlidesStr = implode(',', $visitedList);
    $locStr = "Page {$effTotalSlides}/{$effTotalSlides}";
} else {
    // In-progress engagement only: cap at 99% and never set is_completed = 1 from slide pings
    $slideRatio = ($effTotalSlides > 0) ? (int)round(($uniqueCount / $effTotalSlides) * 100) : 0;
    $serverProgressPercent = min(99, max(0, $slideRatio));
    $isCompleted = 0;
    $statusStr = $serverProgressPercent > 0 ? 'in_progress' : 'not_started';
    $locStr = "Page {$currentSlide}/{$effTotalSlides}";
}

if ($existing) {
    $updProg = $conn->prepare("
        UPDATE onboard_module_progress 
        SET slides_viewed = ?, 
            total_slides = ?, 
            visited_slides = ?,
            lesson_location = ?,
            progress_percent = ?, 
            is_completed = ?,
            status = ?,
            last_synced_at = NOW()
        WHERE user_id = ? AND module_id = ?
    ");
    if ($updProg) {
        $updProg->bind_param('iissiisii', 
            $uniqueCount, 
            $effTotalSlides, 
            $visitedSlidesStr, 
            $locStr, 
            $serverProgressPercent, 
            $isCompleted, 
            $statusStr, 
            $userId, 
            $targetModId
        );
        $updProg->execute();
        $updProg->close();
    }
} else {
    $insProg = $conn->prepare("
        INSERT INTO onboard_module_progress 
            (user_id, module_id, status, progress_percent, slides_viewed, total_slides, visited_slides, lesson_location, is_completed, last_synced_at)
        VALUES 
            (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    if ($insProg) {
        $insProg->bind_param('iisiissii', 
            $userId, 
            $targetModId, 
            $statusStr, 
            $serverProgressPercent, 
            $uniqueCount, 
            $effTotalSlides, 
            $visitedSlidesStr, 
            $locStr, 
            $isCompleted
        );
        $insProg->execute();
        $insProg->close();
    }
}

// Record in onboard_module_sessions
$sessStmt = $conn->prepare("
    INSERT INTO onboard_module_sessions 
        (user_id, module_id, slides_viewed, progress_percent, status, lesson_location, recorded_at)
    VALUES 
        (?, ?, ?, ?, ?, ?, NOW())
");
if ($sessStmt) {
    $sessStmt->bind_param('iiiiss', $userId, $targetModId, $uniqueCount, $serverProgressPercent, $statusStr, $locStr);
    $sessStmt->execute();
    $sessStmt->close();
}

// Invalidate launch authorization immediately once course completes
if ($isCompleted) {
    if (!empty($cmid) && isset($_SESSION["sso_auth_{$userId}_{$cmid}"])) {
        unset($_SESSION["sso_auth_{$userId}_{$cmid}"]);
    }
}

echo json_encode([
    'success' => true,
    'message' => 'Slide progress tracked successfully.',
    'module_id' => $targetModId,
    'current_slide' => $currentSlide,
    'total_slides' => $effTotalSlides,
    'unique_slides_viewed' => $uniqueCount,
    'visited_slides' => $visitedList,
    'progress_percent' => $serverProgressPercent,
    'is_completed' => (bool)$isCompleted,
    'status' => $statusStr
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

$conn->close();
?>
