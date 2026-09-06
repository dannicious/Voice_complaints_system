<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../school_year_helpers.php';

if (!isset($_SESSION['user_id']) || (string)($_SESSION['role'] ?? '') !== 'student') {
    header('Location: ../../student/login.php');
    exit;
}

$requested = trim((string)($_POST['school_year'] ?? $_GET['school_year'] ?? ''));
$redirectTo = (string)($_POST['redirect_to'] ?? $_GET['redirect_to'] ?? 'student_dashboard.php');

// Only allow same-origin redirect targets (site-absolute path or a bare
// filename) — never an external URL.
$isSafeRedirect = $redirectTo !== ''
    && !str_contains($redirectTo, '://')
    && !str_starts_with($redirectTo, '//')
    && !str_contains($redirectTo, "\r")
    && !str_contains($redirectTo, "\n");
if (!$isSafeRedirect) {
    $redirectTo = 'student_dashboard.php';
}

$studentProfileId = sy_resolve_student_profile_id($pdo);

if ($studentProfileId > 0 && sy_is_valid_label($requested)) {
    $available = sy_list_for_student($pdo, $studentProfileId);
    if (in_array($requested, $available, true)) {
        sy_set_selected($requested);
    }
}

header('Location: ' . $redirectTo);
exit;
