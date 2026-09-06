<?php
session_start();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../ticket_flow.php';

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

function suggestion_status_badge(string $status): string
{
    if ($status === 'approved' || $status === 'reviewed') {
        return 'status-approved';
    }
    if ($status === 'rejected' || $status === 'declined') {
        return 'status-rejected';
    }
    if ($status === 'under_review') {
        return 'status-review';
    }

    return 'status-new';
}

$flashMessage = '';
$flashType = '';

// Tab selection: 'manageable' (admin-managed) or 'view_only' (college/dean managed)
$activeTab = strtolower(trim((string)($_GET['tab'] ?? 'manageable')));
if (!in_array($activeTab, ['manageable', 'view_only'], true)) {
    $activeTab = 'manageable';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'update_suggestion_status') {
    $token = (string)($_POST['csrf_token'] ?? '');
    $suggestionId = (int)($_POST['suggestion_id'] ?? 0);
    $status = trim((string)($_POST['status'] ?? ''));
    $remarks = trim((string)($_POST['remarks'] ?? ''));
    $visibilityStatus = isset($_POST['visibility_status']) && $_POST['visibility_status'] === 'public' ? 'public' : 'private';

    $allowedStatuses = ['under_review', 'reviewed'];

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $flashMessage = 'Invalid request token.';
        $flashType = 'error';
    } elseif ($suggestionId <= 0 || !in_array($status, $allowedStatuses, true)) {
        $flashMessage = 'Invalid update request.';
        $flashType = 'error';
    } elseif ($remarks === '') {
        $flashMessage = 'Response to student is required.';
        $flashType = 'error';
    } else {
        try {
            $verifyStmt = $pdo->prepare(
                'SELECT s.id, s.ticket_no, sp.user_id AS student_user_id
                 FROM suggestions s
                 LEFT JOIN student_profiles sp ON sp.id = s.student_id
                                         WHERE s.id = :id
                                             AND s.college_id IS NULL
                 LIMIT 1'
            );
            $verifyStmt->execute([':id' => $suggestionId]);
            $row = $verifyStmt->fetch();

            if (!$row) {
                $flashMessage = 'Suggestion not found.';
                $flashType = 'error';
            } else {
                // Update status using shared helper so related timestamps and rules are applied
                set_ticket_status($pdo, 'suggestion', $suggestionId, $status);

                // Save the mandatory reply only if the thread allows replies
                $threadState = get_ticket_status_state($pdo, 'suggestion', $suggestionId);
                if (!$threadState['can_reply']) {
                    $flashMessage = 'This suggestion thread is currently closed for new replies.';
                    $flashType = 'error';
                } else {
                    save_ticket_reply($pdo, 'suggestion', $suggestionId, (int)$_SESSION['user_id'], 'admin', $remarks);
                }

                $studentUserId = (int)($row['student_user_id'] ?? 0);
                if ($studentUserId > 0) {
                    $notifStmt = $pdo->prepare(
                        'INSERT INTO notifications (user_id, type, message, ticket_type, ticket_id, is_read)
                         VALUES (:user_id, :type, :message, :ticket_type, :ticket_id, :is_read)'
                    );
                    $notifStmt->execute([
                        ':user_id' => $studentUserId,
                        ':type' => 'suggestion_update',
                        ':message' => 'Your suggestion ' . (string)$row['ticket_no'] . ' has a new response. Current status: ' . strtoupper(str_replace('_', ' ', $status)) . '.',
                        ':ticket_type' => 'suggestion',
                        ':ticket_id' => $suggestionId,
                        ':is_read' => 0,
                    ]);
                }

                $flashMessage = 'Suggestion response saved successfully.';
                $flashType = 'success';
            }
        } catch (PDOException $e) {
            $flashMessage = 'Unable to update suggestion.';
            $flashType = 'error';
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

 $colleges = [];
 $suggestions = [];
 $manageableCount = count($manageableSuggestions ?? []);
 $viewOnlyCount = count($viewOnlySuggestions ?? []);

try {
    $collegeStmt = $pdo->query('SELECT id, code, name FROM colleges ORDER BY name ASC');
    $colleges = $collegeStmt->fetchAll();
    if ($college > 0) {
        $departmentStmt = $pdo->prepare('SELECT id, code, name FROM programs WHERE college_id = :college_id AND status = "active" ORDER BY name ASC');
        $departmentStmt->execute([':college_id' => $college]);
        $departments = $departmentStmt->fetchAll(PDO::FETCH_ASSOC);
        $departmentIds = array_map(static fn(array $row): int => (int)$row['id'], $departments);
        if (!in_array($department, $departmentIds, true)) {
            $department = 0;
        }
    }

    $sql =
        "SELECT
            s.id,
            s.ticket_no,
            s.created_at,
            s.subject,
            s.description,
            s.attachment,
            s.status,
            s.is_anonymous,
                COALESCE(sc.name, 'Uncategorized') AS category_name,
                COALESCE(col.code, col.name, scol.code, scol.name, 'N/A') AS college_code,
                col.id AS college_id,
                sp.id AS student_id,
            sp.first_name,
            sp.last_name,
            tf.id AS feedback_id,
            tf.satisfaction AS feedback_satisfaction,
            tf.comment AS feedback_comment,
            tf.created_at AS feedback_created_at
         FROM suggestions s
         LEFT JOIN suggestion_categories sc ON sc.id = s.category_id
         LEFT JOIN colleges col ON col.id = s.college_id
            LEFT JOIN student_profiles sp ON sp.id = s.student_id
            LEFT JOIN ticket_feedback tf ON tf.ticket_type = 'suggestion' AND tf.ticket_id = s.id AND tf.student_id = sp.id
            LEFT JOIN colleges scol ON scol.id = sp.college_id
         WHERE 1=1";

    $params = [];

    if ($q !== '') {
        $sql .= ' AND (s.ticket_no LIKE :q_ticket OR s.subject LIKE :q_subject OR s.description LIKE :q_description OR CONCAT(COALESCE(sp.first_name,\'\'), " ", COALESCE(sp.last_name,\'\')) LIKE :q_student)';
        $searchLike = '%' . $q . '%';
        $params[':q_ticket'] = $searchLike;
        $params[':q_subject'] = $searchLike;
        $params[':q_description'] = $searchLike;
        $params[':q_student'] = $searchLike;
    }

    if ($dateFrom !== '') {
        $sql .= ' AND DATE(s.created_at) >= :date_from';
        $params[':date_from'] = $dateFrom;
    }

    if ($dateTo !== '') {
        $sql .= ' AND DATE(s.created_at) <= :date_to';
        $params[':date_to'] = $dateTo;
    }

    if ($college > 0) {
        $sql .= ' AND s.college_id = :college_id';
        $params[':college_id'] = $college;
    }

    if ($department > 0) {
        $sql .= ' AND sp.program_id = :department_id';
        $params[':department_id'] = $department;
    }

    // Filter by active tab (using status for suggestions)
    if ($activeTab === 'pending') {
        $sql .= ' AND s.status IN ("new", "under_review")';
    } elseif ($activeTab === 'approved') {
        $sql .= ' AND s.status IN ("approved", "reviewed")';
    } elseif ($activeTab === 'history') {
        $sql .= ' AND s.status IN ("rejected", "declined")';
    }

    if ($status !== '') {
        $sql .= ' AND s.status = :status';
        $params[':status'] = $status;
    }

    $sql .= ' ORDER BY s.created_at DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $allSuggestions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Split suggestions into manageable (no college) and view-only (college assigned)
    $suggestions = $allSuggestions; // keep for backward compatibility
    $manageableSuggestions = [];
    $viewOnlySuggestions = [];
    foreach ($allSuggestions as $s) {
        if (empty($s['college_id'])) {
            $manageableSuggestions[] = $s;
        } else {
            $viewOnlySuggestions[] = $s;
        }
    }
} catch (PDOException $e) {
    if ($flashMessage === '') {
        $flashMessage = 'Unable to load suggestions right now.';
        $flashType = 'error';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>VOICE - Suggestions</title>
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
    max-width: 1200px;
    margin: 0 auto;
    width: 100%;
}

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

.details-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.03);
    padding: 18px;
    margin-bottom: 18px;
}

.details-card h3 {
    font-size: 16px;
    margin-bottom: 8px;
    color: #111827;
}

.details-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 8px 16px;
    margin-bottom: 8px;
    font-size: 13px;
}

.details-description {
    font-size: 13px;
    color: #374151;
    margin-top: 8px;
    white-space: pre-wrap;
}

.details-modal {
    position: fixed;
    inset: 0;
    background: rgba(17, 24, 39, 0.45);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 1200;
    padding: 16px;
}

.details-modal.show {
    display: flex;
}

.details-modal-card {
    width: min(760px, 100%);
    background: #fff;
    border-radius: 14px;
    box-shadow: 0 20px 45px rgba(0, 0, 0, 0.2);
    max-height: 90vh;
    overflow: auto;
}

.details-modal-header {
    padding: 16px 20px;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.details-modal-title {
    font-size: 17px;
    font-weight: 600;
    color: #111827;
}

.details-modal-close {
    border: none;
    background: #f3f4f6;
    color: #111827;
    width: 34px;
    height: 34px;
    border-radius: 8px;
    font-size: 18px;
    cursor: pointer;
}

.details-modal-body {
    padding: 18px 20px 22px;
}

.details-form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
    gap: 12px;
}

.details-field label {
    display: block;
    font-size: 11px;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    margin-bottom: 4px;
    font-weight: 600;
}

.details-value {
    width: 100%;
    border: 1px solid #e5e7eb;
    background: #f9fafb;
    border-radius: 8px;
    padding: 9px 10px;
    font-size: 13px;
    color: #111827;
    min-height: 38px;
}

.details-value.textarea {
    min-height: 120px;
    white-space: pre-wrap;
}

.details-attachment {
    margin-top: 12px;
}

.details-attachment a {
    color: #2563eb;
    text-decoration: none;
    font-size: 13px;
}

.details-attachment a:hover {
    text-decoration: underline;
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
    width: 320px;
    border: 1px solid #e5e7eb;
}

.search-box i { color: #888; margin-right: 10px; font-size: 18px; }
.search-box input { border: none; background: transparent; outline: none; width: 100%; font-size: 13px; }

.top-search-actions {
    display: flex;
    align-items: center;
    gap: 10px;
}

.btn-search {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    height: 38px;
    padding: 0 16px;
    border: none;
    border-radius: 8px;
    background: #111827;
    color: #fff;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
}

.btn-search:hover {
    background: #1f2937;
}

.controls-divider { width: 100%; height: 1px; background: #f0f0f0; }

.controls-bottom {
    display: grid;
    grid-template-columns: 138px 138px minmax(210px, 1.15fr) minmax(210px, 1.15fr) 126px auto;
    gap: 12px;
    align-items: end;
}

.controls-bottom.without-department {
    grid-template-columns: 138px 138px minmax(260px, 1fr) 126px auto;
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
    min-width: 150px;
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

.btn-apply-filter:hover { background: #3b6fd1; }

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

/* ===== TABS NAVIGATION ===== */
.tabs-nav {
    display: flex;
    gap: 40px;
    border-bottom: 2px solid #e5e7eb;
    margin-bottom: 20px;
    background: #fff;
    padding: 0 20px;
    border-radius: 12px 12px 0 0;
}

.tab-link {
    padding: 12px 24px;
    font-size: 14px;
    font-weight: 500;
    color: #6b7280;
    text-decoration: none;
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s;
}

.tab-link:hover {
    color: #374151;
}

.tab-link.active {
    color: #6d28d9;
    border-bottom-color: #6d28d9;
    font-weight: 600;
}

.tab-badge {
    background: #ede9fe;
    color: #6d28d9;
    font-size: 11px;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 12px;
    min-width: 20px;
    text-align: center;
}

.tab-badge.gray {
    background: #f3f4f6;
    color: #4b5563;
}

.table-card {
    background: #fff;
    padding: 25px;
    border-radius: 12px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.03);
    width: 100%;
    overflow-x: auto;
}

table { width: 100%; border-collapse: collapse; min-width: 860px; }

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
}

.status-new      { background: #e0f2fe; color: #0284c7; }
.status-review   { background: #fef3c7; color: #d97706; }
.status-approved { background: #d1fae5; color: #059669; }
.status-rejected { background: #fee2e2; color: #b91c1c; }

.action-btns { display: flex; gap: 8px; align-items: center; }

.btn-icon,
.btn-form {
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

.btn-view {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 6px 12px;
    min-width: 0;
    height: 34px;
    border-radius: 8px;
    color: #fff;
    font-size: 12px;
    font-weight: 600;
    background: #6d28d9;
    border: none;
    cursor: pointer;
}
.btn-view:hover {
    background: #5d1fa0;
}

/* Ensure action buttons are clickable above table rows or overlays */
.btn-icon, .btn-view, .btn-manage {
    position: relative !important;
    z-index: 9999 !important;
    pointer-events: auto !important;
    cursor: pointer !important;
    isolation: isolate;
}

td { position: relative; }

.btn-view    { background: #6d28d9; }
.btn-view:hover    { background: #5d1fa0; }
.btn-manage  { background: #6d28d9; }
.btn-manage:hover  { background: #5d1fa0; }
.btn-approve { background: #10b981; }
.btn-approve:hover { background: #059669; }
.btn-reject  { background: #ef4444; }
.btn-reject:hover  { background: #dc2626; }

.action-inline-form { display: inline-flex; }

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

/* Match the manageable complaints table hierarchy and spacing. */
#manageable.table-card {
    padding: 22px 24px 10px;
    overflow-x: auto;
    overflow-y: hidden;
    border: 1px solid #e5e7eb;
    box-shadow: 0 4px 16px rgba(15, 23, 42, 0.04);
}
#manageable .table-card-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 14px;
    margin: 0 0 18px;
}
#manageable .table-card-title { font-size: 17px; font-weight: 700; color: #1f2937; }
#manageable .table-card-subtitle { margin-top: 4px; font-size: 12px; color: #94a3b8; }
#manageable .scope-badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; white-space: nowrap; }
#manageable .scope-general { background: #ede9fe; color: #6d28d9; }
#manageable .scope-college { background: #e0f2fe; color: #0369a1; }
#manageable table { width: 100%; min-width: 920px; table-layout: fixed; }
#manageable table thead th { color: #64748b; font-size: 11px; font-weight: 700; padding: 0 16px 12px; border-bottom: 1px solid #e5e7eb; }
#manageable table tbody tr { transition: background-color .18s ease; }
#manageable table tbody tr:hover { background: #fafbff; }
#manageable table td { padding: 16px; vertical-align: middle; border-bottom: 1px solid #f3f4f6; }
#manageable table th:nth-child(1), #manageable table td:nth-child(1) { width: 116px; }
#manageable table th:nth-child(2), #manageable table td:nth-child(2) { width: 31%; }
#manageable table th:nth-child(3), #manageable table td:nth-child(3) { width: 120px; }
#manageable table th:nth-child(4), #manageable table td:nth-child(4) { width: 18%; }
#manageable table th:nth-child(5), #manageable table td:nth-child(5) { width: 150px; }
#manageable table th:nth-child(6), #manageable table td:nth-child(6) { width: 112px; text-align: right; }
#manageable .subject-text { font-weight: 700; }
#manageable .subject-text, #manageable .small-text, #manageable .cell-college, #manageable .cell-category { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
#manageable .status-badge { padding: 6px 11px; border-radius: 999px; min-width: 80px; }
#manageable .cell-action { text-align: right !important; }
#manageable .btn-manage, #manageable .btn-open-view-only { min-width: 96px; height: 38px; padding: 8px 11px; border-radius: 8px; justify-content: center; box-shadow: 0 5px 12px rgba(109, 40, 217, 0.14); transition: transform .18s ease, background-color .18s ease, box-shadow .18s ease; }
#manageable .btn-manage:hover, #manageable .btn-open-view-only:hover { background: #5d1fa0; transform: translateY(-1px); box-shadow: 0 7px 16px rgba(109, 40, 217, 0.2); }
#manageable .table-card-head + table { margin-top: 0; }

#view_only.table-card {
    padding: 22px 24px 10px;
    overflow-x: auto;
    overflow-y: hidden;
    border: 1px solid #e5e7eb;
    box-shadow: 0 4px 16px rgba(15, 23, 42, 0.04);
}
#view_only .table-card-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 14px;
    margin: 0 0 18px;
}
#view_only .table-card-title { font-size: 17px; font-weight: 700; color: #1f2937; }
#view_only .table-card-subtitle { margin-top: 4px; font-size: 12px; color: #94a3b8; }
#view_only .scope-badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; white-space: nowrap; }
#view_only .scope-college { background: #e0f2fe; color: #0369a1; }
#view_only table { width: 100%; min-width: 920px; table-layout: fixed; }
#view_only table thead th { color: #64748b; font-size: 11px; font-weight: 700; padding: 0 16px 12px; border-bottom: 1px solid #e5e7eb; }
#view_only table tbody tr { transition: background-color .18s ease; }
#view_only table tbody tr:hover { background: #fafbff; }
#view_only table td { padding: 16px; vertical-align: middle; border-bottom: 1px solid #f3f4f6; }
#view_only table th:nth-child(1), #view_only table td:nth-child(1) { width: 116px; }
#view_only table th:nth-child(2), #view_only table td:nth-child(2) { width: 31%; }
#view_only table th:nth-child(3), #view_only table td:nth-child(3) { width: 120px; }
#view_only table th:nth-child(4), #view_only table td:nth-child(4) { width: 18%; }
#view_only table th:nth-child(5), #view_only table td:nth-child(5) { width: 150px; }
#view_only table th:nth-child(6), #view_only table td:nth-child(6) { width: 112px; text-align: right; }
#view_only .subject-text { font-weight: 700; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
#view_only .small-text, #view_only .cell-college, #view_only .cell-category { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
#view_only .status-badge { padding: 6px 11px; border-radius: 999px; min-width: 80px; }
#view_only .cell-action { text-align: right !important; }
#view_only .btn-open-view-only { min-width: 96px; height: 38px; padding: 8px 11px; border-radius: 8px; justify-content: center; box-shadow: 0 5px 12px rgba(109, 40, 217, 0.14); transition: transform .18s ease, background-color .18s ease, box-shadow .18s ease; }
#view_only .btn-open-view-only:hover { background: #5d1fa0; transform: translateY(-1px); box-shadow: 0 7px 16px rgba(109, 40, 217, 0.2); }

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

@media (max-width: 768px) {
    .main {
        margin-left: 0;
        padding: 14px;
        margin-top: 61px;
    }

    .search-box {
        width: 100%;
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
            <h2>Suggestions Management</h2>
        </div>

        <?php if ($flashMessage !== ''): ?>
            <div class="flash <?php echo $flashType === 'success' ? 'flash-success' : 'flash-error'; ?>">
                <?php echo e($flashMessage); ?>
            </div>
        <?php endif; ?>

        <div class="tabs-nav">
            <a href="admin_suggestions.php?tab=manageable" class="tab-link <?php echo $activeTab === 'manageable' ? 'active' : ''; ?>">Manageable Suggestions <span class="tab-badge"><?php echo (int)($manageableCount ?? 0); ?></span></a>
            <a href="admin_suggestions.php?tab=view_only" class="tab-link <?php echo $activeTab === 'view_only' ? 'active' : ''; ?>">View Only Suggestions <span class="tab-badge gray"><?php echo (int)($viewOnlyCount ?? 0); ?></span></a>
        </div>

        <form method="GET" class="controls-card" id="suggestionFiltersForm">
            <input type="hidden" name="tab" value="<?php echo e($activeTab); ?>">
            <div class="controls-top">
                <div class="top-search-actions">
                    <div class="search-box">
                        <i class='bx bx-search'></i>
                        <input type="text" name="q" value="<?php echo e($q); ?>" placeholder="Search Ticket, Student Name, Subject...">
                    </div>
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
                        <option value="reviewed" <?php echo $status === 'reviewed' ? 'selected' : ''; ?>>Reviewed</option>
                    </select>
                </div>
                <a class="btn-reset" href="admin_suggestions.php?tab=<?php echo e($activeTab); ?>">Reset</a>
            </div>
        </form>

        <?php $tabConfigs = [
            'manageable' => ['rows' => $manageableSuggestions ?? [], 'canManage' => true],
            'view_only' => ['rows' => $viewOnlySuggestions ?? [], 'canManage' => false],
        ]; ?>

        <?php foreach ($tabConfigs as $tabKey => $tabConfig): ?>
            <div id="<?php echo e($tabKey); ?>" class="table-card" style="display: <?php echo $activeTab === $tabKey ? 'block' : 'none'; ?>;">
                <div class="table-card-head">
                    <div>
                        <div class="table-card-title"><?php echo $tabKey === 'manageable' ? 'Manageable Suggestions' : 'View Only Suggestions'; ?></div>
                        <div class="table-card-subtitle"><?php echo $tabKey === 'manageable' ? 'General suggestions that the admin can manage directly.' : 'College suggestions assigned to deans.'; ?></div>
                    </div>
                    <span class="scope-badge <?php echo $tabKey === 'manageable' ? 'scope-general' : 'scope-college'; ?>">
                        <?php echo $tabKey === 'manageable' ? 'Admin Managed' : 'Dean Managed'; ?>
                    </span>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Date Filed</th>
                            <th>Subject & Submitter</th>
                            <th>College</th>
                            <th>Topic</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($tabConfig['rows'])): ?>
                            <tr>
                                <td colspan="6" class="no-data">No <?php echo $tabKey === 'manageable' ? 'manageable' : 'view-only'; ?> suggestions found for the selected filters.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($tabConfig['rows'] as $row): ?>
                                <tr>
                                    <td class="cell-date"><?php echo e(date('M d, Y', strtotime((string)$row['created_at']))); ?></td>
                                    <td class="subject-cell">
                                        <div class="subject-text" title="<?php echo e((string)$row['subject']); ?>"><?php echo e((string)$row['subject']); ?></div>
                                        <div class="small-text">
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
                                        </div>
                                    </td>
                                    <td class="cell-college"><?php echo e((string)$row['college_code']); ?></td>
                                    <td class="cell-category"><?php echo e((string)$row['category_name']); ?></td>
                                    <td class="cell-status"><span class="status-badge <?php echo suggestion_status_badge((string)$row['status']); ?>"><?php echo e(ucwords(str_replace('_', ' ', (string)$row['status']))); ?></span></td>
                                    <td class="cell-action" style="text-align:center;">
                                        <?php if ($tabConfig['canManage']): ?>
                                            <button
                                                type="button"
                                                class="btn-manage btn-view"
                                                data-suggestion-id="<?php echo (int)$row['id']; ?>"
                                            >Manage</button>
                                        <?php else: ?>
                                            <button
                                                type="button"
                                                class="btn-view btn-open-view-only"
                                                data-suggestion-id="<?php echo (int)$row['id']; ?>"
                                            ><i class="bx bx-show"></i> View</button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>

    </div>
</div>

<div class="details-modal" id="detailsModal" aria-hidden="true">
    <div class="details-modal-card" role="dialog" aria-modal="true" aria-labelledby="detailsModalTitle">
        <div class="details-modal-header">
            <div class="details-modal-title" id="detailsModalTitle">Suggestion Details</div>
            <button class="details-modal-close" id="detailsModalClose" type="button">&times;</button>
        </div>
        <div class="details-modal-body">
            <div class="details-form-grid">
                <div class="details-field"><label>Ticket #</label><div class="details-value" id="modalTicket"></div></div>
                <div class="details-field"><label>Status</label><div class="details-value" id="modalStatus"></div></div>
                <div class="details-field"><label>Student</label><div class="details-value" id="modalStudent"></div></div>
                <div class="details-field"><label>College</label><div class="details-value" id="modalCollege"></div></div>
                <div class="details-field"><label>Category</label><div class="details-value" id="modalCategory"></div></div>
                <div class="details-field"><label>Date Submitted</label><div class="details-value" id="modalDate"></div></div>
                <div class="details-field" style="grid-column: 1 / -1;"><label>Subject</label><div class="details-value" id="modalSubject"></div></div>
                <div class="details-field" style="grid-column: 1 / -1;"><label>Description</label><div class="details-value textarea" id="modalDescription"></div></div>
            </div>
            <div class="details-attachment" id="modalAttachmentWrap" style="display:none; margin-top: 15px; padding: 15px; background: #f9fafb; border-radius: 6px;">
                <label style="font-size: 12px; color: #666; font-weight: 500; display: block; margin-bottom: 8px;">Attachment</label>
                <img id="modalAttachmentImage" src="" alt="Attachment" style="max-width: 100%; max-height: 300px; border-radius: 8px; cursor: pointer; margin-top: 10px; display: none;" onclick="window.open(this.src, '_blank')">
                <a id="modalAttachment" href="#" target="_blank" rel="noopener" style="
                    display: inline-flex;
                    align-items: center;
                    gap: 8px;
                    background: #4F8CFF;
                    color: white;
                    padding: 10px 20px;
                    border-radius: 6px;
                    text-decoration: none;
                    font-weight: 500;
                    transition: background 0.2s;
                " onmouseover="this.style.background='#3b6fd1'" onmouseout="this.style.background='#4F8CFF'">
                    <i class='bx bx-download'></i>
                    <span>Download Attachment</span>
                </a>
            </div>
            <p id="noAttachmentMsg" style="font-size: 13px; color: #9ca3af; font-style: italic; padding: 15px; background: #f9fafb; border-radius: 6px; margin-top: 15px; display: none;">
                <i class='bx bx-paperclip' style="margin-right: 5px;"></i>No attachment uploaded
            </p>

            <div id="modalFeedbackSection" style="display:none; margin-top:16px;">
                <h4 style="font-size:14px; margin-bottom:8px; color:#111827;">Student Feedback</h4>
                <div style="display:flex; gap:12px; align-items:center; margin-bottom:8px;">
                    <div id="modalFeedbackBadge" style="padding:6px 10px; border-radius:12px; font-weight:700; font-size:13px;"></div>
                    <div id="modalFeedbackTime" style="font-size:12px; color:#6b7280;"></div>
                </div>
                <div id="modalFeedbackComment" style="background:#f9fafb; padding:12px; border-radius:12px; color:#374151; white-space:pre-wrap;">-</div>
                <div id="feedbackRepliesContainer" style="display:none; margin-top:12px; padding:12px; border-radius:12px; background:#eef2ff;"></div>
                <button type="button" id="feedbackReplyToggle" style="display:none; margin-top:12px; padding:10px 16px; border:1px solid #c7d2fe; background:#eff6ff; color:#1d4ed8; border-radius:8px; cursor:pointer; font-weight:600;">
                    <i class='bx bx-reply'></i> Reply
                </button>
                <div id="feedbackReplyFormWrapper" style="display:none; margin-top:16px; padding:16px; border:1px solid #e5e7eb; border-radius:12px; background:#ffffff;">
                    <form id="feedbackReplyForm" method="POST" style="display:flex; flex-direction:column; gap:12px;">
                        <input type="hidden" name="csrf_token" id="feedbackReplyCsrfToken" value="<?php echo e((string)$_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="ticket_type" value="suggestion">
                        <input type="hidden" name="ticket_id" id="feedbackReplyTicketId" value="">
                        <input type="hidden" name="student_id" id="feedbackReplyStudentId" value="">
                        <input type="hidden" name="feedback_history_id" id="feedbackReplyHistoryId" value="">
                        <textarea name="message" id="feedbackReplyMessage" rows="4" required placeholder="Type your response to the student's feedback here..." style="width:100%; padding:12px; border:1px solid #d1d5db; border-radius:10px; resize:vertical;"></textarea>
                        <button type="submit" class="btn-submit" style="align-self:flex-start; padding:10px 18px; background:#4F8CFF; color:#fff; border:none; border-radius:8px; cursor:pointer;"><i class='bx bx-send'></i> Send Reply</button>
                        <div id="feedbackReplyStatus" style="font-size:13px; color:#374151;"></div>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Admin Actions Section - Response only -->
        <div id="adminActionsSection" class="details-modal-body" style="border-top: 1px solid #e5e7eb; display: none;">
            <h4 style="margin-bottom: 15px; color: #333; font-size: 16px;">Admin Response</h4>
            <form method="POST" id="adminActionForm" style="display: flex; flex-direction: column; gap: 12px;">
                <input type="hidden" name="csrf_token" value="<?php echo e((string)$_SESSION['csrf_token']); ?>">
                <input type="hidden" name="suggestion_id" id="modalSuggestionId">
                <input type="hidden" name="action" value="update_suggestion_status">
                
                <div class="form-group" style="display: flex; flex-direction: column; gap: 5px;">
                    <label style="font-weight: 500; color: #374151;">Response to Student <span style="color: #ef4444;">*</span></label>
                    <textarea name="remarks" class="form-input textarea" required placeholder="Type the response or next steps for the student..." style="padding: 10px; border: 1px solid #d1d5db; border-radius: 6px; min-height: 80px;"></textarea>
                </div>

                <div class="form-group" style="display: flex; flex-direction: column; gap: 5px;">
                    <label style="font-weight: 500; color: #374151;">Status <span style="color: #ef4444;">*</span></label>
                    <select name="status" class="form-input" required style="padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px;">
                        <option value="under_review" selected>Under Review</option>
                        <option value="reviewed">Reviewed</option>
                    </select>
                </div>
                
                <div class="form-group" style="display: flex; flex-direction: column; gap: 5px;">
                    <button type="submit" class="btn-submit" style="padding: 10px 16px; background: #4F8CFF; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 500;">
                        <i class='bx bx-send'></i> Save Response
                    </button>
                </div>
            </form>
                <!-- Reject form (hidden) -->
                <form method="POST" id="rejectFormInModal" style="display:none; flex-direction: column; gap: 12px; margin-top: 8px;">
                    <input type="hidden" name="csrf_token" value="<?php echo e((string)$_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="suggestion_id" id="modalRejectSuggestionId">
                    <input type="hidden" name="action" value="update_suggestion_status">
                    <input type="hidden" name="status" value="rejected">

                    <div style="display:flex; flex-direction:column; gap:6px;">
                        <label style="font-weight:500; color:#374151;">Rejection Note <span style="color:#ef4444;">*</span></label>
                        <textarea name="remarks" required style="padding:10px; border:1px solid #d1d5db; border-radius:6px; min-height:80px;" placeholder="Explain why this suggestion is rejected..."></textarea>
                    </div>

                    <div style="display:flex; gap:8px;">
                        <button type="submit" class="btn-submit" style="padding:10px 16px; background:#ef4444; color:white; border:none; border-radius:6px; cursor:pointer; flex:1;">Reject Suggestion</button>
                        <button type="button" onclick="cancelRejectInModal()" style="padding:10px 16px; background:#f3f4f6; border-radius:6px; border:1px solid #e5e7eb; flex:1;">Cancel</button>
                    </div>
                </form>
        </div>
    </div>
</div>

<script>
const detailsModal = document.getElementById('detailsModal');
const detailsModalClose = document.getElementById('detailsModalClose');

function setModalValue(id, value) {
    const el = document.getElementById(id);
    if (el) {
        el.textContent = value || '-';
    }
}

function openDetailsModal(button) {
    setModalValue('modalTicket', button.dataset.ticket || '');
    setModalValue('modalStatus', button.dataset.status || '');
    setModalValue('modalStudent', button.dataset.student || '');
    setModalValue('modalCollege', button.dataset.college || '');
    setModalValue('modalCategory', button.dataset.category || '');
    setModalValue('modalDate', button.dataset.date || '');
    setModalValue('modalSubject', button.dataset.subject || '');
    setModalValue('modalDescription', button.dataset.description || '');

    // Set suggestion IDs for action forms
    const suggestionId = button.dataset.suggestionId || '';
    document.getElementById('modalSuggestionId').value = suggestionId;
    document.getElementById('modalRejectSuggestionId').value = suggestionId;

    const attachmentWrap = document.getElementById('modalAttachmentWrap');
    const attachmentLink = document.getElementById('modalAttachment');
    const attachmentImage = document.getElementById('modalAttachmentImage');
    const noAttachmentMsg = document.getElementById('noAttachmentMsg');
    const attachment = button.dataset.attachment || '';
    if (attachment && attachment !== '') {
        attachmentWrap.style.display = 'block';
        noAttachmentMsg.style.display = 'none';
        // Check if image
        const ext = attachment.split('.').pop().toLowerCase();
        const isImage = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'].includes(ext);
        if (isImage) {
            attachmentImage.src = attachment;
            attachmentImage.style.display = 'block';
            attachmentLink.style.display = 'none';
        } else {
            attachmentImage.style.display = 'none';
            attachmentLink.style.display = 'inline-flex';
            attachmentLink.href = attachment;
        }
    } else {
        attachmentWrap.style.display = 'none';
        noAttachmentMsg.style.display = 'block';
    }

    // Student feedback
    const feedbackSection = document.getElementById('modalFeedbackSection');
    const feedbackBadge = document.getElementById('modalFeedbackBadge');
    const feedbackTime = document.getElementById('modalFeedbackTime');
    const feedbackComment = document.getElementById('modalFeedbackComment');
    const feedbackRepliesContainer = document.getElementById('feedbackRepliesContainer');
    const feedbackReplyToggle = document.getElementById('feedbackReplyToggle');
    const feedbackReplyFormWrapper = document.getElementById('feedbackReplyFormWrapper');
    const feedbackSat = (button.dataset.feedbackSatisfaction || '').toLowerCase();
    const feedbackMsg = button.dataset.feedbackComment || '';
    const feedbackCreated = button.dataset.feedbackCreatedAt || '';
    const feedbackId = button.dataset.feedbackId || '';

    if (feedbackSat && feedbackSat !== '') {
        feedbackSection.style.display = 'block';
        feedbackRepliesContainer.innerHTML = '';
        feedbackRepliesContainer.style.display = 'none';
        feedbackReplyToggle.style.display = 'inline-flex';
        feedbackReplyFormWrapper.style.display = 'none';
        feedbackReplyToggle.onclick = function () {
            feedbackReplyFormWrapper.style.display = 'block';
            feedbackReplyToggle.style.display = 'none';
        };

        if (feedbackSat === 'satisfied') {
            feedbackBadge.style.background = '#d1fae5'; feedbackBadge.style.color = '#059669';
            feedbackBadge.textContent = 'Satisfied';
        } else if (feedbackSat === 'neutral') {
            feedbackBadge.style.background = '#f3f4f6'; feedbackBadge.style.color = '#374151';
            feedbackBadge.textContent = 'Neutral';
        } else if (feedbackSat === 'not_satisfied' || feedbackSat === 'not satisfied') {
            feedbackBadge.style.background = '#fee2e2'; feedbackBadge.style.color = '#b91c1c';
            feedbackBadge.textContent = 'Not Satisfied';
        } else {
            feedbackBadge.style.background = '#f3f4f6'; feedbackBadge.style.color = '#374151';
            feedbackBadge.textContent = feedbackSat;
        }

        feedbackTime.textContent = feedbackCreated ? ('Submitted: ' + feedbackCreated) : '';
        feedbackComment.textContent = feedbackMsg ? feedbackMsg : '-';
        document.getElementById('feedbackReplyTicketId').value = button.dataset.suggestionId || '';
        document.getElementById('feedbackReplyStudentId').value = button.dataset.studentId || '';
        document.getElementById('feedbackReplyHistoryId').value = button.dataset.feedbackId || '';
        loadFeedbackReplies('suggestion', button.dataset.suggestionId || '', button.dataset.studentId || '', button.dataset.feedbackId || '');
    } else {
        feedbackSection.style.display = 'none';
        feedbackRepliesContainer.style.display = 'none';
        feedbackReplyToggle.style.display = 'none';
        feedbackReplyFormWrapper.style.display = 'none';
    }

    // Show/hide admin actions section based on whether this suggestion is admin-manageable
    const status = button.dataset.status || '';
    const canManage = button.dataset.canManage === '1' || button.dataset.canManage === 'true';
    const adminActionsSection = document.getElementById('adminActionsSection');
    if (canManage && (status === 'New' || status === 'Pending' || status === 'new' || status === 'pending' || status === '')) {
        adminActionsSection.style.display = 'block';
        // Reset forms
        const rejectForm = document.getElementById('rejectFormInModal');
        if (rejectForm) { rejectForm.style.display = 'none'; }
        const adminForm = document.getElementById('adminActionForm');
        if (adminForm) { adminForm.style.display = 'block'; }
    } else {
        adminActionsSection.style.display = 'none';
    }

    detailsModal.classList.add('show');
    detailsModal.setAttribute('aria-hidden', 'false');
}

function openRejectInModal() {
    document.getElementById('adminActionForm').style.display = 'none';
    document.getElementById('rejectFormInModal').style.display = 'block';
}

function cancelRejectInModal() {
    document.getElementById('rejectFormInModal').style.display = 'none';
    document.getElementById('adminActionForm').style.display = 'block';
}

function closeDetailsModal() {
    detailsModal.classList.remove('show');
    detailsModal.setAttribute('aria-hidden', 'true');
}

document.querySelectorAll('.js-view-details').forEach((btn) => {
    btn.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        openDetailsModal(btn);
    });
});

document.querySelectorAll('.btn-manage').forEach((btn) => {
    btn.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        const id = btn.dataset.suggestionId || btn.getAttribute('data-suggestion-id');
        if (id) {
            window.location.href = 'admin_suggestion_detail.php?id=' + encodeURIComponent(id);
        }
    });
});

document.querySelectorAll('.btn-open-view-only').forEach((btn) => {
    btn.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        const id = btn.dataset.suggestionId || btn.getAttribute('data-suggestion-id');
        if (id) {
            window.location.href = 'admin_viewOnly_suggestions.php?id=' + encodeURIComponent(id);
        }
    });
});

if (detailsModalClose) {
    detailsModalClose.addEventListener('click', closeDetailsModal);
}

if (detailsModal) {
    detailsModal.addEventListener('click', (event) => {
        if (event.target === detailsModal) {
            closeDetailsModal();
        }
    });
}

window.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && detailsModal && detailsModal.classList.contains('show')) {
        closeDetailsModal();
    }
});

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

function submitFeedbackReply(event) {
    event.preventDefault();
    const form = document.getElementById('feedbackReplyForm');
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
}

const adminFeedbackReplyForm = document.getElementById('feedbackReplyForm');
if (adminFeedbackReplyForm) {
    adminFeedbackReplyForm.addEventListener('submit', submitFeedbackReply);
}

const suggestionFiltersForm = document.getElementById('suggestionFiltersForm');
if (suggestionFiltersForm) {
    const searchInput = suggestionFiltersForm.querySelector('input[name="q"]');
    const searchFocusKey = 'adminSuggestionsSearchFocus';
    let searchTimer;

    if (searchInput) {
        if (window.sessionStorage.getItem(searchFocusKey) === '1') {
            searchInput.focus();
            searchInput.setSelectionRange(searchInput.value.length, searchInput.value.length);
        }

        searchInput.addEventListener('input', () => {
            window.sessionStorage.setItem(searchFocusKey, '1');
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(() => suggestionFiltersForm.submit(), 350);
        });
    }

    suggestionFiltersForm.querySelectorAll('input[name="date_from"], input[name="date_to"], select[name="college"], select[name="department"], select[name="status"]').forEach((filter) => {
        filter.addEventListener('change', () => {
            window.sessionStorage.removeItem(searchFocusKey);
            suggestionFiltersForm.submit();
        });
    });
}
</script>

</body>
</html>
