<?php
// Endpoint: api/admin/course_access.php
// Admin Course Access Management & Individual Employee Unlock Override System

date_default_timezone_set('Asia/Kolkata');

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

$NEED_PDO = false;
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../moodle/client.php';

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
$adminName = 'Administrator';
if (in_array((string)$currentUserId, ['26', '64', '205'], true)) {
    $isAdmin = true;
}

$roleCheck = $conn->prepare("SELECT user_role, firstname, lastname FROM user WHERE uid = ?");
if ($roleCheck) {
    $roleCheck->bind_param('i', $currentUserId);
    $roleCheck->execute();
    $roleRes = $roleCheck->get_result()->fetch_assoc();
    if ($roleRes) {
        if (($roleRes['user_role'] ?? 0) == 1) {
            $isAdmin = true;
        }
        $adminName = trim(($roleRes['firstname'] ?? '') . ' ' . ($roleRes['lastname'] ?? '')) ?: 'Admin #' . $currentUserId;
    }
    $roleCheck->close();
}

if (!$isAdmin) {
    http_response_code(403);
echo json_encode(['success' => false, 'message' => 'Unauthorized: Admin access required.']);
    exit;
}

// Release session lock early to prevent blocking concurrent API requests
session_write_close();

// 2. Handle POST Requests (Unlock, Revoke, Extend Access)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    $action = strtolower(trim($input['action'] ?? 'unlock'));
    $targetUid = isset($input['uid']) ? (int)$input['uid'] : 0;

    if ($targetUid <= 0) {
        http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Missing target employee uid parameter.']);
        exit;
    }

    $invalidateEmployeeCourseCache = function($uid) {
        // Never unlink cache to prevent 30s FastCGI timeouts. In-place sync ensures instant loading.
    };

    // Action 1: Unlock Course for this specific employee
    if ($action === 'unlock') {
        $courseId = isset($input['course_id']) ? (int)$input['course_id'] : 0;
        $moduleId = isset($input['module_id']) ? (int)$input['module_id'] : 0;
        $cmid = isset($input['cmid']) ? (int)$input['cmid'] : 0;
        $courseName = trim($input['course_name'] ?? '');
        $reason = trim($input['reason'] ?? '');

        if (empty($reason)) {
            $reason = 'Admin override authorized for course relaunch / unlock.';
        }

        if ($courseId <= 0 && $moduleId <= 0 && $cmid <= 0) {
            http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Please specify a course_id, module_id, or cmid to unlock.']);
            exit;
        }

        // Deactivate previous active overrides for this specific course & employee
        $deact = $conn->prepare("
            UPDATE onboard_course_unlock_overrides 
            SET is_unlocked = 0, revoked_at = NOW() 
            WHERE user_id = ? 
              AND (
                (? > 0 AND moodle_course_id = ?) OR 
                (? > 0 AND module_id = ?) OR 
                (? > 0 AND moodle_cmid = ?)
              )
              AND is_unlocked = 1
        ");
        if ($deact) {
            $deact->bind_param('iiiiiii', $targetUid, $courseId, $courseId, $moduleId, $moduleId, $cmid, $cmid);
            $deact->execute();
            $deact->close();
        }

        // Insert new audit record with admin, employee, course, date and reason
        $ins = $conn->prepare("
            INSERT INTO onboard_course_unlock_overrides 
                (user_id, moodle_course_id, module_id, moodle_cmid, course_name, admin_id, admin_name, reason, is_unlocked, unlocked_at) 
            VALUES 
                (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
        ");
        if ($ins) {
            $cIdParam = $courseId > 0 ? $courseId : null;
            $mIdParam = $moduleId > 0 ? $moduleId : null;
            $cmidParam = $cmid > 0 ? $cmid : null;
            $ins->bind_param('iiiisiss', $targetUid, $cIdParam, $mIdParam, $cmidParam, $courseName, $currentUserId, $adminName, $reason);
            $ok = $ins->execute();
            $ins->close();

            if ($ok) {
                $invalidateEmployeeCourseCache($targetUid);
echo json_encode([
                    'success' => true,
                    'message' => "Successfully unlocked '{$courseName}' for this employee.",
                    'unlocked_course' => [
                        'user_id' => $targetUid,
                        'course_id' => $courseId,
                        'course_name' => $courseName,
                        'admin_name' => $adminName,
                        'reason' => $reason,
                        'unlocked_at' => date('Y-m-d H:i:s')
                    ]
                ]);
                exit;
            } else {
                http_response_code(500);
echo json_encode(['success' => false, 'message' => 'Database error recording unlock override: ' . $conn->error]);
                exit;
            }
        }
    }

    // Action 2: Revoke Unlock (Re-lock course)
    if ($action === 'revoke' || $action === 'lock') {
        $overrideId = isset($input['override_id']) ? (int)$input['override_id'] : 0;
        $courseId = isset($input['course_id']) ? (int)$input['course_id'] : 0;
        $moduleId = isset($input['module_id']) ? (int)$input['module_id'] : 0;
        $cmid = isset($input['cmid']) ? (int)$input['cmid'] : 0;

        if ($overrideId > 0) {
            $upd = $conn->prepare("UPDATE onboard_course_unlock_overrides SET is_unlocked = 0, revoked_at = NOW() WHERE id = ? AND user_id = ?");
            if ($upd) {
                $upd->bind_param('ii', $overrideId, $targetUid);
                $upd->execute();
                $upd->close();
            }
        } else {
            $upd = $conn->prepare("
                UPDATE onboard_course_unlock_overrides 
                SET is_unlocked = 0, revoked_at = NOW() 
                WHERE user_id = ? 
                  AND (
                    (? > 0 AND moodle_course_id = ?) OR 
                    (? > 0 AND module_id = ?) OR 
                    (? > 0 AND moodle_cmid = ?)
                  )
            ");
            if ($upd) {
                $upd->bind_param('iiiiiii', $targetUid, $courseId, $courseId, $moduleId, $moduleId, $cmid, $cmid);
                $upd->execute();
                $upd->close();
            }
        }

        $invalidateEmployeeCourseCache($targetUid);
echo json_encode([
            'success' => true,
            'message' => 'Course unlock override revoked successfully.'
        ]);
        exit;
    }

    // Action 3: Extend 7-day access window for this employee
    if ($action === 'extend_access') {
        $days = isset($input['days']) ? max(1, (int)$input['days']) : 7;
        $customDate = trim($input['extension_date'] ?? '');
        $reason = trim($input['reason'] ?? 'Admin extended 7-day onboarding period.');

        if (!empty($customDate) && strtotime($customDate) > time()) {
            $newExtDate = date('Y-m-d 23:59:59', strtotime($customDate));
        } else {
            $newExtDate = date('Y-m-d 23:59:59', time() + ($days * 86400));
        }

        $key = "access_extension_{$targetUid}";
        $escKey = $conn->real_escape_string($key);
        $escVal = $conn->real_escape_string($newExtDate);

        $conn->query("
            INSERT INTO onboard_settings (`key`, `value`, `updated_at`) 
            VALUES ('{$escKey}', '{$escVal}', NOW()) 
            ON DUPLICATE KEY UPDATE `value` = '{$escVal}', `updated_at` = NOW()
        ");

        // Record in audit log
        $insLog = $conn->prepare("
            INSERT INTO onboard_course_unlock_overrides 
                (user_id, course_name, admin_id, admin_name, reason, is_unlocked, unlocked_at) 
            VALUES 
                (?, '7-Day Access Window Extension', ?, ?, ?, 1, NOW())
        ");
        if ($insLog) {
            $logReason = "Extended onboarding access window until {$newExtDate}. Reason: {$reason}";
            $insLog->bind_param('iiss', $targetUid, $currentUserId, $adminName, $logReason);
            $insLog->execute();
            $insLog->close();
        }

echo json_encode([
            'success' => true,
            'message' => "Onboarding access extended until " . date('d M Y', strtotime($newExtDate)),
            'extension_date' => $newExtDate
        ]);
        exit;
    }

    http_response_code(400);
echo json_encode(['success' => false, 'message' => "Unknown action: {$action}"]);
    exit;
}

// 4. Handle GET Request: Fetch Employee Info, Courses, and Overrides
$targetUid = isset($_GET['uid']) ? (int)$_GET['uid'] : 0;
if ($targetUid <= 0) {
    http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Missing or invalid employee uid parameter.']);
    exit;
}

// 4.1 Fetch Employee Details
$emp = null;
$empStmt = $conn->prepare("
    SELECT u.uid, 
           TRIM(CONCAT(u.firstname, ' ', u.lastname)) AS name,
           u.firstname, u.lastname, 
           u.ofc_mail, u.joiningdate, u.profile_img,
           m.moodle_user_id, m.created_at AS onboard_started_at, m.sync_status
    FROM user u
    LEFT JOIN onboard_moodle_users m ON m.hexa_uid = u.uid
    WHERE u.uid = ?
    LIMIT 1
");
if ($empStmt) {
    $empStmt->bind_param("i", $targetUid);
    $empStmt->execute();
    $emp = $empStmt->get_result()->fetch_assoc();
    $empStmt->close();
}

if (!$emp) {
    http_response_code(404);
echo json_encode(['success' => false, 'message' => 'Employee not found.']);
    exit;
}

// 4.2 7-Day Access Window calculation
$onboardStartDate = !empty($emp['onboard_started_at']) ? $emp['onboard_started_at'] : (!empty($emp['joiningdate']) && $emp['joiningdate'] !== '0000-00-00' ? $emp['joiningdate'] : null);
$daysElapsed = 0;
$isExpired = false;
$extensionDate = null;
$hasExtension = false;

$chkExt = $conn->query("SELECT `value` FROM onboard_settings WHERE `key` = 'access_extension_{$targetUid}' LIMIT 1");
if ($chkExt && $eRow = $chkExt->fetch_assoc()) {
    $extensionDate = $eRow['value'];
    $hasExtension = (strtotime($extensionDate) > time());
}

if ($onboardStartDate) {
    $daysElapsed = (int)floor((time() - strtotime($onboardStartDate)) / 86400);
    if ($daysElapsed >= 7 && !$hasExtension) {
        $isExpired = true;
    }
}

// 4.3 Check Sequential Locking Global Setting
$seqLocking = false;
$chkSeq = $conn->query("SELECT `value` FROM onboard_settings WHERE `key` = 'sequential_locking' LIMIT 1");
if ($chkSeq && $sRow = $chkSeq->fetch_assoc()) {
    $seqLocking = ((string)$sRow['value'] === '1' || strtolower((string)$sRow['value']) === 'true');
}

// 4.4 Fetch Active Overrides for this Employee
$activeOverrides = [];
$chkOvr = $conn->query("
    SELECT id, moodle_course_id, module_id, moodle_cmid, course_name, admin_name, reason, unlocked_at 
    FROM onboard_course_unlock_overrides 
    WHERE user_id = {$targetUid} AND is_unlocked = 1
");
if ($chkOvr) {
    while ($oRow = $chkOvr->fetch_assoc()) {
        if (!empty($oRow['moodle_course_id'])) $activeOverrides['course_' . (int)$oRow['moodle_course_id']] = $oRow;
        if (!empty($oRow['module_id'])) $activeOverrides['module_' . (int)$oRow['module_id']] = $oRow;
        if (!empty($oRow['moodle_cmid'])) $activeOverrides['cmid_' . (int)$oRow['moodle_cmid']] = $oRow;
    }
}

// 4.5 Fetch All Onboarding Courses & Modules (strictly excluding assessments)
$coursesList = [];
$hasSortCol = false;
$chkSort = $conn->query("SHOW COLUMNS FROM onboard_modules LIKE 'sort_order'");
if ($chkSort && $chkSort->num_rows > 0) {
    $hasSortCol = true;
}
$sortSelect = $hasSortCol ? "IFNULL(NULLIF(m.sort_order, 0), m.id) AS sort_order" : "m.id AS sort_order";
$sortClause = $hasSortCol ? "ORDER BY sort_order ASC, m.id ASC" : "ORDER BY m.id ASC";

$cQuery = $conn->query("
    SELECT m.id, m.title, m.description, m.duration_minutes, m.moodle_course_id, m.moodle_cmid, m.moodle_scoid, 
           m.passing_score, m.type, {$sortSelect}
    FROM onboard_modules m
    WHERE m.is_active = 1
      AND (m.type != 'assessment' OR m.type IS NULL)
      AND LOWER(m.title) NOT LIKE '%assessment%'
      AND LOWER(m.title) NOT LIKE '%quiz%'
    {$sortClause}
");

$orderedModules = [];
if ($cQuery) {
    while ($mRow = $cQuery->fetch_assoc()) {
        $orderedModules[] = $mRow;
    }
}

// Fallback: If onboard_modules has fewer courses, query distinct Moodle courses
if (empty($orderedModules)) {
    $orderedModules = [
        ['id' => 1, 'title' => 'Course 1 – Employee Onboarding', 'moodle_course_id' => 2, 'moodle_cmid' => 6, 'moodle_scoid' => 8, 'duration_minutes' => 45],
        ['id' => 3, 'title' => 'Course 2 – Work Rules and Work Life', 'moodle_course_id' => 4, 'moodle_cmid' => 7, 'moodle_scoid' => 10, 'duration_minutes' => 30],
        ['id' => 5, 'title' => 'Course 3 – Leave and Holidays', 'moodle_course_id' => 5, 'moodle_cmid' => 8, 'moodle_scoid' => 12, 'duration_minutes' => 30],
    ];
}

// SCO ID mapping for verified Moodle SCORM activities
$cmidScoMap = [
    6 => ['scoid' => 8,  'org' => 'M1_-_Onboarding_Program_ORG'],
    4 => ['scoid' => 32, 'org' => 'M_11_-_Assessment_ORG'],
    7 => ['scoid' => 10, 'org' => 'M2_-_Work_Rules_and_Work_Life_ORG'],
    8 => ['scoid' => 12, 'org' => 'M3_-_Leave_&_Holidays_ORG'],
    9 => ['scoid' => 14, 'org' => 'M5_-_IT,_Confidentiality_&_Security_ORG'],
    2 => ['scoid' => 2,  'org' => 'M1_-_Onboarding_Program_ORG'],
    5 => ['scoid' => 6,  'org' => 'Assessment_ORG'],
];

// Fetch user's local progress records
$progressMap = [];
$moodleUid = (int)($emp['moodle_user_id'] ?? 0);
$pRes = $conn->query("
    SELECT module_id, status, is_completed, progress_percent, score, attempts, total_time, completed_at, started_at
    FROM onboard_module_progress
    WHERE user_id = {$targetUid}
");
if ($pRes) {
    while ($pRow = $pRes->fetch_assoc()) {
        $progressMap[(int)$pRow['module_id']] = $pRow;
    }
}

// Pre-fetch SCORM packages for ALL courses in 1 batch call
$batchCourseIds = [];
foreach ($orderedModules as $idxTmp => $mTmp) {
    $mcId = !empty($mTmp['moodle_course_id']) ? (int)$mTmp['moodle_course_id'] : (in_array($idxTmp, [0,1,2,3], true) ? ([2,4,5,6][$idxTmp]) : ($idxTmp + 2));
    if ($mcId > 0) $batchCourseIds[] = $mcId;
}
$batchCourseIds = array_values(array_unique($batchCourseIds));
$batchScorms = [];
if (!empty($batchCourseIds)) {
    $bRes = callMoodleWs('mod_scorm_get_scorms_by_courses', ['courseids' => $batchCourseIds], $conn);
    if (!empty($bRes['scorms']) && is_array($bRes['scorms'])) {
        foreach ($bRes['scorms'] as $scItem) {
            $scCid = (int)($scItem['course'] ?? 0);
            if ($scCid > 0) $batchScorms[$scCid][] = $scItem;
        }
    }
}

// Build sequential course access list with live Moodle SCORM verification
$coursesOut = [];
$prevCompleted = true;
$prevTitle = '';
$hadMoodleErrors = false;
$prevCourseLiveTotal = (int)($GLOBALS['moodle_api_tracker']['live_calls'] ?? 0);

foreach ($orderedModules as $idx => $mod) {
    $currentLiveTotal = (int)($GLOBALS['moodle_api_tracker']['live_calls'] ?? 0);
    if ($currentLiveTotal > $prevCourseLiveTotal) {
        usleep(100000); // 100ms pacing between live courses
    }
    $prevCourseLiveTotal = $currentLiveTotal;

    $modId = (int)$mod['id'];
    $moodleCourseId = !empty($mod['moodle_course_id']) ? (int)$mod['moodle_course_id'] : 0;
    if ($moodleCourseId <= 0) {
        $defaultCourseIds = [2, 4, 5, 6];
        $moodleCourseId = $defaultCourseIds[$idx] ?? ($idx + 2);
    }
    $cmid = !empty($mod['moodle_cmid']) ? (int)$mod['moodle_cmid'] : null;
    $scoid = !empty($mod['moodle_scoid']) ? (int)$mod['moodle_scoid'] : null;
    if (!$scoid && $cmid && isset($cmidScoMap[$cmid])) {
        $scoid = (int)$cmidScoMap[$cmid]['scoid'];
    }

    $userProg = $progressMap[$modId] ?? ($cmid ? ($progressMap[$cmid] ?? null) : null);

    // 1. Dynamically resolve Moodle SCORM package & launchable SCO ID
    $cId = $moodleCourseId;
    $GLOBALS['moodle_error_context'] = [
        'course_id' => $cId,
        'course_title' => $mod['title'] ?? ''
    ];
    $scormId = 0;
    $targetScoId = 0;

    $courseScormItems = $batchScorms[$cId] ?? null;
    if ($courseScormItems === null && !$hadMoodleErrors) {
        $scormsResult = callMoodleWs('mod_scorm_get_scorms_by_courses', [
            'courseids' => [$cId]
        ], $conn);
        $courseScormItems = (!empty($scormsResult['scorms']) && is_array($scormsResult['scorms'])) ? $scormsResult['scorms'] : [];
    }

    if (!empty($courseScormItems)) {
        $primaryScorm = $courseScormItems[0];
        $scormId = (int)$primaryScorm['id'];

        $scoesResult = callMoodleWs('mod_scorm_get_scorm_scoes', [
            'scormid' => $scormId
        ], $conn);

        if (isset($scoesResult['scoes']) && is_array($scoesResult['scoes'])) {
            foreach ($scoesResult['scoes'] as $sco) {
                if (($sco['scormtype'] ?? '') === 'sco' || !empty($sco['launch'])) {
                    $targetScoId = (int)$sco['id'];
                    break;
                }
            }
        }
    }

    // Direct launchable SCO ID mappings for Category 1 Onboarding
    if (!$targetScoId) {
        if ($cId === 2) $targetScoId = 8;
        elseif ($cId === 4) $targetScoId = 10;
        elseif ($cId === 5) $targetScoId = 12;
    }
    if (!$scormId) {
        if ($cId === 2) $scormId = 2;
        elseif ($cId === 4) $scormId = 3;
        elseif ($cId === 5) $scormId = 4;
    }
    $scoid = $targetScoId;

    // 2. Discover attempts from Moodle (circuit-break on error)
    $attemptCount = 0;
    $hasAttemptCountResponse = false;
    if (!$hadMoodleErrors && $scormId > 0 && $moodleUid > 0) {
        $attemptRes = callMoodleWs('mod_scorm_get_scorm_attempt_count', [
            'scormid' => $scormId,
            'userid' => $moodleUid
        ], $conn);
        if (!empty($attemptRes['error'])) {
            $hadMoodleErrors = true;
        } elseif (isset($attemptRes['attemptscount'])) {
            $attemptCount = max(0, (int)$attemptRes['attemptscount']);
            $hasAttemptCountResponse = true;
        }
    }

    // 3. Live verification from Moodle LMS for absolute truth
    $moodleVerified = false;
    $moodleSaysCompleted = false;
    $moodleSaysIncomplete = false;
    $calcPct = 0;
    $cleanTime = '00:00:00';
    $timeSec = 0;
    $scoreVal = null;
    $curSlide = 0;

    if ($hasAttemptCountResponse && $attemptCount === 0) {
        // Moodle explicitly confirms 0 attempts: course has never been launched or touched!
        $moodleVerified = true;
        $moodleSaysIncomplete = true;
        $calcPct = 0;
    } elseif (!$hadMoodleErrors && $scoid > 0 && $moodleUid > 0) {
        $attToFetch = max(1, $attemptCount);
        $tRes = callMoodleWs('mod_scorm_get_scorm_sco_tracks', [
            'scoid' => $scoid,
            'userid' => $moodleUid,
            'attempt' => $attToFetch
        ], $conn);

        if (!empty($tRes['error'])) {
            $hadMoodleErrors = true;
        } elseif (isset($tRes) && is_array($tRes)) {
            $moodleVerified = true;
            $trks = $tRes['data']['tracks'] ?? [];

            $trackMap = [];
            if (is_array($trks)) {
                foreach ($trks as $t) {
                    if (isset($t['element']) && isset($t['value'])) {
                        $trackMap[$t['element']] = $t['value'];
                    }
                }
            }

            $rawStatus = strtolower(trim((string)($trackMap['cmi.core.lesson_status'] ?? $trackMap['status'] ?? '')));

            // Total time spent
            $totTimeStr = $trackMap['cmi.core.total_time'] ?? $trackMap['total_time'] ?? '00:00:00';
            $cleanTime = trim(explode('.', $totTimeStr)[0]);
            $parts = explode(':', $cleanTime);
            if (count($parts) === 3) $timeSec = ((int)$parts[0] * 3600) + ((int)$parts[1] * 60) + (int)$parts[2];
            elseif (count($parts) === 2) $timeSec = ((int)$parts[0] * 60) + (int)$parts[1];

            // Parse score if present
            if (isset($trackMap['cmi.core.score.raw']) && trim((string)$trackMap['cmi.core.score.raw']) !== '') {
                $scoreVal = (float)$trackMap['cmi.core.score.raw'];
            } elseif (isset($trackMap['score_raw']) && trim((string)$trackMap['score_raw']) !== '') {
                $scoreVal = (float)$trackMap['score_raw'];
            }

            // Real slide tracking from lesson_location / suspend_data
            $lessonLoc = trim($trackMap['cmi.core.lesson_location'] ?? $trackMap['cmi.location'] ?? '');
            if (!empty($lessonLoc)) {
                if (preg_match('/^(\d+)\s*[\/\-_]\s*(\d+)$/', $lessonLoc, $lm)) {
                    $curSlide = (int)$lm[1];
                } elseif (is_numeric($lessonLoc)) {
                    $curSlide = (int)$lessonLoc;
                }
            }
            $suspendData = trim($trackMap['cmi.suspend_data'] ?? $trackMap['suspend_data'] ?? '');
            if (!empty($suspendData) && preg_match_all('/101([0-9a-z]{1,2})1/i', $suspendData, $sm)) {
                $curSlide = max($curSlide, count(array_unique($sm[0])));
            }
            if ($curSlide <= 0 && ($timeSec > 0 || in_array($rawStatus, ['incomplete', 'in_progress', 'suspend', 'browsed'], true))) {
                $curSlide = 1;
            }
            if ($curSlide > 0) {
                $curSlide = min($totSlides, max(1, $curSlide));
            }
            $slidePct = ($curSlide > 0 && $totSlides > 0) ? min(95, max(5, (int)round(($curSlide / $totSlides) * 100))) : 0;

            // Strict Moodle SCORM telemetry evaluation:
            // 1) Completed / Passed -> 100% finished
            // 2) Incomplete / Suspend / Browsed / Active time -> In Progress (slide % or time %)
            // 3) 0 attempts / Not Started -> 0% Not Started
            if (in_array($rawStatus, ['completed', 'passed'], true)) {
                $moodleSaysCompleted = true;
                $calcPct = 100;
            } elseif (in_array($rawStatus, ['incomplete', 'browsed', 'suspend', 'in_progress'], true) || $curSlide > 0 || $timeSec > 0) {
                $moodleSaysIncomplete = true;
                $calcPct = $slidePct > 0 ? $slidePct : max(20, min(95, (int)($userProg['progress_percent'] ?? 50)));
            } else {
                $moodleSaysIncomplete = true;
                $calcPct = 0;
            }
        }
    }

    // Determine authoritative status based on Moodle LMS results
    if ($moodleVerified) {
        if ($moodleSaysCompleted) {
            $isDone = true;
            $inProg = false;
            $calcPct = 100;
        } elseif ($calcPct > 0) {
            $isDone = false;
            $inProg = true;
        } else {
            // 0 attempts: strictly Not Started!
            $isDone = false;
            $inProg = false;
            $calcPct = 0;
        }

        // Synchronize local database table onboard_module_progress as authoritative cache
        $syncDoneVal = $isDone ? 1 : 0;
        $syncStatus = $isDone ? 'completed' : ($inProg ? 'in_progress' : 'not_started');
        $syncSlides = $isDone ? $totSlides : $curSlide;
        $escStatus = $conn->real_escape_string($syncStatus);
        $escTime = $conn->real_escape_string($cleanTime);

        // Update target employee record
        $conn->query("
            UPDATE onboard_module_progress 
            SET status = '{$escStatus}', 
                is_completed = {$syncDoneVal}, 
                progress_percent = {$calcPct}, 
                slides_viewed = {$syncSlides}, 
                total_slides = {$totSlides}, 
                total_time = '{$escTime}', 
                last_synced_at = NOW()
            WHERE user_id = {$targetUid}
              AND (
                  module_id = {$modId} 
                  " . ($cmid > 0 ? "OR module_id = {$cmid} " : "") . "
                  " . ($scormId > 0 ? "OR module_id = {$scormId} " : "") . "
                  " . ($cId > 0 ? "OR module_id IN (SELECT id FROM onboard_modules WHERE moodle_course_id = {$cId}) " : "") . "
              )
        ");
    } else {
        // Fallback when Moodle API is unreachable: keep previous verified status without assuming completion
        $isDone = !empty($userProg['is_completed']) || strtolower((string)($userProg['status'] ?? '')) === 'completed' || (int)($userProg['progress_percent'] ?? 0) >= 100;
        $calcPct = $isDone ? 100 : max(0, min(95, (int)($userProg['progress_percent'] ?? 0)));
        $inProg = !$isDone && (strtolower((string)($userProg['status'] ?? '')) === 'in_progress' || $calcPct > 0);
    }

    // Natural locking status according to rules:
    // Course 1 is unlocked initially; once completed, it becomes locked!
    // Next course is unlocked if previous course is completed.
    $naturalLocked = false;
    $naturalReason = null;

    if ($isDone) {
        $naturalLocked = true;
        $naturalReason = "Once completed, course is locked to prevent relaunching without manager's authorization.";
    } elseif ($seqLocking && !$prevCompleted) {
        $naturalLocked = true;
        $naturalReason = "Complete '{$prevTitle}' to unlock this course.";
    }

    // 7-Day Access Expiry: Disabled per requirements (continuous sequential learning access for all employees)
    // Only completion and sequential progression control course locking

    // Check if an active Admin Override exists for this course
    $override = null;
    if (isset($activeOverrides['course_' . $moodleCourseId])) {
        $override = $activeOverrides['course_' . $moodleCourseId];
    } elseif (isset($activeOverrides['module_' . $modId])) {
        $override = $activeOverrides['module_' . $modId];
    } elseif ($cmid && isset($activeOverrides['cmid_' . $cmid])) {
        $override = $activeOverrides['cmid_' . $cmid];
    }

    $isUnlockedByAdmin = ($override !== null);
    $effectiveLocked = $isUnlockedByAdmin ? false : $naturalLocked;

    $coursesOut[] = [
        'index' => $idx + 1,
        'module_id' => $modId,
        'moodle_course_id' => $moodleCourseId,
        'moodle_cmid' => $cmid,
        'title' => $mod['title'],
        'duration_minutes' => (int)($mod['duration_minutes'] ?? 30),
        'is_assessment' => (stripos($mod['title'], 'assess') !== false || stripos($mod['title'], 'quiz') !== false),
        'progress' => [
            'status' => $isDone ? 'completed' : ($inProg ? 'in_progress' : 'not_started'),
            'percent' => $isDone ? 100 : $calcPct,
            'is_completed' => $isDone,
            'score' => $scoreVal !== null ? (float)$scoreVal : ($userProg['score'] !== null ? (float)$userProg['score'] : null),
            'attempts' => $attemptCount > 0 ? $attemptCount : (int)($userProg['attempts'] ?? 0),
            'total_time' => $cleanTime !== '00:00:00' ? $cleanTime : ($userProg['total_time'] ?? ''),
            'completed_at' => $userProg['completed_at'] ?? null,
        ],
        'access' => [
            'is_locked' => $effectiveLocked,
            'natural_locked' => $naturalLocked,
            'admin_unlocked' => $isUnlockedByAdmin,
            'locked_reason' => $effectiveLocked ? ($naturalReason ?? 'Locked') : null,
            'override' => $override ? [
                'id' => (int)$override['id'],
                'admin_name' => $override['admin_name'],
                'reason' => $override['reason'],
                'unlocked_at' => $override['unlocked_at'],
            ] : null,
        ]
    ];

    $prevCompleted = $isDone;
    $prevTitle = $mod['title'];
}

// 4.6 Fetch Complete Override History / Audit Log for this Employee
$auditLog = [];
$logRes = $conn->query("
    SELECT id, moodle_course_id, module_id, course_name, admin_id, admin_name, reason, is_unlocked, unlocked_at, revoked_at, created_at
    FROM onboard_course_unlock_overrides
    WHERE user_id = {$targetUid}
    ORDER BY id DESC
    LIMIT 100
");
if ($logRes) {
    while ($lRow = $logRes->fetch_assoc()) {
        $auditLog[] = [
            'id' => (int)$lRow['id'],
            'course_name' => $lRow['course_name'],
            'admin_name' => $lRow['admin_name'] ?: ('Admin #' . $lRow['admin_id']),
            'reason' => $lRow['reason'] ?: 'No reason specified',
            'is_unlocked' => (bool)$lRow['is_unlocked'],
            'unlocked_at' => $lRow['unlocked_at'],
            'revoked_at' => $lRow['revoked_at'],
            'date_fmt' => date('d M Y, h:i A', strtotime($lRow['unlocked_at'])),
        ];
    }
}

if (function_exists('flushMoodleApiUsage')) {
    flushMoodleApiUsage($conn, 'admin_course_access', $currentUserId, $moodleUid);
}

echo json_encode([
    'success' => true,
    'employee' => [
        'uid' => (int)$emp['uid'],
        'name' => $emp['name'],
        'email' => $emp['ofc_mail'],
        'profile_img' => $emp['profile_img'],
        'moodle_user_id' => $emp['moodle_user_id'],
        'joiningdate' => $emp['joiningdate'],
        'onboard_started_at' => $onboardStartDate,
    ],
    'access_window' => [
        'started_at' => $onboardStartDate,
        'days_elapsed' => $daysElapsed,
        'is_expired' => $isExpired,
        'has_extension' => $hasExtension,
        'extension_date' => $extensionDate,
        'extension_date_fmt' => $extensionDate ? date('d M Y', strtotime($extensionDate)) : null,
    ],
    'sequential_locking' => $seqLocking,
    'courses' => $coursesOut,
    'audit_log' => $auditLog
]);

