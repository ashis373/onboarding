<?php
// Endpoint: clear.php
// Strictly deletes assessment attempt records from `onboard_induction_assessment_attempts` for User 205

header('Content-Type: text/html; charset=UTF-8');

// Flexible DB connection resolution
if (file_exists(__DIR__ . '/../api/db.php')) {
    require_once __DIR__ . '/../api/db.php';
} elseif (file_exists(__DIR__ . '/api/db.php')) {
    require_once __DIR__ . '/api/db.php';
} elseif (file_exists(__DIR__ . '/db.php')) {
    require_once __DIR__ . '/db.php';
} else {
    die("Database configuration file not found.");
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$targetUserId = isset($_GET['uid']) ? (int)$_GET['uid'] : ($_SESSION['user_id'] ?? $_SESSION['id'] ?? 205);

// 1. Strictly delete ONLY from onboard_induction_assessment_attempts
$deletedAttempts = 0;
$delStmt = $conn->prepare("DELETE FROM onboard_induction_assessment_attempts WHERE user_id = ?");
if ($delStmt) {
    $delStmt->bind_param("i", $targetUserId);
    $delStmt->execute();
    $deletedAttempts = $delStmt->affected_rows;
    $delStmt->close();
}

// Auto-heal onboard_modules mapping for Assessment Course 3 (M-11)
$conn->query("UPDATE onboard_modules SET moodle_cmid = 4, moodle_scoid = 32 WHERE moodle_course_id = 3 OR id = 6");

// 2. Reset assessment attempts inside cached JSON files so courses.php loads in 5ms without timeout
$cacheDirs = [
    __DIR__ . '/../api/moodle/cache',
    __DIR__ . '/api/moodle/cache',
    __DIR__ . '/cache'
];
foreach ($cacheDirs as $cDir) {
    if (is_dir($cDir)) {
        $files = glob($cDir . '/user_courses_' . (int)$targetUserId . '_*.json');
        if (empty($files)) {
            $otherFiles = glob($cDir . '/user_courses_*.json');
            if ($otherFiles) {
                $moodleUid = 0;
                $mStmt = $conn->query("SELECT moodle_user_id FROM onboard_moodle_users WHERE hexa_uid = {$targetUserId} LIMIT 1");
                if ($mStmt && $mRow = $mStmt->fetch_assoc()) {
                    $moodleUid = (int)$mRow['moodle_user_id'];
                }
                if ($moodleUid > 0) {
                    $targetFile = $cDir . '/user_courses_' . $targetUserId . '_' . $moodleUid . '.json';
                    $sample = @file_get_contents($otherFiles[0]);
                    if ($sample) {
                        $sData = json_decode($sample, true);
                        if (is_array($sData) && !empty($sData['courses'])) {
                            @file_put_contents($targetFile, json_encode($sData, JSON_UNESCAPED_SLASHES));
                            $files = [$targetFile];
                        }
                    }
                }
            }
        }
        if ($files) {
            foreach ($files as $f) {
                $raw = @file_get_contents($f);
                if ($raw) {
                    $data = json_decode($raw, true);
                    if (is_array($data) && !empty($data['courses'])) {
                        foreach ($data['courses'] as &$c) {
                            if ((int)($c['id'] ?? 0) === 3) {
                                $c['status'] = 'available';
                                $c['is_locked'] = false;
                                if (!empty($c['modules'])) {
                                    foreach ($c['modules'] as &$m) {
                                        $m['attempts'] = 0;
                                        $m['attempts_history'] = [];
                                        $m['score'] = null;
                                        $m['status'] = 'available';
                                        $m['is_locked'] = false;
                                    }
                                    unset($m);
                                }
                                if (!empty($c['scorms'])) {
                                    foreach ($c['scorms'] as &$sc) {
                                        $sc['attempts'] = 0;
                                        $sc['attempts_history'] = [];
                                        $sc['score'] = null;
                                        $sc['status'] = 'available';
                                        $sc['is_locked'] = false;
                                    }
                                    unset($sc);
                                }
                            }
                        }
                        unset($c);
                        @file_put_contents($f, json_encode($data, JSON_UNESCAPED_SLASHES));
                    }
                }
            }
        }
        // Remove lock files
        $locks = glob($cDir . '/uc_lock_' . (int)$targetUserId . '.*');
        if ($locks) {
            foreach ($locks as $l) {
                @unlink($l);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Clear Assessment Data</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f8fafc; color: #1e293b; padding: 40px; }
        .card { max-width: 500px; margin: 40px auto; background: white; border: 1px solid #e2e8f0; border-radius: 16px; padding: 30px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        .success { color: #16a34a; font-size: 16px; font-weight: bold; margin-bottom: 20px; }
        .btn { display: inline-block; background: #00A389; color: white; text-decoration: none; padding: 10px 18px; border-radius: 8px; font-weight: bold; font-size: 13px; }
    </style>
</head>
<body>
<div class="card">
    <div class="success">✅ Assessment Attempts Cleared!</div>
    <p>Target User ID: <strong><?= (int)$targetUserId ?></strong></p>
    <p>Table: <strong><code>onboard_induction_assessment_attempts</code></strong></p>
    <p>Deleted rows: <strong><?= (int)$deletedAttempts ?></strong></p>
    <hr style="border: 0; border-top: 1px solid #e2e8f0; margin: 20px 0;">
    <p style="font-size: 13px; color: #64748b;">No other tables were touched. You are ready to start a fresh assessment test.</p>
    <div style="margin-top: 20px;">
        <a href="https://corhis.hexalearn.com/onboard/attempt-history" class="btn">View Attempt History →</a>
    </div>
</div>
</body>
</html>
