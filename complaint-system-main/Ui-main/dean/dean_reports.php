<?php
session_start();
require_once '../db_connection.php';

// Ensure dean access
function require_dean_session($pdo) {
    if (!isset($_SESSION['user_id'])) {
        header('Location: /complaint-system-main/dean/login.php');
        exit;
    }

    if (($_SESSION['role'] ?? '') === 'dean') {
        return true;
    }

    $stmt = $pdo->prepare('SELECT role FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && $user['role'] === 'dean') {
        $_SESSION['role'] = 'dean';
        return true;
    }

    header('Location: /complaint-system-main/dean/login.php');
    exit;
}

require_dean_session($pdo);
require_once __DIR__ . '/../school_year_helpers.php';

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

// Get dean's college. This is the ONLY source of truth for which college's
// data this page ever queries — every query below is scoped to it, so a
// dean can never see another college's reports regardless of what filter
// values are passed in the URL.
$deanProfileStmt = $pdo->prepare('SELECT college_id, first_name, last_name FROM dean_profiles WHERE user_id = ?');
$deanProfileStmt->execute([$_SESSION['user_id']]);
$deanProfile = $deanProfileStmt->fetch(PDO::FETCH_ASSOC);
$collegeId = $deanProfile['college_id'] ?? null;
$deanDisplayName = trim((string)(($deanProfile['first_name'] ?? '') . ' ' . ($deanProfile['last_name'] ?? '')));
if ($deanDisplayName === '') {
    $deanDisplayName = 'Dean';
}

if (!$collegeId) {
    $collegeStmt = $pdo->prepare('SELECT id FROM colleges LIMIT 1');
    $collegeStmt->execute();
    $college = $collegeStmt->fetch(PDO::FETCH_ASSOC);
    $collegeId = $college['id'] ?? 1;
}
$collegeId = (int)$collegeId;

// Get college information
$collegeStmt = $pdo->prepare('SELECT code, name FROM colleges WHERE id = ?');
$collegeStmt->execute([$collegeId]);
$collegeInfo = $collegeStmt->fetch(PDO::FETCH_ASSOC);
$collegeName = $collegeInfo['name'] ?? 'Unknown College';
$collegeCode = $collegeInfo['code'] ?? 'N/A';

// ---------------------------------------------------------------------
// Filters: School Year, Semester, and Department (program). All three
// are validated server-side against this dean's own college before use, so
// a tampered request param can never pull in another college's data.
// ---------------------------------------------------------------------

// Departments/programs within THIS college only.
$departmentsStmt = $pdo->prepare('SELECT id, name FROM programs WHERE college_id = ? ORDER BY name ASC');
$departmentsStmt->execute([$collegeId]);
$departments = $departmentsStmt->fetchAll(PDO::FETCH_ASSOC);
$departmentIds = array_map(static fn($d) => (int)$d['id'], $departments);

$programId = (int)($_GET['department'] ?? 0);
if ($programId <= 0 || !in_array($programId, $departmentIds, true)) {
    $programId = 0; // 0 = All Departments
}
$selectedDepartmentName = '';
foreach ($departments as $d) {
    if ((int)$d['id'] === $programId) {
        $selectedDepartmentName = (string)$d['name'];
        break;
    }
}

// School years with data in this college, plus the current one.
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

function dean_reports_valid_date(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

if ($dateFrom !== '' && !dean_reports_valid_date($dateFrom)) {
    $dateFrom = '';
}
if ($dateTo !== '' && !dean_reports_valid_date($dateTo)) {
    $dateTo = '';
}
if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

$reportRangeStart = $dateFrom !== '' ? $dateFrom . ' 00:00:00' : '';
$reportRangeEnd = $dateTo !== ''
    ? (new DateTimeImmutable($dateTo))->modify('+1 day')->format('Y-m-d 00:00:00')
    : '';

// Student search and counts (Enrollees Directory)
$q = trim((string)($_GET['q'] ?? ''));
$sort = trim((string)($_GET['sort'] ?? ''));

$filterPreserve = array_filter([
    'q' => $q,
    'sort' => $sort,
    'school_year' => $schoolYear,
    'semester' => $semester,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'department' => $programId > 0 ? (string)$programId : '',
], static fn($v) => $v !== '');

function dean_reports_semester_clause(string $alias, string $semester, array &$params, string $prefix): string
{
    if ($semester === '1') {
        $params[":" . $prefix . "_start"] = '08-01';
        $params[":" . $prefix . "_end"] = '01-01';
        return " AND (DATE_FORMAT({$alias}.created_at, '%m-%d') >= :{$prefix}_start OR DATE_FORMAT({$alias}.created_at, '%m-%d') < :{$prefix}_end)";
    }

    if ($semester === '2') {
        $params[":" . $prefix . "_start"] = '01-01';
        $params[":" . $prefix . "_end"] = '08-01';
        return " AND DATE_FORMAT({$alias}.created_at, '%m-%d') >= :{$prefix}_start AND DATE_FORMAT({$alias}.created_at, '%m-%d') < :{$prefix}_end";
    }

    return '';
}

function dean_reports_range_clause(string $alias, string $rangeStart, string $rangeEnd, array &$params, string $prefix): string
{
    $clause = '';
    if ($rangeStart !== '') {
        $params[":" . $prefix . "_start"] = $rangeStart;
        $clause .= " AND {$alias}.created_at >= :{$prefix}_start";
    }
    if ($rangeEnd !== '') {
        $params[":" . $prefix . "_end"] = $rangeEnd;
        $clause .= " AND {$alias}.created_at < :{$prefix}_end";
    }
    return $clause;
}

// ---------------------------------------------------------------------
// KPI counts — scoped by college (always), department and school year
// (when selected). These give an at-a-glance snapshot.
// ---------------------------------------------------------------------
function dean_reports_scoped_count(PDO $pdo, string $table, int $collegeId, int $programId, string $schoolYear, string $semester, string $rangeStart, string $rangeEnd, string $statusClause = ''): int
{
    $alias = $table === 'complaints' ? 'c' : 's';
    $sql = "SELECT COUNT(*) FROM {$table} {$alias}";
    $params = [':college_id' => $collegeId];

    if ($programId > 0) {
        $sql .= " INNER JOIN student_profiles sp ON sp.id = {$alias}.student_id";
    }

    $sql .= " WHERE {$alias}.college_id = :college_id";

    if ($programId > 0) {
        $sql .= " AND sp.program_id = :program_id";
        $params[':program_id'] = $programId;
    }

    if ($schoolYear !== '') {
        $sql .= " AND {$alias}.school_year = :school_year";
        $params[':school_year'] = $schoolYear;
    }

    $sql .= dean_reports_semester_clause($alias, $semester, $params, $alias . '_semester');

    $sql .= dean_reports_range_clause($alias, $rangeStart, $rangeEnd, $params, $alias . '_range');

    if ($statusClause !== '') {
        $sql .= " AND {$statusClause}";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int)$stmt->fetchColumn();
}

$totalComplaints = dean_reports_scoped_count($pdo, 'complaints', $collegeId, $programId, $schoolYear, $semester, $reportRangeStart, $reportRangeEnd);
$totalSuggestions = dean_reports_scoped_count($pdo, 'suggestions', $collegeId, $programId, $schoolYear, $semester, $reportRangeStart, $reportRangeEnd);
$reviewedSuggestions = dean_reports_scoped_count($pdo, 'suggestions', $collegeId, $programId, $schoolYear, $semester, $reportRangeStart, $reportRangeEnd, "s.status = 'reviewed'");
$resolvedComplaints = dean_reports_scoped_count($pdo, 'complaints', $collegeId, $programId, $schoolYear, $semester, $reportRangeStart, $reportRangeEnd, "c.status = 'resolved'");
$resolvedRate = $totalComplaints > 0 ? (int)round(($resolvedComplaints / $totalComplaints) * 100) : 0;

// ---------------------------------------------------------------------
// Enrollees Directory — same college/department/school-year scope as the
// KPI cards above, plus free-text search and sorting.
// ---------------------------------------------------------------------
$directoryWhere = 'sp.college_id = :college_id';
$directoryParams = [':college_id' => $collegeId];

if ($programId > 0) {
    $directoryWhere .= ' AND sp.program_id = :program_id';
    $directoryParams[':program_id'] = $programId;
}

if ($q !== '') {
    $like = '%' . $q . '%';
    $directoryWhere .= ' AND (sp.student_number LIKE :q1 OR sp.first_name LIKE :q2 OR sp.last_name LIKE :q3 OR u.username LIKE :q4 OR prog.name LIKE :q5)';
    $directoryParams[':q1'] = $like;
    $directoryParams[':q2'] = $like;
    $directoryParams[':q3'] = $like;
    $directoryParams[':q4'] = $like;
    $directoryParams[':q5'] = $like;
}

$reportedSubquery = '(SELECT COUNT(*) FROM complaint_student_links csl INNER JOIN complaints rc ON rc.id = csl.complaint_id WHERE csl.student_id = sp.id';
$complaintsSubquery = '(SELECT COUNT(*) FROM complaints fc WHERE fc.student_id = sp.id';
if ($schoolYear !== '') {
    $reportedSubquery .= ' AND rc.school_year = :sy_reported';
    $complaintsSubquery .= ' AND fc.school_year = :sy_filed';
    $directoryParams[':sy_reported'] = $schoolYear;
    $directoryParams[':sy_filed'] = $schoolYear;
}
$reportedSubquery .= dean_reports_semester_clause('rc', $semester, $directoryParams, 'semester_reported');
$complaintsSubquery .= dean_reports_semester_clause('fc', $semester, $directoryParams, 'semester_filed');
$reportedSubquery .= dean_reports_range_clause('rc', $reportRangeStart, $reportRangeEnd, $directoryParams, 'range_reported');
$complaintsSubquery .= dean_reports_range_clause('fc', $reportRangeStart, $reportRangeEnd, $directoryParams, 'range_filed');
$reportedSubquery .= ') AS times_reported';
$complaintsSubquery .= ') AS complaints_filed';

// Sorting
$sortSql = ' ORDER BY sp.first_name, sp.last_name';
if ($sort === 'complaints_desc') {
    $sortSql = ' ORDER BY complaints_filed DESC, sp.first_name, sp.last_name';
} elseif ($sort === 'reports_desc') {
    $sortSql = ' ORDER BY times_reported DESC, sp.first_name, sp.last_name';
} elseif ($sort === 'name_desc') {
    $sortSql = ' ORDER BY sp.last_name DESC, sp.first_name DESC';
}

$baseSql = "SELECT
        sp.id as sp_id,
        sp.student_number,
        sp.first_name,
        sp.last_name,
        u.username as username,
        prog.name as program,
        sp.year_level,
        sp.status,
        {$reportedSubquery},
        {$complaintsSubquery}
    FROM student_profiles sp
    LEFT JOIN users u ON sp.user_id = u.id
    LEFT JOIN programs prog ON sp.program_id = prog.id
    WHERE {$directoryWhere}";

$sql = $baseSql . $sortSql . ' LIMIT 50';
$studentStmt = $pdo->prepare($sql);
$studentStmt->execute($directoryParams);
$students = $studentStmt->fetchAll(PDO::FETCH_ASSOC);
$students = array_values(array_filter($students, static function (array $student): bool {
    return (int)($student['times_reported'] ?? 0) > 0 || (int)($student['complaints_filed'] ?? 0) > 0;
}));

// If AJAX request, return only table rows HTML for live search
if ((string)($_GET['ajax'] ?? '') === '1') {
    if (empty($students)) {
        echo '<tr><td colspan="7" style="text-align:center;color:#aaa">No data found for selected filters</td></tr>';
        exit;
    }
    foreach ($students as $student) {
        $sid = htmlspecialchars($student['student_number'] ?? $student['username'] ?? 'N/A');
        $name = htmlspecialchars(trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? '')));
        $program = htmlspecialchars($student['program'] ?? 'N/A');
        $year = htmlspecialchars($student['year_level'] ?? 'N/A');
        $reported = (int)($student['times_reported'] ?? 0);
        $complaints = (int)($student['complaints_filed'] ?? 0);
        $status = htmlspecialchars(ucfirst($student['status'] ?? 'active'));
        $statusClass = 'status-' . strtolower($student['status'] ?? 'active');
        echo "<tr>";
        echo "<td><strong>{$sid}</strong></td>";
        echo "<td>{$name}</td>";
        echo "<td>{$program}</td>";
        echo "<td>{$year}</td>";
        echo '<td><a class="complaint-count-badge" href="../reported_complaints.php?student_id=' . (int)$student['sp_id'] . '" title="View reported complaints">' . $reported . '</a></td>';
        echo '<td><a class="complaint-count-badge" href="../reported_complaints.php?student_id=' . (int)$student['sp_id'] . '&view=complaints" title="View student complaints">' . $complaints . '</a></td>';
        echo "<td><span class=\"{$statusClass}\">{$status}</span></td>";
        echo "</tr>";
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>VOICE - Reports Management</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>

<style>
* { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
body { background: #f9fafb; }
.main { margin-left: 260px; margin-top: 61px; padding: 25px; min-height: calc(100vh - 61px); overflow-y: auto; }

/* ===== PAGE HEADER ===== */
.page-header { margin-bottom: 4px; }
.page-header h2 { font-size: 24px; font-weight: 700; color: #1f2937; }
.page-subtitle { color: #6b7280; font-size: 14px; margin-bottom: 6px; }
.filter-summary { color: #6b7280; font-size: 13px; margin-bottom: 20px; }
.filter-summary strong { color: #374151; font-weight: 600; }
.print-only { display: none; }

/* ===== FILTER BAR ===== */
.filter-bar {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.02);
    padding: 18px 20px;
    margin-bottom: 24px;
    display: flex;
    flex-wrap: nowrap;
    align-items: flex-end;
    gap: 16px;
}
.filter-form { display: flex; flex-wrap: nowrap; gap: 12px; align-items: flex-end; flex: 1 1 auto; min-width: 0; }
.filter-field { display: flex; flex-direction: column; gap: 6px; min-width: 0; flex: 0 1 160px; }
.filter-field label { font-size: 11px; font-weight: 600; color: #52627a; text-transform: uppercase; letter-spacing: .03em; }
.filter-select {
    height: 43px;
    padding: 10px 14px;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    background: #fff;
    font-size: 13.5px;
    color: #1f2937;
    font-family: 'Poppins', sans-serif;
    min-width: 0;
    width: 100%;
    cursor: pointer;
}
.filter-select:focus { outline: none; border-color: #6d28d9; box-shadow: 0 0 0 3px rgba(109,40,217,0.1); }

.filter-actions { display: flex; gap: 10px; align-items: flex-end; flex-shrink: 0; }
.btn-print, .btn-ghost {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    height: 40px;
    padding: 10px 18px;
    border-radius: 8px;
    font-size: 13.5px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    border: none;
    font-family: 'Poppins', sans-serif;
    white-space: nowrap;
}
.btn-print { background: #6d28d9; color: #fff; min-width: 137px; justify-content: center; }
.btn-print:hover { background: #5d1fa0; }
.btn-ghost { background: #fff; color: #6b7280; border: 1px solid #e5e7eb; }
.btn-ghost:hover { background: #f3f4f6; color: #374151; }

/* ===== KPI METRICS ===== */
.metrics-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 24px; }
@media (max-width: 1200px) { .metrics-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 640px) { .metrics-grid { grid-template-columns: 1fr; } }

.metric-card {
    background: #fff;
    padding: 20px;
    border-radius: 12px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 2px 10px rgba(0,0,0,0.02);
    display: flex;
    align-items: center;
    gap: 16px;
    transition: transform 0.2s ease;
}
.metric-card:hover { transform: translateY(-3px); box-shadow: 0 5px 15px rgba(0,0,0,0.05); }
.metric-icon { width: 52px; height: 52px; border-radius: 12px; display: flex; justify-content: center; align-items: center; font-size: 24px; flex-shrink: 0; }
.icon-blue { background: #eff6ff; color: #3b82f6; }
.icon-purple { background: #f3e8ff; color: #7c3aed; }
.icon-green { background: #dcfce7; color: #16a34a; }
.icon-amber { background: #fef3c7; color: #d97706; }
.metric-info h4 { color: #6b7280; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .03em; margin-bottom: 6px; }
.metric-info .value { font-size: 24px; font-weight: 700; color: #1f2937; line-height: 1; }
.metric-info .sub { font-size: 12px; color: #9ca3af; margin-top: 4px; }

/* ===== SHARED CARD ===== */
.data-card { background: #fff; padding: 22px; border-radius: 12px; border: 1px solid #e5e7eb; box-shadow: 0 2px 10px rgba(0,0,0,0.02); margin-bottom: 24px; }
.card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; flex-wrap: wrap; gap: 12px; }
.card-header h3 { font-size: 16px; font-weight: 600; color: #1f2937; }
.card-header p { font-size: 12.5px; color: #9ca3af; margin-top: 2px; }

/* ===== DIRECTORY TOOLBAR ===== */
.directory-toolbar { display: flex; gap: 12px; align-items: center; margin-bottom: 18px; flex-wrap: wrap; }
.search-box { display: flex; align-items: center; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 9px 12px; flex: 1; min-width: 220px; }
.search-box i { color: #9ca3af; margin-right: 8px; }
.search-box input { border: none; background: transparent; outline: none; width: 100%; font-size: 13.5px; font-family: 'Poppins', sans-serif; }

/* ===== TABLE ===== */
table { width: 100%; border-collapse: collapse; min-width: 760px; }
.data-card > div[style*="overflow"] { overflow-x: auto; }
th { text-align: left; font-size: 11.5px; color: #9ca3af; padding: 12px 12px; border-bottom: 2px solid #f0f1f3; font-weight: 600; text-transform: uppercase; letter-spacing: .03em; }
td { padding: 14px 12px; font-size: 13.5px; color: #374151; border-bottom: 1px solid #f9f9f9; vertical-align: middle; }
tbody tr:hover td { background: #fafafa; }
.complaint-count-badge { display: inline-flex; align-items: center; justify-content: center; min-width: 34px; padding: 5px 10px; border-radius: 999px; background: #eef2ff; color: #4338ca; font-weight: 600; text-decoration: none; font-size: 13px; }
.complaint-count-badge:hover { background: #e0e7ff; }
.status-active { background: #dcfce7; color: #16a34a; padding: 5px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; }
.status-inactive { background: #fee2e2; color: #dc2626; padding: 5px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; }

@media (max-width: 900px) {
    .main { margin-left: 0 !important; padding: 16px !important; }
    .filter-bar { flex-direction: column; align-items: stretch; }
    .filter-form { flex-wrap: wrap; }
    .filter-field { flex: 1 1 180px; }
    .filter-actions { justify-content: flex-end; }
}

/* ===== PRINT ===== */
@media print {
    @page { size: landscape; margin: 12mm; }
    body { background: #fff; }
    .topbar, .sidebar, .mobile-sidebar-overlay, .no-print { display: none !important; }
    .main { margin: 0 !important; padding: 0 !important; }
    .main > * { display: none !important; }
    .main > .directory-card { display: block !important; }
    .directory-card { padding: 0 !important; margin: 0 !important; width: 100% !important; }
    .directory-card > div[style*="overflow"] { overflow: visible !important; }
    .directory-card table { width: 100% !important; min-width: 0 !important; table-layout: fixed; font-size: 10px; }
    .directory-card th, .directory-card td { padding: 7px 6px; word-wrap: break-word; }
    .print-only { display: block !important; }
    .data-card, .metric-card { box-shadow: none !important; border: 1px solid #ddd !important; break-inside: avoid; }
    .metrics-grid { gap: 12px; }
    .rpt-table-wrap { max-height: none !important; overflow: visible !important; }
    .complaint-count-badge { background: none !important; color: #111 !important; text-decoration: none !important; padding: 0 !important; }
    a { color: inherit !important; }
}
</style>
</head>

<body>

<!-- TOPBAR (fixed, full width) -->
<?php include 'dean_topbar.php'; ?>

<!-- SIDEBAR (fixed, below topbar) -->
<?php include 'dean_sidebar.php'; ?>

<!-- MAIN CONTENT -->
<div class="main">

    <div class="page-header">
        <h2>Reports Management</h2>
    </div>
    <p class="page-subtitle"><?php echo e($collegeName); ?> (<?php echo e($collegeCode); ?>) Performance Overview</p>
    <p class="filter-summary">
        Showing reports<?php if ($dateFrom !== '' || $dateTo !== ''): ?> from <strong><?php echo e($dateFrom !== '' ? $dateFrom : 'Beginning'); ?></strong> to <strong><?php echo e($dateTo !== '' ? $dateTo : 'Today'); ?></strong><?php endif; ?> for
        <strong><?php echo $semester !== '' ? 'Semester ' . e($semester) : 'All Semesters'; ?></strong> &middot;
        <strong><?php echo $programId > 0 ? e($selectedDepartmentName) : 'All Departments'; ?></strong> &middot;
        School Year <strong><?php echo $schoolYear !== '' ? e($schoolYear) : 'All School Years'; ?></strong>
    </p>
    <p class="print-only" style="font-size:12px;color:#6b7280;margin-bottom:16px;">
        Generated <?php echo e(date('F j, Y g:i A')); ?> by <?php echo e($deanDisplayName); ?>, Dean of <?php echo e($collegeName); ?>
    </p>

    <!-- FILTER BAR -->
    <div class="filter-bar no-print">
        <form method="GET" class="filter-form" id="reportFilterForm">
            <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?php echo e($q); ?>"><?php endif; ?>
            <?php if ($sort !== ''): ?><input type="hidden" name="sort" value="<?php echo e($sort); ?>"><?php endif; ?>
            <div class="filter-field">
                <label for="schoolYearFilter">School Year</label>
                <select class="filter-select" id="schoolYearFilter" name="school_year" onchange="this.form.submit()">
                    <option value="">All School Years</option>
                    <?php foreach ($availableSchoolYears as $sy): ?>
                        <option value="<?php echo e($sy); ?>" <?php echo $sy === $schoolYear ? 'selected' : ''; ?>>
                            SY <?php echo e($sy); ?><?php echo $sy === sy_current() ? ' (Current)' : ''; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
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
                <label for="departmentFilter">Department</label>
                <select class="filter-select" id="departmentFilter" name="department" onchange="this.form.submit()">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?php echo (int)$d['id']; ?>" <?php echo $programId === (int)$d['id'] ? 'selected' : ''; ?>>
                            <?php echo e($d['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-field">
                <label for="dateFromFilter">From Date</label>
                <input class="filter-select" type="date" id="dateFromFilter" name="date_from" value="<?php echo e($dateFrom); ?>" onchange="this.form.submit()">
            </div>

            <div class="filter-field">
                <label for="dateToFilter">To Date</label>
                <input class="filter-select" type="date" id="dateToFilter" name="date_to" value="<?php echo e($dateTo); ?>" onchange="this.form.submit()">
            </div>
        </form>

        <div class="filter-actions">
            <?php if ($schoolYear !== '' || $semester !== '' || $programId > 0 || $dateFrom !== '' || $dateTo !== ''): ?>
                <a class="btn-ghost" href="dean_reports.php">
                    <i class='bx bx-x'></i> Clear Filters
                </a>
            <?php endif; ?>
            <button type="button" class="btn-print" onclick="window.print()">
                <i class='bx bx-printer'></i> Print Report
            </button>
        </div>
    </div>

    <!-- KPI METRICS -->
    <div class="metrics-grid">
        <div class="metric-card">
            <div class="metric-icon icon-amber"><i class='bx bx-message-square-detail'></i></div>
            <div class="metric-info">
                <h4>Complaints</h4>
                <div class="value"><?php echo number_format($totalComplaints); ?></div>
            </div>
        </div>
        <div class="metric-card">
            <div class="metric-icon icon-purple"><i class='bx bx-bulb'></i></div>
            <div class="metric-info">
                <h4>Suggestions</h4>
                <div class="value"><?php echo number_format($totalSuggestions); ?></div>
            </div>
        </div>
        <div class="metric-card">
            <div class="metric-icon icon-blue"><i class='bx bx-check-circle'></i></div>
            <div class="metric-info">
                <h4>Reviewed Suggestions</h4>
                <div class="value"><?php echo number_format($reviewedSuggestions); ?></div>
            </div>
        </div>
        <div class="metric-card">
            <div class="metric-icon icon-green"><i class='bx bx-check-circle'></i></div>
            <div class="metric-info">
                <h4>Resolved Complaints</h4>
                <div class="value"><?php echo number_format($resolvedComplaints); ?></div>
                <div class="sub"><?php echo $totalComplaints > 0 ? $resolvedRate . '% resolution rate' : 'No complaints yet'; ?></div>
            </div>
        </div>
    </div>

    <!-- ENROLLEES DIRECTORY -->
    <div class="data-card directory-card">
        <div class="card-header">
            <div>
                <h3><?php echo e($collegeName); ?> Enrollees Directory</h3>
                <p>Filters: School Year: <?php echo $schoolYear !== '' ? e($schoolYear) : 'All'; ?> &middot;
                    Semester: <?php echo $semester !== '' ? e($semester === '1' ? '1st Semester' : '2nd Semester') : 'All'; ?> &middot;
                    Department: <?php echo $programId > 0 ? e($selectedDepartmentName) : 'All'; ?> &middot;
                    Dates: <?php echo e($dateFrom !== '' ? $dateFrom : 'Beginning'); ?> to <?php echo e($dateTo !== '' ? $dateTo : 'Today'); ?></p>
            </div>
        </div>

        <form method="GET" class="directory-toolbar no-print">
            <?php if ($schoolYear !== ''): ?><input type="hidden" name="school_year" value="<?php echo e($schoolYear); ?>"><?php endif; ?>
            <?php if ($semester !== ''): ?><input type="hidden" name="semester" value="<?php echo e($semester); ?>"><?php endif; ?>
            <?php if ($programId > 0): ?><input type="hidden" name="department" value="<?php echo (int)$programId; ?>"><?php endif; ?>
            <?php if ($dateFrom !== ''): ?><input type="hidden" name="date_from" value="<?php echo e($dateFrom); ?>"><?php endif; ?>
            <?php if ($dateTo !== ''): ?><input type="hidden" name="date_to" value="<?php echo e($dateTo); ?>"><?php endif; ?>
            <div class="search-box">
                <i class='bx bx-search'></i>
                <input type="text" name="q" placeholder="Search by ID, Name or Program..." value="<?php echo e($q); ?>">
            </div>
            <div>
                <select name="sort" class="filter-select" style="min-width:160px;">
                    <option value="complaints_desc" <?php echo $sort === 'complaints_desc' ? 'selected' : ''; ?>>Most Complaints</option>
                    <option value="reports_desc" <?php echo $sort === 'reports_desc' ? 'selected' : ''; ?>>Most Reported</option>
                </select>
            </div>
        </form>

        <div style="overflow-x:auto;">
            <table>
                <thead>
                    <tr>
                        <th>Student ID</th>
                        <th>Full Name</th>
                        <th>Program</th>
                        <th>Year Level</th>
                        <th>Reported</th>
                        <th>Complaints</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($students)): ?>
                    <tr>
                        <td colspan="7" style="text-align:center;color:#aaa">No data found for selected filters</td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($students as $student): ?>
                        <tr>
                            <td><strong><?php echo e($student['student_number'] ?? $student['username'] ?? 'N/A'); ?></strong></td>
                            <td><?php echo e(trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? ''))); ?></td>
                            <td><?php echo e($student['program'] ?? 'N/A'); ?></td>
                            <td><?php echo e($student['year_level'] ?? 'N/A'); ?></td>
                            <td><a class="complaint-count-badge" href="../reported_complaints.php?student_id=<?php echo (int)$student['sp_id']; ?>" title="View reported complaints"><?php echo (int)($student['times_reported'] ?? 0); ?></a></td>
                            <td><a class="complaint-count-badge" href="../reported_complaints.php?student_id=<?php echo (int)$student['sp_id']; ?>&view=complaints" title="View student complaints"><?php echo (int)($student['complaints_filed'] ?? 0); ?></a></td>
                            <td><span class="status-<?php echo strtolower($student['status'] ?? 'active'); ?>"><?php echo ucfirst($student['status'] ?? 'active'); ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

</body>
</html>

<script>
document.addEventListener('DOMContentLoaded', function(){
    const qInput = document.querySelector('input[name="q"]');
    const sortSelect = document.querySelector('.directory-toolbar select[name="sort"]');
    const tbody = document.querySelector('table tbody');
    const schoolYear = <?php echo json_encode($schoolYear); ?>;
    const semester = <?php echo json_encode($semester); ?>;
    const department = <?php echo json_encode($programId > 0 ? (string)$programId : ''); ?>;
    const dateFrom = <?php echo json_encode($dateFrom); ?>;
    const dateTo = <?php echo json_encode($dateTo); ?>;
    let timer = null;

    function doSearch() {
        const params = new URLSearchParams();
        if (qInput && qInput.value.trim() !== '') params.set('q', qInput.value.trim());
        if (sortSelect && sortSelect.value) params.set('sort', sortSelect.value);
        if (schoolYear) params.set('school_year', schoolYear);
        if (semester) params.set('semester', semester);
        if (department) params.set('department', department);
        if (dateFrom) params.set('date_from', dateFrom);
        if (dateTo) params.set('date_to', dateTo);
        params.set('ajax', '1');
        fetch(window.location.pathname + '?' + params.toString(), {headers: {'X-Requested-With': 'XMLHttpRequest'}})
            .then(r => r.text())
            .then(html => {
                if (tbody) tbody.innerHTML = html;
            }).catch(err => console.error('Search error', err));
    }

    if (qInput) {
        qInput.addEventListener('input', function(){
            clearTimeout(timer);
            timer = setTimeout(doSearch, 300);
        });
    }
    if (sortSelect) {
        sortSelect.addEventListener('change', function(){ doSearch(); });
    }
});
</script>
