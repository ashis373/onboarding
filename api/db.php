<?php
// Database connection bridge for Onboard API
// Provides both MySQLi ($conn) and PDO ($pdo) connections
// Fully integrated with CorHis Core Platform configuration and session management

// Buffer any stray output
ob_start();
if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}

$coreBootstrap = dirname(__DIR__, 2) . '/core/bootstrap.php';
if (file_exists($coreBootstrap)) {
    require_once $coreBootstrap;
} elseif (file_exists(__DIR__ . '/../../config.php')) {
    require_once __DIR__ . '/../../config.php';
}
ob_end_clean();

use CorHis\Core\Config\Config;
use CorHis\Core\Config\Environment;

$isLocal = class_exists('CorHis\Core\Config\Environment') ? Environment::isLocal() : true;

if (!defined('DB_HOST')) define('DB_HOST', class_exists('CorHis\Core\Config\Config') ? (string)Config::get('database.host', $isLocal ? '127.0.0.1' : 'localhost') : '127.0.0.1');
if (!defined('DB_USER')) define('DB_USER', class_exists('CorHis\Core\Config\Config') ? (string)Config::get('database.username', $isLocal ? 'root' : 'u413479707_prod') : 'root');
if (!defined('DB_PASSWORD')) define('DB_PASSWORD', class_exists('CorHis\Core\Config\Config') ? (string)Config::get('database.password', '') : '');
if (!defined('DB_NAME')) define('DB_NAME', class_exists('CorHis\Core\Config\Config') ? (string)Config::get('database.database', $isLocal ? 'corhis_recovery' : 'u413479707_corhis') : 'corhis_recovery');
if (!defined('DB_PORT')) define('DB_PORT', class_exists('CorHis\Core\Config\Config') ? (int)Config::get('database.port', 3306) : 3306);

// By default, create both $conn and $pdo for full backward compatibility.
// If an endpoint explicitly sets $NEED_MYSQLI = false, MySQLi is skipped.
// If an endpoint explicitly sets $NEED_PDO = false, PDO is skipped.
$needMysqli = !isset($NEED_MYSQLI) || $NEED_MYSQLI === true;
$needPdo = !isset($NEED_PDO) || $NEED_PDO === true;

// MySQLi Connection
if ($needMysqli && !isset($conn)) {
    try {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME, DB_PORT);
        if ($conn->connect_error) {
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'MySQLi Connection failed: ' . $conn->connect_error]);
            exit;
        }
        $conn->set_charset("utf8mb4");
    } catch (\Throwable $e) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'MySQLi Connection failed: ' . $e->getMessage()]);
        exit;
    }
}

// PDO Connection
if ($needPdo && !isset($pdo)) {
    try {
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASSWORD,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]
        );
    } catch (\Throwable $e) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'PDO Connection failed: ' . $e->getMessage()]);
        exit;
    }
}

// Ensure session uses CorHis central session
if (class_exists('CorHis\Core\Auth\SessionManager')) {
    \CorHis\Core\Auth\SessionManager::start();
} elseif (session_status() === PHP_SESSION_NONE) {
    if (isset($_COOKIE['corhis_session'])) {
        session_name('corhis_session');
    }
    session_start();
}
