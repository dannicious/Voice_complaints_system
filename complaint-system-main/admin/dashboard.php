<?php
require_once __DIR__ . '/../config/auth.php';
ensure_role('admin');
header('Location: ../Ui-main/admin/admindashboard.php');
exit;
