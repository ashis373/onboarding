<?php
/**
 * api/moodle/course_image.php
 * Dynamic image proxy & cache for Moodle course summary / overview images.
 * 
 * Directly serves locally cached course cover images from Moodle LMS.
 * Automatically checks image version / timestamp from Moodle overviewfiles.
 * When an image is updated or missing, dynamically fetches the real cover from Moodle LMS.
 */

error_reporting(0);
ini_set('display_errors', 0);

// CORS and Cache Headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$courseId = isset($_GET['course_id']) ? (int)$_GET['course_id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);
if ($courseId <= 0) {
    serveFallbackSvg('HexaLearn Onboarding', 'WELCOME');
    exit;
}

// Resolve root directory of the onboard project cleanly
$rootDir = dirname(dirname(__DIR__));
$cacheDir = $rootDir . DIRECTORY_SEPARATOR . 'courses';
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0777, true);
}

// Normalize Module ID (1-10) to Moodle Course ID if needed
$moduleIdToCourseId = [
    1  => 2,   // Employee Onboarding
    2  => 4,   // Work Rules & Work Life
    3  => 5,   // Leave & Holidays
    4  => 8,   // Code of Conduct & Workplace Behavior
    5  => 6,   // IT, Confidentiality & Security
    6  => 9,   // Prevention of Sexual Harassment
    7  => 10,  // Compensation & Benefits
    8  => 11,  // Performance & Career Growth
    9  => 12,  // HR Processes & Employee Support
    10 => 13,  // Safety, Security & Workplace Essentials
    11 => 3,   // Employee Assessment
];

// Check version tracker file
$versionFile = $cacheDir . DIRECTORY_SEPARATOR . 'course_' . $courseId . '.v';
$savedVersion = file_exists($versionFile) ? (int)trim(@file_get_contents($versionFile)) : 0;
$reqVersion = isset($_GET['v']) ? (int)$_GET['v'] : 0;

$candidateExts = ['png', 'jpg', 'jpeg', 'webp', 'svg', 'gif'];
$foundFile = null;

foreach ($candidateExts as $ext) {
    $filePath = $cacheDir . DIRECTORY_SEPARATOR . 'course_' . $courseId . '.' . $ext;
    if (file_exists($filePath) && filesize($filePath) > 500) {
        $foundFile = $filePath;
        break;
    }
}

// If direct course ID not found, check if it was requested by module ID
if (!$foundFile && isset($moduleIdToCourseId[$courseId])) {
    $mappedId = $moduleIdToCourseId[$courseId];
    foreach ($candidateExts as $ext) {
        $filePath = $cacheDir . DIRECTORY_SEPARATOR . 'course_' . $mappedId . '.' . $ext;
        if (file_exists($filePath) && filesize($filePath) > 500) {
            $foundFile = $filePath;
            $courseId = $mappedId;
            break;
        }
    }
}

// If local cached image file exists and is not outdated by request version, serve immediately
if ($foundFile && ($reqVersion === 0 || $savedVersion >= $reqVersion)) {
    serveExistingImageFile($foundFile);
    exit;
}

// If missing or outdated, attempt dynamic fetch from Moodle LMS
require_once __DIR__ . '/config.php';
$token = getMoodleWsToken();

if (!empty($token)) {
    require_once __DIR__ . '/client.php';
    try {
        $cData = callMoodleWs('core_course_get_courses_by_field', ['field' => 'id', 'value' => (string)$courseId]);
        if (!empty($cData['courses'][0]['overviewfiles'][0]['fileurl'])) {
            $ov = $cData['courses'][0]['overviewfiles'][0];
            $downloadUrl = $ov['fileurl'] . '?token=' . $token;
            $parsedPath = parse_url($ov['fileurl'], PHP_URL_PATH);
            $ext = strtolower(pathinfo($parsedPath, PATHINFO_EXTENSION)) ?: 'png';
            $destFile = $cacheDir . DIRECTORY_SEPARATOR . 'course_' . $courseId . '.' . $ext;
            
            $ctx = stream_context_create([
                'http' => [
                    'timeout' => 6,
                    'header' => "User-Agent: HexaLearn-Onboard/2.0\r\n"
                ],
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false
                ]
            ]);
            $imgBytes = @file_get_contents($downloadUrl, false, $ctx);
            if ($imgBytes && strlen($imgBytes) > 500) {
                @file_put_contents($destFile, $imgBytes);
                @file_put_contents($versionFile, (string)($ov['timemodified'] ?? time()));
                serveExistingImageFile($destFile);
                exit;
            }
        }
    } catch (\Throwable $e) {
        // Fallback continues below
    }
}

// If fetch failed but we had a cached version, serve existing
if ($foundFile) {
    serveExistingImageFile($foundFile);
    exit;
}

// Fallback: Generate branded SVG cover card
$courseMasterTitles = [
    2  => ['title' => 'Employee Onboarding', 'module' => 'MODULE 1'],
    3  => ['title' => 'Employee Assessment', 'module' => 'ASSESSMENT'],
    4  => ['title' => 'Work Rules & Work Life', 'module' => 'MODULE 2'],
    5  => ['title' => 'Leave & Holidays', 'module' => 'MODULE 3'],
    6  => ['title' => 'IT, Confidentiality & Security', 'module' => 'MODULE 5'],
    8  => ['title' => 'Code of Conduct & Workplace Behavior', 'module' => 'MODULE 4'],
    9  => ['title' => 'Prevention of Sexual Harassment', 'module' => 'MODULE 6 (POSH)'],
    10 => ['title' => 'Compensation & Benefits', 'module' => 'MODULE 7'],
    11 => ['title' => 'Performance & Career Growth', 'module' => 'MODULE 8'],
    12 => ['title' => 'HR Processes & Employee Support', 'module' => 'MODULE 9'],
    13 => ['title' => 'Safety, Security & Workplace Essentials', 'module' => 'MODULE 10'],
    14 => ['title' => 'Employee Assessment', 'module' => 'ASSESSMENT'],
];

$titleInfo = $courseMasterTitles[$courseId] ?? ['title' => "Course {$courseId}", 'module' => 'TRAINING'];
$svgFile = $cacheDir . DIRECTORY_SEPARATOR . 'course_' . $courseId . '.svg';
$svgData = generateFallbackSvgContent($titleInfo['title'], $titleInfo['module']);
@file_put_contents($svgFile, $svgData);
@file_put_contents($versionFile, (string)time());
serveExistingImageFile($svgFile);
exit;

function generateFallbackSvgContent($title, $badge = 'HEXALEARN ONBOARDING') {
    $cleanTitle = htmlspecialchars($title, ENT_XML1, 'UTF-8');
    $cleanBadge = htmlspecialchars($badge, ENT_XML1, 'UTF-8');
    return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="600" height="340" viewBox="0 0 600 340">
  <defs>
    <linearGradient id="g" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="#00A389" />
      <stop offset="60%" stop-color="#0284C7" />
      <stop offset="100%" stop-color="#0f172a" />
    </linearGradient>
    <pattern id="grid" width="30" height="30" patternUnits="userSpaceOnUse">
      <path d="M 30 0 L 0 0 0 30" fill="none" stroke="rgba(255,255,255,0.05)" stroke-width="1"/>
    </pattern>
  </defs>
  <rect width="600" height="340" fill="url(#g)" />
  <rect width="600" height="340" fill="url(#grid)" />
  <circle cx="300" cy="130" r="46" fill="#ffffff" fill-opacity="0.15" stroke="#ffffff" stroke-width="2" />
  <path d="M290 118 L318 130 L290 142 Z" fill="#ffffff" />
  <rect x="200" y="195" width="200" height="24" rx="12" fill="rgba(255,255,255,0.2)" />
  <text x="300" y="211" text-anchor="middle" fill="#99f6e4" font-family="-apple-system, BlinkMacSystemFont, Segoe UI, Roboto, sans-serif" font-size="11" font-weight="700" letter-spacing="1">
    {$cleanBadge}
  </text>
  <text x="300" y="250" text-anchor="middle" fill="#ffffff" font-family="-apple-system, BlinkMacSystemFont, Segoe UI, Roboto, sans-serif" font-size="18" font-weight="800">
    {$cleanTitle}
  </text>
</svg>
SVG;
}

function serveFallbackSvg($title, $badge = 'HEXALEARN ONBOARDING') {
    header('Content-Type: image/svg+xml');
    header('Cache-Control: public, max-age=86400');
    echo generateFallbackSvgContent($title, $badge);
    exit;
}

function serveExistingImageFile($filePath) {
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $mime = 'image/jpeg';
    if ($ext === 'png') $mime = 'image/png';
    elseif ($ext === 'webp') $mime = 'image/webp';
    elseif ($ext === 'gif') $mime = 'image/gif';
    elseif ($ext === 'svg') $mime = 'image/svg+xml';

    $fileMtime = @filemtime($filePath);
    $etag = '"' . md5($filePath . $fileMtime . filesize($filePath)) . '"';

    if ((isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) ||
        (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && @strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) >= $fileMtime)) {
        http_response_code(304);
        exit;
    }

    header('Content-Type: ' . $mime);
    // Aggressive, persistent 30-day client browser caching (zero reload on subsequent page visits)
    header('Cache-Control: public, max-age=2592000, immutable');
    header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 2592000) . ' GMT');
    header('Pragma: cache');
    header('ETag: ' . $etag);
    if ($fileMtime) {
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $fileMtime) . ' GMT');
    }
    header('Content-Length: ' . filesize($filePath));
    readfile($filePath);
    exit;
}
