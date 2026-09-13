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
$svSchoolYearCurrent = sy_current($pdo);
$svSchoolYearSelected = $svStudentProfileId > 0 ? sy_get_selected($pdo, $svStudentProfileId) : $svSchoolYearCurrent;
if ($svSchoolYearSelected !== $svSchoolYearCurrent) {
    header('Location: student_complaints.php?mode=' . $mode . '&status=error&msg=' . urlencode(
        'Switch to the current school year (' . $svSchoolYearCurrent . ') to file a new complaint or suggestion.'
    ));
    exit;
}
$svSemesterCurrent = semester_current($pdo);
$svSemesterSelected = $svStudentProfileId > 0 ? semester_get_selected($pdo) : $svSemesterCurrent;
if ($svSemesterSelected !== $svSemesterCurrent) {
    header('Location: student_complaints.php?mode=' . $mode . '&status=error&msg=' . urlencode(
        'Switch to the current semester (' . semester_display_label($svSemesterCurrent) . ') to file a new complaint or suggestion.'
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
                // Hard-capped at 500 characters (also enforced client-side via
                // the field's maxlength) - mb_substr so this can't cut a
                // multi-byte character in half.
                'act_complained_of' => mb_substr(trim((string)($_POST['act_complained_of'] ?? '')), 0, 500),
                // Hard-capped at 300 characters (also enforced client-side via
                // the field's maxlength) - mb_substr so this can't cut a
                // multi-byte character in half.
                'desired_outcome' => mb_substr(trim((string)($_POST['desired_outcome'] ?? '')), 0, 300),
                'terms_agreement_accepted' => isset($_POST['terms_agreement_accepted']) ? '1' : '',
            ];

            $upload = store_preview_upload($_FILES['attachment'] ?? [], 'complaint');
            if ($upload !== null) {
                $draft['attachment_path'] = $upload['path'];
                $draft['attachment_name'] = $upload['name'];
            }

            set_preview_draft('complaint', $draft);

            $required = [
                'person_complained_of', 'date_of_incident', 'place_of_incident',
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
            $categoryIdInput = (int)($_POST['category_id'] ?? 0);
            $categoryNameInput = '';
            if ($categoryIdInput > 0) {
                try {
                    $catLookupStmt = $pdo->prepare(
                        'SELECT name FROM suggestion_categories WHERE id = :id AND is_active = 1 LIMIT 1'
                    );
                    $catLookupStmt->execute([':id' => $categoryIdInput]);
                    $categoryNameInput = (string)($catLookupStmt->fetchColumn() ?: '');
                } catch (PDOException $e) {
                    $categoryNameInput = '';
                }
            }

            $draft = [
                'category_id' => $categoryIdInput > 0 && $categoryNameInput !== '' ? (string)$categoryIdInput : '',
                'category_name' => $categoryNameInput,
                'subject' => trim((string)($_POST['subject'] ?? $categoryNameInput)),
                'description' => trim((string)($_POST['description'] ?? '')),
                'terms_agreement_accepted' => isset($_POST['terms_agreement_accepted']) ? '1' : '',
            ];

            $upload = store_preview_upload($_FILES['reference_photo'] ?? [], 'suggestion');
            if ($upload !== null) {
                $draft['attachment_path'] = $upload['path'];
                $draft['attachment_name'] = $upload['name'];
            }

            set_preview_draft('suggestion', $draft);

            $required = ['category_id', 'subject', 'description', 'terms_agreement_accepted'];
            $missing = [];
            foreach ($required as $key) {
                if ($draft[$key] === '' || $draft[$key] === null) {
                    $missing[] = $key;
                }
            }
            if (!empty($missing)) {
                $flashMessage = $categoryIdInput > 0 && $categoryNameInput === ''
                    ? 'The selected category is no longer available. Please choose another.'
                    : 'Please complete all required fields before proceeding.';
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

// Suggestions only: give the student a heads-up if a very similar suggestion
// was already sent for the same category, so they don't file an accidental
// duplicate. This is informational only - it never blocks submission.
$duplicateSuggestion = null;
if ($mode === 'suggestion') {
    require_once __DIR__ . '/../suggestion_flow.php';
    $duplicateSuggestion = check_suggestion_duplicate($pdo, (int)($draft['category_id'] ?? 0), (string)($draft['description'] ?? ''));
}

// Complaints only: there is no category dropdown anymore - the AI decides
// the category automatically from the written text (shown for
// transparency below, informational only). Routing itself depends only on
// who is being reported - faculty/staff always goes to the SAS Office
// (Admin), a fellow student always goes to the reporting student's own
// college dean - matching process_complaint.php exactly.
$aiClassification = null;
$aiUrgency = null;
$aiRouteRole = null; // 'dean' | 'admin'
$isSpamBlocked = false;
if ($mode === 'complaint') {
    require_once __DIR__ . '/../complaint_ai_helpers.php';
    $aiCombinedText = trim((string)($draft['act_complained_of'] ?? '') . ' ' . (string)($draft['desired_outcome'] ?? ''));
    if ($aiCombinedText !== '') {
        // Reuse a cached result from an earlier render of this same draft
        // (e.g. the student went back and forward again) instead of calling
        // the AI a second time for identical text.
        if (array_key_exists('ai_category_id', $draft)) {
            $aiClassification = [
                'category_id' => $draft['ai_category_id'],
                'category_name' => $draft['ai_category_name'] ?? null,
                'confidence' => (float)($draft['ai_confidence'] ?? 0.0),
                'language' => $draft['ai_language'] ?? null,
                'source' => $draft['ai_source'] ?? 'local',
                'is_spam' => !empty($draft['ai_is_spam']),
            ];
            $aiUrgency = ['score' => (int)($draft['ai_urgency_score'] ?? 0), 'level' => $draft['ai_urgency_level'] ?? 'low'];
        } else {
            $aiClassification = ai_classify_text_smart($pdo, $aiCombinedText);
            $aiUrgency = ai_urgency_score($aiCombinedText);

            // Stash into the session draft so process_complaint.php reuses
            // this exact result instead of calling the AI a second time.
            $_SESSION['submission_drafts']['complaint']['ai_category_id'] = $aiClassification['category_id'];
            $_SESSION['submission_drafts']['complaint']['ai_category_name'] = $aiClassification['category_name'];
            $_SESSION['submission_drafts']['complaint']['ai_confidence'] = $aiClassification['confidence'];
            $_SESSION['submission_drafts']['complaint']['ai_language'] = $aiClassification['language'] ?? null;
            $_SESSION['submission_drafts']['complaint']['ai_source'] = $aiClassification['source'] ?? 'local';
            $_SESSION['submission_drafts']['complaint']['ai_is_spam'] = !empty($aiClassification['is_spam']);
            $_SESSION['submission_drafts']['complaint']['ai_urgency_score'] = $aiUrgency['score'];
            $_SESSION['submission_drafts']['complaint']['ai_urgency_level'] = $aiUrgency['level'];
        }

        // Only Groq can judge spam/nonsense (the local fallback always
        // reports is_spam=false) - block submission when it's confident this
        // isn't a genuine complaint attempt.
        if (!empty($aiClassification['is_spam'])) {
            $isSpamBlocked = true;
        }
    }

    $previewReportedTypeForRoute = (string)($draft['reported_type'] ?? 'student');
    $aiRouteRole = $previewReportedTypeForRoute === 'faculty' ? 'admin' : 'dean';
}
$aiRouteFieldLabel = $aiRouteRole === 'dean' ? 'College Dean' : ($aiRouteRole === 'admin' ? 'SAS Office (Admin)' : 'Pending review');
$aiRouteSentenceLabel = $aiRouteRole === 'dean' ? 'your college dean' : ($aiRouteRole === 'admin' ? 'the SAS Office (Admin)' : null);

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
.notice.info { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
/* Spam-blocked popup modal */
.spam-overlay { position: fixed; inset: 0; display: none; align-items: center; justify-content: center; background: rgba(15,23,42,0.55); z-index: 9999; padding: 20px; }
.spam-overlay.visible { display: flex; }
.spam-card { background: #fff; padding: 24px 26px; border-radius: 14px; box-shadow: 0 20px 45px rgba(15,23,42,0.25); max-width: 460px; width: 100%; text-align: center; border-left: 6px solid #dc2626; }
.spam-card .spam-icon { width: 46px; height: 46px; margin: 0 auto 12px; border-radius: 50%; background: #fee2e2; color: #dc2626; display: flex; align-items: center; justify-content: center; font-size: 24px; }
.spam-card h3 { margin-bottom: 10px; color: #111827; font-size: 18px; }
.spam-card p { color: #4b5563; font-size: 14px; line-height: 1.5; margin-bottom: 18px; }
.spam-close { display: inline-block; padding: 10px 20px; background: #dc2626; color: #fff; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; border: none; }
.spam-close:hover { background: #b91c1c; }
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

        <?php if ($duplicateSuggestion !== null): ?>
            <div class="notice info">
                <i class='bx bx-info-circle'></i>
                A similar suggestion in this category already exists (Ticket <?php echo e($duplicateSuggestion['ticket_no']); ?>, <?php echo e((string)$duplicateSuggestion['percent']); ?>% similar) and is still being handled. You can still submit yours if it's actually different.
            </div>
        <?php endif; ?>

        <?php if ($aiUrgency !== null && $aiUrgency['level'] !== 'low'): ?>
            <div class="notice info">
                <i class='bx bx-error'></i>
                This complaint reads as <strong><?php echo e(ucfirst((string)$aiUrgency['level'])); ?> urgency</strong> based on its wording. It will be flagged accordingly for whoever reviews it.
            </div>
        <?php endif; ?>

        <?php if ($isSpamBlocked): ?>
            <div class="notice error">
                <i class='bx bx-block'></i>
                This submission cannot proceed as written - see the popup for details.
            </div>
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
                    <div class="field"><div class="field-label">Category (AI-assigned)</div><div class="field-value"><?php echo field($aiClassification !== null && $aiClassification['category_name'] !== null ? (string)$aiClassification['category_name'] : 'Pending review'); ?></div></div>
                    <?php $previewLanguageLabel = $aiClassification !== null ? groq_language_label($aiClassification['language'] ?? null) : ''; ?>
                    <?php if ($previewLanguageLabel !== ''): ?>
                        <div class="field"><div class="field-label">Detected Language</div><div class="field-value"><?php echo field($previewLanguageLabel); ?></div></div>
                    <?php endif; ?>
                    <div class="field"><div class="field-label">Will be sent to</div><div class="field-value"><?php echo field($aiRouteFieldLabel); ?></div></div>
                    <div class="field full"><div class="field-label">Act/s Complained Of</div><div class="field-value"><?php echo field((string)($draft['act_complained_of'] ?? '')); ?></div></div>
                    <div class="field full"><div class="field-label">Desired Outcome</div><div class="field-value"><?php echo field((string)($draft['desired_outcome'] ?? '')); ?></div></div>
                    <div class="field full"><div class="field-label">Supporting File</div><div class="field-value"><?php echo !empty($draft['attachment_name']) ? field((string)$draft['attachment_name']) : 'No file attached'; ?></div></div>
                <?php else: ?>
                    <div class="field"><div class="field-label">Category</div><div class="field-value"><?php echo field((string)($draft['category_name'] ?? '')); ?></div></div>
                    <div class="field full"><div class="field-label">Idea Title</div><div class="field-value"><?php echo field((string)($draft['subject'] ?? '')); ?></div></div>
                    <div class="field full"><div class="field-label">Detailed Suggestion</div><div class="field-value"><?php echo field((string)($draft['description'] ?? '')); ?></div></div>
                    <div class="field full"><div class="field-label">Supporting File</div><div class="field-value"><?php echo !empty($draft['attachment_name']) ? field((string)$draft['attachment_name']) : 'No file attached'; ?></div></div>
                <?php endif; ?>
            </div>

            <div class="actions">
                <a class="btn btn-edit" href="<?php echo e($labels['edit_url']); ?>"><i class='bx bx-edit'></i> Edit</a>
                <?php if (!$isSpamBlocked): ?>
                    <form method="POST" action="<?php echo e($labels['submit_url']); ?>" style="display:inline;">
                        <input type="hidden" name="draft_token" value="<?php echo e(get_preview_token($mode)); ?>">
                        <button type="submit" class="btn btn-proceed"><i class='bx bx-check-circle'></i> <?php echo e($labels['submit_label']); ?></button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if ($isSpamBlocked): ?>
    <div id="spamOverlay" class="spam-overlay" role="dialog" aria-modal="true" aria-labelledby="spamModalTitle">
        <div class="spam-card" role="document">
            <div class="spam-icon"><i class='bx bx-block'></i></div>
            <h3 id="spamModalTitle">This doesn't look like a genuine complaint</h3>
            <p>It reads like spam, a test message, or text unrelated to any real incident. Please go back and describe an actual incident, or contact the SAS Office directly if you believe this is a mistake. This submission cannot proceed as written.</p>
            <button id="spamClose" class="spam-close">OK, let me edit it</button>
        </div>
    </div>
    <script>
    window.addEventListener('load', function() {
        const overlay = document.getElementById('spamOverlay');
        const close = document.getElementById('spamClose');
        if (!overlay) return;
        overlay.classList.add('visible');
        function hide() { overlay.classList.remove('visible'); }
        close.addEventListener('click', hide, { once: true });
        overlay.addEventListener('click', function(e){ if (e.target === overlay) hide(); });
    });
    </script>
<?php endif; ?>
</body>
</html>
