<?php
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/api/db.php';

try {
    // 1. Create table onboard_moodle_users with strict 1:1 unique constraints
    $createSql = "
    CREATE TABLE IF NOT EXISTS onboard_moodle_users (
      id INT AUTO_INCREMENT PRIMARY KEY,
      hexa_uid INT NOT NULL,
      username VARCHAR(100) NULL,
      moodle_user_id INT NULL,
      sync_status ENUM('Not linked', 'Pending verification', 'Linked', 'Error') DEFAULT 'Not linked',
      last_synced_at DATETIME NULL,
      notes VARCHAR(255) NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_hexa_uid (hexa_uid),
      UNIQUE KEY uq_moodle_user_id (moodle_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";

    if (!$conn->query($createSql)) {
        throw new Exception("Table creation failed: " . $conn->error);
    }

    // 2. Link HexaOne UID 205 (ashisr) to Moodle User ID 4
    $stmt = $conn->prepare("
        INSERT INTO onboard_moodle_users (hexa_uid, username, moodle_user_id, sync_status, notes)
        VALUES (205, 'ashisr', 4, 'Linked', 'Linked test employee')
        ON DUPLICATE KEY UPDATE 
            moodle_user_id = 4, 
            sync_status = 'Linked', 
            notes = 'Linked test employee',
            updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute();
    // 2.5 Save token to onboard_settings table for persistence
    $conn->query("
        INSERT INTO onboard_settings (`key`, `value`)
        VALUES ('moodle_ws_token', '49221326100285c9e1c5dd4b1a33f5e1')
        ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)
    ");

    // 3. Confirm record
    $res = $conn->query("SELECT * FROM onboard_moodle_users WHERE hexa_uid = 205");
    $row = $res->fetch_assoc();

    echo json_encode([
        'success' => true,
        'message' => 'HexaOne UID 205 successfully linked to Moodle User ID 4.',
        'employee_mapping' => $row
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
?>
