<?php
/**
 * api/admin/assessment_results.php
 * Admin API: Lists all Storyline Induction Assessment attempts, scores, pass/fail status, lockout state, and email logs.
 * Supports unlocking locked employees directly from the Admin Portal.
 */

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

$NEED_PDO = false;
require_once __DIR__ . '/../db.php';

if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { 
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not logged in']); 
    exit; 
}

// Check admin permissions
$isAdmin = false;
if (in_array((string)$user_id, ['26', '64', '205'], true)) {
    $isAdmin = true;
} else {
    $roleCheck = $conn->prepare("SELECT user_role FROM user WHERE uid = ?");
    if ($roleCheck) {
        $roleCheck->bind_param('i', $user_id);
        if ($roleCheck->execute()) {
            $roleRes = $roleCheck->get_result()->fetch_assoc();
            if (($roleRes['user_role'] ?? 0) == 1) $isAdmin = true;
        }
        $roleCheck->close();
    }
}

if (!$isAdmin) { 
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized: Admin access required']); 
    exit; 
}

session_write_close();

// Auto-create table if not exists so endpoint never errors
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

// -------------------------------------------------------------
// POST: Actions (Unlock / Save Email Template)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $action = strtolower(trim($input['action'] ?? ''));

    // Reset to Default Clean Template
    if ($action === 'reset_email_template') {
        $conn->query("DELETE FROM onboard_email_templates WHERE slug = 'assessment_lockout'");
        echo json_encode([
            'success' => true,
            'message' => 'Assessment email template reset to clean default successfully.'
        ]);
        exit;
    }

    // Save Assessment Lockout Email Template
    if ($action === 'save_email_template') {
        $subject = trim($input['subject'] ?? '');
        $bodyHtml = trim($input['body_html'] ?? '');
        $ccRecipients = trim($input['cc_recipients'] ?? '');

        if (empty($subject) || empty($bodyHtml)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Subject and email template body cannot be empty.']);
            exit;
        }

        // Auto-wrap clean paragraphs if plain text was entered without HTML tags
        if (strpos($bodyHtml, '<p>') === false && strpos($bodyHtml, '<div>') === false) {
            $paragraphs = preg_split('/\r?\n\r?\n/', $bodyHtml);
            $wrapped = [];
            foreach ($paragraphs as $p) {
                $trimmed = trim($p);
                if ($trimmed !== '') {
                    $wrapped[] = '<p>' . nl2br($trimmed) . '</p>';
                }
            }
            if (!empty($wrapped)) {
                $bodyHtml = implode("\n\n", $wrapped);
            }
        }

        // Ensure onboard_email_templates exists
        $conn->query("CREATE TABLE IF NOT EXISTS `onboard_email_templates` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `name` varchar(255) NOT NULL,
          `slug` varchar(100) NOT NULL,
          `subject` varchar(255) NOT NULL,
          `body_html` mediumtext NOT NULL,
          `description` varchar(255) DEFAULT '',
          `is_active` tinyint(1) NOT NULL DEFAULT 1,
          `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
          `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
          PRIMARY KEY (`id`),
          UNIQUE KEY `idx_slug` (`slug`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $stmt = $conn->prepare("
            INSERT INTO onboard_email_templates 
                (name, slug, subject, body_html, description, is_active)
            VALUES 
                ('Assessment 2-Attempt Lockout Alert', 'assessment_lockout', ?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE
                subject = VALUES(subject),
                body_html = VALUES(body_html),
                description = VALUES(description),
                is_active = 1,
                updated_at = NOW()
        ");
        if ($stmt) {
            $stmt->bind_param('sss', $subject, $bodyHtml, $ccRecipients);
            $stmt->execute();
            $stmt->close();
        }

        // Save / Update dynamic Department Heads in onboard_department_heads table
        if (!empty($input['dept_heads']) && is_array($input['dept_heads'])) {
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

            $dhSaveStmt = $conn->prepare("
                INSERT INTO onboard_department_heads 
                    (department_id, department_name, head_email, is_active)
                VALUES 
                    (?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE
                    head_email = VALUES(head_email),
                    department_name = VALUES(department_name),
                    is_active = 1,
                    updated_at = NOW()
            ");
            if ($dhSaveStmt) {
                foreach ($input['dept_heads'] as $dh) {
                    $dhId = (int)($dh['id'] ?? 0);
                    $dhEmail = trim($dh['email'] ?? '');
                    $dhDept = trim($dh['dept'] ?? '');
                    if ($dhId > 0 && !empty($dhEmail)) {
                        $dhSaveStmt->bind_param('iss', $dhId, $dhDept, $dhEmail);
                        $dhSaveStmt->execute();
                    }
                }
                $dhSaveStmt->close();
            }
        }

        echo json_encode([
            'success' => true,
            'message' => 'Assessment lockout email template and department heads saved successfully.'
        ]);
        exit;
    }

    if ($action === 'unlock') {
        $targetUid = (int)($input['user_id'] ?? 0);
        $courseId = (int)($input['course_id'] ?? 3);

        if ($targetUid <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing employee user_id']);
            exit;
        }

        // 1. Update attempt table to clear lock flag
        $conn->query("UPDATE onboard_induction_assessment_attempts SET is_locked = 0 WHERE user_id = {$targetUid} AND course_id = {$courseId}");

        // 2. Insert unlock override in onboard_course_unlock_overrides
        $conn->query("CREATE TABLE IF NOT EXISTS `onboard_course_unlock_overrides` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `moodle_course_id` int(11) DEFAULT NULL,
            `module_id` int(11) DEFAULT NULL,
            `moodle_cmid` int(11) DEFAULT NULL,
            `course_name` varchar(255) DEFAULT NULL,
            `admin_id` int(11) DEFAULT NULL,
            `admin_name` varchar(100) DEFAULT NULL,
            `reason` text DEFAULT NULL,
            `is_unlocked` tinyint(1) NOT NULL DEFAULT 1,
            `unlocked_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `revoked_at` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_user_course` (`user_id`,`moodle_course_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $reason = 'Admin override authorized from Assessment Results portal.';
        $adminName = 'Admin';
        $getAdm = $conn->query("SELECT firstname, lastname FROM user WHERE uid = {$user_id} LIMIT 1");
        if ($getAdm && $aRow = $getAdm->fetch_assoc()) {
            $adminName = trim($aRow['firstname'] . ' ' . $aRow['lastname']) ?: 'Admin';
        }

        // Deactivate older overrides
        $conn->query("UPDATE onboard_course_unlock_overrides SET is_unlocked = 0, revoked_at = NOW() WHERE user_id = {$targetUid} AND moodle_course_id = {$courseId}");

        // Add new active override
        $insOvr = $conn->prepare("
            INSERT INTO onboard_course_unlock_overrides
                (user_id, moodle_course_id, course_name, admin_id, admin_name, reason, is_unlocked, unlocked_at)
            VALUES
                (?, ?, 'Induction Assessment', ?, ?, ?, 1, NOW())
        ");
        if ($insOvr) {
            $insOvr->bind_param('iiiss', $targetUid, $courseId, $user_id, $adminName, $reason);
            $insOvr->execute();
            $insOvr->close();
        }

        echo json_encode([
            'success' => true,
            'message' => 'Assessment unlocked successfully. The employee can now launch and retake the assessment.'
        ]);
        exit;
    }
}

// -------------------------------------------------------------
// GET: Actions (Get Template / Query Assessment Results)
// -------------------------------------------------------------
$action = strtolower(trim($_GET['action'] ?? ''));

if ($action === 'get_template') {
    // Check if custom template exists in onboard_email_templates
    $res = $conn->query("SELECT subject, body_html, description, updated_at FROM onboard_email_templates WHERE slug = 'assessment_lockout' LIMIT 1");
    $custom = ($res && $res->num_rows > 0) ? $res->fetch_assoc() : null;

    $defaultSubject = "Assessment Locked - 2 Failed Attempts: {{employee_name}} ({{employee_code}})";
    $defaultBody = "<p>Dear Department Head / Management,</p>\n\n" .
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

    $defaultCc = "ashis.rout@hexalearn.co.in, ashiskrout1@gmail.com";

    $bodyToReturn = $custom['body_html'] ?? $defaultBody;
    if (strpos($bodyToReturn, '<div style=') !== false || strpos($bodyToReturn, 'Dear {{employee_name}}') !== false || strpos($bodyToReturn, 'Office Email: {{office_email}}') !== false || strpos($bodyToReturn, 'https://corhis.hexalearn.com/onboard/admin/assessments') !== false) {
        $bodyToReturn = $defaultBody;
    }

    // Ensure onboard_department_heads table exists
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

    $deptHeadsList = [];
    $dhQuery = $conn->query("SELECT department_id, department_name, head_email FROM onboard_department_heads WHERE is_active = 1 ORDER BY department_id ASC");
    if ($dhQuery) {
        while ($dhRow = $dhQuery->fetch_assoc()) {
            $deptHeadsList[] = [
                'id' => (int)$dhRow['department_id'],
                'dept' => $dhRow['department_name'],
                'email' => $dhRow['head_email']
            ];
        }
    }
    if (empty($deptHeadsList)) {
        $deptHeadsList = [
            ['id' => 1, 'dept' => 'E-learning', 'email' => 'soumya.sahoo@hexalearn.com'],
            ['id' => 2, 'dept' => 'Software', 'email' => 'arup.panda@hexalearn.com'],
            ['id' => 3, 'dept' => 'Multimedia', 'email' => 'anil.sethi@hexalearn.com'],
            ['id' => 4, 'dept' => 'QA', 'email' => 'Purusottam.jayasingh@hexalearn.com'],
            ['id' => 6, 'dept' => 'Digital Marketing', 'email' => 'pravat.rout@hexalearn.com'],
            ['id' => 7, 'dept' => 'Business Development', 'email' => 'priyaranjan.samantaray@hexalearn.com'],
        ];
    }

    echo json_encode([
        'success' => true,
        'template' => [
            'subject' => $custom['subject'] ?? $defaultSubject,
            'body_html' => $bodyToReturn,
            'cc_recipients' => $custom['description'] ?? $defaultCc,
            'updated_at' => $custom['updated_at'] ?? null,
            'is_custom' => ($custom !== null)
        ],
        'dept_heads' => $deptHeadsList,
        'variables' => [
            ['tag' => '{{employee_name}}', 'desc' => 'Employee full name (e.g. Suraj Kumar Bal)'],
            ['tag' => '{{employee_code}}', 'desc' => 'Employee code (e.g. HL078)'],
            ['tag' => '{{office_email}}', 'desc' => 'Official employee email from database'],
            ['tag' => '{{department}}', 'desc' => 'Department name (e.g. Software, QA)'],
            ['tag' => '{{attempt_1_score}}', 'desc' => 'Score from Attempt 1 (e.g. 65.0%)'],
            ['tag' => '{{attempt_2_score}}', 'desc' => 'Score from Attempt 2 (e.g. 72.0%)'],
            ['tag' => '{{passing_score}}', 'desc' => 'Passing threshold (80%)'],
            ['tag' => '{{status}}', 'desc' => 'Current status (LOCKED)']
        ]
    ]);
    exit;
}

// -------------------------------------------------------------
// GET: Query Assessment Results & Metrics
// -------------------------------------------------------------
$search = trim($_GET['q'] ?? '');
$statusFilter = strtolower(trim($_GET['status'] ?? 'all')); // 'all' | 'passed' | 'failed' | 'locked'
$deptFilter = isset($_GET['dept']) ? (int)$_GET['dept'] : 0;
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = max(1, min(100, (int)($_GET['limit'] ?? 20)));
$offset = ($page - 1) * $limit;

// Where conditions
$where = ["1=1"];
if (!empty($search)) {
    $esc = $conn->real_escape_string($search);
    $where[] = "(a.emp_name LIKE '%{$esc}%' OR a.emp_code LIKE '%{$esc}%' OR a.ofc_mail LIKE '%{$esc}%')";
}
if ($deptFilter > 0) {
    $where[] = "a.department_id = {$deptFilter}";
}
if ($statusFilter === 'passed') {
    $where[] = "a.result = 'Passed'";
} elseif ($statusFilter === 'failed') {
    $where[] = "a.result = 'Failed' AND a.is_locked = 0";
} elseif ($statusFilter === 'locked') {
    $where[] = "a.is_locked = 1";
}

$whereClause = implode(' AND ', $where);

// High-level statistics
$metricsRes = $conn->query("
    SELECT 
        COUNT(*) AS total_attempts,
        COUNT(DISTINCT a.user_id) AS total_candidates,
        SUM(CASE WHEN a.result = 'Passed' THEN 1 ELSE 0 END) AS total_passed,
        SUM(CASE WHEN a.result = 'Failed' AND a.is_locked = 0 THEN 1 ELSE 0 END) AS total_failed,
        SUM(CASE WHEN a.is_locked = 1 THEN 1 ELSE 0 END) AS total_locked,
        SUM(CASE WHEN a.email_sent = 1 THEN 1 ELSE 0 END) AS total_emails_sent
    FROM onboard_induction_assessment_attempts a
");
$metrics = $metricsRes ? $metricsRes->fetch_assoc() : [];

// Count matching rows
$cntRes = $conn->query("SELECT COUNT(*) AS total FROM onboard_induction_assessment_attempts a WHERE {$whereClause}");
$totalMatching = $cntRes ? (int)$cntRes->fetch_assoc()['total'] : 0;
$totalPages = ceil($totalMatching / $limit);

// Fetch records
$query = "
    SELECT 
        a.id,
        a.user_id,
        a.emp_code,
        a.emp_name,
        a.ofc_mail,
        a.department_id,
        a.department_name,
        a.course_id,
        a.attempt,
        a.Quiz_score,
        a.passing_score,
        a.result,
        a.is_locked,
        a.email_sent,
        a.email_to,
        a.email_cc,
        a.attempted_at,
        u.profile_img,
        IFNULL(ovr.is_unlocked, 0) AS has_admin_unlocked
    FROM onboard_induction_assessment_attempts a
    LEFT JOIN user u ON u.uid = a.user_id
    LEFT JOIN onboard_course_unlock_overrides ovr 
           ON ovr.user_id = a.user_id 
          AND ovr.moodle_course_id = a.course_id 
          AND ovr.is_unlocked = 1
    WHERE {$whereClause}
    ORDER BY a.attempted_at DESC
    LIMIT {$limit} OFFSET {$offset}
";

$records = [];
$res = $conn->query($query);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $records[] = [
            'id' => (int)$row['id'],
            'user_id' => (int)$row['user_id'],
            'emp_code' => $row['emp_code'] ?? '—',
            'emp_name' => $row['emp_name'],
            'ofc_mail' => $row['ofc_mail'],
            'department_id' => (int)($row['department_id'] ?? 0),
            'department_name' => $row['department_name'] ?? 'General',
            'course_id' => (int)$row['course_id'],
            'attempt' => (int)$row['attempt'],
            'quiz_score' => (float)$row['Quiz_score'],
            'passing_score' => (int)$row['passing_score'],
            'result' => $row['result'],
            'is_locked' => (bool)$row['is_locked'],
            'has_admin_unlocked' => (bool)$row['has_admin_unlocked'],
            'email_sent' => (bool)$row['email_sent'],
            'email_to' => $row['email_to'],
            'email_cc' => $row['email_cc'],
            'attempted_at' => $row['attempted_at'],
            'attempted_at_fmt' => date('d-M-Y H:i', strtotime($row['attempted_at'])),
            'photo' => !empty($row['profile_img']) ? '/internal/corhis/dist/img/user/' . $row['profile_img'] : ''
        ];
    }
}

echo json_encode([
    'success' => true,
    'metrics' => [
        'total_attempts' => (int)($metrics['total_attempts'] ?? 0),
        'total_candidates' => (int)($metrics['total_candidates'] ?? 0),
        'total_passed' => (int)($metrics['total_passed'] ?? 0),
        'total_failed' => (int)($metrics['total_failed'] ?? 0),
        'total_locked' => (int)($metrics['total_locked'] ?? 0),
        'total_emails_sent' => (int)($metrics['total_emails_sent'] ?? 0)
    ],
    'attempts' => $records,
    'pagination' => [
        'page' => $page,
        'limit' => $limit,
        'total' => $totalMatching,
        'totalPages' => $totalPages,
        'hasPrev' => $page > 1,
        'hasNext' => $page < $totalPages
    ]
]);
