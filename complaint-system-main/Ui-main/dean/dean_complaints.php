<?php
session_start();
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../complaint_age_helpers.php';
require_once __DIR__ . '/../complaint_ai_helpers.php';
require_once __DIR__ . '/../ticket_flow.php';
require_once __DIR__ . '/../school_year_helpers.php';
require_once __DIR__ . '/../response_timeline_ui.php';

ai_ensure_ai_tables($pdo);

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$flashMsg = '';
$flashType = '';

auto_close_expired_tickets($pdo);

// Opportunistic SLA check - notifies the handling dean/admin once a
// complaint has sat untouched for 24+ hours. Best-effort, runs on page load.
check_and_send_complaint_overdue_notifications($pdo);

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

// Filter variables
$q = trim((string)($_GET['q'] ?? ''));
$statusFilter = strtolower(trim((string)($_GET['status'] ?? 'all')));
$schoolYearFilter = trim((string)($_GET['school_year'] ?? ''));
$semesterFilter = trim((string)($_GET['semester'] ?? ''));
$viewMode = strtolower(trim((string)($_GET['view'] ?? 'all'))); // 'all' or 'new'
$departmentFilter = (int)($_GET['department'] ?? 0);
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$sortBy = (string)($_GET['sort'] ?? '') === 'urgency' ? 'urgency' : 'date';
$allowedStatusFilters = ['all', 'under_review', 'resolved', 'dismissed'];
if (!in_array($statusFilter, $allowedStatusFilters, true)) {
    $statusFilter = 'all';
}
if (!in_array($semesterFilter, ['', '1', '2'], true)) {
    $semesterFilter = '';
}

$departments = [];
if ($deanCollegeId !== null) {
    $departmentsStmt = $pdo->prepare('SELECT id, name FROM programs WHERE college_id = :college_id AND status = "active" ORDER BY name');
    $departmentsStmt->execute([':college_id' => $deanCollegeId]);
    $departments = $departmentsStmt->fetchAll();
}
$availableSchoolYears = $deanCollegeId !== null ? sy_list_for_college($pdo, $deanCollegeId) : [];
if ($schoolYearFilter !== '' && (!sy_is_valid_label($schoolYearFilter) || !in_array($schoolYearFilter, $availableSchoolYears, true))) {
    $schoolYearFilter = '';
}
$departmentIds = array_map(static fn(array $department): int => (int)$department['id'], $departments);
if ($departmentFilter <= 0 || !in_array($departmentFilter, $departmentIds, true)) {
    $departmentFilter = 0;
}

function dean_complaints_valid_date(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

if ($dateFrom !== '' && !dean_complaints_valid_date($dateFrom)) {
    $dateFrom = '';
}
if ($dateTo !== '' && !dean_complaints_valid_date($dateTo)) {
    $dateTo = '';
}
if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_complaint') {
    $complaintId = (int)($_POST['complaint_id'] ?? 0);
    $status = trim((string)($_POST['status'] ?? ''));
    $remarks = trim((string)($_POST['remarks'] ?? ''));
    $allowedStatuses = ['under_review', 'resolved', 'dismissed'];

    if ($complaintId <= 0 || !in_array($status, $allowedStatuses, true)) {
        $flashMsg = 'Invalid complaint update request.';
        $flashType = 'error';
    } else {
        try {
            $sql =
                'SELECT c.id, sp.user_id AS student_user_id
                 FROM complaints c
                 LEFT JOIN student_profiles sp ON sp.id = c.student_id
                 WHERE c.id = :id';

            $sql .= ' AND c.college_id = :college_id';

            $sql .= ' LIMIT 1';

            $verifyStmt = $pdo->prepare($sql);
            $params = [':id' => $complaintId];
            $params[':college_id'] = $deanCollegeId;
            $verifyStmt->execute($params);
            $found = $verifyStmt->fetch();

                if (!$found) {
                $flashMsg = 'Complaint not found in your college scope.';
                $flashType = 'error';
            } else {
                    // A dismissal always carries a reason: if the remarks box
                    // was left empty, the standard wording stands in.
                    if ($status === 'dismissed' && $remarks === '') {
                        $remarks = default_dismissal_remark();
                    }

                    // Resolving still needs a written reason; there is no
                    // sensible generic wording for that outcome.
                    if ($status === 'resolved' && $remarks === '') {
                        $flashMsg = 'Please provide Official Remarks before marking this complaint as Resolved.';
                        $flashType = 'error';
                    } else {
                        set_ticket_status($pdo, 'complaint', $complaintId, $status);

                        if ($remarks !== '') {
                            save_ticket_reply($pdo, 'complaint', $complaintId, $deanUserId > 0 ? $deanUserId : 0, 'dean', $remarks);
                        }

                        $studentUserId = (int)($found['student_user_id'] ?? 0);
                        if ($studentUserId > 0) {
                            $notifStmt = $pdo->prepare(
                                'INSERT INTO notifications (user_id, type, message, ticket_type, ticket_id, is_read)
                                 VALUES (:user_id, :type, :message, :ticket_type, :ticket_id, :is_read)'
                            );
                            $notifStmt->execute([
                                ':user_id' => $studentUserId,
                                ':type' => 'complaint_update',
                                ':message' => 'Your complaint ' . (string)$complaintId . ' has a new response. Current status: ' . strtoupper(str_replace('_', ' ', $status)) . '.',
                                ':ticket_type' => 'complaint',
                                ':ticket_id' => $complaintId,
                                ':is_read' => 0,
                            ]);
                        }

                        $flashMsg = 'Complaint response saved successfully.';
                        $flashType = 'success';
                    }

                $studentUserId = (int)($found['student_user_id'] ?? 0);
                if ($studentUserId > 0) {
                    $notifStmt = $pdo->prepare(
                        'INSERT INTO notifications (user_id, type, message, ticket_type, ticket_id, is_read)
                         VALUES (:user_id, :type, :message, :ticket_type, :ticket_id, :is_read)'
                    );
                    $notifStmt->execute([
                        ':user_id' => $studentUserId,
                        ':type' => 'complaint_update',
                        ':message' => 'Your complaint ' . (string)$complaintId . ' has a new response. Current status: ' . strtoupper(str_replace('_', ' ', $status)) . '.',
                        ':ticket_type' => 'complaint',
                        ':ticket_id' => $complaintId,
                        ':is_read' => 0,
                    ]);
                }

                $flashMsg = 'Complaint response saved successfully.';
                $flashType = 'success';
            }
        } catch (PDOException $e) {
            $flashMsg = 'Failed to update complaint.';
            $flashType = 'error';
        }
    }
}

$complaints = [];
$params = [];

try {
    // Base SQL for complaints
    $sql =
        "SELECT
            c.id,
            c.ticket_no,
            c.created_at,
            c.date_of_incident,
            c.time_of_incident,
            c.place_of_incident,
            c.act_complained_of,
            c.narrative_report,
            c.proof_of_complaint,
            c.desired_outcome,
            c.attachments,
            c.status,
            c.urgency_level,
            c.urgency_score,
            c.ai_detected_language,
            c.is_anonymous,
            c.complainant_name,
            c.complainant_address,
            c.complainant_sex,
            c.complainant_age,
            c.complainant_civil_status,
            c.complainant_contact_details,
            c.person_complained_of,
            COALESCE(cc.name, 'Uncategorized') AS category_name,
            sp.id AS student_id,
            sp.first_name,
            sp.last_name,
            sp.year_level,
            p.name AS department_name,
            tf.id AS feedback_id,
            tf.satisfaction AS feedback_satisfaction,
            tf.comment AS feedback_comment,
            tf.created_at AS feedback_created_at
         FROM complaints c
         LEFT JOIN complaint_categories cc ON cc.id = c.category_id
         LEFT JOIN student_profiles sp ON sp.id = c.student_id
         LEFT JOIN programs p ON p.id = sp.program_id
         LEFT JOIN ticket_feedback tf ON tf.ticket_type = 'complaint' AND tf.ticket_id = c.id AND tf.student_id = sp.id
         WHERE c.approval_status = 'approved'";

    $sql .= ' AND c.college_id = :college_id';
    $params[':college_id'] = $deanCollegeId;

    if ($departmentFilter > 0) {
        $sql .= ' AND sp.program_id = :department';
        $params[':department'] = $departmentFilter;
    }

    if ($schoolYearFilter !== '') {
        $sql .= ' AND c.school_year = :school_year';
        $params[':school_year'] = $schoolYearFilter;
    }

    if ($semesterFilter === '1' || $semesterFilter === '2') {
        // The stored column, set once at submission time from whichever
        // academic calendar was in effect then - not a hardcoded Aug1/Jan1
        // date guess, so this stays correct even if the calendar changes.
        $sql .= ' AND c.semester = :semester';
        $params[':semester'] = $semesterFilter;
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

    // Filter by view mode
    if ($viewMode === 'new') {
        $sql .= ' AND c.status = "new"';
    } elseif ($statusFilter !== 'all') {
        $sql .= ' AND c.status = :status';
        $params[':status'] = $statusFilter;
    }

    if ($q !== '') {
        $sql .= ' AND (c.ticket_no LIKE :q OR c.narrative_report LIKE :q OR cc.name LIKE :q OR sp.first_name LIKE :q OR sp.last_name LIKE :q)';
        $params[':q'] = '%' . $q . '%';
    }

    $sql .= $sortBy === 'urgency'
        ? ' ORDER BY CASE WHEN c.status = "flagged" THEN 0 ELSE 1 END, c.urgency_score DESC, c.created_at DESC'
        : ' ORDER BY CASE WHEN c.status = "flagged" THEN 0 ELSE 1 END, c.created_at DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $complaints = $stmt->fetchAll();
} catch (PDOException $e) {
    if ($flashMsg === '') {
        $flashMsg = 'Unable to load complaints.';
        $flashType = 'error';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Manage Complaints - VOICE</title>
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
.filter-box,
.date-filter {
    display: flex;
    flex-direction: column;
    align-items: stretch;
    gap: 6px;
    min-width: 0;
}

.search-filter > span,
.filter-box > span,
.date-filter > span {
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
}

.filter-box input[name="school_year"] {
    height: 43px;
    width: 100%;
    padding: 10px 14px;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    font-size: 13px;
    font-family: 'Poppins', sans-serif;
    outline: none;
    background: #fff;
    color: #1f2937;
}

.filter-box {
    flex: 0 1 160px;
}

.filter-box select[name="status"] {
    width: 100%;
}

.filter-box select[name="department"] {
    width: 100%;
}

.date-filter {
    flex: 0 1 160px;
}

.date-filter input {
    height: 43px;
    padding: 10px 12px;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    color: #1f2937;
    font: inherit;
    font-size: 13px;
    outline: none;
    background: #fff;
    width: 100%;
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
    width: 100%;
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
    transition: background 0.2s ease;
    white-space: nowrap;
    align-self: end;
}

.btn-search:hover {
    background: #5d1fa0;
}

.btn-clear {
    padding: 9px 15px;
    background: #f3f4f6;
    color: #4b5563;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    font-size: 13px;
    font-family: 'Poppins', sans-serif;
    text-decoration: none;
    cursor: pointer;
    transition: background 0.2s ease;
}

.btn-clear:hover {
    background: #e5e7eb;
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

.status-pending  { background: #fffbeb; color: #d97706; }
.status-review   { background: #eff6ff; color: #2563eb; }
.status-resolved { background: #f0fdf4; color: #16a34a; }
.status-dismissed { background: #f3f4f6; color: #4b5563; }
.status-flagged  { background: #fef2f2; color: #dc2626; }

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
    width: 1000px;
    max-width: 95%;
    max-height: 95vh;
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
    position: sticky;
    top: 0;
    z-index: 10;
}

.modal-header h3 { font-size: 18px; color: #1f2937; font-weight: 600; }
.close-btn { font-size: 24px; color: #888; cursor: pointer; transition: 0.2s; }
.close-btn:hover { color: #333; }

.modal-body {
    padding: 25px;
    display: grid;
    grid-template-columns: 1fr;
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
    position: sticky;
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

.timeline-list { display:flex; flex-direction:column; gap:14px; }
.timeline-entry { display:flex; gap:12px; align-items:flex-start; }
.timeline-avatar { width:44px; height:44px; border-radius:999px; display:inline-flex; align-items:center; justify-content:center; overflow:hidden; background:#6b46c1; color:#fff; font-weight:700; font-size:14px; border:1px solid #eef2ff; flex-shrink:0; }
.timeline-avatar img { width:100%; height:100%; object-fit:cover; }
.timeline-body { flex:1; min-width:0; display:flex; flex-direction:column; }
.timeline-card { display:inline-block; width:fit-content; max-width:min(85%, 640px); padding:12px 14px; border-radius:14px; border:1px solid #e5e7eb; background:#fff; box-shadow:0 1px 2px rgba(15,23,42,0.06); }
.timeline-card.current-user { background:#f3f0ff; border-color:#c4b5fd; }
.timeline-heading { display:flex; align-items:baseline; gap:8px; flex-wrap:wrap; margin-bottom:2px; }
.timeline-name { font-weight:700; color:#111827; font-size:13px; }
.timeline-role { font-size:11.5px; color:#6b7280; font-weight:600; }
.timeline-card .timeline-text { color:#111827; font-size:14px; line-height:1.55; white-space:pre-wrap; word-break:break-word; }
<?php echo response_timeline_styles(); ?>

.flash-msg {
    margin-bottom: 16px;
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

@media (max-width: 900px) {
    .modal-body { grid-template-columns: 1fr; }
    .controls-left { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .search-filter { grid-column: 1 / -1; }
    .btn-search { width: 100%; }
}

@media (max-width: 520px) {
    .controls-left { grid-template-columns: 1fr; }
    .search-filter { grid-column: auto; }
    .btn-search, .btn-clear { width: 100%; text-align: center; }
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

<!-- TOPBAR -->
<?php include 'dean_topbar.php'; ?>

<!-- SIDEBAR -->
<?php include 'dean_sidebar.php'; ?>

<!-- MAIN CONTENT -->
<div class="main">
    <div class="dashboard-container">

        <h2 class="page-title">Manage Complaints</h2>
        <p class="page-subtitle">Review, update, and resolve student complaints for your college.</p>

        <?php if ($flashMsg !== ''): ?>
            <div class="flash-msg <?php echo $flashType === 'success' ? 'flash-success' : 'flash-error'; ?>">
                <?php echo e($flashMsg); ?>
            </div>
        <?php endif; ?>

        <div class="controls-bar">
            <form method="GET" class="controls-form" id="deanComplaintFiltersForm">
                <div class="controls-left">
                    <div class="search-filter">
                        <span>Search</span>
                        <div class="search-box">
                            <i class='bx bx-search'></i>
                            <input type="text" name="q" value="<?php echo e($q); ?>" placeholder="Search by Subject, Category">
                        </div>
                    </div>
                    <div class="filter-box">
                        <span>Status</span>
                        <select name="status">
                            <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                            <option value="under_review" <?php echo $statusFilter === 'under_review' ? 'selected' : ''; ?>>Under Review</option>
                            <option value="resolved" <?php echo $statusFilter === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                            <option value="dismissed" <?php echo $statusFilter === 'dismissed' ? 'selected' : ''; ?>>Dismissed</option>
                        </select>
                    </div>
                    <div class="filter-box">
                        <span>Sort By</span>
                        <select name="sort">
                            <option value="date" <?php echo $sortBy === 'date' ? 'selected' : ''; ?>>Newest First</option>
                            <option value="urgency" <?php echo $sortBy === 'urgency' ? 'selected' : ''; ?>>Most Urgent First</option>
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
                        <a href="dean_complaints.php" class="btn-clear">Clear</a>
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
                    <?php if (count($complaints) === 0): ?>
                        <tr>
                            <td colspan="7">No complaints found for your college.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($complaints as $row): ?>
                            <?php
                                $status = (string)$row['status'];
                                $statusClass = 'status-pending';
                                $statusIcon = 'bx-time';
                                $statusLabel = 'New';
                                if ($status === 'under_review') {
                                    $statusClass = 'status-review';
                                    $statusIcon = 'bx-search';
                                    $statusLabel = 'Under Review';
                                } elseif ($status === 'resolved') {
                                    $statusClass = 'status-resolved';
                                    $statusIcon = 'bx-check-circle';
                                    $statusLabel = 'Resolved';
                                } elseif ($status === 'dismissed') {
                                    $statusClass = 'status-dismissed';
                                    $statusIcon = 'bx-x-circle';
                                    $statusLabel = 'Dismissed';
                                } elseif ($status === 'flagged') {
                                    $statusClass = 'status-flagged';
                                    $statusIcon = 'bx-flag';
                                    $statusLabel = 'Flagged';
                                }

                                // Still untouched: stop calling it "New" once it no longer
                                // is - fall back to a short elapsed-time label instead, and
                                // let the badge color escalate the same way the age chip does.
                                $newLabel = complaint_new_status_label((string)$row['created_at'], $status);
                                if ($newLabel !== null) {
                                    $statusLabel = $newLabel;
                                    $statusClass = complaint_new_status_badge_class((string)$row['created_at'], $status) ?? $statusClass;
                                }

                                $yearLevel = (int)($row['year_level'] ?? 0);
                                $submitter = ((int)$row['is_anonymous'] === 1)
                                    ? 'Anonymous Student'
                                    : trim((string)$row['first_name'] . ' ' . (string)$row['last_name']);
                                $description = trim((string)$row['narrative_report']) !== '' ? (string)$row['narrative_report'] : 'No description provided.';
                                $attachment = trim((string)$row['attachments']) !== '' ? (string)$row['attachments'] : '';
                            ?>
                            <tr>
                                <td>
                                    <div class="subject-text"><?php echo e($submitter); ?></div>
                                </td>
                                <td>
                                    <?php echo e((string)($row['department_name'] ?? 'Unassigned')); ?>
                                </td>
                                <td>
                                    <?php echo e((string)$row['category_name']); ?><?php echo groq_language_chip($row['ai_detected_language'] ?? null); ?>
                                </td>
                                <td><?php echo e($yearLevel > 0 ? (string)$yearLevel : 'N/A'); ?></td>
                                <td><?php echo e(date('M d, Y', strtotime((string)$row['created_at']))); ?></td>
                                <td>
                                    <span class="status-badge <?php echo e($statusClass); ?>"><?php echo e($statusLabel); ?></span>
                                    <div><?php echo complaint_age_badge((string)$row['created_at'], (string)$row['status']); ?><?php echo ai_urgency_chip($row['urgency_level'] ?? null); ?></div>
                                </td>
                                <td>
                                    <a href="dean_ticket_detail.php?id=<?php echo (int)$row['id']; ?>" class="btn-manage" style="text-decoration:none;display:inline-flex;align-items:center;gap:6px;"><i class='bx bx-edit-alt'></i> Manage</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div>
</div>

<div class="modal-overlay" id="manageModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modalTitle">Update Complaint #VOX-0000</h3>
            <i class='bx bx-x close-btn' onclick="closeModal()"></i>
        </div>
        <div class="modal-body">
            <div>
                <h4 style="margin-bottom: 15px; color: #1e3a8a; font-size: 15px;">Complaint Details</h4>
                
                <fieldset style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 10px; margin: 15px 0;">
                    <legend style="font-weight: 600; color: #333; padding: 0 10px; font-size: 13px;">Complainant Information</legend>
                    <div class="detail-group">
                        <label>Name</label>
                        <p id="modalComplainant">N/A</p>
                    </div>
                    <div class="detail-group">
                        <label>Address</label>
                        <p id="modalComplainantAddress">N/A</p>
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                        <div class="detail-group">
                            <label>Sex</label>
                            <p id="modalComplainantSex">N/A</p>
                        </div>
                        <div class="detail-group">
                            <label>Age</label>
                            <p id="modalComplainantAge">N/A</p>
                        </div>
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                        <div class="detail-group">
                            <label>Civil Status</label>
                            <p id="modalComplainantCivilStatus">N/A</p>
                        </div>
                        <div class="detail-group">
                            <label>Contact Details</label>
                            <p id="modalComplainantContact">N/A</p>
                        </div>
                    </div>
                </fieldset>

                <fieldset style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 10px; margin: 15px 0;">
                    <legend style="font-weight: 600; color: #333; padding: 0 10px; font-size: 13px;">Person/Office Complained Of</legend>
                    <div class="detail-group">
                        <label>Name</label>
                        <p id="modalPersonComplained">N/A</p>
                    </div>
                </fieldset>
                
                <fieldset style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 10px; margin: 15px 0;">
                    <legend style="font-weight: 600; color: #333; padding: 0 10px; font-size: 13px;">Incident Details</legend>
                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px;">
                        <div class="detail-group">
                            <label>Date of Incident</label>
                            <p id="modalDateIncident">N/A</p>
                        </div>
                        <div class="detail-group">
                            <label>Time of Incident</label>
                            <p id="modalTimeIncident">N/A</p>
                        </div>
                        <div class="detail-group">
                            <label>Place of Incident</label>
                            <p id="modalPlaceIncident">N/A</p>
                        </div>
                    </div>
                    <div class="detail-group">
                        <label>Category</label>
                        <p id="modalCategory">Academic</p>
                    </div>
                    <div class="detail-group">
                        <label>Act/s Complained Of</label>
                        <p id="modalActComplained" style="height: 80px; overflow-y: auto;">No information available.</p>
                    </div>
                    <div class="detail-group">
                        <label>Narrative Report</label>
                        <p id="modalNarrative" style="height: 100px; overflow-y: auto;">No narrative available.</p>
                    </div>
                </fieldset>
                
                <fieldset style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 10px; margin: 15px 0;">
                    <legend style="font-weight: 600; color: #333; padding: 0 10px; font-size: 13px;">Proof of Complaint</legend>
                    <div class="detail-group">
                        <label>Documents/Evidence/Witnesses</label>
                        <p id="modalProof" style="height: 60px; overflow-y: auto;">No proof provided.</p>
                    </div>
                    <div class="detail-group" id="modalAttachmentGroup" style="display:none; margin-top: 15px;">
                        <label>Attachment</label>
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
                    <p id="modalNoAttachment" style="font-size: 13px; color: #9ca3af; font-style: italic; padding: 10px; background: #f9fafb; border-radius: 6px; margin-top: 15px;">
                        <i class='bx bx-paperclip' style="margin-right: 5px;"></i>No attachment uploaded
                    </p>
                </fieldset>
                
                <fieldset style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 10px; margin: 15px 0;">
                    <legend style="font-weight: 600; color: #333; padding: 0 10px; font-size: 13px;">Complaint Outcome</legend>
                    <div class="detail-group">
                        <label>Desired Outcome</label>
                        <p id="modalDesiredOutcome" style="height: 80px; overflow-y: auto;">No outcome specified.</p>
                    </div>
                </fieldset>
                
                <div class="detail-group">
                    <label>Submitted By</label>
                    <p id="modalSubmitter">Anonymous</p>
                </div>
            </div>
            <div>
                <h4 style="margin-bottom: 15px; color: #1e3a8a; font-size: 15px;">Dean Response</h4>
                <form method="POST" id="manageForm">
                <input type="hidden" name="action" value="update_complaint">
                <input type="hidden" name="complaint_id" id="modalComplaintId" value="0">
                <div class="form-group">
                    <label>Status</label>
                    <select class="form-control" id="statusSelect" name="status">
                        <option value="new">New</option>
                        <option value="under_review">Under Review</option>
                        <option value="resolved">Resolved</option>
                        <option value="dismissed">Dismissed</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Add Official Remarks (Visible to Student)</label>
                    <textarea id="modalRemarksTextarea" class="form-control" name="remarks" placeholder="Type your response or next steps here..." required></textarea>
                </div>
                <script>
                    (function(){
                        var status = document.getElementById('statusSelect');
                        var remark = document.getElementById('modalRemarksTextarea');
                        if (!status || !remark) return;

                        var defaultDismissalRemark = <?php echo json_encode(default_dismissal_remark(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

                        function toggleRequired(){
                            remark.required = (status.value === 'resolved' || status.value === 'dismissed');

                            if (status.value === 'dismissed') {
                                // Standard wording as a starting point; never
                                // overwrite remarks already written.
                                if (remark.value.trim() === '') {
                                    remark.value = defaultDismissalRemark;
                                }
                            } else if (remark.value.trim() === defaultDismissalRemark) {
                                remark.value = '';
                            }
                        }

                        status.addEventListener('change', toggleRequired);
                        toggleRequired();

                        // The modal sets the status when it opens, so it needs
                        // to re-sync the remarks box afterwards.
                        window.syncRemarkForStatus = toggleRequired;
                    })();
                </script>
                </form>

                <fieldset id="modalRepliesSection" style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 10px; margin: 15px 0 0; display:none;">
                    <legend style="font-weight: 600; color: #333; padding: 0 10px; font-size: 13px;">Response History</legend>
                    <div id="modalRepliesContainer" style="display:none; margin-top:10px; flex-direction:column; gap:12px;"></div>
                    <div id="modalNoReplies" style="display:none; margin-top:10px; font-size:13px; color:#6b7280; background:#f9fafb; border-radius:12px; padding:12px;">No previous responses yet.</div>
                </fieldset>
                <fieldset id="modalFeedbackSection" style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 10px; margin: 15px 0 0; display:none;">
                    <legend style="font-weight: 600; color: #333; padding: 0 10px; font-size: 13px;">Student Feedback</legend>
                    <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:8px;">
                        <div id="modalFeedbackStudentName" style="font-size:13px; font-weight:700; color:#111827;"></div>
                        <div style="display:flex; gap:12px; align-items:center;">
                            <div id="modalFeedbackBadge" style="padding:6px 10px; border-radius:12px; font-weight:700; font-size:13px;"></div>
                            <div id="modalFeedbackTime" style="font-size:12px; color:#6b7280;"></div>
                        </div>
                    </div>
                    <div id="modalFeedbackComment" style="background:#f9fafb; padding:12px; border-radius:12px; color:#374151; white-space:pre-wrap;">-</div>
                    <div id="feedbackRepliesContainer" style="display:none; margin-top:12px; padding:12px; border-radius:12px; background:#eef2ff;"></div>
                    <button type="button" id="feedbackReplyToggle" style="display:none; margin-top:12px; padding:10px 16px; border:1px solid #c7d2fe; background:#eff6ff; color:#1d4ed8; border-radius:8px; cursor:pointer; font-weight:600;">
                        <i class='bx bx-reply'></i> Reply
                    </button>
                    <div id="feedbackReplyFormWrapper" style="display:none; margin-top:12px; padding:12px; border:1px solid #e5e7eb; border-radius:12px; background:#ffffff;">
                        <form id="feedbackReplyForm" method="POST" style="display:flex; flex-direction:column; gap:12px;">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="ticket_type" id="feedbackReplyTicketType" value="complaint">
                            <input type="hidden" name="ticket_id" id="feedbackReplyTicketId" value="">
                            <input type="hidden" name="student_id" id="feedbackReplyStudentId" value="">
                            <input type="hidden" name="feedback_history_id" id="feedbackReplyHistoryId" value="">
                            <textarea name="message" rows="4" required placeholder="Reply to the student about their feedback..." style="width:100%; padding:10px; border:1px solid #d1d5db; border-radius:10px; resize:vertical;"></textarea>
                            <button type="submit" class="btn-save" style="margin-top: 10px; padding:10px 16px; background:#4F8CFF; color:#fff; border:none; border-radius:8px; cursor:pointer;"><i class='bx bx-send'></i> Send Reply</button>
                            <div id="feedbackReplyStatus" style="margin-top: 8px; font-size: 13px;"></div>
                        </form>
                    </div>
                </fieldset>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn-cancel" onclick="closeModal()">Cancel</button>
            <button class="btn-save" onclick="saveChanges()"><i class='bx bx-save'></i> Save Changes</button>
        </div>
    </div>
</div>

<script>
function openModal(button) {
    document.getElementById('modalTitle').innerText = 'Update Complaint #' + button.dataset.ticket;
    document.getElementById('modalComplainant').innerText = button.dataset.complainant || 'N/A';
    document.getElementById('modalComplainantAddress').innerText = button.dataset.complainantAddress || 'N/A';
    document.getElementById('modalComplainantSex').innerText = button.dataset.complainantSex || 'N/A';
    document.getElementById('modalComplainantAge').innerText = button.dataset.complainantAge || 'N/A';
    document.getElementById('modalComplainantCivilStatus').innerText = button.dataset.complainantCivilStatus || 'N/A';
    document.getElementById('modalComplainantContact').innerText = button.dataset.complainantContact || 'N/A';
    document.getElementById('modalPersonComplained').innerText = button.dataset.personComplained || 'N/A';
    document.getElementById('modalCategory').innerText = button.dataset.category;
    document.getElementById('modalDateIncident').innerText = button.dataset.dateIncident || 'N/A';
    document.getElementById('modalTimeIncident').innerText = button.dataset.timeIncident || 'N/A';
    document.getElementById('modalPlaceIncident').innerText = button.dataset.placeIncident || 'N/A';
    document.getElementById('modalActComplained').innerText = button.dataset.actComplained || 'No information available.';
    document.getElementById('modalNarrative').innerText = button.dataset.narrative || 'No narrative available.';
    document.getElementById('modalProof').innerText = button.dataset.proof || 'No proof provided.';
    document.getElementById('modalDesiredOutcome').innerText = button.dataset.desiredOutcome || 'No outcome specified.';
    document.getElementById('modalSubmitter').innerText = button.dataset.submitter;

    const feedbackSection = document.getElementById('modalFeedbackSection');
    const feedbackRepliesContainer = document.getElementById('feedbackRepliesContainer');
    const feedbackReplyToggle = document.getElementById('feedbackReplyToggle');
    const feedbackReplyFormWrapper = document.getElementById('feedbackReplyFormWrapper');
    const feedbackReplyTicketId = document.getElementById('feedbackReplyTicketId');
    const feedbackReplyStudentId = document.getElementById('feedbackReplyStudentId');
    const feedbackReplyHistoryId = document.getElementById('feedbackReplyHistoryId');
    const feedbackReplyStatus = document.getElementById('feedbackReplyStatus');
    const feedbackBadge = document.getElementById('modalFeedbackBadge');
    const feedbackTime = document.getElementById('modalFeedbackTime');
    const feedbackComment = document.getElementById('modalFeedbackComment');
    const feedbackStudentName = document.getElementById('modalFeedbackStudentName');
    const repliesSection = document.getElementById('modalRepliesSection');
    const repliesContainer = document.getElementById('modalRepliesContainer');
    const noRepliesMessage = document.getElementById('modalNoReplies');
    const feedbackSat = (button.dataset.feedbackSatisfaction || '').toLowerCase();
    const feedbackMsg = button.dataset.feedbackComment || '';
    const feedbackCreated = button.dataset.feedbackCreatedAt || '';
    const feedbackId = button.dataset.feedbackId || '';

    if (feedbackSat && feedbackSat !== '') {
        feedbackStudentName.textContent = button.dataset.submitter ? 'Student: ' + button.dataset.submitter : 'Student Feedback';
        feedbackSection.style.display = 'block';
        feedbackRepliesContainer.innerHTML = '';
        feedbackRepliesContainer.style.display = 'none';
        feedbackReplyToggle.style.display = 'inline-flex';
        feedbackReplyFormWrapper.style.display = 'none';
        feedbackReplyTicketId.value = button.dataset.id || '';
        feedbackReplyStudentId.value = button.dataset.studentId || '';
        feedbackReplyHistoryId.value = feedbackId;
        feedbackReplyStatus.innerHTML = '';
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

        feedbackReplyToggle.onclick = function () {
            feedbackReplyFormWrapper.style.display = feedbackReplyFormWrapper.style.display === 'none' ? 'block' : 'none';
        };
        loadFeedbackReplies('complaint', button.dataset.id || '', button.dataset.studentId || '', feedbackId);
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

    document.getElementById('modalComplaintId').value = button.dataset.id;
    document.getElementById('statusSelect').value = button.dataset.status;
    if (typeof window.syncRemarkForStatus === 'function') {
        window.syncRemarkForStatus();
    }

    repliesSection.style.display = 'block';
    repliesContainer.innerHTML = '';
    repliesContainer.style.display = 'none';
    noRepliesMessage.style.display = 'none';
    loadTicketReplies('complaint', button.dataset.id || '');

    document.getElementById('manageModal').style.display = 'flex';
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

function buildTimelineEntry(reply, isReply) {
    const timelineItem = document.createElement('div');
    timelineItem.className = 'timeline-entry';

    const avatarWrap = document.createElement('div');
    avatarWrap.innerHTML = getAvatarHtml(reply.sender_name || reply.sender_role || 'Dean', reply.sender_photo);
    const avatar = avatarWrap.firstElementChild;
    if (avatar) {
        avatar.classList.add('timeline-avatar');
        if (isReply) avatar.classList.add('is-small');
    }

    const body = document.createElement('div');
    body.className = 'timeline-body';

    const card = document.createElement('div');
    card.className = 'timeline-card';

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

    const messageDiv = document.createElement('div');
    messageDiv.className = 'timeline-text';
    messageDiv.textContent = reply.message || '';

    card.appendChild(heading);
    card.appendChild(messageDiv);

    const metaRow = document.createElement('div');
    metaRow.className = 'timeline-meta-row';

    const timeSpan = document.createElement('span');
    timeSpan.className = 'timeline-time';
    timeSpan.textContent = reply.created_at;
    metaRow.appendChild(timeSpan);

    const replyBtn = document.createElement('button');
    replyBtn.type = 'button';
    replyBtn.className = 'timeline-reply-link';
    replyBtn.textContent = 'Reply';
    replyBtn.addEventListener('click', function () {
        const target = document.getElementById('modalRemarksTextarea');
        if (target) {
            target.scrollIntoView({ behavior: 'smooth', block: 'center' });
            target.focus({ preventScroll: true });
        }
    });
    metaRow.appendChild(replyBtn);

    body.appendChild(card);
    body.appendChild(metaRow);
    if (avatar) timelineItem.appendChild(avatar);
    timelineItem.appendChild(body);
    return timelineItem;
}

function renderTicketReplies(replies) {
    const repliesContainer = document.getElementById('modalRepliesContainer');
    const noRepliesMessage = document.getElementById('modalNoReplies');
    if (!Array.isArray(replies) || replies.length === 0) {
        repliesContainer.innerHTML = '';
        repliesContainer.style.display = 'none';
        noRepliesMessage.style.display = 'block';
        return;
    }

    repliesContainer.innerHTML = '';
    repliesContainer.style.display = 'flex';
    repliesContainer.style.flexDirection = 'column';
    repliesContainer.style.gap = '18px';
    noRepliesMessage.style.display = 'none';

    // First message is the anchor response; anything after it is shown
    // indented underneath, like replies under a comment.
    repliesContainer.appendChild(buildTimelineEntry(replies[0], false));

    if (replies.length > 1) {
        const repliesWrap = document.createElement('div');
        repliesWrap.className = 'timeline-replies';
        for (let i = 1; i < replies.length; i++) {
            repliesWrap.appendChild(buildTimelineEntry(replies[i], true));
        }
        repliesContainer.appendChild(repliesWrap);
    }
}

async function loadTicketReplies(ticketType, ticketId) {
    const repliesContainer = document.getElementById('modalRepliesContainer');
    const repliesSection = document.getElementById('modalRepliesSection');
    const noRepliesMessage = document.getElementById('modalNoReplies');
    if (!ticketType || !ticketId) {
        repliesSection.style.display = 'block';
        repliesContainer.style.display = 'none';
        noRepliesMessage.style.display = 'block';
        return;
    }

    try {
        const params = new window.URLSearchParams({
            action: 'list_thread_replies',
            ticket_type: ticketType,
            ticket_id: ticketId,
        });
        const url = `../process_feedback_reply.php?${params.toString()}`;
        const response = await fetch(url);
        const data = await response.json().catch(() => {
            console.error('Failed to parse JSON response from ' + url);
            return {};
        });
        
        if (response.ok && data.status === 'ok' && Array.isArray(data.replies)) {
            renderTicketReplies(data.replies);
        } else {
            repliesContainer.style.display = 'none';
            noRepliesMessage.style.display = 'block';
        }
    } catch (error) {
        console.error('Error loading ticket replies:', error);
        repliesContainer.style.display = 'none';
        noRepliesMessage.style.display = 'block';
    }
}

function closeModal() {
    document.getElementById('manageModal').style.display = 'none';
}

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function appendFeedbackReply(reply) {
    const feedbackRepliesContainer = document.getElementById('feedbackRepliesContainer');
    const replyItem = document.createElement('div');
    replyItem.style.marginTop = '10px';
    replyItem.style.padding = '12px';
    replyItem.style.border = '1px solid #e5e7eb';
    replyItem.style.borderRadius = '12px';
    replyItem.style.background = '#f8fafc';
    replyItem.innerHTML = `
        <div style="font-size:12px; color:#6b7280; margin-bottom:6px;">
            ${escapeHtml(reply.replier_name || reply.replier_role || 'Dean').toUpperCase()} • ${escapeHtml(reply.created_at)}
        </div>
        <div style="white-space:pre-wrap; color:#111827;">${escapeHtml(reply.message)}</div>
    `;
    feedbackRepliesContainer.appendChild(replyItem);
}

async function loadFeedbackReplies(ticketType, ticketId, studentId, feedbackHistoryId) {
    const feedbackRepliesContainer = document.getElementById('feedbackRepliesContainer');
    const feedbackSection = document.getElementById('modalFeedbackSection');
    const feedbackReplyToggle = document.getElementById('feedbackReplyToggle');
    if (!feedbackRepliesContainer) return;

    feedbackRepliesContainer.innerHTML = '';
    if (!ticketId || !studentId || !feedbackHistoryId) {
        feedbackRepliesContainer.style.display = 'none';
        feedbackReplyToggle.style.display = 'none';
        return;
    }

    try {
        const params = new window.URLSearchParams({
            action: 'list_replies',
            ticket_type: ticketType,
            ticket_id: ticketId,
            student_id: studentId,
            feedback_history_id: feedbackHistoryId,
        });
        const response = await fetch(`../process_feedback_reply.php?${params.toString()}`);
        const data = await response.json().catch(() => ({}));
        if (response.ok && Array.isArray(data.replies) && data.replies.length > 0) {
            data.replies.forEach(appendFeedbackReply);
            feedbackRepliesContainer.style.display = 'block';
            feedbackReplyToggle.style.display = 'inline-flex';
        } else {
            feedbackRepliesContainer.style.display = 'none';
            feedbackReplyToggle.style.display = 'inline-flex';
        }
    } catch (error) {
        feedbackRepliesContainer.style.display = 'none';
        feedbackReplyToggle.style.display = 'inline-flex';
    }
}

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
                const feedbackHistoryId = document.getElementById('feedbackReplyHistoryId').value;
                loadFeedbackReplies('complaint', document.getElementById('feedbackReplyTicketId').value, document.getElementById('feedbackReplyStudentId').value, feedbackHistoryId);
            } else {
                statusEl.innerHTML = '<span style="color:#b91c1c;">Unable to send the reply. Please try again.</span>';
            }
        } catch (error) {
            statusEl.innerHTML = '<span style="color:#b91c1c;">Unable to send the reply. Please try again.</span>';
        }
    });
}

function saveChanges() {
    document.getElementById('manageForm').submit();
}

window.onclick = function(event) {
    if (event.target === document.getElementById('manageModal')) closeModal();
}

const deanComplaintFiltersForm = document.getElementById('deanComplaintFiltersForm');
if (deanComplaintFiltersForm) {
    const searchInput = deanComplaintFiltersForm.querySelector('input[name="q"]');
    const focusKey = 'deanComplaintSearchFocus';
    let searchTimer;

    if (searchInput) {
        if (window.sessionStorage.getItem(focusKey) === '1') {
            searchInput.focus();
            searchInput.setSelectionRange(searchInput.value.length, searchInput.value.length);
        }
        searchInput.addEventListener('input', () => {
            window.sessionStorage.setItem(focusKey, '1');
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(() => deanComplaintFiltersForm.submit(), 350);
        });
    }

    deanComplaintFiltersForm.querySelectorAll('select[name="status"], select[name="sort"], select[name="semester"], select[name="department"], input[name="date_from"], input[name="date_to"]').forEach((filter) => {
        filter.addEventListener('change', () => {
            window.sessionStorage.removeItem(focusKey);
            deanComplaintFiltersForm.submit();
        });
    });
}
</script>

<button type="button" id="scrollTopBtn" class="scroll-top-btn" aria-label="Scroll to top" title="Back to top">
    <i class='bx bx-up-arrow-alt'></i>
</button>
<script>
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
<?php echo sy_smart_input_script(); ?>

</body>
</html>