<?php
require_once "d:/xaamp/htdocs/onboard/api/db.php";
require_once "d:/xaamp/htdocs/onboard/api/moodle/client.php";

$userId = 7;
echo "=== INSPECTING MOODLE USER $userId (Ashis Rout) ===\n";

$courses = callMoodleWs('core_enrol_get_users_courses', ['userid' => $userId], $conn);
foreach ($courses as $c) {
    echo "\nCourse {$c['id']}: {$c['fullname']}\n";
    $scRes = callMoodleWs('mod_scorm_get_scorms_by_courses', ['courseids' => [$c['id']]], $conn);
    if (!empty($scRes['scorms'])) {
        foreach ($scRes['scorms'] as $sc) {
            echo "  SCORM {$sc['id']}: {$sc['name']}\n";
            $scoesRes = callMoodleWs('mod_scorm_get_scorm_scoes', ['scormid' => $sc['id']], $conn);
            $targetScoId = $sc['id'];
            if (!empty($scoesRes['scoes'])) {
                foreach ($scoesRes['scoes'] as $sco) {
                    if (($sco['scormtype'] ?? '') === 'sco' || !empty($sco['launch'])) {
                        $targetScoId = (int)$sco['id'];
                    }
                }
            }
            
            $trRes = callMoodleWs('mod_scorm_get_scorm_sco_tracks', ['scoid' => $targetScoId, 'userid' => $userId, 'attempt' => 1], $conn);
            $tracks = $trRes['data']['tracks'] ?? [];
            echo "  Tracks (count: " . count($tracks) . "):\n";
            foreach ($tracks as $t) {
                echo "    {$t['element']} = {$t['value']}\n";
            }
        }
    }
}
