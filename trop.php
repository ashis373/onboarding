<?php
header('Content-Type: text/plain; charset=UTF-8');
require_once __DIR__ . '/api/db.php';

echo "=== Deleting mistakenly added user (anshuman / hexa_uid=165 / id=6) ===\n\n";

// 1. Delete from onboard_moodle_users
$delMoodleUser = $conn->query("DELETE FROM `onboard_moodle_users` WHERE `id` = 6 OR `hexa_uid` = 165 OR `username` = 'anshuman'");
echo "1. Deleted from onboard_moodle_users: " . ($delMoodleUser ? "SUCCESS (Affected rows: {$conn->affected_rows})" : $conn->error) . "\n";

// 2. Also clean up any module progress recorded for hexa_uid = 165
$progCheck = $conn->query("SHOW TABLES LIKE 'onboard_module_progress'");
if ($progCheck && $progCheck->num_rows > 0) {
    $delProg = $conn->query("DELETE FROM `onboard_module_progress` WHERE `user_id` = 165");
    echo "2. Deleted from onboard_module_progress: " . ($delProg ? "SUCCESS (Affected rows: {$conn->affected_rows})" : $conn->error) . "\n";
}

// 3. Display remaining users in onboard_moodle_users
echo "\n=== Remaining records in onboard_moodle_users ===\n";
$res = $conn->query("SELECT id, hexa_uid, username, moodle_user_id, sync_status FROM `onboard_moodle_users` ORDER BY id ASC");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        echo "ID: {$r['id']} | Hexa UID: {$r['hexa_uid']} | Username: {$r['username']} | Moodle ID: #{$r['moodle_user_id']} | Status: {$r['sync_status']}\n";
    }
}
echo "\nDone!\n";
