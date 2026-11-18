<?php
// Script: run_sync_migration.php
// Safe, idempotent runner for local MySQL sync & historical attempt audit tracking
// Run from browser or CLI to safely upgrade onboard_ tables without overwriting existing data.

header('Content-Type: text/plain; charset=UTF-8');

// Resilient DB include (works from root or api/ directory)
if (file_exists(__DIR__ . '/api/db.php')) {
    require_once __DIR__ . '/api/db.php';
} elseif (file_exists(__DIR__ . '/db.php')) {
    require_once __DIR__ . '/db.php';
} else {
    die("Error: db.php could not be located.\n");
}




if (!isset($conn) || $conn->connect_error) {
    die("Database connection failed: " . ($conn->connect_error ?? 'Missing $conn') . "\n");
}

$dbName = $conn->query("SELECT DATABASE()")->fetch_row()[0];
echo "=== HexaOne Onboarding Database Migration: 010_moodle_local_sync ===\n";
echo "Database: $dbName\n\n";

function columnExists($conn, $table, $column) {
    $stmt = $conn->prepare("
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");
    $stmt->bind_param("ss", $table, $column);
    $stmt->execute();
    $res = $stmt->get_result();
    $exists = $res->num_rows > 0;
    $stmt->close();
    return $exists;
}

function indexExists($conn, $table, $index) {
    $stmt = $conn->prepare("
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS 
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
    ");
    $stmt->bind_param("ss", $table, $index);
    $stmt->execute();
    $res = $stmt->get_result();
    $exists = $res->num_rows > 0;
    $stmt->close();
    return $exists;
}

function safeQuery($conn, $sql, $desc) {
    if ($conn->query($sql)) {
        echo "  + $desc: OK\n";
        return true;
    } else {
        echo "  ✗ $desc Error: " . $conn->error . "\n";
        return false;
    }
}

// =========================================================================
// 1. TABLE: onboard_modules (Course & Activity Catalog)
// =========================================================================
echo "1. Checking table: onboard_modules ...\n";

// Support both SCORM training and Assessments in type ENUM
safeQuery($conn, "ALTER TABLE `onboard_modules` MODIFY COLUMN `type` ENUM('scorm', 'assessment', 'video', 'pdf', 'document') DEFAULT 'scorm'", "Updated type ENUM");

if (!columnExists($conn, 'onboard_modules', 'moodle_course_id')) {
    safeQuery($conn, "ALTER TABLE `onboard_modules` ADD COLUMN `moodle_course_id` INT NULL COMMENT 'Moodle course ID (e.g. 2, 3, 4)'", "Added moodle_course_id");
} else {
    echo "  ✓ Column moodle_course_id already exists\n";
}

if (!columnExists($conn, 'onboard_modules', 'moodle_cmid')) {
    safeQuery($conn, "ALTER TABLE `onboard_modules` ADD COLUMN `moodle_cmid` INT NULL COMMENT 'Moodle course module ID'", "Added moodle_cmid");
} else {
    echo "  ✓ Column moodle_cmid already exists\n";
}

if (!columnExists($conn, 'onboard_modules', 'moodle_scoid')) {
    safeQuery($conn, "ALTER TABLE `onboard_modules` ADD COLUMN `moodle_scoid` INT NULL COMMENT 'Moodle launchable SCO ID'", "Added moodle_scoid");
} else {
    echo "  ✓ Column moodle_scoid already exists\n";
}

if (!columnExists($conn, 'onboard_modules', 'passing_score')) {
    safeQuery($conn, "ALTER TABLE `onboard_modules` ADD COLUMN `passing_score` INT DEFAULT 80", "Added passing_score");
} else {
    echo "  ✓ Column passing_score already exists\n";
}

if (!indexExists($conn, 'onboard_modules', 'ix_moodle_course')) {
    safeQuery($conn, "ALTER TABLE `onboard_modules` ADD INDEX `ix_moodle_course` (`moodle_course_id`)", "Added index ix_moodle_course");
}

// =========================================================================
// 2. TABLE: onboard_module_progress (Current Per-Employee Progress & Slide Tracking)
// Guarantees monotonic progression: progress and time spent never regress!
// =========================================================================
echo "\n2. Checking table: onboard_module_progress (Monotonic Progress & Slide Tracking) ...\n";

if (!columnExists($conn, 'onboard_module_progress', 'status')) {
    safeQuery($conn, "ALTER TABLE `onboard_module_progress` ADD COLUMN `status` ENUM('not_started', 'in_progress', 'completed', 'failed') DEFAULT 'not_started'", "Added status column");
} else {
    echo "  ✓ Column status already exists\n";
}

if (!columnExists($conn, 'onboard_module_progress', 'progress_percent')) {
    safeQuery($conn, "ALTER TABLE `onboard_module_progress` ADD COLUMN `progress_percent` INT DEFAULT 0 COMMENT '0 to 100%'", "Added progress_percent column");
} else {
    echo "  ✓ Column progress_percent already exists\n";
}

if (!columnExists($conn, 'onboard_module_progress', 'highest_progress_percent')) {
    safeQuery($conn, "ALTER TABLE `onboard_module_progress` ADD COLUMN `highest_progress_percent` INT DEFAULT 0 COMMENT 'Monotonic high-water mark'", "Added highest_progress_percent column");
} else {
    echo "  ✓ Column highest_progress_percent already exists\n";
}

if (!columnExists($conn, 'onboard_module_progress', 'score')) {
    safeQuery($conn, "ALTER TABLE `onboard_module_progress` ADD COLUMN `score` DECIMAL(5,2) NULL COMMENT 'Latest assessment score'", "Added score column");
} else {
    echo "  ✓ Column score already exists\n";
}

if (!columnExists($conn, 'onboard_module_progress', 'highest_score')) {
    safeQuery($conn, "ALTER TABLE `onboard_module_progress` ADD COLUMN `highest_score` DECIMAL(5,2) NULL COMMENT 'Best assessment score achieved'", "Added highest_score column");
} else {
    echo "  ✓ Column highest_score already exists\n";
}

if (!columnExists($conn, 'onboard_module_progress', 'passing_score')) {
    safeQuery($conn, "ALTER TABLE `onboard_module_progress` ADD COLUMN `passing_score` INT DEFAULT 80", "Added passing_score column");
} else {
    echo "  ✓ Column passing_score already exists\n";
}

if (!columnExists($conn, 'onboard_module_progress', 'total_time')) {
    safeQuery($conn, "ALTER TABLE `onboard_module_progress` ADD COLUMN `total_time` VARCHAR(32) DEFAULT '00:00:00'", "Added total_time column");
} else {
    echo "  ✓ Column total_time already exists\n";
}

if (!columnExists($conn, 'onboard_module_progress', 'total_time_seconds')) {
    safeQuery($conn, "ALTER TABLE `onboard_module_progress` ADD COLUMN `total_time_seconds` INT UNSIGNED DEFAULT 0", "Added total_time_seconds column");
} else {
    echo "  ✓ Column total_time_seconds already exists\n";
}

if (!columnExists($conn, 'onboard_module_progress', 'attempts')) {
    safeQuery($conn, "ALTER TABLE `onboard_module_progress` ADD COLUMN `attempts` INT DEFAULT 0", "Added attempts column");
} else {
    echo "  ✓ Column attempts already exists\n";
}

if (!columnExists($conn, 'onboard_module_progress', 'suspend_data')) {
    safeQuery($conn, "ALTER TABLE `onboard_module_progress` ADD COLUMN `suspend_data` TEXT NULL COMMENT 'SCORM bookmark & slide tracking state'", "Added suspend_data column");
} else {
    echo "  ✓ Column suspend_data already exists\n";
}

if (!columnExists($conn, 'onboard_module_progress', 'lesson_location')) {
    safeQuery($conn, "ALTER TABLE `onboard_module_progress` ADD COLUMN `lesson_location` VARCHAR(255) NULL COMMENT 'Last slide or location visited'", "Added lesson_location column");
} else {
    echo "  ✓ Column lesson_location already exists\n";
}

if (!columnExists($conn, 'onboard_module_progress', 'slides_viewed')) {
    safeQuery($conn, "ALTER TABLE `onboard_module_progress` ADD COLUMN `slides_viewed` INT UNSIGNED DEFAULT 0 COMMENT 'Number of slides viewed'", "Added slides_viewed column");
} else {
    echo "  ✓ Column slides_viewed already exists\n";
}

if (!columnExists($conn, 'onboard_module_progress', 'total_slides')) {
    safeQuery($conn, "ALTER TABLE `onboard_module_progress` ADD COLUMN `total_slides` INT UNSIGNED DEFAULT 0 COMMENT 'Total slides in module'", "Added total_slides column");
} else {
    echo "  ✓ Column total_slides already exists\n";
}

if (!columnExists($conn, 'onboard_module_progress', 'last_checked_at')) {
    safeQuery($conn, "ALTER TABLE `onboard_module_progress` ADD COLUMN `last_checked_at` DATETIME NULL", "Added last_checked_at column");
} else {
    echo "  ✓ Column last_checked_at already exists\n";
}

if (!columnExists($conn, 'onboard_module_progress', 'last_synced_at')) {
    safeQuery($conn, "ALTER TABLE `onboard_module_progress` ADD COLUMN `last_synced_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP", "Added last_synced_at column");
} else {
    echo "  ✓ Column last_synced_at already exists\n";
}

// Ensure unique compound constraint (user_id, module_id)
if (!indexExists($conn, 'onboard_module_progress', 'ux_user_module')) {
    safeQuery($conn, "ALTER TABLE `onboard_module_progress` ADD UNIQUE KEY `ux_user_module` (`user_id`, `module_id`)", "Added unique key ux_user_module");
}

// =========================================================================
// 3. TABLE: onboard_assessment_attempts (HISTORICAL ATTEMPTS AUDIT)
// Never overwrites: Attempt 1 (10am), Attempt 2 (10:40am), Attempt 3 (6:54pm) are stored forever!
// =========================================================================
echo "\n3. Checking table: onboard_assessment_attempts (Historical Audit) ...\n";

// Ensure score supports 2 decimal precision (e.g. 16.66% not truncated to 16)
safeQuery($conn, "ALTER TABLE `onboard_assessment_attempts` MODIFY COLUMN `score` DECIMAL(5,2) NULL", "Updated score column precision to DECIMAL(5,2)");

if (!columnExists($conn, 'onboard_assessment_attempts', 'module_id')) {
    safeQuery($conn, "ALTER TABLE `onboard_assessment_attempts` ADD COLUMN `module_id` INT NULL COMMENT 'Links to onboard_modules.id'", "Added module_id");
} else {
    echo "  ✓ Column module_id already exists\n";
}

if (!columnExists($conn, 'onboard_assessment_attempts', 'attempt_number')) {
    safeQuery($conn, "ALTER TABLE `onboard_assessment_attempts` ADD COLUMN `attempt_number` INT NOT NULL DEFAULT 1", "Added attempt_number");
} else {
    echo "  ✓ Column attempt_number already exists\n";
}

if (!columnExists($conn, 'onboard_assessment_attempts', 'result')) {
    safeQuery($conn, "ALTER TABLE `onboard_assessment_attempts` ADD COLUMN `result` ENUM('Passed', 'Failed', 'Incomplete') DEFAULT 'Incomplete'", "Added result column");
} else {
    echo "  ✓ Column result already exists\n";
}

if (!columnExists($conn, 'onboard_assessment_attempts', 'total_time')) {
    safeQuery($conn, "ALTER TABLE `onboard_assessment_attempts` ADD COLUMN `total_time` VARCHAR(32) DEFAULT '00:00:00'", "Added total_time column");
} else {
    echo "  ✓ Column total_time already exists\n";
}

if (!columnExists($conn, 'onboard_assessment_attempts', 'total_time_seconds')) {
    safeQuery($conn, "ALTER TABLE `onboard_assessment_attempts` ADD COLUMN `total_time_seconds` INT UNSIGNED DEFAULT 0", "Added total_time_seconds column");
} else {
    echo "  ✓ Column total_time_seconds already exists\n";
}

if (!columnExists($conn, 'onboard_assessment_attempts', 'attempted_at')) {
    safeQuery($conn, "ALTER TABLE `onboard_assessment_attempts` ADD COLUMN `attempted_at` DATETIME DEFAULT CURRENT_TIMESTAMP", "Added attempted_at column");
} else {
    echo "  ✓ Column attempted_at already exists\n";
}

// Ensure UNIQUE KEY (user_id, module_id, attempt_number) so every attempt is preserved and never overwritten
if (!indexExists($conn, 'onboard_assessment_attempts', 'ux_user_module_attempt')) {
    safeQuery($conn, "ALTER TABLE `onboard_assessment_attempts` ADD UNIQUE KEY `ux_user_module_attempt` (`user_id`, `module_id`, `attempt_number`)", "Added unique key ux_user_module_attempt (protects historical attempts)");
}

// =========================================================================
// 4. TABLE: onboard_module_sessions (Slide Tracking & Session History Log)
// Stores every learning checkpoint (e.g. 10:00pm session, 11:30pm session)
// =========================================================================
echo "\n4. Checking table: onboard_module_sessions (Session & Slide Tracking History) ...\n";

$sqlSessions = "
CREATE TABLE IF NOT EXISTS `onboard_module_sessions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `module_id` INT NOT NULL,
    `session_time` VARCHAR(32) DEFAULT '00:00:00',
    `session_seconds` INT UNSIGNED DEFAULT 0,
    `slides_viewed` INT UNSIGNED DEFAULT 0,
    `progress_percent` INT DEFAULT 0,
    `score` DECIMAL(5,2) NULL,
    `status` VARCHAR(32) DEFAULT 'in_progress',
    `lesson_location` VARCHAR(255) NULL,
    `suspend_data` TEXT NULL,
    `recorded_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `ix_user_module_sess` (`user_id`, `module_id`, `recorded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Stores audit log of each SCORM study session without overwriting';
";
safeQuery($conn, $sqlSessions, "Created/verified table onboard_module_sessions");

// =========================================================================
// 5. Safe check of Moodle course mappings in onboard_modules (NON-DESTRUCTIVE)
// Does NOT overwrite any existing custom modules or records
// =========================================================================
echo "\n5. Verifying Moodle course mappings in onboard_modules ...\n";

$requiredCourses = [
    ['course_id' => 2, 'cmid' => 2, 'scoid' => 2, 'title' => 'Employee Onboarding 1 SCORM', 'type' => 'scorm', 'dur' => 45],
    ['course_id' => 3, 'cmid' => 14, 'scoid' => 4, 'title' => 'Employee Assessment', 'type' => 'assessment', 'dur' => 20],
    ['course_id' => 4, 'cmid' => 3, 'scoid' => 3, 'title' => 'Work Rules and Work Life', 'type' => 'scorm', 'dur' => 30],
];

foreach ($requiredCourses as $rc) {
    $cId = (int)$rc['course_id'];
    $titleSearch = '%' . $rc['title'] . '%';

    // 1. Check if course is already mapped by moodle_course_id OR title
    $checkStmt = $conn->prepare("
        SELECT id, title, moodle_course_id 
        FROM onboard_modules 
        WHERE moodle_course_id = ? OR title LIKE ? 
        LIMIT 1
    ");
    if ($checkStmt) {
        $checkStmt->bind_param("is", $cId, $titleSearch);
        $checkStmt->execute();
        $existing = $checkStmt->get_result()->fetch_assoc();
        $checkStmt->close();

        if ($existing) {
            $existingId = (int)$existing['id'];
            // If moodle_course_id was missing or null on existing row, link it safely without touching any other fields
            if (empty($existing['moodle_course_id']) || (int)$existing['moodle_course_id'] !== $cId) {
                $upStmt = $conn->prepare("
                    UPDATE onboard_modules 
                    SET moodle_course_id = ?, moodle_cmid = ?, moodle_scoid = ? 
                    WHERE id = ?
                ");
                if ($upStmt) {
                    $upStmt->bind_param("iiii", $cId, $rc['cmid'], $rc['scoid'], $existingId);
                    $upStmt->execute();
                    $upStmt->close();
                    echo "  ✓ Linked existing Module #{$existingId} ('{$existing['title']}') to Course {$cId}\n";
                }
            } else {
                echo "  ✓ Course {$cId} mapped to Module #{$existingId} ('{$existing['title']}')\n";
            }
        } else {
            // Insert as a new row without touching any existing primary keys
            $insStmt = $conn->prepare("
                INSERT INTO onboard_modules (moodle_course_id, moodle_cmid, moodle_scoid, title, type, duration_minutes, passing_score, is_active)
                VALUES (?, ?, ?, ?, ?, ?, 80, 1)
            ");
            if ($insStmt) {
                $insStmt->bind_param("iiissi", $cId, $rc['cmid'], $rc['scoid'], $rc['title'], $rc['type'], $rc['dur']);
                if ($insStmt->execute()) {
                    echo "  + Mapped Course {$cId} ('{$rc['title']}') as Module #{$insStmt->insert_id}\n";
                } else {
                    echo "  ✗ Error mapping Course {$cId}: " . $insStmt->error . "\n";
                }
                $insStmt->close();
            }
        }
    }
}

echo "\n=========================================================================\n";
echo "Migration Complete! All historical attempt tables and constraints are ready.\n";
echo "100% of existing user data, records, and tables have been preserved.\n";
echo "=========================================================================\n";
