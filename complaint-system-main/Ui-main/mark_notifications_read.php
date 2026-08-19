<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/db_connection.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
    exit;
}

$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized.']);
    exit;
}

$scope = trim((string)($_POST['scope'] ?? 'all'));

try {
    if ($scope === 'all') {
        $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = :user_id AND is_read = 0');
        $stmt->execute([':user_id' => $userId]);
        echo json_encode(['ok' => true, 'updated' => $stmt->rowCount()]);
        exit;
    }

    $notificationId = (int)($_POST['notification_id'] ?? 0);
    if ($notificationId <= 0) {
        echo json_encode(['ok' => false, 'message' => 'Invalid notification ID.']);
        exit;
    }

    $stmt = $pdo->prepare(
        'UPDATE notifications
         SET is_read = 1
         WHERE id = :id AND user_id = :user_id AND is_read = 0'
    );
    $stmt->execute([
        ':id' => $notificationId,
        ':user_id' => $userId,
    ]);

    echo json_encode(['ok' => true, 'updated' => $stmt->rowCount()]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Database error.']);
}
