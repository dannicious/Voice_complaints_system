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
$requestedSemester = trim((string)($_POST['semester'] ?? $_GET['semester'] ?? ''));
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
    // Any syntactically valid school year is allowed, even one the student
    // has no records in — the tracking list shows an empty state for it
    // rather than silently bouncing the selection back to the current year.
    sy_set_selected($requested);
}

if ($studentProfileId > 0 && semester_is_valid_label($requestedSemester)) {
    semester_set_selected($requestedSemester);
}

header('Location: ' . $redirectTo);
exit;
