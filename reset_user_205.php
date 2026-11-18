<?php
// Endpoint: reset_user_205.php
// Resets all onboarding progress, sessions, assessment attempts, and Moodle user link for Employee 205 (or specified ?uid=)
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once __DIR__ . '/api/db.php';

try {
    // Target employee ID: defaults to 205, or allows ?uid=X
    $targetUid = isset($_GET['uid']) ? (int)$_GET['uid'] : 205;

    if ($targetUid <= 0) {
        throw new Exception("Invalid employee UID provided.");
    }

    $results = [];

    // 1. Reset all module progress for employee
    $stmt1 = $conn->prepare("DELETE FROM onboard_module_progress WHERE user_id = ?");
    if ($stmt1) {
        $stmt1->bind_param("i", $targetUid);
        $stmt1->execute();
        $results['deleted_module_progress'] = $stmt1->affected_rows;
        $stmt1->close();
    }

    // 2. Clear all recorded slide / timer sessions for employee
    $stmt2 = $conn->prepare("DELETE FROM onboard_module_sessions WHERE user_id = ?");
    if ($stmt2) {
        $stmt2->bind_param("i", $targetUid);
        $stmt2->execute();
        $results['deleted_module_sessions'] = $stmt2->affected_rows;
        $stmt2->close();
    }

    // 3. Clear all quiz / assessment attempt history for employee
    $stmt3 = $conn->prepare("DELETE FROM onboard_assessment_attempts WHERE user_id = ?");
    if ($stmt3) {
        $stmt3->bind_param("i", $targetUid);
        $stmt3->execute();
        $results['deleted_assessment_attempts'] = $stmt3->affected_rows;
        $stmt3->close();
    }

    // 4. Delete the Moodle user link for employee (Allows clean re-sync with fresh Moodle user)
    $stmt4 = $conn->prepare("DELETE FROM onboard_moodle_users WHERE hexa_uid = ?");
    if ($stmt4) {
        $stmt4->bind_param("i", $targetUid);
        $stmt4->execute();
        $results['deleted_moodle_user_links'] = $stmt4->affected_rows;
        $stmt4->close();
    }

    echo json_encode([
        'success' => true,
        'message' => "Successfully reset all onboarding data for employee UID: {$targetUid}",
        'employee_uid' => $targetUid,
        'summary' => $results,
        'next_steps' => [
            '1. You can now delete/re-create the test user in Moodle if needed.',
            '2. Log in as employee ' . $targetUid . ' into the Onboarding portal.',
            '3. All modules will start fresh at 0% (Ready / Not Started).'
        ]
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
