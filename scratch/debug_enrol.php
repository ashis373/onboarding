<?php
require_once "d:/xaamp/htdocs/onboard/api/db.php";
require_once "d:/xaamp/htdocs/onboard/api/moodle/client.php";

$userId = 4;
$courses = callMoodleWs('core_enrol_get_users_courses', ['userid' => $userId], $conn);
echo "Result of core_enrol_get_users_courses for $userId:\n";
var_dump($courses);
