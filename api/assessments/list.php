<?php
header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
$NEED_MYSQLI = false;
require_once '../db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

// Release session lock early to prevent blocking concurrent API requests
session_write_close();

// Check how many modules the user has completed
$completed_modules_count = 0;
try {
    $cntModStmt = $pdo->prepare('SELECT COUNT(*) AS c FROM onboard_module_progress WHERE user_id = ? AND is_completed = 1');
    $cntModStmt->execute([$user_id]);
    $completed_modules_count = intval($cntModStmt->fetch()['c'] ?? 0);
} catch (Throwable $_e) {
    $completed_modules_count = 0;
}
$all_modules_completed = ($completed_modules_count >= 10);

$hasPos = false;
$hasRequiresAll = false;
try {
    $cols = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'onboard_assessments'")->fetchAll();
    foreach ($cols as $c) { 
        $cName = strtolower($c['COLUMN_NAME']);
        if ($cName === 'position') { $hasPos = true; } 
        if ($cName === 'requires_all_modules') { $hasRequiresAll = true; }
    }
} catch (Throwable $_e) { 
    $hasPos = false; 
    $hasRequiresAll = false;
}

$reqSelect = $hasRequiresAll ? ', requires_all_modules' : ', 1 AS requires_all_modules';
$posSelect = $hasPos ? ', position' : '';

$rows = [];
try {
    $select = 'SELECT id, title, description, time_limit_minutes, attempt_limit, passing_score, randomize_questions, is_active' . $reqSelect . $posSelect . ', created_at, updated_at FROM onboard_assessments ';
    $order = $hasPos ? 'ORDER BY COALESCE(position, 999999), id ASC' : 'ORDER BY id ASC';
    $stmt = $pdo->query($select . $order);
    $rows = $stmt->fetchAll();
} catch (Throwable $_e) {
    $rows = [];
}

// Attach pools summary and computed question counts
foreach ($rows as &$row) {
    $row['questions'] = 0;
    $row['pools'] = [];
    try {
        $pstmt = $pdo->prepare('SELECT ap.pool_id, ap.questions_to_draw, ap.position, p.name FROM onboard_assessment_pools ap JOIN onboard_pools p ON p.id = ap.pool_id WHERE ap.assessment_id = ? ORDER BY ap.position');
        $pstmt->execute([$row['id']]);
        $pools = $pstmt->fetchAll();
        foreach ($pools as $p) { $row['questions'] += intval($p['questions_to_draw']); }
        $row['pools'] = $pools;
    } catch (Throwable $_e) {}

    // If no questions from pools, default to 10
    if ($row['questions'] === 0) {
        $row['questions'] = 10;
    }

    // attempts summary for this user (only count submitted attempts)
    try {
        $astmt = $pdo->prepare('SELECT COUNT(*) AS attempts, MAX(score) AS best_score FROM onboard_assessment_attempts WHERE assessment_id = ? AND user_id = ? AND submitted_at IS NOT NULL');
        $astmt->execute([$row['id'], $user_id]);
        $a = $astmt->fetch();
        $row['attempts'] = intval($a['attempts'] ?? 0);
        $row['best_score'] = isset($a['best_score']) ? intval($a['best_score']) : null;
    } catch (Throwable $_e) {
        $row['attempts'] = 0;
        $row['best_score'] = null;
    }

    // Gate final assessment: locked unless all 10 modules completed
    $req = !empty($row['requires_all_modules']);
    $row['is_locked'] = ($req && !$all_modules_completed);
    $row['completed_modules_count'] = $completed_modules_count;
    $row['total_modules_required'] = 10;

    // in-progress attempt info (draft attempt not yet submitted)
    try {
        $ip = $pdo->prepare('SELECT id, started_at FROM onboard_assessment_attempts WHERE assessment_id = ? AND user_id = ? AND submitted_at IS NULL ORDER BY id DESC LIMIT 1');
        $ip->execute([$row['id'], $user_id]);
        if ($prog = $ip->fetch()) {
            $row['attempt_id'] = intval($prog['id']);
            $row['started_at'] = $prog['started_at'];
            $row['in_progress'] = true;
        } else {
            $row['in_progress'] = false;
        }
    } catch (Throwable $_e) {
        $row['in_progress'] = false;
    }
}

echo json_encode(['success' => true, 'assessments' => $rows, 'all_modules_completed' => $all_modules_completed]);
?>
