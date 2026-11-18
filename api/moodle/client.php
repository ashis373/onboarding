<?php
// Moodle REST Web Services Client Bridge
// Executes server-to-server POST requests to Moodle REST API
// Enhanced with In-Memory Deduplication, Metadata Caching, Circuit Breaking & Secure Diagnostic Logging

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/error_logger.php';
require_once __DIR__ . '/api_usage_logger.php';

/**
 * Diagnostic logger for outbound Moodle REST requests (Phase F compliance).
 * Strictly excludes wstoken, passwords, credentials and sensitive bodies.
 */
function logMoodleWsTelemetry($entry) {
    static $logEnabled = null;
    if ($logEnabled === null) {
        $logEnabled = true; // Enabled for investigation
    }
    if (!$logEnabled) return;

    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $logFile = $logDir . '/moodle_ws.log';

    // Log rotation: cap at 5MB
    if (file_exists($logFile) && filesize($logFile) > 5242880) {
        @rename($logFile, $logFile . '.' . date('Ymd_His') . '.bak');
    }

    // Strict defensive sanitization: unconditionally strip credentials/tokens from logged params
    if (!empty($entry['params']) && is_array($entry['params'])) {
        $sanitizedParams = [];
        foreach ($entry['params'] as $pk => $pv) {
            if (in_array(strtolower($pk), ['wstoken', 'password', 'token', 'auth', 'secret'], true)) continue;
            if (is_scalar($pv)) {
                $sanitizedParams[$pk] = (strlen((string)$pv) > 50) ? substr((string)$pv, 0, 50) . '...' : $pv;
            } elseif (is_array($pv)) {
                $sanitizedParams[$pk] = 'array(' . count($pv) . ')';
            }
        }
        $entry['params'] = $sanitizedParams;
    }

    $entry['timestamp'] = date('c');
    $line = json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n";
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

/**
 * Main Web Services Caller
 */
function callMoodleWs($function, $params = [], $conn = null, $maxRetries = 1, $forceLive = false) {
    static $runtimeMemoryCache = [];
    static $activeCircuitBreakerUntil = 0;

    $reqId = $_SERVER['HTTP_X_REQUEST_ID'] ?? ('req_' . substr(md5(microtime(true) . mt_rand()), 0, 8));

    // Resolve caller for diagnostic tracing
    $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
    $callerFile = basename($backtrace[0]['file'] ?? 'unknown');
    $callerLine = $backtrace[0]['line'] ?? 0;
    if (isset($backtrace[1]['file'])) {
        $callerFile = basename($backtrace[1]['file']) . ':' . ($backtrace[1]['line'] ?? 0) . ' -> ' . $callerFile;
    }

    // Safe sanitized params summary for logging (Tokens/passwords never included)
    $safeParams = [];
    foreach ($params as $k => $v) {
        if (in_array(strtolower($k), ['wstoken', 'password', 'token', 'auth', 'secret'], true)) continue;
        if (is_scalar($v)) {
            $safeParams[$k] = (strlen((string)$v) > 50) ? substr((string)$v, 0, 50) . '...' : $v;
        } elseif (is_array($v)) {
            $safeParams[$k] = 'array(' . count($v) . ')';
        }
    }

    // 1. Check Circuit Breaker (Shared across PHP-FPM workers via atomic disk file with memory fallback)
    $now = time();
    $cacheDir = __DIR__ . '/cache';
    $cbFile = $cacheDir . '/circuit_breaker.json';
    $probeLockFile = $cacheDir . '/circuit_breaker_probe.lock';
    $isBreakerOpen = false;
    $remainingThrottleSec = 0;
    $breakerSource = 'PROCESS_MEMORY';
    $isProbeWorker = false;
    $probeLockHandle = null;

    $isDirWritable = is_dir($cacheDir) && is_writable($cacheDir);
    $releaseProbeLock = function() use (&$probeLockHandle) {
        if ($probeLockHandle) {
            @flock($probeLockHandle, LOCK_UN);
            @fclose($probeLockHandle);
            $probeLockHandle = null;
        }
    };

    // 1.1 In-memory check (fastest, 0ms)
    if ($now < $activeCircuitBreakerUntil) {
        $isBreakerOpen = true;
        $remainingThrottleSec = $activeCircuitBreakerUntil - $now;
        $breakerSource = $isDirWritable ? 'PROCESS_MEMORY' : 'MEMORY_FALLBACK_UNWRITABLE_DISK';
    } elseif (file_exists($cbFile)) {
        // 1.2 Cross-worker disk-backed check
        $cbRaw = @file_get_contents($cbFile);
        if ($cbRaw) {
            $cbData = json_decode($cbRaw, true);
            if (is_array($cbData) && !empty($cbData['open_until'])) {
                $fileOpenUntil = (int)$cbData['open_until'];
                if ($now < $fileOpenUntil) {
                    $activeCircuitBreakerUntil = $fileOpenUntil; // Sync worker memory
                    $isBreakerOpen = true;
                    $remainingThrottleSec = $fileOpenUntil - $now;
                    $breakerSource = 'CROSS_WORKER_FILE';
                } else {
                    // 1.3 Half-Open Single-Probe Concurrency Lock
                    // Breaker has expired ($now >= $fileOpenUntil): ONLY ONE process is granted probe rights
                    // All other processes remain throttled to prevent a synchronized thundering herd!
                    $probeLockHandle = @fopen($probeLockFile, 'c+');
                    if ($probeLockHandle && flock($probeLockHandle, LOCK_EX | LOCK_NB)) {
                        // This process won the probe lock!
                        $isProbeWorker = true;
                    } else {
                        // Another worker is actively probing Moodle!
                        if ($probeLockHandle) {
                            @fclose($probeLockHandle);
                        }
                        logMoodleWsTelemetry([
                            'req_id' => $reqId,
                            'func' => $function,
                            'params' => $safeParams,
                            'duration_ms' => 0,
                            'http_code' => 429,
                            'curl_errno' => 0,
                            'cache_source' => 'CIRCUIT_BREAKER_HALF_OPEN_PROBE_ACTIVE',
                            'caller' => $callerFile,
                            'status' => 'queued_behind_active_probe'
                        ]);
                        return [
                            'error' => true,
                            'message' => 'Moodle LMS is currently recovering. A single verification probe is active; request temporarily queued.'
                        ];
                    }
                }
            }
        }
    }

    if ($isBreakerOpen) {
        logMoodleWsTelemetry([
            'req_id' => $reqId,
            'func' => $function,
            'params' => $safeParams,
            'duration_ms' => 0,
            'http_code' => 429,
            'curl_errno' => 0,
            'cache_source' => 'CIRCUIT_BREAKER_' . $breakerSource,
            'caller' => $callerFile,
            'status' => 'blocked_by_circuit_breaker'
        ]);
        return [
            'error' => true,
            'message' => 'Moodle LMS is currently rate-limited or undergoing security challenge. Request throttled (' . $remainingThrottleSec . 's remaining).'
        ];
    }

    try {
        $isForce = $forceLive || !empty($_GET['refresh_all']) || !empty($_GET['refresh_meta']);
        $cacheKey = $function . ':' . md5(json_encode($params));

        // 2. In-Memory Deduplication: Identical calls within the same script execution return in 0ms
        if (!$isForce && isset($runtimeMemoryCache[$cacheKey])) {
            trackMoodleApiCall($function, 'MEM_CACHE', 0, true);
            logMoodleWsTelemetry([
                'req_id' => $reqId,
                'func' => $function,
                'params' => $safeParams,
                'duration_ms' => 0,
                'http_code' => 200,
                'curl_errno' => 0,
                'cache_source' => 'MEM_CACHE',
                'caller' => $callerFile,
                'status' => 'success'
            ]);
            return $runtimeMemoryCache[$cacheKey];
        }

        // 3. Persistent File Cache for Immutable / Semi-Static Course Structure Metadata
        // Course sections, SCORM package IDs, and SCO structure change rarely (TTL = 3600 seconds)
        static $staticMetadataFunctions = [
            'core_course_get_courses_by_field' => 3600,
            'core_course_get_contents' => 3600,
            'mod_scorm_get_scorms_by_courses' => 3600,
            'mod_scorm_get_scorm_scoes' => 3600,
        ];

        $isMetadataFunc = isset($staticMetadataFunctions[$function]);
        if (!$isForce && $isMetadataFunc) {
            $metaTtl = $staticMetadataFunctions[$function];
            $cacheDir = __DIR__ . '/cache';
            if (!is_dir($cacheDir)) {
                @mkdir($cacheDir, 0755, true);
            }
            $cacheFile = $cacheDir . '/meta_' . md5($cacheKey) . '.json';
            if (file_exists($cacheFile) && ($now - filemtime($cacheFile)) < $metaTtl) {
                $cachedContent = @file_get_contents($cacheFile);
                if ($cachedContent) {
                    $decodedCached = json_decode($cachedContent, true);
                    if (is_array($decodedCached) && empty($decodedCached['error'])) {
                        $runtimeMemoryCache[$cacheKey] = $decodedCached;
                        trackMoodleApiCall($function, 'FILE_CACHE', 0, true);
                        logMoodleWsTelemetry([
                            'req_id' => $reqId,
                            'func' => $function,
                            'params' => $safeParams,
                            'duration_ms' => 0,
                            'http_code' => 200,
                            'curl_errno' => 0,
                            'cache_source' => 'FILE_CACHE',
                            'caller' => $callerFile,
                            'status' => 'success'
                        ]);
                        return $decodedCached;
                    }
                }
            }
        }

        $token = getMoodleWsToken($conn);
        if (empty($token) || strpos($token, '...') !== false) {
            return [
                'error' => true,
                'message' => 'Moodle WS token not fully set. Please update the token in api/moodle/config.php or in the onboard_settings table.'
            ];
        }

        $postData = array_merge([
            'wstoken' => $token,
            'wsfunction' => $function,
            'moodlewsrestformat' => 'json'
        ], $params);

        // Classification of idempotent read operations safe for bounded transient retry
        static $idempotentFunctions = [
            'core_course_get_courses' => true,
            'core_course_get_courses_by_field' => true,
            'core_course_get_contents' => true,
            'core_enrol_get_users_courses' => true,
            'mod_scorm_get_scorms_by_courses' => true,
            'mod_scorm_get_scorm_scoes' => true,
            'mod_scorm_get_scorm_attempt_count' => true,
            'mod_scorm_get_scorm_sco_tracks' => true,
            'core_completion_get_activities_completion_status' => true,
            'core_user_get_users_by_field' => true,
            'core_user_get_users' => true,
            'mod_quiz_get_user_quiz_attempts' => true,
        ];

        $isIdempotent = isset($idempotentFunctions[$function]);
        // Limit to max 1 bounded retry for idempotent reads (never retry non-idempotent updates)
        $effectiveMaxRetries = $isIdempotent ? max(0, min((int)$maxRetries, 1)) : 0;

        $attempt = 0;
        while ($attempt <= $effectiveMaxRetries) {
            $attempt++;

            $startTime = microtime(true);
            $ch = curl_init(MOODLE_WS_URL);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'User-Agent: HexaOne-Onboard/2.0 (Internal; LMS-Bridge)'
            ]);

            $response = curl_exec($ch);
            $duration = round((microtime(true) - $startTime) * 1000, 1); // milliseconds
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            $curlErrno = curl_errno($ch);
            curl_close($ch);

            $isDbError = false;
            if (!empty($response) && (stripos($response, 'Database connection failed') !== false || stripos($response, 'Error: Database connection') !== false)) {
                $isDbError = true;
            }

            // Circuit breaker trip on HTTP 403 (Abuse/Cloudflare Challenge) or 429 (Rate Limited)
            if ($httpCode === 403 || $httpCode === 429) {
                // Add jitter to open period (30s + 0..5s) to prevent synchronized worker thundering herds
                $openPeriod = 30 + mt_rand(0, 5);
                $activeCircuitBreakerUntil = time() + $openPeriod;

                // Persist cross-worker state atomically to disk
                if (!is_dir($cacheDir)) {
                    @mkdir($cacheDir, 0755, true);
                }
                $cbPayload = json_encode([
                    'open_until' => $activeCircuitBreakerUntil,
                    'tripped_at' => time(),
                    'status_code' => $httpCode,
                    'pid' => getmypid()
                ], JSON_UNESCAPED_SLASHES);
                $tmpCb = $cacheDir . '/cb_' . uniqid('tmp_', true) . '.tmp';
                if (@file_put_contents($tmpCb, $cbPayload, LOCK_EX) !== false) {
                    if (!@rename($tmpCb, $cbFile)) {
                        @unlink($tmpCb);
                        @file_put_contents($cbFile, $cbPayload, LOCK_EX);
                    }
                } else {
                    @file_put_contents($cbFile, $cbPayload, LOCK_EX);
                }

                logMoodleWsTelemetry([
                    'req_id' => $reqId,
                    'func' => $function,
                    'params' => $safeParams,
                    'duration_ms' => $duration,
                    'http_code' => $httpCode,
                    'curl_errno' => $curlErrno,
                    'cache_source' => 'LIVE',
                    'caller' => $callerFile,
                    'attempt' => $attempt,
                    'status' => 'tripped_circuit_breaker_403_429'
                ]);
                $errCtx = $GLOBALS['moodle_error_context'] ?? [];
                logMoodleError($conn, [
                    'moodle_user_id' => $params['userid'] ?? null,
                    'course_id' => $params['courseid'] ?? ($errCtx['course_id'] ?? null),
                    'course_title' => $errCtx['course_title'] ?? null,
                    'ws_function' => $function,
                    'error_type' => 'rate_limited',
                    'http_code' => $httpCode,
                    'error_message' => 'Moodle LMS returned HTTP ' . $httpCode . ' (Security or Rate limit challenge).',
                ]);
                return [
                    'error' => true,
                    'message' => 'Moodle LMS returned HTTP ' . $httpCode . ' (Security or Rate limit challenge). Request throttled (' . $openPeriod . 's).'
                ];
            }

            // Bounded retry ONLY for transient transport failures (cURL timeout/network drop) or gateway errors (502, 503, 504)
            $isTransientTransport = ($curlErrno !== 0) || in_array($httpCode, [502, 503, 504], true);

            if ($isTransientTransport && $isIdempotent && $attempt <= $effectiveMaxRetries) {
                // Bounded linear backoff with jitter (210ms - 350ms)
                $backoffUs = 200000 * $attempt + mt_rand(10000, 150000);
                usleep($backoffUs);
                continue;
            }

            // On successful live call, clear any expired circuit breaker file
            if ($httpCode === 200 && $curlErrno === 0 && !$isDbError) {
                if (file_exists($cbFile)) {
                    @unlink($cbFile);
                }
                $activeCircuitBreakerUntil = 0;
            } elseif ($isProbeWorker) {
                // If probe worker failed with an error, extend circuit breaker by 30s to prevent rapid probe storms
                $openPeriod = 30 + mt_rand(0, 5);
                $activeCircuitBreakerUntil = time() + $openPeriod;
                $cbPayload = json_encode([
                    'open_until' => $activeCircuitBreakerUntil,
                    'tripped_at' => time(),
                    'status_code' => $httpCode,
                    'pid' => getmypid()
                ], JSON_UNESCAPED_SLASHES);
                @file_put_contents($cbFile, $cbPayload, LOCK_EX);
            }

            // Log final outcome
            $liveSuccess = ($httpCode === 200 && $curlErrno === 0 && !$isDbError);
            trackMoodleApiCall($function, 'LIVE', $duration, $liveSuccess);
            logMoodleWsTelemetry([
                'req_id' => $reqId,
                'func' => $function,
                'params' => $safeParams,
                'duration_ms' => $duration,
                'http_code' => $httpCode,
                'curl_errno' => $curlErrno,
                'cache_source' => 'LIVE',
                'caller' => $callerFile,
                'attempt' => $attempt,
                'status' => ($httpCode === 200 && $curlErrno === 0 && !$isDbError) ? 'success' : 'failed'
            ]);

            $errCtx = $GLOBALS['moodle_error_context'] ?? [];
            if ($curlError) {
                logMoodleError($conn, [
                    'moodle_user_id' => $params['userid'] ?? null,
                    'course_id' => $params['courseid'] ?? ($errCtx['course_id'] ?? null),
                    'course_title' => $errCtx['course_title'] ?? null,
                    'ws_function' => $function,
                    'error_type' => ($curlErrno === 28 ? 'timeout' : 'curl_error'),
                    'http_code' => $httpCode ?: 0,
                    'error_message' => 'cURL connection error: ' . $curlError,
                ]);
                return [
                    'error' => true,
                    'message' => 'cURL connection error: ' . $curlError
                ];
            }

            if ($isDbError) {
                logMoodleError($conn, [
                    'moodle_user_id' => $params['userid'] ?? null,
                    'course_id' => $params['courseid'] ?? ($errCtx['course_id'] ?? null),
                    'course_title' => $errCtx['course_title'] ?? null,
                    'ws_function' => $function,
                    'error_type' => 'db_connection_failed',
                    'http_code' => $httpCode ?: 500,
                    'error_message' => 'Moodle LMS returned Database connection failed.',
                ]);
                return [
                    'error' => true,
                    'message' => 'Moodle LMS database is currently busy. Please refresh in a moment.'
                ];
            }

            if ($httpCode !== 200) {
                logMoodleError($conn, [
                    'moodle_user_id' => $params['userid'] ?? null,
                    'course_id' => $params['courseid'] ?? ($errCtx['course_id'] ?? null),
                    'course_title' => $errCtx['course_title'] ?? null,
                    'ws_function' => $function,
                    'error_type' => ($httpCode >= 500 ? 'http_5xx' : 'http_error'),
                    'http_code' => $httpCode,
                    'error_message' => 'Moodle returned HTTP status code ' . $httpCode,
                ]);
                return [
                    'error' => true,
                    'message' => 'Moodle returned HTTP status code ' . $httpCode
                ];
            }

            $decoded = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $cleanSnippet = trim(strip_tags($response));
                if (strlen($cleanSnippet) > 100) $cleanSnippet = substr($cleanSnippet, 0, 100) . '...';
                return [
                    'error' => true,
                    'message' => !empty($cleanSnippet) ? $cleanSnippet : 'Moodle LMS returned an invalid response. Please refresh.'
                ];
            }

            if (is_array($decoded) && (isset($decoded['exception']) || isset($decoded['errorcode']))) {
                return [
                    'error' => true,
                    'message' => trim(strip_tags($decoded['message'] ?? 'Moodle API exception')),
                    'exception' => $decoded['exception'] ?? '',
                    'errorcode' => $decoded['errorcode'] ?? '',
                    'raw' => $decoded
                ];
            }

            // Cache in memory
            $runtimeMemoryCache[$cacheKey] = $decoded;

            // Persist static metadata cache if applicable
            if ($isMetadataFunc && !empty($cacheFile) && is_array($decoded) && empty($decoded['error'])) {
                @file_put_contents($cacheFile, json_encode($decoded), LOCK_EX);
            }

            return $decoded;
        }

        return [
            'error' => true,
            'message' => 'Moodle request failed after retry.'
        ];
    } finally {
        $releaseProbeLock();
    }
}
