<?php
require_once 'd:/xaamp/htdocs/onboard/api/db.php';
$res = $conn->query("SELECT uid, username, firstname, lastname, status FROM user WHERE username = 'ashisr'");
$u = $res->fetch_assoc();
print_r($u);

$resMap = $conn->query("SELECT * FROM onboard_moodle_users WHERE hexa_uid = " . (int)$u['uid']);
print_r($resMap->fetch_assoc());
