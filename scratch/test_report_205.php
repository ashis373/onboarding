<?php
session_start();
$_SESSION['role'] = 'admin';
$_SESSION['user_id'] = 1;
$_SESSION['role_id'] = 1;
$_GET['uid'] = 205;
ob_start();
include __DIR__ . '/../api/admin/employee_progress.php';
$output = ob_get_clean();
$data = json_decode($output, true);
echo "Success: " . var_export($data['success'] ?? false, true) . PHP_EOL;
echo "Employee: " . ($data['employee']['name'] ?? '') . PHP_EOL;
if (!empty($data['modules'])) {
    foreach ($data['modules'] as $m) {
        echo "Module: " . $m['title'] . " | Status: " . $m['status'] . " | Time: " . $m['time_spent'] . " | Slides: " . ($m['current_slide'] ?? 'N/A') . "/" . ($m['total_slides'] ?? 'N/A') . " | Started: " . ($m['first_started_at'] ?? 'N/A') . " | Last: " . ($m['last_played_at'] ?? 'N/A') . PHP_EOL;
    }
}
