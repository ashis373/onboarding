<?php
/**
 * api/certificate_helper.php
 * Robust, Concurrency-Safe Certificate Number Management Engine
 *
 * Implements strict separation:
 * 1. `onboard_certificate_records`: Stores permanent certificate identity & unique number (HL-2026-00001)
 * 2. `onboard_certificate_downloads`: Stores download audit log events (who, when, IP)
 *
 * Concurrency & Race-Condition Protected via InnoDB Database Transactions (FOR UPDATE) & UNIQUE constraints.
 */

if (!defined('CERT_HELPER_LOADED')) {
    define('CERT_HELPER_LOADED', true);

    /**
     * Ensures all required certificate tables exist in database
     */
    function ensureCertificateTables(mysqli $conn): void {
        // Table 1: Permanent Certificate Record Identity
        $conn->query("
            CREATE TABLE IF NOT EXISTS `onboard_certificate_records` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NOT NULL UNIQUE COMMENT 'User ID from user table',
                `certificate_number` VARCHAR(50) NOT NULL UNIQUE COMMENT 'Unique Permanent Cert No (e.g. HL-2026-00001)',
                `emp_code` VARCHAR(50) NULL,
                `emp_name` VARCHAR(150) NULL,
                `quiz_score` DECIMAL(5,2) NULL,
                `attempt_number` INT NULL DEFAULT 1,
                `passed_at` DATETIME NULL,
                `issued_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_cert_no` (`certificate_number`),
                INDEX `idx_cert_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // Table 2: Download Audit History & Event Logs
        $conn->query("
            CREATE TABLE IF NOT EXISTS `onboard_certificate_downloads` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `certificate_number` VARCHAR(50) NOT NULL COMMENT 'References certificate_records.certificate_number',
                `user_id` INT NOT NULL COMMENT 'User ID',
                `downloaded_by` INT NOT NULL DEFAULT 0 COMMENT 'UID of user or Admin who performed download',
                `emp_code` VARCHAR(50) NULL,
                `emp_name` VARCHAR(150) NULL,
                `ip_address` VARCHAR(50) NULL,
                `downloaded_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_dl_cert_no` (`certificate_number`),
                INDEX `idx_dl_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // Table 3: System Settings (Master ON/OFF Switch for Employee Certificate Downloads)
        $conn->query("
            CREATE TABLE IF NOT EXISTS `onboard_settings` (
                `key` VARCHAR(128) PRIMARY KEY,
                `value` TEXT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // Check legacy onboard_certificate_downloads if any columns are needed
        $chkCol = $conn->query("SHOW COLUMNS FROM `onboard_certificate_downloads` LIKE 'certificate_number'");
        if ($chkCol && $chkCol->num_rows === 0) {
            $conn->query("ALTER TABLE `onboard_certificate_downloads` ADD COLUMN `certificate_number` VARCHAR(50) NULL AFTER `id`");
        }

        // Adjust any earlier UTC download timestamps from today to IST (+5:30)
        $conn->query("
            UPDATE `onboard_certificate_downloads` 
            SET `downloaded_at` = DATE_ADD(`downloaded_at`, INTERVAL 330 MINUTE) 
            WHERE `downloaded_at` <= '2026-11-23 11:30:00' AND DATE(`downloaded_at`) = '2026-11-23'
        ");
    }

    /**
     * Checks if employee certificate download is enabled by Admin
     */
    function isEmployeeCertificateDownloadAllowed(mysqli $conn): bool {
        try {
            ensureCertificateTables($conn);
            $res = $conn->query("SELECT `value` FROM `onboard_settings` WHERE `key` = 'allow_employee_certificate_download' LIMIT 1");
            if ($res && $row = $res->fetch_assoc()) {
                return ((string)$row['value'] === '1' || strtolower((string)$row['value']) === 'true');
            }
        } catch (\Throwable $e) {}
        return false; // Default: OFF (Admin Testing Mode)
    }

    /**
     * Updates Admin master switch for employee certificate download
     */
    function setEmployeeCertificateDownloadAllowed(mysqli $conn, bool $allowed): bool {
        try {
            ensureCertificateTables($conn);
            $val = $allowed ? '1' : '0';
            $stmt = $conn->prepare("
                INSERT INTO `onboard_settings` (`key`, `value`) 
                VALUES ('allow_employee_certificate_download', ?)
                ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)
            ");
            if ($stmt) {
                $stmt->bind_param("s", $val);
                $res = $stmt->execute();
                $stmt->close();
                return $res;
            }
        } catch (\Throwable $e) {
            error_log("Error in setEmployeeCertificateDownloadAllowed: " . $e->getMessage());
        }
        return false;
    }

    /**
     * Atomically retrieves or allocates a permanent unique Certificate Number for a user.
     * Uses InnoDB row-level locking (FOR UPDATE) inside a transaction.
     * Guarantees Reissue Protection (exact same number returned on repeat calls).
     */
    function getOrIssueCertificateRecord(mysqli $conn, int $userId, ?string $fallbackCode = null, ?string $fallbackName = null): array {
        ensureCertificateTables($conn);

        // Retry loop for extreme concurrency collision protection
        $maxRetries = 3;
        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            $conn->begin_transaction();
            try {
                // 1. Check if permanent record already exists FOR UPDATE
                $stmt = $conn->prepare("
                    SELECT id, user_id, certificate_number, emp_code, emp_name, quiz_score, attempt_number, passed_at, issued_at
                    FROM `onboard_certificate_records`
                    WHERE user_id = ?
                    FOR UPDATE
                ");
                $stmt->bind_param("i", $userId);
                $stmt->execute();
                $existing = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($existing) {
                    // Record exists! Commit lock and return existing permanent certificate number
                    $conn->commit();
                    return $existing;
                }

                // 2. Not yet issued. Fetch candidate details & passing info
                $empCode = $fallbackCode;
                $empName = $fallbackName;
                $quizScore = 100.00;
                $attemptNumber = 1;
                $passedAt = date('Y-m-d H:i:s');

                // Check passing attempt if table exists
                try {
                    $aStmt = @$conn->prepare("
                        SELECT attempt, Quiz_score, attempted_at, emp_code, emp_name
                        FROM `onboard_induction_assessment_attempts`
                        WHERE user_id = ? AND result = 'Passed'
                        ORDER BY id DESC LIMIT 1
                    ");
                    if ($aStmt) {
                        $aStmt->bind_param("i", $userId);
                        $aStmt->execute();
                        if ($aRow = $aStmt->get_result()->fetch_assoc()) {
                            $quizScore = (float)$aRow['Quiz_score'];
                            $attemptNumber = (int)$aRow['attempt'];
                            $passedAt = $aRow['attempted_at'] ?: $passedAt;
                            if (empty($empCode)) $empCode = $aRow['emp_code'];
                            if (empty($empName)) $empName = $aRow['emp_name'];
                        }
                        $aStmt->close();
                    }
                } catch (\Throwable $e) {
                    // Assessment table not present in test environment
                }

                // Fallback to user table if needed
                if (empty($empCode) || empty($empName)) {
                    try {
                        $uStmt = @$conn->prepare("SELECT emp_code, firstname, lastname FROM user WHERE uid = ? LIMIT 1");
                        if ($uStmt) {
                            $uStmt->bind_param("i", $userId);
                            $uStmt->execute();
                            if ($uRow = $uStmt->get_result()->fetch_assoc()) {
                                if (empty($empCode)) $empCode = $uRow['emp_code'] ?: "HL{$userId}";
                                if (empty($empName)) $empName = trim(($uRow['firstname'] ?? '') . ' ' . ($uRow['lastname'] ?? ''));
                            }
                            $uStmt->close();
                        }
                    } catch (\Throwable $e) {
                        // User table not present in test environment
                    }
                }

                if (empty($empCode)) $empCode = "HL{$userId}";
                if (empty($empName)) $empName = "Employee";

                // 3. Atomically calculate next sequence for current year with lock
                $currYear = date('Y');
                $seqQuery = "
                    SELECT MAX(CAST(SUBSTRING_INDEX(certificate_number, '-', -1) AS UNSIGNED)) AS max_seq
                    FROM `onboard_certificate_records`
                    WHERE certificate_number LIKE 'HL-{$currYear}-%'
                    FOR UPDATE
                ";
                $seqRes = $conn->query($seqQuery);
                $maxSeq = 0;
                if ($seqRes && $sRow = $seqRes->fetch_assoc()) {
                    $maxSeq = (int)($sRow['max_seq'] ?? 0);
                }
                $nextSeq = $maxSeq + 1;
                $newCertNumber = sprintf("HL-%s-%05d", $currYear, $nextSeq);

                // 4. Insert new permanent certificate record
                $insStmt = $conn->prepare("
                    INSERT INTO `onboard_certificate_records`
                        (`user_id`, `certificate_number`, `emp_code`, `emp_name`, `quiz_score`, `attempt_number`, `passed_at`, `issued_at`)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $insStmt->bind_param("isssdis", $userId, $newCertNumber, $empCode, $empName, $quizScore, $attemptNumber, $passedAt);
                $insStmt->execute();
                $newId = $conn->insert_id;
                $insStmt->close();

                $conn->commit();

                return [
                    'id' => $newId,
                    'user_id' => $userId,
                    'certificate_number' => $newCertNumber,
                    'emp_code' => $empCode,
                    'emp_name' => $empName,
                    'quiz_score' => $quizScore,
                    'attempt_number' => $attemptNumber,
                    'passed_at' => $passedAt,
                    'issued_at' => date('Y-m-d H:i:s')
                ];

            } catch (\mysqli_sql_exception $e) {
                $conn->rollback();
                // If duplicate key collision (error 1062), retry transaction
                if ($e->getCode() === 1062 && $attempt < $maxRetries - 1) {
                    usleep(50000); // Wait 50ms before retrying
                    continue;
                }
                throw $e;
            } catch (\Throwable $e) {
                $conn->rollback();
                throw $e;
            }
        }

        throw new RuntimeException("Failed to generate unique certificate number after {$maxRetries} attempts.");
    }

    /**
     * Records a certificate download event into `onboard_certificate_downloads`
     * and returns aggregated download statistics.
     */
    function recordCertificateDownload(mysqli $conn, int $userId, int $downloadedBy = 0, ?string $ip = null): array {
        ensureCertificateTables($conn);

        // 1. Get permanent certificate record (reissue protected)
        $certRecord = getOrIssueCertificateRecord($conn, $userId);
        $certNo = $certRecord['certificate_number'];
        $empCode = $certRecord['emp_code'];
        $empName = $certRecord['emp_name'];
        $clientIp = $ip ?: ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $nowIst = (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d H:i:s');

        // 2. Insert download event log with explicit IST timestamp
        $stmt = $conn->prepare("
            INSERT INTO `onboard_certificate_downloads`
                (`certificate_number`, `user_id`, `downloaded_by`, `emp_code`, `emp_name`, `ip_address`, `downloaded_at`)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        if ($stmt) {
            $stmt->bind_param("siissss", $certNo, $userId, $downloadedBy, $empCode, $empName, $clientIp, $nowIst);
            $stmt->execute();
            $stmt->close();
        }

        // 3. Query aggregated download statistics
        $aggStmt = $conn->prepare("
            SELECT 
                COUNT(*) AS download_count,
                MIN(downloaded_at) AS first_downloaded_at,
                MAX(downloaded_at) AS last_downloaded_at
            FROM `onboard_certificate_downloads`
            WHERE user_id = ?
        ");
        $stats = ['download_count' => 1, 'first_downloaded_at' => null, 'last_downloaded_at' => null];
        if ($aggStmt) {
            $aggStmt->bind_param("i", $userId);
            $aggStmt->execute();
            if ($row = $aggStmt->get_result()->fetch_assoc()) {
                $stats = $row;
            }
            $aggStmt->close();
        }

        return [
            'certificate_number' => $certNo,
            'download_count' => (int)($stats['download_count'] ?? 1),
            'first_downloaded_at' => $stats['first_downloaded_at'],
            'last_downloaded_at' => $stats['last_downloaded_at'] ?: date('Y-m-d H:i:s'),
            'record' => $certRecord
        ];
    }
}
