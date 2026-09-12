<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

ensure_role('staff');

$displayName = (string)($_SESSION['username'] ?? 'Staff');
$stmt = $pdo->prepare("SELECT name FROM staff_profiles WHERE user_id = :user_id AND status = 'active' LIMIT 1");
$stmt->execute([':user_id' => (int)$_SESSION['user_id']]);
$profileName = trim((string)$stmt->fetchColumn());
if ($profileName !== '') {
    $displayName = $profileName;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Staff Dashboard - VOICE</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <style>*{margin:0;padding:0;box-sizing:border-box;font-family:'Poppins',sans-serif}body{background:#f4f6fb}.main{margin-left:260px;margin-top:61px;padding:25px;min-height:calc(100vh - 61px)}.panel{max-width:1100px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px;box-shadow:0 2px 10px rgba(0,0,0,.02)}.panel h1{margin:0 0 8px;color:#333;font-size:24px;font-weight:600}.panel p{color:#6b7280;font-size:14px}@media(max-width:1024px){.main{margin-left:0;padding:16px}}</style>
</head>
<body>
    <?php include __DIR__ . '/../Ui-main/staff/staff_topbar.php'; ?>
    <?php include __DIR__ . '/../Ui-main/staff/staff_sidebar.php'; ?>
    <main class="main"><section class="panel"><h1>Staff Dashboard</h1><p>Welcome, <?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?>. Staff access is active.</p></section></main>
</body>
</html>
