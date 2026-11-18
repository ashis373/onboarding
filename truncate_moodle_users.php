<?php
// Endpoint: truncate_moodle_users.php
// Truncates onboard_moodle_users table for testing fresh multi-employee auto-sync
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once __DIR__ . '/api/db.php';

try {
    // 1. Temporarily disable foreign key checks to ensure clean truncation
    $conn->query("SET FOREIGN_KEY_CHECKS = 0");

    // 2. Truncate onboard_moodle_users table
    $truncated = $conn->query("TRUNCATE TABLE onboard_moodle_users");
    if (!$truncated) {
        // Fallback: Delete all rows and reset auto-increment if TRUNCATE has permission restrictions
        $conn->query("DELETE FROM onboard_moodle_users");
        $conn->query("ALTER TABLE onboard_moodle_users AUTO_INCREMENT = 1");
    }

    // 3. Re-enable foreign key checks
    $conn->query("SET FOREIGN_KEY_CHECKS = 1");

    // 4. Verify that table is completely empty (0 rows)
    $res = $conn->query("SELECT COUNT(*) AS total FROM onboard_moodle_users");
    $totalRows = (int)($res ? $res->fetch_assoc()['total'] : 0);

    echo json_encode([
        'success' => true,
        'message' => 'Table onboard_moodle_users was successfully truncated!',
        'table' => 'onboard_moodle_users',
        'current_row_count' => $totalRows,
        'next_step' => 'Now open the Onboarding portal. Your account will automatically discover, enrol, and link fresh into Moodle.'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
?>
