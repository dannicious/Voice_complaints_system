<?php
session_start();
require_once __DIR__ . '/../db_connection.php';

function require_admin_session(PDO $pdo): void
{
    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    $sessionRole = strtolower(trim((string)($_SESSION['role'] ?? '')));

    if ($userId <= 0) {
        header('Location: ../login.php?error=' . urlencode('Please log in as admin.'));
        exit;
    }

    if ($sessionRole === 'admin') {
        return;
    }

    try {
        $stmt = $pdo->prepare('SELECT role FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch();
        $dbRole = strtolower(trim((string)($row['role'] ?? '')));

        if ($dbRole === 'admin') {
            $_SESSION['role'] = 'admin';
            return;
        }
    } catch (PDOException $e) {
    }

    header('Location: ../login.php?error=' . urlencode('Please log in as admin.'));
    exit;
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function normalize_username(string $firstName, string $lastName): string
{
    $base = strtolower(preg_replace('/[^a-z0-9]/i', '', $firstName . '.' . $lastName));
    if ($base === '') {
        $base = 'dean';
    }
    return substr($base, 0, 40);
}

function generate_unique_username(PDO $pdo, string $firstName, string $lastName): string
{
    $base = normalize_username($firstName, $lastName);
    $candidate = $base;
    $attempt = 0;

    while (true) {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
        $stmt->execute([':username' => $candidate]);
        $exists = $stmt->fetch();

        if (!$exists) {
            return $candidate;
        }

        $attempt++;
        $suffix = (string)random_int(100, 999);
        $candidate = substr($base, 0, max(1, 40 - strlen($suffix))) . $suffix;

        if ($attempt > 10) {
            return 'dean' . random_int(10000, 99999);
        }
    }
}

function normalize_search_query(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    return preg_replace('/\s+/', ' ', $value) ?? '';
}

function escape_like(string $value): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
}

require_admin_session($pdo);

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$flashMessage = '';
$flashType = '';
$showAddModal = false;
$newDeanUsername = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $token = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $flashMessage = 'Invalid request token.';
        $flashType = 'error';
    } elseif ($action === 'add_dean') {
        $showAddModal = true;

        $firstName = trim((string)($_POST['first_name'] ?? ''));
        $lastName = trim((string)($_POST['last_name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $collegeIdRaw = trim((string)($_POST['college_id'] ?? ''));
        $collegeId = $collegeIdRaw !== '' ? (int)$collegeIdRaw : null;

        if ($firstName === '' || $lastName === '' || $email === '' || $password === '') {
            $flashMessage = 'First name, last name, email, and password are required.';
            $flashType = 'error';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $flashMessage = 'Please provide a valid email address.';
            $flashType = 'error';
        } else {
            try {
                $pdo->beginTransaction();

                if ($collegeId !== null && $collegeId > 0) {
                    $collegeCheck = $pdo->prepare('SELECT id FROM colleges WHERE id = :id LIMIT 1');
                    $collegeCheck->execute([':id' => $collegeId]);
                    if (!$collegeCheck->fetch()) {
                        throw new RuntimeException('Selected college does not exist.');
                    }
                }

                $username = generate_unique_username($pdo, $firstName, $lastName);

                $userStmt = $pdo->prepare(
                    'INSERT INTO users (username, email, password, role, is_active)
                     VALUES (:username, :email, :password, :role, :is_active)'
                );
                $userStmt->execute([
                    ':username' => $username,
                    ':email' => $email,
                    ':password' => password_hash($password, PASSWORD_DEFAULT),
                    ':role' => 'dean',
                    ':is_active' => 1,
                ]);

                $userId = (int)$pdo->lastInsertId();

                $profileStmt = $pdo->prepare(
                    'INSERT INTO dean_profiles (user_id, first_name, last_name, college_id, status)
                     VALUES (:user_id, :first_name, :last_name, :college_id, :status)'
                );
                $profileStmt->execute([
                    ':user_id' => $userId,
                    ':first_name' => $firstName,
                    ':last_name' => $lastName,
                    ':college_id' => ($collegeId !== null && $collegeId > 0) ? $collegeId : null,
                    ':status' => 'active',
                ]);

                $pdo->commit();

                $flashMessage = 'Dean account created successfully.';
                $flashType = 'success';
                $showAddModal = false;
                $newDeanUsername = $username;
            } catch (RuntimeException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $flashMessage = $e->getMessage();
                $flashType = 'error';
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ((string)$e->getCode() === '23000') {
                    $flashMessage = 'Email already exists. Please use a different email.';
                } else {
                    $flashMessage = 'Unable to create dean account right now.';
                }
                $flashType = 'error';
            }
        }
    } elseif ($action === 'delete_dean') {
        $profileId = (int)($_POST['profile_id'] ?? 0);

        if ($profileId <= 0) {
            $flashMessage = 'Invalid delete request.';
            $flashType = 'error';
        } else {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare('SELECT user_id FROM dean_profiles WHERE id = :id LIMIT 1');
                $stmt->execute([':id' => $profileId]);
                $profile = $stmt->fetch();

                if (!$profile) {
                    throw new RuntimeException('Dean profile not found.');
                }

                $deleteProfile = $pdo->prepare('DELETE FROM dean_profiles WHERE id = :id');
                $deleteProfile->execute([':id' => $profileId]);

                $deleteUser = $pdo->prepare('DELETE FROM users WHERE id = :user_id AND role = :role');
                $deleteUser->execute([
                    ':user_id' => (int)$profile['user_id'],
                    ':role' => 'dean',
                ]);

                $pdo->commit();

                $flashMessage = 'Dean account removed successfully.';
                $flashType = 'success';
            } catch (RuntimeException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $flashMessage = $e->getMessage();
                $flashType = 'error';
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $flashMessage = 'Unable to remove dean right now. There may be related records linked to this account.';
                $flashType = 'error';
            }
        }
    }
}

$q = normalize_search_query((string)($_GET['q'] ?? ''));
$deans = [];
$colleges = [];

try {
    $collegesStmt = $pdo->query('SELECT id, code, name FROM colleges ORDER BY name ASC');
    $colleges = $collegesStmt->fetchAll();

    $hasCreatedAt = false;
    try {
        $createdAtCheck = $pdo->query("SHOW COLUMNS FROM dean_profiles LIKE 'created_at'");
        $hasCreatedAt = (bool)$createdAtCheck->fetch();
    } catch (PDOException $e) {
        $hasCreatedAt = false;
    }

    $dateSelect = $hasCreatedAt
        ? "DATE_FORMAT(dp.created_at, '%b %d, %Y') AS date_added"
        : "NULL AS date_added";

    $sql =
        "SELECT
            dp.id AS profile_id,
            dp.user_id,
            dp.first_name,
            dp.last_name,
            dp.status,
            u.username,
            u.email,
            u.is_active,
            COALESCE(c.code, '') AS college_code,
            COALESCE(c.name, '') AS college_name,
            {$dateSelect}
         FROM dean_profiles dp
         INNER JOIN users u ON u.id = dp.user_id
         LEFT JOIN colleges c ON c.id = dp.college_id
         WHERE u.role = 'dean'";

    $params = [];

    if ($q !== '') {
        $likeQuery = '%' . escape_like($q) . '%';
        $sql .= " AND (
            CONCAT(COALESCE(dp.first_name,''), ' ', COALESCE(dp.last_name,'')) LIKE :q1
            OR CONCAT(COALESCE(dp.last_name,''), ' ', COALESCE(dp.first_name,'')) LIKE :q2
            OR COALESCE(dp.first_name,'') LIKE :q3
            OR COALESCE(dp.last_name,'') LIKE :q4
            OR u.email LIKE :q5
            OR u.username LIKE :q6
            OR c.code LIKE :q7
            OR c.name LIKE :q8
            OR dp.status LIKE :q9
        )";
        $params[':q1'] = $likeQuery;
        $params[':q2'] = $likeQuery;
        $params[':q3'] = $likeQuery;
        $params[':q4'] = $likeQuery;
        $params[':q5'] = $likeQuery;
        $params[':q6'] = $likeQuery;
        $params[':q7'] = $likeQuery;
        $params[':q8'] = $likeQuery;
        $params[':q9'] = $likeQuery;
    }

    $sql .= ' ORDER BY dp.id DESC';

    $deansStmt = $pdo->prepare($sql);
    $deansStmt->execute($params);
    $deans = $deansStmt->fetchAll();
} catch (PDOException $e) {
    if ($flashMessage === '') {
        $flashMessage = 'Unable to load dean records right now.';
        $flashType = 'error';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>VOICE - Deans</title>
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
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 25px;
}

.page-header h2 {
    font-size: 24px;
    font-weight: 600;
    color: #333;
}

.flash-popup-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.45);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 120;
}

.flash-popup-overlay.show {
    display: flex;
}

.flash-popup-card {
    width: min(92vw, 440px);
    border-radius: 12px;
    background: #fff;
    padding: 22px;
    box-shadow: 0 14px 32px rgba(0, 0, 0, 0.18);
    animation: fadeIn 0.22s ease;
}

.flash-popup-title {
    font-size: 18px;
    font-weight: 600;
    margin-bottom: 8px;
}

.flash-popup-message {
    font-size: 14px;
    line-height: 1.45;
}

.flash-popup-username {
    margin-top: 10px;
    font-size: 13px;
    font-weight: 600;
}

.flash-popup-actions {
    margin-top: 18px;
    display: flex;
    justify-content: flex-end;
}

.flash-popup-success {
    border: 1px solid #10b98155;
    background: #ecfdf5;
    color: #065f46;
}

.flash-popup-error {
    border: 1px solid #ef444455;
    background: #fef2f2;
    color: #991b1b;
}

/* CONTROLS BAR */
.controls-card {
    background: #fff;
    padding: 20px;
    border-radius: 12px;
    margin-bottom: 25px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.03);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
}

.search-form {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.search-box {
    display: flex;
    align-items: center;
    background: #f4f6fb;
    padding: 8px 15px;
    border-radius: 8px;
    width: 360px;
    border: 1px solid #e5e7eb;
}

.search-box i { color: #888; margin-right: 10px; font-size: 18px; }
.search-box input { border: none; background: transparent; outline: none; width: 100%; font-size: 13px; }

.btn-search {
    padding: 10px 14px;
    border: none;
    border-radius: 8px;
    background: #111827;
    color: #fff;
    cursor: pointer;
    font-size: 13px;
}

.btn-clear {
    padding: 10px 14px;
    border-radius: 8px;
    border: 1px solid #d1d5db;
    background: #fff;
    color: #374151;
    text-decoration: none;
    font-size: 13px;
}

.btn-add {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 10px 20px;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 500;
    background: #6d28d9;
    color: #fff;
    cursor: pointer;
    transition: 0.2s;
}

.btn-add:hover { background: #3b6fd1; }

/* TABLE */
.table-card {
    background: #fff;
    padding: 25px;
    border-radius: 12px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.03);
    width: 100%;
    overflow-x: auto;
}

table { width: 100%; border-collapse: collapse; min-width: 800px; }

th {
    text-align: left;
    font-size: 13px;
    color: #888;
    padding-bottom: 15px;
    border-bottom: 1px solid #f0f0f0;
    font-weight: 500;
}

td {
    padding: 15px 0;
    font-size: 13px;
    color: #444;
    border-bottom: 1px solid #f9f9f9;
    vertical-align: middle;
}

tbody tr { transition: background 0.2s; }
tbody tr:hover { background-color: #fcfcfc; }

/* Dean Profile Cell */
.dean-profile {
    display: flex;
    align-items: center;
    gap: 12px;
}

.avatar-circle {
    width: 35px;
    height: 35px;
    border-radius: 50%;
    background: #dbeafe;
    color: #1d4ed8;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 700;
}

.dean-info { display: flex; flex-direction: column; }
.dean-name { font-weight: 600; color: #333; }
.dean-email { font-size: 11px; color: #888; }

/* Badges */
.status-badge { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
.status-active   { background: #d1fae5; color: #059669; }
.status-inactive { background: #fee2e2; color: #b91c1c; }

.college-badge { background: #e0f2fe; color: #0284c7; padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: 500; }
.unassigned { background: #f3f4f6; color: #6b7280; }

/* Action Buttons */
.action-btns { display: flex; gap: 8px; }

.btn-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    border-radius: 6px;
    color: #fff;
    text-decoration: none;
    font-size: 16px;
    transition: 0.2s;
    border: none;
    cursor: pointer;
}

.btn-edit   { background: #10b981; }
.btn-edit:hover   { background: #059669; }
.btn-delete { background: #ef4444; }
.btn-delete:hover { background: #dc2626; }

/* ===== MODAL STYLES ===== */
.modal-overlay {
    position: fixed;
    top: 0; left: 0; width: 100%; height: 100%;
    background: rgba(0,0,0,0.5);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 100;
}

.modal-overlay.show {
    display: flex;
}

.modal-content {
    background: #fff;
    width: 450px;
    border-radius: 12px;
    padding: 25px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    position: relative;
    animation: fadeIn 0.3s ease;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-20px); }
    to   { opacity: 1; transform: translateY(0); }
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.modal-header h3 { font-size: 18px; color: #333; }
.close-btn { font-size: 24px; color: #888; cursor: pointer; transition: 0.2s; }
.close-btn:hover { color: #ef4444; }

.form-row { display: flex; gap: 15px; }
.form-row .form-group { flex: 1; }

.form-group { margin-bottom: 15px; }
.form-group label { display: block; font-size: 13px; color: #555; margin-bottom: 5px; font-weight: 500; }
.form-group input,
.form-group select {
    width: 100%; padding: 10px 15px; border: 1px solid #ddd;
    border-radius: 8px; font-size: 13px; color: #333; outline: none; transition: 0.2s;
}
.form-group input:focus,
.form-group select:focus { border-color: #4F8CFF; }

.modal-footer { display: flex; justify-content: flex-end; gap: 10px; margin-top: 25px; }
.btn-cancel { padding: 10px 20px; border: none; border-radius: 8px; font-size: 13px; cursor: pointer; background: #f4f6fb; color: #555; }
.btn-cancel:hover { background: #e5e7eb; }
.btn-submit { padding: 10px 20px; border: none; border-radius: 8px; font-size: 13px; cursor: pointer; background: #4F8CFF; color: #fff; font-weight: 500; }
.btn-submit:hover { background: #3b6fd1; }

.empty-row {
    text-align: center;
    color: #9ca3af;
    padding: 20px 0;
}

/* Delete confirmation card */
.confirm-modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 110;
}

.confirm-modal-overlay.show {
    display: flex;
}

.confirm-modal-card {
    width: min(92vw, 420px);
    background: #fff;
    border-radius: 12px;
    padding: 22px;
    box-shadow: 0 14px 30px rgba(0, 0, 0, 0.15);
    animation: fadeIn 0.2s ease;
}

.confirm-modal-title {
    font-size: 18px;
    font-weight: 600;
    color: #111827;
    margin-bottom: 10px;
}

.confirm-modal-text {
    font-size: 14px;
    color: #4b5563;
    line-height: 1.5;
    margin-bottom: 20px;
}

.confirm-modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

.btn-danger {
    padding: 10px 16px;
    border: none;
    border-radius: 8px;
    background: #ef4444;
    color: #fff;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
}

.btn-danger:hover {
    background: #dc2626;
}
</style>
</head>

<body>

<?php if ($flashMessage !== ''): ?>
<div class="flash-popup-overlay show" id="flashPopup">
    <div class="flash-popup-card <?php echo $flashType === 'success' ? 'flash-popup-success' : 'flash-popup-error'; ?>">
        <h3 class="flash-popup-title"><?php echo $flashType === 'success' ? 'Success' : 'Action Failed'; ?></h3>
        <div class="flash-popup-message"><?php echo e($flashMessage); ?></div>
        <?php if ($newDeanUsername !== ''): ?>
            <div class="flash-popup-username">Generated username: <?php echo e($newDeanUsername); ?></div>
        <?php endif; ?>
        <div class="flash-popup-actions">
            <button type="button" class="btn-submit" onclick="closeFlashPopup()">OK</button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- TOPBAR (fixed, full width) -->
<?php include 'admin_topbar.php'; ?>

<!-- SIDEBAR (fixed, below topbar) -->
<?php include 'admin_sidebar.php'; ?>

<!-- MAIN CONTENT -->
<div class="main">
    <div class="dashboard-container">

        <div class="page-header">
            <h2>Deans Management</h2>
        </div>

        <div class="controls-card">
            <form method="GET" class="search-form">
                <div class="search-box">
                    <i class='bx bx-search'></i>
                    <input type="text" name="q" value="<?php echo e($q); ?>" placeholder="Search by Name, Email, Username, or College...">
                </div>
                <button type="submit" class="btn-search">Search</button>
                <?php if ($q !== ''): ?>
                    <a href="admin_dean.php" class="btn-clear">Clear</a>
                <?php endif; ?>
            </form>

            <button class="btn-add" type="button" onclick="openModal()">
                <i class='bx bx-user-plus'></i> Add New Dean
            </button>
        </div>

        <div class="table-card">
            <table>
                <thead>
                    <tr>
                        <th>Profile</th>
                        <th>Assigned College</th>
                        <th>Date Added</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($deans)): ?>
                        <tr>
                            <td colspan="5" class="empty-row">No dean records found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($deans as $row): ?>
                            <?php
                                $fullName = trim((string)$row['first_name'] . ' ' . (string)$row['last_name']);
                                $email = (string)($row['email'] ?? '');
                                $status = strtolower(trim((string)($row['status'] ?? 'inactive')));
                                $isActive = $status === 'active';
                                $initials = strtoupper(substr((string)$row['first_name'], 0, 1) . substr((string)$row['last_name'], 0, 1));
                                $dateAdded = trim((string)($row['date_added'] ?? ''));
                            ?>
                            <tr>
                                <td>
                                    <div class="dean-profile">
                                        <span class="avatar-circle"><?php echo e($initials !== '' ? $initials : 'DN'); ?></span>
                                        <div class="dean-info">
                                            <span class="dean-name"><?php echo e($fullName !== '' ? $fullName : 'Unnamed Dean'); ?></span>
                                            <span class="dean-email"><?php echo e($email); ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php if ((string)$row['college_code'] !== ''): ?>
                                        <span class="college-badge"><?php echo e((string)$row['college_code']); ?></span>
                                    <?php else: ?>
                                        <span class="college-badge unassigned">Unassigned</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo e($dateAdded !== '' ? $dateAdded : 'N/A'); ?></td>
                                <td>
                                    <span class="status-badge <?php echo $isActive ? 'status-active' : 'status-inactive'; ?>">
                                        <?php echo $isActive ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-btns">
                                        <form method="POST" style="display:inline;" onsubmit="return openDeleteModal(event, this, '<?php echo e($fullName !== '' ? $fullName : 'this dean'); ?>');">
                                            <input type="hidden" name="csrf_token" value="<?php echo e((string)$_SESSION['csrf_token']); ?>">
                                            <input type="hidden" name="action" value="delete_dean">
                                            <input type="hidden" name="profile_id" value="<?php echo (int)$row['profile_id']; ?>">
                                            <button type="submit" class="btn-icon btn-delete" title="Delete dean">
                                                <i class='bx bx-trash'></i>
                                            </button>
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

<!-- ADD DEAN MODAL -->
<div class="modal-overlay<?php echo $showAddModal ? ' show' : ''; ?>" id="addDeanModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Add New Dean</h3>
            <i class='bx bx-x close-btn' onclick="closeModal()"></i>
        </div>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo e((string)$_SESSION['csrf_token']); ?>">
            <input type="hidden" name="action" value="add_dean">

            <div class="form-row">
                <div class="form-group">
                    <label>First Name</label>
                    <input type="text" name="first_name" placeholder="e.g. Richard" value="<?php echo e((string)($_POST['first_name'] ?? '')); ?>" required>
                </div>
                <div class="form-group">
                    <label>Last Name</label>
                    <input type="text" name="last_name" placeholder="e.g. Feynman" value="<?php echo e((string)($_POST['last_name'] ?? '')); ?>" required>
                </div>
            </div>

            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" placeholder="dean@voice.edu" value="<?php echo e((string)($_POST['email'] ?? '')); ?>" required>
            </div>

            <div class="form-group">
                <label>Temporary Password</label>
                <input type="password" name="password" placeholder="Set initial password" required>
            </div>

            <div class="form-group">
                <label>Assign to College (Optional)</label>
                <select name="college_id">
                    <option value="">-- Leave Unassigned --</option>
                    <?php foreach ($colleges as $college): ?>
                        <?php $selectedCollege = (string)($_POST['college_id'] ?? '') === (string)$college['id']; ?>
                        <option value="<?php echo (int)$college['id']; ?>" <?php echo $selectedCollege ? 'selected' : ''; ?>>
                            <?php echo e((string)$college['code'] . ' - ' . (string)$college['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn-submit">Save Dean</button>
            </div>
        </form>
    </div>
</div>

<!-- DELETE CONFIRMATION CARD -->
<div class="confirm-modal-overlay" id="deleteConfirmModal">
    <div class="confirm-modal-card">
        <h3 class="confirm-modal-title">Remove Dean</h3>
        <p class="confirm-modal-text" id="deleteConfirmText">Are you sure you want to remove this dean?</p>
        <div class="confirm-modal-actions">
            <button type="button" class="btn-cancel" onclick="closeDeleteModal()">Cancel</button>
            <button type="button" class="btn-danger" onclick="confirmDeleteDean()">Remove Dean</button>
        </div>
    </div>
</div>

<script>
function openModal() {
    document.getElementById('addDeanModal').classList.add('show');
}

function closeModal() {
    document.getElementById('addDeanModal').classList.remove('show');
}

function closeFlashPopup() {
    const popup = document.getElementById('flashPopup');
    if (popup) {
        popup.classList.remove('show');
    }
}

let deleteTargetForm = null;

function openDeleteModal(event, form, deanName) {
    event.preventDefault();
    deleteTargetForm = form;

    const modal = document.getElementById('deleteConfirmModal');
    const text = document.getElementById('deleteConfirmText');
    const safeDeanName = deanName && deanName.trim() !== '' ? deanName : 'this dean';
    text.textContent = 'Are you sure you want to remove ' + safeDeanName + '?';
    modal.classList.add('show');
    return false;
}

function closeDeleteModal() {
    const modal = document.getElementById('deleteConfirmModal');
    modal.classList.remove('show');
    deleteTargetForm = null;
}

function confirmDeleteDean() {
    if (deleteTargetForm) {
        deleteTargetForm.submit();
    }
}

window.addEventListener('click', function(event) {
    var modal = document.getElementById('addDeanModal');
    if (event.target === modal) {
        closeModal();
    }

    var flashPopup = document.getElementById('flashPopup');
    if (event.target === flashPopup) {
        closeFlashPopup();
    }

    var deleteModal = document.getElementById('deleteConfirmModal');
    if (event.target === deleteModal) {
        closeDeleteModal();
    }
});
</script>

</body>
</html>
