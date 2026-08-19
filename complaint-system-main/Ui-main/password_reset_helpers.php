<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/db_connection.php';

$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

function reset_base_url(): string
{
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443');

    $scheme = $isHttps ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $scriptDir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')));
    $scriptDir = rtrim($scriptDir, '/');

    return $scheme . '://' . $host . ($scriptDir !== '' ? $scriptDir : '');
}

function reset_redirect(string $script, string $status, string $message, array $extra = []): void
{
    $query = array_merge([
        'status' => $status,
        'msg' => $message,
    ], $extra);

    header('Location: ' . $script . '?' . http_build_query($query));
    exit;
}

function reset_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS count
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table
           AND COLUMN_NAME = :column'
    );
    $stmt->execute([
        ':table' => $table,
        ':column' => $column,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return (int)($row['count'] ?? 0) > 0;
}

function reset_ensure_columns(PDO $pdo): void
{
    if (!reset_column_exists($pdo, 'users', 'reset_code')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN reset_code VARCHAR(255) NULL');
    }

    if (!reset_column_exists($pdo, 'users', 'reset_expiry')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN reset_expiry DATETIME NULL');
    }
}

function reset_generate_code(): string
{
    return (string) random_int(100000, 999999);
}

function reset_expiry_at(int $minutes = 10): string
{
    return date('Y-m-d H:i:s', time() + ($minutes * 60));
}

function reset_expiry_seconds(?string $expiry): int
{
    if ($expiry === null || $expiry === '') {
        return 0;
    }

    return max(0, strtotime($expiry) - time());
}

function reset_fetch_user_by_email(PDO $pdo, string $email): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, username, email, role
         FROM users
         WHERE email = :email
           AND is_active = 1
         LIMIT 1'
    );
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    return $user ?: null;
}

function reset_fetch_user_by_id(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, username, email, role, reset_code, reset_expiry
         FROM users
         WHERE id = :id
           AND is_active = 1
         LIMIT 1'
    );
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    return $user ?: null;
}

function reset_store_code(PDO $pdo, int $userId, string $otpCode, string $expiry): void
{
    $stmt = $pdo->prepare(
        'UPDATE users
         SET reset_code = :reset_code,
             reset_expiry = :reset_expiry
         WHERE id = :id'
    );
    $stmt->execute([
        ':reset_code' => password_hash($otpCode, PASSWORD_DEFAULT),
        ':reset_expiry' => $expiry,
        ':id' => $userId,
    ]);
}

function reset_clear_code(PDO $pdo, int $userId): void
{
    $stmt = $pdo->prepare(
        'UPDATE users
         SET reset_code = NULL,
             reset_expiry = NULL
         WHERE id = :id'
    );
    $stmt->execute([':id' => $userId]);
}

function reset_mail_settings(): array
{
    $localConfigPath = __DIR__ . '/smtp_config.php';
    $localConfig = is_file($localConfigPath) ? include $localConfigPath : [];
    if (!is_array($localConfig)) {
        $localConfig = [];
    }

    $smtpUsername = trim((string)($localConfig['username'] ?? getenv('GMAIL_SMTP_USERNAME') ?: getenv('SMTP_USER') ?: ''));

    return [
        'host' => trim((string)($localConfig['host'] ?? getenv('GMAIL_SMTP_HOST') ?: getenv('SMTP_HOST') ?: 'smtp.gmail.com')),
        'port' => (int)($localConfig['port'] ?? getenv('GMAIL_SMTP_PORT') ?: getenv('SMTP_PORT') ?: 587),
        'username' => $smtpUsername,
        'password' => (string)($localConfig['password'] ?? getenv('GMAIL_APP_PASSWORD') ?: getenv('SMTP_PASS') ?: ''),
        'from_email' => trim((string)($localConfig['from_email'] ?? getenv('GMAIL_FROM_EMAIL') ?: getenv('SMTP_FROM_EMAIL') ?: $smtpUsername)),
        'from_name' => trim((string)($localConfig['from_name'] ?? getenv('GMAIL_FROM_NAME') ?: getenv('SMTP_FROM_NAME') ?: 'VOICE Support')),
        'secure' => strtolower(trim((string)($localConfig['secure'] ?? getenv('GMAIL_SMTP_SECURE') ?: getenv('SMTP_SECURE') ?: 'tls'))),
    ];
}

function reset_send_otp_email(string $recipientEmail, string $recipientName, string $otpCode, string $expiry, ?string &$error = null): bool
{
    if (!class_exists(PHPMailer::class)) {
        $error = 'PHPMailer is not installed.';
        return false;
    }

    $settings = reset_mail_settings();
    if ($settings['host'] === '' || $settings['username'] === '' || $settings['password'] === '' || $settings['from_email'] === '') {
        $error = 'Mail settings are not configured.';
        return false;
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
        $mailer->Subject = 'VOICE Password Reset Code';
        $mailer->Body = '
            <div style="font-family:Arial,Helvetica,sans-serif;background:#f7f7f8;padding:24px;color:#111827;">
                <div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e5e7eb;box-shadow:0 14px 30px rgba(15,23,42,0.12);">
                    <div style="background:linear-gradient(135deg,#6d28d9,#3b5ba9);color:#fff;padding:22px 24px;">
                        <div style="font-size:12px;letter-spacing:2px;text-transform:uppercase;opacity:.85;">VOICE</div>
                        <h2 style="margin:8px 0 0;font-size:24px;line-height:1.2;">Password Reset Code</h2>
                    </div>
                    <div style="padding:24px;">
                        <p style="margin:0 0 14px;">Hello ' . htmlspecialchars($recipientName !== '' ? $recipientName : $recipientEmail, ENT_QUOTES, 'UTF-8') . ',</p>
                        <p style="margin:0 0 18px;line-height:1.6;">Use the code below to verify your password reset request. The code expires at ' . htmlspecialchars($expiry, ENT_QUOTES, 'UTF-8') . '.</p>
                        <div style="display:inline-block;background:#eef2ff;border:1px solid #c7d2fe;border-radius:14px;padding:16px 24px;font-size:32px;font-weight:700;letter-spacing:8px;color:#1e1b4b;">' . htmlspecialchars($otpCode, ENT_QUOTES, 'UTF-8') . '</div>
                        <p style="margin:18px 0 0;line-height:1.6;color:#4b5563;">If you did not request this, you can ignore this email. No password changes will happen until the code is verified.</p>
                    </div>
                </div>
            </div>
        ';
        $mailer->AltBody = "Hello {$recipientName},\n\nUse this password reset code: {$otpCode}\nIt expires at {$expiry}.\n\nIf you did not request this, ignore this email.";
        $mailer->send();

        return true;
    } catch (PHPMailerException $exception) {
        $error = $exception->getMessage();
        return false;
    }
}

function reset_pending_user(PDO $pdo): ?array
{
    $userId = (int)($_SESSION['reset_user_id'] ?? 0);
    if ($userId <= 0) {
        return null;
    }

    return reset_fetch_user_by_id($pdo, $userId);
}

function reset_is_verified(): bool
{
    return !empty($_SESSION['reset_verified_user_id']) && !empty($_SESSION['reset_verified_at']);
}
