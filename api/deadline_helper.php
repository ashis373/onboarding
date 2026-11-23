<?php
// api/deadline_helper.php
// Centralized Engine for Onboarding Deadlines, Working-Day Calculations, and Access Control
// Supports perpetual automated calendar calculations for 2026, 2027, 2028, 2029, 2030, 2035+

date_default_timezone_set('Asia/Kolkata');

/**
 * Ensures the onboard_employee_deadlines table exists and is populated.
 */
function ensureDeadlinesTable(mysqli $conn): void {
    static $ensured = false;
    if ($ensured) return;

    $tableSql = "CREATE TABLE IF NOT EXISTS `onboard_employee_deadlines` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `user_id` INT NOT NULL UNIQUE COMMENT 'Foreign key user.uid or hexa_uid',
      `batch_id` TINYINT NOT NULL DEFAULT 1 COMMENT '1 = Existing Batch 1, 2 = New Joiner Batch 2',
      `first_opened_at` DATETIME NULL COMMENT 'First time employee opened the portal',
      `deadline_date` DATETIME NOT NULL COMMENT 'Base deadline (e.g. 2026-11-24 23:59:59)',
      `extended_deadline` DATETIME NULL COMMENT 'Manager approved extension date',
      `extended_by_admin_id` INT NULL COMMENT 'Admin uid who granted extension',
      `extension_reason` VARCHAR(255) NULL COMMENT 'Reason for extension',
      `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
      `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX `idx_user_deadline` (`user_id`, `deadline_date`),
      INDEX `idx_extended_deadline` (`extended_deadline`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    
    @$conn->query($tableSql);

    // Initial seed for existing batch 1 users who don't have a record yet
    $seedSql = "INSERT IGNORE INTO `onboard_employee_deadlines` (`user_id`, `batch_id`, `deadline_date`, `created_at`)
        SELECT 
            mu.hexa_uid AS user_id,
            IF(mu.created_at < '2026-11-25 00:00:00', 1, 2) AS batch_id,
            '2026-11-24 23:59:59' AS deadline_date,
            NOW() AS created_at
        FROM `onboard_moodle_users` mu
        WHERE mu.created_at < '2026-11-25 00:00:00';";
    @$conn->query($seedSql);

    $ensured = true;
}

/**
 * Checks if a given date is the 2nd or 3rd Saturday of its month.
 * Universal perpetual rule for any Gregorian calendar month across any year:
 * - Days 1 to 7:   1st Saturday (Office Working Day)
 * - Days 8 to 14:  2nd Saturday (OFF - Company Holiday)
 * - Days 15 to 21: 3rd Saturday (OFF - Company Holiday)
 * - Days 22 to 28: 4th Saturday (Office Working Day)
 * - Days 29 to 31: 5th Saturday (Office Working Day)
 */
function isSecondOrThirdSaturday(DateTime $date): bool {
    // 6 = Saturday in ISO-8601 (1=Mon ... 7=Sun)
    if ((int)$date->format('N') !== 6) {
        return false;
    }
    $dayOfMonth = (int)$date->format('j');
    return ($dayOfMonth >= 8 && $dayOfMonth <= 21);
}

/**
 * Checks if a given date is listed in holiday_info table for official holidays.
 */
function isCompanyHoliday(DateTime $date, mysqli $conn): bool {
    static $holidayCache = null;
    $dateStr = $date->format('Y-m-d');

    // Pre-cache holidays on first call
    if ($holidayCache === null) {
        $holidayCache = [];
        $res = @$conn->query("SELECT `date` FROM holiday_info");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                if (!empty($row['date'])) {
                    $holidayCache[$row['date']] = true;
                }
            }
        }
    }

    return isset($holidayCache[$dateStr]);
}

/**
 * Calculates exactly 7 official working days starting from $startDate.
 * - Skips Sundays (N = 7)
 * - Skips 2nd & 3rd Saturdays (days 8-21 on Saturday)
 * - Skips holiday_info dates
 * - Start day is Day 1 if it is an official working day.
 * Returns deadline formatted as 'Y-m-d 23:59:59'.
 */
function calculate7WorkingDaysDeadline(DateTime $startDate, mysqli $conn): string {
    $current = clone $startDate;
    $workingDaysCounted = 0;
    
    // Safety guard to avoid infinite loops
    $maxIterations = 60;
    $iter = 0;

    while ($workingDaysCounted < 7 && $iter < $maxIterations) {
        $iter++;
        $dayOfWeek = (int)$current->format('N');
        $isSunday = ($dayOfWeek === 7);
        $isOffSaturday = isSecondOrThirdSaturday($current);
        $isHoliday = isCompanyHoliday($current, $conn);
        
        if (!$isSunday && !$isOffSaturday && !$isHoliday) {
            $workingDaysCounted++;
            if ($workingDaysCounted === 7) {
                break;
            }
        }
        $current->modify('+1 day');
    }
    
    return $current->format('Y-m-d') . ' 23:59:59';
}

/**
 * Ensures an employee has a record in onboard_employee_deadlines.
 */
function ensureEmployeeDeadlineRecord(int $userId, mysqli $conn): array {
    ensureDeadlinesTable($conn);

    $stmt = $conn->prepare("SELECT * FROM onboard_employee_deadlines WHERE user_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            return $row;
        }
    }

    // Determine batch and base deadline from onboard_moodle_users or user table
    $createdAt = '2026-11-20 00:00:00';
    $userStmt = $conn->prepare("SELECT created_at FROM onboard_moodle_users WHERE hexa_uid = ? LIMIT 1");
    if ($userStmt) {
        $userStmt->bind_param("i", $userId);
        $userStmt->execute();
        $uRes = $userStmt->get_result()->fetch_assoc();
        $userStmt->close();
        if (!empty($uRes['created_at'])) {
            $createdAt = $uRes['created_at'];
        }
    }

    $dtCreated = new DateTime($createdAt, new DateTimeZone('Asia/Kolkata'));
    $cutoffBatch1 = new DateTime('2026-11-25 00:00:00', new DateTimeZone('Asia/Kolkata'));

    if ($dtCreated < $cutoffBatch1) {
        // Batch 1: Existing employees
        $batchId = 1;
        $deadlineDate = '2026-11-24 23:59:59';
    } else {
        // Batch 2: New Joiner (7 working days)
        $batchId = 2;
        $deadlineDate = calculate7WorkingDaysDeadline($dtCreated, $conn);
    }

    // Insert record
    $insStmt = $conn->prepare("INSERT INTO onboard_employee_deadlines (user_id, batch_id, deadline_date, created_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE deadline_date = VALUES(deadline_date)");
    if ($insStmt) {
        $insStmt->bind_param("iis", $userId, $batchId, $deadlineDate);
        $insStmt->execute();
        $insStmt->close();
    }

    return [
        'user_id' => $userId,
        'batch_id' => $batchId,
        'deadline_date' => $deadlineDate,
        'extended_deadline' => null,
        'extended_by_admin_id' => null,
        'extension_reason' => null
    ];
}

/**
 * Returns comprehensive deadline and access lockout status for an employee.
 */
function getEmployeeDeadlineStatus(int $userId, mysqli $conn): array {
    ensureDeadlinesTable($conn);

    // 1. Check if user already passed induction assessment (Attempt 1 OR Attempt 2)
    $hasPassed = false;
    $stmtPass = $conn->prepare("SELECT COUNT(*) FROM onboard_induction_assessment_attempts WHERE user_id = ? AND result = 'Passed'");
    if ($stmtPass) {
        $stmtPass->bind_param("i", $userId);
        $stmtPass->execute();
        $hasPassed = ((int)$stmtPass->get_result()->fetch_row()[0] > 0);
        $stmtPass->close();
    }

    // 2. Query total attempts count
    $attemptsCount = 0;
    $stmtAtt = $conn->prepare("SELECT COUNT(*) FROM onboard_induction_assessment_attempts WHERE user_id = ?");
    if ($stmtAtt) {
        $stmtAtt->bind_param("i", $userId);
        $stmtAtt->execute();
        $attemptsCount = (int)$stmtAtt->get_result()->fetch_row()[0];
        $stmtAtt->close();
    }

    // 3. If employee is Certified (Passed), they are NEVER deadline-locked
    if ($hasPassed) {
        $dlRow = ensureEmployeeDeadlineRecord($userId, $conn);
        $effDl = $dlRow['extended_deadline'] ?? $dlRow['deadline_date'];
        return [
            'is_deadline_locked' => false,
            'status' => 'completed',
            'is_completed' => true,
            'has_passed' => true,
            'attempts_count' => $attemptsCount,
            'batch_id' => (int)$dlRow['batch_id'],
            'base_deadline' => $dlRow['deadline_date'],
            'effective_deadline' => $effDl,
            'effective_deadline_fmt' => date('d-M-Y h:i A', strtotime($effDl)),
            'is_extended' => !empty($dlRow['extended_deadline']),
            'extended_deadline' => $dlRow['extended_deadline'] ?? null,
            'extended_deadline_fmt' => !empty($dlRow['extended_deadline']) ? date('d-M-Y h:i A', strtotime($dlRow['extended_deadline'])) : null,
            'extension_reason' => $dlRow['extension_reason'] ?? null,
            'message' => 'Permanently Certified'
        ];
    }

    // 4. Check deadline expiration for Incomplete employee
    $dlRow = ensureEmployeeDeadlineRecord($userId, $conn);
    $effectiveDeadline = $dlRow['extended_deadline'] ?? $dlRow['deadline_date'];

    $now = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
    $deadlineTime = new DateTime($effectiveDeadline, new DateTimeZone('Asia/Kolkata'));

    $isPastDeadline = ($now > $deadlineTime);
    // Locked on deadline expiration only if not passed
    $isLocked = ($isPastDeadline && !$hasPassed);

    return [
        'is_deadline_locked' => $isLocked,
        'status' => $isLocked ? 'locked' : 'in_progress',
        'is_completed' => false,
        'has_passed' => false,
        'attempts_count' => $attemptsCount,
        'batch_id' => (int)$dlRow['batch_id'],
        'base_deadline' => $dlRow['deadline_date'],
        'effective_deadline' => $effectiveDeadline,
        'effective_deadline_fmt' => date('d-M-Y h:i A', strtotime($effectiveDeadline)),
        'is_extended' => !empty($dlRow['extended_deadline']),
        'extended_deadline' => $dlRow['extended_deadline'] ?? null,
        'extended_deadline_fmt' => !empty($dlRow['extended_deadline']) ? date('d-M-Y h:i A', strtotime($dlRow['extended_deadline'])) : null,
        'extension_reason' => $dlRow['extension_reason'] ?? null,
        'message' => $isLocked ? 'Deadline Expired. Access Locked.' : 'In Progress'
    ];
}
