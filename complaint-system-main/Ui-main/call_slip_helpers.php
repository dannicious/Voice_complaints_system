<?php

require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

function call_slip_mail_settings(): array
{
    $configPath = __DIR__ . '/smtp_config.php';
    $config = is_file($configPath) ? include $configPath : [];
    if (!is_array($config)) {
        $config = [];
    }

    return [
        'host' => trim((string)($config['host'] ?? 'smtp.gmail.com')),
        'port' => (int)($config['port'] ?? 587),
        'username' => trim((string)($config['username'] ?? '')),
        'password' => preg_replace('/\s+/', '', (string)($config['password'] ?? '')) ?? '',
        'from_email' => trim((string)($config['from_email'] ?? $config['username'] ?? '')),
        'from_name' => trim((string)($config['from_name'] ?? 'Voice System')),
        'secure' => strtolower(trim((string)($config['secure'] ?? 'tls'))),
    ];
}

/**
 * Absolute URL to the student login page, for the "log in to VOICE to view
 * full details" link in the call slip email. Built from the current
 * request rather than a hardcoded host, since callers (dean/admin ticket
 * pages) always live two directories under Ui-main - Ui-main/<role>/*.php -
 * so the app root sits exactly two levels above the current script.
 */
function call_slip_login_url(): string
{
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443');
    $scheme = $isHttps ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');

    $scriptDir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')));
    $appRoot = rtrim(dirname(dirname($scriptDir)), '/');

    return $scheme . '://' . $host . $appRoot . '/student/login.php';
}

/**
 * Converts a 24-hour "HH:MM" (or "HH:MM:SS") time — what a native
 * <input type="time"> always submits, regardless of how it's displayed in
 * the picker — into 12-hour form with an explicit AM/PM, e.g. "23:00" -> "11:00 PM".
 * Returns the input unchanged if it isn't a recognizable time (including empty).
 */
function format_call_slip_time(string $rawTime): string
{
    $rawTime = trim($rawTime);
    if ($rawTime === '') {
        return '';
    }
    foreach (['H:i:s', 'H:i'] as $format) {
        $parsed = DateTime::createFromFormat($format, $rawTime);
        if ($parsed !== false) {
            return $parsed->format('g:i A');
        }
    }
    return $rawTime;
}

function send_call_slip_email(
    string $recipientEmail,
    string $recipientName,
    string $ticketNo,
    string $dateIssued,
    string $timeIssued,
    string $officeMessage,
    string $issuedBy,
    string $reasonNote = ''
): array {
    if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'The reported student does not have a valid Gmail address.'];
    }

    $timeIssued = format_call_slip_time($timeIssued);

    if (!class_exists(PHPMailer::class)) {
        return ['ok' => false, 'message' => 'PHPMailer is not installed.'];
    }

    $settings = call_slip_mail_settings();
    if ($settings['host'] === '' || $settings['username'] === '' || $settings['password'] === '' || $settings['from_email'] === '') {
        return ['ok' => false, 'message' => 'Email settings are not configured.'];
    }

    try {
        $mailer = new PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host = $settings['host'];
        $mailer->SMTPAuth = true;
        $mailer->Username = $settings['username'];
        $mailer->Password = $settings['password'];
        $mailer->SMTPSecure = $settings['secure'] === 'ssl'
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;
        $mailer->Port = $settings['port'];
        $mailer->CharSet = 'UTF-8';
        $mailer->setFrom($settings['from_email'], $settings['from_name']);
        $mailer->addAddress($recipientEmail, $recipientName !== '' ? $recipientName : $recipientEmail);
        $mailer->isHTML(true);
        $mailer->Subject = 'VOICE Call Slip - ' . $ticketNo;

        $displayName = $recipientName !== '' ? $recipientName : 'Student';
        $datePrepared = date('F j, Y');
        $reasonText = $reasonNote !== '' ? $reasonNote : 'General concern regarding the filed complaint.';

        // e.g. "2026-09-10 (Thursday)" - falls back to the raw value if it
        // isn't a parseable date (so a blank/odd value doesn't crash this).
        $dateWithWeekday = $dateIssued;
        $parsedDate = $dateIssued !== '' ? strtotime($dateIssued) : false;
        if ($parsedDate !== false) {
            $dateWithWeekday = date('Y-m-d', $parsedDate) . ' (' . date('l', $parsedDate) . ')';
        }

        $safeName = htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8');
        $safeDatePrepared = htmlspecialchars($datePrepared, ENT_QUOTES, 'UTF-8');
        $safeDate = htmlspecialchars($dateWithWeekday, ENT_QUOTES, 'UTF-8');
        $safeTime = htmlspecialchars($timeIssued, ENT_QUOTES, 'UTF-8');
        $safeOfficeMessage = htmlspecialchars($officeMessage, ENT_QUOTES, 'UTF-8');
        $safeReasonNote = nl2br(htmlspecialchars($reasonText, ENT_QUOTES, 'UTF-8'));
        $safeIssuedBy = htmlspecialchars($issuedBy, ENT_QUOTES, 'UTF-8');
        $loginUrl = call_slip_login_url();
        $safeLoginUrl = htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8');

        $mailer->Body = "<!DOCTYPE html>
<html>
<head><meta charset=\"UTF-8\"><title>VOICE Call Slip</title></head>
<body style=\"margin:0;padding:0;\">
            <div style=\"font-family:Arial,Helvetica,sans-serif;background:#f7f7f8;padding:24px;color:#111827;\">
                <div style=\"max-width:560px;margin:0 auto;background:#ffffff;border:1px solid #e5e7eb;border-radius:12px;padding:24px;\">
                    <h2 style=\"margin:0 0 18px;\">VOICE Call Slip</h2>
                    <p><strong>To:</strong> {$safeName}<br><strong>Date Issued:</strong> {$safeDatePrepared}</p>
                    <p>{$safeOfficeMessage}</p>
                    <p><strong>on Date:</strong> {$safeDate}<br><strong>at Time:</strong> {$safeTime}</p>
                    <p><strong>This is in connection with:</strong><br>{$safeReasonNote}</p>
                    <p>Please review this Call Slip and report to the designated office at the scheduled date and time. Thank you.</p>
                    <p style=\"margin-top:20px;\"><strong>Issued by:</strong> {$safeIssuedBy}</p>
                    <p style=\"margin:18px 0;\"><a href=\"{$safeLoginUrl}\" style=\"display:inline-block;background:#6d28d9;color:#ffffff;text-decoration:none;padding:11px 20px;border-radius:8px;font-weight:600;\">Log in to VOICE</a></p>
                </div>
            </div>
</body>
</html>";
        $mailer->AltBody = "To: {$displayName}\nDate Issued: {$datePrepared}\n\n{$officeMessage}\n\non Date: {$dateWithWeekday}\nat Time: {$timeIssued}\n\nThis is in connection with:\n{$reasonText}\n\nPlease review this Call Slip and report to the designated office at the scheduled date and time. Thank you.\n\nIssued by: {$issuedBy}\n\n{$loginUrl}";
        $mailer->send();
        $transactionId = method_exists($mailer->getSMTPInstance(), 'getLastTransactionID')
            ? (string)$mailer->getSMTPInstance()->getLastTransactionID()
            : '';
        error_log('send_call_slip_email accepted by Gmail to ' . $recipientEmail . ($transactionId !== '' ? ' transaction=' . $transactionId : ''));

        return ['ok' => true, 'message' => 'Gmail accepted the Call Slip for delivery to ' . $recipientEmail . '. Check Inbox, Spam, and All Mail.'];
    } catch (PHPMailerException $exception) {
        error_log('send_call_slip_email to ' . $recipientEmail . ': ' . $exception->getMessage());
        return ['ok' => false, 'message' => 'Call Slip was recorded, but the email could not be sent: ' . $exception->getMessage()];
    }
}
