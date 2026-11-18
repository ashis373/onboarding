<?php
/**
 * Onboarding Users & Moodle Enrollment Inspector
 * Shows all employees currently enrolled in Onboarding and linked with Moodle
 */
header('Content-Type: text/html; charset=UTF-8');
require_once __DIR__ . '/api/db.php';

$res = $conn->query("
    SELECT 
        m.id AS map_id,
        m.hexa_uid,
        u.username AS hexa_username,
        TRIM(CONCAT(u.firstname, ' ', u.lastname)) AS full_name,
        u.ofc_mail,
        m.moodle_user_id,
        m.sync_status,
        m.last_synced_at,
        m.notes,
        (
            SELECT COUNT(*) 
            FROM onboard_module_progress p 
            WHERE p.user_id = m.hexa_uid AND (p.is_completed = 1 OR p.status = 'completed')
        ) AS completed_modules,
        (
            SELECT COUNT(*) 
            FROM onboard_modules mod_t 
            WHERE mod_t.is_active = 1 AND mod_t.moodle_course_id IS NOT NULL
        ) AS total_modules
    FROM onboard_moodle_users m
    LEFT JOIN user u ON u.uid = m.hexa_uid
    ORDER BY m.id ASC
");

$users = [];
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $users[] = $row;
    }
}

if (isset($_GET['format']) && $_GET['format'] === 'json') {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => true, 'total' => count($users), 'enrolled_users' => $users], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enrolled Onboarding Users (Moodle Linked)</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0b1120; color: #f1f5f9; padding: 24px; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 12px; padding: 20px; max-width: 1000px; margin: 0 auto; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.5); }
        h1 { margin: 0 0 8px; color: #38bdf8; font-size: 20px; font-weight: 800; display: flex; align-items: center; justify-content: space-between; }
        p { color: #94a3b8; font-size: 13px; margin: 0 0 16px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th { background: #0f172a; color: #cbd5e1; text-align: left; padding: 10px 12px; font-size: 11px; text-transform: uppercase; border-bottom: 2px solid #334155; }
        td { padding: 12px; border-bottom: 1px solid #334155; }
        tr:hover { background: rgba(56, 189, 248, 0.05); }
        .badge { display: inline-block; padding: 3px 8px; border-radius: 6px; font-size: 11px; font-weight: 700; }
        .badge-linked { background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3); }
        .badge-pending { background: rgba(245, 158, 11, 0.15); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.3); }
        .btn { background: #0284c7; color: #fff; text-decoration: none; padding: 6px 12px; border-radius: 6px; font-size: 12px; font-weight: bold; }
        .btn:hover { background: #0369a1; }
    </style>
</head>
<body>
    <div class="card">
        <h1>
            <span>Enrolled Onboarding Users (Moodle Linked)</span>
            <span style="font-size:13px;color:#94a3b8;font-weight:normal;">Total Enrolled: <strong style="color:#38bdf8;"><?= count($users) ?></strong></span>
        </h1>
        <p>Real-time records from <code>onboard_moodle_users</code> joined with HexaOne <code>user</code>.</p>
        
        <?php if (empty($users)): ?>
            <div style="padding: 30px; text-align: center; color: #94a3b8;">
                No employees currently in <code>onboard_moodle_users</code>.
            </div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Hexa UID</th>
                        <th>Employee</th>
                        <th>Email</th>
                        <th>Moodle User ID</th>
                        <th>Sync Status</th>
                        <th>Progress</th>
                        <th>Last Synced</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td><strong><?= (int)$u['hexa_uid'] ?></strong></td>
                            <td style="font-weight:700;color:#fff;"><?= htmlspecialchars($u['full_name'] ?: $u['hexa_username']) ?></td>
                            <td style="color:#94a3b8;"><?= htmlspecialchars($u['ofc_mail'] ?? '—') ?></td>
                            <td><span style="color:#38bdf8;font-weight:bold;">#<?= htmlspecialchars($u['moodle_user_id'] ?? 'Not set') ?></span></td>
                            <td>
                                <span class="badge <?= $u['sync_status'] === 'Linked' ? 'badge-linked' : 'badge-pending' ?>">
                                    <?= htmlspecialchars($u['sync_status'] ?? 'Not linked') ?>
                                </span>
                            </td>
                            <td>
                                <strong><?= (int)$u['completed_modules'] ?></strong> / <?= (int)$u['total_modules'] ?>
                            </td>
                            <td style="color:#94a3b8;font-size:11px;"><?= htmlspecialchars($u['last_synced_at'] ?? '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        
        <div style="margin-top:20px;display:flex;justify-content:space-between;align-items:center;">
            <a href="p.php?mode=active&table=onboard_moodle_users&tab=data" class="btn" style="background:#334155;">View in DB Explorer</a>
            <a href="onboard/admin/employees" class="btn" style="background:#00A389;">Open Admin User Tracking</a>
        </div>
    </div>
</body>
</html>
