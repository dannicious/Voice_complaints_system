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
$schoolYearCurrent = sy_current();
$schoolYearSelected = $studentProfileId > 0 ? sy_get_selected($pdo, $studentProfileId) : $schoolYearCurrent;

if ($studentProfileId > 0 && $schoolYearSelected !== $schoolYearCurrent) {
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
                <h3>You're viewing School Year <?php echo e($schoolYearSelected); ?></h3>
                <p>
                    <?php echo e($lockedAction); ?> is only available while viewing the current school year
                    (<?php echo e($schoolYearCurrent); ?>). This past school year's records are read-only.
                    Switch back to the current school year to continue.
                </p>
                <form method="POST" action="set_school_year.php">
                    <input type="hidden" name="school_year" value="<?php echo e($schoolYearCurrent); ?>">
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
$complaintCategories = [
    ['value' => 'dean', 'label' => 'Dean'],
    ['value' => 'admin', 'label' => 'Admin'],
];
$placeOfIncidentOptions = ['CTAS Building', 'CCJ Building', 'CCIS Building', 'GYM', 'BACK ADMIN'];
$reportedStudentOptions = [];
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
            'SELECT sp.id, sp.student_number, sp.first_name, sp.last_name, sp.section, sp.year_level,
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
    $suggestionCategories = [];
    try {
        $stmt = $pdo->query('SELECT name FROM suggestion_categories WHERE is_active = 1 ORDER BY name ASC');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $suggestionCategories[] = (string)$row['name'];
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
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                            <div class="form-group">
                                <label>Area of Improvement <span style="color: red;">*</span></label>
                                <select name="category" class="input-field" required>
                                    <option value="" disabled <?php echo empty($submissionDraft['category']) ? 'selected' : ''; ?>>Select an area...</option>
                                    <?php if (count($suggestionCategories) > 0): ?>
                                        <?php foreach ($suggestionCategories as $cat): ?>
                                            <option value="<?php echo htmlspecialchars($cat, ENT_QUOTES, 'UTF-8'); ?>" <?php echo draft_selected($submissionDraft, 'category', $cat); ?>><?php echo htmlspecialchars($cat, ENT_QUOTES, 'UTF-8'); ?></option>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <option value="campus_life" <?php echo draft_selected($submissionDraft, 'category', 'campus_life'); ?>>Campus Life & Events</option>
                                        <option value="facilities" <?php echo draft_selected($submissionDraft, 'category', 'facilities'); ?>>Facilities & Infrastructure</option>
                                        <option value="academics" <?php echo draft_selected($submissionDraft, 'category', 'academics'); ?>>Academics & Curriculum</option>
                                        <option value="technology" <?php echo draft_selected($submissionDraft, 'category', 'technology'); ?>>Technology & Digital Services</option>
                                        <option value="other" <?php echo draft_selected($submissionDraft, 'category', 'other'); ?>>Other Ideas</option>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Date of Suggestion <span style="color: red;">*</span></label>
                                <input type="date" name="date_of_suggestion" class="input-field" value="<?php echo draft_value($submissionDraft, 'date_of_suggestion'); ?>" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Idea Title <span style="color: red;">*</span></label>
                            <input type="text" name="subject" class="input-field" placeholder="E.g., Extend Library Operating Hours during Midterms" value="<?php echo draft_value($submissionDraft, 'subject'); ?>" required>
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
                        <legend style="font-weight: 600; color: #333; padding: 0 10px;">Expected Outcome & Benefits <span style="color: red;">*</span></legend>
                        <div class="form-group">
                            <label>Describe the expected benefits of this suggestion <span style="color: red;">*</span></label>
                            <textarea name="expected_outcome" class="input-field" rows="4" placeholder="Explain how this suggestion will benefit the student body or the campus..." required><?php echo draft_value($submissionDraft, 'expected_outcome'); ?></textarea>
                        </div>
                    </fieldset>
                    <fieldset style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                        <legend style="font-weight: 600; color: #333; padding: 0 10px;">Terms of Agreement <span style="color: red;">*</span></legend>
                        <p style="font-size: 13px; color: #666; margin-bottom: 15px; line-height: 1.6;">Upon filling-up this form, I declare that the information provided is true and accurate to the best of my knowledge. I understand that my suggestion will be reviewed by the University administration for consideration.</p>
                        <div class="checkbox-group" style="margin-bottom: 15px;">
                            <input type="checkbox" name="terms_agreement_accepted" id="terms-agree" value="1" <?php echo draft_checked($submissionDraft, 'terms_agreement_accepted'); ?> required>
                            <label for="terms-agree">I agree that the provided information is true and may be used by the University for improvement purposes. <span style="color: red;">*</span></label>
                        </div>
                    </fieldset>
                    <div style="font-size: 13px; color: #6b7280; margin-bottom: 20px; line-height: 1.6;">
                        Suggestions are sent directly to your college dean for review.
                    </div>
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
.student-picker-input { width: 100%; padding: 12px 15px; border: 1px solid #e5e7eb; border-radius: 8px; background: #f9fafb; font-size: 14px; outline: none; transition: 0.2s; }
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
                            <input type="text" id="studentSearchInput" class="student-picker-input" placeholder="Click to search student names..." autocomplete="off">
                            <div class="student-picker-options" id="studentPickerOptions">
                                <?php foreach ($reportedStudentOptions as $studentOption): ?>
                                    <?php $optionId = (int)$studentOption['id']; ?>
                                    <?php $displayName = trim((string)($studentOption['first_name'] ?? '')) . ' ' . trim((string)($studentOption['last_name'] ?? '')); ?>
                                    <?php $studentNumber = trim((string)($studentOption['student_number'] ?? '')); ?>
                                    <?php $sectionLabel = trim((string)($studentOption['year_level'] ?? '') . (!empty($studentOption['section']) ? ' - ' . $studentOption['section'] : '')); ?>
                                    <?php $programLabel = trim((string)($studentOption['program_name'] ?? '')); ?>
                                    <?php $collegeLabel = trim((string)($studentOption['college_name'] ?? '')); ?>
                                    <?php $detailText = trim(implode(' • ', array_filter([$studentNumber !== '' ? $studentNumber : '', $sectionLabel !== '' ? $sectionLabel : '', $programLabel !== '' ? $programLabel : '', $collegeLabel !== '' ? $collegeLabel : '']))); ?>
                                    <button type="button" class="student-option" data-id="<?php echo e((string)$optionId); ?>" data-label="<?php echo e($displayName); ?>" data-search="<?php echo e(strtolower(trim($displayName . ' ' . $studentNumber . ' ' . $sectionLabel . ' ' . $programLabel . ' ' . $collegeLabel))); ?>">
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
                        <div class="help-text">Click the box to view the list, type to filter names, and choose one or more students.</div>
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
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px;">
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
                            <textarea name="act_complained_of" class="input-field" rows="3" placeholder="Describe the specific act or behavior complained about" required><?php echo draft_value($submissionDraft, 'act_complained_of'); ?></textarea>
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
                    <legend style="font-weight: 600; color: #333; padding: 0 10px;">Complaint Outcome <span style="color: red;">*</span></legend>
                    
                    <div class="form-group">
                        <label>As a result of making this complaint, what outcome would you like to have / to expect? <span style="color: red;">*</span></label>
                            <textarea name="desired_outcome" class="input-field" rows="4" placeholder="Describe the desired outcome or resolution" required><?php echo draft_value($submissionDraft, 'desired_outcome'); ?></textarea>
                    </div>
                </fieldset>

                <!-- TERMS & AGREEMENT SECTION -->
                <fieldset style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                    <legend style="font-weight: 600; color: #333; padding: 0 10px;">Terms of Agreement <span style="color: red;">*</span></legend>
                    
                    <p style="font-size: 13px; color: #666; margin-bottom: 15px; line-height: 1.6;">
                        Upon filling-up this form, I bind myself to stand on the truth of this complaint as a <strong>COMPLAINANT/AGGRIEVED PARTY</strong> on behalf of the public and the institution for legal proceedings may be required as provided by the existing laws.
                    </p>

                    <div class="checkbox-group" style="margin-bottom: 15px;">
                        <input type="checkbox" name="terms_agreement_accepted" id="terms-agree" value="1" <?php echo draft_checked($submissionDraft, 'terms_agreement_accepted'); ?> required>
                        <label for="terms-agree">I agree that the provided information asked herein will be used by the University for whatever legal purpose it may serve. <span style="color: red;">*</span></label>
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