<?php
session_start();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../ticket_flow.php';
require_once __DIR__ . '/../complaint_age_helpers.php';
require_once __DIR__ . '/../complaint_ai_helpers.php';
ai_ensure_ai_tables($pdo);

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

// Opportunistic SLA check - notifies the handling dean/admin once a
// complaint has sat untouched for 24+ hours. Best-effort, runs on page load.
check_and_send_complaint_overdue_notifications($pdo);

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

function fetch_complaints_by_category_type(PDO $pdo, string $categoryType, string $q, string $dateFrom, string $dateTo, int $college, int $department, string $status, string $sortBy = 'date'): array
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
    c.urgency_level,
    c.urgency_score,
    c.ai_detected_language,
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
    sp.year_level,
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

$sql = str_replace(
    'WHERE COALESCE(cc.category_type, cc.route, \'general\') = :category_type',
    $categoryType === 'college'
        ? "WHERE (c.college_id IS NOT NULL OR COALESCE(cc.category_type, cc.route, 'general') = 'college')"
        : "WHERE (c.college_id IS NULL AND COALESCE(cc.category_type, cc.route, 'general') <> 'college')",
    $sql
);
$params = [];

    if ($q !== '') {
        $sql .= ' AND (c.ticket_no LIKE :q_ticket OR c.complainant_name LIKE :q_name OR c.act_complained_of LIKE :q_subject OR c.narrative_report LIKE :q_narrative OR CONCAT(COALESCE(sp.first_name,\'\'), " ", COALESCE(sp.last_name,\'\')) LIKE :q_student)';
        $searchLike = '%' . $q . '%';
        $params[':q_ticket'] = $searchLike;
        $params[':q_name'] = $searchLike;
        $params[':q_subject'] = $searchLike;
        $params[':q_narrative'] = $searchLike;
        $params[':q_student'] = $searchLike;
    }

    if ($dateFrom !== '') {
        $sql .= ' AND c.created_at >= :date_from';
        $params[':date_from'] = $dateFrom . ' 00:00:00';
    }

    if ($dateTo !== '') {
        $dateToExclusive = (new DateTimeImmutable($dateTo))->modify('+1 day')->format('Y-m-d 00:00:00');
        $sql .= ' AND c.created_at < :date_to';
        $params[':date_to'] = $dateToExclusive;
    }

    if ($college > 0) {
        $sql .= ' AND c.college_id = :college_id';
        $params[':college_id'] = $college;
    }

    if ($department > 0) {
        $sql .= ' AND sp.program_id = :department_id';
        $params[':department_id'] = $department;
    }

    if ($status !== '') {
        $sql .= ' AND c.status = :status';
        $params[':status'] = $status;
    }

    $sql .= $sortBy === 'urgency'
        ? ' ORDER BY c.urgency_score DESC, c.created_at DESC'
        : ' ORDER BY c.created_at DESC';

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
                                ':message' => 'Your complaint has a new response. Current status: ' . strtoupper(str_replace('_', ' ', $status)) . '.',
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
                            ':message' => 'Your complaint has a new response. Current status: ' . strtoupper(str_replace('_', ' ', $status)) . '.',
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
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$legacyDate = trim((string)($_GET['date'] ?? ''));
if ($dateFrom === '' && $dateTo === '' && $legacyDate !== '') {
    $dateFrom = $legacyDate;
    $dateTo = $legacyDate;
}
foreach (['dateFrom', 'dateTo'] as $dateVariable) {
    if ($$dateVariable !== '') {
        $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $$dateVariable);
        if ($parsedDate === false || $parsedDate->format('Y-m-d') !== $$dateVariable) {
            $$dateVariable = '';
        }
    }
}
if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}
$college = (int)($_GET['college'] ?? 0);
$department = (int)($_GET['department'] ?? 0);
$departments = [];
if ($college <= 0) {
    $department = 0;
}
$status = trim((string)($_GET['status'] ?? ''));
$sortBy = (string)($_GET['sort'] ?? '') === 'urgency' ? 'urgency' : 'date';
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
    if ($college > 0) {
        $departmentStmt = $pdo->prepare('SELECT id, code, name FROM programs WHERE college_id = :college_id AND status = "active" ORDER BY name ASC');
        $departmentStmt->execute([':college_id' => $college]);
        $departments = $departmentStmt->fetchAll(PDO::FETCH_ASSOC);
        $departmentIds = array_map(static fn(array $row): int => (int)$row['id'], $departments);
        if (!in_array($department, $departmentIds, true)) {
            $department = 0;
        }
    }

    $manageableComplaints = fetch_complaints_by_category_type($pdo, 'general', $q, $dateFrom, $dateTo, $college, $department, $status, $sortBy);
    $viewOnlyComplaints = fetch_complaints_by_category_type($pdo, 'college', $q, $dateFrom, $dateTo, $college, $department, $status, $sortBy);
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
    display: grid;
    grid-template-columns: 138px 138px minmax(180px, 1fr) minmax(180px, 1fr) 126px 150px auto;
    gap: 12px;
    align-items: end;
}

.controls-bottom.without-department {
    grid-template-columns: 138px 138px minmax(220px, 1fr) 126px 150px auto;
}

.filter-group { display: flex; flex-direction: column; gap: 5px; min-width: 0; }

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
    width: 100%;
    min-width: 0;
}

.controls-bottom .btn-reset {
    align-self: end;
    white-space: nowrap;
}

@media (max-width: 1050px) {
    .controls-bottom {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .controls-bottom .btn-reset {
        justify-content: center;
    }
}

@media (max-width: 600px) {
    /* Three filter dropdowns crammed into one row leaves each barely wide
       enough to show its own label on a phone - one full-width filter per
       row is far easier to read and tap correctly. */
    .controls-bottom,
    .controls-bottom.without-department {
        grid-template-columns: 1fr;
    }

    .controls-bottom .btn-reset {
        width: 100%;
    }
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

/* Keep action buttons in the normal table flow so they do not appear to float
   while scrolling and overlapping neighboring rows. */
.btn-icon, .btn-manage, .btn-view {
    position: static;
    z-index: auto;
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
    padding: 22px 24px 10px;
    overflow-x: auto;
    overflow-y: hidden;
    border: 1px solid #e5e7eb;
    box-shadow: 0 4px 16px rgba(15, 23, 42, 0.04);
}
#manageable .table-card-head {
    margin: 0 0 18px;
    align-items: flex-start;
}
#manageable .table-card-title {
    font-size: 17px;
    font-weight: 700;
}
#manageable .table-card-subtitle {
    margin-top: 4px;
    font-size: 12px;
    color: #94a3b8;
}
#manageable table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
    min-width: 920px;
}
#manageable table thead th {
    text-align: left;
    color: #64748b;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    padding: 0 16px 12px;
    border-bottom: 1px solid #e5e7eb;
}
#manageable table tbody tr {
    transition: background-color 0.18s ease, box-shadow 0.18s ease;
}
#manageable table tbody tr:hover {
    background-color: #fafbff;
}
#manageable table td {
    vertical-align: middle;
    padding: 16px;
    font-size: 13px;
    color: #374151;
    border-bottom: 1px solid #f3f4f6;
}
#manageable table th:nth-child(1), #manageable table td:nth-child(1) { width: 25%; }
#manageable table th:nth-child(2), #manageable table td:nth-child(2) { width: 14%; }
#manageable table th:nth-child(3), #manageable table td:nth-child(3) { width: 20%; }
#manageable table th:nth-child(4), #manageable table td:nth-child(4) { width: 15%; }
#manageable table th:nth-child(5), #manageable table td:nth-child(5) { width: 16%; }
#manageable table th:nth-child(6), #manageable table td:nth-child(6) { width: 10%; text-align: right; }
#manageable table th:nth-child(1), #manageable table td:nth-child(1) { width: 23%; }
#manageable table th:nth-child(2), #manageable table td:nth-child(2) { width: 13%; }
#manageable table th:nth-child(3), #manageable table td:nth-child(3) { width: 18%; }
#manageable table th:nth-child(4), #manageable table td:nth-child(4) { width: 10%; }
#manageable table th:nth-child(5), #manageable table td:nth-child(5) { width: 14%; }
#manageable table th:nth-child(6), #manageable table td:nth-child(6) { width: 12%; }
#manageable table th:nth-child(7), #manageable table td:nth-child(7) { width: 10%; text-align: right; }

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
    font-weight: 700;
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
    padding: 6px 11px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 72px;
}
#manageable .cell-status > div {
    margin-top: 5px;
    line-height: 1;
}
#manageable .cell-status .age-chip {
    margin-top: 0;
    font-size: 10px;
    padding: 3px 8px;
}
#manageable .btn-manage,
#manageable .btn-icon.btn-view {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    min-width: 0;
    height: 34px;
    padding: 0 14px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 600;
    white-space: nowrap;
    position: relative !important;
    z-index: 2 !important;
    pointer-events: auto !important;
    cursor: pointer !important;
    box-shadow: none;
    transform: none;
    transition: background-color .18s ease;
}
#manageable .btn-manage:hover,
#manageable .btn-icon.btn-view:hover {
    background: #5d1fa0;
    transform: none;
    box-shadow: none;
}

/* Keep manageable and view-only complaint tables inside the content card. */
.table-card { overflow-x: hidden; }
.table-card table { width: 100%; max-width: 100%; min-width: 0; table-layout: fixed; }
.table-card table thead th { padding-left: 8px; padding-right: 8px; }
.table-card table td { padding-left: 8px; padding-right: 8px; }
.table-card table .td-action { white-space: nowrap; }
.table-card table .btn-icon.btn-view,
.table-card table .btn-manage { min-width: 76px; padding-left: 8px; padding-right: 8px; }

@media (max-width: 900px) {
    #manageable .table-card {
        padding: 18px 14px 8px;
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
        overflow-x: hidden;
    }
    #manageable table {
        min-width: 0;
        width: 100%;
        max-width: 100%;
    }
    #manageable table thead th,
    #manageable table td { padding-left: 12px; padding-right: 12px; }
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

/* Extra field only meant for the mobile card view (e.g. the Manageable tab's
   "Management" badge, added purely for visual parity with View Only's card -
   the desktop table has no such column). */
.mobile-only-field { display: none; }

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
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box;
    }

    .dashboard-container {
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box;
    }

    .search-box {
        width: 100%;
        max-width: 100%;
    }

    .nav-tabs {
        gap: 20px;
    }

    .table-card {
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box;
        overflow-x: hidden !important;
    }

    /* Scrolling sideways through a 6-7 column table (with AI chips, status
       badges, and a "Manage"/"View" button in the mix) is hard to make
       sense of on a phone - one card per record instead, every column
       labeled, keeps everything readable without any horizontal scrolling. */
    .table-card table {
        min-width: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
    }
    .table-card table thead { display: none; }
    .table-card table, .table-card table tbody, .table-card table tr { display: block; width: 100% !important; max-width: 100% !important; }
    .table-card table td { display: block; width: 100% !important; max-width: 100% !important; box-sizing: border-box; }
    /* flex (not plain block) so the Manageable tab's fields can be put in
       the same visual order as View Only's card via the order property
       below, without touching the desktop table's actual column order. */
    .table-card table tbody tr {
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        border: 1px solid #eef0f3;
        border-radius: 10px;
        padding: 12px 14px;
        margin-bottom: 12px;
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box;
        min-width: 0;
    }
    .table-card table tbody tr:last-child { margin-bottom: 0; }
    .table-card table td {
        padding: 4px 0 !important;
        border-bottom: none;
        width: 100% !important;
        max-width: 100% !important;
        text-align: left !important;
        box-sizing: border-box;
    }
    .table-card table td[data-label="Management"],
    .table-card table td.mobile-only-field {
        display: block !important;
        width: 100% !important;
        text-align: left !important;
    }
    .table-card table td[data-label="Management"] .badge-inline,
    .table-card table td.mobile-only-field .badge-inline {
        margin-left: 0 !important;
        display: inline-flex !important;
        justify-content: flex-start !important;
        text-align: left !important;
    }
    .table-card table td[data-label]::before {
        content: attr(data-label);
        display: block;
        font-size: 11px;
        font-weight: 700;
        color: #9ca3af;
        text-transform: uppercase;
        letter-spacing: .03em;
        margin-bottom: 2px;
    }
    .table-card table td.td-action {
        padding-top: 8px !important;
        text-align: left;
        align-self: flex-start;
        display: block !important;
        width: 100% !important;
        justify-content: flex-start;
    }
    .table-card .mobile-only-field { display: block; padding-top: 8px !important; }

    /* Match View Only's card field order: Student, College, Category,
       Submitted, Status, Management, then the action button. */
    #manageable .subject-cell { order: 1; }
    #manageable .cell-college { order: 2; }
    #manageable .cell-category { order: 3; }
    #manageable .cell-date { order: 4; }
    #manageable .cell-status { order: 5; }
    #manageable .mobile-only-field { order: 6; }
    #manageable .td-action { order: 7; display: block !important; text-align: left; }

    /* The complaint subject line isn't part of View Only's card design -
       show just the submitter's name under the "Student" label, matching
       it exactly. The full complaint text is still one tap away. */
    #manageable .subject-cell .subject-text { display: none; }
    #manageable .subject-cell .small-text {
        color: #111827;
        font-size: 15px;
        font-weight: 500;
    }

    /* #manageable's "Manage" button is a bigger, chunkier button by design
       (unlike View Only's compact icon-link style) - stretched full width
       it turned into an oversized purple bar. Keep it at its normal,
       compact size instead, same as View Only's button already is. */
    .table-card table td.td-action .btn-manage,
    .table-card table td.td-action .btn-view {
        width: auto;
        height: 34px !important;
        min-width: 0 !important;
        padding: 0 14px !important;
        font-size: 12px !important;
        margin-left: 0 !important;
        display: inline-flex !important;
        align-self: flex-start !important;
        text-align: left !important;
    }

    /* #manageable's desktop styling truncates long text with an ellipsis
       to keep everything on one table row - once a row becomes a card
       there's no need to cut anything off, so let it wrap and read in
       full, same as the already-clean View Only tab. */
    #manageable .cell-ticket,
    #manageable .cell-date,
    #manageable .cell-college,
    #manageable .cell-category,
    #manageable .cell-status,
    #manageable .cell-action,
    #manageable .subject-cell,
    #manageable .subject-text,
    #manageable .small-text {
        white-space: normal !important;
        overflow: visible !important;
        text-overflow: clip !important;
    }

    /* Cards with wrapped, multi-line text are taller than a single table
       row - don't let the card's height get clipped. */
    #manageable .table-card { overflow-y: visible !important; }
}
.scroll-top-btn { position: fixed; right: 24px; bottom: 24px; width: 44px; height: 44px; border-radius: 999px; background: #6b46c1; color: #fff; border: none; display: none; align-items: center; justify-content: center; cursor: pointer; box-shadow: 0 10px 24px rgba(107, 70, 193, 0.35); z-index: 500; transition: background .15s ease, transform .15s ease, opacity .2s ease; opacity: 0; transform: translateY(8px); }
.scroll-top-btn.visible { display: flex; opacity: 1; transform: translateY(0); }
.scroll-top-btn:hover { background: #5b3aa8; }
.scroll-top-btn i { font-size: 22px; }
</style>
<?php echo complaint_age_styles(); ?>
<?php echo ai_urgency_styles(); ?>
<?php echo groq_language_styles(); ?>
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

        <form method="GET" class="controls-card" id="complaintFiltersForm">
            <input type="hidden" name="tab" id="activeTabInput" value="<?php echo e($activeTab); ?>">
            <div class="controls-top">
                <div class="search-box">
                    <i class='bx bx-search'></i>
                    <input type="text" name="q" value="<?php echo e($q); ?>" placeholder="Search Ticket, Student, Complaint...">
                </div>
            </div>

            <div class="controls-divider"></div>

            <div class="controls-bottom <?php echo $college > 0 ? 'with-department' : 'without-department'; ?>">
                <div class="filter-group">
                    <label>From Date</label>
                    <input type="date" name="date_from" class="filter-input" value="<?php echo e($dateFrom); ?>">
                </div>
                <div class="filter-group">
                    <label>To Date</label>
                    <input type="date" name="date_to" class="filter-input" value="<?php echo e($dateTo); ?>">
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
                <?php if ($college > 0): ?>
                    <div class="filter-group">
                        <label>Department</label>
                        <select name="department" class="filter-select">
                            <option value="0">All Departments</option>
                            <?php foreach ($departments as $d): ?>
                                <option value="<?php echo (int)$d['id']; ?>" <?php echo $department === (int)$d['id'] ? 'selected' : ''; ?>>
                                    <?php echo e((string)$d['code'] . ' - ' . (string)$d['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <div class="filter-group">
                    <label>Status</label>
                    <select name="status" class="filter-select">
                        <option value="">All Statuses</option>
                        <option value="under_review" <?php echo $status === 'under_review' ? 'selected' : ''; ?>>Under Review</option>
                        <option value="resolved" <?php echo $status === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                        <option value="dismissed" <?php echo $status === 'dismissed' ? 'selected' : ''; ?>>Dismissed</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Sort By</label>
                    <select name="sort" class="filter-select">
                        <option value="date" <?php echo $sortBy === 'date' ? 'selected' : ''; ?>>Newest First</option>
                        <option value="urgency" <?php echo $sortBy === 'urgency' ? 'selected' : ''; ?>>Most Urgent First</option>
                    </select>
                </div>
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
                                <th>Student</th>
                                <th>College</th>
                                <th>Category</th>
                                <th>Year</th>
                                <th>Submitted</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$tabConfig['rows']): ?>
                                <tr>
                                    <td colspan="7" class="no-data">
                                        No <?php echo $tabKey === 'manageable' ? 'manageable' : 'view-only'; ?> complaints found for the selected filters.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($tabConfig['rows'] as $row): ?>
                                    <tr>
                                        <?php if ($tabKey === 'manageable'): ?>
                                            <td class="subject-cell" data-label="Student">
                                                <?php
                                                    $submitter = 'Anonymous Student';
                                                    if ((int)$row['is_anonymous'] !== 1) {
                                                        $submitter = trim((string)$row['first_name'] . ' ' . (string)$row['last_name']);
                                                        if ($submitter === '') {
                                                            $submitter = 'Student';
                                                        }
                                                    }
                                                ?>
                                                <div class="subject-text" title="<?php echo e($submitter); ?>"><?php echo e($submitter); ?></div>
                                            </td>
                                            <td class="cell-college" data-label="College"><?php echo e((string)$row['college_code']); ?></td>
                                            <td class="cell-category" data-label="Category"><?php echo e((string)$row['category_name']); ?><?php echo groq_language_chip($row['ai_detected_language'] ?? null); ?></td>
                                            <td data-label="Year"><?php echo e((int)($row['year_level'] ?? 0) > 0 ? (string)$row['year_level'] : 'N/A'); ?></td>
                                            <td class="cell-date" data-label="Submitted"><?php echo e(date('M d, Y', strtotime((string)$row['created_at']))); ?></td>
                                            <?php
                                                $rowCreatedAt = (string)$row['created_at'];
                                                $rowStatus = (string)$row['status'];
                                                $rowStatusBadgeClass = complaint_new_status_badge_class($rowCreatedAt, $rowStatus) ?? complaint_status_badge($rowStatus);
                                                $rowStatusLabel = complaint_new_status_label($rowCreatedAt, $rowStatus) ?? ucfirst(str_replace('_', ' ', $rowStatus));
                                            ?>
                                            <td class="cell-status" data-label="Status"><span class="status-badge <?php echo e($rowStatusBadgeClass); ?>"><?php echo e($rowStatusLabel); ?></span><div><?php echo complaint_age_badge($rowCreatedAt, $rowStatus); ?><?php echo ai_urgency_chip($row['urgency_level'] ?? null); ?></div></td>
                                            <td class="cell-action td-action">
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
                                            <td data-label="Student" class="subject-cell">
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
                                            <td data-label="College"><?php echo e((string)$row['college_code']); ?></td>
                                            <td data-label="Category"><?php echo e((string)$row['category_name']); ?><?php echo groq_language_chip($row['ai_detected_language'] ?? null); ?></td>
                                            <td data-label="Year"><?php echo e((int)($row['year_level'] ?? 0) > 0 ? (string)$row['year_level'] : 'N/A'); ?></td>
                                            <td data-label="Submitted"><?php echo e(date('M d, Y', strtotime((string)$row['created_at']))); ?></td>
                                            <?php
                                                $rowCreatedAt = (string)$row['created_at'];
                                                $rowStatus = (string)$row['status'];
                                                $rowStatusBadgeClass = complaint_new_status_badge_class($rowCreatedAt, $rowStatus) ?? complaint_status_badge($rowStatus);
                                                $rowStatusLabel = complaint_new_status_label($rowCreatedAt, $rowStatus) ?? ucfirst(str_replace('_', ' ', $rowStatus));
                                            ?>
                                            <td data-label="Status">
                                                <span class="status-badge <?php echo e($rowStatusBadgeClass); ?>">
                                                    <?php echo e($rowStatusLabel); ?>
                                                </span>
                                                <div><?php echo complaint_age_badge($rowCreatedAt, $rowStatus); ?><?php echo ai_urgency_chip($row['urgency_level'] ?? null); ?></div>
                                            </td>
                                            <td class="td-action">
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


<button type="button" id="scrollTopBtn" class="scroll-top-btn" aria-label="Scroll to top" title="Back to top">
    <i class='bx bx-up-arrow-alt'></i>
</button>
<script>
function switchTab(event, tab) {
    if (event) {
        event.preventDefault();
    }

    var tabInput = document.getElementById('activeTabInput');
    var filtersForm = document.getElementById('complaintFiltersForm');
    if (!tabInput || !filtersForm) {
        return;
    }

    tabInput.value = tab;
    filtersForm.submit();
}

document.addEventListener('DOMContentLoaded', function () {
    var scrollTopBtn = document.getElementById('scrollTopBtn');
    if (!scrollTopBtn) return;
    var toggleScrollTopBtn = function () {
        if (window.scrollY > 300) {
            scrollTopBtn.classList.add('visible');
        } else {
            scrollTopBtn.classList.remove('visible');
        }
    };
    window.addEventListener('scroll', toggleScrollTopBtn, { passive: true });
    toggleScrollTopBtn();
    scrollTopBtn.addEventListener('click', function () {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
});
</script>

</body>
</html>
