<?php
session_start();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../ticket_flow.php';
require_once __DIR__ . '/../student_bulk_upload.php';
require_once __DIR__ . '/../faculty_helpers.php';
require_once __DIR__ . '/../school_year_helpers.php';

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

$activeTab = (string)($_GET['tab'] ?? 'faq');
$allowedTabs = ['faq', 'types', 'bulk_upload', 'school_year', 'profile', 'logs'];
if (!in_array($activeTab, $allowedTabs, true)) {
    $activeTab = 'faq';
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

// Downloadable CSV template for the Bulk Upload section. Sent before any page
// output, so it must stay above the HTML below.
$downloadTemplate = (string)($_GET['download'] ?? '');
if ($downloadTemplate === 'student_template' || $downloadTemplate === 'faculty_template') {
    $isFacultyTemplate = $downloadTemplate === 'faculty_template';
    $csv = $isFacultyTemplate ? faculty_bulk_upload_template_csv() : student_bulk_upload_template_csv();
    $filename = $isFacultyTemplate ? 'faculty_bulk_upload_template.csv' : 'student_bulk_upload_template.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($csv));
    echo $csv;
    exit;
}

// Per-row results of the last bulk upload are too long for the flash query
// string, so they ride in the session across the redirect instead.
$bulkUploadNotes = [];
if (!empty($_SESSION['bulk_upload_notes']) && is_array($_SESSION['bulk_upload_notes'])) {
    $bulkUploadNotes = $_SESSION['bulk_upload_notes'];
    unset($_SESSION['bulk_upload_notes']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    $token = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        redirect_settings($activeTab, 'error', 'Invalid request token. Please refresh and try again.');
    }

    try {
        if ($action === 'bulk_upload_faculty') {
            $result = faculty_bulk_upload_import($pdo, $_FILES['faculty_csv'] ?? []);
            $_SESSION['bulk_upload_notes'] = $result['notes'];

            if ($result['imported'] > 0) {
                log_admin_activity(
                    $pdo,
                    $adminUserId,
                    'Bulk uploaded ' . $result['imported'] . ' faculty/staff record(s)',
                    'faculty'
                );
            }

            redirect_settings('bulk_upload', $result['ok'] ? 'success' : 'error', $result['message']);
        }

        if ($action === 'bulk_upload_students') {
            $result = student_bulk_upload_import($pdo, $_FILES['students_csv'] ?? []);
            $_SESSION['bulk_upload_notes'] = $result['notes'];

            if ($result['imported'] > 0) {
                log_admin_activity(
                    $pdo,
                    $adminUserId,
                    'Bulk uploaded ' . $result['imported'] . ' student record(s)',
                    'student'
                );
            }

            redirect_settings('bulk_upload', $result['ok'] ? 'success' : 'error', $result['message']);
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

        if ($action === 'set_school_year_override') {
            $overrideValue = trim((string)($_POST['school_year_override'] ?? ''));

            if (!sy_is_valid_label($overrideValue)) {
                redirect_settings('school_year', 'error', 'Enter a school year like 2026-2027.');
            }

            sy_set_override($pdo, $overrideValue);
            log_admin_activity($pdo, $adminUserId, 'Set school year override to ' . $overrideValue, 'system_settings');
            redirect_settings('school_year', 'success', 'The app now treats ' . $overrideValue . ' as the current school year.');
        }

        if ($action === 'clear_school_year_override') {
            sy_clear_override($pdo);
            log_admin_activity($pdo, $adminUserId, 'Cleared school year override', 'system_settings');
            redirect_settings('school_year', 'success', 'Override removed - the school year is calculated automatically again.');
        }

        if ($action === 'save_academic_calendar') {
            $calendarInput = [
                'school_year' => trim((string)($_POST['school_year'] ?? '')),
                'sy_start_date' => trim((string)($_POST['sy_start_date'] ?? '')),
                'sy_end_date' => trim((string)($_POST['sy_end_date'] ?? '')),
                'sem1_start_date' => trim((string)($_POST['sem1_start_date'] ?? '')),
                'sem1_end_date' => trim((string)($_POST['sem1_end_date'] ?? '')),
                'sem2_start_date' => trim((string)($_POST['sem2_start_date'] ?? '')),
                'sem2_end_date' => trim((string)($_POST['sem2_end_date'] ?? '')),
            ];

            $result = sy_save_academic_calendar($pdo, $calendarInput);
            if (!$result['ok']) {
                redirect_settings('school_year', 'error', $result['error']);
            }

            log_admin_activity($pdo, $adminUserId, 'Saved academic calendar for ' . $calendarInput['school_year'], 'academic_calendars');
            redirect_settings('school_year', 'success', 'Academic calendar for ' . $calendarInput['school_year'] . ' saved.');
        }

        if ($action === 'delete_academic_calendar') {
            $calendarId = (int)($_POST['calendar_id'] ?? 0);
            if ($calendarId <= 0) {
                redirect_settings('school_year', 'error', 'Invalid academic calendar.');
            }

            sy_delete_academic_calendar($pdo, $calendarId);
            log_admin_activity($pdo, $adminUserId, 'Deleted academic calendar #' . $calendarId, 'academic_calendars', $calendarId);
            redirect_settings('school_year', 'success', 'Academic calendar removed.');
        }

        redirect_settings($activeTab, 'error', 'Unknown action requested.');
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        redirect_settings($activeTab, 'error', 'Database error: ' . $e->getMessage());
    }
}

$profile = [
    'name' => 'Super Admin',
    'email' => 'admin@voice-system.edu',
    'profile_pic' => '',
];
$logs = [];

try {
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

$profileImage = trim($profile['profile_pic']) !== '' ? '../' . ltrim($profile['profile_pic'], '/') : '../assets/images/default-avatar.svg';
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
        .back-to-top { position: fixed; right: 24px; bottom: 24px; width: 44px; height: 44px; border-radius: 50%; border: 0; background: #6d28d9; color: #fff; font-size: 20px; display: flex; align-items: center; justify-content: center; cursor: pointer; box-shadow: 0 6px 16px rgba(109,40,217,.35); z-index: 500; opacity: 0; visibility: hidden; transform: translateY(8px); transition: opacity .2s, transform .2s, visibility .2s; }
        .back-to-top.visible { opacity: 1; visibility: visible; transform: translateY(0); }
        .back-to-top:hover { background: #5b21b6; }
        .settings-tab-iframe {
            display: block;
            width: 100%;
            height: calc(100vh - 150px);
            min-height: 720px;
            border: 0;
            border-radius: 10px;
            background: #f8fafc;
        }
        /* On a phone-width screen, a 720px minimum is often taller than the
           entire screen - the outer page then has to scroll just to reveal
           the rest of a mostly-empty iframe box, instead of the tab's own
           content scrolling naturally inside it. Let the iframe size itself
           to what's actually visible there instead. */
        @media (max-width: 768px) {
            .settings-tab-iframe {
                height: calc(100vh - 190px);
                min-height: 50vh;
            }
        }

        /* This page uses its own .page-wrapper instead of the shared .main
           class the sidebar's own mobile rule resets - without this, the
           sidebar's off-canvas fix never applied here and the entire page
           stayed shoved 260px to the right on every phone. */
        @media (max-width: 1024px) {
            .page-wrapper { margin-left: 0 !important; }
        }

        @media (max-width: 768px) {
            .nav-header { padding: 0 16px; gap: 24px; }
            .nav-tabs { gap: 24px; }
        }

        @media (max-width: 640px) {
            .main-content { padding: 20px 16px !important; }
            .section-header h1 { font-size: 20px; }

            /* auto-fit with a 400px floor forces a column wider than most
               phone screens, so the grid itself overflows sideways - drop
               the floor and let cards take the full width instead. */
            .card-grid { grid-template-columns: 1fr; gap: 16px; }
            .form-card { padding: 20px; }

            /* Other tables here (Skipped Rows, CSV format, Activity Logs)
               can't squeeze into a phone width without wrapping into
               unreadable ragged text - scroll sideways within the card
               instead of clipping content that doesn't fit. */
            .table-container { overflow: auto; }
            table { min-width: 640px; }

            /* The Academic Calendar table's Edit/Delete buttons sat off to
               the right of a horizontally-scrolling table, so reaching them
               meant swiping sideways first - stack each row into its own
               card instead, with the actions always visible up front. */
            .academic-calendar-table { min-width: 0; }
            .academic-calendar-table thead { display: none; }
            .academic-calendar-table, .academic-calendar-table tbody,
            .academic-calendar-table tr, .academic-calendar-table td { display: block; width: 100%; }
            .academic-calendar-table tbody tr {
                border: 1px solid #eef0f3;
                border-radius: 10px;
                padding: 12px 14px;
                margin-bottom: 12px;
            }
            .academic-calendar-table tbody tr:last-child { margin-bottom: 0; }
            .academic-calendar-table td { padding: 4px 0; border-bottom: none; }
            .academic-calendar-table td[data-label]::before {
                content: attr(data-label);
                display: block;
                font-size: 11px;
                font-weight: 700;
                color: #9ca3af;
                text-transform: uppercase;
                letter-spacing: .03em;
                margin-bottom: 2px;
            }
            .academic-calendar-table td.td-ac-actions { padding-top: 10px; white-space: normal !important; }

            /* The Academic Calendar form's date fields (flex-basis 140px)
               only fit two per row on a phone, wrapping into a lopsided
               grid that's fiddly to tap accurately - one full-width field
               per row is far easier to use here. */
            #academicCalendarForm .form-group { flex: 1 1 100% !important; }
            #academicCalendarForm > div { gap: 12px !important; }
        }
    </style>
</head>
<body>

    <?php include 'admin_topbar.php'; ?>
    <?php include 'admin_sidebar.php'; ?>

    <div class="page-wrapper">

        <div class="nav-header">
            <div class="nav-tabs">
                <button class="tab-btn <?php echo $activeTab === 'faq' ? 'active' : ''; ?>" onclick="switchTab(event, 'faq')">FAQ Management</button>
                <button class="tab-btn <?php echo $activeTab === 'types' ? 'active' : ''; ?>" onclick="switchTab(event, 'types')">Types</button>
                <button class="tab-btn <?php echo $activeTab === 'bulk_upload' ? 'active' : ''; ?>" onclick="switchTab(event, 'bulk_upload')">Bulk Upload</button>
                <button class="tab-btn <?php echo $activeTab === 'school_year' ? 'active' : ''; ?>" onclick="switchTab(event, 'school_year')">School Year</button>
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

            <div id="faq" class="tab-panel <?php echo $activeTab === 'faq' ? 'active' : ''; ?>">
                <iframe class="settings-tab-iframe" src="admin_faq.php?embedded=1" title="FAQ Management"></iframe>
            </div>

            <div id="types" class="tab-panel <?php echo $activeTab === 'types' ? 'active' : ''; ?>">
                <iframe class="settings-tab-iframe" src="admin_types.php?embedded=1" title="Complaint and Suggestion Types"></iframe>
            </div>

            <div id="bulk_upload" class="tab-panel <?php echo $activeTab === 'bulk_upload' ? 'active' : ''; ?>">
                <div class="section-header">
                    <h1>Bulk Upload</h1>
                    <p>Add a whole intake of new enrollees, or your faculty and staff roster, from a CSV file instead of one at a time.</p>
                </div>

                <div class="card-grid">
                    <div class="form-card">
                        <form method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="action" value="bulk_upload_students">
                            <div class="form-group">
                                <label><i class='bx bx-upload'></i> Upload Student CSV</label>
                                <p style="font-size: 12.5px; color: var(--text-light); margin-bottom: 12px;">
                                    Each row creates a student account and profile. Rows that are invalid or already
                                    exist are skipped and listed below, so the rest of the file still imports.
                                </p>
                                <div class="form-group" style="margin-bottom: 14px;">
                                    <input type="file" class="input-field" name="students_csv" accept=".csv,.txt" required>
                                </div>
                                <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-bottom: 12px;">
                                    <button class="btn-primary" type="submit"><i class='bx bx-cloud-upload'></i> Upload Students</button>
                                    <a class="btn-secondary" href="admin_setting.php?download=student_template"><i class='bx bx-download'></i> Download CSV Template</a>
                                </div>
                            </div>
                        </form>

                        <?php if ($bulkUploadNotes !== []): ?>
                            <div class="form-group">
                                <label><i class='bx bx-list-ul'></i> Skipped Rows (<?php echo count($bulkUploadNotes); ?>)</label>
                                <div class="table-container">
                                    <table>
                                        <thead>
                                            <tr><th>Details</th></tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($bulkUploadNotes as $note): ?>
                                                <tr><td><?php echo e((string)$note); ?></td></tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="form-card">
                        <form method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="action" value="bulk_upload_faculty">
                            <div class="form-group">
                                <label><i class='bx bx-id-card'></i> Upload Faculty/Staff CSV</label>
                                <p style="font-size: 12.5px; color: var(--text-light); margin-bottom: 12px;">
                                    Faculty and staff can be reported in a complaint, which is then routed to the
                                    SAS Director. They have no login accounts &mdash; only <strong>first_name</strong>
                                    and <strong>last_name</strong> are required.
                                </p>
                                <div class="form-group" style="margin-bottom: 14px;">
                                    <input type="file" class="input-field" name="faculty_csv" accept=".csv,.txt" required>
                                </div>
                                <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-bottom: 12px;">
                                    <button class="btn-primary" type="submit"><i class='bx bx-cloud-upload'></i> Upload Faculty/Staff</button>
                                    <a class="btn-secondary" href="admin_setting.php?download=faculty_template"><i class='bx bx-download'></i> Download CSV Template</a>
                                </div>
                                <p style="font-size: 12.5px; color: var(--text-light);">
                                    Optional columns: <?php echo e(implode(', ', array_keys(faculty_bulk_upload_columns()['optional']))); ?>.
                                    Add an <strong>email</strong> so the SAS Director can send them a Call Slip.
                                </p>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div id="school_year" class="tab-panel <?php echo $activeTab === 'school_year' ? 'active' : ''; ?>">
                <?php
                    $sySettingAutomatic = sy_label_for_date(date('Y-m-d'), $pdo);
                    $sySettingOverride = sy_get_override($pdo);
                    $sySettingEffective = $sySettingOverride !== '' ? $sySettingOverride : $sySettingAutomatic;
                    $academicCalendars = sy_get_academic_calendars($pdo);
                    $currentAcademicCalendar = sy_find_academic_calendar_for_date($pdo, date('Y-m-d'));
                    $currentAcademicCalendarId = $currentAcademicCalendar['id'] ?? null;
                ?>
                <div class="section-header">
                    <h1>School Year</h1>
                </div>
                <div class="card-grid">
                    <div class="form-card">
                        <h2 style="font-size: 16px; margin-bottom: 6px; display: flex; align-items: center; gap: 8px;">
                            <i class='bx bx-calendar-check' style="color: var(--primary); font-size: 20px;"></i> Currently in effect
                        </h2>
                        <p style="font-size: 28px; font-weight: 700; color: #111827; margin: 10px 0 4px;"><?php echo e($sySettingEffective); ?></p>
                        <p style="font-size: 13px; color: #6b7280; margin-bottom: 0;">
                            <?php if ($sySettingOverride !== ''): ?>
                                Set manually by an admin. Without this override, today's date would calculate to <strong><?php echo e($sySettingAutomatic); ?></strong>.
                            <?php else: ?>
                                Calculated automatically from today's date using the Academic Calendar below.
                            <?php endif; ?>
                        </p>
                    </div>
                </div>

                <div class="section-header" style="margin-top: 28px;">
                    <h1 style="font-size: 20px;">Academic Calendar</h1>
                </div>
                <div class="card-grid">
                    <div class="form-card" style="grid-column: 1 / -1;">
                        <h2 style="font-size: 16px; margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
                            <i class='bx bx-calendar-week' style="color: var(--primary); font-size: 20px;"></i> Configured School Years
                        </h2>
                        <?php if ($academicCalendars === []): ?>
                            <p style="font-size: 13px; color: #6b7280;">No academic calendar configured yet. Add one using the form below.</p>
                        <?php else: ?>
                            <?php if ($currentAcademicCalendarId === null): ?>
                                <p style="font-size: 13px; color: #b91c1c; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 10px 14px; margin-bottom: 14px;">
                                    <i class='bx bx-error-circle'></i> No configured school year covers today's date - filing is currently disabled until one is added or adjusted below.
                                </p>
                            <?php endif; ?>
                            <?php $academicCalendarHistoryCount = ($currentAcademicCalendarId !== null ? count($academicCalendars) - 1 : count($academicCalendars)); ?>
                            <div class="table-container">
                                <table class="academic-calendar-table">
                                    <thead>
                                        <tr>
                                            <th>School Year</th>
                                            <th>SY Dates</th>
                                            <th>1st Sem</th>
                                            <th>2nd Sem</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($academicCalendars as $cal): ?>
                                            <?php $isCurrentCal = $currentAcademicCalendarId !== null && (int)$cal['id'] === (int)$currentAcademicCalendarId; ?>
                                            <tr<?php echo $isCurrentCal ? '' : ' class="ac-history-row" style="display: none;"'; ?>>
                                                <td data-label="School Year">
                                                    <strong><?php echo e((string)$cal['school_year']); ?></strong>
                                                    <?php if ($isCurrentCal): ?>
                                                        <span style="display: inline-block; margin-left: 6px; font-size: 11px; font-weight: 600; color: #166534; background: #dcfce7; padding: 2px 8px; border-radius: 999px;">Current</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td data-label="SY Dates"><?php echo e(date('M j, Y', strtotime((string)$cal['sy_start_date']))); ?> &ndash; <?php echo e(date('M j, Y', strtotime((string)$cal['sy_end_date']))); ?></td>
                                                <td data-label="1st Sem"><?php echo e(date('M j, Y', strtotime((string)$cal['sem1_start_date']))); ?> &ndash; <?php echo e(date('M j, Y', strtotime((string)$cal['sem1_end_date']))); ?></td>
                                                <td data-label="2nd Sem"><?php echo e(date('M j, Y', strtotime((string)$cal['sem2_start_date']))); ?> &ndash; <?php echo e(date('M j, Y', strtotime((string)$cal['sem2_end_date']))); ?></td>
                                                <td class="td-ac-actions" style="white-space: nowrap;">
                                                    <button type="button" class="btn-secondary" style="padding: 6px 10px; font-size: 12px;"
                                                        data-id="<?php echo (int)$cal['id']; ?>"
                                                        data-school-year="<?php echo e((string)$cal['school_year']); ?>"
                                                        data-sy-start="<?php echo e((string)$cal['sy_start_date']); ?>"
                                                        data-sy-end="<?php echo e((string)$cal['sy_end_date']); ?>"
                                                        data-sem1-start="<?php echo e((string)$cal['sem1_start_date']); ?>"
                                                        data-sem1-end="<?php echo e((string)$cal['sem1_end_date']); ?>"
                                                        data-sem2-start="<?php echo e((string)$cal['sem2_start_date']); ?>"
                                                        data-sem2-end="<?php echo e((string)$cal['sem2_end_date']); ?>"
                                                        onclick="editAcademicCalendar(this)" title="Edit">
                                                        <i class='bx bx-edit'></i>
                                                    </button>
                                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Remove the academic calendar for <?php echo e((string)$cal['school_year']); ?>? Existing submissions keep the school year and semester they were filed under.');">
                                                        <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">
                                                        <input type="hidden" name="action" value="delete_academic_calendar">
                                                        <input type="hidden" name="calendar_id" value="<?php echo (int)$cal['id']; ?>">
                                                        <button class="btn-secondary" style="padding: 6px 10px; font-size: 12px; background: #fee2e2; color: #b91c1c;" type="submit" title="Delete"><i class='bx bx-trash'></i></button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php if ($academicCalendarHistoryCount > 0): ?>
                                <button type="button" class="btn-secondary" id="acHistoryToggle" style="margin-top: 12px; padding: 6px 14px; font-size: 12px;" data-count="<?php echo $academicCalendarHistoryCount; ?>" onclick="toggleAcademicCalendarHistory()">
                                    <i class='bx bx-history'></i> <span id="acHistoryToggleLabel">Show other school years (<?php echo $academicCalendarHistoryCount; ?>)</span>
                                </button>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <div class="form-card" style="grid-column: 1 / -1;">
                        <h2 id="academic-calendar-form-title" style="font-size: 16px; margin-bottom: 20px; display: flex; align-items: center; gap: 8px;">
                            <i class='bx bx-calendar-plus' style="color: var(--primary); font-size: 20px;"></i> Add / Update School Year
                        </h2>
                        <form method="POST" id="academicCalendarForm">
                            <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="action" value="save_academic_calendar">
                            <div style="display: flex; gap: 14px; flex-wrap: wrap; align-items: flex-end;">
                                <div class="form-group" style="margin-bottom: 0; flex: 1 1 140px;">
                                    <label>School Year</label>
                                    <input type="text" name="school_year" id="ac_school_year" class="input-field" inputmode="numeric" maxlength="9" placeholder="e.g. 2027-2028" required oninput="formatSchoolYearInput(this, false, event)">
                                </div>
                                <div class="form-group" style="margin-bottom: 0; flex: 1 1 140px;">
                                    <label>SY Start Date</label>
                                    <input type="date" name="sy_start_date" id="ac_sy_start_date" class="input-field" required>
                                </div>
                                <div class="form-group" style="margin-bottom: 0; flex: 1 1 140px;">
                                    <label>SY End Date</label>
                                    <input type="date" name="sy_end_date" id="ac_sy_end_date" class="input-field" required>
                                </div>
                                <div class="form-group" style="margin-bottom: 0; flex: 1 1 140px;">
                                    <label>1st Sem Start</label>
                                    <input type="date" name="sem1_start_date" id="ac_sem1_start_date" class="input-field" required>
                                </div>
                                <div class="form-group" style="margin-bottom: 0; flex: 1 1 140px;">
                                    <label>1st Sem End</label>
                                    <input type="date" name="sem1_end_date" id="ac_sem1_end_date" class="input-field" required>
                                </div>
                                <div class="form-group" style="margin-bottom: 0; flex: 1 1 140px;">
                                    <label>2nd Sem Start</label>
                                    <input type="date" name="sem2_start_date" id="ac_sem2_start_date" class="input-field" required>
                                </div>
                                <div class="form-group" style="margin-bottom: 0; flex: 1 1 140px;">
                                    <label>2nd Sem End</label>
                                    <input type="date" name="sem2_end_date" id="ac_sem2_end_date" class="input-field" required>
                                </div>
                                <div style="display: flex; gap: 10px; flex: 0 0 auto;">
                                    <button class="btn-primary" type="submit"><i class='bx bx-save'></i> Save Calendar</button>
                                    <button class="btn-secondary" type="button" onclick="resetAcademicCalendarForm()">Clear</button>
                                </div>
                            </div>
                        </form>
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
            // Re-check whether the back-to-top button should show for the tab
            // just switched to (it hides itself on the FAQ/Types iframe tabs,
            // which otherwise have no scroll event of their own to react to).
            window.dispatchEvent(new Event('scroll'));
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

        function editAcademicCalendar(button) {
            const form = document.getElementById('academicCalendarForm');
            if (!form) {
                return;
            }

            document.getElementById('ac_school_year').value = button.dataset.schoolYear || '';
            document.getElementById('ac_sy_start_date').value = button.dataset.syStart || '';
            document.getElementById('ac_sy_end_date').value = button.dataset.syEnd || '';
            document.getElementById('ac_sem1_start_date').value = button.dataset.sem1Start || '';
            document.getElementById('ac_sem1_end_date').value = button.dataset.sem1End || '';
            document.getElementById('ac_sem2_start_date').value = button.dataset.sem2Start || '';
            document.getElementById('ac_sem2_end_date').value = button.dataset.sem2End || '';

            const formTitle = document.getElementById('academic-calendar-form-title');
            if (formTitle) {
                formTitle.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }

        function resetAcademicCalendarForm() {
            const form = document.getElementById('academicCalendarForm');
            if (form) {
                form.reset();
            }
        }

        function toggleAcademicCalendarHistory() {
            const rows = document.querySelectorAll('.ac-history-row');
            const btn = document.getElementById('acHistoryToggle');
            const label = document.getElementById('acHistoryToggleLabel');
            if (!rows.length || !btn || !label) {
                return;
            }

            const count = btn.dataset.count || rows.length;
            const isCurrentlyHidden = rows[0].style.display === 'none';
            rows.forEach(row => { row.style.display = isCurrentlyHidden ? '' : 'none'; });
            label.textContent = (isCurrentlyHidden ? 'Hide other school years (' : 'Show other school years (') + count + ')';
        }
    </script>
    <?php echo sy_smart_input_script(); ?>
    <button type="button" id="backToTopBtn" class="back-to-top" title="Back to top" aria-label="Back to top"><i class="bx bx-up-arrow-alt"></i></button>
    <script>
    (function () {
        const backToTop = document.getElementById('backToTopBtn');
        if (!backToTop) {
            return;
        }
        // The FAQ and Types tabs are iframes with their own internal scroll
        // and their own back-to-top button already - this outer one is for
        // the other tabs (Bulk Upload, School Year, etc.) whose content
        // lives directly on this page. Showing both at once on the same
        // tab would just stack two overlapping arrows in the same corner.
        const iframeTabIds = ['faq', 'types'];
        const isIframeTabActive = function () {
            const activePanel = document.querySelector('.tab-panel.active');
            return !!activePanel && iframeTabIds.indexOf(activePanel.id) !== -1;
        };
        const toggleBackToTop = function () {
            backToTop.classList.toggle('visible', !isIframeTabActive() && window.scrollY > 80);
        };
        window.addEventListener('scroll', toggleBackToTop, { passive: true });
        toggleBackToTop();
        backToTop.addEventListener('click', function () {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    })();
    </script>
</body>
</html>
