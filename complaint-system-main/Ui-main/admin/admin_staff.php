<?php
session_start();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../suggestion_flow.php';

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

function normalize_username(string $name): string
{
    $base = strtolower(preg_replace('/[^a-z0-9]/i', '', $name));
    if ($base === '') {
        $base = 'staff';
    }
    return substr($base, 0, 40);
}

function generate_unique_username(PDO $pdo, string $name): string
{
    $base = normalize_username($name);
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
            return 'staff' . random_int(10000, 99999);
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
ensure_suggestion_area_schema($pdo);

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$flashMessage = '';
$flashType = '';
$showAddModal = false;
$newStaffUsername = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $token = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $flashMessage = 'Invalid request token.';
        $flashType = 'error';
    } elseif ($action === 'add_staff') {
        $showAddModal = true;

        $name = trim((string)($_POST['name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $office = trim((string)($_POST['office'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        // Exactly one Area, deliberately - these appear on the student-facing
        // suggestion form as the Level 1 picker, so each one has to be a
        // considered, singular addition to that list, not something that
        // multiplies as a side effect of routine staff account creation.
        $suggestionAreaName = trim((string)($_POST['suggestion_area_text'] ?? ''));

        if ($name === '' || $email === '' || $password === '' || $office === '' || $suggestionAreaName === '') {
            $flashMessage = 'Name, email, password, office, and a General Suggestion Area are required.';
            $flashType = 'error';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $flashMessage = 'Please provide a valid email address.';
            $flashType = 'error';
        } else {
            try {
                $pdo->beginTransaction();

                $username = generate_unique_username($pdo, $name);

                $userStmt = $pdo->prepare(
                    'INSERT INTO users (username, email, password, role, is_active)
                     VALUES (:username, :email, :password, :role, :is_active)'
                );
                $userStmt->execute([
                    ':username' => $username,
                    ':email' => $email,
                    ':password' => password_hash($password, PASSWORD_DEFAULT),
                    ':role' => 'staff',
                    ':is_active' => 1,
                ]);

                $userId = (int)$pdo->lastInsertId();

                $profileStmt = $pdo->prepare(
                    'INSERT INTO staff_profiles (user_id, office, name, phone, status)
                     VALUES (:user_id, :office, :name, :phone, :status)'
                );
                $profileStmt->execute([
                    ':user_id' => $userId,
                    ':office' => $office,
                    ':name' => $name,
                    ':phone' => $phone !== '' ? $phone : null,
                    ':status' => 'active',
                ]);

                $pdo->commit();

                // Best-effort, outside the account-creation transaction: reuse
                // the named General Suggestion Area if it already exists, or
                // create it fresh, then file one starter category under it
                // routed straight to this office - so suggestions can reach
                // the new staff member immediately without a separate trip
                // to Manage Types. Never blocks account creation if anything
                // here goes wrong.
                $wiredArea = false;
                try {
                    $findAreaStmt = $pdo->prepare('SELECT id FROM suggestion_areas WHERE LOWER(name) = LOWER(:name) LIMIT 1');
                    $findAreaStmt->execute([':name' => $suggestionAreaName]);
                    $areaId = (int)$findAreaStmt->fetchColumn();
                    if ($areaId <= 0) {
                        $nextOrderStmt = $pdo->query('SELECT COALESCE(MAX(display_order), 0) + 10 FROM suggestion_areas');
                        $nextOrder = (int)$nextOrderStmt->fetchColumn();
                        $pdo->prepare('INSERT INTO suggestion_areas (name, display_order) VALUES (:name, :display_order)')
                            ->execute([':name' => $suggestionAreaName, ':display_order' => $nextOrder]);
                        $areaId = (int)$pdo->lastInsertId();
                    }

                    $existingWireStmt = $pdo->prepare(
                        "SELECT id FROM suggestion_categories WHERE area_id = :area_id AND route_type = 'office' AND LOWER(TRIM(office)) = LOWER(TRIM(:office)) LIMIT 1"
                    );
                    $existingWireStmt->execute([':area_id' => $areaId, ':office' => $office]);
                    if (!$existingWireStmt->fetchColumn()) {
                        // Not already wired for this office - file the starter category.
                        $catName = $office . ' - General ' . $suggestionAreaName;
                        $nameTakenStmt = $pdo->prepare('SELECT id FROM suggestion_categories WHERE LOWER(name) = LOWER(:name) LIMIT 1');
                        $nameTakenStmt->execute([':name' => $catName]);
                        if (!$nameTakenStmt->fetchColumn()) {
                            $pdo->prepare(
                                "INSERT INTO suggestion_categories (name, area_id, route_type, office, is_active) VALUES (:name, :area_id, 'office', :office, 1)"
                            )->execute([':name' => $catName, ':area_id' => $areaId, ':office' => $office]);
                            $wiredArea = true;
                        }
                    }
                } catch (PDOException $e) {
                    // Ignore - staff account is already created either way.
                }

                $flashMessage = 'Staff account created successfully.';
                if ($wiredArea) {
                    $flashMessage .= " Set up a starter suggestion category under \"{$suggestionAreaName}\" for {$office}.";
                }
                $flashType = 'success';
                $showAddModal = false;
                $newStaffUsername = $username;
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
                    $flashMessage = 'Unable to create staff account right now.';
                }
                $flashType = 'error';
            }
        }
    } elseif ($action === 'toggle_staff') {
        $profileId = (int)($_POST['profile_id'] ?? 0);

        if ($profileId <= 0) {
            $flashMessage = 'Invalid request.';
            $flashType = 'error';
        } else {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare('SELECT user_id, status FROM staff_profiles WHERE id = :id LIMIT 1');
                $stmt->execute([':id' => $profileId]);
                $profile = $stmt->fetch();

                if (!$profile) {
                    throw new RuntimeException('Staff profile not found.');
                }

                $newStatus = strtolower((string)$profile['status']) === 'active' ? 'inactive' : 'active';

                $updateProfile = $pdo->prepare('UPDATE staff_profiles SET status = :status WHERE id = :id');
                $updateProfile->execute([':status' => $newStatus, ':id' => $profileId]);

                $updateUser = $pdo->prepare('UPDATE users SET is_active = :is_active WHERE id = :user_id AND role = :role');
                $updateUser->execute([
                    ':is_active' => $newStatus === 'active' ? 1 : 0,
                    ':user_id' => (int)$profile['user_id'],
                    ':role' => 'staff',
                ]);

                $pdo->commit();

                $flashMessage = $newStatus === 'active' ? 'Staff account activated.' : 'Staff account deactivated.';
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
                $flashMessage = 'Unable to update staff status right now.';
                $flashType = 'error';
            }
        }
    } elseif ($action === 'delete_staff') {
        $profileId = (int)($_POST['profile_id'] ?? 0);

        if ($profileId <= 0) {
            $flashMessage = 'Invalid delete request.';
            $flashType = 'error';
        } else {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare('SELECT user_id FROM staff_profiles WHERE id = :id LIMIT 1');
                $stmt->execute([':id' => $profileId]);
                $profile = $stmt->fetch();

                if (!$profile) {
                    throw new RuntimeException('Staff profile not found.');
                }

                $deleteProfile = $pdo->prepare('DELETE FROM staff_profiles WHERE id = :id');
                $deleteProfile->execute([':id' => $profileId]);

                $deleteUser = $pdo->prepare('DELETE FROM users WHERE id = :user_id AND role = :role');
                $deleteUser->execute([
                    ':user_id' => (int)$profile['user_id'],
                    ':role' => 'staff',
                ]);

                $pdo->commit();

                $flashMessage = 'Staff account removed successfully.';
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
                $flashMessage = 'Unable to remove staff account right now. There may be related records linked to this account.';
                $flashType = 'error';
            }
        }
    }
}

$q = normalize_search_query((string)($_GET['q'] ?? ''));
$staffMembers = [];
$knownOffices = [];

try {
    $sql =
        "SELECT
            sp.id AS profile_id,
            sp.user_id,
            sp.name,
            sp.office,
            sp.phone,
            sp.status,
            u.username,
            u.email,
            u.is_active,
            DATE_FORMAT(sp.created_at, '%b %d, %Y') AS date_added
         FROM staff_profiles sp
         INNER JOIN users u ON u.id = sp.user_id
         WHERE u.role = 'staff'";

    $params = [];

    if ($q !== '') {
        $likeQuery = '%' . escape_like($q) . '%';
        $sql .= " AND (
            COALESCE(sp.name,'') LIKE :q1
            OR u.email LIKE :q2
            OR u.username LIKE :q3
            OR COALESCE(sp.office,'') LIKE :q4
            OR sp.status LIKE :q5
        )";
        $params[':q1'] = $likeQuery;
        $params[':q2'] = $likeQuery;
        $params[':q3'] = $likeQuery;
        $params[':q4'] = $likeQuery;
        $params[':q5'] = $likeQuery;
    }

    $sql .= ' ORDER BY sp.id DESC';

    $staffStmt = $pdo->prepare($sql);
    $staffStmt->execute($params);
    $staffMembers = $staffStmt->fetchAll();

    $officesStmt = $pdo->query(
        "SELECT DISTINCT office FROM staff_profiles WHERE office IS NOT NULL AND TRIM(office) <> '' ORDER BY office ASC"
    );
    $knownOffices = $officesStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    if ($flashMessage === '') {
        $flashMessage = 'Unable to load staff records right now.';
        $flashType = 'error';
    }
}

// For the Add Staff modal's "General Suggestion Areas" checklist.
$suggestionAreasForStaffForm = [];
try {
    $suggestionAreasForStaffForm = $pdo->query(
        'SELECT id, name FROM suggestion_areas WHERE is_active = 1 ORDER BY display_order ASC, name ASC'
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>VOICE - Staff Accounts</title>
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

.btn-add:hover { background: #5b21b6; }

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

/* Staff Profile Cell */
.staff-profile {
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
    flex-shrink: 0;
}

.staff-info { display: flex; flex-direction: column; }
.staff-name { font-weight: 600; color: #333; }
.staff-email { font-size: 11px; color: #888; }

/* Badges */
.status-badge { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
.status-active   { background: #d1fae5; color: #059669; }
.status-inactive { background: #fee2e2; color: #b91c1c; }

.office-badge { background: #e0f2fe; color: #0284c7; padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: 500; }

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

.btn-toggle { background: #f59e0b; }
.btn-toggle:hover { background: #d97706; }
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
    padding: 18px 22px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    position: relative;
    animation: fadeIn 0.3s ease;
    max-height: 96vh;
    overflow-y: auto;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-20px); }
    to   { opacity: 1; transform: translateY(0); }
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}

.modal-header h3 { font-size: 17px; color: #333; }
.close-btn { font-size: 22px; color: #888; cursor: pointer; transition: 0.2s; }
.close-btn:hover { color: #ef4444; }

.form-row { display: flex; gap: 12px; }
.form-row .form-group { flex: 1; }

.form-group { margin-bottom: 10px; }
.form-group label { display: block; font-size: 12.5px; color: #555; margin-bottom: 4px; font-weight: 500; }
.form-group input,
.form-group select {
    width: 100%; padding: 8px 12px; border: 1px solid #ddd;
    border-radius: 8px; font-size: 12.5px; color: #333; outline: none; transition: 0.2s;
}
.form-group input:focus,
.form-group select:focus { border-color: #4F8CFF; }

.area-checklist-hint { margin: 4px 0 0; font-size: 10.5px; color: #9ca3af; line-height: 1.4; }

.modal-footer { display: flex; justify-content: flex-end; gap: 10px; margin-top: 14px; }
.btn-cancel { padding: 9px 18px; border: none; border-radius: 8px; font-size: 12.5px; cursor: pointer; background: #f4f6fb; color: #555; }
.btn-cancel:hover { background: #e5e7eb; }
.btn-submit { padding: 9px 18px; border: none; border-radius: 8px; font-size: 12.5px; cursor: pointer; background: #4F8CFF; color: #fff; font-weight: 500; }
.btn-submit:hover { background: #3b6fd1; }

.empty-row {
    text-align: center;
    color: #9ca3af;
    padding: 20px 0;
}

/* Confirmation card (toggle / delete) */
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

.btn-warning {
    padding: 10px 16px;
    border: none;
    border-radius: 8px;
    background: #f59e0b;
    color: #fff;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
}

.btn-warning:hover {
    background: #d97706;
}
</style>
</head>

<body>

<?php if ($flashMessage !== ''): ?>
<div class="flash-popup-overlay show" id="flashPopup">
    <div class="flash-popup-card <?php echo $flashType === 'success' ? 'flash-popup-success' : 'flash-popup-error'; ?>">
        <h3 class="flash-popup-title"><?php echo $flashType === 'success' ? 'Success' : 'Action Failed'; ?></h3>
        <div class="flash-popup-message"><?php echo e($flashMessage); ?></div>
        <?php if ($newStaffUsername !== ''): ?>
            <div class="flash-popup-username">Generated username: <?php echo e($newStaffUsername); ?></div>
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
            <h2>Staff Accounts</h2>
        </div>

        <div class="controls-card">
            <form method="GET" class="search-form">
                <div class="search-box">
                    <i class='bx bx-search'></i>
                    <input type="text" name="q" value="<?php echo e($q); ?>" placeholder="Search by Name, Email, Username, or Office...">
                </div>
                <button type="submit" class="btn-search">Search</button>
                <?php if ($q !== ''): ?>
                    <a href="admin_staff.php" class="btn-clear">Clear</a>
                <?php endif; ?>
            </form>

            <button class="btn-add" type="button" onclick="openModal()">
                <i class='bx bx-user-plus'></i> Add New Staff
            </button>
        </div>

        <div class="table-card">
            <table>
                <thead>
                    <tr>
                        <th>Profile</th>
                        <th>Office</th>
                        <th>Date Added</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($staffMembers)): ?>
                        <tr>
                            <td colspan="5" class="empty-row">No staff records found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($staffMembers as $row): ?>
                            <?php
                                $name = trim((string)($row['name'] ?? ''));
                                $email = (string)($row['email'] ?? '');
                                $status = strtolower(trim((string)($row['status'] ?? 'inactive')));
                                $isActive = $status === 'active';
                                $initials = strtoupper(substr($name !== '' ? $name : 'ST', 0, 2));
                                $dateAdded = trim((string)($row['date_added'] ?? ''));
                                $office = (string)($row['office'] ?? '');
                            ?>
                            <tr>
                                <td>
                                    <div class="staff-profile">
                                        <span class="avatar-circle"><?php echo e($initials); ?></span>
                                        <div class="staff-info">
                                            <span class="staff-name"><?php echo e($name !== '' ? $name : 'Unnamed Staff'); ?></span>
                                            <span class="staff-email"><?php echo e($email); ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($office !== ''): ?>
                                        <span class="office-badge"><?php echo e($office); ?></span>
                                    <?php else: ?>
                                        <span class="office-badge" style="background:#f3f4f6;color:#6b7280;">Unassigned</span>
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
                                        <form method="POST" style="display:inline;" onsubmit="return openToggleModal(event, this, '<?php echo e($name !== '' ? $name : 'this staff account'); ?>', <?php echo $isActive ? 'true' : 'false'; ?>);">
                                            <input type="hidden" name="csrf_token" value="<?php echo e((string)$_SESSION['csrf_token']); ?>">
                                            <input type="hidden" name="action" value="toggle_staff">
                                            <input type="hidden" name="profile_id" value="<?php echo (int)$row['profile_id']; ?>">
                                            <button type="submit" class="btn-icon btn-toggle" title="<?php echo $isActive ? 'Deactivate' : 'Activate'; ?> staff">
                                                <i class='bx <?php echo $isActive ? 'bx-power-off' : 'bx-check'; ?>'></i>
                                            </button>
                                        </form>
                                        <form method="POST" style="display:inline;" onsubmit="return openDeleteModal(event, this, '<?php echo e($name !== '' ? $name : 'this staff account'); ?>');">
                                            <input type="hidden" name="csrf_token" value="<?php echo e((string)$_SESSION['csrf_token']); ?>">
                                            <input type="hidden" name="action" value="delete_staff">
                                            <input type="hidden" name="profile_id" value="<?php echo (int)$row['profile_id']; ?>">
                                            <button type="submit" class="btn-icon btn-delete" title="Delete staff">
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

<!-- ADD STAFF MODAL -->
<div class="modal-overlay<?php echo $showAddModal ? ' show' : ''; ?>" id="addStaffModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Add New Staff</h3>
            <i class='bx bx-x close-btn' onclick="closeModal()"></i>
        </div>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo e((string)$_SESSION['csrf_token']); ?>">
            <input type="hidden" name="action" value="add_staff">

            <div class="form-group">
                <label>Full Name</label>
                <input type="text" name="name" placeholder="e.g. Juan Dela Cruz" value="<?php echo e((string)($_POST['name'] ?? '')); ?>" required>
            </div>

            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" placeholder="staff@voice.edu" value="<?php echo e((string)($_POST['email'] ?? '')); ?>" required>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Temporary Password</label>
                    <input type="password" name="password" placeholder="Set initial password" required>
                </div>
                <div class="form-group">
                    <label>Phone (Optional)</label>
                    <input type="text" name="phone" placeholder="e.g. 09171234567" value="<?php echo e((string)($_POST['phone'] ?? '')); ?>">
                </div>
            </div>

            <div class="form-group">
                <label>Office</label>
                <input type="text" name="office" list="knownOfficesList" placeholder="e.g. ICT, Library, Registrar" value="<?php echo e((string)($_POST['office'] ?? '')); ?>" required>
                <datalist id="knownOfficesList">
                    <?php foreach ($knownOffices as $officeOption): ?>
                        <option value="<?php echo e((string)$officeOption); ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>

            <div class="form-group">
                <label>General Suggestion Area for this office</label>
                <input type="text" name="suggestion_area_text" list="knownAreasList" placeholder="e.g. Technology &amp; Internet" value="<?php echo e((string)($_POST['suggestion_area_text'] ?? '')); ?>" required>
                <datalist id="knownAreasList">
                    <?php foreach ($suggestionAreasForStaffForm as $areaOption): ?>
                        <option value="<?php echo e((string)$areaOption['name']); ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn-submit">Save Staff</button>
            </div>
        </form>
    </div>
</div>

<!-- DELETE CONFIRMATION CARD -->
<div class="confirm-modal-overlay" id="deleteConfirmModal">
    <div class="confirm-modal-card">
        <h3 class="confirm-modal-title">Remove Staff</h3>
        <p class="confirm-modal-text" id="deleteConfirmText">Are you sure you want to remove this staff account?</p>
        <div class="confirm-modal-actions">
            <button type="button" class="btn-cancel" onclick="closeDeleteModal()">Cancel</button>
            <button type="button" class="btn-danger" onclick="confirmDeleteStaff()">Remove Staff</button>
        </div>
    </div>
</div>

<!-- TOGGLE STATUS CONFIRMATION CARD -->
<div class="confirm-modal-overlay" id="toggleConfirmModal">
    <div class="confirm-modal-card">
        <h3 class="confirm-modal-title" id="toggleConfirmTitle">Update Staff Status</h3>
        <p class="confirm-modal-text" id="toggleConfirmText">Are you sure you want to update this staff account?</p>
        <div class="confirm-modal-actions">
            <button type="button" class="btn-cancel" onclick="closeToggleModal()">Cancel</button>
            <button type="button" class="btn-warning" onclick="confirmToggleStaff()">Confirm</button>
        </div>
    </div>
</div>

<script>
function openModal() {
    document.getElementById('addStaffModal').classList.add('show');
}

function closeModal() {
    document.getElementById('addStaffModal').classList.remove('show');
}

function closeFlashPopup() {
    const popup = document.getElementById('flashPopup');
    if (popup) {
        popup.classList.remove('show');
    }
}

let deleteTargetForm = null;

function openDeleteModal(event, form, staffName) {
    event.preventDefault();
    deleteTargetForm = form;

    const modal = document.getElementById('deleteConfirmModal');
    const text = document.getElementById('deleteConfirmText');
    const safeName = staffName && staffName.trim() !== '' ? staffName : 'this staff account';
    text.textContent = 'Are you sure you want to remove ' + safeName + '?';
    modal.classList.add('show');
    return false;
}

function closeDeleteModal() {
    const modal = document.getElementById('deleteConfirmModal');
    modal.classList.remove('show');
    deleteTargetForm = null;
}

function confirmDeleteStaff() {
    if (deleteTargetForm) {
        deleteTargetForm.submit();
    }
}

let toggleTargetForm = null;

function openToggleModal(event, form, staffName, isActive) {
    event.preventDefault();
    toggleTargetForm = form;

    const modal = document.getElementById('toggleConfirmModal');
    const title = document.getElementById('toggleConfirmTitle');
    const text = document.getElementById('toggleConfirmText');
    const safeName = staffName && staffName.trim() !== '' ? staffName : 'this staff account';
    const verb = isActive ? 'deactivate' : 'activate';
    title.textContent = isActive ? 'Deactivate Staff' : 'Activate Staff';
    text.textContent = 'Are you sure you want to ' + verb + ' ' + safeName + '?';
    modal.classList.add('show');
    return false;
}

function closeToggleModal() {
    const modal = document.getElementById('toggleConfirmModal');
    modal.classList.remove('show');
    toggleTargetForm = null;
}

function confirmToggleStaff() {
    if (toggleTargetForm) {
        toggleTargetForm.submit();
    }
}

window.addEventListener('click', function(event) {
    var modal = document.getElementById('addStaffModal');
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

    var toggleModal = document.getElementById('toggleConfirmModal');
    if (event.target === toggleModal) {
        closeToggleModal();
    }
});
</script>

</body>
</html>
