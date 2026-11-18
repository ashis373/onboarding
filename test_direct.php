<?php
header('Content-Type: text/plain; charset=UTF-8');
require_once __DIR__ . '/api/db.php';
require_once __DIR__ . '/api/moodle/config.php';

$secret = '6724164445813902f62a0b22f9545058679c38ff352ea5e3a66e483a334aae22';
$time = time();
$nonce = bin2hex(random_bytes(16));
$payload = json_encode([
    'timestamp' => $time,
    'nonce'     => $nonce,
    'username'  => 'ashisr',
    'email'     => 'ashiskrout1@gmail.com',
    'firstname' => 'Ashis',
    'lastname'  => 'Rout'
], JSON_UNESCAPED_SLASHES);

$key = hash('sha256', $secret, true);
$iv = random_bytes(16);
$ciphertext = openssl_encrypt($payload, 'aes-256-cbc', $key, 0, $iv);
$encdata = base64_encode($ciphertext . '::' . $iv);
$sig = hash_hmac('sha256', $encdata, $secret);

$playerPath = '/mod/scorm/player.php?' . http_build_query([
    'cm'         => 2,
    'scoid'      => 2,
    'currentorg' => 'M1_-_Onboarding_Program_ORG',
    'mode'       => 'normal',
    'display'    => 'popup'
]);

$ssoUrl = 'https://lms.moodle.hexalearn.com/local/ssologin/login.php?' . http_build_query([
    'data'     => $encdata,
    'sig'      => $sig,
    'redirect' => $playerPath
]);

echo "SSO Target: $ssoUrl\n\n";

$cookieFile = __DIR__ . '/moodle_cookie.txt';
if (file_exists($cookieFile)) unlink($cookieFile);

$ch = curl_init($ssoUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$html = curl_exec($ch);
$finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
curl_close($ch);

echo "Final URL Reached: $finalUrl\n";
echo "Has 'Parameter missing'?: " . (strpos($html, 'Parameter missing') !== false ? "YES (FAILED)" : "NO (SUCCESS!)") . "\n";
echo "Has SCORM Player / Storyline?: " . (strpos($html, 'scorm') !== false || strpos($html, 'scormobject') !== false ? "YES (SUCCESS!)" : "NO") . "\n";

if (preg_match('/<title>(.*?)<\/title>/i', $html, $m)) {
    echo "Page Title: " . trim($m[1]) . "\n";
}
