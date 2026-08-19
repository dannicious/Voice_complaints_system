<?php
session_start();
require_once __DIR__ . '/../db_connection.php';

function require_admin_session(PDO $pdo): void
{
    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    $sessionRole = strtolower(trim((string)($_SESSION['role'] ?? '')));

    if ($userId <= 0) {
        header('Location: /complaint-system-main/admin/login.php?error=' . urlencode('Please log in as admin.'));
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

    header('Location: /complaint-system-main/admin/login.php?error=' . urlencode('Please log in as admin.'));
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

function normalize_key(string $value): string
{
    $value = trim(mb_strtolower($value));
    $value = preg_replace('/^\x{FEFF}/u', '', $value) ?? $value;
    $value = preg_replace('/[^a-z0-9]+/u', '_', $value) ?? $value;
    return trim($value, '_');
}

function table_exists(PDO $pdo, string $tableName): bool
{
    try {
        $stmt = $pdo->prepare('SHOW TABLES LIKE :tableName');
        $stmt->execute([':tableName' => $tableName]);
        return (bool) $stmt->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }
}

function csv_value(array $row, array $headerMap, array $aliases): string
{
    foreach ($aliases as $alias) {
        $key = normalize_key($alias);
        if (array_key_exists($key, $headerMap)) {
            $index = $headerMap[$key];
            return trim((string)($row[$index] ?? ''));
        }
    }

    return '';
}

function is_row_empty(array $row): bool
{
    foreach ($row as $cell) {
        if (trim((string)$cell) !== '') {
            return false;
        }
    }

    return true;
}

function generate_unique_username(PDO $pdo, string $firstName, string $lastName, string $studentNumber = ''): string
{
    $base = strtolower(preg_replace('/[^a-z0-9]/i', '', $firstName . '.' . $lastName));
    if ($base === '') {
        $base = strtolower(preg_replace('/[^a-z0-9]/i', '', $studentNumber));
    }
    if ($base === '') {
        $base = 'student';
    }

    $candidate = substr($base, 0, 40);
    $attempt = 0;

    while (true) {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
        $stmt->execute([':username' => $candidate]);

        if (!$stmt->fetch()) {
            return $candidate;
        }

        $attempt++;
        $suffix = (string)random_int(100, 999);
        $candidate = substr($base, 0, max(1, 40 - strlen($suffix))) . $suffix;

        if ($attempt > 10) {
            return 'student' . random_int(10000, 99999);
        }
    }
}

function resolve_lookup(array $lookupByKey, string $value): ?array
{
    $key = normalize_key($value);
    if ($key === '') {
        return null;
    }

    return $lookupByKey[$key] ?? null;
}

$flashMessage = '';
$flashType = 'success';
$uploadNotes = [];

$collegeLookup = [];
$collegeLookupById = [];
$programLookup = [];
$programLookupById = [];
$studentRows = [];

try {
    $collegeRows = $pdo->query('SELECT id, code, name FROM colleges ORDER BY name ASC')->fetchAll();
    foreach ($collegeRows as $college) {
        $collegeLookup[normalize_key((string)$college['code'])] = $college;
        $collegeLookup[normalize_key((string)$college['name'])] = $college;
        $collegeLookupById[(int)$college['id']] = $college;
    }

    $programRows = $pdo->query('SELECT id, college_id, code, name FROM programs ORDER BY code ASC')->fetchAll();
    foreach ($programRows as $program) {
        $programLookup[normalize_key((string)$program['code'])] = $program;
        $programLookup[normalize_key((string)$program['name'])] = $program;
        $programLookupById[(int)$program['id']] = $program;
    }
} catch (PDOException $e) {
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['student_action'] ?? '') === 'bulk_upload') {
    $token = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $flashMessage = 'Invalid request token. Please refresh and try again.';
        $flashType = 'error';
    } elseif (!isset($_FILES['students_csv'])) {
        $flashMessage = 'Please choose a CSV file to upload.';
        $flashType = 'error';
    } else {
        $file = $_FILES['students_csv'];

        if ((int)$file['error'] !== UPLOAD_ERR_OK) {
            $flashMessage = 'The CSV upload failed. Please try again.';
            $flashType = 'error';
        } elseif (!is_file((string)$file['tmp_name'])) {
            $flashMessage = 'Uploaded file was not found.';
            $flashType = 'error';
        } else {
            $fileName = strtolower((string)$file['name']);
            if (!str_ends_with($fileName, '.csv') && !str_ends_with($fileName, '.txt')) {
                $flashMessage = 'Please upload a CSV file.';
                $flashType = 'error';
            } else {
                $handle = fopen((string)$file['tmp_name'], 'r');
                if ($handle === false) {
                    $flashMessage = 'Unable to read the uploaded file.';
                    $flashType = 'error';
                } else {
                    $headers = fgetcsv($handle);
                    if ($headers === false) {
                        $flashMessage = 'The CSV file is empty.';
                        $flashType = 'error';
                    } else {
                        $headerMap = [];
                        foreach ($headers as $index => $header) {
                            $headerMap[normalize_key((string)$header)] = $index;
                        }

                        $requiredColumns = ['student_number', 'first_name', 'last_name', 'email'];
                        $missingColumns = [];
                        foreach ($requiredColumns as $column) {
                            if (!array_key_exists($column, $headerMap)) {
                                $missingColumns[] = $column;
                            }
                        }

                        if ($missingColumns !== []) {
                            $flashMessage = 'Missing required CSV columns: ' . implode(', ', $missingColumns) . '.';
                            $flashType = 'error';
                        } else {
                            $studentExistsStmt = $pdo->prepare('SELECT id FROM student_profiles WHERE student_number = :student_number LIMIT 1');
                            $accountExistsStmt = $pdo->prepare('SELECT id FROM users WHERE username = :username OR email = :email LIMIT 1');
                            $insertUserStmt = $pdo->prepare(
                                'INSERT INTO users (username, email, password, role, profile_pic, is_active, college)
                                 VALUES (:username, :email, :password, :role, :profile_pic, :is_active, :college)'
                            );
                            $insertProfileStmt = $pdo->prepare(
                                'INSERT INTO student_profiles
                                    (user_id, student_number, first_name, last_name, middle_name, gender, contact_number, college_id, year_level, section, school_year, id_document, status, program_id)
                                 VALUES
                                    (:user_id, :student_number, :first_name, :last_name, :middle_name, :gender, :contact_number, :college_id, :year_level, :section, :school_year, :id_document, :status, :program_id)'
                            );

                            $importedCount = 0;
                            $skippedCount = 0;

                            $rowNumber = 1;
                            while (($row = fgetcsv($handle)) !== false) {
                                $rowNumber++;

                                if (is_row_empty($row)) {
                                    continue;
                                }

                                $studentNumber = trim(csv_value($row, $headerMap, ['student_number', 'student no', 'student id', 'student_id']));
                                $firstName = trim(csv_value($row, $headerMap, ['first_name', 'firstname', 'given_name']));
                                $lastName = trim(csv_value($row, $headerMap, ['last_name', 'lastname', 'surname']));
                                $email = trim(csv_value($row, $headerMap, ['email']));
                                $username = trim(csv_value($row, $headerMap, ['username']));
                                $password = trim(csv_value($row, $headerMap, ['password']));
                                $middleName = trim(csv_value($row, $headerMap, ['middle_name', 'middlename']));
                                $gender = trim(csv_value($row, $headerMap, ['gender']));
                                $contactNumber = trim(csv_value($row, $headerMap, ['contact_number', 'contact', 'phone']));
                                $yearLevelValue = trim(csv_value($row, $headerMap, ['year_level', 'yearlevel']));
                                $section = trim(csv_value($row, $headerMap, ['section']));
                                $schoolYear = trim(csv_value($row, $headerMap, ['school_year', 'schoolyear']));
                                $collegeValue = trim(csv_value($row, $headerMap, ['college', 'college_name', 'college_code']));
                                $programValue = trim(csv_value($row, $headerMap, ['program', 'program_name', 'program_code']));

                                if ($studentNumber === '' || $firstName === '' || $lastName === '' || $email === '') {
                                    $skippedCount++;
                                    $uploadNotes[] = "Row {$rowNumber}: missing a required value.";
                                    continue;
                                }

                                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                                    $skippedCount++;
                                    $uploadNotes[] = "Row {$rowNumber}: invalid email address.";
                                    continue;
                                }

                                $college = resolve_lookup($collegeLookup, $collegeValue);
                                if ($collegeValue !== '' && $college === null) {
                                    $skippedCount++;
                                    $uploadNotes[] = "Row {$rowNumber}: college '{$collegeValue}' was not found.";
                                    continue;
                                }

                                $program = resolve_lookup($programLookup, $programValue);
                                if ($programValue !== '' && $program === null) {
                                    $skippedCount++;
                                    $uploadNotes[] = "Row {$rowNumber}: program '{$programValue}' was not found.";
                                    continue;
                                }

                                if ($college === null && $program !== null) {
                                    $college = $collegeLookupById[(int)$program['college_id']] ?? null;
                                }

                                $collegeLabel = $college ? trim((string)$college['name']) : '';
                                if ($collegeLabel === '' && $collegeValue !== '') {
                                    $collegeLabel = $collegeValue;
                                }

                                $collegeId = $college ? (int)$college['id'] : null;
                                $programId = $program ? (int)$program['id'] : null;

                                if ($username === '') {
                                    $username = generate_unique_username($pdo, $firstName, $lastName, $studentNumber);
                                }

                                if ($password === '') {
                                    $password = $studentNumber;
                                }

                                $yearLevel = $yearLevelValue !== '' ? (int)$yearLevelValue : null;

                                $accountExistsStmt->execute([
                                    ':username' => $username,
                                    ':email' => $email,
                                ]);
                                if ($accountExistsStmt->fetch()) {
                                    $skippedCount++;
                                    $uploadNotes[] = "Row {$rowNumber}: username or email already exists.";
                                    continue;
                                }

                                $studentExistsStmt->execute([':student_number' => $studentNumber]);
                                if ($studentExistsStmt->fetch()) {
                                    $skippedCount++;
                                    $uploadNotes[] = "Row {$rowNumber}: student number already exists.";
                                    continue;
                                }

                                try {
                                    $pdo->beginTransaction();

                                    $insertUserStmt->execute([
                                        ':username' => $username,
                                        ':email' => $email,
                                        ':password' => password_hash($password, PASSWORD_DEFAULT),
                                        ':role' => 'student',
                                        ':profile_pic' => null,
                                        ':is_active' => 1,
                                        ':college' => $collegeLabel,
                                    ]);

                                    $userId = (int)$pdo->lastInsertId();

                                    $insertProfileStmt->execute([
                                        ':user_id' => $userId,
                                        ':student_number' => $studentNumber,
                                        ':first_name' => $firstName,
                                        ':last_name' => $lastName,
                                        ':middle_name' => $middleName !== '' ? $middleName : null,
                                        ':gender' => in_array($gender, ['male', 'female', 'other'], true) ? $gender : null,
                                        ':contact_number' => $contactNumber !== '' ? $contactNumber : null,
                                        ':college_id' => $collegeId,
                                        ':year_level' => $yearLevel,
                                        ':section' => $section !== '' ? $section : null,
                                        ':school_year' => $schoolYear !== '' ? $schoolYear : null,
                                        ':id_document' => null,
                                        ':status' => 'active',
                                        ':program_id' => $programId,
                                    ]);

                                    $pdo->commit();
                                    $importedCount++;
                                } catch (PDOException $e) {
                                    if ($pdo->inTransaction()) {
                                        $pdo->rollBack();
                                    }

                                    $skippedCount++;
                                    $uploadNotes[] = "Row {$rowNumber}: database error while saving the student.";
                                }
                            }

                            fclose($handle);

                            if ($importedCount > 0) {
                                $flashType = 'success';
                                $flashMessage = 'Bulk upload complete. Imported ' . $importedCount . ' student' . ($importedCount === 1 ? '' : 's') . '.';
                                if ($skippedCount > 0) {
                                    $flashMessage .= ' Skipped ' . $skippedCount . ' row' . ($skippedCount === 1 ? '' : 's') . '.';
                                }
                            } else {
                                $flashType = 'error';
                                $flashMessage = 'No students were imported from the CSV file.';
                            }
                        }
                    }
                }
            }
        }
    }
}

try {
    // Use the same correlated counts as the Dean reports page.
    $reportedCountExpr = '(SELECT COUNT(*) FROM complaint_student_links csl WHERE csl.student_id = sp.id) AS reported_count';
    $complaintCountExpr = '(SELECT COUNT(*) FROM complaints c WHERE c.student_id = sp.id AND c.ticket_no NOT LIKE "VOX-C-2026-%") AS complaint_count';

    $studentQuery = "SELECT
                sp.id,
                sp.student_number,
                sp.first_name,
                sp.last_name,
                sp.middle_name,
                sp.contact_number,
                sp.year_level,
                sp.section,
                sp.school_year,
                sp.created_at,
                sp.status,
                u.username,
                u.email,
                c.code AS college_code,
                c.name AS college_name,
                p.code AS program_code,
                p.name AS program_name,
                " . $reportedCountExpr . ",
                " . $complaintCountExpr . "
            FROM student_profiles sp
            INNER JOIN users u ON u.id = sp.user_id
            LEFT JOIN colleges c ON c.id = sp.college_id
            LEFT JOIN programs p ON p.id = sp.program_id
            WHERE u.role = 'student' AND sp.status = 'active'
            ORDER BY complaint_count DESC, reported_count DESC, sp.created_at DESC";

    $studentStmt = $pdo->query($studentQuery);
    $studentRows = $studentStmt->fetchAll();
} catch (PDOException $e) {
    if ($flashMessage === '') {
        $flashMessage = 'Student profiles are not available. Please verify your database setup.';
        $flashType = 'error';
    }
}

// AJAX live-search handler for admin students (returns table rows)
if ((string)($_GET['ajax'] ?? '') === '1') {
    $q = trim((string)($_GET['q'] ?? ''));
    $like = '%' . $q . '%';
    $sort = trim((string)($_GET['sort'] ?? ''));

    // Build same base query but with optional WHERE and ORDER BY
    $where = " WHERE u.role = 'student' AND sp.status = 'active' ";
    $params = [];
    if ($q !== '') {
        $where .= ' AND (sp.student_number LIKE :like1 OR sp.first_name LIKE :like2 OR sp.last_name LIKE :like3 OR u.username LIKE :like4 OR u.email LIKE :like5 OR p.name LIKE :like6)';
        $params[':like1'] = $like;
        $params[':like2'] = $like;
        $params[':like3'] = $like;
        $params[':like4'] = $like;
        $params[':like5'] = $like;
        $params[':like6'] = $like;
    }

    $order = ' ORDER BY complaint_count DESC, reported_count DESC, sp.created_at DESC';
    if ($sort === 'complaints_desc') {
        $order = ' ORDER BY complaint_count DESC, sp.first_name, sp.last_name';
    } elseif ($sort === 'reports_desc') {
        $order = ' ORDER BY reported_count DESC, sp.first_name, sp.last_name';
    } elseif ($sort === 'name_asc') {
        $order = ' ORDER BY sp.first_name, sp.last_name';
    } elseif ($sort === 'name_desc') {
        $order = ' ORDER BY sp.last_name DESC, sp.first_name DESC';
    }

    // Use correlated subqueries for per-student counts (matches Dean implementation)
    $reportedCountExpr = '(SELECT COUNT(*) FROM complaint_student_links csl WHERE csl.student_id = sp.id) AS reported_count';
    $complaintCountExpr = '(SELECT COUNT(*) FROM complaints c WHERE c.student_id = sp.id AND c.ticket_no NOT LIKE "VOX-C-2026-%") AS complaint_count';

    $sql = "SELECT
                sp.id,
                sp.student_number,
                sp.first_name,
                sp.last_name,
                sp.middle_name,
                sp.contact_number,
                sp.year_level,
                sp.section,
                sp.school_year,
                sp.created_at,
                sp.status,
                u.username,
                u.email,
                c.code AS college_code,
                c.name AS college_name,
                p.code AS program_code,
                p.name AS program_name,
                " . $reportedCountExpr . ",
                " . $complaintCountExpr . "
            FROM student_profiles sp
            INNER JOIN users u ON u.id = sp.user_id
            LEFT JOIN colleges c ON c.id = sp.college_id
            LEFT JOIN programs p ON p.id = sp.program_id
            " . $where . "
            " . $order . "
            LIMIT 200";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        echo '<tr><td colspan="8" class="empty-state">No active student accounts yet.</td></tr>';
        exit;
    }

    foreach ($rows as $student) {
        $fullName = trim((string)$student['first_name'] . ' ' . (string)$student['last_name']);
        $collegeLabel = (string)($student['college_name'] ?? 'N/A');
        $programLabel = trim((string)($student['program_code'] ?? '') . ((string)($student['program_name'] ?? '') !== '' ? ' - ' . (string)$student['program_name'] : ''));
        if ($programLabel === '') $programLabel = 'N/A';
        $classInfo = trim((string)($student['year_level'] ?? '') . ((string)($student['section'] ?? '') !== '' ? ' / Section ' . (string)$student['section'] : ''));
        if ($classInfo === '') $classInfo = 'N/A';
        $studentNumber = htmlspecialchars((string)($student['student_number'] ?? ''));
        $username = htmlspecialchars((string)($student['username'] ?? ''));
        $email = htmlspecialchars((string)($student['email'] ?? ''));
        $complaintCount = (int)($student['complaint_count'] ?? 0);
        $reportedCount = (int)($student['reported_count'] ?? 0);

        echo '<tr>';
        echo "<td><strong>{$studentNumber}</strong></td>";
        echo "<td><div class=\"student-cell\"><img src=\"https://ui-avatars.com/api/?name=" . rawurlencode($fullName !== '' ? $fullName : 'Student') . "&background=4F8CFF&color=ffffff\" alt=\"Student Avatar\"><div class=\"student-info\"><span class=\"student-name\">" . htmlspecialchars($fullName !== '' ? $fullName : 'Unnamed Student') . "</span><span class=\"student-email\">{$username}</span></div></div></td>";
        echo "<td>{$email}</td>";
        echo "<td>" . htmlspecialchars($collegeLabel) . "<br><span style=\"color:#6b7280;font-size:11px;\">" . htmlspecialchars($programLabel) . "</span></td>";
        echo "<td>" . htmlspecialchars($classInfo) . "<br><span style=\"color:#6b7280;font-size:11px;\">" . htmlspecialchars((string)($student['school_year'] ?? '')) . "</span></td>";
        echo "<td><span class=\"complaint-count-badge\">{$complaintCount}</span></td>";
        echo "<td><span class=\"complaint-count-badge\">{$reportedCount}</span></td>";
        echo "<td><span class=\"badge bg-active\">" . htmlspecialchars(ucfirst($student['status'] ?? 'active')) . "</span></td>";
        echo '</tr>';
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

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>

<style>
* { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
body { background: #f4f6fb; }
.main { margin-left: 260px; margin-top: 61px; padding: 25px; min-height: calc(100vh - 61px); overflow-y: auto; }
.dashboard-container { max-width: 1200px; margin: 0 auto; width: 100%; }
.page-header { margin-bottom: 20px; }
.page-header h2 { font-size: 24px; font-weight: 600; color: #333; }
.flash-msg { margin-bottom: 15px; padding: 11px 13px; border-radius: 8px; font-size: 12.5px; font-weight: 500; }
.flash-msg.success { background: #e8f9f0; border: 1px solid #b7ebce; color: #1f7a45; }
.flash-msg.error { background: #fff1f1; border: 1px solid #ffd1d1; color: #b42318; }
.panel { background: #fff; padding: 20px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.02); margin-bottom: 20px; }
.panel-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; margin-bottom: 16px; }
.panel-title { font-size: 18px; font-weight: 600; color: #111827; }
.panel-subtitle { font-size: 13px; color: #6b7280; margin-top: 4px; line-height: 1.5; }
.upload-form { display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap; }
.form-group { display: flex; flex-direction: column; gap: 6px; }
.form-group label { font-size: 12px; font-weight: 600; color: #374151; }
.file-input { padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 10px; background: #fff; min-width: 280px; }
.btn { border: none; border-radius: 10px; padding: 11px 16px; font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; }
.btn-primary { background: #4F8CFF; color: #fff; }
.btn-primary:hover { background: #3b75e8; }
.btn-secondary { background: #eef2ff; color: #4338ca; }
.btn-secondary:hover { background: #e0e7ff; }
.note-box { font-size: 12px; color: #6b7280; margin-top: 12px; line-height: 1.6; }
.import-notes { margin-top: 12px; padding-left: 18px; color: #7c2d12; font-size: 12.5px; line-height: 1.6; }
.controls-card { background: #fff; padding: 20px; border-radius: 12px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; gap: 15px; flex-wrap: wrap; box-shadow: 0 2px 10px rgba(0,0,0,0.02); }
.search-box { display: flex; align-items: center; background: #f4f6fb; padding: 8px 15px; border-radius: 8px; border: 1px solid #e5e7eb; width: 320px; max-width: 100%; }
.search-box i { color: #888; font-size: 18px; }
.search-box input { border: none; background: transparent; outline: none; margin-left: 10px; width: 100%; font-size: 13px; }
.table-card { background: #fff; padding: 20px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.02); overflow-x: auto; }
table { width: 100%; border-collapse: collapse; min-width: 980px; }
th { text-align: left; color: #888; font-size: 13px; padding: 15px; border-bottom: 1px solid #eee; font-weight: 500; }
td { padding: 15px; font-size: 13px; border-bottom: 1px solid #f9f9f9; vertical-align: middle; }
tbody tr:hover { background-color: #fcfcfc; }
.student-cell { display: flex; align-items: center; gap: 10px; }
.student-cell img { width: 35px; height: 35px; border-radius: 50%; object-fit: cover; }
.student-info { display: flex; flex-direction: column; }
.student-name { font-weight: 600; color: #333; }
.student-email { font-size: 11px; color: #888; }
.badge { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
.bg-active { background: #d1fae5; color: #059669; }
.complaint-count-badge { display: inline-flex; align-items: center; justify-content: center; min-width: 36px; padding: 6px 10px; border-radius: 999px; background: #eef2ff; color: #4338ca; font-weight: 600; }
.empty-state { color: #6b7280; font-size: 13px; padding: 24px 0; text-align: center; }

@media (max-width: 1024px) { .main { margin-left: 0; } }
</style>
</head>

<body>

<?php include 'admin_topbar.php'; ?>
<?php include 'admin_sidebar.php'; ?>

<div class="main">
    <div class="dashboard-container">

        <div class="page-header">
            <h2>Reports Management</h2>
        </div>

        <?php if ($flashMessage !== ''): ?>
            <div class="flash-msg <?php echo e($flashType); ?>"><?php echo e($flashMessage); ?></div>
        <?php endif; ?>

        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:12px;">
            <div style="flex:1;min-width:220px;">
                <div class="search-box">
                    <i class='bx bx-search'></i>
                    <input type="text" id="studentSearch" name="q" placeholder="Search by ID, name, email or program..." value="<?php echo isset($_GET['q']) ? e((string)$_GET['q']) : ''; ?>">
                </div>
            </div>
            <div>
                <select id="sortSelect" name="sort" style="padding:9px 12px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;font-size:13px;">
                    <option value="complaints_desc">Complaints</option>
                    <option value="reports_desc">Reports</option>
                </select>
            </div>
            <div style="font-size:13px;color:#6b7280;">
                <?php echo count($studentRows); ?> active student<?php echo count($studentRows) === 1 ? '' : 's'; ?>
            </div>
        </div>

        <div class="table-card">
            <table id="studentTable">
                <thead>
                    <tr>
                        <th>Student ID</th>
                        <th>Student Name</th>
                        <th>Email</th>
                        <th>College / Program</th>
                        <th>Class Info</th>
                        <th>Complaints</th>
                        <th>Reported</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($studentRows) === 0): ?>
                        <tr>
                            <td colspan="7" class="empty-state">No active student accounts yet.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($studentRows as $student): ?>
                            <?php
                                $fullName = trim((string)$student['first_name'] . ' ' . (string)$student['last_name']);
                                $collegeLabel = (string)($student['college_name'] ?? 'N/A');
                                $programLabel = trim((string)($student['program_code'] ?? '') . ((string)($student['program_name'] ?? '') !== '' ? ' - ' . (string)$student['program_name'] : ''));
                                if ($programLabel === '') {
                                    $programLabel = 'N/A';
                                }
                                $classInfo = trim((string)($student['year_level'] ?? '') . ((string)($student['section'] ?? '') !== '' ? ' / Section ' . (string)$student['section'] : ''));
                                if ($classInfo === '') {
                                    $classInfo = 'N/A';
                                }
                            ?>
                            <tr>
                                <td><strong><?php echo e((string)$student['student_number']); ?></strong></td>
                                <td>
                                    <div class="student-cell">
                                        <img src="https://ui-avatars.com/api/?name=<?php echo rawurlencode($fullName !== '' ? $fullName : 'Student'); ?>&background=4F8CFF&color=ffffff" alt="Student Avatar">
                                        <div class="student-info">
                                            <span class="student-name"><?php echo e($fullName !== '' ? $fullName : 'Unnamed Student'); ?></span>
                                            <span class="student-email"><?php echo e((string)$student['username']); ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td><?php echo e((string)$student['email']); ?></td>
                                <td><?php echo e($collegeLabel); ?><br><span style="color:#6b7280;font-size:11px;"><?php echo e($programLabel); ?></span></td>
                                <td><?php echo e($classInfo); ?><br><span style="color:#6b7280;font-size:11px;"><?php echo e((string)($student['school_year'] ?? '')); ?></span></td>
                                <td><span class="complaint-count-badge"><?php echo e((string)($student['complaint_count'] ?? 0)); ?></span></td>
                                <td><span class="complaint-count-badge"><?php echo e((string)($student['reported_count'] ?? 0)); ?></span></td>
                                <td><span class="badge bg-active">Active</span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
    const qInput = document.getElementById('studentSearch');
    const sortSelect = document.getElementById('sortSelect');
    const tbody = document.querySelector('#studentTable tbody');
    let timer = null;

    function doSearch() {
        const params = new URLSearchParams();
        if (qInput && qInput.value.trim() !== '') params.set('q', qInput.value.trim());
        if (sortSelect && sortSelect.value) params.set('sort', sortSelect.value);
        params.set('ajax','1');
        fetch(window.location.pathname + '?' + params.toString(), {headers: {'X-Requested-With': 'XMLHttpRequest'}})
            .then(r => r.text())
            .then(html => { if (tbody) tbody.innerHTML = html; })
            .catch(err => console.error('Search error', err));
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

</body>
</html>