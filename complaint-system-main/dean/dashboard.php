<?php
require_once __DIR__ . '/../config/auth.php';
header('Location: ../Ui-main/dean/dean_dashboard.php');
exit;
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Dean Dashboard</title></head>
<body>
    <h1>Dean Dashboard</h1>
    <p>Welcome, <?=htmlspecialchars($_SESSION['username'])?> (Dean)</p>
    <p><a href="log_out.php">Logout</a></p>
</body>
</html>
