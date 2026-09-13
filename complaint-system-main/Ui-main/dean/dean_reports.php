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
require_once __DIR__ . '/../complaint_groq_helpers.php';

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function dean_report_date_valid(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

function dean_report_status_label(string $status): string
{
    return ucwords(str_replace('_', ' ', $status !== '' ? $status : 'new'));
}

function dean_report_semester_label(?string $semester): string
{
    return $semester === '1' ? '1st Semester' : ($semester === '2' ? '2nd Semester' : 'N/A');
}

function dean_report_groq_filters(PDO $pdo, string $query, string $type, array $departments, array $schoolYears, array $offices): array
{
    $config = function_exists('groq_load_config') ? groq_load_config() : null;
    if ($config === null || trim($query) === '') {
        return [];
    }

    $options = [
        'departments' => array_map(static fn(array $row): array => ['id' => (int)$row['id'], 'name' => (string)$row['name']], $departments),
        'school_years' => array_values(array_map('strval', $schoolYears)),
        'offices' => array_values(array_map('strval', $offices)),
        'statuses' => $type === 'complaints'
            ? ['new', 'pending', 'under_review', 'resolved', 'closed']
            : ['new', 'under_review', 'approved', 'implemented', 'reviewed', 'declined', 'resolved', 'rejected', 'accepted', 'not_feasible', 'needs_info', 'planned', 'in_progress'],
    ];

    $prompt = 'Interpret this dean report request into filters for ' . $type . ". Use only IDs and values from the supplied options. Return JSON only with keys department_id, school_year, semester, office, status, date_from, date_to, keyword. Use 0 or empty strings when unknown. semester must be 1 or 2. Dates must be YYYY-MM-DD. Do not invent options or records.\nOptions:\n" . json_encode($options) . "\nRequest:\n" . $query;
    $payload = ['model' => $config['model'], 'messages' => [['role' => 'user', 'content' => $prompt]], 'response_format' => ['type' => 'json_object'], 'temperature' => 0.1];
    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $config['api_key']], CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $config['timeout_seconds'], CURLOPT_CONNECTTIMEOUT => min(5, $config['timeout_seconds'])]);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        return [];
    }

    $decoded = json_decode((string)$response, true);
    $content = $decoded['choices'][0]['message']['content'] ?? '';
    $filters = is_string($content) ? json_decode($content, true) : null;
    return is_array($filters) ? $filters : [];
}

function dean_report_groq_summary(string $type, int $total, array $counts, string $topLabel, string $secondLabel): string
{
    $config = function_exists('groq_load_config') ? groq_load_config() : null;
    if ($config === null || $total === 0) {
        return '';
    }

    $prompt = 'Write one concise factual dean report summary for ' . $type . '. Use only these verified database facts: total=' . $total . ', status_counts=' . json_encode($counts) . ', top=' . $topLabel . ', second=' . $secondLabel . '. Do not add any number or fact not supplied. Return plain text only.';
    $payload = ['model' => $config['model'], 'messages' => [['role' => 'user', 'content' => $prompt]], 'temperature' => 0.1, 'max_tokens' => 120];
    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $config['api_key']], CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $config['timeout_seconds'], CURLOPT_CONNECTTIMEOUT => min(5, $config['timeout_seconds'])]);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        return '';
    }

    $decoded = json_decode((string)$response, true);
    return trim((string)($decoded['choices'][0]['message']['content'] ?? ''));
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

$cardFilter = trim((string)($_GET['card_filter'] ?? ''));
if (!in_array($cardFilter, ['complaints', 'suggestions', 'reviewed_suggestions', 'resolved_complaints'], true)) {
    $cardFilter = '';
}
$cardFilterLabels = [
    '' => 'All Records',
    'complaints' => 'Complaints',
    'suggestions' => 'Suggestions',
    'reviewed_suggestions' => 'Reviewed Suggestions',
    'resolved_complaints' => 'Resolved Complaints',
];

$reportRangeStart = $dateFrom !== '' ? $dateFrom . ' 00:00:00' : '';
$reportRangeEnd = $dateTo !== ''
    ? (new DateTimeImmutable($dateTo))->modify('+1 day')->format('Y-m-d 00:00:00')
    : '';

// Smart dean-side reporting: same as admin patterns, but locked to the dean's college.
$q = trim((string)($_GET['q'] ?? ''));
$sort = trim((string)($_GET['sort'] ?? ''));
$activeTab = ($_GET['tab'] ?? 'complaints') === 'suggestions' ? 'suggestions' : 'complaints';
$status = trim((string)($_GET['status'] ?? ''));
$requestedRange = isset($_GET['range']) ? (string)$_GET['range'] : 'month';
$range = in_array($requestedRange, ['week', 'month', 'year'], true) ? $requestedRange : 'month';

$departments = $pdo->prepare('SELECT id, name FROM programs WHERE college_id = :college_id ORDER BY name');
$departments->execute([':college_id' => $collegeId]);
$departmentsData = $departments->fetchAll(PDO::FETCH_ASSOC);
$departmentIds = array_map(static fn(array $row): int => (int)$row['id'], $departmentsData);

$departmentId = max(0, (int)($_GET['department'] ?? 0));
if ($departmentId > 0 && !in_array($departmentId, $departmentIds, true)) {
    $departmentId = 0;
}

$schoolYears = $pdo->query('SELECT DISTINCT school_year FROM (SELECT school_year FROM complaints WHERE college_id = ' . (int)$collegeId . ' AND school_year IS NOT NULL AND school_year <> "" UNION SELECT school_year FROM suggestions WHERE college_id = ' . (int)$collegeId . ' AND school_year IS NOT NULL AND school_year <> "") AS years ORDER BY school_year DESC')->fetchAll(PDO::FETCH_COLUMN);
$currentSchoolYear = sy_current($pdo);
if ($currentSchoolYear !== '' && !in_array($currentSchoolYear, $schoolYears, true)) {
    array_unshift($schoolYears, $currentSchoolYear);
}
if ($schoolYear !== '' && !in_array($schoolYear, $schoolYears, true)) {
    $schoolYear = '';
}

$offices = $pdo->prepare('SELECT DISTINCT TRIM(office) AS office FROM suggestions WHERE college_id = :college_id AND office IS NOT NULL AND TRIM(office) <> "" ORDER BY office');
$offices->execute([':college_id' => $collegeId]);
$officeOptions = $offices->fetchAll(PDO::FETCH_COLUMN);
$office = trim((string)($_GET['office'] ?? ''));
if ($office !== '' && !in_array($office, $officeOptions, true)) {
    $office = '';
}

$allowedStatuses = $activeTab === 'complaints'
    ? ['new', 'pending', 'under_review', 'resolved', 'closed']
    : ['new', 'under_review', 'approved', 'implemented', 'reviewed', 'declined', 'resolved', 'rejected', 'accepted', 'not_feasible', 'needs_info', 'planned', 'in_progress'];
if ($status !== '' && !in_array($status, $allowedStatuses, true)) {
    $status = '';
}

$aiSearchAttempted = false;
$aiSearchFailed = false;
if ((int)($_GET['ai_search'] ?? 0) === 1 && $q !== '') {
    $aiSearchAttempted = true;
    $rawSearch = $q;
    $interpreted = dean_report_groq_filters($pdo, $q, $activeTab, $departmentsData, $schoolYears, $officeOptions);

    if ($interpreted === []) {
        $aiSearchFailed = true;
        $q = '';
    } else {
        $interpretedDepartment = (int)($interpreted['department_id'] ?? 0);
        if ($interpretedDepartment > 0 && in_array($interpretedDepartment, $departmentIds, true)) {
            $departmentId = $interpretedDepartment;
        }
        if (is_string($interpreted['school_year'] ?? null) && in_array($interpreted['school_year'], $schoolYears, true)) {
            $schoolYear = $interpreted['school_year'];
        }
        if (in_array((string)($interpreted['semester'] ?? ''), ['1', '2'], true)) {
            $semester = (string)$interpreted['semester'];
        }
        if ($activeTab === 'suggestions' && is_string($interpreted['office'] ?? null) && in_array($interpreted['office'], $officeOptions, true)) {
            $office = $interpreted['office'];
        }
        if (is_string($interpreted['status'] ?? null) && in_array($interpreted['status'], $allowedStatuses, true)) {
            $status = $interpreted['status'];
        }
        if (dean_report_date_valid((string)($interpreted['date_from'] ?? ''))) {
            $dateFrom = (string)$interpreted['date_from'];
        }
        if (dean_report_date_valid((string)($interpreted['date_to'] ?? ''))) {
            $dateTo = (string)$interpreted['date_to'];
        }
        $q = trim((string)($interpreted['keyword'] ?? ''));
    }
}

function dean_report_where_for_status(string $tableAlias, string $statusValue): string
{
    return $statusValue !== '' ? " AND {$tableAlias}.status = :status" : '';
}

function dean_report_search_clause(string $tableAlias, string $matcher, array &$params, string $prefix): string
{
    if ($matcher === '') {
        return '';
    }

    $like = '%' . $matcher . '%';
    $params[':q_' . $prefix . '_text'] = $like;
    $params[':q_' . $prefix . '_subject'] = $like;
    $params[':q_' . $prefix . '_desc'] = $like;
    return " AND ({$tableAlias}.act_complained_of LIKE :q_{$prefix}_text OR {$tableAlias}.narrative_report LIKE :q_{$prefix}_desc OR {$tableAlias}.subject LIKE :q_{$prefix}_subject OR {$tableAlias}.description LIKE :q_{$prefix}_desc)";
}

$complaintWhere = ['c.college_id = :college_id'];
$complaintParams = [':college_id' => $collegeId];
if ($departmentId > 0) {
    $complaintWhere[] = 'sp.program_id = :department_id';
    $complaintParams[':department_id'] = $departmentId;
}
if ($schoolYear !== '') {
    $complaintWhere[] = 'c.school_year = :school_year';
    $complaintParams[':school_year'] = $schoolYear;
}
if ($semester !== '') {
    $complaintWhere[] = 'c.semester = :semester';
    $complaintParams[':semester'] = $semester;
}
if ($status !== '') {
    $complaintWhere[] = 'c.status = :status';
    $complaintParams[':status'] = $status;
}
if ($dateFrom !== '') {
    $complaintWhere[] = 'c.created_at >= :date_from';
    $complaintParams[':date_from'] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $complaintWhere[] = 'c.created_at < :date_to';
    $complaintParams[':date_to'] = (new DateTimeImmutable($dateTo))->modify('+1 day')->format('Y-m-d 00:00:00');
}
if ($q !== '') {
    $like = '%' . $q . '%';
    $complaintWhere[] = '(c.act_complained_of LIKE :q_complaint OR c.narrative_report LIKE :q_narrative OR cc.name LIKE :q_category OR p.name LIKE :q_program)';
    $complaintParams[':q_complaint'] = $like;
    $complaintParams[':q_narrative'] = $like;
    $complaintParams[':q_category'] = $like;
    $complaintParams[':q_program'] = $like;
}

$suggestionWhere = ['s.college_id = :college_id'];
$suggestionParams = [':college_id' => $collegeId];
if ($departmentId > 0) {
    $suggestionWhere[] = 'sp.program_id = :department_id';
    $suggestionParams[':department_id'] = $departmentId;
}
if ($schoolYear !== '') {
    $suggestionWhere[] = 's.school_year = :school_year';
    $suggestionParams[':school_year'] = $schoolYear;
}
if ($semester !== '') {
    $suggestionWhere[] = 's.semester = :semester';
    $suggestionParams[':semester'] = $semester;
}
if ($office !== '') {
    $suggestionWhere[] = 's.office = :office';
    $suggestionParams[':office'] = $office;
}
if ($status !== '') {
    $suggestionWhere[] = 's.status = :status';
    $suggestionParams[':status'] = $status;
}
if ($dateFrom !== '') {
    $suggestionWhere[] = 's.created_at >= :date_from';
    $suggestionParams[':date_from'] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $suggestionWhere[] = 's.created_at < :date_to';
    $suggestionParams[':date_to'] = (new DateTimeImmutable($dateTo))->modify('+1 day')->format('Y-m-d 00:00:00');
}
if ($q !== '') {
    $like = '%' . $q . '%';
    $suggestionWhere[] = '(s.subject LIKE :q_subject OR s.description LIKE :q_description OR sc.name LIKE :q_category OR s.office LIKE :q_office OR p.name LIKE :q_program)';
    $suggestionParams[':q_subject'] = $like;
    $suggestionParams[':q_description'] = $like;
    $suggestionParams[':q_category'] = $like;
    $suggestionParams[':q_office'] = $like;
    $suggestionParams[':q_program'] = $like;
}

$complaintSql = 'SELECT c.id, c.created_at, c.act_complained_of, c.narrative_report, c.status, c.school_year, c.semester, COALESCE(cc.name, "Uncategorized") AS category_name, COALESCE(col.name, "Not assigned") AS college_name, COALESCE(p.name, "Not assigned") AS department_name FROM complaints c LEFT JOIN complaint_categories cc ON cc.id = c.category_id LEFT JOIN student_profiles sp ON sp.id = c.student_id LEFT JOIN programs p ON p.id = sp.program_id LEFT JOIN colleges col ON col.id = c.college_id WHERE ' . implode(' AND ', $complaintWhere) . ' ORDER BY c.created_at DESC, c.id DESC LIMIT 100';
$complaintStmt = $pdo->prepare($complaintSql);
$complaintStmt->execute($complaintParams);
$complaints = $complaintStmt->fetchAll(PDO::FETCH_ASSOC);

$suggestionSql = 'SELECT s.id, s.subject, s.description, s.created_at, s.school_year, s.semester, s.status, s.office, COALESCE(sc.name, "Uncategorized") AS category_name, COALESCE(col.name, "Not assigned") AS college_name, COALESCE(p.name, "Not assigned") AS department_name FROM suggestions s LEFT JOIN suggestion_categories sc ON sc.id = s.category_id LEFT JOIN student_profiles sp ON sp.id = s.student_id LEFT JOIN programs p ON p.id = sp.program_id LEFT JOIN colleges col ON col.id = s.college_id WHERE ' . implode(' AND ', $suggestionWhere) . ' ORDER BY s.created_at DESC, s.id DESC LIMIT 100';
$suggestionStmt = $pdo->prepare($suggestionSql);
$suggestionStmt->execute($suggestionParams);
$suggestions = $suggestionStmt->fetchAll(PDO::FETCH_ASSOC);

$complaintCountSql = 'SELECT COUNT(*) FROM complaints c LEFT JOIN student_profiles sp ON sp.id = c.student_id WHERE c.college_id = :college_id' . ($departmentId > 0 ? ' AND sp.program_id = :department_id' : '') . ($schoolYear !== '' ? ' AND c.school_year = :school_year' : '') . ($semester !== '' ? ' AND c.semester = :semester' : '') . ($q !== '' ? ' AND (c.act_complained_of LIKE :q_complaint OR c.narrative_report LIKE :q_narrative OR c.status LIKE :q_status)' : '') . ($dateFrom !== '' ? ' AND c.created_at >= :date_from' : '') . ($dateTo !== '' ? ' AND c.created_at < :date_to' : '') . ($status !== '' ? ' AND c.status = :status' : '');
$complaintCountStmt = $pdo->prepare($complaintCountSql);
$complaintCountParams = [':college_id' => $collegeId];
if ($departmentId > 0) { $complaintCountParams[':department_id'] = $departmentId; }
if ($schoolYear !== '') { $complaintCountParams[':school_year'] = $schoolYear; }
if ($semester !== '') { $complaintCountParams[':semester'] = $semester; }
if ($q !== '') { $complaintCountParams[':q_complaint'] = '%' . $q . '%'; $complaintCountParams[':q_narrative'] = '%' . $q . '%'; $complaintCountParams[':q_status'] = '%' . $q . '%'; }
if ($dateFrom !== '') { $complaintCountParams[':date_from'] = $dateFrom . ' 00:00:00'; }
if ($dateTo !== '') { $complaintCountParams[':date_to'] = (new DateTimeImmutable($dateTo))->modify('+1 day')->format('Y-m-d 00:00:00'); }
if ($status !== '') { $complaintCountParams[':status'] = $status; }
$complaintCountStmt->execute($complaintCountParams);
$totalComplaints = (int)$complaintCountStmt->fetchColumn();

$suggestionCountSql = 'SELECT COUNT(*) FROM suggestions s LEFT JOIN student_profiles sp ON sp.id = s.student_id WHERE s.college_id = :college_id' . ($departmentId > 0 ? ' AND sp.program_id = :department_id' : '') . ($schoolYear !== '' ? ' AND s.school_year = :school_year' : '') . ($semester !== '' ? ' AND s.semester = :semester' : '') . ($office !== '' ? ' AND s.office = :office' : '') . ($q !== '' ? ' AND (s.subject LIKE :q_subject OR s.description LIKE :q_description OR s.status LIKE :q_status)' : '') . ($dateFrom !== '' ? ' AND s.created_at >= :date_from' : '') . ($dateTo !== '' ? ' AND s.created_at < :date_to' : '') . ($status !== '' ? ' AND s.status = :status' : '');
$suggestionCountStmt = $pdo->prepare($suggestionCountSql);
$suggestionCountParams = [':college_id' => $collegeId];
if ($departmentId > 0) { $suggestionCountParams[':department_id'] = $departmentId; }
if ($schoolYear !== '') { $suggestionCountParams[':school_year'] = $schoolYear; }
if ($semester !== '') { $suggestionCountParams[':semester'] = $semester; }
if ($office !== '') { $suggestionCountParams[':office'] = $office; }
if ($q !== '') { $suggestionCountParams[':q_subject'] = '%' . $q . '%'; $suggestionCountParams[':q_description'] = '%' . $q . '%'; $suggestionCountParams[':q_status'] = '%' . $q . '%'; }
if ($dateFrom !== '') { $suggestionCountParams[':date_from'] = $dateFrom . ' 00:00:00'; }
if ($dateTo !== '') { $suggestionCountParams[':date_to'] = (new DateTimeImmutable($dateTo))->modify('+1 day')->format('Y-m-d 00:00:00'); }
if ($status !== '') { $suggestionCountParams[':status'] = $status; }
$suggestionCountStmt->execute($suggestionCountParams);
$totalSuggestions = (int)$suggestionCountStmt->fetchColumn();

$complaintMetricStmt = $pdo->prepare('SELECT c.status, COUNT(*) AS total FROM complaints c LEFT JOIN student_profiles sp ON sp.id = c.student_id WHERE c.college_id = :college_id' . ($departmentId > 0 ? ' AND sp.program_id = :department_id' : '') . ($schoolYear !== '' ? ' AND c.school_year = :school_year' : '') . ($semester !== '' ? ' AND c.semester = :semester' : '') . ($dateFrom !== '' ? ' AND c.created_at >= :date_from' : '') . ($dateTo !== '' ? ' AND c.created_at < :date_to' : '') . ($q !== '' ? ' AND (c.act_complained_of LIKE :q_complaint OR c.narrative_report LIKE :q_narrative)' : '') . ' GROUP BY c.status');
$complaintMetricParams = [':college_id' => $collegeId];
if ($departmentId > 0) { $complaintMetricParams[':department_id'] = $departmentId; }
if ($schoolYear !== '') { $complaintMetricParams[':school_year'] = $schoolYear; }
if ($semester !== '') { $complaintMetricParams[':semester'] = $semester; }
if ($dateFrom !== '') { $complaintMetricParams[':date_from'] = $dateFrom . ' 00:00:00'; }
if ($dateTo !== '') { $complaintMetricParams[':date_to'] = (new DateTimeImmutable($dateTo))->modify('+1 day')->format('Y-m-d 00:00:00'); }
if ($q !== '') { $complaintMetricParams[':q_complaint'] = '%' . $q . '%'; $complaintMetricParams[':q_narrative'] = '%' . $q . '%'; }
$complaintMetricStmt->execute($complaintMetricParams);
$complaintStatusCounts = ['new' => 0, 'pending' => 0, 'under_review' => 0, 'resolved' => 0, 'closed' => 0];
foreach ($complaintMetricStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $key = (string)$row['status'];
    if (isset($complaintStatusCounts[$key])) {
        $complaintStatusCounts[$key] = (int)$row['total'];
    }
}

$suggestionMetricStmt = $pdo->prepare('SELECT s.status, COUNT(*) AS total FROM suggestions s LEFT JOIN student_profiles sp ON sp.id = s.student_id WHERE s.college_id = :college_id' . ($departmentId > 0 ? ' AND sp.program_id = :department_id' : '') . ($schoolYear !== '' ? ' AND s.school_year = :school_year' : '') . ($semester !== '' ? ' AND s.semester = :semester' : '') . ($office !== '' ? ' AND s.office = :office' : '') . ($dateFrom !== '' ? ' AND s.created_at >= :date_from' : '') . ($dateTo !== '' ? ' AND s.created_at < :date_to' : '') . ($q !== '' ? ' AND (s.subject LIKE :q_subject OR s.description LIKE :q_description)' : '') . ' GROUP BY s.status');
$suggestionMetricParams = [':college_id' => $collegeId];
if ($departmentId > 0) { $suggestionMetricParams[':department_id'] = $departmentId; }
if ($schoolYear !== '') { $suggestionMetricParams[':school_year'] = $schoolYear; }
if ($semester !== '') { $suggestionMetricParams[':semester'] = $semester; }
if ($office !== '') { $suggestionMetricParams[':office'] = $office; }
if ($dateFrom !== '') { $suggestionMetricParams[':date_from'] = $dateFrom . ' 00:00:00'; }
if ($dateTo !== '') { $suggestionMetricParams[':date_to'] = (new DateTimeImmutable($dateTo))->modify('+1 day')->format('Y-m-d 00:00:00'); }
if ($q !== '') { $suggestionMetricParams[':q_subject'] = '%' . $q . '%'; $suggestionMetricParams[':q_description'] = '%' . $q . '%'; }
$suggestionMetricStmt->execute($suggestionMetricParams);
$suggestionStatusCounts = ['new' => 0, 'under_review' => 0, 'approved' => 0, 'implemented' => 0, 'reviewed' => 0, 'declined' => 0, 'resolved' => 0, 'rejected' => 0, 'accepted' => 0, 'not_feasible' => 0, 'needs_info' => 0, 'planned' => 0, 'in_progress' => 0];
foreach ($suggestionMetricStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $key = (string)$row['status'];
    if (isset($suggestionStatusCounts[$key])) {
        $suggestionStatusCounts[$key] = (int)$row['total'];
    }
}

$complaintCategories = [];
$suggestionCategories = [];

$complaintCategoryStmt = $pdo->prepare('SELECT COALESCE(cc.name, "Uncategorized") AS label, COUNT(*) AS total FROM complaints c LEFT JOIN complaint_categories cc ON cc.id = c.category_id LEFT JOIN student_profiles sp ON sp.id = c.student_id WHERE c.college_id = :college_id' . ($departmentId > 0 ? ' AND sp.program_id = :department_id' : '') . ($schoolYear !== '' ? ' AND c.school_year = :school_year' : '') . ($semester !== '' ? ' AND c.semester = :semester' : '') . ($dateFrom !== '' ? ' AND c.created_at >= :date_from' : '') . ($dateTo !== '' ? ' AND c.created_at < :date_to' : '') . ($status !== '' ? ' AND c.status = :status' : '') . ($q !== '' ? ' AND (c.act_complained_of LIKE :q_complaint OR c.narrative_report LIKE :q_narrative)' : '') . ' GROUP BY label ORDER BY total DESC LIMIT 8');
$complaintCategoryStmt->execute(array_merge([':college_id' => $collegeId], $departmentId > 0 ? [':department_id' => $departmentId] : [], $schoolYear !== '' ? [':school_year' => $schoolYear] : [], $semester !== '' ? [':semester' => $semester] : [], $dateFrom !== '' ? [':date_from' => $dateFrom . ' 00:00:00'] : [], $dateTo !== '' ? [':date_to' => (new DateTimeImmutable($dateTo))->modify('+1 day')->format('Y-m-d 00:00:00')] : [], $status !== '' ? [':status' => $status] : [], $q !== '' ? [':q_complaint' => '%' . $q . '%', ':q_narrative' => '%' . $q . '%'] : []));
$complaintCategories = $complaintCategoryStmt->fetchAll(PDO::FETCH_ASSOC);
$topComplaintCategory = (string)($complaintCategories[0]['label'] ?? 'No complaints');

$suggestionCategoryStmt = $pdo->prepare('SELECT COALESCE(sc.name, "Uncategorized") AS label, COUNT(*) AS total FROM suggestions s LEFT JOIN suggestion_categories sc ON sc.id = s.category_id LEFT JOIN student_profiles sp ON sp.id = s.student_id WHERE s.college_id = :college_id' . ($departmentId > 0 ? ' AND sp.program_id = :department_id' : '') . ($schoolYear !== '' ? ' AND s.school_year = :school_year' : '') . ($semester !== '' ? ' AND s.semester = :semester' : '') . ($office !== '' ? ' AND s.office = :office' : '') . ($dateFrom !== '' ? ' AND s.created_at >= :date_from' : '') . ($dateTo !== '' ? ' AND s.created_at < :date_to' : '') . ($status !== '' ? ' AND s.status = :status' : '') . ($q !== '' ? ' AND (s.subject LIKE :q_subject OR s.description LIKE :q_description)' : '') . ' GROUP BY label ORDER BY total DESC LIMIT 8');
$suggestionCategoryStmt->execute(array_merge([':college_id' => $collegeId], $departmentId > 0 ? [':department_id' => $departmentId] : [], $schoolYear !== '' ? [':school_year' => $schoolYear] : [], $semester !== '' ? [':semester' => $semester] : [], $office !== '' ? [':office' => $office] : [], $dateFrom !== '' ? [':date_from' => $dateFrom . ' 00:00:00'] : [], $dateTo !== '' ? [':date_to' => (new DateTimeImmutable($dateTo))->modify('+1 day')->format('Y-m-d 00:00:00')] : [], $status !== '' ? [':status' => $status] : [], $q !== '' ? [':q_subject' => '%' . $q . '%', ':q_description' => '%' . $q . '%'] : []));
$suggestionCategories = $suggestionCategoryStmt->fetchAll(PDO::FETCH_ASSOC);
$topSuggestionCategory = (string)($suggestionCategories[0]['label'] ?? 'No suggestions');

$reviewedSuggestions = $suggestionStatusCounts['reviewed'] ?? 0;
$resolvedComplaints = $complaintStatusCounts['resolved'] ?? 0;
$resolutionRate = $totalComplaints > 0 ? (int)round(($resolvedComplaints / $totalComplaints) * 100) : 0;

$summaryType = $activeTab === 'complaints' ? 'complaints' : 'suggestions';
$aiSummary = $activeTab === 'complaints'
    ? dean_report_groq_summary('complaints', $totalComplaints, $complaintStatusCounts, $topComplaintCategory, $topComplaintCategory)
    : dean_report_groq_summary('suggestions', $totalSuggestions, $suggestionStatusCounts, $topSuggestionCategory, $topSuggestionCategory);
if ($aiSummary === '') {
    $aiSummary = $activeTab === 'complaints'
        ? 'The dean report shows ' . number_format($totalComplaints) . ' complaint entries in this college, with ' . number_format($resolvedComplaints) . ' resolved and a resolution rate of ' . $resolutionRate . '%.'
        : 'The dean report shows ' . number_format($totalSuggestions) . ' suggestions in this college, with ' . number_format($reviewedSuggestions) . ' reviewed and a strong focus on ' . $topSuggestionCategory . '.';
}

$filterPreserve = array_filter([
    'tab' => $activeTab,
    'q' => $q,
    'sort' => $sort,
    'school_year' => $schoolYear,
    'semester' => $semester,
    'department' => $departmentId > 0 ? (string)$departmentId : '',
    'status' => $status,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'office' => $office,
    'range' => $range,
], static fn($v) => $v !== '');

$tabUrl = static function (string $tab) use ($filterPreserve): string {
    $params = $filterPreserve;
    $params['tab'] = $tab;
    return 'dean_reports.php?' . http_build_query($params);
};

$cardFilterUrl = static function (string $value) use ($filterPreserve): string {
    $params = $filterPreserve;
    if ($value === '') {
        unset($params['card_filter']);
    } else {
        $params['card_filter'] = $value;
    }
    return 'dean_reports.php?' . http_build_query($params);
};

function dean_reports_semester_clause(string $alias, string $semester, array &$params, string $prefix): string
{
    // The stored semester column, set once at submission time from whichever
    // academic calendar was in effect then - not a hardcoded Aug1/Jan1 date
    // guess, so this stays correct even if the calendar changes.
    if ($semester === '1' || $semester === '2') {
        $params[":{$prefix}_semester"] = $semester;
        return " AND {$alias}.semester = :{$prefix}_semester";
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
$suggestionsSubquery = '(SELECT COUNT(*) FROM suggestions fs WHERE fs.student_id = sp.id';
if ($schoolYear !== '') {
    $reportedSubquery .= ' AND rc.school_year = :sy_reported';
    $complaintsSubquery .= ' AND fc.school_year = :sy_filed';
    $suggestionsSubquery .= ' AND fs.school_year = :sy_suggestions';
    $directoryParams[':sy_reported'] = $schoolYear;
    $directoryParams[':sy_filed'] = $schoolYear;
    $directoryParams[':sy_suggestions'] = $schoolYear;
}
$reportedSubquery .= dean_reports_semester_clause('rc', $semester, $directoryParams, 'semester_reported');
$complaintsSubquery .= dean_reports_semester_clause('fc', $semester, $directoryParams, 'semester_filed');
$suggestionsSubquery .= dean_reports_semester_clause('fs', $semester, $directoryParams, 'semester_suggestions');
$reportedSubquery .= dean_reports_range_clause('rc', $reportRangeStart, $reportRangeEnd, $directoryParams, 'range_reported');
$complaintsSubquery .= dean_reports_range_clause('fc', $reportRangeStart, $reportRangeEnd, $directoryParams, 'range_filed');
$suggestionsSubquery .= dean_reports_range_clause('fs', $reportRangeStart, $reportRangeEnd, $directoryParams, 'range_suggestions');
$complaintsSubquery .= $cardFilter === 'resolved_complaints' ? " AND fc.status = 'resolved'" : '';
$suggestionsSubquery .= in_array($cardFilter, ['reviewed_suggestions'], true) ? " AND fs.status = 'reviewed'" : '';
if (in_array($cardFilter, ['suggestions', 'reviewed_suggestions'], true)) {
    $reportedSubquery .= ' AND 1 = 0';
    $complaintsSubquery .= ' AND 1 = 0';
}
if (in_array($cardFilter, ['complaints', 'resolved_complaints'], true)) {
    $suggestionsSubquery .= ' AND 1 = 0';
}
$latestComplaintSubquery = preg_replace('/^\(SELECT COUNT\(\*\)/', '(SELECT MAX(fc.created_at)', $complaintsSubquery);
$latestSuggestionSubquery = preg_replace('/^\(SELECT COUNT\(\*\)/', '(SELECT MAX(fs.created_at)', $suggestionsSubquery);
$latestParameterPrefixes = [
    'sy_filed' => 'latest_sy_filed',
    'semester_filed_start' => 'latest_semester_filed_start',
    'semester_filed_end' => 'latest_semester_filed_end',
    'range_filed_start' => 'latest_range_filed_start',
    'range_filed_end' => 'latest_range_filed_end',
    'sy_suggestions' => 'latest_sy_suggestions',
    'semester_suggestions_start' => 'latest_semester_suggestions_start',
    'semester_suggestions_end' => 'latest_semester_suggestions_end',
    'range_suggestions_start' => 'latest_range_suggestions_start',
    'range_suggestions_end' => 'latest_range_suggestions_end',
];
foreach ($latestParameterPrefixes as $sourcePrefix => $latestPrefix) {
    $sourceParameter = ':' . $sourcePrefix;
    $latestParameter = ':' . $latestPrefix;
    $latestComplaintSubquery = str_replace($sourceParameter, $latestParameter, $latestComplaintSubquery);
    $latestSuggestionSubquery = str_replace($sourceParameter, $latestParameter, $latestSuggestionSubquery);
    if (array_key_exists($sourceParameter, $directoryParams)) {
        $directoryParams[$latestParameter] = $directoryParams[$sourceParameter];
    }
}
$latestComplaintSubquery .= ')';
$latestSuggestionSubquery .= ')';
$latestActivitySql = "GREATEST(COALESCE({$latestComplaintSubquery}, '1000-01-01 00:00:00'), COALESCE({$latestSuggestionSubquery}, '1000-01-01 00:00:00'))";
$reportedSubquery .= ') AS times_reported';
$complaintsSubquery .= ') AS complaints_filed';
$suggestionsSubquery .= ') AS suggestions_filed';

$cardActivitySql = '';
if ($cardFilter !== '') {
    $activityTable = in_array($cardFilter, ['suggestions', 'reviewed_suggestions'], true) ? 'suggestions' : 'complaints';
    $activityAlias = $activityTable === 'suggestions' ? 'af_s' : 'af_c';
    $cardActivitySql = " AND EXISTS (SELECT 1 FROM {$activityTable} {$activityAlias} WHERE {$activityAlias}.student_id = sp.id";
    if ($schoolYear !== '') {
        $cardActivitySql .= " AND {$activityAlias}.school_year = :sy_card_activity";
        $directoryParams[':sy_card_activity'] = $schoolYear;
    }
    $cardActivitySql .= dean_reports_semester_clause($activityAlias, $semester, $directoryParams, 'semester_card_activity');
    $cardActivitySql .= dean_reports_range_clause($activityAlias, $reportRangeStart, $reportRangeEnd, $directoryParams, 'range_card_activity');
    if ($cardFilter === 'reviewed_suggestions') {
        $cardActivitySql .= " AND {$activityAlias}.status = 'reviewed'";
    } elseif ($cardFilter === 'resolved_complaints') {
        $cardActivitySql .= " AND {$activityAlias}.status = 'resolved'";
    }
    $cardActivitySql .= ')';
}

// Sorting: newest matching complaint/suggestion activity first by default.
$sortSql = " ORDER BY {$latestActivitySql} DESC, sp.first_name, sp.last_name";
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
        {$complaintsSubquery},
        {$suggestionsSubquery}
    FROM student_profiles sp
    LEFT JOIN users u ON sp.user_id = u.id
    LEFT JOIN programs prog ON sp.program_id = prog.id
    WHERE {$directoryWhere}{$cardActivitySql}";

$sql = $baseSql . $sortSql . ' LIMIT 50';
$studentStmt = $pdo->prepare($sql);
$studentStmt->execute($directoryParams);
$students = $studentStmt->fetchAll(PDO::FETCH_ASSOC);
$students = array_values(array_filter($students, static function (array $student): bool {
    return (int)($student['times_reported'] ?? 0) > 0 || (int)($student['complaints_filed'] ?? 0) > 0 || (int)($student['suggestions_filed'] ?? 0) > 0;
}));

// If AJAX request, return only table rows HTML for live search
if ((string)($_GET['ajax'] ?? '') === '1') {
    if (empty($students)) {
        echo '<tr><td colspan="8" style="text-align:center;color:#aaa">No data found for selected filters</td></tr>';
        exit;
    }
    foreach ($students as $student) {
        $sid = htmlspecialchars($student['student_number'] ?? $student['username'] ?? 'N/A');
        $name = htmlspecialchars(trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? '')));
        $program = htmlspecialchars($student['program'] ?? 'N/A');
        $year = htmlspecialchars($student['year_level'] ?? 'N/A');
        $detailParams = $filterPreserve;
        $detailParams['student_id'] = (int)$student['sp_id'];
        $detailUrl = 'dean_report_student_detail.php?' . http_build_query($detailParams);
        $reported = (int)($student['times_reported'] ?? 0);
        $complaints = (int)($student['complaints_filed'] ?? 0);
        $suggestions = (int)($student['suggestions_filed'] ?? 0);
        $status = htmlspecialchars(ucfirst($student['status'] ?? 'active'));
        $statusClass = 'status-' . strtolower($student['status'] ?? 'active');
        echo '<tr class="report-student-row" tabindex="0" role="link" data-href="' . htmlspecialchars($detailUrl, ENT_QUOTES, 'UTF-8') . '" title="Open student report details">';
        echo "<td><strong>{$sid}</strong></td>";
        echo "<td>{$name}</td>";
        echo "<td>{$program}</td>";
        echo "<td>{$year}</td>";
        echo '<td><a class="complaint-count-badge" href="dean_reported_complaints.php?student_id=' . (int)$student['sp_id'] . '" title="View reported complaints">' . $reported . '</a></td>';
        echo '<td><a class="complaint-count-badge" href="dean_reported_complaints.php?student_id=' . (int)$student['sp_id'] . '&view=complaints" title="View student complaints">' . $complaints . '</a></td>';
        echo '<td><span class="complaint-count-badge" title="Student suggestions">' . $suggestions . '</span></td>';
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
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>

<style>
* { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
body { background: #f9fafb; }
.main { margin-left: 260px; margin-top: 61px; padding: 20px 24px; min-height: calc(100vh - 61px); overflow-y: auto; }

/* ===== PAGE HEADER ===== */
.page-header { margin-bottom: 4px; }
.page-header h2 { font-size: 24px; font-weight: 700; color: #1f2937; }
.page-subtitle { color: #6b7280; font-size: 14px; margin-bottom: 6px; }
.filter-summary { color: #6b7280; font-size: 13px; margin-bottom: 12px; }
.filter-summary strong { color: #374151; font-weight: 600; }
.print-only { display: none; }

/* ===== FILTER BAR ===== */
.filter-bar {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.02);
    padding: 12px 14px;
    margin-bottom: 16px;
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: 10px;
}
.filter-form { display: flex; flex-wrap: nowrap; gap: 10px; align-items: flex-end; flex: 1 1 700px; min-width: 0; }
.filter-field { display: flex; flex-direction: column; gap: 4px; min-width: 0; flex: 1 1 125px; }
.filter-field label { font-size: 11px; font-weight: 600; color: #52627a; text-transform: uppercase; letter-spacing: .03em; }
.filter-select {
    height: 36px;
    padding: 6px 10px;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    background: #fff;
    font-size: 12.5px;
    color: #1f2937;
    font-family: 'Poppins', sans-serif;
    min-width: 0;
    width: 100%;
    cursor: pointer;
}
.filter-select:focus { outline: none; border-color: #6d28d9; box-shadow: 0 0 0 3px rgba(109,40,217,0.1); }
input.filter-select { cursor: text; }

.filter-actions { display: flex; gap: 8px; align-items: flex-end; flex: 0 0 auto; }
.btn-print, .btn-ghost {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    height: 36px;
    padding: 7px 12px;
    border-radius: 8px;
    font-size: 12.5px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    border: none;
    font-family: 'Poppins', sans-serif;
    white-space: nowrap;
}
.btn-print { background: #6d28d9; color: #fff; min-width: 125px; justify-content: center; }
.btn-print:hover { background: #5d1fa0; }
.btn-ghost { background: #fff; color: #6b7280; border: 1px solid #e5e7eb; }
.btn-ghost:hover { background: #f3f4f6; color: #374151; }

/* ===== KPI METRICS ===== */
.metrics-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 16px; }
@media (max-width: 1200px) { .metrics-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 640px) { .metrics-grid { grid-template-columns: 1fr; } }

.metric-card {
    background: #fff;
    padding: 13px 14px;
    border-radius: 12px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 2px 10px rgba(0,0,0,0.02);
    display: flex;
    align-items: center;
    gap: 11px;
    transition: transform 0.2s ease;
    text-decoration: none;
    color: inherit;
    cursor: pointer;
}
.metric-card:hover { transform: translateY(-3px); box-shadow: 0 5px 15px rgba(0,0,0,0.05); }
.metric-card.active { border-color: #6d28d9; box-shadow: 0 0 0 3px rgba(109,40,217,0.12), 0 5px 15px rgba(0,0,0,0.05); }
.metric-icon { width: 42px; height: 42px; border-radius: 10px; display: flex; justify-content: center; align-items: center; font-size: 20px; flex-shrink: 0; }
.icon-blue { background: #eff6ff; color: #3b82f6; }
.icon-purple { background: #f3e8ff; color: #7c3aed; }
.icon-green { background: #dcfce7; color: #16a34a; }
.icon-amber { background: #fef3c7; color: #d97706; }
.metric-info h4 { color: #6b7280; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .03em; margin-bottom: 3px; }
.metric-info .value { font-size: 21px; font-weight: 700; color: #1f2937; line-height: 1; }
.metric-info .sub { font-size: 12px; color: #9ca3af; margin-top: 4px; }

/* ===== SHARED CARD ===== */
.data-card { background: #fff; padding: 16px 18px; border-radius: 12px; border: 1px solid #e5e7eb; box-shadow: 0 2px 10px rgba(0,0,0,0.02); margin-bottom: 16px; }
.card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 11px; flex-wrap: wrap; gap: 8px; }
.card-header h3 { font-size: 16px; font-weight: 600; color: #1f2937; }
.card-header p { font-size: 12.5px; color: #9ca3af; margin-top: 2px; }

/* ===== DIRECTORY TOOLBAR ===== */
.directory-toolbar { display: flex; gap: 8px; align-items: center; margin-bottom: 11px; flex-wrap: wrap; }
.search-box { display: flex; align-items: center; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 7px 10px; flex: 1; min-width: 220px; }
.search-box i { color: #9ca3af; margin-right: 8px; }
.search-box input { border: none; background: transparent; outline: none; width: 100%; font-size: 13.5px; font-family: 'Poppins', sans-serif; }

/* ===== SMART REPORTS ===== */
.main { background: #f4f6fb; color: #1f2937; }
.page-header { margin-bottom: 12px; }
.page-header h2 { margin: 0; font-size: 25px; font-weight: 600; color: #262626; }
.page-subtitle { margin: 4px 0 0; color: #778197; font-size: 13px; }
.tabs { display: flex; gap: 4px; border-bottom: 1px solid #e5e7eb; margin-bottom: 16px; }
.tab { padding: 11px 16px; color: #778197; text-decoration: none; font-size: 13px; font-weight: 600; border-bottom: 3px solid transparent; }
.tab:hover, .tab.active { color: #6d28d9; border-bottom-color: #6d28d9; }
.card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; box-shadow: 0 4px 15px rgba(15,23,42,.04); }
.search-card { padding: 16px; margin-bottom: 14px; }
.section-label { display: block; color: #374151; font-size: 12px; font-weight: 700; margin-bottom: 8px; }
.search-row { display: flex; gap: 10px; }
.search-row input { flex: 1; min-width: 0; height: 40px; border: 1px solid #d8dce5; border-radius: 8px; padding: 0 13px; color: #1f2937; font-size: 13px; outline: none; }
.search-row input:focus, .filters select:focus, .filter-input:focus { border-color: #8b5cf6; box-shadow: 0 0 0 3px rgba(139,92,246,.12); outline: none; }
.primary-btn { border: 0; border-radius: 8px; background: #6d28d9; color: #fff; padding: 0 17px; height: 40px; font-weight: 600; font-size: 12.5px; cursor: pointer; white-space: nowrap; }
.primary-btn:hover { background: #5b21b6; }
.filters { padding: 16px; margin-bottom: 14px; }
.filter-grid { display: grid; grid-template-columns: repeat(6, minmax(125px, 1fr)); gap: 10px; }
.filter-grid .filter-field { min-width: 0; flex: initial; }
.filter-grid .filter-field label { display: block; margin-bottom: 5px; color: #6b7280; font-size: 11px; }
.filter-grid .filter-field select, .filter-input { width: 100%; height: 36px; border: 1px solid #d8dce5; border-radius: 7px; padding: 0 9px; background: #fff; color: #374151; font-size: 12px; }
.filter-footer { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-top: 13px; flex-wrap: wrap; }
.quick-dates { display: flex; gap: 7px; flex-wrap: wrap; }
.quick-date { border: 1px solid #ddd6fe; color: #6d28d9; background: #faf9ff; border-radius: 7px; padding: 7px 10px; font-size: 11px; font-weight: 600; cursor: pointer; }
.quick-date:hover { background: #ede9fe; }
.filter-footer .filter-actions { display: flex; gap: 7px; flex-wrap: wrap; }
.apply-btn, .clear-btn { display: inline-flex; align-items: center; justify-content: center; height: 34px; padding: 0 13px; border-radius: 7px; font-size: 12px; font-weight: 600; text-decoration: none; cursor: pointer; }
.apply-btn { border: 0; background: #6d28d9; color: #fff; }
.clear-btn { border: 1px solid #d8dce5; background: #fff; color: #6b7280; }
.summary-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 14px; }
.metric { padding: 16px; position: relative; overflow: hidden; }
.metric:after { content: ''; position: absolute; width: 52px; height: 52px; border-radius: 50%; right: -15px; top: -15px; background: #f0eafe; }
.metric-icon { color: #6d28d9; font-size: 21px; margin-bottom: 10px; }
.metric strong { display: block; font-size: 24px; line-height: 1; color: #252525; }
.metric span { display: block; color: #737b8c; font-size: 11.5px; margin-top: 7px; }
.metric em { display: block; color: #6d28d9; font-size: 11px; font-style: normal; font-weight: 600; margin-top: 3px; }
.analytics-grid { display: grid; grid-template-columns: minmax(0, 1.6fr) minmax(260px, .8fr); gap: 14px; margin-bottom: 14px; }
.chart-card { padding: 16px; min-height: 280px; }
.category-chart-wrap { height: 215px; position: relative; }
.chart-card h2, .ai-card h2, .table-card h2 { margin: 0 0 13px; color: #30343b; font-size: 14px; font-weight: 600; }
.mini-list { max-height: 225px; overflow-y: auto; }
.mini-row { display: flex; justify-content: space-between; gap: 12px; padding: 9px 0; border-bottom: 1px solid #f1f3f6; color: #52627a; font-size: 12px; }
.mini-row strong { color: #6d28d9; }
.ai-card { padding: 18px; border-top: 3px solid #7c3aed; }
.ai-badge { display: inline-flex; align-items: center; gap: 5px; color: #6d28d9; background: #f3efff; border-radius: 99px; padding: 5px 8px; font-size: 10px; font-weight: 700; margin-bottom: 11px; }
.ai-card p { margin: 0; color: #5f6878; font-size: 12px; line-height: 1.7; }
.ai-note { margin-top: 14px !important; padding-top: 12px; border-top: 1px solid #eeeaf8; color: #8b93a3 !important; font-size: 10.5px !important; }
.table-card { padding: 16px; overflow: hidden; }
.table-scroll { overflow-x: auto; }
table.report-table { min-width: 0; table-layout: fixed; }
.report-table th { padding: 9px 7px; border-bottom: 2px solid #f0f1f3; color: #798294; text-align: left; font-size: 9.5px; text-transform: uppercase; overflow-wrap: break-word; }
.report-table td { padding: 9px 7px; border-bottom: 1px solid #f1f3f6; color: #4b5563; font-size: 11px; vertical-align: top; overflow-wrap: break-word; }
.report-table tbody tr:hover { background: #fbfaff; }
.report-table .subject { color: #30343b; font-weight: 600; }
.report-table .subject small { display: block; overflow: hidden; color: #8a93a2; font-size: 10px; font-weight: 400; text-overflow: ellipsis; white-space: nowrap; }
.status-pill { display: inline-block; padding: 4px 8px; border-radius: 6px; background: #f3f4f6; color: #596273; font-size: 10px; font-weight: 600; white-space: nowrap; }
.status-pill.implemented, .status-pill.resolved { background: #dcfce7; color: #166534; }
.status-pill.under_review, .status-pill.pending { background: #fef3c7; color: #92400e; }
.status-pill.new { background: #dbeafe; color: #1e40af; }
.action-link { color: #6d28d9; text-decoration: none; font-weight: 600; white-space: nowrap; }
.empty { padding: 28px; color: #8b93a3; text-align: center; font-size: 12px; }
.ai-fallback-note { margin: 10px 0 0; padding: 9px 12px; background: #fff7ed; border: 1px solid #fde3c4; border-radius: 8px; color: #9a5b1f; font-size: 12px; line-height: 1.5; }
.cols-8 th:nth-child(1), .cols-8 td:nth-child(1) { width: 11%; }
.cols-8 th:nth-child(2), .cols-8 td:nth-child(2) { width: 23%; }
.cols-8 th:nth-child(3), .cols-8 td:nth-child(3) { width: 13%; }
.cols-8 th:nth-child(4), .cols-8 td:nth-child(4) { width: 15%; }
.cols-8 th:nth-child(5), .cols-8 td:nth-child(5) { width: 11%; }
.cols-8 th:nth-child(6), .cols-8 td:nth-child(6) { width: 10%; }
.cols-8 th:nth-child(7), .cols-8 td:nth-child(7) { width: 10%; }
.cols-8 th:nth-child(8), .cols-8 td:nth-child(8) { width: 7%; }
.cols-9 th:nth-child(1), .cols-9 td:nth-child(1) { width: 10%; }
.cols-9 th:nth-child(2), .cols-9 td:nth-child(2) { width: 20%; }
.cols-9 th:nth-child(3), .cols-9 td:nth-child(3) { width: 11%; }
.cols-9 th:nth-child(4), .cols-9 td:nth-child(4) { width: 14%; }
.cols-9 th:nth-child(5), .cols-9 td:nth-child(5) { width: 12%; }
.cols-9 th:nth-child(6), .cols-9 td:nth-child(6) { width: 10%; }
.cols-9 th:nth-child(7), .cols-9 td:nth-child(7) { width: 9%; }
.cols-9 th:nth-child(8), .cols-9 td:nth-child(8) { width: 7%; }
.cols-9 th:nth-child(9), .cols-9 td:nth-child(9) { width: 7%; }

/* ===== TABLE ===== */
table { width: 100%; border-collapse: collapse; min-width: 760px; }
.data-card > div[style*="overflow"] { overflow-x: auto; }
th { text-align: left; font-size: 11px; color: #9ca3af; padding: 9px 10px; border-bottom: 2px solid #f0f1f3; font-weight: 600; text-transform: uppercase; letter-spacing: .03em; }
td { padding: 10px; font-size: 13px; color: #374151; border-bottom: 1px solid #f9f9f9; vertical-align: middle; }
tbody tr:hover td { background: #fafafa; }
.report-student-row { cursor: pointer; }
.report-student-row:focus td { background: #f5f3ff; outline: none; }
.complaint-count-badge { display: inline-flex; align-items: center; justify-content: center; min-width: 34px; padding: 5px 10px; border-radius: 999px; background: #eef2ff; color: #4338ca; font-weight: 600; text-decoration: none; font-size: 13px; }
.complaint-count-badge:hover { background: #e0e7ff; }
.status-active { background: #dcfce7; color: #16a34a; padding: 5px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; }
.status-inactive { background: #fee2e2; color: #dc2626; padding: 5px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; }

@media (max-width: 900px) {
    .main { margin-left: 0 !important; padding: 16px !important; }
    .filter-bar { align-items: stretch; }
    .filter-form { flex-wrap: wrap; }
    .filter-field { flex: 1 1 180px; }
    .filter-actions { justify-content: flex-end; }
}

@media (max-width: 640px) {
    .filter-actions { width: 100%; justify-content: stretch; }
    .filter-actions > * { flex: 1; }
    .metrics-grid { gap: 10px; }
    .metric-card { padding: 11px; }
    .data-card { padding: 14px 12px; }
}

@media (max-width: 1050px) {
    .filter-grid { grid-template-columns: repeat(3, minmax(150px, 1fr)); }
    .analytics-grid { grid-template-columns: 1fr; }
}

@media (max-width: 700px) {
    .main { margin-left: 0 !important; padding: 16px !important; }
    .summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .filter-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .search-row { flex-direction: column; }
    .primary-btn { width: 100%; }
    .filter-footer { flex-direction: column; align-items: stretch; }
    .quick-dates, .filter-footer .filter-actions { justify-content: center; }
    table.report-table { table-layout: auto; min-width: 0; }
    .report-table thead { display: none; }
    .report-table, .report-table tbody, .report-table tr, .report-table td { display: block; width: 100% !important; }
    .report-table tbody tr { border: 1px solid #eef0f3; border-radius: 10px; padding: 12px 14px; margin-bottom: 12px; }
    .report-table td { padding: 4px 0; border-bottom: none; }
    .report-table td[data-label]::before { content: attr(data-label); display: block; font-size: 10px; font-weight: 700; color: #9ca3af; text-transform: uppercase; margin-bottom: 2px; }
    .report-table .subject { font-size: 13px; margin-bottom: 4px; }
    .report-table .subject small { white-space: normal; }
    .report-table td.td-action { padding-top: 8px; }
    .report-table td.td-action .action-link { display: inline-block; width: 100%; text-align: center; padding: 8px 0; border: 1px solid #ddd6fe; border-radius: 7px; background: #faf9ff; }
}

@media (max-width: 430px) {
    .filter-grid, .summary-grid { grid-template-columns: 1fr; }
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
    <header class="page-header">
        <h2>Reports Management</h2>
        <p class="page-subtitle"><?php echo e($collegeName); ?> (<?php echo e($collegeCode); ?>) Performance Overview</p>
    </header>

    <nav class="tabs" aria-label="Dean report tabs">
        <a class="tab <?php echo $activeTab === 'complaints' ? 'active' : ''; ?>" href="<?php echo e($tabUrl('complaints')); ?>">Complaint Reports</a>
        <a class="tab <?php echo $activeTab === 'suggestions' ? 'active' : ''; ?>" href="<?php echo e($tabUrl('suggestions')); ?>">Suggestion Reports</a>
    </nav>

    <form class="card search-card" method="get">
        <input type="hidden" name="tab" value="<?php echo e($activeTab); ?>">
        <input type="hidden" name="ai_search" value="1">
        <label class="section-label" for="aiSearch">Ask Reports - Dean View</label>
        <div class="search-row">
            <input id="aiSearch" name="q" value="<?php echo e($q); ?>" placeholder="Ask about <?php echo $activeTab === 'complaints' ? 'complaints (category, department, school year, semester, date, status, etc.)' : 'suggestions (category, department, office, school year, semester, date, status, etc.)'; ?>">
            <button class="primary-btn" type="submit"><i class='bx bx-sparkles'></i> Generate Report</button>
        </div>
        <?php if ($aiSearchFailed): ?>
            <p class="ai-fallback-note"><i class='bx bx-error-circle'></i> Couldn't interpret that as a smart dean search right now. Try a shorter keyword or use the filters below.</p>
        <?php endif; ?>
    </form>

    <form class="card filters" method="get" id="reportFilters">
        <input type="hidden" name="tab" value="<?php echo e($activeTab); ?>">
        <div class="filter-grid">
            <div class="filter-field">
                <label for="keywordFilter">Search / Keyword</label>
                <input class="filter-input" id="keywordFilter" name="q" value="<?php echo e($q); ?>" placeholder="Search report text...">
            </div>
            <div class="filter-field">
                <label for="department">Department</label>
                <select id="department" name="department">
                    <option value="0">All Departments</option>
                    <?php foreach ($departmentsData as $department): ?>
                        <option value="<?php echo (int)$department['id']; ?>" <?php echo $departmentId === (int)$department['id'] ? 'selected' : ''; ?>><?php echo e($department['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-field">
                <label for="schoolYear">School Year</label>
                <input class="filter-input" type="text" id="schoolYear" name="school_year" list="schoolYearsList" value="<?php echo e($schoolYear); ?>" placeholder="e.g. 2026-2027">
                <datalist id="schoolYearsList">
                    <?php foreach ($schoolYears as $year): ?>
                        <option value="<?php echo e($year); ?>"><?php endforeach; ?>
                </datalist>
            </div>
            <div class="filter-field">
                <label for="semester">Semester</label>
                <select id="semester" name="semester">
                    <option value="">All Semesters</option>
                    <option value="1" <?php echo $semester === '1' ? 'selected' : ''; ?>>1st Semester</option>
                    <option value="2" <?php echo $semester === '2' ? 'selected' : ''; ?>>2nd Semester</option>
                </select>
            </div>
            <?php if ($activeTab === 'suggestions'): ?>
                <div class="filter-field">
                    <label for="office">Office</label>
                    <select id="office" name="office">
                        <option value="">All Offices</option>
                        <?php foreach ($officeOptions as $officeOption): ?>
                            <option value="<?php echo e($officeOption); ?>" <?php echo $office === $officeOption ? 'selected' : ''; ?>><?php echo e($officeOption); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div class="filter-field">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="">All Statuses</option>
                    <?php foreach ($allowedStatuses as $statusOption): ?>
                        <option value="<?php echo e($statusOption); ?>" <?php echo $status === $statusOption ? 'selected' : ''; ?>><?php echo e(dean_report_status_label($statusOption)); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-field">
                <label for="range">Trend Grouping</label>
                <select id="range" name="range">
                    <option value="week" <?php echo $range === 'week' ? 'selected' : ''; ?>>Weekly</option>
                    <option value="month" <?php echo $range === 'month' ? 'selected' : ''; ?>>Monthly</option>
                    <option value="year" <?php echo $range === 'year' ? 'selected' : ''; ?>>Yearly</option>
                </select>
            </div>
            <div class="filter-field">
                <label for="dateFrom">Date From</label>
                <input class="filter-input" type="date" id="dateFrom" name="date_from" value="<?php echo e($dateFrom); ?>">
            </div>
            <div class="filter-field">
                <label for="dateTo">Date To</label>
                <input class="filter-input" type="date" id="dateTo" name="date_to" value="<?php echo e($dateTo); ?>">
            </div>
        </div>
        <div class="filter-footer">
            <div class="quick-dates">
                <button class="quick-date" type="button" data-range="week">This Week</button>
                <button class="quick-date" type="button" data-range="month">This Month</button>
                <button class="quick-date" type="button" data-range="year">This Year</button>
            </div>
            <div class="filter-actions">
                <a class="clear-btn" href="dean_reports.php?tab=<?php echo e($activeTab); ?>">Clear</a>
                <button class="apply-btn" type="submit">Apply Filters</button>
            </div>
        </div>
    </form>

    <?php if ($activeTab === 'complaints'): ?>
        <section class="summary-grid">
            <article class="card metric"><i class='bx bx-file metric-icon'></i><strong><?php echo (int)$totalComplaints; ?></strong><span>Total Complaints</span><em>College-wide</em></article>
            <article class="card metric"><i class='bx bx-check-circle metric-icon'></i><strong><?php echo (int)($complaintStatusCounts['resolved'] ?? 0); ?></strong><span>Resolved</span><em><?php echo $totalComplaints > 0 ? round((($complaintStatusCounts['resolved'] ?? 0) / $totalComplaints) * 100) : 0; ?>% of results</em></article>
            <article class="card metric"><i class='bx bx-time-five metric-icon'></i><strong><?php echo (int)($complaintStatusCounts['under_review'] ?? 0); ?></strong><span>Under Review</span><em><?php echo $totalComplaints > 0 ? round((($complaintStatusCounts['under_review'] ?? 0) / $totalComplaints) * 100) : 0; ?>% of results</em></article>
            <article class="card metric"><i class='bx bx-hourglass metric-icon'></i><strong><?php echo (int)($complaintStatusCounts['pending'] ?? 0); ?></strong><span>Pending</span><em><?php echo $totalComplaints > 0 ? round((($complaintStatusCounts['pending'] ?? 0) / $totalComplaints) * 100) : 0; ?>% of results</em></article>
        </section>

        <section class="analytics-grid">
            <article class="card chart-card">
                <h2>Top Complaint Categories</h2>
                <div class="category-chart-wrap">
                    <?php if ($complaintCategories): ?>
                        <canvas id="complaintCategoryChart" aria-label="Top complaint categories"></canvas>
                    <?php else: ?>
                        <div class="empty">No complaint data for the selected filters.</div>
                    <?php endif; ?>
                </div>
            </article>
            <article class="card ai-card">
                <span class="ai-badge"><i class='bx bx-sparkles'></i> AI SUMMARY</span>
                <h2>AI Summary</h2>
                <p><?php echo e($aiSummary); ?></p>
                <p class="ai-note">AI-generated summary for this dean-level college view. Verify against the filtered records.</p>
            </article>
        </section>

        <section class="card table-card">
            <h2>Complaint Reports</h2>
            <div class="table-scroll">
                <table class="report-table cols-8">
                    <thead>
                        <tr>
                            <th>Date Submitted</th>
                            <th>Complaint</th>
                            <th>Category</th>
                            <th>Department</th>
                            <th>School Year</th>
                            <th>Semester</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$complaints): ?>
                            <tr><td class="empty" colspan="8">No complaints match the selected filters.</td></tr>
                        <?php else: ?>
                            <?php foreach ($complaints as $complaint): ?>
                                <tr>
                                    <td data-label="Date Submitted"><?php echo e(date('M j, Y', strtotime((string)$complaint['created_at']))); ?></td>
                                    <td class="subject"><?php echo e($complaint['act_complained_of'] ?? 'Complaint'); ?><small><?php echo e($complaint['narrative_report'] ?? ''); ?></small></td>
                                    <td data-label="Category"><?php echo e($complaint['category_name'] ?? 'Uncategorized'); ?></td>
                                    <td data-label="Department"><?php echo e($complaint['department_name'] ?? 'Not assigned'); ?></td>
                                    <td data-label="School Year"><?php echo e($complaint['school_year'] ?: 'N/A'); ?></td>
                                    <td data-label="Semester"><?php echo e(dean_report_semester_label($complaint['semester'] ?? null)); ?></td>
                                    <td data-label="Status"><span class="status-pill <?php echo e($complaint['status'] ?? 'new'); ?>"><?php echo e(dean_report_status_label((string)($complaint['status'] ?? 'new'))); ?></span></td>
                                    <td class="td-action"><a class="action-link" href="dean_ticket_detail.php?id=<?php echo (int)$complaint['id']; ?>">View</a></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php else: ?>
        <section class="summary-grid">
            <article class="card metric"><i class='bx bx-bulb metric-icon'></i><strong><?php echo (int)$totalSuggestions; ?></strong><span>Total Suggestions</span><em>College-wide</em></article>
            <article class="card metric"><i class='bx bx-check-circle metric-icon'></i><strong><?php echo (int)($suggestionStatusCounts['implemented'] ?? 0); ?></strong><span>Implemented</span><em><?php echo $totalSuggestions > 0 ? round((($suggestionStatusCounts['implemented'] ?? 0) / $totalSuggestions) * 100) : 0; ?>% of results</em></article>
            <article class="card metric"><i class='bx bx-time-five metric-icon'></i><strong><?php echo (int)($suggestionStatusCounts['under_review'] ?? 0); ?></strong><span>Under Review</span><em><?php echo $totalSuggestions > 0 ? round((($suggestionStatusCounts['under_review'] ?? 0) / $totalSuggestions) * 100) : 0; ?>% of results</em></article>
            <article class="card metric"><i class='bx bx-hourglass metric-icon'></i><strong><?php echo (int)($suggestionStatusCounts['new'] ?? 0); ?></strong><span>Pending</span><em><?php echo $totalSuggestions > 0 ? round((($suggestionStatusCounts['new'] ?? 0) / $totalSuggestions) * 100) : 0; ?>% of results</em></article>
        </section>

        <section class="analytics-grid">
            <article class="card chart-card">
                <h2>Suggestions by Category</h2>
                <div class="category-chart-wrap">
                    <?php if ($suggestionCategories): ?>
                        <canvas id="suggestionCategoryChart" aria-label="Suggestion categories"></canvas>
                    <?php else: ?>
                        <div class="empty">No suggestion data for the selected filters.</div>
                    <?php endif; ?>
                </div>
            </article>
            <article class="card ai-card">
                <span class="ai-badge"><i class='bx bx-sparkles'></i> AI SUMMARY</span>
                <h2>AI Summary</h2>
                <p><?php echo e($aiSummary); ?></p>
                <p class="ai-note">AI-generated summary for this dean-level college view. Verify against the filtered records.</p>
            </article>
        </section>

        <section class="card table-card">
            <h2>Suggestions</h2>
            <div class="table-scroll">
                <table class="report-table cols-9">
                    <thead>
                        <tr>
                            <th>Date Submitted</th>
                            <th>Suggestion</th>
                            <th>Category</th>
                            <th>Department</th>
                            <th>Office</th>
                            <th>School Year</th>
                            <th>Semester</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$suggestions): ?>
                            <tr><td class="empty" colspan="9">No suggestions match the selected filters.</td></tr>
                        <?php else: ?>
                            <?php foreach ($suggestions as $suggestion): ?>
                                <tr>
                                    <td data-label="Date Submitted"><?php echo e(date('M j, Y', strtotime((string)$suggestion['created_at']))); ?></td>
                                    <td class="subject"><?php echo e($suggestion['subject'] ?? 'Suggestion'); ?><small><?php echo e($suggestion['description'] ?? ''); ?></small></td>
                                    <td data-label="Category"><?php echo e($suggestion['category_name'] ?? 'Uncategorized'); ?></td>
                                    <td data-label="Department"><?php echo e($suggestion['department_name'] ?? 'Not assigned'); ?></td>
                                    <td data-label="Office"><?php echo e($suggestion['office'] ?: 'Unassigned'); ?></td>
                                    <td data-label="School Year"><?php echo e($suggestion['school_year'] ?: 'N/A'); ?></td>
                                    <td data-label="Semester"><?php echo e(dean_report_semester_label($suggestion['semester'] ?? null)); ?></td>
                                    <td data-label="Status"><span class="status-pill <?php echo e($suggestion['status'] ?? 'new'); ?>"><?php echo e(dean_report_status_label((string)($suggestion['status'] ?? 'new'))); ?></span></td>
                                    <td class="td-action"><a class="action-link" href="dean_suggestion_detail.php?id=<?php echo (int)$suggestion['id']; ?>">View</a></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
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
    const cardFilter = <?php echo json_encode($cardFilter); ?>;
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
        if (cardFilter) params.set('card_filter', cardFilter);
        params.set('ajax', '1');
        fetch(window.location.pathname + '?' + params.toString(), {headers: {'X-Requested-With': 'XMLHttpRequest'}})
            .then(r => r.text())
            .then(html => {
                if (tbody) tbody.innerHTML = html;
                bindStudentRows();
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

    function bindStudentRows() {
        if (!tbody) return;
        tbody.querySelectorAll('.report-student-row').forEach(function(row) {
            if (row.dataset.bound === '1') return;
            row.dataset.bound = '1';
            const openDetails = function(event) {
                if (event.target.closest('a, button, input, select, textarea')) return;
                const href = row.dataset.href;
                if (href) window.location.href = href;
            };
            row.addEventListener('click', openDetails);
            row.addEventListener('keydown', function(event) {
                if ((event.key === 'Enter' || event.key === ' ') && !event.target.closest('a, button, input, select, textarea')) {
                    event.preventDefault();
                    openDetails(event);
                }
            });
        });
    }

    bindStudentRows();
});
</script>
<script>
function createCategoryBarChart(canvasId, rows) {
    const canvas = document.getElementById(canvasId);
    if (!canvas || !window.Chart || !rows.length) return;

    new Chart(canvas, {
        type: 'bar',
        data: {
            labels: rows.map(row => row.label),
            datasets: [{
                data: rows.map(row => Number(row.total)),
                backgroundColor: '#7c3aed',
                borderRadius: 5,
                borderSkipped: false,
                barThickness: 19,
                maxBarThickness: 22,
            }],
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            animation: { duration: 500 },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: context => ' ' + context.parsed.x + ' records',
                    },
                },
            },
            scales: {
                x: {
                    beginAtZero: true,
                    ticks: { precision: 0, color: '#5f6368', font: { family: 'Poppins', size: 11 } },
                    grid: { color: '#e2e2e2', drawBorder: false },
                },
                y: {
                    ticks: { color: '#5f6368', font: { family: 'Poppins', size: 11 } },
                    grid: { color: '#e2e2e2', drawBorder: false },
                },
            },
        },
    });
}

const complaintCategoryRows = <?php echo json_encode(array_map(static function (array $row): array {
    return ['label' => (string)$row['label'], 'total' => (int)$row['total']];
}, $complaintCategories), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const suggestionCategoryRows = <?php echo json_encode(array_map(static function (array $row): array {
    return ['label' => (string)$row['label'], 'total' => (int)$row['total']];
}, $suggestionCategories), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

document.addEventListener('DOMContentLoaded', function(){
    createCategoryBarChart('complaintCategoryChart', complaintCategoryRows);
    createCategoryBarChart('suggestionCategoryChart', suggestionCategoryRows);

    const qInput = document.querySelector('input[name="q"]');
    const sortSelect = document.querySelector('.directory-toolbar select[name="sort"]');
    const tbody = document.querySelector('table tbody');
    const schoolYear = <?php echo json_encode($schoolYear); ?>;
    const semester = <?php echo json_encode($semester); ?>;
    const department = <?php echo json_encode($programId > 0 ? (string)$programId : ''); ?>;
    const dateFrom = <?php echo json_encode($dateFrom); ?>;
    const dateTo = <?php echo json_encode($dateTo); ?>;
    const cardFilter = <?php echo json_encode($cardFilter); ?>;
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
        if (cardFilter) params.set('card_filter', cardFilter);
        params.set('ajax', '1');
        fetch(window.location.pathname + '?' + params.toString(), {headers: {'X-Requested-With': 'XMLHttpRequest'}})
            .then(response => response.text())
            .then(html => {
                if (tbody) tbody.innerHTML = html;
                bindStudentRows();
            })
            .catch(error => console.error('Search error', error));
    }

    if (qInput) {
        qInput.addEventListener('input', function(){
            clearTimeout(timer);
            timer = setTimeout(doSearch, 300);
        });
    }
    if (sortSelect) {
        sortSelect.addEventListener('change', doSearch);
    }

    function bindStudentRows() {
        if (!tbody) return;
        tbody.querySelectorAll('.report-student-row').forEach(function(row) {
            if (row.dataset.bound === '1') return;
            row.dataset.bound = '1';
            const openDetails = function(event) {
                if (event.target.closest('a, button, input, select, textarea')) return;
                const href = row.dataset.href;
                if (href) window.location.href = href;
            };
            row.addEventListener('click', openDetails);
            row.addEventListener('keydown', function(event) {
                if ((event.key === 'Enter' || event.key === ' ') && !event.target.closest('a, button, input, select, textarea')) {
                    event.preventDefault();
                    openDetails(event);
                }
            });
        });
    }

    bindStudentRows();
});
</script>

<?php echo sy_smart_input_script(); ?>
