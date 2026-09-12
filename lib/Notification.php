<?php
/**
 * rcloneCWP Notification Engine
 *
 * Multi-channel notification delivery system supporting Email (SMTP & mail),
 * Telegram Bot API, and Webhooks (Slack, Discord, generic JSON).
 *
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP
 */

namespace CWP\RcloneCWP;

use Exception;

class Notification
{
    /**
     * @var Database
     */
    private $db;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var Encryption
     */
    private $encryption;

    /**
     * Supported notification types
     */
    const TYPE_EMAIL = 'email';
    const TYPE_TELEGRAM = 'telegram';
    const TYPE_WEBHOOK = 'webhook';

    /**
     * Supported notification events
     */
    const EVENT_BACKUP_START = 'backup_start';
    const EVENT_BACKUP_COMPLETE = 'backup_complete';
    const EVENT_BACKUP_FAIL = 'backup_fail';
    const EVENT_RESTORE_START = 'restore_start';
    const EVENT_RESTORE_COMPLETE = 'restore_complete';
    const EVENT_RESTORE_FAIL = 'restore_fail';
    const EVENT_TEST = 'test';

    public function __construct(Database $db = null, Logger $logger = null, Encryption $encryption = null)
    {
        $this->db = $db ?: Database::getInstance();
        $this->logger = $logger ?: new Logger(RCLONE_LOG_DIR, $this->db);
        $this->encryption = $encryption ?: new Encryption();
    }

    /**
     * Enforce administrative authorization for notification management mutations
     *
     * @throws Exception If called from an unauthorized context
     */
    private function assertAdminAccess(): void
    {
        if (php_sapi_name() === 'cli') {
            return;
        }

        if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
            @session_start();
        }

        $isAdmin = (
            (!empty($_SESSION['is_admin']) && $_SESSION['is_admin'] === true) ||
            (!empty($_SESSION['username']) && $_SESSION['username'] === 'root')
        );

        if (!$isAdmin) {
            throw new Exception('Access denied: Administrative privileges required.');
        }
    }

    /**
     * Dispatch notification for an event to all subscribed and active channels
     *
     * @param string $event Event identifier (e.g. 'backup_complete', 'backup_fail')
     * @param array $payload Context payload (job_id, job_name, status, files_count, bytes, duration, error, etc.)
     * @return array Summary of dispatch results ['sent' => int, 'failed' => int, 'details' => array]
     */
    public function notify(string $event, array $payload = []): array
    {
        $notifications = $this->getActiveNotificationsForEvent($event);
        if (empty($notifications)) {
            return ['sent' => 0, 'failed' => 0, 'details' => []];
        }

        $sent = 0;
        $failed = 0;
        $details = [];

        foreach ($notifications as $notification) {
            $notifId = (int) $notification['id'];
            $notifName = $notification['name'];
            $notifType = $notification['type'];

            try {
                $config = $this->decodeAndDecryptConfig($notification['config'], $notifType);
                $res = $this->sendToChannel($notifType, $config, $event, $payload, $notifName);

                $details[] = [
                    'id' => $notifId,
                    'name' => $notifName,
                    'type' => $notifType,
                    'ok' => $res['ok'] ?? false,
                    'message' => $res['message'] ?? '',
                    'error' => $res['error'] ?? null,
                ];

                if ($res['ok']) {
                    $sent++;
                } else {
                    $failed++;
                    $this->logger->warning("Notification #{$notifId} ('{$notifName}') failed: " . ($res['error'] ?? 'Unknown error'));
                }

                $this->logNotificationDispatch($notifId, $notifName, $notifType, $event, $res, $payload);
            } catch (Exception $e) {
                $failed++;
                $details[] = [
                    'id' => $notifId,
                    'name' => $notifName,
                    'type' => $notifType,
                    'ok' => false,
                    'error' => $e->getMessage(),
                ];
                $this->logger->error("Exception dispatching notification #{$notifId}: " . $e->getMessage());
            }
        }

        return [
            'sent' => $sent,
            'failed' => $failed,
            'details' => $details,
        ];
    }

    /**
     * Dispatch notification to a single channel
     *
     * @param string $type Channel type ('email', 'telegram', 'webhook')
     * @param array $config Decrypted channel configuration
     * @param string $event Event identifier
     * @param array $payload Context payload
     * @param string $name Notification channel name
     * @return array ['ok' => bool, 'message' => string, 'error' => string|null]
     */
    public function sendToChannel(string $type, array $config, string $event, array $payload, string $name = ''): array
    {
        switch ($type) {
            case self::TYPE_EMAIL:
                return $this->dispatchEmail($config, $event, $payload);
            case self::TYPE_TELEGRAM:
                return $this->dispatchTelegram($config, $event, $payload);
            case self::TYPE_WEBHOOK:
                return $this->dispatchWebhook($config, $event, $payload);
            default:
                return ['ok' => false, 'error' => "Unsupported notification type: {$type}"];
        }
    }

    // ========================================================================
    // EMAIL CHANNEL DISPATCHER & SMTP CLIENT
    // ========================================================================

    /**
     * Format and dispatch an email notification
     *
     * @param array $config
     * @param string $event
     * @param array $payload
     * @return array
     */
    private function dispatchEmail(array $config, string $event, array $payload): array
    {
        $to = trim($config['to'] ?? '');
        if (empty($to)) {
            return ['ok' => false, 'error' => 'Email notification recipient "to" is empty'];
        }

        $subject = $this->buildEmailSubject($event, $payload);
        $htmlBody = $this->renderEmailTemplate($event, $payload);
        $textBody = $this->renderTextTemplate($event, $payload);

        $method = strtolower($config['method'] ?? 'smtp');
        if ($method === 'mail') {
            return $this->sendViaPhpMail($to, $subject, $htmlBody, $textBody, $config);
        }

        return $this->sendViaSmtp($to, $subject, $htmlBody, $textBody, $config);
    }

    /**
     * Send email via native socket SMTP
     *
     * @param string $to
     * @param string $subject
     * @param string $htmlBody
     * @param string $textBody
     * @param array $config
     * @return array
     */
    public function sendViaSmtp(string $to, string $subject, string $htmlBody, string $textBody, array $config): array
    {
        $host = trim($config['smtp_host'] ?? '127.0.0.1');
        $port = (int)($config['smtp_port'] ?? 25);
        $secure = strtolower(trim($config['smtp_secure'] ?? ''));
        $timeout = (int)($config['timeout'] ?? 15);
        if ($timeout < 5) {
            $timeout = 15;
        }

        $cleanHeader = function (string $val): string {
            return trim(str_replace(["\r", "\n", "\0"], '', $val));
        };

        $fromEmail = $cleanHeader($config['from_email'] ?? 'rclonecwp@' . (gethostname() ?: 'localhost'));
        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            $fromEmail = 'rclonecwp@' . (gethostname() ?: 'localhost');
        }
        $fromName = $cleanHeader($config['from_name'] ?? 'rcloneCWP');
        $cleanSubject = $cleanHeader($subject);

        $recipients = [];
        foreach (explode(',', $to) as $rcpt) {
            $cleaned = $cleanHeader($rcpt);
            if (filter_var($cleaned, FILTER_VALIDATE_EMAIL)) {
                $recipients[] = $cleaned;
            }
        }

        if (empty($recipients)) {
            return ['ok' => false, 'error' => 'No valid recipient email address provided'];
        }

        $user = trim($config['smtp_user'] ?? '');
        $pass = $config['smtp_pass'] ?? '';

        $targetHost = $host;
        if ($secure === 'ssl') {
            $targetHost = 'ssl://' . $host;
        }

        $socket = @fsockopen($targetHost, $port, $errno, $errstr, $timeout);
        if (!$socket) {
            return ['ok' => false, 'error' => "SMTP connection failed: {$errstr} ({$errno})"];
        }

        stream_set_timeout($socket, $timeout);

        $readResponse = function () use ($socket) {
            $response = '';
            while ($line = fgets($socket, 515)) {
                $response .= $line;
                if (isset($line[3]) && $line[3] === ' ') {
                    break;
                }
            }
            return $response;
        };

        $sendCommand = function ($cmd, $expectedCode) use ($socket, $readResponse) {
            fputs($socket, $cmd . "\r\n");
            $resp = $readResponse();
            $code = substr($resp, 0, 3);
            if ($code !== (string)$expectedCode) {
                throw new Exception("SMTP error on '{$cmd}': {$resp}");
            }
            return $resp;
        };

        try {
            $initResp = $readResponse();
            if (substr($initResp, 0, 3) !== '220') {
                throw new Exception("Invalid SMTP greeting: {$initResp}");
            }

            $clientHost = gethostname() ?: 'localhost';
            $sendCommand("EHLO {$clientHost}", 250);

            // Handle STARTTLS if configured
            if ($secure === 'tls') {
                $sendCommand("STARTTLS", 220);
                $crypto = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                if (!$crypto) {
                    throw new Exception("STARTTLS negotiation failed");
                }
                $sendCommand("EHLO {$clientHost}", 250);
            }

            // Handle Authentication if credentials provided
            if ($user !== '' && $pass !== '') {
                $sendCommand("AUTH LOGIN", 334);
                $sendCommand(base64_encode($user), 334);
                $sendCommand(base64_encode($pass), 235);
            }

            // Envelope
            $sendCommand("MAIL FROM:<{$fromEmail}>", 250);

            foreach ($recipients as $recipient) {
                $sendCommand("RCPT TO:<{$recipient}>", 250);
            }

            $sendCommand("DATA", 354);

            // Build MIME message
            $boundary = "----=_rcloneCWP_" . md5(uniqid(microtime(true), true));
            $headers = [];
            $headers[] = "Date: " . date('r');
            $headers[] = "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$fromEmail}>";
            $headers[] = "To: " . implode(', ', $recipients);
            $headers[] = "Subject: =?UTF-8?B?" . base64_encode($cleanSubject) . "?=";
            $headers[] = "MIME-Version: 1.0";
            $headers[] = "Content-Type: multipart/alternative; boundary=\"{$boundary}\"";
            $headers[] = "X-Mailer: rcloneCWP-Notification/1.0";

            $mimeMessage = implode("\r\n", $headers) . "\r\n\r\n";
            $mimeMessage .= "--{$boundary}\r\n";
            $mimeMessage .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $mimeMessage .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $mimeMessage .= chunk_split(base64_encode($textBody)) . "\r\n";
            $mimeMessage .= "--{$boundary}\r\n";
            $mimeMessage .= "Content-Type: text/html; charset=UTF-8\r\n";
            $mimeMessage .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $mimeMessage .= chunk_split(base64_encode($htmlBody)) . "\r\n";
            $mimeMessage .= "--{$boundary}--\r\n";
            $mimeMessage .= ".";

            $sendCommand($mimeMessage, 250);
            $sendCommand("QUIT", 221);

            fclose($socket);
            return ['ok' => true, 'message' => 'Email dispatched successfully via SMTP'];
        } catch (Exception $e) {
            @fclose($socket);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Send email via PHP mail() fallback
     */
    private function sendViaPhpMail(string $to, string $subject, string $htmlBody, string $textBody, array $config): array
    {
        $cleanHeader = function (string $val): string {
            return trim(str_replace(["\r", "\n", "\0"], '', $val));
        };

        $fromEmail = $cleanHeader($config['from_email'] ?? 'rclonecwp@' . (gethostname() ?: 'localhost'));
        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            $fromEmail = 'rclonecwp@' . (gethostname() ?: 'localhost');
        }
        $fromName = $cleanHeader($config['from_name'] ?? 'rcloneCWP');
        $cleanSubject = $cleanHeader($subject);

        $recipients = [];
        foreach (explode(',', $to) as $rcpt) {
            $cleaned = $cleanHeader($rcpt);
            if (filter_var($cleaned, FILTER_VALIDATE_EMAIL)) {
                $recipients[] = $cleaned;
            }
        }

        if (empty($recipients)) {
            return ['ok' => false, 'error' => 'No valid recipient email address provided'];
        }

        $boundary = "----=_rcloneCWP_" . md5(uniqid(microtime(true), true));
        $headers = [];
        $headers[] = "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$fromEmail}>";
        $headers[] = "Reply-To: <{$fromEmail}>";
        $headers[] = "MIME-Version: 1.0";
        $headers[] = "Content-Type: multipart/alternative; boundary=\"{$boundary}\"";
        $headers[] = "X-Mailer: rcloneCWP-Notification/1.0";

        $mimeMessage = "--{$boundary}\r\n";
        $mimeMessage .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $mimeMessage .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $mimeMessage .= chunk_split(base64_encode($textBody)) . "\r\n";
        $mimeMessage .= "--{$boundary}\r\n";
        $mimeMessage .= "Content-Type: text/html; charset=UTF-8\r\n";
        $mimeMessage .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $mimeMessage .= chunk_split(base64_encode($htmlBody)) . "\r\n";
        $mimeMessage .= "--{$boundary}--";

        $toHeader = implode(', ', $recipients);
        $encodedSubject = "=?UTF-8?B?" . base64_encode($cleanSubject) . "?=";
        $sent = @mail($toHeader, $encodedSubject, $mimeMessage, implode("\r\n", $headers));
        if ($sent) {
            return ['ok' => true, 'message' => 'Email dispatched via PHP mail()'];
        }

        return ['ok' => false, 'error' => 'PHP mail() returned false'];
    }

    // ========================================================================
    // TELEGRAM CHANNEL DISPATCHER
    // ========================================================================

    /**
     * Dispatch notification to Telegram via Bot API
     *
     * @param array $config
     * @param string $event
     * @param array $payload
     * @return array
     */
    private function dispatchTelegram(array $config, string $event, array $payload): array
    {
        $botToken = trim($config['bot_token'] ?? '');
        $chatId = trim($config['chat_id'] ?? '');

        if (empty($botToken) || empty($chatId)) {
            return ['ok' => false, 'error' => 'Telegram configuration missing bot_token or chat_id'];
        }

        $apiUrl = "https://api.telegram.org/bot{$botToken}/sendMessage";
        $message = $this->renderTelegramTemplate($event, $payload);

        $postData = [
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $apiUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($postData),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['ok' => false, 'error' => "Telegram cURL error: {$error}"];
        }

        $data = json_decode($response, true);
        if ($httpCode === 200 && ($data['ok'] ?? false)) {
            return ['ok' => true, 'message' => 'Telegram notification sent successfully'];
        }

        $apiError = $data['description'] ?? "HTTP {$httpCode}";
        return ['ok' => false, 'error' => "Telegram API error: {$apiError}"];
    }

    // ========================================================================
    // WEBHOOK CHANNEL DISPATCHER (SLACK, DISCORD, GENERIC)
    // ========================================================================

    /**
     * Dispatch notification to Webhook
     *
     * @param array $config
     * @param string $event
     * @param array $payload
     * @return array
     */
    private function dispatchWebhook(array $config, string $event, array $payload): array
    {
        $url = trim($config['url'] ?? '');
        if (empty($url)) {
            return ['ok' => false, 'error' => 'Webhook URL is missing'];
        }

        // SSRF validation
        $safety = Hook::validateSafeUrl($url);
        if (!$safety['safe']) {
            return ['ok' => false, 'error' => 'Webhook URL blocked by SSRF policy: ' . $safety['error']];
        }

        $format = strtolower($config['format'] ?? 'slack');
        $timeout = (int)($config['timeout'] ?? 15);
        if ($timeout < 5) {
            $timeout = 15;
        }

        $postData = [];
        if ($format === 'slack') {
            $postData = $this->renderSlackTemplate($event, $payload);
        } elseif ($format === 'discord') {
            $postData = $this->renderDiscordTemplate($event, $payload);
        } else {
            // Generic JSON format
            $postData = [
                'event' => $event,
                'generator' => 'rcloneCWP',
                'timestamp' => time(),
                'datetime' => date('c'),
                'server' => gethostname(),
                'payload' => $payload,
            ];
        }

        $headers = ['Content-Type: application/json'];
        if (!empty($config['secret'])) {
            $headers[] = 'X-RcloneCWP-Signature: ' . hash_hmac('sha256', json_encode($postData), $config['secret']);
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($postData),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['ok' => false, 'error' => "Webhook cURL error: {$error}"];
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            return ['ok' => true, 'message' => "Webhook delivered successfully (HTTP {$httpCode})"];
        }

        return ['ok' => false, 'error' => "Webhook endpoint returned HTTP {$httpCode}: " . substr((string)$response, 0, 200)];
    }

    // ========================================================================
    // TEMPLATE FORMATTERS & HELPERS
    // ========================================================================

    private function buildEmailSubject(string $event, array $payload): string
    {
        $server = gethostname() ?: 'CWP';
        $statusStr = 'NOTIFICATION';

        switch ($event) {
            case self::EVENT_BACKUP_COMPLETE:
                $statusStr = 'SUCCESS';
                $job = $payload['job_name'] ?? ('Job #' . ($payload['job_id'] ?? ''));
                return "[rcloneCWP] [{$statusStr}] Backup '{$job}' completed on {$server}";
            case self::EVENT_BACKUP_FAIL:
                $statusStr = 'FAILURE';
                $job = $payload['job_name'] ?? ('Job #' . ($payload['job_id'] ?? ''));
                return "[rcloneCWP] [{$statusStr}] Backup '{$job}' failed on {$server}";
            case self::EVENT_RESTORE_COMPLETE:
                $statusStr = 'SUCCESS';
                $user = $payload['username'] ?? '';
                return "[rcloneCWP] [{$statusStr}] Restore for '{$user}' completed on {$server}";
            case self::EVENT_RESTORE_FAIL:
                $statusStr = 'FAILURE';
                $user = $payload['username'] ?? '';
                return "[rcloneCWP] [{$statusStr}] Restore for '{$user}' failed on {$server}";
            case self::EVENT_TEST:
                return "[rcloneCWP] Test Notification from {$server}";
            default:
                return "[rcloneCWP] Event '{$event}' on {$server}";
        }
    }

    private function renderEmailTemplate(string $event, array $payload): string
    {
        // Check if custom template file exists in templates/email/{event}.html
        $tplFile = dirname(__DIR__) . "/templates/email/{$event}.html";
        if (file_exists($tplFile)) {
            $tpl = file_get_contents($tplFile);
            return $this->interpolateVariables($tpl, $payload, $event);
        }

        $isSuccess = strpos($event, 'fail') === false;
        $color = $isSuccess ? '#28a745' : '#dc3545';
        $title = htmlspecialchars($this->buildEmailSubject($event, $payload));

        $html = '<!DOCTYPE html><html><body style="font-family: Arial, sans-serif; background-color: #f4f6f9; margin: 0; padding: 20px;">';
        $html .= '<div style="max-width: 650px; margin: 0 auto; background: #fff; border-radius: 6px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); overflow: hidden;">';
        $html .= '<div style="background-color: ' . $color . '; color: #fff; padding: 18px 24px; font-size: 18px; font-weight: bold;">' . $title . '</div>';
        $html .= '<div style="padding: 24px;">';
        $html .= '<table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">';

        $items = [
            'Event' => $event,
            'Server' => gethostname(),
            'Timestamp' => date('Y-m-d H:i:s T'),
        ];
        if (isset($payload['job_id'])) {
            $items['Job ID'] = '#' . $payload['job_id'];
        }
        if (isset($payload['job_name'])) {
            $items['Job Name'] = $payload['job_name'];
        }
        if (isset($payload['username'])) {
            $items['User Account'] = $payload['username'];
        }
        if (isset($payload['files_count'])) {
            $items['Files Transferred'] = number_format($payload['files_count']);
        }
        if (isset($payload['bytes'])) {
            $items['Data Volume'] = $this->formatBytes($payload['bytes']);
        }
        if (isset($payload['duration'])) {
            $items['Duration'] = $payload['duration'] . ' seconds';
        }
        if (isset($payload['error']) && $payload['error']) {
            $items['Error Details'] = '<span style="color: #dc3545; font-weight: bold;">' . htmlspecialchars($payload['error']) . '</span>';
        }

        foreach ($items as $k => $v) {
            $html .= '<tr><td style="padding: 8px 12px; border-bottom: 1px solid #eee; font-weight: bold; width: 35%; color: #555;">' . htmlspecialchars($k) . '</td>';
            $html .= '<td style="padding: 8px 12px; border-bottom: 1px solid #eee; color: #222;">' . (strpos($v, '<span') !== false ? $v : htmlspecialchars($v)) . '</td></tr>';
        }

        $html .= '</table>';
        $html .= '<p style="font-size: 12px; color: #888; margin-top: 25px; text-align: center;">Sent by rcloneCWP &bull; Enterprise Backup &amp; Restore Module</p>';
        $html .= '</div></div></body></html>';

        return $html;
    }

    private function renderTextTemplate(string $event, array $payload): string
    {
        $lines = [];
        $lines[] = "rcloneCWP Event: " . strtoupper($event);
        $lines[] = "Server: " . gethostname();
        $lines[] = "Date: " . date('Y-m-d H:i:s T');
        $lines[] = "--------------------------------------------------";

        if (isset($payload['job_id'])) {
            $lines[] = "Job ID: #" . $payload['job_id'];
        }
        if (isset($payload['job_name'])) {
            $lines[] = "Job Name: " . $payload['job_name'];
        }
        if (isset($payload['username'])) {
            $lines[] = "Account: " . $payload['username'];
        }
        if (isset($payload['status'])) {
            $lines[] = "Status: " . strtoupper($payload['status']);
        }
        if (isset($payload['files_count'])) {
            $lines[] = "Files: " . number_format($payload['files_count']);
        }
        if (isset($payload['bytes'])) {
            $lines[] = "Transferred: " . $this->formatBytes($payload['bytes']);
        }
        if (isset($payload['duration'])) {
            $lines[] = "Duration: " . $payload['duration'] . "s";
        }
        if (!empty($payload['error'])) {
            $lines[] = "Error: " . $payload['error'];
        }

        return implode("\n", $lines);
    }

    private function renderTelegramTemplate(string $event, array $payload): string
    {
        $isSuccess = strpos($event, 'fail') === false;
        $icon = $isSuccess ? '✅' : '❌';
        $server = htmlspecialchars(gethostname() ?: 'CWP');

        $msg = "<b>{$icon} rcloneCWP: " . htmlspecialchars(strtoupper($event)) . "</b>\n";
        $msg .= "🖥 <b>Server:</b> <code>{$server}</code>\n";
        $msg .= "🕒 <b>Time:</b> " . date('Y-m-d H:i:s') . "\n";

        if (isset($payload['job_name'])) {
            $msg .= "📦 <b>Job:</b> " . htmlspecialchars($payload['job_name']) . " (#" . ($payload['job_id'] ?? '') . ")\n";
        }
        if (isset($payload['username'])) {
            $msg .= "👤 <b>User:</b> <code>" . htmlspecialchars($payload['username']) . "</code>\n";
        }
        if (isset($payload['files_count'])) {
            $msg .= "📄 <b>Files:</b> " . number_format($payload['files_count']) . "\n";
        }
        if (isset($payload['bytes'])) {
            $msg .= "📊 <b>Size:</b> " . $this->formatBytes($payload['bytes']) . "\n";
        }
        if (isset($payload['duration'])) {
            $msg .= "⏱ <b>Duration:</b> " . $payload['duration'] . "s\n";
        }
        if (!empty($payload['error'])) {
            $msg .= "\n⚠️ <b>Error:</b>\n<pre>" . htmlspecialchars(substr($payload['error'], 0, 500)) . "</pre>\n";
        }

        return $msg;
    }

    private function renderSlackTemplate(string $event, array $payload): array
    {
        $isSuccess = strpos($event, 'fail') === false;
        $color = $isSuccess ? '#28a745' : '#dc3545';
        $title = $this->buildEmailSubject($event, $payload);

        $fields = [
            ['title' => 'Server', 'value' => gethostname(), 'short' => true],
            ['title' => 'Event', 'value' => $event, 'short' => true],
        ];

        if (isset($payload['job_name'])) {
            $fields[] = ['title' => 'Job', 'value' => $payload['job_name'], 'short' => true];
        }
        if (isset($payload['username'])) {
            $fields[] = ['title' => 'Account', 'value' => $payload['username'], 'short' => true];
        }
        if (isset($payload['files_count'])) {
            $fields[] = ['title' => 'Files', 'value' => (string)$payload['files_count'], 'short' => true];
        }
        if (isset($payload['bytes'])) {
            $fields[] = ['title' => 'Volume', 'value' => $this->formatBytes($payload['bytes']), 'short' => true];
        }
        if (isset($payload['duration'])) {
            $fields[] = ['title' => 'Duration', 'value' => $payload['duration'] . 's', 'short' => true];
        }
        if (!empty($payload['error'])) {
            $fields[] = ['title' => 'Error', 'value' => substr($payload['error'], 0, 300), 'short' => false];
        }

        return [
            'attachments' => [
                [
                    'color' => $color,
                    'title' => $title,
                    'fields' => $fields,
                    'footer' => 'rcloneCWP Backup Engine',
                    'ts' => time(),
                ],
            ],
        ];
    }

    private function renderDiscordTemplate(string $event, array $payload): array
    {
        $isSuccess = strpos($event, 'fail') === false;
        $color = $isSuccess ? 3066993 : 15158332; // Green or Red in decimal
        $title = $this->buildEmailSubject($event, $payload);

        $fields = [
            ['name' => 'Server', 'value' => gethostname(), 'inline' => true],
            ['name' => 'Event', 'value' => $event, 'inline' => true],
        ];

        if (isset($payload['job_name'])) {
            $fields[] = ['name' => 'Job', 'value' => $payload['job_name'], 'inline' => true];
        }
        if (isset($payload['username'])) {
            $fields[] = ['name' => 'Account', 'value' => $payload['username'], 'inline' => true];
        }
        if (isset($payload['files_count'])) {
            $fields[] = ['name' => 'Files', 'value' => (string)$payload['files_count'], 'inline' => true];
        }
        if (isset($payload['bytes'])) {
            $fields[] = ['name' => 'Volume', 'value' => $this->formatBytes($payload['bytes']), 'inline' => true];
        }
        if (isset($payload['duration'])) {
            $fields[] = ['name' => 'Duration', 'value' => $payload['duration'] . 's', 'inline' => true];
        }
        if (!empty($payload['error'])) {
            $fields[] = ['name' => 'Error', 'value' => substr($payload['error'], 0, 300), 'inline' => false];
        }

        return [
            'embeds' => [
                [
                    'title' => $title,
                    'color' => $color,
                    'fields' => $fields,
                    'footer' => ['text' => 'rcloneCWP Backup Engine'],
                    'timestamp' => date('c'),
                ],
            ],
        ];
    }

    private function interpolateVariables(string $template, array $payload, string $event): string
    {
        $vars = [
            '{EVENT}' => htmlspecialchars($event),
            '{SERVER}' => htmlspecialchars(gethostname()),
            '{TIMESTAMP}' => date('Y-m-d H:i:s'),
            '{JOB_ID}' => htmlspecialchars($payload['job_id'] ?? ''),
            '{JOB_NAME}' => htmlspecialchars($payload['job_name'] ?? ''),
            '{USERNAME}' => htmlspecialchars($payload['username'] ?? ''),
            '{STATUS}' => htmlspecialchars($payload['status'] ?? ''),
            '{FILES_COUNT}' => htmlspecialchars((string)($payload['files_count'] ?? 0)),
            '{BYTES}' => htmlspecialchars($this->formatBytes($payload['bytes'] ?? 0)),
            '{DURATION}' => htmlspecialchars((string)($payload['duration'] ?? 0)),
            '{ERROR}' => htmlspecialchars($payload['error'] ?? ''),
        ];

        return str_replace(array_keys($vars), array_values($vars), $template);
    }

    private function formatBytes($bytes): string
    {
        $bytes = (float)$bytes;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    // ========================================================================
    // DATABASE QUERY & SECRET ENCRYPTION HELPERS
    // ========================================================================

    /**
     * Retrieve active notifications that match the given event
     */
    public function getActiveNotificationsForEvent(string $event): array
    {
        $all = $this->db->fetchAll(
            "SELECT * FROM rclone_notifications WHERE active = 1 ORDER BY id ASC"
        );

        $matched = [];
        foreach ($all as $n) {
            $config = json_decode($n['config'], true) ?: [];
            $events = $config['events'] ?? [];

            // If events array is empty or event is explicitly subscribed
            if (empty($events) || in_array($event, $events, true)) {
                $matched[] = $n;
            }
        }

        return $matched;
    }

    /**
     * Decode and decrypt sensitive configuration fields
     */
    private function decodeAndDecryptConfig($configJson, string $type): array
    {
        $cfg = is_array($configJson) ? $configJson : (json_decode($configJson, true) ?: []);

        if ($type === self::TYPE_EMAIL && !empty($cfg['smtp_pass'])) {
            $decrypted = $this->encryption->decrypt($cfg['smtp_pass']);
            if ($decrypted !== false) {
                $cfg['smtp_pass'] = $decrypted;
            }
        } elseif ($type === self::TYPE_TELEGRAM && !empty($cfg['bot_token'])) {
            $decrypted = $this->encryption->decrypt($cfg['bot_token']);
            if ($decrypted !== false) {
                $cfg['bot_token'] = $decrypted;
            }
        } elseif ($type === self::TYPE_WEBHOOK && !empty($cfg['secret'])) {
            $decrypted = $this->encryption->decrypt($cfg['secret']);
            if ($decrypted !== false) {
                $cfg['secret'] = $decrypted;
            }
        }

        return $cfg;
    }

    /**
     * Log notification dispatch attempt in rclone_logs
     */
    private function logNotificationDispatch(
        int $notifId,
        string $name,
        string $type,
        string $event,
        array $res,
        array $payload
    ): void {
        try {
            $level = ($res['ok'] ?? false) ? 'info' : 'error';
            $msg = "Notification #{$notifId} ('{$name}', type: {$type}) dispatched for event '{$event}': " .
                (($res['ok'] ?? false) ? 'success' : 'failed');

            $context = [
                'notification_id' => $notifId,
                'name' => $name,
                'type' => $type,
                'event' => $event,
                'result' => $res,
                'job_id' => $payload['job_id'] ?? null,
            ];

            $this->db->getConnection()->prepare(
                "INSERT INTO rclone_logs (level, category, message, context) VALUES (?, ?, ?, ?)"
            )->execute([
                $level,
                'notification',
                $msg,
                json_encode($context)
            ]);
        } catch (Exception $e) {
            // Ignore logging errors to prevent disruption of core operation
        }
    }

    // ========================================================================
    // CRUD OPERATIONS FOR rclone_notifications
    // ========================================================================

    /**
     * Create a new notification channel
     */
    public function createNotification(array $data): array
    {
        $this->assertAdminAccess();

        $name = trim($data['name'] ?? '');
        if (empty($name) || strlen($name) > 255) {
            return ['ok' => false, 'error' => 'Notification name is required (max 255 chars)'];
        }

        $type = strtolower(trim($data['type'] ?? ''));
        if (!in_array($type, [self::TYPE_EMAIL, self::TYPE_TELEGRAM, self::TYPE_WEBHOOK], true)) {
            return ['ok' => false, 'error' => 'Invalid notification type (must be email, telegram, or webhook)'];
        }

        $rawConfig = is_array($data['config'] ?? null) ? $data['config'] : (json_decode($data['config'] ?? '{}', true) ?: []);

        // Encrypt secrets before storing
        $encryptedConfig = $this->prepareConfigForStorage($type, $rawConfig);

        $active = isset($data['active']) ? ($data['active'] ? 1 : 0) : 1;

        $stmt = $this->db->getConnection()->prepare(
            "INSERT INTO rclone_notifications (name, type, config, active) VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$name, $type, json_encode($encryptedConfig), $active]);

        return ['ok' => true, 'id' => (int)$this->db->getConnection()->lastInsertId()];
    }

    /**
     * Get notification by ID
     */
    public function getNotification($id, bool $decrypt = false)
    {
        Validator::int($id, 1);
        $row = $this->db->fetchOne("SELECT * FROM rclone_notifications WHERE id = ?", [$id]);
        if (!$row) {
            return null;
        }

        $config = json_decode($row['config'], true) ?: [];
        if ($decrypt) {
            $config = $this->decodeAndDecryptConfig($config, $row['type']);
        } else {
            // Mask secrets
            if (!empty($config['smtp_pass'])) {
                $config['smtp_pass'] = '••••••••';
            }
            if (!empty($config['bot_token'])) {
                $config['bot_token'] = '••••••••';
            }
            if (!empty($config['secret'])) {
                $config['secret'] = '••••••••';
            }
        }
        $row['config'] = $config;

        return $row;
    }

    /**
     * List all notifications
     */
    public function listNotifications(): array
    {
        $rows = $this->db->fetchAll("SELECT * FROM rclone_notifications ORDER BY id ASC");
        foreach ($rows as &$row) {
            $config = json_decode($row['config'], true) ?: [];
            if (!empty($config['smtp_pass'])) {
                $config['smtp_pass'] = '••••••••';
            }
            if (!empty($config['bot_token'])) {
                $config['bot_token'] = '••••••••';
            }
            if (!empty($config['secret'])) {
                $config['secret'] = '••••••••';
            }
            $row['config'] = $config;
        }
        return $rows;
    }

    /**
     * Update notification
     */
    public function updateNotification($id, array $data): array
    {
        $this->assertAdminAccess();
        Validator::int($id, 1);

        $existing = $this->db->fetchOne("SELECT * FROM rclone_notifications WHERE id = ?", [$id]);
        if (!$existing) {
            return ['ok' => false, 'error' => 'Notification channel not found'];
        }

        $type = $existing['type'];
        $oldConfig = json_decode($existing['config'], true) ?: [];

        $name = isset($data['name']) ? trim($data['name']) : $existing['name'];
        if (empty($name) || strlen($name) > 255) {
            return ['ok' => false, 'error' => 'Notification name cannot be empty'];
        }

        $active = isset($data['active']) ? ($data['active'] ? 1 : 0) : $existing['active'];

        $newConfig = is_array($data['config'] ?? null) ? $data['config'] : $oldConfig;

        // Preserve existing secrets if masked or omitted
        $preparedConfig = $this->prepareConfigForStorage($type, $newConfig, $oldConfig);

        $stmt = $this->db->getConnection()->prepare(
            "UPDATE rclone_notifications SET name = ?, config = ?, active = ? WHERE id = ?"
        );
        $stmt->execute([$name, json_encode($preparedConfig), $active, $id]);

        return ['ok' => true];
    }

    /**
     * Delete notification
     */
    public function deleteNotification($id): array
    {
        $this->assertAdminAccess();
        Validator::int($id, 1);

        $stmt = $this->db->getConnection()->prepare(
            "DELETE FROM rclone_notifications WHERE id = ?"
        );
        $stmt->execute([$id]);

        return ['ok' => $stmt->rowCount() > 0];
    }

    /**
     * Toggle active state
     */
    public function toggleNotification($id): array
    {
        $this->assertAdminAccess();
        Validator::int($id, 1);

        $existing = $this->db->fetchOne("SELECT * FROM rclone_notifications WHERE id = ?", [$id]);
        if (!$existing) {
            return ['ok' => false, 'error' => 'Notification channel not found'];
        }

        $newState = $existing['active'] ? 0 : 1;
        $stmt = $this->db->getConnection()->prepare(
            "UPDATE rclone_notifications SET active = ? WHERE id = ?"
        );
        $stmt->execute([$newState, $id]);

        return ['ok' => true, 'active' => $newState];
    }

    /**
     * Test notification dispatch
     */
    public function testNotification($id): array
    {
        $this->assertAdminAccess();
        Validator::int($id, 1);

        $existing = $this->getNotification($id, true);
        if (!$existing) {
            return ['ok' => false, 'error' => 'Notification channel not found'];
        }

        $testPayload = [
            'job_id' => 9999,
            'job_name' => 'Sample Backup Test',
            'username' => 'builderh',
            'status' => 'completed',
            'files_count' => 1420,
            'bytes' => 104857600, // 100 MB
            'duration' => 12,
            'error' => null,
            'test_mode' => true,
        ];

        return $this->sendToChannel(
            $existing['type'],
            $existing['config'],
            self::EVENT_TEST,
            $testPayload,
            $existing['name']
        );
    }

    /**
     * Encrypt sensitive fields before storing in database
     */
    private function prepareConfigForStorage(string $type, array $newConfig, array $oldConfig = []): array
    {
        $config = $newConfig;

        if ($type === self::TYPE_EMAIL) {
            if (isset($config['smtp_pass'])) {
                if ($config['smtp_pass'] === '••••••••' || $config['smtp_pass'] === '') {
                    $config['smtp_pass'] = $oldConfig['smtp_pass'] ?? '';
                } else {
                    $config['smtp_pass'] = $this->encryption->encrypt($config['smtp_pass']);
                }
            } elseif (isset($oldConfig['smtp_pass'])) {
                $config['smtp_pass'] = $oldConfig['smtp_pass'];
            }
        } elseif ($type === self::TYPE_TELEGRAM) {
            if (isset($config['bot_token'])) {
                if ($config['bot_token'] === '••••••••' || $config['bot_token'] === '') {
                    $config['bot_token'] = $oldConfig['bot_token'] ?? '';
                } else {
                    $config['bot_token'] = $this->encryption->encrypt($config['bot_token']);
                }
            } elseif (isset($oldConfig['bot_token'])) {
                $config['bot_token'] = $oldConfig['bot_token'];
            }
        } elseif ($type === self::TYPE_WEBHOOK) {
            if (isset($config['secret'])) {
                if ($config['secret'] === '••••••••' || $config['secret'] === '') {
                    $config['secret'] = $oldConfig['secret'] ?? '';
                } else {
                    $config['secret'] = $this->encryption->encrypt($config['secret']);
                }
            } elseif (isset($oldConfig['secret'])) {
                $config['secret'] = $oldConfig['secret'];
            }
        }

        return $config;
    }
}
