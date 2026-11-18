<?php
// Endpoint: api/admin/courses_overview.php
// Returns Moodle courses and onboarding modules overview with real-time enrollment and completion statistics

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

$NEED_PDO = false;
require_once __DIR__ . '/../db.php';
$rootDir = dirname(dirname(__DIR__));

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentUserId = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$currentUserId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

// Admin check
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
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Release session lock early to prevent blocking concurrent API requests
session_write_close();

// Count total active employees enrolled in onboarding
$totalEnrolled = 0;
$userRes = $conn->query("SELECT COUNT(*) AS cnt FROM user WHERE IFNULL(status, 0) = 0");
if ($userRes) {
    $totalEnrolled = (int)($userRes->fetch_assoc()['cnt'] ?? 0);
}

// Count trainees enrolled in Moodle Onboarding (from onboard_moodle_users)
$moodleEnrolled = 0;
$mUserRes = $conn->query("SELECT COUNT(*) AS cnt FROM onboard_moodle_users WHERE moodle_user_id IS NOT NULL");
if ($mUserRes) {
    $moodleEnrolled = (int)($mUserRes->fetch_assoc()['cnt'] ?? 0);
}
// If Moodle users exist, use real Moodle enrolled count; else total active employees
$totalEnrolled = $moodleEnrolled > 0 ? $moodleEnrolled : $totalEnrolled;

// Check if total_slides and sort_order columns exist in onboard_modules
$hasTotalSlidesCol = false;
$colCheck = $conn->query("SHOW COLUMNS FROM onboard_modules LIKE 'total_slides'");
if ($colCheck && $colCheck->num_rows > 0) {
    $hasTotalSlidesCol = true;
}
$totalSlidesField = $hasTotalSlidesCol ? "total_slides," : "10 AS total_slides,";

$hasSortOrderCol = false;
$sortCheck = $conn->query("SHOW COLUMNS FROM onboard_modules LIKE 'sort_order'");
if ($sortCheck && $sortCheck->num_rows > 0) {
    $hasSortOrderCol = true;
}
$sortOrderSelect = $hasSortOrderCol ? "IFNULL(NULLIF(sort_order, 0), id) AS sort_order," : "id AS sort_order,";

// Auto-sync any Category 1 courses from Moodle that aren't yet in onboard_modules
require_once __DIR__ . '/../moodle/client.php';
try {
    $catCourses = callMoodleWs('core_course_get_courses_by_field', ['field' => 'category', 'value' => '1'], $conn);
    if (!empty($catCourses['courses']) && is_array($catCourses['courses'])) {
        foreach ($catCourses['courses'] as $cc) {
            $cid = (int)$cc['id'];
            $chk = $conn->prepare("SELECT id FROM onboard_modules WHERE moodle_course_id = ?");
            if ($chk) {
                $chk->bind_param("i", $cid);
                $chk->execute();
                $exists = $chk->get_result()->fetch_assoc();
                $chk->close();
                if (!$exists) {
                    $cName = html_entity_decode($cc['fullname'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $type = (stripos($cName, 'assess') !== false || stripos($cName, 'quiz') !== false) ? 'assessment' : 'scorm';
                    $desc = "Onboarding course and interactive SCORM learning";
                    $dur = 30;
                    $pass = 80;
                    $sort = $cid;
                    $ins = $conn->prepare("INSERT INTO onboard_modules (title, type, description, duration_minutes, passing_score, is_active, moodle_course_id, sort_order) VALUES (?, ?, ?, ?, ?, 1, ?, ?)");
                    if ($ins) {
                        $ins->bind_param("sssiiii", $cName, $type, $desc, $dur, $pass, $cid, $sort);
                        $ins->execute();
                        $ins->close();
                    }
                }
            }
        }
    }
} catch (Exception $e) {}

// Fetch real Moodle courses (both active and hidden, so Admin can toggle visibility and edit names)
$modules = [];
$modSql = "SELECT id, title, type, description, duration_minutes, passing_score, is_active, moodle_course_id, moodle_cmid, video_url, file_url, {$totalSlidesField} {$sortOrderSelect} 1 AS _dummy 
           FROM onboard_modules 
           WHERE moodle_course_id IS NOT NULL AND moodle_course_id > 0
           ORDER BY sort_order ASC, moodle_course_id ASC, id ASC";
$mRes = $conn->query($modSql);

if ($mRes) {
    while ($m = $mRes->fetch_assoc()) {
        $mId = (int)$m['id'];
        $moodleCid = (int)$m['moodle_course_id'];
        
        // Real progress statistics for this module
        $compCount = 0;
        $inProgCount = 0;
        $failedCount = 0;
        $avgScore = null;
        $totalTimeSeconds = 0;

        $pSql = "SELECT status, is_completed, score, total_time_seconds 
                 FROM onboard_module_progress 
                 WHERE module_id = $mId";
        $pRes = $conn->query($pSql);
        $scores = [];
        if ($pRes) {
            while ($p = $pRes->fetch_assoc()) {
                $isDone = ($p['is_completed'] == 1 || $p['status'] === 'completed') && !in_array($p['status'], ['incomplete', 'in_progress', 'suspend', 'browsed'], true);
                if ($isDone) {
                    $compCount++;
                } elseif ($p['status'] === 'failed') {
                    $failedCount++;
                } elseif ($p['status'] === 'in_progress' || in_array($p['status'], ['incomplete', 'suspend', 'browsed'], true)) {
                    $inProgCount++;
                }

                if ($p['score'] !== null) {
                    $scores[] = (float)$p['score'];
                }
                if (!empty($p['total_time_seconds'])) {
                    $totalTimeSeconds += (int)$p['total_time_seconds'];
                }
            }
        }

        // Real assessment attempts from onboard_assessment_attempts
        $attSql = "SELECT score, is_passed, result FROM onboard_assessment_attempts WHERE module_id = $mId";
        $attRes = $conn->query($attSql);
        if ($attRes) {
            while ($a = $attRes->fetch_assoc()) {
                if ($a['score'] !== null) {
                    $scores[] = (float)$a['score'];
                }
            }
        }

        if (count($scores) > 0) {
            $avgScore = round(array_sum($scores) / count($scores), 1);
        }

        $enrolledCount = $totalEnrolled;
        $notStartedCount = max(0, $enrolledCount - $compCount - $inProgCount - $failedCount);
        $compPercent = $enrolledCount > 0 ? round(($compCount / $enrolledCount) * 100) : 0;

        // Build exact Moodle direct link
        $moodleLink = "https://lms.moodle.hexalearn.com/course/view.php?id=" . $moodleCid;
        if (!empty($m['moodle_cmid'])) {
            $moodleLink = "https://lms.moodle.hexalearn.com/mod/scorm/view.php?id=" . $m['moodle_cmid'];
        }

        // Resolve cache-busting timestamp from cached course image
        $imgVersion = 0;
        $candidateDirs = [
            $rootDir . DIRECTORY_SEPARATOR . 'courses',
            $rootDir . DIRECTORY_SEPARATOR . 'dist' . DIRECTORY_SEPARATOR . 'courses'
        ];
        foreach ($candidateDirs as $cdir) {
            if (is_dir($cdir)) {
                foreach (['jpg', 'jpeg', 'png', 'webp', 'gif'] as $ext) {
                    $cPath = $cdir . DIRECTORY_SEPARATOR . 'course_' . $moodleCid . '.' . $ext;
                    if (file_exists($cPath)) {
                        $imgVersion = filemtime($cPath);
                        break 2;
                    }
                }
            }
        }
        $courseImageFinal = $onboardApiBase . '/moodle/course_image.php?course_id=' . $moodleCid . ($imgVersion > 0 ? '&v=' . $imgVersion : '');

        $modules[] = [
            'id' => $mId,
            'title' => $m['title'],
            'type' => strtoupper($m['type'] ?: 'SCORM'),
            'description' => $m['description'] ?: 'Onboarding course and interactive SCORM learning',
            'duration' => $m['duration_minutes'] ? "{$m['duration_minutes']} mins" : 'Self-paced',
            'passing_score' => (int)($m['passing_score'] ?: 80),
            'is_active' => (bool)$m['is_active'],
            'moodle_course_id' => $moodleCid,
            'moodle_cmid' => $m['moodle_cmid'] ? (int)$m['moodle_cmid'] : null,
            'moodle_link' => $moodleLink,
            'course_image' => $courseImageFinal,
            'enrolled_count' => $enrolledCount,
            'completed_count' => $compCount,
            'in_progress_count' => $inProgCount,
            'failed_count' => $failedCount,
            'not_started_count' => $notStartedCount,
            'completion_percent' => $compPercent,
            'average_score' => $avgScore,
            'total_time_seconds' => $totalTimeSeconds,
            'total_slides' => (int)($m['total_slides'] ?? 10),
            'sort_order' => (int)($m['sort_order'] ?? 0)
        ];
    }
}

// Summary stats
$totalCourses = count($modules);
$activeCourses = count(array_filter($modules, fn($m) => $m['is_active']));
$scormCount = count(array_filter($modules, fn($m) => stripos($m['type'], 'SCORM') !== false));
$quizCount = count(array_filter($modules, fn($m) => stripos($m['type'], 'ASSESSMENT') !== false || stripos($m['type'], 'QUIZ') !== false));

// Check Sequential Progression Rule from onboard_settings
$seqLocking = false;
$chkSet = $conn->query("SELECT `value` FROM onboard_settings WHERE `key` = 'sequential_locking' LIMIT 1");
if ($chkSet && $sRow = $chkSet->fetch_assoc()) {
    $seqLocking = ((string)$sRow['value'] === '1' || strtolower((string)$sRow['value']) === 'true');
} else {
    $conn->query("INSERT IGNORE INTO onboard_settings (`key`, `value`) VALUES ('sequential_locking', '0')");
}

echo json_encode([
    'success' => true,
    'sequential_locking' => $seqLocking,
    'stats' => [
        'total_courses' => $totalCourses,
        'active_courses' => $activeCourses,
        'scorm_count' => $scormCount,
        'quiz_count' => $quizCount,
        'total_enrolled' => $totalEnrolled,
        'sequential_locking' => $seqLocking,
        'lms_connected' => true,
        'moodle_url' => 'https://lms.moodle.hexalearn.com'
    ],
    'courses' => $modules
]);
$conn->close();
?>
