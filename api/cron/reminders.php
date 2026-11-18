<?php
/**
 * Automated Onboarding Reminders - COMPLETELY DISABLED
 * Per organization policy, all email notifications and reminders are disabled.
 */
header("Content-Type: application/json; charset=UTF-8");

echo json_encode([
    'success' => true,
    'message' => 'Email reminders are completely disabled.',
    'emails_sent' => 0
]);
exit;
