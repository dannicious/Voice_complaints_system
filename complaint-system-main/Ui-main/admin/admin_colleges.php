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

require_admin_session($pdo);

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$flashMessage = '';
$flashType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = trim((string)($_POST['action'] ?? ''));
    $token = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $flashMessage = 'Invalid request token. Please refresh and try again.';
        $flashType = 'error';
    } else {
        try {
            if ($action === 'add_college') {
                $code = strtoupper(trim((string)($_POST['code'] ?? '')));
                $name = trim((string)($_POST['name'] ?? ''));

                if ($code === '' || $name === '') {
                    $flashMessage = 'College code and name are required.';
                    $flashType = 'error';
                } else {
                    $stmt = $pdo->prepare(
                        'INSERT INTO colleges (code, name, status)
                         VALUES (:code, :name, :status)'
                    );
                    $stmt->execute([
                        ':code' => $code,
                        ':name' => $name,
                        ':status' => 'active',
                    ]);

                    $flashMessage = 'College added successfully.';
                    $flashType = 'success';
                }
            } elseif ($action === 'add_program') {
                $collegeId = (int)($_POST['college_id'] ?? 0);
                $programCode = strtoupper(trim((string)($_POST['prog_code'] ?? '')));
                $programName = trim((string)($_POST['prog_name'] ?? ''));

                if ($collegeId <= 0 || $programCode === '' || $programName === '') {
                    $flashMessage = 'Please provide a valid college and program details.';
                    $flashType = 'error';
                } else {
                    $collegeStmt = $pdo->prepare('SELECT id FROM colleges WHERE id = :id LIMIT 1');
                    $collegeStmt->execute([':id' => $collegeId]);
                    if (!$collegeStmt->fetch()) {
                        $flashMessage = 'Selected college does not exist.';
                        $flashType = 'error';
                    } else {
                        $progStmt = $pdo->prepare(
                            'INSERT INTO programs (college_id, code, name, status)
                             VALUES (:college_id, :code, :name, :status)'
                        );
                        $progStmt->execute([
                            ':college_id' => $collegeId,
                            ':code' => $programCode,
                            ':name' => $programName,
                            ':status' => 'active',
                        ]);

                        $flashMessage = 'Program added successfully.';
                        $flashType = 'success';
                    }
                }
            } elseif ($action === 'update_program') {
                $programId = (int)($_POST['program_id'] ?? 0);
                $collegeId = (int)($_POST['college_id'] ?? 0);
                $programCode = strtoupper(trim((string)($_POST['prog_code'] ?? '')));
                $programName = trim((string)($_POST['prog_name'] ?? ''));
                $status = trim((string)($_POST['status'] ?? 'active'));

                if ($programId <= 0 || $collegeId <= 0 || $programCode === '' || $programName === '') {
                    $flashMessage = 'Program ID, college, code, and name are required.';
                    $flashType = 'error';
                } elseif (!in_array($status, ['active', 'inactive'], true)) {
                    $flashMessage = 'Invalid program status.';
                    $flashType = 'error';
                } else {
                    $programStmt = $pdo->prepare('SELECT id FROM programs WHERE id = :id LIMIT 1');
                    $programStmt->execute([':id' => $programId]);
                    if (!$programStmt->fetch()) {
                        $flashMessage = 'Selected program does not exist.';
                        $flashType = 'error';
                    } else {
                        $collegeStmt = $pdo->prepare('SELECT id FROM colleges WHERE id = :id LIMIT 1');
                        $collegeStmt->execute([':id' => $collegeId]);
                        if (!$collegeStmt->fetch()) {
                            $flashMessage = 'Selected college does not exist.';
                            $flashType = 'error';
                        } else {
                            $updateProgram = $pdo->prepare(
                                'UPDATE programs
                                 SET college_id = :college_id, code = :code, name = :name, status = :status
                                 WHERE id = :id'
                            );
                            $updateProgram->execute([
                                ':college_id' => $collegeId,
                                ':code' => $programCode,
                                ':name' => $programName,
                                ':status' => $status,
                                ':id' => $programId,
                            ]);

                            $flashMessage = 'Program updated successfully.';
                            $flashType = 'success';
                        }
                    }
                }
            } elseif ($action === 'update_college') {
                $collegeId = (int)($_POST['college_id'] ?? 0);
                $code = strtoupper(trim((string)($_POST['code'] ?? '')));
                $name = trim((string)($_POST['name'] ?? ''));
                $status = trim((string)($_POST['status'] ?? 'active'));
                $deanUserId = (int)($_POST['dean_user_id'] ?? 0);

                if ($collegeId <= 0 || $code === '' || $name === '') {
                    $flashMessage = 'College ID, code, and name are required.';
                    $flashType = 'error';
                } elseif (!in_array($status, ['active', 'inactive'], true)) {
                    $flashMessage = 'Invalid college status.';
                    $flashType = 'error';
                } else {
                    $collegeStmt = $pdo->prepare('SELECT id FROM colleges WHERE id = :id LIMIT 1');
                    $collegeStmt->execute([':id' => $collegeId]);

                    if (!$collegeStmt->fetch()) {
                        $flashMessage = 'Selected college does not exist.';
                        $flashType = 'error';
                    } else {
                        if ($deanUserId > 0) {
                            $deanCheck = $pdo->prepare(
                                'SELECT id, username
                                 FROM users
                                 WHERE id = :id AND role = :role
                                 LIMIT 1'
                            );
                            $deanCheck->execute([
                                ':id' => $deanUserId,
                                ':role' => 'dean',
                            ]);

                            $deanUser = $deanCheck->fetch();
                            if (!$deanUser) {
                                $flashMessage = 'Selected dean is invalid.';
                                $flashType = 'error';
                            }
                        }

                        if ($flashMessage === '') {
                            $pdo->beginTransaction();

                            $updateCollege = $pdo->prepare(
                                'UPDATE colleges
                                 SET code = :code, name = :name, status = :status
                                 WHERE id = :id'
                            );
                            $updateCollege->execute([
                                ':code' => $code,
                                ':name' => $name,
                                ':status' => $status,
                                ':id' => $collegeId,
                            ]);

                            if ($deanUserId > 0) {
                                $profileLookup = $pdo->prepare(
                                    'SELECT id
                                     FROM dean_profiles
                                     WHERE user_id = :user_id
                                     LIMIT 1'
                                );
                                $profileLookup->execute([':user_id' => $deanUserId]);
                                $profileRow = $profileLookup->fetch();
                                $deanProfileId = (int)($profileRow['id'] ?? 0);

                                if ($deanProfileId <= 0) {
                                    $insertProfile = $pdo->prepare(
                                        'INSERT INTO dean_profiles (user_id, first_name, last_name, college_id, status)
                                         VALUES (:user_id, :first_name, :last_name, :college_id, :status)'
                                    );
                                    $insertProfile->execute([
                                        ':user_id' => $deanUserId,
                                        ':first_name' => (string)($deanUser['username'] ?? 'Dean'),
                                        ':last_name' => '',
                                        ':college_id' => null,
                                        ':status' => 'active',
                                    ]);
                                    $deanProfileId = (int)$pdo->lastInsertId();
                                }

                                // Ensure only one dean stays assigned per college.
                                $unassignCollege = $pdo->prepare(
                                    'UPDATE dean_profiles
                                     SET college_id = NULL
                                     WHERE college_id = :college_id AND id <> :dean_id'
                                );
                                $unassignCollege->execute([
                                    ':college_id' => $collegeId,
                                    ':dean_id' => $deanProfileId,
                                ]);

                                // Move selected dean to this college.
                                $assignDean = $pdo->prepare(
                                    'UPDATE dean_profiles
                                     SET college_id = :college_id
                                     WHERE id = :dean_id'
                                );
                                $assignDean->execute([
                                    ':college_id' => $collegeId,
                                    ':dean_id' => $deanProfileId,
                                ]);
                            } else {
                                // Explicitly remove assignment when no dean selected.
                                $clearDean = $pdo->prepare(
                                    'UPDATE dean_profiles
                                     SET college_id = NULL
                                     WHERE college_id = :college_id'
                                );
                                $clearDean->execute([':college_id' => $collegeId]);
                            }

                            $pdo->commit();

                            $flashMessage = 'College updated successfully.';
                            $flashType = 'success';
                        }
                    }
                }
            } else {
                $flashMessage = 'Invalid action request.';
                $flashType = 'error';
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ((string)$e->getCode() === '23000') {
                $flashMessage = 'Duplicate code detected. College/program code already exists.';
                $flashType = 'error';
            } else {
                $flashMessage = 'Unable to save changes right now.';
                $flashType = 'error';
            }
        }
    }
}

$colleges = [];
$deanOptions = [];

try {
    $collegeStmt = $pdo->query(
    "SELECT c.id, c.code, c.name, c.status,
        dp.id AS dean_profile_id,
        dp.user_id AS dean_user_id,
                CONCAT(dp.first_name, ' ', dp.last_name) AS dean_name
         FROM colleges c
         LEFT JOIN dean_profiles dp ON dp.college_id = c.id AND dp.status = 'active'
         ORDER BY c.name ASC"
    );
    $collegeRows = $collegeStmt->fetchAll();

    $programStmt = $pdo->query(
        "SELECT id, college_id, code, name, status
         FROM programs
         ORDER BY name ASC"
    );
    $programRows = $programStmt->fetchAll();

    $deansStmt = $pdo->query(
        "SELECT u.id AS user_id,
                u.username,
                dp.id AS profile_id,
                COALESCE(dp.first_name, u.username) AS first_name,
                COALESCE(dp.last_name, '') AS last_name,
                COALESCE(dp.status, CASE WHEN u.is_active = 1 THEN 'active' ELSE 'inactive' END) AS status,
                dp.college_id,
                c.code AS college_code
         FROM users u
         LEFT JOIN dean_profiles dp ON dp.user_id = u.id
         LEFT JOIN colleges c ON c.id = dp.college_id
         WHERE u.role = 'dean'
         ORDER BY first_name ASC, last_name ASC"
    );
    $deanOptions = $deansStmt->fetchAll();

    $programsByCollege = [];
    foreach ($programRows as $program) {
        $cid = (int)$program['college_id'];
        if (!isset($programsByCollege[$cid])) {
            $programsByCollege[$cid] = [];
        }
        $programsByCollege[$cid][] = $program;
    }

    foreach ($collegeRows as $college) {
        $cid = (int)$college['id'];
        $college['programs'] = $programsByCollege[$cid] ?? [];
        $colleges[] = $college;
    }
} catch (PDOException $e) {
    if ($flashMessage === '') {
        $flashMessage = 'Unable to load colleges/programs. Please check your database tables.';
        $flashType = 'error';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>VOICE - Colleges & Programs</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>

<style>
* { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
body { background: #f4f6fb; }

/* ===== MAIN CONTENT ===== */
.main {
    margin-left: 260px;
    margin-top: 61px;
    padding: 25px;
    min-height: calc(100vh - 61px);
}

.dashboard-container { max-width: 1200px; margin: 0 auto; width: 100%; }

/* ===== PAGE HEADER ===== */
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
.page-header h2 { font-size: 24px; font-weight: 600; color: #333; }

/* CONTROLS BAR */
.controls-card {
    background: #fff; padding: 20px; border-radius: 12px; margin-bottom: 25px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.03);
    display: flex; justify-content: space-between; align-items: center; gap: 15px; flex-wrap: wrap;
}

.search-box {
    display: flex; align-items: center; background: #f4f6fb; padding: 8px 15px;
    border-radius: 8px; width: 100%; max-width: 300px; border: 1px solid #e5e7eb;
}
.search-box input { border: none; background: transparent; outline: none; width: 100%; font-size: 13px; }

.btn-add {
    display: flex; align-items: center; gap: 6px; padding: 10px 20px; border: none;
    border-radius: 8px; font-size: 14px; background: #6d28d9; color: #fff; cursor: pointer;
}

/* TABLE & PROGRAM TAGS */
.table-card { background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); overflow-x: auto; }
table { width: 100%; border-collapse: collapse; min-width: 900px; }
th { text-align: left; font-size: 13px; color: #888; padding-bottom: 15px; border-bottom: 1px solid #f0f0f0; }
td { padding: 15px 0; font-size: 13px; color: #444; border-bottom: 1px solid #f9f9f9; }

.program-list { display: flex; flex-wrap: wrap; gap: 5px; max-width: 300px; }
.program-item {
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.prog-pill { 
    background: #eff6ff; color: #3b82f6; padding: 2px 8px; 
    border-radius: 4px; font-size: 11px; font-weight: 600; border: 1px solid #dbeafe;
}
.prog-pill-btn {
    width: 20px;
    height: 20px;
    border: none;
    border-radius: 4px;
    background: #f59e0b;
    color: #fff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 12px;
}

.status-badge { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
.status-active { background: #d1fae5; color: #059669; }

.action-btns { display: flex; gap: 8px; }
.btn-icon {
    display: inline-flex; align-items: center; justify-content: center;
    width: 30px; height: 30px; border-radius: 6px; color: #fff; text-decoration: none; font-size: 16px;
}
.btn-prog { background: #10b981; cursor: pointer; border: none; }
.btn-edit { background: #f59e0b; }
.btn-delete { background: #ef4444; }

/* ===== MODAL ===== */
.modal-overlay {
    position: fixed; top: 0; left: 0; width: 100%; height: 100%; box-sizing: border-box; padding: 16px;
    background: rgba(0,0,0,0.5); display: none; align-items: center; justify-content: center; z-index: 1100;
}
.modal-content { background: #fff; width: 450px; max-width: 100%; max-height: 90vh; overflow-y: auto; border-radius: 12px; padding: 25px; animation: fadeIn 0.3s ease; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }

.form-group { margin-bottom: 15px; }
.form-group label { display: block; font-size: 13px; color: #555; margin-bottom: 5px; font-weight: 500; }
.form-group input { width: 100%; padding: 10px 15px; border: 1px solid #ddd; border-radius: 8px; outline: none; }
.form-group select { width: 100%; padding: 10px 15px; border: 1px solid #ddd; border-radius: 8px; outline: none; background: #fff; }

.modal-footer { display: flex; justify-content: flex-end; gap: 10px; margin-top: 25px; }
.btn-cancel { padding: 10px 20px; border: none; border-radius: 8px; cursor: pointer; background: #f4f6fb; }
.btn-submit { padding: 10px 20px; border: none; border-radius: 8px; cursor: pointer; background: #4F8CFF; color: #fff; }

.flash-msg {
    margin-bottom: 14px;
    padding: 10px 12px;
    border-radius: 8px;
    font-size: 12.5px;
    font-weight: 500;
}

.flash-success {
    background: #e8f9f0;
    border: 1px solid #b7ebce;
    color: #1f7a45;
}

.flash-error {
    background: #fff1f1;
    border: 1px solid #ffd1d1;
    color: #b42318;
}

.status-inactive { background: #fee2e2; color: #b91c1c; }

@media (max-width: 1024px) {
    .main { margin-left: 0 !important; padding: 16px !important; }
}

@media (max-width: 640px) {
    .page-header { flex-wrap: wrap; gap: 12px; }
    .search-box { max-width: 100%; }
    .program-list { max-width: 100%; }
}
</style>
</head>

<body>

<?php include 'admin_topbar.php'; ?>
<?php include 'admin_sidebar.php'; ?>

<div class="main">
    <div class="dashboard-container">
        <div class="page-header">
            <h2>Colleges & Programs Management</h2>
        </div>

        <?php if ($flashMessage !== ''): ?>
            <div class="flash-msg <?php echo $flashType === 'success' ? 'flash-success' : 'flash-error'; ?>">
                <?php echo e($flashMessage); ?>
            </div>
        <?php endif; ?>

        <div class="controls-card">
            <div class="search-box">
                <i class='bx bx-search'></i>
                <input type="text" placeholder="Search for programs or colleges...">
            </div>
            <button class="btn-add" onclick="openModal('addCollegeModal')">
                <i class='bx bx-plus-circle'></i> Add New College
            </button>
        </div>

        <div class="table-card">
            <table>
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>College Name</th>
                        <th>Programs Offered</th>
                        <th>Dean</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($colleges) === 0): ?>
                        <tr>
                            <td colspan="6">No colleges yet. Add your first college and then programs.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($colleges as $college): ?>
                            <tr>
                                <td><strong><?php echo e((string)$college['code']); ?></strong></td>
                                <td><?php echo e((string)$college['name']); ?></td>
                                <td>
                                    <div class="program-list">
                                        <?php if (count($college['programs']) === 0): ?>
                                            <span style="font-size:12px; color:#6b7280;">No programs yet</span>
                                        <?php else: ?>
                                            <?php foreach ($college['programs'] as $program): ?>
                                                <span class="program-item" title="<?php echo e((string)$program['name']); ?>">
                                                    <span class="prog-pill"><?php echo e((string)$program['code']); ?></span>
                                                    <button type="button" class="prog-pill-btn" title="Edit Program"
                                                            data-program-id="<?php echo (int)$program['id']; ?>"
                                                            data-college-id="<?php echo (int)$program['college_id']; ?>"
                                                            data-code="<?php echo e((string)$program['code']); ?>"
                                                            data-name="<?php echo e((string)$program['name']); ?>"
                                                            data-status="<?php echo e((string)$program['status']); ?>"
                                                            onclick="openEditProgramModal(this)">
                                                        <i class='bx bx-edit'></i>
                                                    </button>
                                                </span>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td><?php echo e(trim((string)($college['dean_name'] ?? '')) !== '' ? (string)$college['dean_name'] : 'Not Assigned'); ?></td>
                                <td>
                                    <?php $isActive = (string)$college['status'] === 'active'; ?>
                                    <span class="status-badge <?php echo $isActive ? 'status-active' : 'status-inactive'; ?>">
                                        <?php echo $isActive ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-btns">
                                        <button class="btn-icon btn-prog" onclick="openProgramModal(<?php echo (int)$college['id']; ?>, '<?php echo e((string)$college['code']); ?>')" title="Add Program">
                                            <i class='bx bx-list-plus'></i>
                                        </button>
                                        <button class="btn-icon btn-edit" type="button"
                                                data-college-id="<?php echo (int)$college['id']; ?>"
                                                data-code="<?php echo e((string)$college['code']); ?>"
                                                data-name="<?php echo e((string)$college['name']); ?>"
                                                data-status="<?php echo e((string)$college['status']); ?>"
                                                data-dean-id="<?php echo (int)($college['dean_user_id'] ?? 0); ?>"
                                                onclick="openEditCollegeModal(this)" title="Edit College">
                                            <i class='bx bx-edit'></i>
                                        </button>
                                        <a href="#" class="btn-icon btn-delete" title="Delete coming soon"><i class='bx bx-trash'></i></a>
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

<div class="modal-overlay" id="editCollegeModal">
    <div class="modal-content">
        <div class="modal-header" style="display:flex; justify-content:space-between;">
            <h3>Edit College</h3>
            <i class='bx bx-x' style="cursor:pointer; font-size:24px;" onclick="closeModal('editCollegeModal')"></i>
        </div>
        <form action="" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="action" value="update_college">
            <input type="hidden" name="college_id" id="editCollegeId">

            <div class="form-group">
                <label>College Code</label>
                <input type="text" name="code" id="editCollegeCode" required>
            </div>

            <div class="form-group">
                <label>College Name</label>
                <input type="text" name="name" id="editCollegeName" required>
            </div>

            <div class="form-group">
                <label>Status</label>
                <select name="status" id="editCollegeStatus" required>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>

            <div class="form-group">
                <label>Assign Dean</label>
                <select name="dean_user_id" id="editCollegeDean">
                    <option value="0">-- Not Assigned --</option>
                    <?php foreach ($deanOptions as $dean): ?>
                        <?php
                            $deanName = trim((string)$dean['first_name'] . ' ' . (string)$dean['last_name']);
                            $assignedCode = trim((string)($dean['college_code'] ?? ''));
                            $label = $deanName;
                            if ((int)($dean['profile_id'] ?? 0) <= 0) {
                                $label .= ' (No profile yet)';
                            }
                            if ($assignedCode !== '') {
                                $label .= ' (Assigned: ' . $assignedCode . ')';
                            }
                        ?>
                        <option value="<?php echo (int)$dean['user_id']; ?>"><?php echo e($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('editCollegeModal')">Cancel</button>
                <button type="submit" class="btn-submit" style="background:#f59e0b;">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="addCollegeModal">
    <div class="modal-content">
        <div class="modal-header" style="display:flex; justify-content:space-between;">
            <h3>New College</h3>
            <i class='bx bx-x' style="cursor:pointer; font-size:24px;" onclick="closeModal('addCollegeModal')"></i>
        </div>
        <form action="" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="action" value="add_college">
            <div class="form-group">
                <label>College Code</label>
                <input type="text" name="code" placeholder="e.g. CTAS" required>
            </div>
            <div class="form-group">
                <label>College Name</label>
                <input type="text" name="name" placeholder="Full name of college" required>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('addCollegeModal')">Cancel</button>
                <button type="submit" class="btn-submit">Save College</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="addProgramModal">
    <div class="modal-content">
        <div class="modal-header" style="display:flex; justify-content:space-between;">
            <h3>Add Program to <span id="displayCollegeCode" style="color:#4F8CFF;"></span></h3>
            <i class='bx bx-x' style="cursor:pointer; font-size:24px;" onclick="closeModal('addProgramModal')"></i>
        </div>
        <form action="" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="action" value="add_program">
            <input type="hidden" name="college_id" id="hiddenCollegeId">

            <div class="form-group">
                <label>Program Code</label>
                <input type="text" name="prog_code" placeholder="e.g. BSIT" required>
            </div>
            <div class="form-group">
                <label>Program Description</label>
                <input type="text" name="prog_name" placeholder="e.g. Bachelor of Science in IT" required>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('addProgramModal')">Cancel</button>
                <button type="submit" class="btn-submit" style="background:#10b981;">Save Program</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="editProgramModal">
    <div class="modal-content">
        <div class="modal-header" style="display:flex; justify-content:space-between;">
            <h3>Edit Program</h3>
            <i class='bx bx-x' style="cursor:pointer; font-size:24px;" onclick="closeModal('editProgramModal')"></i>
        </div>
        <form action="" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="action" value="update_program">
            <input type="hidden" name="program_id" id="editProgramId">

            <div class="form-group">
                <label>College</label>
                <select name="college_id" id="editProgramCollege" required>
                    <?php foreach ($colleges as $collegeOption): ?>
                        <option value="<?php echo (int)$collegeOption['id']; ?>"><?php echo e((string)$collegeOption['code'] . ' - ' . (string)$collegeOption['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Program Code</label>
                <input type="text" name="prog_code" id="editProgramCode" required>
            </div>

            <div class="form-group">
                <label>Program Description</label>
                <input type="text" name="prog_name" id="editProgramName" required>
            </div>

            <div class="form-group">
                <label>Status</label>
                <select name="status" id="editProgramStatus" required>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('editProgramModal')">Cancel</button>
                <button type="submit" class="btn-submit" style="background:#f59e0b;">Save Program</button>
            </div>
        </form>
    </div>
</div>

<script>
// Generic open/close functions
function openModal(id) {
    document.getElementById(id).style.display = 'flex';
}
function closeModal(id) {
    document.getElementById(id).style.display = 'none';
}

// Special function for the Program Modal
function openProgramModal(collegeId, collegeCode) {
    // 1. Set the hidden ID field so PHP knows which college this belongs to
    document.getElementById('hiddenCollegeId').value = collegeId;
    
    // 2. Set the text in the modal header so the user knows what they're doing
    document.getElementById('displayCollegeCode').innerText = collegeCode;
    
    // 3. Show the modal
    openModal('addProgramModal');
}

function openEditCollegeModal(button) {
    document.getElementById('editCollegeId').value = button.dataset.collegeId || '';
    document.getElementById('editCollegeCode').value = button.dataset.code || '';
    document.getElementById('editCollegeName').value = button.dataset.name || '';
    document.getElementById('editCollegeStatus').value = button.dataset.status || 'active';
    document.getElementById('editCollegeDean').value = button.dataset.deanId || '0';

    openModal('editCollegeModal');
}

function openEditProgramModal(button) {
    document.getElementById('editProgramId').value = button.dataset.programId || '';
    document.getElementById('editProgramCollege').value = button.dataset.collegeId || '';
    document.getElementById('editProgramCode').value = button.dataset.code || '';
    document.getElementById('editProgramName').value = button.dataset.name || '';
    document.getElementById('editProgramStatus').value = button.dataset.status || 'active';

    openModal('editProgramModal');
}

// Close modal when clicking outside the box
window.onclick = function(event) {
    if (event.target.className === 'modal-overlay') {
        event.target.style.display = 'none';
    }
}
</script>

</body>
</html>