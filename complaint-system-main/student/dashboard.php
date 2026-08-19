<?php
require_once __DIR__ . '/../config/auth.php';
ensure_role('student');
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Student Dashboard</title></head>
<body>
    <h1>Student Dashboard</h1>
    <p>Welcome, <?=htmlspecialchars($_SESSION['username'])?> (Student)</p>
    <p><a href="log_out.php">Logout</a></p>
</body>
</html>
