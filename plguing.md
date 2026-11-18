Title: Live Content

Description: Fetched live

Source: https://raw.githubusercontent.com/richardg7/moodle-local_ssologin/main/README.md

🔐 SSO Login — External Authentication Plugin for Moodle
A local Moodle plugin that enables Single Sign-On (SSO) authentication from an external system, using AES-256-CBC encryption and HMAC-SHA256 signatures to ensure data security and integrity.

Version: v1.6 · Moodle version code: 2026051802 · Maturity: MATURITY_STABLE Requires: Moodle 5+ · License: GNU GPL v3 Developer: Richard Guedes — President of the Cyber Defense Institute (IDCiber)

🚀 Installation
Copy the ssologin folder to {moodle_root}/local/
Access Moodle as Administrator
Go to Site Administration › Notifications
Moodle will detect the plugin and run the database upgrade
⚙️ Configuration
After installing, go to:

Site Administration › Plugins › Local Plugins › SSO Login

Setting	Default	Description
Shared Secret Key	Auto-generated	HMAC key shared between Moodle and external system
Token Expiry (seconds)	300	Maximum age of a valid token
Enable Legacy Mode	Off	Allow requests without HMAC signature (transition only)
Enable JIT Provisioning	Off	Auto-create Moodle users on first SSO login
Enable Profile Sync	Off	Update user profile fields on every SSO login
Reading settings in code
php

$secret      = get_config('local_ssologin', 'secretkey');
$tokenexpire = get_config('local_ssologin', 'tokenexpire');
$legacymode  = get_config('local_ssologin', 'legacymode');
$jitprov     = get_config('local_ssologin', 'jitprovisioning');
$profilesync = get_config('local_ssologin', 'profilesync');
🔗 How It Works

External System                          Moodle (local_ssologin)
──────────────                           ───────────────────────
1. Build JSON payload
   { username, email, timestamp, nonce }
2. Derive AES key: SHA-256(secret)
3. Encrypt payload (AES-256-CBC)
4. Sign encdata with HMAC-SHA256
5. Redirect user to Moodle ──────────►  6. Verify HMAC signature (before decrypt)
                                         7. Decrypt payload
                                         8. Validate timestamp (clock-skew)
                                         9. Check nonce (replay protection)
                                        10. Account Linking (Fallback to email)
                                        11. JIT provision user (if enabled)
                                        12. Sync profile (if enabled)
                                        13. complete_user_login()
                                        14. Secure redirect ◄── user lands in Moodle
IMPORTANT

Protocol rules — all three must be followed exactly:

The AES key is always SHA-256(secret) — never use the raw secret as the key
The encrypted payload format is base64( base64(ciphertext) + '::' + iv )
The HMAC signs the already-encrypted Base64 string — not the raw JSON
💻 Integration Examples
🔹 PHP
php

<?php
/**
 * Generates the SSO URL to redirect the user to Moodle.
 */
function generate_sso_url(array $userdata, string $sharedSecret, string $moodleLoginUrl, string $redirectAfter = ''): string {
    // 1. Build the JSON payload
    $payload = json_encode(array_merge([
        'timestamp' => time(),
        'nonce'     => bin2hex(random_bytes(16)),
    ], $userdata));
    // 2. Derive AES-256 key via SHA-256 (required — matches locallib.php)
    $key = hash('sha256', $sharedSecret, true);
    // 3. Generate random IV and encrypt (AES-256-CBC)
    $iv         = random_bytes(16);
    $ciphertext = openssl_encrypt($payload, 'aes-256-cbc', $key, 0, $iv);
    // 4. Build encdata: base64(ciphertext . '::' . iv)
    $encdata = base64_encode($ciphertext . '::' . $iv);
    // 5. Sign the already-encrypted encdata with HMAC-SHA256
    $signature = hash_hmac('sha256', $encdata, $sharedSecret);
    // 6. Build final URL
    $params = ['data' => $encdata, 'sig' => $signature];
    if (!empty($redirectAfter)) {
        $params['redirect'] = $redirectAfter;
    }
    return $moodleLoginUrl . '?' . http_build_query($params);
}
// Usage
$url = generate_sso_url(
    [
        'username'  => 'joao.silva',
        'email'     => 'joao@empresa.com',
        'firstname' => 'João',
        'lastname'  => 'Silva',
    ],
    sharedSecret:   'YOUR_SECRET_KEY',
    moodleLoginUrl: 'https://moodle.yoursite.com/local/ssologin/login.php',
    redirectAfter:  '/course/view.php?id=5'
);
header('Location: ' . $url);
exit;
🔹 Python
Requirement: pip install pycryptodome

python

import time, json, base64, hmac, hashlib, os, urllib.parse
from Crypto.Cipher import AES
from Crypto.Util.Padding import pad
def generate_sso_url(userdata: dict, shared_secret: str, moodle_login_url: str, redirect_after: str = '') -> str:
    secret_bytes = shared_secret.encode('utf-8')
    # 1. Build the JSON payload
    payload = json.dumps({
        **userdata,
        'timestamp': int(time.time()),
        'nonce':     os.urandom(16).hex(),
    }, ensure_ascii=False).encode('utf-8')
    # 2. Derive AES-256 key via SHA-256 (required — matches locallib.php)
    key = hashlib.sha256(secret_bytes).digest()  # 32 bytes
    # 3. Generate random IV and encrypt (AES-256-CBC + PKCS7 padding)
    iv         = os.urandom(16)
    cipher     = AES.new(key, AES.MODE_CBC, iv)
    ciphertext = cipher.encrypt(pad(payload, AES.block_size))
    # 4. Build encdata: base64( base64(ciphertext) + '::' + iv )
    #    openssl_encrypt in PHP returns ciphertext as Base64 (flag 0) — mirror that here
    php_ciphertext = base64.b64encode(ciphertext).decode('ascii')