<?php
// Secure Moodle API Configuration
// Stored strictly server-side in PHP — NEVER exposed to the frontend/browser

if (!defined('MOODLE_WS_URL')) {
    define('MOODLE_WS_URL', 'https://lms.moodle.hexalearn.com/webservice/rest/server.php');
}

/**
 * Retrieves the Moodle Web Services Token.
 * Priority:
 * 1. Environment variable MOODLE_WS_TOKEN
 * 2. Database table onboard_settings (key = 'moodle_ws_token')
 * 3. Fallback token constant below
 */
function getMoodleWsToken($conn = null) {
    static $cachedToken = null;
    if ($cachedToken !== null) {
        return $cachedToken;
    }

    // 1. Environment variable
    $envToken = getenv('MOODLE_WS_TOKEN');
    if (!empty($envToken)) {
        $cachedToken = trim($envToken);
        return $cachedToken;
    }

    // 2. onboard_settings table
    if ($conn) {
        $stmt = $conn->prepare("SELECT value FROM onboard_settings WHERE `key` = 'moodle_ws_token' LIMIT 1");
        if ($stmt) {
            $stmt->execute();
            $res = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!empty($res['value'])) {
                $cachedToken = trim($res['value']);
                return $cachedToken;
            }
        }
    }

    // 3. Fallback constant token
    $cachedToken = '49221326100285c9e1c5dd4b1a33f5e1';
    return $cachedToken;
}

if (!defined('MOODLE_SSO_LOGIN_URL')) {
    define('MOODLE_SSO_LOGIN_URL', 'https://lms.moodle.hexalearn.com/local/ssologin/login.php');
}

/**
 * Retrieves the Moodle SSO Shared Secret Key (local_ssologin).
 */
function getMoodleSsoSecret($conn = null) {
    $envSecret = getenv('MOODLE_SSO_SECRET');
    if (!empty($envSecret)) {
        return trim($envSecret);
    }

    if ($conn) {
        $stmt = $conn->prepare("SELECT value FROM onboard_settings WHERE `key` = 'moodle_sso_secret' LIMIT 1");
        if ($stmt) {
            $stmt->execute();
            $res = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!empty($res['value'])) {
                return trim($res['value']);
            }
        }
    }

    // Shared Secret Key configured in Moodle local_ssologin plugin
    return '6724164445813902f62a0b22f9545058679c38ff352ea5e3a66e483a334aae22';
}
