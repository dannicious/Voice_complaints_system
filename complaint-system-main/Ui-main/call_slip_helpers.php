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

function send_call_slip_email(
    string $recipientEmail,
    string $recipientName,
    string $ticketNo,
    string $dateIssued,
    string $timeIssued,
    string $officeMessage,
    string $issuedBy
): array {
    if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'The reported student does not have a valid Gmail address.'];
    }

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

        $safeName = htmlspecialchars($recipientName !== '' ? $recipientName : 'Student', ENT_QUOTES, 'UTF-8');
        $safeTicketNo = htmlspecialchars($ticketNo, ENT_QUOTES, 'UTF-8');
        $safeDate = htmlspecialchars($dateIssued, ENT_QUOTES, 'UTF-8');
        $safeTime = htmlspecialchars($timeIssued, ENT_QUOTES, 'UTF-8');
        $safeOfficeMessage = htmlspecialchars($officeMessage, ENT_QUOTES, 'UTF-8');
        $safeIssuedBy = htmlspecialchars($issuedBy, ENT_QUOTES, 'UTF-8');

        $mailer->Body = "
            <div style=\"font-family:Arial,Helvetica,sans-serif;background:#f7f7f8;padding:24px;color:#111827;\">
                <div style=\"max-width:560px;margin:0 auto;background:#ffffff;border:1px solid #e5e7eb;border-radius:12px;padding:24px;\">
                    <h2 style=\"margin:0 0 18px;\">VOICE Call Slip</h2>
                    <p>Hello {$safeName},</p>
                    <p>A Call Slip has been issued for complaint <strong>{$safeTicketNo}</strong>.</p>
                    <p><strong>Date:</strong> {$safeDate}<br><strong>Time:</strong> {$safeTime}</p>
                    <p>{$safeOfficeMessage}</p>
                    <p><strong>Issued by:</strong> {$safeIssuedBy}</p>
                </div>
            </div>
        ";
        $mailer->AltBody = "Hello {$recipientName},\n\nA Call Slip has been issued for complaint {$ticketNo}.\nDate: {$dateIssued}\nTime: {$timeIssued}\n\n{$officeMessage}\nIssued by: {$issuedBy}";
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
