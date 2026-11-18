<?php
// Endpoint: api/admin/export_reports.php
// Exports comprehensive Onboarding Employee Progress Report as a downloadable CSV

$NEED_PDO = false;
require_once __DIR__ . '/../db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentUserId = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$currentUserId) {
    http_response_code(401);
    die('Not authenticated.');
}

// Admin check
$isAdmin = false;
if (in_array((string)$currentUserId, ['26', '64', '205'], true)) {
    $isAdmin = true;
} else {
    $roleCheck = $conn->prepare("SELECT user_role FROM user WHERE uid = ?");
    if ($roleCheck) {
        $roleCheck->bind_param('i', $currentUserId);
        $roleCheck->execute();
        $roleRes = $roleCheck->get_result()->fetch_assoc();
        if (($roleRes['user_role'] ?? 0) == 1) $isAdmin = true;
        $roleCheck->close();
    }
}

if (!$isAdmin) {
    http_response_code(403);
    die('Unauthorized: Admin access required.');
}

$filename = "HexaOne_Onboarding_Report_" . date('Y-m-d_His') . ".csv";

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

// UTF-8 BOM for Excel compatibility
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// CSV Header
fputcsv($output, [
    'UID',
    'Employee Name',
    'Email',
    'Joining Date',
    'Completed Modules',
    'Total Modules',
    'Completion %',
    'Status',
    'Last Activity'
]);

// Fetch total modules count
$totalModules = 0;
$mRes = $conn->query("SELECT COUNT(*) as cnt FROM onboard_modules WHERE is_active = 1 AND moodle_course_id IS NOT NULL AND moodle_course_id > 0");
if ($mRes) {
    $totalModules = (int)($mRes->fetch_assoc()['cnt'] ?? 0);
}

// Fetch enrolled employees with their completed modules count
$sql = "
    SELECT m.hexa_uid AS uid, 
           COALESCE(TRIM(CONCAT(u.firstname, ' ', u.lastname)), m.username, CONCAT('Employee #', m.hexa_uid)) AS full_name,
           u.firstname, u.lastname, u.personal_email, u.joiningdate,
           COUNT(DISTINCT CASE WHEN (p.is_completed = 1 OR p.status = 'completed') AND p.status NOT IN ('incomplete', 'in_progress', 'suspend', 'browsed') THEN p.module_id END) as completed_count,
           MAX(COALESCE(p.completed_at, p.last_synced_at, p.started_at)) as last_activity
    FROM onboard_moodle_users m
    LEFT JOIN user u ON u.uid = m.hexa_uid
    LEFT JOIN onboard_module_progress p ON p.user_id = m.hexa_uid
    GROUP BY m.hexa_uid
    ORDER BY m.id ASC
";

$res = $conn->query($sql);
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $comp = (int)$r['completed_count'];
        $pct = $totalModules > 0 ? round(($comp / $totalModules) * 100) : 0;
        $pct = min(100, $pct);

        $status = 'Not Started';
        if ($pct >= 100) {
            $status = 'Completed';
        } elseif ($pct > 0) {
            $status = 'In Progress';
        }

        fputcsv($output, [
            $r['uid'],
            trim(($r['firstname'] ?? '') . ' ' . ($r['lastname'] ?? '')),
            $r['personal_email'] ?? '',
            $r['joiningdate'] ?? '',
            $comp,
            $totalModules,
            $pct . '%',
            $status,
            $r['last_activity'] ?? 'No activity'
        ]);
    }
}

fclose($output);
$conn->close();
exit;
?>
