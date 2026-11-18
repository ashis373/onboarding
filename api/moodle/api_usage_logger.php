<?php
/**
 * Per-Request Moodle REST API Usage Tracker
 * Accumulates live vs cached Moodle WS calls in memory and writes 1 summary row
 * per request into Onboard MySQL (`onboard_moodle_api_logs`) in IST.
 */

function ensureMoodleApiLogsTable($conn) {
    static $ensured = false;
    if ($ensured || !$conn) {
        return;
    }
    $ensured = true;
    try {
        @$conn->query("
            CREATE TABLE IF NOT EXISTS `onboard_moodle_api_logs` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NULL,
                `moodle_user_id` INT NULL,
                `endpoint` VARCHAR(80) NOT NULL,
                `scenario` VARCHAR(80) NOT NULL,
                `live_calls` INT NOT NULL DEFAULT 0,
                `cached_calls` INT NOT NULL DEFAULT 0,
                `failed_calls` INT NOT NULL DEFAULT 0,
                `duration_ms` INT NOT NULL DEFAULT 0,
                `ws_functions` TEXT NULL,
                `created_at` DATETIME NOT NULL,
                INDEX `idx_created_at` (`created_at`),
                INDEX `idx_user_id` (`user_id`),
                INDEX `idx_endpoint` (`endpoint`),
                INDEX `idx_scenario` (`scenario`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (\Throwable $e) {
        // Never allow telemetry DDL to break requests
    }
}

function &getMoodleApiTrackerState() {
    if (!isset($GLOBALS['moodle_api_tracker']) || !is_array($GLOBALS['moodle_api_tracker'])) {
        $GLOBALS['moodle_api_tracker'] = [
            'scenario' => null,
            'user_id' => null,
            'moodle_user_id' => null,
            'live_calls' => 0,
            'cached_calls' => 0,
            'failed_calls' => 0,
            'duration_ms' => 0.0,
            'functions' => [],
            'flushed' => false,
        ];
    }
    return $GLOBALS['moodle_api_tracker'];
}

function setMoodleApiContext($scenario, $userId = null, $moodleUserId = null) {
    $tracker = &getMoodleApiTrackerState();
    if ($scenario !== null) {
        $tracker['scenario'] = (string)$scenario;
    }
    if ($userId !== null) {
        $tracker['user_id'] = (int)$userId;
    }
    if ($moodleUserId !== null) {
        $tracker['moodle_user_id'] = (int)$moodleUserId;
    }
}

function trackMoodleApiCall($function, $cacheSource, $durationMs = 0.0, $isSuccess = true) {
    $tracker = &getMoodleApiTrackerState();
    $isCached = in_array($cacheSource, ['MEM_CACHE', 'FILE_CACHE'], true);

    if ($isCached) {
        $tracker['cached_calls']++;
    } elseif ($cacheSource === 'LIVE') {
        $tracker['live_calls']++;
        $tracker['duration_ms'] += (float)$durationMs;
        if (!$isSuccess) {
            $tracker['failed_calls']++;
        }
    } else {
        // Circuit breaker / throttled skip
        if (!$isSuccess) {
            $tracker['failed_calls']++;
        }
    }

    $fnKey = (string)$function . ($isCached ? ' [cache]' : '');
    if (!isset($tracker['functions'][$fnKey])) {
        $tracker['functions'][$fnKey] = 0;
    }
    $tracker['functions'][$fnKey]++;
}

function flushMoodleApiUsage($conn, $scenarioOverride = null, $userIdOverride = null, $moodleUserIdOverride = null) {
    if (!$conn || !($conn instanceof mysqli)) {
        return;
    }
    $tracker = &getMoodleApiTrackerState();
    if (!empty($tracker['flushed'])) {
        return;
    }
    if ($scenarioOverride !== null) {
        $tracker['scenario'] = (string)$scenarioOverride;
    }
    if ($userIdOverride !== null) {
        $tracker['user_id'] = (int)$userIdOverride;
    }
    if ($moodleUserIdOverride !== null) {
        $tracker['moodle_user_id'] = (int)$moodleUserIdOverride;
    }

    // Skip DB write on 0-call cache hits to avoid consuming MySQL connections per second
    if ($tracker['live_calls'] === 0 && $tracker['failed_calls'] === 0) {
        return;
    }

    $tracker['flushed'] = true;

    try {
        date_default_timezone_set('Asia/Kolkata');
        ensureMoodleApiLogsTable($conn);

        $userId = $tracker['user_id'] ?? (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : (isset($_SESSION['id']) ? (int)$_SESSION['id'] : null));
        $moodleUserId = $tracker['moodle_user_id'];
        $endpoint = mb_substr(basename((string)($_SERVER['SCRIPT_NAME'] ?? 'unknown')), 0, 75);
        $scenario = mb_substr((string)($tracker['scenario'] ?: 'default'), 0, 75);
        $liveCalls = (int)$tracker['live_calls'];
        $cachedCalls = (int)$tracker['cached_calls'];
        $failedCalls = (int)$tracker['failed_calls'];
        $durationMs = (int)round($tracker['duration_ms']);

        $fnSummary = [];
        foreach ($tracker['functions'] as $fn => $cnt) {
            $fnSummary[] = "{$fn}: {$cnt}";
        }
        $wsFunctionsStr = !empty($fnSummary) ? mb_substr(implode(', ', $fnSummary), 0, 2000) : null;
        $createdAtIst = date('Y-m-d H:i:s');

        $stmt = @$conn->prepare("
            INSERT INTO `onboard_moodle_api_logs`
                (`user_id`, `moodle_user_id`, `endpoint`, `scenario`, `live_calls`, `cached_calls`, `failed_calls`, `duration_ms`, `ws_functions`, `created_at`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if ($stmt) {
            $stmt->bind_param(
                "iissiiiiss",
                $userId,
                $moodleUserId,
                $endpoint,
                $scenario,
                $liveCalls,
                $cachedCalls,
                $failedCalls,
                $durationMs,
                $wsFunctionsStr,
                $createdAtIst
            );
            @$stmt->execute();
            @$stmt->close();
        }
    } catch (\Throwable $e) {
        // Never allow usage logging to break application flow
    }
}

