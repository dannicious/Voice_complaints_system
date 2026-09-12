<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../ticket_flow.php';
require_once __DIR__ . '/../faculty_helpers.php';
require_once __DIR__ . '/../school_year_helpers.php';
require_once __DIR__ . '/../complaint_ai_helpers.php';

function back_with_message(string $type, string $msg): void
{
    $q = http_build_query([
        'status' => $type,
        'msg' => $msg,
    ]);
    header('Location: student_complaints.php?' . $q);
    exit;
}

function handle_upload(array $file, string $targetFolder): ?string
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

    $map = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    ];

    if (!isset($map[$mime])) {
        throw new RuntimeException('Only JPG, PNG, PDF, DOC, and DOCX files are allowed.');
    }

    $ext = $map[$mime];
    $uploadDir = __DIR__ . '/../assets/uploads/' . $targetFolder;
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Failed to prepare upload directory.');
    }

    $filename = $targetFolder . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $absPath = $uploadDir . '/' . $filename;

    if (!move_uploaded_file($tmpName, $absPath)) {
        throw new RuntimeException('Failed to store uploaded file.');
    }

    return 'assets/uploads/' . $targetFolder . '/' . $filename;
}

function get_preview_draft(string $mode): array
{
    $draft = $_SESSION['submission_drafts'][$mode] ?? [];
    return is_array($draft) ? $draft : [];
}

function get_preview_token(string $mode): string
{
    return (string)($_SESSION['submission_draft_tokens'][$mode] ?? '');
}

function clear_preview_draft(string $mode): void
{
    if (isset($_SESSION['submission_drafts'][$mode]) && is_array($_SESSION['submission_drafts'][$mode])) {
        $draft = $_SESSION['submission_drafts'][$mode];
        if (!empty($draft['attachment_path'])) {
            $absPath = __DIR__ . '/../' . ltrim((string)$draft['attachment_path'], '/');
            if (is_file($absPath)) {
                @unlink($absPath);
            }
        }
    }

    unset($_SESSION['submission_drafts'][$mode], $_SESSION['submission_draft_tokens'][$mode]);
}

function finalize_preview_upload(array $draft, string $targetFolder): ?string
{
    if (empty($draft['attachment_path'])) {
        return null;
    }

    $draftPath = __DIR__ . '/../' . ltrim((string)$draft['attachment_path'], '/');
    if (!is_file($draftPath)) {
        return null;
    }

    $uploadDir = __DIR__ . '/../assets/uploads/' . $targetFolder;
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Failed to prepare upload directory.');
    }

    $ext = strtolower(pathinfo((string)$draftPath, PATHINFO_EXTENSION));
    $filename = $targetFolder . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destPath = $uploadDir . '/' . $filename;

    if (!rename($draftPath, $destPath)) {
        if (!copy($draftPath, $destPath) || !unlink($draftPath)) {
            throw new RuntimeException('Failed to finalize uploaded file.');
        }
    }

    return 'assets/uploads/' . $targetFolder . '/' . $filename;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    back_with_message('error', 'Invalid request.');
}

if (!isset($_SESSION['user_id']) || (string)($_SESSION['role'] ?? '') !== 'student') {
    back_with_message('error', 'Please log in as student first.');
}

$draft = get_preview_draft('complaint');
if (empty($draft)) {
    back_with_message('error', 'Please review your complaint before submitting it.');
}

$draftToken = (string)($_POST['draft_token'] ?? '');
if ($draftToken === '' || !hash_equals(get_preview_token('complaint'), $draftToken)) {
    back_with_message('error', 'Your complaint preview has expired. Please review it again.');
}

$student = null;
try {
    $studentStmt = $pdo->prepare(
        'SELECT id, college_id, first_name, last_name, gender, contact_number
         FROM student_profiles
         WHERE user_id = :user_id
         LIMIT 1'
    );
    $studentStmt->execute([':user_id' => (int)$_SESSION['user_id']]);
    $student = $studentStmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $student = null;
}

if (!$student) {
    back_with_message('error', 'Student profile not found.');
}

// Defense in depth: filing is only allowed for the current school year and
// semester, even if this is posted directly while a past period is selected.
$schoolYearCurrent = sy_current($pdo);
$schoolYearSelected = sy_get_selected($pdo, (int)$student['id']);
if ($schoolYearSelected !== $schoolYearCurrent) {
    back_with_message('error', 'Switch to the current school year (' . $schoolYearCurrent . ') to file a new complaint.');
}
$semesterCurrent = semester_current();
$semesterSelected = semester_get_selected();
if ($semesterSelected !== $semesterCurrent) {
    back_with_message('error', 'Switch to the current semester (' . semester_display_label($semesterCurrent) . ') to file a new complaint.');
}

// Complainant Information
$complainantName = trim((string)($draft['complainant_name'] ?? ''));
if ($complainantName === '') {
    $complainantName = trim((string)($student['first_name'] ?? '') . ' ' . (string)($student['last_name'] ?? ''));
}
$complainantAddress = trim((string)($draft['complainant_address'] ?? ''));
if ($complainantAddress === '') {
    $complainantAddress = 'Not provided';
}
$complainantSex = trim((string)($draft['complainant_sex'] ?? ''));
if ($complainantSex === '') {
    $complainantSex = trim((string)($student['gender'] ?? ''));
    if ($complainantSex === '') {
        $complainantSex = 'Not provided';
    }
}
$complainantAge = !empty($draft['complainant_age']) ? (int)$draft['complainant_age'] : null;
$complainantCivilStatus = trim((string)($draft['complainant_civil_status'] ?? ''));
if ($complainantCivilStatus === '') {
    $complainantCivilStatus = 'Not provided';
}
$complainantContactDetails = trim((string)($draft['complainant_contact_details'] ?? ''));
if ($complainantContactDetails === '') {
    $complainantContactDetails = trim((string)($student['contact_number'] ?? ''));
    if ($complainantContactDetails === '') {
        $complainantContactDetails = 'Not provided';
    }
}

// Person Complained Of
$personComplainedOf = trim((string)($draft['person_complained_of'] ?? ''));
$reportedStudentIds = array_values(array_filter(array_map('intval', (array)($draft['reported_student_ids'] ?? []))));

// Incident Details
$dateOfIncident = trim((string)($draft['date_of_incident'] ?? ''));
$placeOfIncident = trim((string)($draft['place_of_incident'] ?? ''));
$timeOfIncident = trim((string)($draft['time_of_incident'] ?? ''));
$actComplainedOf = trim((string)($draft['act_complained_of'] ?? ''));

// Evidence & Outcome
$desiredOutcome = trim((string)($draft['desired_outcome'] ?? ''));

// Agreement
$termsAccepted = !empty($draft['terms_agreement_accepted']) ? 1 : 0;

// Validate required fields. Category is no longer part of this - it's no
// longer a form field at all, the AI classifier assigns it below.
if ($complainantName === '' || $complainantAddress === '' || $complainantSex === '' ||
    $complainantCivilStatus === '' || $complainantContactDetails === '' ||
    $personComplainedOf === '' || $dateOfIncident === '' || $placeOfIncident === '' ||
    $actComplainedOf === '' || $desiredOutcome === '' ||
    $termsAccepted === 0) {
    back_with_message('error', 'Please complete all required fields.');
}

// AI triage: the category is no longer picked by the student - the
// classifier assigns it from the written text (Groq first, handling
// English/Tagalog/Bisaya/mixed text with no training data needed; falls
// back to the local classifier if Groq isn't available). This is done
// before the try block (and before the file upload is finalized below) so
// a spam-blocked submission never leaves an orphaned uploaded file.
ai_ensure_ai_tables($pdo);
$aiCombinedText = trim($actComplainedOf . ' ' . $desiredOutcome);
if (array_key_exists('ai_category_id', $draft)) {
    // Reuse the result already computed on the review/preview page for this
    // exact same text, instead of calling the AI a second time.
    $aiClassification = [
        'category_id' => $draft['ai_category_id'],
        'category_name' => $draft['ai_category_name'] ?? null,
        'confidence' => (float)($draft['ai_confidence'] ?? 0.0),
        'language' => $draft['ai_language'] ?? null,
        'source' => $draft['ai_source'] ?? 'local',
        'is_spam' => !empty($draft['ai_is_spam']),
    ];
    $aiUrgencyResult = ['score' => (int)($draft['ai_urgency_score'] ?? 0), 'level' => $draft['ai_urgency_level'] ?? 'low'];
} else {
    $aiClassification = $aiCombinedText !== '' ? ai_classify_text_smart($pdo, $aiCombinedText) : ['category_id' => null, 'category_name' => null, 'confidence' => 0.0, 'language' => null, 'source' => null, 'is_spam' => false];
    $aiUrgencyResult = $aiCombinedText !== '' ? ai_urgency_score($aiCombinedText) : ['score' => 0, 'level' => 'low'];
}

// Only Groq can judge spam/nonsense (the local fallback always reports
// is_spam=false, so an outage never blocks a genuine submission). This is
// the same check already shown to the student on the preview page - this
// is the server-side enforcement of it, in case that page was bypassed.
if (!empty($aiClassification['is_spam'])) {
    back_with_message('error', "This doesn't read like a genuine complaint. Please go back and describe an actual incident, or contact the SAS Office directly if you believe this is a mistake.");
}

$categoryId = $aiClassification['category_id'] !== null ? (int)$aiClassification['category_id'] : null;

// Student complaints are routed to the dean of the student who filed them.
// Complaints about faculty/staff continue to go to the SAS Director.
ensure_faculty_tables($pdo);

$reportedType = (string)($draft['reported_type'] ?? 'student');
if (!in_array($reportedType, ['student', 'faculty'], true)) {
    $reportedType = 'student';
}
$reportedFacultyIds = array_values(array_filter(array_map('intval', (array)($draft['reported_faculty_ids'] ?? []))));

$routingCollegeId = $student['college_id'] !== null ? (int)$student['college_id'] : null;

try {
    $attachment = finalize_preview_upload($draft, 'complaints');

    // Routing depends only on who is being reported: a complaint against
    // faculty/staff always goes to the SAS Director (Admin); a complaint
    // against a fellow student always goes to that student's own college
    // dean. This is independent of the AI-assigned category.
    if ($reportedType === 'faculty') {
        $recipientRole = 'admin';
        $recipientUserId = 0;
        $collegeId = null;
    } else {
        $recipientRole = 'dean';
        $recipientUserId = 0;
        $collegeId = $routingCollegeId;

        if ($routingCollegeId !== null && $routingCollegeId > 0) {
            try {
                $deanStmt = $pdo->prepare(
                    'SELECT u.id
                     FROM users u
                     INNER JOIN dean_profiles dp ON dp.user_id = u.id
                     WHERE u.role = :role AND u.is_active = 1 AND dp.status = :status AND dp.college_id = :college_id
                     LIMIT 1'
                );
                $deanStmt->execute([
                    ':role' => 'dean',
                    ':status' => 'active',
                    ':college_id' => $routingCollegeId,
                ]);
                $dean = $deanStmt->fetch(PDO::FETCH_ASSOC);
                if ($dean) {
                    $recipientUserId = (int)$dean['id'];
                }
            } catch (PDOException $e) {
            }
        }
    }

    $insertStmt = $pdo->prepare(
        'INSERT INTO complaints (
            ticket_no,
            student_id,
            college_id,
            category_id,
            complainant_name,
            complainant_address,
            complainant_sex,
            complainant_age,
            complainant_civil_status,
            complainant_contact_details,
            person_complained_of,
            date_of_incident,
            place_of_incident,
            time_of_incident,
            act_complained_of,
            attachments,
            desired_outcome,
            terms_agreement_accepted,
            reported_type,
            status,
            approval_status,
            visibility_status,
            ai_suggested_category_id,
            ai_suggestion_confidence,
            urgency_score,
            urgency_level,
            ai_detected_language,
            ai_classification_source,
            school_year,
            semester,
            created_at
        ) VALUES (
            :ticket_no,
            :student_id,
            :college_id,
            :category_id,
            :complainant_name,
            :complainant_address,
            :complainant_sex,
            :complainant_age,
            :complainant_civil_status,
            :complainant_contact_details,
            :person_complained_of,
            :date_of_incident,
            :place_of_incident,
            :time_of_incident,
            :act_complained_of,
            :attachments,
            :desired_outcome,
            :terms_agreement_accepted,
            :reported_type,
            :status,
            :approval_status,
            :visibility_status,
            :ai_suggested_category_id,
            :ai_suggestion_confidence,
            :urgency_score,
            :urgency_level,
            :ai_detected_language,
            :ai_classification_source,
            :school_year,
            :semester,
            NOW()
        )'
    );

    $insertStmt->execute([
        ':ticket_no' => generate_ticket_no($pdo, 'complaints', 'VOX-C'),
        ':student_id' => (int)$student['id'],
        ':college_id' => $collegeId,
        ':category_id' => $categoryId,
        ':complainant_name' => $complainantName,
        ':complainant_address' => $complainantAddress,
        ':complainant_sex' => $complainantSex,
        ':complainant_age' => $complainantAge,
        ':complainant_civil_status' => $complainantCivilStatus,
        ':complainant_contact_details' => $complainantContactDetails,
        ':person_complained_of' => $personComplainedOf,
        ':date_of_incident' => $dateOfIncident,
        ':place_of_incident' => $placeOfIncident,
        ':time_of_incident' => !empty($timeOfIncident) ? $timeOfIncident : null,
        ':act_complained_of' => $actComplainedOf,
        ':attachments' => $attachment,
        ':desired_outcome' => $desiredOutcome,
        ':terms_agreement_accepted' => $termsAccepted,
        ':reported_type' => $reportedType,
        ':status' => 'new',
        ':approval_status' => 'approved',
        ':visibility_status' => 'private',
        ':ai_suggested_category_id' => $aiClassification['category_id'],
        ':ai_suggestion_confidence' => $aiClassification['confidence'],
        ':urgency_score' => $aiUrgencyResult['score'],
        ':urgency_level' => $aiUrgencyResult['level'],
        ':ai_detected_language' => $aiClassification['language'] ?? null,
        ':ai_classification_source' => $aiClassification['source'] ?? null,
        ':school_year' => $schoolYearCurrent,
        ':semester' => $semesterCurrent,
    ]);

    $complaintId = (int)$pdo->lastInsertId();

    if (!empty($reportedStudentIds)) {
        $linkStmt = $pdo->prepare('INSERT INTO complaint_student_links (complaint_id, student_id) VALUES (:complaint_id, :student_id)');
        $countStmt = $pdo->prepare('UPDATE student_profiles SET complaint_count = complaint_count + 1 WHERE id = :student_id');
        foreach ($reportedStudentIds as $reportedStudentId) {
            $linkStmt->execute([
                ':complaint_id' => $complaintId,
                ':student_id' => $reportedStudentId,
            ]);
            $countStmt->execute([':student_id' => $reportedStudentId]);
        }
    }

    if (!empty($reportedFacultyIds)) {
        $facultyLinkStmt = $pdo->prepare('INSERT INTO complaint_faculty_links (complaint_id, faculty_id) VALUES (:complaint_id, :faculty_id)');
        foreach ($reportedFacultyIds as $reportedFacultyId) {
            $facultyLinkStmt->execute([
                ':complaint_id' => $complaintId,
                ':faculty_id' => $reportedFacultyId,
            ]);
        }
    }

    $recipientLabel = $recipientRole === 'dean' ? 'your college dean' : 'admin';
    if ($recipientRole === 'admin') {
        $notifyStmt = $pdo->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1");
        $insertNotif = $pdo->prepare(
            'INSERT INTO notifications (user_id, type, message, ticket_type, ticket_id, is_read)
             VALUES (:user_id, :type, :message, :ticket_type, :ticket_id, :is_read)'
        );
        foreach ($notifyStmt->fetchAll() as $target) {
            $insertNotif->execute([
                ':user_id' => (int)$target['id'],
                ':type' => 'new_complaint',
                ':message' => $complainantName . ' submitted a new complaint.',
                ':ticket_type' => 'complaint',
                ':ticket_id' => $complaintId,
                ':is_read' => 0,
            ]);
        }
    } elseif ($recipientUserId > 0) {
        $insertNotif = $pdo->prepare(
            'INSERT INTO notifications (user_id, type, message, ticket_type, ticket_id, is_read)
             VALUES (:user_id, :type, :message, :ticket_type, :ticket_id, :is_read)'
        );
        $insertNotif->execute([
            ':user_id' => $recipientUserId,
            ':type' => 'new_complaint',
            ':message' => $complainantName . ' submitted a new complaint.',
            ':ticket_type' => 'complaint',
            ':ticket_id' => $complaintId,
            ':is_read' => 0,
        ]);
    }

    clear_preview_draft('complaint');
    back_with_message('success', 'Complaint submitted successfully and routed to ' . $recipientLabel . '.');
} catch (RuntimeException $e) {
    back_with_message('error', $e->getMessage());
} catch (PDOException $e) {
    back_with_message('error', 'Unable to save complaint: ' . $e->getMessage());
}
