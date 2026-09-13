<?php
session_start();
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../ticket_flow.php';
require_once __DIR__ . '/../suggestion_flow.php';
require_once __DIR__ . '/../school_year_helpers.php';
require_once __DIR__ . '/../response_timeline_ui.php';

// Opportunistic SLA check - nudges the handling office, then admin, if a
// suggestion has gone unanswered too long. Suggestion-only, best-effort.
check_and_send_suggestion_overdue_notifications($pdo);

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
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

$flashMsg = '';
$flashType = '';

$q = normalize_search_query((string)($_GET['q'] ?? ''));
$statusFilter = strtolower(trim((string)($_GET['status'] ?? 'all')));
$schoolYearFilter = trim((string)($_GET['school_year'] ?? ''));
$semesterFilter = trim((string)($_GET['semester'] ?? ''));
$departmentFilter = (int)($_GET['department'] ?? 0);
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$viewMode = 'all';
$allowedStatusFilters = ['all', 'under_review', 'needs_info', 'accepted', 'not_feasible', 'planned', 'in_progress', 'implemented'];
if (!in_array($statusFilter, $allowedStatusFilters, true)) {
    $statusFilter = 'all';
}
if (!in_array($semesterFilter, ['', '1', '2'], true)) {
    $semesterFilter = '';
}

$deanUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$deanCollegeId = null;

try {
    if ($deanUserId > 0 && (string)($_SESSION['role'] ?? '') === 'dean') {
        $deanStmt = $pdo->prepare('SELECT college_id FROM dean_profiles WHERE user_id = :user_id LIMIT 1');
        $deanStmt->execute([':user_id' => $deanUserId]);
        $dean = $deanStmt->fetch();
        if ($dean) {
            $deanCollegeId = $dean['college_id'] !== null ? (int)$dean['college_id'] : null;
        }
    }
} catch (PDOException $e) {
    $flashMsg = 'Unable to resolve dean profile.';
    $flashType = 'error';
}

if ($deanCollegeId === null) {
    $flashMsg = 'Dean college profile is not configured. Please contact the administrator.';
    $flashType = 'error';
}

$availableSchoolYears = $deanCollegeId !== null ? sy_list_for_college($pdo, $deanCollegeId) : [];
if ($schoolYearFilter !== '' && (!sy_is_valid_label($schoolYearFilter) || !in_array($schoolYearFilter, $availableSchoolYears, true))) {
    $schoolYearFilter = '';
}

$departments = [];
if ($deanCollegeId !== null) {
    $departmentsStmt = $pdo->prepare('SELECT id, name FROM programs WHERE college_id = :college_id AND status = "active" ORDER BY name');
    $departmentsStmt->execute([':college_id' => $deanCollegeId]);
    $departments = $departmentsStmt->fetchAll(PDO::FETCH_ASSOC);
}
$departmentIds = array_map(static fn(array $department): int => (int)$department['id'], $departments);
if ($departmentFilter <= 0 || !in_array($departmentFilter, $departmentIds, true)) {
    $departmentFilter = 0;
}

function dean_suggestions_valid_date(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

if ($dateFrom !== '' && !dean_suggestions_valid_date($dateFrom)) {
    $dateFrom = '';
}
if ($dateTo !== '' && !dean_suggestions_valid_date($dateTo)) {
    $dateTo = '';
}
if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

$suggestions = [];

try {
    $sql =
        "SELECT
            s.id,
            s.ticket_no,
            s.created_at,
            s.subject,
            s.description,
            s.attachment,
            s.status,
            sp.id AS student_id,
            s.is_anonymous,
            COALESCE(sc.name, 'Uncategorized') AS category_name,
            sp.first_name,
            sp.last_name,
            sp.year_level,
            p.name AS department_name,
            tf.id AS feedback_id,
            tf.satisfaction AS feedback_satisfaction,
            tf.comment AS feedback_comment,
            tf.created_at AS feedback_created_at,
            (
                SELECT tr.id
                FROM ticket_replies tr
                WHERE tr.ticket_type = 'suggestion'
                  AND tr.ticket_id = s.id
                  AND LOWER(tr.sender_role) = 'dean'
                ORDER BY tr.created_at DESC, tr.id DESC
                LIMIT 1
            ) AS remark_id,
            (
                SELECT tr.message
                FROM ticket_replies tr
                WHERE tr.ticket_type = 'suggestion'
                  AND tr.ticket_id = s.id
                  AND LOWER(tr.sender_role) = 'dean'
                ORDER BY tr.created_at DESC, tr.id DESC
                LIMIT 1
            ) AS remark_message
         FROM suggestions s
         LEFT JOIN suggestion_categories sc ON sc.id = s.category_id
         LEFT JOIN student_profiles sp ON sp.id = s.student_id
         LEFT JOIN programs p ON p.id = sp.program_id
         LEFT JOIN ticket_feedback tf ON tf.ticket_type = 'suggestion' AND tf.ticket_id = s.id AND tf.student_id = sp.id";

    $conditions = [];
    $params = [];

    // Only show suggestions that have been admin-approved or still waiting for dean review.
    $conditions[] = 's.status NOT IN ("rejected", "declined")';
    $conditions[] = 's.college_id = :college_id';
    $params[':college_id'] = $deanCollegeId;

    if ($departmentFilter > 0) {
        $conditions[] = 'sp.program_id = :department';
        $params[':department'] = $departmentFilter;
    }

    if ($schoolYearFilter !== '') {
        $conditions[] = 's.school_year = :school_year';
        $params[':school_year'] = $schoolYearFilter;
    }

    if ($semesterFilter === '1' || $semesterFilter === '2') {
        // The stored column, set once at submission time from whichever
        // academic calendar was in effect then - not a hardcoded Aug1/Jan1
        // date guess, so this stays correct even if the calendar changes.
        $conditions[] = 's.semester = :semester';
        $params[':semester'] = $semesterFilter;
    }

    if ($dateFrom !== '') {
        $conditions[] = 's.created_at >= :date_from';
        $params[':date_from'] = $dateFrom . ' 00:00:00';
    }
    if ($dateTo !== '') {
        $dateToExclusive = (new DateTimeImmutable($dateTo))->modify('+1 day')->format('Y-m-d 00:00:00');
        $conditions[] = 's.created_at < :date_to';
        $params[':date_to'] = $dateToExclusive;
    }

    if ($statusFilter !== 'all') {
        $conditions[] = 's.status = :status_filter';
        $params[':status_filter'] = $statusFilter;
    }

    if ($q !== '') {
        $like = '%' . escape_like($q) . '%';
        $conditions[] = '(
            s.ticket_no LIKE :q1
            OR s.subject LIKE :q2
            OR s.description LIKE :q3
            OR COALESCE(sc.name, \'\') LIKE :q4
            OR COALESCE(sp.first_name, \'\') LIKE :q5
            OR COALESCE(sp.last_name, \'\') LIKE :q6
        )';
        $params[':q1'] = $like;
        $params[':q2'] = $like;
        $params[':q3'] = $like;
        $params[':q4'] = $like;
        $params[':q5'] = $like;
        $params[':q6'] = $like;
    }

    if (!empty($conditions)) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }

    $sql .= ' ORDER BY s.created_at DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $suggestions = $stmt->fetchAll();
} catch (PDOException $e) {
    if ($flashMsg === '') {
        $flashMsg = 'Unable to load suggestions.';
        $flashType = 'error';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Manage Suggestions - VOICE</title>
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

/* ===== CONTROLS ===== */
.controls-bar {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, .02);
    padding: 18px 20px;
    margin-bottom: 20px;
}

.search-filter,
.filter-box {
    display: flex;
    flex-direction: column;
    align-items: stretch;
    gap: 6px;
    min-width: 0;
}

.search-filter > span,
.filter-box > span {
    color: #52627a;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .03em;
}

.search-box {
    display: flex;
    align-items: center;
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 10px 12px;
    width: 100%;
    height: 43px;
    flex: none;
}

.search-box i { color: #94a3b8; margin-right: 10px; flex-shrink: 0; }
.search-box input { border: none; background: transparent; outline: none; width: 100%; min-width: 0; font-size: 13px; }

.filter-box select {
    height: 43px;
    padding: 10px 14px;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    font-size: 13px;
    font-family: 'Poppins', sans-serif;
    outline: none;
    background: #fff;
    cursor: pointer;
    width: 100%;
}

.filter-box input[name="school_year"] {
    height: 43px;
    padding: 10px 14px;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    font-size: 13px;
    font-family: 'Poppins', sans-serif;
    outline: none;
    background: #fff;
    color: #1f2937;
    width: 100%;
}

.date-filter {
    display: flex;
    flex-direction: column;
    align-items: stretch;
    gap: 6px;
    min-width: 0;
}

.date-filter > span {
    color: #52627a;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .03em;
}

.date-filter input {
    height: 43px;
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    color: #1f2937;
    font: inherit;
    font-size: 13px;
    outline: none;
    background: #fff;
}

.date-filter input:focus,
.filter-box select:focus,
.filter-box input[name="school_year"]:focus {
    border-color: #8b5cf6;
    box-shadow: 0 0 0 2px rgba(139, 92, 246, .12);
}

.controls-form {
    width: 100%;
    display: block;
}

.controls-left {
    display: grid;
    grid-template-columns: minmax(220px, 2fr) repeat(6, minmax(105px, 1fr)) auto;
    align-items: end;
    gap: 12px;
}

.btn-search {
    height: 40px;
    padding: 10px 20px;
    background: #6d28d9;
    color: #fff;
    border: none;
    border-radius: 8px;
    font-size: 13px;
    font-family: 'Poppins', sans-serif;
    cursor: pointer;
    white-space: nowrap;
}

.btn-search:hover { background: #5d1fa0; }

@media (max-width: 900px) {
    .controls-left { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .search-filter { grid-column: 1 / -1; }
    .btn-search, .btn-clear { width: 100%; text-align: center; }
}

@media (max-width: 520px) {
    .controls-left { grid-template-columns: 1fr; }
    .search-filter { grid-column: auto; }
}

/* ===== TABS ===== */
.tabs-container {
    display: flex;
    gap: 0;
    margin-bottom: 20px;
    border-bottom: 2px solid #e5e7eb;
}

.tab-btn {
    padding: 12px 24px;
    background: transparent;
    border: none;
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
    cursor: pointer;
    font-weight: 500;
    color: #6b7280;
    text-decoration: none;
    transition: all 0.2s;
}

.tab-btn:hover {
    color: #4F8CFF;
}

.tab-btn.active {
    color: #4F8CFF;
    border-bottom-color: #4F8CFF;
}

.btn-search {
    padding: 9px 20px;
    border: none;
    border-radius: 8px;
    background: #6d28d9;
    color: #fff;
    font-size: 13px;
    font-family: 'Poppins', sans-serif;
    cursor: pointer;
    transition: background 0.2s ease;
}

.btn-search:hover {
    background: #5d1fa0;
    cursor: pointer;
}

.btn-clear {
    padding: 9px 14px;
    border-radius: 8px;
    border: 1px solid #d1d5db;
    background: #fff;
    color: #374151;
    text-decoration: none;
    font-size: 13px;
}

/* ===== TABLE ===== */
.data-card {
    background: #fff;
    padding: 25px;
    border-radius: 12px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 2px 10px rgba(0,0,0,0.02);
    overflow-x: auto;
}

table { width: 100%; border-collapse: collapse; min-width: 800px; }

th {
    text-align: left;
    font-size: 12px;
    color: #888;
    padding: 15px 10px;
    border-bottom: 2px solid #f0f0f0;
    font-weight: 600;
    text-transform: uppercase;
}

td {
    padding: 15px 10px;
    font-size: 13px;
    color: #444;
    border-bottom: 1px solid #f9f9f9;
    vertical-align: middle;
}

tbody tr:hover td { background: #f9fafb; }

.status-badge {
    padding: 5px 10px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 500;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

.status-pending     { background: #fffbeb; color: #d97706; }
.status-review      { background: #eff6ff; color: #2563eb; }
.status-approved    { /* legacy; treated as under_review for dean view */ }
.status-reviewed { background: #ccfbf1; color: #0f766e; }
.status-declined    { background: #f3f4f6; color: #4b5563; }
.status-needs-info  { background: #fef3c7; color: #92400e; }
.status-accepted    { background: #dbeafe; color: #1d4ed8; }
.status-planned     { background: #e0e7ff; color: #3730a3; }
.status-progress    { background: #ede9fe; color: #5b21b6; }
.status-implemented { background: #dcfce7; color: #065f46; }

.subject-text { font-weight: 500; color: #1f2937; }
.small-text   { font-size: 12px; color: #888; }

.btn-manage {
    padding: 8px 15px;
    background: #6d28d9;
    color: white;
    border: none;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 500;
    font-family: 'Poppins', sans-serif;
    cursor: pointer;
    transition: 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

.btn-manage:hover { background: #5d1fa0; }

/* ===== MODAL ===== */
.modal-overlay {
    position: fixed;
    top: 0; left: 0; width: 100%; height: 100%;
    background: rgba(0,0,0,0.4);
    display: none;
    justify-content: center;
    align-items: center;
    z-index: 1000;
}

.modal-content {
    background: #fff;
    width: 800px;
    max-width: 90%;
    /* FIX: Added max-height and overflow to ensure it fits on any screen */
    max-height: 90vh;
    overflow-y: auto;
    border-radius: 12px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    display: flex;
    flex-direction: column;
    animation: fadeIn 0.3s ease;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-15px); }
    to   { opacity: 1; transform: translateY(0); }
}

.modal-header {
    background: #f9fafb;
    padding: 20px 25px;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    justify-content: space-between;
    align-items: center;
    position: sticky; /* Keeps header visible while scrolling */
    top: 0;
    z-index: 10;
}

.modal-header h3 { font-size: 18px; color: #1f2937; font-weight: 600; }
.close-btn { font-size: 24px; color: #888; cursor: pointer; transition: 0.2s; }
.close-btn:hover { color: #333; }

.modal-body {
    padding: 25px;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 30px;
}

.detail-group { margin-bottom: 15px; }
.detail-group label {
    display: block;
    font-size: 12px;
    color: #888;
    text-transform: uppercase;
    margin-bottom: 5px;
    font-weight: 500;
}

.detail-group p {
    font-size: 14px;
    color: #333;
    line-height: 1.5;
    background: #f9fafb;
    padding: 10px;
    border-radius: 6px;
    border: 1px solid #f0f0f0;
}

.form-group { margin-bottom: 15px; }
.form-group label {
    display: block;
    font-size: 13px;
    font-weight: 500;
    color: #374151;
    margin-bottom: 8px;
}

.form-control {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 13px;
    font-family: 'Poppins', sans-serif;
    outline: none;
    transition: 0.2s;
}

.form-control:focus { border-color: #6d28d9; }
textarea.form-control { resize: vertical; min-height: 100px; }

.modal-footer {
    padding: 20px 25px;
    border-top: 1px solid #e5e7eb;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    background: #f9fafb;
    position: sticky; /* Keeps footer visible while scrolling */
    bottom: 0;
    z-index: 10;
}

.btn-cancel {
    padding: 10px 20px;
    background: #fff;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    color: #374151;
    font-weight: 500;
    font-family: 'Poppins', sans-serif;
    cursor: pointer;
}

.btn-save {
    padding: 10px 20px;
    background: #6d28d9;
    border: none;
    border-radius: 6px;
    color: #fff;
    font-weight: 500;
    font-family: 'Poppins', sans-serif;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.btn-save:hover { background: #5d1fa0; }

/* Timeline and Avatar Styles for Response History */
.avatar-circle {
    width: 40px;
    height: 40px;
    border-radius: 999px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    background: #6b46c1;
    color: #fff;
    font-weight: 700;
    border: 1px solid #eef2ff;
    flex-shrink: 0;
    font-size: 14px;
}

.avatar-circle img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.timeline-item {
    display: flex;
    gap: 12px;
    align-items: flex-start;
    margin-bottom: 18px;
}

.timeline-item .card {
    padding: 12px !important;
}

.timeline-dot-container {
    width: 40px;
    flex: 0 0 40px;
    display: flex;
    justify-content: center;
}

.timeline-dot {
    width: 12px;
    height: 12px;
    border-radius: 999px;
    background: #6b46c1;
    margin-top: 6px;
}

.reply-header {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    align-items: flex-start;
    margin-bottom: 8px;
}

.reply-sender-name {
    font-size: 13px;
    font-weight: 700;
    color: #111827;
}

.reply-timestamp {
    font-size: 12px;
    color: #6b7280;
    white-space: nowrap;
}

.reply-message {
    font-size: 14px;
    color: #374151;
    white-space: pre-wrap;
    word-wrap: break-word;
}

.reply-sender-role {
    font-size: 12px;
    color: #6b7280;
    font-weight: 500;
}

.timeline-list {
    display: flex;
    flex-direction: column;
    gap: 18px;
}

.response-history-header {
    font-weight: 700;
    color: #111827;
    margin-bottom: 10px;
    font-size: 14px;
}

.reply-card {
    padding: 16px;
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    background: #ffffff;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.06);
}

.reply-card-inner {
    display: flex;
    gap: 12px;
    align-items: flex-start;
}

.reply-avatar {
    flex: 0 0 48px;
}

.timeline-entry {
    display: flex;
    gap: 12px;
    align-items: flex-start;
    margin-bottom: 18px;
}

.timeline-avatar {
    width: 44px;
    height: 44px;
    border-radius: 999px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    background: #6b46c1;
    color: #fff;
    font-weight: 700;
    font-size: 14px;
    border: 1px solid #eef2ff;
    flex-shrink: 0;
}

.timeline-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.timeline-body {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.timeline-heading {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.timeline-name {
    font-weight: 700;
    color: #111827;
    font-size: 14px;
}

.timeline-role {
    font-size: 12px;
    color: #6b7280;
}

.timeline-time {
    font-size: 12px;
    color: #6b7280;
}

.timeline-card {
    display: inline-block;
    width: fit-content;
    max-width: min(78%, 720px);
    padding: 16px 18px;
    border-radius: 14px;
    border: 1px solid #e5e7eb;
    background: #fff;
    box-shadow: 0 6px 18px rgba(15, 23, 42, 0.04);
}

.timeline-card.current-user {
    background: #f3f0ff;
    border-color: #c4b5fd;
}

.timeline-card .timeline-text {
    color: #111827;
    font-size: 14px;
    line-height: 1.6;
    white-space: pre-wrap;
    word-break: break-word;
    text-align: left;
}

<?php echo response_timeline_styles(); ?>

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

@media (max-width: 900px) {
    .modal-body { grid-template-columns: 1fr; }
}
</style>
</head>

<body>

<!-- TOPBAR -->
<?php include 'dean_topbar.php'; ?>

<!-- SIDEBAR -->
<?php include 'dean_sidebar.php'; ?>

<!-- MAIN CONTENT -->
<div class="main">
    <div class="dashboard-container">

        <h2 class="page-title">Manage Suggestions</h2>
        <p class="page-subtitle">Review, evaluate, and respond to student ideas and suggestions for your college.</p>

        <?php if ($flashMsg !== ''): ?>
            <div class="flash-msg <?php echo $flashType === 'success' ? 'flash-success' : 'flash-error'; ?>">
                <?php echo e($flashMsg); ?>
            </div>
        <?php endif; ?>

        <div class="controls-bar">
            <form method="GET" class="controls-form" id="deanSuggestionFiltersForm">
                <div class="controls-left">
                    <div class="search-filter">
                        <span>Search</span>
                        <div class="search-box">
                            <i class='bx bx-search'></i>
                            <input type="text" name="q" value="<?php echo e($q); ?>" placeholder="Search by Subject, Category, or Student...">
                        </div>
                    </div>
                    <div class="filter-box">
                        <span>Status</span>
                        <select name="status">
                            <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                            <?php foreach (['under_review', 'needs_info', 'accepted', 'not_feasible', 'planned', 'in_progress', 'implemented'] as $statusFilterOption): ?>
                                <option value="<?php echo e($statusFilterOption); ?>" <?php echo $statusFilter === $statusFilterOption ? 'selected' : ''; ?>><?php echo e(suggestion_status_meta($statusFilterOption)['label']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-box">
                        <span>School Year</span>
                        <input type="text" name="school_year" inputmode="numeric" maxlength="9" autocomplete="off"
                               placeholder="All School Years (e.g. 2024)" value="<?php echo e($schoolYearFilter); ?>"
                               oninput="formatSchoolYearInput(this, true, event)" onkeydown="schoolYearInputKeydown(event, this)">
                    </div>
                    <div class="filter-box">
                        <span>Semester</span>
                        <select name="semester">
                            <option value="">All Semesters</option>
                            <option value="1" <?php echo $semesterFilter === '1' ? 'selected' : ''; ?>>1st Semester</option>
                            <option value="2" <?php echo $semesterFilter === '2' ? 'selected' : ''; ?>>2nd Semester</option>
                        </select>
                    </div>
                    <div class="filter-box">
                        <span>Department</span>
                        <select name="department">
                            <option value="0">All Departments</option>
                            <?php foreach ($departments as $department): ?>
                                <option value="<?php echo (int)$department['id']; ?>" <?php echo $departmentFilter === (int)$department['id'] ? 'selected' : ''; ?>><?php echo e((string)$department['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <label class="date-filter">
                        <span>From Date</span>
                        <input type="date" name="date_from" value="<?php echo e($dateFrom); ?>">
                    </label>
                    <label class="date-filter">
                        <span>To Date</span>
                        <input type="date" name="date_to" value="<?php echo e($dateTo); ?>">
                    </label>
                    <?php if ($q !== '' || $statusFilter !== 'all' || $schoolYearFilter !== '' || $semesterFilter !== '' || $departmentFilter > 0 || $dateFrom !== '' || $dateTo !== ''): ?>
                        <a href="dean_suggestions.php" class="btn-clear">Clear</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="data-card">
            <table>
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Department</th>
                        <th>Category</th>
                        <th>Year</th>
                        <th>Submitted</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($suggestions) === 0): ?>
                        <tr>
                            <td colspan="7">No suggestions found for your college.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($suggestions as $row): ?>
                            <?php
                                $status = (string)$row['status'];
                                $rowStatusMeta = suggestion_status_meta($status);
                                $statusClass = $rowStatusMeta['badge'];
                                $statusLabel = $rowStatusMeta['label'];

                                $yearLevel = (int)($row['year_level'] ?? 0);
                                $submitter = ((int)$row['is_anonymous'] === 1)
                                    ? 'Anonymous Student'
                                    : trim((string)$row['first_name'] . ' ' . (string)$row['last_name']);
                                $description = trim((string)$row['description']) !== '' ? (string)$row['description'] : 'No description provided.';
                                $attachment = trim((string)$row['attachment']) !== '' ? (string)$row['attachment'] : '';
                            ?>
                            <tr>
                                <td>
                                    <div class="subject-text"><?php echo e($submitter); ?></div>
                                </td>
                                <td><?php echo e((string)($row['department_name'] ?? 'Unassigned')); ?></td>
                                <td><?php echo e((string)$row['category_name']); ?></td>
                                <td><?php echo e($yearLevel > 0 ? (string)$yearLevel : 'N/A'); ?></td>
                                <td><?php echo e(date('M d, Y', strtotime((string)$row['created_at']))); ?></td>
                                <td><span class="status-badge <?php echo e($statusClass); ?>"><?php echo e($statusLabel); ?></span></td>
                                <td>
                                    <a href="dean_suggestion_detail.php?id=<?php echo (int)$row['id']; ?>" class="btn-manage" style="text-decoration:none;"><i class='bx bx-edit-alt'></i> Manage</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div>
</div>

<script>

const deanSuggestionFiltersForm = document.getElementById('deanSuggestionFiltersForm');
if (deanSuggestionFiltersForm) {
    const searchInput = deanSuggestionFiltersForm.querySelector('input[name="q"]');
    const focusKey = 'deanSuggestionSearchFocus';
    let searchTimer;

    if (searchInput) {
        if (window.sessionStorage.getItem(focusKey) === '1') {
            searchInput.focus();
            searchInput.setSelectionRange(searchInput.value.length, searchInput.value.length);
        }
        searchInput.addEventListener('input', () => {
            window.sessionStorage.setItem(focusKey, '1');
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(() => deanSuggestionFiltersForm.submit(), 350);
        });
    }

    deanSuggestionFiltersForm.querySelectorAll('select[name="status"], select[name="semester"], select[name="department"], input[name="date_from"], input[name="date_to"]').forEach((filter) => {
        filter.addEventListener('change', () => {
            window.sessionStorage.removeItem(focusKey);
            deanSuggestionFiltersForm.submit();
        });
    });
}
</script>
<?php echo sy_smart_input_script(); ?>

</body>
</html>
