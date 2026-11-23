<?php
// Endpoint: api/moodle/courses.php
// Returns enrolled Moodle courses and SCORM tracking for the authenticated HexaOne employee

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
@set_time_limit(120);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$NEED_PDO = false;
require_once __DIR__ . '/../db.php';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$httpHost = $_SERVER['HTTP_HOST'] ?? 'corhis.hexalearn.com';
$reqUri = $_SERVER['REQUEST_URI'] ?? '';
if (strpos($reqUri, '/internal/corhis/onboard') !== false) {
    $onboardApiBase = $scheme . $httpHost . '/internal/corhis/onboard/api';
} elseif (strpos($httpHost, 'corhis.hexalearn.com') !== false) {
    $onboardApiBase = 'https://corhis.hexalearn.com/onboard/api';
} else {
    $onboardApiBase = $scheme . $httpHost . '/onboard/api';
}

require_once __DIR__ . '/client.php';
require_once __DIR__ . '/sync_user.php';
if (file_exists(__DIR__ . '/../deadline_helper.php')) {
    require_once __DIR__ . '/../deadline_helper.php';
}
if (file_exists(__DIR__ . '/../certificate_helper.php')) {
    require_once __DIR__ . '/../certificate_helper.php';
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated. Please log in to HexaOne.']);
    exit;
}

// Release session lock early so concurrent requests do not wait or block on PHP session
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// 1. Verify employee identity and active status
// Status Rule: status = 0 (Currently Active), status = 1 (Left company / Inactive)
$stmt = $conn->prepare("SELECT * FROM user WHERE uid = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Employee record not found.']);
    exit;
}

if ((int)$user['status'] !== 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Employee is inactive (status indicates employee has left the company). Access denied.']);
    exit;
}

// Check if user is Admin
$isAdmin = false;
if (
    in_array((string)$user_id, ['26', '64'], true) ||
    ((int)($user['user_role'] ?? 0) === 1)
) {
    $isAdmin = true;
}

// 2. Fetch authoritative curriculum modules from onboard_modules
$modSettings = [];
$activeRequiredCourseIds = [];

// Authoritative Course to primary SCORM module & SCO mapping
$courseMasterMap = [
    2  => ['cmid' => 6,  'scoid' => 8,  'org' => 'M1_-_Onboarding_Program_ORG', 'title' => 'Employee Onboarding 1 SCORM'],
    3  => ['cmid' => 4,  'scoid' => 32, 'org' => 'M_11_-_Assessment_ORG',        'title' => 'Employee_Assessment'],
    4  => ['cmid' => 7,  'scoid' => 10, 'org' => 'M2_-_Work_Rules_and_Work_Life_ORG', 'title' => 'Work Rules and Work Life M-2'],
    5  => ['cmid' => 8,  'scoid' => 12, 'org' => 'M3_-_Leave_&_Holidays_ORG',   'title' => 'Leave & Holidays'],
    6  => ['cmid' => 9,  'scoid' => 14, 'org' => 'M5_-_IT,_Confidentiality_&_Security_ORG', 'title' => 'IT, Confidentiality & Security'],
    8  => ['cmid' => 10, 'scoid' => 18, 'org' => 'M4_-_Code_of_Conduct_and_Workplace_Behavior_ORG', 'title' => 'Code of Conduct and Workplace Behavior'],
    9  => ['cmid' => 11, 'scoid' => 20, 'org' => 'M6_-_Prevention_of_Sexual_Harassment_(POSH)_ORG', 'title' => 'Prevention of Sexual Harassment'],
    10 => ['cmid' => 12, 'scoid' => 22, 'org' => 'M7_-_Compensation_&_Benefits_ORG', 'title' => 'Compensation and Benefits'],
    11 => ['cmid' => 14, 'scoid' => 24, 'org' => 'M8_-_Performance_and_Career_Growth_ORG', 'title' => 'Performance and Career Growth'],
    12 => ['cmid' => 15, 'scoid' => 26, 'org' => 'M9_-_HR_Processes_&_Employee_Support_ORG', 'title' => 'HR Processes and Employee Support'],
    13 => ['cmid' => 16, 'scoid' => 28, 'org' => 'M10_-_Safety,_Security,_and_Workplace_Essentials_ORG', 'title' => 'Safety, Security, and Workplace Essentials'],
];

$hasSortOrderCol = false;
$sortCheck = $conn->query("SHOW COLUMNS FROM onboard_modules LIKE 'sort_order'");
if ($sortCheck && $sortCheck->num_rows > 0) {
    $hasSortOrderCol = true;
}
$sortOrderSelect = $hasSortOrderCol ? "IFNULL(NULLIF(sort_order, 0), id) AS sort_order," : "id AS sort_order,";
$sortOrderClause = $hasSortOrderCol ? "ORDER BY sort_order ASC, id ASC" : "ORDER BY id ASC";

$modSetRes = $conn->query("
    SELECT id, moodle_course_id, moodle_cmid, moodle_scoid, title, description,
           {$sortOrderSelect}
           IFNULL(is_active, 1) AS is_active,
           IFNULL(duration_minutes, 30) AS duration_minutes,
           IFNULL(passing_score, 80) AS passing_score
    FROM onboard_modules 
    WHERE moodle_course_id IS NOT NULL AND moodle_course_id > 0
    {$sortOrderClause}
");
if ($modSetRes) {
    while ($msRow = $modSetRes->fetch_assoc()) {
        $cIdKey = (int)$msRow['moodle_course_id'];
        $isActive = (int)$msRow['is_active'];
        $mCmid = (int)($msRow['moodle_cmid'] ?? 0);
        $mScoId = (int)($msRow['moodle_scoid'] ?? 0);

        if ($cIdKey === 3 || (int)($msRow['id'] ?? 0) === 6 || stripos((string)$msRow['title'], 'assess') !== false) {
            $mCmid = 4;
            $mScoId = 32;
            if ($conn && ((int)($msRow['moodle_cmid'] ?? 0) !== 4 || (int)($msRow['moodle_scoid'] ?? 0) !== 32)) {
                @$conn->query("UPDATE onboard_modules SET moodle_cmid = 4, moodle_scoid = 32 WHERE moodle_course_id = 3 OR id = 6");
            }
        } else {
            if (!$mCmid && isset($courseMasterMap[$cIdKey])) {
                $mCmid = $courseMasterMap[$cIdKey]['cmid'];
            }
            if (!$mScoId && isset($courseMasterMap[$cIdKey])) {
                $mScoId = $courseMasterMap[$cIdKey]['scoid'];
            }
        }

        $modSettings[$cIdKey] = [
            'id' => (int)$msRow['id'],
            'moodle_course_id' => $cIdKey,
            'moodle_cmid' => $mCmid,
            'moodle_scoid' => $mScoId,
            'title' => trim((string)$msRow['title']),
            'description' => trim((string)$msRow['description']),
            'duration_minutes' => (int)$msRow['duration_minutes'],
            'passing_score' => (int)$msRow['passing_score'],
            'total_slides' => 10,
            'sort_order' => (int)$msRow['sort_order'],
            'is_active' => $isActive
        ];
        if ($isActive === 1) {
            $activeRequiredCourseIds[] = $cIdKey;
        }
    }
}

// 2.5 Fetch mapped Moodle User ID from onboard_moodle_users
$stmt = $conn->prepare("SELECT moodle_user_id, sync_status, last_synced_at FROM onboard_moodle_users WHERE hexa_uid = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$mapping = $stmt->get_result()->fetch_assoc();
$stmt->close();

$moodleUserId = null;
if (!empty($mapping['moodle_user_id']) && $mapping['sync_status'] === 'Linked') {
    $moodleUserId = (int)$mapping['moodle_user_id'];
} else {
    // Automatically discover, create in Moodle, enrol in Category 1 courses, and link in onboard_moodle_users
    $syncedId = autoSyncMoodleUser($user_id, $conn);
    if ($syncedId) {
        $moodleUserId = $syncedId;
    }
}

if (!$moodleUserId) {
    // Release session lock before exit
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    echo json_encode([
        'success' => true,
        'linked' => false,
        'message' => 'Moodle learning account is not yet linked for this employee.',
        'employee' => [
            'uid' => (int)$user['uid'],
            'username' => $user['username'],
            'name' => trim($user['firstname'] . ' ' . $user['lastname']),
            'sync_status' => $mapping['sync_status'] ?? 'Not linked'
        ],
        'sync_diagnostic' => $GLOBALS['moodle_sync_diagnostic'] ?? null,
        'courses' => []
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// 2.6 Serve per-user cached response unless course-close sync / force refresh is requested (no 600s auto-polling)
$userCacheDir = __DIR__ . '/cache';
$userCacheFile = $userCacheDir . '/user_courses_' . (int)$user_id . '_' . (int)$moodleUserId . '.json';
$bypassUserCache = !empty($_GET['force_refresh']) || !empty($_GET['refresh_all']) || !empty($_GET['sync_progress']);

$serveUserCacheIfValid = function() use ($userCacheFile, &$conn, $modSettings, $activeRequiredCourseIds, $user_id, $onboardApiBase) {
    if (!file_exists($userCacheFile)) {
        return false;
    }
    $cachedPayload = @file_get_contents($userCacheFile);
    if ($cachedPayload !== false && $cachedPayload !== '') {
        $decodedCached = json_decode($cachedPayload, true);
        if (is_array($decodedCached) && !empty($decodedCached['success']) && !empty($decodedCached['courses'])) {
            // Verify all currently active courses in onboard_modules are present in the cached courses list.
            // If an Admin unhid a course, the cached list won't have it -> return false to regenerate instantly with 0 delay!
            $cachedCourseIds = array_map(function($c) { return (int)($c['id'] ?? 0); }, $decodedCached['courses']);
            foreach ($activeRequiredCourseIds as $reqCid) {
                if (!in_array($reqCid, $cachedCourseIds, true)) {
                    return false;
                }
            }

            // In-Memory Filter: Hide inactive courses dynamically without deleting the cache!
            $decodedCached['courses'] = array_values(array_filter($decodedCached['courses'], function($cc) use ($modSettings) {
                $cid = (int)($cc['id'] ?? 0);
                if (isset($modSettings[$cid]) && (int)$modSettings[$cid]['is_active'] === 0) {
                    return false;
                }
                return true;
            }));

            // Dynamically inject live assessment attempts into the cached response so Attempt History is ALWAYS 100% up to date
            if ($user_id > 0 && isset($conn) && $conn instanceof mysqli) {
                try {
                    $attStmt = $conn->prepare("
                        SELECT id, attempt, Quiz_score, passing_score, result, is_locked, attempted_at 
                        FROM onboard_induction_assessment_attempts 
                        WHERE user_id = ? 
                        ORDER BY attempt ASC, id ASC
                    ");
                    if ($attStmt) {
                        $attStmt->bind_param("i", $user_id);
                        $attStmt->execute();
                        $attRes = $attStmt->get_result();
                        $liveAttempts = [];
                        $liveLastScore = null;
                        $liveIsLocked = false;
                        $livePassed = false;
                        while ($att = $attRes->fetch_assoc()) {
                            $sVal = (float)$att['Quiz_score'];
                            $pVal = (float)($att['passing_score'] ?: 80);
                            $rVal = trim($att['result'] ?? '');
                            if (empty($rVal)) {
                                $rVal = ($sVal >= $pVal) ? 'Passed' : 'Failed';
                            }
                            $stVal = (strtolower($rVal) === 'passed' || $sVal >= $pVal) ? 'passed' : 'failed';
                            $liveAttempts[] = [
                                'attempt' => (int)$att['attempt'],
                                'score' => (int)round($sVal),
                                'result' => $rVal,
                                'status' => $stVal,
                                'total_time' => '—',
                                'date' => !empty($att['attempted_at']) ? date('d-M-Y H:i', strtotime($att['attempted_at'])) : '—'
                            ];
                            $liveLastScore = (int)round($sVal);
                            if ($stVal === 'passed') $livePassed = true;
                            if ((int)$att['is_locked'] === 1) $liveIsLocked = true;
                        }
                        $attStmt->close();

                        if (!empty($liveAttempts)) {
                            $liveCount = count($liveAttempts);
                            $foundAssessInCache = false;
                            foreach ($decodedCached['courses'] as &$cItem) {
                                if ((int)($cItem['id'] ?? 0) === 3) {
                                    $foundAssessInCache = true;
                                    if (!empty($cItem['modules']) && is_array($cItem['modules'])) {
                                        foreach ($cItem['modules'] as &$mItem) {
                                            $mItem['attempts'] = $liveCount;
                                            $mItem['attempts_history'] = $liveAttempts;
                                            $mItem['score'] = $liveLastScore;
                                            if ($livePassed) {
                                                $mItem['status'] = 'completed';
                                                $mItem['is_completed'] = true;
                                                $mItem['progress_percent'] = 100;
                                            } elseif ($liveIsLocked) {
                                                $mItem['status'] = 'locked';
                                                $mItem['is_locked'] = true;
                                            }
                                        }
                                        unset($mItem);
                                    }
                                    if (!empty($cItem['scorms']) && is_array($cItem['scorms'])) {
                                        foreach ($cItem['scorms'] as &$scItem) {
                                            $scItem['attempts'] = $liveCount;
                                            $scItem['attempts_history'] = $liveAttempts;
                                            $scItem['score'] = $liveLastScore;
                                            if ($livePassed) {
                                                $scItem['status'] = 'completed';
                                                $scItem['is_completed'] = true;
                                                $scItem['progress_percent'] = 100;
                                            } elseif ($liveIsLocked) {
                                                $scItem['status'] = 'locked';
                                                $scItem['is_locked'] = true;
                                            }
                                        }
                                        unset($scItem);
                                    }
                                }
                            }
                            unset($cItem);
                            if ($foundAssessInCache) {
                                $cachedPayload = json_encode($decodedCached, JSON_UNESCAPED_SLASHES);
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    error_log("Notice: Error in live attempts injection: " . $e->getMessage());
                }
            }

            // Dynamically inject live deadline, certificate download flag, and lockout status into cached response
            if ($user_id > 0 && isset($conn) && $conn instanceof mysqli) {
                try {
                    if (function_exists('getEmployeeDeadlineStatus')) {
                        $decodedCached['deadline_info'] = getEmployeeDeadlineStatus((int)$user_id, $conn);
                    }
                    if (function_exists('isEmployeeCertificateDownloadAllowed')) {
                        $decodedCached['allow_employee_certificate_download'] = isEmployeeCertificateDownloadAllowed($conn);
                    }
                    $cachedPayload = json_encode($decodedCached, JSON_UNESCAPED_SLASHES);
                } catch (\Throwable $e) {
                    error_log("Notice: Error in live deadline injection: " . $e->getMessage());
                }
            }

            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            if (isset($conn) && $conn instanceof mysqli) {
                @$conn->close();
            }
            echo $cachedPayload;
            return true;
        }
    }
    return false;
};

if (!$bypassUserCache && $serveUserCacheIfValid()) {
    exit;
}

// Per-user concurrency lock: prevents two open tabs from firing duplicate Moodle bursts simultaneously
if (!is_dir($userCacheDir)) {
    @mkdir($userCacheDir, 0755, true);
}
$userLockHandle = @fopen($userCacheDir . '/uc_lock_' . (int)$user_id . '.lock', 'c+');
if ($userLockHandle) {
    @flock($userLockHandle, LOCK_EX);
    // If another tab just finished syncing within the last 5 seconds, reuse its fresh cache
    if (file_exists($userCacheFile) && (time() - filemtime($userCacheFile)) < 5 && $serveUserCacheIfValid()) {
        @flock($userLockHandle, LOCK_UN);
        @fclose($userLockHandle);
        exit;
    }
}

// 3. Query Moodle for employee enrollments (skip on sync_progress=1 when valid user cache already exists)
$moodleWarnings = [];
$syncCourseId = (!empty($_GET['sync_progress']) && !empty($_GET['sync_course_id'])) ? (int)$_GET['sync_course_id'] : 0;
$isSyncProgressOnly = !empty($_GET['sync_progress']) && empty($_GET['force_refresh']) && empty($_GET['refresh_all']) && file_exists($userCacheFile);

$cachedUserCoursesMap = [];
if ($isSyncProgressOnly && $syncCourseId > 0) {
    $rawCachedJson = @file_get_contents($userCacheFile);
    $decodedCache = $rawCachedJson ? json_decode($rawCachedJson, true) : null;
    if (is_array($decodedCache) && !empty($decodedCache['courses']) && is_array($decodedCache['courses'])) {
        foreach ($decodedCache['courses'] as $ccItem) {
            if (!empty($ccItem['id'])) {
                $cachedUserCoursesMap[(int)$ccItem['id']] = $ccItem;
            }
        }
    }
}

$coursesResponse = [];
if (!$isSyncProgressOnly) {
    $coursesResponse = callMoodleWs('core_enrol_get_users_courses', [
        'userid' => $moodleUserId
    ], $conn);

    // Verify if employee is missing enrollment in any required active module courses
    $enrolledCourseIds = [];
    if (!empty($coursesResponse) && is_array($coursesResponse) && empty($coursesResponse['error'])) {
        foreach ($coursesResponse as $cr) {
            if (isset($cr['id'])) {
                $enrolledCourseIds[] = (int)$cr['id'];
            }
        }
    }

    $missingEnrollmentIds = array_values(array_diff($activeRequiredCourseIds, $enrolledCourseIds));
    $needsEnrollmentSync = !empty($missingEnrollmentIds) || !empty($_GET['refresh_all']);

    if ($needsEnrollmentSync) {
        syncCategoryCoursesAndEnrollments($moodleUserId, $conn, 1);

        // Re-fetch user enrollments from Moodle only if missing courses were enrolled or refresh_all was requested
        $refreshedResponse = callMoodleWs('core_enrol_get_users_courses', [
            'userid' => $moodleUserId
        ], $conn);
        if (!empty($refreshedResponse) && is_array($refreshedResponse) && empty($refreshedResponse['error'])) {
            $coursesResponse = $refreshedResponse;
        }
    }
}

// Release session lock early so concurrent requests do not wait or block
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$cacheKey = 'moodle_cached_courses_' . $moodleUserId;

// 3.5 CATALOG RECONCILIATION:
// Index courses returned by Moodle and synthesize any missing required modules from onboard_modules
$indexedCourses = [];
if (is_array($coursesResponse) && empty($coursesResponse['error'])) {
    foreach ($coursesResponse as $c) {
        $cId = (int)($c['id'] ?? 0);
        if ($cId > 0) {
            $indexedCourses[$cId] = $c;
        }
    }
}

// Guarantee that EVERY active module in onboard_modules is present
foreach ($modSettings as $cId => $mSet) {
    if ((int)($mSet['is_active'] ?? 0) === 1 && !isset($indexedCourses[$cId])) {
        $indexedCourses[$cId] = [
            'id' => $cId,
            'fullname' => $mSet['title'],
            'displayname' => $mSet['title'],
            'shortname' => $mSet['title'],
            'summary' => $mSet['description'],
            'category' => 1,
            'categoryid' => 1,
            'timemodified' => time(),
            'overviewfiles' => [],
            'modules' => [],
            'scorms' => []
        ];
    }
}

// Filter out courses hidden by Admin
$coursesResponse = array_values(array_filter($indexedCourses, function($c) use ($modSettings) {
    $cId = (int)($c['id'] ?? 0);
    if (isset($modSettings[$cId]) && (int)$modSettings[$cId]['is_active'] === 0) {
        return false; // Hidden from employee
    }
    return true;
}));

// Sort strictly by Admin-configured sort_order ASC, then by id ASC
usort($coursesResponse, function($a, $b) use ($modSettings) {
    $cIdA = (int)($a['id'] ?? 0);
    $cIdB = (int)($b['id'] ?? 0);
    $orderA = isset($modSettings[$cIdA]) ? $modSettings[$cIdA]['sort_order'] : 9999;
    $orderB = isset($modSettings[$cIdB]) ? $modSettings[$cIdB]['sort_order'] : 9999;
    if ($orderA !== $orderB) {
        return $orderA <=> $orderB;
    }
    return $cIdA <=> $cIdB;
});

// Pre-fetch SCORM packages for ALL target courses in a single batch call (Performance Optimization: 1 call instead of N calls)
$allCourseIds = array_values(array_map(function($c) { return (int)$c['id']; }, $coursesResponse));
$batchScorms = [];
if (!empty($allCourseIds)) {
    $batchScormsResult = callMoodleWs('mod_scorm_get_scorms_by_courses', [
        'courseids' => $allCourseIds
    ], $conn);
    if (!empty($batchScormsResult['scorms']) && is_array($batchScormsResult['scorms'])) {
        foreach ($batchScormsResult['scorms'] as $scItem) {
            $scCourseId = (int)($scItem['course'] ?? 0);
            if ($scCourseId > 0) {
                $batchScorms[$scCourseId][] = $scItem;
            }
        }
    }
}

// Pre-fetch all existing progress for this user in 1 fast query (0.5ms) to avoid N+1 DB roundtrips
$userProgressMap = [];
if ($user_id > 0) {
    $pRes = $conn->query("SELECT module_id, status, score, progress_percent, total_time, attempts, slides_viewed, total_slides, visited_slides, is_completed, lesson_location FROM onboard_module_progress WHERE user_id = {$user_id}");
    if ($pRes) {
        while ($pr = $pRes->fetch_assoc()) {
            $userProgressMap[(int)$pr['module_id']] = $pr;
        }
    }
}

// Pre-fetch Authoritative Assessment Attempts from onboard_induction_assessment_attempts for this specific employee
$userAssessmentAttempts = [];
$assessmentAttemptCount = 0;
$assessmentLatestScore = null;
$assessmentIsLockedByAttempt = false;
$assessmentIsCompleted = false;

if ($user_id > 0) {
    try {
        $attStmt = $conn->prepare("
            SELECT id, attempt, Quiz_score, passing_score, result, is_locked, attempted_at 
            FROM onboard_induction_assessment_attempts 
            WHERE user_id = ? 
            ORDER BY attempt ASC, id ASC
        ");
        if ($attStmt) {
            $attStmt->bind_param("i", $user_id);
            $attStmt->execute();
            $attRes = $attStmt->get_result();
            while ($att = $attRes->fetch_assoc()) {
                $scoreVal = (float)$att['Quiz_score'];
                $passVal = (float)($att['passing_score'] ?: 80);
                $resVal = trim($att['result'] ?? '');
                if (empty($resVal)) {
                    $resVal = ($scoreVal >= $passVal) ? 'Passed' : 'Failed';
                }
                $statusVal = (strtolower($resVal) === 'passed' || $scoreVal >= $passVal) ? 'passed' : 'failed';
                $dateVal = !empty($att['attempted_at']) ? date('d-M-Y H:i', strtotime($att['attempted_at'])) : '—';

                $userAssessmentAttempts[] = [
                    'attempt' => (int)$att['attempt'],
                    'score' => (int)round($scoreVal),
                    'result' => $resVal,
                    'status' => $statusVal,
                    'total_time' => '—',
                    'date' => $dateVal
                ];

                $assessmentLatestScore = (int)round($scoreVal);
                if ($statusVal === 'passed') {
                    $assessmentIsCompleted = true;
                }
                if ((int)$att['is_locked'] === 1) {
                    $assessmentIsLockedByAttempt = true;
                }
            }
            $attStmt->close();
            $assessmentAttemptCount = count($userAssessmentAttempts);
        }
    } catch (\Throwable $e) {
        error_log("Notice: Unable to query onboard_induction_assessment_attempts: " . $e->getMessage());
    }
}

// Pre-load sequential locking & active admin overrides so locked future courses can skip redundant Moodle calls
$seqLocking = true;
$chkSetEarly = $conn->query("SELECT `value` FROM onboard_settings WHERE `key` = 'sequential_locking' LIMIT 1");
if ($chkSetEarly && $sRowEarly = $chkSetEarly->fetch_assoc()) {
    $seqLocking = ((string)$sRowEarly['value'] === '1' || strtolower((string)$sRowEarly['value']) === 'true');
}
$activeOverrides = [];
try {
    $chkOvrEarly = $conn->query("
        SELECT id, moodle_course_id, module_id, moodle_cmid, course_name, admin_name, reason, unlocked_at 
        FROM onboard_course_unlock_overrides 
        WHERE user_id = {$user_id} AND is_unlocked = 1
    ");
    if ($chkOvrEarly) {
        while ($oRow = $chkOvrEarly->fetch_assoc()) {
            if (!empty($oRow['moodle_course_id'])) $activeOverrides['course_' . (int)$oRow['moodle_course_id']] = $oRow;
            if (!empty($oRow['module_id'])) $activeOverrides['module_' . (int)$oRow['module_id']] = $oRow;
            if (!empty($oRow['moodle_cmid'])) $activeOverrides['cmid_' . (int)$oRow['moodle_cmid']] = $oRow;
        }
    }
} catch (\Throwable $e) {
    error_log("Notice: Unable to query onboard_course_unlock_overrides: " . $e->getMessage());
}

// 4. Enrich courses with SCORM modules and real completion tracking
$enrichedCourses = [];
$hadMoodleCourseErrors = false;
$foundFirstUncompleted = false;
$prevCourseLiveTotal = (int)($GLOBALS['moodle_api_tracker']['live_calls'] ?? 0);
foreach ($coursesResponse as $courseIdx => $course) {
    $cId = (int)$course['id'];

    // Single-course sync optimization: when sync_course_id is specified, reuse cached courses for all other courses
    if ($syncCourseId > 0 && $cId !== $syncCourseId && isset($cachedUserCoursesMap[$cId])) {
        $cachedC = $cachedUserCoursesMap[$cId];
        $enrichedCourses[] = $cachedC;
        if (empty($cachedC['completed']) && empty($cachedC['is_completed']) && ($cachedC['status'] ?? '') !== 'completed') {
            $foundFirstUncompleted = true;
        }
        continue;
    }

    $currentLiveTotal = (int)($GLOBALS['moodle_api_tracker']['live_calls'] ?? 0);
    if ($currentLiveTotal > $prevCourseLiveTotal) {
        usleep(100000); // 100ms pacing only after courses that made live Moodle calls (keeps burst <= 10/sec)
    }
    $prevCourseLiveTotal = $currentLiveTotal;
    $courseDurMin = !empty($modSettings[$cId]['duration_minutes']) ? (int)$modSettings[$cId]['duration_minutes'] : 30;
    $courseCustomDesc = !empty($modSettings[$cId]['description']) ? $modSettings[$cId]['description'] : ($course['summary'] ?? '');

    // Authoritative Admin-edited Course Name from onboard_modules
    if (!empty($modSettings[$cId]['title'])) {
        $course['fullname'] = $modSettings[$cId]['title'];
        $course['displayname'] = $modSettings[$cId]['title'];
    }
    $GLOBALS['moodle_error_context'] = [
        'course_id' => $cId,
        'course_title' => $course['fullname'] ?? ''
    ];
    if (!empty($modSettings[$cId]['description'])) {
        $course['summary'] = $modSettings[$cId]['description'];
    }
    $course['duration_minutes'] = $courseDurMin;
    $course['duration'] = $courseDurMin . ' min';
    $course['description'] = $courseCustomDesc;
    $course['scorms'] = [];
    $course['total_scorms'] = 0;
    $course['completed_scorms'] = 0;
    $course['progress_percent'] = 0;

    // Primary Course Completion Check: strictly from Moodle LMS (never override with stale local DB before checking Moodle)
    $isMoodleCourseCompleted = !empty($course['completed']) || (isset($course['progress']) && (float)$course['progress'] >= 100);

    // Use course timemodified timestamp for cache-busting image URL
    $imgTime = !empty($course['overviewfiles'][0]['timemodified'])
        ? (int)$course['overviewfiles'][0]['timemodified']
        : (int)($course['timemodified'] ?? 0);
    $courseImageFinal = $onboardApiBase . '/moodle/course_image.php?course_id=' . $cId . ($imgTime > 0 ? '&v=' . $imgTime : '');

    // Retrieve SCORMs from pre-fetched batch or fallback to targeted course call
    $scormItemsForCourse = $batchScorms[$cId] ?? null;
    if ($scormItemsForCourse === null) {
        $scormsResult = callMoodleWs('mod_scorm_get_scorms_by_courses', [
            'courseids' => [$cId]
        ], $conn);
        $scormItemsForCourse = (!empty($scormsResult['scorms']) && is_array($scormsResult['scorms'])) ? $scormsResult['scorms'] : [];
    }

    if (!empty($scormItemsForCourse)) {
        $course['total_scorms'] = count($scormItemsForCourse);
        foreach ($scormItemsForCourse as $scormItem) {
            $scormId = (int)$scormItem['id'];

                // 1) Find the actual launchable SCO ID, organization, and passing masteryscore
                $targetScoId = $scormId;
                $targetOrg = '';
                $masteryScore = null;
                $hasMasteryScore = false;
                if (isset($courseMasterMap[$cId])) {
                    $targetScoId = (int)$courseMasterMap[$cId]['scoid'];
                    $targetOrg = $courseMasterMap[$cId]['org'];
                    $scoesResult = [];
                } else {
                    $scoesResult = callMoodleWs('mod_scorm_get_scorm_scoes', [
                        'scormid' => $scormId
                    ], $conn);
                }

                if (isset($scoesResult['scoes']) && is_array($scoesResult['scoes'])) {
                    foreach ($scoesResult['scoes'] as $sco) {
                        if (!empty($sco['organization']) && empty($targetOrg)) {
                            $targetOrg = $sco['organization'];
                        }
                        if (!empty($sco['extradata']) && is_array($sco['extradata'])) {
                            foreach ($sco['extradata'] as $ex) {
                                if (($ex['element'] ?? '') === 'masteryscore' && trim((string)$ex['value']) !== '') {
                                    $masteryScore = (int)$ex['value'];
                                    $hasMasteryScore = true;
                                }
                            }
                        }
                        if (($sco['scormtype'] ?? '') === 'sco' || !empty($sco['launch'])) {
                            $targetScoId = (int)$sco['id'];
                            if (!empty($sco['organization'])) {
                                $targetOrg = $sco['organization'];
                            }
                            if (!empty($sco['extradata']) && is_array($sco['extradata'])) {
                                foreach ($sco['extradata'] as $ex) {
                                    if (($ex['element'] ?? '') === 'masteryscore' && trim((string)$ex['value']) !== '') {
                                        $masteryScore = (int)$ex['value'];
                                        $hasMasteryScore = true;
                                    }
                                }
                            }
                            break;
                        }
                    }
                }

                // Strictly determine if this SCORM is an ASSESSMENT:
                $scormName = $scormItem['name'] ?? '';
                $isAssessment = (stripos($scormName, 'assess') !== false) || (stripos($scormName, 'quiz') !== false);
                if ($isAssessment && $masteryScore === null) {
                    $masteryScore = 80; // default passing score ONLY for assessment quizzes
                }

                // Resolve matching module in onboard_modules using preloaded $modSettings (0ms)
                $targetModId = 0;
                $configuredTotalSlides = 0;
                if (!empty($modSettings[$cId]['id'])) {
                    $targetModId = (int)$modSettings[$cId]['id'];
                    $configuredTotalSlides = (int)($modSettings[$cId]['total_slides'] ?? 0);
                } else {
                    foreach ($modSettings as $msItem) {
                        if (($msItem['moodle_cmid'] > 0 && $msItem['moodle_cmid'] === $scormId) ||
                            ($msItem['moodle_scoid'] > 0 && $msItem['moodle_scoid'] === $targetScoId)) {
                            $targetModId = (int)$msItem['id'];
                            $configuredTotalSlides = (int)($msItem['total_slides'] ?? 0);
                            break;
                        }
                    }
                }

                // Authoritative slide counts for verified Storyline packages
                if ($cId === 2 || $targetModId === 5 || $targetModId === 1) {
                    if ($configuredTotalSlides <= 0 || $configuredTotalSlides === 10) $configuredTotalSlides = 18;
                } elseif ($cId === 4 || $targetModId === 7 || $targetModId === 3) {
                    if ($configuredTotalSlides <= 0 || $configuredTotalSlides === 10) $configuredTotalSlides = 18;
                } elseif ($cId === 5 || $targetModId === 8) {
                    if ($configuredTotalSlides <= 0 || $configuredTotalSlides === 10) $configuredTotalSlides = 22;
                } elseif ($cId === 6 || $targetModId === 9 || $targetModId === 4) {
                    if ($configuredTotalSlides <= 0 || $configuredTotalSlides === 10) $configuredTotalSlides = 19;
                } elseif ($cId === 3 || $targetModId === 6 || $targetModId === 2) {
                    if ($configuredTotalSlides <= 0 || $configuredTotalSlides === 10) $configuredTotalSlides = 21;
                }

                // Check pre-fetched local MySQL progress from $userProgressMap (0ms)
                $localProg = ($targetModId > 0 && isset($userProgressMap[$targetModId])) ? $userProgressMap[$targetModId] : null;
                $isLocalCompleted = false;
                $isLocalFailed = false;
                if ($localProg) {
                    $localStatus = strtolower($localProg['status'] ?? '');
                    $localScore = $localProg['score'] !== null ? (float)$localProg['score'] : null;
                    $localPassReq = $masteryScore ?? 80;

                    if ($isAssessment && $localScore !== null && $localScore < $localPassReq) {
                        $isLocalFailed = true;
                    } elseif ($isAssessment && $localStatus === 'failed') {
                        $isLocalFailed = true;
                    } elseif (!empty($localProg['is_completed']) || in_array($localStatus, ['completed', 'passed'], true) || (int)($localProg['progress_percent'] ?? 0) === 100) {
                        if (!$isAssessment || ($localScore === null || $localScore >= $localPassReq)) {
                            $isLocalCompleted = true;
                        }
                    }
                }

                $totalSlideCount = ($configuredTotalSlides > 0) ? $configuredTotalSlides : 10;
                $attemptNum = 1;
                $trackMap = [];
                $attemptHistory = [];
                $isDone = false;
                $isFailed = false;
                $rawScore = null;
                $status = 'not_started';
                $progPercent = 0;
                $curSlide = 0;
                
                $moodleAttemptVerified = false;
                $moodleZeroAttempts = false;
                $moodleTracksVerified = false;

                // Optimization: Bypass Moodle track queries if Moodle confirms completion, on sync_progress=1 for completed courses,
                // or for locked future courses behind the first uncompleted prerequisite (unless admin-unlocked or has local progress)
                $skipCompletedOnSync = (!empty($_GET['sync_progress']) && $isLocalCompleted && !$isAssessment);
                $hasAnyOverride = isset($activeOverrides['course_' . $cId]) || isset($activeOverrides['module_' . $targetModId]) || isset($activeOverrides['cmid_' . $scormId]);
                $hasRealLocalProgress = !empty($localProg) && (
                    !empty($localProg['is_completed']) ||
                    (int)($localProg['progress_percent'] ?? 0) > 0 ||
                    (int)($localProg['attempts'] ?? 0) > 0 ||
                    in_array(strtolower((string)($localProg['status'] ?? '')), ['completed', 'passed', 'in_progress', 'incomplete', 'failed'], true)
                );
                $skipLockedFutureCourse = ($seqLocking && $foundFirstUncompleted && !$hasAnyOverride && !$hasRealLocalProgress && $syncCourseId <= 0 && empty($_GET['force_refresh']) && empty($_GET['refresh_all']));
                if (($isMoodleCourseCompleted || $skipCompletedOnSync) && empty($_GET['force_refresh']) && empty($_GET['refresh_all'])) {
                    $isDone = true;
                    $status = 'completed';
                    $progPercent = 100;
                    $rawScore = $localProg['score'] !== null ? (float)$localProg['score'] : null;
                    $attemptNum = max(1, (int)($localProg['attempts'] ?? 1));
                    $curSlide = $totalSlideCount;
                    $moodleAttemptVerified = true;
                    $moodleTracksVerified = true;
                    $trackMap = [
                        'cmi.core.lesson_status' => 'completed',
                        'cmi.core.lesson_location' => (string)$curSlide,
                        'cmi.core.total_time' => $localProg['total_time'] ?? '',
                        'timemodified' => null,
                    ];
                    $course['completed_scorms']++;
                } elseif ($cId === 3) {
                    // Induction Assessment Course 3: strictly local attempts from onboard_induction_assessment_attempts, skip Moodle network calls
                    $moodleZeroAttempts = true;
                    $moodleTracksVerified = true;
                    $moodleAttemptVerified = true;
                    $attemptNum = $assessmentAttemptCount;
                    $isDone = $assessmentIsCompleted;
                    $status = $assessmentIsCompleted ? 'completed' : ($assessmentAttemptCount > 0 ? 'failed' : 'available');
                    $progPercent = $assessmentIsCompleted ? 100 : 0;
                } elseif ($skipLockedFutureCourse) {
                    // Locked behind uncompleted prerequisite with no override/progress: skip Moodle query
                    $moodleZeroAttempts = true;
                    $moodleTracksVerified = true;
                    $moodleAttemptVerified = true;
                    $attemptNum = 0;
                    $isDone = false;
                    $status = 'not_started';
                    $progPercent = 0;
                } elseif ($hadMoodleCourseErrors) {
                    // Circuit-break remaining courses if Moodle DB already failed in this request
                    $moodleAttemptVerified = false;
                    $moodleTracksVerified = false;
                } else {
                    // 2) Fetch live attempt count from Moodle LMS (Authoritative Primary Source)
                    $attemptRes = callMoodleWs('mod_scorm_get_scorm_attempt_count', [
                        'scormid' => $scormId,
                        'userid' => $moodleUserId
                    ], $conn);
                    if (!empty($attemptRes['error']) && stripos((string)($attemptRes['message'] ?? ''), 'busy') !== false) {
                        usleep(200000);
                        $attemptRes = callMoodleWs('mod_scorm_get_scorm_attempt_count', [
                            'scormid' => $scormId,
                            'userid' => $moodleUserId
                        ], $conn, 1, true);
                    }

                    $moodleAttemptVerified = (is_array($attemptRes) && empty($attemptRes['error']) && isset($attemptRes['attemptscount']));
                    if (!$moodleAttemptVerified) {
                        $hadMoodleCourseErrors = true;
                    }
                    if ($moodleAttemptVerified) {
                        $rawAttCount = (int)$attemptRes['attemptscount'];
                        if ($rawAttCount <= 0) {
                            // Moodle explicitly confirms 0 attempts: employee has never launched this SCORM!
                            $moodleZeroAttempts = true;
                            $moodleTracksVerified = true;
                            $attemptNum = 0;
                        } else {
                            $attemptNum = $rawAttCount;
                        }
                    }

                    $tracks = [];
                    if (!$moodleZeroAttempts && $moodleAttemptVerified) {
                        $tracksResult = callMoodleWs('mod_scorm_get_scorm_sco_tracks', [
                            'scoid' => $targetScoId,
                            'userid' => $moodleUserId,
                            'attempt' => max(1, $attemptNum)
                        ], $conn);
                        if (!empty($tracksResult['error']) && stripos((string)($tracksResult['message'] ?? ''), 'busy') !== false) {
                            usleep(200000);
                            $tracksResult = callMoodleWs('mod_scorm_get_scorm_sco_tracks', [
                                'scoid' => $targetScoId,
                                'userid' => $moodleUserId,
                                'attempt' => max(1, $attemptNum)
                            ], $conn, 1, true);
                        }

                        $moodleTracksVerified = ($moodleAttemptVerified && is_array($tracksResult) && empty($tracksResult['error']));
                        $tracks = $tracksResult['data']['tracks'] ?? [];
                        if (is_array($tracks)) {
                            foreach ($tracks as $t) {
                                if (isset($t['element']) && isset($t['value'])) {
                                    $trackMap[$t['element']] = $t['value'];
                                }
                            }
                        }
                    }

                    $status = $trackMap['cmi.core.lesson_status'] ?? $trackMap['status'] ?? 'not_started';
                    if (isset($trackMap['cmi.core.score.raw']) && trim((string)$trackMap['cmi.core.score.raw']) !== '') {
                        $rawScore = (float)$trackMap['cmi.core.score.raw'];
                    } elseif (isset($trackMap['score_raw']) && trim((string)$trackMap['score_raw']) !== '') {
                        $rawScore = (float)$trackMap['score_raw'];
                    }

                    $isDone = in_array(strtolower($status), ['completed', 'passed'], true);

                    // Multi-attempt evaluation: If latest attempt is not completed and user has prior attempts, check them!
                    if (!$isDone && $attemptNum > 1) {
                        for ($prevAtt = $attemptNum - 1; $prevAtt >= max(1, $attemptNum - 2); $prevAtt--) {
                            $prevResult = callMoodleWs('mod_scorm_get_scorm_sco_tracks', [
                                'scoid' => $targetScoId,
                                'userid' => $moodleUserId,
                                'attempt' => $prevAtt
                            ], $conn);
                            $prevTracks = $prevResult['data']['tracks'] ?? [];
                            $prevMap = [];
                            if (is_array($prevTracks)) {
                                foreach ($prevTracks as $pt) {
                                    if (isset($pt['element']) && isset($pt['value'])) {
                                        $prevMap[$pt['element']] = $pt['value'];
                                    }
                                }
                            }
                            $prevStat = strtolower($prevMap['cmi.core.lesson_status'] ?? $prevMap['status'] ?? '');
                            $prevScore = null;
                            if (isset($prevMap['cmi.core.score.raw']) && trim((string)$prevMap['cmi.core.score.raw']) !== '') {
                                $prevScore = (float)$prevMap['cmi.core.score.raw'];
                            } elseif (isset($prevMap['score_raw']) && trim((string)$prevMap['score_raw']) !== '') {
                                $prevScore = (float)$prevMap['score_raw'];
                            }
                            $prevDone = in_array($prevStat, ['completed', 'passed'], true) || ($isAssessment && $masteryScore !== null && $prevScore !== null && $prevScore >= $masteryScore);
                            if ($prevDone) {
                                $isDone = true;
                                $status = 'completed';
                                if ($rawScore === null && $prevScore !== null) {
                                    $rawScore = $prevScore;
                                }
                                if (!empty($prevMap['cmi.core.total_time'])) {
                                    $trackMap['cmi.core.total_time'] = $prevMap['cmi.core.total_time'];
                                }
                                if (!empty($prevMap['cmi.core.lesson_location'])) {
                                    $trackMap['cmi.core.lesson_location'] = $prevMap['cmi.core.lesson_location'];
                                }
                                break;
                            }
                        }
                    }
                    
                    if ($isDone) {
                        $course['completed_scorms']++;
                    }

                    // Only ASSESSMENTS can fail! Regular learning modules are completed, in progress, or available!
                    if ($isAssessment) {
                        if (strtolower($status) === 'failed') {
                            $isFailed = true;
                        } elseif ($masteryScore !== null && $rawScore !== null && $rawScore < $masteryScore && $status !== 'not_started') {
                            $isFailed = true;
                        }
                    }

                    // Collect Attempt History only for Assessment activities
                    if ($isAssessment) {
                        for ($att = 1; $att <= $attemptNum; $att++) {
                            $attTracks = ($att === $attemptNum) ? $tracks : [];
                            if ($att < $attemptNum) {
                                $attResult = callMoodleWs('mod_scorm_get_scorm_sco_tracks', [
                                    'scoid' => $targetScoId,
                                    'userid' => $moodleUserId,
                                    'attempt' => $att
                                ], $conn);
                                $attTracks = $attResult['data']['tracks'] ?? [];
                            }

                            $attMap = [];
                            if (is_array($attTracks)) {
                                foreach ($attTracks as $t) {
                                    if (isset($t['element']) && isset($t['value'])) {
                                        $attMap[$t['element']] = $t['value'];
                                    }
                                }
                            }

                            $attStatus = $attMap['cmi.core.lesson_status'] ?? $attMap['status'] ?? 'not_started';
                            $attScore = null;
                            if (isset($attMap['cmi.core.score.raw']) && trim((string)$attMap['cmi.core.score.raw']) !== '') {
                                $attScore = (float)$attMap['cmi.core.score.raw'];
                            } elseif (isset($attMap['score_raw']) && trim((string)$attMap['score_raw']) !== '') {
                                $attScore = (float)$attMap['score_raw'];
                            }

                            $hasRealAttemptData = (
                                (!empty($attStatus) && $attStatus !== 'not_started') ||
                                $attScore !== null ||
                                (!empty($attMap['timemodified']) && (int)$attMap['timemodified'] > 0) ||
                                (!empty($attMap['cmi.core.total_time']) && $attMap['cmi.core.total_time'] !== '00:00:00' && $attMap['cmi.core.total_time'] !== '00:00:00.00' && $attMap['cmi.core.total_time'] !== '0s')
                            );

                            if (!$hasRealAttemptData) {
                                continue;
                            }

                            $attPassed = in_array(strtolower($attStatus), ['completed', 'passed'], true) || ($attScore !== null && $masteryScore !== null && $attScore >= $masteryScore);
                            $attFailed = (strtolower($attStatus) === 'failed') || ($masteryScore !== null && $attScore !== null && $attScore < $masteryScore && $attStatus !== 'not_started');

                            $attemptHistory[] = [
                                'attempt' => $att,
                                'score' => $attScore,
                                'result' => $attPassed ? 'Passed' : ($attFailed ? 'Failed' : ($attStatus !== 'not_started' ? ucfirst($attStatus) : 'Incomplete')),
                                'is_passed' => $attPassed,
                                'is_failed' => $attFailed,
                                'status' => $attStatus,
                                'total_time' => $attMap['cmi.core.total_time'] ?? $attMap['total_time'] ?? '',
                                'timemodified' => $attMap['timemodified'] ?? null,
                                'date' => !empty($attMap['timemodified']) ? date('M j, Y, g:i A', (int)$attMap['timemodified']) : ''
                            ];
                        }
                    }

                    // Primary Authority Evaluation:
                    // Moodle LMS is authoritative whenever Moodle API responded without error.
                    // Only fall back to local MySQL is_completed when Moodle API is unreachable/rate-limited.
                    if ($moodleZeroAttempts && !$isMoodleCourseCompleted) {
                        $isDone = false;
                        $status = 'not_started';
                        $progPercent = 0;
                        $isFailed = false;
                        $curSlide = 0;
                    } elseif ($moodleTracksVerified) {
                        if ($isDone || $isMoodleCourseCompleted) {
                            $isDone = true;
                            $status = 'completed';
                            $progPercent = 100;
                            $isFailed = false;
                            $curSlide = $totalSlideCount;
                        } elseif ($isFailed) {
                            $isDone = false;
                            $status = 'failed';
                            $isFailed = true;
                        } elseif (in_array(strtolower($status), ['incomplete', 'browsed', 'suspend', 'in_progress'], true)) {
                            $isDone = false;
                            $status = 'in_progress';
                            $isFailed = false;
                        } elseif (empty($tracks) && (int)$attemptNum <= 1 && empty($trackMap['cmi.core.total_time']) && empty($trackMap['cmi.core.lesson_status'])) {
                            $isDone = false;
                            $status = 'not_started';
                            $isFailed = false;
                            $progPercent = 0;
                        }
                    } else {
                        // Moodle unreachable / throttled fallback: preserve verified local state
                        if ($isDone || $isLocalCompleted || $isMoodleCourseCompleted) {
                            $isDone = true;
                            $status = 'completed';
                            $progPercent = 100;
                            $isFailed = false;
                            $curSlide = $totalSlideCount;
                        } elseif ($isFailed || $isLocalFailed) {
                            $isDone = false;
                            $status = 'failed';
                            $isFailed = true;
                        } elseif (in_array(strtolower($status), ['incomplete', 'browsed', 'suspend', 'in_progress'], true)) {
                            $isDone = false;
                            $status = 'in_progress';
                            $isFailed = false;
                        } elseif ($localProg && in_array(strtolower($localProg['status'] ?? ''), ['in_progress', 'incomplete', 'suspend', 'browsed'], true)) {
                            $isDone = false;
                            $status = 'in_progress';
                            $isFailed = false;
                        } elseif (empty($tracks) && (int)$attemptNum <= 1 && empty($trackMap['cmi.core.total_time']) && empty($trackMap['cmi.core.lesson_status'])) {
                            $isDone = false;
                            $status = 'not_started';
                            $isFailed = false;
                            $progPercent = 0;
                        }
                    }

                    // Extract SCORM telemetry keys for slide & active progress calculation
                    $lessonLoc = trim($trackMap['cmi.core.lesson_location'] ?? $trackMap['cmi.location'] ?? $trackMap['lesson_location'] ?? '');
                    $suspendData = trim($trackMap['cmi.suspend_data'] ?? $trackMap['suspend_data'] ?? '');
                    $totalTimeStr = trim($trackMap['cmi.core.total_time'] ?? $trackMap['total_time'] ?? '');

                    // 1. Check lesson_location for numeric bookmark or slide number
                    if (!$moodleZeroAttempts && !empty($lessonLoc)) {
                        if (preg_match('/(?:page|slide|scene)?\s*(\d+)\s*[\/\-_]\s*(\d+)/i', $lessonLoc, $m)) {
                            $curSlide = (int)$m[1];
                            if ((int)$m[2] > 0) {
                                $totalSlideCount = (int)$m[2];
                            }
                        } elseif (is_numeric($lessonLoc)) {
                            $curSlide = (int)$lessonLoc;
                        } elseif (preg_match('/(?:slide|page|scene)[_\-\s]*(\d+)/i', $lessonLoc, $m)) {
                            $curSlide = (int)$m[1];
                        }
                    }

                    // 2. Check Articulate Storyline suspend_data for viewed slides bitmask
                    if (!$moodleZeroAttempts && !empty($suspendData)) {
                        if (preg_match_all('/101([0-9a-z]{1,2})1/i', $suspendData, $sm)) {
                            $storylineViewed = count(array_unique($sm[0]));
                            if ($storylineViewed > 0) {
                                $curSlide = max($curSlide, $storylineViewed);
                            }
                        }
                        if (preg_match('/viewed=([0-9,]+)/i', $suspendData, $m)) {
                            $viewedCount = count(explode(',', $m[1]));
                            if ($viewedCount > 0) {
                                $curSlide = max($curSlide, $viewedCount);
                            }
                        } elseif (preg_match('/^(\d+)~/', $suspendData, $m)) {
                            $viewedCount = (int)$m[1];
                            if ($viewedCount > 0) {
                                $curSlide = max($curSlide, $viewedCount);
                            }
                        }
                    }

                    // 3. Check verified visited_slides from track_slide.php if active attempt exists
                    if (!$moodleZeroAttempts && $localProg && !empty($localProg['visited_slides'])) {
                        $vParts = array_filter(array_map('trim', explode(',', (string)$localProg['visited_slides'])), function($x) { return is_numeric($x) && (int)$x > 0; });
                        $vCount = count(array_unique($vParts));
                        if ($vCount > $curSlide) {
                            $curSlide = $vCount;
                        }
                    }

                    // 3b. Fallback to local slides_viewed ONLY when Moodle tracks failed/unavailable
                    if (!$moodleTracksVerified && $curSlide <= 0 && $localProg && !empty($localProg['slides_viewed']) && (int)$localProg['slides_viewed'] > 0) {
                        $curSlide = (int)$localProg['slides_viewed'];
                    }

                    // 4. If in-progress or engaged (time spent > 0) but slide was not explicitly recorded, default to slide 1
                    if (!$moodleZeroAttempts && $curSlide <= 0 && ($status === 'in_progress' || !empty($totalTimeStr))) {
                        $curSlide = 1;
                    }

                    // Keep curSlide bounded between 1 and totalSlideCount
                    if ($curSlide > 0) {
                        $curSlide = min($totalSlideCount, max(1, $curSlide));
                    }

                    // Compute slideProgress % (engagement metric only — NEVER marks course completed without Moodle status)
                    $slideProgress = 0;
                    if (!$moodleZeroAttempts && $curSlide > 0 && $totalSlideCount > 0) {
                        $slideProgress = (int)round(($curSlide / $totalSlideCount) * 100);
                        $slideProgress = min(99, max(5, $slideProgress));
                    }

                    $timeSec = 0;
                    if (!empty($totalTimeStr)) {
                        $cleanTime = explode('.', $totalTimeStr)[0];
                        $tParts = explode(':', $cleanTime);
                        if (count($tParts) === 3) {
                            $timeSec = ((int)$tParts[0] * 3600) + ((int)$tParts[1] * 60) + (int)$tParts[2];
                        }
                    }

                    // Compute real progress percentage from Moodle SCORM tracks (fallback to local only if Moodle unavailable)
                    if ($status === 'not_started' || $status === 'available' || ($moodleZeroAttempts && !$isMoodleCourseCompleted)) {
                        $progPercent = 0;
                        $curSlide = 0;
                        $slideProgress = 0;
                    } elseif ($isDone) {
                        $progPercent = 100;
                        $curSlide = $totalSlideCount;
                    } elseif ($isFailed) {
                        $progPercent = ($rawScore !== null) ? (int)round($rawScore) : 0;
                    } elseif (!$moodleTracksVerified && $localProg && isset($localProg['progress_percent']) && (int)$localProg['progress_percent'] > 0) {
                        $progPercent = (int)$localProg['progress_percent'];
                        if (!empty($localProg['slides_viewed']) && (int)$localProg['slides_viewed'] > 0) {
                            $curSlide = (int)$localProg['slides_viewed'];
                        }
                        if (!empty($localProg['total_slides']) && (int)$localProg['total_slides'] > 0) {
                            $totalSlideCount = (int)$localProg['total_slides'];
                        }
                    } elseif ($slideProgress > 0 && $status === 'in_progress') {
                        if ($totalSlideCount == 17) {
                            if ($curSlide >= 14) $progPercent = 90;
                            elseif ($curSlide >= 10) $progPercent = 75;
                            elseif ($curSlide >= 7) $progPercent = 50;
                            elseif ($curSlide >= 4) $progPercent = 25;
                            else $progPercent = $slideProgress;
                        } else {
                            $progPercent = $slideProgress;
                        }
                    } elseif (isset($trackMap['cmi.progress_measure']) && is_numeric($trackMap['cmi.progress_measure'])) {
                        $progPercent = (int)round((float)$trackMap['cmi.progress_measure'] * 100);
                    } elseif ($status === 'in_progress' || $timeSec > 0) {
                        $progPercent = 5;
                    } else {
                        $progPercent = 0;
                    }

                    // Reconcile stale local DB rows whenever live Moodle verification confirms NOT completed
                    if ($targetModId > 0 && $user_id > 0 && $moodleTracksVerified && !$isDone && $localProg && (!empty($localProg['is_completed']) || strtolower($localProg['status'] ?? '') === 'completed' || (int)($localProg['progress_percent'] ?? 0) >= 100)) {
                        $recLoc = $curSlide > 0 ? "Page {$curSlide}/{$totalSlideCount}" : null;
                        $recStmt = $conn->prepare("
                            UPDATE onboard_module_progress
                            SET is_completed = 0,
                                status = ?,
                                progress_percent = ?,
                                highest_progress_percent = ?,
                                slides_viewed = ?,
                                total_slides = ?,
                                lesson_location = ?,
                                attempts = ?,
                                last_synced_at = NOW()
                            WHERE user_id = ? AND module_id = ?
                        ");
                        if ($recStmt) {
                            $recAtt = $moodleZeroAttempts ? 0 : max(1, $attemptNum);
                            $recStmt->bind_param('siiiisiii', $status, $progPercent, $progPercent, $curSlide, $totalSlideCount, $recLoc, $recAtt, $user_id, $targetModId);
                            $recStmt->execute();
                            $recStmt->close();
                        }
                    }

                    // Sync active/completed progress back into onboard_module_progress
                    if ($targetModId > 0 && $user_id > 0 && ($progPercent > 0 || $isDone)) {
                        $completedVal = $isDone ? 1 : 0;
                        $locStr = "Page {$curSlide}/{$totalSlideCount}";
                        $syncUp = $conn->prepare("
                            INSERT INTO onboard_module_progress 
                                (user_id, module_id, status, progress_percent, slides_viewed, total_slides, lesson_location, is_completed, last_synced_at)
                            VALUES 
                                (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                            ON DUPLICATE KEY UPDATE 
                                lesson_location = CASE
                                    WHEN VALUES(is_completed) = 1 OR VALUES(status) IN ('completed', 'passed')
                                        THEN CONCAT('Page ', VALUES(total_slides), '/', VALUES(total_slides))
                                    WHEN VALUES(status) = 'not_started' 
                                      OR VALUES(lesson_location) IS NULL 
                                      OR TRIM(VALUES(lesson_location)) = '' 
                                      OR VALUES(lesson_location) IN ('Page 0/10', 'Page 0/17', 'Page 0/18', 'Page 0/19', 'Page 0/21', 'Page 0/22')
                                        THEN IFNULL(onboard_module_progress.lesson_location, VALUES(lesson_location))
                                    ELSE VALUES(lesson_location)
                                END,
                                slides_viewed = CASE
                                    WHEN VALUES(is_completed) = 1 OR VALUES(status) IN ('completed', 'passed')
                                        THEN VALUES(total_slides)
                                    ELSE VALUES(slides_viewed)
                                END,
                                total_slides = VALUES(total_slides),
                                progress_percent = CASE
                                    WHEN VALUES(is_completed) = 1 OR VALUES(status) IN ('completed', 'passed') THEN 100
                                    ELSE VALUES(progress_percent)
                                END,
                                status = CASE
                                    WHEN VALUES(is_completed) = 1 OR VALUES(status) IN ('completed', 'passed') THEN 'completed'
                                    WHEN VALUES(status) = 'failed' THEN 'failed'
                                    WHEN VALUES(status) IN ('incomplete', 'in_progress', 'suspend', 'browsed') THEN 'in_progress'
                                    ELSE VALUES(status)
                                END,
                                is_completed = CASE
                                    WHEN VALUES(is_completed) = 1 OR VALUES(status) IN ('completed', 'passed') THEN 1
                                    ELSE 0
                                END,
                                last_synced_at = NOW()
                        ");
                        if ($syncUp) {
                            $syncUp->bind_param('iisiissi', $user_id, $targetModId, $status, $progPercent, $curSlide, $totalSlideCount, $locStr, $completedVal);
                            $syncUp->execute();
                            $syncUp->close();
                        }
                    }
                }

                $scormIsDone = $isDone || $isMoodleCourseCompleted;
                $course['scorms'][] = [
                    'id' => $scormId,
                    'module_id' => $targetModId,
                    'scoid' => $targetScoId,
                    'organization' => $targetOrg,
                    'name' => (!empty($modSettings[$cId]['title']) ? $modSettings[$cId]['title'] : $scormItem['name']),
                    'is_assessment' => $isAssessment,
                    'status' => $scormIsDone ? 'completed' : $status,
                    'is_completed' => $scormIsDone,
                    'is_failed' => $scormIsDone ? false : $isFailed,
                    'progress_percent' => $scormIsDone ? 100 : $progPercent,
                    'passing_score' => $isAssessment ? $masteryScore : null,
                    'total_time' => $trackMap['cmi.core.total_time'] ?? $trackMap['total_time'] ?? '',
                    'score' => $rawScore,
                    'score_max' => $trackMap['cmi.core.score.max'] ?? 100,
                    'suspend_data' => $trackMap['cmi.suspend_data'] ?? '',
                    'attempts' => $isAssessment ? count($attemptHistory) : ($status === 'not_started' ? 0 : $attemptNum),
                    'attempts_history' => $attemptHistory,
                    'timemodified' => $trackMap['timemodified'] ?? null,
                    'first_started_at' => !empty($trackMap['x.start.time']) 
                        ? date('Y-m-d H:i:s', (int)$trackMap['x.start.time']) 
                        : (!empty($attemptHistory) && !empty($attemptHistory[0]['timemodified'])
                            ? date('Y-m-d H:i:s', (int)$attemptHistory[0]['timemodified'])
                            : (!empty($trackMap['timemodified']) && !in_array($status, ['not_started', ''])
                                ? date('Y-m-d H:i:s', (int)$trackMap['timemodified'])
                                : null)),
                    'last_played_at' => !empty($trackMap['timemodified']) ? date('Y-m-d H:i:s', (int)$trackMap['timemodified']) : null,
                    'duration_minutes' => $courseDurMin,
                    'duration' => $courseDurMin . ' min',
                    'description' => $courseCustomDesc,
                    'current_slide' => $scormIsDone ? $totalSlideCount : $curSlide,
                    'total_slides' => $totalSlideCount,
                    'moodle_verified' => $moodleTracksVerified,
                    'moodle_zero_attempts' => $moodleZeroAttempts
                ];
            }
        }

        // Fetch course sections and activities via core_course_get_contents (Skip for custom assessment Course 3)
        if ($cId === 3) {
            $contentsResult = [];
        } else {
            $contentsResult = callMoodleWs('core_course_get_contents', [
                'courseid' => $cId
            ], $conn);
        }

        if (isset($contentsResult['error']) && $contentsResult['error']) {
            $moodleWarnings[] = "Sections for course {$cId}: " . ($contentsResult['message'] ?? 'Database or service exception');
            $course['sections'] = [];
            $course['section_error'] = $contentsResult['message'] ?? 'Failed to load course sections';
        } else {
            $course['sections'] = is_array($contentsResult) ? $contentsResult : [];
        }

        // Fetch authoritative activity completions only for non-SCORM courses (SCORM courses already have authoritative SCORM tracks)
        $activityCompletionMap = [];
        $hasScormModules = !empty($course['scorms']);
        if (!$hasScormModules || !empty($_GET['force_refresh'])) {
            $complResult = callMoodleWs('core_completion_get_activities_completion_status', [
                'courseid' => $cId,
                'userid' => $moodleUserId
            ], $conn);
            if (!empty($complResult['statuses']) && is_array($complResult['statuses'])) {
                foreach ($complResult['statuses'] as $st) {
                    // state: 0 = not completed, 1 = completed, 2 = pass, 3 = fail
                    $activityCompletionMap[(int)$st['cmid']] = (int)$st['state'];
                }
            }
        }

        // Extract learning modules from Moodle sections (ignoring non-learning forums, labels, chats)
        $modulesList = [];
        $modIndex = 1;
        $ignoredModnames = ['forum', 'label', 'chat', 'news', 'feedback_offline'];

        if (!empty($course['sections'])) {
            foreach ($course['sections'] as $sec) {
                $secName = !empty($sec['name']) ? $sec['name'] : 'Section';
                if (!empty($sec['modules']) && is_array($sec['modules'])) {
                    foreach ($sec['modules'] as $mod) {
                        $modname = strtolower($mod['modname'] ?? '');

                        // STRICT FILTER: Exclude forums ("Announcements") and labels from the sequential roadmap
                        if (in_array($modname, $ignoredModnames, true)) {
                            continue;
                        }

                        // Match with SCORM tracking if it's a SCORM activity
                        $scormTrack = null;
                        if ($modname === 'scorm') {
                            foreach ($course['scorms'] as $sc) {
                                if ((int)$sc['id'] === (int)$mod['instance']) {
                                    $scormTrack = $sc;
                                    break;
                                }
                            }
                        }

                        // Match with Quiz tracking if it's a native Moodle Quiz via mod_quiz_get_user_quiz_attempts
                        $quizTrack = null;
                        if ($modname === 'quiz' && !empty($mod['instance'])) {
                            $quizId = (int)$mod['instance'];
                            $qAttemptsRes = callMoodleWs('mod_quiz_get_user_quiz_attempts', [
                                'quizid' => $quizId,
                                'userid' => $moodleUserId,
                                'status' => 'all'
                            ], $conn);
                            if (!empty($qAttemptsRes['attempts']) && is_array($qAttemptsRes['attempts'])) {
                                $finishedQAttempts = array_values(array_filter($qAttemptsRes['attempts'], function($qa) {
                                    return ($qa['state'] ?? '') === 'finished';
                                }));
                                $latestQAtt = !empty($finishedQAttempts) ? end($finishedQAttempts) : null;
                                $qScore = $latestQAtt && isset($latestQAtt['sumgrades']) && $latestQAtt['sumgrades'] !== null ? round((float)$latestQAtt['sumgrades'], 1) : null;
                                $firstQAtt = reset($qAttemptsRes['attempts']);
                                $quizFirstStarted = !empty($firstQAtt['timestart']) ? date('Y-m-d H:i:s', (int)$firstQAtt['timestart']) : null;
                                $quizLastEpoch = !empty($latestQAtt['timefinish']) ? $latestQAtt['timefinish'] : (!empty($latestQAtt['timemodified']) ? $latestQAtt['timemodified'] : null);
                                $quizLastPlayed = !empty($quizLastEpoch) ? date('Y-m-d H:i:s', (int)$quizLastEpoch) : null;

                                $quizTrack = [
                                    'attempts' => count($qAttemptsRes['attempts']),
                                    'score' => $qScore,
                                    'latest_attempt' => $latestQAtt,
                                    'attempts_history' => $finishedQAttempts,
                                    'first_started_at' => $quizFirstStarted,
                                    'last_played_at' => $quizLastPlayed,
                                ];
                            }
                        }

                        $isAssess = !empty($scormTrack['is_assessment']) || $modname === 'quiz' || stripos($mod['name'], 'assess') !== false || stripos($mod['name'], 'quiz') !== false;
                        $passing = !empty($scormTrack['passing_score']) ? (int)$scormTrack['passing_score'] : 80;
                        $score = $scormTrack['score'] ?? ($quizTrack['score'] ?? null);

                        $isCompleted = false;
                        $isFailed = false;

                        // Check authoritative Moodle activity completion status and course completion
                        $actComplState = $activityCompletionMap[(int)$mod['id']] ?? (int)($mod['completiondata']['state'] ?? 0);
                        if ($isMoodleCourseCompleted || $actComplState === 1 || $actComplState === 2) {
                            $isCompleted = true;
                            $isFailed = false;
                        } elseif ($actComplState === 3) {
                            $isFailed = true;
                            $isCompleted = false;
                        } elseif ($isAssess) {
                            if ($score !== null && (float)$score < $passing) {
                                $isFailed = true;
                                $isCompleted = false;
                            } elseif ($score !== null && (float)$score >= $passing) {
                                $isCompleted = true;
                                $isFailed = false;
                            } elseif (!empty($scormTrack['is_failed']) || strtolower($scormTrack['status'] ?? '') === 'failed') {
                                $isFailed = true;
                                $isCompleted = false;
                            } elseif (!empty($scormTrack['is_completed']) || in_array(strtolower($scormTrack['status'] ?? ''), ['completed', 'passed'], true)) {
                                $isCompleted = true;
                                $isFailed = false;
                            }
                        } else {
                            if (!empty($scormTrack['is_completed']) || in_array(strtolower($scormTrack['status'] ?? ''), ['completed', 'passed'], true)) {
                                $isCompleted = true;
                            } elseif ($actComplState > 0 && !in_array(strtolower($scormTrack['status'] ?? ''), ['incomplete', 'browsed', 'suspend', 'in_progress', 'failed'], true)) {
                                $isCompleted = true;
                            }
                        }

                        $isInProgress = false;
                        if (!$isCompleted && !$isFailed && $scormTrack && (
                            in_array(strtolower($scormTrack['status']), ['incomplete', 'browsed', 'suspend', 'in_progress'], true) ||
                            (!empty($scormTrack['progress_percent']) && (int)$scormTrack['progress_percent'] > 0 && (int)$scormTrack['progress_percent'] < 100)
                        )) {
                            $isInProgress = true;
                        } elseif (!$isCompleted && !$isFailed && $quizTrack && $quizTrack['attempts'] > 0) {
                            $isInProgress = true;
                        }

                        $statusStr = $isFailed ? 'failed' : ($isCompleted ? 'completed' : ($isInProgress ? 'in_progress' : 'available'));

                        // Use our secure SSO launcher URL with explicit courseid, module_id and title to prevent CMID collision
                        $displayTitle = !empty($modSettings[$cId]['title']) ? $modSettings[$cId]['title'] : $mod['name'];
                        $launchBase = $onboardApiBase . '/moodle/launch.php?cmid=' . (int)$mod['id'];
                        if ($cId > 0) {
                            $launchBase .= '&courseid=' . (int)$cId;
                        }
                        if ($targetModId > 0) {
                            $launchBase .= '&module_id=' . (int)$targetModId;
                        }
                        if (!empty($displayTitle)) {
                            $launchBase .= '&title=' . urlencode($displayTitle);
                        }
                        if ($modname === 'scorm') {
                            $effScoId = !empty($scormTrack['scoid']) ? (int)$scormTrack['scoid'] : (!empty($modSettings[$cId]['moodle_scoid']) ? (int)$modSettings[$cId]['moodle_scoid'] : null);
                            if (!empty($effScoId)) {
                                $launchBase .= '&scoid=' . $effScoId;
                            }
                            if (!empty($scormTrack['organization'])) {
                                $launchBase .= '&currentorg=' . urlencode($scormTrack['organization']);
                            }
                        }
                        $launchUrl = $launchBase;
                        $retakeUrl = $launchBase . '&newattempt=on';
                        $reviewUrl = $launchBase . '&mode=review';
                        $modulesList[] = [
                            'id' => $targetModId > 0 ? $targetModId : $modIndex++,
                            'module_id' => $targetModId,
                            'cmid' => (int)$mod['id'],
                            'scoid' => !empty($scormTrack['scoid']) ? (int)$scormTrack['scoid'] : (!empty($modSettings[$cId]['moodle_scoid']) ? (int)$modSettings[$cId]['moodle_scoid'] : null),
                            'organization' => $scormTrack['organization'] ?? null,
                            'instance' => (int)$mod['instance'],
                            'title' => $displayTitle,
                            'section' => $secName,
                            'modname' => $modname,
                            'url' => $launchUrl,
                            'launch_url' => $launchUrl,
                            'retake_url' => $retakeUrl,
                            'review_url' => $reviewUrl,
                            'is_completed' => $isCompleted,
                            'is_failed' => $isFailed,
                            'is_in_progress' => $isInProgress,
                            'status' => $statusStr,
                            'is_assessment' => $isAssess,
                            'passing_score' => $isAssess ? $passing : null,
                            'total_time' => $scormTrack['total_time'] ?? '',
                            'score' => $score,
                            'score_max' => $scormTrack['score_max'] ?? 100,
                            'suspend_data' => $scormTrack['suspend_data'] ?? '',
                            'attempts' => $scormTrack['attempts'] ?? ($quizTrack['attempts'] ?? 0),
                            'attempts_history' => $scormTrack['attempts_history'] ?? ($quizTrack['attempts_history'] ?? []),
                            'timemodified' => $scormTrack['timemodified'] ?? null,
                            'first_started_at' => $scormTrack['first_started_at'] ?? ($quizTrack['first_started_at'] ?? null),
                            'last_played_at' => $scormTrack['last_played_at'] ?? ($quizTrack['last_played_at'] ?? null),
                            'progress_percent' => $isCompleted ? 100 : (isset($scormTrack['progress_percent']) ? (int)$scormTrack['progress_percent'] : ($isFailed ? (int)round($score ?? 0) : 0)),
                            'current_slide' => $isCompleted ? ($scormTrack['total_slides'] ?? $configuredTotalSlides ?? 10) : (($statusStr === 'available' || $statusStr === 'not_started') ? 0 : ($scormTrack['current_slide'] ?? 0)),
                            'total_slides' => $scormTrack['total_slides'] ?? $configuredTotalSlides ?? 10,
                            'duration_minutes' => $courseDurMin,
                            'duration' => $courseDurMin . ' min',
                            'description' => $courseCustomDesc,
                            'course_id' => $cId,
                            'course_fullname' => $course['fullname'],
                            'course_image' => $courseImageFinal,
                            'moodle_verified' => !empty($scormTrack['moodle_verified'])
                        ];
                    }
                }
            }
        }

        // Fallback: If sections failed or returned no learning modules, use SCORM list
        if (empty($modulesList) && !empty($course['scorms'])) {
            $scormIdx = 1;
            foreach ($course['scorms'] as $sc) {
                $scCmid = !empty($sc['coursemodule']) ? (int)$sc['coursemodule'] : (int)$sc['id'];
                $launchBase = $onboardApiBase . '/moodle/launch.php?cmid=' . $scCmid;
                if ($cId > 0) {
                    $launchBase .= '&courseid=' . (int)$cId;
                }
                if (!empty($sc['module_id'])) {
                    $launchBase .= '&module_id=' . (int)$sc['module_id'];
                }
                if (!empty($sc['name'])) {
                    $launchBase .= '&title=' . urlencode($sc['name']);
                }
                if (!empty($sc['scoid'])) {
                    $launchBase .= '&scoid=' . (int)$sc['scoid'];
                }
                if (!empty($sc['organization'])) {
                    $launchBase .= '&currentorg=' . urlencode($sc['organization']);
                }
                $launchUrl = $launchBase;
                $retakeUrl = $launchBase . '&newattempt=on';
                $reviewUrl = $launchBase . '&mode=review';

                $isAssess = !empty($sc['is_assessment']) || stripos($sc['name'], 'assess') !== false || stripos($sc['name'], 'quiz') !== false;
                $passing = !empty($sc['passing_score']) ? (int)$sc['passing_score'] : 80;
                $score = $sc['score'] ?? null;

                $isCompleted = false;
                $isFailed = false;

                if ($isMoodleCourseCompleted || !empty($sc['is_completed']) || in_array(strtolower($sc['status'] ?? ''), ['completed', 'passed'], true)) {
                    $isCompleted = true;
                    $isFailed = false;
                } elseif ($isAssess) {
                    if ($score !== null && (float)$score < $passing) {
                        $isFailed = true;
                        $isCompleted = false;
                    } elseif ($score !== null && (float)$score >= $passing) {
                        $isCompleted = true;
                        $isFailed = false;
                    } elseif (!empty($sc['is_failed']) || strtolower($sc['status'] ?? '') === 'failed') {
                        $isFailed = true;
                        $isCompleted = false;
                    }
                } else {
                    if (!empty($sc['is_completed']) || in_array(strtolower($sc['status'] ?? ''), ['completed', 'passed'], true)) {
                        $isCompleted = true;
                    }
                }

                $isInProgress = !$isCompleted && !$isFailed && (
                    in_array(strtolower($sc['status']), ['incomplete', 'browsed', 'suspend', 'in_progress'], true) ||
                    (!empty($sc['progress_percent']) && (int)$sc['progress_percent'] > 0 && (int)$sc['progress_percent'] < 100)
                );
                $statusStr = $isFailed ? 'failed' : ($isCompleted ? 'completed' : ($isInProgress ? 'in_progress' : ($sc['status'] !== 'not_started' ? 'in_progress' : 'available')));

                $modulesList[] = [
                    'id' => $scormIdx++,
                    'cmid' => $scCmid,
                    'instance' => $sc['id'],
                    'title' => $sc['name'],
                    'section' => 'Course Modules',
                    'modname' => 'scorm',
                    'url' => $launchUrl,
                    'launch_url' => $launchUrl,
                    'retake_url' => $retakeUrl,
                    'review_url' => $reviewUrl,
                    'is_completed' => $isCompleted,
                    'is_failed' => $isFailed,
                    'is_in_progress' => $isInProgress,
                    'status' => $statusStr,
                    'is_assessment' => !empty($sc['is_assessment']),
                    'passing_score' => !empty($sc['is_assessment']) ? ($sc['passing_score'] ?? 80) : null,
                    'total_time' => $sc['total_time'],
                    'score' => $sc['score'],
                    'score_max' => $sc['score_max'] ?? 100,
                    'suspend_data' => $sc['suspend_data'],
                    'attempts' => $sc['attempts'] ?? 1,
                    'attempts_history' => $sc['attempts_history'] ?? [],
                    'timemodified' => $sc['timemodified'] ?? null,
                    'progress_percent' => $isCompleted ? 100 : (isset($sc['progress_percent']) ? (int)$sc['progress_percent'] : ($isFailed ? (int)round($sc['score'] ?? 0) : 0)),
                    'module_id' => $sc['module_id'] ?? null,
                    'current_slide' => $isCompleted ? ($sc['total_slides'] ?? $configuredTotalSlides ?? 10) : (($statusStr === 'available' || $statusStr === 'not_started') ? 0 : ($sc['current_slide'] ?? 0)),
                    'total_slides' => $sc['total_slides'] ?? $configuredTotalSlides ?? 10,
                    'duration_minutes' => $courseDurMin,
                    'duration' => $courseDurMin . ' min',
                    'description' => $courseCustomDesc,
                    'course_id' => $cId,
                    'course_fullname' => $course['fullname'],
                    'course_image' => $courseImageFinal,
                    'moodle_verified' => !empty($sc['moodle_verified'])
                ];
            }
        }

        // Ultimate Fallback: If sections and SCORM APIs returned no modules, synthesize from onboard_modules & local MySQL progress
        if (empty($modulesList)) {
            $tMod = $modSettings[$cId] ?? null;
            $tModId = $tMod ? (int)$tMod['id'] : 0;
            $tCmid = $tMod ? (int)($tMod['moodle_cmid'] ?? 0) : 0;
            $tTitle = $tMod ? $tMod['title'] : ($course['fullname'] ?? "Module {$cId}");
            $tDurMin = $tMod ? (int)$tMod['duration_minutes'] : 30;
            $tTotSlides = $tMod ? (int)($tMod['total_slides'] ?? 10) : 10;
            $tPassScore = $tMod ? (int)($tMod['passing_score'] ?? 80) : 80;
            $isAssess = ($cId === 3 || stripos($tTitle, 'assess') !== false || stripos($tTitle, 'quiz') !== false);

            $locProg = ($tModId > 0 && isset($userProgressMap[$tModId])) ? $userProgressMap[$tModId] : null;

            $isDone = $isMoodleCourseCompleted || !empty($locProg['is_completed']) || in_array(strtolower($locProg['status'] ?? ''), ['completed', 'passed'], true);
            $pPct = $isDone ? 100 : (int)($locProg['progress_percent'] ?? 0);
            $stStr = $isDone ? 'completed' : ($pPct > 0 ? 'in_progress' : 'available');

            $modAttempts = (int)($locProg['attempts'] ?? 0);
            $modHistory = [];
            $modScore = $locProg['score'] ?? null;
            if ($isAssess && !empty($userAssessmentAttempts)) {
                $modHistory = $userAssessmentAttempts;
                $modAttempts = $assessmentAttemptCount;
                $modScore = $assessmentLatestScore;
                if ($assessmentIsCompleted) {
                    $isDone = true;
                    $stStr = 'completed';
                    $pPct = 100;
                } elseif ($assessmentIsLockedByAttempt) {
                    $stStr = 'locked';
                } else {
                    $stStr = 'failed';
                }
            }

            $tScoId = $tMod ? (int)($tMod['moodle_scoid'] ?? 0) : 0;
            if (!$tScoId && isset($courseMasterMap[$cId])) {
                $tScoId = (int)$courseMasterMap[$cId]['scoid'];
            }
            if (!$tCmid && isset($courseMasterMap[$cId])) {
                $tCmid = (int)$courseMasterMap[$cId]['cmid'];
            }
            $launchBase = $onboardApiBase . '/moodle/launch.php?courseid=' . $cId;
            if ($tCmid > 0) $launchBase .= '&cmid=' . $tCmid;
            if ($tModId > 0) $launchBase .= '&module_id=' . $tModId;
            if ($tScoId > 0) $launchBase .= '&scoid=' . $tScoId;
            $launchBase .= '&title=' . urlencode($tTitle);

            $modulesList[] = [
                'id' => $tModId > 0 ? $tModId : 1,
                'module_id' => $tModId,
                'cmid' => $tCmid,
                'scoid' => $tScoId > 0 ? $tScoId : null,
                'instance' => $tCmid,
                'title' => $tTitle,
                'section' => 'Course Modules',
                'modname' => 'scorm',
                'url' => $launchBase,
                'launch_url' => $launchBase,
                'retake_url' => $launchBase . '&newattempt=on',
                'review_url' => $launchBase . '&mode=review',
                'is_completed' => $isDone,
                'is_failed' => ($isAssess && !$isDone && $modAttempts > 0),
                'is_in_progress' => ($stStr === 'in_progress'),
                'status' => $stStr,
                'is_assessment' => $isAssess,
                'passing_score' => $isAssess ? $tPassScore : null,
                'total_time' => $locProg['total_time'] ?? '',
                'score' => $modScore,
                'score_max' => 100,
                'suspend_data' => '',
                'attempts' => $modAttempts,
                'attempts_history' => $modHistory,
                'timemodified' => null,
                'progress_percent' => $pPct,
                'current_slide' => $isDone ? $tTotSlides : (int)($locProg['slides_viewed'] ?? 0),
                'total_slides' => $tTotSlides,
                'duration_minutes' => $tDurMin,
                'duration' => $tDurMin . ' min',
                'description' => $tMod['description'] ?? '',
                'course_id' => $cId,
                'course_fullname' => $tTitle,
                'course_image' => $courseImageFinal
            ];
        }

        $course['course_image'] = $courseImageFinal;
        $course['modules'] = $modulesList;

        $completedCount = 0;
        $totalMods = count($course['modules']);
        if ($totalMods > 0) {
            foreach ($course['modules'] as $m) {
                if (!empty($m['is_completed']) || ($m['status'] ?? '') === 'completed') {
                    $completedCount++;
                }
            }
        }

        $isCourseAllDone = ($totalMods > 0 && $completedCount >= $totalMods) || $isMoodleCourseCompleted || ($course['total_scorms'] > 0 && $course['completed_scorms'] >= $course['total_scorms']);
        if ($isCourseAllDone) {
            $course['completed'] = true;
            $course['is_completed'] = true;
            $course['status'] = 'completed';
            $course['progress_percent'] = 100;
            $course['completed_modules_count'] = $totalMods > 0 ? $totalMods : max(1, (int)$course['total_scorms']);
            $course['total_modules_count'] = $totalMods > 0 ? $totalMods : max(1, (int)$course['total_scorms']);

            // CRITICAL: If a course is completed as per Moodle LMS, mark all modules and all slides as completed
            if (!empty($course['modules']) && is_array($course['modules'])) {
                foreach ($course['modules'] as &$mRef) {
                    $mRef['is_completed'] = true;
                    $mRef['status'] = 'completed';
                    $mRef['progress_percent'] = 100;
                    $mTot = (int)($mRef['total_slides'] ?? 10);
                    if ($mTot <= 0) $mTot = 10;
                    $mRef['current_slide'] = $mTot;
                    $mRef['total_slides'] = $mTot;
                }
                unset($mRef);
            }
        } elseif ($totalMods > 0) {
            $course['completed'] = false;
            $course['is_completed'] = false;
            $course['status'] = ($completedCount > 0) ? 'in_progress' : 'available';
            $course['progress_percent'] = round(($completedCount / $totalMods) * 100);
            $course['completed_modules_count'] = $completedCount;
            $course['total_modules_count'] = $totalMods;
        } elseif ($course['total_scorms'] > 0) {
            $course['completed'] = ($course['completed_scorms'] >= $course['total_scorms']);
            $course['is_completed'] = $course['completed'];
            $course['status'] = $course['completed'] ? 'completed' : (($course['completed_scorms'] > 0) ? 'in_progress' : 'available');
            $course['progress_percent'] = round(($course['completed_scorms'] / $course['total_scorms']) * 100);
            $course['completed_modules_count'] = $course['completed_scorms'];
            $course['total_modules_count'] = $course['total_scorms'];
        } else {
            $course['completed'] = !empty($course['completed']);
            $course['is_completed'] = $course['completed'];
            $course['status'] = $course['completed'] ? 'completed' : 'available';
            $course['progress_percent'] = $course['completed'] ? 100 : 0;
            $course['completed_modules_count'] = $course['completed'] ? 1 : 0;
            $course['total_modules_count'] = 1;
        }

        if (!$isCourseAllDone) {
            $foundFirstUncompleted = true;
        }
        $enrichedCourses[] = $course;
    }
    unset($GLOBALS['moodle_error_context']);

    // Persist live tracking, monotonic progress, slide checkpoints, and attempt history into local MySQL
    persistMoodleTrackingToLocalSql($conn, $user_id, $enrichedCourses);

    // Guaranteed direct sync into onboard_module_progress for all courses and modules
    $syncAudit = [];
    foreach ($enrichedCourses as $encCourse) {
        $cMoodleId = (int)($encCourse['id'] ?? 0);
        $isCourseDone = !empty($encCourse['is_completed']) || !empty($encCourse['completed']) || ($encCourse['status'] ?? '') === 'completed';
        if (empty($encCourse['modules']) || !is_array($encCourse['modules'])) {
            continue;
        }
        foreach ($encCourse['modules'] as $encMod) {
            $encModVerified = !empty($encMod['moodle_verified']);
            $cTotSlides = (int)($encMod['total_slides'] ?? 10);
            $cDone = (!empty($encMod['is_completed']) || $isCourseDone || ($encMod['status'] ?? '') === 'completed') ? 1 : 0;
            $cCurSlide = $cDone ? $cTotSlides : (int)($encMod['current_slide'] ?? 0);
            $cPct = $cDone ? 100 : (int)($encMod['progress_percent'] ?? 0);
            $cStatus = $cDone ? 'completed' : ($encMod['status'] ?? 'available');
            $cLoc = $cCurSlide > 0 ? "Page {$cCurSlide}/{$cTotSlides}" : '';
            $cModTitle = trim($encMod['title'] ?? '');
            $cCmid = (int)($encMod['cmid'] ?? 0);

            // 1. Resolve module id from onboard_modules
            $mId = 0;
            $escTitle = $conn->real_escape_string($cModTitle);
            $titleCond = $escTitle !== '' ? "OR title LIKE '%{$escTitle}%'" : "";
            $chkM = $conn->query("
                SELECT id FROM onboard_modules 
                WHERE (moodle_course_id = {$cMoodleId} AND moodle_course_id > 0)
                   OR (moodle_cmid = {$cCmid} AND moodle_cmid > 0)
                   {$titleCond}
                ORDER BY (moodle_course_id = {$cMoodleId}) DESC, (moodle_cmid = {$cCmid}) DESC
                LIMIT 1
            ");
            if ($chkM && $mRow = $chkM->fetch_assoc()) {
                $mId = (int)$mRow['id'];
                // Self-healing: Automatically populate missing moodle_cmid and moodle_scoid in onboard_modules
                if ($cCmid > 0) {
                    $conn->query("UPDATE onboard_modules SET moodle_cmid = {$cCmid} WHERE id = {$mId} AND (moodle_cmid IS NULL OR moodle_cmid = 0)");
                }
                $cModScoId = (int)($encMod['scoid'] ?? 0);
                if ($cModScoId > 0) {
                    $conn->query("UPDATE onboard_modules SET moodle_scoid = {$cModScoId} WHERE id = {$mId} AND (moodle_scoid IS NULL OR moodle_scoid = 0)");
                }
            }

            if ($mId > 0 && $user_id > 0) {
                if ($encModVerified && $cDone === 0 && in_array($cStatus, ['not_started', 'available'], true) && $cPct === 0) {
                    // Authoritative Moodle 0-attempt / not_started: clear any stale false completion in DB without inserting blank rows
                    $okSync = $conn->query("
                        UPDATE onboard_module_progress
                        SET status = 'not_started',
                            is_completed = 0,
                            progress_percent = 0,
                            highest_progress_percent = 0,
                            slides_viewed = 0,
                            total_slides = {$cTotSlides},
                            lesson_location = NULL,
                            attempts = 0,
                            last_synced_at = NOW()
                        WHERE user_id = {$user_id} AND module_id = {$mId}
                          AND (is_completed = 1 OR status = 'completed' OR progress_percent > 0)
                    ");
                } elseif ($encModVerified) {
                    // Live Moodle verified: Moodle's active/completed state is authoritative
                    $escStatus = $conn->real_escape_string($cStatus === 'available' ? 'not_started' : $cStatus);
                    $locSqlVal = $cLoc !== '' ? "'" . $conn->real_escape_string($cLoc) . "'" : "NULL";
                    $syncSql = "
                        INSERT INTO onboard_module_progress 
                            (user_id, module_id, status, progress_percent, highest_progress_percent, slides_viewed, total_slides, lesson_location, is_completed, last_synced_at)
                        VALUES 
                            ({$user_id}, {$mId}, '{$escStatus}', {$cPct}, {$cPct}, {$cCurSlide}, {$cTotSlides}, {$locSqlVal}, {$cDone}, NOW())
                        ON DUPLICATE KEY UPDATE 
                            slides_viewed = VALUES(slides_viewed),
                            total_slides = VALUES(total_slides),
                            lesson_location = CASE
                                WHEN VALUES(is_completed) = 1 OR VALUES(status) IN ('completed', 'passed')
                                    THEN CONCAT('Page ', VALUES(total_slides), '/', VALUES(total_slides))
                                ELSE VALUES(lesson_location)
                            END,
                            progress_percent = VALUES(progress_percent),
                            highest_progress_percent = CASE
                                WHEN VALUES(is_completed) = 1 THEN 100
                                ELSE VALUES(progress_percent)
                            END,
                            status = VALUES(status),
                            is_completed = VALUES(is_completed),
                            last_synced_at = NOW()
                    ";
                    $okSync = $conn->query($syncSql);
                } else {
                    // Moodle unreachable fallback: preserve existing monotonic local progress
                    $escStatus = $conn->real_escape_string($cStatus === 'available' ? 'not_started' : $cStatus);
                    $escLoc = $conn->real_escape_string($cLoc !== '' ? $cLoc : "Page 0/{$cTotSlides}");
                    $syncSql = "
                        INSERT INTO onboard_module_progress 
                            (user_id, module_id, status, progress_percent, slides_viewed, total_slides, lesson_location, is_completed, last_synced_at)
                        VALUES 
                            ({$user_id}, {$mId}, '{$escStatus}', {$cPct}, {$cCurSlide}, {$cTotSlides}, '{$escLoc}', {$cDone}, NOW())
                        ON DUPLICATE KEY UPDATE 
                            slides_viewed = CASE
                                WHEN VALUES(is_completed) = 1 OR VALUES(status) IN ('completed', 'passed') OR IFNULL(onboard_module_progress.is_completed, 0) = 1
                                    THEN VALUES(total_slides)
                                WHEN VALUES(status) = 'not_started' AND VALUES(is_completed) = 0 
                                    THEN IFNULL(onboard_module_progress.slides_viewed, 0)
                                ELSE GREATEST(IFNULL(onboard_module_progress.slides_viewed, 0), VALUES(slides_viewed))
                            END,
                            total_slides = VALUES(total_slides),
                            lesson_location = CASE
                                WHEN VALUES(is_completed) = 1 OR VALUES(status) IN ('completed', 'passed') OR IFNULL(onboard_module_progress.is_completed, 0) = 1
                                    THEN CONCAT('Page ', VALUES(total_slides), '/', VALUES(total_slides))
                                WHEN VALUES(status) = 'not_started' OR VALUES(lesson_location) IS NULL OR TRIM(VALUES(lesson_location)) = ''
                                    THEN IFNULL(onboard_module_progress.lesson_location, VALUES(lesson_location))
                                WHEN VALUES(slides_viewed) >= IFNULL(onboard_module_progress.slides_viewed, 0)
                                    THEN VALUES(lesson_location)
                                ELSE IFNULL(onboard_module_progress.lesson_location, VALUES(lesson_location))
                            END,
                            progress_percent = CASE
                                WHEN IFNULL(onboard_module_progress.is_completed, 0) = 1 THEN 100
                                WHEN VALUES(is_completed) = 1 OR VALUES(status) IN ('completed', 'passed') THEN 100
                                WHEN VALUES(status) IN ('incomplete', 'in_progress', 'suspend', 'browsed') THEN GREATEST(IFNULL(onboard_module_progress.progress_percent, 0), VALUES(progress_percent))
                                WHEN VALUES(status) = 'not_started' AND VALUES(is_completed) = 0 THEN IFNULL(onboard_module_progress.progress_percent, 0)
                                ELSE GREATEST(IFNULL(onboard_module_progress.progress_percent, 0), VALUES(progress_percent))
                            END,
                            status = CASE
                                WHEN IFNULL(onboard_module_progress.is_completed, 0) = 1 THEN 'completed'
                                WHEN VALUES(is_completed) = 1 OR VALUES(status) IN ('completed', 'passed') THEN 'completed'
                                WHEN VALUES(status) = 'failed' THEN 'failed'
                                WHEN VALUES(status) IN ('incomplete', 'in_progress', 'suspend', 'browsed') THEN 'in_progress'
                                WHEN IFNULL(onboard_module_progress.status, '') IN ('completed', 'passed') THEN 'completed'
                                WHEN IFNULL(onboard_module_progress.status, '') = 'in_progress' THEN 'in_progress'
                                ELSE VALUES(status)
                            END,
                            is_completed = CASE
                                WHEN IFNULL(onboard_module_progress.is_completed, 0) = 1 THEN 1
                                WHEN VALUES(is_completed) = 1 OR VALUES(status) IN ('completed', 'passed') THEN 1
                                WHEN IFNULL(onboard_module_progress.status, '') IN ('completed', 'passed') THEN 1
                                ELSE 0
                            END,
                            last_synced_at = NOW()
                    ";
                    $okSync = $conn->query($syncSql);
                }
                $syncAudit[] = [
                    'module_id' => $mId,
                    'slide' => $cCurSlide,
                    'total' => $cTotSlides,
                    'percent' => $cPct,
                    'success' => (bool)$okSync,
                    'error' => $okSync ? null : $conn->error
                ];
            }
        }
    }

// 4. Update last_synced_at timestamp in onboard_moodle_users
$stmt = $conn->prepare("UPDATE onboard_moodle_users SET last_synced_at = CURRENT_TIMESTAMP WHERE hexa_uid = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->close();

// 5. Optimize and prune payload: Remove massive SCORM suspend_data and raw internal Moodle structures
// Drops response size by 90%+ without breaking any frontend workflows
$cleanCourses = [];
foreach ($enrichedCourses as $c) {
    $isDoneCourse = !empty($c['completed']) || !empty($c['is_completed']) || ($c['status'] ?? '') === 'completed' || ((int)($c['progress_percent'] ?? 0) === 100);
    $cleanModules = [];
    if (!empty($c['modules']) && is_array($c['modules'])) {
        foreach ($c['modules'] as $m) {
            unset($m['suspend_data']);
            if ($isDoneCourse) {
                $m['is_completed'] = true;
                $m['status'] = 'completed';
                $m['progress_percent'] = 100;
                $mTot = (int)($m['total_slides'] ?? 10);
                if ($mTot <= 0) $mTot = 10;
                $m['current_slide'] = $mTot;
                $m['total_slides'] = $mTot;
            }
            if (((int)($c['id'] ?? 0) === 3 || !empty($m['is_assessment'])) && !empty($userAssessmentAttempts)) {
                $m['attempts'] = $assessmentAttemptCount;
                $m['attempts_history'] = $userAssessmentAttempts;
                $m['score'] = $assessmentLatestScore;
                if ($assessmentIsCompleted) {
                    $m['status'] = 'completed';
                    $m['is_completed'] = true;
                    $m['progress_percent'] = 100;
                } elseif ($assessmentIsLockedByAttempt) {
                    $m['status'] = 'locked';
                    $m['is_locked'] = true;
                } else {
                    $m['status'] = 'failed';
                }
            }
            $cleanModules[] = $m;
        }
    }

    $cleanScorms = [];
    if (!empty($c['scorms']) && is_array($c['scorms'])) {
        foreach ($c['scorms'] as $sc) {
            unset($sc['suspend_data']);
            if ($isDoneCourse) {
                $sc['is_completed'] = true;
                $sc['status'] = 'completed';
                $sc['progress_percent'] = 100;
                $scTot = (int)($sc['total_slides'] ?? 10);
                if ($scTot <= 0) $scTot = 10;
                $sc['current_slide'] = $scTot;
                $sc['total_slides'] = $scTot;
            }
            if (((int)($c['id'] ?? 0) === 3 || !empty($sc['is_assessment'])) && !empty($userAssessmentAttempts)) {
                $sc['attempts'] = $assessmentAttemptCount;
                $sc['attempts_history'] = $userAssessmentAttempts;
                $sc['score'] = $assessmentLatestScore;
                if ($assessmentIsCompleted) {
                    $sc['status'] = 'completed';
                    $sc['is_completed'] = true;
                    $sc['progress_percent'] = 100;
                } elseif ($assessmentIsLockedByAttempt) {
                    $sc['status'] = 'locked';
                    $sc['is_locked'] = true;
                } else {
                    $sc['status'] = 'failed';
                }
            }
            $cleanScorms[] = $sc;
        }
    }

    // Guarantee that Course 3 always contains its authoritative assessment module with attempt history
    if ((int)($c['id'] ?? 0) === 3 && empty($cleanModules) && empty($cleanScorms)) {
        $cleanModules[] = [
            'id' => 6,
            'module_id' => 6,
            'cmid' => 4,
            'scoid' => 32,
            'instance' => 2,
            'title' => 'Employee Assessment',
            'section' => 'Course Modules',
            'modname' => 'scorm',
            'url' => $onboardApiBase . '/moodle/launch.php?cmid=4&courseid=3&scoid=32',
            'launch_url' => $onboardApiBase . '/moodle/launch.php?cmid=4&courseid=3&scoid=32',
            'retake_url' => $onboardApiBase . '/moodle/launch.php?cmid=4&courseid=3&scoid=32&newattempt=on',
            'review_url' => $onboardApiBase . '/moodle/launch.php?cmid=4&courseid=3&scoid=32&mode=review',
            'is_completed' => $assessmentIsCompleted,
            'is_failed' => !$assessmentIsCompleted && $assessmentAttemptCount > 0,
            'is_in_progress' => false,
            'status' => $assessmentIsCompleted ? 'completed' : ($assessmentAttemptCount > 0 ? 'failed' : 'available'),
            'is_assessment' => true,
            'passing_score' => 80,
            'total_time' => '—',
            'score' => $assessmentLatestScore,
            'score_max' => 100,
            'attempts' => $assessmentAttemptCount,
            'attempts_history' => $userAssessmentAttempts,
            'timemodified' => null,
            'progress_percent' => $assessmentIsCompleted ? 100 : 0,
            'current_slide' => $assessmentIsCompleted ? 10 : 0,
            'total_slides' => 10,
            'duration_minutes' => 20,
            'duration' => '20 min',
            'description' => 'Employee Assessment Quiz',
            'course_id' => 3,
            'course_fullname' => 'Employee Assessment',
            'course_image' => $courseImageFinal ?? ''
        ];
    }

    $totModsCount = (int)($c['total_modules_count'] ?? count($cleanModules));
    $compModsCount = $isDoneCourse ? $totModsCount : (int)($c['completed_modules_count'] ?? 0);

    $cleanCourses[] = [
        'id' => (int)($c['id'] ?? 0),
        'shortname' => $c['shortname'] ?? '',
        'fullname' => $c['fullname'] ?? '',
        'displayname' => $c['displayname'] ?? ($c['fullname'] ?? ''),
        'summary' => $c['summary'] ?? '',
        'course_image' => $c['course_image'] ?? '',
        'progress_percent' => $isDoneCourse ? 100 : (int)($c['progress_percent'] ?? 0),
        'completed' => $isDoneCourse,
        'is_completed' => $isDoneCourse,
        'status' => $isDoneCourse ? 'completed' : ($c['status'] ?? 'available'),
        'completed_modules_count' => $compModsCount,
        'total_modules_count' => $totModsCount,
        'total_scorms' => (int)($c['total_scorms'] ?? 0),
        'completed_scorms' => $isDoneCourse ? (int)($c['total_scorms'] ?? count($cleanScorms)) : (int)($c['completed_scorms'] ?? 0),
        'scorms' => empty($cleanModules) ? $cleanScorms : [],
        'modules' => $cleanModules
    ];
}

// 4. Evaluate Sequential Locking rule configured by Admin in onboard_settings
$seqLocking = true; // Default sequential progression to TRUE as core LMS business rule
$chkSet = $conn->query("SELECT `value` FROM onboard_settings WHERE `key` = 'sequential_locking' LIMIT 1");
if ($chkSet && $sRow = $chkSet->fetch_assoc()) {
    $seqLocking = ((string)$sRow['value'] === '1' || strtolower((string)$sRow['value']) === 'true');
}

if ($seqLocking) {
    $allPrevCompleted = true; // Course 1 is always unlocked initially
    $prevTitle = '';

    foreach ($cleanCourses as &$cRef) {
        $cIsDone = !empty($cRef['completed']) || ((int)($cRef['progress_percent'] ?? 0) === 100);

        if ($cIsDone) {
            $cRef['is_locked'] = true;
            $cRef['locked_reason'] = "Once you complete a course, you cannot launch it again without your manager's approval. If you wish to relaunch the course, please contact your manager for authorization.";
        } elseif ($allPrevCompleted) {
            $cRef['is_locked'] = false;
            $cRef['locked_reason'] = null;
            $allPrevCompleted = false; // Strictly lock all subsequent courses in the curriculum
        } else {
            $cRef['is_locked'] = true;
            $cRef['locked_reason'] = "Complete '{$prevTitle}' to unlock this course";
        }

        if (!empty($cRef['modules']) && is_array($cRef['modules'])) {
            foreach ($cRef['modules'] as &$mRef) {
                $mRef['is_locked'] = $cRef['is_locked'];
                $mRef['locked_reason'] = $cRef['locked_reason'];
            }
            unset($mRef);
        }
        if (!empty($cRef['scorms']) && is_array($cRef['scorms'])) {
            foreach ($cRef['scorms'] as &$scRef) {
                $scRef['is_locked'] = $cRef['is_locked'];
                $scRef['locked_reason'] = $cRef['locked_reason'];
            }
            unset($scRef);
        }

        if (!$cIsDone) {
            $allPrevCompleted = false;
        }
        $prevTitle = $cRef['displayname'] ?: ($cRef['fullname'] ?: 'previous module');
    }
    unset($cRef);
} else {
    // Open mode: All courses unlocked
    foreach ($cleanCourses as &$cRef) {
        $cRef['is_locked'] = false;
        $cRef['locked_reason'] = null;
        if (!empty($cRef['modules']) && is_array($cRef['modules'])) {
            foreach ($cRef['modules'] as &$mRef) {
                $mRef['is_locked'] = false;
                $mRef['locked_reason'] = null;
            }
            unset($mRef);
        }
        if (!empty($cRef['scorms']) && is_array($cRef['scorms'])) {
            foreach ($cRef['scorms'] as &$scRef) {
                $scRef['is_locked'] = false;
                $scRef['locked_reason'] = null;
            }
            unset($scRef);
        }
    }
    unset($cRef);
}

// 4.5 Apply Individual Employee Admin Overrides
$activeOverrides = [];
try {
    $chkOvr = $conn->query("
        SELECT id, moodle_course_id, module_id, moodle_cmid, course_name, admin_name, reason, unlocked_at 
        FROM onboard_course_unlock_overrides 
        WHERE user_id = {$user_id} AND is_unlocked = 1
    ");
    if ($chkOvr) {
        while ($oRow = $chkOvr->fetch_assoc()) {
            if (!empty($oRow['moodle_course_id'])) $activeOverrides['course_' . (int)$oRow['moodle_course_id']] = $oRow;
            if (!empty($oRow['module_id'])) $activeOverrides['module_' . (int)$oRow['module_id']] = $oRow;
            if (!empty($oRow['moodle_cmid'])) $activeOverrides['cmid_' . (int)$oRow['moodle_cmid']] = $oRow;
        }
    }
} catch (\Throwable $e) {
    // Ignore if table does not exist or has schema variance
    error_log("Notice: Unable to query onboard_course_unlock_overrides: " . $e->getMessage());
}

if (!empty($activeOverrides)) {
    foreach ($cleanCourses as &$cRef) {
        $cIdNum = (int)($cRef['id'] ?? 0);
        $hasCourseOverride = isset($activeOverrides['course_' . $cIdNum]);

        if (!empty($cRef['modules']) && is_array($cRef['modules'])) {
            foreach ($cRef['modules'] as &$mRef) {
                $mIdNum = (int)($mRef['id'] ?? ($mRef['module_id'] ?? 0));
                $cmIdNum = (int)($mRef['cmid'] ?? 0);
                if ($hasCourseOverride || isset($activeOverrides['module_' . $mIdNum]) || isset($activeOverrides['cmid_' . $cmIdNum])) {
                    $mRef['is_locked'] = false;
                    $mRef['admin_unlocked'] = true;
                    $mRef['locked_reason'] = null;
                    $hasCourseOverride = true;
                }
            }
            unset($mRef);
        }

        if (!empty($cRef['scorms']) && is_array($cRef['scorms'])) {
            foreach ($cRef['scorms'] as &$scRef) {
                $scIdNum = (int)($scRef['id'] ?? 0);
                $scModId = (int)($scRef['module_id'] ?? 0);
                if ($hasCourseOverride || isset($activeOverrides['module_' . $scModId]) || isset($activeOverrides['cmid_' . $scIdNum])) {
                    $scRef['is_locked'] = false;
                    $scRef['admin_unlocked'] = true;
                    $scRef['locked_reason'] = null;
                    $hasCourseOverride = true;
                }
            }
            unset($scRef);
        }

        if ($hasCourseOverride) {
            $cRef['is_locked'] = false;
            $cRef['admin_unlocked'] = true;
            $cRef['locked_reason'] = null;
        }
    }
    unset($cRef);
}

// 5. 7-Day Access Expiry: Disabled per requirements (continuous sequential access)
$isAdmin = in_array((string)$user_id, ['26', '64'], true) || (string)($user['user_role'] ?? '') === '1';
$daysElapsed = 0;
$isExpired = false;

// Only cache if cleanCourses has all required active modules, preventing truncated session cache
$minRequiredCount = count($activeRequiredCourseIds);
if (!empty($cleanCourses) && ($minRequiredCount === 0 || count($cleanCourses) >= $minRequiredCount)) {
    // Reopen session cleanly to persist course cache without holding locks during REST loop
    // Strictly verify session is active, or safely reopened before headers are sent, and always release lock
    $cacheSaved = false;
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION[$cacheKey] = $cleanCourses;
        session_write_close();
        $cacheSaved = true;
    } elseif (!headers_sent() && session_status() !== PHP_SESSION_ACTIVE) {
        if (@session_start()) {
            $_SESSION[$cacheKey] = $cleanCourses;
            session_write_close();
            $cacheSaved = true;
        }
    }
}

// Flush per-request Moodle REST API usage summary before closing DB connection
$usageScenario = !empty($_GET['refresh_all'])
    ? 'refresh_all'
    : (!empty($_GET['force_refresh'])
        ? 'force_refresh'
        : ($syncCourseId > 0
            ? 'sync_single_course'
            : (!empty($_GET['sync_progress']) ? 'sync_progress' : 'cold_load')));
flushMoodleApiUsage($conn, $usageScenario, $user_id, $moodleUserId);

$deadlineInfo = null;
if (isset($conn) && $conn instanceof mysqli) {
    try {
        $deadlineInfo = getEmployeeDeadlineStatus((int)$user_id, $conn);
    } catch (\Throwable $e) {
        error_log("Notice: Error in deadline evaluation: " . $e->getMessage());
    }
}

$allowCertDownload = false;
if (isset($conn) && $conn instanceof mysqli && function_exists('isEmployeeCertificateDownloadAllowed')) {
    try {
        $allowCertDownload = isEmployeeCertificateDownloadAllowed($conn);
    } catch (\Throwable $e) {
        $allowCertDownload = false;
    }
}

// Explicitly close the local database connection now that all reads and writes are complete
if (isset($conn) && $conn instanceof mysqli) {
    @$conn->close();
}

$responsePayload = json_encode([
    'success' => true,
    'linked' => true,
    'access_expired' => $isExpired,
    'deadline_info' => $deadlineInfo,
    'allow_employee_certificate_download' => $allowCertDownload,
    'days_elapsed' => $daysElapsed,
    'days_remaining' => max(0, 7 - $daysElapsed),
    'sequential_locking' => $seqLocking,
    'cached_at' => time(),
    'employee' => [
        'uid' => (int)$user['uid'],
        'username' => $user['username'],
        'name' => trim($user['firstname'] . ' ' . $user['lastname']),
        'moodle_user_id' => $moodleUserId,
    ],
    'db_sync' => $syncAudit ?? [],
    'courses' => $cleanCourses
], JSON_UNESCAPED_SLASHES);

// Persist per-user response cache atomically when all required courses are present and no Moodle DB errors occurred
if (!$hadMoodleCourseErrors && $responsePayload !== false && !empty($cleanCourses) && ($minRequiredCount === 0 || count($cleanCourses) >= $minRequiredCount)) {
    if (!is_dir($userCacheDir)) {
        @mkdir($userCacheDir, 0755, true);
    }
    $tmpUserCache = $userCacheDir . '/uc_' . uniqid('', true) . '.tmp';
    if (@file_put_contents($tmpUserCache, $responsePayload, LOCK_EX) !== false) {
        if (!@rename($tmpUserCache, $userCacheFile)) {
            @unlink($tmpUserCache);
            @file_put_contents($userCacheFile, $responsePayload, LOCK_EX);
        }
    }
}

if (isset($userLockHandle) && $userLockHandle) {
    @flock($userLockHandle, LOCK_UN);
    @fclose($userLockHandle);
}

echo $responsePayload;

