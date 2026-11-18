<?php
// Endpoint: api/email/smtp_test.php
// POST: Test SMTP Server Connection & Send Test Email

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

$NEED_PDO = false;
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/mailer.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentUserId = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$currentUserId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

// Verify Admin Role
$isAdmin = false;
if (in_array((string)$currentUserId, ['26', '64', '205'], true)) {
    $isAdmin = true;
} else {
    $roleCheck = $conn->prepare("SELECT user_role FROM user WHERE uid = ?");
    if ($roleCheck) {
        $roleCheck->bind_param('i', $currentUserId);
        $roleCheck->execute();
        $roleRes = $roleCheck->get_result()->fetch_assoc();
        if (($roleRes['user_role'] ?? 0) == 1) $isAdmin = true;
        $roleCheck->close();
    }
}

if (!$isAdmin) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized: Admin access required.']);
    exit;
}

session_write_close();

$raw = file_get_contents('php://input');
$body = json_decode($raw, true) ?: $_POST;

$host = trim($body['host'] ?? $body['smtp_host'] ?? '');
$port = intval($body['port'] ?? $body['smtp_port'] ?? 0);
$user = trim($body['username'] ?? $body['smtp_user'] ?? '');
$pass = $body['password'] ?? $body['smtp_pass'] ?? '';
$crypto = strtolower(trim($body['encryption'] ?? $body['smtp_crypto'] ?? 'tls'));
$fromName = trim($body['from_name'] ?? 'HexaLearn Onboarding');
$fromEmail = trim($body['from_email'] ?? '');
$testRecipient = trim($body['test_email'] ?? $body['test_recipient'] ?? '');

if (empty($host) || empty($user) || empty($pass)) {
    $res = $conn->query("SELECT * FROM `onboard_smtp_settings` ORDER BY id DESC LIMIT 1");
    if ($res && ($saved = $res->fetch_assoc())) {
        if (empty($host)) $host = $saved['host'] ?? $saved['smtp_host'] ?? '';
        if ($port <= 0) $port = (int)($saved['port'] ?? $saved['smtp_port'] ?? 587);
        if (empty($user)) $user = $saved['username'] ?? $saved['smtp_user'] ?? '';
        if (empty($pass)) $pass = $saved['password'] ?? $saved['smtp_pass'] ?? '';
        if (empty($crypto)) $crypto = $saved['encryption'] ?? $saved['smtp_crypto'] ?? 'tls';
        if (empty($fromName)) $fromName = $saved['from_name'] ?? 'HexaLearn Onboarding';
        if (empty($fromEmail)) $fromEmail = $saved['from_email'] ?? $user;
    }
}

if ($port <= 0) $port = 587;
if (empty($fromEmail)) $fromEmail = $user;
if (empty($testRecipient)) $testRecipient = $fromEmail;

if (empty($host)) {
    echo json_encode(['success' => false, 'message' => 'Please provide the SMTP Host.']);
    exit;
}

if (!filter_var($testRecipient, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Please provide a valid test recipient email address.']);
    exit;
}

$mailer = new SmtpMailer([
    'host' => $host,
    'port' => $port,
    'user' => $user,
    'pass' => $pass,
    'crypto' => $crypto,
    'from_email' => $fromEmail,
    'from_name' => $fromName,
    'timeout' => 12
]);

$subject = "HexaLearn Onboarding — SMTP Test Connection Successful";
$testDate = date('d-M-Y g:i:s A');
$htmlBody = "
<div style=\"font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 560px; margin: 0 auto; padding: 24px; border: 1px solid #e2e8f0; border-radius: 12px; background: #ffffff;\">
  <div style=\"text-align: center; margin-bottom: 20px;\">
    <div style=\"display: inline-block; width: 44px; height: 44px; line-height: 44px; border-radius: 50%; background: #E6F7F4; color: #00A389; font-size: 20px;\">✓</div>
    <h2 style=\"color: #0f172a; margin: 12px 0 4px; font-size: 20px;\">SMTP Connection Verified</h2>
    <p style=\"color: #64748b; font-size: 13px; margin: 0;\">HexaLearn Employee Onboarding Portal</p>
  </div>
  <p style=\"font-size: 14px; color: #334155; line-height: 1.6;\">
    Congratulations! Your outbound email configuration is working properly. The onboarding system can now dispatch automated deadline reminders, course notifications, and welcome emails directly to employees.
  </p>
  <div style=\"background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px; margin: 18px 0; font-size: 12px; color: #475569;\">
    <div><strong>SMTP Host:</strong> {$host}:{$port} ({$crypto})</div>
    <div style=\"margin-top: 4px;\"><strong>Authenticated As:</strong> {$user}</div>
    <div style=\"margin-top: 4px;\"><strong>From Header:</strong> {$fromName} &lt;{$fromEmail}&gt;</div>
    <div style=\"margin-top: 4px;\"><strong>Tested At:</strong> {$testDate} (Asia/Kolkata)</div>
  </div>
  <p style=\"font-size: 11px; color: #94a3b8; text-align: center; margin-top: 20px;\">
    This is an automated test message. No further action is required.
  </p>
</div>
";

$result = $mailer->send($testRecipient, 'Admin Tester', $subject, $htmlBody);

// Ensure logs table exists
$conn->query("CREATE TABLE IF NOT EXISTS `onboard_email_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `recipient_uid` int(11) DEFAULT NULL,
  `recipient_name` varchar(255) NOT NULL DEFAULT '',
  `recipient_email` varchar(255) NOT NULL DEFAULT '',
  `subject` varchar(255) NOT NULL DEFAULT '',
  `body_snippet` text DEFAULT NULL,
  `status` enum('sent','failed') NOT NULL DEFAULT 'sent',
  `error_message` text DEFAULT NULL,
  `sent_by_uid` int(11) DEFAULT NULL,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `recipient_uid` (`recipient_uid`),
  KEY `status` (`status`),
  KEY `sent_at` (`sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$status = $result['success'] ? 'sent' : 'failed';
$errMsg = $result['error'] ?? null;
$snip = "Test Connection email to {$testRecipient}";

$logStmt = $conn->prepare("
    INSERT INTO `onboard_email_logs` 
    (`recipient_uid`, `recipient_name`, `recipient_email`, `subject`, `body_snippet`, `status`, `error_message`, `sent_by_uid`)
    VALUES (?, 'Admin Tester', ?, ?, ?, ?, ?, ?)
");
if ($logStmt) {
    $logStmt->bind_param("isssssi", $currentUserId, $testRecipient, $subject, $snip, $status, $errMsg, $currentUserId);
    $logStmt->execute();
    $logStmt->close();
}

if ($result['success']) {
    echo json_encode([
        'success' => true,
        'message' => "Test email successfully delivered to {$testRecipient}!"
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => "SMTP Error: " . ($result['error'] ?? 'Connection failed.')
    ]);
}

