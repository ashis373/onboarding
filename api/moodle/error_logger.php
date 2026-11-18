<?php
/**
 * Shared Moodle Error & DB Connection Telemetry Logger
 * Stores errors in Onboard MySQL table `onboard_moodle_error_logs` (never Moodle DB).
 */

function ensureMoodleErrorLogsTable($conn) {
    static $ensured = false;
    if ($ensured || !$conn) {
        return;
    }
    $ensured = true;
    try {
        @$conn->query("
            CREATE TABLE IF NOT EXISTS `onboard_moodle_error_logs` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NULL,
                `moodle_user_id` INT NULL,
                `course_id` INT NULL,
                `cmid` INT NULL,
                `course_title` VARCHAR(255) NULL,
                `endpoint` VARCHAR(100) NOT NULL,
                `ws_function` VARCHAR(120) NULL,
                `error_type` VARCHAR(60) NOT NULL,
                `http_code` INT NULL,
                `error_message` TEXT NOT NULL,
                `ip_address` VARCHAR(64) NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_created_at` (`created_at`),
                INDEX `idx_error_type` (`error_type`),
                INDEX `idx_user_id` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (\Throwable $e) {
        // Never allow telemetry table checks to break application flow
    }
}

function logMoodleError($conn, array $data) {
    if (!$conn || !($conn instanceof mysqli)) {
        return;
    }
    try {
        date_default_timezone_set('Asia/Kolkata');
        @$conn->query("SET time_zone = '+05:30'");
        ensureMoodleErrorLogsTable($conn);

        $userId = isset($data['user_id']) && $data['user_id'] !== null
            ? (int)$data['user_id']
            : (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : (isset($_SESSION['id']) ? (int)$_SESSION['id'] : null));

        $moodleUserId = !empty($data['moodle_user_id']) ? (int)$data['moodle_user_id'] : null;
        $courseId = !empty($data['course_id']) ? (int)$data['course_id'] : null;
        $cmid = !empty($data['cmid']) ? (int)$data['cmid'] : null;
        $courseTitle = !empty($data['course_title']) ? mb_substr(trim((string)$data['course_title']), 0, 250) : null;
        $endpoint = mb_substr(trim((string)($data['endpoint'] ?? basename($_SERVER['SCRIPT_NAME'] ?? 'unknown'))), 0, 95);
        $wsFunction = !empty($data['ws_function']) ? mb_substr(trim((string)$data['ws_function']), 0, 115) : null;
        $errorType = mb_substr(trim((string)($data['error_type'] ?? 'unknown')), 0, 55);
        $httpCode = isset($data['http_code']) ? (int)$data['http_code'] : null;
        $errorMessage = mb_substr(trim((string)($data['error_message'] ?? 'Unknown Moodle error')), 0, 2000);
        $ipAddress = mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 60);
        $createdAtIst = date('Y-m-d H:i:s');

        $stmt = @$conn->prepare("
            INSERT INTO `onboard_moodle_error_logs`
                (`user_id`, `moodle_user_id`, `course_id`, `cmid`, `course_title`, `endpoint`, `ws_function`, `error_type`, `http_code`, `error_message`, `ip_address`, `created_at`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if ($stmt) {
            $stmt->bind_param(
                "iiiissssisss",
                $userId,
                $moodleUserId,
                $courseId,
                $cmid,
                $courseTitle,
                $endpoint,
                $wsFunction,
                $errorType,
                $httpCode,
                $errorMessage,
                $ipAddress,
                $createdAtIst
            );
            @$stmt->execute();
            @$stmt->close();
        }
    } catch (\Throwable $e) {
        // Never allow logging failure to break caller
    }
}

