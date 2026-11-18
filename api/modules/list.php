<?php
header("Content-Type: application/json; charset=UTF-8");
$NEED_PDO = false;
require_once '../db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { echo json_encode(['success' => false, 'message' => 'Not logged in']); exit; }

// Detect optional columns safely
$hasPrereq = false;
$hasSlides = false;
$colCheck = $conn->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'onboard_modules'");
if ($colCheck) {
    while ($c = $colCheck->fetch_assoc()) {
        $colName = strtolower($c['COLUMN_NAME']);
        if ($colName === 'prerequisite_module_id') $hasPrereq = true;
        if ($colName === 'total_slides') $hasSlides = true;
    }
}

$prereqSelect = $hasPrereq ? "m.prerequisite_module_id," : "NULL AS prerequisite_module_id,";
$slidesSelect = $hasSlides ? "COALESCE(m.total_slides, 5) AS total_slides," : "5 AS total_slides,";

$modules = [];
$sql = "SELECT m.id, m.title, m.type, m.description, m.duration_minutes, m.video_url, m.file_url,
               $prereqSelect
               $slidesSelect
               IFNULL(p.is_completed, 0) AS is_completed,
               p.started_at, p.last_viewed_at, p.progress_pct, p.time_spent_seconds
        FROM onboard_modules m
        LEFT JOIN onboard_module_progress p ON p.module_id = m.id AND p.user_id = ?
        WHERE m.is_active = 1
        ORDER BY m.id ASC";

if ($stmt = $conn->prepare($sql)) {
  $stmt->bind_param('i', $user_id);
  if ($stmt->execute()) {
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) { 
      $modules[] = $row; 
    }
  }
  $stmt->close();
}

// Compute sequential unlocking:
// Module 1 is always unlocked.
// Modules 2-10 unlock when the previous module has been completed.
$completed_map = [];
foreach ($modules as $m) {
    if (!empty($m['is_completed'])) {
        $completed_map[(int)$m['id']] = true;
    }
}

for ($i = 0; $i < count($modules); $i++) {
    $mId = (int)$modules[$i]['id'];
    $isComp = !empty($modules[$i]['is_completed']);
    if ($i === 0) {
        $modules[$i]['is_locked'] = false;
    } else {
        $prereqId = !empty($modules[$i]['prerequisite_module_id']) 
            ? (int)$modules[$i]['prerequisite_module_id'] 
            : (int)$modules[$i - 1]['id'];
        
        $prereqDone = !empty($completed_map[$prereqId]);
        $modules[$i]['is_locked'] = (!$prereqDone && !$isComp);
    }
}

echo json_encode(['success' => true, 'modules' => $modules]);
$conn->close();
?>
