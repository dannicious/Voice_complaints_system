<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/../db_connection.php';

header('Content-Type: application/json; charset=utf-8');

function json_response(bool $ok, string $message, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode([
        'ok' => $ok,
        'message' => $message,
    ]);
    exit;
}

function normalize_text(string $text): string
{
    $text = mb_strtolower($text);
    $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text);
    $text = preg_replace('/\s+/u', ' ', $text);
    return trim($text);
}

function tokenize_text(string $text): array
{
    $text = normalize_text($text);
    if ($text === '') {
        return [];
    }
    return preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
}

function tokens_in_order(array $haystack, array $needle): bool
{
    if (empty($needle)) {
        return false;
    }

    $position = 0;
    foreach ($needle as $token) {
        $found = false;
        while ($position < count($haystack)) {
            if ($haystack[$position] === $token) {
                $found = true;
                $position++;
                break;
            }
            $position++;
        }
        if (!$found) {
            return false;
        }
    }

    return true;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, 'Invalid request method.', 405);
}

if (!isset($_SESSION['user_id']) || (string)($_SESSION['role'] ?? '') !== 'student') {
    json_response(false, 'Please log in as a student to use chatbot.', 401);
}

$message = trim((string)($_POST['message'] ?? ''));
if ($message === '') {
    json_response(false, 'Message cannot be empty.', 422);
}

if (mb_strlen($message) > 1000) {
    json_response(false, 'Message is too long.', 422);
}

try {
    $settingsStmt = $pdo->query('SELECT bot_name, welcome_message, fallback_message, is_active FROM chatbot_settings ORDER BY id ASC LIMIT 1');
    $settings = $settingsStmt->fetch();

    $fallback = "I'm not sure about that. Please file a formal complaint.";
    $botReply = $fallback;
    $isActive = true;

    if ($settings) {
        $fallbackDb = trim((string)($settings['fallback_message'] ?? ''));
        if ($fallbackDb !== '') {
            $fallback = $fallbackDb;
        }
        $isActive = (int)($settings['is_active'] ?? 1) === 1;
    }

    $insertStudent = $pdo->prepare(
        'INSERT INTO chatbot_conversations (user_id, sender, message)
         VALUES (:user_id, :sender, :message)'
    );
    $insertStudent->execute([
        ':user_id' => (int)$_SESSION['user_id'],
        ':sender' => 'student',
        ':message' => $message,
    ]);

    if (!$isActive) {
        $botReply = 'Chatbot is currently offline for maintenance. Please try again later.';
    } else {
        $messageNormalized = normalize_text($message);
        $messageTokens = tokenize_text($messageNormalized);
        $triggerStmt = $pdo->query('SELECT keywords, response FROM chatbot_triggers WHERE is_active = 1 ORDER BY id DESC');
        $triggers = $triggerStmt->fetchAll();

        foreach ($triggers as $trigger) {
            $rawKeywords = (string)$trigger['keywords'];
            $response = trim((string)$trigger['response']);
            if ($response === '') {
                continue;
            }

            $parts = preg_split('/\s*,\s*/', $rawKeywords);
            $matched = false;
            foreach ($parts as $kw) {
                $normalizedKeyword = normalize_text((string)$kw);
                if ($normalizedKeyword === '') {
                    continue;
                }

                if (mb_strpos($messageNormalized, $normalizedKeyword) !== false) {
                    $matched = true;
                    break;
                }

                $keywordTokens = tokenize_text($normalizedKeyword);
                if (tokens_in_order($messageTokens, $keywordTokens)) {
                    $matched = true;
                    break;
                }
            }

            if ($matched) {
                $botReply = $response;
                break;
            }
        }

        if ($botReply === '') {
            $botReply = $fallback;
        }
    }

    $insertBot = $pdo->prepare(
        'INSERT INTO chatbot_conversations (user_id, sender, message)
         VALUES (:user_id, :sender, :message)'
    );
    $insertBot->execute([
        ':user_id' => (int)$_SESSION['user_id'],
        ':sender' => 'bot',
        ':message' => $botReply,
    ]);

    json_response(true, $botReply);
} catch (PDOException $e) {
    json_response(false, 'Unable to process chatbot message right now.', 500);
}
