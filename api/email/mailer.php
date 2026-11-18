<?php
// File: api/email/mailer.php
// Pure-PHP SMTP Client & PHPMailer bridge for HexaLearn Onboarding Email Module
// Supports Zoho SMTP (smtp.zoho.com:465 SSL) with corporate credentials contact-us@hexalearn.com

if (!class_exists('PHPMailer\PHPMailer\PHPMailer') && !class_exists('PHPMailer')) {
    $possiblePaths = [
        __DIR__ . '/../../../php-mail/PHPMailer.php',
        __DIR__ . '/../../php-mail/PHPMailer.php',
        __DIR__ . '/../../hex-timesheet/php-mail/PHPMailer.php',
        __DIR__ . '/../php-mail/PHPMailer.php',
        __DIR__ . '/php-mail/PHPMailer.php',
        'D:/xaamp/htdocs/hex-timesheet/php-mail/PHPMailer.php',
        'D:/xaamp/htdocs/cadmin/config/phpmailer/class.phpmailer.php'
    ];
    foreach ($possiblePaths as $path) {
        if (file_exists($path)) {
            $dir = dirname($path);
            if (file_exists($dir . '/Exception.php')) @require_once $dir . '/Exception.php';
            if (file_exists($dir . '/PHPMailer.php')) @require_once $dir . '/PHPMailer.php';
            if (file_exists($dir . '/SMTP.php')) @require_once $dir . '/SMTP.php';
            if (file_exists($dir . '/class.phpmailer.php')) @require_once $dir . '/class.phpmailer.php';
            break;
        }
    }
}

class SmtpMailer {
    private $host;
    private $port;
    private $user;
    private $pass;
    private $crypto;
    private $fromEmail;
    private $fromName;
    private $timeout;
    private $debugLog = [];

    public function __construct(array $config = []) {
        $this->host = trim($config['host'] ?? 'smtp.zoho.com') ?: 'smtp.zoho.com';
        $this->port = intval($config['port'] ?? 465) ?: 465;
        $this->user = trim($config['user'] ?? 'contact-us@hexalearn.com') ?: 'contact-us@hexalearn.com';
        $this->pass = !empty($config['pass']) ? $config['pass'] : 'XplTdnq@m12x';
        $this->crypto = strtolower(trim($config['crypto'] ?? 'ssl')) ?: 'ssl'; // 'ssl', 'tls', 'none'
        $this->fromEmail = trim($config['from_email'] ?? 'contact-us@hexalearn.com') ?: 'contact-us@hexalearn.com';
        $this->fromName = trim($config['from_name'] ?? 'Hexalearn') ?: 'Hexalearn';
        $this->timeout = intval($config['timeout'] ?? 15);
    }

    private function log($msg) {
        $this->debugLog[] = $msg;
    }

    public function getLogs() {
        return $this->debugLog;
    }

    /**
     * Send an HTML email to recipient (with optional CC recipients)
     */
    public function send($toEmail, $toName, $subject, $htmlBody, $ccEmails = []) {
        $toEmail = trim($toEmail);
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => "Invalid recipient email address: '{$toEmail}'"];
        }

        // Try PHPMailer first if available
        if (class_exists('PHPMailer\PHPMailer\PHPMailer') || class_exists('PHPMailer')) {
            try {
                $mail = class_exists('PHPMailer\PHPMailer\PHPMailer') 
                    ? new \PHPMailer\PHPMailer\PHPMailer(true) 
                    : new \PHPMailer(true);

                $mail->isSMTP();
                $mail->Host       = 'smtp.zoho.com';
                $mail->SMTPAuth   = true;
                $mail->Username   = 'contact-us@hexalearn.com';
                $mail->Password   = 'XplTdnq@m12x';
                $mail->SMTPSecure = 'ssl';
                $mail->Port       = 465;

                $mail->setFrom('contact-us@hexalearn.com', 'Hexalearn');
                $mail->addAddress($toEmail, $toName ?: $toEmail);
                if (!empty($ccEmails)) {
                    foreach ((array)$ccEmails as $cc) {
                        $cc = trim($cc);
                        if (filter_var($cc, FILTER_VALIDATE_EMAIL)) {
                            $mail->addCC($cc);
                        }
                    }
                }
                $mail->isHTML(true);
                $mail->CharSet = 'UTF-8';
                $mail->Subject = $subject;
                $mail->Body    = $htmlBody;
                $mail->AltBody = strip_tags($htmlBody);

                $mail->send();
                return ['success' => true];
            } catch (\Exception $e) {
                $this->log("PHPMailer attempt: " . $e->getMessage() . " - switching to socket SMTP.");
            }
        }

        if (empty($this->host)) {
            return ['success' => false, 'error' => "SMTP Host is not configured."];
        }

        $remoteSocket = ($this->crypto === 'ssl') ? "ssl://{$this->host}:{$this->port}" : "tcp://{$this->host}:{$this->port}";
        
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ]);

        $this->log("Connecting to {$remoteSocket} (timeout: {$this->timeout}s)...");
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client($remoteSocket, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $context);

        if (!$socket) {
            $err = "Could not connect to SMTP server {$this->host}:{$this->port}. ($errno: $errstr)";
            $this->log("ERR: {$err}");
            return ['success' => false, 'error' => $err];
        }

        stream_set_timeout($socket, $this->timeout);

        // 1. Read greeting banner
        $response = $this->readResponse($socket);
        if (!$this->isOkCode($response, [220])) {
            fclose($socket);
            return ['success' => false, 'error' => "SMTP Greeting failed: {$response}"];
        }

        // 2. Send EHLO
        $clientName = !empty($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'hexalearn.com';
        $this->writeCommand($socket, "EHLO {$clientName}");
        $ehloRes = $this->readResponse($socket);

        // 3. Negotiate STARTTLS if requested
        if ($this->crypto === 'tls' || ($this->port === 587 && $this->crypto !== 'none')) {
            $this->writeCommand($socket, "STARTTLS");
            $tlsRes = $this->readResponse($socket);
            if (!$this->isOkCode($tlsRes, [220])) {
                fclose($socket);
                return ['success' => false, 'error' => "STARTTLS failed: {$tlsRes}"];
            }

            $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            }
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            }

            $secureSuccess = @stream_socket_enable_crypto($socket, true, $cryptoMethod);
            if (!$secureSuccess) {
                fclose($socket);
                return ['success' => false, 'error' => "TLS handshake with SMTP server failed."];
            }

            // Resend EHLO after TLS established
            $this->writeCommand($socket, "EHLO {$clientName}");
            $ehloRes = $this->readResponse($socket);
        }

        // 4. Authenticate if username and password provided
        if (!empty($this->user) && !empty($this->pass)) {
            $this->writeCommand($socket, "AUTH LOGIN");
            $authRes = $this->readResponse($socket);
            if (!$this->isOkCode($authRes, [334])) {
                fclose($socket);
                return ['success' => false, 'error' => "AUTH LOGIN not accepted: {$authRes}"];
            }

            // Send base64 username
            $this->writeCommand($socket, base64_encode($this->user));
            $userRes = $this->readResponse($socket);
            if (!$this->isOkCode($userRes, [334])) {
                fclose($socket);
                return ['success' => false, 'error' => "SMTP Username rejected: {$userRes}"];
            }

            // Send base64 password
            $this->writeCommand($socket, base64_encode($this->pass));
            $passRes = $this->readResponse($socket);
            if (!$this->isOkCode($passRes, [235])) {
                fclose($socket);
                return ['success' => false, 'error' => "SMTP Authentication failed (wrong username/password): {$passRes}"];
            }
        }

        // 5. MAIL FROM
        $this->writeCommand($socket, "MAIL FROM:<{$this->fromEmail}>");
        $mailFromRes = $this->readResponse($socket);
        if (!$this->isOkCode($mailFromRes, [250])) {
            fclose($socket);
            return ['success' => false, 'error' => "MAIL FROM rejected: {$mailFromRes}"];
        }

        // 6. RCPT TO
        $this->writeCommand($socket, "RCPT TO:<{$toEmail}>");
        $rcptRes = $this->readResponse($socket);
        if (!$this->isOkCode($rcptRes, [250, 251])) {
            fclose($socket);
            return ['success' => false, 'error' => "Recipient {$toEmail} rejected by server: {$rcptRes}"];
        }

        $validCcs = [];
        if (!empty($ccEmails)) {
            foreach ((array)$ccEmails as $cc) {
                $cc = trim($cc);
                if (filter_var($cc, FILTER_VALIDATE_EMAIL)) {
                    $validCcs[] = $cc;
                    $this->writeCommand($socket, "RCPT TO:<{$cc}>");
                    $this->readResponse($socket);
                }
            }
        }

        // 7. DATA
        $this->writeCommand($socket, "DATA");
        $dataRes = $this->readResponse($socket);
        if (!$this->isOkCode($dataRes, [354])) {
            fclose($socket);
            return ['success' => false, 'error' => "DATA command rejected: {$dataRes}"];
        }

        // 8. Headers & Body
        $encodedSubject = "=?UTF-8?B?" . base64_encode($subject) . "?=";
        $fromHeaderName = !empty($this->fromName) ? "=?UTF-8?B?" . base64_encode($this->fromName) . "?=" : "";
        $fromFormatted = !empty($fromHeaderName) ? "{$fromHeaderName} <{$this->fromEmail}>" : "<{$this->fromEmail}>";
        
        $toHeaderName = !empty($toName) ? "=?UTF-8?B?" . base64_encode($toName) . "?=" : "";
        $toFormatted = !empty($toHeaderName) ? "{$toHeaderName} <{$toEmail}>" : "<{$toEmail}>";

        $dateStr = date('r');
        $msgId = "<" . md5(uniqid(microtime(true), true)) . "@" . $clientName . ">";

        $headers = [
            "Date: {$dateStr}",
            "From: {$fromFormatted}",
            "To: {$toFormatted}",
            "Subject: {$encodedSubject}",
            "Message-ID: {$msgId}",
            "MIME-Version: 1.0",
            "Content-Type: text/html; charset=UTF-8",
            "Content-Transfer-Encoding: base64",
            "X-Mailer: HexaLearn-Onboard-SMTP/1.0"
        ];

        if (!empty($validCcs)) {
            $headers[] = "Cc: " . implode(', ', $validCcs);
        }

        $payload = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($htmlBody)) . "\r\n.";

        fwrite($socket, $payload . "\r\n");
        $sendRes = $this->readResponse($socket);

        if (!$this->isOkCode($sendRes, [250])) {
            fclose($socket);
            return ['success' => false, 'error' => "Message body rejected: {$sendRes}"];
        }

        // 9. QUIT
        $this->writeCommand($socket, "QUIT");
        $this->readResponse($socket);
        fclose($socket);

        return ['success' => true, 'message' => 'Email sent successfully.'];
    }

    private function writeCommand($socket, $cmd) {
        $this->log(">> " . (stripos($cmd, 'AUTH') !== false || strlen($cmd) > 100 ? substr($cmd, 0, 30) . '...' : $cmd));
        fwrite($socket, $cmd . "\r\n");
    }

    private function readResponse($socket) {
        $res = '';
        while (!feof($socket)) {
            $line = fgets($socket, 512);
            if ($line === false) break;
            $res .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
            if (strlen(trim($line)) === 3) {
                break;
            }
        }
        $clean = trim($res);
        $this->log("<< " . $clean);
        return $clean;
    }

    private function isOkCode($res, array $expectedCodes) {
        $code = intval(substr(trim($res), 0, 3));
        return in_array($code, $expectedCodes, true);
    }
}

