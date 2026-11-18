<?php
require_once "d:/xaamp/htdocs/onboard/api/db.php";
require_once "d:/xaamp/htdocs/onboard/api/moodle/client.php";

$userId = 7;
echo "=== CHECKING core_course_get_contents for Course 2 ===\n";

$contents = callMoodleWs('core_course_get_contents', ['courseid' => 2], $conn);
foreach ($contents as $sec) {
    echo "Section: {$sec['name']}\n";
    foreach ($sec['modules'] as $m) {
        echo "  Mod: {$m['name']}, modname: {$m['modname']}, id(cmid): {$m['id']}, instance: {$m['instance']}\n";
        echo "  completiondata: " . json_encode($m['completiondata'] ?? null) . "\n";
    }
}
