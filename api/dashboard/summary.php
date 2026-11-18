<?php
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
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

// Check if user is admin (user_role = 1 OR specific UIDs)
$isAdmin = false;
if (in_array((string)$user_id, ['26', '64', '205'], true)) {
    $isAdmin = true;
} else {
    $roleCheck = $conn->prepare("SELECT user_role FROM user WHERE uid = ?");
    $roleCheck->bind_param('i', $user_id);
    if ($roleCheck->execute()) {
        $roleRes = $roleCheck->get_result()->fetch_assoc();
        if (($roleRes['user_role'] ?? 0) == 1) $isAdmin = true;
    }
    $roleCheck->close();
}

// Release session lock early to prevent blocking concurrent API requests
session_write_close();

// 1. Get Modules Progress
$modules = [];
$roadmap = [];
$completed_modules_count = 0;
$total_modules_count = 0;

// Detect available columns in onboard_modules
$moduleColumns = [];
$colCheck = $conn->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'onboard_modules'");
if ($colCheck) {
    while ($c = $colCheck->fetch_assoc()) $moduleColumns[] = strtolower($c['COLUMN_NAME']);
}
$hasRoadmapDay   = in_array('roadmap_day', $moduleColumns);
$hasSortOrder    = in_array('sort_order', $moduleColumns);

$roadmapSelect = $hasRoadmapDay ? "COALESCE(m.roadmap_day, 1) AS roadmap_day," : "1 AS roadmap_day,";
$orderBy       = "ORDER BY " . ($hasRoadmapDay ? "m.roadmap_day ASC, " : "") . ($hasSortOrder ? "m.sort_order ASC, " : "") . "m.id ASC";

$sqlModules = "SELECT m.id, m.title, m.type, m.duration_minutes, m.video_url, m.file_url, m.is_active,
                      $roadmapSelect
                      CASE 
                        WHEN p.status IN ('in_progress', 'incomplete', 'suspend', 'browsed') THEN 0
                        WHEN p.is_completed = 1 OR p.status IN ('completed', 'passed') THEN 1 
                        ELSE 0 
                      END AS is_completed,
                      CASE 
                        WHEN p.status IN ('in_progress', 'incomplete', 'suspend', 'browsed') THEN 'in_progress'
                        WHEN p.is_completed = 1 OR p.status IN ('completed', 'passed') THEN 'completed' 
                        ELSE COALESCE(p.status, 'not_started') 
                      END AS status,
                      CASE 
                        WHEN p.status IN ('in_progress', 'incomplete', 'suspend', 'browsed') THEN COALESCE(p.progress_percent, 0)
                        WHEN p.is_completed = 1 OR p.status IN ('completed', 'passed') THEN 100 
                        ELSE COALESCE(p.progress_percent, 0) 
                      END AS progress_percent,
                      p.score AS score,
                      COALESCE(p.total_time, '') AS total_time
               FROM onboard_modules m
               LEFT JOIN onboard_module_progress p ON p.module_id = m.id AND p.user_id = ?
               WHERE m.is_active = 1
               $orderBy";

// Roadmap phase labels
$roadmapPhases = [
    1  => ['day' => 1,  'label' => 'Day 1',   'description' => 'Getting started — orientation and culture'],
    30 => ['day' => 30, 'label' => 'Day 30',  'description' => 'Building confidence — tools and processes'],
    60 => ['day' => 60, 'label' => 'Day 60',  'description' => 'Finding your rhythm — projects and relationships'],
    90 => ['day' => 90, 'label' => 'Day 90',  'description' => 'Full speed — contributing independently'],
];

if ($stmt = $conn->prepare($sqlModules)) {
    $stmt->bind_param('i', $user_id);
    if ($stmt->execute()) {
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $total_modules_count++;
            if ($row['is_completed']) $completed_modules_count++;
            
            $module = [
                'id'               => (int)$row['id'],
                'title'            => $row['title'],
                'type'             => $row['type'],
                'completed'        => (bool)$row['is_completed'],
                'status'           => $row['status'] ?? 'not_started',
                'progress_percent' => (int)($row['progress_percent'] ?? 0),
                'score'            => $row['score'] !== null ? (float)$row['score'] : null,
                'total_time'       => $row['total_time'] ?? '',
                'is_active'        => (bool)($row['is_active'] ?? 1),
                'duration'         => $row['duration_minutes'] ? $row['duration_minutes'] . ' min' : '',
                'video_url'        => $row['video_url'],
                'file_url'         => $row['file_url'],
                'roadmap_day'      => (int)$row['roadmap_day'],
            ];
            $modules[] = $module;

            // Group into roadmap phases
            $day = (int)$row['roadmap_day'];
            if ($day <= 1)  $phaseKey = 1;
            elseif ($day <= 30) $phaseKey = 30;
            elseif ($day <= 60) $phaseKey = 60;
            else             $phaseKey = 90;

            if (!isset($roadmap[$phaseKey])) {
                $roadmap[$phaseKey] = $roadmapPhases[$phaseKey] ?? ['day' => $phaseKey, 'label' => "Day $phaseKey", 'description' => ''];
                $roadmap[$phaseKey]['modules'] = [];
                $roadmap[$phaseKey]['total']     = 0;
                $roadmap[$phaseKey]['completed'] = 0;
            }
            $roadmap[$phaseKey]['modules'][] = $module;
            $roadmap[$phaseKey]['total']++;
            if ($module['completed']) $roadmap[$phaseKey]['completed']++;
        }
    }
    $stmt->close();
}

// Sort roadmap by day and convert to indexed array
ksort($roadmap);
$roadmapArr = array_values(array_map(function($phase) {
    $phase['percent'] = $phase['total'] > 0 ? round(($phase['completed'] / $phase['total']) * 100) : 0;
    return $phase;
}, $roadmap));

// 2. Get Assessments Progress (only if table exists)
$assessments = [];
$assessments_completed_count = 0;

$hasAssessmentsTbl = false;
$asCheck = $conn->query("SHOW TABLES LIKE 'onboard_assessments'");
if ($asCheck && $asCheck->num_rows > 0) $hasAssessmentsTbl = true;

if ($hasAssessmentsTbl) {
    $sqlAssessments = "SELECT a.id, a.title, a.passing_score,
                              (SELECT MAX(score) FROM `onboard_assessment_attempts` 
                               WHERE assessment_id = a.id AND user_id = ? AND submitted_at IS NOT NULL) AS best_score,
                              (SELECT COUNT(*) FROM `onboard_assessment_attempts` 
                               WHERE assessment_id = a.id AND user_id = ? AND submitted_at IS NOT NULL) AS attempts
                       FROM `onboard_assessments` a
                       WHERE a.is_active = 1
                       ORDER BY a.id ASC";

    if ($stmt = $conn->prepare($sqlAssessments)) {
        $stmt->bind_param('ii', $user_id, $user_id);
        if ($stmt->execute()) {
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $is_passed = isset($row['best_score']) && $row['best_score'] >= $row['passing_score'];
                if ($is_passed) $assessments_completed_count++;
                
                $assessments[] = [
                    'id' => (int)$row['id'],
                    'title' => $row['title'],
                    'completed' => $is_passed,
                    'score' => $row['best_score'] !== null ? (int)$row['best_score'] : null,
                    'maxScore' => 100, // Assuming percentage base
                    'attempts' => (int)$row['attempts'],
                    'maxAttempts' => 3 // Placeholder or could be added to schema
                ];
            }
        }
        $stmt->close();
    }
}

// 3. Admin stats (if admin)
$adminStats = null;
if ($isAdmin) {
    $totalEmployees = 0;
    $enrolledCount = 0;
    $completedCount = 0;
    $avgComp = 0;
    
    // Count total active employees (read-only from user table)
    if ($res = $conn->query("SELECT COUNT(*) AS cnt FROM `user` WHERE IFNULL(`status`, 0) = 0 OR `status` = 'online'")) {
        $totalEmployees = (int)($res->fetch_assoc())['cnt'];
    }

    // Count enrolled onboarding trainees from onboard_moodle_users
    $tblMoodleExist = false;
    $mCheck = $conn->query("SHOW TABLES LIKE 'onboard_moodle_users'");
    if ($mCheck && $mCheck->num_rows > 0) $tblMoodleExist = true;

    if ($tblMoodleExist) {
        $eSql = "SELECT COUNT(DISTINCT m.hexa_uid) AS cnt 
                 FROM onboard_moodle_users m 
                 JOIN `user` u ON u.uid = m.hexa_uid 
                 WHERE IFNULL(u.status, 0) = 0";
        if ($eRes = $conn->query($eSql)) {
            $enrolledCount = (int)($eRes->fetch_assoc())['cnt'];
        }
    }
    
    // Check active modules count
    $activeModulesCount = 0;
    $mCheck2 = $conn->query("SHOW TABLES LIKE 'onboard_modules'");
    if ($mCheck2 && $mCheck2->num_rows > 0) {
        $mRes = $conn->query("
            SELECT COUNT(*) AS cnt 
            FROM `onboard_modules` 
            WHERE `is_active` = 1 
              AND `moodle_course_id` IS NOT NULL 
              AND `moodle_course_id` > 0
              AND (type != 'assessment' OR type IS NULL)
              AND LOWER(title) NOT LIKE '%assessment%'
              AND LOWER(title) NOT LIKE '%quiz%'
        ");
        if ($mRes) {
            $activeModulesCount = (int)($mRes->fetch_assoc())['cnt'];
        }
    }

    if ($enrolledCount > 0 && $activeModulesCount > 0) {
        // Authoritative count of completed enrolled trainees (completed all active modules)
        // Correctly matches against both internal module_id and moodle_cmid
        $cRes = $conn->query("
            SELECT p.user_id, COUNT(DISTINCT m.id) AS done_cnt
            FROM onboard_module_progress p
            JOIN onboard_moodle_users m_usr ON m_usr.hexa_uid = p.user_id
            JOIN `user` u ON u.uid = m_usr.hexa_uid AND IFNULL(u.status, 0) = 0
            INNER JOIN onboard_modules m ON (m.id = p.module_id OR (m.moodle_cmid > 0 AND m.moodle_cmid = p.module_id))
            WHERE (p.is_completed = 1 OR p.status = 'completed')
              AND p.status NOT IN ('incomplete', 'in_progress', 'suspend', 'browsed')
              AND m.is_active = 1
              AND m.moodle_course_id > 0
              AND (m.type != 'assessment' OR m.type IS NULL)
              AND LOWER(m.title) NOT LIKE '%assessment%'
              AND LOWER(m.title) NOT LIKE '%quiz%'
            GROUP BY p.user_id
            HAVING done_cnt >= $activeModulesCount
        ");
        if ($cRes) {
            $completedCount = $cRes->num_rows;
        }

        // Average completion across all enrolled trainees
        $avgSql = "
            SELECT AVG(t.pct) as avg_pct FROM (
                SELECT m_usr.hexa_uid,
                       LEAST(100.0, COUNT(DISTINCT m.id) * 100.0 / $activeModulesCount) as pct
                FROM onboard_moodle_users m_usr
                JOIN `user` u ON u.uid = m_usr.hexa_uid AND IFNULL(u.status, 0) = 0
                LEFT JOIN onboard_module_progress p ON p.user_id = m_usr.hexa_uid
                    AND (p.is_completed = 1 OR p.status = 'completed')
                    AND p.status NOT IN ('incomplete', 'in_progress', 'suspend', 'browsed')
                LEFT JOIN onboard_modules m ON (m.id = p.module_id OR (m.moodle_cmid > 0 AND m.moodle_cmid = p.module_id))
                    AND m.is_active = 1
                    AND m.moodle_course_id > 0
                    AND (m.type != 'assessment' OR m.type IS NULL)
                    AND LOWER(m.title) NOT LIKE '%assessment%'
                    AND LOWER(m.title) NOT LIKE '%quiz%'
                GROUP BY m_usr.hexa_uid
            ) as t
        ";
        if ($aRes = $conn->query($avgSql)) {
            $avgComp = round((float)($aRes->fetch_assoc()['avg_pct'] ?? 0));
        }
    }

    $activeOnboarding = max(0, $enrolledCount - $completedCount);
    $completionRate = $totalEmployees > 0 ? round(($completedCount * 100.0) / $totalEmployees) : 0;
    $cohortCompletionRate = $enrolledCount > 0 ? round(($completedCount * 100.0) / $enrolledCount) : 0;

    $adminStats = [
        'totalEmployees' => $totalEmployees,
        'activeCourses' => $activeModulesCount,
        'enrolledCount' => $enrolledCount,
        'notEnrolledCount' => max(0, $totalEmployees - $enrolledCount),
        'activeOnboarding' => $enrolledCount,
        'inProgressCount' => $activeOnboarding,
        'completedOnboarding' => $completedCount, 
        'averageCompletion' => $avgComp,
        'completionRate' => $completionRate,
        'cohortCompletionRate' => $cohortCompletionRate,
        'debug' => [
            'user_id' => $user_id,
            'sql_error' => $conn->error,
            'tables_exist' => [
                'onboard_modules' => $activeModulesCount > 0,
                'onboard_moodle_users' => $tblMoodleExist,
                'user' => true
            ]
        ]
    ];
}

// 4. Get Buddy Info (only if table exists)
$buddy = null;
$hasBuddyTbl = false;
$btCheck = $conn->query("SHOW TABLES LIKE 'onboard_buddies'");
if ($btCheck && $btCheck->num_rows > 0) $hasBuddyTbl = true;

if ($hasBuddyTbl) {
    $sqlBuddy = "SELECT b.buddy_id, u.firstname, u.lastname, u.ofc_mail, u.profile_img
                 FROM onboard_buddies b
                 JOIN user u ON u.uid = b.buddy_id
                 WHERE b.employee_id = ?";
    if ($stmt = $conn->prepare($sqlBuddy)) {
        $stmt->bind_param('i', $user_id);
        if ($stmt->execute()) {
            $bRes = $stmt->get_result();
            if ($row = $bRes->fetch_assoc()) {
                $buddy = [
                    'name' => trim($row['firstname'] . ' ' . $row['lastname']),
                    'email' => $row['ofc_mail'],
                    'photo' => $row['profile_img'] ? (((isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/internal/corhis') !== false) ? '/internal/corhis/dist/img/user/' : '/dist/img/user/') . implode('/', array_map('rawurlencode', explode('/', trim($row['profile_img']))))) : null
                ];
            }
        }
        $stmt->close();
    }
}

$overallProgress = $total_modules_count > 0 ? round(($completed_modules_count / $total_modules_count) * 100) : 0;

echo json_encode([
    'success' => true,
    'summary' => [
        'modules'     => $modules,
        'assessments' => $assessments,
        'buddy'       => $buddy,
        'roadmap'     => $roadmapArr,
        'stats' => [
            'completed' => $completed_modules_count,
            'total' => $total_modules_count,
            'overallProgress' => $overallProgress,
            'assessmentsCompleted' => $assessments_completed_count,
            'assessmentsTotal' => count($assessments),
            'admin' => $adminStats
        ],
        'nextMilestone' => $next_milestone ?? null
    ]
]);
$conn->close();
?>
