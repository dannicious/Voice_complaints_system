<?php
declare(strict_types=1);

require_once __DIR__ . '/password_reset_helpers.php';

reset_ensure_columns($pdo);

$status = trim((string)($_GET['status'] ?? ''));
$message = trim((string)($_GET['msg'] ?? ''));
if (!in_array($status, ['success', 'error', 'info'], true)) {
    $status = '';
    $message = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_otp'])) {
    $email = trim((string)($_POST['email'] ?? ''));

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        reset_redirect('forgotpassword.php', 'error', 'Please enter a valid registered email address.');
    }

    $user = reset_fetch_user_by_email($pdo, $email);
    if (!$user) {
        reset_redirect('forgotpassword.php', 'error', 'We could not find an active account with that email address.');
    }

    $otpCode = reset_generate_code();
    $expiry = reset_expiry_at(10);

    reset_store_code($pdo, (int)$user['id'], $otpCode, $expiry);

    session_regenerate_id(true);
    $_SESSION['reset_user_id'] = (int)$user['id'];
    $_SESSION['reset_email'] = (string)$user['email'];
    $_SESSION['reset_username'] = (string)$user['username'];
    $_SESSION['reset_role'] = (string)$user['role'];
    $_SESSION['reset_expiry'] = $expiry;
    $_SESSION['reset_verified_user_id'] = null;
    $_SESSION['reset_verified_at'] = null;

    $mailError = null;
    if (!reset_send_otp_email((string)$user['email'], (string)$user['username'], $otpCode, $expiry, $mailError)) {
        reset_clear_code($pdo, (int)$user['id']);
        unset($_SESSION['reset_user_id'], $_SESSION['reset_email'], $_SESSION['reset_username'], $_SESSION['reset_role'], $_SESSION['reset_expiry']);
        reset_redirect('forgotpassword.php', 'error', 'Unable to send the OTP right now. ' . ($mailError !== null ? $mailError : 'Please try again later.'));
    }

    reset_redirect('verify_code.php', 'success', 'We sent a 6-digit OTP to your email address.');
}

$authCssVersion = @filemtime(__DIR__ . '/assets/css/auth.css');
if ($authCssVersion === false) {
    $authCssVersion = time();
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Forgot Password - VOICE</title>
    <link rel="stylesheet" href="assets/css/auth.css?v=<?= (int)$authCssVersion ?>">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
</head>
<body>
<div class="auth-card auth-card--wide">
    <div class="auth-logo">
        <img src="assets/images/logo.png" alt="VOICE Logo">
        <h3 class="auth-title">VOICE</h3>
    </div>

    <div class="auth-sub">Forgot Password</div>

    <p class="auth-copy">Enter your registered email address. We will send a 6-digit verification code to start the reset process.</p>

    <?php if ($status !== '' && $message !== ''): ?>
        <div class="flash-<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <form class="auth-form" method="POST" action="">
        <div class="form-group">
            <label for="email">Email Address</label>
            <div class="input-wrapper">
                <i class='bx bx-envelope icon-left'></i>
                <input type="email" id="email" name="email" placeholder="name@example.com" autocomplete="email" required>
            </div>
        </div>

        <button class="auth-btn auth-btn--full" type="submit" name="send_otp" value="1">
            Send OTP <i class='bx bx-mail-send'></i>
        </button>

        <div class="auth-footer-row auth-footer-row--single">
            <a class="small-link" href="../student/login.php">Back to login</a>
        </div>
    </form>
</div>
</body>
</html>