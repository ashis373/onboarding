<?php
// Script: run_all_migrations.php
// Applies all onboarding database migrations into the local or server MySQL database safely
header('Content-Type: text/plain; charset=UTF-8');

require_once __DIR__ . '/api/db.php';

$dbName = $conn->query("SELECT DATABASE()")->fetch_row()[0];
echo "Connected to database: $dbName\n\n";

// 1. Ensure required columns on onboard_assessment_attempts
$conn->query("ALTER TABLE onboard_assessment_attempts ADD COLUMN IF NOT EXISTS max_score INT DEFAULT 100");
$conn->query("ALTER TABLE onboard_assessment_attempts ADD COLUMN IF NOT EXISTS is_passed TINYINT(1) NULL");

// 2. Ensure required columns on onboard_modules
$conn->query("ALTER TABLE onboard_modules ADD COLUMN IF NOT EXISTS roadmap_day INT NOT NULL DEFAULT 1");
$conn->query("ALTER TABLE onboard_modules ADD COLUMN IF NOT EXISTS department_id INT DEFAULT 0");
$conn->query("ALTER TABLE onboard_modules ADD COLUMN IF NOT EXISTS designation_id INT DEFAULT 0");
$conn->query("ALTER TABLE onboard_modules ADD COLUMN IF NOT EXISTS video_url VARCHAR(512) NULL");
$conn->query("ALTER TABLE onboard_modules ADD COLUMN IF NOT EXISTS file_url VARCHAR(512) NULL");

$sqlFiles = [
    'install/schema.sql',
    'install/alter_010_moodle_local_sync.sql',
];

foreach ($sqlFiles as $file) {
    $fullPath = __DIR__ . '/' . $file;
    if (!file_exists($fullPath)) {
        echo "Skipping (not found): $file\n";
        continue;
    }
    echo "Running: $file ... ";
    $sqlContent = file_get_contents($fullPath);
    
    if ($conn->multi_query($sqlContent)) {
        do {
            if ($result = $conn->store_result()) {
                $result->free();
            }
        } while ($conn->more_results() && $conn->next_result());
        
        if ($conn->errno) {
            echo "Warning (" . $conn->errno . "): " . $conn->error . "\n";
        } else {
            echo "OK\n";
        }
    } else {
        echo "Error: " . $conn->error . "\n";
    }
}

// Clear any remaining results
while ($conn->more_results() && $conn->next_result()) {
    if ($result = $conn->store_result()) {
        $result->free();
    }
}

// Ensure onboard_settings exists
echo "Ensuring onboard_settings table exists ... ";
$conn->query("
    CREATE TABLE IF NOT EXISTS onboard_settings (
      `key` VARCHAR(191) PRIMARY KEY,
      `value` LONGTEXT,
      `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");
echo "OK\n";

// Populate default Moodle settings in onboard_settings
echo "Configuring Moodle WS token and SSO Secret in onboard_settings ... ";
$settings = [
    'moodle_ws_token' => '49221326100285c9e1c5dd4b1a33f5e1',
    'moodle_sso_secret' => '6724164445813902f62a0b22f9545058679c38ff352ea5e3a66e483a334aae22'
];
$stmt = $conn->prepare("INSERT INTO onboard_settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
foreach ($settings as $k => $v) {
    $stmt->bind_param("ss", $k, $v);
    $stmt->execute();
}
$stmt->close();
echo "OK\n";

// Link test employee 205 in onboard_moodle_users
echo "Ensuring initial user mapping in onboard_moodle_users ... ";
$conn->query("
    INSERT INTO onboard_moodle_users (hexa_uid, username, moodle_user_id, sync_status, notes)
    VALUES (205, 'ashisr', 4, 'Linked', 'Linked test employee Ashis Rout')
    ON DUPLICATE KEY UPDATE 
        moodle_user_id = 4, 
        sync_status = 'Linked', 
        notes = 'Linked test employee Ashis Rout',
        updated_at = CURRENT_TIMESTAMP
");
echo "OK\n";

echo "\n--- Summary of All onboard_% Tables in $dbName ---\n";
$res = $conn->query("
    SELECT TABLE_NAME, TABLE_TYPE 
    FROM INFORMATION_SCHEMA.TABLES 
    WHERE TABLE_SCHEMA = '$dbName' AND TABLE_NAME LIKE 'onboard_%' 
    ORDER BY TABLE_NAME
");
$count = 0;
while ($row = $res->fetch_assoc()) {
    $count++;
    echo sprintf("  %2d. %-35s [%s]\n", $count, $row['TABLE_NAME'], $row['TABLE_TYPE']);
}
echo "\nTotal onboard tables & views in $dbName: $count\n";
