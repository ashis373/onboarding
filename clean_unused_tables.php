<?php
/**
 * Database Cleanup Script: Unused & Dummy Onboard Tables
 * Location: clean_unused_tables.php
 *
 * Usage:
 * - Browser: Visit https://hexalearn.com/internal/hex-timesheet/onboard/clean_unused_tables.php
 * - CLI: php clean_unused_tables.php [--truncate | --drop | --preview]
 */

require_once __DIR__ . '/api/db.php';

// The 7 Active Moodle & Employee tables to NEVER touch
$keepTables = [
    'onboard_moodle_users',
    'onboard_modules',
    'onboard_module_progress',
    'onboard_module_sessions',
    'onboard_assessment_attempts',
    'onboard_settings',
    'onboard_question_types'
];

// The 20 Unused / Dummy Admin tables to clean
$unusedTables = [
    'onboard_assessment_answers',
    'onboard_assessment_pools',
    'onboard_assessments',
    'onboard_assets',
    'onboard_buddies',
    'onboard_feedback_pulse',
    'onboard_group_audience',
    'onboard_group_schedule',
    'onboard_groups',
    'onboard_module_drafts',
    'onboard_pool_questions',
    'onboard_pools',
    'onboard_question_matching_pairs',
    'onboard_question_options',
    'onboard_question_reorder_items',
    'onboard_questions',
    'onboard_results_export',
    'onboard_saved_filters',
    'onboard_sections',
    'onboard_sequence_items'
];

$action = 'preview';
if (php_sapi_name() === 'cli') {
    global $argv;
    if (in_array('--truncate', $argv ?? [], true)) $action = 'truncate';
    if (in_array('--drop', $argv ?? [], true)) $action = 'drop';
} else {
    $action = $_GET['action'] ?? 'preview';
}

$report = [];
$conn->query("SET FOREIGN_KEY_CHECKS = 0");

foreach ($unusedTables as $table) {
    $check = $conn->query("SHOW TABLES LIKE '$table'");
    if (!$check || $check->num_rows === 0) {
        $report[$table] = ['exists' => false, 'rows' => 0, 'status' => 'Table does not exist'];
        continue;
    }

    $rows = 0;
    try {
        $cntRes = $conn->query("SELECT COUNT(1) FROM `$table`");
        $rows = $cntRes ? (int)$cntRes->fetch_row()[0] : 0;
    } catch (Throwable $e) {
        $rows = 0;
    }

    if ($action === 'truncate') {
        try {
            $ok = $conn->query("TRUNCATE TABLE `$table`");
            $report[$table] = [
                'exists' => true,
                'rows_before' => $rows,
                'status' => $ok ? 'TRUNCATED (Now 0 rows)' : 'Error: ' . $conn->error
            ];
        } catch (Throwable $e) {
            $report[$table] = [
                'exists' => true,
                'rows_before' => $rows,
                'status' => 'Error: ' . $e->getMessage()
            ];
        }
    } elseif ($action === 'drop') {
        try {
            // Drop view first in case it's a VIEW, then drop table
            $conn->query("DROP VIEW IF EXISTS `$table`");
            $conn->query("DROP TABLE IF EXISTS `$table`");
            $report[$table] = [
                'exists' => true,
                'rows_before' => $rows,
                'status' => 'DROPPED (Permanently Removed)'
            ];
        } catch (Throwable $e) {
            $report[$table] = [
                'exists' => true,
                'rows_before' => $rows,
                'status' => 'Error: ' . $e->getMessage()
            ];
        }
    } else {
        $report[$table] = [
            'exists' => true,
            'rows' => $rows,
            'status' => ($rows > 0) ? "Has $rows dummy rows (Ready to clean)" : "0 rows (Empty)"
        ];
    }
}

$conn->query("SET FOREIGN_KEY_CHECKS = 1");

// CLI Output
if (php_sapi_name() === 'cli') {
    echo "=======================================================\n";
    echo "ONBOARD UNUSED TABLES CLEANUP (Action: " . strtoupper($action) . ")\n";
    echo "=======================================================\n";
    foreach ($report as $tbl => $info) {
        echo sprintf("%-33s | %s\n", $tbl, $info['status']);
    }
    echo "=======================================================\n";
    exit;
}

// Graphical UI Output
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Clean Unused Onboard Tables</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f172a; color: #f8fafc; margin: 0; padding: 30px; }
        .container { max-width: 900px; margin: 0 auto; background: #1e293b; border-radius: 12px; padding: 24px; box-shadow: 0 10px 25px rgba(0,0,0,0.4); border: 1px solid #334155; }
        h1 { color: #38bdf8; margin-top: 0; font-size: 22px; }
        p { color: #94a3b8; font-size: 14px; line-height: 1.6; }
        .badge-keep { background: #065f46; color: #6ee7b7; padding: 3px 8px; border-radius: 6px; font-size: 12px; font-weight: 600; }
        .badge-clean { background: #7f1d1d; color: #fca5a5; padding: 3px 8px; border-radius: 6px; font-size: 12px; font-weight: 600; }
        .table-wrap { margin-top: 20px; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { padding: 10px 14px; text-align: left; border-bottom: 1px solid #334155; }
        th { background: #0f172a; color: #94a3b8; font-weight: 600; }
        tr:hover { background: #243249; }
        .btn-group { display: flex; gap: 12px; margin: 24px 0 10px 0; }
        .btn { padding: 10px 18px; border-radius: 8px; font-weight: 600; font-size: 13px; text-decoration: none; cursor: pointer; border: none; display: inline-flex; align-items: center; }
        .btn-preview { background: #334155; color: #f8fafc; }
        .btn-truncate { background: #d97706; color: #fff; }
        .btn-drop { background: #dc2626; color: #fff; }
        .btn:hover { opacity: 0.9; }
        .notice { background: #1e3a5f; border-left: 4px solid #38bdf8; padding: 12px 16px; border-radius: 4px; margin-bottom: 20px; font-size: 13px; color: #bae6fd; }
    </style>
</head>
<body>
<div class="container">
    <h1>Database Cleanup: Unused & Dummy Tables</h1>
    <p>
        The live employee portal uses <strong>Moodle LMS</strong> for real-time course completions and assessment scores.
        Below are the 20 legacy dummy tables from the initial quiz prototype that are no longer used.
    </p>

    <div class="notice">
        <strong>Protected Tables (NEVER Touched):</strong><br>
        <code><?php echo implode('</code>, <code>', $keepTables); ?></code>
    </div>

    <div class="btn-group">
        <a href="?action=preview" class="btn btn-preview">1. Refresh Preview</a>
        <a href="?action=truncate" class="btn btn-truncate" onclick="return confirm('Wipe dummy data from all 20 unused tables? Structure will remain empty.')">2. Wipe Dummy Data (Truncate)</a>
        <a href="?action=drop" class="btn btn-drop" onclick="return confirm('PERMANENTLY DROP all 20 unused tables from MySQL database? This cannot be undone.')">3. Drop Unused Tables (Permanent)</a>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Table Name</th>
                    <th>Status / Action Result</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($report as $tbl => $info): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($tbl); ?></strong></td>
                    <td>
                        <?php if (strpos($info['status'], 'DROPPED') !== false): ?>
                            <span class="badge-clean"><?php echo htmlspecialchars($info['status']); ?></span>
                        <?php elseif (strpos($info['status'], 'TRUNCATED') !== false): ?>
                            <span class="badge-clean"><?php echo htmlspecialchars($info['status']); ?></span>
                        <?php elseif (strpos($info['status'], 'Has') !== false): ?>
                            <span style="color:#fbbf24; font-weight:600;"><?php echo htmlspecialchars($info['status']); ?></span>
                        <?php else: ?>
                            <span style="color:#94a3b8;"><?php echo htmlspecialchars($info['status']); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
