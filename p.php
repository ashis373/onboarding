<?php
/**
 * HexaLearn Database Explorer & Onboard Table Inspector
 * STRICTLY READ-ONLY — Only SELECT and INFORMATION_SCHEMA queries allowed.
 * Zero database writes, zero updates, zero deletes.
 */

// Buffer any stray output from includes
ob_start();
if (file_exists(__DIR__ . '/api/db.php')) {
    require_once __DIR__ . '/api/db.php';
} elseif (file_exists(__DIR__ . '/db.php')) {
    require_once __DIR__ . '/db.php';
} else {
    die("Database configuration file api/db.php not found.");
}
ob_end_clean();

header("Content-Type: text/html; charset=UTF-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

// Validate connection
if (!isset($conn) || $conn->connect_error) {
    die('<div style="background:#090d16;color:#f43f5e;padding:30px;font-family:sans-serif;"><h2>Database Connection Error</h2><p>' . htmlspecialchars($conn->connect_error ?? 'Missing database connection') . '</p></div>');
}

// 1. Fetch current database name & server version
$dbResult = $conn->query("SELECT DATABASE() AS db_name, VERSION() AS mysql_ver");
$dbInfo = $dbResult ? $dbResult->fetch_assoc() : ['db_name' => 'Unknown', 'mysql_ver' => ''];
$databaseName = $dbInfo['db_name'] ?? 'hex-timesheet';
$mysqlVersion = $dbInfo['mysql_ver'] ?? '';

// 2. Fetch all tables and metadata in ONE fast query from INFORMATION_SCHEMA
$tblQuery = "
    SELECT 
        TABLE_NAME, 
        TABLE_ROWS, 
        DATA_LENGTH, 
        INDEX_LENGTH, 
        ENGINE, 
        TABLE_COLLATION, 
        TABLE_COMMENT
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
    ORDER BY TABLE_NAME ASC
";
$tblRes = $conn->query($tblQuery);

$allTables = [];
$onboardTables = [];
$tableMeta = [];

if ($tblRes) {
    while ($row = $tblRes->fetch_assoc()) {
        $t = $row['TABLE_NAME'];
        $allTables[] = $t;
        $tableMeta[$t] = [
            'rows' => (int)($row['TABLE_ROWS'] ?? 0),
            'size' => (int)($row['DATA_LENGTH'] ?? 0) + (int)($row['INDEX_LENGTH'] ?? 0),
            'engine' => $row['ENGINE'] ?? 'InnoDB',
            'collation' => $row['TABLE_COLLATION'] ?? '',
            'comment' => $row['TABLE_COMMENT'] ?? ''
        ];

        // Classify Onboarding-relevant tables (onboard_* prefix and core employee/setting tables)
        if (strpos($t, 'onboard_') === 0 || in_array(strtolower($t), ['user', 'user_role', 'settings', 'employee'])) {
            $onboardTables[] = $t;
        }
    }
}
sort($allTables);
sort($onboardTables);

// Core Active Moodle & Employee tables actually used by the current app
$activeMoodleTables = [
    'onboard_modules',
    'onboard_moodle_users',
    'onboard_module_progress',
    'onboard_module_sessions',
    'onboard_assessment_attempts',
    'onboard_settings',
    'user'
];

$activeTables = [];
foreach ($activeMoodleTables as $at) {
    if (in_array($at, $allTables, true)) {
        $activeTables[] = $at;
    }
}

// 3. Mode filter: 'active' (default), 'onboard', or 'all'
$mode = isset($_GET['mode']) && in_array($_GET['mode'], ['active', 'onboard', 'all'], true) ? $_GET['mode'] : 'active';
if ($mode === 'active') {
    $displayTables = $activeTables;
} elseif ($mode === 'all') {
    $displayTables = $allTables;
} else {
    $displayTables = $onboardTables;
}

if ($mode === 'active' && empty($activeTables)) {
    $mode = 'onboard';
    $displayTables = $onboardTables;
}

// 4. Selected table
$selectedTable = isset($_GET['table']) ? trim($_GET['table']) : '';
if (!in_array($selectedTable, $allTables, true)) {
    $selectedTable = !empty($displayTables) ? $displayTables[0] : '';
}

// 5. Active Tab ('data', 'schema', 'sql')
$activeTab = isset($_GET['tab']) && in_array($_GET['tab'], ['data', 'schema', 'sql'], true) ? $_GET['tab'] : 'data';

// 6. Pagination & Search variables
$page = max(1, isset($_GET['p']) ? (int)$_GET['p'] : 1);
$perPage = isset($_GET['limit']) ? min(200, max(10, (int)$_GET['limit'])) : 25;
$search = isset($_GET['q']) ? trim($_GET['q']) : '';

// 7. Table Details (if valid table selected)
$tableColumns = [];
$tableData = [];
$totalRows = 0;
$totalPages = 1;
$createTableSql = '';
$tableStatus = null;

if ($selectedTable) {
    // 7.1 Fetch Columns schema
    $colRes = $conn->query("
        SELECT 
            COLUMN_NAME, 
            COLUMN_TYPE, 
            IS_NULLABLE, 
            COLUMN_KEY, 
            COLUMN_DEFAULT, 
            EXTRA, 
            COLUMN_COMMENT
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = '" . $conn->real_escape_string($selectedTable) . "'
        ORDER BY ORDINAL_POSITION ASC
    ");
    if ($colRes) {
        while ($col = $colRes->fetch_assoc()) {
            $tableColumns[] = $col;
        }
    }

    // 7.2 Count Total Rows (with search query support)
    $whereClause = "";
    if ($search !== '' && !empty($tableColumns)) {
        $searchTerms = [];
        $escapedSearch = '%' . $conn->real_escape_string($search) . '%';
        foreach ($tableColumns as $col) {
            $colName = $col['COLUMN_NAME'];
            $colType = strtolower($col['COLUMN_TYPE']);
            if (preg_match('/char|text|int|blob|date|time/', $colType)) {
                $searchTerms[] = "`" . $conn->real_escape_string($colName) . "` LIKE '" . $escapedSearch . "'";
            }
        }
        if (!empty($searchTerms)) {
            $whereClause = " WHERE " . implode(" OR ", $searchTerms);
        }
    }

    $countRes = $conn->query("SELECT COUNT(*) AS total FROM `" . $conn->real_escape_string($selectedTable) . "`" . $whereClause);
    if ($countRes) {
        $totalRows = (int)($countRes->fetch_assoc()['total'] ?? 0);
    }
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    if ($page > $totalPages) $page = $totalPages;
    $offset = ($page - 1) * $perPage;

    // 7.3 Fetch Rows for Data Preview
    if ($activeTab === 'data') {
        $dataRes = $conn->query("SELECT * FROM `" . $conn->real_escape_string($selectedTable) . "`" . $whereClause . " LIMIT " . (int)$offset . ", " . (int)$perPage);
        if ($dataRes) {
            while ($row = $dataRes->fetch_assoc()) {
                $tableData[] = $row;
            }
        }
    }

    // 7.4 Fetch CREATE TABLE SQL
    if ($activeTab === 'sql') {
        $sqlRes = $conn->query("SHOW CREATE TABLE `" . $conn->real_escape_string($selectedTable) . "`");
        if ($sqlRes) {
            $createTableSql = $sqlRes->fetch_row()[1] ?? '';
        }
    }

    // 7.5 Fetch Status Engine
    $statusRes = $conn->query("SHOW TABLE STATUS LIKE '" . $conn->real_escape_string($selectedTable) . "'");
    if ($statusRes) {
        $tableStatus = $statusRes->fetch_assoc();
    }
}

// 8. Safe Read-Only CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv' && $selectedTable) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $selectedTable . '_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    if (!empty($tableColumns)) {
        fputcsv($out, array_column($tableColumns, 'COLUMN_NAME'));
    }
    $exportRes = $conn->query("SELECT * FROM `" . $conn->real_escape_string($selectedTable) . "`" . $whereClause . " LIMIT 5000");
    if ($exportRes) {
        while ($r = $exportRes->fetch_assoc()) {
            fputcsv($out, $r);
        }
    }
    fclose($out);
    exit;
}

// 9. Raw JSON API Endpoint
if (isset($_GET['format']) && $_GET['format'] === 'json') {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'database' => $databaseName,
        'mysql_version' => $mysqlVersion,
        'selected_table' => $selectedTable,
        'total_rows' => $totalRows,
        'onboard_count' => count($onboardTables),
        'total_tables_count' => count($allTables),
        'columns' => $tableColumns,
        'data' => $tableData,
        'onboard_tables' => $onboardTables
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// Helper to format bytes
function formatTableBytes($bytes) {
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HexaLearn DB Explorer &bull; <?= htmlspecialchars($selectedTable ?: 'Tables') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-body: #070d19;
            --bg-surface: #0f172a;
            --bg-card: #141e33;
            --bg-card-hover: #1b2742;
            --border-color: #1e293b;
            --border-highlight: #334155;
            --text-main: #f1f5f9;
            --text-muted: #94a3b8;
            --text-faint: #64748b;
            --accent-cyan: #38bdf8;
            --accent-blue: #2563eb;
            --accent-purple: #818cf8;
            --accent-emerald: #10b981;
            --accent-amber: #f59e0b;
            --accent-rose: #f43f5e;
            --font-sans: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            --font-mono: 'JetBrains Mono', Consolas, monospace;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            background-color: var(--bg-body);
            color: var(--text-main);
            font-family: var(--font-sans);
            font-size: 13.5px;
            line-height: 1.5;
            display: flex;
            flex-direction: column;
            height: 100vh;
            overflow: hidden;
            -webkit-font-smoothing: antialiased;
        }

        /* Top Bar */
        .topbar {
            height: 56px;
            background: var(--bg-surface);
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 20px;
            flex-shrink: 0;
            gap: 16px;
        }

        .brand-box {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .brand-icon {
            width: 34px;
            height: 34px;
            background: linear-gradient(135deg, #0284c7, #2563eb);
            border-radius: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            box-shadow: 0 0 14px rgba(37, 99, 235, 0.45);
        }

        .brand-icon svg {
            display: block;
        }

        .brand-title {
            font-size: 15px;
            font-weight: 700;
            color: #fff;
            display: flex;
            align-items: center;
            gap: 9px;
            letter-spacing: -0.2px;
        }

        .brand-badge {
            font-size: 10px;
            text-transform: uppercase;
            padding: 2px 7px;
            background: rgba(56, 189, 248, 0.12);
            color: var(--accent-cyan);
            border: 1px solid rgba(56, 189, 248, 0.35);
            border-radius: 4px;
            letter-spacing: 0.6px;
            font-weight: 700;
        }

        .top-meta {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 12px;
            color: var(--text-muted);
        }

        .meta-pill {
            display: flex;
            align-items: center;
            gap: 6px;
            background: var(--bg-card);
            padding: 5px 12px;
            border-radius: 6px;
            border: 1px solid var(--border-color);
        }

        .meta-pill strong {
            color: #fff;
            font-family: var(--font-mono);
        }

        /* App Body Layout */
        .app-body {
            display: flex;
            flex: 1;
            overflow: hidden;
        }

        /* Left Sidebar */
        .sidebar {
            width: 310px;
            background: var(--bg-surface);
            border-right: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
        }

        .sidebar-header {
            padding: 14px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        /* Pill Mode Switcher */
        .mode-toggle {
            display: flex;
            background: var(--bg-body);
            padding: 3px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }

        .mode-btn {
            flex: 1;
            text-align: center;
            padding: 7px 10px;
            font-size: 12.5px;
            font-weight: 600;
            color: var(--text-muted);
            text-decoration: none;
            border-radius: 6px;
            transition: all 0.15s ease;
        }

        .mode-btn:hover {
            color: #fff;
        }

        .mode-btn.active {
            background: var(--bg-card);
            color: #fff;
            border: 1px solid rgba(56, 189, 248, 0.25);
            box-shadow: 0 1px 4px rgba(0,0,0,0.4);
        }

        /* Quick Find Input */
        .search-box-wrapper {
            position: relative;
        }

        .search-input {
            width: 100%;
            background: var(--bg-body);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 8px 12px;
            font-size: 13px;
            color: #fff;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
            font-family: var(--font-sans);
        }

        .search-input::placeholder {
            color: var(--text-faint);
        }

        .search-input:focus {
            border-color: var(--accent-cyan);
            box-shadow: 0 0 0 2px rgba(56, 189, 248, 0.18);
        }

        /* Table List */
        .table-list {
            flex: 1;
            overflow-y: auto;
            padding: 8px;
            list-style: none;
        }

        .table-list::-webkit-scrollbar {
            width: 6px;
        }
        .table-list::-webkit-scrollbar-thumb {
            background: var(--border-highlight);
            border-radius: 4px;
        }

        .table-item-link {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 12px;
            color: var(--text-muted);
            text-decoration: none;
            border-radius: 6px;
            font-size: 13px;
            font-family: var(--font-mono);
            transition: all 0.15s ease;
            margin-bottom: 2px;
            border-left: 3px solid transparent;
        }

        .table-item-link:hover {
            background: var(--bg-card);
            color: #fff;
        }

        .table-item-link.active {
            background: linear-gradient(90deg, rgba(56, 189, 248, 0.16), rgba(37, 99, 235, 0.08));
            border-left-color: var(--accent-cyan);
            color: #fff;
            font-weight: 600;
        }

        .table-count-badge {
            font-size: 11px;
            padding: 2px 7px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 4px;
            color: var(--text-faint);
            font-family: var(--font-sans);
            font-weight: 500;
        }

        .table-item-link.active .table-count-badge {
            color: var(--accent-cyan);
            background: rgba(56, 189, 248, 0.18);
        }

        /* Main Content View */
        .content-area {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            background: var(--bg-body);
        }

        /* Content Header */
        .content-header {
            padding: 16px 24px;
            background: var(--bg-surface);
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }

        .table-heading {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .table-heading h1 {
            font-size: 20px;
            font-weight: 700;
            color: #fff;
            font-family: var(--font-mono);
            letter-spacing: -0.3px;
        }

        .tab-nav {
            display: flex;
            gap: 6px;
        }

        .tab-btn {
            padding: 7px 14px;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-muted);
            text-decoration: none;
            border-radius: 6px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            transition: all 0.15s ease;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .tab-btn:hover {
            color: #fff;
            background: var(--bg-card-hover);
        }

        .tab-btn.active {
            color: #fff;
            background: var(--accent-blue);
            border-color: var(--accent-blue);
            box-shadow: 0 0 12px rgba(37, 99, 235, 0.4);
        }

        /* Action / Search Bar inside Content Area */
        .action-bar {
            padding: 10px 24px;
            background: rgba(15, 23, 42, 0.7);
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .filter-form {
            display: flex;
            align-items: center;
            gap: 8px;
            flex: 1;
            max-width: 480px;
        }

        .table-view-container {
            flex: 1;
            overflow: auto;
            padding: 20px 24px;
        }

        .table-view-container::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        .table-view-container::-webkit-scrollbar-thumb {
            background: var(--border-highlight);
            border-radius: 4px;
        }

        /* Data Grid Table */
        .data-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            background: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            overflow: hidden;
            font-size: 13px;
        }

        .data-table th {
            background: var(--bg-card);
            color: var(--text-muted);
            font-weight: 600;
            text-align: left;
            padding: 10px 14px;
            border-bottom: 1px solid var(--border-color);
            position: sticky;
            top: 0;
            z-index: 10;
            white-space: nowrap;
            font-family: var(--font-mono);
            font-size: 12px;
        }

        .data-table td {
            padding: 9px 14px;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-main);
            vertical-align: top;
            max-width: 320px;
            word-break: break-word;
        }

        .data-table tr:last-child td {
            border-bottom: none;
        }

        .data-table tr:hover td {
            background: rgba(255, 255, 255, 0.025);
        }

        /* Badges */
        .badge-null {
            color: var(--text-faint);
            font-style: italic;
            font-size: 11px;
            background: rgba(255, 255, 255, 0.04);
            padding: 2px 6px;
            border-radius: 4px;
        }

        .badge-pri {
            background: rgba(245, 158, 11, 0.15);
            color: var(--accent-amber);
            border: 1px solid rgba(245, 158, 11, 0.35);
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 4px;
            font-weight: 700;
            font-family: var(--font-mono);
        }

        .badge-mul {
            background: rgba(56, 189, 248, 0.15);
            color: var(--accent-cyan);
            border: 1px solid rgba(56, 189, 248, 0.35);
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 4px;
            font-family: var(--font-mono);
        }

        .badge-uni {
            background: rgba(16, 185, 129, 0.15);
            color: var(--accent-emerald);
            border: 1px solid rgba(16, 185, 129, 0.35);
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 4px;
            font-family: var(--font-mono);
        }

        .badge-type {
            font-family: var(--font-mono);
            color: var(--accent-purple);
            font-size: 12px;
        }

        /* Pagination Bar */
        .pagination-bar {
            padding: 12px 24px;
            background: var(--bg-surface);
            border-top: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 13px;
            color: var(--text-muted);
        }

        .page-controls {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .page-btn {
            padding: 5px 12px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            color: var(--text-main);
            text-decoration: none;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            transition: all 0.15s ease;
            cursor: pointer;
        }

        .page-btn:hover:not(.disabled) {
            background: var(--bg-card-hover);
            border-color: var(--border-highlight);
            color: #fff;
        }

        .page-btn.disabled {
            opacity: 0.4;
            pointer-events: none;
        }

        /* SQL Pre block */
        .sql-box {
            background: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 20px;
            font-family: var(--font-mono);
            color: #e2e8f0;
            white-space: pre-wrap;
            line-height: 1.6;
            overflow: auto;
            position: relative;
        }

        .copy-btn {
            position: absolute;
            top: 14px;
            right: 14px;
            padding: 5px 12px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            color: var(--accent-cyan);
            border-radius: 5px;
            font-size: 12px;
            cursor: pointer;
            font-family: var(--font-sans);
        }

        .copy-btn:hover {
            background: var(--bg-card-hover);
            color: #fff;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-muted);
        }
        .empty-state h3 {
            color: #fff;
            margin-bottom: 8px;
        }
    </style>
</head>
<body>

    <!-- Top Navigation Bar -->
    <header class="topbar">
        <div class="brand-box">
            <div class="brand-icon">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="4" y1="6" x2="20" y2="6"></line>
                    <line x1="4" y1="12" x2="20" y2="12"></line>
                    <line x1="4" y1="18" x2="20" y2="18"></line>
                </svg>
            </div>
            <div class="brand-title">
                HexaLearn DB Explorer
                <span class="brand-badge">READ-ONLY</span>
            </div>
        </div>
        <div class="top-meta">
            <div class="meta-pill">
                <span>DB:</span>
                <strong><?= htmlspecialchars($databaseName) ?></strong>
            </div>
            <div class="meta-pill">
                <span>Onboard:</span>
                <strong style="color:var(--accent-cyan);"><?= count($onboardTables) ?></strong>
            </div>
            <div class="meta-pill">
                <span>Total:</span>
                <strong><?= count($allTables) ?></strong>
            </div>
            <a href="?format=json<?= $selectedTable ? '&table='.urlencode($selectedTable) : '' ?>" target="_blank" class="page-btn">
                &lcub;&rcub; JSON
            </a>
        </div>
    </header>

    <!-- Main Application Body -->
    <div class="app-body">

        <!-- Sidebar Tables Navigation -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <!-- Mode Switcher: Active Moodle vs All Onboard vs All Tables -->
                <div class="mode-toggle">
                    <a href="?mode=active&tab=<?= $activeTab ?>" class="mode-btn <?= $mode === 'active' ? 'active' : '' ?>" title="Active Moodle & Core Tables">
                        Active (<?= count($activeTables) ?>)
                    </a>
                    <a href="?mode=onboard&tab=<?= $activeTab ?>" class="mode-btn <?= $mode === 'onboard' ? 'active' : '' ?>" title="All Onboard tables including QA prototype">
                        Onboard (<?= count($onboardTables) ?>)
                    </a>
                    <a href="?mode=all&tab=<?= $activeTab ?>" class="mode-btn <?= $mode === 'all' ? 'active' : '' ?>" title="All database tables">
                        All (<?= count($allTables) ?>)
                    </a>
                </div>

                <!-- Instant Quick Find Input -->
                <div class="search-box-wrapper">
                    <input type="text" id="tableFilterInput" class="search-input" placeholder="Quick find table..." autocomplete="off">
                </div>
            </div>

            <!-- Scrollable Table Links -->
            <ul class="table-list" id="tableListUl">
                <?php foreach ($displayTables as $tbl): 
                    $rCount = $tableMeta[$tbl]['rows'] ?? 0;
                    $isActive = in_array($tbl, $activeMoodleTables, true);
                    $isLegacyQA = (strpos($tbl, 'onboard_') === 0 && !$isActive && $tbl !== 'onboard_question_types');
                ?>
                    <li>
                        <a href="?mode=<?= $mode ?>&table=<?= urlencode($tbl) ?>&tab=<?= $activeTab ?>" 
                           class="table-item-link <?= $tbl === $selectedTable ? 'active' : '' ?>"
                           data-table-name="<?= htmlspecialchars($tbl) ?>">
                            <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($tbl) ?></span>
                            <span style="display:flex;align-items:center;gap:5px;flex-shrink:0;">
                                <?php if ($isActive): ?>
                                    <span style="font-size:9px;color:var(--accent-emerald);background:rgba(16,185,129,0.14);padding:1px 5px;border-radius:3px;font-family:var(--font-sans);font-weight:700;">ACTIVE</span>
                                <?php elseif ($isLegacyQA): ?>
                                    <span style="font-size:9px;color:var(--text-faint);background:rgba(255,255,255,0.05);padding:1px 4px;border-radius:3px;font-family:var(--font-sans);">QA</span>
                                <?php endif; ?>
                                <span class="table-count-badge"><?= number_format($rCount) ?></span>
                            </span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </aside>

        <!-- Right Content Area -->
        <main class="content-area">

            <?php if ($selectedTable): 
                $isSelectedActive = in_array($selectedTable, $activeMoodleTables, true);
                $isSelectedLegacyQA = (strpos($selectedTable, 'onboard_') === 0 && !$isSelectedActive);
            ?>
                <!-- Content Header -->
                <div class="content-header">
                    <div class="table-heading">
                        <h1><?= htmlspecialchars($selectedTable) ?></h1>
                        <span class="brand-badge" style="background:rgba(59,130,246,0.15);color:#60a5fa;border-color:rgba(59,130,246,0.35);">
                            <?= number_format($totalRows) ?> rows
                        </span>
                        <?php if ($isSelectedActive): ?>
                            <span class="brand-badge" style="background:rgba(16,185,129,0.15);color:#34d399;border-color:rgba(16,185,129,0.35);">
                                MOODLE ACTIVE
                            </span>
                        <?php elseif ($isSelectedLegacyQA): ?>
                            <span class="brand-badge" style="background:rgba(148,163,184,0.12);color:#94a3b8;border-color:rgba(148,163,184,0.3);">
                                LEGACY / QA
                            </span>
                        <?php endif; ?>
                        <?php if (!empty($tableMeta[$selectedTable]['size'])): ?>
                            <span class="table-count-badge" style="background:rgba(255,255,255,0.06);color:var(--text-muted);">
                                <?= formatTableBytes($tableMeta[$selectedTable]['size']) ?>
                            </span>
                        <?php endif; ?>
                        <?php if ($tableStatus && !empty($tableStatus['Engine'])): ?>
                            <span class="table-count-badge"><?= htmlspecialchars($tableStatus['Engine']) ?></span>
                        <?php endif; ?>
                    </div>

                    <!-- Navigation Tabs -->
                    <div class="tab-nav">
                        <a href="?mode=<?= $mode ?>&table=<?= urlencode($selectedTable) ?>&tab=data" class="tab-btn <?= $activeTab === 'data' ? 'active' : '' ?>">
                            <span>📊</span> Browse Data
                        </a>
                        <a href="?mode=<?= $mode ?>&table=<?= urlencode($selectedTable) ?>&tab=schema" class="tab-btn <?= $activeTab === 'schema' ? 'active' : '' ?>">
                            <span>🛠</span> Structure (<?= count($tableColumns) ?>)
                        </a>
                        <a href="?mode=<?= $mode ?>&table=<?= urlencode($selectedTable) ?>&tab=sql" class="tab-btn <?= $activeTab === 'sql' ? 'active' : '' ?>">
                            <span>📜</span> SQL DDL
                        </a>
                        <a href="?export=csv&table=<?= urlencode($selectedTable) ?>&q=<?= urlencode($search) ?>" class="tab-btn" style="color:var(--accent-emerald);">
                            <span>📥</span> CSV
                        </a>
                    </div>
                </div>

                <?php if ($isSelectedLegacyQA): ?>
                    <div style="margin:12px 24px 0;padding:10px 16px;background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.25);border-radius:8px;font-size:12.5px;color:#fde68a;display:flex;align-items:center;gap:10px;">
                        <span style="font-size:16px;">ℹ️</span>
                        <div>
                            <strong>Legacy QA Prototype Table (Unused):</strong> This table is from the old internal question builder prototype. Real courses, SCORM packages, and assessments are now served live via <strong>Moodle LMS</strong>.
                        </div>
                    </div>
                <?php elseif ($isSelectedActive): ?>
                    <div style="margin:12px 24px 0;padding:8px 16px;background:rgba(16,185,129,0.08);border:1px solid rgba(16,185,129,0.22);border-radius:8px;font-size:12.5px;color:#a7f3d0;display:flex;align-items:center;gap:10px;">
                        <span style="font-size:15px;">✅</span>
                        <div>
                            <strong>Active Live Table:</strong> Used directly by the Onboarding portal & Moodle LMS sync for real-time progress.
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Action / Search Bar (Only for Data Tab) -->
                <?php if ($activeTab === 'data'): ?>
                    <div class="action-bar">
                        <form method="GET" class="filter-form">
                            <input type="hidden" name="mode" value="<?= htmlspecialchars($mode) ?>">
                            <input type="hidden" name="table" value="<?= htmlspecialchars($selectedTable) ?>">
                            <input type="hidden" name="tab" value="data">
                            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" class="search-input" placeholder="Search within this table...">
                            <button type="submit" class="page-btn">Search</button>
                            <?php if ($search !== ''): ?>
                                <a href="?mode=<?= $mode ?>&table=<?= urlencode($selectedTable) ?>&tab=data" class="page-btn" style="text-decoration:none;">Clear</a>
                            <?php endif; ?>
                        </form>

                        <div style="font-size:12px;color:var(--text-muted);display:flex;align-items:center;gap:12px;">
                            <span>Showing page <?= $page ?> of <?= $totalPages ?> (Limit: <?= $perPage ?>)</span>
                            <form method="GET" style="display:inline-block;">
                                <input type="hidden" name="mode" value="<?= htmlspecialchars($mode) ?>">
                                <input type="hidden" name="table" value="<?= htmlspecialchars($selectedTable) ?>">
                                <input type="hidden" name="tab" value="data">
                                <input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>">
                                <select name="limit" onchange="this.form.submit()" style="background:var(--bg-card);color:#fff;border:1px solid var(--border-color);border-radius:4px;padding:3px 6px;font-size:12px;">
                                    <option value="25" <?= $perPage == 25 ? 'selected' : '' ?>>25 / page</option>
                                    <option value="50" <?= $perPage == 50 ? 'selected' : '' ?>>50 / page</option>
                                    <option value="100" <?= $perPage == 100 ? 'selected' : '' ?>>100 / page</option>
                                </select>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- View Container -->
                <div class="table-view-container">

                    <!-- TAB 1: BROWSE DATA -->
                    <?php if ($activeTab === 'data'): ?>
                        <?php if (empty($tableData)): ?>
                            <div class="empty-state">
                                <h3>No Records Found</h3>
                                <p><?= $search !== '' ? 'No rows match your query: "'.htmlspecialchars($search).'"' : 'This table is currently empty.' ?></p>
                            </div>
                        <?php else: ?>
                            <div style="overflow-x:auto;">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <?php foreach ($tableColumns as $col): ?>
                                                <th>
                                                    <?= htmlspecialchars($col['COLUMN_NAME']) ?>
                                                    <?php if ($col['COLUMN_KEY'] === 'PRI'): ?>
                                                        <span class="badge-pri">PRI</span>
                                                    <?php elseif ($col['COLUMN_KEY'] === 'MUL'): ?>
                                                        <span class="badge-mul">FK</span>
                                                    <?php elseif ($col['COLUMN_KEY'] === 'UNI'): ?>
                                                        <span class="badge-uni">UNI</span>
                                                    <?php endif; ?>
                                                </th>
                                            <?php endforeach; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($tableData as $row): ?>
                                            <tr>
                                                <?php foreach ($tableColumns as $col): 
                                                    $cName = $col['COLUMN_NAME'];
                                                    $val = $row[$cName] ?? null;
                                                    $isSecret = preg_match('/password|token|secret|salt/i', $cName);
                                                ?>
                                                    <td>
                                                        <?php if ($val === null): ?>
                                                            <span class="badge-null">NULL</span>
                                                        <?php elseif ($isSecret): ?>
                                                            <span style="color:var(--text-faint);letter-spacing:2px;font-family:var(--font-mono);">&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;</span>
                                                        <?php elseif (strlen($val) > 200): ?>
                                                            <span title="<?= htmlspecialchars($val) ?>"><?= htmlspecialchars(substr($val, 0, 195)) ?>&hellip;</span>
                                                        <?php elseif (is_numeric($val) && ($col['COLUMN_KEY'] === 'PRI' || substr($cName, -3) === '_id' || $cName === 'uid')): ?>
                                                            <strong style="color:var(--accent-cyan);font-family:var(--font-mono);"><?= htmlspecialchars($val) ?></strong>
                                                        <?php else: ?>
                                                            <?= htmlspecialchars($val) ?>
                                                        <?php endif; ?>
                                                    </td>
                                                <?php endforeach; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>

                    <!-- TAB 2: SCHEMA & STRUCTURE -->
                    <?php elseif ($activeTab === 'schema'): ?>
                        <div style="overflow-x:auto;">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Column</th>
                                        <th>Data Type</th>
                                        <th>Nullable</th>
                                        <th>Key</th>
                                        <th>Default Value</th>
                                        <th>Extra</th>
                                        <th>Comment</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($tableColumns as $col): ?>
                                        <tr>
                                            <td>
                                                <strong style="font-family:var(--font-mono);color:#fff;"><?= htmlspecialchars($col['COLUMN_NAME']) ?></strong>
                                            </td>
                                            <td>
                                                <span class="badge-type"><?= htmlspecialchars($col['COLUMN_TYPE']) ?></span>
                                            </td>
                                            <td>
                                                <?= $col['IS_NULLABLE'] === 'YES' ? '<span style="color:var(--text-faint)">YES</span>' : '<span style="color:var(--accent-emerald);font-weight:600;">NO</span>' ?>
                                            </td>
                                            <td>
                                                <?php if ($col['COLUMN_KEY'] === 'PRI'): ?>
                                                    <span class="badge-pri">PRIMARY KEY</span>
                                                <?php elseif ($col['COLUMN_KEY'] === 'MUL'): ?>
                                                    <span class="badge-mul">INDEX (MUL)</span>
                                                <?php elseif ($col['COLUMN_KEY'] === 'UNI'): ?>
                                                    <span class="badge-uni">UNIQUE</span>
                                                <?php else: ?>
                                                    <span class="badge-null">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?= $col['COLUMN_DEFAULT'] === null ? '<span class="badge-null">NULL</span>' : htmlspecialchars($col['COLUMN_DEFAULT']) ?>
                                            </td>
                                            <td>
                                                <?= htmlspecialchars($col['EXTRA'] ?: '-') ?>
                                            </td>
                                            <td>
                                                <span style="color:var(--text-muted);font-size:12px;"><?= htmlspecialchars($col['COLUMN_COMMENT'] ?: '-') ?></span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                    <!-- TAB 3: SQL CREATE STATEMENT -->
                    <?php elseif ($activeTab === 'sql'): ?>
                        <div class="sql-box" id="sqlBox">
                            <button class="copy-btn" onclick="copySql()">Copy SQL</button>
                            <?= htmlspecialchars($createTableSql) ?>
                        </div>
                    <?php endif; ?>

                </div>

                <!-- Pagination Footer (Only for Data Tab) -->
                <?php if ($activeTab === 'data' && $totalPages > 1): ?>
                    <footer class="pagination-bar">
                        <div>
                            Showing <?= number_format($offset + 1) ?> - <?= number_format(min($totalRows, $offset + $perPage)) ?> of <?= number_format($totalRows) ?> total
                        </div>
                        <div class="page-controls">
                            <a href="?mode=<?= $mode ?>&table=<?= urlencode($selectedTable) ?>&tab=data&p=1&q=<?= urlencode($search) ?>&limit=<?= $perPage ?>" class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>">&laquo; First</a>
                            <a href="?mode=<?= $mode ?>&table=<?= urlencode($selectedTable) ?>&tab=data&p=<?= $page - 1 ?>&q=<?= urlencode($search) ?>&limit=<?= $perPage ?>" class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>">&lsaquo; Prev</a>
                            <span style="padding: 0 8px; font-weight:600; color:#fff;">Page <?= $page ?> / <?= $totalPages ?></span>
                            <a href="?mode=<?= $mode ?>&table=<?= urlencode($selectedTable) ?>&tab=data&p=<?= $page + 1 ?>&q=<?= urlencode($search) ?>&limit=<?= $perPage ?>" class="page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>">Next &rsaquo;</a>
                            <a href="?mode=<?= $mode ?>&table=<?= urlencode($selectedTable) ?>&tab=data&p=<?= $totalPages ?>&q=<?= urlencode($search) ?>&limit=<?= $perPage ?>" class="page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>">Last &raquo;</a>
                        </div>
                    </footer>
                <?php endif; ?>

            <?php else: ?>
                <div class="empty-state">
                    <h3>No Table Selected</h3>
                    <p>Select a table from the sidebar on the left to inspect its schema and data.</p>
                </div>
            <?php endif; ?>

        </main>
    </div>

    <!-- Client-side Fast Table Filter & Copy Script -->
    <script>
        document.getElementById('tableFilterInput')?.addEventListener('input', function(e) {
            const query = e.target.value.toLowerCase().trim();
            const items = document.querySelectorAll('#tableListUl li');
            items.forEach(li => {
                const link = li.querySelector('.table-item-link');
                const name = link?.getAttribute('data-table-name') || '';
                if (name.toLowerCase().includes(query)) {
                    li.style.display = '';
                } else {
                    li.style.display = 'none';
                }
            });
        });

        function copySql() {
            const box = document.getElementById('sqlBox');
            if (box) {
                const text = box.innerText.replace('Copy SQL', '').trim();
                navigator.clipboard.writeText(text).then(() => {
                    const btn = box.querySelector('.copy-btn');
                    if (btn) {
                        btn.innerText = 'Copied!';
                        setTimeout(() => btn.innerText = 'Copy SQL', 2000);
                    }
                });
            }
        }
    </script>
</body>
</html>
