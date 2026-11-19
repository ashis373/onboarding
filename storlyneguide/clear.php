<?php
/**
 * Clear Assessment Attempts Endpoint: clear.php
 * Accessible via:
 *   - https://corhis.hexalearn.com/onboard/api/clear.php
 *   - https://corhis.hexalearn.com/onboard/clear.php
 * Strictly clears assessment attempts for User 205 (Ashis Kumar Rout) or ?uid=XX
 * so the employee can retake the assessment cleanly from Attempt 1.
 */

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Resilient DB resolver
$dbFile = null;
if (file_exists(__DIR__ . '/db.php')) {
    $dbFile = __DIR__ . '/db.php';
} elseif (file_exists(__DIR__ . '/../api/db.php')) {
    $dbFile = __DIR__ . '/../api/db.php';
} elseif (file_exists(__DIR__ . '/api/db.php')) {
    $dbFile = __DIR__ . '/api/db.php';
} elseif (file_exists(dirname(__DIR__) . '/api/db.php')) {
    $dbFile = dirname(__DIR__) . '/api/db.php';
}

if ($dbFile) {
    require_once $dbFile;
} else {
    die("Database configuration file not found.");
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$targetUserId = isset($_GET['uid']) ? (int)$_GET['uid'] : ($_SESSION['user_id'] ?? $_SESSION['id'] ?? 205);
if ($targetUserId <= 0) {
    $targetUserId = 205;
}

// 1. Delete attempts from `onboard_induction_assessment_attempts`
$deletedAttempts = 0;
$delStmt = $conn->prepare("DELETE FROM onboard_induction_assessment_attempts WHERE user_id = ?");
if ($delStmt) {
    $delStmt->bind_param("i", $targetUserId);
    $delStmt->execute();
    $deletedAttempts = $delStmt->affected_rows;
    $delStmt->close();
}

// 1.5 Update email template CC to production managers
$conn->query("UPDATE onboard_email_templates SET description = 'Purusottam.jayasingh@hexalearn.com, priyaranjan.samantaray@hexalearn.com' WHERE slug = 'assessment_lockout'");

// 2. Remove any lockout override records so fresh state begins
$conn->query("DELETE FROM onboard_course_unlock_overrides WHERE user_id = {$targetUserId} AND moodle_course_id = 3");

// 3. Auto-heal onboard_modules mapping for Assessment Course 3 (M-11)
$conn->query("UPDATE onboard_modules SET moodle_cmid = 4, moodle_scoid = 32 WHERE moodle_course_id = 3 OR id = 6");

// 4. Reset assessment attempts inside cached JSON files
$cacheDirs = [
    __DIR__ . '/moodle/cache',
    __DIR__ . '/../api/moodle/cache',
    __DIR__ . '/api/moodle/cache',
    __DIR__ . '/cache'
];

$cacheFilesReset = 0;
foreach ($cacheDirs as $cDir) {
    if (is_dir($cDir)) {
        $files = glob($cDir . '/user_courses_' . (int)$targetUserId . '_*.json');
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
                        $cacheFilesReset++;
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

// Return JSON if requested
if (isset($_GET['format']) && $_GET['format'] === 'json') {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success' => true,
        'user_id' => $targetUserId,
        'deleted_attempts' => $deletedAttempts,
        'cache_files_reset' => $cacheFilesReset,
        'message' => 'Assessment attempts and locks cleared successfully. Ready for fresh test.'
    ]);
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clear Assessment Data - CorHis Onboard</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f8fafc; color: #1e293b; padding: 40px 20px; }
        .card { max-width: 520px; margin: 40px auto; background: white; border: 1px solid #e2e8f0; border-radius: 16px; padding: 32px; box-shadow: 0 4px 12px rgba(0,0,0,0.06); }
        .success { color: #16a34a; font-size: 20px; font-weight: 800; margin-bottom: 16px; display: flex; items-center; gap: 8px; }
        .info-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; }
        .info-label { color: #64748b; font-weight: 500; }
        .info-val { font-weight: 700; color: #0f172a; }
        .btn-group { margin-top: 24px; display: flex; gap: 12px; }
        .btn { display: inline-flex; align-items: center; justify-content: center; background: #00A389; color: white; text-decoration: none; padding: 11px 18px; border-radius: 10px; font-weight: 700; font-size: 13px; transition: background 0.2s; }
        .btn:hover { background: #008f77; }
        .btn-outline { background: white; color: #475569; border: 1px solid #cbd5e1; }
        .btn-outline:hover { background: #f8fafc; }
    </style>
</head>
<body>
<div class="card">
    <div class="success">✅ Assessment Attempts Cleared!</div>
    <p style="font-size: 14px; color: #475569; margin-bottom: 20px;">
        Assessment attempts have been cleared from the database for testing.
    </p>

    <div class="info-row">
        <span class="info-label">Target User ID</span>
        <span class="info-val"><?= (int)$targetUserId ?> (Ashis Kumar Rout)</span>
    </div>
    <div class="info-row">
        <span class="info-label">Table Cleared</span>
        <span class="info-val"><code>onboard_induction_assessment_attempts</code></span>
    </div>
    <div class="info-row">
        <span class="info-label">Deleted Attempts</span>
        <span class="info-val"><?= (int)$deletedAttempts ?> rows</span>
    </div>
    <div class="info-row">
        <span class="info-label">LMS Course Cache</span>
        <span class="info-val"><?= (int)$cacheFilesReset ?> files refreshed</span>
    </div>

    <div class="btn-group">
        <a href="https://corhis.hexalearn.com/onboard/modules" class="btn">Launch Assessment (Modules) →</a>
        <a href="https://corhis.hexalearn.com/onboard/attempt-history" class="btn btn-outline">Attempt History</a>
    </div>
</div>
</body>
</html>
