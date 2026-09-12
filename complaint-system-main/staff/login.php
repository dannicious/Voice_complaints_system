<?php
session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

$authCssVersion = @filemtime(__DIR__ . '/../Ui-main/assets/css/auth.css');
if ($authCssVersion === false) {
    $authCssVersion = time();
}

$error = '';
$status = trim((string)($_GET['status'] ?? ''));
$message = trim((string)($_GET['msg'] ?? ''));
if (!in_array($status, ['success', 'error', 'info'], true)) {
    $status = '';
    $message = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Please enter username and password.';
    } else {
        $stmt = $pdo->prepare('SELECT id, username, password, role FROM users WHERE username = :username LIMIT 1');
        $stmt->execute([':username' => $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $error = 'Invalid username or password.';
        } else {
            $storedPassword = (string)($user['password'] ?? '');
            $validPassword = $storedPassword !== '' && password_verify($password, $storedPassword);

            if (!$validPassword && $storedPassword !== '' && hash_equals($storedPassword, $password)) {
                $validPassword = true;
                $update = $pdo->prepare('UPDATE users SET password = :password WHERE id = :id');
                $update->execute([
                    ':password' => password_hash($password, PASSWORD_DEFAULT),
                    ':id' => (int)$user['id'],
                ]);
            }

            if (!$validPassword) {
                $error = 'Invalid username or password.';
            } elseif (strtolower(trim((string)$user['role'])) !== 'staff') {
                $error = 'Access denied. Staff accounts only.';
            } else {
                login_user($user);
                header('Location: dashboard.php');
                exit;
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Staff Login - VOICE</title>
    <link rel="stylesheet" href="../Ui-main/assets/css/auth.css?v=<?= (int)$authCssVersion ?>">
</head>
<body>
    <div class="auth-card">
        <div class="auth-logo">
            <img src="../Ui-main/assets/images/logo.png" alt="VOICE Logo">
            <h3 class="auth-title">VOICE</h3>
        </div>

        <div class="auth-sub">Staff Login:</div>

        <?php if ($status !== '' && $message !== ''): ?>
            <div class="flash-<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="flash-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form class="auth-form" method="post" action="">
            <div class="form-group">
                <label for="username">Username</label>
                <div class="input-wrapper">
                    <i class="bx bx-user icon-left"></i>
                    <input id="username" type="text" name="username" autocomplete="username" required>
                </div>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <div class="password-field">
                    <i class="bx bx-lock-alt icon-left"></i>
                    <input id="password" type="password" name="password" autocomplete="current-password" required>
                    <button class="password-toggle" type="button" data-target="password" aria-label="Show password">
                        <i class="bx bx-show"></i>
                    </button>
                </div>
            </div>

            <div class="auth-footer-row">
                <a class="small-link" href="../Ui-main/forgotpassword.php">Forgot your password?</a>
                <button class="auth-btn" type="submit">LOGIN</button>
            </div>
        </form>

        <div class="auth-footer-note">A web-based information system for managing student complaints and suggestion at BISU Balilihan Campus</div>
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
