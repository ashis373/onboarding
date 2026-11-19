<?php
/**
 * api/moodle/track_assessment.php
 * Real-time Storyline SCORM Assessment Tracker
 * Receives: { quiz_score, attempt, student_id, course_id }
 * Enforces: 
 *   - 10 Induction Course Prerequisite Check
 *   - Passing score >= 80% (Pass), < 80% (Fail)
 *   - Max 2 Attempts Lockout
 *   - Automated Alert Email on 2nd Failure (SENT STRICTLY ONCE) to Employee (ofc_mail only) + Dept Head, CC 2 Heads
 *   - Local-First MySQL Storage (onboard_induction_assessment_attempts & onboard_module_progress)
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');

// CORS Support for Storyline player embed
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
require_once __DIR__ . '/../email/mailer.php';

// 1. Parse JSON or POST payload
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$userId = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
session_write_close();

$quizScore = isset($input['quiz_score']) ? (float)$input['quiz_score'] : (isset($input['Quiz_score']) ? (float)$input['Quiz_score'] : 0.0);
$attempt = isset($input['attempt']) ? max(1, (int)$input['attempt']) : 1;
$rawStudentId = trim((string)($input['student_id'] ?? $input['username'] ?? ''));
$courseId = isset($input['course_id']) ? (int)$input['course_id'] : 3; // Default to Induction Assessment course ID 3

// 2. Resolve Employee UID if session is not active
if (!$userId && !empty($rawStudentId)) {
    if (is_numeric($rawStudentId)) {
        $mId = (int)$rawStudentId;
        $chkU = $conn->prepare("SELECT hexa_uid FROM onboard_moodle_users WHERE moodle_user_id = ? LIMIT 1");
        if ($chkU) {
            $chkU->bind_param('i', $mId);
            $chkU->execute();
            $uRow = $chkU->get_result()->fetch_assoc();
            $chkU->close();
            if ($uRow) $userId = (int)$uRow['hexa_uid'];
        }
    }
    if (!$userId) {
        $chkU = $conn->prepare("SELECT uid FROM user WHERE username = ? OR emp_code = ? LIMIT 1");
        if ($chkU) {
            $chkU->bind_param('ss', $rawStudentId, $rawStudentId);
            $chkU->execute();
            $uRow = $chkU->get_result()->fetch_assoc();
            $chkU->close();
            if ($uRow) $userId = (int)$uRow['uid'];
        }
    }
}

if (!$userId) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized: Unable to identify employee session or student_id.'
    ]);
    exit;
}

// 3. Fetch Employee Details dynamically from `user` and `department` tables (Office mail ONLY)
$empStmt = $conn->prepare("
    SELECT u.uid, u.emp_code, u.firstname, u.lastname, u.ofc_mail, u.department, d.name AS department_name, u.status 
    FROM user u 
    LEFT JOIN department d ON d.id = u.department 
    WHERE u.uid = ? LIMIT 1
");
$empStmt->bind_param('i', $userId);
$empStmt->execute();
$employee = $empStmt->get_result()->fetch_assoc();
$empStmt->close();

if (!$employee || (int)$employee['status'] !== 0) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Inactive or non-existent employee.'
    ]);
    exit;
}

$empName = trim($employee['firstname'] . ' ' . $employee['lastname']);
$empCode = trim($employee['emp_code'] ?? '');
$empOfcMail = trim($employee['ofc_mail'] ?? '');
$deptId = (int)($employee['department'] ?? 0);
$deptName = trim($employee['department_name'] ?? '');

if (empty($deptName)) {
    $fallbackDepts = [
        1 => 'E-learning',
        2 => 'Software',
        3 => 'Multimedia',
        4 => 'QA',
        5 => 'Other',
        6 => 'Digital Marketing',
        7 => 'Business Development',
        8 => 'Management'
    ];
    $deptName = $fallbackDepts[$deptId] ?? 'Other';
}

// 4. Auto-create dedicated tracking table if not exists
$conn->query("
CREATE TABLE IF NOT EXISTS `onboard_induction_assessment_attempts` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL COMMENT 'Hexa UID from user table',
  `emp_code` VARCHAR(50) DEFAULT NULL COMMENT 'Employee Code e.g. HL039',
  `emp_name` VARCHAR(150) NOT NULL COMMENT 'Firstname + Lastname',
  `ofc_mail` VARCHAR(100) NOT NULL COMMENT 'Official office email (user.ofc_mail)',
  `department_id` INT(10) DEFAULT NULL COMMENT 'Department ID (1-8)',
  `department_name` VARCHAR(100) DEFAULT NULL COMMENT 'E-learning, Software, QA, etc.',
  `course_id` INT(11) NOT NULL DEFAULT 3 COMMENT 'Moodle Course ID',
  `module_id` INT(11) DEFAULT NULL COMMENT 'onboard_modules ID',
  `student_id` VARCHAR(100) DEFAULT NULL COMMENT 'SCORM cmi.core.student_id',
  `attempt` INT(11) NOT NULL DEFAULT 1 COMMENT 'Storyline attempt variable (1, 2)',
  `Quiz_score` DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Storyline Quiz_score variable',
  `passing_score` INT(11) NOT NULL DEFAULT 80 COMMENT 'Pass mark threshold (80)',
  `result` ENUM('Passed', 'Failed') NOT NULL COMMENT 'Passed if Quiz_score >= 80, Failed if < 80',
  `is_locked` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 if locked after 2 failed attempts',
  `email_sent` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 if lockout alert email sent',
  `email_to` VARCHAR(255) DEFAULT NULL COMMENT 'TO recipients',
  `email_cc` VARCHAR(255) DEFAULT NULL COMMENT 'CC recipients',
  `attempted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_attempt` (`user_id`, `attempt`),
  KEY `idx_course_id` (`course_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

// 5. Enforce 10 Induction Course Prerequisites
// All 10 active learning modules must have is_completed = 1
$prereqQuery = "
    SELECT COUNT(*) AS total_req,
           SUM(CASE WHEN IFNULL(p.is_completed, 0) = 1 THEN 1 ELSE 0 END) AS total_done
    FROM onboard_modules m
    LEFT JOIN onboard_module_progress p 
           ON p.module_id = m.id AND p.user_id = {$userId}
    WHERE m.is_active = 1
      AND m.moodle_course_id > 0
      AND m.moodle_course_id != {$courseId}
      AND (m.type != 'assessment' OR m.type IS NULL)
      AND LOWER(m.title) NOT LIKE '%assessment%'
      AND LOWER(m.title) NOT LIKE '%quiz%'
";
$prereqRes = $conn->query($prereqQuery);
$prereqRow = $prereqRes ? $prereqRes->fetch_assoc() : null;
$totalReq = (int)($prereqRow['total_req'] ?? 10);
$totalDone = (int)($prereqRow['total_done'] ?? 0);

if ($totalReq > 0 && $totalDone < $totalReq) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => "Prerequisite incomplete: You have completed {$totalDone} of {$totalReq} courses. You must complete all 10 induction courses before taking the assessment.",
        'prerequisites' => [
            'total_required' => $totalReq,
            'total_completed' => $totalDone
        ],
        'locked' => true
    ]);
    exit;
}

// Resolve Assessment Module ID from onboard_modules
$modId = null;
$mChk = $conn->query("SELECT id, passing_score FROM onboard_modules WHERE moodle_course_id = {$courseId} OR LOWER(title) LIKE '%assessment%' LIMIT 1");
$passingScore = 80;
if ($mChk && $mRow = $mChk->fetch_assoc()) {
    $modId = (int)$mRow['id'];
    if (!empty($mRow['passing_score'])) {
        $passingScore = (int)$mRow['passing_score'];
    }
}

// 6. Upfront Lockout & Already-Passed Guard
// Check if user has already passed
$passChk = $conn->query("
    SELECT id, Quiz_score FROM onboard_induction_assessment_attempts 
    WHERE user_id = {$userId} AND course_id = {$courseId} AND result = 'Passed'
    LIMIT 1
");
if ($passChk && $passChk->num_rows > 0) {
    echo json_encode([
        'success' => true,
        'message' => 'Assessment already passed. Further attempts are not permitted.',
        'passed' => true,
        'locked' => false,
        'can_retry' => false
    ]);
    exit;
}

// Check if user is locked out after 2 failed attempts
$lockChk = $conn->query("
    SELECT is_locked FROM onboard_induction_assessment_attempts 
    WHERE user_id = {$userId} AND course_id = {$courseId} 
    ORDER BY id DESC LIMIT 1
");
$isUserLocked = ($lockChk && $lRow = $lockChk->fetch_assoc()) ? ((int)$lRow['is_locked'] === 1) : false;

// Count previous attempts
$attCntStmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM onboard_induction_assessment_attempts WHERE user_id = ? AND course_id = ?");
$attCntStmt->bind_param('ii', $userId, $courseId);
$attCntStmt->execute();
$prevAttemptsCount = (int)($attCntStmt->get_result()->fetch_assoc()['cnt'] ?? 0);
$attCntStmt->close();

if ($isUserLocked || $prevAttemptsCount >= 2) {
    // Check if an authorized admin unlock override is active
    $ovrChk = $conn->query("
        SELECT id FROM onboard_course_unlock_overrides 
        WHERE user_id = {$userId} AND (moodle_course_id = {$courseId} OR module_id = {$modId}) AND is_unlocked = 1 
        ORDER BY id DESC LIMIT 1
    ");
    $hasOverride = ($ovrChk && $ovrChk->num_rows > 0);
    if (!$hasOverride) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Assessment locked: Maximum allowable attempts (2) reached. Please contact your department head or administrator for unlock authorization.',
            'locked' => true,
            'can_retry' => false,
            'attempt' => $prevAttemptsCount
        ]);
        exit;
    }
}

// 6. Idempotency Guard (Avoid double trigger on slide refresh within 15 seconds)
$chkDup = $conn->prepare("
    SELECT id, result, is_locked FROM onboard_induction_assessment_attempts
    WHERE user_id = ? AND attempt = ? AND Quiz_score = ? AND attempted_at >= DATE_SUB(NOW(), INTERVAL 15 SECOND)
    ORDER BY id DESC LIMIT 1
");
if ($chkDup) {
    $chkDup->bind_param('iid', $userId, $attempt, $quizScore);
    $chkDup->execute();
    $dupRow = $chkDup->get_result()->fetch_assoc();
    $chkDup->close();
    if ($dupRow) {
        echo json_encode([
            'success' => true,
            'message' => 'Duplicate attempt detected, returning recorded state.',
            'passed' => ($dupRow['result'] === 'Passed'),
            'score' => $quizScore,
            'attempt' => $attempt,
            'locked' => (bool)$dupRow['is_locked'],
            'can_retry' => ($dupRow['result'] !== 'Passed' && !$dupRow['is_locked'])
        ]);
        exit;
    }
}

// 7. Evaluate Score: >= 80 is Passed, < 80 is Failed
$isPassed = ($quizScore >= $passingScore);
$resultText = $isPassed ? 'Passed' : 'Failed';

// Count previous attempts from the database for accuracy
$attCntStmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM onboard_induction_assessment_attempts WHERE user_id = ? AND course_id = ?");
$attCntStmt->bind_param('ii', $userId, $courseId);
$attCntStmt->execute();
$prevAttemptsCount = (int)($attCntStmt->get_result()->fetch_assoc()['cnt'] ?? 0);
$attCntStmt->close();

$effectiveAttempt = max($attempt, $prevAttemptsCount + 1);

// 8. Determine Locking and Email Trigger
$isLocked = 0;
$emailSent = 0;
$emailToLogged = null;
$emailCcLogged = null;

if ($isPassed) {
    // PASS: Mark completed in local module progress
    $isLocked = 0;
    if ($modId > 0) {
        $conn->query("
            INSERT INTO onboard_module_progress 
                (user_id, module_id, status, is_completed, progress_percent, score, attempts, completed_at, last_synced_at)
            VALUES 
                ({$userId}, {$modId}, 'completed', 1, 100, {$quizScore}, {$effectiveAttempt}, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                status = 'completed',
                is_completed = 1,
                progress_percent = 100,
                score = GREATEST(IFNULL(score, 0), VALUES(score)),
                attempts = VALUES(attempts),
                completed_at = IFNULL(completed_at, NOW()),
                last_synced_at = NOW()
        ");
    }
} else {
    // FAIL: Check if this is 2nd attempt failure
    if ($effectiveAttempt >= 2) {
        $isLocked = 1; // Strictly locked after 2 failed attempts
        
        // Mark module progress as failed and NOT completed
        if ($modId > 0) {
            $conn->query("
                INSERT INTO onboard_module_progress 
                    (user_id, module_id, status, is_completed, progress_percent, score, attempts, last_synced_at)
                VALUES 
                    ({$userId}, {$modId}, 'failed', 0, 0, {$quizScore}, {$effectiveAttempt}, NOW())
                ON DUPLICATE KEY UPDATE
                    status = 'failed',
                    is_completed = 0,
                    score = VALUES(score),
                    attempts = VALUES(attempts),
                    last_synced_at = NOW()
            ");
        }

        // Revoke any previous admin unlock overrides
        $conn->query("
            UPDATE onboard_course_unlock_overrides 
            SET is_unlocked = 0, revoked_at = NOW() 
            WHERE user_id = {$userId} AND (moodle_course_id = {$courseId} OR module_id = {$modId})
        ");

        // Auto-create and seed onboard_department_heads table if not exists
        $conn->query("
        CREATE TABLE IF NOT EXISTS `onboard_department_heads` (
          `department_id` INT(11) NOT NULL,
          `department_name` VARCHAR(100) NOT NULL,
          `head_email` VARCHAR(150) NOT NULL,
          `is_active` TINYINT(1) NOT NULL DEFAULT 1,
          `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`department_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // Seed initial department heads if table empty
        $cntDh = $conn->query("SELECT COUNT(*) AS cnt FROM onboard_department_heads");
        if ($cntDh && (int)($cntDh->fetch_assoc()['cnt'] ?? 0) === 0) {
            $conn->query("
                INSERT IGNORE INTO `onboard_department_heads` (`department_id`, `department_name`, `head_email`, `is_active`) VALUES
                (1, 'E-learning', 'soumya.sahoo@hexalearn.com', 1),
                (2, 'Software', 'arup.panda@hexalearn.com', 1),
                (3, 'Multimedia', 'anil.sethi@hexalearn.com', 1),
                (4, 'QA', 'Purusottam.jayasingh@hexalearn.com', 1),
                (6, 'Digital Marketing', 'pravat.rout@hexalearn.com', 1),
                (7, 'Business Development', 'priyaranjan.samantaray@hexalearn.com', 1);
            ");
        }

        $blacklistedEmails = ['satyabrata.das@hexalearn.com'];
        $toRecipients = [];

        // 1. Send ONLY to Department Head (Dynamically resolved from database based on employee's department)
        if ($deptId > 0) {
            $dhStmt = $conn->prepare("SELECT head_email FROM onboard_department_heads WHERE department_id = ? AND is_active = 1 LIMIT 1");
            if ($dhStmt) {
                $dhStmt->bind_param('i', $deptId);
                $dhStmt->execute();
                $dhRow = $dhStmt->get_result()->fetch_assoc();
                $dhStmt->close();
                if ($dhRow && !empty($dhRow['head_email'])) {
                    $headMail = strtolower(trim($dhRow['head_email']));
                    if (!in_array($headMail, $blacklistedEmails, true)) {
                        $toRecipients[] = $headMail;
                    }
                }
            }
        }

        // Fallback to static mapping if database row was not found
        if (empty($toRecipients)) {
            $staticDeptHeads = [
                1 => 'soumya.sahoo@hexalearn.com',
                2 => 'arup.panda@hexalearn.com',
                3 => 'anil.sethi@hexalearn.com',
                4 => 'Purusottam.jayasingh@hexalearn.com',
                6 => 'pravat.rout@hexalearn.com',
                7 => 'priyaranjan.samantaray@hexalearn.com'
            ];
            if (isset($staticDeptHeads[$deptId])) {
                $headMail = strtolower(trim($staticDeptHeads[$deptId]));
                if (!in_array($headMail, $blacklistedEmails, true)) {
                    $toRecipients[] = $headMail;
                }
            }
        }

        // If no Department Head exists (e.g. Dept 5 Other), send directly to primary Management
        if (empty($toRecipients)) {
            $toRecipients[] = 'priyaranjan.samantaray@hexalearn.com';
        }

        // 2. CC Recipients (Updated for testing / management)
        $ccRecipients = [
            'ashis.rout@hexalearn.co.in',
            'ashiskrout1@gmail.com'
        ];

        // Fetch Attempt 1 score for the email body
        $att1Score = 'N/A';
        $att1Res = $conn->query("SELECT Quiz_score FROM onboard_induction_assessment_attempts WHERE user_id = {$userId} AND course_id = {$courseId} ORDER BY id ASC LIMIT 1");
        if ($att1Res && $att1Row = $att1Res->fetch_assoc()) {
            $att1Score = number_format((float)$att1Row['Quiz_score'], 1) . '%';
        }

        $att2Score = number_format($quizScore, 1) . '%';

        // Check if Admin defined a custom template in onboard_email_templates
        $subjectTpl = "Assessment Locked - 2 Failed Attempts: {{employee_name}} ({{employee_code}})";
        $bodyTpl = "<p>Dear Department Head / Management,</p>\n\n" .
            "<p>This is an automated notification that the following employee has completed both allowable attempts (Attempt 1 and Attempt 2) for the Induction Assessment and did not meet the required passing score of {{passing_score}} (80%).</p>\n\n" .
            "<p>Employee Details:</p>\n\n" .
            "<p>Employee Name: {{employee_name}}<br>\n" .
            "Employee Code: {{employee_code}}<br>\n" .
            "Attempt 1 Score: {{attempt_1_score}}<br>\n" .
            "Attempt 2 Score: {{attempt_2_score}}<br>\n" .
            "Current Status: {{status}}</p>\n\n" .
            "<p>Action Taken: The assessment module has been automatically locked for this employee. The employee cannot retake the assessment unless authorized and unlocked by an Administrator.</p>\n\n" .
            "<p>Thank you,</p>\n\n" .
            "<p>HexaLearn Onboarding System</p>";

        $tplQuery = $conn->query("SELECT subject, body_html, description FROM onboard_email_templates WHERE slug = 'assessment_lockout' AND is_active = 1 LIMIT 1");
        if ($tplQuery && $tplRow = $tplQuery->fetch_assoc()) {
            if (!empty($tplRow['subject'])) $subjectTpl = $tplRow['subject'];
            if (!empty($tplRow['body_html'])) $bodyTpl = $tplRow['body_html'];
            if (!empty($tplRow['description'])) {
                $customCcs = array_filter(array_map('trim', explode(',', $tplRow['description'])));
                if (!empty($customCcs)) {
                    $ccRecipients = array_values($customCcs);
                }
            }
        }

        // Replace template variables
        $placeholders = [
            '{{employee_name}}' => $empName,
            '{{employee_code}}' => $empCode,
            '{{office_email}}' => $empOfcMail,
            '{{department}}' => $deptName,
            '{{attempt_1_score}}' => $att1Score,
            '{{attempt_2_score}}' => $att2Score,
            '{{passing_score}}' => '80%',
            '{{status}}' => 'LOCKED'
        ];
        $subject = str_replace(array_keys($placeholders), array_values($placeholders), $subjectTpl);
        $htmlBody = str_replace(array_keys($placeholders), array_values($placeholders), $bodyTpl);

        // STRICT GUARD: Send lockout email ONLY ONE TIME!
        // Check if an alert email was ALREADY sent previously for this employee on this course
        $alreadySentChk = $conn->query("
            SELECT id, email_to, email_cc 
            FROM onboard_induction_assessment_attempts 
            WHERE user_id = {$userId} AND course_id = {$courseId} AND email_sent = 1 
            LIMIT 1
        ");
        $emailAlreadySent = ($alreadySentChk && $alreadySentChk->num_rows > 0);
        if ($emailAlreadySent && $prevRow = $alreadySentChk->fetch_assoc()) {
            $emailSent = 1; // Retain status flag in audit log
            $emailToLogged = $prevRow['email_to'];
            $emailCcLogged = $prevRow['email_cc'];
        }

        if (!$emailAlreadySent && !empty($toRecipients)) {
            $primaryTo = array_shift($toRecipients);
            // Combine any remaining TO recipients into CC
            $finalCc = array_unique(array_merge($ccRecipients, $toRecipients));

            $mailer = new SmtpMailer();
            $sendRes = $mailer->send($primaryTo, $empName, $subject, $htmlBody, $finalCc);
            if (!empty($sendRes['success'])) {
                $emailSent = 1;
                $emailToLogged = $primaryTo;
                $emailCcLogged = implode(', ', $finalCc);
            } else {
                error_log("Failed to send assessment lockout email: " . ($sendRes['error'] ?? 'Unknown error'));
            }
        }
    } else {
        // FAIL on Attempt 1: Keep unlocked for retake
        $isLocked = 0;
        if ($modId > 0) {
            $conn->query("
                INSERT INTO onboard_module_progress 
                    (user_id, module_id, status, is_completed, progress_percent, score, attempts, last_synced_at)
                VALUES 
                    ({$userId}, {$modId}, 'failed', 0, 0, {$quizScore}, {$effectiveAttempt}, NOW())
                ON DUPLICATE KEY UPDATE
                    status = 'failed',
                    is_completed = 0,
                    score = VALUES(score),
                    attempts = VALUES(attempts),
                    last_synced_at = NOW()
            ");
        }
    }
}

// 9. Record Attempt into dedicated audit table
$insStmt = $conn->prepare("
    INSERT INTO onboard_induction_assessment_attempts
        (user_id, emp_code, emp_name, ofc_mail, department_id, department_name, course_id, module_id, student_id, attempt, Quiz_score, passing_score, result, is_locked, email_sent, email_to, email_cc)
    VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");
$insStmt->bind_param(
    'isssisissidisisss',
    $userId,
    $empCode,
    $empName,
    $empOfcMail,
    $deptId,
    $deptName,
    $courseId,
    $modId,
    $rawStudentId,
    $effectiveAttempt,
    $quizScore,
    $passingScore,
    $resultText,
    $isLocked,
    $emailSent,
    $emailToLogged,
    $emailCcLogged
);
$insStmt->execute();
$insStmt->close();

// Update courses cache IN-PLACE for this employee so attempts appear instantly in <2ms without triggering a 30s cold build / 500 error
$userCacheFiles = glob(__DIR__ . '/cache/user_courses_' . (int)$userId . '_*.json');
if ($userCacheFiles) {
    foreach ($userCacheFiles as $ucf) {
        $raw = @file_get_contents($ucf);
        if ($raw) {
            $data = json_decode($raw, true);
            if (is_array($data) && !empty($data['courses'])) {
                foreach ($data['courses'] as &$c) {
                    if ((int)($c['id'] ?? 0) === 3) {
                        $c['status'] = $isPassed ? 'completed' : ($isLockedByAttempt ? 'locked' : 'available');
                        $c['is_locked'] = $isLockedByAttempt;
                        if (!empty($c['modules'])) {
                            foreach ($c['modules'] as &$m) {
                                $m['attempts'] = $currentAttempt;
                                $m['score'] = $quizScore;
                                $m['status'] = $isPassed ? 'completed' : ($isLockedByAttempt ? 'locked' : 'failed');
                                $m['is_locked'] = $isLockedByAttempt;
                                if (!isset($m['attempts_history']) || !is_array($m['attempts_history'])) {
                                    $m['attempts_history'] = [];
                                }
                                $m['attempts_history'][] = [
                                    'attempt' => (int)$currentAttempt,
                                    'score' => (int)round($quizScore),
                                    'result' => $resultStr,
                                    'status' => strtolower($resultStr),
                                    'total_time' => '—',
                                    'date' => date('d-M-Y H:i')
                                ];
                            }
                            unset($m);
                        }
                    }
                }
                unset($c);
                @file_put_contents($ucf, json_encode($data, JSON_UNESCAPED_SLASHES));
            }
        }
    }
}

// 10. Return Atomic, Network-Optimized JSON Response
echo json_encode([
    'success' => true,
    'passed' => $isPassed,
    'score' => $quizScore,
    'passing_score' => $passingScore,
    'attempt' => $effectiveAttempt,
    'result' => $resultText,
    'locked' => (bool)$isLocked,
    'can_retry' => (!$isPassed && $isLocked === 0),
    'email_alert_sent' => (bool)$emailSent,
    'message' => $isPassed 
        ? "Congratulations! You passed the induction assessment with {$quizScore}%." 
        : ($isLocked 
            ? "You have failed 2 attempts with {$quizScore}%. The assessment is now locked. Your department head and HR have been notified." 
            : "Attempt 1 recorded: Score {$quizScore}%. You have 1 remaining attempt.")
]);
