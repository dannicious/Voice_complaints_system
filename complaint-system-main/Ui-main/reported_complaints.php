<?php

// This page has moved so it can show the normal topbar/sidebar (it needs to
// live inside admin/ or dean/ for their nav's relative links/assets to
// resolve correctly). Kept here as a redirect for any old/bookmarked links.
// See admin/admin_reported_complaints.php and dean/dean_reported_complaints.php.

session_start();

$role = strtolower(trim((string)($_SESSION['role'] ?? '')));
$query = $_SERVER['QUERY_STRING'] ?? '';

if ($role === 'dean') {
    header('Location: dean/dean_reported_complaints.php' . ($query !== '' ? '?' . $query : ''));
    exit;
}

if ($role === 'admin') {
    header('Location: admin/admin_reported_complaints.php' . ($query !== '' ? '?' . $query : ''));
    exit;
}

header('Location: student/login.php?error=' . urlencode('Please log in as admin or dean.'));
exit;
