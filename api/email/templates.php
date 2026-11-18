<?php
// Endpoint: api/email/templates.php
// GET: Retrieve Email Templates
// POST: Create, Update, or Delete Template

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

// Ensure templates table exists
$conn->query("CREATE TABLE IF NOT EXISTS `onboard_email_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `body_html` mediumtext NOT NULL,
  `description` varchar(255) DEFAULT '',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

// Update Enrolled Reminder template (Pure clean paragraphs, no CSS, no buttons, no colors)
$conn->query("
    UPDATE `onboard_email_templates`
    SET 
        `name` = 'Staff Enrolled: Complete Your Course by Friday',
        `subject` = 'Important: Complete Your HexaLearn Onboarding Course by Friday',
        `body_html` = '<p>Dear {{employee_name}},</p>

<p>Modules 7, 8, 9, and 10 have been newly added to your HexaLearn Onboarding course.</p>

<p>Please log in to the onboarding portal, access your course, and complete all pending modules, including the newly added modules, by Friday (final deadline).</p>

<p>Kindly ensure that all assigned training activities and assessments are completed before the deadline.</p>

<p>Important Notice: If you do not complete all assigned modules by Friday, your access to the onboarding portal will be locked. If you require access after the deadline, please contact your supervisor or the administrator for assistance.</p>

<p>Thank you for your cooperation.</p>

<p>HexaLearn Onboarding Team</p>',
        `description` = 'Sent to active enrolled trainees to complete new modules (7, 8, 9, 10) and pending courses by Friday.',
        `is_active` = 1
    WHERE `slug` = 'friday_reminder' OR `id` = 1
");

// Add / Update Not Enrolled Pending Reminder template (Pure clean paragraphs, no CSS, no buttons, no colors)
$conn->query("
    INSERT INTO `onboard_email_templates` (`name`, `slug`, `subject`, `body_html`, `description`, `is_active`)
    VALUES (
        'Staff Not Enrolled: Course Enrollment Friday Deadline',
        'not_enrolled_reminder',
        'Reminder: HexaLearn Onboarding Course Enrollment – Friday Deadline',
        '<p>Dear {{employee_name}},</p>

<p>You have not yet been enrolled in the HexaLearn Onboarding course.</p>

<p>Please complete your onboarding enrollment and all assigned training courses by Friday (final deadline).</p>

<p>Kindly take the necessary action to ensure your onboarding training is completed within the given timeframe.</p>

<p>Important Notice: If you do not complete all assigned modules by Friday, your access to the onboarding portal will be locked. If you require access after the deadline, please contact your supervisor or the administrator for assistance.</p>

<p>Thank you for your cooperation.</p>

<p>HexaLearn Onboarding Team</p>',
        'Sent to unlinked / not yet enrolled employees to complete onboarding enrollment by Friday.',
        1
    )
    ON DUPLICATE KEY UPDATE 
        `name` = VALUES(`name`),
        `subject` = VALUES(`subject`),
        `body_html` = VALUES(`body_html`),
        `description` = VALUES(`description`),
        `is_active` = 1
");

// Mark legacy unused templates inactive so they do not confuse the admin
$conn->query("
    UPDATE `onboard_email_templates`
    SET `is_active` = 0
    WHERE `slug` IN ('incomplete_progress', 'welcome_onboarding')
");

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $rows = [];
    $res = $conn->query("SELECT * FROM `onboard_email_templates` WHERE is_active = 1 ORDER BY id ASC");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $name = $r['name'] ?? $r['title'] ?? '';
            $bodyHtml = $r['body_html'] ?? $r['body'] ?? '';
            $slug = $r['slug'] ?? $r['template_type'] ?? ('tmpl_' . $r['id']);
            $desc = $r['description'] ?? '';

            $rows[] = [
                'id' => (int)$r['id'],
                'name' => $name,
                'title' => $name,
                'slug' => $slug,
                'template_type' => $slug,
                'subject' => $r['subject'] ?? '',
                'body_html' => $bodyHtml,
                'body' => $bodyHtml,
                'description' => $desc,
                'is_active' => intval($r['is_active'] ?? 1),
                'updated_at' => $r['updated_at'] ?? ''
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'templates' => $rows
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true) ?: $_POST;

    $action = $body['action'] ?? 'save';
    $id = intval($body['id'] ?? 0);

    if ($action === 'delete') {
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Template ID required.']);
            exit;
        }
        $del = $conn->prepare("DELETE FROM `onboard_email_templates` WHERE id = ?");
        if ($del) {
            $del->bind_param("i", $id);
            $del->execute();
            $del->close();
        }
        echo json_encode(['success' => true, 'message' => 'Template deleted successfully.']);
        exit;
    }

    $name = trim($body['name'] ?? $body['title'] ?? '');
    $slug = trim($body['slug'] ?? $body['template_type'] ?? ('custom_' . time()));
    $subject = trim($body['subject'] ?? '');
    $bodyHtml = trim($body['body_html'] ?? $body['body'] ?? '');
    $description = trim($body['description'] ?? '');

    if (empty($name) || empty($subject) || empty($bodyHtml)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Name, Subject, and Email Body are required.']);
        exit;
    }

    // Inspect columns of onboard_email_templates to support both schemas
    $cols = [];
    $cRes = $conn->query("SHOW COLUMNS FROM `onboard_email_templates`");
    if ($cRes) {
        while ($c = $cRes->fetch_assoc()) $cols[] = strtolower($c['Field']);
    }

    $hasName = in_array('name', $cols, true);
    $hasBodyHtml = in_array('body_html', $cols, true);

    if ($id > 0) {
        if ($hasName && $hasBodyHtml) {
            $stmt = $conn->prepare("
                UPDATE `onboard_email_templates` 
                SET `name` = ?, `slug` = ?, `subject` = ?, `body_html` = ?, `description` = ?, `updated_at` = NOW() 
                WHERE `id` = ?
            ");
            if ($stmt) {
                $stmt->bind_param("sssssi", $name, $slug, $subject, $bodyHtml, $description, $id);
                $stmt->execute();
                $stmt->close();
            }
        } else {
            $stmt = $conn->prepare("
                UPDATE `onboard_email_templates` 
                SET `title` = ?, `subject` = ?, `body` = ?, `updated_at` = NOW() 
                WHERE `id` = ?
            ");
            if ($stmt) {
                $stmt->bind_param("sssi", $name, $subject, $bodyHtml, $id);
                $stmt->execute();
                $stmt->close();
            }
        }
        echo json_encode(['success' => true, 'message' => 'Template updated successfully!']);
    } else {
        if ($hasName && $hasBodyHtml) {
            $stmt = $conn->prepare("
                INSERT INTO `onboard_email_templates` 
                (`name`, `slug`, `subject`, `body_html`, `description`, `is_active`) 
                VALUES (?, ?, ?, ?, ?, 1)
            ");
            if ($stmt) {
                $stmt->bind_param("sssss", $name, $slug, $subject, $bodyHtml, $description);
                $stmt->execute();
                $newId = $stmt->insert_id;
                $stmt->close();
                echo json_encode(['success' => true, 'id' => $newId, 'message' => 'Template created successfully!']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to save template: ' . $conn->error]);
            }
        } else {
            $stmt = $conn->prepare("
                INSERT INTO `onboard_email_templates` 
                (`title`, `subject`, `body`, `template_type`, `is_default`) 
                VALUES (?, ?, ?, ?, 0)
            ");
            if ($stmt) {
                $stmt->bind_param("ssss", $name, $subject, $bodyHtml, $slug);
                $stmt->execute();
                $newId = $stmt->insert_id;
                $stmt->close();
                echo json_encode(['success' => true, 'id' => $newId, 'message' => 'Template created successfully!']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to save template: ' . $conn->error]);
            }
        }
    }
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
