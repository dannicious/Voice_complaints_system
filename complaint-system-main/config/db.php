<?php
// config/db.php
// Update these settings to match your local XAMPP MySQL configuration.
$DB_HOST = '127.0.0.1';
$DB_NAME = 'voicedb';
$DB_USER = 'root';
$DB_PASS = '';

try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    // In production, avoid echoing the raw error.
    die('Database connection failed: ' . $e->getMessage());
}

?>
