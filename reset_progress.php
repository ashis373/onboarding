<?php
/**
 * HexaLearn Onboarding - Progress & Session Reset Utility
 * 
 * Purpose:
 * Clears test progress, SCORM sessions, and assessment attempts so employees can test courses fresh from 0%.
 * PRESERVES: `user`, `onboard_modules`, `onboard_moodle_users`, `onboard_settings` (SAFE & UNTOUCHED).
 * 
 * Usage:
 * Open in browser: https://hexalearn.com/internal/hex-timesheet/onboard/reset_progress.php
 * Or local:        http://localhost/onboard/reset_progress.php
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

// 1. Fetch counts BEFORE reset
function getRowCount($conn, $table) {
    $res = $conn->query("SELECT COUNT(*) AS cnt FROM `{$table}`");
    if ($res) {
        $row = $res->fetch_assoc();
        return (int)$row['cnt'];
    }
    return 0;
}

$before_progress = getRowCount($conn, 'onboard_module_progress');
$before_sessions = getRowCount($conn, 'onboard_module_sessions');
$before_attempts = getRowCount($conn, 'onboard_assessment_attempts');

$preserved_users = getRowCount($conn, 'user');
$preserved_modules = getRowCount($conn, 'onboard_modules');
$preserved_moodle_users = getRowCount($conn, 'onboard_moodle_users');
$preserved_settings = getRowCount($conn, 'onboard_settings');

// 2. Perform safe reset of progress tables
$errors = [];
$success_msg = [];

// A. Truncate module progress
if ($conn->query("TRUNCATE TABLE `onboard_module_progress`") === TRUE) {
    $success_msg[] = "Successfully cleared `onboard_module_progress` (was {$before_progress} rows -> now 0 rows).";
} else {
    // Fallback to DELETE if TRUNCATE has foreign key restriction
    if ($conn->query("DELETE FROM `onboard_module_progress`") === TRUE) {
        $success_msg[] = "Successfully deleted all rows from `onboard_module_progress` (was {$before_progress} rows -> now 0 rows).";
    } else {
        $errors[] = "Error clearing `onboard_module_progress`: " . $conn->error;
    }
}

// B. Truncate module sessions
if ($conn->query("TRUNCATE TABLE `onboard_module_sessions`") === TRUE) {
    $success_msg[] = "Successfully cleared `onboard_module_sessions` (was {$before_sessions} rows -> now 0 rows).";
} else {
    if ($conn->query("DELETE FROM `onboard_module_sessions`") === TRUE) {
        $success_msg[] = "Successfully deleted all rows from `onboard_module_sessions` (was {$before_sessions} rows -> now 0 rows).";
    } else {
        $errors[] = "Error clearing `onboard_module_sessions`: " . $conn->error;
    }
}

// C. Truncate assessment attempts
if ($conn->query("TRUNCATE TABLE `onboard_assessment_attempts`") === TRUE) {
    $success_msg[] = "Successfully cleared `onboard_assessment_attempts` (was {$before_attempts} rows -> now 0 rows).";
} else {
    if ($conn->query("DELETE FROM `onboard_assessment_attempts`") === TRUE) {
        $success_msg[] = "Successfully deleted all rows from `onboard_assessment_attempts` (was {$before_attempts} rows -> now 0 rows).";
    } else {
        $errors[] = "Error clearing `onboard_assessment_attempts`: " . $conn->error;
    }
}

// D. Clear cached courses in current PHP session
foreach ($_SESSION as $k => $v) {
    if (strpos($k, 'moodle_cached_courses_') === 0 || strpos($k, 'moodle_enrol_checked_') === 0) {
        unset($_SESSION[$k]);
    }
}

// Check if JSON format requested
if (isset($_GET['format']) && $_GET['format'] === 'json') {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success' => empty($errors),
        'cleared' => [
            'onboard_module_progress' => $before_progress,
            'onboard_module_sessions' => $before_sessions,
            'onboard_assessment_attempts' => $before_attempts
        ],
        'preserved' => [
            'user' => $preserved_users,
            'onboard_modules' => $preserved_modules,
            'onboard_moodle_users' => $preserved_moodle_users,
            'onboard_settings' => $preserved_settings
        ],
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
    <title>HexaLearn - Reset Progress Complete</title>
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
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
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
        .item .status-cleared {
            font-size: 12px;
            font-weight: 700;
            color: #10b981;
            background: rgba(16, 185, 129, 0.15);
            padding: 4px 10px;
            border-radius: 20px;
        }
        .item .status-safe {
            font-size: 12px;
            font-weight: 700;
            color: #38bdf8;
            background: rgba(56, 189, 248, 0.15);
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
            <div class="badge-icon">✅</div>
            <div>
                <h1>Progress Reset Successfully</h1>
                <p class="subtitle">All learning progress and session logs have been reset to 0%.</p>
            </div>
        </div>

        <div class="section-title">Erased / Reset Tables (Ready for Fresh Testing)</div>
        <div class="grid">
            <div class="item">
                <span class="name">onboard_module_progress</span>
                <span class="status-cleared"><?php echo $before_progress; ?> &rarr; 0 rows</span>
            </div>
            <div class="item">
                <span class="name">onboard_module_sessions</span>
                <span class="status-cleared"><?php echo $before_sessions; ?> &rarr; 0 rows</span>
            </div>
            <div class="item">
                <span class="name">onboard_assessment_attempts</span>
                <span class="status-cleared"><?php echo $before_attempts; ?> &rarr; 0 rows</span>
            </div>
        </div>

        <div class="section-title">Preserved Tables (Safe & Untouched)</div>
        <div class="grid">
            <div class="item">
                <span class="name">user</span>
                <span class="status-safe"><?php echo $preserved_users; ?> users safe</span>
            </div>
            <div class="item">
                <span class="name">onboard_modules</span>
                <span class="status-safe"><?php echo $preserved_modules; ?> modules safe</span>
            </div>
            <div class="item">
                <span class="name">onboard_moodle_users</span>
                <span class="status-safe"><?php echo $preserved_moodle_users; ?> links safe</span>
            </div>
            <div class="item">
                <span class="name">onboard_settings</span>
                <span class="status-safe"><?php echo $preserved_settings; ?> settings safe</span>
            </div>
        </div>

        <div class="actions">
            <a href="index.php" class="btn btn-primary">Go to Onboard Dashboard</a>
            <a href="p.php" class="btn btn-secondary">Open Database Manager</a>
        </div>
    </div>
</body>
</html>
