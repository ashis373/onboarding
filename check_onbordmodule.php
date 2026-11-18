<?php
// If opened in browser without ?format=json, serve the graphical DB Explorer UI
if (php_sapi_name() !== 'cli' && (!isset($_GET['format']) || $_GET['format'] !== 'json')) {
    require_once __DIR__ . '/tables.php';
    exit;
}

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/api/db.php';

try {
    $dbResult = $conn->query("SELECT DATABASE()");
    $database = $dbResult->fetch_row()[0] ?? null;

    $query = $conn->query("
        SELECT
            TABLE_NAME,
            COLUMN_NAME,
            COLUMN_TYPE,
            IS_NULLABLE,
            COLUMN_KEY,
            COLUMN_DEFAULT,
            EXTRA
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME LIKE 'onboard_%'
        ORDER BY TABLE_NAME, ORDINAL_POSITION
    ");

    $tables = [];

    while ($row = $query->fetch_assoc()) {
        $tables[$row['TABLE_NAME']][] = [
            'column' => $row['COLUMN_NAME'],
            'type' => $row['COLUMN_TYPE'],
            'nullable' => $row['IS_NULLABLE'],
            'key' => $row['COLUMN_KEY'],
            'default' => $row['COLUMN_DEFAULT'],
            'extra' => $row['EXTRA']
        ];
    }

    $jsonOutput = json_encode([
        'database' => $database,
        'total_onboard_tables' => count($tables),
        'tables' => $tables
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    // Automatically save schema output to file
    $schemaFile = __DIR__ . '/install/allschema.md';
    @file_put_contents($schemaFile, $jsonOutput);

    echo $jsonOutput;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Database inspection failed.'
    ], JSON_PRETTY_PRINT);
}