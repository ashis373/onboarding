<?php
// Optional local configuration override for local development
if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}

// Reuse main CorHis config for DB and global constants
if (file_exists(__DIR__ . '/../../config.php')) {
    require_once __DIR__ . '/../../config.php';
} elseif (file_exists(__DIR__ . '/../../../config.php')) {
    require_once __DIR__ . '/../../../config.php';
} elseif (isset($_SERVER['DOCUMENT_ROOT']) && file_exists($_SERVER['DOCUMENT_ROOT'] . '/internal/hex-timesheet/config.php')) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/internal/hex-timesheet/config.php';
}
?>
