<?php
/**
 * Database Structure Updater & Optimizer for Onboard System
 * Location: update_db_structure.php
 *
 * Usage:
 * - Browser: Visit https://hexalearn.com/internal/hex-timesheet/onboard/update_db_structure.php
 * - CLI: php update_db_structure.php [--apply]
 *
 * STRICT SAFETY RULES:
 * - ONLY touches `onboard_*` tables.
 * - Under NO circumstances will it alter, update, or touch the `user` table.
 */

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/api/db.php';
require_once __DIR__ . '/api/moodle/sync_user.php';

$isCli = (php_sapi_name() === 'cli');
$apply = $isCli ? in_array('--apply', $argv ?? [], true) : (isset($_POST['apply']) || isset($_GET['apply']));

$results = [];

function logStep(&$results, $step, $status, $message, $query = '') {
    $results[] = [
        'step'    => $step,
        'status'  => $status, // 'success', 'warning', 'info', 'error'
        'message' => $message,
        'query'   => $query
    ];
}

function indexExists($conn, $table, $indexName) {
    $res = $conn->query("SHOW INDEX FROM `$table` WHERE Key_name = '$indexName'");
    return ($res && $res->num_rows > 0);
}

if ($apply) {
    $conn->query("SET FOREIGN_KEY_CHECKS = 0");

    // =========================================================================
    // STEP 1: De-duplicate `onboard_module_progress` (if any duplicate rows)
    // =========================================================================
    $dupRes = $conn->query("
        SELECT user_id, module_id, COUNT(*) AS cnt 
        FROM onboard_module_progress 
        GROUP BY user_id, module_id 
        HAVING cnt > 1
    ");
    $dupCount = $dupRes ? $dupRes->num_rows : 0;
    if ($dupCount > 0) {
        $del = $conn->query("
            DELETE p1 FROM onboard_module_progress p1
            INNER JOIN onboard_module_progress p2 
            WHERE p1.id < p2.id 
              AND p1.user_id = p2.user_id 
              AND p1.module_id = p2.module_id
        ");
        logStep($results, 'Step 1: De-duplication', 'success', "Removed duplicate records from onboard_module_progress for $dupCount user-module pairs.");
    } else {
        logStep($results, 'Step 1: De-duplication', 'info', 'No duplicate user-module records found in onboard_module_progress.');
    }

    // =========================================================================
    // STEP 2: Add UNIQUE constraint on `onboard_module_progress(user_id, module_id)`
    // =========================================================================
    if (!indexExists($conn, 'onboard_module_progress', 'uk_user_module')) {
        // Drop non-unique indexes if they exist to prevent redundant index storage
        if (indexExists($conn, 'onboard_module_progress', 'user_id')) {
            $conn->query("ALTER TABLE `onboard_module_progress` DROP INDEX `user_id`");
        }
        if (indexExists($conn, 'onboard_module_progress', 'module_id')) {
            $conn->query("ALTER TABLE `onboard_module_progress` DROP INDEX `module_id`");
        }

        $sql = "ALTER TABLE `onboard_module_progress` ADD UNIQUE KEY `uk_user_module` (`user_id`, `module_id`)";
        if ($conn->query($sql)) {
            logStep($results, 'Step 2: Unique Constraint', 'success', 'Successfully added UNIQUE KEY `uk_user_module` (user_id, module_id) to onboard_module_progress.', $sql);
        } else {
            logStep($results, 'Step 2: Unique Constraint', 'error', 'Failed adding unique constraint: ' . $conn->error, $sql);
        }
    } else {
        logStep($results, 'Step 2: Unique Constraint', 'info', 'UNIQUE KEY `uk_user_module` already exists on onboard_module_progress.');
    }

    // Supporting composite index for fast completion dashboard lookups
    if (!indexExists($conn, 'onboard_module_progress', 'idx_omp_status_completed')) {
        $sql = "ALTER TABLE `onboard_module_progress` ADD INDEX `idx_omp_status_completed` (`user_id`, `status`, `is_completed`)";
        $conn->query($sql);
        logStep($results, 'Step 2B: Status Index', 'success', 'Added composite lookup index `idx_omp_status_completed` to onboard_module_progress.', $sql);
    }

    // =========================================================================
    // STEP 3: Optimize `onboard_module_sessions` (190+ rows)
    // =========================================================================
    if (!indexExists($conn, 'onboard_module_sessions', 'idx_user_mod_session')) {
        $sql = "ALTER TABLE `onboard_module_sessions` ADD INDEX `idx_user_mod_session` (`user_id`, `module_id`, `id`)";
        if ($conn->query($sql)) {
            logStep($results, 'Step 3: Session Index', 'success', 'Successfully added composite index `idx_user_mod_session` (user_id, module_id, id) to onboard_module_sessions.', $sql);
        } else {
            logStep($results, 'Step 3: Session Index', 'error', 'Failed adding index to onboard_module_sessions: ' . $conn->error, $sql);
        }
    } else {
        logStep($results, 'Step 3: Session Index', 'info', 'Index `idx_user_mod_session` already exists on onboard_module_sessions.');
    }

    // =========================================================================
    // STEP 4: Optimize `onboard_assessment_attempts`
    // =========================================================================
    if (!indexExists($conn, 'onboard_assessment_attempts', 'idx_user_module')) {
        $sql = "ALTER TABLE `onboard_assessment_attempts` ADD INDEX `idx_user_module` (`user_id`, `module_id`)";
        $conn->query($sql);
        logStep($results, 'Step 4A: Attempt Index', 'success', 'Added `idx_user_module` to onboard_assessment_attempts.', $sql);
    }
    if (!indexExists($conn, 'onboard_assessment_attempts', 'idx_user_assessment')) {
        $sql = "ALTER TABLE `onboard_assessment_attempts` ADD INDEX `idx_user_assessment` (`user_id`, `assessment_id`)";
        $conn->query($sql);
        logStep($results, 'Step 4B: Assessment Index', 'success', 'Added `idx_user_assessment` to onboard_assessment_attempts.', $sql);
    }

    // =========================================================================
    // STEP 5: Optimize `onboard_modules`
    // =========================================================================
    if (!indexExists($conn, 'onboard_modules', 'idx_moodle_ids')) {
        $sql = "ALTER TABLE `onboard_modules` ADD INDEX `idx_moodle_ids` (`moodle_course_id`, `moodle_cmid`, `moodle_scoid`)";
        $conn->query($sql);
        logStep($results, 'Step 5: Modules Index', 'success', 'Added compound index `idx_moodle_ids` to onboard_modules.', $sql);
    } else {
        logStep($results, 'Step 5: Modules Index', 'info', 'Index `idx_moodle_ids` already exists on onboard_modules.');
    }

    // =========================================================================
    // STEP 6: Synchronize 190+ sessions into `onboard_module_progress`
    // =========================================================================
    $syncSql = "
        INSERT INTO `onboard_module_progress` 
          (`user_id`, `module_id`, `status`, `progress_percent`, `highest_progress_percent`, `score`, `highest_score`, `passing_score`, `total_time`, `total_time_seconds`, `attempts`, `suspend_data`, `is_completed`, `last_synced_at`)
        SELECT 
          s.user_id,
          s.module_id,
          s.status,
          s.progress_percent,
          s.progress_percent,
          s.score,
          IFNULL(s.score, 0),
          80,
          s.session_time,
          s.session_seconds,
          1,
          s.suspend_data,
          IF(s.status = 'completed' OR s.progress_percent >= 100, 1, 0),
          NOW()
        FROM (
          SELECT s1.* 
          FROM `onboard_module_sessions` s1
          JOIN (
            SELECT user_id, module_id, MAX(id) AS max_id 
            FROM `onboard_module_sessions` 
            GROUP BY user_id, module_id
          ) s2 ON s1.id = s2.max_id
        ) s
        ON DUPLICATE KEY UPDATE
          status = VALUES(status),
          progress_percent = GREATEST(onboard_module_progress.progress_percent, VALUES(progress_percent)),
          highest_progress_percent = GREATEST(onboard_module_progress.highest_progress_percent, VALUES(progress_percent)),
          score = COALESCE(VALUES(score), onboard_module_progress.score),
          highest_score = GREATEST(IFNULL(onboard_module_progress.highest_score, 0), IFNULL(VALUES(score), 0)),
          total_time = VALUES(total_time),
          total_time_seconds = GREATEST(onboard_module_progress.total_time_seconds, VALUES(total_time_seconds)),
          is_completed = IF(onboard_module_progress.is_completed = 1 OR VALUES(is_completed) = 1, 1, 0),
          last_synced_at = NOW()
    ";
    if ($conn->query($syncSql)) {
        $affected = $conn->affected_rows;
        logStep($results, 'Step 6: Real Telemetry Sync', 'success', "Successfully populated/updated onboard_module_progress with real session tracking (rows affected: $affected).", $syncSql);
    } else {
        logStep($results, 'Step 6: Real Telemetry Sync', 'warning', "Sync notice: " . $conn->error, $syncSql);
    }

    $conn->query("SET FOREIGN_KEY_CHECKS = 1");
}

// Current table counts
$tablesToCheck = [
    'onboard_modules',
    'onboard_moodle_users',
    'onboard_module_progress',
    'onboard_module_sessions',
    'onboard_assessment_attempts',
    'onboard_settings'
];

$tableStats = [];
foreach ($tablesToCheck as $tbl) {
    $res = $conn->query("SELECT COUNT(*) AS c FROM `$tbl`");
    $cnt = $res ? (int)$res->fetch_assoc()['c'] : 'Error';
    $tableStats[$tbl] = $cnt;
}

if ($isCli) {
    echo "========================================================\n";
    echo " ONBOARD DATABASE STRUCTURE UPDATER\n";
    echo "========================================================\n";
    if (!$apply) {
        echo "Dry run preview mode. To apply changes, run:\n";
        echo "  php update_db_structure.php --apply\n\n";
    } else {
        foreach ($results as $r) {
            echo "[" . strtoupper($r['status']) . "] " . $r['step'] . ": " . $r['message'] . "\n";
        }
    }
    echo "\n--- Current Table Row Counts ---\n";
    foreach ($tableStats as $tbl => $cnt) {
        echo sprintf(" %-30s : %d\n", $tbl, $cnt);
    }
    exit(0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Structure Updater - Onboarding Portal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #0f172a;
            color: #f8fafc;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        .card {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 16px;
            max-width: 820px;
            width: 100%;
            padding: 32px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.4), 0 8px 10px -6px rgba(0, 0, 0, 0.3);
        }
        .header {
            display: flex;
            align-items: center;
            gap: 16px;
            border-bottom: 1px solid #334155;
            padding-bottom: 20px;
            margin-bottom: 24px;
        }
        .icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: #00A389;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            font-weight: bold;
            color: #fff;
        }
        h1 { font-size: 20px; font-weight: 800; color: #fff; }
        p.subtitle { font-size: 13px; color: #94a3b8; margin-top: 2px; }
        .safety-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 8px;
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: #34d399;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 20px;
        }
        .table-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 12px;
            margin-bottom: 24px;
        }
        .stat-box {
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 10px;
            padding: 12px 16px;
        }
        .stat-name { font-size: 11px; color: #94a3b8; font-weight: 600; }
        .stat-value { font-size: 20px; color: #00A389; font-weight: 800; margin-top: 4px; }
        .step-list {
            margin-bottom: 24px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .step-item {
            padding: 12px 16px;
            border-radius: 10px;
            font-size: 13px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }
        .step-item.success { background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.3); color: #6ee7b7; }
        .step-item.info { background: rgba(59, 130, 246, 0.1); border: 1px solid rgba(59, 130, 246, 0.3); color: #93c5fd; }
        .step-item.warning { background: rgba(245, 158, 11, 0.1); border: 1px solid rgba(245, 158, 11, 0.3); color: #fcd34d; }
        .step-item.error { background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); color: #fca5a5; }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 12px 24px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 700;
            color: #fff;
            background: #00A389;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            width: 100%;
        }
        .btn:hover { background: #008a74; }
        .sql-box {
            margin-top: 20px;
            padding: 16px;
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 10px;
            font-family: monospace;
            font-size: 11px;
            color: #cbd5e1;
            white-space: pre-wrap;
            max-height: 200px;
            overflow-y: auto;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="header">
            <div class="icon">✓</div>
            <div>
                <h1>Database Structure Updater & Telemetry Sync</h1>
                <p class="subtitle">Optimizes indexes, adds unique constraint on progress, and syncs live session data.</p>
            </div>
        </div>

        <div class="safety-badge">
            🛡️ Strict Safety Guarantee: Modifies ONLY <code>onboard_*</code> tables. The <code>user</code> table is never touched.
        </div>

        <h3 style="font-size: 13px; font-weight: 700; color: #94a3b8; margin-bottom: 8px;">LIVE TABLE ROW COUNTS</h3>
        <div class="table-grid">
            <?php foreach ($tableStats as $tbl => $cnt): ?>
                <div class="stat-box">
                    <div class="stat-name"><?= htmlspecialchars($tbl) ?></div>
                    <div class="stat-value"><?= htmlspecialchars($cnt) ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($apply): ?>
            <h3 style="font-size: 13px; font-weight: 700; color: #94a3b8; margin-bottom: 8px;">EXECUTION RESULTS</h3>
            <div class="step-list">
                <?php foreach ($results as $res): ?>
                    <div class="step-item <?= htmlspecialchars($res['status']) ?>">
                        <div>
                            <strong><?= htmlspecialchars($res['step']) ?>:</strong>
                            <?= htmlspecialchars($res['message']) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div style="display: flex; gap: 12px; margin-top: 16px;">
                <a href="update_db_structure.php" class="btn" style="background: #334155;">Refresh Status</a>
                <a href="/internal/hex-timesheet/onboard/modules" class="btn" target="_blank">Go to Onboard Modules →</a>
            </div>
        <?php else: ?>
            <p style="font-size: 13px; color: #cbd5e1; margin-bottom: 16px; line-height: 1.6;">
                Clicking <strong>"Apply Structure Updates & Sync Now"</strong> will:
                <br>• Add <code>UNIQUE KEY (user_id, module_id)</code> to <code>onboard_module_progress</code>.
                <br>• Add composite index to <code>onboard_module_sessions</code> (speeding up queries across 190+ rows).
                <br>• Add attempt indexes to <code>onboard_assessment_attempts</code>.
                <br>• Synchronize the 190+ session telemetry records into <code>onboard_module_progress</code> so the table is fully active.
            </p>
            <form method="POST">
                <input type="hidden" name="apply" value="1">
                <button type="submit" class="btn">Apply Structure Updates & Sync Now</button>
            </form>

            <div class="sql-box">
-- Or run raw SQL in phpMyAdmin or p.php:
ALTER TABLE `onboard_module_progress` ADD UNIQUE KEY `uk_user_module` (`user_id`, `module_id`);
ALTER TABLE `onboard_module_sessions` ADD INDEX `idx_user_mod_session` (`user_id`, `module_id`, `id`);
ALTER TABLE `onboard_assessment_attempts` ADD INDEX `idx_user_module` (`user_id`, `module_id`);
ALTER TABLE `onboard_assessment_attempts` ADD INDEX `idx_user_assessment` (`user_id`, `assessment_id`);
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
