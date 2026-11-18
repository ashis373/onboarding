<?php
/**
 * HexaLearn Onboarding - Delete Test User Utility
 * 
 * Target User:
 *   - Username: ashisr
 *   - Hexa UID: 205
 *   - Moodle User ID: 7
 *   - Row ID in onboard_moodle_users: 5
 * 
 * Purpose:
 * Removes test records for this user ONLY from `onboard_*` tables:
 *   1. `onboard_moodle_users`
 *   2. `onboard_module_progress`
 *   3. `onboard_module_sessions`
 *   4. `onboard_assessment_attempts`
 * 
 * PRESERVED: `user` table is 100% SAFE & NEVER TOUCHED!
 * 
 * Usage:
 *   Browser: https://hexalearn.com/internal/hex-timesheet/onboard/delete_test_user.php
 *   Or local: http://localhost/onboard/delete_test_user.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/api/db.php';

if (!isset($conn) || $conn->connect_error) {
    die("Database connection failed: " . ($conn->connect_error ?? "Unknown error"));
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$target_username = 'ashisr';
$target_hexa_uid = 205;
$target_moodle_id = 7;

// Fetch target info before deletion
$found_user = null;
$uRes = $conn->query("SELECT * FROM `user` WHERE uid = {$target_hexa_uid} OR username = '{$target_username}' LIMIT 1");
if ($uRes && $uRes->num_rows > 0) {
    $found_user = $uRes->fetch_assoc();
}

$found_moodle_user = null;
$mRes = $conn->query("SELECT * FROM `onboard_moodle_users` WHERE hexa_uid = {$target_hexa_uid} OR moodle_user_id = {$target_moodle_id} OR username = '{$target_username}' LIMIT 1");
if ($mRes && $mRes->num_rows > 0) {
    $found_moodle_user = $mRes->fetch_assoc();
}

$deleted_counts = [];
$errors = [];

// 1. Delete from onboard_moodle_users
$delMoodle = $conn->query("DELETE FROM `onboard_moodle_users` WHERE hexa_uid = {$target_hexa_uid} OR moodle_user_id = {$target_moodle_id} OR username = '{$target_username}'");
if ($delMoodle) {
    $deleted_counts['onboard_moodle_users'] = $conn->affected_rows;
} else {
    $errors[] = "Error deleting from onboard_moodle_users: " . $conn->error;
}

// 2. Delete from onboard_module_progress
$delProg = $conn->query("DELETE FROM `onboard_module_progress` WHERE user_id = {$target_hexa_uid}");
if ($delProg) {
    $deleted_counts['onboard_module_progress'] = $conn->affected_rows;
} else {
    $errors[] = "Error deleting from onboard_module_progress: " . $conn->error;
}

// 3. Delete from onboard_module_sessions
$delSess = $conn->query("DELETE FROM `onboard_module_sessions` WHERE user_id = {$target_hexa_uid}");
if ($delSess) {
    $deleted_counts['onboard_module_sessions'] = $conn->affected_rows;
} else {
    $errors[] = "Error deleting from onboard_module_sessions: " . $conn->error;
}

// 4. Delete from onboard_assessment_attempts
$delAtt = $conn->query("DELETE FROM `onboard_assessment_attempts` WHERE user_id = {$target_hexa_uid}");
if ($delAtt) {
    $deleted_counts['onboard_assessment_attempts'] = $conn->affected_rows;
} else {
    $errors[] = "Error deleting from onboard_assessment_attempts: " . $conn->error;
}

// 5. The `user` table is 100% PRESERVED & SAFE (NEVER DELETED)
// Only `onboard_*` records are removed!

// Clear any session cache
foreach ($_SESSION as $k => $v) {
    if (strpos($k, 'moodle_cached_courses_') === 0 || strpos($k, 'moodle_enrol_checked_') === 0) {
        unset($_SESSION[$k]);
    }
}

// JSON Output
if (isset($_GET['format']) && $_GET['format'] === 'json') {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success' => empty($errors),
        'deleted_user' => [
            'username' => $target_username,
            'hexa_uid' => $target_hexa_uid,
            'moodle_user_id' => $target_moodle_id
        ],
        'deleted_records' => $deleted_counts,
        'errors' => $errors
    ], JSON_PRETTY_PRINT);
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delete Test User - Complete</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Plus Jakarta Sans', -apple-system, sans-serif;
            background: #0f172a;
            color: #f8fafc;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 24px;
        }
        .card {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 20px;
            max-width: 680px;
            width: 100%;
            padding: 36px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }
        .header {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 24px;
            padding-bottom: 20px;
            border-bottom: 1px solid #334155;
        }
        .badge-icon {
            width: 52px;
            height: 52px;
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
        }
        h1 { font-size: 22px; font-weight: 700; color: #fff; }
        p.subtitle { font-size: 14px; color: #94a3b8; margin-top: 4px; }
        .section-title {
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #64748b;
            margin-top: 24px;
            margin-bottom: 12px;
        }
        .target-box {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 20px;
        }
        .target-box p { font-size: 14px; color: #fca5a5; margin-bottom: 4px; }
        .target-box strong { color: #fff; }
        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
        .item {
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 12px;
            padding: 14px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .item .name { font-size: 13px; color: #cbd5e1; font-weight: 500; }
        .item .count-deleted {
            font-size: 12px;
            font-weight: 700;
            color: #ef4444;
            background: rgba(239, 68, 68, 0.15);
            padding: 4px 10px;
            border-radius: 20px;
        }
        .actions {
            margin-top: 32px;
            display: flex;
            gap: 12px;
        }
        .btn {
            flex: 1;
            padding: 14px 20px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            text-align: center;
            transition: all 0.2s ease;
        }
        .btn-primary {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: #fff;
        }
        .btn-primary:hover { background: #1d4ed8; }
        .btn-secondary {
            background: #334155;
            color: #f1f5f9;
        }
        .btn-secondary:hover { background: #475569; }
    </style>
</head>
<body>
    <div class="card">
        <div class="header">
            <div class="badge-icon">🗑️</div>
            <div>
                <h1>Test User Deleted Successfully</h1>
                <p class="subtitle">User <strong><?php echo htmlspecialchars($target_username); ?></strong> and all associated test data have been removed.</p>
            </div>
        </div>

        <div class="target-box">
            <p>Deleted User: <strong><?php echo htmlspecialchars($target_username); ?></strong> (Hexa UID: <strong><?php echo $target_hexa_uid; ?></strong>, Moodle User ID: <strong><?php echo $target_moodle_id; ?></strong>)</p>
            <?php if ($found_user): ?>
                <p>Employee: <strong><?php echo htmlspecialchars(trim(($found_user['firstname'] ?? '') . ' ' . ($found_user['lastname'] ?? ''))); ?></strong></p>
            <?php endif; ?>
        </div>

        <div class="section-title">Records Deleted Across Tables</div>
        <div class="grid">
            <div class="item">
                <span class="name">onboard_moodle_users</span>
                <span class="count-deleted"><?php echo $deleted_counts['onboard_moodle_users'] ?? 0; ?> row(s) deleted</span>
            </div>
            <div class="item">
                <span class="name">onboard_module_progress</span>
                <span class="count-deleted"><?php echo $deleted_counts['onboard_module_progress'] ?? 0; ?> row(s) deleted</span>
            </div>
            <div class="item">
                <span class="name">onboard_module_sessions</span>
                <span class="count-deleted"><?php echo $deleted_counts['onboard_module_sessions'] ?? 0; ?> row(s) deleted</span>
            </div>
            <div class="item">
                <span class="name">onboard_assessment_attempts</span>
                <span class="count-deleted"><?php echo $deleted_counts['onboard_assessment_attempts'] ?? 0; ?> row(s) deleted</span>
            </div>
            <div class="item">
                <span class="name">user table</span>
                <span style="font-size: 12px; font-weight: 700; color: #38bdf8; background: rgba(56, 189, 248, 0.15); padding: 4px 10px; border-radius: 20px;">SAFE (Untouched)</span>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
        <div style="margin-top: 20px; padding: 14px; background: rgba(239,68,68,0.2); border-radius: 10px; color: #fca5a5; font-size: 13px;">
            <?php foreach ($errors as $e): ?>
                <p><?php echo htmlspecialchars($e); ?></p>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="actions">
            <a href="index.php" class="btn btn-primary">Go to Onboard Dashboard</a>
            <a href="p.php" class="btn btn-secondary">Open Database Manager (p.php)</a>
        </div>
    </div>
</body>
</html>
