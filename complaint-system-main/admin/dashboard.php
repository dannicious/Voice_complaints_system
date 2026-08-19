<?php
require_once __DIR__ . '/../config/auth.php';
ensure_role('admin');
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Admin Dashboard</title></head>
<body>
    <h1>Admin Dashboard</h1>
    <p>Welcome, <?=htmlspecialchars($_SESSION['username'])?> (Admin)</p>
    <p><a href="log_out.php">Logout</a></p>
</body>
</html>
