<?php
declare(strict_types=1);

require_once __DIR__ . '/password_reset_helpers.php';

reset_ensure_columns($pdo);

$user = reset_pending_user($pdo);
if (!$user) {
    reset_redirect('forgotpassword.php', 'error', 'Start the password reset process again to receive a new OTP.');
}

$status = trim((string)($_GET['status'] ?? ''));
$message = trim((string)($_GET['msg'] ?? ''));
if (!in_array($status, ['success', 'error', 'info'], true)) {
    $status = '';
    $message = '';
}

$expiry = (string)($_SESSION['reset_expiry'] ?? ($user['reset_expiry'] ?? ''));
$remainingSeconds = reset_expiry_seconds($expiry);
$expired = $remainingSeconds <= 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['resend_otp'])) {
        $otpCode = reset_generate_code();
        $newExpiry = reset_expiry_at(10);
        reset_store_code($pdo, (int)$user['id'], $otpCode, $newExpiry);

        $_SESSION['reset_expiry'] = $newExpiry;
        $_SESSION['reset_verified_user_id'] = null;
        $_SESSION['reset_verified_at'] = null;

        $mailError = null;
        if (!reset_send_otp_email((string)$user['email'], (string)$user['username'], $otpCode, $newExpiry, $mailError)) {
            reset_clear_code($pdo, (int)$user['id']);
            unset($_SESSION['reset_user_id'], $_SESSION['reset_email'], $_SESSION['reset_username'], $_SESSION['reset_expiry']);
            reset_redirect('forgotpassword.php', 'error', 'Unable to resend the OTP right now. ' . ($mailError !== null ? $mailError : 'Please try again later.'));
        }

        reset_redirect('verify_code.php', 'success', 'A fresh OTP was sent to your email address.');
    }

    if (isset($_POST['verify_otp'])) {
        $code = preg_replace('/\D+/', '', (string)($_POST['otp_code'] ?? ''));

        $latest = reset_fetch_user_by_id($pdo, (int)$user['id']);
        if (!$latest || empty($latest['reset_code']) || empty($latest['reset_expiry'])) {
            reset_redirect('forgotpassword.php', 'error', 'Your verification code is no longer available. Please request a new one.');
        }

        if (reset_expiry_seconds((string)$latest['reset_expiry']) <= 0) {
            reset_redirect('verify_code.php', 'error', 'The OTP has expired. Please resend a new code.');
        }

        if ($code === '' || strlen($code) !== 6) {
            reset_redirect('verify_code.php', 'error', 'Enter the 6-digit OTP sent to your email.');
        }

        if (!password_verify($code, (string)$latest['reset_code'])) {
            reset_redirect('verify_code.php', 'error', 'Invalid OTP code. Please try again.');
        }

        session_regenerate_id(true);
        $_SESSION['reset_verified_user_id'] = (int)$latest['id'];
        $_SESSION['reset_verified_at'] = time();
        $_SESSION['reset_expiry'] = (string)$latest['reset_expiry'];

        header('Location: reset_password.php');
        exit;
    }
}

$authCssVersion = @filemtime(__DIR__ . '/assets/css/auth.css');
if ($authCssVersion === false) {
    $authCssVersion = time();
}

$expiryTimestamp = strtotime($expiry) ?: time();
$codeAge = max(0, $expiryTimestamp - time());
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Verify OTP - VOICE</title>
    <link rel="stylesheet" href="assets/css/auth.css?v=<?= (int)$authCssVersion ?>">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
</head>
<body>
<div class="auth-card auth-card--wide">
    <div class="auth-logo">
        <img src="assets/images/logo.png" alt="VOICE Logo">
        <h3 class="auth-title">VOICE</h3>
    </div>

    <div class="auth-sub">Verify OTP</div>

    <p class="auth-copy">We sent a 6-digit code to <strong><?= htmlspecialchars((string)$user['email'], ENT_QUOTES, 'UTF-8') ?></strong>. Enter it below to continue.</p>

    <?php if ($status !== '' && $message !== ''): ?>
        <div class="flash-<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <div class="otp-panel">
        <div class="otp-panel__label">Code expires in</div>
        <div class="otp-timer" data-expiry="<?= htmlspecialchars((string)$expiryTimestamp, ENT_QUOTES, 'UTF-8') ?>"><?= gmdate('i:s', $codeAge) ?></div>
    </div>

    <form class="auth-form" method="POST" action="">
        <div class="form-group">
            <label for="otp_code">6-Digit OTP</label>
            <div class="input-wrapper">
                <i class='bx bx-shield-quarter icon-left'></i>
                <input type="text" id="otp_code" name="otp_code" placeholder="123456" inputmode="numeric" maxlength="6" minlength="6" autocomplete="one-time-code" required>
            </div>
        </div>

        <button class="auth-btn auth-btn--full" type="submit" name="verify_otp" value="1">
            Verify Code <i class='bx bx-check-shield'></i>
        </button>

        <div class="auth-footer-row">
            <button class="auth-link-button" type="submit" name="resend_otp" value="1">Resend OTP</button>
            <a class="small-link" href="forgotpassword.php">Use another email</a>
        </div>
    </form>
</div>

<script>
(function () {
    const timer = document.querySelector('.otp-timer');
    if (!timer) {
        return;
    }

    const expiry = parseInt(timer.getAttribute('data-expiry') || '0', 10) * 1000;
    const resendButton = document.querySelector('button[name="resend_otp"]');
    const verifyButton = document.querySelector('button[name="verify_otp"]');

    function tick() {
        const remaining = Math.max(0, Math.floor((expiry - Date.now()) / 1000));
        const minutes = String(Math.floor(remaining / 60)).padStart(2, '0');
        const seconds = String(remaining % 60).padStart(2, '0');
        timer.textContent = minutes + ':' + seconds;

        if (remaining <= 0) {
            timer.textContent = '00:00';
            timer.classList.add('is-expired');
            if (verifyButton) {
                verifyButton.disabled = true;
            }
            if (resendButton) {
                resendButton.classList.add('is-emphasis');
            }
        }
    }

    tick();
    setInterval(tick, 1000);
})();
</script>
</body>
</html>