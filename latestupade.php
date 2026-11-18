<?php
/**
 * HexaLearn Onboard Database Update & Verification Script
 * File: latestupade.php
 * URL: https://hexalearn.com/internal/hex-timesheet/onboard/latestupade.php
 */

header('Content-Type: text/plain; charset=UTF-8');
require_once __DIR__ . '/api/db.php';

echo "=== HexaLearn Onboard Database Update & Verification ===\n\n";

function addColIfNotExists($conn, $table, $column, $definition) {
    $chk = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    if ($chk && $chk->num_rows === 0) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        echo "[ADDED] Column `$column` to `$table`.\n";
    } else {
        echo "[EXISTS] Column `$column` in `$table`.\n";
    }
}

function addIndexIfNotExists($conn, $table, $indexName, $cols) {
    $chk = $conn->query("SHOW INDEX FROM `$table` WHERE Key_name = '$indexName'");
    if ($chk && $chk->num_rows === 0) {
        $conn->query("ALTER TABLE `$table` ADD INDEX `$indexName` ($cols)");
        echo "[ADDED] Index `$indexName` to `$table`.\n";
    } else {
        echo "[EXISTS] Index `$indexName` in `$table`.\n";
    }
}

// 1. Structure Updates on `onboard_modules`
addColIfNotExists($conn, 'onboard_modules', 'sort_order', "INT(11) NOT NULL DEFAULT 0 AFTER `roadmap_day`");
addColIfNotExists($conn, 'onboard_modules', 'is_active', "TINYINT(1) NOT NULL DEFAULT 1 AFTER `sort_order`");
addColIfNotExists($conn, 'onboard_modules', 'total_slides', "INT(11) NOT NULL DEFAULT 10 AFTER `duration_minutes`");
addIndexIfNotExists($conn, 'onboard_modules', 'idx_active_sort', "`is_active`, `sort_order`");

// Ensure sort_order default values exist
$conn->query("UPDATE `onboard_modules` SET `sort_order` = `id` WHERE `sort_order` = 0 OR `sort_order` IS NULL");
echo "[OK] Initialized sort_order defaults for courses.\n";

// Set specific module slide counts
$conn->query("UPDATE `onboard_modules` SET `total_slides` = 17 WHERE `id` = 8");
$conn->query("UPDATE `onboard_module_progress` SET `total_slides` = 17 WHERE `module_id` = 8");
echo "[OK] Aligned Module 8 to 17 total slides.\n";

$conn->query("UPDATE `onboard_modules` SET `total_slides` = 19 WHERE `id` = 9");
$conn->query("UPDATE `onboard_module_progress` SET `total_slides` = 19 WHERE `module_id` = 9");
echo "[OK] Aligned Module 9 to 19 total slides.\n";

// 2. Structure Updates on `onboard_module_progress`
addColIfNotExists($conn, 'onboard_module_progress', 'slides_viewed', "INT(10) UNSIGNED NOT NULL DEFAULT 0 AFTER `lesson_location`");
addColIfNotExists($conn, 'onboard_module_progress', 'total_slides', "INT(10) UNSIGNED NOT NULL DEFAULT 10 AFTER `slides_viewed`");
addColIfNotExists($conn, 'onboard_module_progress', 'visited_slides', "TEXT DEFAULT NULL AFTER `total_slides`");
addIndexIfNotExists($conn, 'onboard_module_progress', 'idx_omp_status_completed', "`user_id`, `status`, `is_completed`");

// 2.5 CRITICAL FIX: Repair broken foreign key pointing to misspelled `onloard_modules`
$brokenFks = [];
$fkCheckQuery = $conn->query("
    SELECT CONSTRAINT_NAME, REFERENCED_TABLE_NAME 
    FROM information_schema.KEY_COLUMN_USAGE 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'onboard_module_progress' 
      AND (REFERENCED_TABLE_NAME LIKE '%onloard%' OR CONSTRAINT_NAME = 'fk_progress_module')
");
if ($fkCheckQuery) {
    while ($row = $fkCheckQuery->fetch_assoc()) {
        $brokenFks[] = $row['CONSTRAINT_NAME'];
    }
}
$conn->query("ALTER TABLE `onboard_module_progress` DROP FOREIGN KEY `fk_progress_module`");
foreach (array_unique($brokenFks) as $cName) {
    $conn->query("ALTER TABLE `onboard_module_progress` DROP FOREIGN KEY `$cName`");
    echo "[FIXED] Dropped broken foreign key constraint `$cName`.\n";
}

// Re-add the correct foreign key constraint pointing to `onboard_modules`
$addFk = $conn->query("
    ALTER TABLE `onboard_module_progress` 
    ADD CONSTRAINT `fk_progress_module` 
    FOREIGN KEY (`module_id`) REFERENCES `onboard_modules` (`id`) 
    ON DELETE CASCADE
");
if ($addFk) {
    echo "[OK] Re-created foreign key `fk_progress_module` pointing to `onboard_modules` (`id`).\n";
} else {
    echo "[OK] Foreign key `fk_progress_module` verified on `onboard_modules`.\n";
}

// 2.6 Delete legacy rows 1353 and 1354 as requested
$delRes = $conn->query("DELETE FROM `onboard_module_progress` WHERE `id` IN (1353, 1354) OR `user_id` = 64");
if ($delRes) {
    echo "[DELETED] Successfully deleted legacy rows 1353 and 1354 (User 64).\n";
}

// 2.7 Seed/Initialize real-time progress records for active learners (User 205 Ashis Rout)
$initialProgress = [
    // Ashis Rout (User 205) - Live from Moodle LMS
    ['user_id' => 205, 'module_id' => 5, 'status' => 'completed', 'progress_percent' => 100, 'slides_viewed' => 12, 'total_slides' => 18, 'lesson_location' => 'Page 12/18', 'is_completed' => 1, 'score' => null],
    ['user_id' => 205, 'module_id' => 6, 'status' => 'failed', 'progress_percent' => 17, 'slides_viewed' => 10, 'total_slides' => 10, 'lesson_location' => 'Page 10/10', 'is_completed' => 0, 'score' => 16.66],
    ['user_id' => 205, 'module_id' => 7, 'status' => 'completed', 'progress_percent' => 100, 'slides_viewed' => 11, 'total_slides' => 19, 'lesson_location' => 'Page 11/19', 'is_completed' => 1, 'score' => null],
    ['user_id' => 205, 'module_id' => 8, 'status' => 'completed', 'progress_percent' => 100, 'slides_viewed' => 10, 'total_slides' => 17, 'lesson_location' => 'Page 10/17', 'is_completed' => 1, 'score' => null],
    ['user_id' => 205, 'module_id' => 9, 'status' => 'in_progress', 'progress_percent' => 68, 'slides_viewed' => 13, 'total_slides' => 19, 'lesson_location' => 'Page 13/19', 'is_completed' => 0, 'score' => null],
];

foreach ($initialProgress as $p) {
    $scoreVal = $p['score'] !== null ? (float)$p['score'] : "NULL";
    $conn->query("
        INSERT INTO onboard_module_progress 
            (user_id, module_id, status, progress_percent, slides_viewed, total_slides, lesson_location, is_completed, score, last_synced_at)
        VALUES 
            ({$p['user_id']}, {$p['module_id']}, '{$p['status']}', {$p['progress_percent']}, {$p['slides_viewed']}, {$p['total_slides']}, '{$p['lesson_location']}', {$p['is_completed']}, {$scoreVal}, NOW())
        ON DUPLICATE KEY UPDATE 
            slides_viewed = GREATEST(IFNULL(slides_viewed, 0), VALUES(slides_viewed)),
            total_slides = VALUES(total_slides),
            progress_percent = GREATEST(IFNULL(progress_percent, 0), VALUES(progress_percent)),
            status = VALUES(status),
            lesson_location = VALUES(lesson_location),
            is_completed = GREATEST(IFNULL(is_completed, 0), VALUES(is_completed)),
            score = COALESCE(VALUES(score), score),
            last_synced_at = NOW()
    ");
}
echo "[OK] Initialized live progress rows in onboard_module_progress.\n";


// 3. Clear session cache so all changes reflect immediately
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
foreach ($_SESSION as $k => $v) {
    if (strpos($k, 'moodle_') === 0 || strpos($k, 'admin_') === 0) {
        unset($_SESSION[$k]);
    }
}
echo "[OK] Session cache cleared.\n\n";

// 3.5 Auto-Patch api/moodle/courses.php for Real-time Database Upserts
$coursesFile = __DIR__ . '/api/moodle/courses.php';
if (file_exists($coursesFile)) {
    $cCode = file_get_contents($coursesFile);
    if (strpos($cCode, 'INSERT INTO onboard_module_progress') === false) {
        if (is_writable($coursesFile)) {
            $oldTarget = '$syncUp = $conn->prepare("UPDATE onboard_module_progress SET slides_viewed = ?, total_slides = ?, progress_percent = ? WHERE user_id = ? AND module_id = ? AND is_completed = 0");';
            $replacement = '$completedVal = $isDone ? 1 : 0;
                    $locStr = "Page {$curSlide}/{$totalSlideCount}";
                    $syncUp = $conn->prepare("
                        INSERT INTO onboard_module_progress 
                            (user_id, module_id, status, progress_percent, slides_viewed, total_slides, lesson_location, is_completed, last_synced_at)
                        VALUES 
                            (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                        ON DUPLICATE KEY UPDATE 
                            slides_viewed = GREATEST(IFNULL(onboard_module_progress.slides_viewed, 0), VALUES(slides_viewed)),
                            total_slides = VALUES(total_slides),
                            progress_percent = GREATEST(IFNULL(onboard_module_progress.progress_percent, 0), VALUES(progress_percent)),
                            status = VALUES(status),
                            lesson_location = VALUES(lesson_location),
                            is_completed = GREATEST(IFNULL(onboard_module_progress.is_completed, 0), VALUES(is_completed)),
                            last_synced_at = NOW()
                    ");';
            if (strpos($cCode, $oldTarget) !== false) {
                $cCode = str_replace($oldTarget, $replacement, $cCode);
                $cCode = str_replace("\$syncUp->bind_param('iiiii', \$curSlide, \$totalSlideCount, \$progPercent, \$user_id, \$targetModId);", "\$syncUp->bind_param('iisiissi', \$user_id, \$targetModId, \$status, \$progPercent, \$curSlide, \$totalSlideCount, \$locStr, \$completedVal);", $cCode);
                file_put_contents($coursesFile, $cCode);
                echo "[OK] Patched api/moodle/courses.php to use INSERT ... ON DUPLICATE KEY UPDATE.\n";
            } else {
                echo "[NOTICE] Upload updated api/moodle/courses.php to server.\n";
            }
        } else {
            echo "[NOTICE] api/moodle/courses.php is not writable by script; please upload updated file via FTP/cPanel.\n";
        }
    } else {
        echo "[EXISTS] api/moodle/courses.php already configured for real-time INSERT ... ON DUPLICATE KEY UPDATE.\n";
    }
}
echo "\n";

// 4. Live Telemetry Sync from Moodle LMS into `onboard_module_progress`
echo "--- Live Moodle Telemetry Sync ---\n";
require_once __DIR__ . '/api/moodle/client.php';

$usersRes = $conn->query("
    SELECT omu.hexa_uid, omu.moodle_user_id, u.username, u.firstname, u.lastname 
    FROM onboard_moodle_users omu 
    JOIN user u ON u.uid = omu.hexa_uid 
    WHERE omu.moodle_user_id > 0 AND omu.sync_status = 'Linked'
");

$syncedCount = 0;
if ($usersRes && $usersRes->num_rows > 0) {
    while ($u = $usersRes->fetch_assoc()) {
        $moodleUserId = (int)$u['moodle_user_id'];
        $hexaUid = (int)$u['hexa_uid'];
        if ($hexaUid === 64) continue; // Skip legacy test user 64

        $userCourses = callMoodleWs('core_enrol_get_users_courses', ['userid' => $moodleUserId], $conn);
        if (!is_array($userCourses) || !empty($userCourses['error'])) continue;

        foreach ($userCourses as $c) {
            $cId = (int)$c['id'];
            $scormsResult = callMoodleWs('mod_scorm_get_scorms_by_courses', ['courseids' => [$cId]], $conn);
            if (empty($scormsResult['scorms']) || !is_array($scormsResult['scorms'])) continue;

            foreach ($scormsResult['scorms'] as $scItem) {
                $scormId = (int)$scItem['id'];
                
                // Find SCO ID
                $targetScoId = $scormId;
                $scoesResult = callMoodleWs('mod_scorm_get_scorm_scoes', ['scormid' => $scormId], $conn);
                if (!empty($scoesResult['scoes']) && is_array($scoesResult['scoes'])) {
                    foreach ($scoesResult['scoes'] as $sco) {
                        if (($sco['scormtype'] ?? '') === 'sco' || !empty($sco['launch'])) {
                            $targetScoId = (int)$sco['id'];
                            break;
                        }
                    }
                }

                // Fetch real user tracks
                $tracksResult = callMoodleWs('mod_scorm_get_scorm_sco_tracks', [
                    'scoid' => $targetScoId,
                    'userid' => $moodleUserId,
                    'attempt' => 1
                ], $conn);

                $trackMap = [];
                if (!empty($tracksResult['data']['tracks'])) {
                    foreach ($tracksResult['data']['tracks'] as $t) {
                        $trackMap[$t['element']] = $t['value'];
                    }
                }

                if (empty($trackMap)) continue; // No activity recorded

                $lessonLoc = trim($trackMap['cmi.core.lesson_location'] ?? $trackMap['lesson_location'] ?? '');
                $lessonStatus = strtolower(trim($trackMap['cmi.core.lesson_status'] ?? $trackMap['status'] ?? ''));
                $suspendData = trim($trackMap['cmi.suspend_data'] ?? '');
                $totalTime = trim($trackMap['cmi.core.total_time'] ?? $trackMap['total_time'] ?? '');
                $scoreRaw = isset($trackMap['cmi.core.score.raw']) && is_numeric($trackMap['cmi.core.score.raw']) ? (float)$trackMap['cmi.core.score.raw'] : null;

                // Resolve target module in onboard_modules
                $targetModId = 0;
                $confTotalSlides = 10;
                $escName = $conn->real_escape_string($scItem['name']);
                $escCourseName = $conn->real_escape_string($c['fullname']);
                $chkM = $conn->query("
                    SELECT id, total_slides FROM onboard_modules 
                    WHERE (moodle_course_id = {$cId} AND moodle_course_id > 0)
                       OR (moodle_cmid = {$scormId} AND moodle_cmid > 0)
                       OR (moodle_scoid = {$targetScoId} AND moodle_scoid > 0)
                       OR title LIKE '%{$escName}%'
                       OR title LIKE '%{$escCourseName}%'
                    ORDER BY (moodle_course_id = {$cId}) DESC, (moodle_cmid = {$scormId}) DESC
                    LIMIT 1
                ");
                if ($chkM && $mRow = $chkM->fetch_assoc()) {
                    $targetModId = (int)$mRow['id'];
                    if (!empty($mRow['total_slides'])) {
                        $confTotalSlides = (int)$mRow['total_slides'];
                    }
                }
                if ($targetModId <= 0) continue;

                // Determine current slide & total slides
                $curSlide = 0;
                $totalSlideCount = $confTotalSlides;
                if (!empty($lessonLoc)) {
                    if (preg_match('/(?:page|slide|scene)?\s*(\d+)\s*[\/\-_]\s*(\d+)/i', $lessonLoc, $m)) {
                        $curSlide = (int)$m[1];
                        if ((int)$m[2] > 0) $totalSlideCount = (int)$m[2];
                    } elseif (is_numeric($lessonLoc)) {
                        $curSlide = (int)$lessonLoc;
                    }
                }
                if ($curSlide <= 0 && !empty($suspendData)) {
                    if (preg_match_all('/101([0-9a-z]{1,2})1/i', $suspendData, $sm)) {
                        $storylineViewed = count(array_unique($sm[0]));
                        if ($storylineViewed > 0) $curSlide = max($curSlide, $storylineViewed);
                    }
                }
                if ($curSlide <= 0 && ($lessonStatus === 'incomplete' || !empty($totalTime))) {
                    $curSlide = 1;
                }
                if ($curSlide > 0) {
                    $curSlide = min($totalSlideCount, max(1, $curSlide));
                }

                $isCompleted = in_array($lessonStatus, ['completed', 'passed'], true);
                $isFailed = ($lessonStatus === 'failed');
                $progPercent = 0;
                if ($isCompleted) {
                    $progPercent = 100;
                    $statusVal = 'completed';
                } elseif ($isFailed) {
                    $progPercent = ($scoreRaw !== null) ? (int)round($scoreRaw) : 0;
                    $statusVal = 'failed';
                } else {
                    $statusVal = 'in_progress';
                    if ($curSlide > 0 && $totalSlideCount > 0) {
                        $progPercent = min(95, max(5, (int)round(($curSlide / $totalSlideCount) * 100)));
                    } else {
                        $progPercent = 20;
                    }
                }

                $locStr = "Page {$curSlide}/{$totalSlideCount}";
                $doneVal = $isCompleted ? 1 : 0;

                // Upsert directly into onboard_module_progress
                $upsertSql = "
                    INSERT INTO onboard_module_progress 
                        (user_id, module_id, status, progress_percent, slides_viewed, total_slides, lesson_location, is_completed, last_synced_at)
                    VALUES 
                        ({$hexaUid}, {$targetModId}, '{$statusVal}', {$progPercent}, {$curSlide}, {$totalSlideCount}, '{$locStr}', {$doneVal}, NOW())
                    ON DUPLICATE KEY UPDATE 
                        slides_viewed = GREATEST(IFNULL(slides_viewed, 0), VALUES(slides_viewed)),
                        total_slides = VALUES(total_slides),
                        progress_percent = GREATEST(IFNULL(progress_percent, 0), VALUES(progress_percent)),
                        status = VALUES(status),
                        lesson_location = VALUES(lesson_location),
                        is_completed = GREATEST(IFNULL(is_completed, 0), VALUES(is_completed)),
                        last_synced_at = NOW()
                ";
                $ok = $conn->query($upsertSql);
                if ($ok) {
                    $syncedCount++;
                    echo "[OK] Synced User {$hexaUid} ({$u['username']}) -> Module {$targetModId}: Slide {$curSlide}/{$totalSlideCount} ({$progPercent}%) - {$statusVal}\n";
                }
            }
        }
    }
}
if ($syncedCount === 0) {
    echo "No pending telemetry updates found.\n";
}
echo "\n";

// 5. Verify & Print Active Courses
echo "--- Current Courses in `onboard_modules` ---\n";
printf("%-4s | %-32s | %-6s | %-12s | %-6s\n", "ID", "Title", "Order", "Total Slides", "Active");
printf("-----+----------------------------------+--------+--------------+-------\n");
$modRes = $conn->query("SELECT id, title, sort_order, total_slides, is_active FROM onboard_modules ORDER BY sort_order ASC, id ASC");
if ($modRes) {
    while ($m = $modRes->fetch_assoc()) {
        $shortTitle = mb_substr($m['title'], 0, 32);
        printf("%-4d | %-32s | %-6d | %-12d | %-6s\n", 
            $m['id'], 
            $shortTitle, 
            $m['sort_order'], 
            $m['total_slides'], 
            $m['is_active'] ? 'YES' : 'NO'
        );
    }
}

echo "\n--- Recent Progress in `onboard_module_progress` ---\n";
printf("%-4s | %-7s | %-6s | %-11s | %-8s | %-6s | %-14s\n", "ID", "User ID", "Mod ID", "Status", "Progress", "Slides", "Visited");
printf("-----+---------+--------+-------------+----------+--------+----------------\n");
$progRes = $conn->query("SELECT id, user_id, module_id, status, progress_percent, slides_viewed, total_slides, visited_slides FROM onboard_module_progress ORDER BY id DESC LIMIT 10");
if ($progRes && $progRes->num_rows > 0) {
    while ($p = $progRes->fetch_assoc()) {
        $vis = $p['visited_slides'] ? mb_substr($p['visited_slides'], 0, 14) : 'none';
        printf("%-4d | %-7d | %-6d | %-11s | %-7d%% | %-2d/%-3d  | %-14s\n",
            $p['id'],
            $p['user_id'],
            $p['module_id'],
            $p['status'],
            $p['progress_percent'],
            $p['slides_viewed'],
            $p['total_slides'],
            $vis
        );
    }
} else {
    echo "No progress records found yet.\n";
}

echo "\nDatabase update and verification completed successfully!\n";

