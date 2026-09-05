<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../ticket_flow.php';
require_once __DIR__ . '/../faculty_helpers.php';

function back_with_message(string $type, string $msg): void
{
    $q = http_build_query([
        'status' => $type,
        'msg' => $msg,
    ]);
    header('Location: student_complaints.php?' . $q);
    exit;
}

function create_ticket_no(PDO $pdo, string $table, string $prefix): string
{
    for ($i = 0; $i < 10; $i++) {
        $ticket = sprintf('%s-%s-%04d', $prefix, date('Y'), random_int(1, 9999));
        $stmt = $pdo->prepare("SELECT id FROM {$table} WHERE ticket_no = :ticket LIMIT 1");
        $stmt->execute([':ticket' => $ticket]);
        if (!$stmt->fetch()) {
            return $ticket;
        }
    }

    throw new RuntimeException('Unable to generate unique ticket number.');
}

function resolve_category_id(PDO $pdo, string $table, string $submittedValue, array $aliases): ?int
{
    $value = strtolower(trim($submittedValue));
    if ($value === '') {
        return null;
    }

    $candidates = $aliases[$value] ?? [$submittedValue, str_replace('_', ' ', $submittedValue)];

    $stmt = $pdo->prepare("SELECT id FROM {$table} WHERE LOWER(name) = LOWER(:name) AND is_active = 1 LIMIT 1");
    foreach ($candidates as $name) {
        $stmt->execute([':name' => $name]);
        $row = $stmt->fetch();
        if ($row) {
            return (int)$row['id'];
        }
    }

    return null;
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

// Other
$categoryInput = trim((string)($draft['category'] ?? ''));
$normalizedCategory = strtolower($categoryInput);

// Validate required fields
if ($complainantName === '' || $complainantAddress === '' || $complainantSex === '' || 
    $complainantCivilStatus === '' || $complainantContactDetails === '' || 
    $personComplainedOf === '' || $dateOfIncident === '' || $placeOfIncident === '' || 
    $actComplainedOf === '' || $desiredOutcome === '' || 
    $categoryInput === '' || $termsAccepted === 0) {
    back_with_message('error', 'Please complete all required fields.');
}

function resolve_direct_category_route(PDO $pdo, string $categoryInput, ?int $studentCollegeId): array
{
    $normalized = strtolower(trim($categoryInput));

    if ($normalized === 'dean') {
        $collegeId = $studentCollegeId !== null && $studentCollegeId > 0 ? (int)$studentCollegeId : null;
        $recipientUserId = 0;

        if ($collegeId !== null) {
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
                    ':college_id' => $collegeId,
                ]);
                $dean = $deanStmt->fetch(PDO::FETCH_ASSOC);
                if ($dean) {
                    $recipientUserId = (int)$dean['id'];
                }
            } catch (PDOException $e) {
            }
        }

        return [
            'role' => 'dean',
            'user_id' => $recipientUserId,
            'college_id' => $collegeId,
        ];
    }

    return [
        'role' => 'admin',
        'user_id' => 0,
        'college_id' => null,
    ];
}

// Who is being reported decides where the complaint goes, so the student
// filing it never picks an office: a reported student goes to the dean of
// that student's college, a reported faculty/staff member goes to the SAS
// Director.
ensure_faculty_tables($pdo);

$reportedType = (string)($draft['reported_type'] ?? 'student');
if (!in_array($reportedType, ['student', 'faculty'], true)) {
    $reportedType = 'student';
}
$reportedFacultyIds = array_values(array_filter(array_map('intval', (array)($draft['reported_faculty_ids'] ?? []))));

// A complaint that routes to a dean should go to the dean with jurisdiction
// over the REPORTED student (who the dean would actually call in) — not the
// complainant's own dean. Fall back to the complainant's college only when no
// reported student profile is on record (e.g. the reported party isn't a
// registered student), preserving the previous behavior for that case.
$reportedStudentCollegeId = null;
if (!empty($reportedStudentIds)) {
    try {
        $reportedCollegeStmt = $pdo->prepare('SELECT college_id FROM student_profiles WHERE id = :id LIMIT 1');
        $reportedCollegeStmt->execute([':id' => $reportedStudentIds[0]]);
        $reportedCollegeIdValue = $reportedCollegeStmt->fetchColumn();
        if ($reportedCollegeIdValue !== false && $reportedCollegeIdValue !== null) {
            $reportedStudentCollegeId = (int)$reportedCollegeIdValue;
        }
    } catch (PDOException $e) {
        $reportedStudentCollegeId = null;
    }
}
$routingCollegeId = $reportedStudentCollegeId
    ?? ($student['college_id'] !== null ? (int)$student['college_id'] : null);

try {
    $attachment = finalize_preview_upload($draft, 'complaints');
    $categoryId = null;
    $recipientRole = 'admin';
    $recipientUserId = 0;
    $collegeId = null;

    if ($reportedType === 'faculty') {
        // Complaints about faculty/staff are handled by the SAS Director.
        $recipientRole = 'admin';
        $recipientUserId = 0;
        $collegeId = null;
    } elseif (in_array($normalizedCategory, ['dean', 'admin'], true)) {
        $route = resolve_direct_category_route($pdo, $categoryInput, $routingCollegeId);
        $recipientRole = (string)$route['role'];
        $recipientUserId = isset($route['user_id']) ? (int)$route['user_id'] : 0;
        $collegeId = isset($route['college_id']) ? (int)$route['college_id'] : null;
    } else {
        $categoryAliases = [
            'facilities' => ['Facilities & Maintenance', 'Facilities', 'Campus Facilities'],
            'academic' => ['Academic Concern', 'Academic Concerns', 'Academics'],
            'safety' => ['Safety & Security', 'Security & Safety', 'Safety'],
            'admin' => ['Administrative / Registrar', 'Administrative', 'Registrar'],
            'other' => ['Other'],
        ];
        $categoryId = resolve_category_id($pdo, 'complaint_categories', $categoryInput, $categoryAliases);
        if ($categoryId === null) {
            back_with_message('error', 'Selected complaint category is invalid. Please review the form again.');
        }
        $route = resolve_ticket_route($pdo, 'complaint', $categoryId, $routingCollegeId);
        $recipientRole = (string)$route['role'];
        $recipientUserId = isset($route['user_id']) ? (int)$route['user_id'] : 0;
        $collegeId = $recipientRole === 'admin' ? null : $routingCollegeId;
    }

    $ticketNo = create_ticket_no($pdo, 'complaints', 'VOX-C');

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
            NOW()
        )'
    );

    $insertStmt->execute([
        ':ticket_no' => $ticketNo,
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
                ':message' => 'New general complaint submitted: ' . $ticketNo,
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
            ':message' => 'New college complaint submitted: ' . $ticketNo,
            ':ticket_type' => 'complaint',
            ':ticket_id' => $complaintId,
            ':is_read' => 0,
        ]);
    }

    clear_preview_draft('complaint');
    back_with_message('success', 'Complaint submitted successfully and routed to ' . $recipientLabel . '. Ticket: ' . $ticketNo);
} catch (RuntimeException $e) {
    back_with_message('error', $e->getMessage());
} catch (PDOException $e) {
    back_with_message('error', 'Unable to save complaint: ' . $e->getMessage());
}
