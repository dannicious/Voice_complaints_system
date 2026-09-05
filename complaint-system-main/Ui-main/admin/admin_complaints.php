<?php
session_start();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../ticket_flow.php';
require_once __DIR__ . '/../complaint_age_helpers.php';

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
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
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

function complaint_status_badge(string $status): string
{
    if ($status === 'resolved') {
        return 'status-resolved';
    }
    if ($status === 'dismissed') {
        return 'status-dismissed';
    }
    if ($status === 'new' || $status === 'pending') {
        return 'status-new';
    }
    return 'status-pending';
}

function normalize_complaint_category_type(?string $value): string
{
    $normalized = strtolower(trim((string)$value));
    return in_array($normalized, ['general', 'college'], true) ? $normalized : 'general';
}

function fetch_complaints_by_category_type(PDO $pdo, string $categoryType, string $q, string $date, int $college, string $status): array
{
    $sql = <<<'SQL'
SELECT
    c.id,
    c.ticket_no,
    c.created_at,
    c.act_complained_of,
    c.narrative_report,
    c.attachments,
    c.status,
    c.is_anonymous,
    c.admin_notes,
    c.admin_reviewed_at,
    c.complainant_name,
    c.complainant_address,
    c.complainant_sex,
    c.complainant_age,
    c.complainant_civil_status,
    c.complainant_contact_details,
    c.person_complained_of,
    c.date_of_incident,
    c.time_of_incident,
    c.place_of_incident,
    COALESCE(cc.name, 'Uncategorized') AS category_name,
    COALESCE(cc.category_type, cc.route, 'general') AS category_type,
    COALESCE(col.code, col.name, scol.code, scol.name, 'N/A') AS college_code,
    sp.id AS student_id,
    sp.first_name,
    sp.last_name,
    tf.id AS feedback_id,
    tf.satisfaction AS feedback_satisfaction,
    tf.comment AS feedback_comment,
    tf.created_at AS feedback_created_at
FROM complaints c
LEFT JOIN complaint_categories cc ON cc.id = c.category_id
LEFT JOIN colleges col ON col.id = c.college_id
LEFT JOIN student_profiles sp ON sp.id = c.student_id
 LEFT JOIN ticket_feedback tf ON tf.ticket_type = 'complaint' AND tf.ticket_id = c.id AND tf.student_id = sp.id
LEFT JOIN colleges scol ON scol.id = sp.college_id
WHERE COALESCE(cc.category_type, cc.route, 'general') = :category_type
SQL;

    $params = [':category_type' => $categoryType];

    if ($q !== '') {
        $sql .= ' AND (c.ticket_no LIKE :q OR c.complainant_name LIKE :q OR c.act_complained_of LIKE :q OR c.narrative_report LIKE :q OR CONCAT(COALESCE(sp.first_name,\'\'), " ", COALESCE(sp.last_name,\'\')) LIKE :q)';
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

    $sql .= ' ORDER BY c.created_at DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function is_manageable_complaint(PDO $pdo, int $complaintId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT c.id, c.ticket_no, c.status, COALESCE(cc.category_type, cc.route, "general") AS category_type
         FROM complaints c
         LEFT JOIN complaint_categories cc ON cc.id = c.category_id
         WHERE c.id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $complaintId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    return normalize_complaint_category_type($row['category_type'] ?? null) === 'general' ? $row : null;
}

$flashMessage = '';
$flashType = '';
$adminId = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    $token = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $flashMessage = 'Invalid request token.';
        $flashType = 'error';
    } elseif ($action === 'update_complaint_status') {
        $complaintId = (int)($_POST['complaint_id'] ?? 0);
        $status = trim((string)($_POST['status'] ?? ''));
        $remarks = trim((string)($_POST['remarks'] ?? ''));
        $allowedStatuses = ['new', 'under_review', 'resolved'];

        if ($complaintId <= 0 || !in_array($status, $allowedStatuses, true)) {
            $flashMessage = 'Invalid update request.';
            $flashType = 'error';
        } elseif ($remarks === '') {
            $flashMessage = 'Response to student is required.';
            $flashType = 'error';
        } else {
            $row = is_manageable_complaint($pdo, $complaintId);
            if (!$row) {
                $flashMessage = 'Complaint not found or view-only complaints cannot be updated.';
                $flashType = 'error';
            } else {
                try {
                    $studentStmt = $pdo->prepare(
                        'SELECT sp.user_id AS student_user_id
                         FROM complaints c
                         LEFT JOIN student_profiles sp ON sp.id = c.student_id
                         WHERE c.id = :id
                         LIMIT 1'
                    );
                    $studentStmt->execute([':id' => $complaintId]);
                    $studentRow = $studentStmt->fetch(PDO::FETCH_ASSOC);

                    // Use shared helper to update status so timestamps and rules are preserved
                    set_ticket_status($pdo, 'complaint', $complaintId, $status);

                    // Save the mandatory reply only if the thread allows replies
                    $threadState = get_ticket_status_state($pdo, 'complaint', $complaintId);
                    if (!$threadState['can_reply']) {
                        $flashMessage = 'This complaint thread is currently closed for new replies.';
                        $flashType = 'error';
                    } else {
                        save_ticket_reply($pdo, 'complaint', $complaintId, $adminId, 'admin', $remarks);

                        $studentUserId = (int)($studentRow['student_user_id'] ?? 0);
                        if ($studentUserId > 0) {
                            $notifStmt = $pdo->prepare(
                                'INSERT INTO notifications (user_id, type, message, ticket_type, ticket_id, is_read)
                                 VALUES (:user_id, :type, :message, :ticket_type, :ticket_id, :is_read)'
                            );
                            $notifStmt->execute([
                                ':user_id' => $studentUserId,
                                ':type' => 'complaint_update',
                                ':message' => 'Your complaint ' . (string)$row['ticket_no'] . ' has a new response. Current status: ' . strtoupper(str_replace('_', ' ', $status)) . '.',
                                ':ticket_type' => 'complaint',
                                ':ticket_id' => $complaintId,
                                ':is_read' => 0,
                            ]);
                        }

                        $flashMessage = 'Complaint response saved successfully.';
                        $flashType = 'success';
                    }

                    $studentUserId = (int)($studentRow['student_user_id'] ?? 0);
                    if ($studentUserId > 0) {
                        $notifStmt = $pdo->prepare(
                            'INSERT INTO notifications (user_id, type, message, ticket_type, ticket_id, is_read)
                             VALUES (:user_id, :type, :message, :ticket_type, :ticket_id, :is_read)'
                        );
                        $notifStmt->execute([
                            ':user_id' => $studentUserId,
                            ':type' => 'complaint_update',
                            ':message' => 'Your complaint ' . (string)$row['ticket_no'] . ' has a new response. Current status: ' . strtoupper(str_replace('_', ' ', $status)) . '.',
                            ':ticket_type' => 'complaint',
                            ':ticket_id' => $complaintId,
                            ':is_read' => 0,
                        ]);
                    }

                    $flashMessage = 'Complaint response saved successfully.';
                    $flashType = 'success';
                } catch (PDOException $e) {
                    $flashMessage = 'Unable to update complaint.';
                    $flashType = 'error';
                }
            }
        }
    } elseif ($action === 'delete_complaint') {
        $complaintId = (int)($_POST['complaint_id'] ?? 0);

        if ($complaintId <= 0) {
            $flashMessage = 'Invalid complaint ID.';
            $flashType = 'error';
        } else {
            $row = is_manageable_complaint($pdo, $complaintId);
            if (!$row) {
                $flashMessage = 'Complaint not found or view-only complaints cannot be deleted.';
                $flashType = 'error';
            } else {
                try {
                    $deleteStmt = $pdo->prepare('DELETE FROM complaints WHERE id = :id');
                    $deleteStmt->execute([':id' => $complaintId]);
                    $flashMessage = 'Complaint deleted successfully.';
                    $flashType = 'success';
                } catch (PDOException $e) {
                    $flashMessage = 'Unable to delete complaint.';
                    $flashType = 'error';
                }
            }
        }
    }
}

$q = trim((string)($_GET['q'] ?? ''));
$date = trim((string)($_GET['date'] ?? ''));
$college = (int)($_GET['college'] ?? 0);
$status = trim((string)($_GET['status'] ?? ''));
$activeTab = strtolower(trim((string)($_GET['tab'] ?? 'manageable')));
if (!in_array($activeTab, ['manageable', 'view_only'], true)) {
    $activeTab = 'manageable';
}

$colleges = [];
$manageableComplaints = [];
$viewOnlyComplaints = [];

try {
    $collegeStmt = $pdo->query('SELECT id, code, name FROM colleges ORDER BY name ASC');
    $colleges = $collegeStmt->fetchAll(PDO::FETCH_ASSOC);

    $manageableComplaints = fetch_complaints_by_category_type($pdo, 'general', $q, $date, $college, $status);
    $viewOnlyComplaints = fetch_complaints_by_category_type($pdo, 'college', $q, $date, $college, $status);
} catch (PDOException $e) {
    if ($flashMessage === '') {
        $flashMessage = 'Unable to load complaints right now.';
        $flashType = 'error';
    }
}

$manageableCount = count($manageableComplaints);
$viewOnlyCount = count($viewOnlyComplaints);

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

/* ===== TABS NAVIGATION ===== */
.nav-tabs {
    display: flex;
    gap: 40px;
    overflow-x: auto;
    flex-grow: 1;
    background: #fff;
    padding: 0 20px;
    border-radius: 12px 12px 0 0;
    border-bottom: 2px solid #e5e7eb;
}

.nav-tabs::-webkit-scrollbar { display: none; }

.tab-btn {
    background: none;
    border: none;
    padding: 18px 0;
    font-size: 14.5px;
    font-weight: 500;
    color: #6b7280;
    cursor: pointer;
    position: relative;
    transition: 0.3s;
    white-space: nowrap;
    font-family: 'Poppins', sans-serif;
    display: flex;
    align-items: center;
    gap: 10px;
}

.tab-btn:hover { color: #6d28d9; }
.tab-btn.active {
    color: #6d28d9;
    font-weight: 600;
}

.tab-btn.active::after {
    content: '';
    position: absolute;
    bottom: -1px;
    left: 0;
    width: 100%;
    height: 3px;
    background: #6d28d9;
    border-radius: 3px 3px 0 0;
    box-shadow: 0 -2px 5px rgba(109, 40, 217, 0.25);
}

.tab-count {
    min-width: 24px;
    height: 22px;
    padding: 0 8px;
    border-radius: 999px;
    background: #ede9fe;
    color: #6d28d9;
    font-size: 11px;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.tab-count.gray {
    background: #f3f4f6;
    color: #4b5563;
}

.tab-panel {
    display: none;
    animation: fadeInUp 0.3s ease-out;
}

.tab-panel.active {
    display: block;
}

.table-card-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 14px;
    margin-bottom: 16px;
    flex-wrap: wrap;
}

.table-card-title {
    font-size: 16px;
    font-weight: 600;
    color: #1f2937;
}

.table-card-subtitle {
    font-size: 12.5px;
    color: #6b7280;
}

.scope-badge {
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.scope-general {
    background: #ede9fe;
    color: #6d28d9;
}

.scope-college {
    background: #e0f2fe;
    color: #0369a1;
}

.tab-link.active {
    color: #6d28d9;
    border-bottom-color: #6d28d9;
    font-weight: 600;
}

.tab-badge {
    background: #ef4444;
    color: #fff;
    font-size: 11px;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 12px;
    min-width: 20px;
    text-align: center;
}

.tab-badge.gray {
    background: #6b7280;
}

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
.status-dismissed { background: #e5e7eb; color: #4b5563; }

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

/* Ensure action buttons are clickable above table rows or overlays */
.btn-icon, .btn-manage, .btn-view {
    position: relative;
    z-index: 2;
    pointer-events: auto;
}

.btn-view    { background: #6d28d9; }
.btn-view:hover    { background: #5d1fa0; }
.btn-manage { background: #6d28d9; }
.btn-manage:hover { background: #5d1fa0; }
.btn-reply { background: #2563eb; }
.btn-reply:hover { background: #1d4ed8; }
.btn-resolve { background: #10b981; }
.btn-resolve:hover { background: #059669; }
.btn-status { background: #f59e0b; }
.btn-status:hover { background: #d97706; }
.btn-delete { background: #ef4444; }
.btn-delete:hover { background: #dc2626; }
.btn-approve { background: #10b981; }
.btn-approve:hover { background: #059669; }
.btn-reject  { background: #ef4444; }
.btn-reject:hover { background: #dc2626; }

.btn-icon[disabled] {
    opacity: 0.55;
    cursor: not-allowed;
}

.no-data {
    text-align: center;
    color: #6b7280;
    padding: 28px 0;
    font-size: 13px;
}

/* Manageable tab layout refinements */
#manageable .table-card {
    padding: 16px 18px 10px;
    overflow-x: hidden;
    overflow-y: hidden;
}
#manageable table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
    min-width: 0;
}
#manageable table thead th {
    text-align: left;
    color: #6b7280;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    padding: 0 8px 10px;
    border-bottom: 1px solid #e5e7eb;
}
#manageable table tbody tr {
    transition: background-color 0.2s ease;
}
#manageable table tbody tr:hover {
    background-color: #f9fafb;
}
#manageable table td {
    vertical-align: middle;
    padding: 12px 8px;
    font-size: 13px;
    color: #374151;
    border-bottom: 1px solid #f3f4f6;
}
#manageable table th:nth-child(1), #manageable table td:nth-child(1) { width: 110px; }
#manageable table th:nth-child(2), #manageable table td:nth-child(2) { width: 100px; }
#manageable table th:nth-child(3), #manageable table td:nth-child(3) { width: auto; min-width: 260px; }
#manageable table th:nth-child(4), #manageable table td:nth-child(4) { width: 90px; }
#manageable table th:nth-child(5), #manageable table td:nth-child(5) { width: 130px; }
#manageable table th:nth-child(6), #manageable table td:nth-child(6) { width: 110px; }
#manageable table th:nth-child(7), #manageable table td:nth-child(7) { width: 96px; text-align: center; }

#manageable .cell-ticket,
#manageable .cell-date,
#manageable .cell-college,
#manageable .cell-category,
#manageable .cell-status,
#manageable .cell-action,
#manageable .subject-cell {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
#manageable .subject-cell {
    min-width: 0;
}
#manageable .subject-text {
    display: block;
    font-weight: 600;
    color: #111827;
    margin-bottom: 4px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
#manageable .small-text {
    color: #6b7280;
    font-size: 12px;
    line-height: 1.3;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
#manageable .status-badge {
    padding: 5px 9px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 72px;
}
#manageable .btn-manage {
    padding: 6px 8px;
    height: 34px;
    border-radius: 8px;
    min-width: 0;
    color: #fff;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    justify-content: center;
    position: relative !important;
    z-index: 2 !important;
    pointer-events: auto !important;
    cursor: pointer !important;
    isolation: isolate;
    white-space: nowrap;
    font-size: 12px;
    font-weight: 600;
    box-shadow: 0 6px 18px rgba(79,140,255,0.08);
}

@media (max-width: 900px) {
    #manageable .table-card { padding: 14px 12px 8px; }
    #manageable table { table-layout: auto; }
    #manageable table th:nth-child(1), #manageable table td:nth-child(1) { width: 92px; }
    #manageable table th:nth-child(2), #manageable table td:nth-child(2) { width: 86px; }
    #manageable table th:nth-child(3), #manageable table td:nth-child(3) { min-width: 200px; }
    #manageable table th:nth-child(4), #manageable table td:nth-child(4) { width: 78px; }
    #manageable table th:nth-child(5), #manageable table td:nth-child(5) { width: 110px; }
    #manageable table th:nth-child(6), #manageable table td:nth-child(6) { width: 96px; }
    #manageable table th:nth-child(7), #manageable table td:nth-child(7) { width: 86px; }
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

.badge-inline {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
}

.badge-admin {
    background: #ede9fe;
    color: #6d28d9;
}

.badge-dean {
    background: #e0f2fe;
    color: #0369a1;
}

@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
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

    .nav-tabs {
        gap: 20px;
    }
}
</style>
<?php echo complaint_age_styles(); ?>
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
        </div>

        <?php if ($flashMessage !== ''): ?>
            <div class="flash <?php echo $flashType === 'success' ? 'flash-success' : 'flash-error'; ?>">
                <?php echo e($flashMessage); ?>
            </div>
        <?php endif; ?>

        <div class="nav-tabs">
            <button type="button" class="tab-btn <?php echo $activeTab === 'manageable' ? 'active' : ''; ?>" onclick="switchTab(event, 'manageable')">
                Manageable Complaints <span class="tab-count"><?php echo (int)$manageableCount; ?></span>
            </button>
            <button type="button" class="tab-btn <?php echo $activeTab === 'view_only' ? 'active' : ''; ?>" onclick="switchTab(event, 'view_only')">
                View Only Complaints <span class="tab-count gray"><?php echo (int)$viewOnlyCount; ?></span>
            </button>
        </div>

        <form method="GET" class="controls-card">
            <input type="hidden" name="tab" id="activeTabInput" value="<?php echo e($activeTab); ?>">
            <div class="controls-top">
                <div class="search-box">
                    <i class='bx bx-search'></i>
                    <input type="text" name="q" value="<?php echo e($q); ?>" placeholder="Search Ticket, Student, Complaint...">
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
                    <label>Status</label>
                    <select name="status" class="filter-select">
                        <option value="">All Statuses</option>
                        <option value="under_review" <?php echo $status === 'under_review' ? 'selected' : ''; ?>>Under Review</option>
                        <option value="resolved" <?php echo $status === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                        <option value="dismissed" <?php echo $status === 'dismissed' ? 'selected' : ''; ?>>Dismissed</option>
                    </select>
                </div>
                <button class="btn-apply-filter" type="submit"><i class='bx bx-filter-alt'></i> Apply Filter</button>
                <a class="btn-reset" href="admin_complaints.php?tab=<?php echo e($activeTab); ?>">Reset</a>
            </div>
        </form>

        <?php
            $tabConfigs = [
                'manageable' => [
                    'title' => 'Manageable Complaints',
                    'subtitle' => 'General complaints and suggestions that the admin can manage directly.',
                    'badgeClass' => 'scope-general',
                    'badgeText' => 'Admin Managed',
                    'rows' => $manageableComplaints,
                    'canManage' => true,
                ],
                'view_only' => [
                    'title' => 'View Only Complaints',
                    'subtitle' => 'College complaints and suggestions assigned to deans.',
                    'badgeClass' => 'scope-college',
                    'badgeText' => 'Dean Managed',
                    'rows' => $viewOnlyComplaints,
                    'canManage' => false,
                ],
            ];
        ?>

        <?php foreach ($tabConfigs as $tabKey => $tabConfig): ?>
            <div id="<?php echo e($tabKey); ?>" class="tab-panel <?php echo $activeTab === $tabKey ? 'active' : ''; ?>">
                <div class="table-card">
                    <div class="table-card-head">
                        <div>
                            <div class="table-card-title"><?php echo e((string)$tabConfig['title']); ?></div>
                            <div class="table-card-subtitle"><?php echo e((string)$tabConfig['subtitle']); ?></div>
                        </div>
                        <span class="scope-badge <?php echo e((string)$tabConfig['badgeClass']); ?>">
                            <?php echo e((string)$tabConfig['badgeText']); ?>
                        </span>
                    </div>

                    <table>
                        <thead>
                            <tr>
                                <?php if ($tabKey === 'manageable'): ?>
                                    <th>Date Filed</th>
                                    <th>Subject & Submitter</th>
                                    <th>College</th>
                                    <th>Category</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                <?php else: ?>
                                    <th>Student</th>
                                    <th>College</th>
                                    <th>Category</th>
                                    <th>Submitted</th>
                                    <th>Status</th>
                                    <th>Management</th>
                                    <th>Actions</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$tabConfig['rows']): ?>
                                <tr>
                                    <td colspan="<?php echo $tabKey === 'manageable' ? 6 : 7; ?>" class="no-data">
                                        No <?php echo $tabKey === 'manageable' ? 'manageable' : 'view-only'; ?> complaints found for the selected filters.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($tabConfig['rows'] as $row): ?>
                                    <tr>
                                        <?php if ($tabKey === 'manageable'): ?>
                                            <td class="cell-date"><?php echo e(date('M d, Y', strtotime((string)$row['created_at']))); ?></td>
                                            <td class="subject-cell">
                                                <div class="subject-text"><?php echo e((string)$row['act_complained_of']); ?></div>
                                                <div class="small-text">
                                                    <?php
                                                        $submitter = 'Anonymous Student';
                                                        if ((int)$row['is_anonymous'] !== 1) {
                                                            $submitter = trim((string)$row['first_name'] . ' ' . (string)$row['last_name']);
                                                            if ($submitter === '') {
                                                                $submitter = 'Student';
                                                            }
                                                        }
                                                        echo e($submitter);
                                                    ?>
                                                </div>
                                            </td>
                                            <td class="cell-college"><?php echo e((string)$row['college_code']); ?></td>
                                            <td class="cell-category"><?php echo e((string)$row['category_name']); ?></td>
                                            <td class="cell-status"><span class="status-badge <?php echo complaint_status_badge((string)$row['status']); ?>"><?php echo e(ucfirst(str_replace('_', ' ', (string)$row['status']))); ?></span><div><?php echo complaint_age_badge((string)$row['created_at'], (string)$row['status']); ?></div></td>
                                            <td class="cell-action">
                                                <button class="btn-manage btn-view" type="button"
                                                    data-id="<?php echo (int)$row['id']; ?>"
                                                    data-ticket="<?php echo e((string)$row['ticket_no']); ?>"
                                                    data-act-complained="<?php echo e((string)$row['act_complained_of']); ?>"
                                                    data-narrative="<?php echo e((string)$row['narrative_report']); ?>"
                                                    data-attachment="<?php echo e(!empty($row['attachments']) ? '../' . (string)$row['attachments'] : ''); ?>"
                                                    data-complainant="<?php echo e((string)$row['complainant_name']); ?>"
                                                    data-complainant-address="<?php echo e((string)$row['complainant_address']); ?>"
                                                    data-complainant-sex="<?php echo e((string)$row['complainant_sex']); ?>"
                                                    data-complainant-age="<?php echo e((string)$row['complainant_age']); ?>"
                                                    data-complainant-civil-status="<?php echo e((string)$row['complainant_civil_status']); ?>"
                                                    data-complainant-contact="<?php echo e((string)$row['complainant_contact_details']); ?>"
                                                    data-person-complained="<?php echo e((string)$row['person_complained_of']); ?>"
                                                    data-date-incident="<?php echo e((string)$row['date_of_incident']); ?>"
                                                    data-time-incident="<?php echo e((string)$row['time_of_incident']); ?>"
                                                    data-place-incident="<?php echo e((string)$row['place_of_incident']); ?>"
                                                    data-category="<?php echo e((string)$row['category_name']); ?>"
                                                    data-can-manage="1"
                                                    data-action-mode="status"
                                                    data-default-status="<?php echo e((string)$row['status']); ?>"
                                                    data-feedback-id="<?php echo e((string)($row['feedback_id'] ?? '')); ?>"
                                                    data-feedback-satisfaction="<?php echo e((string)($row['feedback_satisfaction'] ?? '')); ?>"
                                                    data-feedback-comment="<?php echo e((string)($row['feedback_comment'] ?? '')); ?>"
                                                    data-feedback-created-at="<?php echo e((string)($row['feedback_created_at'] ?? '')); ?>"
                                                    data-student-id="<?php echo (int)($row['student_id'] ?? 0); ?>"
                                                    onclick="window.location.href='admin_complaints_details.php?id='+encodeURIComponent(this.dataset.id)">
                                                    <i class='bx bx-edit-alt'></i> Manage
                                                </button>
                                            </td>
                                        <?php else: ?>
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
                                                <span class="status-badge <?php echo complaint_status_badge((string)$row['status']); ?>">
                                                    <?php echo e(ucfirst(str_replace('_', ' ', (string)$row['status']))); ?>
                                                </span>
                                                <div><?php echo complaint_age_badge((string)$row['created_at'], (string)$row['status']); ?></div>
                                            </td>
                                            <td>
                                                <span class="badge-inline badge-dean">
                                                    <i class='bx bx-shield-quarter'></i>
                                                    Dean Managed
                                                </span>
                                            </td>
                                            <td>
                                                <div class="action-btns">
                                                    <button class="btn-icon btn-view" type="button"
                                                        data-id="<?php echo (int)$row['id']; ?>"
                                                        data-ticket="<?php echo e((string)$row['ticket_no']); ?>"
                                                        data-act-complained="<?php echo e((string)$row['act_complained_of']); ?>"
                                                        data-narrative="<?php echo e((string)$row['narrative_report']); ?>"
                                                        data-attachment="<?php echo e(!empty($row['attachments']) ? '../' . (string)$row['attachments'] : ''); ?>"
                                                        data-complainant="<?php echo e((string)$row['complainant_name']); ?>"
                                                        data-complainant-address="<?php echo e((string)$row['complainant_address']); ?>"
                                                        data-complainant-sex="<?php echo e((string)$row['complainant_sex']); ?>"
                                                        data-complainant-age="<?php echo e((string)$row['complainant_age']); ?>"
                                                        data-complainant-civil-status="<?php echo e((string)$row['complainant_civil_status']); ?>"
                                                        data-complainant-contact="<?php echo e((string)$row['complainant_contact_details']); ?>"
                                                        data-person-complained="<?php echo e((string)$row['person_complained_of']); ?>"
                                                        data-date-incident="<?php echo e((string)$row['date_of_incident']); ?>"
                                                        data-time-incident="<?php echo e((string)$row['time_of_incident']); ?>"
                                                        data-place-incident="<?php echo e((string)$row['place_of_incident']); ?>"
                                                        data-category="<?php echo e((string)$row['category_name']); ?>"
                                                        data-can-manage="0"
                                                        data-action-mode="status"
                                                        data-default-status="<?php echo e((string)$row['status']); ?>"
                                                        data-feedback-id="<?php echo e((string)($row['feedback_id'] ?? '')); ?>"
                                                        data-feedback-satisfaction="<?php echo e((string)($row['feedback_satisfaction'] ?? '')); ?>"
                                                        data-feedback-comment="<?php echo e((string)($row['feedback_comment'] ?? '')); ?>"
                                                        data-feedback-created-at="<?php echo e((string)($row['feedback_created_at'] ?? '')); ?>"
                                                        data-student-id="<?php echo (int)($row['student_id'] ?? 0); ?>"
                                                        onclick="window.location.href='admin_viewOnly_complaints.php?id=' + encodeURIComponent(this.dataset.id)">
                                                        <i class='bx bx-show'></i> View
                                                    </button>
                                                </div>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>

        <form id="deleteComplaintForm" method="POST" style="display:none;">
            <input type="hidden" name="csrf_token" value="<?php echo e((string)$_SESSION['csrf_token']); ?>">
            <input type="hidden" name="action" value="delete_complaint">
            <input type="hidden" name="complaint_id" id="deleteComplaintId">
        </form>
    </div>
</div>

<!-- View Complaint Modal -->
<div id="viewModal" class="modal">
    <div class="modal-card" style="max-width: 700px; max-height: 90vh; overflow-y: auto;">
        <div class="modal-header">
            <div class="modal-title" id="viewModalTitle">Complaint Details</div>
            <button class="modal-close" onclick="closeModal('viewModal')">✕</button>
        </div>
        <div class="modal-body">
            <!-- COMPLAINANT INFORMATION -->
            <fieldset style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                <legend style="font-weight: 600; color: #333; padding: 0 10px; font-size: 14px;">Complainant Information</legend>
                <div class="form-group">
                    <label style="font-size: 12px; color: #666; font-weight: 500;">Name</label>
                    <p id="viewComplainant" style="font-size: 14px; margin: 5px 0; padding: 8px 0;">N/A</p>
                </div>
                <div class="form-group">
                    <label style="font-size: 12px; color: #666; font-weight: 500;">Address</label>
                    <p id="viewComplainantAddress" style="font-size: 14px; margin: 5px 0; padding: 8px 0;">N/A</p>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label style="font-size: 12px; color: #666; font-weight: 500;">Sex</label>
                        <p id="viewComplainantSex" style="font-size: 14px; margin: 5px 0; padding: 8px 0;">N/A</p>
                    </div>
                    <div class="form-group">
                        <label style="font-size: 12px; color: #666; font-weight: 500;">Age</label>
                        <p id="viewComplainantAge" style="font-size: 14px; margin: 5px 0; padding: 8px 0;">N/A</p>
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label style="font-size: 12px; color: #666; font-weight: 500;">Civil Status</label>
                        <p id="viewComplainantCivilStatus" style="font-size: 14px; margin: 5px 0; padding: 8px 0;">N/A</p>
                    </div>
                    <div class="form-group">
                        <label style="font-size: 12px; color: #666; font-weight: 500;">Contact Details</label>
                        <p id="viewComplainantContact" style="font-size: 14px; margin: 5px 0; padding: 8px 0;">N/A</p>
                    </div>
                </div>
            </fieldset>

            <!-- PERSON COMPLAINED OF -->
            <fieldset style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                <legend style="font-weight: 600; color: #333; padding: 0 10px; font-size: 14px;">Person/Office Complained Of</legend>
                <div class="form-group">
                    <label style="font-size: 12px; color: #666; font-weight: 500;">Name</label>
                    <p id="viewPersonComplained" style="font-size: 14px; margin: 5px 0; padding: 8px 0;">N/A</p>
                </div>
            </fieldset>

            <!-- INCIDENT DETAILS -->
            <fieldset style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                <legend style="font-weight: 600; color: #333; padding: 0 10px; font-size: 14px;">Incident Details</legend>
                <div class="form-group">
                    <label style="font-size: 12px; color: #666; font-weight: 500;">Ticket Number</label>
                    <p id="viewTicketNo" style="font-size: 14px; margin: 5px 0; padding: 8px 0; font-weight: 600; color: #4F8CFF;">N/A</p>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label style="font-size: 12px; color: #666; font-weight: 500;">Date of Incident</label>
                        <p id="viewDateIncident" style="font-size: 14px; margin: 5px 0; padding: 8px 0;">N/A</p>
                    </div>
                    <div class="form-group">
                        <label style="font-size: 12px; color: #666; font-weight: 500;">Time of Incident</label>
                        <p id="viewTimeIncident" style="font-size: 14px; margin: 5px 0; padding: 8px 0;">N/A</p>
                    </div>
                </div>
                <div class="form-group">
                    <label style="font-size: 12px; color: #666; font-weight: 500;">Place of Incident</label>
                    <p id="viewPlaceIncident" style="font-size: 14px; margin: 5px 0; padding: 8px 0;">N/A</p>
                </div>
                <div class="form-group">
                    <label style="font-size: 12px; color: #666; font-weight: 500;">Category</label>
                    <p id="viewCategory" style="font-size: 14px; margin: 5px 0; padding: 8px 0;">N/A</p>
                </div>
                <div class="form-group">
                    <label style="font-size: 12px; color: #666; font-weight: 500;">Act/s Complained Of</label>
                    <p id="viewActComplained" style="font-size: 14px; margin: 5px 0; padding: 8px 0;">N/A</p>
                </div>
                <div class="form-group">
                    <label style="font-size: 12px; color: #666; font-weight: 500;">Narrative Report</label>
                    <div id="viewNarrative" style="font-size: 14px; margin: 5px 0; padding: 10px; background: #f9fafb; border-radius: 6px; min-height: 60px;">N/A</div>
                </div>
            </fieldset>

            <!-- PROOF OF COMPLAINT -->
            <fieldset style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                <legend style="font-weight: 600; color: #333; padding: 0 10px; font-size: 14px;">Proof of Complaint</legend>
                <div class="form-group" id="attachmentGroup" style="display:none;">
                    <label style="font-size: 12px; color: #666; font-weight: 500;">Attachment</label>
                    <img id="attachmentImage" src="" alt="Attachment" style="max-width: 100%; max-height: 300px; border-radius: 8px; cursor: pointer; margin-top: 10px; display: none;" onclick="window.open(this.src, '_blank')">
                    <a id="attachmentLink" target="_blank" style="
                        display: inline-flex;
                        align-items: center;
                        gap: 8px;
                        background: #4F8CFF;
                        color: white;
                        padding: 10px 20px;
                        border-radius: 6px;
                        text-decoration: none;
                        font-weight: 500;
                        margin-top: 10px;
                        transition: background 0.2s;
                    " onmouseover="this.style.background='#3b6fd1'" onmouseout="this.style.background='#4F8CFF'">
                        <i class='bx bx-download'></i>
                        <span>Download Attachment</span>
                    </a>
                </div>
                <p id="noAttachmentMsg" style="font-size: 13px; color: #9ca3af; font-style: italic; padding: 10px; background: #f9fafb; border-radius: 6px;">
                    <i class='bx bx-paperclip' style="margin-right: 5px;"></i>No attachment uploaded
                </p>
            </fieldset>
        </div>
        
        <!-- Admin Actions Section - Response only -->
        <div id="adminActionsSection" class="modal-body" style="border-top: 1px solid #e5e7eb; display: none;">
            <h4 id="adminActionsTitle" style="margin-bottom: 15px; color: #333;">Admin Response</h4>
            <p id="adminActionNote" style="font-size: 12px; color: #6b7280; margin-bottom: 14px; line-height: 1.5;">
                Add a reply or update the status for manageable complaints.
            </p>
            <form method="POST" id="adminActionForm">
                <input type="hidden" name="csrf_token" value="<?php echo e((string)$_SESSION['csrf_token']); ?>">
                <input type="hidden" name="complaint_id" id="actionComplaintId">
                <input type="hidden" name="action" value="update_complaint_status">
                
                <div class="form-group">
                    <label>Response to Student <span style="color: #ef4444;">*</span></label>
                    <textarea class="form-input textarea" name="remarks" placeholder="Type the response or next steps for the student..." required></textarea>
                </div>
                
                <div class="form-group">
                    <label>Status <span style="color: #ef4444;">*</span></label>
                    <select name="status" class="form-input" required>

                </div>
                
                <div class="form-group">
                    <button type="submit" class="btn-submit" style="background: #4F8CFF; width: 100%;">
                        <i class='bx bx-send'></i> Save Response
                    </button>
                </div>
            </form>

            <fieldset id="viewFeedbackSection" style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 15px; margin-top: 20px; display: none;">
                <legend style="font-weight: 600; color: #333; padding: 0 10px; font-size: 14px;">Student Feedback</legend>
                <div style="display:flex; gap:12px; align-items:center; margin-bottom:8px;">
                    <div id="viewFeedbackBadge" style="padding:6px 10px; border-radius:12px; font-weight:700; font-size:13px;"></div>
                    <div id="viewFeedbackTime" style="font-size:12px; color:#6b7280;"></div>
                </div>
                <div id="viewFeedbackComment" style="background:#f9fafb; padding:12px; border-radius:12px; color:#374151; white-space:pre-wrap;">-</div>
                <div id="feedbackRepliesContainer" style="display:none; margin-top:12px; padding:12px; border-radius:12px; background:#eef2ff;"></div>
                <button type="button" id="feedbackReplyToggle" style="display:none; margin-top:12px; padding:10px 16px; border:1px solid #c7d2fe; background:#eff6ff; color:#1d4ed8; border-radius:8px; cursor:pointer; font-weight:600;">
                    <i class='bx bx-reply'></i> Reply
                </button>
                <div id="feedbackReplyFormWrapper" style="display:none; margin-top:12px; padding:12px; border:1px solid #e5e7eb; border-radius:12px; background:#ffffff;">
                    <form id="feedbackReplyForm" method="POST" style="display:flex; flex-direction:column; gap:12px;">
                        <input type="hidden" name="csrf_token" value="<?php echo e((string)$_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="ticket_type" id="feedbackReplyTicketType" value="complaint">
                        <input type="hidden" name="ticket_id" id="feedbackReplyTicketId" value="">
                        <input type="hidden" name="student_id" id="feedbackReplyStudentId" value="">
                        <input type="hidden" name="feedback_history_id" id="feedbackReplyHistoryId" value="">
                        <textarea name="message" rows="4" required placeholder="Reply to the student about their feedback..." style="width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 10px; resize: vertical;"></textarea>
                        <button type="submit" class="btn-submit" style="background: #4F8CFF; margin-top: 8px; padding: 10px 16px; color: #fff; border: none; border-radius: 8px; cursor: pointer;"><i class='bx bx-send'></i> Send Reply</button>
                        <div id="feedbackReplyStatus" style="margin-top: 8px; font-size: 13px;"></div>
                    </form>
                </div>
            </fieldset>
        </div>
    </div>
</div>

<script>
function openComplaintModal(id, ticketNo, actComplained, narrativeReport, attachment, complainant, complainantAddress, complainantSex, complainantAge, complainantCivilStatus, complainantContact, personComplained, dateIncident, timeIncident, placeIncident, category, canManage, actionMode, defaultStatus, feedbackSatisfaction, feedbackComment, feedbackCreatedAt, feedbackId, studentId) {
    document.getElementById('viewModalTitle').innerText = 'Complaint #' + ticketNo;
    
    // Complainant Information
    document.getElementById('viewComplainant').innerText = complainant || 'N/A';
    document.getElementById('viewComplainantAddress').innerText = complainantAddress || 'N/A';
    document.getElementById('viewComplainantSex').innerText = complainantSex || 'N/A';
    document.getElementById('viewComplainantAge').innerText = complainantAge || 'N/A';
    document.getElementById('viewComplainantCivilStatus').innerText = complainantCivilStatus || 'N/A';
    document.getElementById('viewComplainantContact').innerText = complainantContact || 'N/A';
    
    // Person Complained Of
    document.getElementById('viewPersonComplained').innerText = personComplained || 'N/A';
    
    // Incident Details
    document.getElementById('viewTicketNo').innerText = ticketNo;
    document.getElementById('viewDateIncident').innerText = dateIncident || 'N/A';
    document.getElementById('viewTimeIncident').innerText = timeIncident || 'N/A';
    document.getElementById('viewPlaceIncident').innerText = placeIncident || 'N/A';
    document.getElementById('viewCategory').innerText = category || 'N/A';
    document.getElementById('viewActComplained').innerText = actComplained || 'N/A';
    document.getElementById('viewNarrative').innerText = narrativeReport || 'N/A';
    
    // Attachment
    const imgEl = document.getElementById('attachmentImage');
    const linkEl = document.getElementById('attachmentLink');
    if (attachment && attachment !== '') {
        document.getElementById('attachmentGroup').style.display = 'block';
        document.getElementById('noAttachmentMsg').style.display = 'none';
        // Check if image
        const ext = attachment.split('.').pop().toLowerCase();
        const isImage = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'].includes(ext);
        if (isImage) {
            imgEl.src = attachment;
            imgEl.style.display = 'block';
            linkEl.style.display = 'none';
        } else {
            imgEl.style.display = 'none';
            linkEl.style.display = 'inline-flex';
            linkEl.href = attachment;
        }
    } else {
        document.getElementById('attachmentGroup').style.display = 'none';
        document.getElementById('noAttachmentMsg').style.display = 'block';
    }

    const feedbackRepliesContainer = document.getElementById('feedbackRepliesContainer');
    const feedbackReplyToggle = document.getElementById('feedbackReplyToggle');
    const feedbackReplyFormWrapper = document.getElementById('feedbackReplyFormWrapper');
    const feedbackReplyTicketId = document.getElementById('feedbackReplyTicketId');
    const feedbackReplyStudentId = document.getElementById('feedbackReplyStudentId');
    const feedbackReplyHistoryId = document.getElementById('feedbackReplyHistoryId');
    const feedbackReplyStatus = document.getElementById('feedbackReplyStatus');
    const adminActionsSection = document.getElementById('adminActionsSection');
    const adminActionsTitle = document.getElementById('adminActionsTitle');
    const adminActionNote = document.getElementById('adminActionNote');
    const adminActionButton = document.querySelector('#adminActionForm .btn-submit');
    const statusSelect = document.querySelector('#adminActionForm select[name="status"]');

    document.getElementById('actionComplaintId').value = id;

    if (canManage) {
        adminActionsSection.style.display = 'block';
        document.getElementById('adminActionForm').style.display = 'block';

        if (statusSelect) {
            statusSelect.value = defaultStatus || 'under_review';
        }

        if (actionMode === 'reply') {
            adminActionsTitle.innerText = 'Reply to Complaint';
            adminActionNote.innerText = 'Send a response and set the complaint status if needed.';
            if (adminActionButton) {
                adminActionButton.innerHTML = '<i class="bx bx-send"></i> Send Reply';
            }
        } else if (actionMode === 'resolve') {
            adminActionsTitle.innerText = 'Resolve Complaint';
            adminActionNote.innerText = 'Mark this complaint as resolved and optionally add a closing note.';
            if (adminActionButton) {
                adminActionButton.innerHTML = '<i class="bx bx-check-circle"></i> Mark Resolved';
            }
            if (statusSelect) {
                statusSelect.value = 'resolved';
            }
        } else {
            adminActionsTitle.innerText = 'Update Complaint Status';
            adminActionNote.innerText = 'Update the status and optionally add a reply for the student.';
            if (adminActionButton) {
                adminActionButton.innerHTML = '<i class="bx bx-transfer-alt"></i> Save Status';
            }
        }
    } else {
        adminActionsSection.style.display = 'none';
        document.getElementById('adminActionForm').style.display = 'block';
        if (adminActionButton) {
            adminActionButton.innerHTML = '<i class="bx bx-send"></i> Save Response';
        }
    }

    document.getElementById('viewModal').classList.add('show');

    // Student feedback
    const feedbackSection = document.getElementById('viewFeedbackSection');
    const feedbackBadge = document.getElementById('viewFeedbackBadge');
    const feedbackTime = document.getElementById('viewFeedbackTime');
    const feedbackCommentEl = document.getElementById('viewFeedbackComment');
    const sat = (feedbackSatisfaction || '').toLowerCase();
    const fcomment = feedbackComment || '';
    const fcreated = feedbackCreatedAt || '';

    if (sat && sat !== '') {
        feedbackSection.style.display = 'block';
        feedbackRepliesContainer.innerHTML = '';
        feedbackRepliesContainer.style.display = 'none';
        feedbackReplyToggle.style.display = 'inline-flex';
        feedbackReplyFormWrapper.style.display = 'none';
        feedbackReplyTicketId.value = id;
        feedbackReplyStudentId.value = studentId || '';
        feedbackReplyHistoryId.value = feedbackId || '';
        feedbackReplyStatus.innerHTML = '';

        if (sat === 'satisfied') {
            feedbackBadge.style.background = '#d1fae5'; feedbackBadge.style.color = '#059669';
            feedbackBadge.textContent = 'Satisfied';
        } else if (sat === 'neutral') {
            feedbackBadge.style.background = '#f3f4f6'; feedbackBadge.style.color = '#374151';
            feedbackBadge.textContent = 'Neutral';
        } else if (sat === 'not_satisfied' || sat === 'not satisfied') {
            feedbackBadge.style.background = '#fee2e2'; feedbackBadge.style.color = '#b91c1c';
            feedbackBadge.textContent = 'Not Satisfied';
        } else {
            feedbackBadge.style.background = '#f3f4f6'; feedbackBadge.style.color = '#374151';
            feedbackBadge.textContent = sat;
        }

        feedbackTime.textContent = fcreated ? ('Submitted: ' + fcreated) : '';
        feedbackCommentEl.textContent = fcomment ? fcomment : '-';

        feedbackReplyToggle.onclick = function () {
            feedbackReplyFormWrapper.style.display = feedbackReplyFormWrapper.style.display === 'none' ? 'block' : 'none';
        };

        loadFeedbackReplies('complaint', id, studentId || '', feedbackId || '');
    } else {
        feedbackSection.style.display = 'none';
        feedbackRepliesContainer.style.display = 'none';
        feedbackReplyToggle.style.display = 'none';
        feedbackReplyFormWrapper.style.display = 'none';
        feedbackReplyTicketId.value = '';
        feedbackReplyStudentId.value = '';
        feedbackReplyHistoryId.value = '';
        feedbackReplyStatus.innerHTML = '';
    }
}

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function renderFeedbackReplies(replies) {
    const container = document.getElementById('feedbackRepliesContainer');
    container.innerHTML = '';
    if (!Array.isArray(replies) || replies.length === 0) {
        container.style.display = 'none';
        return;
    }
    container.style.display = 'block';
    replies.forEach((reply) => {
        const item = document.createElement('div');
        item.style.marginBottom = '12px';
        item.innerHTML = `
            <div style="font-size:12px; color:#6b7280; margin-bottom:6px;">${escapeHtml(reply.replier_name || reply.replier_role || 'Admin').toUpperCase()} • ${escapeHtml(reply.created_at)}</div>
            <div style="background:#f8fafc; border:1px solid #e5e7eb; border-radius:12px; padding:14px; color:#111827; white-space:pre-wrap;">${escapeHtml(reply.message)}</div>
        `;
        container.appendChild(item);
    });
}

function loadFeedbackReplies(ticketType, ticketId, studentId, feedbackHistoryId) {
    const container = document.getElementById('feedbackRepliesContainer');
    if (!ticketType || !ticketId || !studentId) {
        container.style.display = 'none';
        return;
    }

    let url = `../process_feedback_reply.php?action=list_replies&ticket_type=${encodeURIComponent(ticketType)}&ticket_id=${encodeURIComponent(ticketId)}&student_id=${encodeURIComponent(studentId)}`;
    if (feedbackHistoryId) {
        url += `&feedback_history_id=${encodeURIComponent(feedbackHistoryId)}`;
    }

    fetch(url)
        .then((response) => response.ok ? response.json() : Promise.reject())
        .then((data) => {
            if (data.replies && Array.isArray(data.replies) && data.replies.length > 0) {
                renderFeedbackReplies(data.replies);
            } else {
                container.style.display = 'none';
            }
        })
        .catch(() => {
            container.style.display = 'none';
        });
}

function appendFeedbackReply(reply) {
    const container = document.getElementById('feedbackRepliesContainer');
    if (!container) return;
    if (container.style.display === 'none') {
        container.style.display = 'block';
        container.innerHTML = '';
    }
    const item = document.createElement('div');
    item.style.marginBottom = '12px';
    item.innerHTML = `
        <div style="font-size:12px; color:#6b7280; margin-bottom:6px;">${escapeHtml(reply.replier_name || reply.replier_role || 'Admin').toUpperCase()} • ${escapeHtml(reply.created_at)}</div>
        <div style="background:#f8fafc; border:1px solid #e5e7eb; border-radius:12px; padding:14px; color:#111827; white-space:pre-wrap;">${escapeHtml(reply.message)}</div>
    `;
    container.appendChild(item);
}

const adminComplaintFeedbackForm = document.getElementById('feedbackReplyForm');
if (adminComplaintFeedbackForm) {
    adminComplaintFeedbackForm.addEventListener('submit', function (event) {
        event.preventDefault();
        const form = event.currentTarget;
        const statusEl = document.getElementById('feedbackReplyStatus');
        const formData = new window.FormData(form);

        statusEl.textContent = 'Sending reply...';
        statusEl.style.color = '#374151';

        fetch('../process_feedback_reply.php', {
            method: 'POST',
            body: formData,
        }).then((response) => response.ok ? response.json() : Promise.reject())
          .then((data) => {
              if (data.status === 'ok') {
                  statusEl.textContent = 'Reply sent successfully.';
                  statusEl.style.color = '#059669';
                  form.querySelector('[name="message"]').value = '';
                  if (data.reply) {
                      appendFeedbackReply(data.reply);
                  }
              } else {
                  statusEl.textContent = 'Failed to send reply. Please try again.';
                  statusEl.style.color = '#b91c1c';
              }
          }).catch(() => {
              statusEl.textContent = 'Failed to send reply. Please try again.';
              statusEl.style.color = '#b91c1c';
          });
    });
}

function confirmDeleteComplaint(id, ticketNo) {
    if (!window.confirm('Delete complaint #' + ticketNo + '? This action cannot be undone.')) {
        return;
    }

    const deleteForm = document.getElementById('deleteComplaintForm');
    const deleteId = document.getElementById('deleteComplaintId');
    if (!deleteForm || !deleteId) {
        return;
    }

    deleteId.value = id;
    deleteForm.submit();
}

function switchTab(evt, tabId) {
    const panels = document.querySelectorAll('.tab-panel');
    const buttons = document.querySelectorAll('.tab-btn');
    const input = document.getElementById('activeTabInput');

    panels.forEach(panel => panel.classList.remove('active'));
    buttons.forEach(button => button.classList.remove('active'));

    const targetPanel = document.getElementById(tabId);
    if (targetPanel) {
        targetPanel.classList.add('active');
    }

    if (evt && evt.currentTarget) {
        evt.currentTarget.classList.add('active');
    }

    if (input) {
        input.value = tabId;
    }

    const current = window.location.href.split('?')[0];
    const rawQuery = window.location.search.replace(/^\?/, '');
    const pairs = rawQuery ? rawQuery.split('&').filter(Boolean) : [];
    const filteredPairs = pairs.filter(function(pair) {
        return pair.split('=')[0] !== 'tab';
    });
    filteredPairs.push('tab=' + encodeURIComponent(tabId));
    const queryString = filteredPairs.length > 0 ? '?' + filteredPairs.join('&') : '';
    window.history.replaceState({}, '', current + queryString);
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

document.querySelectorAll('.btn-manage.btn-view, .btn-icon.btn-view').forEach((btn) => {
    btn.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        openComplaintModal(
            btn.dataset.id,
            btn.dataset.ticket,
            btn.dataset.actComplained,
            btn.dataset.narrative,
            btn.dataset.attachment,
            btn.dataset.complainant,
            btn.dataset.complainantAddress,
            btn.dataset.complainantSex,
            btn.dataset.complainantAge,
            btn.dataset.complainantCivilStatus,
            btn.dataset.complainantContact,
            btn.dataset.personComplained,
            btn.dataset.dateIncident,
            btn.dataset.timeIncident,
            btn.dataset.placeIncident,
            btn.dataset.category,
            btn.dataset.canManage === '1' || btn.dataset.canManage === 'true',
            btn.dataset.actionMode || 'status',
            btn.dataset.defaultStatus || btn.dataset.status || 'under_review',
            btn.dataset.feedbackSatisfaction || '',
            btn.dataset.feedbackComment || '',
            btn.dataset.feedbackCreatedAt || ''
        );
    });
});
</script>

</body>
</html>
