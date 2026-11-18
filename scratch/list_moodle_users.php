<?php
require_once "d:/xaamp/htdocs/onboard/api/db.php";
require_once "d:/xaamp/htdocs/onboard/api/moodle/client.php";

echo "=== SEARCHING MOODLE USERS ===\n";
$users = callMoodleWs('core_user_get_users', [
    'criteria' => [
        ['key' => 'email', 'value' => '%']
    ]
], $conn);

if (!empty($users['users'])) {
    foreach ($users['users'] as $u) {
        echo "ID: {$u['id']}, Username: {$u['username']}, Name: {$u['fullname']}, Email: {$u['email']}\n";
        $c = callMoodleWs('core_enrol_get_users_courses', ['userid' => $u['id']], $conn);
        echo "  Enrolled courses: " . count($c) . "\n";
        foreach ($c as $course) {
            echo "    Course {$course['id']}: {$course['fullname']}\n";
        }
    }
} else {
    echo "No users found or error: " . json_encode($users) . "\n";
}
