<?php
/**
 * Auto-Sync & Enrollment Engine for HexaLearn Moodle Integration
 * Location: api/moodle/sync_user.php
 * 
 * Automatically resolves, discovers, creates, and enrolls employees into Moodle LMS
 * on first entry to Onboarding.
 */

require_once __DIR__ . '/client.php';

/**
 * Ensures an employee is mapped, exists in Moodle, enrolled in Onboarding (Course 2),
 * and recorded in `onboard_moodle_users`.
 * 
 * @param int $hexaUid The Timesheet/HexaOne user ID
 * @param mysqli $conn Active database connection
 * @return int|null Returns Moodle User ID on success, or null on failure
 */
function autoSyncMoodleUser($hexaUid, $conn) {
    if (!$hexaUid || !$conn) {
        return null;
    }

    // 1. Fetch employee details from Timesheet `user` table (READ-ONLY)
    $stmt = $conn->prepare("SELECT * FROM user WHERE uid = ?");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param("i", $hexaUid);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user || (int)$user['status'] !== 0) {
        return null;
    }

    // 2. Check if employee already has an active link in `onboard_moodle_users`
    $stmt = $conn->prepare("
        SELECT moodle_user_id, sync_status, last_synced_at 
        FROM onboard_moodle_users 
        WHERE hexa_uid = ?
    ");
    $stmt->bind_param("i", $hexaUid);
    $stmt->execute();
    $mapping = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!empty($mapping['moodle_user_id']) && $mapping['sync_status'] === 'Linked') {
        $moodleUserId = (int)$mapping['moodle_user_id'];
        $lastSync = !empty($mapping['last_synced_at']) ? strtotime($mapping['last_synced_at']) : 0;
        // Throttle: Only query all Moodle category course enrollments if not synced in the last hour
        if (time() - $lastSync > 3600) {
            syncCategoryCoursesAndEnrollments($moodleUserId, $conn, 1);
        }
        return $moodleUserId;
    }

    $username = trim($user['username']);
    // Official employee email: ofc_mail or username@hexalearn.com (personal_email strictly forbidden)
    $officialEmail = !empty($user['ofc_mail']) ? trim($user['ofc_mail']) : (strtolower($username) . '@hexalearn.com');
    $firstname = !empty($user['firstname']) ? trim($user['firstname']) : $username;
    $lastname = !empty($user['lastname']) ? trim($user['lastname']) : 'HexaLearn';

    $moodleUser = null;
    $diagnostic = [
        'searched_username' => $username,
        'searched_email' => $officialEmail
    ];

    // 3. Step A: Search Moodle by username using core_user_get_users_by_field
    $resUser = callMoodleWs('core_user_get_users_by_field', [
        'field' => 'username',
        'values' => [strtolower($username)]
    ], $conn);
    $diagnostic['by_field_username'] = $resUser;

    if (!empty($resUser) && is_array($resUser) && empty($resUser['error']) && !empty($resUser[0]['id'])) {
        $moodleUser = $resUser[0];
    }

    // 3.5 Step A2: Fallback search using core_user_get_users criteria
    if (!$moodleUser) {
        $resCrit = callMoodleWs('core_user_get_users', [
            'criteria' => [
                ['key' => 'username', 'value' => strtolower($username)]
            ]
        ], $conn);
        $diagnostic['criteria_username'] = $resCrit;
        if (!empty($resCrit['users'][0]['id'])) {
            $moodleUser = $resCrit['users'][0];
        }
    }

    // 4. Step B: Search Moodle by official email if username not matched
    if (!$moodleUser && !empty($officialEmail)) {
        $resEmail = callMoodleWs('core_user_get_users_by_field', [
            'field' => 'email',
            'values' => [strtolower($officialEmail)]
        ], $conn);
        $diagnostic['by_field_email'] = $resEmail;
        if (!empty($resEmail) && is_array($resEmail) && empty($resEmail['error']) && !empty($resEmail[0]['id'])) {
            $moodleUser = $resEmail[0];
        }
    }

    $moodleUserId = null;

    // 5. If found in Moodle, use existing ID
    if ($moodleUser && !empty($moodleUser['id'])) {
        $moodleUserId = (int)$moodleUser['id'];
        $diagnostic['status'] = 'Found in Moodle with ID ' . $moodleUserId;

        // Keep Moodle profile (email & names) updated if changed in HexaOne
        $needsUpdate = false;
        $updateData = ['id' => $moodleUserId];
        if (!empty($officialEmail) && (!isset($moodleUser['email']) || strtolower($moodleUser['email']) !== strtolower($officialEmail))) {
            $updateData['email'] = strtolower($officialEmail);
            $needsUpdate = true;
        }
        if (!empty($firstname) && (!isset($moodleUser['firstname']) || trim($moodleUser['firstname']) !== trim($firstname))) {
            $updateData['firstname'] = $firstname;
            $needsUpdate = true;
        }
        if (!empty($lastname) && (!isset($moodleUser['lastname']) || trim($moodleUser['lastname']) !== trim($lastname))) {
            $updateData['lastname'] = $lastname;
            $needsUpdate = true;
        }
        if ($needsUpdate) {
            $updRes = callMoodleWs('core_user_update_users', [
                'users' => [$updateData]
            ], $conn);
            $diagnostic['user_update_response'] = $updRes;
        }
    } else {
        // 6. Step C: If user does not exist in Moodle, auto-create them using official email
        $tempPassword = 'Hexa#' . bin2hex(random_bytes(4)) . '!2026';

        $createRes = callMoodleWs('core_user_create_users', [
            'users' => [
                [
                    'username' => strtolower($username),
                    'email' => strtolower($officialEmail),
                    'firstname' => $firstname,
                    'lastname' => $lastname,
                    'auth' => 'manual',
                    'password' => $tempPassword
                ]
            ]
        ], $conn);
        $diagnostic['create_user_response'] = $createRes;

        if (!empty($createRes) && is_array($createRes) && empty($createRes['error']) && !empty($createRes[0]['id'])) {
            $moodleUserId = (int)$createRes[0]['id'];
            $diagnostic['status'] = 'Created new user in Moodle with ID ' . $moodleUserId;
        } else {
            $diagnostic['status'] = 'User creation failed: ' . ($createRes['message'] ?? 'Unknown Moodle error');
        }
    }

    $GLOBALS['moodle_sync_diagnostic'] = $diagnostic;

    // 7. If we have a resolved Moodle User ID:
    if ($moodleUserId) {
        // Auto-enrol user into ALL active courses under Category 1 (role 5 = student)
        $enrolledIds = syncCategoryCoursesAndEnrollments($moodleUserId, $conn, 1);
        $diagnostic['auto_enrolled_courses'] = $enrolledIds;
        $GLOBALS['moodle_sync_diagnostic'] = $diagnostic;

        // Update / Insert into `onboard_moodle_users`
        $stmtSave = $conn->prepare("
            INSERT INTO onboard_moodle_users (hexa_uid, username, moodle_user_id, sync_status, notes, last_synced_at)
            VALUES (?, ?, ?, 'Linked', 'Auto-synced and enrolled in Moodle', NOW())
            ON DUPLICATE KEY UPDATE 
                moodle_user_id = VALUES(moodle_user_id),
                sync_status = 'Linked',
                notes = 'Auto-synced and enrolled in Moodle',
                last_synced_at = NOW()
        ");
        if ($stmtSave) {
            $stmtSave->bind_param("isi", $hexaUid, $username, $moodleUserId);
            $stmtSave->execute();
            $stmtSave->close();
        }

        return $moodleUserId;
    }

    return null;
}

/**
 * Automatically ensures the employee (by Moodle User ID) is enrolled
 * in all active courses under the Onboarding curriculum and specified Moodle category (default categoryid = 1).
 *
 * @param int $moodleUserId
 * @param mysqli $conn
 * @param int $categoryId Default 1
 * @return array Array of enrolled category course IDs
 */
function syncCategoryCoursesAndEnrollments($moodleUserId, $conn, $categoryId = 1) {
    if (!$moodleUserId || !$conn) {
        return [];
    }

    // 1. Authoritative curriculum courses from onboard_modules
    $categoryCourseIds = [];
    $modRes = $conn->query("
        SELECT DISTINCT moodle_course_id 
        FROM onboard_modules 
        WHERE IFNULL(is_active, 1) = 1 AND moodle_course_id > 0
        ORDER BY moodle_course_id ASC
    ");
    if ($modRes) {
        while ($r = $modRes->fetch_assoc()) {
            $categoryCourseIds[] = (int)$r['moodle_course_id'];
        }
    }

    // 2. Fetch courses in target category from Moodle using fast, targeted query
    $catCoursesRes = callMoodleWs('core_course_get_courses_by_field', [
        'field' => 'category',
        'value' => (string)$categoryId
    ], $conn);

    if (!empty($catCoursesRes['courses']) && is_array($catCoursesRes['courses'])) {
        foreach ($catCoursesRes['courses'] as $c) {
            $cId = (int)($c['id'] ?? 0);
            if ($cId > 0 && !empty($c['visible']) && !in_array($cId, $categoryCourseIds, true)) {
                $categoryCourseIds[] = $cId;
            }
        }
    }

    // Fallback: If both queries failed, enforce known standard Onboard courses
    if (empty($categoryCourseIds)) {
        $categoryCourseIds = [2, 4, 5, 8, 6, 9, 10, 11, 12, 13, 3];
    }

    // 3. Fetch the user's currently enrolled courses
    $userCourses = callMoodleWs('core_enrol_get_users_courses', [
        'userid' => $moodleUserId
    ], $conn);

    $enrolledCourseIds = [];
    if (!empty($userCourses) && is_array($userCourses) && empty($userCourses['error'])) {
        foreach ($userCourses as $uc) {
            if (isset($uc['id'])) {
                $enrolledCourseIds[] = (int)$uc['id'];
            }
        }
    }

    // 4. Find any category courses the employee is NOT enrolled in yet
    $missingCourseIds = array_values(array_diff($categoryCourseIds, $enrolledCourseIds));

    // 5. Auto-enrol employee into all missing category courses (role 5 = student)
    if (!empty($missingCourseIds)) {
        $enrolments = [];
        foreach ($missingCourseIds as $missingId) {
            $enrolments[] = [
                'roleid'   => 5,
                'userid'   => $moodleUserId,
                'courseid' => $missingId
            ];
        }

        callMoodleWs('enrol_manual_enrol_users', [
            'enrolments' => $enrolments
        ], $conn);
    }

    return $categoryCourseIds;
}

/**
 * Syncs and enrolls all currently linked employees in `onboard_moodle_users`
 * into all courses of the target category.
 *
 * @param mysqli $conn
 * @param int $categoryId Default 1
 * @return array
 */
function syncAllLinkedEmployeesCategoryCourses($conn, $categoryId = 1) {
    $results = [];
    $res = $conn->query("
        SELECT hexa_uid, username, moodle_user_id 
        FROM onboard_moodle_users 
        WHERE sync_status = 'Linked' AND moodle_user_id IS NOT NULL AND moodle_user_id > 0
    ");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $mUserId = (int)$row['moodle_user_id'];
            $courseIds = syncCategoryCoursesAndEnrollments($mUserId, $conn, $categoryId);
            $results[] = [
                'hexa_uid' => (int)$row['hexa_uid'],
                'username' => $row['username'],
                'moodle_user_id' => $mUserId,
                'category_courses' => $courseIds
            ];
        }
    }
    return $results;
}

/**
 * Persists live SCORM tracking and multi-attempt assessment history into local MySQL tables:
 * 1. `onboard_module_progress`: Per-employee monotonic progress (time & progress never regress).
 * 2. `onboard_assessment_attempts`: Preserves every historical attempt (Attempt 1, 2, 3...).
 * 3. `onboard_module_sessions`: Records study session checkpoints for slide tracking audit.
 *
 * @param mysqli $conn Active database connection
 * @param int $userId HexaOne employee UID
 * @param array $enrichedCourses Enriched course and SCORM structures
 */
function persistMoodleTrackingToLocalSql($conn, $userId, $enrichedCourses) {
    if (!$conn || !$userId || empty($enrichedCourses) || !is_array($enrichedCourses)) {
        return;
    }

    $timeToSeconds = function($t) {
        if (empty($t)) return 0;
        if (is_numeric($t)) return (int)$t;
        $clean = trim(explode('.', (string)$t)[0]);
        $parts = explode(':', $clean);
        if (count($parts) === 3) {
            return ((int)$parts[0] * 3600) + ((int)$parts[1] * 60) + (int)$parts[2];
        } elseif (count($parts) === 2) {
            return ((int)$parts[0] * 60) + (int)$parts[1];
        }
        return 0;
    };

    foreach ($enrichedCourses as $course) {
        $courseId = (int)($course['id'] ?? 0);
        if (empty($course['modules']) || !is_array($course['modules'])) {
            continue;
        }

        foreach ($course['modules'] as $mod) {
            $cmid = (int)($mod['cmid'] ?? 0);
            $modTitle = trim($mod['title'] ?? '');
            $moduleId = null;

            // 1. Locate module in onboard_modules (course_id first, then cmid, then exact non-empty title)
            $titlePattern = ($modTitle !== '') ? ('%' . $modTitle . '%') : '__NO_TITLE_MATCH__';
            $modStmt = $conn->prepare("
                SELECT id, title, type, passing_score 
                FROM onboard_modules 
                WHERE (moodle_course_id = ? AND moodle_course_id > 0)
                   OR (moodle_cmid = ? AND moodle_cmid > 0)
                   OR title LIKE ?
                ORDER BY (moodle_course_id = ?) DESC, (moodle_cmid = ?) DESC
                LIMIT 1
            ");
            if ($modStmt) {
                $modStmt->bind_param("iisii", $courseId, $cmid, $titlePattern, $courseId, $cmid);
                $modStmt->execute();
                $modRow = $modStmt->get_result()->fetch_assoc();
                $modStmt->close();
                if ($modRow) {
                    $moduleId = (int)$modRow['id'];
                }
            }

            // If not yet cataloged in onboard_modules, safely insert as a new active module
            if (!$moduleId && !empty($modTitle)) {
                $modType = !empty($mod['is_assessment']) ? 'assessment' : 'scorm';
                $passing = (int)($mod['passing_score'] ?? 80);
                $insM = $conn->prepare("
                    INSERT INTO onboard_modules (moodle_course_id, moodle_cmid, title, type, passing_score, is_active)
                    VALUES (?, ?, ?, ?, ?, 1)
                ");
                if ($insM) {
                    $insM->bind_param("iissi", $courseId, $cmid, $modTitle, $modType, $passing);
                    $insM->execute();
                    $moduleId = $insM->insert_id;
                    $insM->close();
                }
            }

            if (!$moduleId) {
                continue;
            }

            $isModuleAssessment = (($modRow['type'] ?? '') === 'assessment') || (stripos($modRow['title'] ?? '', 'assess') !== false) || (stripos($modRow['title'] ?? '', 'quiz') !== false);
            $score = ($mod['score'] !== null) ? (float)$mod['score'] : null;
            $passingScore = (int)($mod['passing_score'] ?? $modRow['passing_score'] ?? 80);

            $isCourseDone = !empty($course['is_completed']) || !empty($course['completed']) || ($course['status'] ?? '') === 'completed';
            $status = $mod['status'] ?? 'not_started';
            $isCompleted = ($isCourseDone || !empty($mod['is_completed']) || in_array(strtolower($status), ['completed', 'passed'], true)) ? 1 : 0;
            if ($isModuleAssessment && $score !== null && $score < $passingScore) {
                $isCompleted = 0;
                $status = 'failed';
            } elseif (in_array(strtolower($status), ['incomplete', 'browsed', 'suspend', 'in_progress'], true)) {
                $isCompleted = 0;
                $status = 'in_progress';
            }

            $totalSlides = (int)($mod['total_slides'] ?? 0);
            $moodleVerified = !empty($mod['moodle_verified']);
            if ($isCompleted) {
                $status = 'completed';
                $progressPercent = 100;
                $curSlide = $totalSlides;
                $locStr = "Page {$totalSlides}/{$totalSlides}";
            } else {
                $progressPercent = (int)($mod['progress_percent'] ?? 0);
                $curSlide = (int)($mod['current_slide'] ?? 0);
                if (in_array(strtolower($status), ['available', 'not_started'], true) && $progressPercent === 0) {
                    $status = 'not_started';
                    $curSlide = 0;
                    $locStr = null;
                } else {
                    $locStr = ($curSlide > 0 && $totalSlides > 0) ? "Page {$curSlide}/{$totalSlides}" : ($mod['lesson_location'] ?? null);
                }
            }
            $totalTimeStr = $mod['total_time'] ?? '00:00:00';
            $totalTimeSec = $timeToSeconds($totalTimeStr);
            $attempts = (int)($mod['attempts'] ?? 0);
            $suspendData = $mod['suspend_data'] ?? '';

            // Skip inserting blank rows for unstarted modules when live Moodle confirms 0 progress; clear stale completion if verified
            if ($moodleVerified && $isCompleted === 0 && $status === 'not_started' && $progressPercent === 0) {
                $clrStmt = $conn->prepare("
                    UPDATE onboard_module_progress
                    SET status = 'not_started',
                        is_completed = 0,
                        progress_percent = 0,
                        highest_progress_percent = 0,
                        slides_viewed = 0,
                        total_slides = CASE WHEN ? > 0 THEN ? ELSE total_slides END,
                        lesson_location = NULL,
                        attempts = 0,
                        last_synced_at = NOW()
                    WHERE user_id = ? AND module_id = ?
                      AND (is_completed = 1 OR status = 'completed' OR progress_percent > 0)
                ");
                if ($clrStmt) {
                    $clrStmt->bind_param("iiii", $totalSlides, $totalSlides, $userId, $moduleId);
                    $clrStmt->execute();
                    $clrStmt->close();
                }
                continue;
            }

            // 2. Upsert into onboard_module_progress (Moodle-verified state takes priority over stale local flags)
            if ($moodleVerified) {
                $progStmt = $conn->prepare("
                    INSERT INTO onboard_module_progress 
                      (user_id, module_id, status, progress_percent, highest_progress_percent, score, highest_score, passing_score, total_time, total_time_seconds, attempts, suspend_data, is_completed, slides_viewed, total_slides, lesson_location, last_synced_at)
                    VALUES 
                      (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                      status = VALUES(status),
                      progress_percent = VALUES(progress_percent),
                      highest_progress_percent = CASE WHEN VALUES(is_completed) = 1 THEN 100 ELSE VALUES(progress_percent) END,
                      score = COALESCE(VALUES(score), onboard_module_progress.score),
                      highest_score = GREATEST(COALESCE(onboard_module_progress.highest_score, 0), COALESCE(VALUES(score), 0)),
                      total_time = CASE 
                        WHEN VALUES(total_time_seconds) >= IFNULL(onboard_module_progress.total_time_seconds, 0) THEN VALUES(total_time)
                        ELSE onboard_module_progress.total_time
                      END,
                      total_time_seconds = GREATEST(IFNULL(onboard_module_progress.total_time_seconds, 0), VALUES(total_time_seconds)),
                      attempts = GREATEST(IFNULL(onboard_module_progress.attempts, 0), VALUES(attempts)),
                      suspend_data = CASE 
                        WHEN VALUES(suspend_data) IS NOT NULL AND VALUES(suspend_data) != '' THEN VALUES(suspend_data)
                        ELSE onboard_module_progress.suspend_data
                      END,
                      is_completed = VALUES(is_completed),
                      lesson_location = CASE
                        WHEN VALUES(is_completed) = 1 OR VALUES(status) IN ('completed', 'passed')
                            THEN CONCAT('Page ', CASE WHEN VALUES(total_slides) > 0 THEN VALUES(total_slides) ELSE IFNULL(onboard_module_progress.total_slides, 10) END, '/', CASE WHEN VALUES(total_slides) > 0 THEN VALUES(total_slides) ELSE IFNULL(onboard_module_progress.total_slides, 10) END)
                        ELSE VALUES(lesson_location)
                      END,
                      slides_viewed = CASE
                        WHEN VALUES(is_completed) = 1 OR VALUES(status) IN ('completed', 'passed')
                            THEN CASE WHEN VALUES(total_slides) > 0 THEN VALUES(total_slides) ELSE IFNULL(onboard_module_progress.total_slides, 10) END
                        ELSE VALUES(slides_viewed)
                      END,
                      total_slides = CASE WHEN VALUES(total_slides) > 0 THEN VALUES(total_slides) ELSE onboard_module_progress.total_slides END,
                      last_synced_at = NOW()
                ");
            } else {
                $progStmt = $conn->prepare("
                    INSERT INTO onboard_module_progress 
                      (user_id, module_id, status, progress_percent, highest_progress_percent, score, highest_score, passing_score, total_time, total_time_seconds, attempts, suspend_data, is_completed, slides_viewed, total_slides, lesson_location, last_synced_at)
                    VALUES 
                      (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                      status = CASE 
                        WHEN IFNULL(onboard_module_progress.is_completed, 0) = 1 THEN 'completed'
                        WHEN VALUES(status) IN ('completed', 'passed') OR VALUES(is_completed) = 1 THEN 'completed'
                        WHEN VALUES(status) = 'failed' THEN 'failed'
                        WHEN VALUES(status) IN ('incomplete', 'in_progress', 'suspend', 'browsed') THEN 'in_progress'
                        WHEN IFNULL(onboard_module_progress.status, '') IN ('completed', 'passed') THEN 'completed'
                        WHEN IFNULL(onboard_module_progress.status, '') = 'in_progress' THEN 'in_progress'
                        WHEN VALUES(status) = 'not_started' AND VALUES(is_completed) = 0 AND VALUES(total_time_seconds) = 0 THEN IFNULL(onboard_module_progress.status, 'not_started')
                        ELSE VALUES(status)
                      END,
                      progress_percent = CASE
                        WHEN IFNULL(onboard_module_progress.is_completed, 0) = 1 THEN 100
                        WHEN VALUES(status) IN ('completed', 'passed') OR VALUES(is_completed) = 1 THEN 100
                        WHEN VALUES(status) IN ('incomplete', 'in_progress', 'suspend', 'browsed') THEN GREATEST(IFNULL(onboard_module_progress.progress_percent, 0), VALUES(progress_percent))
                        WHEN VALUES(status) = 'not_started' AND VALUES(is_completed) = 0 AND VALUES(total_time_seconds) = 0 THEN IFNULL(onboard_module_progress.progress_percent, 0)
                        ELSE GREATEST(IFNULL(onboard_module_progress.progress_percent, 0), VALUES(progress_percent))
                      END,
                      highest_progress_percent = CASE
                        WHEN IFNULL(onboard_module_progress.is_completed, 0) = 1 THEN 100
                        WHEN VALUES(status) IN ('completed', 'passed') OR VALUES(is_completed) = 1 THEN 100
                        WHEN VALUES(status) IN ('incomplete', 'in_progress', 'suspend', 'browsed') THEN GREATEST(IFNULL(onboard_module_progress.highest_progress_percent, 0), VALUES(progress_percent))
                        WHEN VALUES(status) = 'not_started' AND VALUES(is_completed) = 0 AND VALUES(total_time_seconds) = 0 THEN IFNULL(onboard_module_progress.highest_progress_percent, 0)
                        ELSE GREATEST(IFNULL(onboard_module_progress.highest_progress_percent, 0), VALUES(progress_percent))
                      END,
                      score = COALESCE(VALUES(score), onboard_module_progress.score),
                      highest_score = GREATEST(COALESCE(onboard_module_progress.highest_score, 0), COALESCE(VALUES(score), 0)),
                      total_time = CASE 
                        WHEN VALUES(status) = 'not_started' AND VALUES(is_completed) = 0 AND VALUES(total_time_seconds) = 0 THEN IFNULL(onboard_module_progress.total_time, '00:00:00')
                        WHEN VALUES(total_time_seconds) >= IFNULL(onboard_module_progress.total_time_seconds, 0) THEN VALUES(total_time)
                        ELSE onboard_module_progress.total_time
                      END,
                      total_time_seconds = CASE
                        WHEN VALUES(status) = 'not_started' AND VALUES(is_completed) = 0 AND VALUES(total_time_seconds) = 0 THEN IFNULL(onboard_module_progress.total_time_seconds, 0)
                        ELSE GREATEST(IFNULL(onboard_module_progress.total_time_seconds, 0), VALUES(total_time_seconds))
                      END,
                      attempts = CASE
                        WHEN VALUES(status) = 'not_started' AND VALUES(is_completed) = 0 AND VALUES(total_time_seconds) = 0 THEN IFNULL(onboard_module_progress.attempts, 0)
                        ELSE GREATEST(IFNULL(onboard_module_progress.attempts, 0), VALUES(attempts))
                      END,
                      suspend_data = CASE 
                        WHEN VALUES(suspend_data) IS NOT NULL AND VALUES(suspend_data) != '' THEN VALUES(suspend_data)
                        ELSE onboard_module_progress.suspend_data
                      END,
                      is_completed = CASE 
                        WHEN IFNULL(onboard_module_progress.is_completed, 0) = 1 THEN 1
                        WHEN VALUES(status) IN ('completed', 'passed') OR VALUES(is_completed) = 1 THEN 1
                        ELSE 0 
                      END,
                      lesson_location = CASE
                        WHEN IFNULL(onboard_module_progress.is_completed, 0) = 1 OR VALUES(is_completed) = 1 OR VALUES(status) IN ('completed', 'passed')
                            THEN CONCAT('Page ', CASE WHEN VALUES(total_slides) > 0 THEN VALUES(total_slides) ELSE IFNULL(onboard_module_progress.total_slides, 10) END, '/', CASE WHEN VALUES(total_slides) > 0 THEN VALUES(total_slides) ELSE IFNULL(onboard_module_progress.total_slides, 10) END)
                        WHEN VALUES(status) = 'not_started' OR VALUES(lesson_location) IS NULL OR TRIM(VALUES(lesson_location)) = '' OR VALUES(lesson_location) IN ('Page 0/10', 'Page 0/17', 'Page 0/18', 'Page 0/19', 'Page 0/21', 'Page 0/22')
                            THEN IFNULL(onboard_module_progress.lesson_location, VALUES(lesson_location))
                        WHEN VALUES(slides_viewed) >= IFNULL(onboard_module_progress.slides_viewed, 0) 
                            THEN VALUES(lesson_location)
                        ELSE IFNULL(onboard_module_progress.lesson_location, VALUES(lesson_location))
                      END,
                      slides_viewed = CASE
                        WHEN IFNULL(onboard_module_progress.is_completed, 0) = 1 OR VALUES(is_completed) = 1 OR VALUES(status) IN ('completed', 'passed')
                            THEN CASE WHEN VALUES(total_slides) > 0 THEN VALUES(total_slides) ELSE IFNULL(onboard_module_progress.total_slides, 10) END
                        WHEN VALUES(status) = 'not_started' AND VALUES(is_completed) = 0 AND VALUES(total_time_seconds) = 0 THEN IFNULL(onboard_module_progress.slides_viewed, 0)
                        ELSE GREATEST(IFNULL(onboard_module_progress.slides_viewed, 0), VALUES(slides_viewed))
                      END,
                      total_slides = CASE WHEN VALUES(total_slides) > 0 THEN VALUES(total_slides) ELSE onboard_module_progress.total_slides END,
                      last_synced_at = NOW()
                ");
            }

            if ($progStmt) {
                $progStmt->bind_param(
                    "iisiiddisiisiiss",
                    $userId,
                    $moduleId,
                    $status,
                    $progressPercent,
                    $progressPercent,
                    $score,
                    $score,
                    $passingScore,
                    $totalTimeStr,
                    $totalTimeSec,
                    $attempts,
                    $suspendData,
                    $isCompleted,
                    $curSlide,
                    $totalSlides,
                    $locStr
                );
                $progStmt->execute();
                $progStmt->close();
            }

            $isModuleAssessment = (($modRow['type'] ?? '') === 'assessment');

            // 3. Persist Historical Assessment Attempts (Attempt 1, Attempt 2, Attempt 3...)
            // Preserves every historical attempt (Attempt 1 at 10am, Attempt 2 at 10:40am, Attempt 3 at 6:54pm)
            if ($isModuleAssessment && !empty($mod['attempts_history']) && is_array($mod['attempts_history'])) {
                foreach ($mod['attempts_history'] as $att) {
                    $attNum = (int)($att['attempt'] ?? 1);
                    $attScore = ($att['score'] !== null) ? (float)$att['score'] : null;
                    // Ignore empty phantom drafts without score
                    if ($attScore === null && empty($att['is_passed']) && empty($att['is_failed'])) {
                        continue;
                    }
                    $attResult = $att['result'] ?? ($att['is_passed'] ? 'Passed' : ($att['is_failed'] ? 'Failed' : 'Incomplete'));
                    $attPassed = !empty($att['is_passed']) ? 1 : 0;
                    $attTimeStr = $att['total_time'] ?? '00:00:00';
                    $attTimeSec = $timeToSeconds($attTimeStr);
                    $attDate = !empty($att['timemodified']) ? date('Y-m-d H:i:s', (int)$att['timemodified']) : date('Y-m-d H:i:s');

                    $attStmt = $conn->prepare("
                        INSERT INTO onboard_assessment_attempts
                          (user_id, module_id, attempt_number, score, result, is_passed, total_time, total_time_seconds, attempted_at)
                        VALUES
                          (?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                          score = VALUES(score),
                          result = VALUES(result),
                          is_passed = VALUES(is_passed),
                          total_time = VALUES(total_time),
                          total_time_seconds = VALUES(total_time_seconds),
                          attempted_at = COALESCE(VALUES(attempted_at), onboard_assessment_attempts.attempted_at)
                    ");
                    if ($attStmt) {
                        $attStmt->bind_param(
                            "iiidsisis",
                            $userId,
                            $moduleId,
                            $attNum,
                            $attScore,
                            $attResult,
                            $attPassed,
                            $attTimeStr,
                            $attTimeSec,
                            $attDate
                        );
                        $attStmt->execute();
                        $attStmt->close();
                    }
                }
            }

            // 4. Record study session checkpoint in onboard_module_sessions if time spent > 0
            if ($totalTimeSec > 0 || $progressPercent > 0) {
                $tblCheck = $conn->query("SHOW TABLES LIKE 'onboard_module_sessions'");
                if ($tblCheck && $tblCheck->num_rows > 0) {
                    $chkSess = $conn->prepare("
                        SELECT id FROM onboard_module_sessions 
                        WHERE user_id = ? AND module_id = ? AND recorded_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
                        LIMIT 1
                    ");
                    if ($chkSess) {
                        $chkSess->bind_param("ii", $userId, $moduleId);
                        $chkSess->execute();
                        $recentSess = $chkSess->get_result()->fetch_assoc();
                        $chkSess->close();

                        if (!$recentSess) {
                            $insSess = $conn->prepare("
                                INSERT INTO onboard_module_sessions
                                  (user_id, module_id, session_time, session_seconds, progress_percent, score, status, suspend_data, recorded_at)
                                VALUES
                                  (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                            ");
                            if ($insSess) {
                                $insSess->bind_param(
                                    "iisiidss",
                                    $userId,
                                    $moduleId,
                                    $totalTimeStr,
                                    $totalTimeSec,
                                    $progressPercent,
                                    $score,
                                    $status,
                                    $suspendData
                                );
                                $insSess->execute();
                                $insSess->close();
                            }
                        }
                    }
                }
            }
        }
    }
}

/**
 * Computes exact live LMS progress summary for an employee, matching the detailed report 100%.
 * Ensures /onboard/admin/employees and /admin/employees/:uid/report show the exact same progress.
 *
 * @param mysqli $conn Active database connection
 * @param int $moodleUserId Moodle User ID
 * @param int $hexaUid HexaOne UID
 * @return array ['completed' => int, 'total' => int, 'percent' => int, 'status' => string]
 */
function getLiveEmployeeAuditSummary($conn, $moodleUserId, $hexaUid = 0) {
    if (!$conn || !$moodleUserId) {
        return ['completed' => 0, 'total' => 4, 'percent' => 0, 'status' => 'Not Started'];
    }

    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }

    $cacheKey = 'live_audit_summary_' . $moodleUserId;
    if (!empty($_SESSION[$cacheKey]) && is_array($_SESSION[$cacheKey]) && (time() - ($_SESSION[$cacheKey]['ts'] ?? 0)) < 30) {
        return $_SESSION[$cacheKey]['data'];
    }

    $coursesResponse = callMoodleWs('core_enrol_get_users_courses', [
        'userid' => $moodleUserId
    ], $conn);

    if (!is_array($coursesResponse) || !empty($coursesResponse['error'])) {
        // Fallback strictly to onboard_module_progress in DB (no fake sessions or attempt fallbacks)
        $dbCompleted = 0;
        $dbTotal = 4;
        $lookupUid = $hexaUid > 0 ? $hexaUid : (int)$moodleUserId;
        $stmt = $conn->prepare("
            SELECT COUNT(DISTINCT p.module_id) AS cnt
            FROM onboard_module_progress p
            JOIN onboard_modules m ON m.id = p.module_id AND m.is_active = 1 AND m.moodle_course_id IS NOT NULL
            WHERE p.user_id = ?
              AND (p.is_completed = 1 OR p.status = 'completed')
              AND p.status NOT IN ('incomplete', 'in_progress', 'suspend', 'browsed')
        ");
        if ($stmt) {
            $stmt->bind_param("i", $lookupUid);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $dbCompleted = intval($row['cnt'] ?? 0);
            $stmt->close();
        }
        $pct = $dbTotal > 0 ? round(($dbCompleted / $dbTotal) * 100) : 0;
        $statusStr = $pct >= 100 ? 'Completed' : ($pct > 0 ? 'In Progress' : 'Not Started');
        return ['completed' => $dbCompleted, 'total' => $dbTotal, 'percent' => $pct, 'status' => $statusStr];
    }

    // Fetch active module IDs configured in onboard_modules
    $activeModMap = [];
    $modRes = $conn->query("SELECT moodle_course_id FROM onboard_modules WHERE is_active = 1 AND moodle_course_id IS NOT NULL AND moodle_course_id > 0");
    if ($modRes) {
        while ($mr = $modRes->fetch_assoc()) {
            $activeModMap[(int)$mr['moodle_course_id']] = true;
        }
    }

    // Filter Category 1 courses matching active onboard_modules
    $categoryCourses = array_values(array_filter($coursesResponse, function($c) use ($activeModMap) {
        $cat = (int)($c['category'] ?? $c['categoryid'] ?? 0);
        if ($cat !== 1) return false;
        $cId = (int)($c['id'] ?? 0);
        if (!empty($activeModMap) && empty($activeModMap[$cId])) {
            return false;
        }
        return true;
    }));

    $completedCount = 0;
    $totalCount = 0;
    $hasInProgress = false;

    foreach ($categoryCourses as $course) {
        $cId = (int)$course['id'];
        $contents = callMoodleWs('core_course_get_contents', ['courseid' => $cId], $conn);
        $activeScormIds = [];
        if (is_array($contents)) {
            foreach ($contents as $sec) {
                foreach ($sec['modules'] ?? [] as $m) {
                    if (strtolower($m['modname'] ?? '') === 'scorm' && !empty($m['instance'])) {
                        $activeScormIds[] = (int)$m['instance'];
                    }
                }
            }
        }

        $scormsResult = callMoodleWs('mod_scorm_get_scorms_by_courses', ['courseids' => [$cId]], $conn);
        if (isset($scormsResult['scorms']) && is_array($scormsResult['scorms'])) {
            $scormsToProcess = $scormsResult['scorms'];
            if (!empty($activeScormIds)) {
                $scormsToProcess = array_values(array_filter($scormsResult['scorms'], function($sc) use ($activeScormIds) {
                    return in_array((int)$sc['id'], $activeScormIds, true);
                }));
            }

            foreach ($scormsToProcess as $scormItem) {
                $totalCount++;
                $scormId = (int)$scormItem['id'];
                $targetScoId = $scormId;
                $masteryScore = null;

                $scoesResult = callMoodleWs('mod_scorm_get_scorm_scoes', ['scormid' => $scormId], $conn);
                if (isset($scoesResult['scoes']) && is_array($scoesResult['scoes'])) {
                    foreach ($scoesResult['scoes'] as $sco) {
                        if (!empty($sco['extradata']) && is_array($sco['extradata'])) {
                            foreach ($sco['extradata'] as $ex) {
                                if (($ex['element'] ?? '') === 'masteryscore' && trim((string)$ex['value']) !== '') {
                                    $masteryScore = (int)$ex['value'];
                                }
                            }
                        }
                        if (($sco['scormtype'] ?? '') === 'sco' || !empty($sco['launch'])) {
                            $targetScoId = (int)$sco['id'];
                            break;
                        }
                    }
                }

                $scormName = $scormItem['name'] ?? '';
                $isAssessment = (stripos($scormName, 'assess') !== false) || (stripos($scormName, 'quiz') !== false);
                if ($isAssessment && $masteryScore === null) {
                    $masteryScore = 80;
                }

                $attemptNum = 1;
                $attemptRes = callMoodleWs('mod_scorm_get_scorm_attempt_count', ['scormid' => $scormId, 'userid' => $moodleUserId], $conn);
                if (isset($attemptRes['attemptscount']) && (int)$attemptRes['attemptscount'] > 0) {
                    $attemptNum = (int)$attemptRes['attemptscount'];
                }

                $tracksResult = callMoodleWs('mod_scorm_get_scorm_sco_tracks', [
                    'scoid' => $targetScoId,
                    'userid' => $moodleUserId,
                    'attempt' => $attemptNum
                ], $conn);

                $tracks = $tracksResult['data']['tracks'] ?? [];
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
                }

                $isDone = false;
                if ($isAssessment) {
                    $isFailed = ($status === 'failed') || ($masteryScore !== null && $rawScore !== null && $rawScore < $masteryScore && $status !== 'not_started');
                    if (!$isFailed && ($rawScore !== null && $rawScore >= $masteryScore)) {
                        $isDone = true;
                    } elseif (!$isFailed && in_array($status, ['passed'], true)) {
                        $isDone = true;
                    }
                } else {
                    if (in_array($status, ['completed', 'passed'], true) && !in_array($status, ['incomplete', 'browsed', 'suspend', 'in_progress'], true)) {
                        $isDone = true;
                    }
                }

                if ($isDone) {
                    $completedCount++;
                } elseif (in_array($status, ['incomplete', 'in_progress', 'suspend', 'browsed'], true) || !empty($trackMap['cmi.core.total_time'])) {
                    $hasInProgress = true;
                }
            }
        }
    }

    $expectedTotal = !empty($activeModMap) ? count($activeModMap) : 4;
    if ($totalCount === 0 || $totalCount > $expectedTotal) {
        $totalCount = $expectedTotal;
    }
    if ($completedCount > $totalCount) $completedCount = $totalCount;
    $overallPercent = $totalCount > 0 ? min(100, (int)round(($completedCount / $totalCount) * 100)) : 0;
    $statusStr = $overallPercent >= 100 ? 'Completed' : ($overallPercent > 0 || $hasInProgress ? 'In Progress' : 'Not Started');

    $resData = [
        'completed' => $completedCount,
        'total' => $totalCount,
        'percent' => $overallPercent,
        'status' => $statusStr
    ];

    $_SESSION[$cacheKey] = [
        'ts' => time(),
        'data' => $resData
    ];

    return $resData;
}

