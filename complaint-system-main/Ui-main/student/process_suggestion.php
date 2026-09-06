<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../ticket_flow.php';
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
$categoryInput = trim((string)($draft['category'] ?? ''));

if ($subject === '' || $description === '' || $categoryInput === '') {
    back_with_message('error', 'Please complete all required fields.');
}

$categoryAliases = [
    'campus_life' => ['Campus Life & Events', 'Campus Life Improvements', 'Student Events'],
    'facilities' => ['Facilities & Infrastructure', 'Facilities', 'Campus Facilities'],
    'academics' => ['Academics & Curriculum', 'Academic Concerns', 'Academics'],
    'technology' => ['Technology & Digital Services', 'Mobile App Features', 'Technology'],
    'other' => ['Other Ideas', 'Other'],
];

function suggestion_recipient_role(): string
{
    return 'dean';
}

try {
    $studentStmt = $pdo->prepare(
        'SELECT id, college_id
         FROM student_profiles
         WHERE user_id = :user_id
         LIMIT 1'
    );
    $studentStmt->execute([':user_id' => (int)$_SESSION['user_id']]);
    $student = $studentStmt->fetch();

    if (!$student) {
        back_with_message('error', 'Student profile not found.');
    }

    // Defense in depth: filing is only allowed for the current school year,
    // even if this is posted directly while a past school year is selected.
    $schoolYearCurrent = sy_current();
    $schoolYearSelected = sy_get_selected($pdo, (int)$student['id']);
    if ($schoolYearSelected !== $schoolYearCurrent) {
        back_with_message('error', 'Switch to the current school year (' . $schoolYearCurrent . ') to send a new suggestion.');
    }

    $attachment = finalize_preview_upload($draft, 'suggestions');
    $categoryId = resolve_category_id($pdo, 'suggestion_categories', $categoryInput, $categoryAliases);
    if ($categoryId === null) {
        back_with_message('error', 'Selected suggestion category is invalid. Please review the form again.');
    }
    $ticketNo = create_ticket_no($pdo, 'suggestions', 'VOX-S');
    $route = resolve_ticket_route($pdo, 'suggestion', $categoryId, $student['college_id'] !== null ? (int)$student['college_id'] : null);
    $recipientRole = (string)$route['role'];
    $recipientUserId = isset($route['user_id']) ? (int)$route['user_id'] : 0;
    // Set college_id based on routing: if routed to admin/general, store NULL so
    // the suggestion is not visible in dean scopes. If routed to dean, store
    // the student's college id.
    $collegeId = $recipientRole === 'admin' ? null : ($student['college_id'] !== null ? (int)$student['college_id'] : null);

    $insertStmt = $pdo->prepare(
        'INSERT INTO suggestions (
            ticket_no,
            student_id,
            college_id,
            category_id,
            date_of_suggestion,
            subject,
            description,
            expected_outcome,
            attachment,
            status,
            school_year
        ) VALUES (
            :ticket_no,
            :student_id,
            :college_id,
            :category_id,
            :date_of_suggestion,
            :subject,
            :description,
            :expected_outcome,
            :attachment,
            :status,
            :school_year
        )'
    );

    $insertStmt->execute([
        ':ticket_no' => $ticketNo,
        ':student_id' => (int)$student['id'],
        ':college_id' => $collegeId,
        ':category_id' => $categoryId,
        ':date_of_suggestion' => trim((string)($draft['date_of_suggestion'] ?? '')),
        ':subject' => $subject,
        ':description' => $description,
        ':expected_outcome' => trim((string)($draft['expected_outcome'] ?? '')),
        ':attachment' => $attachment,
        ':status' => 'approved',
        ':school_year' => $schoolYearCurrent,
    ]);

    $suggestionId = (int)$pdo->lastInsertId();

    $recipientLabel = $recipientRole === 'dean' ? 'your college dean' : 'admin';
    $insertNotif = $pdo->prepare(
        'INSERT INTO notifications (user_id, type, message, ticket_type, ticket_id, is_read)
         VALUES (:user_id, :type, :message, :ticket_type, :ticket_id, :is_read)'
    );

    if ($recipientRole === 'admin') {
        $adminStmt = $pdo->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1");
        foreach ($adminStmt->fetchAll() as $target) {
            $insertNotif->execute([
                ':user_id' => (int)$target['id'],
                ':type' => 'new_suggestion',
                ':message' => 'New general suggestion submitted: ' . $ticketNo,
                ':ticket_type' => 'suggestion',
                ':ticket_id' => $suggestionId,
                ':is_read' => 0,
            ]);
        }
    } elseif ($recipientUserId > 0) {
        $insertNotif->execute([
            ':user_id' => $recipientUserId,
            ':type' => 'new_suggestion',
            ':message' => 'New college suggestion submitted: ' . $ticketNo,
            ':ticket_type' => 'suggestion',
            ':ticket_id' => $suggestionId,
            ':is_read' => 0,
        ]);
    }

    clear_preview_draft('suggestion');
    back_with_message('success', 'Suggestion submitted successfully and routed to ' . $recipientLabel . '. Ticket: ' . $ticketNo);
} catch (RuntimeException $e) {
    back_with_message('error', $e->getMessage());
} catch (PDOException $e) {
    back_with_message('error', 'Unable to save suggestion: ' . $e->getMessage());
}
