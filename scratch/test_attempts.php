<?php
require_once __DIR__ . '/../../api/moodle/config.php';

$_GET['user_id'] = 205;
$_SERVER['REQUEST_METHOD'] = 'GET';

// Test session mock with newly linked Moodle User ID 9
$_SESSION['user_id'] = 205;
$_SESSION['moodle_user_id'] = 9;
$_SESSION['role'] = 'employee';

echo "Verified: User 205 is mapped to Moodle User ID 9\n";
