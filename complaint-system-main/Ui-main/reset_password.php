<?php
declare(strict_types=1);

require_once __DIR__ . '/password_reset_helpers.php';

reset_ensure_columns($pdo);

if (!reset_is_verified()) {
    reset_redirect('forgotpassword.php', 'error', 'Please verify the OTP before creating a new password.');
}

$verifiedUserId = (int)($_SESSION['reset_verified_user_id'] ?? 0);
$user = $verifiedUserId > 0 ? reset_fetch_user_by_id($pdo, $verifiedUserId) : null;

if (!$user) {
    unset($_SESSION['reset_user_id'], $_SESSION['reset_email'], $_SESSION['reset_username'], $_SESSION['reset_role'], $_SESSION['reset_expiry'], $_SESSION['reset_verified_user_id'], $_SESSION['reset_verified_at']);
    reset_redirect('forgotpassword.php', 'error', 'Your password reset session has expired. Please start again.');
}

$status = trim((string)($_GET['status'] ?? ''));
$message = trim((string)($_GET['msg'] ?? ''));
if (!in_array($status, ['success', 'error', 'info'], true)) {
    $status = '';
    $message = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    $password = (string)($_POST['password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if (strlen($password) < 8) {
        reset_redirect('reset_password.php', 'error', 'Password must be at least 8 characters long.');
    }

    if ($password !== $confirmPassword) {
        reset_redirect('reset_password.php', 'error', 'Passwords do not match.');
    }

    if (reset_expiry_seconds((string)($user['reset_expiry'] ?? '')) <= 0) {
        reset_redirect('verify_code.php', 'error', 'The OTP session expired. Please request a new code.');
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare(
        'UPDATE users
         SET password = :password,
             reset_code = NULL,
             reset_expiry = NULL
         WHERE id = :id'
    );
    $stmt->execute([
        ':password' => $passwordHash,
        ':id' => (int)$user['id'],
    ]);

    $role = (string)($_SESSION['reset_role'] ?? 'student');
    unset($_SESSION['reset_user_id'], $_SESSION['reset_email'], $_SESSION['reset_username'], $_SESSION['reset_role'], $_SESSION['reset_expiry'], $_SESSION['reset_verified_user_id'], $_SESSION['reset_verified_at']);
    session_regenerate_id(true);

    $loginTarget = '../student/login.php';
    if ($role === 'admin') {
        $loginTarget = '../admin/login.php';
    } elseif ($role === 'dean') {
        $loginTarget = '../dean/login.php';
    }

    reset_redirect($loginTarget, 'success', 'Your password has been updated successfully. Please log in with the new password.');
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
    <title>Reset Password - VOICE</title>
    <link rel="stylesheet" href="assets/css/auth.css?v=<?= (int)$authCssVersion ?>">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
</head>
<body>
<div class="auth-card auth-card--wide">
    <div class="auth-logo">
        <img src="assets/images/logo.png" alt="VOICE Logo">
        <h3 class="auth-title">VOICE</h3>
    </div>

    <div class="auth-sub">Create New Password</div>

    <p class="auth-copy">Set a new password for <strong><?= htmlspecialchars((string)$user['email'], ENT_QUOTES, 'UTF-8') ?></strong>.</p>

    <?php if ($status !== '' && $message !== ''): ?>
        <div class="flash-<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <form class="auth-form" method="POST" action="">
        <div class="form-group">
            <label for="password">New Password</label>
            <div class="password-field">
                <i class="bx bx-lock-alt icon-left"></i>
                <input type="password" id="password" name="password" minlength="8" required>
                <button class="password-toggle" type="button" data-target="password" aria-label="Show password">
                    <i class='bx bx-show'></i>
                </button>
            </div>
        </div>

        <div class="form-group">
            <label for="confirm_password">Confirm Password</label>
            <div class="password-field">
                <i class="bx bx-lock-alt icon-left"></i>
                <input type="password" id="confirm_password" name="confirm_password" minlength="8" required>
                <button class="password-toggle" type="button" data-target="confirm_password" aria-label="Show password">
                    <i class='bx bx-show'></i>
                </button>
            </div>
        </div>

        <button class="auth-btn auth-btn--full" type="submit" name="reset_password" value="1">
            Update Password <i class='bx bx-check-circle'></i>
        </button>

        <div class="auth-footer-row auth-footer-row--single">
            <a class="small-link" href="verify_code.php">Back to OTP verification</a>
        </div>
    </form>
</div>

<script>
document.querySelectorAll('.password-toggle').forEach(function(button) {
    button.addEventListener('click', function() {
        const targetId = button.getAttribute('data-target');
        const input = document.getElementById(targetId);
        const icon = button.querySelector('i');
        if (!input || !icon) return;

        const isHidden = input.type === 'password';
        input.type = isHidden ? 'text' : 'password';
        icon.className = isHidden ? 'bx bx-hide' : 'bx bx-show';
        button.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
    });
});
</script>
</body>
</html>