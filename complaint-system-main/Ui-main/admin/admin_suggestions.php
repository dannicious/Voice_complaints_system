<?php
session_start();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../ticket_flow.php';
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

require_admin_session($pdo);

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Opportunistic SLA check - nudges the handling office, then admin, if a
// suggestion has gone unanswered too long. Suggestion-only, best-effort.
check_and_send_suggestion_overdue_notifications($pdo);

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function suggestion_status_badge(string $status): string
{
    return (string)suggestion_status_meta($status)['badge'];
}

function suggestion_status_label(string $status): string
{
    return (string)suggestion_status_meta($status)['label'];
}

$flashMessage = '';
$flashType = '';

// Tab selection: 'manageable' (admin-managed) or 'view_only' (college/dean managed)
$activeTab = strtolower(trim((string)($_GET['tab'] ?? 'manageable')));
if (!in_array($activeTab, ['manageable', 'view_only'], true)) {
    $activeTab = 'manageable';
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
                 WHERE 1=1
                     AND (s.office IS NULL OR s.office = '')";

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
.age-badge {
    display: inline-block;
    margin-top: 3px;
    padding: 2px 7px;
    border-radius: 999px;
    font-size: 10.5px;
    font-weight: 700;
    background: #f3f4f6;
    color: #6b7280;
}
.age-badge.age-new { background: #dcfce7; color: #166534; }
.age-badge.age-overdue { background: #fee2e2; color: #b91c1c; }
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
                        <?php foreach (['under_review', 'needs_info', 'accepted', 'not_feasible', 'planned', 'in_progress', 'implemented'] as $statusOption): ?>
                            <option value="<?php echo e($statusOption); ?>" <?php echo $status === $statusOption ? 'selected' : ''; ?>><?php echo e(suggestion_status_label($statusOption)); ?></option>
                        <?php endforeach; ?>
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
                                    <td class="cell-date">
                                        <?php echo e(date('M d, Y', strtotime((string)$row['created_at']))); ?>
                                        <?php
                                            $rowAgeLabel = suggestion_age_label((string)$row['created_at']);
                                            $rowAgeHours = (int)floor((time() - strtotime((string)$row['created_at'])) / 3600);
                                            $rowAgeClass = $rowAgeLabel === 'New' ? 'age-new' : ($rowAgeHours >= 24 ? 'age-overdue' : '');
                                        ?>
                                        <div class="age-badge <?php echo e($rowAgeClass); ?>"><?php echo e($rowAgeLabel); ?></div>
                                    </td>
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
                                    <td class="cell-status"><span class="status-badge <?php echo suggestion_status_badge((string)$row['status']); ?>"><?php echo e(suggestion_status_label((string)$row['status'])); ?></span></td>
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

<script>
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
