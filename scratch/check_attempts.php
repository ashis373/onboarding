<?php
$conn = new mysqli('127.0.0.1', 'root', '', '', 3306);
if ($conn->connect_error) {
    echo "Connection failed: " . $conn->connect_error . PHP_EOL;
    exit;
}
$res = $conn->query("SHOW DATABASES");
while ($r = $res->fetch_row()) {
    echo $r[0] . PHP_EOL;
}
