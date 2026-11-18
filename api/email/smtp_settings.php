<?php
// Endpoint: api/email/smtp_settings.php
// GET: Retrieve SMTP Configuration
// POST: Save/Update SMTP Configuration

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

$NEED_PDO = false;
require_once __DIR__ . '/../db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentUserId = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$currentUserId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

// 1. Verify Admin Role
$isAdmin = false;
if (in_array((string)$currentUserId, ['26', '64', '205'], true)) {
    $isAdmin = true;
} else {
    $roleCheck = $conn->prepare("SELECT user_role FROM user WHERE uid = ?");
    if ($roleCheck) {
        $roleCheck->bind_param('i', $currentUserId);
        $roleCheck->execute();
        $roleRes = $roleCheck->get_result()->fetch_assoc();
        if (($roleRes['user_role'] ?? 0) == 1) {
            $isAdmin = true;
        }
        $roleCheck->close();
    }
}

if (!$isAdmin) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized: Admin access required.']);
    exit;
}

session_write_close();

// Ensure table exists safely with Zoho defaults
$conn->query("CREATE TABLE IF NOT EXISTS `onboard_smtp_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `host` varchar(255) NOT NULL DEFAULT 'smtp.zoho.com',
  `port` int(11) NOT NULL DEFAULT 465,
  `encryption` enum('tls','ssl','none') NOT NULL DEFAULT 'ssl',
  `username` varchar(255) NOT NULL DEFAULT 'contact-us@hexalearn.com',
  `password` varchar(255) NOT NULL DEFAULT 'XplTdnq@m12x',
  `from_name` varchar(255) NOT NULL DEFAULT 'Hexalearn',
  `from_email` varchar(255) NOT NULL DEFAULT 'contact-us@hexalearn.com',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

// Seed or update to corporate Zoho credentials
$conn->query("
    INSERT INTO `onboard_smtp_settings` (`id`, `host`, `port`, `username`, `password`, `encryption`, `from_name`, `from_email`, `is_active`)
    VALUES (1, 'smtp.zoho.com', 465, 'contact-us@hexalearn.com', 'XplTdnq@m12x', 'ssl', 'Hexalearn', 'contact-us@hexalearn.com', 1)
    ON DUPLICATE KEY UPDATE 
        `host` = 'smtp.zoho.com',
        `port` = 465,
        `username` = 'contact-us@hexalearn.com',
        `password` = 'XplTdnq@m12x',
        `encryption` = 'ssl',
        `from_name` = 'Hexalearn',
        `from_email` = 'contact-us@hexalearn.com',
        `is_active` = 1
");

$method = $_SERVER['REQUEST_METHOD'];

// Discover table columns dynamically
$smtpCols = [];
$cRes = $conn->query("SHOW COLUMNS FROM `onboard_smtp_settings`");
if ($cRes) {
    while ($c = $cRes->fetch_assoc()) $smtpCols[] = strtolower($c['Field']);
}
$hasHostCol = in_array('host', $smtpCols, true);
$hasUsernameCol = in_array('username', $smtpCols, true);
$hasPasswordCol = in_array('password', $smtpCols, true);
$hasEncryptionCol = in_array('encryption', $smtpCols, true);

if ($method === 'GET') {
    $res = $conn->query("SELECT * FROM `onboard_smtp_settings` ORDER BY id DESC LIMIT 1");
    $settings = $res ? $res->fetch_assoc() : null;

    $host = (!empty($settings['host']) && $settings['host'] !== 'smtp.gmail.com') ? $settings['host'] : 'smtp.zoho.com';
    $port = intval($settings['port'] ?? 465) ?: 465;
    $user = (!empty($settings['username']) && $settings['username'] !== 'hexadmin') ? $settings['username'] : 'contact-us@hexalearn.com';
    $pass = !empty($settings['password']) ? $settings['password'] : 'XplTdnq@m12x';
    $crypto = !empty($settings['encryption']) ? $settings['encryption'] : 'ssl';
    $fromName = !empty($settings['from_name']) ? $settings['from_name'] : 'Hexalearn';
    $fromEmail = !empty($settings['from_email']) ? $settings['from_email'] : 'contact-us@hexalearn.com';
    $hasPassword = !empty($pass);

    echo json_encode([
        'success' => true,
        'is_configured' => true,
        'settings' => [
            'id' => (int)($settings['id'] ?? 1),
            'host' => $host,
            'smtp_host' => $host,
            'port' => $port,
            'smtp_port' => $port,
            'username' => $user,
            'smtp_user' => $user,
            'password' => $pass,
            'encryption' => $crypto,
            'smtp_crypto' => $crypto,
            'from_name' => $fromName,
            'from_email' => $fromEmail,
            'has_password' => $hasPassword,
            'is_active' => true,
            'updated_at' => $settings['updated_at'] ?? date('Y-m-d H:i:s')
        ]
    ]);
    exit;
}

if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true) ?: $_POST;

    $host = trim($body['host'] ?? $body['smtp_host'] ?? '');
    $port = intval($body['port'] ?? $body['smtp_port'] ?? 587);
    $user = trim($body['username'] ?? $body['smtp_user'] ?? '');
    $pass = $body['password'] ?? $body['smtp_pass'] ?? '';
    $crypto = strtolower(trim($body['encryption'] ?? $body['smtp_crypto'] ?? 'tls'));
    $fromName = trim($body['from_name'] ?? 'HexaLearn Onboarding');
    $fromEmail = trim($body['from_email'] ?? $user);

    if (empty($host)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'SMTP Host is required.']);
        exit;
    }

    if ($port <= 0 || $port > 65535) {
        $port = 587;
    }

    // Check existing configuration
    $res = $conn->query("SELECT * FROM `onboard_smtp_settings` ORDER BY id DESC LIMIT 1");
    $existing = $res ? $res->fetch_assoc() : null;

    if ($existing) {
        $id = (int)$existing['id'];
        if (trim($pass) === '') {
            $pass = $existing['password'] ?? $existing['smtp_pass'] ?? '';
        }

        if ($hasHostCol) {
            $stmt = $conn->prepare("
                UPDATE `onboard_smtp_settings` 
                SET `host` = ?, 
                    `port` = ?, 
                    `username` = ?, 
                    `password` = ?, 
                    `encryption` = ?, 
                    `from_name` = ?, 
                    `from_email` = ?,
                    `is_active` = 1,
                    `updated_at` = NOW()
                WHERE `id` = ?
            ");
            if ($stmt) {
                $stmt->bind_param("sisssssi", $host, $port, $user, $pass, $crypto, $fromName, $fromEmail, $id);
                $stmt->execute();
                $stmt->close();
            }
        } else {
            $stmt = $conn->prepare("
                UPDATE `onboard_smtp_settings` 
                SET `smtp_host` = ?, 
                    `smtp_port` = ?, 
                    `smtp_user` = ?, 
                    `smtp_pass` = ?, 
                    `smtp_crypto` = ?, 
                    `from_name` = ?, 
                    `from_email` = ?,
                    `is_active` = 1,
                    `updated_at` = NOW()
                WHERE `id` = ?
            ");
            if ($stmt) {
                $stmt->bind_param("sisssssi", $host, $port, $user, $pass, $crypto, $fromName, $fromEmail, $id);
                $stmt->execute();
                $stmt->close();
            }
        }
    } else {
        if ($hasHostCol) {
            $stmt = $conn->prepare("
                INSERT INTO `onboard_smtp_settings` 
                (`host`, `port`, `username`, `password`, `encryption`, `from_name`, `from_email`, `is_active`)
                VALUES (?, ?, ?, ?, ?, ?, ?, 1)
            ");
            if ($stmt) {
                $stmt->bind_param("sisssss", $host, $port, $user, $pass, $crypto, $fromName, $fromEmail);
                $stmt->execute();
                $stmt->close();
            }
        } else {
            $stmt = $conn->prepare("
                INSERT INTO `onboard_smtp_settings` 
                (`smtp_host`, `smtp_port`, `smtp_user`, `smtp_pass`, `smtp_crypto`, `from_name`, `from_email`, `is_active`)
                VALUES (?, ?, ?, ?, ?, ?, ?, 1)
            ");
            if ($stmt) {
                $stmt->bind_param("sisssss", $host, $port, $user, $pass, $crypto, $fromName, $fromEmail);
                $stmt->execute();
                $stmt->close();
            }
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'SMTP Configuration saved successfully!',
        'settings' => [
            'host' => $host,
            'smtp_host' => $host,
            'port' => $port,
            'smtp_port' => $port,
            'username' => $user,
            'smtp_user' => $user,
            'encryption' => $crypto,
            'smtp_crypto' => $crypto,
            'from_name' => $fromName,
            'from_email' => $fromEmail,
            'has_password' => !empty($pass),
            'is_active' => true
        ]
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed.']);

