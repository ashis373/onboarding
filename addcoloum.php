<?php
/**
 * Migration script: Add total_slides column to onboard_modules
 * Accessible via browser (http://localhost/onboard/addcoloum.php) or CLI
 */

header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/api/db.php';

if (!isset($conn) || $conn->connect_error) {
    die("Database connection failed: " . ($conn->connect_error ?? "Unknown error"));
}

echo "=== MIGRATION: ADD total_slides COLUMN ===\n\n";

// 1. Check if column already exists
$checkSql = "SHOW COLUMNS FROM `onboard_modules` LIKE 'total_slides'";
$checkRes = $conn->query($checkSql);

if ($checkRes && $checkRes->num_rows > 0) {
    echo "[INFO] The column `total_slides` already exists in `onboard_modules` table.\n";
} else {
    // 2. Add the column
    $alterSql = "ALTER TABLE `onboard_modules` ADD COLUMN `total_slides` INT(11) NOT NULL DEFAULT 10 AFTER `duration_minutes`";
    if ($conn->query($alterSql) === TRUE) {
        echo "[SUCCESS] Successfully added `total_slides` INT(11) column to `onboard_modules`.\n";
    } else {
        echo "[ERROR] Failed to add column: " . $conn->error . "\n";
    }
}

// 3. Display current structure & records
echo "\n--- Current Columns in `onboard_modules` ---\n";
$colRes = $conn->query("SHOW COLUMNS FROM `onboard_modules`");
if ($colRes) {
    while ($col = $colRes->fetch_assoc()) {
        echo "  - {$col['Field']} ({$col['Type']})\n";
    }
}

echo "\n--- Current Modules in `onboard_modules` ---\n";
$modRes = $conn->query("SELECT id, title, moodle_course_id, total_slides FROM `onboard_modules`");
if ($modRes) {
    while ($row = $modRes->fetch_assoc()) {
        echo "  - ID #{$row['id']} | Moodle Course ID: {$row['moodle_course_id']} | Total Slides: {$row['total_slides']} | Title: {$row['title']}\n";
    }
}

echo "\nMigration complete.\n";
$conn->close();
?>
