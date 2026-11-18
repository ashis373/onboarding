<?php
header('Content-Type: application/json');

require_once __DIR__ . '/api/db.php';

$databases = ['timesheet', 'timesheet_app'];
$result = [];

foreach ($databases as $db) {
    if ($conn->select_db($db)) {
        $query = $conn->query("SHOW TABLES");
        $result[$db] = [];

        if ($query) {
            while ($row = $query->fetch_row()) {
                $result[$db][] = $row[0];
            }
        }
    } else {
        $result[$db] = 'Unavailable or no permission';
    }
}

echo json_encode($result, JSON_PRETTY_PRINT);
?>