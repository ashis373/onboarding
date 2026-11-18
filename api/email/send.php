<?php
// Endpoint: api/email/send.php
// POST: Broadcast or send email notifications to employees

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

// Set execution time limit for batch email sending (up to 5 minutes for large batches)
set_time_limit(300);

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

$raw = file_get_contents('php://input');
$body = json_decode($raw, true) ?: $_POST;

$subjectRaw = trim($body['subject'] ?? '');
$bodyTemplate = trim($body['body_html'] ?? $body['body'] ?? '');
$selectedUids = $body['uids'] ?? [];
$customEmails = $body['custom_emails'] ?? [];
$deadlineDay = trim($body['deadline_day'] ?? '');

if (empty($deadlineDay)) {
    // Default to this Friday
    $fridayTs = strtotime('this friday');
    if ($fridayTs <= time()) {
        $fridayTs = strtotime('next friday');
    }
    $deadlineDay = "Friday, " . date('M j, Y', $fridayTs);
}

if (empty($subjectRaw)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Email Subject is required.']);
    exit;
}

if (empty($bodyTemplate)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Email Body content is required.']);
    exit;
}

// 1. Fetch active SMTP Configuration (Pre-configured corporate Zoho SMTP backend)
$defaultSmtp = [
    'host' => 'smtp.zoho.com',
    'port' => 465,
    'user' => 'contact-us@hexalearn.com',
    'pass' => 'XplTdnq@m12x',
    'crypto' => 'ssl',
    'from_email' => 'contact-us@hexalearn.com',
    'from_name' => 'Hexalearn'
];

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

// Ensure default working Zoho SMTP is set in DB
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

$smtpRes = $conn->query("SELECT * FROM `onboard_smtp_settings` WHERE is_active = 1 ORDER BY id DESC LIMIT 1");
$smtp = $smtpRes ? $smtpRes->fetch_assoc() : null;

$smtpHost = !empty($smtp['host']) && $smtp['host'] !== 'smtp.gmail.com' ? $smtp['host'] : (!empty($smtp['smtp_host']) ? $smtp['smtp_host'] : $defaultSmtp['host']);
$smtpPort = intval($smtp['port'] ?? $smtp['smtp_port'] ?? 465) ?: $defaultSmtp['port'];
$smtpUser = !empty($smtp['username']) && $smtp['username'] !== 'hexadmin' ? $smtp['username'] : (!empty($smtp['smtp_user']) ? $smtp['smtp_user'] : $defaultSmtp['user']);
$smtpPass = !empty($smtp['password']) ? $smtp['password'] : (!empty($smtp['smtp_pass']) ? $smtp['smtp_pass'] : $defaultSmtp['pass']);
$smtpCrypto = !empty($smtp['encryption']) ? $smtp['encryption'] : (!empty($smtp['smtp_crypto']) ? $smtp['smtp_crypto'] : $defaultSmtp['crypto']);
$fromEmail = !empty($smtp['from_email']) ? $smtp['from_email'] : $defaultSmtp['from_email'];
$fromName = !empty($smtp['from_name']) ? $smtp['from_name'] : $defaultSmtp['from_name'];

$mailer = new SmtpMailer([
    'host' => $smtpHost,
    'port' => $smtpPort,
    'user' => $smtpUser,
    'pass' => $smtpPass,
    'crypto' => $smtpCrypto ?: 'ssl',
    'from_email' => $fromEmail ?: 'contact-us@hexalearn.com',
    'from_name' => $fromName ?: 'Hexalearn',
    'timeout' => 15
]);

// 2. Discover available user columns
$userCols = [];
$cRes = $conn->query("SHOW COLUMNS FROM `user`");
if ($cRes) {
    while ($c = $cRes->fetch_assoc()) $userCols[] = strtolower($c['Field']);
}
$hasOfcMail = in_array('ofc_mail', $userCols, true);
$hasPersonalMail = in_array('personal_email', $userCols, true);
$ofcMailSelect = $hasOfcMail ? "u.ofc_mail" : "NULL AS ofc_mail";
$persMailSelect = $hasPersonalMail ? "u.personal_email" : "NULL AS personal_email";

// 3. Total active modules in onboarding curriculum
$totalModules = 4;
$mRes = $conn->query("
    SELECT COUNT(*) AS cnt 
    FROM `onboard_modules` 
    WHERE `is_active` = 1 
      AND `moodle_course_id` > 0
      AND (type != 'assessment' OR type IS NULL)
      AND LOWER(title) NOT LIKE '%assessment%'
      AND LOWER(title) NOT LIKE '%quiz%'
");
if ($mRes && ($mRow = $mRes->fetch_assoc())) {
    $cnt = intval($mRow['cnt']);
    if ($cnt > 0) $totalModules = $cnt;
}

// 4. Batch progress query
$dbProgressMap = [];
$pRes = $conn->query("
    SELECT p.user_id, COUNT(DISTINCT m.id) AS done_cnt
    FROM onboard_module_progress p
    INNER JOIN onboard_modules m ON (m.id = p.module_id OR (m.moodle_cmid > 0 AND m.moodle_cmid = p.module_id))
    WHERE (p.is_completed = 1 OR p.status = 'completed')
      AND p.status NOT IN ('incomplete', 'in_progress', 'suspend', 'browsed')
      AND m.is_active = 1
      AND m.moodle_course_id > 0
      AND (m.type != 'assessment' OR m.type IS NULL)
      AND LOWER(m.title) NOT LIKE '%assessment%'
      AND LOWER(m.title) NOT LIKE '%quiz%'
    GROUP BY p.user_id
");
if ($pRes) {
    while ($pRow = $pRes->fetch_assoc()) {
        $dbProgressMap[(int)$pRow['user_id']] = (int)$pRow['done_cnt'];
    }
}

// 5. Query candidate recipients
$whereExtra = "";
if (!empty($selectedUids) && is_array($selectedUids)) {
    $sanitizedUids = array_values(array_unique(array_filter(array_map('intval', $selectedUids))));
    if (!empty($sanitizedUids)) {
        $whereExtra = "AND u.uid IN (" . implode(',', $sanitizedUids) . ")";
    }
}

$sql = "
    SELECT 
        u.uid,
        u.firstname,
        u.lastname,
        $ofcMailSelect,
        $persMailSelect,
        m.id AS map_id,
        m.email AS moodle_email,
        m.moodle_user_id
    FROM user u
    LEFT JOIN onboard_moodle_users m ON m.hexa_uid = u.uid
    WHERE (IFNULL(u.status, 0) = 0 OR u.status = 'online')
    $whereExtra
    GROUP BY u.uid
";

// Official Moodle LMS roster mapping
$moodleEmails = [
    24 => 'abhilipsa@hexalearn.com', 27 => 'aishwarya.jena@hexalearn.com', 45 => 'akhila.das@hexalearn.co.in',
    40 => 'anil.sethi@hexalearn.com', 15 => 'anup.jena@hexalearn.co.in', 42 => 'arpita.behera@hexalearn.co.in',
    6  => 'arup.panda@hexalearn.com', 14 => 'ashis.rout@hexalearn.co.in', 33 => 'ashisb@hexalearn.com',
    23 => 'ashita@hexalearn.com', 38 => 'babita.naik@hexalearn.com', 47 => 'debiprasad.jaysingh@hexalearn.co.in',
    17 => 'dibyadarsan.panda@hexalearn.co.in', 35 => 'guduli.dakua@hexalearn.co.in', 31 => 'jayaprakash.routray@hexalearn.co.in',
    26 => 'kanha.bisoyi@hexalearn.co.in', 29 => 'laxmipriya.patra@hexalearn.co.in', 30 => 'pravat.rout@hexalearn.com',
    21 => 'priyaranjan.samantaray@hexalearn.com', 39 => 'purusottam.jayasingh@hexalearn.com', 19 => 'rajan.bisla@hexalearn.com',
    18 => 'ratnamanjari@hexalearn.com', 41 => 'rupali@hexalearn.com', 44 => 'sagnika@hexalearn.com',
    16 => 'sangram@hexalearn.com', 28 => 'santosh@hexalearn.com', 32 => 'saroj.das@hexalearn.co.in',
    36 => 'satyaranjan.rout@hexalearn.co.in', 46 => 'sipra@hexalearn.com', 25 => 'sonali.jena@hexalearn.co.in',
    22 => 'soumya.sahoo@hexalearn.com', 43 => 'subhasmita@hexalearn.com', 20 => 'sumandev@hexalearn.com',
    34 => 'suresh.rout@hexalearn.com'
];

$moodleUidEmails = [
    218 => 'abhilipsa@hexalearn.com', 213 => 'aishwarya.jena@hexalearn.com', 161 => 'akhila.das@hexalearn.co.in',
    80  => 'anil.sethi@hexalearn.com', 182 => 'anup.jena@hexalearn.co.in', 180 => 'arpita.behera@hexalearn.co.in',
    64  => 'arup.panda@hexalearn.com', 205 => 'ashis.rout@hexalearn.co.in', 192 => 'ashisb@hexalearn.com',
    190 => 'ashita@hexalearn.com', 115 => 'babita.naik@hexalearn.com',
    210 => 'debiprasad.jaysingh@hexalearn.co.in', 202 => 'dibyadarsan.panda@hexalearn.co.in', 203 => 'guduli.dakua@hexalearn.co.in',
    141 => 'jayaprakash.routray@hexalearn.co.in', 154 => 'kanha.bisoyi@hexalearn.co.in', 186 => 'laxmipriya.patra@hexalearn.co.in',
    77  => 'pravat.rout@hexalearn.com', 65  => 'priyaranjan.samantaray@hexalearn.com', 79  => 'purusottam.jayasingh@hexalearn.com',
    121 => 'rajan.bisla@hexalearn.com', 201 => 'ratnamanjari@hexalearn.com', 197 => 'rupali@hexalearn.com',
    222 => 'sagnika@hexalearn.com', 196 => 'sangram@hexalearn.com', 189 => 'santosh@hexalearn.com',
    110 => 'saroj.das@hexalearn.co.in', 211 => 'satyaranjan.rout@hexalearn.co.in', 216 => 'sipra@hexalearn.com',
    209 => 'sonali.jena@hexalearn.co.in', 72  => 'soumya.sahoo@hexalearn.com', 219 => 'subhasmita@hexalearn.com',
    195 => 'sumandev@hexalearn.com', 106 => 'suresh.rout@hexalearn.com'
];

$empRes = $conn->query($sql);
$recipients = [];
$seenUids = [];
$seenEmails = [];

if ($empRes) {
    while ($r = $empRes->fetch_assoc()) {
        $uid = (int)$r['uid'];
        
        // Strict Deduplication Step 1: Guarantee every employee UID is processed strictly ONCE
        if (isset($seenUids[$uid])) {
            continue;
        }
        $seenUids[$uid] = true;
        
        $isEnrolled = !empty($r['map_id']) || !empty($r['moodle_user_id']);
        $ofc = trim($r['ofc_mail'] ?? '');
        $moodleEmail = trim($r['moodle_email'] ?? '');
        $moodleId = !empty($r['moodle_user_id']) ? (int)$r['moodle_user_id'] : 0;

        $email = '';
        if (!empty($customEmails[$uid]) && filter_var(trim($customEmails[$uid]), FILTER_VALIDATE_EMAIL)) {
            // Temporary email provided by admin for this dispatch only - DO NOT update database
            $email = trim($customEmails[$uid]);
        } elseif ($isEnrolled) {
            if (!empty($ofc) && filter_var($ofc, FILTER_VALIDATE_EMAIL)) {
                $email = $ofc;
            } elseif (!empty($moodleEmail) && filter_var($moodleEmail, FILTER_VALIDATE_EMAIL)) {
                $email = $moodleEmail;
            } elseif ($moodleId > 0 && !empty($moodleEmails[$moodleId])) {
                $email = $moodleEmails[$moodleId];
            } elseif (!empty($moodleUidEmails[$uid])) {
                $email = $moodleUidEmails[$uid];
            }
        } else {
            if (!empty($ofc) && filter_var($ofc, FILTER_VALIDATE_EMAIL)) {
                $email = $ofc;
            }
        }

        if (empty($email)) {
            continue; // Exclude employees without office / Moodle email
        }

        // Strict Deduplication Step 2: Guarantee no email address receives more than 1 email in this batch
        $cleanEmail = strtolower(trim($email));
        if (isset($seenEmails[$cleanEmail])) {
            continue;
        }
        $seenEmails[$cleanEmail] = true;

        $fullName = trim(($r['firstname'] ?? '') . ' ' . ($r['lastname'] ?? ''));
        if (empty($fullName)) $fullName = "Employee #{$uid}";

        $completedMods = $dbProgressMap[$uid] ?? 0;
        if ($completedMods > $totalModules) $completedMods = $totalModules;

        $percent = ($isEnrolled && $totalModules > 0) ? min(100, (int)round(($completedMods / $totalModules) * 100)) : 0;

        $recipients[] = [
            'uid' => $uid,
            'name' => $fullName,
            'firstname' => $r['firstname'] ?? $fullName,
            'email' => $email,
            'percent' => $percent,
            'completed' => $completedMods,
            'total' => $totalModules,
            'pending' => max(0, $totalModules - $completedMods)
        ];
    }
}

if (empty($recipients)) {
    echo json_encode([
        'success' => false,
        'message' => 'No matching employees with valid email addresses found for this selection.'
    ]);
    exit;
}

// 6. Base portal URL for links
$portalUrl = "https://corhis.hexalearn.com/onboard/modules";

// 7. Dispatch emails in batch
$sentCount = 0;
$failedCount = 0;
$deliveryResults = [];

$logStmt = $conn->prepare("
    INSERT INTO `onboard_email_logs` 
    (`recipient_uid`, `recipient_name`, `recipient_email`, `subject`, `body_snippet`, `status`, `error_message`, `sent_by_uid`)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
");

foreach ($recipients as $recip) {
    // Replace smart tags (supports both single {tag} and double {{tag}})
    $searchTags = [
        '{{employee_name}}',
        '{employee_name}',
        '{{firstname}}',
        '{firstname}',
        '{{completion_percent}}',
        '{completion_percent}',
        '{{deadline_day}}',
        '{deadline_day}',
        '{{onboard_url}}',
        '{onboard_url}',
        '{pending_modules_count}',
        '{total_modules_count}',
        '{completed_modules}',
        '{total_modules}'
    ];
    $replaceValues = [
        htmlspecialchars($recip['name'], ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($recip['name'], ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($recip['firstname'], ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($recip['firstname'], ENT_QUOTES, 'UTF-8'),
        $recip['percent'] . '%',
        $recip['percent'] . '%',
        htmlspecialchars($deadlineDay, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($deadlineDay, ENT_QUOTES, 'UTF-8'),
        $portalUrl,
        $portalUrl,
        $recip['pending'],
        $recip['total'],
        $recip['completed'],
        $recip['total']
    ];

    $personalizedSubject = str_replace($searchTags, $replaceValues, $subjectRaw);
    $personalizedBody = str_replace($searchTags, $replaceValues, $bodyTemplate);

    // Format plain text into clean paragraphs with line breaks if no HTML tags present
    if (strpos($personalizedBody, '<p>') === false && strpos($personalizedBody, '<div') === false) {
        $paras = preg_split("/\r\n\r\n|\n\n/", trim($personalizedBody));
        $htmlParas = [];
        foreach ($paras as $p) {
            $pTrim = trim($p);
            if ($pTrim !== '') {
                $htmlParas[] = '<p style="margin: 0 0 16px 0; line-height: 1.6;">' . nl2br($pTrim) . '</p>';
            }
        }
        $dispatchBody = implode("\n", $htmlParas);
    } else {
        $dispatchBody = $personalizedBody;
    }

    $res = $mailer->send($recip['email'], $recip['name'], $personalizedSubject, $dispatchBody);

    $status = $res['success'] ? 'sent' : 'failed';
    $errMsg = $res['error'] ?? null;
    $snippet = substr(strip_tags($personalizedBody), 0, 150);

    if ($logStmt) {
        $logStmt->bind_param("issssssi", $recip['uid'], $recip['name'], $recip['email'], $personalizedSubject, $snippet, $status, $errMsg, $currentUserId);
        $logStmt->execute();
    }

    if ($res['success']) {
        $sentCount++;
    } else {
        $failedCount++;
    }

    $deliveryResults[] = [
        'uid' => $recip['uid'],
        'name' => $recip['name'],
        'email' => $recip['email'],
        'status' => $status,
        'error' => $errMsg
    ];
}

if ($logStmt) $logStmt->close();

echo json_encode([
    'success' => true,
    'total_recipients' => count($recipients),
    'sent_count' => $sentCount,
    'failed_count' => $failedCount,
    'message' => "Campaign finished: {$sentCount} sent successfully" . ($failedCount > 0 ? ", {$failedCount} failed." : "."),
    'results' => $deliveryResults
]);
