<?php
require_once "d:/xaamp/htdocs/onboard/api/db.php";
require_once "d:/xaamp/htdocs/onboard/api/moodle/client.php";

$uRes = $conn->query("SELECT uid, username, status FROM user WHERE uid = 205");
$uRow = $uRes ? $uRes->fetch_assoc() : null;
echo "User 205 row: " . json_encode($uRow) . "\n";
if (!$uRow) {
    $anyUser = $conn->query("SELECT uid, username, status FROM user LIMIT 5")->fetch_all(MYSQLI_ASSOC);
    echo "Sample users: " . json_encode($anyUser) . "\n";
}


// Ensure onboard_moodle_users has moodle_user_id = 7
$conn->query("UPDATE onboard_moodle_users SET moodle_user_id = 7, sync_status = 'Linked' WHERE hexa_uid = 205");

ob_start();
include "d:/xaamp/htdocs/onboard/api/moodle/courses.php";
$json = ob_get_clean();

$data = json_decode($json, true);
echo "=== COURSES RETURNED BY COURSES.PHP ===\n";
if (!empty($data['courses'])) {
    foreach ($data['courses'] as $c) {
        echo "Course {$c['id']}: {$c['fullname']} (completed: " . ($c['completed_modules_count'] ?? 0) . "/" . ($c['total_modules_count'] ?? 0) . ")\n";
        foreach ($c['modules'] as $m) {
            echo "  Module: {$m['title']}\n";
            echo "    Status: {$m['status']}, is_completed: " . json_encode($m['is_completed']) . ", progress_percent: {$m['progress_percent']}, total_time: {$m['total_time']}\n";
        }
    }
} else {
    echo "Output: " . substr($json, 0, 500) . "\n";
}
