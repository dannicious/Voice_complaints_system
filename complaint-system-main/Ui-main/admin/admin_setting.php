<?php
session_start();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../ticket_flow.php';

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function verify_password_compat(string $plainPassword, string $storedPassword): bool
{
    if ($storedPassword === '') {
        return false;
    }

    if (password_verify($plainPassword, $storedPassword)) {
        return true;
    }

    // Compatibility fallback for old/plain-text passwords.
    return hash_equals($storedPassword, $plainPassword);
}

function route_to_label(?string $route, string $ticketType, string $name): string
{
    $normalized = normalize_ticket_route($route);
    if ($normalized !== null) {
        return $normalized;
    }

    return ticket_route_role($ticketType, $name) === 'dean' ? 'college' : 'general';
}

function category_table_for_scope(string $scope): string
{
    return $scope === 'suggestion' ? 'suggestion_categories' : 'complaint_categories';
}

function category_label_for_scope(string $scope): string
{
    return $scope === 'suggestion' ? 'Suggestion Topics' : 'Issue Categories (Complaints)';
}

function category_context_label(string $scope): string
{
    return $scope === 'suggestion' ? 'Suggestion' : 'Complaint';
}

function normalize_category_scope(?string $scope): ?string
{
    $value = strtolower(trim((string)$scope));
    return in_array($value, ['complaint', 'suggestion'], true) ? $value : null;
}

function fetch_category_rows(PDO $pdo, string $scope): array
{
    $table = category_table_for_scope($scope);
    $rows = [];

    try {
        $stmt = $pdo->query(
            "SELECT id, name, COALESCE(category_type, route) AS category_type
             FROM {$table}
             ORDER BY name ASC"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
    }

    return $rows;
}

function redirect_settings(string $tab, string $type, string $msg): void
{
    $query = http_build_query([
        'tab' => $tab,
        'status' => $type,
        'msg' => $msg,
    ]);
    header('Location: admin_setting.php?' . $query);
    exit;
}

function log_admin_activity(PDO $pdo, int $userId, string $action, ?string $targetType = null, ?int $targetId = null): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO activity_logs (user_id, role, action, target_type, target_id)
         VALUES (:user_id, :role, :action, :target_type, :target_id)'
    );

    $stmt->execute([
        ':user_id' => $userId > 0 ? $userId : null,
        ':role' => 'admin',
        ':action' => $action,
        ':target_type' => $targetType,
        ':target_id' => $targetId,
    ]);
}

$activeTab = (string)($_GET['tab'] ?? 'chatbot');
$allowedTabs = ['chatbot', 'categories', 'profile', 'logs'];
if (!in_array($activeTab, $allowedTabs, true)) {
    $activeTab = 'chatbot';
}

$flashStatus = (string)($_GET['status'] ?? '');
$flashMessage = trim((string)($_GET['msg'] ?? ''));
if (!in_array($flashStatus, ['success', 'error'], true)) {
    $flashStatus = '';
    $flashMessage = '';
}

$currentUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$currentRole = (string)($_SESSION['role'] ?? '');
$adminUserId = 0;

try {
    if ($currentUserId <= 0) {
        header('Location: /complaint-system-main/admin/login.php');
        exit;
    }

    if ($currentRole !== 'admin') {
        $roleStmt = $pdo->prepare('SELECT role FROM users WHERE id = :id LIMIT 1');
        $roleStmt->execute([':id' => $currentUserId]);
        $roleRow = $roleStmt->fetch(PDO::FETCH_ASSOC);

        if (!$roleRow || (string)$roleRow['role'] !== 'admin') {
            header('Location: /complaint-system-main/admin/login.php');
            exit;
        }

        $_SESSION['role'] = 'admin';
    }

    $adminUserId = $currentUserId;
} catch (PDOException $e) {
    header('Location: /complaint-system-main/admin/login.php');
    exit;
}

ensure_category_route_columns($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    $token = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        redirect_settings($activeTab, 'error', 'Invalid request token. Please refresh and try again.');
    }

    try {
        if ($action === 'update_chatbot_identity') {
            $botName = trim((string)($_POST['bot_name'] ?? ''));
            $welcomeMessage = trim((string)($_POST['welcome_message'] ?? ''));

            if ($botName === '' || $welcomeMessage === '') {
                redirect_settings('chatbot', 'error', 'Bot name and welcome message are required.');
            }

            $rowStmt = $pdo->query('SELECT id FROM chatbot_settings ORDER BY id ASC LIMIT 1');
            $row = $rowStmt->fetch();

            if ($row) {
                $stmt = $pdo->prepare(
                    'UPDATE chatbot_settings
                     SET bot_name = :bot_name, welcome_message = :welcome_message
                     WHERE id = :id'
                );
                $stmt->execute([
                    ':bot_name' => $botName,
                    ':welcome_message' => $welcomeMessage,
                    ':id' => (int)$row['id'],
                ]);
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO chatbot_settings (bot_name, welcome_message, fallback_message, is_active)
                     VALUES (:bot_name, :welcome_message, :fallback_message, :is_active)'
                );
                $stmt->execute([
                    ':bot_name' => $botName,
                    ':welcome_message' => $welcomeMessage,
                    ':fallback_message' => "I'm not sure about that. Please file a formal complaint.",
                    ':is_active' => 1,
                ]);
            }

            if ($adminUserId > 0) {
                log_admin_activity($pdo, $adminUserId, 'Updated chatbot identity settings', 'chatbot_settings', 1);
            }

            redirect_settings('chatbot', 'success', 'Chatbot identity updated successfully.');
        }

        if ($action === 'update_chatbot_runtime') {
            $fallbackMessage = trim((string)($_POST['fallback_message'] ?? ''));
            $isActive = (string)($_POST['chatbot_status'] ?? '1') === '1' ? 1 : 0;

            if ($fallbackMessage === '') {
                redirect_settings('chatbot', 'error', 'Default fallback response is required.');
            }

            $rowStmt = $pdo->query('SELECT id, bot_name, welcome_message FROM chatbot_settings ORDER BY id ASC LIMIT 1');
            $row = $rowStmt->fetch();

            if ($row) {
                $stmt = $pdo->prepare(
                    'UPDATE chatbot_settings
                     SET fallback_message = :fallback_message, is_active = :is_active
                     WHERE id = :id'
                );
                $stmt->execute([
                    ':fallback_message' => $fallbackMessage,
                    ':is_active' => $isActive,
                    ':id' => (int)$row['id'],
                ]);
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO chatbot_settings (bot_name, welcome_message, fallback_message, is_active)
                     VALUES (:bot_name, :welcome_message, :fallback_message, :is_active)'
                );
                $stmt->execute([
                    ':bot_name' => 'VOICE Assistant',
                    ':welcome_message' => "Hi! I'm the VOICE Assistant. How can I help you today?",
                    ':fallback_message' => $fallbackMessage,
                    ':is_active' => $isActive,
                ]);
            }

            if ($adminUserId > 0) {
                log_admin_activity($pdo, $adminUserId, 'Updated chatbot runtime settings', 'chatbot_settings', 1);
            }

            redirect_settings('chatbot', 'success', 'Chatbot runtime settings saved.');
        }

        if ($action === 'add_trigger') {
            $keywords = trim((string)($_POST['keywords'] ?? ''));
            $response = trim((string)($_POST['response'] ?? ''));

            if ($keywords === '' || $response === '') {
                redirect_settings('chatbot', 'error', 'Keywords and response are required.');
            }

            $stmt = $pdo->prepare(
                'INSERT INTO chatbot_triggers (keywords, response, is_active)
                 VALUES (:keywords, :response, :is_active)'
            );
            $stmt->execute([
                ':keywords' => $keywords,
                ':response' => $response,
                ':is_active' => 1,
            ]);

            if ($adminUserId > 0) {
                log_admin_activity($pdo, $adminUserId, 'Added chatbot trigger', 'chatbot_triggers', (int)$pdo->lastInsertId());
            }

            redirect_settings('chatbot', 'success', 'New chatbot trigger added.');
        }

        if ($action === 'delete_trigger') {
            $triggerId = (int)($_POST['trigger_id'] ?? 0);
            if ($triggerId <= 0) {
                redirect_settings('chatbot', 'error', 'Invalid trigger selected.');
            }

            $stmt = $pdo->prepare('DELETE FROM chatbot_triggers WHERE id = :id');
            $stmt->execute([':id' => $triggerId]);

            if ($adminUserId > 0) {
                log_admin_activity($pdo, $adminUserId, 'Deleted chatbot trigger', 'chatbot_triggers', $triggerId);
            }

            redirect_settings('chatbot', 'success', 'Trigger removed successfully.');
        }

        if ($action === 'save_category') {
            $scope = normalize_category_scope((string)($_POST['category_scope'] ?? ''));
            $name = trim((string)($_POST['category_name'] ?? ''));
            $type = normalize_ticket_route((string)($_POST['category_type'] ?? 'general')) ?? 'general';
            $categoryId = (int)($_POST['category_id'] ?? 0);

            if ($scope === null) {
                redirect_settings('categories', 'error', 'Please choose a category group.');
            }

            if ($name === '') {
                redirect_settings('categories', 'error', 'Category name is required.');
            }

            $table = category_table_for_scope($scope);
            $existingStmt = $pdo->prepare("SELECT id FROM {$table} WHERE name = :name" . ($categoryId > 0 ? ' AND id <> :id' : '') . ' LIMIT 1');
            $existingParams = [':name' => $name];
            if ($categoryId > 0) {
                $existingParams[':id'] = $categoryId;
            }
            $existingStmt->execute($existingParams);

            if ($existingStmt->fetch()) {
                redirect_settings('categories', 'error', 'That category name already exists.');
            }

            $pdo->beginTransaction();
            if ($categoryId > 0) {
                $updateStmt = $pdo->prepare(
                    "UPDATE {$table}
                     SET name = :name, category_type = :category_type, route = :route, is_active = 1
                     WHERE id = :id"
                );
                $updateStmt->execute([
                    ':name' => $name,
                    ':category_type' => $type,
                    ':route' => $type,
                    ':id' => $categoryId,
                ]);
                $actionLabel = 'Updated ' . $scope . ' category';
            } else {
                $insertStmt = $pdo->prepare(
                    "INSERT INTO {$table} (name, category_type, route, is_active)
                     VALUES (:name, :category_type, :route, 1)"
                );
                $insertStmt->execute([
                    ':name' => $name,
                    ':category_type' => $type,
                    ':route' => $type,
                ]);
                $actionLabel = 'Added ' . $scope . ' category';
            }
            $pdo->commit();

            if ($adminUserId > 0) {
                log_admin_activity($pdo, $adminUserId, $actionLabel, $table, $categoryId > 0 ? $categoryId : (int)$pdo->lastInsertId());
            }

            redirect_settings('categories', 'success', ucfirst($scope) . ' category saved successfully.');
        }

        if ($action === 'delete_category') {
            $scope = normalize_category_scope((string)($_POST['category_scope'] ?? ''));
            $categoryId = (int)($_POST['category_id'] ?? 0);

            if ($scope === null || $categoryId <= 0) {
                redirect_settings('categories', 'error', 'Invalid category selected.');
            }

            $table = category_table_for_scope($scope);
            $deleteStmt = $pdo->prepare("DELETE FROM {$table} WHERE id = :id");
            $deleteStmt->execute([':id' => $categoryId]);

            if ($adminUserId > 0) {
                log_admin_activity($pdo, $adminUserId, 'Deleted ' . $scope . ' category', $table, $categoryId);
            }

            redirect_settings('categories', 'success', ucfirst($scope) . ' category deleted successfully.');
        }

        if ($action === 'update_profile') {
            if ($adminUserId <= 0) {
                redirect_settings('profile', 'error', 'No admin user available to update.');
            }

            $adminName = trim((string)($_POST['admin_name'] ?? ''));
            $adminEmail = strtolower(trim((string)($_POST['admin_email'] ?? '')));

            if ($adminName === '' || $adminEmail === '') {
                redirect_settings('profile', 'error', 'Name and email are required.');
            }

            if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
                redirect_settings('profile', 'error', 'Please provide a valid email address.');
            }

            $pdo->beginTransaction();

            $emailDupStmt = $pdo->prepare(
                'SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1'
            );
            $emailDupStmt->execute([
                ':email' => $adminEmail,
                ':id' => $adminUserId,
            ]);
            if ($emailDupStmt->fetch()) {
                $pdo->rollBack();
                redirect_settings('profile', 'error', 'That email is already used by another account.');
            }

            $updateUserStmt = $pdo->prepare('UPDATE users SET email = :email WHERE id = :id');
            $updateUserStmt->execute([
                ':email' => $adminEmail,
                ':id' => $adminUserId,
            ]);

            $profilePicPath = null;
            if (isset($_FILES['imageUpload']) && is_array($_FILES['imageUpload']) && (int)$_FILES['imageUpload']['error'] === UPLOAD_ERR_OK) {
                $tmp = (string)$_FILES['imageUpload']['tmp_name'];
                $original = (string)$_FILES['imageUpload']['name'];
                $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
                $allowed = ['jpg', 'jpeg', 'png', 'webp'];

                if (!in_array($ext, $allowed, true)) {
                    $pdo->rollBack();
                    redirect_settings('profile', 'error', 'Invalid image format. Use JPG, PNG, or WEBP.');
                }

                $destDir = __DIR__ . '/../assets/images/profiles';
                if (!is_dir($destDir)) {
                    mkdir($destDir, 0775, true);
                }

                $filename = 'admin_' . $adminUserId . '_' . time() . '.' . $ext;
                $destAbs = $destDir . '/' . $filename;
                if (!move_uploaded_file($tmp, $destAbs)) {
                    $pdo->rollBack();
                    redirect_settings('profile', 'error', 'Failed to upload profile image.');
                }

                $profilePicPath = 'assets/images/profiles/' . $filename;

                $picStmt = $pdo->prepare('UPDATE users SET profile_pic = :profile_pic WHERE id = :id');
                $picStmt->execute([
                    ':profile_pic' => $profilePicPath,
                    ':id' => $adminUserId,
                ]);
            }

            $profileStmt = $pdo->prepare('SELECT id FROM admin_profiles WHERE user_id = :user_id LIMIT 1');
            $profileStmt->execute([':user_id' => $adminUserId]);
            $existing = $profileStmt->fetch();

            if ($existing) {
                $updateProfileStmt = $pdo->prepare('UPDATE admin_profiles SET name = :name WHERE user_id = :user_id');
                $updateProfileStmt->execute([
                    ':name' => $adminName,
                    ':user_id' => $adminUserId,
                ]);
            } else {
                $insertProfileStmt = $pdo->prepare('INSERT INTO admin_profiles (user_id, name) VALUES (:user_id, :name)');
                $insertProfileStmt->execute([
                    ':user_id' => $adminUserId,
                    ':name' => $adminName,
                ]);
            }

            $pdo->commit();

            if ($adminUserId > 0) {
                log_admin_activity($pdo, $adminUserId, 'Updated admin profile', 'admin_profiles', $adminUserId);
            }

            redirect_settings('profile', 'success', $profilePicPath ? 'Profile and image updated successfully.' : 'Profile updated successfully.');
        }

        if ($action === 'update_password') {
            if ($adminUserId <= 0) {
                redirect_settings('profile', 'error', 'No admin user available to update.');
            }

            $currentPassword = (string)($_POST['current_password'] ?? '');
            $newPassword = (string)($_POST['new_password'] ?? '');
            $confirmPassword = (string)($_POST['confirm_password'] ?? '');

            if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
                redirect_settings('profile', 'error', 'All password fields are required.');
            }

            if (strlen($newPassword) < 6) {
                redirect_settings('profile', 'error', 'New password must be at least 6 characters.');
            }

            if ($newPassword !== $confirmPassword) {
                redirect_settings('profile', 'error', 'New password and confirmation do not match.');
            }

            $userStmt = $pdo->prepare('SELECT password FROM users WHERE id = :id LIMIT 1');
            $userStmt->execute([':id' => $adminUserId]);
            $user = $userStmt->fetch();

            if (!$user || !verify_password_compat($currentPassword, (string)$user['password'])) {
                redirect_settings('profile', 'error', 'Current password is incorrect.');
            }

            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $updateStmt = $pdo->prepare('UPDATE users SET password = :password WHERE id = :id');
            $updateStmt->execute([
                ':password' => $newHash,
                ':id' => $adminUserId,
            ]);

            if ($adminUserId > 0) {
                log_admin_activity($pdo, $adminUserId, 'Updated account password', 'users', $adminUserId);
            }

            redirect_settings('profile', 'success', 'Password updated successfully.');
        }

        redirect_settings($activeTab, 'error', 'Unknown action requested.');
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        redirect_settings($activeTab, 'error', 'Database error: ' . $e->getMessage());
    }
}

$chatbot = [
    'bot_name' => 'VOICE Assistant',
    'welcome_message' => "Hi! I'm the VOICE Assistant. How can I help you today?",
    'fallback_message' => "I'm not sure about that. Please file a formal complaint.",
    'is_active' => 1,
];
$triggers = [];
$issueCategories = [];
$suggestionCategories = [];
$editCategoryScope = normalize_category_scope((string)($_GET['edit_scope'] ?? ''));
$editCategoryId = (int)($_GET['edit_id'] ?? 0);
$editCategory = null;
$issueFormName = '';
$issueFormType = 'general';
$suggestionFormName = '';
$suggestionFormType = 'general';
$profile = [
    'name' => 'Super Admin',
    'email' => 'admin@voice-system.edu',
    'profile_pic' => '',
];
$logs = [];

try {
    $chatbotStmt = $pdo->query('SELECT * FROM chatbot_settings ORDER BY id ASC LIMIT 1');
    $chatbotRow = $chatbotStmt->fetch();
    if ($chatbotRow) {
        $chatbot['bot_name'] = (string)$chatbotRow['bot_name'];
        $chatbot['welcome_message'] = (string)$chatbotRow['welcome_message'];
        $chatbot['fallback_message'] = (string)$chatbotRow['fallback_message'];
        $chatbot['is_active'] = (int)$chatbotRow['is_active'];
    }

    $triggerStmt = $pdo->query('SELECT id, keywords, response FROM chatbot_triggers ORDER BY id DESC');
    $triggers = $triggerStmt->fetchAll();

    $issueRows = fetch_category_rows($pdo, 'complaint');
    foreach ($issueRows as $row) {
        $name = (string)($row['name'] ?? '');
        $type = route_to_label($row['category_type'] ?? null, 'complaint', $name);
        $issueCategories[] = [
            'id' => (int)($row['id'] ?? 0),
            'name' => $name,
            'type' => $type,
        ];
        if ($editCategoryScope === 'complaint' && $editCategoryId > 0 && (int)($row['id'] ?? 0) === $editCategoryId) {
            $editCategory = [
                'scope' => 'complaint',
                'id' => (int)$row['id'],
                'name' => $name,
                'type' => $type,
            ];
        }
    }

    $suggestionRows = fetch_category_rows($pdo, 'suggestion');
    foreach ($suggestionRows as $row) {
        $name = (string)($row['name'] ?? '');
        $type = route_to_label($row['category_type'] ?? null, 'suggestion', $name);
        $suggestionCategories[] = [
            'id' => (int)($row['id'] ?? 0),
            'name' => $name,
            'type' => $type,
        ];
        if ($editCategoryScope === 'suggestion' && $editCategoryId > 0 && (int)($row['id'] ?? 0) === $editCategoryId) {
            $editCategory = [
                'scope' => 'suggestion',
                'id' => (int)$row['id'],
                'name' => $name,
                'type' => $type,
            ];
        }
    }

    if ($editCategory !== null) {
        if ($editCategory['scope'] === 'complaint') {
            $issueFormName = (string)$editCategory['name'];
            $issueFormType = (string)$editCategory['type'];
        } else {
            $suggestionFormName = (string)$editCategory['name'];
            $suggestionFormType = (string)$editCategory['type'];
        }
    }

    if ($adminUserId > 0) {
        $profileStmt = $pdo->prepare(
            'SELECT u.email, u.profile_pic, COALESCE(ap.name, u.username) AS display_name
             FROM users u
             LEFT JOIN admin_profiles ap ON ap.user_id = u.id
             WHERE u.id = :id
             LIMIT 1'
        );
        $profileStmt->execute([':id' => $adminUserId]);
        $profileRow = $profileStmt->fetch();
        if ($profileRow) {
            $profile['name'] = (string)$profileRow['display_name'];
            $profile['email'] = (string)$profileRow['email'];
            $profile['profile_pic'] = (string)($profileRow['profile_pic'] ?? '');
        }
    }

    $logStmt = $pdo->query(
        "SELECT l.action, l.created_at,
                COALESCE(ap.name, u.username, 'System') AS admin_name
         FROM activity_logs l
         LEFT JOIN users u ON u.id = l.user_id
         LEFT JOIN admin_profiles ap ON ap.user_id = l.user_id
         WHERE l.role = 'admin'
         ORDER BY l.created_at DESC
         LIMIT 30"
    );
    $logs = $logStmt->fetchAll();
} catch (PDOException $e) {
    if ($flashMessage === '') {
        $flashStatus = 'error';
        $flashMessage = 'Could not load one or more settings sections. Please verify database tables.';
    }
}

$profileImage = trim($profile['profile_pic']) !== '' ? '../' . ltrim($profile['profile_pic'], '/') : 'https://i.pravatar.cc/150?img=12';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings | VOICE System</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>

    <style>
        :root {
            --primary: #4F8CFF;
            --primary-hover: #3b6fd1;
            --primary-light: rgba(79, 140, 255, 0.1);
            --bg: #f4f6f9;
            --card-bg: #ffffff;
            --border: #e1e5eb;
            --text-main: #2c3e50;
            --text-light: #64748b;
            --danger: #ef4444;
            --success: #10b981;
            --warning: #f59e0b;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        
        body {
            background-color: var(--bg);
            color: var(--text-main);
            line-height: 1.5;
            overflow-x: hidden;
        }

        .page-wrapper {
            margin-left: 260px;
            margin-top: 60px;
            min-height: calc(100vh - 60px);
            display: flex;
            flex-direction: column;
        }

        .nav-header {
            background: var(--card-bg);
            border-bottom: 1px solid var(--border);
            padding: 0 40px;
            display: flex;
            align-items: center;
            box-shadow: 0 4px 15px rgba(0,0,0,0.02);
            position: sticky;
            top: 60px;
            z-index: 900;
        }

        .nav-tabs {
            display: flex;
            gap: 40px;
            overflow-x: auto;
            flex-grow: 1;
        }

        .nav-tabs::-webkit-scrollbar { display: none; }

        .tab-btn {
            background: none;
            border: none;
            padding: 22px 0;
            font-size: 14.5px;
            font-weight: 500;
            color: var(--text-light);
            cursor: pointer;
            position: relative;
            transition: 0.3s;
            white-space: nowrap;
            font-family: 'Poppins', sans-serif;
        }

        .tab-btn:hover { color: var(--primary); }
        .tab-btn.active {
            color: var(--primary);
            font-weight: 600;
        }

        .tab-btn.active::after {
            content: '';
            position: absolute;
            bottom: -1px;
            left: 0;
            width: 100%;
            height: 3px;
            background: var(--primary);
            border-radius: 3px 3px 0 0;
            box-shadow: 0 -2px 5px rgba(79, 140, 255, 0.3);
        }

        .main-content {
            padding: 40px;
            flex-grow: 1;
        }

        .tab-panel { display: none; width: 100%; animation: slideUpFade 0.4s ease-out forwards; }
        .tab-panel.active { display: block; }

        @keyframes slideUpFade {
            from { opacity: 0; transform: translateY(15px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .section-header { margin-bottom: 35px; }
        .section-header h1 { font-size: 24px; font-weight: 700; color: var(--text-main); letter-spacing: 0.5px; }
        .section-header p { color: var(--text-light); font-size: 14px; margin-top: 5px; }

        .card-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }

        .form-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            padding: 30px;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.03);
            transition: transform 0.3s ease;
        }

        .form-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.06);
        }

        .form-group { margin-bottom: 22px; }

        .form-group label {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-main);
            margin-bottom: 8px;
        }

        .form-group label i { color: var(--primary); font-size: 16px; }

        .input-field {
            width: 100%;
            padding: 12px 16px;
            border: 1.5px solid var(--border);
            border-radius: 10px;
            font-size: 14px;
            background: #f8fafc;
            color: var(--text-main);
            outline: none;
            transition: all 0.3s ease;
            font-family: 'Poppins', sans-serif;
        }

        .input-field:focus {
            border-color: var(--primary);
            background: #ffffff;
            box-shadow: 0 0 0 4px var(--primary-light);
        }

        .password-input-wrap {
            position: relative;
        }

        .password-input-wrap .input-field {
            padding-right: 44px;
        }

        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            border: none;
            background: transparent;
            color: var(--text-light);
            cursor: pointer;
            font-size: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0;
            line-height: 1;
        }

        .password-toggle:hover {
            color: var(--primary);
        }

        .btn-primary {
            background: var(--primary);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary:hover {
            background: var(--primary-hover);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(79, 140, 255, 0.3);
        }

        .table-container {
            width: 100%;
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0,0,0,0.03);
        }

        table { width: 100%; border-collapse: collapse; }

        th {
            text-align: left;
            padding: 16px 25px;
            background: #f8fafc;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-light);
            text-transform: uppercase;
            border-bottom: 1px solid var(--border);
            letter-spacing: 0.5px;
        }

        td {
            padding: 18px 25px;
            font-size: 14px;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
            color: var(--text-main);
        }

        tbody tr { transition: background 0.2s; }
        tbody tr:hover { background: #fcfdfd; }

        .keyword-chip {
            background: var(--primary-light);
            color: var(--primary);
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12.5px;
            font-weight: 500;
            margin-right: 6px;
            display: inline-block;
            margin-bottom: 4px;
        }

        .preview-box { display: flex; align-items: center; gap: 15px; margin-top: 12px; }
        .badge { padding: 6px 14px; border-radius: 20px; color: #fff; font-size: 12px; font-weight: 500; }

        .btn-delete {
            color: var(--text-light);
            background: var(--primary-light);
            border: none;
            width: 35px;
            height: 35px;
            border-radius: 8px;
            font-size: 18px;
            cursor: pointer;
            transition: 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .btn-delete:hover {
            color: white;
            background: var(--danger);
            transform: scale(1.05);
        }

        .profile-upload-area {
            display: flex;
            align-items: center;
            gap: 25px;
            margin-bottom: 25px;
        }

        .large-profile-img {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--primary-light);
            padding: 2px;
        }

        .inline-form {
            display: inline;
        }

        .flash {
            margin-bottom: 18px;
            padding: 12px 14px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 500;
        }

        .flash.success {
            background: #e8f9f0;
            border: 1px solid #b7ebce;
            color: #1f7a45;
        }

        .flash.error {
            background: #fff1f1;
            border: 1px solid #ffd1d1;
            color: #b42318;
        }

        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.45);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            padding: 20px;
        }

        .modal-card {
            width: 100%;
            max-width: 520px;
            background: #fff;
            border-radius: 12px;
            border: 1px solid var(--border);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.18);
            padding: 20px;
        }

        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
        }

        .modal-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-main);
        }

        .modal-close {
            background: transparent;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--text-light);
            line-height: 1;
        }

        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 10px;
        }

        .btn-secondary {
            border: 1px solid var(--border);
            background: #fff;
            color: var(--text-main);
            border-radius: 8px;
            padding: 10px 16px;
            font-size: 14px;
            cursor: pointer;
        }
    </style>
</head>
<body>

    <?php include 'admin_topbar.php'; ?>
    <?php include 'admin_sidebar.php'; ?>

    <div class="page-wrapper">

        <div class="nav-header">
            <div class="nav-tabs">
                <button class="tab-btn <?php echo $activeTab === 'chatbot' ? 'active' : ''; ?>" onclick="switchTab(event, 'chatbot')">VOICE Assistant Management</button>
                <button class="tab-btn <?php echo $activeTab === 'categories' ? 'active' : ''; ?>" onclick="switchTab(event, 'categories')">Categories & Suggestions</button>
                <button class="tab-btn <?php echo $activeTab === 'profile' ? 'active' : ''; ?>" onclick="switchTab(event, 'profile')">My Profile</button>
                <button class="tab-btn <?php echo $activeTab === 'logs' ? 'active' : ''; ?>" onclick="switchTab(event, 'logs')">Activity Logs</button>
            </div>
        </div>

        <main class="main-content">
            <?php if ($flashMessage !== ''): ?>
                <div class="flash <?php echo $flashStatus === 'success' ? 'success' : 'error'; ?>">
                    <?php echo e($flashMessage); ?>
                </div>
            <?php endif; ?>

            <div id="chatbot" class="tab-panel <?php echo $activeTab === 'chatbot' ? 'active' : ''; ?>">
                <div class="section-header">
                    <h1>VOICE Assistant Intelligence</h1>
                    <p>Configure how the automated assistant handles student inquiries.</p>
                </div>
                <div class="card-grid">
                    <div class="form-card">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="action" value="update_chatbot_identity">
                            <div class="form-group">
                                <label><i class='bx bx-bot'></i> VOICE Assistant Display Name</label>
                                <input type="text" class="input-field" name="bot_name" value="<?php echo e($chatbot['bot_name']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label><i class='bx bx-message-rounded-dots'></i> Welcome Message</label>
                                <textarea class="input-field" name="welcome_message" rows="3" required><?php echo e($chatbot['welcome_message']); ?></textarea>
                            </div>
                            <button class="btn-primary" type="submit">Update Profile</button>
                        </form>
                    </div>
                    <div class="form-card">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="action" value="update_chatbot_runtime">
                            <div class="form-group">
                                <label><i class='bx bx-error'></i> Default Response (No Match)</label>
                                <textarea class="input-field" name="fallback_message" rows="3" required><?php echo e($chatbot['fallback_message']); ?></textarea>
                            </div>
                            <div class="form-group">
                                <label><i class='bx bx-power-off'></i> Operational Status</label>
                                <select class="input-field" name="chatbot_status">
                                    <option value="1" <?php echo (int)$chatbot['is_active'] === 1 ? 'selected' : ''; ?>>Active / Online</option>
                                    <option value="0" <?php echo (int)$chatbot['is_active'] === 0 ? 'selected' : ''; ?>>Offline / Maintenance</option>
                                </select>
                            </div>
                            <button class="btn-primary" type="submit">Save Settings</button>
                        </form>
                    </div>
                </div>

                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th width="350">Keywords</th>
                                <th>Automated Response</th>
                                <th width="100">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($triggers) === 0): ?>
                                <tr>
                                    <td colspan="3">No VOICE Assistant triggers yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($triggers as $trigger): ?>
                                    <tr>
                                        <td>
                                            <?php
                                                $chips = preg_split('/\s*,\s*/', (string)$trigger['keywords']);
                                                foreach ($chips as $chip):
                                                    $chip = trim($chip);
                                                    if ($chip === '') {
                                                        continue;
                                                    }
                                            ?>
                                                <span class="keyword-chip"><?php echo e($chip); ?></span>
                                            <?php endforeach; ?>
                                        </td>
                                        <td><?php echo e((string)$trigger['response']); ?></td>
                                        <td>
                                            <form method="POST" class="inline-form" onsubmit="return confirm('Are you sure you want to delete this trigger?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">
                                                <input type="hidden" name="action" value="delete_trigger">
                                                <input type="hidden" name="trigger_id" value="<?php echo (int)$trigger['id']; ?>">
                                                <button class="btn-delete" type="submit" title="Delete"><i class='bx bx-trash'></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <div style="padding: 20px; text-align: right; background: var(--card-bg); border-top: 1px solid var(--border);">
                        <button class="btn-primary" style="background: var(--success);" onclick="openTriggerModal()" type="button">
                            <i class='bx bx-plus-circle'></i> Add New Trigger
                        </button>
                    </div>
                </div>

                <div class="modal-overlay" id="triggerModal" onclick="if (event.target.id === 'triggerModal') closeTriggerModal();">
                    <div class="modal-card">
                        <div class="modal-header">
                            <h3 class="modal-title">Add New Trigger</h3>
                            <button type="button" class="modal-close" onclick="closeTriggerModal()">&times;</button>
                        </div>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="action" value="add_trigger">
                            <div class="form-group">
                                <label><i class='bx bx-purchase-tag'></i> Keywords (comma separated)</label>
                                <input type="text" class="input-field" name="keywords" id="modalKeywords" placeholder="e.g. tuition, fees, payment" required>
                            </div>
                            <div class="form-group">
                                <label><i class='bx bx-message-rounded-detail'></i> Automated Response</label>
                                <textarea class="input-field" name="response" id="modalResponse" rows="4" placeholder="Type the response users will receive" required></textarea>
                            </div>
                            <div class="modal-actions">
                                <button type="button" class="btn-secondary" onclick="closeTriggerModal()">Cancel</button>
                                <button type="submit" class="btn-primary" style="background: var(--success);">
                                    <i class='bx bx-save'></i> Save Trigger
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div id="categories" class="tab-panel <?php echo $activeTab === 'categories' ? 'active' : ''; ?>">
                <div class="section-header">
                    <h1>System Classifications</h1>
                    <p>Organize how complaints and suggestions are grouped across the platform.</p>
                </div>

                <div class="card-grid">
                    <div class="form-card">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="action" value="save_category">
                            <input type="hidden" name="category_scope" value="complaint">
                            <input type="hidden" name="category_id" value="<?php echo ($editCategory !== null && $editCategory['scope'] === 'complaint') ? (int)$editCategory['id'] : 0; ?>">
                            <div class="form-group">
                                <label><i class='bx bx-error-circle'></i> <?php echo e(category_label_for_scope('complaint')); ?></label>
                                <p style="font-size: 12.5px; color: var(--text-light); margin-bottom: 12px;">Create or update complaint categories here. Route decides whether the category goes to Admin or Dean.</p>
                                <?php if ($editCategory !== null && $editCategory['scope'] === 'complaint'): ?>
                                    <div class="flash success" style="margin-bottom: 14px;">Editing complaint category <?php echo e((string)$editCategory['name']); ?>.</div>
                                <?php endif; ?>
                                <div class="form-group" style="margin-bottom: 14px;">
                                    <label><i class='bx bx-tag'></i> Category Name</label>
                                    <input type="text" class="input-field" name="category_name" value="<?php echo e($issueFormName); ?>" placeholder="e.g. Facilities" required>
                                </div>
                                <div class="form-group" style="margin-bottom: 14px;">
                                    <label><i class='bx bx-transfer-alt'></i> Category Type</label>
                                    <select class="input-field" name="category_type" required>
                                        <option value="general" <?php echo $issueFormType === 'general' ? 'selected' : ''; ?>>GENERAL - routes to Admin</option>
                                        <option value="college" <?php echo $issueFormType === 'college' ? 'selected' : ''; ?>>COLLEGE - routes to Dean</option>
                                    </select>
                                </div>
                                <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-bottom: 12px;">
                                    <button class="btn-primary" type="submit"><i class='bx bx-save'></i> <?php echo $issueFormName !== '' ? 'Update Category' : 'Add Category'; ?></button>
                                    <?php if ($editCategory !== null && $editCategory['scope'] === 'complaint'): ?>
                                        <a class="btn-secondary" href="admin_setting.php?tab=categories">Cancel Edit</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </form>

                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Category Name</th>
                                        <th>Type</th>
                                        <th width="180">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($issueCategories) === 0): ?>
                                        <tr>
                                            <td colspan="3">No complaint categories yet.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($issueCategories as $category): ?>
                                            <tr>
                                                <td><?php echo e((string)$category['name']); ?></td>
                                                <td>
                                                    <span class="badge" style="background: <?php echo $category['type'] === 'college' ? '#7c3aed' : 'var(--primary)'; ?>;">
                                                        <?php echo e(strtoupper((string)$category['type'])); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                                                        <a class="btn-secondary" href="admin_setting.php?tab=categories&edit_scope=complaint&edit_id=<?php echo (int)$category['id']; ?>">Edit</a>
                                                        <form method="POST" class="inline-form" onsubmit="return confirm('Delete this complaint category?');">
                                                            <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">
                                                            <input type="hidden" name="action" value="delete_category">
                                                            <input type="hidden" name="category_scope" value="complaint">
                                                            <input type="hidden" name="category_id" value="<?php echo (int)$category['id']; ?>">
                                                            <button class="btn-delete" type="submit" title="Delete"><i class='bx bx-trash'></i></button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="form-card">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="action" value="save_category">
                            <input type="hidden" name="category_scope" value="suggestion">
                            <input type="hidden" name="category_id" value="<?php echo ($editCategory !== null && $editCategory['scope'] === 'suggestion') ? (int)$editCategory['id'] : 0; ?>">
                            <div class="form-group">
                                <label><i class='bx bx-bulb'></i> <?php echo e(category_label_for_scope('suggestion')); ?></label>
                                <p style="font-size: 12.5px; color: var(--text-light); margin-bottom: 12px;">Create or update suggestion categories here. Route decides whether the category goes to Admin or Dean.</p>
                                <?php if ($editCategory !== null && $editCategory['scope'] === 'suggestion'): ?>
                                    <div class="flash success" style="margin-bottom: 14px;">Editing suggestion category <?php echo e((string)$editCategory['name']); ?>.</div>
                                <?php endif; ?>
                                <div class="form-group" style="margin-bottom: 14px;">
                                    <label><i class='bx bx-tag'></i> Category Name</label>
                                    <input type="text" class="input-field" name="category_name" value="<?php echo e($suggestionFormName); ?>" placeholder="e.g. Library Resources" required>
                                </div>
                                <div class="form-group" style="margin-bottom: 14px;">
                                    <label><i class='bx bx-transfer-alt'></i> Category Type</label>
                                    <select class="input-field" name="category_type" required>
                                        <option value="general" <?php echo $suggestionFormType === 'general' ? 'selected' : ''; ?>>GENERAL - routes to Admin</option>
                                        <option value="college" <?php echo $suggestionFormType === 'college' ? 'selected' : ''; ?>>COLLEGE - routes to Dean</option>
                                    </select>
                                </div>
                                <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-bottom: 12px;">
                                    <button class="btn-primary" type="submit"><i class='bx bx-save'></i> <?php echo $suggestionFormName !== '' ? 'Update Category' : 'Add Category'; ?></button>
                                    <?php if ($editCategory !== null && $editCategory['scope'] === 'suggestion'): ?>
                                        <a class="btn-secondary" href="admin_setting.php?tab=categories">Cancel Edit</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </form>

                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Category Name</th>
                                        <th>Type</th>
                                        <th width="180">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($suggestionCategories) === 0): ?>
                                        <tr>
                                            <td colspan="3">No suggestion categories yet.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($suggestionCategories as $category): ?>
                                            <tr>
                                                <td><?php echo e((string)$category['name']); ?></td>
                                                <td>
                                                    <span class="badge" style="background: <?php echo $category['type'] === 'college' ? '#7c3aed' : 'var(--primary)'; ?>;">
                                                        <?php echo e(strtoupper((string)$category['type'])); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                                                        <a class="btn-secondary" href="admin_setting.php?tab=categories&edit_scope=suggestion&edit_id=<?php echo (int)$category['id']; ?>">Edit</a>
                                                        <form method="POST" class="inline-form" onsubmit="return confirm('Delete this suggestion category?');">
                                                            <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">
                                                            <input type="hidden" name="action" value="delete_category">
                                                            <input type="hidden" name="category_scope" value="suggestion">
                                                            <input type="hidden" name="category_id" value="<?php echo (int)$category['id']; ?>">
                                                            <button class="btn-delete" type="submit" title="Delete"><i class='bx bx-trash'></i></button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div id="profile" class="tab-panel <?php echo $activeTab === 'profile' ? 'active' : ''; ?>">
                <div class="section-header">
                    <h1>Account Settings</h1>
                    <p>Update your personal information and profile security.</p>
                </div>
                <div class="card-grid">
                    <div class="form-card">
                        <form method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="action" value="update_profile">
                            <div class="form-group">
                                <label>Profile Picture</label>
                                <div class="profile-upload-area">
                                    <img id="edit-profile-preview" src="<?php echo e($profileImage); ?>" class="large-profile-img" alt="Admin Profile">
                                    <div>
                                        <input type="file" name="imageUpload" id="imageUpload" accept="image/*" style="display: none;" onchange="previewImage(this)">
                                        <button class="btn-primary" style="background: #e2e8f0; color: #475569; padding: 8px 16px; font-size: 13px;" onclick="document.getElementById('imageUpload').click()" type="button">
                                            <i class='bx bx-image-add'></i> Change Photo
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="form-group">
                                <label><i class='bx bx-user'></i> Full Name</label>
                                <input type="text" id="adminNameInput" name="admin_name" class="input-field" value="<?php echo e($profile['name']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label><i class='bx bx-envelope'></i> Email Address</label>
                                <input type="email" name="admin_email" class="input-field" value="<?php echo e($profile['email']); ?>" required>
                            </div>
                            <button class="btn-primary" type="submit"><i class='bx bx-save'></i> Save Changes</button>
                        </form>
                    </div>

                    <div class="form-card">
                        <h2 style="font-size: 16px; margin-bottom: 20px; display: flex; align-items: center; gap: 8px;">
                            <i class='bx bx-shield-quarter' style="color: var(--primary); font-size: 20px;"></i> Security
                        </h2>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="action" value="update_password">
                            <div class="form-group">
                                <label>Current Password</label>
                                <div class="password-input-wrap">
                                    <input type="password" name="current_password" class="input-field" placeholder="••••••••" required>
                                    <button type="button" class="password-toggle" onclick="togglePasswordVisibility(this)" aria-label="Show password">
                                        <i class='bx bx-hide'></i>
                                    </button>
                                </div>
                            </div>
                            <div class="form-group">
                                <label>New Password</label>
                                <div class="password-input-wrap">
                                    <input type="password" name="new_password" class="input-field" placeholder="Enter new password" required>
                                    <button type="button" class="password-toggle" onclick="togglePasswordVisibility(this)" aria-label="Show password">
                                        <i class='bx bx-hide'></i>
                                    </button>
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Confirm New Password</label>
                                <div class="password-input-wrap">
                                    <input type="password" name="confirm_password" class="input-field" placeholder="Repeat new password" required>
                                    <button type="button" class="password-toggle" onclick="togglePasswordVisibility(this)" aria-label="Show password">
                                        <i class='bx bx-hide'></i>
                                    </button>
                                </div>
                            </div>
                            <button class="btn-primary" type="submit"><i class='bx bx-lock-alt'></i> Update Password</button>
                        </form>
                    </div>
                </div>
            </div>

            <div id="logs" class="tab-panel <?php echo $activeTab === 'logs' ? 'active' : ''; ?>">
                <div class="section-header">
                    <h1>Activity Logs</h1>
                    <p>History of administrative actions.</p>
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Action Performed</th>
                                <th>Admin User</th>
                                <th>Timestamp</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($logs) === 0): ?>
                                <tr>
                                    <td colspan="3">No activity logs yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($logs as $log): ?>
                                    <tr>
                                        <td><strong><?php echo e((string)$log['action']); ?></strong></td>
                                        <td><?php echo e((string)$log['admin_name']); ?></td>
                                        <td style="color: var(--text-light);"><?php echo e(date('M d, Y - H:i', strtotime((string)$log['created_at']))); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </main>
    </div>

    <script>
        function switchTab(evt, tabId) {
            const panels = document.querySelectorAll('.tab-panel');
            const buttons = document.querySelectorAll('.tab-btn');

            panels.forEach(p => p.classList.remove('active'));
            buttons.forEach(b => b.classList.remove('active'));

            document.getElementById(tabId).classList.add('active');
            evt.currentTarget.classList.add('active');

            window.history.replaceState({}, '', 'admin_setting.php?tab=' + encodeURIComponent(tabId));
        }

        function openTriggerModal() {
            document.getElementById('modalKeywords').value = '';
            document.getElementById('modalResponse').value = '';
            document.getElementById('triggerModal').style.display = 'flex';
            document.getElementById('modalKeywords').focus();
        }

        function closeTriggerModal() {
            document.getElementById('triggerModal').style.display = 'none';
        }

        function previewImage(input) {
            if (input.files && input.files[0]) {
                const preview = document.getElementById('edit-profile-preview');
                if (!preview) {
                    return;
                }

                const topbarPreview = document.querySelector('.profile-container .profile-pic');

                if (preview.dataset.objectUrl) {
                    URL.revokeObjectURL(preview.dataset.objectUrl);
                }

                if (topbarPreview && topbarPreview.dataset.objectUrl) {
                    URL.revokeObjectURL(topbarPreview.dataset.objectUrl);
                }

                const objectUrl = URL.createObjectURL(input.files[0]);
                preview.src = objectUrl;
                preview.dataset.objectUrl = objectUrl;
                preview.setAttribute('alt', input.files[0].name);

                if (topbarPreview) {
                    topbarPreview.src = objectUrl;
                    topbarPreview.dataset.objectUrl = objectUrl;
                    topbarPreview.setAttribute('alt', input.files[0].name);
                }
            }
        }

        function togglePasswordVisibility(toggleButton) {
            const wrapper = toggleButton.closest('.password-input-wrap');
            if (!wrapper) {
                return;
            }

            const input = wrapper.querySelector('input');
            const icon = toggleButton.querySelector('i');
            if (!input || !icon) {
                return;
            }

            const isPassword = input.type === 'password';
            input.type = isPassword ? 'text' : 'password';
            icon.className = isPassword ? 'bx bx-show' : 'bx bx-hide';
            toggleButton.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
        }
    </script>

</body>
</html>
