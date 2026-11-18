<?php
$NEED_MYSQLI = false;
require_once '../db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Not logged in']); exit; }
if (!in_array((string)$user_id, ['26', '64', '205'], true)) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }

// Accept multipart/form-data
if (!isset($_FILES['file'])) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'file missing']); exit; }
$file = $_FILES['file'];
if ($file['error'] !== UPLOAD_ERR_OK) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'upload error']); exit; }

$tmp = $file['tmp_name'];
$size = filesize($tmp);
if ($size === false || $size <= 0) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'empty file']); exit; }
// Basic size limit 50MB
if ($size > 50 * 1024 * 1024) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'file too large']); exit; }

$mime = mime_content_type($tmp) ?: 'application/octet-stream';
// Basic mime allowlist
$allowed = ['image/jpeg','image/png','image/gif','image/webp','application/pdf','video/mp4','text/plain'];
if (!in_array($mime, $allowed, true)) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'mime not allowed']); exit; }

$hash = hash_file('sha256', $tmp);

// Dedup: if exists, return existing
$stmt = $pdo->prepare('SELECT id, url FROM onboard_assets WHERE sha256 = ?');
$stmt->execute([$hash]);
$row = $stmt->fetch();
if ($row) {
  header('Content-Type: application/json');
  echo json_encode(['success' => true, 'id' => intval($row['id']), 'url' => $row['url'], 'deduped' => true]);
  exit;
}

// Ensure upload dir
$baseDir = __DIR__ . '/../../public/uploads';
if (!is_dir($baseDir)) { @mkdir($baseDir, 0775, true); }

$ext = pathinfo($file['name'], PATHINFO_EXTENSION);
$safeName = preg_replace('/[^A-Za-z0-9_\.-]/', '_', basename($file['name']));
if ($safeName === '' || $safeName === '.' || $safeName === '..') { $safeName = $hash . ($ext ? ('.' . $ext) : ''); }

$destName = $hash . '_' . $safeName;
$destPath = $baseDir . '/' . $destName;
if (!move_uploaded_file($tmp, $destPath)) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'store failed']); exit; }

$isLocal = (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/internal/corhis') !== false);
$url = ($isLocal ? '/internal/corhis/onboard/public/uploads/' : '/onboard/public/uploads/') . $destName;

$ins = $pdo->prepare('INSERT INTO onboard_assets (filename, mime, size_bytes, sha256, url, created_by) VALUES (?,?,?,?,?,?)');
$ins->execute([$file['name'], $mime, $size, $hash, $url, $user_id]);
$id = (int)$pdo->lastInsertId();

header('Content-Type: application/json');
echo json_encode(['success' => true, 'id' => $id, 'url' => $url, 'deduped' => false]);
?>


