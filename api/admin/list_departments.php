<?php
// Endpoint: api/admin/list_departments.php
// Returns list of valid departments that actually have active company employees

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

$NEED_PDO = false;
require_once __DIR__ . '/../db.php';

if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { 
    echo json_encode(['success' => false, 'message' => 'Not logged in']); 
    exit; 
}

session_write_close();

$depts = [];
$sql = "
    SELECT DISTINCT 
        d.id, 
        d.name, 
        COUNT(u.uid) AS employee_count 
    FROM department d 
    INNER JOIN user u ON u.department = d.id 
    WHERE (IFNULL(u.status, 0) = 0 OR u.status = 'online') 
    GROUP BY d.id, d.name 
    ORDER BY d.name ASC
";

if ($res = $conn->query($sql)) {
    while ($row = $res->fetch_assoc()) { 
        $depts[] = [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'employee_count' => (int)$row['employee_count']
        ]; 
    }
}

echo json_encode(['success' => true, 'departments' => $depts], JSON_UNESCAPED_SLASHES);
$conn->close();
?>