<?php
// Endpoint: api/admin/employee_progress.php
// Returns SCORM Learning & Module Progress Report and Historical Assessment Attempt Audit for an employee

date_default_timezone_set('Asia/Kolkata');

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

$NEED_PDO = false;
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../moodle/client.php';
require_once __DIR__ . '/../moodle/sync_user.php';

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
echo json_encode(['success' => false, 'message' => 'Unauthorized: Admin access required.']);
    exit;
}

// Release session lock early to prevent blocking concurrent API requests
session_write_close();

// Target employee ID to inspect
$targetUid = isset($_GET['uid']) ? (int)$_GET['uid'] : (isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0);
if ($targetUid <= 0) {
    http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Missing or invalid employee uid parameter.']);
    exit;
}

// 2. Discover available user columns dynamically
$userCols = [];
$cRes = $conn->query("SHOW COLUMNS FROM `user`");
if ($cRes) {
    while ($cRow = $cRes->fetch_assoc()) {
        $userCols[] = strtolower($cRow['Field']);
    }
}

$hasOfcMail = in_array('ofc_mail', $userCols, true);
$mailField = $hasOfcMail ? "u.ofc_mail" : (in_array('email', $userCols, true) ? "u.email AS ofc_mail" : (in_array('personal_email', $userCols, true) ? "u.personal_email AS ofc_mail" : "'' AS ofc_mail"));

$hasProfileImg = in_array('profile_img', $userCols, true);
$imgField = $hasProfileImg ? "u.profile_img" : "NULL AS profile_img";

$hasJoiningDate = in_array('joiningdate', $userCols, true);
$joinField = $hasJoiningDate ? "u.joiningdate" : "NULL AS joiningdate";

$hasLastActive = in_array('last_active_time', $userCols, true);
$lastActiveField = $hasLastActive ? "u.last_active_time" : "NULL AS last_active_time";

// 3. Fetch Employee details - strict Hexa UID match first
$emp = null;
$empStmt = $conn->prepare("
    SELECT u.uid, 
           COALESCE(TRIM(CONCAT(u.firstname, ' ', u.lastname)), m.username, CONCAT('Employee #', u.uid)) AS name,
           u.firstname, u.lastname, $mailField, 
           $joinField, $lastActiveField, $imgField,
           m.moodle_user_id, m.sync_status
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

// Fallback 1: match onboard_moodle_users by hexa_uid if not found in user table
if (!$emp) {
    $mStmt = $conn->prepare("
        SELECT COALESCE(u.uid, m.hexa_uid) AS uid,
               COALESCE(TRIM(CONCAT(u.firstname, ' ', u.lastname)), m.username, CONCAT('Employee #', m.hexa_uid)) AS name,
               u.firstname, u.lastname, $mailField,
               $joinField, $lastActiveField, $imgField,
               m.moodle_user_id, m.sync_status
        FROM onboard_moodle_users m
        LEFT JOIN user u ON u.uid = m.hexa_uid
        WHERE m.hexa_uid = ?
        LIMIT 1
    ");
    if ($mStmt) {
        $mStmt->bind_param("i", $targetUid);
        $mStmt->execute();
        $emp = $mStmt->get_result()->fetch_assoc();
        $mStmt->close();
    }
}

// Fallback 2: match onboard_moodle_users by moodle_user_id only if no Hexa UID matched
if (!$emp) {
    $mStmt2 = $conn->prepare("
        SELECT COALESCE(u.uid, m.hexa_uid, m.moodle_user_id) AS uid,
               COALESCE(TRIM(CONCAT(u.firstname, ' ', u.lastname)), m.username, CONCAT('Employee #', m.moodle_user_id)) AS name,
               u.firstname, u.lastname, $mailField,
               $joinField, $lastActiveField, $imgField,
               m.moodle_user_id, m.sync_status
        FROM onboard_moodle_users m
        LEFT JOIN user u ON u.uid = m.hexa_uid
        WHERE m.moodle_user_id = ?
        LIMIT 1
    ");
    if ($mStmt2) {
        $mStmt2->bind_param("i", $targetUid);
        $mStmt2->execute();
        $emp = $mStmt2->get_result()->fetch_assoc();
        $mStmt2->close();
    }
}

if (!$emp) {
    http_response_code(404);
echo json_encode(['success' => false, 'message' => 'Employee record not found.']);
    exit;
}

$joinTs = !empty($emp['joiningdate']) ? strtotime($emp['joiningdate']) : null;
$joiningFmt = $joinTs ? date('d-M-Y', $joinTs) : ($emp['joiningdate'] ?? '');

$employeeData = [
    'uid' => (int)$emp['uid'],
    'name' => trim(($emp['firstname'] ?? '') . ' ' . ($emp['lastname'] ?? '')) ?: ($emp['name'] ?? 'Employee #' . $emp['uid']),
    'email' => $emp['ofc_mail'] ?? '',
    'joiningdate' => $emp['joiningdate'] ?? '',
    'joiningdate_fmt' => $joiningFmt,
    'photo' => !empty($emp['profile_img']) ? (((isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/internal/corhis') !== false) ? '/internal/corhis/dist/img/user/' : '/dist/img/user/') . implode('/', array_map('rawurlencode', explode('/', trim($emp['profile_img']))))) : '',
    'moodle_user_id' => !empty($emp['moodle_user_id']) ? (int)$emp['moodle_user_id'] : null,
    'moodle_status' => $emp['sync_status'] ?? 'Not linked'
];

$employeeHexaUid = (int)$emp['uid'];
$moodleUserId = !empty($emp['moodle_user_id']) ? (int)$emp['moodle_user_id'] : null;

// Auto-discover Moodle user if not yet mapped
if (!$moodleUserId && function_exists('autoSyncMoodleUser')) {
    $syncedId = autoSyncMoodleUser($employeeHexaUid, $conn);
    if ($syncedId) {
        $moodleUserId = (int)$syncedId;
        $employeeData['moodle_user_id'] = $moodleUserId;
        $employeeData['moodle_status'] = 'Linked';
    }
}

/**
 * Authoritative Induction Assessment Attempts Resolver
 * Queries onboard_induction_assessment_attempts for this employee
 */
if (!function_exists('getInductionAttemptsForUser')) {
    function getInductionAttemptsForUser($conn, $userId) {
        $attempts = [];
        if (!$conn || !$userId) return $attempts;
        $hasPhaseCol = false;
        $chkPh = $conn->query("SHOW COLUMNS FROM `onboard_induction_assessment_attempts` LIKE 'phase'");
        if ($chkPh && $chkPh->num_rows > 0) {
            $hasPhaseCol = true;
        }
        $phaseSelect = $hasPhaseCol ? "phase," : "1 AS phase,";
        $stmt = $conn->prepare("
            SELECT id, {$phaseSelect} attempt, Quiz_score, passing_score, result, is_locked, email_sent, email_to, email_cc, attempted_at
            FROM onboard_induction_assessment_attempts
            WHERE user_id = ?
            ORDER BY phase ASC, attempt ASC, id ASC
        ");
        if ($stmt) {
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $score = (float)$row['Quiz_score'];
                $pScore = !empty($row['passing_score']) ? (int)$row['passing_score'] : 80;
                $isPassed = ($row['result'] === 'Passed' || $score >= $pScore);
                $isFailed = !$isPassed;
                
                $attDate = '';
                if (!empty($row['attempted_at'])) {
                    try {
                        $dt = new DateTime($row['attempted_at'], new DateTimeZone('UTC'));
                        $dt->setTimezone(new DateTimeZone('Asia/Kolkata'));
                        $attDate = $dt->format('M j, Y, g:i A');
                    } catch (\Exception $e) {
                        $attDate = date('M j, Y, g:i A', strtotime($row['attempted_at']));
                    }
                }
                
                $attempts[] = [
                    'id' => (int)$row['id'],
                    'phase' => (int)($row['phase'] ?? 1),
                    'module_id' => 6,
                    'module_title' => 'Employee Assessment',
                    'attempt' => (int)$row['attempt'],
                    'score' => $score,
                    'passing_score' => $pScore,
                    'result' => $isPassed ? 'Passed' : 'Failed',
                    'is_passed' => $isPassed,
                    'is_failed' => $isFailed,
                    'is_locked' => (bool)$row['is_locked'],
                    'email_sent' => (bool)$row['email_sent'],
                    'email_to' => $row['email_to'],
                    'email_cc' => $row['email_cc'],
                    'duration' => '—',
                    'date' => $attDate,
                    'attempted_at' => $row['attempted_at']
                ];
            }
            $stmt->close();
        }
        return $attempts;
    }
}

// 3.5 Serve 600-second (10-minute) per-target-employee cache for admin report (unless force_refresh=1)
$empProgCacheDir = __DIR__ . '/../moodle/cache';
$empProgCacheFile = $empProgCacheDir . '/admin_emp_prog_' . (int)$employeeHexaUid . '.json';
if (empty($_GET['force_refresh']) && file_exists($empProgCacheFile) && (time() - filemtime($empProgCacheFile)) < 600) {
    $cachedEmpJson = @file_get_contents($empProgCacheFile);
    if ($cachedEmpJson) {
        $cData = json_decode($cachedEmpJson, true);
        if (is_array($cData) && !empty($cData['success'])) {
            $freshAtt = getInductionAttemptsForUser($conn, $employeeHexaUid);
            $cData['attempts'] = is_array($freshAtt) ? $freshAtt : [];
            if (isset($cData['progress_summary'])) {
                $cData['progress_summary']['total_attempts'] = count($cData['attempts']);
            }
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode($cData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            exit;
        }
    }
}

$modules = [];
$attempts = [];
$moodleSuccess = false;

// 4. If Moodle User ID is linked, query LIVE Moodle REST API for real-time SCORM & quiz telemetry
if ($moodleUserId) {
    // Load configured total_slides from onboard_modules
    $moduleSlidesMap = [];
    $modSlideRes = $conn->query("SELECT moodle_course_id, moodle_cmid, id, total_slides FROM onboard_modules WHERE is_active = 1");
    if ($modSlideRes) {
        while ($msr = $modSlideRes->fetch_assoc()) {
            if (!empty($msr['moodle_course_id'])) {
                $moduleSlidesMap[(int)$msr['moodle_course_id']] = (int)$msr['total_slides'];
            }
            if (!empty($msr['moodle_cmid'])) {
                $moduleSlidesMap['cm_' . (int)$msr['moodle_cmid']] = (int)$msr['total_slides'];
            }
        }
    }

    $coursesResponse = callMoodleWs('core_enrol_get_users_courses', [
        'userid' => $moodleUserId
    ], $conn);

    if (is_array($coursesResponse) && empty($coursesResponse['error'])) {
        // Filter Category 1 courses (Onboarding) and active in onboard_modules
        $categoryCourses = array_values(array_filter($coursesResponse, function($c) use ($moduleSlidesMap) {
            $cat = (int)($c['category'] ?? $c['categoryid'] ?? 0);
            if ($cat !== 1) return false;
            $cId = (int)($c['id'] ?? 0);
            if (!empty($moduleSlidesMap) && !isset($moduleSlidesMap[$cId])) {
                return false;
            }
            return true;
        }));

        // Sort by idnumber ASC, then id ASC
        usort($categoryCourses, function($a, $b) {
            $idA = $a['idnumber'] ?? '';
            $idB = $b['idnumber'] ?? '';
            if ($idA !== '' && $idB !== '') return strnatcasecmp($idA, $idB);
            return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
        });

        $enrichedCourses = [];
        $modIdx = 1;

        // Pre-fetch SCORM packages for ALL category courses in 1 batch call instead of N calls
        $allCourseIds = array_values(array_map(function($c) { return (int)$c['id']; }, $categoryCourses));
        $batchScorms = [];
        if (!empty($allCourseIds)) {
            $batchScormsRes = callMoodleWs('mod_scorm_get_scorms_by_courses', [
                'courseids' => $allCourseIds
            ], $conn);
            if (!empty($batchScormsRes['scorms']) && is_array($batchScormsRes['scorms'])) {
                foreach ($batchScormsRes['scorms'] as $scItem) {
                    $scCourseId = (int)($scItem['course'] ?? 0);
                    if ($scCourseId > 0) {
                        $batchScorms[$scCourseId][] = $scItem;
                    }
                }
            }
        }

        $hadMoodleErrors = false;
        $prevCourseLiveTotal = (int)($GLOBALS['moodle_api_tracker']['live_calls'] ?? 0);
        foreach ($categoryCourses as $course) {
            $currentLiveTotal = (int)($GLOBALS['moodle_api_tracker']['live_calls'] ?? 0);
            if ($currentLiveTotal > $prevCourseLiveTotal) {
                usleep(100000); // 100ms pacing between live courses to stay under Hostinger 20/sec DB cap
            }
            $prevCourseLiveTotal = $currentLiveTotal;

            $cId = (int)$course['id'];
            $GLOBALS['moodle_error_context'] = [
                'course_id' => $cId,
                'course_title' => $course['fullname'] ?? ''
            ];
            $course['scorms'] = [];
            $courseModules = [];
            $course['total_scorms'] = 0;
            $course['completed_scorms'] = 0;

            // Fetch course contents to find active module instances in visible course sections
            $contents = callMoodleWs('core_course_get_contents', ['courseid' => $cId], $conn);
            $activeScormIds = [];
            $scormCompletionMap = [];
            if (is_array($contents)) {
                foreach ($contents as $sec) {
                    foreach ($sec['modules'] ?? [] as $m) {
                        $modname = strtolower($m['modname'] ?? '');
                        if ($modname === 'scorm' && !empty($m['instance'])) {
                            $activeScormIds[] = (int)$m['instance'];
                        }
                    }
                }
            }

            // Retrieve SCORM packages from pre-fetched batch (or targeted fallback if missing)
            $scormItemsForCourse = $batchScorms[$cId] ?? null;
            if ($scormItemsForCourse === null) {
                $scormsResult = callMoodleWs('mod_scorm_get_scorms_by_courses', [
                    'courseids' => [$cId]
                ], $conn);
                $scormItemsForCourse = (!empty($scormsResult['scorms']) && is_array($scormsResult['scorms'])) ? $scormsResult['scorms'] : [];
            }

            // Only query Moodle activity completion for non-SCORM courses (SCORM courses use SCORM tracks directly)
            if (empty($scormItemsForCourse)) {
                $complRes = callMoodleWs('core_completion_get_activities_completion_status', [
                    'courseid' => $cId,
                    'userid' => $moodleUserId
                ], $conn);
                if (!empty($complRes['statuses']) && is_array($complRes['statuses'])) {
                    foreach ($complRes['statuses'] as $st) {
                        if ((int)$st['state'] > 0) {
                            $scormCompletionMap[(int)$st['cmid']] = true;
                        }
                    }
                }
            }

            if (!empty($scormItemsForCourse)) {
                // Strictly filter to active SCORM packages that exist in the course sections
                $scormsToProcess = $scormItemsForCourse;
                if (!empty($activeScormIds)) {
                    $scormsToProcess = array_values(array_filter($scormItemsForCourse, function($sc) use ($activeScormIds) {
                        return in_array((int)$sc['id'], $activeScormIds, true);
                    }));
                }

                $course['total_scorms'] = count($scormsToProcess);
                foreach ($scormsToProcess as $scormItem) {
                    $scormId = (int)$scormItem['id'];
                    $targetScoId = $scormId;
                    $targetOrg = '';
                    $masteryScore = null;

                    $scoesResult = callMoodleWs('mod_scorm_get_scorm_scoes', [
                        'scormid' => $scormId
                    ], $conn);

                    if (isset($scoesResult['scoes']) && is_array($scoesResult['scoes'])) {
                        foreach ($scoesResult['scoes'] as $sco) {
                            if (!empty($sco['organization']) && empty($targetOrg)) {
                                $targetOrg = $sco['organization'];
                            }
                            if (!empty($sco['extradata']) && is_array($sco['extradata'])) {
                                foreach ($sco['extradata'] as $ex) {
                                    if (($ex['element'] ?? '') === 'masteryscore' && trim((string)$ex['value']) !== '') {
                                        $masteryScore = (int)$ex['value'];
                                    }
                                }
                            }
                            if (($sco['scormtype'] ?? '') === 'sco' || !empty($sco['launch'])) {
                                $targetScoId = (int)$sco['id'];
                                if (!empty($sco['organization'])) $targetOrg = $sco['organization'];
                                break;
                            }
                        }
                    }

                    $scormName = $scormItem['name'] ?? '';
                    $isAssessment = (stripos($scormName, 'assess') !== false) || (stripos($scormName, 'quiz') !== false);
                    if ($isAssessment && $masteryScore === null) {
                        $masteryScore = 80;
                    }

                    // Fetch attempt count from Moodle (circuit-break if Moodle DB already failed)
                    $attemptNum = 0;
                    $tracks = [];
                    if (!$hadMoodleErrors) {
                        $attemptRes = callMoodleWs('mod_scorm_get_scorm_attempt_count', [
                            'scormid' => $scormId,
                            'userid' => $moodleUserId
                        ], $conn);
                        if (!empty($attemptRes['error'])) {
                            $hadMoodleErrors = true;
                        } elseif (isset($attemptRes['attemptscount'])) {
                            $attemptNum = max(0, (int)$attemptRes['attemptscount']);
                        } else {
                            $attemptNum = 1;
                        }

                        // Skip sco_tracks when Moodle confirms 0 attempts or DB failed
                        if (!$hadMoodleErrors && $attemptNum > 0) {
                            $tracksResult = callMoodleWs('mod_scorm_get_scorm_sco_tracks', [
                                'scoid' => $targetScoId,
                                'userid' => $moodleUserId,
                                'attempt' => $attemptNum
                            ], $conn);
                            if (!empty($tracksResult['error'])) {
                                $hadMoodleErrors = true;
                            } else {
                                $tracks = $tracksResult['data']['tracks'] ?? [];
                            }
                        }
                    }
                    $trackMap = [];
                    if (is_array($tracks)) {
                        foreach ($tracks as $t) {
                            if (isset($t['element']) && isset($t['value'])) {
                                $trackMap[$t['element']] = $t['value'];
                            }
                        }
                    }

                    $status = strtolower($trackMap['cmi.core.lesson_status'] ?? $trackMap['status'] ?? 'not_started');
                    $rawScore = null;
                    if (isset($trackMap['cmi.core.score.raw']) && trim((string)$trackMap['cmi.core.score.raw']) !== '') {
                        $rawScore = (float)$trackMap['cmi.core.score.raw'];
                    } elseif (isset($trackMap['score_raw']) && trim((string)$trackMap['score_raw']) !== '') {
                        $rawScore = (float)$trackMap['score_raw'];
                    }

                    $isDone = in_array($status, ['completed', 'passed'], true);



                    $isFailed = false;
                    if ($isAssessment) {
                        if ($status === 'failed') {
                            $isFailed = true;
                        } elseif ($masteryScore !== null && $rawScore !== null && $rawScore < $masteryScore && $status !== 'not_started') {
                            $isFailed = true;
                        }
                    }

                    // Collect all attempts for assessments
                    $attemptHistory = [];
                    if ($isAssessment) {
                        for ($att = 1; $att <= $attemptNum; $att++) {
                            $attTracks = ($att === $attemptNum) ? $tracks : [];
                            if ($att < $attemptNum) {
                                $attResTracks = callMoodleWs('mod_scorm_get_scorm_sco_tracks', [
                                    'scoid' => $targetScoId,
                                    'userid' => $moodleUserId,
                                    'attempt' => $att
                                ], $conn);
                                $attTracks = $attResTracks['data']['tracks'] ?? [];
                            }

                            $attMap = [];
                            if (is_array($attTracks)) {
                                foreach ($attTracks as $t) {
                                    if (isset($t['element']) && isset($t['value'])) {
                                        $attMap[$t['element']] = $t['value'];
                                    }
                                }
                            }

                            $attStatus = strtolower($attMap['cmi.core.lesson_status'] ?? $attMap['status'] ?? 'not_started');
                            $attScore = null;
                            if (isset($attMap['cmi.core.score.raw']) && trim((string)$attMap['cmi.core.score.raw']) !== '') {
                                $attScore = (float)$attMap['cmi.core.score.raw'];
                            } elseif (isset($attMap['score_raw']) && trim((string)$attMap['score_raw']) !== '') {
                                $attScore = (float)$attMap['score_raw'];
                            }

                            $attPassed = in_array($attStatus, ['completed', 'passed'], true) || ($attScore !== null && $masteryScore !== null && $attScore >= $masteryScore);
                            $attFailed = ($attStatus === 'failed') || ($masteryScore !== null && $attScore !== null && $attScore < $masteryScore && $attStatus !== 'not_started');

                            $rawDuration = $attMap['cmi.core.total_time'] ?? $attMap['total_time'] ?? '00:00:00';
                            $formattedDur = $rawDuration;
                            $cleanDur = trim(explode('.', $rawDuration)[0]);
                            $durParts = explode(':', $cleanDur);
                            if (count($durParts) === 3) {
                                $h = (int)$durParts[0]; $m = (int)$durParts[1]; $s = (int)$durParts[2];
                                if ($h > 0) $formattedDur = "{$h}h {$m}m";
                                elseif ($m > 0) $formattedDur = "{$m}m {$s}s";
                                else $formattedDur = "{$s}s";
                            }

                            $attTimeModified = $attMap['timemodified'] ?? null;
                            $attDateFormatted = !empty($attTimeModified) ? date('M j, Y, g:i A', (int)$attTimeModified) : '';

                            $attemptHistory[] = [
                                'attempt' => $att,
                                'score' => $attScore,
                                'result' => $attPassed ? 'Passed' : ($attFailed ? 'Failed' : ($attStatus !== 'not_started' ? ucfirst($attStatus) : 'Incomplete')),
                                'is_passed' => $attPassed,
                                'is_failed' => $attFailed,
                                'status' => $attStatus,
                                'duration' => $formattedDur,
                                'total_time' => $rawDuration,
                                'timemodified' => $attTimeModified,
                                'date' => $attDateFormatted
                            ];
                        }
                    }

                    // Calculate progress percent & time
                    $totTimeStr = $trackMap['cmi.core.total_time'] ?? $trackMap['total_time'] ?? '00:00:00';
                    $cleanTime = trim(explode('.', $totTimeStr)[0]);
                    $timeSec = 0;
                    $parts = explode(':', $cleanTime);
                    if (count($parts) === 3) $timeSec = ((int)$parts[0] * 3600) + ((int)$parts[1] * 60) + (int)$parts[2];
                    elseif (count($parts) === 2) $timeSec = ((int)$parts[0] * 60) + (int)$parts[1];

                    // For learning modules: only completed if Moodle SCORM tracks explicitly state completed or passed!
                    // Incomplete, suspend, or browsed tracks MUST strictly remain in_progress (e.g. slide 2/17 viewed)
                    if (!$isAssessment) {
                        if (in_array($status, ['incomplete', 'browsed', 'suspend', 'in_progress'], true)) {
                            $isDone = false;
                            $status = 'in_progress';
                        } elseif (in_array($status, ['completed', 'passed'], true)) {
                            $isDone = true;
                            $status = 'completed';
                        }
                    }

                    // For assessment modules: only completed if actually passed (score >= 80% or passed)
                    if ($isAssessment && !$isDone) {
                        if (!$isFailed && ($rawScore !== null && $masteryScore !== null && $rawScore >= $masteryScore)) {
                            $isDone = true;
                            $status = 'completed';
                        }
                    }

                    $totSlides = $moduleSlidesMap[$cId] ?? $moduleSlidesMap['cm_' . $scormId] ?? 10;
                    // Authoritative slide counts for verified Storyline packages
                    if ($cId === 2) {
                        if ($totSlides <= 0 || $totSlides === 10) $totSlides = 18;
                    } elseif ($cId === 4) {
                        if ($totSlides <= 0 || $totSlides === 10) $totSlides = 18;
                    } elseif ($cId === 5) {
                        if ($totSlides <= 0 || $totSlides === 30 || $totSlides === 10) $totSlides = 17;
                    } elseif ($cId === 6) {
                        if ($totSlides <= 0 || $totSlides === 10) $totSlides = 19;
                    }
                    $lessonLoc = trim($trackMap['cmi.core.lesson_location'] ?? $trackMap['cmi.location'] ?? '');
                    $suspendData = trim($trackMap['cmi.suspend_data'] ?? $trackMap['suspend_data'] ?? '');
                    $curSlide = 0;
                    if (!empty($lessonLoc)) {
                        if (preg_match('/^(\d+)\s*[\/\-_]\s*(\d+)$/', $lessonLoc, $m)) {
                            $curSlide = (int)$m[1];
                        } elseif (is_numeric($lessonLoc)) {
                            $curSlide = (int)$lessonLoc;
                        } elseif (preg_match('/(?:slide|page|scene)[_\-\s]*(\d+)/i', $lessonLoc, $m)) {
                            $curSlide = (int)$m[1];
                        }
                    }
                    if (!empty($suspendData)) {
                        if (preg_match_all('/101([0-9a-z]{1,2})1/i', $suspendData, $sm)) {
                            $storylineViewed = count(array_unique($sm[0]));
                            if ($storylineViewed > 0) $curSlide = max($curSlide, $storylineViewed);
                        }
                    }
                    if ($curSlide <= 0 && ($status === 'in_progress' || $timeSec > 0)) {
                        $curSlide = 1;
                    }
                    if ($curSlide > 0) {
                        $curSlide = min($totSlides, max(1, $curSlide));
                    }
                    $slidePct = ($curSlide > 0 && $totSlides > 0) ? min(95, max(5, (int)round(($curSlide / $totSlides) * 100))) : 0;

                    $progPercent = 0;
                    if ($isDone) {
                        $progPercent = 100;
                    } elseif ($isFailed) {
                        $progPercent = $rawScore !== null ? (int)round($rawScore) : 0;
                    } elseif ($slidePct > 0) {
                        $progPercent = $slidePct;
                    } elseif ($status === 'in_progress' || $timeSec > 0) {
                        $progPercent = 20;
                    }

                    $statusFinal = $isDone ? 'completed' : ($isFailed ? 'failed' : ($progPercent > 0 || $timeSec > 0 ? 'in_progress' : 'ready'));

                    $course['scorms'][] = [
                        'id' => $scormId,
                        'name' => $scormName,
                        'is_assessment' => $isAssessment,
                        'status' => $statusFinal,
                        'is_completed' => $isDone,
                        'is_failed' => $isFailed,
                        'progress_percent' => $progPercent,
                        'passing_score' => $masteryScore,
                        'total_time' => $cleanTime,
                        'score' => $rawScore,
                        'attempts' => $attemptNum,
                        'attempts_history' => $attemptHistory,
                        'current_slide' => $isDone ? $totSlides : $curSlide,
                        'slides_viewed' => $isDone ? $totSlides : $curSlide,
                        'total_slides' => $totSlides,
                        'first_started_at' => !empty($trackMap['x.start.time']) 
                            ? date('Y-m-d H:i:s', (int)$trackMap['x.start.time']) 
                            : (!empty($attemptHistory) && !empty($attemptHistory[0]['timemodified'])
                                ? date('Y-m-d H:i:s', (int)$attemptHistory[0]['timemodified'])
                                : (!empty($trackMap['timemodified']) && !in_array($statusFinal, ['not_started', ''])
                                    ? date('Y-m-d H:i:s', (int)$trackMap['timemodified'])
                                    : null)),
                        'last_played_at' => !empty($trackMap['timemodified']) ? date('Y-m-d H:i:s', (int)$trackMap['timemodified']) : null,
                    ];

                    $firstStartMod = !empty($trackMap['x.start.time']) 
                        ? date('Y-m-d H:i:s', (int)$trackMap['x.start.time']) 
                        : (!empty($attemptHistory) && !empty($attemptHistory[0]['timemodified'])
                            ? date('Y-m-d H:i:s', (int)$attemptHistory[0]['timemodified'])
                            : (!empty($trackMap['timemodified']) && !in_array($statusFinal, ['not_started', ''])
                                ? date('Y-m-d H:i:s', (int)$trackMap['timemodified'])
                                : null));

                    $isMoodleLiveOk = (empty($attemptRes['error']) && empty($tracksResult['error']));
                    $modEntry = [
                        'id' => $modIdx++,
                        'title' => $scormName,
                        'type' => $isAssessment ? 'assessment' : 'scorm',
                        'is_assessment' => $isAssessment,
                        'status' => $statusFinal === 'ready' ? 'not_started' : $statusFinal,
                        'is_completed' => $isDone,
                        'is_failed' => $isFailed,
                        'is_in_progress' => ($statusFinal === 'in_progress'),
                        'progress_percent' => $progPercent,
                        'score' => $rawScore,
                        'passing_score' => $masteryScore ?: 80,
                        'total_time' => $cleanTime,
                        'time_spent' => $cleanTime,
                        'time_seconds' => $timeSec,
                        'attempts' => ($statusFinal === 'ready' && $timeSec === 0) ? 0 : $attemptNum,
                        'current_slide' => $isDone ? $totSlides : $curSlide,
                        'slides_viewed' => $isDone ? $totSlides : $curSlide,
                        'total_slides' => $totSlides,
                        'first_started_at' => $firstStartMod,
                        'last_played_at' => !empty($trackMap['timemodified']) ? date('Y-m-d H:i:s', (int)$trackMap['timemodified']) : null,
                        'course_id' => $cId,
                        'course_name' => $course['fullname'] ?? '',
                        'moodle_verified' => $isMoodleLiveOk
                    ];
                    $modules[] = array_merge($modEntry, ['status' => $statusFinal]);
                    $courseModules[] = $modEntry;

                    // Align local database progress directly with authoritative live Moodle SCORM track status
                    if ($employeeHexaUid > 0 && !empty($scormId) && $isMoodleLiveOk) {
                        $dbStatus = ($statusFinal === 'ready') ? 'not_started' : $statusFinal;
                        $escStatus = $conn->real_escape_string($dbStatus);
                        $doneVal = $isDone ? 1 : 0;
                        $slViewed = $isDone ? $totSlides : $curSlide;
                        $conn->query("
                            UPDATE onboard_module_progress 
                            SET 
                                status = '{$escStatus}',
                                is_completed = {$doneVal},
                                progress_percent = {$progPercent},
                                slides_viewed = {$slViewed},
                                total_slides = {$totSlides},
                                last_synced_at = NOW()
                            WHERE user_id = {$employeeHexaUid}
                              AND (
                                  module_id = {$scormId} 
                                  OR module_id IN (SELECT id FROM onboard_modules WHERE moodle_cmid = {$scormId} OR moodle_course_id = {$cId})
                              )
                        ");
                    }

                    if ($isAssessment && !empty($attemptHistory)) {
                        foreach ($attemptHistory as $ah) {
                            if ($ah['score'] === null && (empty($ah['duration']) || $ah['duration'] === '0s') && empty($ah['date'])) {
                                continue;
                            }
                            $attempts[] = [
                                'id' => count($attempts) + 1,
                                'module_id' => $scormId,
                                'module_title' => $scormName,
                                'attempt' => (int)$ah['attempt'],
                                'score' => $ah['score'],
                                'result' => $ah['result'],
                                'is_passed' => !empty($ah['is_passed']),
                                'is_failed' => !empty($ah['is_failed']),
                                'duration' => $ah['duration'],
                                'date' => $ah['date'],
                                'attempted_at' => !empty($ah['timemodified']) ? date('Y-m-d H:i:s', (int)$ah['timemodified']) : ''
                            ];
                        }
                    }
                }
            }

            $course['modules'] = $courseModules;
            $enrichedCourses[] = $course;
        }

        // Persist live tracking to local MySQL for the authoritative employee Hexa UID
        if (!empty($enrichedCourses) && function_exists('persistMoodleTrackingToLocalSql')) {
            $saveUid = $employeeHexaUid > 0 ? $employeeHexaUid : (int)$moodleUserId;
            if ($saveUid > 0) {
                persistMoodleTrackingToLocalSql($conn, $saveUid, $enrichedCourses);
            }
        }

        $moodleSuccess = true;
    }
}

// 5. Fallback: If Moodle API returned empty or failed, fetch from MySQL database tables
if (!$moodleSuccess || empty($modules)) {
    // 3.5 Serve 600-second (10-minute) per-target-employee cache for admin report (unless force_refresh=1)
$empProgCacheDir = __DIR__ . '/../moodle/cache';
$empProgCacheFile = $empProgCacheDir . '/admin_emp_prog_' . (int)$employeeHexaUid . '.json';
if (empty($_GET['force_refresh']) && file_exists($empProgCacheFile) && (time() - filemtime($empProgCacheFile)) < 600) {
    $cachedEmpJson = @file_get_contents($empProgCacheFile);
    if ($cachedEmpJson) {
        $cData = json_decode($cachedEmpJson, true);
        if (is_array($cData) && !empty($cData['success'])) {
            $freshAtt = getInductionAttemptsForUser($conn, $employeeHexaUid);
            $cData['attempts'] = is_array($freshAtt) ? $freshAtt : [];
            if (isset($cData['progress_summary'])) {
                $cData['progress_summary']['total_attempts'] = count($cData['attempts']);
            }
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode($cData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            exit;
        }
    }
}

$modules = [];
    $progLookupUid = $employeeHexaUid > 0 ? $employeeHexaUid : (int)$moodleUserId;
    $modQuery = "
        SELECT 
            m.id, 
            m.title, 
            m.type, 
            m.duration_minutes, 
            m.passing_score AS module_passing_score,
            m.moodle_course_id,
            m.moodle_cmid,
            m.moodle_scoid,
            p.status,
            p.progress_percent,
            p.highest_progress_percent,
            p.score,
            p.highest_score,
            p.passing_score,
            p.total_time,
            p.total_time_seconds,
            p.attempts,
            p.slides_viewed,
            p.total_slides,
            p.is_completed,
            p.last_synced_at
        FROM onboard_modules m
        LEFT JOIN onboard_module_progress p ON p.module_id = m.id AND p.user_id = ?
        WHERE m.is_active = 1
        ORDER BY m.id ASC
    ";

    $modStmt = $conn->prepare($modQuery);
    if ($modStmt) {
        $modStmt->bind_param("i", $progLookupUid);
        $modStmt->execute();
        $res = $modStmt->get_result();
        $idx = 1;
        while ($r = $res->fetch_assoc()) {
            $isAssessment = ($r['type'] === 'assessment') || (stripos($r['title'], 'assessment') !== false) || (stripos($r['title'], 'quiz') !== false);
            $statusStr = strtolower($r['status'] ?? 'not_started');
            $passScore = (int)($r['passing_score'] ?: $r['module_passing_score'] ?: 80);
            $rawScore = $r['score'] !== null ? (float)$r['score'] : null;

            $isFailed = ($statusStr === 'failed') || ($isAssessment && $rawScore !== null && $rawScore < $passScore && $statusStr !== 'not_started');
            $isDone = !$isFailed && (!empty($r['is_completed']) || in_array($statusStr, ['completed', 'passed'], true)) && !in_array($statusStr, ['incomplete', 'in_progress', 'suspend', 'browsed'], true);
            $isInProgress = !$isDone && !$isFailed && ($statusStr === 'in_progress' || in_array($statusStr, ['incomplete', 'suspend', 'browsed'], true) || (int)($r['progress_percent'] ?? 0) > 0 || (int)($r['total_time_seconds'] ?? 0) > 0);

            if ($isDone) {
                $statusFinal = 'completed';
            } elseif ($isFailed) {
                $statusFinal = 'failed';
            } elseif ($isInProgress) {
                $statusFinal = 'in_progress';
            } else {
                $statusFinal = 'ready';
            }

            $timeSpent = $r['total_time'] ?: '00:00:00';
            if ($timeSpent !== '00:00:00' && strpos($timeSpent, '.') !== false) {
                $timeSpent = explode('.', $timeSpent)[0];
            }

            $modules[] = [
                'id' => (int)$r['id'],
                'index' => $idx++,
                'title' => $r['title'],
                'type' => $r['type'],
                'is_assessment' => $isAssessment,
                'status' => $statusFinal,
                'is_completed' => $isDone,
                'is_failed' => $isFailed,
                'is_in_progress' => $isInProgress,
                'progress_percent' => (int)($r['progress_percent'] ?? ($isDone ? 100 : 0)),
                'highest_progress_percent' => (int)($r['highest_progress_percent'] ?? $r['progress_percent'] ?? 0),
                'score' => $r['score'] !== null ? (float)$r['score'] : null,
                'highest_score' => $r['highest_score'] !== null ? (float)$r['highest_score'] : null,
                'passing_score' => (int)($r['passing_score'] ?: $r['module_passing_score'] ?: 80),
                'time_spent' => $timeSpent,
                'time_seconds' => (int)($r['total_time_seconds'] ?? 0),
                'attempts' => (int)($r['attempts'] ?? ($isAssessment ? 1 : 0)),
                'slides_viewed' => (int)($r['slides_viewed'] ?? 0),
                'total_slides' => (int)($r['total_slides'] ?? 0),
                'last_synced_at' => $r['last_synced_at']
            ];
        }
        $modStmt->close();
    }

    // Authoritative Induction Assessment Attempts strictly from onboard_induction_assessment_attempts
    $attempts = getInductionAttemptsForUser($conn, $employeeHexaUid);
}

// 6. Fetch Study Session Checkpoints Log (with timezone conversion from UTC to Asia/Kolkata)
$sessions = [];
$tblCheck = $conn->query("SHOW TABLES LIKE 'onboard_module_sessions'");
if ($tblCheck && $tblCheck->num_rows > 0) {
    $sessStmt = $conn->prepare("
        SELECT 
            s.id,
            s.module_id,
            COALESCE(m.title, 'SCORM Activity') AS module_title,
            s.session_time,
            s.session_seconds,
            s.slides_viewed,
            s.progress_percent,
            s.score,
            s.status,
            s.recorded_at
        FROM onboard_module_sessions s
        LEFT JOIN onboard_modules m ON m.id = s.module_id
        WHERE s.user_id = ?
        ORDER BY s.recorded_at DESC
        LIMIT 20
    ");
    if ($sessStmt) {
        $sessStmt->bind_param("i", $progLookupUid);
        $sessStmt->execute();
        $sessRes = $sessStmt->get_result();
        while ($s = $sessRes->fetch_assoc()) {
            $sessDate = '';
            if (!empty($s['recorded_at'])) {
                try {
                    $dt = new DateTime($s['recorded_at'], new DateTimeZone('UTC'));
                    $dt->setTimezone(new DateTimeZone('Asia/Kolkata'));
                    $sessDate = $dt->format('M j, Y, g:i A');
                } catch (\Exception $e) {
                    $sessDate = date('M j, Y, g:i A', strtotime($s['recorded_at']));
                }
            }

            $sessions[] = [
                'id' => (int)$s['id'],
                'module_id' => (int)$s['module_id'],
                'module_title' => $s['module_title'] ?? 'Module',
                'session_time' => $s['session_time'],
                'session_seconds' => (int)$s['session_seconds'],
                'slides_viewed' => (int)$s['slides_viewed'],
                'progress_percent' => (int)$s['progress_percent'],
                'score' => $s['score'] !== null ? (float)$s['score'] : null,
                'status' => $s['status'],
                'date' => $sessDate,
                'recorded_at' => $s['recorded_at']
            ];
        }
        $sessStmt->close();
    }
}

// 7. Calculate overall progress summary metrics
$totalModCount = count($modules);
$completedModCount = 0;
foreach ($modules as $m) {
    if (!empty($m['is_completed'])) {
        $completedModCount++;
    }
}
$overallPercent = $totalModCount > 0 ? round(($completedModCount / $totalModCount) * 100) : 0;

if (function_exists('flushMoodleApiUsage')) {
    flushMoodleApiUsage($conn, 'admin_employee_report', $currentUserId, $moodleUserId);
}

$attempts = getInductionAttemptsForUser($conn, $employeeHexaUid);

$empReportJson = json_encode([
    'success' => true,
    'employee' => $employeeData,
    'progress_summary' => [
        'completed_modules' => $completedModCount,
        'total_modules' => $totalModCount,
        'percent' => $overallPercent,
        'total_attempts' => count($attempts)
    ],
    'modules' => $modules,
    'attempts' => $attempts,
    'sessions' => $sessions
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

if (empty($hadMoodleErrors) && $empReportJson !== false && !empty($modules)) {
    if (!is_dir($empProgCacheDir)) @mkdir($empProgCacheDir, 0755, true);
    @file_put_contents($empProgCacheFile, $empReportJson, LOCK_EX);
}

echo $empReportJson;
