<?php
session_start();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../school_year_helpers.php';

if (!isset($_SESSION['user_id']) || (string)($_SESSION['role'] ?? '') !== 'dean') {
    header('Location: login.php');
    exit;
}

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

$deanStmt = $pdo->prepare('SELECT dp.college_id, c.name AS college_name, c.code AS college_code FROM dean_profiles dp LEFT JOIN colleges c ON c.id = dp.college_id WHERE dp.user_id = :user_id LIMIT 1');
$deanStmt->execute([':user_id' => (int)$_SESSION['user_id']]);
$dean = $deanStmt->fetch(PDO::FETCH_ASSOC);
if (!$dean || empty($dean['college_id'])) {
    http_response_code(403);
    echo 'Dean college not configured.';
    exit;
}
$collegeId = (int)$dean['college_id'];

$studentId = (int)($_GET['student_id'] ?? 0);
$cardFilter = trim((string)($_GET['card_filter'] ?? ''));
if (!in_array($cardFilter, ['complaints', 'suggestions', 'reviewed_suggestions', 'resolved_complaints'], true)) {
    $cardFilter = '';
}

$departmentsStmt = $pdo->prepare('SELECT id, name FROM programs WHERE college_id = :college_id ORDER BY name');
$departmentsStmt->execute([':college_id' => $collegeId]);
$departmentIds = array_map(static fn(array $row): int => (int)$row['id'], $departmentsStmt->fetchAll(PDO::FETCH_ASSOC));
$programId = (int)($_GET['department'] ?? 0);
if ($programId <= 0 || !in_array($programId, $departmentIds, true)) {
    $programId = 0;
}

$availableSchoolYears = sy_list_for_college($pdo, $collegeId);
$schoolYear = trim((string)($_GET['school_year'] ?? ''));
if ($schoolYear !== '' && (!sy_is_valid_label($schoolYear) || !in_array($schoolYear, $availableSchoolYears, true))) {
    $schoolYear = '';
}
$semester = trim((string)($_GET['semester'] ?? ''));
if (!in_array($semester, ['1', '2'], true)) {
    $semester = '';
}
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$validDate = static function (string $date): bool {
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
};
if ($dateFrom !== '' && !$validDate($dateFrom)) $dateFrom = '';
if ($dateTo !== '' && !$validDate($dateTo)) $dateTo = '';
if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}
$rangeStart = $dateFrom !== '' ? $dateFrom . ' 00:00:00' : '';
$rangeEnd = $dateTo !== '' ? (new DateTimeImmutable($dateTo))->modify('+1 day')->format('Y-m-d 00:00:00') : '';

// New list-page params (search/status/category/page) - additive, independent of the existing card_filter mechanism.
$status = in_array((string)($_GET['status'] ?? ''), ['new', 'under_review', 'resolved'], true) ? (string)$_GET['status'] : '';
$categoryId = (int)($_GET['category'] ?? 0);
$searchQ = trim((string)($_GET['cq'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$pageSize = 10;

$studentStmt = $pdo->prepare(
    'SELECT sp.id, sp.student_number, sp.first_name, sp.last_name, sp.middle_name,
            sp.year_level, sp.section, sp.status, sp.school_year AS student_school_year,
            p.name AS program, c.name AS college_name,
            u.profile_pic
     FROM student_profiles sp
     LEFT JOIN programs p ON p.id = sp.program_id
     LEFT JOIN colleges c ON c.id = sp.college_id
     LEFT JOIN users u ON u.id = sp.user_id
     WHERE sp.id = :student_id AND sp.college_id = :college_id
    AND (:program_id_filter = 0 OR sp.program_id = :program_id_match)
     LIMIT 1'
);
$studentStmt->execute([
    ':student_id' => $studentId,
    ':college_id' => $collegeId,
    ':program_id_filter' => $programId,
    ':program_id_match' => $programId,
]);
$student = $studentStmt->fetch(PDO::FETCH_ASSOC);
if (!$student) {
    http_response_code(404);
    echo 'Student not found in this dean\'s college.';
    exit;
}

$studentPhotoStored = trim((string)($student['profile_pic'] ?? ''));
$studentPhotoSrc = ($studentPhotoStored !== '' && is_file(__DIR__ . '/../' . ltrim($studentPhotoStored, '/')))
    ? '../' . ltrim($studentPhotoStored, '/')
    : '../assets/images/default-avatar.svg';

function report_detail_filter_sql(string $alias, string $schoolYear, string $semester, string $rangeStart, string $rangeEnd, array &$params, string $prefix): string
{
    $sql = '';
    if ($schoolYear !== '') {
        $sql .= " AND {$alias}.school_year = :{$prefix}_school_year";
        $params[":{$prefix}_school_year"] = $schoolYear;
    }
    if ($semester === '1' || $semester === '2') {
        // The stored column, set once at submission time from whichever
        // academic calendar was in effect then - not a hardcoded Aug1/Jan1
        // date guess, so this stays correct even if the calendar changes.
        $sql .= " AND {$alias}.semester = :{$prefix}_semester";
        $params[":{$prefix}_semester"] = $semester;
    }
    if ($rangeStart !== '') {
        $sql .= " AND {$alias}.created_at >= :{$prefix}_range_start";
        $params[":{$prefix}_range_start"] = $rangeStart;
    }
    if ($rangeEnd !== '') {
        $sql .= " AND {$alias}.created_at < :{$prefix}_range_end";
        $params[":{$prefix}_range_end"] = $rangeEnd;
    }
    return $sql;
}

function report_detail_status_label(string $status): string { return $status !== '' ? ucwords(str_replace('_', ' ', $status)) : 'Not set'; }
function report_detail_status_class(string $status): string
{
    switch ($status) {
        case 'resolved': return 'pill-green';
        case 'under_review': return 'pill-amber';
        case 'new': return 'pill-blue';
        default: return 'pill-gray';
    }
}
/** Builds a page-number list with ellipsis markers (null) for long ranges, so pagination stays compact with hundreds of pages. */
function report_detail_page_numbers(int $current, int $total): array
{
    $pages = [];
    $windowSize = 2;
    for ($p = 1; $p <= $total; $p++) {
        if ($p === 1 || $p === $total || ($p >= $current - $windowSize && $p <= $current + $windowSize)) {
            $pages[] = $p;
        } elseif (end($pages) !== null) {
            $pages[] = null;
        }
    }
    return $pages;
}

$complaints = [];
$suggestions = [];
$baseParams = [':student_id' => $studentId, ':college_id' => $collegeId];
$loadComplaints = $cardFilter === '' || in_array($cardFilter, ['complaints', 'resolved_complaints'], true);
$loadSuggestions = $cardFilter === '' || in_array($cardFilter, ['suggestions', 'reviewed_suggestions'], true);

$categoryOptions = $pdo->query('SELECT id, name FROM complaint_categories WHERE is_active = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);

// Lifetime profile stats for the header - independent of the list filters/card_filter below.
$allTimeFiledByStmt = $pdo->prepare('SELECT COUNT(*) FROM complaints WHERE student_id = :student_id AND college_id = :college_id');
$allTimeFiledByStmt->execute([':student_id' => $studentId, ':college_id' => $collegeId]);
$allTimeFiledByCount = (int)$allTimeFiledByStmt->fetchColumn();

$filedAgainstStmt = $pdo->prepare("SELECT COUNT(*) FROM complaints c INNER JOIN complaint_student_links csl ON csl.complaint_id = c.id WHERE csl.student_id = :student_id AND c.approval_status = 'approved'");
$filedAgainstStmt->execute([':student_id' => $studentId]);
$filedAgainstCount = (int)$filedAgainstStmt->fetchColumn();

$summaryTotal = 0;
$summaryResolved = 0;
$summaryUnderReview = 0;
$filteredTotal = 0;
$totalPages = 1;
$offset = 0;
$rowRangeStart = 0;
$rowRangeEnd = 0;

if ($loadComplaints) {
    // Period-scoped summary tiles (Total / Resolved / Under Review) - reflect school year/semester/date range
    // only, not status/category/search/page, so they read as "this period" not "this search".
    $summaryParams = [':student_id' => $studentId, ':college_id' => $collegeId];
    $summaryWhere = ' WHERE c.student_id = :student_id AND c.college_id = :college_id' . report_detail_filter_sql('c', $schoolYear, $semester, $rangeStart, $rangeEnd, $summaryParams, 'summary');
    $summaryStmt = $pdo->prepare(
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN c.status = 'resolved' THEN 1 ELSE 0 END) AS resolved,
                SUM(CASE WHEN c.status = 'under_review' THEN 1 ELSE 0 END) AS under_review
         FROM complaints c" . $summaryWhere
    );
    $summaryStmt->execute($summaryParams);
    $summaryRow = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $summaryTotal = (int)($summaryRow['total'] ?? 0);
    $summaryResolved = (int)($summaryRow['resolved'] ?? 0);
    $summaryUnderReview = (int)($summaryRow['under_review'] ?? 0);

    $complaintParams = [':student_id' => $studentId, ':college_id' => $collegeId];
    $complaintWhere = ' WHERE c.student_id = :student_id AND c.college_id = :college_id' . report_detail_filter_sql('c', $schoolYear, $semester, $rangeStart, $rangeEnd, $complaintParams, 'complaint_detail');
    if ($cardFilter === 'resolved_complaints') {
        $complaintWhere .= " AND c.status = 'resolved'";
    }
    if ($status !== '') { $complaintWhere .= ' AND c.status = :status'; $complaintParams[':status'] = $status; }
    if ($categoryId > 0) { $complaintWhere .= ' AND c.category_id = :category_id'; $complaintParams[':category_id'] = $categoryId; }
    if ($searchQ !== '') {
        $complaintWhere .= ' AND (c.ticket_no LIKE :search1 OR cc.name LIKE :search2 OR c.narrative_report LIKE :search3 OR c.act_complained_of LIKE :search4 OR c.status LIKE :search5)';
        $searchTerm = '%' . $searchQ . '%';
        $complaintParams[':search1'] = $searchTerm;
        $complaintParams[':search2'] = $searchTerm;
        $complaintParams[':search3'] = $searchTerm;
        $complaintParams[':search4'] = $searchTerm;
        $complaintParams[':search5'] = $searchTerm;
    }

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM complaints c LEFT JOIN complaint_categories cc ON cc.id = c.category_id' . $complaintWhere);
    $countStmt->execute($complaintParams);
    $filteredTotal = (int)$countStmt->fetchColumn();

    $totalPages = max(1, (int)ceil($filteredTotal / $pageSize));
    if ($page > $totalPages) { $page = $totalPages; }
    $offset = ($page - 1) * $pageSize;

    $sql = "SELECT c.*, cc.name AS category_name,
                   (SELECT tr.message FROM ticket_replies tr WHERE tr.ticket_type = 'complaint' AND tr.ticket_id = c.id AND tr.sender_role = 'dean' ORDER BY tr.created_at DESC, tr.id DESC LIMIT 1) AS dean_remark
            FROM complaints c
            LEFT JOIN complaint_categories cc ON cc.id = c.category_id"
        . $complaintWhere . " ORDER BY c.created_at DESC, c.id DESC LIMIT {$pageSize} OFFSET {$offset}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($complaintParams);
    $complaints = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $rowRangeStart = $filteredTotal > 0 ? $offset + 1 : 0;
    $rowRangeEnd = min($offset + $pageSize, $filteredTotal);
}

if ($loadSuggestions) {
    $params = $baseParams;
    $sql = "SELECT s.*, sc.name AS category_name,
                   (SELECT tr.message FROM ticket_replies tr WHERE tr.ticket_type = 'suggestion' AND tr.ticket_id = s.id AND tr.sender_role = 'dean' ORDER BY tr.created_at DESC, tr.id DESC LIMIT 1) AS dean_remark
            FROM suggestions s
            LEFT JOIN suggestion_categories sc ON sc.id = s.category_id
            WHERE s.student_id = :student_id AND s.college_id = :college_id";
    $sql .= report_detail_filter_sql('s', $schoolYear, $semester, $rangeStart, $rangeEnd, $params, 'suggestion_detail');
    if ($cardFilter === 'reviewed_suggestions') {
        $sql .= " AND s.status = 'reviewed'";
    }
    $sql .= ' ORDER BY COALESCE(s.date_of_suggestion, DATE(s.created_at)) DESC, s.id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $suggestions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($suggestions as &$suggestion) { $suggestion['ticket_no'] = ''; }
    unset($suggestion);
}

$backParams = array_filter([
    'q' => trim((string)($_GET['q'] ?? '')),
    'sort' => trim((string)($_GET['sort'] ?? '')),
    'school_year' => $schoolYear,
    'semester' => $semester,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'department' => $programId > 0 ? (string)$programId : '',
    'card_filter' => $cardFilter,
], static fn($value) => $value !== '');
$backUrl = 'dean_reports.php' . ($backParams !== [] ? '?' . http_build_query($backParams) : '');
$filterLabel = [
    '' => 'All Records',
    'complaints' => 'Complaints',
    'suggestions' => 'Suggestions',
    'reviewed_suggestions' => 'Reviewed Suggestions',
    'resolved_complaints' => 'Resolved Complaints',
][$cardFilter];

// Preserves every current query param (filters + page) when building filter/pagination links.
$currentQuery = $_GET;
$pageUrl = function (array $overrides) use ($currentQuery): string {
    $merged = array_filter(array_merge($currentQuery, $overrides), static fn($v) => $v !== null && $v !== '');
    return '?' . http_build_query($merged);
};
$clearUrl = '?student_id=' . (int)$studentId;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Student Report Details - VOICE</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
<style>
* { box-sizing: border-box; font-family: 'Poppins', sans-serif; }
body { margin: 0; background: #f8fafc; color: #1f2937; }
.main { margin-left: 260px; margin-top: 61px; padding: 24px; min-height: calc(100vh - 61px); }
.shell { max-width: 1120px; margin: 0 auto; }
.back-link { display: inline-flex; align-items: center; gap: 6px; color: #5b21b6; text-decoration: none; font-size: 13px; font-weight: 600; margin-bottom: 16px; }
.student-header, .record-card, .data-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; box-shadow: 0 3px 12px rgba(15,23,42,.04); }
.student-header { padding: 20px; margin-bottom: 16px; }
.student-header-inner { display: flex; gap: 16px; align-items: flex-start; flex-wrap: wrap; }
.student-avatar { width: 56px; height: 56px; border-radius: 999px; object-fit: cover; border: 1px solid #e5e7eb; flex-shrink: 0; }
.student-header-text { min-width: 0; flex: 1 1 220px; }
.student-header h1 { margin: 0 0 3px; font-size: 19px; }
.student-header p, .filter-note { margin: 2px 0; color: #6b7280; font-size: 12.5px; }
.student-header-stats { display: flex; gap: 8px; flex-wrap: wrap; align-items: flex-start; }
.stat-chip { display: flex; flex-direction: column; gap: 1px; padding: 7px 12px; border-radius: 9px; background: #f5f3ff; min-width: 118px; }
.stat-chip.alt { background: #fef2f2; }
.stat-chip .stat-num { font-size: 17px; font-weight: 700; color: #5b21b6; line-height: 1.1; }
.stat-chip.alt .stat-num { color: #b91c1c; }
.stat-chip .stat-label { font-size: 10.5px; color: #6b7280; font-weight: 600; }
@media (max-width: 480px) { .student-header-inner { flex-direction: column; align-items: flex-start; } }
.badges { display: flex; flex-wrap: wrap; gap: 7px; margin-top: 12px; }
.badge { padding: 5px 9px; border-radius: 999px; background: #f3f4f6; color: #4b5563; font-size: 11px; font-weight: 600; }
.btn-view-against { display: inline-flex; align-items: center; gap: 6px; margin-top: 12px; padding: 8px 14px; border-radius: 8px; background: #6d28d9; color: #fff; text-decoration: none; font-size: 12.5px; font-weight: 600; }
.btn-view-against:hover { background: #5b21b6; }
.section { margin-top: 18px; }
.section h2 { font-size: 17px; margin: 0 0 10px; }

/* Compact complaint summary tiles */
.summary-row { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; margin-bottom: 14px; }
.summary-tile { padding: 12px 14px; }
.summary-tile .summary-num { font-size: 21px; font-weight: 700; color: #1f2937; }
.summary-tile .summary-label { font-size: 11.5px; color: #6b7280; font-weight: 600; margin-top: 2px; }
@media (max-width: 560px) { .summary-row { grid-template-columns: 1fr 1fr; } }

/* Compact filter bar (matches dean_reports.php's filter language) */
.filter-bar { padding: 14px 16px; margin-bottom: 14px; display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap; }
.filter-field { display: flex; flex-direction: column; gap: 4px; flex: 1 1 150px; min-width: 0; }
.filter-field-search { flex: 1 1 220px; }
.filter-field label { font-size: 11px; font-weight: 600; color: #52627a; }
.filter-select { height: 36px; padding: 6px 10px; border: 1px solid #e5e7eb; border-radius: 8px; background: #fff; color: #1f2937; font-size: 12.5px; min-width: 0; width: 100%; }
select.filter-select { appearance: none; -webkit-appearance: none; -moz-appearance: none; background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%236b7280' stroke-width='1.4' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 10px center; padding-right: 26px; }
.filter-actions { display: flex; gap: 8px; flex: 0 0 auto; }
.btn-filter-apply { height: 36px; padding: 0 14px; border: none; border-radius: 8px; background: #6d28d9; color: #fff; font-size: 12.5px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
.btn-filter-apply:hover { background: #5b21b6; }
.btn-filter-clear { height: 36px; padding: 0 14px; border: 1px solid #e5e7eb; border-radius: 8px; background: #fff; color: #52627a; font-size: 12.5px; font-weight: 600; display: inline-flex; align-items: center; text-decoration: none; }
.btn-filter-clear:hover { background: #f8fafc; }

/* Compact complaints table */
.table-wrap { overflow-x: auto; }
.complaints-table { width: 100%; border-collapse: collapse; font-size: 12.5px; min-width: 640px; }
.complaints-table th { text-align: left; padding: 10px 12px; color: #6b7280; font-size: 11px; font-weight: 700; border-bottom: 1px solid #e5e7eb; white-space: nowrap; }
.complaints-table td { padding: 11px 12px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
.complaints-table tbody tr:hover { background: #f8fafc; }
.empty-cell { text-align: center; color: #6b7280; padding: 22px 12px !important; }
.pill { display: inline-block; padding: 4px 9px; border-radius: 999px; font-size: 11px; font-weight: 600; white-space: nowrap; }
.pill-green { background: #dcfce7; color: #166534; }
.pill-amber { background: #fef3c7; color: #92400e; }
.pill-blue { background: #dbeafe; color: #1e40af; }
.pill-gray { background: #f3f4f6; color: #4b5563; }
.row-view { color: #5b21b6; font-size: 12px; font-weight: 600; text-decoration: none; white-space: nowrap; }

/* Pagination */
.pagination-bar { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-top: 12px; }
.pagination-info { margin: 0; font-size: 12px; color: #6b7280; }
.pagination-links { display: flex; gap: 5px; flex-wrap: wrap; }
.page-link { display: inline-flex; align-items: center; justify-content: center; min-width: 30px; height: 30px; padding: 0 8px; border-radius: 7px; border: 1px solid #e5e7eb; color: #4b5563; font-size: 12px; font-weight: 600; text-decoration: none; }
.page-link:hover { background: #f8fafc; }
.page-link.active { background: #6d28d9; border-color: #6d28d9; color: #fff; }
.page-link.disabled { opacity: .4; pointer-events: none; }
.page-ellipsis { padding: 0 4px; color: #9ca3af; font-size: 12px; align-self: center; }

.record-card { padding: 17px; margin-bottom: 12px; }
.record-head { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; margin-bottom: 12px; }
.record-head h3 { margin: 0; font-size: 15px; }
.record-head a { color: #5b21b6; font-size: 12px; font-weight: 600; text-decoration: none; white-space: nowrap; }
.meta { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; margin-bottom: 13px; }
.meta div { background: #f8fafc; border-radius: 8px; padding: 9px; }
.meta strong { display: block; color: #6b7280; font-size: 10px; text-transform: uppercase; }
.meta span { display: block; font-size: 12px; margin-top: 3px; word-break: break-word; }
.detail-label { margin: 11px 0 4px; color: #6b7280; font-size: 11px; font-weight: 700; text-transform: uppercase; }
.detail-text { margin: 0; white-space: pre-wrap; line-height: 1.55; font-size: 13px; }
.empty { padding: 16px; border: 1px dashed #d1d5db; border-radius: 10px; color: #6b7280; font-size: 13px; background: #fff; }
@media (max-width: 900px) { .main { margin-left: 0; padding: 16px; } .meta { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 480px) { .meta { grid-template-columns: 1fr; } .record-head { display: block; } .record-head a { display: inline-block; margin-top: 7px; } .filter-actions { width: 100%; } .btn-filter-apply, .btn-filter-clear { flex: 1 1 auto; justify-content: center; } }
</style>
</head>
<body>
<?php include 'dean_topbar.php'; ?>
<?php include 'dean_sidebar.php'; ?>
<div class="main">
<div class="shell">
    <a class="back-link" href="<?php echo e($backUrl); ?>"><i class="bx bx-arrow-back"></i> Back to Reports Management</a>
    <div class="student-header">
        <div class="student-header-inner">
            <img class="student-avatar" src="<?php echo e($studentPhotoSrc); ?>" alt="">
            <div class="student-header-text">
                <h1><?php echo e(trim($student['first_name'] . ' ' . $student['last_name'])); ?></h1>
                <p>Student ID: <strong><?php echo e($student['student_number']); ?></strong></p>
                <p><?php echo e($student['college_name'] ?? $dean['college_name']); ?></p>
                <p><?php echo e($student['program'] ?? 'Program not assigned'); ?></p>
                <p>Year <?php echo e($student['year_level'] ?? 'N/A'); ?>, Section <?php echo e($student['section'] ?? 'N/A'); ?></p>
            </div>
            <div class="student-header-stats">
                <div class="stat-chip"><span class="stat-num"><?php echo $allTimeFiledByCount; ?></span><span class="stat-label">Complaints Filed By This Student</span></div>
                <div class="stat-chip alt"><span class="stat-num"><?php echo $filedAgainstCount; ?></span><span class="stat-label">Complaints Filed Against This Student</span></div>
            </div>
        </div>
        <div class="badges">
            <span class="badge">Card: <?php echo e($filterLabel); ?></span>
            <span class="badge">SY: <?php echo e($schoolYear !== '' ? $schoolYear : 'All'); ?></span>
            <span class="badge">Semester: <?php echo e($semester === '1' ? '1st Semester' : ($semester === '2' ? '2nd Semester' : 'All')); ?></span>
            <span class="badge">Dates: <?php echo e($dateFrom !== '' ? $dateFrom : 'Beginning'); ?> to <?php echo e($dateTo !== '' ? $dateTo : 'Today'); ?></span>
        </div>
        <a class="btn-view-against" href="dean_reported_complaints.php?student_id=<?php echo (int)$studentId; ?>"><i class="bx bx-shield-quarter"></i> View Complaints Filed Against <?php echo e($student['first_name']); ?></a>
    </div>

    <?php if ($loadComplaints): ?>
    <section class="section">
        <h2>Complaints Filed By This Student (<?php echo $filteredTotal; ?>)</h2>

        <div class="summary-row">
            <div class="data-card summary-tile"><div class="summary-num"><?php echo $summaryTotal; ?></div><div class="summary-label">Total Complaints</div></div>
            <div class="data-card summary-tile"><div class="summary-num"><?php echo $summaryResolved; ?></div><div class="summary-label">Resolved</div></div>
            <div class="data-card summary-tile"><div class="summary-num"><?php echo $summaryUnderReview; ?></div><div class="summary-label">Under Review</div></div>
        </div>

        <form class="filter-bar data-card" method="get">
            <input type="hidden" name="student_id" value="<?php echo (int)$studentId; ?>">
            <?php if ($programId > 0): ?><input type="hidden" name="department" value="<?php echo (int)$programId; ?>"><?php endif; ?>
            <?php if ($dateFrom !== ''): ?><input type="hidden" name="date_from" value="<?php echo e($dateFrom); ?>"><?php endif; ?>
            <?php if ($dateTo !== ''): ?><input type="hidden" name="date_to" value="<?php echo e($dateTo); ?>"><?php endif; ?>
            <?php if ($cardFilter !== ''): ?><input type="hidden" name="card_filter" value="<?php echo e($cardFilter); ?>"><?php endif; ?>
            <?php if (trim((string)($_GET['q'] ?? '')) !== ''): ?><input type="hidden" name="q" value="<?php echo e((string)$_GET['q']); ?>"><?php endif; ?>
            <?php if (trim((string)($_GET['sort'] ?? '')) !== ''): ?><input type="hidden" name="sort" value="<?php echo e((string)$_GET['sort']); ?>"><?php endif; ?>

            <div class="filter-field filter-field-search">
                <label for="cq">Search</label>
                <input type="text" class="filter-select" id="cq" name="cq" placeholder="Search ID, category, description..." value="<?php echo e($searchQ); ?>">
            </div>
            <div class="filter-field">
                <label for="schoolYearFilter">School Year</label>
                <input type="text" class="filter-select" id="schoolYearFilter" name="school_year" placeholder="All School Years" value="<?php echo e($schoolYear); ?>">
            </div>
            <div class="filter-field">
                <label for="semesterFilter">Semester</label>
                <select class="filter-select" id="semesterFilter" name="semester" onchange="this.form.submit()">
                    <option value="">All Semesters</option>
                    <option value="1" <?php echo $semester === '1' ? 'selected' : ''; ?>>1st Semester</option>
                    <option value="2" <?php echo $semester === '2' ? 'selected' : ''; ?>>2nd Semester</option>
                </select>
            </div>
            <div class="filter-field">
                <label for="statusFilter">Status</label>
                <select class="filter-select" id="statusFilter" name="status" onchange="this.form.submit()">
                    <option value="">All Statuses</option>
                    <option value="new" <?php echo $status === 'new' ? 'selected' : ''; ?>>New</option>
                    <option value="under_review" <?php echo $status === 'under_review' ? 'selected' : ''; ?>>Under Review</option>
                    <option value="resolved" <?php echo $status === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                </select>
            </div>
            <div class="filter-field">
                <label for="categoryFilter">Category</label>
                <select class="filter-select" id="categoryFilter" name="category" onchange="this.form.submit()">
                    <option value="">All Categories</option>
                    <?php foreach ($categoryOptions as $cat): ?>
                        <option value="<?php echo (int)$cat['id']; ?>" <?php echo $categoryId === (int)$cat['id'] ? 'selected' : ''; ?>><?php echo e($cat['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-actions">
                <button type="submit" class="btn-filter-apply"><i class="bx bx-search"></i> Apply</button>
                <a class="btn-filter-clear" href="<?php echo e($clearUrl); ?>">Clear Filters</a>
            </div>
        </form>

        <?php if (!$complaints): ?>
            <div class="empty">No complaints match the selected filters.</div>
        <?php else: ?>
            <div class="table-wrap data-card">
                <table class="complaints-table">
                    <thead>
                        <tr><th>Complaint ID</th><th>Category</th><th>Date Submitted</th><th>School Year</th><th>Semester</th><th>Status</th><th></th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($complaints as $record): ?>
                        <tr>
                            <td><?php echo e($record['ticket_no'] !== '' && $record['ticket_no'] !== null ? $record['ticket_no'] : ('#' . (int)$record['id'])); ?></td>
                            <td><?php echo e($record['category_name'] ?? 'Uncategorized'); ?></td>
                            <td><?php echo e($record['created_at']); ?></td>
                            <td><?php echo e($record['school_year'] ?? 'Not set'); ?></td>
                            <td><?php echo e(semester_display_label((string)($record['semester'] ?? ''))); ?></td>
                            <td><span class="pill <?php echo report_detail_status_class((string)$record['status']); ?>"><?php echo e(report_detail_status_label((string)$record['status'])); ?></span></td>
                            <td><a class="row-view" href="dean_ticket_detail.php?id=<?php echo (int)$record['id']; ?>">View Details <i class="bx bx-right-arrow-alt"></i></a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="pagination-bar">
                <p class="pagination-info">Showing <?php echo $rowRangeStart; ?>&ndash;<?php echo $rowRangeEnd; ?> of <?php echo $filteredTotal; ?> complaints</p>
                <?php if ($totalPages > 1): ?>
                <div class="pagination-links">
                    <a class="page-link <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="<?php echo $page > 1 ? e($pageUrl(['page' => $page - 1])) : '#'; ?>">Prev</a>
                    <?php foreach (report_detail_page_numbers($page, $totalPages) as $p): ?>
                        <?php if ($p === null): ?>
                            <span class="page-ellipsis">&hellip;</span>
                        <?php else: ?>
                            <a class="page-link <?php echo $p === $page ? 'active' : ''; ?>" href="<?php echo e($pageUrl(['page' => $p])); ?>"><?php echo $p; ?></a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <a class="page-link <?php echo $page >= $totalPages ? 'disabled' : ''; ?>" href="<?php echo $page < $totalPages ? e($pageUrl(['page' => $page + 1])) : '#'; ?>">Next</a>
                </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($loadSuggestions): ?>
    <section class="section">
        <h2>Suggestions (<?php echo count($suggestions); ?>)</h2>
        <?php if (!$suggestions): ?><div class="empty">No suggestions match the selected report filters.</div><?php endif; ?>
        <?php foreach ($suggestions as $record): ?>
        <article class="record-card">
            <div class="record-head">
                <h3>Suggestion</h3>
                <a href="dean_suggestion_detail.php?id=<?php echo (int)$record['id']; ?>">Open suggestion details <i class="bx bx-right-arrow-alt"></i></a>
            </div>
            <div class="meta">
                <div><strong>Category</strong><span><?php echo e($record['category_name'] ?? 'Uncategorized'); ?></span></div>
                <div><strong>Date submitted</strong><span><?php echo e($record['date_of_suggestion'] ?: $record['created_at']); ?></span></div>
                <div><strong>School Year</strong><span><?php echo e($record['school_year'] ?? 'Not set'); ?></span></div>
                <div><strong>Semester</strong><span><?php echo e(semester_display_label((string)($record['semester'] ?? ''))); ?></span></div>
                <div><strong>Status</strong><span><?php echo e(ucwords(str_replace('_', ' ', (string)$record['status']))); ?></span></div>
                <div><strong>Review status</strong><span><?php echo e(ucwords(str_replace('_', ' ', (string)$record['status']))); ?></span></div>
                <div><strong>Forwarded</strong><span><?php echo !empty($record['is_forwarded']) ? 'Yes' : 'No'; ?></span></div>
                <div><strong>Review remarks</strong><span><?php echo e($record['dean_remark'] ? 'Available' : 'None'); ?></span></div>
            </div>
            <div class="detail-label">Subject</div>
            <p class="detail-text"><?php echo e($record['subject']); ?></p>
            <div class="detail-label">Suggestion description</div>
            <p class="detail-text"><?php echo e($record['description']); ?></p>
            <?php if (!empty($record['expected_outcome'])): ?><div class="detail-label">Expected outcome</div><p class="detail-text"><?php echo e($record['expected_outcome']); ?></p><?php endif; ?>
            <?php if (!empty($record['dean_remark'])): ?><div class="detail-label">Review remarks</div><p class="detail-text"><?php echo e($record['dean_remark']); ?></p><?php endif; ?>
        </article>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>
</div>
</div>
</body>
</html>
