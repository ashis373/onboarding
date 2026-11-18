<?php
header("Content-Type: text/plain");
require_once __DIR__ . '/../api/db.php';

$file = $argv[1] ?? 'install/buddy_system.sql';
if (!file_exists($file)) {
    die("File not found: $file\n");
}

$sql = file_get_contents($file);
if ($conn->multi_query($sql)) {
    do {
        if ($res = $conn->store_result()) {
            $res->free();
        }
    } while ($conn->next_result());
    echo "SQL applied successfully: $file\n";
} else {
    echo "Error: " . $conn->error . "\n";
}
$conn->close();
?>
