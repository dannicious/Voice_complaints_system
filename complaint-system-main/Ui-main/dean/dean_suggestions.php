<?php
session_start();
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../ticket_flow.php';

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
$viewMode = 'all';
$allowedStatusFilters = ['all', 'under_review', 'reviewed'];
if (!in_array($statusFilter, $allowedStatusFilters, true)) {
    $statusFilter = 'all';
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_suggestion') {
    $suggestionId = (int)($_POST['suggestion_id'] ?? 0);
    $status = trim((string)($_POST['status'] ?? ''));
    $remarks = trim((string)($_POST['remarks'] ?? ''));
    $forwarded = isset($_POST['is_forwarded']) ? 1 : 0;

    $allowedStatuses = ['under_review', 'reviewed'];

    if ($suggestionId <= 0 || !in_array($status, $allowedStatuses, true)) {
        $flashMsg = 'Invalid suggestion update request.';
        $flashType = 'error';
    } else {
        try {
            $sql =
                'SELECT s.id, s.ticket_no, sp.user_id AS student_user_id
                 FROM suggestions s
                 LEFT JOIN student_profiles sp ON sp.id = s.student_id
                 WHERE s.id = :id';

            $sql .= ' AND s.college_id = :college_id';

            $sql .= ' LIMIT 1';

            $verifyStmt = $pdo->prepare($sql);
            $params = [':id' => $suggestionId];
            $params[':college_id'] = $deanCollegeId;
            $verifyStmt->execute($params);
            $found = $verifyStmt->fetch();

            if (!$found) {
                $flashMsg = 'Suggestion not found in your college scope.';
                $flashType = 'error';
            } else {
                $updateStmt = $pdo->prepare(
                    'UPDATE suggestions
                     SET status = :status, is_forwarded = :is_forwarded
                     WHERE id = :id'
                );
                $updateStmt->execute([
                    ':status' => $status,
                    ':is_forwarded' => $forwarded,
                    ':id' => $suggestionId,
                ]);

                $remarkId = isset($_POST['remark_id']) ? (int)$_POST['remark_id'] : 0;
                if ($remarkId > 0) {
                    // Allow updating existing remark even to an empty value (clearing it)
                    $updateReplyStmt = $pdo->prepare(
                        'UPDATE ticket_replies
                         SET message = :message
                         WHERE id = :id
                           AND ticket_type = :ticket_type
                           AND ticket_id = :ticket_id
                           AND LOWER(sender_role) = :sender_role'
                    );
                    $updateReplyStmt->execute([
                        ':message' => $remarks,
                        ':id' => $remarkId,
                        ':ticket_type' => 'suggestion',
                        ':ticket_id' => $suggestionId,
                        ':sender_role' => 'dean',
                    ]);

                    if ($updateReplyStmt->rowCount() === 0) {
                        save_ticket_reply($pdo, 'suggestion', $suggestionId, $deanUserId > 0 ? $deanUserId : 0, 'dean', $remarks);
                    }
                } else {
                    // Creating new remark: only create if non-empty
                    if ($remarks !== '') {
                        save_ticket_reply($pdo, 'suggestion', $suggestionId, $deanUserId > 0 ? $deanUserId : 0, 'dean', $remarks);
                    }
                }

                $studentUserId = (int)($found['student_user_id'] ?? 0);
                if ($studentUserId > 0) {
                    $notifStmt = $pdo->prepare(
                        'INSERT INTO notifications (user_id, type, message, ticket_type, ticket_id, is_read)
                         VALUES (:user_id, :type, :message, :ticket_type, :ticket_id, :is_read)'
                    );
                    $notifStmt->execute([
                        ':user_id' => $studentUserId,
                        ':type' => 'suggestion_update',
                        ':message' => 'Your suggestion ' . (string)$found['ticket_no'] . ' has a new response. Current status: ' . strtoupper(str_replace('_', ' ', $status)) . '.',
                        ':ticket_type' => 'suggestion',
                        ':ticket_id' => $suggestionId,
                        ':is_read' => 0,
                    ]);
                }

                $flashMsg = 'Suggestion response saved successfully.';
                $flashType = 'success';
            }
        } catch (PDOException $e) {
            $flashMsg = 'Failed to update suggestion.';
            $flashType = 'error';
        }
    }
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
         LEFT JOIN ticket_feedback tf ON tf.ticket_type = 'suggestion' AND tf.ticket_id = s.id AND tf.student_id = sp.id";

    $conditions = [];
    $params = [];

    // Only show suggestions that have been admin-approved or still waiting for dean review.
    $conditions[] = 's.status NOT IN ("rejected", "declined")';
    $conditions[] = 's.college_id = :college_id';
    $params[':college_id'] = $deanCollegeId;

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
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    gap: 15px;
    flex-wrap: wrap;
}

.search-box {
    display: flex;
    align-items: center;
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 8px 15px;
    width: 300px;
}

.search-box i { color: #888; margin-right: 10px; }
.search-box input { border: none; background: transparent; outline: none; width: 100%; font-size: 13px; }

.filter-box select {
    padding: 9px 15px;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    font-size: 13px;
    font-family: 'Poppins', sans-serif;
    outline: none;
    background: #f9fafb;
    cursor: pointer;
}

.controls-form {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    flex-wrap: wrap;
}

.controls-left {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
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
            <form method="GET" class="controls-form">
                <div class="controls-left">
                    <div class="search-box">
                        <i class='bx bx-search'></i>
                        <input type="text" name="q" value="<?php echo e($q); ?>" placeholder="Search by Ticket ID, Subject, Category, or Student...">
                    </div>
                        <div class="filter-box">
                            <select name="status">
                                <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                                <option value="under_review" <?php echo $statusFilter === 'under_review' ? 'selected' : ''; ?>>Under Review</option>
                                <option value="reviewed" <?php echo $statusFilter === 'reviewed' ? 'selected' : ''; ?>>Reviewed</option>
                            </select>
                        </div>
                    <button type="submit" class="btn-search">Apply</button>
                    <?php if ($q !== '' || $statusFilter !== 'all'): ?>
                        <a href="dean_suggestions.php" class="btn-clear">Clear</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="data-card">
            <table>
                <thead>
                    <tr>
                        <th>Date Submitted</th>
                        <th>Idea & Submitter</th>
                        <th>Category</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($suggestions) === 0): ?>
                        <tr>
                            <td colspan="5">No suggestions found for your college.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($suggestions as $row): ?>
                            <?php
                                $status = (string)$row['status'];
                                $statusClass = 'status-pending';
                                $statusIcon = 'bx-time';
                                $statusLabel = 'New';
                                if ($status === 'under_review' || $status === 'approved') {
                                    // Treat 'approved' as 'under_review' in dean UI to avoid showing 'Approved'
                                    $statusClass = 'status-review';
                                    $statusIcon = 'bx-search';
                                    $statusLabel = 'Under Review';
                                } elseif ($status === 'reviewed') {
                                    $statusClass = 'status-reviewed';
                                    $statusIcon = 'bx-rocket';
                                    $statusLabel = 'Reviewed';
                                } elseif (in_array($status, ['declined', 'rejected'], true)) {
                                    $statusClass = 'status-declined';
                                    $statusIcon = 'bx-x-circle';
                                    $statusLabel = ucfirst($status);
                                }

                                $submitter = ((int)$row['is_anonymous'] === 1)
                                    ? 'Anonymous Student'
                                    : trim((string)$row['first_name'] . ' ' . (string)$row['last_name']) . ((string)$row['year_level'] !== '' ? ' (' . (int)$row['year_level'] . 'th Year)' : '');
                                $description = trim((string)$row['description']) !== '' ? (string)$row['description'] : 'No description provided.';
                                $attachment = trim((string)$row['attachment']) !== '' ? (string)$row['attachment'] : '';
                            ?>
                            <tr>
                                <td><?php echo e(date('M d, Y', strtotime((string)$row['created_at']))); ?></td>
                                <td>
                                    <div class="subject-text"><?php echo e((string)$row['subject']); ?></div>
                                    <div class="small-text"><?php echo e($submitter); ?></div>
                                </td>
                                <td><?php echo e((string)$row['category_name']); ?></td>
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

<!-- MODAL -->
<div class="modal-overlay" id="manageModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modalTitle">Evaluate Suggestion #VOX-S000</h3>
            <i class='bx bx-x close-btn' onclick="closeModal()"></i>
        </div>
        <div class="modal-body">
            <div>
                <h4 style="margin-bottom: 15px; color: #1e3a8a; font-size: 15px;">Suggestion Details</h4>
                <div class="detail-group">
                    <label>Submitted By</label>
                    <p id="modalSubmitter">John Doe</p>
                </div>
                <div class="detail-group">
                    <label>Subject / Idea</label>
                    <p id="modalSubject">Subject Title Here</p>
                </div>
                <div class="detail-group">
                    <label>Category</label>
                    <p id="modalCategory">Facilities</p>
                </div>
                <div class="detail-group">
                    <label>Full Pitch / Description</label>
                    <p id="modalDescription" style="height: 100px; overflow-y: auto;">No description available.</p>
                </div>
                <div class="detail-group" id="modalAttachmentGroup" style="display:none;">
                    <label>Attachments / References</label>
                    <img id="modalAttachmentImage" src="" alt="Attachment" style="max-width: 100%; max-height: 300px; border-radius: 8px; cursor: pointer; margin-top: 10px; display: none;" onclick="window.open(this.src, '_blank')">
                    <a id="modalAttachmentLink" target="_blank" style="
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
                <p id="modalNoAttachment" style="font-size: 13px; color: #9ca3af; font-style: italic; padding: 10px; background: #f9fafb; border-radius: 6px;">
                    <i class='bx bx-paperclip' style="margin-right: 5px;"></i>No attachment uploaded
                </p>
            </div>
            <div>
                <h4 style="margin-bottom: 15px; color: #1e3a8a; font-size: 15px;">Dean Response</h4>
                <form method="POST" id="manageForm">
                <input type="hidden" name="action" value="update_suggestion">
                <input type="hidden" name="suggestion_id" id="modalSuggestionId" value="0">
                <input type="hidden" name="remark_id" id="modalRemarkId" value="0">
                <div class="form-group">
                    <label>Status</label>
                    <select class="form-control" id="statusSelect" name="status">
                        <option value="under_review">Under Review</option>
                        <option value="reviewed">Reviewed</option>
                    </select>
                </div>
                <div class="form-group">
                    <label id="modalRemarksLabel">Add Official Remarks (Visible to Student)</label>
                    <textarea class="form-control" id="modalRemarksTextarea" name="remarks" placeholder="Explain the response or implementation steps here..."></textarea>
                </div>
                </form>

                <div id="modalRepliesSection" style="display:none; margin-top:16px; border: 1px solid #e5e7eb; border-radius:12px; padding:16px; background:#ffffff;">
                    <div class="response-history-header">Response History</div>
                    <div id="modalRepliesContainer" class="timeline-list"></div>
                    <div id="modalNoReplies" class="no-replies-message">No previous responses yet.</div>
                </div>
                <div id="modalFeedbackSection" style="display:none; margin-top:16px;">
                    <h4 style="font-size:14px; margin-bottom:8px; color:#111827;">Student Feedback</h4>
                    <div style="display:flex; gap:12px; align-items:center; margin-bottom:8px;">
                        <div id="modalFeedbackBadge" style="padding:6px 10px; border-radius:12px; font-weight:700; font-size:13px;"></div>
                        <div id="modalFeedbackTime" style="font-size:12px; color:#6b7280;"></div>
                    </div>
                    <div id="modalFeedbackComment" style="background:#f9fafb; padding:18px; border-radius:12px; color:#374151; white-space:pre-wrap; text-align:left; justify-content:flex-start; align-items:flex-start; word-break:break-word;">-</div>
                    <div id="feedbackRepliesContainer" style="display:none; margin-top:12px; padding:12px; border-radius:12px; background:#eef2ff;"></div>
                    <div id="feedbackReplyFormWrapper" style="display:none; margin-top:12px; padding:12px; border-radius:12px; background:#ffffff;">
                        <div id="feedbackReplyDisabledMsg" style="display:none; padding:12px; border-radius:8px; background:#fff7ed; color:#92400e; font-weight:600;">This complaint has been resolved. No further replies can be sent.</div>
                        <div id="feedbackReplyFormInner" style="display:block; margin-top:8px;">
                        <h4 style="font-size:14px; margin-bottom:8px; color:#111827;">Response to Student Feedback</h4>
                        <form id="feedbackReplyForm" method="POST" style="display:flex; flex-direction:column; gap:12px;">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="ticket_type" id="feedbackReplyTicketType" value="suggestion">
                            <input type="hidden" name="ticket_id" id="feedbackReplyTicketId" value="">
                            <input type="hidden" name="student_id" id="feedbackReplyStudentId" value="">
                            <input type="hidden" name="feedback_history_id" id="feedbackReplyHistoryId" value="">
                            <textarea name="message" rows="4" required placeholder="Reply to the student about their feedback..." style="width:100%; padding:10px; border:1px solid #d1d5db; border-radius:6px; resize:vertical;"></textarea>
                            <div style="display:flex; gap:12px; align-items:center; justify-content:flex-end; margin-top:10px;">
                                <button type="submit" class="btn-save"><i class='bx bx-send'></i> Send Reply</button>
                            </div>
                            <div id="feedbackReplyStatus" style="margin-top:8px; font-size:13px;"></div>
                        </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn-cancel" onclick="closeModal()">Cancel</button>
            <button class="btn-save" onclick="saveChanges()"><i class='bx bx-save'></i> Save Response</button>
        </div>
    </div>
</div>

<script>
function openModal(button) {
    document.getElementById('modalTitle').innerText = 'Evaluate Suggestion #' + button.dataset.ticket;
    document.getElementById('modalSubject').innerText = button.dataset.subject;
    document.getElementById('modalCategory').innerText = button.dataset.category;
    document.getElementById('modalSubmitter').innerText = button.dataset.submitter;
    document.getElementById('modalDescription').innerText = button.dataset.description;

    const feedbackSection = document.getElementById('modalFeedbackSection');
    const feedbackReplyTicketId = document.getElementById('feedbackReplyTicketId');
    const feedbackReplyStudentId = document.getElementById('feedbackReplyStudentId');
    const feedbackReplyHistoryId = document.getElementById('feedbackReplyHistoryId');
    const feedbackReplyStatus = document.getElementById('feedbackReplyStatus');
    const feedbackBadge = document.getElementById('modalFeedbackBadge');
    const feedbackTime = document.getElementById('modalFeedbackTime');
    const feedbackComment = document.getElementById('modalFeedbackComment');
    const feedbackRepliesContainer = document.getElementById('feedbackRepliesContainer');
    const feedbackReplyToggle = document.getElementById('feedbackReplyToggle');
    const feedbackReplyFormWrapper = document.getElementById('feedbackReplyFormWrapper');
    const repliesSection = document.getElementById('modalRepliesSection');
    const repliesContainer = document.getElementById('modalRepliesContainer');
    const noRepliesMessage = document.getElementById('modalNoReplies');
    const feedbackSat = (button.dataset.feedbackSatisfaction || '').toLowerCase();
    const feedbackId = button.dataset.feedbackId || '';
    const feedbackMsg = button.dataset.feedbackComment || '';
    const feedbackCreated = button.dataset.feedbackCreatedAt || '';

    function resetReplySection() {
        feedbackRepliesContainer.innerHTML = '';
        feedbackRepliesContainer.style.display = 'none';
        if (feedbackReplyToggle) {
            feedbackReplyToggle.style.display = 'none';
        }
        if (feedbackReplyFormWrapper) {
            feedbackReplyFormWrapper.style.display = 'none';
        }
        feedbackReplyStatus.innerHTML = '';
    }

    if (feedbackSat && feedbackSat !== '') {
            feedbackSection.style.display = 'block';
            feedbackReplyTicketId.value = button.dataset.id || '';
            feedbackReplyStudentId.value = button.dataset.studentId || '';
        feedbackReplyHistoryId.value = feedbackId;
        resetReplySection();

        repliesSection.style.display = 'block';
        repliesContainer.innerHTML = '';
        repliesContainer.style.display = 'none';
        noRepliesMessage.style.display = 'none';
        loadTicketReplies('suggestion', button.dataset.id || '');

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

            // Always place the single reply form beneath the feedback replies container
            try {
                const container = document.getElementById('feedbackRepliesContainer');
                if (container) {
                    // Ensure the reply form wrapper follows the replies container
                    if (feedbackReplyFormWrapper && container.parentNode) {
                        container.parentNode.insertBefore(feedbackReplyFormWrapper, container.nextSibling);
                    }
                }
            } catch (e) {}

            // Hide the old in-line toggle if present
            if (feedbackReplyToggle) feedbackReplyToggle.style.display = 'none';

            loadFeedbackReplies('suggestion', button.dataset.id || '', button.dataset.studentId || '', feedbackId);
        } else {
            feedbackSection.style.display = 'none';
            feedbackReplyTicketId.value = '';
            feedbackReplyStudentId.value = '';
            feedbackReplyStatus.innerHTML = '';
            resetReplySection();
            repliesSection.style.display = 'block';
            repliesContainer.innerHTML = '';
            repliesContainer.style.display = 'none';
            noRepliesMessage.style.display = 'block';
            loadTicketReplies('suggestion', button.dataset.id || '');
        }
    }

    function normalizeAvatarUrl(photo) {
        const url = String(photo || '').trim();
        if (url === '') {
            return '';
        }
        if (/^(https?:)?\/\//i.test(url) || url.startsWith('../') || url.startsWith('./') || url.startsWith('/')) {
            return url;
        }
        return '../' + url.replace(/^\.\//, '');
    }

    function getAvatarHtml(name, photo) {
        const label = String(name || '').trim();
        const initial = escapeHtml(label ? label.charAt(0).toUpperCase() : '?');
        const normalizedPhoto = normalizeAvatarUrl(photo);
        if (normalizedPhoto !== '') {
            return `
                <div style="width:40px;height:40px;min-width:40px;border-radius:999px;overflow:hidden;background:#6b46c1;border:1px solid #eef2ff;flex-shrink:0;">
                    <img src="${escapeHtml(normalizedPhoto)}" alt="${escapeHtml(label)}" style="width:100%;height:100%;object-fit:cover;">
                </div>
            `;
        }

        return `
            <div style="width:40px;height:40px;min-width:40px;border-radius:999px;display:flex;align-items:center;justify-content:center;background:#6b46c1;color:#fff;font-weight:700;border:1px solid #eef2ff;font-size:14px;flex-shrink:0;">
                ${initial}
            </div>
        `;
    }

    function formatSenderRole(role) {
        const normalized = String(role || '').trim().toLowerCase();
        switch (normalized) {
            case 'student':
                return 'Student';
            case 'dean':
                return 'College Dean';
            case 'admin':
                return 'Administrator';
            default:
                return normalized ? normalized.charAt(0).toUpperCase() + normalized.slice(1) : 'Staff';
        }
    }

    function renderFeedbackReplies(replies) {
        const container = document.getElementById('feedbackRepliesContainer');
        container.innerHTML = '';
        if (!Array.isArray(replies) || replies.length === 0) {
            container.style.display = 'none';
            return;
        }
        container.style.display = 'block';
        const ordered = replies.slice().sort((a, b) => {
            const ta = new Date(a.created_at).getTime() || 0;
            const tb = new Date(b.created_at).getTime() || 0;
            return ta - tb;
        });

        ordered.forEach((reply) => {
            const timelineItem = document.createElement('div');
            timelineItem.className = 'timeline-item';

            const dotContainer = document.createElement('div');
            dotContainer.className = 'timeline-dot-container';
            dotContainer.innerHTML = '<div class="timeline-dot"></div>';

            const contentContainer = document.createElement('div');
            contentContainer.style.flex = '1';

            const card = document.createElement('div');
            card.className = 'card reply-card';

            const innerContent = document.createElement('div');
            innerContent.className = 'reply-card-inner';

            const avatarDiv = document.createElement('div');
            avatarDiv.className = 'reply-avatar';
            avatarDiv.innerHTML = getAvatarHtml(reply.replier_name || reply.replier_role || 'Admin', reply.replier_photo);

            const textContent = document.createElement('div');
            textContent.style.flex = '1';

            const header = document.createElement('div');
            header.className = 'reply-header';

            const titleGroup = document.createElement('div');
            titleGroup.style.display = 'flex';
            titleGroup.style.flexDirection = 'column';
            titleGroup.style.gap = '2px';

            const nameDiv = document.createElement('div');
            nameDiv.className = 'reply-sender-name';
            nameDiv.textContent = String(reply.replier_name || reply.replier_role || 'Admin').trim();

            const roleDiv = document.createElement('div');
            roleDiv.className = 'reply-sender-role';
            roleDiv.textContent = formatSenderRole(reply.replier_role);

            titleGroup.appendChild(nameDiv);
            titleGroup.appendChild(roleDiv);
            header.appendChild(titleGroup);

            const timeDiv = document.createElement('div');
            timeDiv.className = 'reply-timestamp';
            timeDiv.textContent = reply.created_at;
            header.appendChild(timeDiv);

            const messageDiv = document.createElement('div');
            messageDiv.className = 'reply-message';
            messageDiv.textContent = reply.message;

            textContent.appendChild(header);
            textContent.appendChild(messageDiv);

            innerContent.appendChild(avatarDiv);
            innerContent.appendChild(textContent);
            card.appendChild(innerContent);
            contentContainer.appendChild(card);
            timelineItem.appendChild(dotContainer);
            timelineItem.appendChild(contentContainer);
            container.appendChild(timelineItem);
        });

        try {
            const wrapper = document.getElementById('feedbackReplyFormWrapper');
            if (wrapper && container.parentNode) {
                container.parentNode.insertBefore(wrapper, container.nextSibling);
                const currentStatus = (document.getElementById('statusSelect') || {}).value || '';
                const isResolved = String(currentStatus).toLowerCase() === 'reviewed';
                const disabledMsg = document.getElementById('feedbackReplyDisabledMsg');
                const inner = document.getElementById('feedbackReplyFormInner');
                if (isResolved) {
                    if (inner) inner.style.display = 'none';
                    if (disabledMsg) disabledMsg.style.display = 'block';
                    if (wrapper) wrapper.style.display = 'block';
                } else {
                    if (disabledMsg) disabledMsg.style.display = 'none';
                    if (inner) inner.style.display = 'block';
                    if (wrapper) wrapper.style.display = 'block';
                }
                wrapper.scrollIntoView({ behavior: 'smooth', block: 'end' });
            }
        } catch (e) {}
    }

    function loadFeedbackReplies(ticketType, ticketId, studentId, feedbackHistoryId) {
        const container = document.getElementById('feedbackRepliesContainer');
        const replyToggle = document.getElementById('feedbackReplyToggle');
        const replyFormWrapper = document.getElementById('feedbackReplyFormWrapper');

        if (!ticketType || !ticketId || !studentId) {
            container.style.display = 'none';
            if (replyToggle) replyToggle.style.display = 'none';
            if (replyFormWrapper) replyFormWrapper.style.display = 'none';
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
                    // Render replies chronological (oldest → newest)
                    renderFeedbackReplies(data.replies);
                    // Keep Reply button visible so staff can continue replying
                    if (replyToggle) replyToggle.style.display = 'inline-flex';
                    if (replyFormWrapper) replyFormWrapper.style.display = 'none';
                } else {
                    container.style.display = 'none';
                    if (replyToggle) replyToggle.style.display = 'inline-flex';
                    if (replyFormWrapper) replyFormWrapper.style.display = 'none';
                }
            })
            .catch(() => {
                container.style.display = 'none';
                if (replyToggle) replyToggle.style.display = 'inline-flex';
                if (replyFormWrapper) replyFormWrapper.style.display = 'none';
            });
    }

    function renderTicketReplies(replies) {
        const container = document.getElementById('modalRepliesContainer');
        const noRepliesMessage = document.getElementById('modalNoReplies');
        if (!Array.isArray(replies) || replies.length === 0) {
            container.innerHTML = '';
            container.style.display = 'none';
            noRepliesMessage.style.display = 'block';
            return;
        }

        container.innerHTML = '';
        container.style.display = 'flex';
        container.style.flexDirection = 'column';
        container.style.gap = '18px';
        noRepliesMessage.style.display = 'none';

        replies.forEach((reply) => {
            const timelineItem = document.createElement('div');
            timelineItem.className = 'timeline-entry';

            const avatar = document.createElement('div');
            avatar.className = 'timeline-avatar';
            avatar.innerHTML = getAvatarHtml(reply.sender_name || reply.sender_role || 'Dean', reply.sender_photo);

            const body = document.createElement('div');
            body.className = 'timeline-body';

            const heading = document.createElement('div');
            heading.className = 'timeline-heading';

            const nameDiv = document.createElement('div');
            nameDiv.className = 'timeline-name';
            nameDiv.textContent = String(reply.sender_name || reply.sender_role || 'Dean').trim();

            const roleDiv = document.createElement('div');
            roleDiv.className = 'timeline-role';
            roleDiv.textContent = formatSenderRole(reply.sender_role);

            heading.appendChild(nameDiv);
            heading.appendChild(roleDiv);

            const timeDiv = document.createElement('div');
            timeDiv.className = 'timeline-time';
            timeDiv.textContent = reply.created_at;

            const card = document.createElement('div');
            card.className = 'timeline-card';

            const messageDiv = document.createElement('div');
            messageDiv.className = 'timeline-text';
            messageDiv.innerHTML = reply.message ? String(reply.message).replace(/\n/g, '<br>') : '';

            card.appendChild(messageDiv);
            body.appendChild(heading);
            body.appendChild(timeDiv);
            body.appendChild(card);
            timelineItem.appendChild(avatar);
            timelineItem.appendChild(body);
            container.appendChild(timelineItem);
        });
    }

    async function loadTicketReplies(ticketType, ticketId) {
        const container = document.getElementById('modalRepliesContainer');
        const noRepliesMessage = document.getElementById('modalNoReplies');
        if (!ticketType || !ticketId) {
            container.style.display = 'none';
            noRepliesMessage.style.display = 'block';
            return;
        }

        try {
            let url = `../process_feedback_reply.php?action=list_thread_replies&ticket_type=${encodeURIComponent(ticketType)}&ticket_id=${encodeURIComponent(ticketId)}`;
            const response = await fetch(url);
            const data = await response.json().catch(() => ({}));
            if (response.ok && Array.isArray(data.replies)) {
                renderTicketReplies(data.replies);
            } else {
                container.style.display = 'none';
                noRepliesMessage.style.display = 'block';
            }
        } catch (error) {
            container.style.display = 'none';
            noRepliesMessage.style.display = 'block';
        }
    }

    function appendFeedbackReply(reply) {
        const container = document.getElementById('feedbackRepliesContainer');
        if (!container) return;
        if (container.style.display === 'none') {
            container.style.display = 'block';
            container.innerHTML = '';
        }

        const timelineItem = document.createElement('div');
        timelineItem.className = 'timeline-item';

        const dotContainer = document.createElement('div');
        dotContainer.className = 'timeline-dot-container';
        dotContainer.innerHTML = '<div class="timeline-dot"></div>';

        const contentContainer = document.createElement('div');
        contentContainer.style.flex = '1';

        const card = document.createElement('div');
        card.className = 'card reply-card';

        const innerContent = document.createElement('div');
        innerContent.className = 'reply-card-inner';

        const avatarDiv = document.createElement('div');
        avatarDiv.className = 'reply-avatar';
        avatarDiv.innerHTML = getAvatarHtml(reply.replier_name || reply.replier_role || 'Admin', reply.replier_photo);

        const textContent = document.createElement('div');
        textContent.style.flex = '1';

        const header = document.createElement('div');
        header.className = 'reply-header';

        const titleGroup = document.createElement('div');
        titleGroup.style.display = 'flex';
        titleGroup.style.flexDirection = 'column';
        titleGroup.style.gap = '2px';

        const nameDiv = document.createElement('div');
        nameDiv.className = 'reply-sender-name';
        nameDiv.textContent = String(reply.replier_name || reply.replier_role || 'Admin').trim();

        const roleDiv = document.createElement('div');
        roleDiv.className = 'reply-sender-role';
        roleDiv.textContent = formatSenderRole(reply.replier_role);

        titleGroup.appendChild(nameDiv);
        titleGroup.appendChild(roleDiv);
        header.appendChild(titleGroup);

        const timeDiv = document.createElement('div');
        timeDiv.className = 'reply-timestamp';
        timeDiv.textContent = reply.created_at;
        header.appendChild(timeDiv);

        const messageDiv = document.createElement('div');
        messageDiv.className = 'reply-message';
        messageDiv.textContent = reply.message;

        textContent.appendChild(header);
        textContent.appendChild(messageDiv);

        innerContent.appendChild(avatarDiv);
        innerContent.appendChild(textContent);
        card.appendChild(innerContent);
        contentContainer.appendChild(card);

        timelineItem.appendChild(dotContainer);
        timelineItem.appendChild(contentContainer);
        container.appendChild(timelineItem);

        try {
            const wrapper = document.getElementById('feedbackReplyFormWrapper');
            if (wrapper && container.parentNode) {
                container.parentNode.insertBefore(wrapper, container.nextSibling);
                wrapper.scrollIntoView({ behavior: 'smooth', block: 'end' });
            }
        } catch (e) {}
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

    const deanFeedbackReplyForm = document.getElementById('feedbackReplyForm');
    if (deanFeedbackReplyForm) {
        deanFeedbackReplyForm.addEventListener('submit', submitFeedbackReply);
    }

    // Handle attachment
    const attachment = button.dataset.attachment || '';
    const imgEl = document.getElementById('modalAttachmentImage');
    const linkEl = document.getElementById('modalAttachmentLink');
    if (attachment && attachment !== '') {
        document.getElementById('modalAttachmentGroup').style.display = 'block';
        document.getElementById('modalNoAttachment').style.display = 'none';
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
        document.getElementById('modalAttachmentGroup').style.display = 'none';
        document.getElementById('modalNoAttachment').style.display = 'block';
    }

    document.getElementById('modalSuggestionId').value = button.dataset.id;
    document.getElementById('modalRemarkId').value = button.dataset.remarkId || '0';
    document.getElementById('statusSelect').value = button.dataset.status;

    const remarkText = button.dataset.remarkMessage ? decodeURIComponent(button.dataset.remarkMessage) : '';
    const remarkTextarea = document.getElementById('modalRemarksTextarea');
    if (remarkTextarea) {
        remarkTextarea.disabled = false;
        remarkTextarea.readOnly = false;
        remarkTextarea.value = remarkText;
        remarkTextarea.focus();
    }
    document.getElementById('modalRemarksLabel').innerText = remarkText !== '' ? 'Edit Official Remarks (Visible to Student)' : 'Add Official Remarks (Visible to Student)';

    const modal = document.getElementById('manageModal');
    if (modal) {
        modal.style.display = 'flex';
        modal.style.pointerEvents = 'auto';
    }
}

function closeModal() {
    document.getElementById('manageModal').style.display = 'none';
}

function saveChanges() {
    document.getElementById('manageForm').submit();
}

window.onclick = function(event) {
    if (event.target === document.getElementById('manageModal')) closeModal();
}
</script>
<script>
if (document.getElementById('feedbackReplyForm')) {
    document.getElementById('feedbackReplyForm').addEventListener('submit', async function (event) {
        event.preventDefault();
        const form = event.currentTarget;
        const statusEl = document.getElementById('feedbackReplyStatus');
        const formData = new window.FormData(form);

        try {
            const response = await fetch('../process_feedback_reply.php', {
                method: 'POST',
                body: formData,
            });
            const result = await response.json().catch(() => ({}));
            if (response.ok && result.status === 'ok') {
                statusEl.innerHTML = '<span style="color:#166534;">Reply sent successfully.</span>';
                form.reset();
                setTimeout(() => window.location.reload(), 600);
            } else {
                statusEl.innerHTML = '<span style="color:#b91c1c;">Unable to send the reply. Please try again.</span>';
            }
        } catch (error) {
            statusEl.innerHTML = '<span style="color:#b91c1c;">Unable to send the reply. Please try again.</span>';
        }
    });
}
</script>

</body>
</html>