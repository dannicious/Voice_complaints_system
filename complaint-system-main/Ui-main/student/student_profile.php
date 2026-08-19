<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../db_connection.php';

if (!isset($_SESSION['profile_csrf_token'])) {
    $_SESSION['profile_csrf_token'] = bin2hex(random_bytes(32));
}

function redirect_profile(string $status, string $msg): void
{
    $query = http_build_query([
        'status' => $status,
        'msg' => $msg,
    ]);
    header('Location: student_profile.php?' . $query);
    exit;
}

function upload_student_profile_photo(array $file): ?string
{
    if (!isset($file['error']) || (int)$file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ((int)$file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Photo upload failed.');
    }

    if ((int)$file['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('Photo must be 5MB or smaller.');
    }

    $tmpName = (string)$file['tmp_name'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (string)finfo_file($finfo, $tmpName) : '';
    if ($finfo) {
        finfo_close($finfo);
    }

    $map = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    if (!isset($map[$mime])) {
        throw new RuntimeException('Only JPG, PNG, and WEBP images are allowed.');
    }

    $uploadDir = __DIR__ . '/../assets/images/profiles';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Failed to prepare profile photo directory.');
    }

    $filename = 'student_' . (int)($_SESSION['user_id'] ?? 0) . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $map[$mime];
    $absPath = $uploadDir . '/' . $filename;

    if (!move_uploaded_file($tmpName, $absPath)) {
        throw new RuntimeException('Failed to save profile photo.');
    }

    return 'assets/images/profiles/' . $filename;
}

function resolve_student_photo(string $storedPath): string
{
    $storedPath = trim($storedPath);
    if ($storedPath === '') {
        return '../assets/images/default-avatar.svg';
    }

    $relativePath = ltrim($storedPath, '/');
    $absolutePath = __DIR__ . '/../' . $relativePath;
    if (is_file($absolutePath)) {
        return '../' . $relativePath;
    }

    return '../assets/images/default-avatar.svg';
}

// Default values
$studentName = 'Student';
$studentEmail = '';
$studentPhoto = '../assets/images/default-avatar.svg';
$firstName = '';
$lastName = '';
$role = 'Student';
$studentProfile = [];

if (isset($_SESSION['user_id'])) {
    try {
        $profileStmt = $pdo->prepare(
            'SELECT sp.first_name, sp.last_name, sp.student_number, sp.middle_name, sp.gender, sp.contact_number,
                    sp.year_level, sp.section, sp.school_year,
                    c.name AS college_name, c.code AS college_code,
                    p.name AS program_name,
                    u.username, u.email, u.profile_pic
             FROM student_profiles sp
             LEFT JOIN users u ON u.id = sp.user_id
             LEFT JOIN colleges c ON c.id = sp.college_id
             LEFT JOIN programs p ON p.id = sp.program_id
             WHERE sp.user_id = :user_id'
        );
        $profileStmt->execute([':user_id' => (int)$_SESSION['user_id']]);
        $studentProfile = $profileStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($studentProfile) {
            $firstName = $studentProfile['first_name'] ?? '';
            $lastName = $studentProfile['last_name'] ?? '';
            $studentName = trim($firstName . ' ' . $lastName);
            if (empty($studentName)) {
                $studentName = trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? $studentProfile['username'] ?? 'Student'));
            }
            $studentEmail = $studentProfile['email'] ?? '';
            $studentPhoto = resolve_student_photo((string)($studentProfile['profile_pic'] ?? ''));
            $role = 'Student';
            if (!empty($studentProfile['year_level'])) {
                $role .= ', ' . $studentProfile['year_level'] . ' Year';
            }
            if (!empty($studentProfile['college_name'])) {
                $role .= ' - ' . $studentProfile['college_name'];
            }
        }
    } catch (PDOException $e) {
        // Silently fail, use defaults
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && (string)$_POST['action'] === 'update_profile') {
    if (!isset($_SESSION['user_id'])) {
        redirect_profile('error', 'Please log in again.');
    }

    if (!hash_equals((string)$_SESSION['profile_csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
        redirect_profile('error', 'Invalid request token. Please refresh and try again.');
    }

    $newFirstName = trim((string)($_POST['first_name'] ?? ''));
    $newLastName = trim((string)($_POST['last_name'] ?? ''));

    if ($newFirstName === '' || $newLastName === '') {
        redirect_profile('error', 'First name and last name are required.');
    }

    try {
        $pdo->beginTransaction();

        $profilePhotoPath = upload_student_profile_photo($_FILES['imageUpload'] ?? []);
        if ($profilePhotoPath !== null) {
            $picStmt = $pdo->prepare('UPDATE users SET profile_pic = :profile_pic WHERE id = :id');
            $picStmt->execute([
                ':profile_pic' => $profilePhotoPath,
                ':id' => (int)$_SESSION['user_id'],
            ]);
        }

        $updateProfileStmt = $pdo->prepare(
            'UPDATE student_profiles
             SET first_name = :first_name, last_name = :last_name
             WHERE user_id = :user_id'
        );
        $updateProfileStmt->execute([
            ':first_name' => $newFirstName,
            ':last_name' => $newLastName,
            ':user_id' => (int)$_SESSION['user_id'],
        ]);

        $pdo->commit();
        redirect_profile('success', 'Profile updated successfully.');
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        redirect_profile('error', $e->getMessage());
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        redirect_profile('error', 'Unable to update profile right now.');
    }
}

$flashStatus = (string)($_GET['status'] ?? '');
$flashMessage = trim((string)($_GET['msg'] ?? ''));
if (!in_array($flashStatus, ['success', 'error'], true)) {
    $flashStatus = '';
    $flashMessage = '';
}

function e(string $text): string {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}
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

@media (max-width: 900px) {
    .profile-grid { grid-template-columns: 1fr; }
    .form-row { grid-template-columns: 1fr; }
}
</style>
</head>

<body>

<!-- TOPBAR -->
<?php include 'student_topbar.php'; ?>

<!-- SIDEBAR -->
<?php include 'student_sidebar.php'; ?>

<!-- MAIN CONTENT -->
<div class="main">
    <div class="dashboard-container">

        <h2 class="page-title">My Profile</h2>
        <p class="page-subtitle">Manage your account settings and personal information.</p>

        <div class="profile-grid">

            <!-- Left: Summary Card -->
            <div class="card">
                <div class="profile-summary">
                    <div class="profile-avatar-wrapper">
                        <img src="<?php echo e($studentPhoto); ?>" alt="Profile Photo" class="profile-photo-large" id="profilePreview">
                    </div>
                    <input type="file" id="profileUpload" name="imageUpload" form="studentProfileForm" class="upload-input-hidden" accept="image/*" onchange="previewImage(event)">
                    <button type="button" class="btn-change-photo" onclick="document.getElementById('profileUpload').click()">
                            <i class='bx bx-image-add'></i> Change Photo
                        </button>
                    <h3><?php echo e($studentName); ?></h3>
                    <p class="role"><?php echo e($role); ?></p>
                </div>

                <div class="profile-details">
                    <div class="detail-item">
                        <label>Student ID</label>
                        <p><?php echo e($studentProfile['student_number'] ?? 'N/A'); ?></p>
                    </div>
                    <div class="detail-item">
                        <label>College</label>
                        <p><?php echo e($studentProfile['college_name'] ?? 'N/A'); ?></p>
                    </div>
                    <div class="detail-item">
                        <label>Course</label>
                        <p><?php echo e($studentProfile['program_name'] ?? 'N/A'); ?></p>
                    </div>
                    <div class="detail-item">
                        <label>Year Level</label>
                        <p><?php echo e(!empty($studentProfile['year_level']) ? $studentProfile['year_level'] . ' Year' : 'N/A'); ?></p>
                    </div>
                    <div class="detail-item">
                        <label>Email Address</label>
                        <p><i class='bx bx-envelope'></i> <?php echo e($studentEmail); ?></p>
                    </div>
                </div>
            </div>

            <!-- Right: Edit Forms -->
            <div class="card">

                <?php if ($flashMessage !== ''): ?>
                    <div style="margin-bottom: 18px; padding: 12px 14px; border-radius: 8px; background: <?php echo $flashStatus === 'success' ? '#ecfdf5' : '#fef2f2'; ?>; color: <?php echo $flashStatus === 'success' ? '#065f46' : '#991b1b'; ?>; border: 1px solid <?php echo $flashStatus === 'success' ? '#a7f3d0' : '#fecaca'; ?>;">
                        <?php echo e($flashMessage); ?>
                    </div>
                <?php endif; ?>

                <div class="form-section">
                    <h3 class="form-section-title">Personal Information</h3>
                    <form id="studentProfileForm" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['profile_csrf_token']); ?>">
                        <input type="hidden" name="action" value="update_profile">
                        <div class="profile-avatar-wrapper" style="margin: 0 0 18px; display:none;">
                            <img src="<?php echo e($studentPhoto); ?>" alt="Profile Photo" class="profile-photo-large" id="profilePreviewForm">
                        </div>
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
                                <input type="email" class="form-control" value="<?php echo e($studentEmail); ?>" disabled title="Email address cannot be changed here.">
                            </div>
                        </div>
                        <div class="form-row single-column">
                            <div class="form-group">
                                <label><i class='bx bx-buildings'></i> Course / Department</label>
                                <input type="text" class="form-control" value="<?php echo e($studentProfile['program_name'] ?? 'N/A'); ?>" disabled title="Contact your department office to update your course information.">
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
        document.getElementById('profilePreview').src = reader.result;
    };
    reader.readAsDataURL(file);
}
</script>

</body>
</html>