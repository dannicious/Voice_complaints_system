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

function js(string $value): string
{
    return htmlspecialchars(json_encode($value, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG), ENT_QUOTES, 'UTF-8');
}

function approval_badge(string $status): string
{
    if ($status === 'approved') {
        return 'approval-approved';
    }
    if ($status === 'rejected') {
        return 'approval-rejected';
    }
    return 'approval-pending';
}

function complaint_status_badge(string $status): string
{
    if ($status === 'resolved') {
        return 'status-resolved';
    }
    if ($status === 'new' || $status === 'pending') {
        return 'status-new';
    }
    return 'status-pending';
}

$flashMessage = '';
$flashType = '';
$adminId = (int)$_SESSION['user_id'];

// Handle approve/reject actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    $token = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $flashMessage = 'Invalid request token.';
        $flashType = 'error';
    } elseif ($action === 'approve_complaint') {
        $complaintId = (int)($_POST['complaint_id'] ?? 0);
        $adminNotes = trim((string)($_POST['admin_notes'] ?? ''));

        if ($complaintId <= 0) {
            $flashMessage = 'Invalid complaint ID.';
            $flashType = 'error';
        } else {
            try {
                $verifyStmt = $pdo->prepare(
                    'SELECT c.id, c.ticket_no, c.student_id, sp.user_id AS student_user_id
                     FROM complaints c
                     LEFT JOIN student_profiles sp ON sp.id = c.student_id
                     WHERE c.id = :id AND c.approval_status = :approval_status
                     LIMIT 1'
                );
                $verifyStmt->execute([
                    ':id' => $complaintId,
                    ':approval_status' => 'pending'
                ]);
                $complaint = $verifyStmt->fetch();

                if (!$complaint) {
                    $flashMessage = 'Complaint not found or already processed.';
                    $flashType = 'error';
                } else {
                    $updateStmt = $pdo->prepare(
                        'UPDATE complaints
                         SET approval_status = :approval_status,
                             visibility_status = :visibility_status,
                             admin_id = :admin_id,
                             admin_notes = :admin_notes,
                             admin_reviewed_at = NOW()
                         WHERE id = :id'
                    );
                    $updateStmt->execute([
                        ':approval_status' => 'approved',
                        ':visibility_status' => 'public',
                        ':admin_id' => $adminId,
                        ':admin_notes' => $adminNotes,
                        ':id' => $complaintId,
                    ]);

                    // Notify student of approval
                    $studentUserId = (int)($complaint['student_user_id'] ?? 0);
                    if ($studentUserId > 0) {
                        $notifStmt = $pdo->prepare(
                            'INSERT INTO notifications (user_id, type, message, ticket_type, ticket_id, is_read)
                             VALUES (:user_id, :type, :message, :ticket_type, :ticket_id, :is_read)'
                        );
                        $notifStmt->execute([
                            ':user_id' => $studentUserId,
                            ':type' => 'complaint_approved',
                            ':message' => 'Your complaint ' . (string)$complaint['ticket_no'] . ' has been approved and is now visible.',
                            ':ticket_type' => 'complaint',
                            ':ticket_id' => $complaintId,
                            ':is_read' => 0,
                        ]);
                    }

                    // Notify deans about approved complaint
                    $deanStmt = $pdo->query("SELECT id FROM users WHERE role = 'dean' AND is_active = 1");
                    $deans = $deanStmt->fetchAll();
                    if ($deans) {
                        $deanNotifStmt = $pdo->prepare(
                            'INSERT INTO notifications (user_id, type, message, ticket_type, ticket_id, is_read)
                             VALUES (:user_id, :type, :message, :ticket_type, :ticket_id, :is_read)'
                        );
                        foreach ($deans as $dean) {
                            $deanNotifStmt->execute([
                                ':user_id' => (int)$dean['id'],
                                ':type' => 'complaint_approved_by_admin',
                                ':message' => 'Admin approved complaint ' . (string)$complaint['ticket_no'] . ' for your review.',
                                ':ticket_type' => 'complaint',
                                ':ticket_id' => $complaintId,
                                ':is_read' => 0,
                            ]);
                        }
                    }

                    $flashMessage = 'Complaint approved successfully and forwarded to deans.';
                    $flashType = 'success';
                }
            } catch (PDOException $e) {
                $flashMessage = 'Unable to approve complaint: ' . $e->getMessage();
                $flashType = 'error';
            }
        }
    } elseif ($action === 'reject_complaint') {
        $complaintId = (int)($_POST['complaint_id'] ?? 0);
        $rejectionReason = trim((string)($_POST['rejection_reason'] ?? ''));

        if ($complaintId <= 0 || $rejectionReason === '') {
            $flashMessage = 'Please provide a rejection reason.';
            $flashType = 'error';
        } else {
            try {
                $verifyStmt = $pdo->prepare(
                    'SELECT c.id, c.ticket_no, sp.user_id AS student_user_id
                     FROM complaints c
                     LEFT JOIN student_profiles sp ON sp.id = c.student_id
                     WHERE c.id = :id AND c.approval_status = :approval_status
                     LIMIT 1'
                );
                $verifyStmt->execute([
                    ':id' => $complaintId,
                    ':approval_status' => 'pending'
                ]);
                $complaint = $verifyStmt->fetch();

                if (!$complaint) {
                    $flashMessage = 'Complaint not found or already processed.';
                    $flashType = 'error';
                } else {
                    $updateStmt = $pdo->prepare(
                        'UPDATE complaints
                         SET approval_status = :approval_status,
                             admin_id = :admin_id,
                             admin_notes = :admin_notes,
                             admin_reviewed_at = NOW()
                         WHERE id = :id'
                    );
                    $updateStmt->execute([
                        ':approval_status' => 'rejected',
                        ':admin_id' => $adminId,
                        ':admin_notes' => $rejectionReason,
                        ':id' => $complaintId,
                    ]);

                    // Notify student of rejection
                    $studentUserId = (int)($complaint['student_user_id'] ?? 0);
                    if ($studentUserId > 0) {
                        $notifStmt = $pdo->prepare(
                            'INSERT INTO notifications (user_id, type, message, ticket_type, ticket_id, is_read)
                             VALUES (:user_id, :type, :message, :ticket_type, :ticket_id, :is_read)'
                        );
                        $notifStmt->execute([
                            ':user_id' => $studentUserId,
                            ':type' => 'complaint_rejected',
                            ':message' => 'Your complaint ' . (string)$complaint['ticket_no'] . ' was rejected. Reason: ' . $rejectionReason,
                            ':ticket_type' => 'complaint',
                            ':ticket_id' => $complaintId,
                            ':is_read' => 0,
                        ]);
                    }

                    $flashMessage = 'Complaint rejected.';
                    $flashType = 'success';
                }
            } catch (PDOException $e) {
                $flashMessage = 'Unable to reject complaint: ' . $e->getMessage();
                $flashType = 'error';
            }
        }
    } elseif ((string)($_POST['action'] ?? '') === 'update_complaint_status') {
        $complaintId = (int)($_POST['complaint_id'] ?? 0);
        $status = trim((string)($_POST['status'] ?? ''));
        $remarks = trim((string)($_POST['remarks'] ?? ''));

        $allowedStatuses = ['new', 'pending', 'under_review', 'flagged', 'resolved'];

        if ($complaintId <= 0 || !in_array($status, $allowedStatuses, true)) {
            $flashMessage = 'Invalid update request.';
            $flashType = 'error';
        } else {
            try {
                $verifyStmt = $pdo->prepare(
                    'SELECT c.id, c.ticket_no, sp.user_id AS student_user_id
                     FROM complaints c
                     LEFT JOIN student_profiles sp ON sp.id = c.student_id
                     WHERE c.id = :id
                     LIMIT 1'
                );
                $verifyStmt->execute([':id' => $complaintId]);
                $row = $verifyStmt->fetch();

                if (!$row) {
                    $flashMessage = 'Complaint not found.';
                    $flashType = 'error';
                } else {
                    $updateStmt = $pdo->prepare(
                        'UPDATE complaints
                         SET status = :status
                         WHERE id = :id'
                    );
                    $updateStmt->execute([
                        ':status' => $status,
                        ':id' => $complaintId,
                    ]);

                    if ($remarks !== '') {
                        $replyStmt = $pdo->prepare(
                            'INSERT INTO ticket_replies (ticket_type, ticket_id, sender_id, sender_role, message, is_read)
                             VALUES (:ticket_type, :ticket_id, :sender_id, :sender_role, :message, :is_read)'
                        );
                        $replyStmt->execute([
                            ':ticket_type' => 'complaint',
                            ':ticket_id' => $complaintId,
                            ':sender_id' => $adminId,
                            ':sender_role' => 'admin',
                            ':message' => $remarks,
                            ':is_read' => 0,
                        ]);
                    }

                    $studentUserId = (int)($row['student_user_id'] ?? 0);
                    if ($studentUserId > 0) {
                        $notifStmt = $pdo->prepare(
                            'INSERT INTO notifications (user_id, type, message, ticket_type, ticket_id, is_read)
                             VALUES (:user_id, :type, :message, :ticket_type, :ticket_id, :is_read)'
                        );
                        $notifStmt->execute([
                            ':user_id' => $studentUserId,
                            ':type' => 'complaint_update',
                            ':message' => 'Your complaint ' . (string)$row['ticket_no'] . ' status is now ' . strtoupper(str_replace('_', ' ', $status)) . '.',
                            ':ticket_type' => 'complaint',
                            ':ticket_id' => $complaintId,
                            ':is_read' => 0,
                        ]);
                    }

                    $flashMessage = 'Complaint updated successfully.';
                    $flashType = 'success';
                }
            } catch (PDOException $e) {
                $flashMessage = 'Unable to update complaint.';
                $flashType = 'error';
            }
        }
    }
}

$q = trim((string)($_GET['q'] ?? ''));
$date = trim((string)($_GET['date'] ?? ''));
$college = (int)($_GET['college'] ?? 0);
$status = trim((string)($_GET['status'] ?? ''));
$approvalStatus = trim((string)($_GET['approval_status'] ?? ''));

$colleges = [];
$complaints = [];

try {
    $collegeStmt = $pdo->query('SELECT id, code, name FROM colleges ORDER BY name ASC');
    $colleges = $collegeStmt->fetchAll();

    $sql =
        "SELECT
            c.id,
            c.ticket_no,
            c.created_at,
            c.complainant_name,
            c.person_complained_of,
            c.date_of_incident,
            c.place_of_incident,
            c.act_complained_of,
            c.narrative_report,
            c.desired_outcome,
            c.status,
            c.is_anonymous,
            c.approval_status,
            c.admin_notes,
            c.admin_reviewed_at,
            COALESCE(cc.name, 'Uncategorized') AS category_name,
            COALESCE(col.code, col.name, 'N/A') AS college_code,
            sp.first_name,
            sp.last_name
         FROM complaints c
         LEFT JOIN complaint_categories cc ON cc.id = c.category_id
         LEFT JOIN colleges col ON col.id = c.college_id
         LEFT JOIN student_profiles sp ON sp.id = c.student_id
                 WHERE 1=1
                       AND c.ticket_no NOT LIKE 'VOX-C-2026-%'";

    $params = [];

    if ($q !== '') {
        $sql .= ' AND (c.ticket_no LIKE :q OR c.complainant_name LIKE :q OR c.act_complained_of LIKE :q OR c.narrative_report LIKE :q OR CONCAT(COALESCE(sp.first_name,\'\'), \" \", COALESCE(sp.last_name,\'\')) LIKE :q)';
        $params[':q'] = '%' . $q . '%';
    }

    if ($date !== '') {
        $sql .= ' AND DATE(c.created_at) = :date_submitted';
        $params[':date_submitted'] = $date;
    }

    if ($college > 0) {
        $sql .= ' AND c.college_id = :college_id';
        $params[':college_id'] = $college;
    }

    if ($status !== '') {
        $sql .= ' AND c.status = :status';
        $params[':status'] = $status;
    }

    if ($approvalStatus !== '') {
        $sql .= ' AND c.approval_status = :approval_status';
        $params[':approval_status'] = $approvalStatus;
    }

    // Default: show pending approvals first, then others
    if ($approvalStatus === '') {
        $sql .= ' ORDER BY c.approval_status = "pending" DESC, c.created_at DESC';
    } else {
        $sql .= ' ORDER BY c.created_at DESC';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $complaints = $stmt->fetchAll();
} catch (PDOException $e) {
    if ($flashMessage === '') {
        $flashMessage = 'Unable to load complaints right now.';
        $flashType = 'error';
    }
}

// Count pending approvals
$pendingCount = 0;
try {
    $countStmt = $pdo->query("SELECT COUNT(*) as count FROM complaints WHERE approval_status = 'pending'");
    $countRow = $countStmt->fetch();
    $pendingCount = (int)($countRow['count'] ?? 0);
} catch (PDOException $e) {
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>VOICE - Complaints Management</title>
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

.main {
    margin-left: 260px;
    margin-top: 61px;
    padding: 25px;
    min-height: calc(100vh - 61px);
    overflow-y: auto;
}

.dashboard-container {
    max-width: 1400px;
    margin: 0 auto;
    width: 100%;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 25px;
    flex-wrap: wrap;
    gap: 15px;
}

.page-header h2 {
    font-size: 24px;
    font-weight: 600;
    color: #333;
}

.pending-badge {
    background: #fef3c7;
    color: #d97706;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 600;
}

.flash {
    border-radius: 10px;
    padding: 12px 14px;
    margin-bottom: 18px;
    font-size: 13px;
}

.flash-success {
    background: #ecfdf5;
    border: 1px solid #86efac;
    color: #166534;
}

.flash-error {
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #991b1b;
}

.controls-card {
    background: #fff;
    padding: 20px;
    border-radius: 12px;
    margin-bottom: 25px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.03);
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.controls-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
}

.search-box {
    display: flex;
    align-items: center;
    background: #f4f6fb;
    padding: 8px 15px;
    border-radius: 8px;
    flex: 1;
    min-width: 250px;
    max-width: 400px;
    border: 1px solid #e5e7eb;
}

.search-box i { color: #888; margin-right: 10px; font-size: 18px; }
.search-box input { border: none; background: transparent; outline: none; width: 100%; font-size: 13px; }

.controls-divider { width: 100%; height: 1px; background: #f0f0f0; }

.controls-bottom {
    display: flex;
    gap: 15px;
    align-items: flex-end;
    flex-wrap: wrap;
}

.filter-group { display: flex; flex-direction: column; gap: 5px; }

.filter-group label {
    font-size: 11px;
    color: #888;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.filter-input,
.filter-select {
    padding: 8px 12px;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    background: #f4f6fb;
    font-size: 13px;
    color: #555;
    outline: none;
    min-width: 140px;
    height: 38px;
}

.btn-apply-filter {
    display: flex;
    align-items: center;
    gap: 5px;
    padding: 0 20px;
    height: 38px;
    background: #6d28d9;
    color: #fff;
    border: none;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    transition: 0.2s;
}

.btn-apply-filter:hover { background: #5d1fa0; }

.btn-reset {
    height: 38px;
    display: inline-flex;
    align-items: center;
    padding: 0 14px;
    border-radius: 8px;
    border: 1px solid #d1d5db;
    color: #374151;
    text-decoration: none;
    font-size: 13px;
    background: #fff;
}

.btn-reset:hover { background: #f3f4f6; }

.table-card {
    background: #fff;
    padding: 25px;
    border-radius: 12px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.03);
    width: 100%;
    overflow-x: auto;
}

table { width: 100%; border-collapse: collapse; min-width: 1000px; }

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
    vertical-align: top;
}

tbody tr { transition: background 0.2s; }
tbody tr:hover { background-color: #fcfcfc; }

.status-badge {
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    display: inline-block;
}

.status-new      { background: #e0f2fe; color: #0284c7; }
.status-pending  { background: #fef3c7; color: #d97706; }
.status-resolved { background: #d1fae5; color: #059669; }

.approval-badge {
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    display: inline-block;
}

.approval-pending  { background: #fef3c7; color: #d97706; }
.approval-approved { background: #d1fae5; color: #059669; }
.approval-rejected { background: #fee2e2; color: #991b1b; }

.action-btns { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }

.btn-icon,
.btn-form {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 6px 10px;
    border-radius: 6px;
    color: #fff;
    text-decoration: none;
    font-size: 12px;
    transition: 0.2s;
    border: none;
    cursor: pointer;
}

.btn-view    { background: #6d28d9; }
.btn-view:hover    { background: #5d1fa0; }
.btn-approve { background: #10b981; }
.btn-approve:hover { background: #059669; }
.btn-reject  { background: #ef4444; }
.btn-reject:hover { background: #dc2626; }

.no-data {
    text-align: center;
    color: #6b7280;
    padding: 28px 0;
    font-size: 13px;
}

/* Modal Styles */
.modal {
    position: fixed;
    inset: 0;
    background: rgba(17, 24, 39, 0.45);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 1200;
    padding: 16px;
}

.modal.show {
    display: flex;
}

.modal-card {
    width: min(600px, 100%);
    background: #fff;
    border-radius: 14px;
    box-shadow: 0 20px 45px rgba(0, 0, 0, 0.2);
    max-height: 90vh;
    overflow: auto;
}

.modal-header {
    padding: 16px 20px;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-title {
    font-size: 17px;
    font-weight: 600;
    color: #111827;
}

.modal-close {
    border: none;
    background: #f3f4f6;
    color: #111827;
    width: 34px;
    height: 34px;
    border-radius: 8px;
    font-size: 18px;
    cursor: pointer;
}

.modal-body {
    padding: 18px 20px 22px;
}

.form-group { margin-bottom: 16px; }
.form-group label { display: block; font-size: 13px; font-weight: 500; color: #374151; margin-bottom: 6px; }
.form-input { width: 100%; padding: 9px 12px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 13px; }
.form-input.textarea { resize: vertical; min-height: 100px; }
.form-input:focus { outline: none; border-color: #6d28d9; box-shadow: 0 0 0 3px rgba(109, 40, 217, 0.1); }

.modal-buttons {
    display: flex;
    gap: 10px;
    margin-top: 20px;
    justify-content: flex-end;
}

.btn-cancel {
    padding: 9px 18px;
    background: #f3f4f6;
    color: #374151;
    border: none;
    border-radius: 8px;
    font-size: 13px;
    cursor: pointer;
}

.btn-cancel:hover { background: #e5e7eb; }

.btn-submit {
    padding: 9px 18px;
    background: #6d28d9;
    color: #fff;
    border: none;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
}

.btn-submit:hover { background: #5d1fa0; }

.btn-reject-submit {
    background: #ef4444;
}

.btn-reject-submit:hover {
    background: #dc2626;
}

@media (max-width: 768px) {
    .main {
        margin-left: 0;
        padding: 14px;
        margin-top: 61px;
    }

    .search-box {
        width: 100%;
        max-width: 100%;
    }

    table {
        min-width: 700px;
    }
}
</style>
</head>

<body>
<?php include 'admin_topbar.php'; ?>
<?php include 'admin_sidebar.php'; ?>

<div class="main">
    <div class="dashboard-container">
        <div class="page-header">
            <div>
                <h2>Complaints Management</h2>
            </div>
            <?php if ($pendingCount > 0): ?>
                <div class="pending-badge">
                    <i class='bx bx-bell'></i> <?php echo $pendingCount; ?> Pending Approval
                </div>
            <?php endif; ?>
        </div>

        <?php if ($flashMessage !== ''): ?>
            <div class="flash <?php echo $flashType === 'success' ? 'flash-success' : 'flash-error'; ?>">
                <?php echo e($flashMessage); ?>
            </div>
        <?php endif; ?>

        <form method="GET" class="controls-card">
            <div class="controls-top">
                <div class="search-box">
                    <i class='bx bx-search'></i>
                    <input type="text" name="q" value="<?php echo e($q); ?>" placeholder="Search Ticket, Student, Subject...">
                </div>
            </div>

            <div class="controls-divider"></div>

            <div class="controls-bottom">
                <div class="filter-group">
                    <label>Date</label>
                    <input type="date" name="date" class="filter-input" value="<?php echo e($date); ?>">
                </div>
                <div class="filter-group">
                    <label>College</label>
                    <select name="college" class="filter-select">
                        <option value="0">All Colleges</option>
                        <?php foreach ($colleges as $c): ?>
                            <option value="<?php echo (int)$c['id']; ?>" <?php echo $college === (int)$c['id'] ? 'selected' : ''; ?>>
                                <?php echo e((string)$c['code'] . ' - ' . (string)$c['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Approval Status</label>
                    <select name="approval_status" class="filter-select">
                        <option value="">All Statuses</option>
                        <option value="pending" <?php echo $approvalStatus === 'pending' ? 'selected' : ''; ?>>Pending Approval</option>
                        <option value="approved" <?php echo $approvalStatus === 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="rejected" <?php echo $approvalStatus === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                    </select>
                </div>
                <button class="btn-apply-filter" type="submit"><i class='bx bx-filter-alt'></i> Apply Filter</button>
                <a class="btn-reset" href="admin_complaints.php">Reset</a>
            </div>
        </form>

        <div class="table-card">
            <table>
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>College</th>
                        <th>Category</th>
                        <th>Submitted</th>
                        <th>Approval Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$complaints): ?>
                        <tr>
                            <td colspan="6" class="no-data">No complaints found for the selected filters.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($complaints as $row): ?>
                            <tr>
                                <td>
                                    <?php
                                        $displayName = 'Anonymous Student';
                                        if ((int)$row['is_anonymous'] !== 1) {
                                            $displayName = trim((string)$row['first_name'] . ' ' . (string)$row['last_name']);
                                            if ($displayName === '') {
                                                $displayName = 'Student';
                                            }
                                        }
                                        echo e($displayName);
                                    ?>
                                </td>
                                <td><?php echo e((string)$row['college_code']); ?></td>
                                <td><?php echo e((string)$row['category_name']); ?></td>
                                <td><?php echo e(date('M d, Y', strtotime((string)$row['created_at']))); ?></td>
                                <td>
                                    <span class="approval-badge <?php echo approval_badge((string)$row['approval_status']); ?>">
                                        <?php echo e(ucfirst((string)$row['approval_status'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-btns">
                                        <button class="btn-icon btn-view" onclick="viewComplaint(<?php echo (int)$row['id']; ?>, <?php echo js((string)$row['ticket_no']); ?>, <?php echo js((string)$row['complainant_name']); ?>, <?php echo js((string)$row['complainant_address']); ?>, <?php echo js((string)$row['complainant_sex']); ?>, <?php echo js((string)$row['complainant_age']); ?>, <?php echo js((string)$row['complainant_civil_status']); ?>, <?php echo js((string)$row['complainant_contact_details']); ?>, <?php echo js((string)$row['person_complained_of']); ?>, <?php echo js((string)$row['date_of_incident']); ?>, <?php echo js((string)$row['time_of_incident']); ?>, <?php echo js((string)$row['place_of_incident']); ?>, <?php echo js((string)$row['act_complained_of']); ?>, <?php echo js((string)$row['narrative_report']); ?>, <?php echo js((string)$row['proof_of_complaint']); ?>, <?php echo js((string)$row['desired_outcome']); ?>, <?php echo js((string)$row['signature']); ?>)">
                                            <i class='bx bx-show'></i> View
                                        </button>
                                        <?php if ((string)$row['approval_status'] === 'pending'): ?>
                                            <button class="btn-icon btn-approve" onclick="openApproveModal(<?php echo (int)$row['id']; ?>, '<?php echo e((string)$row['ticket_no']); ?>')">
                                                <i class='bx bx-check'></i> Approve
                                            </button>
                                            <button class="btn-icon btn-reject" onclick="openRejectModal(<?php echo (int)$row['id']; ?>, '<?php echo e((string)$row['ticket_no']); ?>')">
                                                <i class='bx bx-x'></i> Reject
                                            </button>
                                        <?php endif; ?>
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

<!-- View Complaint Modal -->
<div id="viewModal" class="modal">
    <div class="modal-card">
        <div class="modal-header">
            <div class="modal-title">Complaint Details</div>
            <button class="modal-close" onclick="closeModal('viewModal')">✕</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label>Ticket Number</label>
                <input type="text" class="form-input" id="viewTicketNo" readonly>
            </div>
            
            <fieldset style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 10px; margin: 15px 0;">
                <legend style="font-weight: 600; color: #333; padding: 0 10px; font-size: 13px;">Complainant Information</legend>
                
                <div class="form-group">
                    <label>Name</label>
                    <input type="text" class="form-input" id="viewComplainantName" readonly>
                </div>
                
                <div class="form-group">
                    <label>Address</label>
                    <textarea class="form-input textarea" id="viewComplainantAddress" readonly></textarea>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                    <div class="form-group">
                        <label>Sex</label>
                        <input type="text" class="form-input" id="viewComplainantSex" readonly>
                    </div>
                    <div class="form-group">
                        <label>Age</label>
                        <input type="text" class="form-input" id="viewComplainantAge" readonly>
                    </div>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                    <div class="form-group">
                        <label>Civil Status</label>
                        <input type="text" class="form-input" id="viewComplainantCivilStatus" readonly>
                    </div>
                    <div class="form-group">
                        <label>Contact Details</label>
                        <input type="text" class="form-input" id="viewComplainantContactDetails" readonly>
                    </div>
                </div>
            </fieldset>
            
            <fieldset style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 10px; margin: 15px 0;">
                <legend style="font-weight: 600; color: #333; padding: 0 10px; font-size: 13px;">Person/Office Complained Of</legend>
                
                <div class="form-group">
                    <label>Name</label>
                    <input type="text" class="form-input" id="viewPersonComplainedOf" readonly>
                </div>
            </fieldset>
            
            <fieldset style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 10px; margin: 15px 0;">
                <legend style="font-weight: 600; color: #333; padding: 0 10px; font-size: 13px;">Incident Details</legend>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px;">
                    <div class="form-group">
                        <label>Date of Incident</label>
                        <input type="date" class="form-input" id="viewDateOfIncident" readonly>
                    </div>
                    <div class="form-group">
                        <label>Time of Incident</label>
                        <input type="time" class="form-input" id="viewTimeOfIncident" readonly>
                    </div>
                    <div class="form-group">
                        <label>Place of Incident</label>
                        <input type="text" class="form-input" id="viewPlaceOfIncident" readonly>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Act/s Complained Of</label>
                    <textarea class="form-input textarea" id="viewActComplainedOf" readonly></textarea>
                </div>
                
                <div class="form-group">
                    <label>Narrative Report of Complaint</label>
                    <textarea class="form-input textarea" id="viewNarrativeReport" readonly></textarea>
                </div>
            </fieldset>
            
            <fieldset style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 10px; margin: 15px 0;">
                <legend style="font-weight: 600; color: #333; padding: 0 10px; font-size: 13px;">Proof of Complaint</legend>
                
                <div class="form-group">
                    <label>Documents/Evidence/Witnesses</label>
                    <textarea class="form-input textarea" id="viewProofOfComplaint" readonly></textarea>
                </div>
            </fieldset>
            
            <fieldset style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 10px; margin: 15px 0;">
                <legend style="font-weight: 600; color: #333; padding: 0 10px; font-size: 13px;">Complaint Outcome</legend>
                
                <div class="form-group">
                    <label>Desired Outcome</label>
                    <textarea class="form-input textarea" id="viewDesiredOutcome" readonly></textarea>
                </div>
            </fieldset>
            
            <fieldset style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 10px; margin: 15px 0;">
                <legend style="font-weight: 600; color: #333; padding: 0 10px; font-size: 13px;">Signature</legend>
                
                <div class="form-group">
                    <label>Signature/Name of Complainant</label>
                    <input type="text" class="form-input" id="viewSignature" readonly>
                </div>
            </fieldset>
        </div>
    </div>
</div>

<!-- Approve Modal -->
<div id="approveModal" class="modal">
    <div class="modal-card">
        <div class="modal-header">
            <div class="modal-title">Approve Complaint</div>
            <button class="modal-close" onclick="closeModal('approveModal')">✕</button>
        </div>
        <form method="POST" onsubmit="return true;">
            <div class="modal-body">
                <div class="form-group">
                    <label>Ticket Number</label>
                    <input type="text" class="form-input" id="approveTicketNo" readonly>
                </div>
                <div class="form-group">
                    <label>Admin Notes (Optional)</label>
                    <textarea class="form-input textarea" name="admin_notes" placeholder="Add any notes or comments about this complaint..."></textarea>
                </div>
            </div>
            <div class="modal-body" style="border-top: 1px solid #e5e7eb; padding-top: 0;">
                <p style="font-size: 12px; color: #6b7280; margin-bottom: 12px;">
                    ✓ This complaint will be approved<br>
                    ✓ It will be visible to students<br>
                    ✓ Deans will be notified for review
                </p>
                <div class="modal-buttons">
                    <button type="button" class="btn-cancel" onclick="closeModal('approveModal')">Cancel</button>
                    <button type="submit" class="btn-submit" name="action" value="approve_complaint" formmethod="POST">Approve</button>
                </div>
            </div>
            <input type="hidden" name="complaint_id" id="approveComplaintId">
            <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">
        </form>
    </div>
</div>

<!-- Reject Modal -->
<div id="rejectModal" class="modal">
    <div class="modal-card">
        <div class="modal-header">
            <div class="modal-title">Reject Complaint</div>
            <button class="modal-close" onclick="closeModal('rejectModal')">✕</button>
        </div>
        <form method="POST" onsubmit="return true;">
            <div class="modal-body">
                <div class="form-group">
                    <label>Ticket Number</label>
                    <input type="text" class="form-input" id="rejectTicketNo" readonly>
                </div>
                <div class="form-group">
                    <label>Rejection Reason <span style="color: #ef4444;">*</span></label>
                    <textarea class="form-input textarea" name="rejection_reason" placeholder="Explain why this complaint is being rejected..." required></textarea>
                </div>
            </div>
            <div class="modal-body" style="border-top: 1px solid #e5e7eb; padding-top: 0;">
                <p style="font-size: 12px; color: #6b7280; margin-bottom: 12px;">
                    ✓ The student will be notified of rejection<br>
                    ✓ The reason will be shared with them<br>
                    ✓ Complaint will not be visible to others
                </p>
                <div class="modal-buttons">
                    <button type="button" class="btn-cancel" onclick="closeModal('rejectModal')">Cancel</button>
                    <button type="submit" class="btn-submit btn-reject-submit" name="action" value="reject_complaint" formmethod="POST">Reject</button>
                </div>
            </div>
            <input type="hidden" name="complaint_id" id="rejectComplaintId">
            <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">
        </form>
    </div>
</div>

<script>
function viewComplaint(id, ticketNo, complainantName, complainantAddress, complainantSex, complainantAge, complainantCivilStatus, complainantContactDetails, personComplainedOf, dateOfIncident, timeOfIncident, placeOfIncident, actComplainedOf, narrativeReport, proofOfComplaint, desiredOutcome, signature) {
    document.getElementById('viewTicketNo').value = ticketNo;
    document.getElementById('viewComplainantName').value = complainantName;
    document.getElementById('viewComplainantAddress').value = complainantAddress;
    document.getElementById('viewComplainantSex').value = complainantSex;
    document.getElementById('viewComplainantAge').value = complainantAge;
    document.getElementById('viewComplainantCivilStatus').value = complainantCivilStatus;
    document.getElementById('viewComplainantContactDetails').value = complainantContactDetails;
    document.getElementById('viewPersonComplainedOf').value = personComplainedOf;
    document.getElementById('viewDateOfIncident').value = dateOfIncident;
    document.getElementById('viewTimeOfIncident').value = timeOfIncident;
    document.getElementById('viewPlaceOfIncident').value = placeOfIncident;
    document.getElementById('viewActComplainedOf').value = actComplainedOf;
    document.getElementById('viewNarrativeReport').value = narrativeReport;
    document.getElementById('viewProofOfComplaint').value = proofOfComplaint;
    document.getElementById('viewDesiredOutcome').value = desiredOutcome;
    document.getElementById('viewSignature').value = signature;

    document.getElementById('viewModal').classList.add('show');
}

function openApproveModal(id, ticketNo) {
    document.getElementById('approveComplaintId').value = id;
    document.getElementById('approveTicketNo').value = ticketNo;
    document.getElementById('approveModal').classList.add('show');
}

function openRejectModal(id, ticketNo) {
    document.getElementById('rejectComplaintId').value = id;
    document.getElementById('rejectTicketNo').value = ticketNo;
    document.getElementById('rejectModal').classList.add('show');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('show');
}

document.querySelectorAll('.modal').forEach(modal => {
    modal.addEventListener('click', (e) => {
        if (e.target === modal) {
            modal.classList.remove('show');
        }
    });
});
</script>

</body>
</html>
