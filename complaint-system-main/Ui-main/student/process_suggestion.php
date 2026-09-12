<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../ticket_flow.php';
require_once __DIR__ . '/../suggestion_flow.php';
require_once __DIR__ . '/../school_year_helpers.php';

function back_with_message(string $type, string $msg): void
{
    $q = http_build_query([
        'status' => $type,
        'msg' => $msg,
    ]);
    header('Location: student_complaints.php?mode=suggestion&' . $q);
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
        'image/webp' => 'webp',
    ];

    if (!isset($map[$mime])) {
        throw new RuntimeException('Only JPG, PNG, and WEBP images are allowed.');
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

$draft = get_preview_draft('suggestion');
if (empty($draft)) {
    back_with_message('error', 'Please review your suggestion before submitting it.');
}

$draftToken = (string)($_POST['draft_token'] ?? '');
if ($draftToken === '' || !hash_equals(get_preview_token('suggestion'), $draftToken)) {
    back_with_message('error', 'Your suggestion preview has expired. Please review it again.');
}

$subject = trim((string)($draft['subject'] ?? ''));
$description = trim((string)($draft['description'] ?? ''));
$categoryIdInput = (int)($draft['category_id'] ?? 0);

if ($subject === '' || $description === '' || $categoryIdInput <= 0) {
    back_with_message('error', 'Please complete all required fields.');
}

function suggestion_recipient_role(): string
{
    return 'dean';
}

try {
    ensure_suggestion_status_enum($pdo);
    ensure_suggestion_tracking_columns($pdo);
    ensure_suggestion_area_schema($pdo);

    $studentStmt = $pdo->prepare(
        'SELECT id, college_id, first_name, last_name
         FROM student_profiles
         WHERE user_id = :user_id
         LIMIT 1'
    );
    $studentStmt->execute([':user_id' => (int)$_SESSION['user_id']]);
    $student = $studentStmt->fetch();

    if (!$student) {
        back_with_message('error', 'Student profile not found.');
    }

    $studentDisplayName = trim((string)($student['first_name'] ?? '') . ' ' . (string)($student['last_name'] ?? ''));
    if ($studentDisplayName === '') {
        $studentDisplayName = 'A student';
    }

    // Defense in depth: filing is only allowed for the current school year
    // and semester, even if this is posted directly while a past period is selected.
    $schoolYearCurrent = sy_current($pdo);
    $schoolYearSelected = sy_get_selected($pdo, (int)$student['id']);
    if ($schoolYearSelected !== $schoolYearCurrent) {
        back_with_message('error', 'Switch to the current school year (' . $schoolYearCurrent . ') to send a new suggestion.');
    }
    $semesterCurrent = semester_current();
    $semesterSelected = semester_get_selected();
    if ($semesterSelected !== $semesterCurrent) {
        back_with_message('error', 'Switch to the current semester (' . semester_display_label($semesterCurrent) . ') to send a new suggestion.');
    }

    $attachment = finalize_preview_upload($draft, 'suggestions');

    // The student only ever picks an area then a specific category; routing
    // is entirely derived from that category's route_type behind the scenes:
    //   office -> that office's staff
    //   dean   -> the dean of the student's own college
    //   admin  -> the SAS Director (VOICE Admin)
    $categoryLookupStmt = $pdo->prepare(
        'SELECT id, office, route_type FROM suggestion_categories WHERE id = :id AND is_active = 1 LIMIT 1'
    );
    $categoryLookupStmt->execute([':id' => $categoryIdInput]);
    $categoryRow = $categoryLookupStmt->fetch();
    if (!$categoryRow) {
        back_with_message('error', 'The selected category is no longer available. Please choose another.');
    }

    $categoryId = (int)$categoryRow['id'];
    $categoryOffice = trim((string)($categoryRow['office'] ?? ''));
    $routeType = (string)($categoryRow['route_type'] ?? 'admin');
    if (!in_array($routeType, ['office', 'dean', 'admin'], true)) {
        $routeType = 'admin';
    }
    if ($routeType === 'office' && $categoryOffice === '') {
        // Misconfigured category (marked office-routed but no office set) -
        // fail safe to admin rather than losing the suggestion.
        $routeType = 'admin';
    }

    $recipientRole = 'admin';
    $recipientUserId = 0;
    // Only a dean-routed suggestion is scoped to a college. Office- and
    // admin-routed suggestions always store no college_id, so they never
    // leak into a dean's inbox (which is keyed purely on college_id).
    $collegeId = null;

    if ($routeType === 'office') {
        $recipientRole = 'staff';
    } elseif ($routeType === 'dean') {
        $studentCollegeId = $student['college_id'] !== null ? (int)$student['college_id'] : 0;
        if ($studentCollegeId > 0) {
            $recipientRole = 'dean';
            $collegeId = $studentCollegeId;
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
                    ':college_id' => $studentCollegeId,
                ]);
                $deanId = $deanStmt->fetchColumn();
                if ($deanId) {
                    $recipientUserId = (int)$deanId;
                }
            } catch (PDOException $e) {
            }
        }
        // No college on file for this student - nothing to route to, fall
        // back to admin rather than leaving the suggestion unreachable.
    }

    $insertStmt = $pdo->prepare(
        'INSERT INTO suggestions (
            ticket_no,
            student_id,
            college_id,
            category_id,
            office,
            date_of_suggestion,
            subject,
            description,
            expected_outcome,
            attachment,
            status,
            school_year,
            semester
        ) VALUES (
            :ticket_no,
            :student_id,
            :college_id,
            :category_id,
            :office,
            :date_of_suggestion,
            :subject,
            :description,
            :expected_outcome,
            :attachment,
            :status,
            :school_year,
            :semester
        )'
    );

    $insertStmt->execute([
        ':ticket_no' => generate_ticket_no($pdo, 'suggestions', 'VOX-S'),
        ':student_id' => (int)$student['id'],
        ':college_id' => $collegeId,
        ':category_id' => $categoryId,
        ':office' => $categoryOffice !== '' ? $categoryOffice : null,
        ':date_of_suggestion' => date('Y-m-d'),
        ':subject' => $subject,
        ':description' => $description,
        ':expected_outcome' => trim((string)($draft['expected_outcome'] ?? '')),
        ':attachment' => $attachment,
        ':status' => 'under_review',
        ':school_year' => $schoolYearCurrent,
        ':semester' => $semesterCurrent,
    ]);

    $suggestionId = (int)$pdo->lastInsertId();

    $recipientLabel = $recipientRole === 'staff' ? $categoryOffice : ($recipientRole === 'dean' ? 'your college dean' : 'admin');
    $insertNotif = $pdo->prepare(
        'INSERT INTO notifications (user_id, type, message, ticket_type, ticket_id, is_read)
         VALUES (:user_id, :type, :message, :ticket_type, :ticket_id, :is_read)'
    );

    if ($recipientRole === 'staff') {
        $staffStmt = $pdo->prepare("SELECT u.id FROM users u INNER JOIN staff_profiles sp ON sp.user_id = u.id WHERE u.role = 'staff' AND u.is_active = 1 AND sp.status = 'active' AND LOWER(TRIM(sp.office)) = LOWER(TRIM(:office))");
        $staffStmt->execute([':office' => $categoryOffice]);
        foreach ($staffStmt->fetchAll() as $target) {
            $insertNotif->execute([
                ':user_id' => (int)$target['id'],
                ':type' => 'new_suggestion',
                ':message' => $studentDisplayName . ' submitted a new suggestion.',
                ':ticket_type' => 'suggestion',
                ':ticket_id' => $suggestionId,
                ':is_read' => 0,
            ]);
        }
    } elseif ($recipientRole === 'admin') {
        $adminStmt = $pdo->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1");
        foreach ($adminStmt->fetchAll() as $target) {
            $insertNotif->execute([
                ':user_id' => (int)$target['id'],
                ':type' => 'new_suggestion',
                ':message' => $studentDisplayName . ' submitted a new suggestion.',
                ':ticket_type' => 'suggestion',
                ':ticket_id' => $suggestionId,
                ':is_read' => 0,
            ]);
        }
    } elseif ($recipientUserId > 0) {
        $insertNotif->execute([
            ':user_id' => $recipientUserId,
            ':type' => 'new_suggestion',
            ':message' => $studentDisplayName . ' submitted a new suggestion.',
            ':ticket_type' => 'suggestion',
            ':ticket_id' => $suggestionId,
            ':is_read' => 0,
        ]);
    }

    clear_preview_draft('suggestion');
    back_with_message('success', 'Suggestion submitted successfully and routed to ' . $recipientLabel . '.');
} catch (RuntimeException $e) {
    back_with_message('error', $e->getMessage());
} catch (PDOException $e) {
    back_with_message('error', 'Unable to save suggestion: ' . $e->getMessage());
}
