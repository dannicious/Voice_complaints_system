<?php
// config/auth.php
// Authentication helper functions used by dashboards and login pages.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function login_user(array $user): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    session_regenerate_id(true);

    // Minimal session fingerprinting could be added later
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];
}

function ensure_role(string $expectedRole): void
{
    if (!isset($_SESSION['user_id'])) {
        // Not logged in -> redirect to role login
        header('Location: ../' . $expectedRole . '/login.php');
        exit;
    }

    if (!isset($_SESSION['role']) || $_SESSION['role'] !== $expectedRole) {
        // Logged in but wrong role -> show unauthorized message and stop
        http_response_code(403);
        echo 'Unauthorized role access.';
        exit;
    }
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'], $params['secure'], $params['httponly']
        );
    }
    session_destroy();
}

?>
