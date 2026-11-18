require_once __DIR__ . '/../api/db.php';

// 1. Check if column exists
$checkCol = $conn->query("SHOW COLUMNS FROM onboard_modules LIKE 'total_slides'");
if ($checkCol && $checkCol->num_rows === 0) {
    $alter = $conn->query("ALTER TABLE onboard_modules ADD COLUMN total_slides INT(11) NOT NULL DEFAULT 10 AFTER duration_minutes");
    if ($alter) {
        echo "SUCCESS: Added total_slides column to onboard_modules.\n";
    } else {
        echo "ERROR: Could not add total_slides column: " . $conn->error . "\n";
    }
} else {
    echo "INFO: total_slides column already exists in onboard_modules.\n";
}

// 2. Display all current modules in onboard_modules
$res = $conn->query("SELECT id, title, moodle_course_id, moodle_cmid, total_slides FROM onboard_modules");
if ($res) {
    echo "\nCurrent Modules in onboard_modules:\n";
    while ($row = $res->fetch_assoc()) {
        echo "- ID: {$row['id']} | Moodle CID: {$row['moodle_course_id']} | CMID: {$row['moodle_cmid']} | Total Slides: {$row['total_slides']} | Title: {$row['title']}\n";
    }
}

$conn->close();
?>
