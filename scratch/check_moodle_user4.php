<?php
require_once "d:/xaamp/htdocs/onboard/api/db.php";
require_once "d:/xaamp/htdocs/onboard/api/moodle/client.php";

echo "=== CHECKING ONBOARD_MOODLE_USERS ===\n";
$mRes = $conn->query("SELECT * FROM onboard_moodle_users");
$moodleUserId = null;
while ($row = $mRes->fetch_assoc()) {
    echo json_encode($row) . "\n";
    if ((int)$row['hexa_uid'] === 205) {
        $moodleUserId = (int)$row['moodle_user_id'];
    }
}

echo "=== CHECKING ONBOARD_MODULE_PROGRESS FOR 205 ===\n";
$pRes = $conn->query("SELECT * FROM onboard_module_progress WHERE user_id = 205");
while ($row = $pRes->fetch_assoc()) {
    echo json_encode($row) . "\n";
}

if (!$moodleUserId) {
    echo "No moodle user id found for 205!\n";
    exit;
}

echo "=== INSPECTING MOODLE DATA FOR USER $moodleUserId ===\n";

$res = callMoodleWs('core_enrol_get_users_courses', ['userid' => $moodleUserId], $conn);
if (!empty($res['error'])) {
    echo "Error: " . $res['message'] . "\n";
    exit;
}

foreach ($res as $c) {
    echo "\n-----------------------------------------\n";
    echo "Course: {$c['fullname']} (ID: {$c['id']})\n";
    
    // Check scorms
    $scRes = callMoodleWs('mod_scorm_get_scorms_by_courses', ['courseids' => [$c['id']]], $conn);
    if (!empty($scRes['scorms'])) {
        foreach ($scRes['scorms'] as $sc) {
            echo "  SCORM id: {$sc['id']}, name: {$sc['name']}\n";
            
            // Scoes
            $scoesRes = callMoodleWs('mod_scorm_get_scorm_scoes', ['scormid' => $sc['id']], $conn);
            $targetScoId = $sc['id'];
            if (!empty($scoesRes['scoes'])) {
                foreach ($scoesRes['scoes'] as $sco) {
                    echo "    SCO: id={$sco['id']}, title={$sco['title']}, launch={$sco['launch']}, scormtype={$sco['scormtype']}\n";
                    if (($sco['scormtype'] ?? '') === 'sco' || !empty($sco['launch'])) {
                        $targetScoId = (int)$sco['id'];
                    }
                }
            }
            echo "  Target SCO ID used: $targetScoId\n";
            
            // Attempts
            $attRes = callMoodleWs('mod_scorm_get_scorm_attempt_count', ['scormid' => $sc['id'], 'userid' => $moodleUserId], $conn);
            echo "  Attempts: " . json_encode($attRes) . "\n";
            
            // Tracks for targetScoId
            $trRes = callMoodleWs('mod_scorm_get_scorm_sco_tracks', ['scoid' => $targetScoId, 'userid' => $moodleUserId, 'attempt' => 1], $conn);
            $tracks = $trRes['data']['tracks'] ?? [];
            echo "  Tracks count for sco $targetScoId: " . count($tracks) . "\n";
            foreach ($tracks as $t) {
                echo "    {$t['element']} = {$t['value']}\n";
            }
            
            // Check if attempt 2 exists
            $trRes2 = callMoodleWs('mod_scorm_get_scorm_sco_tracks', ['scoid' => $targetScoId, 'userid' => $moodleUserId, 'attempt' => 2], $conn);
            $tracks2 = $trRes2['data']['tracks'] ?? [];
            if (!empty($tracks2)) {
                echo "  Tracks count for attempt 2: " . count($tracks2) . "\n";
                foreach ($tracks2 as $t) {
                    echo "    [Att 2] {$t['element']} = {$t['value']}\n";
                }
            }
        }
    }
}
