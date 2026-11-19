<?php
/**
 * Migration script: Add `phase` column to `onboard_induction_assessment_attempts`
 * Accessible via browser:
 *   - https://corhis.hexalearn.com/onboard/add.php
 *   - https://corhis.hexalearn.com/onboard/api/add.php
 */

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

header('Content-Type: text/plain; charset=utf-8');

// Resilient database connection resolver (works whether placed in /onboard/ or /onboard/api/)
$dbFile = null;
if (file_exists(__DIR__ . '/db.php')) {
    $dbFile = __DIR__ . '/db.php';
} elseif (file_exists(__DIR__ . '/api/db.php')) {
    $dbFile = __DIR__ . '/api/db.php';
} elseif (file_exists(dirname(__DIR__) . '/api/db.php')) {
    $dbFile = dirname(__DIR__) . '/api/db.php';
}

if ($dbFile) {
    require_once $dbFile;
} else {
    die("[ERROR] Could not locate db.php from " . __DIR__ . "\n");
}

if (!isset($conn) || $conn->connect_error) {
    die("Database connection failed: " . ($conn->connect_error ?? "Unknown error"));
}

echo "=== MIGRATION: ADD `phase` COLUMN TO `onboard_induction_assessment_attempts` ===\n\n";

// 1. Ensure table exists
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
  `phase` INT(11) NOT NULL DEFAULT 1 COMMENT '1=Initial Induction Cycle, 2=Retake after Admin Unlock',
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
  KEY `idx_user_phase_attempt` (`user_id`, `phase`, `attempt`),
  KEY `idx_course_id` (`course_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

// 2. Check if `phase` column already exists
$colChk = $conn->query("SHOW COLUMNS FROM `onboard_induction_assessment_attempts` LIKE 'phase'");

if ($colChk && $colChk->num_rows > 0) {
    echo "[INFO] The column `phase` ALREADY EXISTS in `onboard_induction_assessment_attempts`.\n";
} else {
    // 3. Add `phase` column and index
    $alterSql = "
        ALTER TABLE `onboard_induction_assessment_attempts` 
        ADD COLUMN `phase` INT(11) NOT NULL DEFAULT 1 
        COMMENT '1=Initial Induction Cycle, 2=Retake after Admin Unlock' 
        AFTER `student_id`,
        ADD INDEX `idx_user_phase_attempt` (`user_id`, `phase`, `attempt`)
    ";
    
    if ($conn->query($alterSql) === TRUE) {
        echo "[SUCCESS] Successfully added `phase` INT(11) column and index `idx_user_phase_attempt`.\n";
    } else {
        echo "[ERROR] Failed to alter table: " . $conn->error . "\n";
    }
}

// 4. Ensure all existing records default to phase = 1
$conn->query("UPDATE `onboard_induction_assessment_attempts` SET `phase` = 1 WHERE `phase` IS NULL OR `phase` = 0");

// 4b. Update email template for `assessment_lockout` in `onboard_email_templates`
$newBodyHtml = "<p>Dear Department Head / Management,</p>\n\n" .
    "<p>This is an automated notification that {{employee_name}} has completed both allowable attempts (Attempt 1 and Attempt 2) for the Induction Assessment and did not meet the required passing score of {{passing_score}}.</p>\n\n" .
    "<p>Employee Details:</p>\n\n" .
    "<p>Employee Name: {{employee_name}}<br>\n" .
    "Employee Code: {{employee_code}}<br>\n" .
    "Office Email: {{office_email}}<br>\n" .
    "Department: {{department}}<br>\n" .
    "Attempt 1 Score: {{attempt_1_score}}<br>\n" .
    "Attempt 2 Score: {{attempt_2_score}}<br>\n" .
    "Current Status: {{status}}</p>\n\n" .
    "<p>Action Taken: The assessment module has been automatically locked for this employee. The employee cannot retake the assessment unless authorized and unlocked by an Administrator.</p>\n\n" .
    "<p>Thank you,</p>\n\n" .
    "<p>HexaLearn Onboarding System</p>";

$escBodyHtml = $conn->real_escape_string($newBodyHtml);
$tplUpdate = $conn->query("
    UPDATE `onboard_email_templates` 
    SET 
        `subject` = 'Assessment Locked - 2 Failed Attempts: {{employee_name}} ({{employee_code}})',
        `body_html` = '{$escBodyHtml}'
    WHERE `slug` = 'assessment_lockout'
");
if ($tplUpdate) {
    echo "[SUCCESS] Updated `assessment_lockout` email template in `onboard_email_templates`.\n";
} else {
    echo "[INFO] Email template update skipped or table not present.\n";
}

// 5. Display current table structure
echo "\n--- Current Columns in `onboard_induction_assessment_attempts` ---\n";
$colRes = $conn->query("SHOW COLUMNS FROM `onboard_induction_assessment_attempts`");
if ($colRes) {
    while ($col = $colRes->fetch_assoc()) {
        $extra = ($col['Field'] === 'phase') ? "  <-- ⭐ NEW COLUMN" : "";
        echo sprintf("  %-20s %-25s Null: %-3s Key: %-3s Default: %-15s %s\n", 
            $col['Field'], $col['Type'], $col['Null'], $col['Key'], $col['Default'] ?? 'NULL', $extra
        );
    }
}

// 6. Display current records
echo "\n--- Current Attempts in `onboard_induction_assessment_attempts` ---\n";
$rowsRes = $conn->query("SELECT id, user_id, emp_name, phase, attempt, Quiz_score, result, is_locked, attempted_at FROM `onboard_induction_assessment_attempts` ORDER BY id ASC");
if ($rowsRes && $rowsRes->num_rows > 0) {
    while ($row = $rowsRes->fetch_assoc()) {
        echo sprintf("  ID #%-3d | UID: %-4d | Name: %-20s | Phase: %-2d | Attempt: %-2d | Score: %-5.1f%% | Result: %-6s | Locked: %-1d | Date: %s\n",
            $row['id'], $row['user_id'], $row['emp_name'], $row['phase'], $row['attempt'], $row['Quiz_score'], $row['result'], $row['is_locked'], $row['attempted_at']
        );
    }
} else {
    echo "  (No attempt records found)\n";
}

echo "\nMigration complete! All systems ready.\n";
$conn->close();
?>
