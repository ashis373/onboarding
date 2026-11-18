<?php
/**
 * Check if a DB table exists. Returns true if it does.
 * Use: if (!tableExists($conn, 'onboard_groups')) { echo json_encode([...]); exit; }
 */
function tableExists($conn, $tableName) {
    $escaped = $conn->real_escape_string($tableName);
    $res = $conn->query("SHOW TABLES LIKE '$escaped'");
    return ($res && $res->num_rows > 0);
}
?>
