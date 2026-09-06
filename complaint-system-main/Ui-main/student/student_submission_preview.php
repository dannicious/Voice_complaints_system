<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/../db_connection.php';

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function normalize_mode(string $mode): string
{
    return $mode === 'suggestion' ? 'suggestion' : 'complaint';
}

function draft_storage_key(string $mode): string
{
    return 'submission_drafts';
}

function draft_token_key(string $mode): string
{
    return 'submission_draft_tokens';
}

function draft_upload_dir(string $mode): string
{
    return __DIR__ . '/../assets/uploads/_drafts/' . $mode;
}

function store_preview_upload(array $file, string $mode): ?array
{
    if (!isset($file['error']) || (int)$file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ((int)$file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('File upload failed.');
    }

    if ((int)$file['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('File exceeds 5MB limit.');
    }

    $tmpName = (string)$file['tmp_name'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (string)finfo_file($finfo, $tmpName) : '';
    if ($finfo) {
        finfo_close($finfo);
    }

    $allowed = $mode === 'complaint'
        ? [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        ]
        : [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];

    if (!isset($allowed[$mime])) {
        throw new RuntimeException($mode === 'complaint'
            ? 'Only JPG, PNG, PDF, DOC, and DOCX files are allowed.'
            : 'Only JPG, PNG, and WEBP images are allowed.');
    }

    $ext = $allowed[$mime];
    $uploadDir = draft_upload_dir($mode);
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Failed to prepare preview upload directory.');
    }

    $filename = $mode . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $absPath = $uploadDir . '/' . $filename;

    if (!move_uploaded_file($tmpName, $absPath)) {
        throw new RuntimeException('Failed to store preview upload.');
    }

    return [
        'path' => 'assets/uploads/_drafts/' . $mode . '/' . $filename,
        'name' => (string)($file['name'] ?? $filename),
        'mime' => $mime,
    ];
}

function cleanup_draft_file(?string $relativePath): void
{
    if ($relativePath === null || trim($relativePath) === '') {
        return;
    }

    $absPath = __DIR__ . '/../' . ltrim($relativePath, '/');
    if (is_file($absPath)) {
        @unlink($absPath);
    }
}

function set_preview_draft(string $mode, array $draft): void
{
    if (!isset($_SESSION[draft_storage_key($mode)]) || !is_array($_SESSION[draft_storage_key($mode)])) {
        $_SESSION[draft_storage_key($mode)] = [];
    }

    if (!isset($_SESSION[draft_token_key($mode)]) || !is_array($_SESSION[draft_token_key($mode)])) {
        $_SESSION[draft_token_key($mode)] = [];
    }

    $existing = $_SESSION[draft_storage_key($mode)][$mode] ?? null;
    if (is_array($existing) && !empty($existing['attachment_path']) && ($existing['attachment_path'] !== ($draft['attachment_path'] ?? null))) {
        cleanup_draft_file((string)$existing['attachment_path']);
    }

    $_SESSION[draft_storage_key($mode)][$mode] = $draft;
    $_SESSION[draft_token_key($mode)][$mode] = bin2hex(random_bytes(16));
}

function get_preview_draft(string $mode): array
{
    $draft = $_SESSION[draft_storage_key($mode)][$mode] ?? [];
    return is_array($draft) ? $draft : [];
}

function get_preview_token(string $mode): string
{
    return (string)($_SESSION[draft_token_key($mode)][$mode] ?? '');
}

function field(string $value): string
{
    return e($value);
}

if (!isset($_SESSION['user_id']) || (string)($_SESSION['role'] ?? '') !== 'student') {
    header('Location: /complaint-system-main/student/login.php');
    exit;
}

$mode = normalize_mode((string)($_GET['mode'] ?? $_POST['mode'] ?? 'complaint'));

// Defense in depth: filing/previewing is only allowed for the current school
// year, even if a student somehow posts here directly while a past school
// year is selected (the "File Complaint" / "Send Suggestion" nav is already
// locked in that state).
require_once __DIR__ . '/../school_year_helpers.php';
$svStudentProfileId = sy_resolve_student_profile_id($pdo);
$svSchoolYearCurrent = sy_current();
$svSchoolYearSelected = $svStudentProfileId > 0 ? sy_get_selected($pdo, $svStudentProfileId) : $svSchoolYearCurrent;
if ($svSchoolYearSelected !== $svSchoolYearCurrent) {
    header('Location: student_complaints.php?mode=' . $mode . '&status=error&msg=' . urlencode(
        'Switch to the current school year (' . $svSchoolYearCurrent . ') to file a new complaint or suggestion.'
    ));
    exit;
}
$flashMessage = '';
$flashType = 'error';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($mode === 'complaint') {
            $reportedType = trim((string)($_POST['reported_type'] ?? 'student'));
            if (!in_array($reportedType, ['student', 'faculty'], true)) {
                $reportedType = 'student';
            }

            $draft = [
                'complainant_name' => trim((string)($_POST['complainant_name'] ?? '')),
                'complainant_address' => trim((string)($_POST['complainant_address'] ?? '')),
                'complainant_sex' => trim((string)($_POST['complainant_sex'] ?? '')),
                'complainant_age' => trim((string)($_POST['complainant_age'] ?? '')),
                'complainant_civil_status' => trim((string)($_POST['complainant_civil_status'] ?? '')),
                'complainant_contact_details' => trim((string)($_POST['complainant_contact_details'] ?? '')),
                'reported_type' => $reportedType,
                'reported_student_ids' => $reportedType === 'student'
                    ? array_values(array_filter(array_map('intval', (array)($_POST['reported_student_ids'] ?? []))))
                    : [],
                'reported_faculty_ids' => $reportedType === 'faculty'
                    ? array_values(array_filter(array_map('intval', (array)($_POST['reported_faculty_ids'] ?? []))))
                    : [],
                'person_complained_of' => trim((string)($_POST['person_complained_of'] ?? '')),
                'date_of_incident' => trim((string)($_POST['date_of_incident'] ?? '')),
                'time_of_incident' => trim((string)($_POST['time_of_incident'] ?? '')),
                'place_of_incident' => trim((string)($_POST['place_of_incident'] ?? '')),
                // The recipient is decided by who is being reported, not by the
                // student: reported students go to their dean, reported
                // faculty/staff go to the SAS Director.
                'category' => $reportedType === 'faculty' ? 'admin' : 'dean',
                'act_complained_of' => trim((string)($_POST['act_complained_of'] ?? '')),
                'desired_outcome' => trim((string)($_POST['desired_outcome'] ?? '')),
                'terms_agreement_accepted' => isset($_POST['terms_agreement_accepted']) ? '1' : '',
            ];

            $upload = store_preview_upload($_FILES['attachment'] ?? [], 'complaint');
            if ($upload !== null) {
                $draft['attachment_path'] = $upload['path'];
                $draft['attachment_name'] = $upload['name'];
            }

            set_preview_draft('complaint', $draft);

            $required = [
                'person_complained_of', 'date_of_incident', 'place_of_incident', 'category',
                'act_complained_of', 'desired_outcome', 'terms_agreement_accepted',
            ];
            $missing = [];
            foreach ($required as $key) {
                if ($draft[$key] === '' || $draft[$key] === null) {
                    $missing[] = $key;
                }
            }
            if (!empty($missing)) {
                $flashMessage = 'Please complete all required fields before proceeding.';
                $flashType = 'error';
            } elseif ($reportedType === 'faculty' && $draft['reported_faculty_ids'] === []) {
                $flashMessage = 'Please select the faculty or staff member being reported.';
                $flashType = 'error';
            } elseif ($reportedType === 'student' && $draft['reported_student_ids'] === []) {
                $flashMessage = 'Please select the student being reported.';
                $flashType = 'error';
            }
        } else {
            $draft = [
                'category' => trim((string)($_POST['category'] ?? '')),
                'date_of_suggestion' => trim((string)($_POST['date_of_suggestion'] ?? '')),
                'subject' => trim((string)($_POST['subject'] ?? '')),
                'description' => trim((string)($_POST['description'] ?? '')),
                'expected_outcome' => trim((string)($_POST['expected_outcome'] ?? '')),
                'terms_agreement_accepted' => isset($_POST['terms_agreement_accepted']) ? '1' : '',
            ];

            $upload = store_preview_upload($_FILES['reference_photo'] ?? [], 'suggestion');
            if ($upload !== null) {
                $draft['attachment_path'] = $upload['path'];
                $draft['attachment_name'] = $upload['name'];
            }

            set_preview_draft('suggestion', $draft);

            $required = ['category', 'date_of_suggestion', 'subject', 'description', 'expected_outcome', 'terms_agreement_accepted'];
            $missing = [];
            foreach ($required as $key) {
                if ($draft[$key] === '' || $draft[$key] === null) {
                    $missing[] = $key;
                }
            }
            if (!empty($missing)) {
                $flashMessage = 'Please complete all required fields before proceeding.';
                $flashType = 'error';
            }
        }
    } catch (RuntimeException $e) {
        $flashMessage = $e->getMessage();
        $flashType = 'error';
    }
}

$draft = get_preview_draft($mode);
if (empty($draft)) {
    header('Location: student_complaints.php?mode=' . $mode . '&status=error&msg=' . urlencode('Please complete the form first.'));
    exit;
}

$labels = $mode === 'complaint'
    ? [
        'title' => 'Review Complaint',
        'subtitle' => 'Check every detail before your complaint is saved.',
        'edit_url' => 'student_complaints.php?mode=complaint',
        'submit_url' => 'process_complaint.php',
        'submit_label' => 'Proceed / Submit Complaint',
        'section_title' => 'Complaint Preview',
    ]
    : [
        'title' => 'Review Suggestion',
        'subtitle' => 'Check every detail before your suggestion is saved.',
        'edit_url' => 'student_complaints.php?mode=suggestion',
        'submit_url' => 'process_suggestion.php',
        'submit_label' => 'Proceed / Submit Suggestion',
        'section_title' => 'Suggestion Preview',
    ];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo e($labels['title']); ?> - VOICE</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
body { background: #f4f6fb; color: #1f2937; }
.main { margin-left: 260px; margin-top: 61px; padding: 25px; min-height: calc(100vh - 61px); display: flex; justify-content: center; }
.content-wrapper { width: 100%; max-width: 900px; }
.page-title { margin-bottom: 8px; color: #111827; font-weight: 700; font-size: 28px; }
.page-subtitle { color: #6b7280; margin-bottom: 22px; }
.card { background: #fff; border: 1px solid #e5e7eb; border-radius: 16px; padding: 28px; box-shadow: 0 10px 25px rgba(15,23,42,0.05); }
.grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
.field { background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 12px; padding: 14px 16px; }
.field-label { font-size: 12px; text-transform: uppercase; letter-spacing: 0.06em; color: #6b7280; margin-bottom: 6px; }
.field-value { font-size: 15px; color: #111827; line-height: 1.5; white-space: pre-wrap; word-break: break-word; }
.full { grid-column: 1 / -1; }
.badge { display: inline-flex; align-items: center; padding: 6px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; color: #fff; }
.badge-general { background: #2563eb; }
.badge-college { background: #7c3aed; }
.actions { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 22px; }
.btn { border: none; border-radius: 10px; padding: 12px 18px; font-size: 14px; font-weight: 600; text-decoration: none; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; gap: 8px; }
.btn-edit { background: #e5e7eb; color: #111827; }
.btn-edit:hover { background: #d1d5db; }
.btn-proceed { background: #10b981; color: white; }
.btn-proceed:hover { background: #059669; }
.notice { margin-bottom: 16px; padding: 12px 14px; border-radius: 10px; font-size: 13px; font-weight: 500; }
.notice.error { background: #fff1f2; color: #b91c1c; border: 1px solid #fecdd3; }
.notice.success { background: #ecfdf5; color: #166534; border: 1px solid #bbf7d0; }
@media (max-width: 768px) {
    .main { margin-left: 0; }
    .grid { grid-template-columns: 1fr; }
}
</style>
</head>
<body>
<?php include 'student_topbar.php'; ?>
<?php include 'student_sidebar.php'; ?>
<div class="main">
    <div class="content-wrapper">
        <h2 class="page-title"><?php echo e($labels['title']); ?></h2>
        <p class="page-subtitle"><?php echo e($labels['subtitle']); ?></p>

        <?php if ($flashMessage !== ''): ?>
            <div class="notice <?php echo e($flashType); ?>"><?php echo e($flashMessage); ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="grid">
                <div class="field full">
                    <div class="field-label"><?php echo e($labels['section_title']); ?></div>
                    <div class="field-value"><span class="badge <?php echo $mode === 'complaint' ? 'badge-general' : 'badge-college'; ?>"><?php echo $mode === 'complaint' ? 'Complaint' : 'Suggestion'; ?></span></div>
                </div>

                <?php if ($mode === 'complaint'): ?>
                    <?php $previewReportedType = (string)($draft['reported_type'] ?? 'student'); ?>
                    <div class="field full"><div class="field-label"><?php echo $previewReportedType === 'faculty' ? 'Faculty/Staff Involved' : 'Students Involved'; ?></div><div class="field-value"><?php
                        $selectedNames = [];
                        $draftIdsKey = $previewReportedType === 'faculty' ? 'reported_faculty_ids' : 'reported_student_ids';
                        if (!empty($draft[$draftIdsKey]) && is_array($draft[$draftIdsKey])) {
                            $reportedIds = array_filter(array_map('intval', $draft[$draftIdsKey]));
                            if (!empty($reportedIds)) {
                                $reportedIdList = implode(',', $reportedIds);
                                try {
                                    if ($previewReportedType === 'faculty') {
                                        $nameStmt = $pdo->prepare('SELECT first_name, last_name, position FROM faculty_staff WHERE id IN (' . $reportedIdList . ') ORDER BY first_name ASC, last_name ASC');
                                        $nameStmt->execute();
                                        foreach ($nameStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                                            $position = trim((string)($row['position'] ?? ''));
                                            $selectedNames[] = trim((string)($row['first_name'] ?? '')) . ' ' . trim((string)($row['last_name'] ?? ''))
                                                . ($position !== '' ? ' (' . $position . ')' : '');
                                        }
                                    } else {
                                        $nameStmt = $pdo->prepare('SELECT first_name, last_name, student_number FROM student_profiles WHERE id IN (' . $reportedIdList . ') ORDER BY first_name ASC, last_name ASC');
                                        $nameStmt->execute();
                                        foreach ($nameStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                                            $selectedNames[] = trim((string)($row['first_name'] ?? '')) . ' ' . trim((string)($row['last_name'] ?? '')) . ' (' . (string)($row['student_number'] ?? '') . ')';
                                        }
                                    }
                                } catch (PDOException $e) {
                                    $selectedNames = [];
                                }
                            }
                        }
                        echo field(!empty($selectedNames) ? implode(', ', $selectedNames) : ($previewReportedType === 'faculty' ? 'No faculty/staff selected' : 'No students selected'));
                    ?></div></div>
                    <div class="field full"><div class="field-label">Person/Office Complained Of</div><div class="field-value"><?php echo field((string)($draft['person_complained_of'] ?? '')); ?></div></div>
                    <div class="field"><div class="field-label">Date of Incident</div><div class="field-value"><?php echo field((string)($draft['date_of_incident'] ?? '')); ?></div></div>
                    <div class="field"><div class="field-label">Time of Incident</div><div class="field-value"><?php echo field((string)($draft['time_of_incident'] ?? '')); ?></div></div>
                    <div class="field full"><div class="field-label">Place of Incident</div><div class="field-value"><?php echo field((string)($draft['place_of_incident'] ?? '')); ?></div></div>
                    <div class="field"><div class="field-label">Reported</div><div class="field-value"><?php echo field(((string)($draft['reported_type'] ?? 'student')) === 'faculty' ? 'Faculty/Staff' : 'Student'); ?></div></div>
                    <div class="field"><div class="field-label">Will be sent to</div><div class="field-value"><?php echo field(((string)($draft['category'] ?? '')) === 'admin' ? 'SAS Director' : 'College Dean'); ?></div></div>
                    <div class="field full"><div class="field-label">Act/s Complained Of</div><div class="field-value"><?php echo field((string)($draft['act_complained_of'] ?? '')); ?></div></div>
                    <div class="field full"><div class="field-label">Desired Outcome</div><div class="field-value"><?php echo field((string)($draft['desired_outcome'] ?? '')); ?></div></div>
                    <div class="field full"><div class="field-label">Supporting File</div><div class="field-value"><?php echo !empty($draft['attachment_name']) ? field((string)$draft['attachment_name']) : 'No file attached'; ?></div></div>
                <?php else: ?>
                    <div class="field"><div class="field-label">Category</div><div class="field-value"><?php echo field((string)($draft['category'] ?? '')); ?></div></div>
                    <div class="field"><div class="field-label">Date of Suggestion</div><div class="field-value"><?php echo field((string)($draft['date_of_suggestion'] ?? '')); ?></div></div>
                    <div class="field full"><div class="field-label">Idea Title</div><div class="field-value"><?php echo field((string)($draft['subject'] ?? '')); ?></div></div>
                    <div class="field full"><div class="field-label">Detailed Suggestion</div><div class="field-value"><?php echo field((string)($draft['description'] ?? '')); ?></div></div>
                    <div class="field full"><div class="field-label">Expected Outcome</div><div class="field-value"><?php echo field((string)($draft['expected_outcome'] ?? '')); ?></div></div>
                    <div class="field full"><div class="field-label">Supporting File</div><div class="field-value"><?php echo !empty($draft['attachment_name']) ? field((string)$draft['attachment_name']) : 'No file attached'; ?></div></div>
                <?php endif; ?>
            </div>

            <div class="actions">
                <a class="btn btn-edit" href="<?php echo e($labels['edit_url']); ?>"><i class='bx bx-edit'></i> Edit</a>
                <form method="POST" action="<?php echo e($labels['submit_url']); ?>" style="display:inline;">
                    <input type="hidden" name="draft_token" value="<?php echo e(get_preview_token($mode)); ?>">
                    <button type="submit" class="btn btn-proceed"><i class='bx bx-check-circle'></i> <?php echo e($labels['submit_label']); ?></button>
                </form>
            </div>
        </div>
    </div>
</div>
</body>
</html>
