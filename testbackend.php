<?php
/**
 * ============================================================================
 * testbackend.php
 * Assessment Attempt History & Moodle Backend Diagnostics Engine
 * 
 * Provides full server-side functions and live inspection for:
 * 1. Employee identification & Moodle account mapping
 * 2. Assessment Attempt History rows (Attempt 1, 2, 3...)
 * 3. Exact row data format sent to the client-side React table
 * 4. Raw SCORM CMI tracking elements from Moodle LMS
 * 5. All Onboarding Courses & Modules status
 * 
 * Access:
 * - HTML Dashboard: https://hexalearn.com/internal/hex-timesheet/onboard/testbackend.php
 * - Pure JSON API:  https://hexalearn.com/internal/hex-timesheet/onboard/testbackend.php?format=json
 * ============================================================================
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Default to test user 205 (ashisr / Moodle User 4)
if (empty($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 205;
}
$userId = (int)$_SESSION['user_id'];

require_once __DIR__ . '/api/db.php';
require_once __DIR__ . '/api/moodle/client.php';

// Check if JSON response is requested
$isJson = (isset($_GET['format']) && $_GET['format'] === 'json') || 
          (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false && !isset($_GET['html']));

// ============================================================================
// FUNCTION DEFINITIONS
// ============================================================================

/**
 * 1. Format SCORM total_time (HH:MM:SS.SS) into human-friendly duration
 *    Examples: 00:00:00 -> "0s" | 00:05:07.12 -> "5m 7s" | 00:00:27.45 -> "27s"
 */
function formatSCORMDuration($timeStr) {
    if (empty($timeStr) || $timeStr === '00:00:00' || $timeStr === '00:00:00.00') {
        return '0s';
    }
    $parts = explode(':', $timeStr);
    if (count($parts) >= 3) {
        $hours = (int)$parts[0];
        $mins = (int)$parts[1];
        $secParts = explode('.', $parts[2]);
        $secs = (int)$secParts[0];

        if ($hours > 0) return "{$hours}h {$mins}m";
        if ($mins > 0) return "{$mins}m {$secs}s";
        if ($secs > 0) return "{$secs}s";
        return '0s';
    }
    return $timeStr;
}

/**
 * 2. Fetch Employee details from HexaOne Database and Moodle mapping table
 */
function getEmployeeInfo($conn, $userId) {
    $emp = [
        'uid' => $userId,
        'username' => '',
        'fullname' => 'Test Employee',
        'email' => '',
        'moodle_user_id' => 4,
        'mapping_found' => false
    ];

    if ($stmt = $conn->prepare("SELECT uid, username, firstname, lastname, ofc_mail FROM user WHERE uid = ?")) {
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $emp['username'] = $row['username'] ?? '';
            $emp['fullname'] = trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? ''));
            $emp['email'] = $row['ofc_mail'] ?? '';
        }
        $stmt->close();
    }

    if ($stmtMap = $conn->prepare("SELECT moodle_user_id FROM onboard_moodle_users WHERE hexa_uid = ?")) {
        $stmtMap->bind_param("i", $userId);
        $stmtMap->execute();
        $resMap = $stmtMap->get_result();
        if ($mRow = $resMap->fetch_assoc()) {
            $emp['moodle_user_id'] = (int)$mRow['moodle_user_id'];
            $emp['mapping_found'] = true;
        }
        $stmtMap->close();
    }

    return $emp;
}

/**
 * 3. Fetch Assessment Attempt History from Moodle LMS
 *    Queries mod_scorm_get_scorm_attempt_count and mod_scorm_get_scorm_sco_tracks
 */
function getAssessmentAttemptHistory($conn, $moodleUserId, $scormId = 2, $scoId = 4, $masteryScore = 80) {
    $attemptCountRes = callMoodleWs('mod_scorm_get_scorm_attempt_count', [
        'scormid' => $scormId,
        'userid' => $moodleUserId
    ], $conn);

    $totalAttempts = max(1, (int)($attemptCountRes['attemptscount'] ?? 1));
    $historyRows = [];
    $rawTracks = [];

    for ($att = 1; $att <= $totalAttempts; $att++) {
        $trackRes = callMoodleWs('mod_scorm_get_scorm_sco_tracks', [
            'scoid' => $scoId,
            'userid' => $moodleUserId,
            'attempt' => $att
        ], $conn);

        $cleanTrack = [];
        if (!empty($trackRes['data']['tracks']) && is_array($trackRes['data']['tracks'])) {
            foreach ($trackRes['data']['tracks'] as $t) {
                if (isset($t['element']) && isset($t['value'])) {
                    $cleanTrack[$t['element']] = $t['value'];
                }
            }
        }
        $rawTracks["Attempt {$att}"] = $cleanTrack;

        $score = isset($cleanTrack['cmi.core.score.raw']) && is_numeric($cleanTrack['cmi.core.score.raw'])
            ? round((float)$cleanTrack['cmi.core.score.raw'], 2)
            : 0;

        $status = strtolower($cleanTrack['cmi.core.lesson_status'] ?? 'not_started');
        $totalTime = $cleanTrack['cmi.core.total_time'] ?? '';
        $timeModified = !empty($cleanTrack['timemodified']) ? (int)$cleanTrack['timemodified'] : null;

        // Pass/Fail evaluation against masteryScore (80%)
        $isPassed = in_array($status, ['completed', 'passed'], true) || ($score >= $masteryScore);
        $isFailed = ($status === 'failed') || ($score < $masteryScore && $status !== 'not_started' && $status !== 'incomplete');
        $isIncomplete = ($status === 'incomplete');

        if ($isPassed) {
            $result = 'Passed';
        } elseif ($isFailed) {
            $result = 'Failed';
        } elseif ($isIncomplete) {
            $result = 'Incomplete';
        } else {
            $result = ucfirst($status);
        }

        $formattedDuration = formatSCORMDuration($totalTime);
        $dateFormatted = $timeModified ? date('M j, Y, g:i A', $timeModified) : '—';

        $historyRows[] = [
            'attempt' => $att,
            'score' => $score,
            'result' => $result,
            'is_passed' => $isPassed,
            'is_failed' => $isFailed,
            'is_incomplete' => $isIncomplete,
            'status' => $status,
            'total_time' => $totalTime,
            'formattedDuration' => $formattedDuration,
            'timemodified' => $timeModified,
            'date' => $dateFormatted,
            'note' => ($att === 1) ? 'Initial' : ($isPassed ? 'Qualified' : "Retake Attempt {$att}"),
            'action_review_url' => "https://hexalearn.com/internal/hex-timesheet/onboard/api/moodle/launch.php?cmid={$scormId}&mode=review",
            'action_retake_url' => "https://hexalearn.com/internal/hex-timesheet/onboard/api/moodle/launch.php?cmid={$scormId}&newattempt=on"
        ];
    }

    return [
        'total_attempts' => $totalAttempts,
        'rows' => $historyRows,
        'raw_tracks' => $rawTracks
    ];
}

/**
 * 4. Fetch Full Courses API Response
 */
function getCoursesApiResponse($userId) {
    $_SESSION['user_id'] = $userId;
    ob_start();
    include __DIR__ . '/api/moodle/courses.php';
    $raw = ob_get_clean();
    return json_decode($raw, true) ?: [];
}

// ============================================================================
// DATA EXECUTION
// ============================================================================

$employee = getEmployeeInfo($conn, $userId);
$attemptData = getAssessmentAttemptHistory($conn, $employee['moodle_user_id'], 2, 4, 80);
$coursesApiData = getCoursesApiResponse($userId);

// Find assessment module from courses API response
$assessmentModule = null;
foreach (($coursesApiData['courses'] ?? []) as $c) {
    foreach (($c['modules'] ?? []) as $m) {
        if (!empty($m['is_assessment']) || stripos($m['title'] ?? '', 'assess') !== false) {
            $assessmentModule = $m;
            break 2;
        }
    }
}

// If JSON requested, output clean JSON payload
if ($isJson) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success' => true,
        'employee' => $employee,
        'assessment_module' => [
            'id' => $assessmentModule['id'] ?? 'scorm_2',
            'title' => $assessmentModule['title'] ?? 'Employee_Assessment',
            'is_assessment' => true,
            'passing_score' => 80,
            'attempts_count' => $attemptData['total_attempts']
        ],
        'client_side_rows' => $attemptData['rows'],
        'raw_moodle_tracks' => $attemptData['raw_tracks'],
        'courses_api_response' => $coursesApiData
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// HTML Dashboard Presentation
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assessment Attempt History - Backend Inspector</title>
    <style>
        :root {
            --bg: #f8fafc;
            --surface: #ffffff;
            --border: #e2e8f0;
            --text-dark: #0f172a;
            --text-muted: #64748b;
            --primary: #00A389;
            --primary-hover: #008a74;
            --red: #ef4444;
            --green: #10b981;
            --amber: #f59e0b;
        }
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: var(--bg);
            color: var(--text-dark);
            margin: 0;
            padding: 24px;
            line-height: 1.5;
        }
        .container { max-width: 1240px; margin: 0 auto; }
        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        }
        .header {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            border-bottom: 1px solid var(--border);
            padding-bottom: 20px;
            margin-bottom: 24px;
        }
        h1 { margin: 0; font-size: 22px; font-weight: 800; color: var(--text-dark); }
        h2 { font-size: 16px; margin: 0 0 12px 0; font-weight: 800; color: var(--text-dark); display: flex; align-items: center; gap: 8px; }
        h3 { font-size: 13px; margin: 16px 0 6px 0; color: #334155; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; }
        p.desc { margin: 0 0 14px 0; color: var(--text-muted); font-size: 13px; }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--primary);
            color: white;
            padding: 8px 16px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
        }
        .btn:hover { background: var(--primary-hover); }
        .btn-outline {
            background: white;
            color: #334155;
            border: 1px solid var(--border);
            padding: 6px 12px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 11px;
            font-weight: 700;
        }
        .btn-outline:hover { background: #f1f5f9; }
        .btn-retake {
            background: #d97706;
            color: white;
            padding: 6px 12px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 11px;
            font-weight: 700;
        }
        .btn-retake:hover { background: #b45309; }
        .pill {
            background: #f1f5f9;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            color: #334155;
            display: inline-block;
            margin-right: 6px;
        }
        .pill strong { color: var(--text-dark); }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            margin-top: 8px;
        }
        th, td {
            padding: 12px 14px;
            text-align: left;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }
        th {
            background: #f8fafc;
            color: #475569;
            font-weight: 700;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        tr:hover td { background: #fbfcfe; }
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 8px;
            border-radius: 9999px;
            font-size: 11px;
            font-weight: 700;
        }
        .badge-green { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .badge-red { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
        .badge-amber { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
        .badge-pulse {
            display: inline-block;
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #0284c7;
        }
        pre {
            background: #090d16;
            color: #38bdf8;
            padding: 14px 16px;
            border-radius: 8px;
            overflow-x: auto;
            font-size: 12px;
            line-height: 1.5;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            margin: 6px 0 0 0;
        }
        code {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            background: #f1f5f9;
            color: #0284c7;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 12px;
        }
    </style>
</head>
<body>
<div class="container">

    <!-- Header -->
    <div class="card">
        <div class="header">
            <div>
                <h1>Assessment Attempt History (Backend Inspector)</h1>
                <p style="margin:4px 0 0 0; color:#64748b; font-size:13px;">
                    Employee: <strong><?php echo htmlspecialchars($employee['fullname']); ?></strong> · 
                    <span style="color:#0284c7; font-weight:600;"><?php echo htmlspecialchars($assessmentModule['title'] ?? 'Employee_Assessment'); ?></span>
                </p>
                <div style="margin-top:12px;">
                    <span class="pill">UID: <strong><?php echo $employee['uid']; ?></strong></span>
                    <span class="pill">Moodle User ID: <strong><?php echo $employee['moodle_user_id']; ?></strong></span>
                    <span class="pill">Threshold: <strong>80% Required</strong></span>
                </div>
            </div>
            <div>
                <a href="?format=json" target="_blank" class="btn">View Raw JSON API →</a>
            </div>
        </div>

        <!-- 1. The Exact Table as Rendered on the Client Side -->
        <h2>
            <span class="badge-pulse"></span>
            Live Moodle LMS (<?php echo count($attemptData['rows']); ?> Attempts Recorded)
        </h2>
        <p class="desc">This is the exact data and structure passed to the frontend React table:</p>

        <table>
            <thead>
                <tr>
                    <th>Attempt</th>
                    <th>Score</th>
                    <th>Result</th>
                    <th>Duration</th>
                    <th>Date &amp; Time</th>
                    <th style="text-align:right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($attemptData['rows'] as $row): ?>
                    <tr>
                        <!-- Attempt -->
                        <td>
                            <strong style="color:#0f172a;">Attempt <?php echo $row['attempt']; ?></strong>
                            <?php if ($row['attempt'] === 1): ?>
                                <span style="background:#f1f5f9; color:#475569; padding:2px 6px; border-radius:4px; font-size:10px; font-weight:700; margin-left:4px;">Initial</span>
                            <?php elseif ($row['is_passed']): ?>
                                <span style="background:#dcfce7; color:#15803d; padding:2px 6px; border-radius:4px; font-size:10px; font-weight:700; margin-left:4px;">Qualified</span>
                            <?php endif; ?>
                        </td>

                        <!-- Score -->
                        <td>
                            <span style="font-size:14px; font-weight:800; color:<?php echo $row['is_passed'] ? '#15803d' : '#b91c1c'; ?>;">
                                <?php echo $row['score']; ?>%
                            </span>
                        </td>

                        <!-- Result -->
                        <td>
                            <?php if ($row['is_passed']): ?>
                                <span class="badge badge-green">✓ Passed</span>
                            <?php elseif ($row['is_failed']): ?>
                                <span class="badge badge-red">⚠ Failed</span>
                            <?php else: ?>
                                <span class="badge badge-amber"><?php echo htmlspecialchars($row['result']); ?></span>
                            <?php endif; ?>
                        </td>

                        <!-- Duration -->
                        <td>
                            <strong><?php echo htmlspecialchars($row['formattedDuration']); ?></strong>
                            <span style="color:#94a3b8; font-size:11px;">(<?php echo htmlspecialchars($row['total_time']); ?>)</span>
                        </td>

                        <!-- Date & Time -->
                        <td style="color:#475569; font-weight:500;">
                            <?php echo htmlspecialchars($row['date']); ?>
                        </td>

                        <!-- Action -->
                        <td style="text-align:right;">
                            <a href="<?php echo htmlspecialchars($row['action_review_url']); ?>" target="_blank" class="btn-outline">Review Quiz</a>
                            <?php if (!$row['is_passed']): ?>
                                <a href="<?php echo htmlspecialchars($row['action_retake_url']); ?>" target="_blank" class="btn-retake">Retake Quiz →</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div style="margin-top:14px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:10px 14px; font-size:12px; color:#64748b;">
            Passing threshold: <strong>80%</strong>. Retakes record new attempts in Moodle LMS automatically.
        </div>
    </div>

    <!-- 2. Client Side Row Schema & Fields Breakdown -->
    <div class="card">
        <h2>2. Client-Side Row Properties (What fields come in each row object)</h2>
        <p class="desc">Every attempt row item returned by the backend has these properties ready for client-side consumption:</p>
        
        <table>
            <thead>
                <tr>
                    <th>Row Property</th>
                    <th>Type</th>
                    <th>Value in Attempt 2</th>
                    <th>Source in Moodle / DB</th>
                    <th>Usage on Client Side</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><code>attempt</code></td>
                    <td><code>integer</code></td>
                    <td><code>2</code></td>
                    <td>Attempt counter (1 to N)</td>
                    <td>Displays column "Attempt 2"</td>
                </tr>
                <tr>
                    <td><code>score</code></td>
                    <td><code>float</code></td>
                    <td><code>16.66</code></td>
                    <td><code>cmi.core.score.raw</code></td>
                    <td>Displays score percentage with color code</td>
                </tr>
                <tr>
                    <td><code>result</code></td>
                    <td><code>string</code></td>
                    <td><code>"Failed"</code></td>
                    <td>Calculated vs 80% passing threshold</td>
                    <td>Displays badge (Passed/Failed/Incomplete)</td>
                </tr>
                <tr>
                    <td><code>is_passed</code></td>
                    <td><code>boolean</code></td>
                    <td><code>false</code></td>
                    <td><code>score >= 80 || status == 'passed'</code></td>
                    <td>Conditional styling & hiding Retake Quiz button</td>
                </tr>
                <tr>
                    <td><code>is_failed</code></td>
                    <td><code>boolean</code></td>
                    <td><code>true</code></td>
                    <td><code>status == 'failed' || score < 80</code></td>
                    <td>Shows warning alert and Retake Quiz button</td>
                </tr>
                <tr>
                    <td><code>status</code></td>
                    <td><code>string</code></td>
                    <td><code>"failed"</code></td>
                    <td><code>cmi.core.lesson_status</code></td>
                    <td>Raw SCORM completion status</td>
                </tr>
                <tr>
                    <td><code>total_time</code></td>
                    <td><code>string</code></td>
                    <td><code>"00:05:07.12"</code></td>
                    <td><code>cmi.core.total_time</code></td>
                    <td>Raw SCORM session duration</td>
                </tr>
                <tr>
                    <td><code>formattedDuration</code></td>
                    <td><code>string</code></td>
                    <td><code>"5m 7s"</code></td>
                    <td>Formatted by <code>formatSCORMDuration()</code></td>
                    <td>Displays column "Duration"</td>
                </tr>
                <tr>
                    <td><code>timemodified</code></td>
                    <td><code>integer</code></td>
                    <td><code>1792060860</code></td>
                    <td>Unix timestamp from Moodle track</td>
                    <td>Sort order & timestamp</td>
                </tr>
                <tr>
                    <td><code>date</code></td>
                    <td><code>string</code></td>
                    <td><code>"Oct 15, 2026, 4:21 PM"</code></td>
                    <td>Formatted date string</td>
                    <td>Displays column "Date & Time"</td>
                </tr>
                <tr>
                    <td><code>action_review_url</code></td>
                    <td><code>string</code></td>
                    <td><code>.../launch.php?cmid=2&amp;mode=review</code></td>
                    <td>Review launch endpoint</td>
                    <td>Bound to "Review Quiz" button</td>
                </tr>
                <tr>
                    <td><code>action_retake_url</code></td>
                    <td><code>string</code></td>
                    <td><code>.../launch.php?cmid=2&amp;newattempt=on</code></td>
                    <td>New attempt launch endpoint</td>
                    <td>Bound to "Retake Quiz →" button</td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- 3. Raw SCORM Tracks per Attempt from Moodle -->
    <div class="card">
        <h2>3. Raw Moodle CMI Tracking Elements (Direct from <code>mod_scorm_get_scorm_sco_tracks</code>)</h2>
        <p class="desc">Exact keys and values stored in Moodle LMS database for SCO 4 across attempts:</p>

        <?php foreach ($attemptData['raw_tracks'] as $attLabel => $tracks): ?>
            <h3><?php echo htmlspecialchars($attLabel); ?>:</h3>
            <pre><?php echo htmlspecialchars(json_encode($tracks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
        <?php endforeach; ?>
    </div>

    <!-- 4. Sample JSON Row for Frontend Consumption -->
    <div class="card">
        <h2>4. Complete JSON Array of Rows (Ready for React Client)</h2>
        <pre><?php echo htmlspecialchars(json_encode($attemptData['rows'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
    </div>

    <!-- 5. Moodle Course Cover Images Showcase -->
    <div class="card">
        <h2>5. Moodle Course Cover Images (Uploaded in Moodle LMS)</h2>
        <p class="desc">Each course in Category "Onbording" has its cover image dynamically retrieved and proxied from Moodle LMS:</p>

        <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; margin-top: 14px;">
            <?php foreach (($coursesApiData['courses'] ?? []) as $c): ?>
                <?php 
                    $cId = (int)$c['id'];
                    $cImg = "https://hexalearn.com/internal/hex-timesheet/onboard/api/moodle/course_image.php?course_id={$cId}";
                ?>
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
                    <div style="height:140px; background:#e2e8f0; position:relative; overflow:hidden;">
                        <img src="<?php echo $cImg; ?>" alt="<?php echo htmlspecialchars($c['fullname']); ?>" style="width:100%; height:100%; object-fit:cover;" />
                    </div>
                    <div style="padding:14px;">
                        <div style="font-size:11px; font-weight:700; color:#00A389; text-transform:uppercase; margin-bottom:4px;">Course ID: <?php echo $cId; ?></div>
                        <div style="font-size:14px; font-weight:800; color:#0f172a; margin-bottom:6px;"><?php echo htmlspecialchars($c['fullname']); ?></div>
                        <div style="font-size:11px; color:#64748b;">Category: <?php echo htmlspecialchars($c['categoryname'] ?? 'Onbording'); ?></div>
                        <div style="margin-top:10px; font-size:11px; font-family:monospace; color:#0284c7; word-break:break-all;">
                            <?php echo $cImg; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

</div>
</body>
</html>