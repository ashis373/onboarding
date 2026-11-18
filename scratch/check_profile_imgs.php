<?php
require_once __DIR__ . '/../api/db.php';
$res = $conn->query("SELECT uid, username, firstname, lastname, profile_img, ofc_mail FROM user WHERE uid IN (26, 64, 182, 205) OR profile_img IS NOT NULL AND profile_img != '' LIMIT 10");
while ($row = $res->fetch_assoc()) {
    echo sprintf("UID: %-4s | User: %-12s | Img: %s\n", $row['uid'], $row['username'], $row['profile_img'] ?? 'NULL');
}
