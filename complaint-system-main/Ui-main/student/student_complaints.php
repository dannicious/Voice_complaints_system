<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../faculty_helpers.php';
require_once __DIR__ . '/../school_year_helpers.php';

$pageMode = (string)($_GET['mode'] ?? 'complaint');
$flashStatus = (string)($_GET['status'] ?? '');
$flashMessage = trim((string)($_GET['msg'] ?? ''));

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

// Filing a new complaint/suggestion is only allowed while viewing the
// current school year. A student viewing a past school year (via the
// topbar switcher) gets a locked, read-only notice instead of the form.
$studentProfileId = sy_resolve_student_profile_id($pdo);
$schoolYearCurrent = sy_current($pdo);
$schoolYearSelected = $studentProfileId > 0 ? sy_get_selected($pdo, $studentProfileId) : $schoolYearCurrent;
$semesterCurrent = semester_current();
$semesterSelected = $studentProfileId > 0 ? semester_get_selected() : $semesterCurrent;

if ($studentProfileId > 0 && ($schoolYearSelected !== $schoolYearCurrent || $semesterSelected !== $semesterCurrent)) {
    $lockedIsSuggestion = $pageMode === 'suggestion';
    $lockedTitle = $lockedIsSuggestion ? 'Send a Suggestion' : 'File a Complaint';
    $lockedAction = $lockedIsSuggestion ? 'Sending a suggestion' : 'Filing a complaint';
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($lockedTitle); ?> - VOICE</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <style>
    * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
    body { background: #f4f6fb; }
    .main { margin-left: 260px; margin-top: 61px; padding: 25px; min-height: calc(100vh - 61px); display: flex; justify-content: center; }
    .content-wrapper { width: 100%; max-width: 700px; }
    .page-title { margin-bottom: 25px; color: #333; font-weight: 600; font-size: 24px; }
    .locked-card { background: #fff; padding: 45px 35px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid #eee; text-align: center; }
    .locked-card i.bx-lock-alt { font-size: 46px; color: #f59e0b; margin-bottom: 16px; }
    .locked-card h3 { font-size: 18px; color: #1f2937; margin-bottom: 10px; }
    .locked-card p { color: #6b7280; font-size: 14px; margin-bottom: 26px; line-height: 1.6; }
    .btn-blue { padding: 12px 25px; background: #6d28d9; color: #fff; border-radius: 8px; text-decoration: none; font-size: 15px; font-weight: 500; display: inline-flex; align-items: center; gap: 8px; border: none; cursor: pointer; }
    .btn-blue:hover { background: #5d1fa0; }
    @media (max-width: 1024px) { .main { margin-left: 0 !important; padding: 16px !important; } }
    </style>
    </head>
    <body>
    <?php include 'student_topbar.php'; ?>
    <?php include 'student_sidebar.php'; ?>
    <div class="main">
        <div class="content-wrapper">
            <h2 class="page-title"><?php echo e($lockedTitle); ?></h2>
            <div class="locked-card">
                <i class='bx bx-lock-alt'></i>
                <h3>You're viewing School Year <?php echo e($schoolYearSelected); ?>, <?php echo e(semester_display_label($semesterSelected)); ?></h3>
                <p>
                    <?php echo e($lockedAction); ?> is only available while viewing the current school year and semester
                    (<?php echo e($schoolYearCurrent); ?>, <?php echo e(semester_display_label($semesterCurrent)); ?>). This past period's records are read-only.
                    Switch back to the current school year to continue.
                </p>
                <form method="POST" action="set_school_year.php">
                    <input type="hidden" name="school_year" value="<?php echo e($schoolYearCurrent); ?>">
                    <input type="hidden" name="semester" value="<?php echo e($semesterCurrent); ?>">
                    <input type="hidden" name="redirect_to" value="<?php echo e($_SERVER['REQUEST_URI'] ?? 'student_complaints.php'); ?>">
                    <button type="submit" class="btn-blue"><i class='bx bx-refresh'></i> Switch to <?php echo e($schoolYearCurrent); ?></button>
                </form>
            </div>
        </div>
    </div>
    </body>
    </html>
    <?php
    exit;
}

function draft_value(array $draft, string $key, string $default = ''): string
{
    return e((string)($draft[$key] ?? $default));
}

function draft_selected(array $draft, string $key, string $expected): string
{
    return (string)($draft[$key] ?? '') === $expected ? 'selected' : '';
}

function draft_checked(array $draft, string $key): string
{
    return !empty($draft[$key]) ? 'checked' : '';
}
// Complaint category is no longer picked by the student - the AI
// classifier assigns it automatically from the written complaint text
// (see complaint_ai_helpers.php / student_submission_preview.php).
$placeOfIncidentOptions = ['CTAS Building', 'CCJ Building', 'CCIS Building', 'GYM', 'BACK ADMIN'];
$reportedStudentOptions = [];
$studentBrowseColleges = [];
$studentBrowsePrograms = [];
$studentBrowseYearLevels = [];
$studentBrowseSections = [];
$selectedReportedStudentIds = [];
$draftKey = $pageMode === 'suggestion' ? 'suggestion' : 'complaint';

// Clear draft immediately if coming from a successful submission
if ($flashStatus === 'success') {
    if (isset($_SESSION['submission_drafts']) && is_array($_SESSION['submission_drafts'])) {
        unset($_SESSION['submission_drafts'][$draftKey]);
    }
    if (isset($_SESSION['submission_draft_tokens']) && is_array($_SESSION['submission_draft_tokens'])) {
        unset($_SESSION['submission_draft_tokens'][$draftKey]);
    }
    $submissionDraft = [];
} else {
    $submissionDraft = $_SESSION['submission_drafts'][$draftKey] ?? [];
}
$placeOfIncidentDraftValue = (string)($submissionDraft['place_of_incident'] ?? '');
$placeOfIncidentIsOther = $placeOfIncidentDraftValue !== '' && !in_array($placeOfIncidentDraftValue, $placeOfIncidentOptions, true);

if ($pageMode !== 'suggestion') {
    try {
        $studentOptionsStmt = $pdo->prepare(
            'SELECT sp.id, sp.student_number, sp.first_name, sp.last_name, sp.section, sp.year_level, u.profile_pic,
                    c.name AS college_name, p.name AS program_name
             FROM student_profiles sp
             LEFT JOIN users u ON u.id = sp.user_id
             LEFT JOIN colleges c ON c.id = sp.college_id
             LEFT JOIN programs p ON p.id = sp.program_id
             WHERE u.role = :role AND sp.status = :status AND sp.user_id <> :current_user_id
             ORDER BY sp.first_name ASC, sp.last_name ASC, sp.student_number ASC'
        );
        $studentOptionsStmt->execute([
            ':role' => 'student',
            ':status' => 'active',
            ':current_user_id' => (int)($_SESSION['user_id'] ?? 0),
        ]);
        $reportedStudentOptions = $studentOptionsStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $reportedStudentOptions = [];
    }

    // Distinct filter values for the "Browse students" modal, so a student
    // who doesn't know a name can instead narrow the list down by college,
    // program/department, year level, and section.
    $studentBrowseColleges = [];
    $studentBrowsePrograms = [];
    $studentBrowseYearLevels = [];
    $studentBrowseSections = [];
    foreach ($reportedStudentOptions as $studentOption) {
        $collegeName = trim((string)($studentOption['college_name'] ?? ''));
        $programName = trim((string)($studentOption['program_name'] ?? ''));
        $yearLevelValue = trim((string)($studentOption['year_level'] ?? ''));
        $sectionValue = trim((string)($studentOption['section'] ?? ''));
        if ($collegeName !== '') { $studentBrowseColleges[$collegeName] = true; }
        if ($programName !== '') { $studentBrowsePrograms[$programName] = true; }
        if ($yearLevelValue !== '') { $studentBrowseYearLevels[$yearLevelValue] = true; }
        if ($sectionValue !== '') { $studentBrowseSections[$sectionValue] = true; }
    }
    $studentBrowseColleges = array_keys($studentBrowseColleges);
    $studentBrowsePrograms = array_keys($studentBrowsePrograms);
    $studentBrowseYearLevels = array_keys($studentBrowseYearLevels);
    $studentBrowseSections = array_keys($studentBrowseSections);
    sort($studentBrowseColleges);
    sort($studentBrowsePrograms);
    sort($studentBrowseYearLevels, SORT_NATURAL);
    sort($studentBrowseSections, SORT_NATURAL);

    $reportedFacultyOptions = faculty_staff_options($pdo);

    $reportedTypeDraft = (string)($submissionDraft['reported_type'] ?? 'student');
    if (!in_array($reportedTypeDraft, ['student', 'faculty'], true)) {
        $reportedTypeDraft = 'student';
    }

    $selectedReportedFacultyIds = [];
    if (!empty($submissionDraft['reported_faculty_ids']) && is_array($submissionDraft['reported_faculty_ids'])) {
        foreach ($submissionDraft['reported_faculty_ids'] as $facultyId) {
            $facultyId = (int)$facultyId;
            if ($facultyId > 0) {
                $selectedReportedFacultyIds[] = $facultyId;
            }
        }
    }

    $selectedReportedStudentIds = [];
    if (!empty($submissionDraft['reported_student_ids']) && is_array($submissionDraft['reported_student_ids'])) {
        foreach ($submissionDraft['reported_student_ids'] as $studentId) {
            $studentId = (int)$studentId;
            if ($studentId > 0) {
                $selectedReportedStudentIds[] = $studentId;
            }
        }
    }
}

if ($pageMode === 'suggestion') {
    // Offices a suggestion can be routed to directly — the same set staff
    // accounts and suggestion categories are matched against at submission.
    $suggestionOffices = [];
    try {
        $officeStmt = $pdo->query(
            "SELECT office FROM staff_profiles WHERE office IS NOT NULL AND TRIM(office) <> ''
             UNION
             SELECT office FROM suggestion_categories WHERE office IS NOT NULL AND TRIM(office) <> ''
             ORDER BY office ASC"
        );
        foreach ($officeStmt->fetchAll(PDO::FETCH_COLUMN) as $office) {
            $office = trim((string)$office);
            if ($office !== '' && !in_array($office, $suggestionOffices, true)) {
                $suggestionOffices[] = $office;
            }
        }
    } catch (PDOException $e) {
    }
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Send a Suggestion - VOICE</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <style>
    * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
    body { background: #f4f6fb; }
    .main { margin-left: 260px; margin-top: 61px; padding: 25px; min-height: calc(100vh - 61px); display: flex; justify-content: center; }
    .content-wrapper { width: 100%; max-width: 800px; }
    .page-title { margin-bottom: 25px; color: #333; font-weight: 600; font-size: 24px; text-align: left; }
    .data-card { background: #fff; padding: 30px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); width: 100%; border: 1px solid #eee; }
    .form-group { margin-bottom: 20px; }
    .form-group label { display: block; font-size: 14px; font-weight: 500; color: #444; margin-bottom: 8px; }
    .input-field { width: 100%; padding: 12px 15px; border: 1px solid #e5e7eb; border-radius: 8px; background: #f9fafb; font-size: 14px; outline: none; transition: 0.2s; }
    .input-field:focus { border-color: #10b981; background: #fff; box-shadow: 0 0 0 3px rgba(16,185,129,0.1); }
    .file-upload-wrapper { position: relative; border: 2px dashed #e5e7eb; border-radius: 8px; background: #f9fafb; padding: 30px; text-align: center; transition: 0.2s; cursor: pointer; }
    .file-upload-wrapper:hover { border-color: #10b981; background: #f0fdf4; }
    .file-upload-wrapper i { font-size: 40px; color: #10b981; margin-bottom: 10px; }
    .file-upload-wrapper p { font-size: 14px; color: #6b7280; margin-bottom: 5px; }
    .file-upload-wrapper span { font-size: 12px; color: #9ca3af; }
    .file-input { position: absolute; top: 0; left: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; }
    .file-name-display { margin-top: 10px; font-size: 13px; color: #10b981; font-weight: 500; display: none; }
    .checkbox-group { display: flex; align-items: center; gap: 10px; margin-bottom: 25px; }
    .checkbox-group input { width: 16px; height: 16px; cursor: pointer; }
    .checkbox-group label { margin: 0; font-size: 14px; cursor: pointer; }
    .btn-green { padding: 12px 25px; background: #10b981; color: #fff; border-radius: 8px; text-decoration: none; font-size: 15px; font-weight: 500; cursor: pointer; border: none; transition: 0.2s; }
    .btn-green:hover { background: #059669; }
    /* Flash modal */
    .flash-overlay { position: fixed; inset: 0; display: none; align-items: center; justify-content: center; background: rgba(0,0,0,0.35); z-index: 9999; }
    .flash-card { background: #fff; padding: 22px 26px; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.12); max-width: 420px; width: calc(100% - 40px); text-align: center; border-left: 6px solid #10b981; }
    .flash-card h3 { margin-bottom: 8px; color: #111827; font-size: 18px; }
    .flash-card p { color: #4b5563; font-size: 14px; margin-bottom: 14px; }
    .flash-close { display: inline-block; padding: 8px 14px; background: #10b981; color: #fff; border-radius: 8px; text-decoration: none; cursor: pointer; border: none; }
    </style>
    </head>
    <body>
    <?php include 'student_topbar.php'; ?>
    <?php include 'student_sidebar.php'; ?>
    <div class="main">
        <div class="content-wrapper">
            <h2 class="page-title">Send a Suggestion</h2>
            <div class="data-card">
                <form action="student_submission_preview.php?mode=suggestion" method="POST" enctype="multipart/form-data">
                    <fieldset style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                        <legend style="font-weight: 600; color: #333; padding: 0 10px;">Suggestion Details <span style="color: red;">*</span></legend>
                        <div class="form-group">
                            <label>Select Office <span style="color: red;">*</span></label>
                            <select name="office" class="input-field" required>
                                <option value="" disabled <?php echo empty($submissionDraft['office']) ? 'selected' : ''; ?>>Select an office...</option>
                                <?php foreach ($suggestionOffices as $officeOption): ?>
                                    <option value="<?php echo htmlspecialchars($officeOption, ENT_QUOTES, 'UTF-8'); ?>" <?php echo draft_selected($submissionDraft, 'office', $officeOption); ?>><?php echo htmlspecialchars($officeOption, ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($suggestionOffices === []): ?>
                                <div class="help-text" style="color:#b45309;font-size:12px;margin-top:6px;">No offices have been set up yet. Please contact the SAS Office.</div>
                            <?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label>Detailed Suggestion <span style="color: red;">*</span></label>
                            <textarea name="description" class="input-field" rows="4" placeholder="Explain your idea clearly..." required><?php echo draft_value($submissionDraft, 'description'); ?></textarea>
                        </div>
                    </fieldset>
                    <fieldset style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                        <legend style="font-weight: 600; color: #333; padding: 0 10px;">Supporting Documents</legend>
                        <div class="form-group">
                            <label>Attach a Reference Photo/Sketch (Optional)</label>
                            <div class="file-upload-wrapper">
                                <i class='bx bx-cloud-upload'></i>
                                <p>Click to browse or drag and drop your image here</p>
                                <span>Supports JPG, PNG, JPEG (Max 5MB)</span>
                                <input type="file" name="reference_photo" class="file-input" accept="image/*" id="photoInput" onchange="displayFileName()">
                            </div>
                            <div id="fileNameDisplay" class="file-name-display" style="<?php echo !empty($submissionDraft['attachment_name']) ? 'display:block;' : ''; ?>"><i class='bx bx-check-circle'></i> File selected: <span id="fileNameText"><?php echo draft_value($submissionDraft, 'attachment_name'); ?></span></div>
                        </div>
                    </fieldset>
                    <fieldset style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                        <legend style="font-weight: 600; color: #333; padding: 0 10px;">Terms of Agreement <span style="color: red;">*</span></legend>
                        <p style="font-size: 14px; font-weight: 600; color: #374151; margin-bottom: 15px; line-height: 1.6;">Upon filling-up this form, I declare that the information provided is true and accurate to the best of my knowledge. I understand that my suggestion will be reviewed by the University administration for consideration.</p>
                        <div class="checkbox-group" style="margin-bottom: 15px;">
                            <input type="checkbox" name="terms_agreement_accepted" id="terms-agree" value="1" <?php echo draft_checked($submissionDraft, 'terms_agreement_accepted'); ?> required>
                            <label for="terms-agree" style="font-weight: 400; color: #6b7280;">I agree that the provided information is true and may be used by the University for improvement purposes. <span style="color: red;">*</span></label>
                        </div>
                    </fieldset>
                    <button type="submit" class="btn-green">Submit Suggestion</button>
                </form>
            </div>
        </div>
    </div>
    <script>
    function displayFileName() {
        const input = document.getElementById('photoInput');
        const displayArea = document.getElementById('fileNameDisplay');
        const nameText = document.getElementById('fileNameText');
        if (input.files && input.files.length > 0) {
            nameText.textContent = input.files[0].name;
            displayArea.style.display = 'block';
        } else {
            displayArea.style.display = 'none';
        }
    }
    </script>
    <?php if ($pageMode === 'suggestion' && $flashStatus === 'error' && $flashMessage !== ''): ?>
        <div class="notice error" style="margin: 20px auto 0; max-width: 800px;">
            <?php echo htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>
    <?php if ($pageMode === 'suggestion' && $flashStatus === 'success' && $flashMessage !== ''): ?>
    <div id="flashOverlay" class="flash-overlay" role="dialog" aria-modal="true">
        <div class="flash-card" role="document">
            <h3>Submission Successful</h3>
            <p><?php echo htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8'); ?></p>
            <button id="flashClose" class="flash-close">OK</button>
        </div>
    </div>
    <script>
    window.addEventListener('load', function() {
        const overlay = document.getElementById('flashOverlay');
        const close = document.getElementById('flashClose');
        if (!overlay) return;
        overlay.style.display = 'flex';
        function hide() { overlay.style.display = 'none'; }
        close.addEventListener('click', hide, { once: true });
        overlay.addEventListener('click', function(e){ if (e.target === overlay) hide(); });
        setTimeout(hide, 3500);
    });
    </script>
    <?php endif; ?>
    </body>
    </html>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>File a Complaint - VOICE</title>
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
    display: flex;
    justify-content: center; /* Centers the content block horizontally */
}

/* Wrapper to keep title and card aligned on the left */
.content-wrapper {
    width: 100%;
    max-width: 800px;
}

/* ===== FORM STYLES ===== */
.page-title {
    margin-bottom: 25px;
    color: #333;
    font-weight: 600;
    font-size: 24px;
    text-align: left; /* Aligned with the form's left edge */
}

.data-card {
    background: #fff;
    padding: 30px;
    border-radius: 12px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.03);
    width: 100%;
    border: 1px solid #eee;
}

.form-group { margin-bottom: 20px; }
.incident-details-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 15px; }
.form-group label { display: block; font-size: 14px; font-weight: 500; color: #444; margin-bottom: 8px; }
.input-field { width: 100%; padding: 12px 15px; border: 1px solid #e5e7eb; border-radius: 8px; background: #f9fafb; font-size: 14px; outline: none; transition: 0.2s; }
.input-field:focus { border-color: #6d28d9; background: #fff; box-shadow: 0 0 0 3px rgba(109,40,217,0.1); }
.help-text { margin-top: 8px; font-size: 12px; color: #6b7280; line-height: 1.5; }
.reported-type-switch { display: flex; gap: 10px; flex-wrap: wrap; }
.reported-type-option { display: inline-flex; align-items: center; gap: 8px; padding: 10px 16px; border: 1px solid #e5e7eb; border-radius: 8px; cursor: pointer; font-size: 14px; font-weight: 500; color: #374151; background: #fff; }
.reported-type-option:hover { border-color: #c4b5fd; }
.reported-type-option:has(input:checked) { border-color: #7c3aed; background: #f5f3ff; color: #5b21b6; }
.reported-type-option input { accent-color: #7c3aed; margin: 0; }
.student-picker { position: relative; }
.student-search-row { display: flex; flex-wrap: nowrap; align-items: center; gap: 10px; }
.student-picker-input { flex: 1 1 auto; min-width: 0; width: auto; padding: 12px 15px; border: 1px solid #e5e7eb; border-radius: 8px; background: #f9fafb; font-size: 14px; font-family: inherit; line-height: 1.4; outline: none; transition: 0.2s; box-sizing: border-box; }
.student-picker-input:focus { border-color: #6d28d9; background: #fff; box-shadow: 0 0 0 3px rgba(109,40,217,0.1); }
.student-picker-options { position: absolute; top: calc(100% + 6px); left: 0; right: 0; background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; box-shadow: 0 10px 24px rgba(15,23,42,0.12); max-height: 220px; overflow-y: auto; z-index: 20; display: none; }
.student-option { width: 100%; padding: 10px 12px; text-align: left; border: none; background: #fff; cursor: pointer; font-size: 13px; color: #374151; display: flex; flex-direction: column; gap: 2px; align-items: flex-start; }
.student-option:hover, .student-option.active { background: #f5f3ff; color: #5b21b6; }
.student-option.selected { background: #ede9fe; font-weight: 600; }
.student-option-name { font-weight: 600; color: #111827; }
.student-option-details { font-size: 12px; color: #6b7280; }
.student-chips { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 8px; }
.student-chip { display: inline-flex; align-items: center; gap: 6px; background: #ede9fe; color: #5b21b6; padding: 6px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; }
.student-chip button { border: none; background: transparent; color: inherit; cursor: pointer; font-size: 12px; }

/* "Browse students" link + modal */
.browse-students-btn { flex: 0 0 auto; box-sizing: border-box; border: 1px solid #6d28d9; background: #fff; color: #6d28d9; font-weight: 700; font-family: inherit; font-size: 14px; line-height: 1.4; cursor: pointer; padding: 12px 16px; border-radius: 8px; display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; }
.browse-students-btn:hover { background: #f5f3ff; }
.browse-students-btn i { font-size: 16px; }
.browse-modal-overlay { display: none; position: fixed; inset: 0; background: rgba(15,23,42,0.55); z-index: 2000; align-items: center; justify-content: center; padding: 20px; }
.browse-modal-overlay.visible { display: flex; }
.browse-modal { background: #fff; border-radius: 14px; width: min(880px, 100%); max-height: min(720px, 92vh); display: flex; flex-direction: column; box-shadow: 0 30px 60px rgba(15,23,42,0.3); overflow: hidden; }
.browse-modal-header { display: flex; align-items: flex-start; justify-content: space-between; padding: 18px 22px; border-bottom: 1px solid #e5e7eb; }
.browse-modal-header-left { display: flex; align-items: center; gap: 12px; }
.browse-modal-icon { width: 38px; height: 38px; border-radius: 10px; background: #ede9fe; color: #6d28d9; display: inline-flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
.browse-modal-title { font-weight: 700; font-size: 17px; color: #111827; }
.browse-modal-subtitle { font-size: 13px; color: #6b7280; margin-top: 2px; }
.browse-modal-close { border: none; background: transparent; font-size: 22px; line-height: 1; color: #6b7280; cursor: pointer; width: 28px; height: 28px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
.browse-modal-close:hover { background: #f3f4f6; color: #111827; }
.browse-modal-filters { display: flex; flex-wrap: wrap; gap: 10px; padding: 16px 22px 0; align-items: flex-end; }
.browse-filter-field { display: flex; flex-direction: column; gap: 4px; flex: 1 1 150px; min-width: 140px; }
.browse-filter-field label { font-size: 11px; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: .04em; }
.browse-filter-field select { padding: 9px 10px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 13px; background: #fff; color: #111827; }
.browse-filter-search { flex: 1 1 220px; }
.browse-search-wrap { position: relative; display: flex; align-items: center; }
.browse-search-wrap i { position: absolute; left: 10px; color: #9ca3af; font-size: 15px; }
.browse-search-wrap input { width: 100%; padding: 9px 10px 9px 32px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 13px; background: #fff; color: #111827; }
.browse-modal-toolbar { display: flex; align-items: center; justify-content: space-between; padding: 14px 22px 8px; flex-wrap: wrap; gap: 10px; }
.browse-total-count { font-size: 13px; font-weight: 700; color: #111827; }
.browse-filter-clear { flex: 0 0 auto; min-width: 0; }
.browse-clear-filters-btn { height: 38px; padding: 0 4px; border: none; background: none; font-size: 13px; font-weight: 700; color: #6b46c1; cursor: pointer; white-space: nowrap; }
.browse-clear-filters-btn:hover { text-decoration: underline; }
.browse-table-wrap { flex: 1; overflow-y: auto; padding: 0 22px 8px; }
.browse-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.browse-table thead th { position: sticky; top: 0; background: #fff; text-align: left; padding: 8px 10px; font-size: 11px; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: .03em; border-bottom: 1px solid #e5e7eb; }
.browse-th-check { width: 34px; }
.browse-table tbody tr { cursor: pointer; border-bottom: 1px solid #f3f4f6; }
.browse-table tbody tr:hover { background: #f9fafb; }
.browse-table tbody tr.checked { background: #eff6ff; }
.browse-table td { padding: 8px 10px; vertical-align: middle; color: #374151; }
.browse-row-name-cell { display: flex; align-items: center; gap: 10px; }
.browse-row-avatar { width: 30px; height: 30px; border-radius: 50%; object-fit: cover; flex-shrink: 0; background: #ede9fe; }
.browse-row-name { font-weight: 600; color: #111827; }
.browse-modal-empty { text-align: center; color: #9ca3af; font-size: 13px; padding: 30px 10px; }
.browse-modal-footer { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 22px; border-top: 1px solid #e5e7eb; }
.browse-cancel-btn { border: 1px solid #d1d5db; background: #fff; color: #374151; border-radius: 8px; padding: 10px 18px; font-size: 13px; font-weight: 600; cursor: pointer; }
.browse-cancel-btn:hover { background: #f3f4f6; }

@media (max-width: 1024px) {
    .incident-details-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}

@media (max-width: 600px) {
    .incident-details-grid { grid-template-columns: 1fr; }
}

/* File Upload Styles */
.file-upload-wrapper {
    position: relative;
    border: 2px dashed #e5e7eb;
    border-radius: 8px;
    background: #f9fafb;
    padding: 30px;
    text-align: center;
    transition: 0.2s;
    cursor: pointer;
}

.file-upload-wrapper:hover {
    border-color: #6d28d9;
    background: #f5f3f7;
}

.file-upload-wrapper i {
    font-size: 40px;
    color: #6d28d9;
    margin-bottom: 10px;
}

.file-upload-wrapper p {
    font-size: 14px;
    color: #6b7280;
    margin-bottom: 5px;
}

.file-upload-wrapper span {
    font-size: 12px;
    color: #9ca3af;
}

.file-input {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    opacity: 0;
    cursor: pointer;
}

.file-name-display {
    margin-top: 10px;
    font-size: 13px;
    color: #10b981;
    font-weight: 500;
    display: none;
}

/* Buttons and Checkbox */
.checkbox-group {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 25px;
}

.checkbox-group input {
    width: 16px;
    height: 16px;
    cursor: pointer;
}

.checkbox-group label {
    margin: 0;
    font-size: 14px;
    cursor: pointer;
}

.btn-blue { 
    padding: 12px 25px; 
    background: #6d28d9; 
    color: #fff; 
    border-radius: 8px; 
    text-decoration: none; 
    font-size: 15px; 
    font-weight: 500; 
    cursor: pointer; 
    border: none; 
    transition: 0.2s;
}
.btn-blue:hover { background: #5d1fa0; }

/* Flash modal (complaint view) */
.flash-overlay { position: fixed; inset: 0; display: none; align-items: center; justify-content: center; background: rgba(0,0,0,0.35); z-index: 9999; }
.flash-card { background: #fff; padding: 22px 26px; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.12); max-width: 420px; width: calc(100% - 40px); text-align: center; border-left: 6px solid #10b981; }
.flash-card h3 { margin-bottom: 8px; color: #111827; font-size: 18px; }
.flash-card p { color: #4b5563; font-size: 14px; margin-bottom: 14px; }
.flash-close { display: inline-block; padding: 8px 14px; background: #10b981; color: #fff; border-radius: 8px; text-decoration: none; cursor: pointer; border: none; }

</style>
</head>

<body>

<?php include 'student_topbar.php'; ?>

<?php include 'student_sidebar.php'; ?>

<div class="main">

    <div class="content-wrapper">
        <h2 class="page-title">File a Complaint</h2>
        
        <div class="data-card">
            <form action="student_submission_preview.php?mode=complaint" method="POST" enctype="multipart/form-data">
                
                <!-- COMPLAINT TARGET SECTION -->
                <fieldset style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                    <legend style="font-weight: 600; color: #333; padding: 0 10px;">Person/Office Complained Of <span style="color: red;">*</span></legend>
                    
                    <div class="form-group">
                        <label>Who are you reporting? <span style="color: red;">*</span></label>
                        <div class="reported-type-switch">
                            <label class="reported-type-option">
                                <input type="radio" name="reported_type" value="student" <?php echo $reportedTypeDraft === 'student' ? 'checked' : ''; ?>>
                                <span>Student</span>
                            </label>
                            <label class="reported-type-option">
                                <input type="radio" name="reported_type" value="faculty" <?php echo $reportedTypeDraft === 'faculty' ? 'checked' : ''; ?>>
                                <span>Faculty/Staff</span>
                            </label>
                        </div>
                        <div class="help-text" id="routingHint"></div>
                    </div>

                    <div class="form-group" id="studentPickerGroup">
                        <label>Search and select the student(s) involved <span style="color: red;">*</span></label>
                        <div class="student-picker" id="studentPicker">
                            <div class="student-chips" id="studentPickerChips"></div>
                            <div class="student-search-row">
                                <input type="text" id="studentSearchInput" class="student-picker-input" placeholder="Click to search student names..." autocomplete="off">
                                <button type="button" id="browseStudentsBtn" class="browse-students-btn"><i class='bx bx-user-plus'></i> Browse Student</button>
                            </div>
                            <div class="student-picker-options" id="studentPickerOptions">
                                <?php foreach ($reportedStudentOptions as $studentOption): ?>
                                    <?php $optionId = (int)$studentOption['id']; ?>
                                    <?php $displayName = trim((string)($studentOption['first_name'] ?? '')) . ' ' . trim((string)($studentOption['last_name'] ?? '')); ?>
                                    <?php $studentNumber = trim((string)($studentOption['student_number'] ?? '')); ?>
                                    <?php $sectionLabel = trim((string)($studentOption['year_level'] ?? '') . (!empty($studentOption['section']) ? ' - ' . $studentOption['section'] : '')); ?>
                                    <?php $programLabel = trim((string)($studentOption['program_name'] ?? '')); ?>
                                    <?php $collegeLabel = trim((string)($studentOption['college_name'] ?? '')); ?>
                                    <?php // Student number is left out of every visible label here - it's personal information, only used behind the scenes for search matching (see data-search below). ?>
                                    <?php $detailText = trim(implode(' • ', array_filter([$sectionLabel !== '' ? $sectionLabel : '', $programLabel !== '' ? $programLabel : '', $collegeLabel !== '' ? $collegeLabel : '']))); ?>
                                    <?php $optionPhoto = function_exists('resolve_student_photo') ? resolve_student_photo((string)($studentOption['profile_pic'] ?? '')) : ''; ?>
                                    <button type="button" class="student-option" data-id="<?php echo e((string)$optionId); ?>" data-label="<?php echo e($displayName); ?>" data-search="<?php echo e(strtolower(trim($displayName . ' ' . $studentNumber . ' ' . $sectionLabel . ' ' . $programLabel . ' ' . $collegeLabel))); ?>" data-college="<?php echo e($collegeLabel); ?>" data-program="<?php echo e($programLabel); ?>" data-yearlevel="<?php echo e(trim((string)($studentOption['year_level'] ?? ''))); ?>" data-section="<?php echo e(trim((string)($studentOption['section'] ?? ''))); ?>" data-details="<?php echo e($detailText); ?>" data-photo="<?php echo e($optionPhoto); ?>" data-number="<?php echo e($studentNumber); ?>">
                                        <span class="student-option-name"><?php echo e($displayName); ?></span>
                                        <?php if ($detailText !== ''): ?>
                                            <span class="student-option-details"><?php echo e($detailText); ?></span>
                                        <?php endif; ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php foreach ($selectedReportedStudentIds as $selectedId): ?>
                            <input type="hidden" name="reported_student_ids[]" value="<?php echo e((string)(int)$selectedId); ?>">
                        <?php endforeach; ?>
                    </div>

                    <div class="browse-modal-overlay" id="browseStudentsModal" aria-hidden="true">
                        <div class="browse-modal" role="dialog" aria-modal="true" aria-labelledby="browseStudentsTitle">
                            <div class="browse-modal-header">
                                <div class="browse-modal-header-left">
                                    <div class="browse-modal-icon"><i class='bx bx-user'></i></div>
                                    <div>
                                        <div id="browseStudentsTitle" class="browse-modal-title">Browse Students</div>
                                        <div class="browse-modal-subtitle">Find and select student(s) involved in this case.</div>
                                    </div>
                                </div>
                                <button type="button" class="browse-modal-close" id="browseStudentsCloseBtn" aria-label="Close">&times;</button>
                            </div>
                            <div class="browse-modal-filters">
                                <div class="browse-filter-field">
                                    <label for="browseFilterCollege">College</label>
                                    <select id="browseFilterCollege">
                                        <option value="">All Colleges</option>
                                        <?php foreach ($studentBrowseColleges as $collegeName): ?>
                                            <option value="<?php echo e($collegeName); ?>"><?php echo e($collegeName); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="browse-filter-field">
                                    <label for="browseFilterProgram">Department</label>
                                    <select id="browseFilterProgram">
                                        <option value="">All Departments</option>
                                        <?php foreach ($studentBrowsePrograms as $programName): ?>
                                            <option value="<?php echo e($programName); ?>"><?php echo e($programName); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="browse-filter-field">
                                    <label for="browseFilterYearLevel">Year Level</label>
                                    <select id="browseFilterYearLevel">
                                        <option value="">All Year Levels</option>
                                        <?php foreach ($studentBrowseYearLevels as $yearLevelName): ?>
                                            <option value="<?php echo e($yearLevelName); ?>"><?php echo e($yearLevelName); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="browse-filter-field">
                                    <label for="browseFilterSection">Section</label>
                                    <select id="browseFilterSection">
                                        <option value="">All Sections</option>
                                        <?php foreach ($studentBrowseSections as $sectionName): ?>
                                            <option value="<?php echo e($sectionName); ?>"><?php echo e($sectionName); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="browse-filter-field browse-filter-clear">
                                    <label>&nbsp;</label>
                                    <button type="button" class="browse-clear-filters-btn" id="browseClearFiltersBtn">Clear Filters</button>
                                </div>
                                <div class="browse-filter-field browse-filter-search">
                                    <label for="browseSearchInput">&nbsp;</label>
                                    <div class="browse-search-wrap">
                                        <i class='bx bx-search'></i>
                                        <input type="text" id="browseSearchInput" placeholder="Search by name...">
                                    </div>
                                </div>
                            </div>
                            <div class="browse-modal-toolbar">
                                <div class="browse-total-count" id="browseResultsCount">Total Students: 0</div>
                            </div>
                            <div class="browse-table-wrap">
                                <table class="browse-table">
                                    <thead>
                                        <tr>
                                            <th class="browse-th-check"></th>
                                            <th>Student Name</th>
                                            <th>College</th>
                                            <th>Department</th>
                                            <th>Year Level</th>
                                            <th>Section</th>
                                        </tr>
                                    </thead>
                                    <tbody id="browseStudentsList"></tbody>
                                </table>
                            </div>
                            <div class="browse-modal-footer">
                                <button type="button" class="browse-cancel-btn" id="browseStudentsCancelBtn">Cancel</button>
                                <button type="button" class="btn-blue" id="browseStudentsDoneBtn">Add Selected (0)</button>
                            </div>
                        </div>
                    </div>

                    <div class="form-group" id="facultyPickerGroup">
                        <label>Search and select the faculty or staff involved <span style="color: red;">*</span></label>
                        <?php if ($reportedFacultyOptions === []): ?>
                            <div class="help-text" style="color:#b45309;">No faculty or staff records have been added yet. Please contact the SAS Office.</div>
                        <?php endif; ?>
                        <div class="student-picker" id="facultyPicker">
                            <div class="student-chips" id="facultyPickerChips"></div>
                            <input type="text" id="facultySearchInput" class="student-picker-input" placeholder="Click to search faculty or staff names..." autocomplete="off">
                            <div class="student-picker-options" id="facultyPickerOptions">
                                <?php foreach ($reportedFacultyOptions as $facultyOption): ?>
                                    <?php $facultyId = (int)$facultyOption['id']; ?>
                                    <?php $facultyName = trim((string)($facultyOption['first_name'] ?? '')) . ' ' . trim((string)($facultyOption['last_name'] ?? '')); ?>
                                    <?php $facultyPosition = trim((string)($facultyOption['position'] ?? '')); ?>
                                    <?php $facultyDepartment = trim((string)($facultyOption['department'] ?? '')); ?>
                                    <?php $facultyCollege = trim((string)($facultyOption['college_name'] ?? '')); ?>
                                    <?php $facultyDetail = trim(implode(' • ', array_filter([$facultyPosition, $facultyDepartment, $facultyCollege]))); ?>
                                    <button type="button" class="student-option" data-id="<?php echo e((string)$facultyId); ?>" data-label="<?php echo e($facultyName); ?>" data-search="<?php echo e(strtolower(trim($facultyName . ' ' . $facultyPosition . ' ' . $facultyDepartment . ' ' . $facultyCollege))); ?>">
                                        <span class="student-option-name"><?php echo e($facultyName); ?></span>
                                        <?php if ($facultyDetail !== ''): ?>
                                            <span class="student-option-details"><?php echo e($facultyDetail); ?></span>
                                        <?php endif; ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php foreach ($selectedReportedFacultyIds as $selectedFacultyId): ?>
                            <input type="hidden" name="reported_faculty_ids[]" value="<?php echo e((string)(int)$selectedFacultyId); ?>">
                        <?php endforeach; ?>
                        <div class="help-text">Click the box to view the list, type to filter names, and choose one or more.</div>
                    </div>

                    <input type="hidden" name="person_complained_of" id="personComplainedOfInput" value="<?php echo draft_value($submissionDraft, 'person_complained_of'); ?>">
                </fieldset>

                <!-- INCIDENT DETAILS SECTION -->
                <fieldset style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                    <legend style="font-weight: 600; color: #333; padding: 0 10px;">Incident Details <span style="color: red;">*</span></legend>
                    
                    <div class="incident-details-grid">
                        <div class="form-group">
                            <label>Date of Incident <span style="color: red;">*</span></label>
                            <input type="date" name="date_of_incident" class="input-field" value="<?php echo draft_value($submissionDraft, 'date_of_incident'); ?>" required>
                        </div>

                        <div class="form-group">
                            <label>Time of Incident</label>
                            <input type="time" name="time_of_incident" class="input-field" value="<?php echo draft_value($submissionDraft, 'time_of_incident'); ?>">
                        </div>

                        <div class="form-group">
                            <label>Place of Incident <span style="color: red;">*</span></label>
                            <select id="placeOfIncidentSelect" class="input-field" required onchange="syncPlaceOfIncident()">
                                <option value="" disabled <?php echo $placeOfIncidentDraftValue === '' ? 'selected' : ''; ?>>Select a place...</option>
                                <?php foreach ($placeOfIncidentOptions as $place): ?>
                                    <option value="<?php echo e($place); ?>" <?php echo draft_selected($submissionDraft, 'place_of_incident', $place); ?>><?php echo e($place); ?></option>
                                <?php endforeach; ?>
                                <option value="Others" <?php echo $placeOfIncidentIsOther ? 'selected' : ''; ?>>Others</option>
                            </select>
                            <input type="text" id="placeOfIncidentOther" class="input-field" placeholder="Please specify the place" style="margin-top:8px; <?php echo $placeOfIncidentIsOther ? '' : 'display:none;'; ?>" value="<?php echo $placeOfIncidentIsOther ? e($placeOfIncidentDraftValue) : ''; ?>" <?php echo $placeOfIncidentIsOther ? 'required' : ''; ?> oninput="syncPlaceOfIncident()">
                            <input type="hidden" name="place_of_incident" id="placeOfIncidentHidden" value="<?php echo draft_value($submissionDraft, 'place_of_incident'); ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Act/s Complained Of <span style="color: red;">*</span></label>
                            <textarea name="act_complained_of" id="actComplainedOf" class="input-field" rows="3" maxlength="500" placeholder="Describe the specific act or behavior complained about" required oninput="updateActComplainedOfCount()"><?php echo draft_value($submissionDraft, 'act_complained_of'); ?></textarea>
                            <div id="actComplainedOfCount" style="font-size: 12px; color: #6b7280; margin-top: 4px; text-align: right;"></div>
                    </div>

                </fieldset>

                <!-- PROOF & EVIDENCE SECTION -->
                <fieldset style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                    <legend style="font-weight: 600; color: #333; padding: 0 10px;">Proof of Complaint</legend>
                    
                    <div class="form-group">
                        <label>Attach Photo or Document</label>
                            <div class="file-upload-wrapper">
                            <i class='bx bx-cloud-upload'></i>
                            <p>Click to upload or drag and drop</p>
                            <span>Supported formats: JPG, PNG, PDF, DOC, DOCX (Max 5MB)</span>
                            <input type="file" name="attachment" class="file-input" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx">
                        </div>
                        <div class="file-name-display" id="file-name" style="<?php echo !empty($submissionDraft['attachment_name']) ? 'display:block;' : ''; ?>"><?php echo !empty($submissionDraft['attachment_name']) ? 'Selected file: ' . e((string)$submissionDraft['attachment_name']) : ''; ?></div>
                    </div>
                </fieldset>

                <!-- DESIRED OUTCOME SECTION -->
                <fieldset style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                    <legend style="font-weight: 600; color: #333; padding: 0 10px;">Expected Outcome <span style="color: red;">*</span></legend>
                    
                    <div class="form-group">
                        <label>As a result of making this complaint, what outcome would you like to have / to expect? <span style="color: red;">*</span></label>
                            <textarea name="desired_outcome" id="desiredOutcome" class="input-field" rows="4" maxlength="300" placeholder="Describe the desired outcome or resolution" required oninput="updateDesiredOutcomeCount()"><?php echo draft_value($submissionDraft, 'desired_outcome'); ?></textarea>
                            <div id="desiredOutcomeCount" style="font-size: 12px; color: #6b7280; margin-top: 4px; text-align: right;"></div>
                    </div>
                </fieldset>

                <!-- TERMS & AGREEMENT SECTION -->
                <fieldset style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                    <legend style="font-weight: 600; color: #333; padding: 0 10px;">Terms of Agreement <span style="color: red;">*</span></legend>
                    
                    <p style="font-size: 14px; font-weight: 600; color: #374151; margin-bottom: 15px; line-height: 1.6;">
                        Upon filling-up this form, I bind myself to stand on the truth of this complaint as a <strong>COMPLAINANT/AGGRIEVED PARTY</strong> on behalf of the public and the institution for legal proceedings may be required as provided by the existing laws.
                    </p>

                    <div class="checkbox-group" style="margin-bottom: 15px;">
                        <input type="checkbox" name="terms_agreement_accepted" id="terms-agree" value="1" <?php echo draft_checked($submissionDraft, 'terms_agreement_accepted'); ?> required>
                        <label for="terms-agree" style="font-weight: 400; color: #6b7280;">I agree that the provided information asked herein will be used by the University for whatever legal purpose it may serve. <span style="color: red;">*</span></label>
                    </div>
                </fieldset>

                <div style="font-size: 13px; color: #6b7280; margin-bottom: 20px; line-height: 1.6;">
                    General complaints are sent to the admin. College-related complaints are sent directly to your college dean.
                </div>

                <button type="submit" class="btn-blue">Submit Complaint</button>
            </form>
        </div>
    </div>

</div>

<script>
function updateActComplainedOfCount() {
    const field = document.getElementById('actComplainedOf');
    const counter = document.getElementById('actComplainedOfCount');
    if (!field || !counter) {
        return;
    }
    const max = field.maxLength;
    counter.textContent = field.value.length + ' / ' + max;
}

function updateDesiredOutcomeCount() {
    const field = document.getElementById('desiredOutcome');
    const counter = document.getElementById('desiredOutcomeCount');
    if (!field || !counter) {
        return;
    }
    const max = field.maxLength;
    counter.textContent = field.value.length + ' / ' + max;
}

function syncPlaceOfIncident() {
    const select = document.getElementById('placeOfIncidentSelect');
    const other = document.getElementById('placeOfIncidentOther');
    const hidden = document.getElementById('placeOfIncidentHidden');
    if (!select || !other || !hidden) {
        return;
    }
    if (select.value === 'Others') {
        other.style.display = 'block';
        other.required = true;
        hidden.value = other.value.trim();
    } else {
        other.style.display = 'none';
        other.required = false;
        other.value = '';
        hidden.value = select.value;
    }
}

function resetComplaintForm() {
    // Find and reset the form
    const form = document.querySelector('form[action*="student_submission_preview.php"]');
    if (form) {
        form.reset();
        
        // Explicitly clear all input fields
        const inputs = form.querySelectorAll('input, textarea, select');
        inputs.forEach(function(input) {
            if (input.type === 'checkbox' || input.type === 'radio') {
                input.checked = false;
            } else if (input.type === 'hidden' && (input.name === 'reported_student_ids[]' || input.name === 'reported_faculty_ids[]')) {
                input.remove();
            } else {
                input.value = '';
            }
        });
    }

    // Clear the student and faculty pickers
    ['studentPickerChips', 'facultyPickerChips'].forEach(function (id) {
        const chipsContainer = document.getElementById(id);
        if (chipsContainer) {
            chipsContainer.innerHTML = '';
        }
    });

    const hiddenInputs = document.querySelectorAll('input[name="reported_student_ids[]"], input[name="reported_faculty_ids[]"]');
    hiddenInputs.forEach(function (input) {
        input.remove();
    });

    const personComplainedOfInput = document.getElementById('personComplainedOfInput');
    if (personComplainedOfInput) {
        personComplainedOfInput.value = '';
    }

    const searchInput = document.getElementById('studentSearchInput');
    if (searchInput) {
        searchInput.value = '';
    }

    const studentOptions = document.getElementById('studentPickerOptions');
    if (studentOptions) {
        studentOptions.style.display = 'none';
    }

    const fileNameDisplay = document.getElementById('file-name');
    if (fileNameDisplay) {
        fileNameDisplay.textContent = '';
        fileNameDisplay.style.display = 'none';
    }
    
    // Clear browser autocomplete
    const fileInput = document.querySelector('input[type="file"]');
    if (fileInput) {
        fileInput.value = '';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    updateActComplainedOfCount();
    updateDesiredOutcomeCount();

    const params = new URLSearchParams(window.location.search);
    if (params.get('status') === 'success') {
        // Reset immediately
        resetComplaintForm();
        
        // Reset again after a short delay to ensure browser cache is cleared
        setTimeout(function() {
            resetComplaintForm();
        }, 100);
    }

    const fileInput = document.querySelector('.file-input');
    const fileNameDisplay = document.getElementById('file-name');
    
    if (fileInput && fileNameDisplay) {
        fileInput.addEventListener('change', function() {
            if (this.files && this.files[0]) {
                const fileName = this.files[0].name;
                fileNameDisplay.textContent = 'Selected file: ' + fileName;
                fileNameDisplay.style.display = 'block';
            } else {
                fileNameDisplay.style.display = 'none';
            }
        });
    }

    const personComplainedOfInput = document.getElementById('personComplainedOfInput');

    // One picker implementation, used for both the student list and the
    // faculty/staff list. Only the picker matching the selected "Who are you
    // reporting?" option contributes to person_complained_of.
    function createPicker(config) {
        const picker = document.getElementById(config.pickerId);
        const options = document.getElementById(config.optionsId);
        const chipsContainer = document.getElementById(config.chipsId);
        const searchInput = document.getElementById(config.searchId);

        if (!picker || !options || !chipsContainer || !searchInput || !personComplainedOfInput) {
            return null;
        }

        const allOptions = Array.from(options.querySelectorAll('.student-option'));
        const selectedValues = new Map();
        let onChange = function () {};

        function render() {
            chipsContainer.innerHTML = '';
            picker.querySelectorAll('input[name="' + config.fieldName + '"]').forEach(function (input) {
                input.remove();
            });

            Array.from(selectedValues.values()).forEach(function (item) {
                const chip = document.createElement('div');
                chip.className = 'student-chip';
                const label = document.createElement('span');
                label.textContent = item.label;
                const removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.setAttribute('data-id', item.id);
                removeBtn.setAttribute('aria-label', 'Remove');
                removeBtn.textContent = '×';
                chip.appendChild(label);
                chip.appendChild(removeBtn);
                chipsContainer.appendChild(chip);

                const hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = config.fieldName;
                hiddenInput.value = item.id;
                picker.appendChild(hiddenInput);
            });

            allOptions.forEach(function (option) {
                option.classList.toggle('selected', selectedValues.has(option.getAttribute('data-id')));
            });

            onChange();
        }

        function filterOptions() {
            const query = searchInput.value.toLowerCase().trim();
            allOptions.forEach(function (option) {
                const haystack = (option.getAttribute('data-search') || '').toLowerCase();
                option.style.display = haystack.includes(query) ? '' : 'none';
            });
        }

        allOptions.forEach(function (option) {
            option.addEventListener('click', function () {
                const id = option.getAttribute('data-id');
                const label = option.getAttribute('data-label');
                if (!id || !label) {
                    return;
                }
                if (selectedValues.has(id)) {
                    selectedValues.delete(id);
                } else {
                    selectedValues.set(id, { id: id, label: label });
                }
                render();
            });
        });

        chipsContainer.addEventListener('click', function (event) {
            const button = event.target.closest('button[data-id]');
            if (!button) {
                return;
            }
            const id = button.getAttribute('data-id');
            if (id) {
                selectedValues.delete(id);
                render();
            }
        });

        ['focus', 'click', 'input'].forEach(function (evt) {
            searchInput.addEventListener(evt, function () {
                options.style.display = 'block';
                filterOptions();
            });
        });

        document.addEventListener('click', function (event) {
            if (!picker.contains(event.target)) {
                options.style.display = 'none';
            }
        });

        // Restore anything already chosen before a back-navigation.
        Array.from(picker.querySelectorAll('input[name="' + config.fieldName + '"]')).forEach(function (input) {
            const id = input.value;
            const option = allOptions.find(function (item) { return item.getAttribute('data-id') === id; });
            if (option) {
                selectedValues.set(id, { id: id, label: option.getAttribute('data-label') || '' });
            }
        });

        return {
            render: render,
            filterOptions: filterOptions,
            setOnChange: function (fn) { onChange = fn; },
            clear: function () { selectedValues.clear(); render(); },
            labels: function () {
                return Array.from(selectedValues.values()).map(function (item) { return item.label; });
            }
        };
    }

    const studentPickerApi = createPicker({
        pickerId: 'studentPicker',
        optionsId: 'studentPickerOptions',
        chipsId: 'studentPickerChips',
        searchId: 'studentSearchInput',
        fieldName: 'reported_student_ids[]'
    });

    // "Browse students" modal: an easier alternative to the type-to-search
    // box for students who don't know exactly who to search for. Selections
    // made here are staged locally and only applied to the real inline
    // picker (by clicking its matching hidden .student-option buttons) when
    // "Add Selected" is pressed - "Cancel"/close just discards the staging.
    (function setupBrowseStudentsModal() {
        const openBtn = document.getElementById('browseStudentsBtn');
        const modal = document.getElementById('browseStudentsModal');
        const closeBtn = document.getElementById('browseStudentsCloseBtn');
        const cancelBtn = document.getElementById('browseStudentsCancelBtn');
        const doneBtn = document.getElementById('browseStudentsDoneBtn');
        const listEl = document.getElementById('browseStudentsList');
        const searchInput = document.getElementById('browseSearchInput');
        const collegeSelect = document.getElementById('browseFilterCollege');
        const programSelect = document.getElementById('browseFilterProgram');
        const yearLevelSelect = document.getElementById('browseFilterYearLevel');
        const sectionSelect = document.getElementById('browseFilterSection');
        const countEl = document.getElementById('browseResultsCount');
        const clearFiltersBtn = document.getElementById('browseClearFiltersBtn');
        const sourceOptions = document.getElementById('studentPickerOptions');

        if (!openBtn || !modal || !sourceOptions) {
            return;
        }

        let students = [];
        let staged = new Set();

        function buildStudents() {
            students = Array.from(sourceOptions.querySelectorAll('.student-option')).map(function (option) {
                return {
                    id: option.getAttribute('data-id') || '',
                    label: option.getAttribute('data-label') || '',
                    search: option.getAttribute('data-search') || '',
                    college: option.getAttribute('data-college') || '',
                    program: option.getAttribute('data-program') || '',
                    yearLevel: option.getAttribute('data-yearlevel') || '',
                    section: option.getAttribute('data-section') || '',
                    photo: option.getAttribute('data-photo') || '',
                    row: null
                };
            });
            // Names are already ordered first_name/last_name by the server
            // query, so a plain alphabetical re-sort here keeps it stable.
            students.sort(function (a, b) { return a.label.localeCompare(b.label); });
        }

        function updateDoneButton() {
            doneBtn.textContent = 'Add Selected (' + staged.size + ')';
        }

        function renderRows() {
            listEl.innerHTML = '';
            students.forEach(function (s) {
                const tr = document.createElement('tr');
                s.row = tr;

                const checkCell = document.createElement('td');
                const checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.checked = staged.has(s.id);
                checkbox.addEventListener('click', function (event) { event.stopPropagation(); });
                checkbox.addEventListener('change', function () { toggle(s); });
                checkCell.appendChild(checkbox);
                tr.appendChild(checkCell);

                const nameCell = document.createElement('td');
                const nameWrap = document.createElement('div');
                nameWrap.className = 'browse-row-name-cell';
                const avatar = document.createElement('img');
                avatar.className = 'browse-row-avatar';
                avatar.src = s.photo || '../assets/images/default-avatar.svg';
                avatar.alt = '';
                const nameSpan = document.createElement('span');
                nameSpan.className = 'browse-row-name';
                nameSpan.textContent = s.label;
                nameWrap.appendChild(avatar);
                nameWrap.appendChild(nameSpan);
                nameCell.appendChild(nameWrap);
                tr.appendChild(nameCell);

                [s.college, s.program, s.yearLevel, s.section].forEach(function (value) {
                    const td = document.createElement('td');
                    td.textContent = value;
                    tr.appendChild(td);
                });

                tr.classList.toggle('checked', checkbox.checked);
                tr.addEventListener('click', function (event) {
                    if (event.target.tagName === 'INPUT') { return; }
                    checkbox.checked = !checkbox.checked;
                    toggle(s);
                });

                listEl.appendChild(tr);
            });
        }

        function toggle(s) {
            if (staged.has(s.id)) {
                staged.delete(s.id);
            } else {
                staged.add(s.id);
            }
            if (s.row) { s.row.classList.toggle('checked', staged.has(s.id)); }
            updateDoneButton();
        }

        // Narrows the Department dropdown to only the departments that
        // actually have students in the selected college (instead of always
        // listing every department in the school), and drops the current
        // department selection if it no longer belongs to that college.
        function updateDepartmentOptions() {
            const college = collegeSelect.value;
            const previousProgram = programSelect.value;

            const availablePrograms = [];
            const seen = new Set();
            students.forEach(function (s) {
                if (college !== '' && s.college !== college) { return; }
                if (s.program === '' || seen.has(s.program)) { return; }
                seen.add(s.program);
                availablePrograms.push(s.program);
            });
            availablePrograms.sort(function (a, b) { return a.localeCompare(b); });

            programSelect.innerHTML = '';
            const allOption = document.createElement('option');
            allOption.value = '';
            allOption.textContent = 'All Departments';
            programSelect.appendChild(allOption);
            availablePrograms.forEach(function (program) {
                const option = document.createElement('option');
                option.value = program;
                option.textContent = program;
                programSelect.appendChild(option);
            });

            programSelect.value = availablePrograms.indexOf(previousProgram) !== -1 ? previousProgram : '';
        }

        function applyFilters() {
            const query = (searchInput.value || '').toLowerCase().trim();
            const college = collegeSelect.value;
            const program = programSelect.value;
            const yearLevel = yearLevelSelect.value;
            const section = sectionSelect.value;
            let visibleCount = 0;

            students.forEach(function (s) {
                const matchesQuery = query === '' || s.search.includes(query);
                const matchesCollege = college === '' || s.college === college;
                const matchesProgram = program === '' || s.program === program;
                const matchesYearLevel = yearLevel === '' || s.yearLevel === yearLevel;
                const matchesSection = section === '' || s.section === section;
                const visible = matchesQuery && matchesCollege && matchesProgram && matchesYearLevel && matchesSection;
                if (s.row) { s.row.style.display = visible ? '' : 'none'; }
                if (visible) { visibleCount++; }
            });

            if (countEl) {
                countEl.textContent = 'Total Students: ' + visibleCount;
            }
            let emptyRow = listEl.querySelector('.browse-modal-empty-row');
            if (visibleCount === 0) {
                if (!emptyRow) {
                    emptyRow = document.createElement('tr');
                    emptyRow.className = 'browse-modal-empty-row';
                    const td = document.createElement('td');
                    td.colSpan = 6;
                    td.className = 'browse-modal-empty';
                    td.textContent = 'No students match these filters.';
                    emptyRow.appendChild(td);
                    listEl.appendChild(emptyRow);
                }
            } else if (emptyRow) {
                emptyRow.remove();
            }
        }

        function openModal() {
            buildStudents();
            // Stage whatever's already selected in the real picker.
            staged = new Set(
                Array.from(sourceOptions.querySelectorAll('.student-option.selected')).map(function (option) {
                    return option.getAttribute('data-id') || '';
                })
            );
            renderRows();
            updateDepartmentOptions();
            applyFilters();
            updateDoneButton();
            modal.classList.add('visible');
            modal.setAttribute('aria-hidden', 'false');
        }

        function closeModal() {
            modal.classList.remove('visible');
            modal.setAttribute('aria-hidden', 'true');
        }

        function commitSelection() {
            Array.from(sourceOptions.querySelectorAll('.student-option')).forEach(function (option) {
                const id = option.getAttribute('data-id') || '';
                const shouldBeSelected = staged.has(id);
                if (option.classList.contains('selected') !== shouldBeSelected) {
                    option.click();
                }
            });
            closeModal();
        }

        openBtn.addEventListener('click', function (event) {
            event.preventDefault();
            openModal();
        });
        if (closeBtn) { closeBtn.addEventListener('click', closeModal); }
        if (cancelBtn) { cancelBtn.addEventListener('click', closeModal); }
        if (doneBtn) { doneBtn.addEventListener('click', commitSelection); }
        modal.addEventListener('click', function (event) {
            if (event.target === modal) { closeModal(); }
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && modal.classList.contains('visible')) { closeModal(); }
        });

        [searchInput, programSelect, yearLevelSelect, sectionSelect].forEach(function (el) {
            if (el) { el.addEventListener('input', applyFilters); el.addEventListener('change', applyFilters); }
        });
        if (collegeSelect) {
            collegeSelect.addEventListener('change', function () {
                updateDepartmentOptions();
                applyFilters();
            });
        }
        if (clearFiltersBtn) {
            clearFiltersBtn.addEventListener('click', function () {
                searchInput.value = '';
                collegeSelect.value = '';
                yearLevelSelect.value = '';
                sectionSelect.value = '';
                updateDepartmentOptions();
                programSelect.value = '';
                applyFilters();
            });
        }
    })();

    const facultyPickerApi = createPicker({
        pickerId: 'facultyPicker',
        optionsId: 'facultyPickerOptions',
        chipsId: 'facultyPickerChips',
        searchId: 'facultySearchInput',
        fieldName: 'reported_faculty_ids[]'
    });

    const typeRadios = Array.from(document.querySelectorAll('input[name="reported_type"]'));
    const studentGroup = document.getElementById('studentPickerGroup');
    const facultyGroup = document.getElementById('facultyPickerGroup');
    const routingHint = document.getElementById('routingHint');

    function currentType() {
        const checked = typeRadios.find(function (radio) { return radio.checked; });
        return checked ? checked.value : 'student';
    }

    function syncPersonComplainedOf() {
        if (!personComplainedOfInput) {
            return;
        }
        const api = currentType() === 'faculty' ? facultyPickerApi : studentPickerApi;
        personComplainedOfInput.value = api ? api.labels().join(', ') : '';
    }

    if (studentPickerApi) { studentPickerApi.setOnChange(syncPersonComplainedOf); }
    if (facultyPickerApi) { facultyPickerApi.setOnChange(syncPersonComplainedOf); }

    function applyType(clearOther) {
        const type = currentType();
        const isFaculty = type === 'faculty';

        if (studentGroup) { studentGroup.style.display = isFaculty ? 'none' : ''; }
        if (facultyGroup) { facultyGroup.style.display = isFaculty ? '' : 'none'; }

        // Only one kind of person can be reported per complaint, so switching
        // clears whatever was picked in the other list.
        if (clearOther) {
            if (isFaculty && studentPickerApi) { studentPickerApi.clear(); }
            if (!isFaculty && facultyPickerApi) { facultyPickerApi.clear(); }
        }

        if (routingHint) {
            routingHint.textContent = isFaculty
                ? 'This complaint will be sent automatically to the SAS Director.'
                : "This complaint will be sent automatically to the dean of the reported student's college.";
        }

        syncPersonComplainedOf();
    }

    typeRadios.forEach(function (radio) {
        radio.addEventListener('change', function () { applyType(true); });
    });

    if (studentPickerApi) { studentPickerApi.render(); studentPickerApi.filterOptions(); }
    if (facultyPickerApi) { facultyPickerApi.render(); facultyPickerApi.filterOptions(); }
    applyType(false);
});

<?php if ($pageMode !== 'suggestion' && $flashStatus === 'success' && $flashMessage !== ''): ?>
document.addEventListener('DOMContentLoaded', function() {
    var overlay = document.createElement('div');
    overlay.id = 'flashOverlay';
    overlay.className = 'flash-overlay';
    overlay.innerHTML = '<div class="flash-card"><h3>Submission Successful</h3><p>' + <?php echo json_encode($flashMessage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?> + '</p><button id="flashClose" class="flash-close">OK</button></div>';
    document.body.appendChild(overlay);
    overlay.style.display = 'flex';
    var closeBtn = document.getElementById('flashClose');
    function hide() { overlay.style.display = 'none'; }
    closeBtn.addEventListener('click', hide, { once: true });
    overlay.addEventListener('click', function(e){ if (e.target === overlay) hide(); });
    setTimeout(hide, 3500);
});
<?php endif; ?>
</script>

</body>
</html>