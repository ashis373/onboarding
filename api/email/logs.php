<?php
/**
 * api/email/logs.php
 * Fetches email delivery logs with pagination, search, and filtering.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

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

// Ensure email logs table exists
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
  KEY `idx_recipient_uid` (`recipient_uid`),
  KEY `idx_status` (`status`),
  KEY `idx_sent_at` (`sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
    // Action to clear logs if requested
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? '';
    if ($action === 'clear') {
        $conn->query("TRUNCATE TABLE `onboard_email_logs`");
        echo json_encode(['success' => true, 'message' => 'Email logs cleared successfully.']);
        exit;
    }
}

// GET Logs
$page = max(1, intval($_GET['page'] ?? 1));
$limit = max(1, min(100, intval($_GET['limit'] ?? 20)));
$offset = ($page - 1) * $limit;
$search = trim($_GET['q'] ?? '');
$statusFilter = trim($_GET['status'] ?? 'all'); // 'all', 'sent', 'failed'
$viewMode = trim($_GET['view'] ?? 'unique'); // 'unique' (1 line per recipient) or 'all' (dispatch history)

$whereConditions = [];
$params = [];
$paramTypes = "";

if ($statusFilter === 'sent') {
    $whereConditions[] = "l.status = 'sent'";
} elseif ($statusFilter === 'failed') {
    $whereConditions[] = "l.status = 'failed'";
}

if ($search !== '') {
    $whereConditions[] = "(l.recipient_name LIKE ? OR l.recipient_email LIKE ? OR l.subject LIKE ?)";
    $like = "%" . $search . "%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $paramTypes .= "sss";
}

$whereClause = count($whereConditions) > 0 ? "WHERE " . implode(" AND ", $whereConditions) : "";

$joinUnique = "";
if ($viewMode === 'unique') {
    $joinUnique = "INNER JOIN (SELECT MAX(id) AS max_id FROM onboard_email_logs GROUP BY recipient_email) uq ON uq.max_id = l.id";
}

// Count total matching
$countSql = "SELECT COUNT(*) AS total FROM onboard_email_logs l $joinUnique $whereClause";
$countStmt = $conn->prepare($countSql);
if ($countStmt) {
    if (!empty($params)) {
        $countStmt->bind_param($paramTypes, ...$params);
    }
    $countStmt->execute();
    $totalCount = intval($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $countStmt->close();
} else {
    $totalCount = 0;
}

// Overall summary stats (Total sent, Total failed)
$statsRes = $conn->query("
    SELECT 
        COUNT(*) AS total_all,
        SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS total_sent,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS total_failed
    FROM onboard_email_logs
");
$stats = [
    'total' => 0,
    'sent' => 0,
    'failed' => 0
];
if ($statsRes && $row = $statsRes->fetch_assoc()) {
    $stats['total'] = intval($row['total_all'] ?? 0);
    $stats['sent'] = intval($row['total_sent'] ?? 0);
    $stats['failed'] = intval($row['total_failed'] ?? 0);
}

// Fetch page logs
$logsSql = "
    SELECT 
        l.id,
        l.recipient_uid,
        l.recipient_name,
        l.recipient_email,
        l.subject,
        l.body_snippet,
        l.status,
        l.error_message,
        l.sent_by_uid,
        l.sent_at,
        u.firstname AS sender_firstname,
        u.lastname AS sender_lastname
    FROM onboard_email_logs l
    $joinUnique
    LEFT JOIN user u ON u.uid = l.sent_by_uid
    $whereClause
    ORDER BY l.sent_at DESC, l.id DESC
    LIMIT ? OFFSET ?
";

$fetchStmt = $conn->prepare($logsSql);
$logs = [];

if ($fetchStmt) {
    $execParams = $params;
    $execParamTypes = $paramTypes . "ii";
    $execParams[] = $limit;
    $execParams[] = $offset;

    $fetchStmt->bind_param($execParamTypes, ...$execParams);
    $fetchStmt->execute();
    $res = $fetchStmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $senderName = trim(($row['sender_firstname'] ?? '') . ' ' . ($row['sender_lastname'] ?? ''));
        if ($senderName === '') {
            $senderName = $row['sent_by_uid'] ? ('Admin #' . $row['sent_by_uid']) : 'System';
        }

        $sentTs = strtotime($row['sent_at']);
        $formattedDate = $sentTs ? date('d-M-Y h:i A', $sentTs) : $row['sent_at'];

        $logs[] = [
            'id' => intval($row['id']),
            'recipient_uid' => $row['recipient_uid'] ? intval($row['recipient_uid']) : null,
            'recipient_name' => $row['recipient_name'],
            'recipient_email' => $row['recipient_email'],
            'subject' => $row['subject'],
            'body_snippet' => $row['body_snippet'],
            'status' => $row['status'],
            'error_message' => $row['error_message'],
            'sender_name' => $senderName,
            'sent_at' => $row['sent_at'],
            'sent_at_formatted' => $formattedDate,
        ];
    }
    $fetchStmt->close();
}

$totalPages = $limit > 0 ? max(1, (int)ceil($totalCount / $limit)) : 1;

echo json_encode([
    'success' => true,
    'logs' => $logs,
    'pagination' => [
        'page' => $page,
        'limit' => $limit,
        'total' => $totalCount,
        'total_pages' => $totalPages
    ],
    'stats' => $stats
], JSON_UNESCAPED_UNICODE);

