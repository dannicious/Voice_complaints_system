<?php
session_start();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../school_year_helpers.php';
require_once __DIR__ . '/../complaint_groq_helpers.php';

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0 || strtolower((string)($_SESSION['role'] ?? '')) !== 'admin') {
    header('Location: /complaint-system-main/admin/login.php');
    exit;
}

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function report_date_valid(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

function report_status_label(string $status): string
{
    return ucwords(str_replace('_', ' ', $status !== '' ? $status : 'new'));
}

function report_semester_label(?string $semester): string
{
    return $semester === '1' ? '1st Semester' : ($semester === '2' ? '2nd Semester' : 'N/A');
}

function report_groq_filters(PDO $pdo, string $query, string $type, array $colleges, array $departments, array $schoolYears, array $offices): array
{
    $config = function_exists('groq_load_config') ? groq_load_config() : null;
    if ($config === null || trim($query) === '') return [];
    $options = [
        'colleges' => array_map(static fn(array $row): array => ['id' => (int)$row['id'], 'name' => (string)$row['name']], $colleges),
        'departments' => array_map(static fn(array $row): array => ['id' => (int)$row['id'], 'name' => (string)$row['name']], $departments),
        'school_years' => array_values(array_map('strval', $schoolYears)),
        'offices' => array_values(array_map('strval', $offices)),
        'statuses' => $type === 'complaints' ? ['new', 'pending', 'under_review', 'resolved', 'closed'] : ['new', 'under_review', 'approved', 'implemented', 'reviewed', 'declined', 'rejected', 'accepted', 'not_feasible', 'needs_info', 'planned', 'in_progress'],
    ];
    $prompt = 'Interpret this admin report request into filters for ' . $type . ". Use only IDs and values from the supplied options. Return JSON only with keys college_id, department_id, school_year, semester, office, status, date_from, date_to, keyword. Use 0 or empty strings when unknown. semester must be 1 or 2. Dates must be YYYY-MM-DD. Do not invent options or records.\nOptions:\n" . json_encode($options) . "\nRequest:\n" . $query;
    $payload = ['model' => $config['model'], 'messages' => [['role' => 'user', 'content' => $prompt]], 'response_format' => ['type' => 'json_object'], 'temperature' => 0.1];
    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $config['api_key']], CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $config['timeout_seconds'], CURLOPT_CONNECTTIMEOUT => min(5, $config['timeout_seconds'])]);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($response === false || $httpCode !== 200) return [];
    $decoded = json_decode((string)$response, true);
    $content = $decoded['choices'][0]['message']['content'] ?? '';
    $filters = is_string($content) ? json_decode($content, true) : null;
    return is_array($filters) ? $filters : [];
}

function report_groq_summary(string $type, int $total, array $counts, string $topLabel, string $secondLabel): string
{
    $config = function_exists('groq_load_config') ? groq_load_config() : null;
    if ($config === null || $total === 0) return '';
    $prompt = 'Write one concise factual admin report summary for ' . $type . '. Use only these verified database facts: total=' . $total . ', status_counts=' . json_encode($counts) . ', top=' . $topLabel . ', second=' . $secondLabel . '. Do not add any number or fact not supplied. Return plain text only.';
    $payload = ['model' => $config['model'], 'messages' => [['role' => 'user', 'content' => $prompt]], 'temperature' => 0.1, 'max_tokens' => 120];
    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $config['api_key']], CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $config['timeout_seconds'], CURLOPT_CONNECTTIMEOUT => min(5, $config['timeout_seconds'])]);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($response === false || $httpCode !== 200) return '';
    $decoded = json_decode((string)$response, true);
    return trim((string)($decoded['choices'][0]['message']['content'] ?? ''));
}

$activeTab = ($_GET['tab'] ?? 'suggestions') === 'complaints' ? 'complaints' : 'suggestions';
$search = trim((string)($_GET['q'] ?? ''));
$collegeId = max(0, (int)($_GET['college'] ?? 0));
$departmentId = max(0, (int)($_GET['department'] ?? 0));
$schoolYear = trim((string)($_GET['school_year'] ?? ''));
$semester = in_array((string)($_GET['semester'] ?? ''), ['1', '2'], true) ? (string)$_GET['semester'] : '';
$office = trim((string)($_GET['office'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$range = in_array((string)($_GET['range'] ?? 'month'), ['week', 'month', 'year'], true) ? (string)$_GET['range'] : 'month';
if ($dateFrom !== '' && !report_date_valid($dateFrom)) $dateFrom = '';
if ($dateTo !== '' && !report_date_valid($dateTo)) $dateTo = '';
if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) [$dateFrom, $dateTo] = [$dateTo, $dateFrom];

$colleges = $pdo->query('SELECT id, name FROM colleges ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$departmentsStmt = $collegeId > 0
    ? $pdo->prepare('SELECT id, name FROM programs WHERE college_id = :college_id ORDER BY name')
    : $pdo->prepare('SELECT id, name FROM programs ORDER BY name');
$departmentsStmt->execute($collegeId > 0 ? [':college_id' => $collegeId] : []);
$departments = $departmentsStmt->fetchAll(PDO::FETCH_ASSOC);
$departmentIds = array_map(static fn(array $row): int => (int)$row['id'], $departments);
if ($departmentId > 0 && !in_array($departmentId, $departmentIds, true)) $departmentId = 0;

$schoolYears = $pdo->query("SELECT school_year FROM (SELECT DISTINCT school_year FROM suggestions WHERE school_year IS NOT NULL AND school_year <> '' UNION SELECT DISTINCT school_year FROM complaints WHERE school_year IS NOT NULL AND school_year <> '') years ORDER BY school_year DESC")->fetchAll(PDO::FETCH_COLUMN);
$currentSchoolYear = sy_current($pdo);
if ($currentSchoolYear !== '' && !in_array($currentSchoolYear, $schoolYears, true)) array_unshift($schoolYears, $currentSchoolYear);
if ($schoolYear !== '' && !in_array($schoolYear, $schoolYears, true)) $schoolYear = '';

$offices = $pdo->query("SELECT DISTINCT TRIM(office) AS office FROM suggestions WHERE office IS NOT NULL AND TRIM(office) <> '' ORDER BY office")->fetchAll(PDO::FETCH_COLUMN);
$allowedStatuses = ['new', 'under_review', 'approved', 'implemented', 'reviewed', 'declined', 'resolved', 'rejected', 'accepted', 'not_feasible', 'needs_info', 'planned', 'in_progress'];
if ($status !== '' && !in_array($status, array_merge($allowedStatuses, ['pending', 'closed']), true)) $status = '';

if ((int)($_GET['ai_search'] ?? 0) === 1 && $search !== '') {
    $interpreted = report_groq_filters($pdo, $search, $activeTab, $colleges, $departments, $schoolYears, $offices);
    $interpretedCollege = (int)($interpreted['college_id'] ?? 0);
    $interpretedDepartment = (int)($interpreted['department_id'] ?? 0);
    if ($interpretedCollege > 0 && in_array($interpretedCollege, array_map(static fn(array $row): int => (int)$row['id'], $colleges), true)) $collegeId = $interpretedCollege;
    if ($interpretedDepartment > 0 && in_array($interpretedDepartment, array_map(static fn(array $row): int => (int)$row['id'], $departments), true)) $departmentId = $interpretedDepartment;
    if (is_string($interpreted['school_year'] ?? null) && in_array($interpreted['school_year'], $schoolYears, true)) $schoolYear = $interpreted['school_year'];
    if (in_array((string)($interpreted['semester'] ?? ''), ['1', '2'], true)) $semester = (string)$interpreted['semester'];
    if ($activeTab === 'suggestions' && is_string($interpreted['office'] ?? null) && in_array($interpreted['office'], $offices, true)) $office = $interpreted['office'];
    if (is_string($interpreted['status'] ?? null) && in_array($interpreted['status'], array_merge($allowedStatuses, ['pending', 'closed']), true)) $status = $interpreted['status'];
    if (report_date_valid((string)($interpreted['date_from'] ?? ''))) $dateFrom = (string)$interpreted['date_from'];
    if (report_date_valid((string)($interpreted['date_to'] ?? ''))) $dateTo = (string)$interpreted['date_to'];
    if (trim((string)($interpreted['keyword'] ?? '')) !== '') $search = trim((string)$interpreted['keyword']);
}

$where = ['1=1'];
$params = [];
if ($collegeId > 0) { $where[] = 's.college_id = :college_id'; $params[':college_id'] = $collegeId; }
if ($departmentId > 0) { $where[] = 'sp.program_id = :department_id'; $params[':department_id'] = $departmentId; }
if ($schoolYear !== '') { $where[] = 's.school_year = :school_year'; $params[':school_year'] = $schoolYear; }
if ($semester !== '') { $where[] = 's.semester = :semester'; $params[':semester'] = $semester; }
if ($office !== '') { $where[] = 's.office = :office'; $params[':office'] = $office; }
if ($status !== '') { $where[] = 's.status = :status'; $params[':status'] = $status; }
if ($dateFrom !== '') { $where[] = 's.created_at >= :date_from'; $params[':date_from'] = $dateFrom . ' 00:00:00'; }
if ($dateTo !== '') { $where[] = 's.created_at < :date_to'; $params[':date_to'] = (new DateTimeImmutable($dateTo))->modify('+1 day')->format('Y-m-d 00:00:00'); }
if ($search !== '') {
    $where[] = '(s.subject LIKE :q_subject OR s.description LIKE :q_description OR s.office LIKE :q_office OR sc.name LIKE :q_category OR c.name LIKE :q_college OR p.name LIKE :q_department)';
    $searchLike = '%' . $search . '%';
    foreach (['subject', 'description', 'office', 'category', 'college', 'department'] as $key) $params[':q_' . $key] = $searchLike;
}
$whereSql = implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM suggestions s LEFT JOIN suggestion_categories sc ON sc.id = s.category_id LEFT JOIN colleges c ON c.id = s.college_id LEFT JOIN student_profiles sp ON sp.id = s.student_id LEFT JOIN programs p ON p.id = sp.program_id WHERE {$whereSql}");
$countStmt->execute($params);
$totalSuggestions = (int)$countStmt->fetchColumn();

$metricStmt = $pdo->prepare("SELECT s.status, COUNT(*) AS total FROM suggestions s LEFT JOIN suggestion_categories sc ON sc.id = s.category_id LEFT JOIN colleges c ON c.id = s.college_id LEFT JOIN student_profiles sp ON sp.id = s.student_id LEFT JOIN programs p ON p.id = sp.program_id WHERE {$whereSql} GROUP BY s.status");
$metricStmt->execute($params);
$statusCounts = array_fill_keys($allowedStatuses, 0);
foreach ($metricStmt->fetchAll(PDO::FETCH_ASSOC) as $row) $statusCounts[(string)$row['status']] = (int)$row['total'];
$implemented = $statusCounts['implemented'];
$underReview = $statusCounts['under_review'];
$pending = $statusCounts['new'];
$implementedPercent = $totalSuggestions > 0 ? round($implemented / $totalSuggestions * 100) : 0;
$underReviewPercent = $totalSuggestions > 0 ? round($underReview / $totalSuggestions * 100) : 0;
$pendingPercent = $totalSuggestions > 0 ? round($pending / $totalSuggestions * 100) : 0;

$rowLimit = ($_GET['export'] ?? '') === 'csv' ? '' : ' LIMIT 100';
$rowsStmt = $pdo->prepare("SELECT s.id, s.subject, s.description, s.created_at, s.school_year, s.semester, s.status, s.office, COALESCE(sc.name, 'Uncategorized') AS category_name, COALESCE(c.name, 'Not assigned') AS college_name, COALESCE(p.name, 'Not assigned') AS department_name FROM suggestions s LEFT JOIN suggestion_categories sc ON sc.id = s.category_id LEFT JOIN colleges c ON c.id = s.college_id LEFT JOIN student_profiles sp ON sp.id = s.student_id LEFT JOIN programs p ON p.id = sp.program_id WHERE {$whereSql} ORDER BY s.created_at DESC, s.id DESC{$rowLimit}");
$rowsStmt->execute($params);
$suggestions = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

$categoryStmt = $pdo->prepare("SELECT COALESCE(sc.name, 'Uncategorized') AS label, COUNT(*) AS total FROM suggestions s LEFT JOIN suggestion_categories sc ON sc.id = s.category_id LEFT JOIN colleges c ON c.id = s.college_id LEFT JOIN student_profiles sp ON sp.id = s.student_id LEFT JOIN programs p ON p.id = sp.program_id WHERE {$whereSql} GROUP BY label ORDER BY total DESC LIMIT 8");
$categoryStmt->execute($params);
$categories = $categoryStmt->fetchAll(PDO::FETCH_ASSOC);

$officeStmt = $pdo->prepare("SELECT COALESCE(NULLIF(TRIM(s.office), ''), 'Unassigned') AS label, COUNT(*) AS total FROM suggestions s LEFT JOIN suggestion_categories sc ON sc.id = s.category_id LEFT JOIN colleges c ON c.id = s.college_id LEFT JOIN student_profiles sp ON sp.id = s.student_id LEFT JOIN programs p ON p.id = sp.program_id WHERE {$whereSql} GROUP BY label ORDER BY total DESC LIMIT 6");
$officeStmt->execute($params);
$officeCounts = $officeStmt->fetchAll(PDO::FETCH_ASSOC);

$trendExpression = $range === 'week' ? "DATE_FORMAT(s.created_at, '%x-W%v')" : ($range === 'year' ? "DATE_FORMAT(s.created_at, '%Y')" : "DATE_FORMAT(s.created_at, '%Y-%m')");
$trendStmt = $pdo->prepare("SELECT {$trendExpression} AS period, COUNT(*) AS total FROM suggestions s LEFT JOIN suggestion_categories sc ON sc.id = s.category_id LEFT JOIN colleges c ON c.id = s.college_id LEFT JOIN student_profiles sp ON sp.id = s.student_id LEFT JOIN programs p ON p.id = sp.program_id WHERE {$whereSql} GROUP BY period ORDER BY period DESC LIMIT 24");
$trendStmt->execute($params);
$trendRows = array_reverse($trendStmt->fetchAll(PDO::FETCH_ASSOC));

$topCategory = $categories[0]['label'] ?? 'No category yet';
$topOffice = $officeCounts[0]['label'] ?? 'No office yet';
$aiSummary = $totalSuggestions === 0
    ? 'No suggestions match the selected filters.'
    : $totalSuggestions . ' ' . ($totalSuggestions === 1 ? 'suggestion was' : 'suggestions were') . ' found. Most suggestions were related to ' . $topCategory . ', with ' . $topOffice . ' receiving the most.';

$filterQuery = $_GET;
$filterQuery['tab'] = 'suggestions';
$filterQuery['date_from'] = $dateFrom;
$filterQuery['date_to'] = $dateTo;
$filterQuery = array_filter($filterQuery, static fn($value): bool => $value !== '' && $value !== null);
$exportQuery = array_filter(['tab' => $activeTab, 'q' => $search, 'college' => $collegeId ?: '', 'department' => $departmentId ?: '', 'school_year' => $schoolYear, 'semester' => $semester, 'office' => $office, 'status' => $status, 'range' => $range, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'export' => 'csv'], static fn($value): bool => $value !== '' && $value !== 0 && $value !== null);
$exportUrl = '?' . http_build_query($exportQuery);
$tabUrl = static function (string $tab) use ($filterQuery): string {
    $query = $filterQuery;
    $query['tab'] = $tab;
    return '?' . http_build_query($query);
};

$complaintWhere = ['1=1'];
$complaintParams = [];
if ($collegeId > 0) { $complaintWhere[] = 'c.college_id = :complaint_college_id'; $complaintParams[':complaint_college_id'] = $collegeId; }
if ($departmentId > 0) { $complaintWhere[] = 'sp.program_id = :complaint_department_id'; $complaintParams[':complaint_department_id'] = $departmentId; }
if ($schoolYear !== '') { $complaintWhere[] = 'c.school_year = :complaint_school_year'; $complaintParams[':complaint_school_year'] = $schoolYear; }
if ($semester !== '') { $complaintWhere[] = 'c.semester = :complaint_semester'; $complaintParams[':complaint_semester'] = $semester; }
if ($status !== '' && in_array($status, ['new', 'pending', 'under_review', 'resolved', 'closed'], true)) { $complaintWhere[] = 'c.status = :complaint_status'; $complaintParams[':complaint_status'] = $status; }
if ($dateFrom !== '') { $complaintWhere[] = 'c.created_at >= :complaint_date_from'; $complaintParams[':complaint_date_from'] = $dateFrom . ' 00:00:00'; }
if ($dateTo !== '') { $complaintWhere[] = 'c.created_at < :complaint_date_to'; $complaintParams[':complaint_date_to'] = (new DateTimeImmutable($dateTo))->modify('+1 day')->format('Y-m-d 00:00:00'); }
if ($search !== '') {
    $complaintWhere[] = '(c.narrative_report LIKE :complaint_q_narrative OR c.act_complained_of LIKE :complaint_q_act OR cc.name LIKE :complaint_q_category OR college.name LIKE :complaint_q_college OR p.name LIKE :complaint_q_department)';
    $complaintSearchLike = '%' . $search . '%';
    foreach (['narrative', 'act', 'category', 'college', 'department'] as $key) $complaintParams[':complaint_q_' . $key] = $complaintSearchLike;
}
$complaintWhereSql = implode(' AND ', $complaintWhere);
$complaintBaseJoins = ' FROM complaints c LEFT JOIN complaint_categories cc ON cc.id = c.category_id LEFT JOIN colleges college ON college.id = c.college_id LEFT JOIN student_profiles sp ON sp.id = c.student_id LEFT JOIN programs p ON p.id = sp.program_id';
$complaintCountStmt = $pdo->prepare("SELECT COUNT(*)" . $complaintBaseJoins . " WHERE {$complaintWhereSql}");
$complaintCountStmt->execute($complaintParams);
$totalComplaints = (int)$complaintCountStmt->fetchColumn();
$complaintStatusStmt = $pdo->prepare("SELECT c.status, COUNT(*) AS total" . $complaintBaseJoins . " WHERE {$complaintWhereSql} GROUP BY c.status");
$complaintStatusStmt->execute($complaintParams);
$complaintStatusCounts = ['new' => 0, 'pending' => 0, 'under_review' => 0, 'resolved' => 0, 'closed' => 0];
foreach ($complaintStatusStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if (array_key_exists((string)$row['status'], $complaintStatusCounts)) $complaintStatusCounts[(string)$row['status']] = (int)$row['total'];
}
$complaintRowsStmt = $pdo->prepare("SELECT c.id, c.created_at, c.narrative_report, c.act_complained_of, c.school_year, c.semester, c.status, COALESCE(cc.name, 'Uncategorized') AS category_name, COALESCE(college.name, 'Not assigned') AS college_name, COALESCE(p.name, 'Not assigned') AS department_name" . $complaintBaseJoins . " WHERE {$complaintWhereSql} ORDER BY c.created_at DESC, c.id DESC{$rowLimit}");
$complaintRowsStmt->execute($complaintParams);
$complaints = $complaintRowsStmt->fetchAll(PDO::FETCH_ASSOC);
$complaintCategoryStmt = $pdo->prepare("SELECT COALESCE(cc.name, 'Uncategorized') AS label, COUNT(*) AS total" . $complaintBaseJoins . " WHERE {$complaintWhereSql} GROUP BY label ORDER BY total DESC LIMIT 8");
$complaintCategoryStmt->execute($complaintParams);
$complaintCategories = $complaintCategoryStmt->fetchAll(PDO::FETCH_ASSOC);
$complaintOrganizationStmt = $pdo->prepare("SELECT CONCAT('College: ', COALESCE(college.name, 'Unassigned')) AS label, COUNT(*) AS total" . $complaintBaseJoins . " WHERE {$complaintWhereSql} GROUP BY college.id, college.name");
$complaintOrganizationStmt->execute($complaintParams);
$complaintOrganizations = $complaintOrganizationStmt->fetchAll(PDO::FETCH_ASSOC);
$complaintDepartmentStmt = $pdo->prepare("SELECT CONCAT('Department: ', COALESCE(p.name, 'Unassigned')) AS label, COUNT(*) AS total" . $complaintBaseJoins . " WHERE {$complaintWhereSql} GROUP BY p.id, p.name");
$complaintDepartmentStmt->execute($complaintParams);
$complaintOrganizations = array_merge($complaintOrganizations, $complaintDepartmentStmt->fetchAll(PDO::FETCH_ASSOC));
usort($complaintOrganizations, static fn(array $a, array $b): int => (int)$b['total'] <=> (int)$a['total']);
$complaintOrganizations = array_slice($complaintOrganizations, 0, 8);
$complaintTrendExpression = $range === 'week' ? "DATE_FORMAT(c.created_at, '%x-W%v')" : ($range === 'year' ? "DATE_FORMAT(c.created_at, '%Y')" : "DATE_FORMAT(c.created_at, '%Y-%m')");
$complaintTrendStmt = $pdo->prepare("SELECT {$complaintTrendExpression} AS period, COUNT(*) AS total" . $complaintBaseJoins . " WHERE {$complaintWhereSql} GROUP BY period ORDER BY period DESC LIMIT 24");
$complaintTrendStmt->execute($complaintParams);
$complaintTrendRows = array_reverse($complaintTrendStmt->fetchAll(PDO::FETCH_ASSOC));
$complaintPending = $complaintStatusCounts['new'] + $complaintStatusCounts['pending'];

$summaryType = $activeTab === 'complaints' ? 'complaints' : 'suggestions';
$summaryCounts = $activeTab === 'complaints' ? $complaintStatusCounts : ['implemented' => $implemented, 'under_review' => $underReview, 'pending' => $pending];
$summaryLabels = $activeTab === 'complaints' ? $complaintCategories : $categories;
$summaryTop = $summaryLabels[0]['label'] ?? 'No category yet';
$summarySecond = $summaryLabels[1]['label'] ?? 'No second category';
$aiSummaryFromGroq = report_groq_summary($summaryType, $activeTab === 'complaints' ? $totalComplaints : $totalSuggestions, $summaryCounts, $summaryTop, $summarySecond);
if ($activeTab === 'complaints') {
    $aiSummary = $totalComplaints === 0 ? 'No complaints match the selected filters.' : $totalComplaints . ' complaints were found. Most were categorized as ' . $summaryTop . ', followed by ' . $summarySecond . '.';
} else {
    $aiSummary = $totalSuggestions === 0 ? 'No suggestions match the selected filters.' : $totalSuggestions . ' suggestions were found. Most were categorized as ' . $summaryTop . ', followed by ' . $summarySecond . '.';
}
if ($aiSummaryFromGroq !== '') $aiSummary = $aiSummaryFromGroq;

if (($_GET['export'] ?? '') === 'csv') {
    $exportRows = $activeTab === 'complaints' ? $complaints : $suggestions;
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $summaryType . '-report-' . date('Y-m-d') . '.csv');
    $output = fopen('php://output', 'w');
    if ($activeTab === 'complaints') {
        fputcsv($output, ['Date Submitted', 'Complaint', 'Category', 'College', 'Department', 'School Year', 'Semester', 'Status']);
        foreach ($exportRows as $row) fputcsv($output, [$row['created_at'], $row['act_complained_of'] ?: 'Complaint', $row['category_name'], $row['college_name'], $row['department_name'], $row['school_year'], report_semester_label($row['semester']), report_status_label($row['status'])]);
    } else {
        fputcsv($output, ['Date Submitted', 'Suggestion', 'Category', 'College', 'Department', 'Office', 'School Year', 'Semester', 'Status']);
        foreach ($exportRows as $row) fputcsv($output, [$row['created_at'], $row['subject'], $row['category_name'], $row['college_name'], $row['department_name'], $row['office'], $row['school_year'], report_semester_label($row['semester']), report_status_label($row['status'])]);
    }
    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reports - VOICE</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<style>
* { box-sizing: border-box; font-family: 'Poppins', sans-serif; }
body { margin: 0; background: #f4f6fb; color: #1f2937; }
.main { margin-left: 260px; margin-top: 61px; padding: 25px; min-height: calc(100vh - 61px); }
.shell { max-width: 1240px; margin: 0 auto; }
.page-header { margin-bottom: 20px; }
.page-header h1 { margin: 0; font-size: 25px; font-weight: 600; color: #262626; }
.subtitle { margin: 5px 0 0; color: #778197; font-size: 13px; }
.tabs { display: flex; gap: 4px; border-bottom: 1px solid #e5e7eb; margin-bottom: 16px; }
.tab { padding: 11px 16px; color: #778197; text-decoration: none; font-size: 13px; font-weight: 600; border-bottom: 3px solid transparent; }
.tab:hover, .tab.active { color: #6d28d9; border-bottom-color: #6d28d9; }
.card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; box-shadow: 0 4px 15px rgba(15,23,42,.04); }
.search-card { padding: 16px; margin-bottom: 14px; }
.section-label { display: block; color: #374151; font-size: 12px; font-weight: 700; margin-bottom: 8px; }
.search-row { display: flex; gap: 10px; }
.search-row input { flex: 1; min-width: 0; height: 40px; border: 1px solid #d8dce5; border-radius: 8px; padding: 0 13px; color: #1f2937; font-size: 13px; outline: none; }
.search-row input:focus, select:focus { border-color: #8b5cf6; box-shadow: 0 0 0 3px rgba(139,92,246,.12); }
.primary-btn { border: 0; border-radius: 8px; background: #6d28d9; color: #fff; padding: 0 17px; height: 40px; font-weight: 600; font-size: 12.5px; cursor: pointer; white-space: nowrap; }
.primary-btn:hover { background: #5b21b6; }
.filters { padding: 16px; margin-bottom: 14px; }
.filter-grid { display: grid; grid-template-columns: repeat(6, minmax(125px, 1fr)); gap: 10px; }
.filter-field { min-width: 0; }
.filter-field label { display: block; margin-bottom: 5px; color: #6b7280; font-size: 11px; font-weight: 600; }
.filter-field select, .filter-input { width: 100%; height: 36px; border: 1px solid #d8dce5; border-radius: 7px; padding: 0 9px; background: #fff; color: #374151; font-size: 12px; }
.filter-footer { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-top: 13px; flex-wrap: wrap; }
.quick-dates { display: flex; gap: 7px; flex-wrap: wrap; }
.quick-date { border: 1px solid #ddd6fe; color: #6d28d9; background: #faf9ff; border-radius: 7px; padding: 7px 10px; font-size: 11px; font-weight: 600; cursor: pointer; }
.quick-date:hover { background: #ede9fe; }
.filter-actions { display: flex; gap: 7px; }
.apply-btn, .clear-btn { display: inline-flex; align-items: center; justify-content: center; height: 34px; padding: 0 13px; border-radius: 7px; font-size: 12px; font-weight: 600; text-decoration: none; cursor: pointer; }
.apply-btn { border: 0; background: #6d28d9; color: #fff; }
.clear-btn { border: 1px solid #d8dce5; background: #fff; color: #6b7280; }
.export-btn { display: inline-flex; align-items: center; gap: 5px; border: 1px solid #ddd6fe; background: #faf9ff; color: #6d28d9; }
.summary-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 14px; }
.metric { padding: 16px; position: relative; overflow: hidden; }
.metric:after { content: ''; position: absolute; width: 52px; height: 52px; border-radius: 50%; right: -15px; top: -15px; background: #f0eafe; }
.metric-icon { color: #6d28d9; font-size: 21px; margin-bottom: 10px; }
.metric strong { display: block; font-size: 24px; line-height: 1; color: #252525; }
.metric span { display: block; color: #737b8c; font-size: 11.5px; margin-top: 7px; }
.metric em { display: block; color: #6d28d9; font-size: 11px; font-style: normal; font-weight: 600; margin-top: 3px; }
.analytics-grid { display: grid; grid-template-columns: minmax(0, 1.6fr) minmax(260px, .8fr); gap: 14px; margin-bottom: 14px; }
.chart-card { padding: 16px; min-height: 280px; }
.chart-card h2, .ai-card h2, .table-card h2 { margin: 0 0 13px; color: #30343b; font-size: 14px; font-weight: 600; }
.chart-wrap { height: 215px; position: relative; }
.office-card { padding: 16px; margin-bottom: 14px; }
.office-item { display: grid; grid-template-columns: minmax(120px, 1fr) 2fr 30px; align-items: center; gap: 10px; margin: 13px 0; font-size: 11.5px; color: #52627a; }
.bar { height: 8px; background: #ede9fe; border-radius: 5px; overflow: hidden; }
.bar span { display: block; height: 100%; background: #7c3aed; border-radius: 5px; }
.office-item strong { font-size: 11px; color: #374151; text-align: right; }
.ai-card { padding: 18px; border-top: 3px solid #7c3aed; }
.ai-badge { display: inline-flex; align-items: center; gap: 5px; color: #6d28d9; background: #f3efff; border-radius: 99px; padding: 5px 8px; font-size: 10px; font-weight: 700; margin-bottom: 11px; }
.ai-card p { margin: 0; color: #5f6878; font-size: 12px; line-height: 1.7; }
.ai-note { margin-top: 14px !important; padding-top: 12px; border-top: 1px solid #eeeaf8; color: #8b93a3 !important; font-size: 10.5px !important; }
.table-card { padding: 16px; overflow: hidden; }
.table-scroll { overflow-x: auto; }
table { width: 100%; min-width: 1030px; border-collapse: collapse; }
th { padding: 10px 9px; border-bottom: 2px solid #f0f1f3; color: #798294; text-align: left; font-size: 10px; text-transform: uppercase; white-space: nowrap; }
td { padding: 11px 9px; border-bottom: 1px solid #f1f3f6; color: #4b5563; font-size: 11.5px; vertical-align: top; }
tbody tr:hover { background: #fbfaff; }
.subject { max-width: 210px; color: #30343b; font-weight: 600; }
.subject small { display: block; overflow: hidden; color: #8a93a2; font-size: 10px; font-weight: 400; text-overflow: ellipsis; white-space: nowrap; }
.status-pill { display: inline-block; padding: 4px 8px; border-radius: 6px; background: #f3f4f6; color: #596273; font-size: 10px; font-weight: 600; white-space: nowrap; }
.status-pill.implemented { background: #dcfce7; color: #166534; }
.status-pill.under_review { background: #fef3c7; color: #92400e; }
.status-pill.new { background: #dbeafe; color: #1e40af; }
.action-link { color: #6d28d9; text-decoration: none; font-weight: 600; white-space: nowrap; }
.empty { padding: 28px; color: #8b93a3; text-align: center; font-size: 12px; }
.complaint-note { padding: 25px; color: #697386; font-size: 13px; line-height: 1.7; }
@media (max-width: 1050px) { .filter-grid { grid-template-columns: repeat(3, minmax(150px, 1fr)); } .analytics-grid { grid-template-columns: 1fr; } }
@media (max-width: 700px) { .main { margin-left: 0; padding: 16px; } .summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } .filter-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } .search-row { flex-direction: column; } .primary-btn { width: 100%; } }
@media (max-width: 430px) { .filter-grid { grid-template-columns: 1fr; } .summary-grid { gap: 8px; } .metric { padding: 12px; } }
</style>
</head>
<body>
<?php include 'admin_topbar.php'; ?>
<?php include 'admin_sidebar.php'; ?>
<main class="main"><div class="shell">
    <header class="page-header">
        <h1>Reports</h1>
        <p class="subtitle">View and analyze complaints and suggestions with smart filters, insights, and AI-powered summaries.</p>
    </header>

    <nav class="tabs" aria-label="Report types">
        <a class="tab <?= $activeTab === 'complaints' ? 'active' : '' ?>" href="<?= e($tabUrl('complaints')) ?>">Complaint Reports</a>
        <a class="tab <?= $activeTab === 'suggestions' ? 'active' : '' ?>" href="<?= e($tabUrl('suggestions')) ?>">Suggestion Reports</a>
    </nav>

    <form class="card search-card" method="get">
        <input type="hidden" name="tab" value="<?= e($activeTab) ?>"><input type="hidden" name="ai_search" value="1">
        <label class="section-label" for="aiSearch">Ask Reports - Powered by Groq</label>
        <div class="search-row"><input id="aiSearch" name="q" value="<?= e($search) ?>" placeholder="Ask about <?= $activeTab === 'complaints' ? 'complaints (keyword, category, college, department, school year, semester, date, etc.)' : 'suggestions (keyword, category, college, department, school year, semester, office, status, etc.)' ?>"><button class="primary-btn" type="submit"><i class="bx bx-sparkles"></i> Generate Report</button></div>
    </form>

    <form class="card filters" method="get" id="reportFilters">
        <input type="hidden" name="tab" value="<?= e($activeTab) ?>"><input type="hidden" name="q" value="<?= e($search) ?>">
        <div class="filter-grid">
            <div class="filter-field"><label for="keywordFilter">Search / Keyword</label><input class="filter-input" id="keywordFilter" name="q" value="<?= e($search) ?>" placeholder="Search report text..."></div>
            <div class="filter-field"><label for="college">College</label><select id="college" name="college"><option value="0">All Colleges</option><?php foreach ($colleges as $college): ?><option value="<?= (int)$college['id'] ?>" <?= $collegeId === (int)$college['id'] ? 'selected' : '' ?>><?= e($college['name']) ?></option><?php endforeach; ?></select></div>
            <div class="filter-field"><label for="department">Department</label><select id="department" name="department"><option value="0">All Departments</option><?php foreach ($departments as $department): ?><option value="<?= (int)$department['id'] ?>" <?= $departmentId === (int)$department['id'] ? 'selected' : '' ?>><?= e($department['name']) ?></option><?php endforeach; ?></select></div>
            <div class="filter-field"><label for="schoolYear">School Year</label><select id="schoolYear" name="school_year"><option value="">All School Years</option><?php foreach ($schoolYears as $year): ?><option value="<?= e($year) ?>" <?= $schoolYear === $year ? 'selected' : '' ?>><?= e($year) ?></option><?php endforeach; ?></select></div>
            <div class="filter-field"><label for="semester">Semester</label><select id="semester" name="semester"><option value="">All Semesters</option><option value="1" <?= $semester === '1' ? 'selected' : '' ?>>1st Semester</option><option value="2" <?= $semester === '2' ? 'selected' : '' ?>>2nd Semester</option></select></div>
            <?php if ($activeTab === 'suggestions'): ?><div class="filter-field"><label for="office">Office</label><select id="office" name="office"><option value="">All Offices</option><?php foreach ($offices as $officeOption): ?><option value="<?= e($officeOption) ?>" <?= $office === $officeOption ? 'selected' : '' ?>><?= e($officeOption) ?></option><?php endforeach; ?></select></div><?php endif; ?>
            <div class="filter-field"><label for="status">Status</label><select id="status" name="status"><option value="">All Statuses</option><?php foreach (($activeTab === 'complaints' ? ['new', 'pending', 'under_review', 'resolved', 'closed'] : $allowedStatuses) as $statusOption): ?><option value="<?= e($statusOption) ?>" <?= $status === $statusOption ? 'selected' : '' ?>><?= e(report_status_label($statusOption)) ?></option><?php endforeach; ?></select></div>
            <div class="filter-field"><label for="range">Trend Grouping</label><select id="range" name="range"><option value="week" <?= $range === 'week' ? 'selected' : '' ?>>Weekly</option><option value="month" <?= $range === 'month' ? 'selected' : '' ?>>Monthly</option><option value="year" <?= $range === 'year' ? 'selected' : '' ?>>Yearly</option></select></div>
            <div class="filter-field"><label for="dateFrom">Date From</label><input class="filter-input" type="date" id="dateFrom" name="date_from" value="<?= e($dateFrom) ?>"></div>
            <div class="filter-field"><label for="dateTo">Date To</label><input class="filter-input" type="date" id="dateTo" name="date_to" value="<?= e($dateTo) ?>"></div>
        </div>
        <div class="filter-footer"><div class="quick-dates"><button class="quick-date" type="button" data-range="week">This Week</button><button class="quick-date" type="button" data-range="month">This Month</button><button class="quick-date" type="button" data-range="year">This Year</button></div><div class="filter-actions"><a class="clear-btn" href="report.php?tab=<?= e($activeTab) ?>">Clear</a><a class="export-btn" href="<?= e($exportUrl) ?>"><i class="bx bx-download"></i> Export Report</a><button class="apply-btn" type="submit">Apply Filters</button></div></div>
    </form>

    <?php if ($activeTab === 'complaints'): ?>
        <section class="summary-grid">
            <article class="card metric"><i class="bx bx-file metric-icon"></i><strong><?= $totalComplaints ?></strong><span>Total Complaints</span><em>100% of results</em></article>
            <article class="card metric"><i class="bx bx-check-circle metric-icon"></i><strong><?= $complaintStatusCounts['resolved'] ?></strong><span>Resolved</span><em><?= $totalComplaints > 0 ? round($complaintStatusCounts['resolved'] / $totalComplaints * 100) : 0 ?>% of results</em></article>
            <article class="card metric"><i class="bx bx-time-five metric-icon"></i><strong><?= $complaintStatusCounts['under_review'] ?></strong><span>Under Review</span><em><?= $totalComplaints > 0 ? round($complaintStatusCounts['under_review'] / $totalComplaints * 100) : 0 ?>% of results</em></article>
            <article class="card metric"><i class="bx bx-hourglass metric-icon"></i><strong><?= $complaintPending ?></strong><span>Pending</span><em><?= $totalComplaints > 0 ? round($complaintPending / $totalComplaints * 100) : 0 ?>% of results</em></article>
        </section>
        <section class="analytics-grid">
            <article class="card chart-card"><h2>Top Complaint Categories</h2><div class="chart-wrap"><canvas id="complaintCategoryChart"></canvas></div></article>
            <article class="card ai-card"><span class="ai-badge"><i class="bx bx-sparkles"></i> AI SUMMARY</span><h2>AI Summary</h2><p><?= e($aiSummary) ?></p><p class="ai-note">AI-generated summary. Verify against the displayed results.</p></article>
        </section>
        <section class="analytics-grid">
            <article class="card chart-card"><h2>Complaints Over Time</h2><div class="chart-wrap"><canvas id="complaintTrendChart"></canvas></div></article>
            <article class="card chart-card"><h2>Top Colleges / Departments</h2><div class="chart-wrap"><canvas id="complaintOrganizationChart"></canvas></div></article>
        </section>
        <section class="card table-card">
            <h2>Complaint Reports</h2>
            <div class="table-scroll"><table><thead><tr><th>Date Submitted</th><th>Complaint</th><th>Category</th><th>College</th><th>Department</th><th>School Year</th><th>Semester</th><th>Status</th><th>Action</th></tr></thead><tbody>
            <?php if (!$complaints): ?><tr><td class="empty" colspan="9">No complaints match the selected filters.</td></tr><?php else: foreach ($complaints as $complaint): ?><tr><td><?= e(date('M j, Y', strtotime((string)$complaint['created_at']))) ?></td><td class="subject"><?= e($complaint['act_complained_of'] ?: 'Complaint') ?><small><?= e($complaint['narrative_report']) ?></small></td><td><?= e($complaint['category_name']) ?></td><td><?= e($complaint['college_name']) ?></td><td><?= e($complaint['department_name']) ?></td><td><?= e($complaint['school_year'] ?: 'N/A') ?></td><td><?= e(report_semester_label($complaint['semester'])) ?></td><td><span class="status-pill <?= e($complaint['status']) ?>"><?= e(report_status_label($complaint['status'])) ?></span></td><td><a class="action-link" href="admin_complaints_details.php?id=<?= (int)$complaint['id'] ?>">View</a></td></tr><?php endforeach; endif; ?>
            </tbody></table></div>
        </section>
    <?php else: ?>
        <section class="summary-grid">
            <article class="card metric"><i class="bx bx-bulb metric-icon"></i><strong><?= $totalSuggestions ?></strong><span>Total Suggestions</span><em>100% of results</em></article>
            <article class="card metric"><i class="bx bx-check-circle metric-icon"></i><strong><?= $implemented ?></strong><span>Implemented</span><em><?= $implementedPercent ?>% of results</em></article>
            <article class="card metric"><i class="bx bx-time-five metric-icon"></i><strong><?= $underReview ?></strong><span>Under Review</span><em><?= $underReviewPercent ?>% of results</em></article>
            <article class="card metric"><i class="bx bx-hourglass metric-icon"></i><strong><?= $pending ?></strong><span>Pending</span><em><?= $pendingPercent ?>% of results</em></article>
        </section>

        <section class="analytics-grid">
            <article class="card chart-card"><h2>Suggestions by Category</h2><div class="chart-wrap"><canvas id="categoryChart"></canvas></div></article>
            <article class="card ai-card"><span class="ai-badge"><i class="bx bx-sparkles"></i> AI SUMMARY</span><h2>AI Summary</h2><p><?= e($aiSummary) ?></p><p class="ai-note">AI-generated summary. Verify against the displayed results.</p></article>
        </section>
        <section class="card chart-card" style="margin-bottom:14px"><h2>Suggestions Over Time</h2><div class="chart-wrap"><canvas id="trendChart"></canvas></div></section>
        <section class="card office-card"><h2>Top Offices Receiving Suggestions</h2><?php $maxOffice = max(1, (int)($officeCounts[0]['total'] ?? 1)); foreach ($officeCounts as $officeRow): ?><div class="office-item"><span><?= e($officeRow['label']) ?></span><div class="bar"><span style="width:<?= round((int)$officeRow['total'] / $maxOffice * 100) ?>%"></span></div><strong><?= (int)$officeRow['total'] ?></strong></div><?php endforeach; ?><?php if (!$officeCounts): ?><div class="empty">No office data for the selected filters.</div><?php endif; ?></section>

        <section class="card table-card"><h2>Suggestions</h2><div class="table-scroll"><table><thead><tr><th>Date Submitted</th><th>Suggestion</th><th>Category</th><th>College</th><th>Department</th><th>Office</th><th>School Year</th><th>Semester</th><th>Status</th><th>Action</th></tr></thead><tbody><?php if (!$suggestions): ?><tr><td class="empty" colspan="10">No suggestions match the selected filters.</td></tr><?php else: foreach ($suggestions as $suggestion): ?><tr><td><?= e(date('M j, Y', strtotime((string)$suggestion['created_at']))) ?></td><td class="subject"><?= e($suggestion['subject']) ?><small><?= e($suggestion['description']) ?></small></td><td><?= e($suggestion['category_name']) ?></td><td><?= e($suggestion['college_name']) ?></td><td><?= e($suggestion['department_name']) ?></td><td><?= e($suggestion['office'] ?: 'Unassigned') ?></td><td><?= e($suggestion['school_year'] ?: 'N/A') ?></td><td><?= e(report_semester_label($suggestion['semester'])) ?></td><td><span class="status-pill <?= e($suggestion['status']) ?>"><?= e(report_status_label($suggestion['status'])) ?></span></td><td><a class="action-link" href="admin_suggestion_detail.php?id=<?= (int)$suggestion['id'] ?>">View</a></td></tr><?php endforeach; endif; ?></tbody></table></div></section>
    <?php endif; ?>
</div></main>
<script>
const categoryLabels = <?= json_encode(array_column($categories, 'label'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const categoryValues = <?= json_encode(array_map('intval', array_column($categories, 'total'))) ?>;
const trendLabels = <?= json_encode(array_column($trendRows, 'period'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const trendValues = <?= json_encode(array_map('intval', array_column($trendRows, 'total'))) ?>;
const complaintCategoryLabels = <?= json_encode(array_column($complaintCategories, 'label'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const complaintCategoryValues = <?= json_encode(array_map('intval', array_column($complaintCategories, 'total'))) ?>;
const complaintTrendLabels = <?= json_encode(array_column($complaintTrendRows, 'period'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const complaintTrendValues = <?= json_encode(array_map('intval', array_column($complaintTrendRows, 'total'))) ?>;
const complaintOrganizationLabels = <?= json_encode(array_column($complaintOrganizations, 'label'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const complaintOrganizationValues = <?= json_encode(array_map('intval', array_column($complaintOrganizations, 'total'))) ?>;
const horizontalOptions = { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { precision: 0 } }, y: { grid: { display: false } } } };
if (window.Chart && document.getElementById('categoryChart') && document.getElementById('trendChart')) {
    new Chart(document.getElementById('categoryChart'), { type: 'bar', data: { labels: categoryLabels, datasets: [{ data: categoryValues, backgroundColor: '#7c3aed', borderRadius: 5, barThickness: 18 }] }, options: horizontalOptions });
    new Chart(document.getElementById('trendChart'), { type: 'line', data: { labels: trendLabels, datasets: [{ data: trendValues, borderColor: '#7c3aed', backgroundColor: 'rgba(124,58,237,.12)', fill: true, tension: .35, pointRadius: 3 }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } }, x: { grid: { display: false } } } } });
}
if (window.Chart && document.getElementById('complaintCategoryChart')) new Chart(document.getElementById('complaintCategoryChart'), { type: 'bar', data: { labels: complaintCategoryLabels, datasets: [{ data: complaintCategoryValues, backgroundColor: '#7c3aed', borderRadius: 5, barThickness: 18 }] }, options: horizontalOptions });
if (window.Chart && document.getElementById('complaintOrganizationChart')) new Chart(document.getElementById('complaintOrganizationChart'), { type: 'bar', data: { labels: complaintOrganizationLabels, datasets: [{ data: complaintOrganizationValues, backgroundColor: '#a78bfa', borderRadius: 5, barThickness: 18 }] }, options: horizontalOptions });
if (window.Chart && document.getElementById('complaintTrendChart')) new Chart(document.getElementById('complaintTrendChart'), { type: 'line', data: { labels: complaintTrendLabels, datasets: [{ data: complaintTrendValues, borderColor: '#7c3aed', backgroundColor: 'rgba(124,58,237,.12)', fill: true, tension: .35, pointRadius: 3 }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } }, x: { grid: { display: false } } } } });
document.querySelectorAll('.quick-date').forEach(button => button.addEventListener('click', () => { const end = new Date(); const start = new Date(end); if (button.dataset.range === 'week') start.setDate(end.getDate() - 6); if (button.dataset.range === 'month') start.setDate(end.getDate() - 29); if (button.dataset.range === 'year') start.setFullYear(end.getFullYear() - 1); const iso = date => date.toISOString().slice(0, 10); document.getElementById('dateFrom').value = iso(start); document.getElementById('dateTo').value = iso(end); document.getElementById('reportFilters').submit(); }));
</script>
</body>
</html>
