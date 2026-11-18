<?php
/**
 * Direct Live Progress Sync & Diagnosis Tool
 * File: sync_test.php
 * URL: https://hexalearn.com/internal/hex-timesheet/onboard/sync_test.php
 */

header('Content-Type: text/plain; charset=UTF-8');
require_once __DIR__ . '/api/db.php';
require_once __DIR__ . '/api/moodle/client.php';

echo "=== HexaLearn Real-Time Progress Sync ===\n\n";

// 1. Fetch linked employees
$usersRes = $conn->query("
    SELECT u.hexa_uid, u.username, u.moodle_user_id 
    FROM onboard_moodle_users u 
    WHERE u.moodle_user_id IS NOT NULL AND u.moodle_user_id > 0
");

if (!$usersRes || $usersRes->num_rows === 0) {
    echo "No linked Moodle users found.\n";
    exit;
}

while ($emp = $usersRes->fetch_assoc()) {
    $uid = (int)$emp['hexa_uid'];
    $moodleUid = (int)$emp['moodle_user_id'];
    $username = $emp['username'];

    echo "Checking employee: {$username} (Hexa UID: {$uid}, Moodle UID: {$moodleUid})\n";

    // Call Moodle API for courses
    $coursesRes = callMoodleWs('core_enrol_get_users_courses', ['userid' => $moodleUid], $conn);
    if (!is_array($coursesRes) || isset($coursesRes['error'])) {
        echo "  [ERROR] Failed to fetch courses from Moodle.\n";
        continue;
    }

    foreach ($coursesRes as $course) {
        $cId = (int)($course['id'] ?? 0);
        $cName = $course['fullname'] ?? '';

        // Fetch SCORM packages
        $scormsRes = callMoodleWs('mod_scorm_get_scorms_by_courses', ['courseids' => [$cId]], $conn);
        if (empty($scormsRes['scorms']) || !is_array($scormsRes['scorms'])) {
            continue;
        }

        foreach ($scormsRes['scorms'] as $sc) {
            $scId = (int)$sc['id'];
            $scName = $sc['name'] ?? '';

            // Fetch user SCORM tracks from Moodle
            $tracksRes = callMoodleWs('mod_scorm_get_scorm_user_data', [
                'scoid' => $scId,
                'attempt' => 1
            ], $conn);

            $tracks = $tracksRes['data']['tracks'] ?? [];
            $trackMap = [];
            foreach ($tracks as $t) {
                if (isset($t['element']) && isset($t['value'])) {
                    $trackMap[$t['element']] = $t['value'];
                }
            }

            // Extract slide location
            $lessonLoc = $trackMap['cmi.core.lesson_location'] ?? '';
            $status = $trackMap['cmi.core.lesson_status'] ?? 'not_started';
            $curSlide = 1;
            $totalSlides = 10;

            if (preg_match('/(?:page|slide)?\s*(\d+)\s*[\/\-_]\s*(\d+)/i', $lessonLoc, $m)) {
                $curSlide = (int)$m[1];
                $totalSlides = (int)$m[2];
            } elseif (is_numeric($lessonLoc)) {
                $curSlide = (int)$lessonLoc;
            }

            $progPercent = ($totalSlides > 0) ? min(100, (int)round(($curSlide / $totalSlides) * 100)) : 0;
            $isDone = in_array(strtolower($status), ['completed', 'passed'], true) ? 1 : 0;
            if ($isDone) {
                $progPercent = 100;
                $status = 'completed';
            } elseif (in_array(strtolower($status), ['incomplete', 'browsed', 'suspend'], true) || $progPercent > 0) {
                $status = 'in_progress';
            }

            // Find matching module in onboard_modules
            $mId = 0;
            $escName = $conn->real_escape_string($cName);
            $escScName = $conn->real_escape_string($scName);
            $chkM = $conn->query("
                SELECT id, total_slides FROM onboard_modules 
                WHERE moodle_course_id = {$cId} 
                   OR title LIKE '%{$escName}%' 
                   OR title LIKE '%{$escScName}%'
                LIMIT 1
            ");
            if ($chkM && $mRow = $chkM->fetch_assoc()) {
                $mId = (int)$mRow['id'];
                if ($totalSlides === 10 && !empty($mRow['total_slides'])) {
                    $totalSlides = (int)$mRow['total_slides'];
                    $progPercent = min(100, (int)round(($curSlide / $totalSlides) * 100));
                }
            }

            if ($mId > 0 && ($progPercent > 0 || $isDone || $curSlide > 1)) {
                $locStr = "Page {$curSlide}/{$totalSlides}";
                $syncSql = "
                    INSERT INTO onboard_module_progress 
                        (user_id, module_id, status, progress_percent, slides_viewed, total_slides, lesson_location, is_completed, last_synced_at)
                    VALUES 
                        ({$uid}, {$mId}, '{$status}', {$progPercent}, {$curSlide}, {$totalSlides}, '{$locStr}', {$isDone}, NOW())
                    ON DUPLICATE KEY UPDATE 
                        slides_viewed = GREATEST(IFNULL(slides_viewed, 0), VALUES(slides_viewed)),
                        total_slides = VALUES(total_slides),
                        progress_percent = GREATEST(IFNULL(progress_percent, 0), VALUES(progress_percent)),
                        status = VALUES(status),
                        lesson_location = VALUES(lesson_location),
                        is_completed = GREATEST(IFNULL(is_completed, 0), VALUES(is_completed)),
                        last_synced_at = NOW()
                ";
                $ok = $conn->query($syncSql);
                if ($ok) {
                    echo "  -> [SYNCED] Module {$mId} ({$cName}): Slide {$curSlide}/{$totalSlides} ({$progPercent}%) - Status: {$status}\n";
                } else {
                    echo "  -> [ERROR] Failed to save: " . $conn->error . "\n";
                }
            }
        }
    }
    echo "\n";
}

echo "--- Current `onboard_module_progress` Database Rows ---\n";
printf("%-5s | %-8s | %-8s | %-12s | %-8s | %-12s | %-14s | %-19s\n", "ID", "User ID", "Mod ID", "Status", "Progress", "Slides", "Location", "Last Synced");
printf("------+----------+----------+--------------+----------+--------------+----------------+--------------------\n");
$res = $conn->query("SELECT id, user_id, module_id, status, progress_percent, slides_viewed, total_slides, lesson_location, last_synced_at FROM onboard_module_progress ORDER BY id DESC LIMIT 15");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        printf("%-5d | %-8d | %-8d | %-12s | %-7d%% | %-2d / %-6d | %-14s | %-19s\n",
            $r['id'],
            $r['user_id'],
            $r['module_id'],
            $r['status'],
            $r['progress_percent'],
            $r['slides_viewed'],
            $r['total_slides'],
            $r['lesson_location'] ?? 'none',
            $r['last_synced_at']
        );
    }
}

echo "\nSync check complete!\n";
