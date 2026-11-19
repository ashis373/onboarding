<?php
// Endpoint: api/moodle/launch.php
// Bridges Timesheet PHP Session to Moodle LMS via local_ssologin (v1.6)

$NEED_PDO = false;
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/sync_user.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) {
    header('Location: ../../../index.php?error=unauthorized');
    exit;
}

// 1. Verify active employee
$stmt = $conn->prepare("SELECT * FROM user WHERE uid = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    die("Access denied: Employee record not found.");
}

if ((int)$user['status'] !== 0) {
    die("Access denied: Inactive or unverified employee.");
}

// 2. Fetch or automatically establish linked Moodle mapping
$stmt = $conn->prepare("SELECT moodle_user_id, sync_status FROM onboard_moodle_users WHERE hexa_uid = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$mapping = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (empty($mapping['moodle_user_id']) || $mapping['sync_status'] !== 'Linked') {
    $syncedId = autoSyncMoodleUser($user_id, $conn);
    // Objective 5: Refresh Moodle user mapping after automatic synchronization
    $stmt = $conn->prepare("SELECT moodle_user_id, sync_status FROM onboard_moodle_users WHERE hexa_uid = ?");
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $mapping = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

// 3. Determine target redirect URL inside Moodle
$cmid = isset($_GET['cmid']) ? (int)$_GET['cmid'] : (isset($_GET['cm']) ? (int)$_GET['cm'] : null);
$courseid = isset($_GET['courseid']) ? (int)$_GET['courseid'] : null;
$scoid = isset($_GET['scoid']) ? (int)$_GET['scoid'] : null;
$targetModuleId = isset($_GET['module_id']) ? (int)$_GET['module_id'] : null;
$passedTitle = !empty($_GET['title']) ? trim($_GET['title']) : null;
$currentorg = !empty($_GET['currentorg']) ? trim($_GET['currentorg']) : null;
if ($currentorg !== null && strpos($currentorg, '%') !== false) {
    $currentorg = rawurldecode($currentorg);
}
$launchMode = !empty($_GET['mode']) ? trim($_GET['mode']) : 'normal';
$newAttempt = !empty($_GET['newattempt']) ? trim($_GET['newattempt']) : null;

$redirectAfter = '';
if ($cmid || $courseid || $targetModuleId) {
    // 1. Authoritative curriculum SCORM & SCO Master Matrix
    // Maps Moodle Course Module ID (cmid) -> scoid, courseid, organization, title
    $cmidMasterMap = [
        6  => ['scoid' => 8,  'courseid' => 2,  'org' => 'M1_-_Onboarding_Program_ORG', 'title' => 'Employee Onboarding 1 SCORM'],
        2  => ['scoid' => 2,  'courseid' => 2,  'org' => 'M1_-_Onboarding_Program_ORG', 'title' => 'Employee Onboarding Module'],
        5  => ['scoid' => 6,  'courseid' => 2,  'org' => 'Assessment_ORG',               'title' => 'Employee Assessment'],
        4  => ['scoid' => 32, 'courseid' => 3,  'org' => 'M_11_-_Assessment_ORG',        'title' => 'Employee_Assessment'],
        7  => ['scoid' => 10, 'courseid' => 4,  'org' => 'M2_-_Work_Rules_and_Work_Life_ORG', 'title' => 'Work Rules and Work Life M-2'],
        8  => ['scoid' => 12, 'courseid' => 5,  'org' => 'M3_-_Leave_&_Holidays_ORG',   'title' => 'Leave & Holidays'],
        9  => ['scoid' => 14, 'courseid' => 6,  'org' => 'M5_-_IT,_Confidentiality_&_Security_ORG', 'title' => 'IT, Confidentiality & Security'],
        10 => ['scoid' => 18, 'courseid' => 8,  'org' => 'M4_-_Code_of_Conduct_and_Workplace_Behavior_ORG', 'title' => 'Code of Conduct and Workplace Behavior'],
        11 => ['scoid' => 20, 'courseid' => 9,  'org' => 'M6_-_Prevention_of_Sexual_Harassment_(POSH)_ORG', 'title' => 'Prevention of Sexual Harassment'],
        12 => ['scoid' => 22, 'courseid' => 10, 'org' => 'M7_-_Compensation_&_Benefits_ORG', 'title' => 'Compensation and Benefits'],
        14 => ['scoid' => 24, 'courseid' => 11, 'org' => 'M8_-_Performance_and_Career_Growth_ORG', 'title' => 'Performance and Career Growth'],
        15 => ['scoid' => 26, 'courseid' => 12, 'org' => 'M9_-_HR_Processes_&_Employee_Support_ORG', 'title' => 'HR Processes and Employee Support'],
        16 => ['scoid' => 28, 'courseid' => 13, 'org' => 'M10_-_Safety,_Security,_and_Workplace_Essentials_ORG', 'title' => 'Safety, Security, and Workplace Essentials'],
    ];

    // Maps Moodle Course ID -> primary cmid, scoid, organization
    $courseMasterMap = [
        2  => ['cmid' => 6,  'scoid' => 8,  'org' => 'M1_-_Onboarding_Program_ORG'],
        3  => ['cmid' => 4,  'scoid' => 32, 'org' => 'M_11_-_Assessment_ORG'],
        4  => ['cmid' => 7,  'scoid' => 10, 'org' => 'M2_-_Work_Rules_and_Work_Life_ORG'],
        5  => ['cmid' => 8,  'scoid' => 12, 'org' => 'M3_-_Leave_&_Holidays_ORG'],
        6  => ['cmid' => 9,  'scoid' => 14, 'org' => 'M5_-_IT,_Confidentiality_&_Security_ORG'],
        8  => ['cmid' => 10, 'scoid' => 18, 'org' => 'M4_-_Code_of_Conduct_and_Workplace_Behavior_ORG'],
        9  => ['cmid' => 11, 'scoid' => 20, 'org' => 'M6_-_Prevention_of_Sexual_Harassment_(POSH)_ORG'],
        10 => ['cmid' => 12, 'scoid' => 22, 'org' => 'M7_-_Compensation_&_Benefits_ORG'],
        11 => ['cmid' => 14, 'scoid' => 24, 'org' => 'M8_-_Performance_and_Career_Growth_ORG'],
        12 => ['cmid' => 15, 'scoid' => 26, 'org' => 'M9_-_HR_Processes_&_Employee_Support_ORG'],
        13 => ['cmid' => 16, 'scoid' => 28, 'org' => 'M10_-_Safety,_Security,_and_Workplace_Essentials_ORG'],
    ];

    // Maps Onboard internal module_id -> cmid, scoid, courseid, organization
    $moduleMasterMap = [
        5  => ['cmid' => 6,  'scoid' => 8,  'courseid' => 2,  'org' => 'M1_-_Onboarding_Program_ORG'],
        6  => ['cmid' => 4,  'scoid' => 32, 'courseid' => 3,  'org' => 'M_11_-_Assessment_ORG'],
        7  => ['cmid' => 7,  'scoid' => 10, 'courseid' => 4,  'org' => 'M2_-_Work_Rules_and_Work_Life_ORG'],
        8  => ['cmid' => 8,  'scoid' => 12, 'courseid' => 5,  'org' => 'M3_-_Leave_&_Holidays_ORG'],
        9  => ['cmid' => 9,  'scoid' => 14, 'courseid' => 6,  'org' => 'M5_-_IT,_Confidentiality_&_Security_ORG'],
        10 => ['cmid' => 10, 'scoid' => 18, 'courseid' => 8,  'org' => 'M4_-_Code_of_Conduct_and_Workplace_Behavior_ORG'],
        11 => ['cmid' => 11, 'scoid' => 20, 'courseid' => 9,  'org' => 'M6_-_Prevention_of_Sexual_Harassment_(POSH)_ORG'],
        12 => ['cmid' => 12, 'scoid' => 22, 'courseid' => 10, 'org' => 'M7_-_Compensation_&_Benefits_ORG'],
        13 => ['cmid' => 14, 'scoid' => 24, 'courseid' => 11, 'org' => 'M8_-_Performance_and_Career_Growth_ORG'],
        14 => ['cmid' => 15, 'scoid' => 26, 'courseid' => 12, 'org' => 'M9_-_HR_Processes_&_Employee_Support_ORG'],
        15 => ['cmid' => 16, 'scoid' => 28, 'courseid' => 13, 'org' => 'M10_-_Safety,_Security,_and_Workplace_Essentials_ORG'],
    ];

    // Induction Assessment Course 3 Authoritative Resolution & Database Self-Healing
    if ($courseid === 3 || $cmid === 4 || $targetModuleId === 6 || (!empty($passedTitle) && stripos($passedTitle, 'assess') !== false)) {
        $courseid = 3;
        $cmid = 4;
        $scoid = 32;
        $currentorg = 'M_11_-_Assessment_ORG';
        if ($conn) {
            @$conn->query("UPDATE onboard_modules SET moodle_cmid = 4, moodle_scoid = 32 WHERE moodle_course_id = 3 OR id = 6");
        }
    }

    // Resolution Step 1: If cmid provided, resolve from cmidMasterMap
    if ($cmid && isset($cmidMasterMap[$cmid])) {
        if (!$scoid) $scoid = $cmidMasterMap[$cmid]['scoid'];
        if (empty($currentorg)) $currentorg = $cmidMasterMap[$cmid]['org'];
        if (!$courseid) $courseid = $cmidMasterMap[$cmid]['courseid'];
    }

    // Resolution Step 2: If courseid provided, resolve from courseMasterMap
    if ($courseid && isset($courseMasterMap[$courseid])) {
        if (!$cmid) $cmid = $courseMasterMap[$courseid]['cmid'];
        if (!$scoid) $scoid = $courseMasterMap[$courseid]['scoid'];
        if (empty($currentorg)) $currentorg = $courseMasterMap[$courseid]['org'];
    }

    // Resolution Step 3: If targetModuleId provided, resolve from moduleMasterMap
    if ($targetModuleId && isset($moduleMasterMap[$targetModuleId])) {
        if (!$cmid) $cmid = $moduleMasterMap[$targetModuleId]['cmid'];
        if (!$scoid) $scoid = $moduleMasterMap[$targetModuleId]['scoid'];
        if (!$courseid) $courseid = $moduleMasterMap[$targetModuleId]['courseid'];
        if (empty($currentorg)) $currentorg = $moduleMasterMap[$targetModuleId]['org'];
    }

    // Resolution Step 4: Database fallback from onboard_modules (by cmid, courseid, or module_id)
    if ((!$scoid || !$cmid) && $conn) {
        $chkModSql = "SELECT moodle_cmid, moodle_scoid, moodle_course_id FROM onboard_modules WHERE ";
        $chkParams = [];
        $chkTypes = '';
        if ($cmid) {
            $chkModSql .= "moodle_cmid = ? ";
            $chkParams[] = $cmid;
            $chkTypes .= 'i';
        } elseif ($targetModuleId) {
            $chkModSql .= "id = ? ";
            $chkParams[] = $targetModuleId;
            $chkTypes .= 'i';
        } elseif ($courseid) {
            $chkModSql .= "moodle_course_id = ? ";
            $chkParams[] = $courseid;
            $chkTypes .= 'i';
        }
        $chkModSql .= "LIMIT 1";

        if (!empty($chkTypes)) {
            $chkStmt = $conn->prepare($chkModSql);
            if ($chkStmt) {
                $chkStmt->bind_param($chkTypes, ...$chkParams);
                $chkStmt->execute();
                $mRow = $chkStmt->get_result()->fetch_assoc();
                $chkStmt->close();
                if ($mRow) {
                    if (!$scoid && !empty($mRow['moodle_scoid'])) {
                        $scoid = (int)$mRow['moodle_scoid'];
                    }
                    if (!$cmid && !empty($mRow['moodle_cmid'])) {
                        $cmid = (int)$mRow['moodle_cmid'];
                    }
                    if (!$courseid && !empty($mRow['moodle_course_id'])) {
                        $courseid = (int)$mRow['moodle_course_id'];
                    }
                }
            }
        }
    }

    // Resolution Step 5: Dynamic Moodle Web Services discovery if scoid is still missing
    if (!$scoid && ($courseid || $cmid)) {
        $targetCId = $courseid;
        $batchScorms = callMoodleWs('mod_scorm_get_scorms_by_courses', [
            'courseids' => $targetCId ? [$targetCId] : []
        ], $conn);

        $matchedScorm = null;
        if (!empty($batchScorms['scorms']) && is_array($batchScorms['scorms'])) {
            foreach ($batchScorms['scorms'] as $scItem) {
                if ($cmid && !empty($scItem['coursemodule']) && (int)$scItem['coursemodule'] === $cmid) {
                    $matchedScorm = $scItem;
                    break;
                }
            }
            if (!$matchedScorm && !empty($batchScorms['scorms'][0])) {
                $matchedScorm = $batchScorms['scorms'][0];
            }
        }

        if ($matchedScorm && !empty($matchedScorm['id'])) {
            $scormInstId = (int)$matchedScorm['id'];
            if (!$cmid && !empty($matchedScorm['coursemodule'])) {
                $cmid = (int)$matchedScorm['coursemodule'];
            }
            $scoesRes = callMoodleWs('mod_scorm_get_scorm_scoes', [
                'scormid' => $scormInstId
            ], $conn);
            if (!empty($scoesRes['scoes']) && is_array($scoesRes['scoes'])) {
                foreach ($scoesRes['scoes'] as $sco) {
                    if (empty($currentorg) && !empty($sco['organization'])) {
                        $currentorg = $sco['organization'];
                    }
                    if (($sco['scormtype'] ?? '') === 'sco' || !empty($sco['launch'])) {
                        $scoid = (int)$sco['id'];
                        break;
                    }
                }
                if (!$scoid && !empty($scoesRes['scoes'][0]['id'])) {
                    $scoid = (int)$scoesRes['scoes'][0]['id'];
                }
            }
        }
    }

    // 4. Construct DIRECT SCORM Player launch URL
    // CRITICAL: We launch directly into Moodle's /mod/scorm/player.php with display=popup.
    // In Moodle 4.3, display=popup forces $PAGE->set_pagelayout('embedded'), which completely
    // strips the Moodle navigation header, drawer, and activity landing page chrome.
    // This loads the pure SCORM player directly inside the HexaLearn Onboard player iframe.
    if ($cmid && !empty($scoid)) {
        $playerParams = [
            'cm'         => $cmid,
            'scoid'      => $scoid,
            'mode'       => $launchMode,
            'display'    => 'popup'
        ];
        if (!empty($currentorg)) {
            $playerParams['currentorg'] = $currentorg;
        }
        if (!empty($newAttempt)) {
            $playerParams['newattempt'] = $newAttempt;
        }
        $redirectAfter = '/mod/scorm/player.php?' . http_build_query($playerParams);
    }
}

// FAIL CLOSED: If SCOID or CMID cannot be resolved, halt with controlled error.
// Never redirect without a player URL, and never redirect to Moodle landing pages or dashboard.
if (empty($redirectAfter)) {
    http_response_code(400);
    logMoodleSecurityEvent($conn, $user_id, 'scorm_launch_unresolved_activity', 'failed', 'Could not resolve a valid SCORM activity and SCO ID', [
        'cmid' => $cmid,
        'courseid' => $courseid,
        'module_id' => $targetModuleId
    ]);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Unable to Launch Activity | HexaLearn Onboard</title>
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #020617; color: #f8fafc; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 20px; box-sizing: border-box; }
            .card { background: #0f172a; border: 1px solid #1e293b; border-radius: 20px; padding: 36px 32px; max-width: 480px; text-align: center; box-shadow: 0 20px 30px -10px rgba(0,0,0,0.7); }
            .icon { width: 64px; height: 64px; border-radius: 50%; background: rgba(239, 68, 68, 0.12); color: #ef4444; display: inline-flex; align-items: center; justify-content: center; font-size: 30px; margin-bottom: 20px; border: 1px solid rgba(239,68,68,0.25); }
            h1 { margin: 0 0 10px; font-size: 20px; font-weight: 800; color: #ffffff; }
            p { margin: 0 0 24px; font-size: 13px; color: #94a3b8; line-height: 1.6; }
            .btn { display: inline-block; background: #00A389; color: white; padding: 11px 26px; border-radius: 12px; font-size: 13px; font-weight: 700; text-decoration: none; }
            .btn:hover { background: #008f78; }
        </style>
    </head>
    <body>
        <div class="card">
            <div class="icon">⚠️</div>
            <h1>Unable to Launch Course</h1>
            <p>The learning activity configuration could not be verified. Please return to the learning dashboard or contact your system administrator.</p>
            <div>
                <a href="<?php echo $onboardModulesUrl; ?>" class="btn">Return to Learning Modules</a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// 3.5 Structured Security & Error Logging Helper
function logMoodleSecurityEvent($conn, $userId, $action, $status, $message, $context = []) {
    if (!$conn) return;
    $conn->query("CREATE TABLE IF NOT EXISTS `onboard_error_logs` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT NULL,
        `action` VARCHAR(100) NOT NULL,
        `status` VARCHAR(50) NOT NULL,
        `message` TEXT NOT NULL,
        `context_json` TEXT NULL,
        `ip_address` VARCHAR(50) NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_action (`action`),
        INDEX idx_created_at (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    $stmt = $conn->prepare("INSERT INTO `onboard_error_logs` (`user_id`, `action`, `status`, `message`, `context_json`, `ip_address`) VALUES (?, ?, ?, ?, ?, ?)");
    if ($stmt) {
        $cJson = !empty($context) ? json_encode($context, JSON_UNESCAPED_SLASHES) : null;
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $stmt->bind_param("isssss", $userId, $action, $status, $message, $cJson, $ip);
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * Authoritative Moodle Course & Activity Completion Resolver
 * Primary: Moodle Activity Completion (core_completion_get_activities_completion_status)
 * Fallback: Multi-SCO & Multi-Attempt SCORM Verification (mod_scorm_get_scorm_sco_tracks)
 * Returns array: ['verified' => bool, 'is_completed' => bool, 'source' => string, 'error' => string|null]
 */
function verifyMoodleActivityCompletion($courseId, $cmid, $scoid, $moodleUid, $conn) {
    if ($moodleUid <= 0) {
        return ['verified' => false, 'is_completed' => false, 'source' => 'none', 'error' => 'No Moodle UID'];
    }

    // 1. PRIMARY FOR NON-SCORM ACTIVITIES ONLY: Moodle Core Activity Completion Engine
    // Skip for SCORM courses ($scoid > 0) since SCORM courses return empty statuses and use SCORM tracks directly
    if ($scoid <= 0 && $courseId > 0 && $cmid > 0) {
        $complRes = callMoodleWs('core_completion_get_activities_completion_status', [
            'courseid' => (int)$courseId,
            'userid' => (int)$moodleUid
        ], $conn);

        if (!empty($complRes) && empty($complRes['error']) && !empty($complRes['statuses']) && is_array($complRes['statuses'])) {
            foreach ($complRes['statuses'] as $st) {
                if ((int)($st['cmid'] ?? 0) === (int)$cmid) {
                    $state = (int)($st['state'] ?? 0);
                    // Moodle Activity Completion States: 1 = Complete, 2 = Complete Pass, 3 = Complete Fail, 0 = Incomplete
                    $isDone = ($state === 1 || $state === 2);
                    return [
                        'verified' => true,
                        'is_completed' => $isDone,
                        'source' => 'moodle_activity_completion',
                        'state' => $state,
                        'error' => null
                    ];
                }
            }
        }
    }

    // 2. SCORM Track Verification across Active & Multi-Attempt Tracks
    if ($scoid > 0) {
        // Query SCORM tracks (default gives current active attempt)
        $tracksRes = callMoodleWs('mod_scorm_get_scorm_sco_tracks', [
            'scoid' => (int)$scoid,
            'userid' => (int)$moodleUid
        ], $conn);

        if (!empty($tracksRes) && empty($tracksRes['error'])) {
            $tracks = $tracksRes['data']['tracks'] ?? [];
            if (empty($tracks)) {
                // Moodle authoritatively confirms 0 tracks recorded (course not started / incomplete)
                return [
                    'verified' => true,
                    'is_completed' => false,
                    'source' => 'scorm_zero_tracks',
                    'status' => 'not_started',
                    'error' => null
                ];
            }

            $statusVal = '';
            foreach ($tracks as $t) {
                if (($t['element'] ?? '') === 'cmi.core.lesson_status' || ($t['element'] ?? '') === 'status') {
                    $statusVal = strtolower(trim($t['value']));
                    break;
                }
            }
            if ($statusVal === 'completed' || $statusVal === 'passed') {
                return [
                    'verified' => true,
                    'is_completed' => true,
                    'source' => 'scorm_active_track',
                    'status' => $statusVal,
                    'error' => null
                ];
            }
            if (in_array($statusVal, ['incomplete', 'browsed', 'not_started', 'suspend', ''], true)) {
                // If current attempt is incomplete, inspect if employee had any previous passing attempt
                $attCntRes = callMoodleWs('mod_scorm_get_scorm_attempt_count', [
                    'scoid' => (int)$scoid,
                    'userid' => (int)$moodleUid
                ], $conn);
                $attCnt = (int)($attCntRes['attemptscount'] ?? 1);
                if ($attCnt > 1) {
                    for ($a = $attCnt - 1; $a >= 1; $a--) {
                        $prevAttRes = callMoodleWs('mod_scorm_get_scorm_sco_tracks', [
                            'scoid' => (int)$scoid,
                            'userid' => (int)$moodleUid,
                            'attempt' => $a
                        ], $conn);
                        if (!empty($prevAttRes['data']['tracks'])) {
                            foreach ($prevAttRes['data']['tracks'] as $pt) {
                                if (($pt['element'] ?? '') === 'cmi.core.lesson_status' || ($pt['element'] ?? '') === 'status') {
                                    $pStatus = strtolower(trim($pt['value']));
                                    if ($pStatus === 'completed' || $pStatus === 'passed') {
                                        return [
                                            'verified' => true,
                                            'is_completed' => true,
                                            'source' => 'scorm_previous_attempt_pass',
                                            'error' => null
                                        ];
                                    }
                                }
                            }
                        }
                    }
                }
                return [
                    'verified' => true,
                    'is_completed' => false,
                    'source' => 'scorm_track_incomplete',
                    'status' => $statusVal,
                    'error' => null
                ];
            }
        } elseif (!empty($tracksRes['error'])) {
            return [
                'verified' => false,
                'is_completed' => false,
                'source' => 'api_error',
                'error' => $tracksRes['message'] ?? 'API error'
            ];
        }
    }

    // If Moodle API returned successfully without errors, but found NO completion record:
    // Moodle authoritatively verifies that this course is INCOMPLETE.
    $apiHadError = !empty($complRes['error']) || (!empty($tracksRes) && !empty($tracksRes['error']));
    if (!$apiHadError && ($courseId > 0 || $scoid > 0)) {
        return [
            'verified' => true,
            'is_completed' => false,
            'source' => 'moodle_uncompleted',
            'error' => null
        ];
    }

    return [
        'verified' => false,
        'is_completed' => false,
        'source' => 'unverified',
        'error' => 'No completion record returned'
    ];
}

// 3.6 Server-Side Sequential Progression Security Guard
$seqLocking = true; // Sequential course progression is the authoritative default rule
$chkSet = $conn->query("SELECT `value` FROM onboard_settings WHERE `key` = 'sequential_locking' LIMIT 1");
if ($chkSet && $sRow = $chkSet->fetch_assoc()) {
    $seqLocking = ((string)$sRow['value'] === '1' || strtolower((string)$sRow['value']) === 'true');
}

$isAdmin = in_array((string)$user_id, ['26', '64', '205']) || (string)($user['user_role'] ?? '') === '1';

if ($seqLocking && $cmid) {
    // Fetch active learning modules in order of sort_order ASC, id ASC
    $hasSortCol = false;
    $chkSort = $conn->query("SHOW COLUMNS FROM onboard_modules LIKE 'sort_order'");
    if ($chkSort && $chkSort->num_rows > 0) {
        $hasSortCol = true;
    }
    $sortSelect = $hasSortCol ? "IFNULL(NULLIF(sort_order, 0), id) AS sort_order" : "id AS sort_order";
    $sortClause = $hasSortCol ? "ORDER BY sort_order ASC, id ASC" : "ORDER BY id ASC";

    $modListQuery = $conn->query("
        SELECT id, title, moodle_course_id, moodle_cmid, moodle_scoid, {$sortSelect}
        FROM onboard_modules 
        WHERE is_active = 1 
          AND LOWER(title) NOT LIKE '%assessment%' 
          AND LOWER(title) NOT LIKE '%quiz%'
        {$sortClause}
    ");
    $orderedModules = [];
    if ($modListQuery && $modListQuery->num_rows > 0) {
        while ($mRow = $modListQuery->fetch_assoc()) {
            $orderedModules[] = $mRow;
        }
    } else {
        $fallbackQuery = $conn->query("SELECT id, title, moodle_course_id, moodle_cmid, moodle_scoid, {$sortSelect} FROM onboard_modules WHERE is_active = 1 {$sortClause}");
        if ($fallbackQuery) {
            while ($mRow = $fallbackQuery->fetch_assoc()) {
                $orderedModules[] = $mRow;
            }
        }
    }

    // Locate the current course in sequence (match module_id, moodle_cmid, moodle_course_id, moodle_scoid, or title)
    $currentIndex = -1;

    // 1. Exact match by target module_id if passed
    if ($targetModuleId > 0) {
        foreach ($orderedModules as $idx => $mod) {
            if ((int)$mod['id'] === $targetModuleId) {
                $currentIndex = $idx;
                break;
            }
        }
    }

    // 2. Exact match by moodle_cmid
    if ($currentIndex === -1 && $cmid > 0) {
        foreach ($orderedModules as $idx => $mod) {
            if (!empty($mod['moodle_cmid']) && (int)$mod['moodle_cmid'] === $cmid) {
                $currentIndex = $idx;
                break;
            }
        }
    }

    // 3. Exact match by moodle_course_id
    if ($currentIndex === -1 && $courseid > 0) {
        foreach ($orderedModules as $idx => $mod) {
            if (!empty($mod['moodle_course_id']) && (int)$mod['moodle_course_id'] === $courseid) {
                $currentIndex = $idx;
                break;
            }
        }
    }

    // 4. Exact match by moodle_scoid
    if ($currentIndex === -1 && $scoid > 0) {
        foreach ($orderedModules as $idx => $mod) {
            if (!empty($mod['moodle_scoid']) && (int)$mod['moodle_scoid'] === $scoid) {
                $currentIndex = $idx;
                break;
            }
        }
    }

    // 5. Match by course title from query string
    if ($currentIndex === -1 && !empty($passedTitle)) {
        $cleanPassed = strtolower(preg_replace('/[^a-z0-9]/', '', $passedTitle));
        foreach ($orderedModules as $idx => $mod) {
            $cleanModTitle = strtolower(preg_replace('/[^a-z0-9]/', '', $mod['title'] ?? ''));
            if (!empty($cleanModTitle) && ($cleanModTitle === $cleanPassed || strpos($cleanModTitle, $cleanPassed) !== false || strpos($cleanPassed, $cleanModTitle) !== false)) {
                $currentIndex = $idx;
                break;
            }
        }
    }

    $moodleUid = !empty($mapping['moodle_user_id']) ? (int)$mapping['moodle_user_id'] : $user_id;

    // Check if Admin Override exists for this employee and course/module/cmid
    $isAdminUnlocked = false;
    $currModIdForCheck = ($currentIndex >= 0 && !empty($orderedModules[$currentIndex]['id'])) ? (int)$orderedModules[$currentIndex]['id'] : 0;
    $cIdForCheck = $courseid ?: ($currentIndex >= 0 && !empty($orderedModules[$currentIndex]['moodle_course_id']) ? (int)$orderedModules[$currentIndex]['moodle_course_id'] : 0);
    $chkOvr = $conn->prepare("
        SELECT id, reason, admin_name 
        FROM onboard_course_unlock_overrides 
        WHERE user_id = ? 
          AND (
            (? > 0 AND moodle_cmid = ?) OR 
            (? > 0 AND module_id = ?) OR 
            (? > 0 AND moodle_course_id = ?)
          )
          AND is_unlocked = 1 
        LIMIT 1
    ");
    if ($chkOvr) {
        $chkOvr->bind_param("iiiiiii", $user_id, $cmid, $cmid, $currModIdForCheck, $currModIdForCheck, $cIdForCheck, $cIdForCheck);
        $chkOvr->execute();
        $ovrRes = $chkOvr->get_result()->fetch_assoc();
        $chkOvr->close();
        if ($ovrRes) {
            $isAdminUnlocked = true;
        }
    }

    // =========================================================================
    // CONDITION 1: SECURE COURSE-SCOPED 30-SECOND SESSION TOKEN
    // Strictly scoped to exact employee and course, with rigorous invalidation
    // =========================================================================
    $authCacheKey = "sso_auth_{$user_id}_{$cmid}";
    $cachedAuth = $_SESSION[$authCacheKey] ?? null;

    // Strict Invalidation 1: URL specifies retake or review mode
    if (!empty($newAttempt) || $launchMode === 'review') {
        unset($_SESSION[$authCacheKey]);
        $cachedAuth = null;
    }

    // Strict Invalidation 2: If local database shows course completed, destroy token immediately
    if ($cachedAuth && $currModIdForCheck > 0) {
        $chkDoneLocal = $conn->prepare("SELECT is_completed, status, progress_percent FROM onboard_module_progress WHERE user_id = ? AND module_id = ? LIMIT 1");
        if ($chkDoneLocal) {
            $chkDoneLocal->bind_param("ii", $user_id, $currModIdForCheck);
            $chkDoneLocal->execute();
            $dlRes = $chkDoneLocal->get_result()->fetch_assoc();
            $chkDoneLocal->close();
            if ($dlRes && ((int)$dlRes['is_completed'] === 1 || strtolower((string)$dlRes['status']) === 'completed' || (int)($dlRes['progress_percent'] ?? 0) === 100)) {
                unset($_SESSION[$authCacheKey]);
                $cachedAuth = null;
            }
        }
    }

    $hasValidInProgressAuth = (
        !empty($cachedAuth) &&
        ($cachedAuth['status'] ?? '') === 'in_progress' &&
        (int)($cachedAuth['cmid'] ?? 0) === (int)$cmid &&
        (int)($cachedAuth['user_id'] ?? 0) === (int)$user_id &&
        (time() - (int)($cachedAuth['auth_time'] ?? 0)) < 30
    );

    // If no valid in-progress authorization token exists, execute full Moodle authoritative checks
    if (!$hasValidInProgressAuth) {

        // =====================================================================
        // RULE 1: COMPLETED COURSE LOCK CHECK (Moodle Authoritative)
        // Never reopen completed courses without an authorized admin override
        // =====================================================================
        if (!$isAdminUnlocked && $currentIndex >= 0 && !empty($orderedModules[$currentIndex]['id'])) {
            $currModId = (int)$orderedModules[$currentIndex]['id'];
            $currCmid = (int)($orderedModules[$currentIndex]['moodle_cmid'] ?? $cmid);
            $currScoId = $scoid ?: (int)($orderedModules[$currentIndex]['moodle_scoid'] ?? 0);
            $currCourseId = $courseid ?: (int)($orderedModules[$currentIndex]['moodle_course_id'] ?? 0);
            if (!$currScoId && isset($cmidMasterMap[$currCmid])) {
                $currScoId = (int)$cmidMasterMap[$currCmid]['scoid'];
            }

            // Check local progress table (strictly scoped to authenticated hexa_uid)
            $localIsDone = false;
            $chkCurrProg = $conn->prepare("SELECT is_completed, status, progress_percent FROM onboard_module_progress WHERE user_id = ? AND module_id = ? LIMIT 1");
            if ($chkCurrProg) {
                $chkCurrProg->bind_param("ii", $user_id, $currModId);
                $chkCurrProg->execute();
                $currRes = $chkCurrProg->get_result()->fetch_assoc();
                $chkCurrProg->close();
                if ($currRes && ((int)$currRes['is_completed'] === 1 || strtolower((string)$currRes['status']) === 'completed' || (int)($currRes['progress_percent'] ?? 0) === 100)) {
                    $localIsDone = true;
                }
            }

            // Authoritative Moodle verification
            $moodleVerif = verifyMoodleActivityCompletion($currCourseId, $currCmid, $currScoId, $moodleUid, $conn);

            $isCourseDone = false;
            if ($moodleVerif['verified']) {
                if ($moodleVerif['is_completed']) {
                    $isCourseDone = true;
                    // Reconcile local database to completed
                    $conn->query("UPDATE onboard_module_progress SET is_completed = 1, status = 'completed', progress_percent = 100, last_synced_at = NOW() WHERE user_id = {$user_id} AND module_id = {$currModId}");
                } else {
                    // Moodle authoritatively verifies this course is incomplete
                    $isCourseDone = false;
                    if ($localIsDone) {
                        // Heal incorrect local completed flag
                        $conn->query("UPDATE onboard_module_progress SET is_completed = 0, status = 'in_progress', progress_percent = CASE WHEN progress_percent >= 100 THEN 0 ELSE progress_percent END WHERE user_id = {$user_id} AND module_id = {$currModId}");
                    }
                }
            } else {
                // If Moodle API is unavailable / error, fail safe: use verified local record
                $isCourseDone = $localIsDone;
                if (!empty($moodleVerif['error'])) {
                    logMoodleSecurityEvent($conn, $user_id, 'moodle_completion_check_failed', 'warning', $moodleVerif['error'], [
                        'cmid' => $currCmid,
                        'course_id' => $currCourseId
                    ]);
                }
            }

            if ($isCourseDone) {
                http_response_code(403);
                logMoodleSecurityEvent($conn, $user_id, 'course_launch_blocked_completed', 'blocked', 'Employee attempted to launch completed course without admin override', [
                    'cmid' => $currCmid,
                    'course_id' => $currCourseId
                ]);
                ?>
                <!DOCTYPE html>
                <html lang="en">
                <head>
                    <meta charset="UTF-8">
                    <title>Course Completed &amp; Locked | HexaLearn</title>
                    <meta name="viewport" content="width=device-width, initial-scale=1.0">
                    <style>
                        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #020617; color: #f8fafc; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 20px; box-sizing: border-box; }
                        .card { background: #0f172a; border: 1px solid #1e293b; border-radius: 20px; padding: 36px 32px; max-width: 500px; text-align: center; box-shadow: 0 20px 30px -10px rgba(0,0,0,0.7); }
                        .icon { width: 64px; height: 64px; border-radius: 50%; background: rgba(245, 158, 11, 0.12); color: #f59e0b; display: inline-flex; align-items: center; justify-content: center; font-size: 30px; margin-bottom: 20px; border: 1px solid rgba(245, 158, 11, 0.25); }
                        h1 { margin: 0 0 12px; font-size: 22px; font-weight: 800; color: #ffffff; letter-spacing: -0.02em; }
                        p { margin: 0 0 24px; font-size: 13.5px; color: #94a3b8; line-height: 1.6; }
                        .btn { display: inline-block; background: #00A389; color: white; padding: 11px 26px; border-radius: 12px; font-size: 13px; font-weight: 700; text-decoration: none; transition: all 0.2s; box-shadow: 0 4px 12px rgba(0,163,137,0.3); }
                        .btn:hover { background: #008f78; transform: translateY(-1px); }
                    </style>
                </head>
                <body>
                    <div class="card">
                        <div class="icon">🔒</div>
                        <h1>Course Completed &amp; Locked</h1>
                        <p>Once you complete a course, you cannot launch it again without your manager's approval. If you wish to relaunch the course, please contact your manager for authorization.</p>
                        <div>
                            <a href="<?php echo $onboardModulesUrl; ?>" class="btn">Return to Learning Roadmap</a>
                        </div>
                    </div>
                </body>
                </html>
                <?php
                exit;
            }
        }

        // =====================================================================
        // RULE 2: SEQUENTIAL PREREQUISITE CHECK (Fail-Closed Security)
        // Unlocks Course N only if Course N-1 is confirmed complete in Moodle
        // =====================================================================
        if (!$isAdminUnlocked && $currentIndex > 0) {
            $prevMod = $orderedModules[$currentIndex - 1];
            $prevModId = (int)$prevMod['id'];
            $prevCourseId = (int)($prevMod['moodle_course_id'] ?? 0);
            $prevCmid = (int)($prevMod['moodle_cmid'] ?? 0);
            $prevScoId = (int)($prevMod['moodle_scoid'] ?? 0);
            if (!$prevScoId && isset($cmidMasterMap[$prevCmid])) {
                $prevScoId = (int)$cmidMasterMap[$prevCmid]['scoid'];
            }
            $prevTitle = $prevMod['title'];

            // 1. Local Database Verification (strictly scoped to authenticated hexa_uid)
            $isPrevDone = false;
            $chkProg = $conn->prepare("
                SELECT p.is_completed, p.status, p.progress_percent, p.slides_viewed, p.total_slides 
                FROM onboard_module_progress p
                LEFT JOIN onboard_modules m ON m.id = p.module_id
                WHERE p.user_id = ? 
                  AND (
                      p.module_id = ? 
                      OR (? > 0 AND m.moodle_cmid = ?)
                      OR (? > 0 AND m.moodle_course_id = ?)
                  )
                ORDER BY p.is_completed DESC, p.progress_percent DESC 
                LIMIT 1
            ");
            if ($chkProg) {
                $chkProg->bind_param("iiiiii", $user_id, $prevModId, $prevCmid, $prevCmid, $prevCourseId, $prevCourseId);
                $chkProg->execute();
                $pRes = $chkProg->get_result()->fetch_assoc();
                $chkProg->close();
                if ($pRes) {
                    $pSt = strtolower(trim((string)($pRes['status'] ?? '')));
                    $pDone = (int)($pRes['is_completed'] ?? 0);
                    $pPct = (int)($pRes['progress_percent'] ?? 0);
                    $pViewed = (int)($pRes['slides_viewed'] ?? 0);
                    $pTot = (int)($pRes['total_slides'] ?? 0);
                    // Prerequisite completion is strictly Moodle-authoritative: must be 'completed' or 'passed'.
                    // Viewing slides alone (slides_viewed >= total_slides) NEVER unlocks the next course.
                    if (($pDone === 1 && $pPct >= 100) || in_array($pSt, ['completed', 'passed'], true)) {
                        $isPrevDone = true;
                    }
                }
            }

            // 2. Authoritative Live Moodle Verification (if local database does not confirm completion)
            $isTransientMoodleBusy = false;
            if (!$isPrevDone && $moodleUid > 0) {
                $prevMoodleVerif = verifyMoodleActivityCompletion($prevCourseId, $prevCmid, $prevScoId, $moodleUid, $conn);
                if (!$prevMoodleVerif['verified'] && !empty($prevMoodleVerif['error'])) {
                    // Retry once after 400ms on transient Moodle DB busy / timeout
                    usleep(400000);
                    $prevMoodleVerif = verifyMoodleActivityCompletion($prevCourseId, $prevCmid, $prevScoId, $moodleUid, $conn);
                }
                if ($prevMoodleVerif['verified'] && $prevMoodleVerif['is_completed']) {
                    $isPrevDone = true;
                    // Self-healing: Reconcile verified completion to local database (strictly for hexa_uid)
                    if ($prevModId > 0) {
                        $sUid = (int)$user_id;
                        $conn->query("
                            INSERT INTO onboard_module_progress 
                                (user_id, module_id, status, progress_percent, is_completed, last_synced_at)
                            VALUES 
                                ({$sUid}, {$prevModId}, 'completed', 100, 1, NOW())
                            ON DUPLICATE KEY UPDATE 
                                status = 'completed',
                                progress_percent = 100,
                                is_completed = 1,
                                last_synced_at = NOW()
                        ");
                    }
                } elseif (!$prevMoodleVerif['verified'] && !empty($prevMoodleVerif['error'])) {
                    $isTransientMoodleBusy = true;
                    // FAIL CLOSED: If Moodle API failed or database dropped, log failure and show friendly auto-retry loader
                    logMoodleSecurityEvent($conn, $user_id, 'prereq_moodle_verification_failed', 'fail_closed', $prevMoodleVerif['error'], [
                        'target_cmid' => $cmid,
                        'prereq_cmid' => $prevCmid,
                        'prev_course_id' => $prevCourseId
                    ]);
                }
            }

            if (!$isPrevDone && $isTransientMoodleBusy) {
                http_response_code(503);
                ?>
                <!DOCTYPE html>
                <html lang="en">
                <head>
                    <meta charset="UTF-8">
                    <title>Loading Your Course | HexaLearn</title>
                    <meta name="viewport" content="width=device-width, initial-scale=1.0">
                    <meta http-equiv="refresh" content="2">
                    <style>
                        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #020617; color: #f8fafc; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 20px; box-sizing: border-box; }
                        .card { background: #0f172a; border: 1px solid #1e293b; border-radius: 20px; padding: 36px 32px; max-width: 460px; text-align: center; box-shadow: 0 20px 30px -10px rgba(0,0,0,0.7); }
                        .spinner { width: 44px; height: 44px; border: 4px solid rgba(0, 163, 137, 0.2); border-top-color: #00A389; border-radius: 50%; margin: 0 auto 20px; animation: spin 0.9s linear infinite; }
                        @keyframes spin { to { transform: rotate(360deg); } }
                        h1 { margin: 0 0 10px; font-size: 20px; font-weight: 800; color: #ffffff; }
                        p { margin: 0; font-size: 13.5px; color: #94a3b8; line-height: 1.6; }
                    </style>
                </head>
                <body>
                    <div class="card">
                        <div class="spinner"></div>
                        <h1>Loading your course...</h1>
                        <p>Connecting to the learning server and preparing your module. This will only take a moment.</p>
                    </div>
                </body>
                </html>
                <?php
                exit;
            }

            // FAIL CLOSED: If prerequisite cannot be verified as completed, block access
            if (!$isPrevDone) {
                http_response_code(403);
                logMoodleSecurityEvent($conn, $user_id, 'course_launch_blocked_prerequisite', 'locked', 'Prerequisite module not completed or unverified', [
                    'target_cmid' => $cmid,
                    'prereq_title' => $prevTitle
                ]);
                ?>
                <!DOCTYPE html>
                <html lang="en">
                <head>
                    <meta charset="UTF-8">
                    <title>Course Locked | HexaLearn Interactive Learning</title>
                    <meta name="viewport" content="width=device-width, initial-scale=1.0">
                    <style>
                        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #020617; color: #f8fafc; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 20px; box-sizing: border-box; }
                        .card { background: #0f172a; border: 1px solid #1e293b; border-radius: 20px; padding: 36px 32px; max-width: 480px; text-align: center; box-shadow: 0 20px 30px -10px rgba(0,0,0,0.7); }
                        .icon { width: 64px; height: 64px; border-radius: 50%; background: rgba(239, 68, 68, 0.12); color: #ef4444; display: inline-flex; align-items: center; justify-content: center; font-size: 30px; margin-bottom: 20px; border: 1px solid rgba(239,68,68,0.25); }
                        h1 { margin: 0 0 10px; font-size: 22px; font-weight: 800; color: #ffffff; letter-spacing: -0.02em; }
                        p { margin: 0 0 20px; font-size: 13px; color: #94a3b8; line-height: 1.6; }
                        .prereq-badge { display: inline-block; background: #020617; border: 1px solid #334155; padding: 10px 18px; border-radius: 12px; font-size: 13px; font-weight: 700; color: #38bdf8; margin-bottom: 24px; }
                        .btn { display: inline-block; background: #00A389; color: white; padding: 11px 26px; border-radius: 12px; font-size: 13px; font-weight: 700; text-decoration: none; transition: all 0.2s; box-shadow: 0 4px 12px rgba(0,163,137,0.3); }
                        .btn:hover { background: #008f78; transform: translateY(-1px); }
                    </style>
                </head>
                <body>
                    <div class="card">
                        <div class="icon">🔒</div>
                        <h1>This Course is Locked</h1>
                        <p>Sequential course progression is enabled. You must successfully complete the prerequisite module before starting this course.</p>
                        <div class="prereq-badge">Prerequisite: <?php echo htmlspecialchars($prevTitle); ?></div>
                        <div>
                            <a href="<?php echo $onboardModulesUrl; ?>" class="btn">Return to Learning Roadmap</a>
                        </div>
                    </div>
                </body>
                </html>
                <?php
                exit;
            }
        }
    }
}


// 4. Retrieve Moodle SSO Shared Secret Key
$sharedSecret = getMoodleSsoSecret($conn);
$moodleLoginUrl = defined('MOODLE_SSO_LOGIN_URL') 
    ? MOODLE_SSO_LOGIN_URL 
    : 'https://lms.moodle.hexalearn.com/local/ssologin/login.php';

$email = !empty($user['ofc_mail']) ? trim($user['ofc_mail']) : (strtolower($user['username']) . '@hexalearn.com');
$firstname = !empty($user['firstname']) ? trim($user['firstname']) : $user['username'];
$lastname = !empty($user['lastname']) ? trim($user['lastname']) : 'HexaLearn';

// 5. Build payload adhering strictly to local_ssologin specification
$payloadData = [
    'timestamp' => time(),
    'nonce'     => bin2hex(random_bytes(16)),
    'username'  => $user['username'],
    'email'     => $email,
    'firstname' => $firstname,
    'lastname'  => $lastname,
];
$payloadJson = json_encode($payloadData, JSON_UNESCAPED_SLASHES);

// 6. Cryptography adhering to local_ssologin protocol:
// Protocol Rule 1: The AES key is always SHA-256(secret) (raw 32 bytes binary)
$key = hash('sha256', $sharedSecret, true);

// Protocol Rule 2: Random 16-byte IV, encrypt with aes-256-cbc
$iv = random_bytes(16);
$ciphertext = openssl_encrypt($payloadJson, 'aes-256-cbc', $key, 0, $iv);

// Protocol Rule 3: encdata is base64(ciphertext . '::' . iv)
$encdata = base64_encode($ciphertext . '::' . $iv);

// Protocol Rule 4: HMAC-SHA256 signature calculated on the already-encrypted encdata
$signature = hash_hmac('sha256', $encdata, $sharedSecret);

// 7. Update last_synced_at timestamp in onboard_moodle_users
$stmt = $conn->prepare("UPDATE onboard_moodle_users SET last_synced_at = CURRENT_TIMESTAMP WHERE hexa_uid = ?");
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->close();
}

// 8. Build final SSO URL with query parameters
$params = [
    'data' => $encdata,
    'sig'  => $signature,
];
if (!empty($redirectAfter)) {
    $params['redirect'] = $redirectAfter;
}

$ssoUrl = $moodleLoginUrl . '?' . http_build_query($params);

// Record verified in-progress authorization token (Strict Condition 1 implementation)
if ($cmid) {
    $_SESSION["sso_auth_{$user_id}_{$cmid}"] = [
        'status' => 'in_progress',
        'cmid' => (int)$cmid,
        'user_id' => (int)$user_id,
        'auth_time' => time(),
        'token' => bin2hex(random_bytes(8))
    ];
}

// Record Moodle REST API usage before redirecting
if (function_exists('flushMoodleApiUsage')) {
    flushMoodleApiUsage($conn, 'launch_sso', $user_id, $moodleUid);
}

// 9. Redirect employee's browser directly to Moodle SSO
header("Location: " . $ssoUrl);
exit;
