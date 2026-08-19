<?php
session_start();
require_once __DIR__ . '/../db_connection.php';

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$botName = 'VOICE Assistant';
$botStatusText = 'Online';
$botWelcome = "Hello! I am your VOICE Assistant. I can help you find campus resources, guide you on how to file complaints, or answer general university questions. How can I assist you today?";
$botIsActive = true;
$canSend = isset($_SESSION['user_id']) && (string)($_SESSION['role'] ?? '') === 'student';
$messages = [];

try {
    $settingsStmt = $pdo->query('SELECT bot_name, welcome_message, is_active FROM chatbot_settings ORDER BY id ASC LIMIT 1');
    $settings = $settingsStmt->fetch();
    if ($settings) {
        $name = trim((string)($settings['bot_name'] ?? ''));
        $welcome = trim((string)($settings['welcome_message'] ?? ''));
        $botIsActive = (int)($settings['is_active'] ?? 1) === 1;
        $botStatusText = $botIsActive ? 'Online' : 'Offline';
        if ($name !== '') {
            $botName = $name;
        }
        if ($welcome !== '') {
            $botWelcome = $welcome;
        }
    }

    if ($canSend) {
        $msgStmt = $pdo->prepare(
            'SELECT sender, message
             FROM chatbot_conversations
             WHERE user_id = :user_id
             ORDER BY id DESC
             LIMIT 50'
        );
        $msgStmt->execute([':user_id' => (int)$_SESSION['user_id']]);
        $rows = $msgStmt->fetchAll();
        $rows = array_reverse($rows);

        foreach ($rows as $row) {
            $sender = (string)$row['sender'];
            if (!in_array($sender, ['student', 'bot'], true)) {
                continue;
            }
            $messages[] = [
                'sender' => $sender,
                'message' => (string)$row['message'],
            ];
        }
    }
} catch (PDOException $e) {
    // Keep safe defaults.
}

if (count($messages) === 0) {
    $messages[] = [
        'sender' => 'bot',
        'message' => $canSend
            ? $botWelcome
            : 'Please log in as a student to start chatting with the assistant.',
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Chatbot Help - VOICE</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>

<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: 'Poppins', sans-serif;
}

body {
    background: #f4f6fb;
}

/* ===== MAIN CONTENT ===== */
.main {
    margin-left: 260px;
    margin-top: 61px;
    padding: 25px;
    min-height: calc(100vh - 61px);
    display: flex;
    justify-content: center; /* Centers the whole chat block horizontally */
}

/* Wrapper to keep title and chat aligned */
.content-wrapper {
    width: 100%;
    max-width: 900px; /* Matches the chat card width */
}

/* ===== CHATBOT STYLES ===== */
.page-title {
    margin-bottom: 25px;
    color: #333;
    font-weight: 600;
    font-size: 24px;
    text-align: left; /* Aligned with the left of the chat box */
}

.chat-card {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.03);
    width: 100%;
    border: 1px solid #eee;
    display: flex;
    flex-direction: column;
    height: calc(100vh - 180px); 
    min-height: 500px;
    margin-bottom: 40px; 
    overflow: hidden;
}

.chat-header {
    padding: 20px;
    border-bottom: 1px solid #eee;
    display: flex;
    align-items: center;
    gap: 15px;
    background: #fff;
}

.chat-header .bot-icon {
    width: 45px;
    height: 45px;
    background: #eef2ff;
    color: #4F8CFF;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
}

.chat-header-info h3 {
    font-size: 16px;
    color: #333;
    font-weight: 600;
}

.chat-header-info p {
    font-size: 12px;
    color: #10b981;
    display: flex;
    align-items: center;
    gap: 5px;
}

.chat-header-info p::before {
    content: '';
    display: inline-block;
    width: 8px;
    height: 8px;
    background: #10b981;
    border-radius: 50%;
}

.chat-messages {
    flex: 1;
    padding: 25px;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: 15px;
    background: #fcfdfd;
}

.msg {
    max-width: 75%;
    padding: 14px 18px;
    font-size: 14px;
    line-height: 1.5;
    position: relative;
    word-wrap: break-word;
}

.msg-bot {
    background: #fff;
    color: #444;
    border: 1px solid #e5e7eb;
    align-self: flex-start;
    border-radius: 15px 15px 15px 4px;
    box-shadow: 0 2px 5px rgba(0,0,0,0.02);
}

.msg-user {
    background: #6d28d9;
    color: #fff;
    align-self: flex-end;
    border-radius: 15px 15px 4px 15px;
    box-shadow: 0 2px 5px rgba(109,40,217,0.2);
}

.chat-input-area {
    padding: 20px;
    background: #fff;
    border-top: 1px solid #eee;
    display: flex;
    gap: 15px;
    align-items: center;
}

.chat-input {
    flex: 1;
    padding: 14px 20px;
    border: 1px solid #e5e7eb;
    border-radius: 30px;
    background: #f9fafb;
    font-size: 14px;
    outline: none;
    transition: 0.2s;
}

.chat-input:focus {
    border-color: #6d28d9;
    background: #fff;
}

.btn-send {
    background: #6d28d9;
    color: white;
    border: none;
    width: 48px;
    height: 48px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 22px;
    transition: 0.2s;
    box-shadow: 0 4px 10px rgba(109,40,217,0.3);
}

.btn-send:hover {
    background: #5d1fa0;
    transform: translateY(-2px);
}
</style>
</head>

<body>

<?php include 'student_topbar.php'; ?>

<?php include 'student_sidebar.php'; ?>

<div class="main">

    <div class="content-wrapper">
        <h2 class="page-title">VOICE Assistant</h2>
        
        <div class="chat-card">
            <div class="chat-header">
                <div class="bot-icon"><i class='bx bx-bot'></i></div>
                <div class="chat-header-info">
                    <h3><?php echo e($botName); ?></h3>
                    <p><?php echo e($botStatusText); ?></p>
                </div>
            </div>

            <div class="chat-messages" id="chatMessages">
                <?php foreach ($messages as $msg): ?>
                    <div class="msg <?php echo $msg['sender'] === 'student' ? 'msg-user' : 'msg-bot'; ?>"><?php echo e($msg['message']); ?></div>
                <?php endforeach; ?>
            </div>

            <div class="chat-input-area">
                <input type="text" id="chatInput" class="chat-input" placeholder="Type your message here..." autocomplete="off" <?php echo $canSend ? '' : 'disabled'; ?>>
                <button class="btn-send" onclick="sendMessage()" <?php echo $canSend ? '' : 'disabled'; ?>><i class='bx bx-send'></i></button>
            </div>
        </div>
    </div>

</div>

<script>
const canSendMessage = <?php echo $canSend ? 'true' : 'false'; ?>;

function sendMessage() {
    if (!canSendMessage) {
        return;
    }

    const inputField = document.getElementById('chatInput');
    const messageText = inputField.value.trim();
    const chatContainer = document.getElementById('chatMessages');

    if (messageText !== "") {
        const userMsg = document.createElement('div');
        userMsg.className = 'msg msg-user';
        userMsg.textContent = messageText;
        chatContainer.appendChild(userMsg);
        inputField.value = '';
        chatContainer.scrollTop = chatContainer.scrollHeight;

        fetch('chatbot_message.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
            },
            body: 'message=' + encodeURIComponent(messageText)
        })
        .then(response => response.json())
        .then(data => {
            const botMsg = document.createElement('div');
            botMsg.className = 'msg msg-bot';
            botMsg.textContent = data && data.message ? data.message : 'No response from chatbot.';
            chatContainer.appendChild(botMsg);
            chatContainer.scrollTop = chatContainer.scrollHeight;
        })
        .catch(() => {
            const botMsg = document.createElement('div');
            botMsg.className = 'msg msg-bot';
            botMsg.textContent = 'Unable to contact chatbot right now. Please try again.';
            chatContainer.appendChild(botMsg);
            chatContainer.scrollTop = chatContainer.scrollHeight;
        });
    }
}

document.getElementById('chatInput').addEventListener('keypress', function (e) {
    if (e.key === 'Enter') {
        sendMessage();
    }
});
</script>

</body>
</html>