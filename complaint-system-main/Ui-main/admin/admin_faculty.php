<?php
session_start();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../faculty_helpers.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /complaint-system-main/admin/login.php');
    exit;
}

if ((string)($_SESSION['role'] ?? '') !== 'admin') {
    $roleStmt = $pdo->prepare('SELECT role FROM users WHERE id = :id LIMIT 1');
    $roleStmt->execute([':id' => (int)$_SESSION['user_id']]);
    $roleRow = $roleStmt->fetch(PDO::FETCH_ASSOC);
    if (!$roleRow || (string)$roleRow['role'] !== 'admin') {
        header('Location: /complaint-system-main/admin/login.php');
        exit;
    }
    $_SESSION['role'] = 'admin';
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!function_exists('e')) {
    function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

ensure_faculty_tables($pdo);

$flashMessage = trim((string)($_GET['msg'] ?? ''));
$flashType = (string)($_GET['status'] ?? '');
if (!in_array($flashType, ['success', 'error'], true)) {
    $flashType = '';
    $flashMessage = '';
}

function redirect_faculty(string $type, string $msg): void
{
    header('Location: admin_faculty.php?' . http_build_query(['status' => $type, 'msg' => $msg]));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));

    if (!hash_equals((string)$_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
        redirect_faculty('error', 'Invalid request token. Please refresh and try again.');
    }

    try {
        if ($action === 'add_faculty') {
            $firstName = trim((string)($_POST['first_name'] ?? ''));
            $lastName = trim((string)($_POST['last_name'] ?? ''));
            $employeeNumber = trim((string)($_POST['employee_number'] ?? ''));
            $position = trim((string)($_POST['position'] ?? ''));
            $department = trim((string)($_POST['department'] ?? ''));
            $collegeId = (int)($_POST['college_id'] ?? 0);
            $email = trim((string)($_POST['email'] ?? ''));
            $contactNumber = trim((string)($_POST['contact_number'] ?? ''));

            if ($firstName === '' || $lastName === '') {
                redirect_faculty('error', 'First name and last name are required.');
            }

            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                redirect_faculty('error', 'Please provide a valid email address.');
            }

            if ($employeeNumber !== '') {
                $dupStmt = $pdo->prepare('SELECT id FROM faculty_staff WHERE employee_number = :employee_number LIMIT 1');
                $dupStmt->execute([':employee_number' => $employeeNumber]);
                if ($dupStmt->fetch()) {
                    redirect_faculty('error', 'That employee number is already on record.');
                }
            }

            $insertStmt = $pdo->prepare(
                'INSERT INTO faculty_staff
                    (employee_number, first_name, last_name, position, department, college_id, email, contact_number, status)
                 VALUES
                    (:employee_number, :first_name, :last_name, :position, :department, :college_id, :email, :contact_number, :status)'
            );
            $insertStmt->execute([
                ':employee_number' => $employeeNumber !== '' ? $employeeNumber : null,
                ':first_name' => $firstName,
                ':last_name' => $lastName,
                ':position' => $position !== '' ? $position : null,
                ':department' => $department !== '' ? $department : null,
                ':college_id' => $collegeId > 0 ? $collegeId : null,
                ':email' => $email !== '' ? $email : null,
                ':contact_number' => $contactNumber !== '' ? $contactNumber : null,
                ':status' => 'active',
            ]);

            redirect_faculty('success', trim($firstName . ' ' . $lastName) . ' was added.');
        }

        if ($action === 'toggle_status') {
            $facultyId = (int)($_POST['faculty_id'] ?? 0);
            if ($facultyId <= 0) {
                redirect_faculty('error', 'Invalid record selected.');
            }

            $toggleStmt = $pdo->prepare(
                "UPDATE faculty_staff
                 SET status = CASE WHEN status = 'active' THEN 'inactive' ELSE 'active' END
                 WHERE id = :id"
            );
            $toggleStmt->execute([':id' => $facultyId]);

            redirect_faculty('success', 'Record status updated.');
        }

        if ($action === 'delete_faculty') {
            $facultyId = (int)($_POST['faculty_id'] ?? 0);
            if ($facultyId <= 0) {
                redirect_faculty('error', 'Invalid record selected.');
            }

            // A record named in an existing complaint is kept for the record's
            // sake; it is deactivated instead so it stops appearing in the
            // complaint form without breaking that complaint's history.
            $usedStmt = $pdo->prepare('SELECT COUNT(*) FROM complaint_faculty_links WHERE faculty_id = :id');
            $usedStmt->execute([':id' => $facultyId]);
            if ((int)$usedStmt->fetchColumn() > 0) {
                $deactivateStmt = $pdo->prepare("UPDATE faculty_staff SET status = 'inactive' WHERE id = :id");
                $deactivateStmt->execute([':id' => $facultyId]);
                redirect_faculty('success', 'This person is named in an existing complaint, so they were set to inactive instead of deleted.');
            }

            $deleteStmt = $pdo->prepare('DELETE FROM faculty_staff WHERE id = :id');
            $deleteStmt->execute([':id' => $facultyId]);

            redirect_faculty('success', 'Record deleted.');
        }

        redirect_faculty('error', 'Unknown action.');
    } catch (PDOException $e) {
        redirect_faculty('error', 'Database error: ' . $e->getMessage());
    }
}

$colleges = [];
try {
    $colleges = $pdo->query('SELECT id, code, name FROM colleges ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
}

$search = trim((string)($_GET['q'] ?? ''));
$facultyRows = [];
try {
    $sql = 'SELECT f.*, c.code AS college_code,
                   (SELECT COUNT(*) FROM complaint_faculty_links cfl WHERE cfl.faculty_id = f.id) AS complaint_count
            FROM faculty_staff f
            LEFT JOIN colleges c ON c.id = f.college_id';
    $params = [];
    if ($search !== '') {
        $sql .= ' WHERE (f.first_name LIKE :like OR f.last_name LIKE :like2 OR f.position LIKE :like3 OR f.department LIKE :like4 OR f.employee_number LIKE :like5)';
        $like = '%' . $search . '%';
        $params = [':like' => $like, ':like2' => $like, ':like3' => $like, ':like4' => $like, ':like5' => $like];
    }
    $sql .= ' ORDER BY f.first_name ASC, f.last_name ASC';
    $listStmt = $pdo->prepare($sql);
    $listStmt->execute($params);
    $facultyRows = $listStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $facultyRows = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>VOICE - Faculty & Staff</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
body { background: #f9fafb; }
.main { margin-left: 260px; margin-top: 61px; padding: 25px; min-height: calc(100vh - 61px); }
.page-header { margin-bottom: 6px; }
.page-header h2 { font-size: 24px; font-weight: 600; color: #333; }
.page-subtitle { color: #6b7280; font-size: 14px; margin-bottom: 22px; }
.card { background: #fff; padding: 20px; border-radius: 12px; border: 1px solid #e5e7eb; box-shadow: 0 2px 10px rgba(0,0,0,0.02); margin-bottom: 20px; overflow-x: auto; }
.card h3 { font-size: 16px; font-weight: 600; color: #111827; margin-bottom: 14px; }
.form-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; }
.form-group label { display: block; font-size: 12.5px; font-weight: 500; color: #4b5563; margin-bottom: 6px; }
.input-field { width: 100%; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 13.5px; background: #fff; }
.input-field:focus { outline: none; border-color: #7c3aed; }
.btn { padding: 10px 18px; border-radius: 8px; border: none; cursor: pointer; font-size: 13.5px; font-weight: 600; background: #7c3aed; color: #fff; }
.btn:hover { background: #6d28d9; }
.btn-secondary { background: #f3f4f6; color: #374151; }
.btn-danger { background: #fee2e2; color: #b91c1c; }
.btn-small { padding: 6px 12px; font-size: 12px; }
.flash { padding: 12px 16px; border-radius: 8px; margin-bottom: 18px; font-size: 13.5px; }
.flash.success { background: #dcfce7; color: #166534; }
.flash.error { background: #fee2e2; color: #b91c1c; }
table { width: 100%; border-collapse: collapse; min-width: 780px; }
th { text-align: left; font-size: 11.5px; color: #9ca3af; padding: 12px 10px; border-bottom: 2px solid #f3f4f6; text-transform: uppercase; font-weight: 600; }
td { padding: 12px 10px; font-size: 13.5px; color: #374151; border-bottom: 1px solid #f9fafb; vertical-align: middle; }
.badge { display: inline-block; padding: 4px 10px; border-radius: 999px; font-size: 11.5px; font-weight: 600; }
.badge.active { background: #dcfce7; color: #16a34a; }
.badge.inactive { background: #f3f4f6; color: #6b7280; }
.search-box { display: flex; align-items: center; gap: 8px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 8px 12px; max-width: 320px; }
.search-box input { border: none; background: transparent; outline: none; width: 100%; font-size: 13.5px; }
.muted { color: #9ca3af; }
@media (max-width: 1024px) { .main { margin-left: 0; } .form-grid { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<?php include 'admin_topbar.php'; ?>
<?php include 'admin_sidebar.php'; ?>

<div class="main">
    <div class="page-header"><h2>Faculty &amp; Staff</h2></div>
    <p class="page-subtitle">People who can be named in a complaint. Complaints about them are routed to the SAS Director.</p>

    <?php if ($flashMessage !== ''): ?>
        <div class="flash <?php echo e($flashType); ?>"><?php echo e($flashMessage); ?></div>
    <?php endif; ?>

    <div class="card">
        <h3>Add Faculty or Staff</h3>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo e((string)$_SESSION['csrf_token']); ?>">
            <input type="hidden" name="action" value="add_faculty">
            <div class="form-grid">
                <div class="form-group">
                    <label>First Name <span style="color:red;">*</span></label>
                    <input type="text" name="first_name" class="input-field" required>
                </div>
                <div class="form-group">
                    <label>Last Name <span style="color:red;">*</span></label>
                    <input type="text" name="last_name" class="input-field" required>
                </div>
                <div class="form-group">
                    <label>Employee Number</label>
                    <input type="text" name="employee_number" class="input-field" placeholder="EMP-0001">
                </div>
                <div class="form-group">
                    <label>Position</label>
                    <input type="text" name="position" class="input-field" placeholder="Instructor">
                </div>
                <div class="form-group">
                    <label>Department / Office</label>
                    <input type="text" name="department" class="input-field" placeholder="Registrar Office">
                </div>
                <div class="form-group">
                    <label>College</label>
                    <select name="college_id" class="input-field">
                        <option value="0">Not applicable</option>
                        <?php foreach ($colleges as $college): ?>
                            <option value="<?php echo (int)$college['id']; ?>"><?php echo e((string)$college['code'] . ' - ' . (string)$college['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Email <span class="muted">(needed for Call Slips)</span></label>
                    <input type="email" name="email" class="input-field" placeholder="name@bisu.edu.ph">
                </div>
                <div class="form-group">
                    <label>Contact Number</label>
                    <input type="text" name="contact_number" class="input-field" placeholder="09123456789">
                </div>
                <div class="form-group" style="display:flex; align-items:flex-end;">
                    <button type="submit" class="btn"><i class='bx bx-plus'></i> Add Record</button>
                </div>
            </div>
        </form>
        <p style="font-size:12.5px; color:#6b7280; margin-top:14px;">
            Adding many at once? Use <a href="admin_setting.php?tab=bulk_upload" style="color:#7c3aed;">Settings &rsaquo; Bulk Upload</a>.
        </p>
    </div>

    <div class="card">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:14px;">
            <h3 style="margin:0;"><?php echo count($facultyRows); ?> record<?php echo count($facultyRows) === 1 ? '' : 's'; ?></h3>
            <form method="GET" class="search-box">
                <i class='bx bx-search'></i>
                <input type="text" name="q" placeholder="Search name, position, department..." value="<?php echo e($search); ?>">
            </form>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Position</th>
                    <th>Department / College</th>
                    <th>Email</th>
                    <th>Complaints</th>
                    <th>Status</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($facultyRows === []): ?>
                    <tr><td colspan="7" style="text-align:center; color:#9ca3af; padding:28px;">
                        <?php echo $search !== '' ? 'No records match that search.' : 'No faculty or staff records yet. Add one above, or bulk upload a roster.'; ?>
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($facultyRows as $row): ?>
                        <tr>
                            <td>
                                <strong><?php echo e(trim((string)$row['first_name'] . ' ' . (string)$row['last_name'])); ?></strong>
                                <?php if (!empty($row['employee_number'])): ?>
                                    <div class="muted" style="font-size:12px;"><?php echo e((string)$row['employee_number']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $row['position'] !== null && $row['position'] !== '' ? e((string)$row['position']) : '<span class="muted">—</span>'; ?></td>
                            <td>
                                <?php
                                $where = array_filter([
                                    (string)($row['department'] ?? ''),
                                    (string)($row['college_code'] ?? ''),
                                ]);
                                echo $where !== [] ? e(implode(' • ', $where)) : '<span class="muted">—</span>';
                                ?>
                            </td>
                            <td><?php echo $row['email'] !== null && $row['email'] !== '' ? e((string)$row['email']) : '<span class="muted">—</span>'; ?></td>
                            <td><?php echo (int)$row['complaint_count']; ?></td>
                            <td><span class="badge <?php echo e((string)$row['status']); ?>"><?php echo e(ucfirst((string)$row['status'])); ?></span></td>
                            <td style="text-align:right; white-space:nowrap;">
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?php echo e((string)$_SESSION['csrf_token']); ?>">
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="faculty_id" value="<?php echo (int)$row['id']; ?>">
                                    <button type="submit" class="btn btn-secondary btn-small"><?php echo (string)$row['status'] === 'active' ? 'Deactivate' : 'Activate'; ?></button>
                                </form>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this record?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo e((string)$_SESSION['csrf_token']); ?>">
                                    <input type="hidden" name="action" value="delete_faculty">
                                    <input type="hidden" name="faculty_id" value="<?php echo (int)$row['id']; ?>">
                                    <button type="submit" class="btn btn-danger btn-small">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
