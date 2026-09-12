<?php
session_start();
require_once '../db_connection.php';

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function require_dean_session(PDO $pdo): void
{
    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    if ($userId <= 0) {
        header('Location: /complaint-system-main/dean/login.php');
        exit;
    }

    if (($_SESSION['role'] ?? '') === 'dean') {
        return;
    }

    $stmt = $pdo->prepare('SELECT role FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && ($user['role'] ?? '') === 'dean') {
        $_SESSION['role'] = 'dean';
        return;
    }

    header('Location: /complaint-system-main/dean/login.php');
    exit;
}

function get_upload_extension(string $mime): ?string
{
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    return $allowed[$mime] ?? null;
}

require_dean_session($pdo);

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$userId = (int)$_SESSION['user_id'];

try {
    $photoColumnCheck = $pdo->query("SHOW COLUMNS FROM dean_profiles LIKE 'profile_photo'");
    if (!$photoColumnCheck->fetch()) {
        $pdo->exec("ALTER TABLE dean_profiles ADD COLUMN profile_photo VARCHAR(255) NULL AFTER status");
    }
} catch (PDOException $e) {
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'update_profile') {
    $csrf = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals((string)$_SESSION['csrf_token'], $csrf)) {
        header('Location: dean_profile.php?status=error&msg=' . urlencode('Invalid request token. Please try again.'));
        exit;
    }

    $firstName = trim((string)($_POST['first_name'] ?? ''));
    $lastName = trim((string)($_POST['last_name'] ?? ''));

    if ($firstName === '' || $lastName === '') {
        header('Location: dean_profile.php?status=error&msg=' . urlencode('First name and last name are required.'));
        exit;
    }

    try {
        $existingStmt = $pdo->prepare('SELECT id, profile_photo FROM dean_profiles WHERE user_id = :user_id LIMIT 1');
        $existingStmt->execute([':user_id' => $userId]);
        $existingProfile = $existingStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!$existingProfile) {
            $createStmt = $pdo->prepare(
                'INSERT INTO dean_profiles (user_id, first_name, last_name, college_id, status)
                 VALUES (:user_id, :first_name, :last_name, NULL, :status)'
            );
            $createStmt->execute([
                ':user_id' => $userId,
                ':first_name' => $firstName,
                ':last_name' => $lastName,
                ':status' => 'active',
            ]);

            $existingProfile = [
                'id' => (int)$pdo->lastInsertId(),
                'profile_photo' => null,
            ];
        }

        $newPhotoPath = null;
        $upload = $_FILES['profile_photo'] ?? null;

        if (is_array($upload) && isset($upload['error']) && (int)$upload['error'] !== UPLOAD_ERR_NO_FILE) {
            if ((int)$upload['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Upload failed. Please try another photo.');
            }

            if ((int)($upload['size'] ?? 0) > 5 * 1024 * 1024) {
                throw new RuntimeException('Photo must be 5MB or smaller.');
            }

            $tmpPath = (string)($upload['tmp_name'] ?? '');
            $mime = '';
            if ($tmpPath !== '' && is_file($tmpPath)) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo !== false) {
                    $mime = (string)finfo_file($finfo, $tmpPath);
                    finfo_close($finfo);
                }
            }

            $ext = get_upload_extension($mime);
            if ($ext === null) {
                throw new RuntimeException('Only JPG, PNG, GIF, and WEBP images are allowed.');
            }

            $uploadDir = dirname(__DIR__) . '/assets/images/profiles';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
                throw new RuntimeException('Unable to create profile image folder.');
            }

            $fileName = 'dean_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $destination = $uploadDir . '/' . $fileName;

            if (!move_uploaded_file($tmpPath, $destination)) {
                throw new RuntimeException('Failed to save uploaded photo.');
            }

            $newPhotoPath = 'assets/images/profiles/' . $fileName;

            $oldPhoto = (string)($existingProfile['profile_photo'] ?? '');
            $oldFilePath = dirname(__DIR__) . '/' . ltrim($oldPhoto, '/');
            if ($oldPhoto !== '' && strpos($oldPhoto, 'assets/images/profiles/') === 0 && is_file($oldFilePath)) {
                @unlink($oldFilePath);
            }
        }

        $sql = 'UPDATE dean_profiles SET first_name = :first_name, last_name = :last_name';
        $params = [
            ':first_name' => $firstName,
            ':last_name' => $lastName,
            ':user_id' => $userId,
        ];

        if ($newPhotoPath !== null) {
            $sql .= ', profile_photo = :profile_photo';
            $params[':profile_photo'] = $newPhotoPath;
        }

        $sql .= ' WHERE user_id = :user_id';

        $updateStmt = $pdo->prepare($sql);
        $updateStmt->execute($params);

        $_SESSION['full_name'] = trim($firstName . ' ' . $lastName);

        header('Location: dean_profile.php?status=success&msg=' . urlencode('Profile updated successfully.'));
        exit;
    } catch (RuntimeException $e) {
        header('Location: dean_profile.php?status=error&msg=' . urlencode($e->getMessage()));
        exit;
    } catch (PDOException $e) {
        header('Location: dean_profile.php?status=error&msg=' . urlencode('Unable to save profile right now.'));
        exit;
    }
}

$profileStmt = $pdo->prepare(
    'SELECT
        u.username,
        u.email,
        dp.first_name,
        dp.last_name,
        dp.college_id,
        dp.profile_photo,
        c.name AS college_name,
        c.code AS college_code
     FROM users u
     LEFT JOIN dean_profiles dp ON dp.user_id = u.id
     LEFT JOIN colleges c ON c.id = dp.college_id
     WHERE u.id = :id AND u.role = :role
     LIMIT 1'
);
$profileStmt->execute([
    ':id' => $userId,
    ':role' => 'dean',
]);
$profile = $profileStmt->fetch(PDO::FETCH_ASSOC);

if (!$profile) {
    header('Location: /complaint-system-main/dean/login.php');
    exit;
}

$defaultName = trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Dean User'));
$nameParts = preg_split('/\s+/', $defaultName);
$fallbackFirstName = $nameParts[0] ?? 'Dean';
$fallbackLastName = count($nameParts) > 1 ? implode(' ', array_slice($nameParts, 1)) : 'User';

$firstName = trim((string)($profile['first_name'] ?? '')) ?: $fallbackFirstName;
$lastName = trim((string)($profile['last_name'] ?? '')) ?: $fallbackLastName;
$email = (string)($profile['email'] ?? ($_SESSION['email'] ?? ''));
$collegeName = trim((string)($profile['college_name'] ?? ''));
$collegeCode = trim((string)($profile['college_code'] ?? ''));
$collegeDisplay = $collegeName !== '' ? $collegeName : 'Not Assigned';
if ($collegeCode !== '') {
    $collegeDisplay .= ' (' . $collegeCode . ')';
}

$profilePhoto = trim((string)($profile['profile_photo'] ?? ''));
if ($profilePhoto !== '' && (stripos($profilePhoto, 'http://') === 0 || stripos($profilePhoto, 'https://') === 0)) {
    $profileImageSrc = $profilePhoto;
} elseif ($profilePhoto !== '') {
    $profileImageSrc = '../' . ltrim($profilePhoto, '/');
} else {
    // No photo on file - show the local placeholder instead of a blank box.
    $profileImageSrc = '../assets/images/default-avatar.svg';
}

$flashType = trim((string)($_GET['status'] ?? ''));
$flashMessage = trim((string)($_GET['msg'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>My Profile - VOICE</title>
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
    overflow-y: auto;
}

.dashboard-container {
    max-width: 1200px;
    margin: 0 auto;
    width: 100%;
}

/* ===== PAGE HEADER ===== */
.page-title {
    font-size: 24px;
    font-weight: 600;
    color: #333;
    margin-bottom: 5px;
}

.page-subtitle {
    color: #6b7280;
    font-size: 14px;
    margin-bottom: 25px;
}

/* ===== PROFILE GRID ===== */
.profile-grid {
    display: grid;
    grid-template-columns: 1fr 2fr;
    gap: 25px;
}

.card {
    background: #fff;
    border-radius: 12px;
    padding: 25px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 2px 10px rgba(0,0,0,0.02);
}

/* Profile Summary Card */
.profile-summary { text-align: center; }

.profile-avatar-wrapper {
    position: relative;
    width: 120px;
    height: 120px;
    margin: 0 auto 15px;
}

.profile-avatar-wrapper img {
    width: 100%;
    height: 100%;
    border-radius: 50%;
    object-fit: cover;
    border: 4px solid #f9fafb;
    box-shadow: 0 4px 10px rgba(0,0,0,0.1);
}

.upload-input-hidden { display: none; }

.btn-change-photo {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #e2e8f0;
    color: #475569;
    border: none;
    padding: 8px 16px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 500;
    font-family: 'Poppins', sans-serif;
    cursor: pointer;
    transition: 0.2s;
}

.btn-change-photo:hover {
    background: #cbd5e1;
}

.photo-hint {
    font-size: 11.5px;
    color: #6b7280;
    margin-top: 8px;
}

.profile-summary h3 { font-size: 18px; color: #1f2937; font-weight: 600; }
.profile-summary p.role { font-size: 13px; color: #4F8CFF; font-weight: 500; margin-bottom: 20px; }

.profile-details {
    text-align: left;
    margin-top: 20px;
    border-top: 1px solid #f0f0f0;
    padding-top: 20px;
}

.detail-item { margin-bottom: 15px; }
.detail-item label {
    display: block;
    font-size: 11px;
    text-transform: uppercase;
    color: #888;
    font-weight: 600;
    margin-bottom: 3px;
}

.detail-item p {
    font-size: 14px;
    color: #374151;
    display: flex;
    align-items: center;
    gap: 8px;
}

.detail-item i { color: #9ca3af; }

/* Forms */
.form-section { margin-bottom: 30px; }
.form-section-title {
    font-size: 16px;
    color: #1f2937;
    font-weight: 600;
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 1px solid #f0f0f0;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 15px;
}

.form-row.single-column {
    grid-template-columns: 1fr;
}

.form-group { display: flex; flex-direction: column; }
.form-group label { font-size: 13px; color: #374151; font-weight: 500; margin-bottom: 8px; }

.form-control {
    padding: 10px 15px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 13px;
    font-family: 'Poppins', sans-serif;
    outline: none;
    transition: 0.2s;
    background: #f9fafb;
}

.form-control:focus { border-color: #6d28d9; background: #fff; }
.form-control:disabled { background: #f3f4f6; color: #9ca3af; cursor: not-allowed; }

.btn-save {
    padding: 10px 20px;
    background: #6d28d9;
    color: white;
    border: none;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 500;
    font-family: 'Poppins', sans-serif;
    cursor: pointer;
    transition: 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.btn-save:hover { background: #5d1fa0; }

.flash-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.28);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1200;
    padding: 16px;
}

.flash-overlay.hidden {
    display: none;
}

.flash-card {
    width: min(92vw, 420px);
    border-radius: 14px;
    border: 1px solid;
    padding: 18px 18px 14px;
    box-shadow: 0 18px 40px rgba(2, 6, 23, 0.18);
    animation: popupIn 0.22s ease-out;
}

.flash-title {
    font-size: 16px;
    font-weight: 600;
    margin-bottom: 6px;
}

.flash-message {
    font-size: 14px;
    line-height: 1.4;
}

.flash-actions {
    margin-top: 14px;
    display: flex;
    justify-content: flex-end;
}

.flash-close-btn {
    border: none;
    border-radius: 8px;
    padding: 8px 12px;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
}

.flash-success {
    background: #ecfdf5;
    border-color: #a7f3d0;
    color: #047857;
}

.flash-success .flash-close-btn {
    background: #10b981;
    color: #fff;
}

.flash-error {
    background: #fef2f2;
    border-color: #fecaca;
    color: #b91c1c;
}

.flash-error .flash-close-btn {
    background: #ef4444;
    color: #fff;
}

@keyframes popupIn {
    from {
        opacity: 0;
        transform: translateY(8px) scale(0.98);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

@media (max-width: 900px) {
    .profile-grid { grid-template-columns: 1fr; }
    .form-row { grid-template-columns: 1fr; }
}
</style>
</head>

<body>

<!-- TOPBAR -->
<?php include 'dean_topbar.php'; ?>

<!-- SIDEBAR -->
<?php include 'dean_sidebar.php'; ?>

<!-- MAIN CONTENT -->
<div class="main">
    <div class="dashboard-container">

        <?php if ($flashMessage !== '' && in_array($flashType, ['success', 'error'], true)): ?>
            <div class="flash-overlay" id="flashOverlay">
                <div class="flash-card <?php echo $flashType === 'success' ? 'flash-success' : 'flash-error'; ?>" role="alert" aria-live="polite">
                    <div class="flash-title"><?php echo $flashType === 'success' ? 'Success' : 'Error'; ?></div>
                    <div class="flash-message"><?php echo e($flashMessage); ?></div>
                    <div class="flash-actions">
                        <button type="button" class="flash-close-btn" id="flashCloseBtn">OK</button>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <h2 class="page-title">My Profile</h2>
        <p class="page-subtitle">Manage your account settings and personal information.</p>

        <div class="profile-grid">

            <!-- Left: Summary Card -->
            <div class="card">
                <div class="profile-summary">
                    <div class="profile-avatar-wrapper">
                    <?php if ($profileImageSrc !== ''): ?>
                        <img src="<?php echo e($profileImageSrc); ?>" id="profileImagePreview" alt="Profile Picture">
                    <?php endif; ?>
                </div>
                    <input type="file" name="profile_photo" form="profileForm" id="profileUpload" class="upload-input-hidden" accept="image/*" onchange="previewImage(event)">
                    <button type="button" class="btn-change-photo" onclick="document.getElementById('profileUpload').click()">
                            <i class='bx bx-image-add'></i> Change Photo
                    </button>
                    <h3><?php echo e(trim($firstName . ' ' . $lastName)); ?></h3>
                    <p class="role">Dean, <?php echo e($collegeDisplay); ?></p>
                </div>

                <div class="profile-details">
                    <div class="detail-item">
                        <label>Email Address</label>
                        <p><i class='bx bx-envelope'></i> <?php echo e($email); ?></p>
                    </div>
                </div>
            </div>

            <!-- Right: Edit Forms -->
            <div class="card">

                <div class="form-section">
                    <h3 class="form-section-title">Personal Information</h3>
                    <form id="profileForm" method="POST" enctype="multipart/form-data" action="dean_profile.php">
                        <input type="hidden" name="action" value="update_profile">
                        <input type="hidden" name="csrf_token" value="<?php echo e((string)$_SESSION['csrf_token']); ?>">
                        <div class="form-row">
                            <div class="form-group">
                                <label><i class='bx bx-user'></i> First Name</label>
                                <input type="text" name="first_name" class="form-control" value="<?php echo e($firstName); ?>" required>
                            </div>
                            <div class="form-group">
                                <label><i class='bx bx-user'></i> Last Name</label>
                                <input type="text" name="last_name" class="form-control" value="<?php echo e($lastName); ?>" required>
                            </div>
                        </div>
                        <div class="form-row single-column">
                            <div class="form-group">
                                <label><i class='bx bx-envelope'></i> Email Address</label>
                                <input type="email" class="form-control" value="<?php echo e($email); ?>" disabled title="Email address cannot be changed here.">
                            </div>
                        </div>
                        <div class="form-row single-column">
                            <div class="form-group">
                                <label><i class='bx bx-buildings'></i> College Department</label>
                                <input type="text" class="form-control" value="<?php echo e($collegeDisplay); ?>" disabled title="Contact SuperAdmin to change your designated college.">
                            </div>
                        </div>
                        <button type="submit" class="btn-save"><i class='bx bx-save'></i> Save Changes</button>
                    </form>
                </div>

                <div class="form-section" style="margin-bottom: 0;">
                    <h3 class="form-section-title">Security & Password</h3>
                    <form onsubmit="event.preventDefault(); alert('Password updated successfully!');">
                        <div class="form-group" style="margin-bottom: 15px;">
                            <label>Current Password</label>
                            <input type="password" class="form-control" placeholder="Enter current password" required>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>New Password</label>
                                <input type="password" class="form-control" placeholder="Create new password" required>
                            </div>
                            <div class="form-group">
                                <label>Confirm New Password</label>
                                <input type="password" class="form-control" placeholder="Confirm new password" required>
                            </div>
                        </div>
                        <button type="submit" class="btn-save"><i class='bx bx-lock-alt'></i> Update Password</button>
                    </form>
                </div>

            </div>
        </div>

    </div>
</div>

<script>
function previewImage(event) {
    const file = event.target.files[0];
    if (!file) return;

    const reader = new window.FileReader();
    reader.onload = function() {
        document.getElementById('profileImagePreview').src = reader.result;
    };
    reader.readAsDataURL(file);
}

(function () {
    var overlay = document.getElementById('flashOverlay');
    if (!overlay) return;

    var closeBtn = document.getElementById('flashCloseBtn');
    var hide = function () {
        overlay.classList.add('hidden');
    };

    if (closeBtn) {
        closeBtn.addEventListener('click', hide);
    }

    overlay.addEventListener('click', function (event) {
        if (event.target === overlay) {
            hide();
        }
    });

    setTimeout(hide, 2600);
})();
</script>

</body>
</html>