<?php
header('Content-Type: application/json; charset=UTF-8');
$NEED_MYSQLI = false;
require_once '../db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$q = trim($_GET['q'] ?? '');
if ($q === '') { echo json_encode(['success' => true, 'modules' => [], 'assessments' => [], 'questions' => []]); exit; }

$like = '%' . $q . '%';

$mods = $pdo->prepare('SELECT id, title, description FROM onboard_modules WHERE title LIKE ? OR description LIKE ? ORDER BY id DESC LIMIT 20');
try { $mods->execute([$like, $like]); $modules = $mods->fetchAll(); } catch (Throwable $e) { $modules = []; }

$asm = $pdo->prepare('SELECT id, title, description FROM onboard_assessments WHERE title LIKE ? OR description LIKE ? ORDER BY id DESC LIMIT 20');
try { $asm->execute([$like, $like]); $assessments = $asm->fetchAll(); } catch (Throwable $e) { $assessments = []; }

$qs = $pdo->prepare("SELECT id, prompt FROM onboard_questions WHERE prompt LIKE ? ORDER BY id DESC LIMIT 20");
try { $qs->execute([$like]); $questions = $qs->fetchAll(); } catch (Throwable $e) { $questions = []; }

echo json_encode(['success' => true, 'modules' => $modules, 'assessments' => $assessments, 'questions' => $questions]);
?>


