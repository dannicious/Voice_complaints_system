<?php

session_start();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../ticket_flow.php';
require_once __DIR__ . '/../call_slip_helpers.php';
require_once __DIR__ . '/../complaint_age_helpers.php';
require_once __DIR__ . '/../response_timeline_ui.php';
// Prevent PHP warnings from being printed to the page (they break layout). Logging still occurs.
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
// simple escaper used across templates
if (!function_exists('e')) {
    function e(string $value = ''): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
// Resolve display name and photo for a sender (student/dean/admin)
function get_person_display(PDO $pdo, string $role, int|string $id): array
{
    $role = strtolower($role);
    try {
        if ($role === 'student') {
            $stmt = $pdo->prepare('SELECT sp.first_name, sp.last_name, u.profile_pic FROM student_profiles sp LEFT JOIN users u ON u.id = sp.user_id WHERE sp.id = :id LIMIT 1');
            $stmt->execute([':id' => (int)$id]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($r) {
                $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: 'Student';
                $photo = $r['profile_pic'] ?? null;
                return ['name' => $name, 'photo' => $photo];
            }
        }

        if ($role === 'dean') {
            // Try dean_profiles by profile id
            $stmt = $pdo->prepare('SELECT dp.first_name, dp.last_name, dp.profile_photo, u.profile_pic FROM dean_profiles dp LEFT JOIN users u ON u.id = dp.user_id WHERE dp.id = :id LIMIT 1');
            $stmt->execute([':id' => (int)$id]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($r && (!empty($r['first_name']) || !empty($r['last_name']) || !empty($r['profile_photo']) || !empty($r['profile_pic']))) {
                $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: 'Dean';
                $photo = $r['profile_photo'] ?? $r['profile_pic'] ?? null;
                return ['name' => $name, 'photo' => $photo];
            }

            // If not found, maybe sender_id is actually the users.id — try by user_id
            $stmt = $pdo->prepare('SELECT dp.first_name, dp.last_name, dp.profile_photo, u.profile_pic FROM dean_profiles dp LEFT JOIN users u ON u.id = dp.user_id WHERE dp.user_id = :id LIMIT 1');
            $stmt->execute([':id' => (int)$id]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($r && (!empty($r['first_name']) || !empty($r['last_name']) || !empty($r['profile_photo']) || !empty($r['profile_pic']))) {
                $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: 'Dean';
                $photo = $r['profile_photo'] ?? $r['profile_pic'] ?? null;
                return ['name' => $name, 'photo' => $photo];
            }

            // As a last resort, try to read from users table directly
            $stmt = $pdo->prepare('SELECT u.first_name, u.last_name, u.profile_pic FROM users u WHERE u.id = :id LIMIT 1');
            $stmt->execute([':id' => (int)$id]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($r) {
                $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: 'Dean';
                $photo = $r['profile_pic'] ?? null;
                return ['name' => $name, 'photo' => $photo];
            }
        }

        if ($role === 'admin') {
            $stmt = $pdo->prepare('SELECT ap.name, u.profile_pic FROM admin_profiles ap LEFT JOIN users u ON u.id = ap.user_id WHERE ap.id = :id LIMIT 1');
            $stmt->execute([':id' => (int)$id]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($r) {
                $name = $r['name'] ?? 'Admin';
                $photo = $r['profile_pic'] ?? null;
                return ['name' => $name, 'photo' => $photo];
            }
        }
    } catch (Exception $e) {
        // ignore and fallback
    }

    return ['name' => ucfirst($role), 'photo' => null];
}

// Ensure we have the current dean's profile ID available
$deanProfileId = 0;
if (!isset($_SESSION['user_id']) || (string)($_SESSION['role'] ?? '') !== 'dean') {
    $deanProfileId = 0;
} else {
    try {
        $deanStmt = $pdo->prepare('SELECT id FROM dean_profiles WHERE user_id = :user_id LIMIT 1');
        $deanStmt->execute([':user_id' => (int)$_SESSION['user_id']]);
        $dean = $deanStmt->fetch();
        if ($dean) {
            $deanProfileId = (int)$dean['id'];
        } else {
            $deanProfileId = 0;
        }
    } catch (PDOException $e) {
        $deanProfileId = 0;
    }
}

if ($deanProfileId <= 0) {
    http_response_code(403);
    echo 'Dean profile not found.';
    exit;
}

/**
 * Every Call Slip fact that depends on which dean's account/department is
 * logged in — the office to report to and who signs as "Issued by" — comes
 * from this single lookup, keyed by college code.
 */
function dean_call_slip_profile(string $collegeCode): array
{
    $profiles = [
        'CCJ' => [
            'signatory' => 'Dr. Marry Joyce Ale',
            'office_message' => "Please see the Dean at the CCJ Dean's Office.",
        ],
        'CCIS' => [
            'signatory' => 'Dr. Shella C. Olaguir',
            'office_message' => "Please see the Dean at the CCIS Dean's Office.",
        ],
        'CTAS' => [
            'signatory' => 'Mrs. Irene G. Maglajos',
            'office_message' => "Please see the Dean at the CTAS Dean's Office.",
        ],
    ];
    return $profiles[$collegeCode] ?? [
        'signatory' => 'DEAN',
        'office_message' => "Please see the Dean at the Dean's Office.",
    ];
}

// Resolve this dean's own college, so a Call Slip issued from this dashboard
// is signed with — and directs students to — the dean assigned to that
// college, not whoever happens to be the reported student's college.
$deanOwnCollegeCode = '';
try {
    $deanCollegeStmt = $pdo->prepare(
        'SELECT c.code FROM dean_profiles dp LEFT JOIN colleges c ON c.id = dp.college_id WHERE dp.id = :id LIMIT 1'
    );
    $deanCollegeStmt->execute([':id' => $deanProfileId]);
    $deanOwnCollegeCode = strtoupper(trim((string)($deanCollegeStmt->fetchColumn() ?: '')));
} catch (PDOException $e) {
    $deanOwnCollegeCode = '';
}
$deanCallSlipProfile = dean_call_slip_profile($deanOwnCollegeCode);

function ensure_call_slip_table(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS call_slips (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        ticket_type ENUM('complaint', 'suggestion') NOT NULL DEFAULT 'complaint',
        ticket_id INT UNSIGNED NOT NULL,
        student_id INT UNSIGNED NOT NULL,
        issued_by_role VARCHAR(40) NOT NULL,
        issued_by_user_id INT UNSIGNED DEFAULT NULL,
        issued_by_name VARCHAR(150) DEFAULT NULL,
        report_date DATE DEFAULT NULL,
        report_time VARCHAR(20) DEFAULT NULL,
        office_message VARCHAR(255) DEFAULT NULL,
        issued_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        status VARCHAR(20) NOT NULL DEFAULT 'issued',
        PRIMARY KEY (id),
        KEY idx_ticket (ticket_type, ticket_id),
        KEY idx_student (student_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    // Self-heal older installs where call_slips already existed without
    // these columns - the report date/time/venue a call slip actually
    // communicates, previously only ever emailed and never stored, so a
    // student could never see it again after the email was gone.
    foreach ([
        'issued_by_name' => "ALTER TABLE call_slips ADD COLUMN issued_by_name VARCHAR(150) DEFAULT NULL",
        'report_date' => "ALTER TABLE call_slips ADD COLUMN report_date DATE DEFAULT NULL",
        'report_time' => "ALTER TABLE call_slips ADD COLUMN report_time VARCHAR(20) DEFAULT NULL",
        'office_message' => "ALTER TABLE call_slips ADD COLUMN office_message VARCHAR(255) DEFAULT NULL",
        'reason_note' => "ALTER TABLE call_slips ADD COLUMN reason_note TEXT DEFAULT NULL",
    ] as $column => $alterSql) {
        $exists = $pdo->query('SHOW COLUMNS FROM call_slips LIKE ' . $pdo->quote($column))->fetchColumn();
        if (!$exists) {
            $pdo->exec($alterSql);
        }
    }
}

function issue_call_slip(PDO $pdo, int $ticketId, string $ticketType, int $studentProfileId, string $issuedByRole, int $issuedByUserId, string $issuedByName = '', string $categoryName = '', string $reportDate = '', string $reportTime = '', string $officeMessage = '', string $reasonNote = ''): array
{
    try {
        ensure_call_slip_table($pdo);
        $ticketType = strtolower($ticketType);
        if (!in_array($ticketType, ['complaint', 'suggestion'], true)) {
            return ['ok' => false, 'message' => 'Invalid ticket type.'];
        }

        $studentStmt = $pdo->prepare('SELECT user_id FROM student_profiles WHERE id = :id LIMIT 1');
        $studentStmt->execute([':id' => $studentProfileId]);
        $studentRow = $studentStmt->fetch(PDO::FETCH_ASSOC);
        if (!$studentRow) {
            return ['ok' => false, 'message' => 'Student not found.'];
        }

        $table = $ticketType === 'complaint' ? 'complaints' : 'suggestions';
        $ticketNoStmt = $pdo->prepare('SELECT ticket_no, student_id FROM ' . $table . ' WHERE id = :id LIMIT 1');
        $ticketNoStmt->execute([':id' => $ticketId]);
        $ticketRow = $ticketNoStmt->fetch(PDO::FETCH_ASSOC);
        $ticketNo = $ticketRow['ticket_no'] ?? 'UNKNOWN';
        $complainantStudentId = (int)($ticketRow['student_id'] ?? 0);

        $issuedByName = trim($issuedByName);
        $categoryName = trim($categoryName);
        $reportDate = trim($reportDate);
        $reportTime = trim($reportTime);
        $officeMessage = trim($officeMessage);
        // Matches the textarea's maxlength="300" - enforced here too since a
        // direct POST could otherwise bypass the browser-side limit.
        $reasonNote = mb_substr(trim($reasonNote), 0, 300);
        $categorySuffix = $categoryName !== '' ? ' about ' . $categoryName : '';

        $insertStmt = $pdo->prepare(
            'INSERT INTO call_slips (ticket_type, ticket_id, student_id, issued_by_role, issued_by_user_id, issued_by_name, report_date, report_time, office_message, reason_note, status)
             VALUES (:ticket_type, :ticket_id, :student_id, :issued_by_role, :issued_by_user_id, :issued_by_name, :report_date, :report_time, :office_message, :reason_note, :status)'
        );
        $insertStmt->execute([
            ':ticket_type' => $ticketType,
            ':ticket_id' => $ticketId,
            ':student_id' => $studentProfileId,
            ':issued_by_role' => $issuedByRole,
            ':issued_by_user_id' => $issuedByUserId > 0 ? $issuedByUserId : null,
            ':issued_by_name' => $issuedByName !== '' ? $issuedByName : null,
            ':report_date' => $reportDate !== '' ? $reportDate : null,
            ':report_time' => $reportTime !== '' ? $reportTime : null,
            ':office_message' => $officeMessage !== '' ? $officeMessage : null,
            ':reason_note' => $reasonNote !== '' ? $reasonNote : null,
            ':status' => 'issued',
        ]);

        $notifyStmt = $pdo->prepare(
            'INSERT INTO notifications (user_id, type, message, ticket_type, ticket_id, is_read)
             VALUES (:user_id, :type, :message, :ticket_type, :ticket_id, 0)'
        );

        // The call slip itself is a summons: it's addressed to the student
        // named in the complaint, e.g. "Dr. Shella C. Olaguir issued a call
        // slip regarding a complaint about Bullying filed against you."
        $summonsMessage = $issuedByName !== ''
            ? $issuedByName . ' issued a call slip regarding a ' . $ticketType . $categorySuffix . ' filed against you.'
            : 'A call slip has been issued for complaint ' . $ticketNo . '.';
        $notifyStmt->execute([
            ':user_id' => (int)$studentRow['user_id'],
            ':type' => 'call_slip_issued',
            ':message' => $summonsMessage,
            ':ticket_type' => $ticketType,
            ':ticket_id' => $ticketId,
        ]);

        // Also let the student who filed the complaint know it led to a call
        // slip, e.g. "Dr. Shella C. Olaguir issued a call slip for your
        // complaint about Bullying." Skipped if they're the same person the
        // call slip above was already addressed to (e.g. no separate reported
        // student was on record).
        if ($complainantStudentId > 0 && $complainantStudentId !== $studentProfileId) {
            $complainantUserStmt = $pdo->prepare('SELECT user_id FROM student_profiles WHERE id = :id LIMIT 1');
            $complainantUserStmt->execute([':id' => $complainantStudentId]);
            $complainantUserId = (int)($complainantUserStmt->fetchColumn() ?: 0);
            if ($complainantUserId > 0) {
                $complainantMessage = $issuedByName !== ''
                    ? $issuedByName . ' issued a call slip for your ' . $ticketType . $categorySuffix . '.'
                    : 'A call slip has been issued for your ' . $ticketType . ' ' . $ticketNo . '.';
                $notifyStmt->execute([
                    ':user_id' => $complainantUserId,
                    ':type' => 'call_slip_issued',
                    ':message' => $complainantMessage,
                    ':ticket_type' => $ticketType,
                    ':ticket_id' => $ticketId,
                ]);
            }
        }

        return ['ok' => true, 'message' => 'Call Slip issued successfully.'];
    } catch (Throwable $e) {
        error_log('issue_call_slip: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Unable to issue call slip right now.'];
    }
}

// Initialize template variables
$flashMessage = '';
$flashType = 'info';
$showCallSlipConfirmation = false;
if (!empty($_SESSION['call_slip_flash']) && is_array($_SESSION['call_slip_flash'])) {
    $flashMessage = (string)($_SESSION['call_slip_flash']['message'] ?? '');
    $flashType = (string)($_SESSION['call_slip_flash']['type'] ?? 'info');
    $showCallSlipConfirmation = $flashType === 'success';
    unset($_SESSION['call_slip_flash']);
}
$ticketId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($ticketId <= 0) {
    $flashMessage = 'Complaint identifier is missing.';
    $flashType = 'error';
}

$ticket = null;
$replies = [];
$feedback = null;
$feedbackReplies = [];
$feedbackHistory = [];
$deanRemark = null;
$callSlipHistory = [];
$deanOfficeMessage = $deanCallSlipProfile['office_message'];

if ($ticketId > 0) {
    try {
        $stmt = $pdo->prepare('SELECT c.*, cc.name AS category_name, sp.first_name, sp.last_name FROM complaints c LEFT JOIN complaint_categories cc ON cc.id = c.category_id LEFT JOIN student_profiles sp ON sp.id = c.student_id WHERE c.id = :id LIMIT 1');
        $stmt->execute([':id' => $ticketId]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$ticket) {
            $flashMessage = 'Complaint not found.';
            $flashType = 'error';
        } else {
            // Opening a complaint counts as picking it up, so it stops sitting
            // in the list as "New" while the dean is already handling it.
            if (complaint_is_awaiting_action((string)($ticket['status'] ?? ''))) {
                mark_complaint_under_review($pdo, $ticketId);
                $ticket['status'] = 'under_review';
            }

            $studentDisplayName = '';
            $studentDisplayEmail = '';
            $reportedStudentId = 0;
            $reportedStudentStmt = $pdo->prepare(
                'SELECT sp.id AS reported_student_id, sp.first_name, sp.last_name, u.email
                 FROM complaint_student_links csl
                 LEFT JOIN student_profiles sp ON sp.id = csl.student_id
                 LEFT JOIN users u ON u.id = sp.user_id
                 WHERE csl.complaint_id = :complaint_id
                 ORDER BY csl.created_at ASC, csl.student_id ASC
                 LIMIT 1'
            );
            $reportedStudentStmt->execute([':complaint_id' => $ticketId]);
            $reportedStudent = $reportedStudentStmt->fetch(PDO::FETCH_ASSOC);
            if ($reportedStudent) {
                $reportedStudentId = (int)($reportedStudent['reported_student_id'] ?? 0);
                $studentDisplayName = trim((string)($reportedStudent['first_name'] ?? '') . ' ' . (string)($reportedStudent['last_name'] ?? ''));
                $studentDisplayEmail = trim((string)($reportedStudent['email'] ?? ''));
            }
            if ($studentDisplayName === '') {
                $studentDisplayName = trim((string)($ticket['person_complained_of'] ?? ''));
            }

            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'issue_call_slip') {
                $recipientEmail = trim((string)($_POST['to_name'] ?? $studentDisplayEmail));
                $recipientName = trim((string)($_POST['student_name'] ?? $studentDisplayName));
                $dateIssued = trim((string)($_POST['date_issued'] ?? ''));
                $timeIssued = trim((string)($_POST['time_issued'] ?? ''));
                $reasonNote = trim((string)($_POST['reason_note'] ?? ''));
                $result = issue_call_slip(
                    $pdo,
                    $ticketId,
                    'complaint',
                    $reportedStudentId > 0 ? $reportedStudentId : (int)$ticket['student_id'],
                    'dean',
                    (int)$_SESSION['user_id'],
                    $deanCallSlipProfile['signatory'],
                    (string)($ticket['category_name'] ?? ''),
                    $dateIssued,
                    format_call_slip_time($timeIssued),
                    $deanOfficeMessage,
                    $reasonNote
                );
                if ($result['ok']) {
                    $mailResult = send_call_slip_email(
                        $recipientEmail,
                        $recipientName,
                        (string)($ticket['ticket_no'] ?? ''),
                        $dateIssued,
                        $timeIssued,
                        $deanOfficeMessage,
                        $deanCallSlipProfile['signatory'],
                        $reasonNote
                    );
                    $flashMessage = $result['message'];
                    if (!$mailResult['ok']) {
                        $flashMessage .= ' ' . $mailResult['message'];
                        $flashType = 'error';
                    } else {
                        $flashMessage .= ' ' . $mailResult['message'];
                    }
                    if ($mailResult['ok']) {
                        $flashType = 'success';
                        $_SESSION['call_slip_flash'] = [
                            'message' => 'Call Slip sent successfully to ' . $recipientEmail . '.',
                            'type' => 'success',
                        ];
                        header('Location: ' . $_SERVER['REQUEST_URI']);
                        exit;
                    }
                }
                if ($flashMessage === '') {
                    $flashMessage = $result['message'];
                    $flashType = 'error';
                }
            }

            // POST handling for status update and adding/editing remarks
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                if (isset($_POST['action']) && $_POST['action'] === 'update_status') {
                    // CSRF check
                    $postedToken = (string)($_POST['csrf_token'] ?? '');
                    $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
                    if ($postedToken === '' || $sessionToken === '' || !hash_equals($sessionToken, $postedToken)) {
                        $flashMessage = 'Your session has expired. Please refresh the page and try again.';
                        $flashType = 'error';
                    } else {
                        $newStatus = (string)($_POST['status'] ?? '');
                        $remark = trim((string)($_POST['remark'] ?? ''));
                        $remarkId = isset($_POST['remark_id']) ? (int)$_POST['remark_id'] : 0;

                        // A dismissal always carries a reason: if the remarks
                        // box was left empty, the standard wording stands in.
                        if ($newStatus === 'dismissed' && $remark === '') {
                            $remark = default_dismissal_remark();
                        }

                        if (in_array($newStatus, ['under_review', 'resolved', 'dismissed'], true)) {
                            // Resolving still needs a written reason; there is
                            // no sensible generic wording for that outcome.
                            if ($newStatus === 'resolved' && $remark === '') {
                                $flashMessage = 'Please provide Official Remarks before marking this complaint as Resolved.';
                                $flashType = 'error';
                            } else {
                            try {
                                $updateStmt = $pdo->prepare('UPDATE complaints SET status = :status WHERE id = :id');
                                $updateStmt->execute([':status' => $newStatus, ':id' => $ticketId]);

                                // Handle remark update/create. Allow updating an existing remark
                                // even if the new content is empty (so a dean can clear remarks).
                                if ($remarkId > 0) {
                                    // Update existing remark (allow empty message)
                                    $updateReplyStmt = $pdo->prepare('UPDATE ticket_replies SET message = :message WHERE id = :id AND sender_id = :sender_id AND sender_role = :sender_role');
                                    $updateReplyStmt->execute([
                                        ':message' => $remark,
                                        ':id' => $remarkId,
                                        ':sender_id' => $deanProfileId,
                                        ':sender_role' => 'dean'
                                    ]);
                                } else {
                                    // Creating a new remark: only create if non-empty to avoid blank replies
                                    if ($remark !== '') {
                                        // Check if this dean already has a remark for this complaint
                                        $checkStmt = $pdo->prepare('SELECT id FROM ticket_replies WHERE ticket_type = :ticket_type AND ticket_id = :ticket_id AND sender_id = :sender_id AND sender_role = :sender_role LIMIT 1');
                                        $checkStmt->execute([
                                            ':ticket_type' => 'complaint',
                                            ':ticket_id' => $ticketId,
                                            ':sender_id' => $deanProfileId,
                                            ':sender_role' => 'dean'
                                        ]);
                                        $existingRemark = $checkStmt->fetch();

                                        if (!$existingRemark) {
                                            // Create new remark (only if one doesn't exist)
                                            $replyStmt = $pdo->prepare('INSERT INTO ticket_replies (ticket_type, ticket_id, sender_id, sender_role, message, created_at) VALUES (:ticket_type, :ticket_id, :sender_id, :sender_role, :message, NOW())');
                                            $replyStmt->execute([
                                                ':ticket_type' => 'complaint',
                                                ':ticket_id' => $ticketId,
                                                ':sender_id' => $deanProfileId,
                                                ':sender_role' => 'dean',
                                                ':message' => $remark
                                            ]);
                                        }
                                    }
                                }

                                // Let the student know their complaint was updated.
                                $actorInfo = get_person_display($pdo, 'dean', $deanProfileId);
                                $actorName = $actorInfo['name'] ?? 'Your dean';
                                $statusLabel = ucwords(str_replace('_', ' ', $newStatus));
                                $updateMessage = $remark !== ''
                                    ? $actorName . ' posted an official remark and updated your complaint status to ' . $statusLabel . '.'
                                    : $actorName . ' updated your complaint status to ' . $statusLabel . '.';
                                notify_ticket_owner($pdo, 'complaint', $ticketId, (int)$ticket['student_id'], 'complaint_update', $updateMessage);

                                // Reload to show updated data
                                header('Location: ' . $_SERVER['REQUEST_URI']);
                                exit;
                            } catch (Throwable $e) {
                                $flashMessage = 'Unable to save changes at this time.';
                                $flashType = 'error';
                            }
                            }
                        }
                    }
                }
            }

            // load replies
            $replyStmt = $pdo->prepare('SELECT id, sender_id, sender_role, message, created_at FROM ticket_replies WHERE ticket_type = :ticket_type AND ticket_id = :ticket_id ORDER BY created_at ASC, id ASC');
            $replyStmt->execute([':ticket_type' => 'complaint', ':ticket_id' => $ticketId]);
            $replies = $replyStmt->fetchAll(PDO::FETCH_ASSOC);

            // Find dean's remark (first reply from this dean)
            foreach ($replies as $reply) {
                if ((int)$reply['sender_id'] === $deanProfileId && strtolower((string)$reply['sender_role']) === 'dean') {
                    $deanRemark = $reply;
                    break;
                }
            }

            // load feedback
            $feedback = get_ticket_feedback($pdo, 'complaint', $ticketId, (int)$ticket['student_id']);
            $feedbackReplies = get_ticket_feedback_replies($pdo, 'complaint', $ticketId, (int)$ticket['student_id']);

            try {
                $callSlipHistoryStmt = $pdo->prepare(
                    "SELECT cs.id, cs.issued_at, cs.status, cs.issued_by_role, cs.issued_by_user_id,
                            COALESCE(
                                NULLIF(TRIM(CONCAT_WS(' ', dp.first_name, dp.last_name)), ''),
                                NULLIF(ap.name, ''),
                                NULLIF(u.username, ''),
                                cs.issued_by_role
                            ) AS issuer_name
                     FROM call_slips cs
                     LEFT JOIN users u ON u.id = cs.issued_by_user_id
                     LEFT JOIN dean_profiles dp ON dp.user_id = cs.issued_by_user_id
                     LEFT JOIN admin_profiles ap ON ap.user_id = cs.issued_by_user_id
                     WHERE cs.ticket_type = 'complaint' AND cs.ticket_id = :ticket_id
                     ORDER BY cs.issued_at ASC, cs.id ASC"
                );
                $callSlipHistoryStmt->execute([':ticket_id' => $ticketId]);
                $callSlipHistory = $callSlipHistoryStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                $callSlipHistory = [];
            }
        }
    } catch (PDOException $e) {
        $flashMessage = 'Unable to load complaint details at this time.';
        $flashType = 'error';
    }
}

// Ensure $ticket is an array to avoid template notices when fields are accessed
if (!is_array($ticket)) {
    $ticket = [
        'subject' => '',
        'act_complained_of' => '',
        'category_name' => '',
        'created_at' => null,
        'desired_outcome' => '',
        'ticket_no' => '',
        'status' => 'new',
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage Complaint - VOICE</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
<style>
/* Small reset to match other student pages */
* { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
body { background: #f4f6fb; }

/* Main content pushed to the right to clear the fixed sidebar and centered */
.main {
    margin-left: 260px;
    margin-top: 61px;
    padding: 25px;
    min-height: calc(100vh - 61px);
}

/* Center cards and timeline within the main column */
.main .card, .main .ticket-header, .main .timeline {
    max-width: 980px;
    margin: 0 auto;
}
.complaint-document-layout { max-width: 1180px; margin: 0 auto 18px; display: grid; grid-template-columns: minmax(0, 2fr) minmax(280px, 1fr); gap: 18px; align-items: start; }
.complaint-document, .call-slip-history-card { max-width: none !important; margin: 0 !important; }
.status-remarks-card, .response-timeline-card {
    max-width: calc((min(1180px, 100%) - 18px) * 2 / 3) !important;
    width: calc((min(1180px, 100%) - 18px) * 2 / 3);
    margin-left: max(0px, calc((100% - 1180px) / 2)) !important;
    margin-right: auto !important;
}
.complaint-document { padding: 0; overflow: hidden; }
.document-heading { padding: 16px 22px; border-bottom: 1px solid #e5e7eb; background: linear-gradient(180deg, #ffffff 0%, #fafafa 100%); display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.document-heading-left { min-width: 0; display: flex; align-items: center; gap: 10px; }
.document-back-btn { display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 8px; border: 1px solid #d1d5db; background: #fff; color: #374151; text-decoration: none; flex-shrink: 0; transition: border-color .15s ease, background .15s ease, color .15s ease; }
.document-back-btn:hover { background: #f3f4f6; border-color: #a5b4fc; color: #111827; }
.document-back-btn i { font-size: 18px; }
.document-kicker { color: #6b7280; font-size: 11px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; margin-bottom: 5px; }
.document-submitted { color: #374151; font-size: 13px; }
.record-action-dropdown { position: relative; flex-shrink: 0; }
.record-action-btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 8px; border: 1px solid #d1d5db; background: #fff; color: #111827; font-weight: 700; font-size: 13px; font-family: 'Poppins', sans-serif; cursor: pointer; transition: border-color .15s ease, box-shadow .15s ease; }
.record-action-btn:hover { border-color: #a5b4fc; box-shadow: 0 4px 10px rgba(79,140,255,0.15); }
.record-action-btn i { font-size: 16px; transition: transform .15s ease; }
.record-action-dropdown.open .record-action-btn i.bx-chevron-down { transform: rotate(180deg); }
.record-action-menu { position: absolute; top: calc(100% + 6px); right: 0; min-width: 170px; background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; box-shadow: 0 12px 28px rgba(15,23,42,0.16); padding: 6px; display: none; flex-direction: column; gap: 2px; z-index: 50; }
.record-action-dropdown.open .record-action-menu { display: flex; }
.record-action-item { display: flex; align-items: center; gap: 8px; width: 100%; text-align: left; padding: 9px 10px; border: none; background: transparent; border-radius: 7px; font-size: 13px; font-weight: 600; color: #374151; cursor: pointer; font-family: 'Poppins', sans-serif; }
.record-action-item:hover { background: #f3f4f6; color: #111827; }
.record-action-item i { font-size: 16px; color: #6b7280; }
.update-status-modal { position: fixed; inset: 0; display: none; align-items: center; justify-content: center; z-index: 9998; padding: 20px; }
.update-status-modal.visible { display: flex; }
.update-status-backdrop { position: absolute; inset: 0; background: rgba(15, 23, 42, 0.48); }
.update-status-sheet { position: relative; z-index: 1; width: min(540px, 100%); max-height: 90vh; overflow-y: auto; border-radius: 14px; }
.update-status-sheet .status-remarks-card { max-width: none !important; width: 100% !important; margin: 0 !important; box-shadow: 0 30px 60px rgba(15, 23, 42, 0.25); }
.update-status-modal-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
.update-status-close { background: transparent; border: none; cursor: pointer; color: #6b7280; font-size: 20px; display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 8px; flex-shrink: 0; }
.update-status-close:hover { background: #f3f4f6; color: #111827; }
.document-section { padding: 20px 22px; border-bottom: 1px solid #e5e7eb; }
.document-section:last-child { border-bottom: 0; }
.document-section-title { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 14px; color: #111827; font-weight: 700; }
.document-section-title .section-label { font-size: 15px; }
.call-slip-history-card { padding: 18px; position: sticky; top: 80px; }
.call-slip-history-card > div:first-child { font-size: 15px; }
.call-slip-history-list { display: flex; flex-direction: column; gap: 10px; }
.call-slip-history-entry { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; padding: 12px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 10px; }
.call-slip-history-entry .issuer { color: #111827; font-size: 13px; font-weight: 600; }
.call-slip-history-entry .meta { color: #6b7280; font-size: 12px; line-height: 1.5; margin-top: 3px; }
@media (max-width: 900px) {
    .complaint-document-layout { grid-template-columns: 1fr; }
    .call-slip-history-card { position: static; }
    .status-remarks-card, .response-timeline-card {
        max-width: 100% !important;
        width: 100%;
        margin-left: auto !important;
    }
}

/* Card utility to match other student pages */
.card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 14px; box-shadow: 0 6px 18px rgba(15,23,42,0.04); }
.avatar-circle { width: 40px; height: 40px; border-radius: 999px; display: inline-flex; align-items: center; justify-content: center; overflow: hidden; background: #6b46c1; color: #fff; font-weight: 700; font-size: 14px; border: 1px solid #eef2ff; flex-shrink: 0; }
.avatar-circle img { width: 100%; height: 100%; object-fit: cover; }
.feedback-reply { background:#f8fafc; border-color:#eef2ff; }
.feedback-reply.current-user-reply { margin-left: auto; max-width: 80%; background: #ede9fe !important; border-color: #c4b5fd !important; color: #4c1d95 !important; }
.feedback-reply.current-user-reply .avatar-circle { background: #7c3aed; border-color: #c4b5fd; }

.feedback-badge { display: inline-flex; gap: 8px; align-items: center; padding: 6px 10px; border-radius: 999px; font-weight: 700; font-size: 13px; }
.feedback-badge.satisfied { background: #dcfce7; color: #065f46; }
.feedback-badge.neutral { background: #f3f4f6; color: #374151; }
.feedback-badge.not_satisfied { background: #fee2e2; color: #b91c1c; }

.feedback-edited-history-title { font-size: 14px; font-weight: 700; color: #111827; }
.feedback-edited-history-subtitle { font-size: 12px; color: #6b7280; }
.feedback-row { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin-bottom: 14px; }
.feedback-option {
    position: relative;
    border: 1px solid #d1d5db;
    border-radius: 16px;
    padding: 14px;
    background: #fff;
    display: flex;
    flex-direction: column;
    gap: 8px;
    cursor: pointer;
    transition: transform 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
    min-height: 108px;
}
.feedback-option:hover { transform: translateY(-1px); border-color: #a5b4fc; box-shadow: 0 12px 24px rgba(15, 23, 42, 0.08); }
.feedback-option.active { border-color: #4f8cff; background: linear-gradient(180deg, #f8fbff 0%, #eef4ff 100%); box-shadow: 0 12px 26px rgba(79, 140, 255, 0.16); }
.feedback-option input { position: absolute; opacity: 0; pointer-events: none; }
.feedback-option-head { display: flex; align-items: center; gap: 10px; }
.feedback-option-icon {
    width: 40px;
    height: 40px;
    border-radius: 12px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex: 0 0 auto;
}
.feedback-option-label { font-size: 15px; font-weight: 700; color: #111827; }
.feedback-option-desc { font-size: 12px; line-height: 1.45; color: #6b7280; }
.feedback-option.active .feedback-option-label { color: #1d4ed8; }
.feedback-option[data-option="satisfied"] .feedback-option-icon { background: #dcfce7; color: #059669; }
.feedback-option[data-option="neutral"] .feedback-option-icon { background: #f3f4f6; color: #4b5563; }
.feedback-option[data-option="not_satisfied"] .feedback-option-icon { background: #fee2e2; color: #b91c1c; }
.feedback-option.active[data-option="satisfied"] .feedback-option-icon { background: #bbf7d0; }
.feedback-option.active[data-option="neutral"] .feedback-option-icon { background: #e5e7eb; }
.feedback-option.active[data-option="not_satisfied"] .feedback-option-icon { background: #fecaca; }
.feedback-history { display: grid; gap: 12px; padding: 16px; }
.feedback-history-item {
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    padding: 14px 16px;
    background: #fff;
}
.feedback-history-head { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; margin-bottom: 8px; }
.feedback-history-label { display: flex; align-items: center; gap: 10px; font-weight: 700; color: #111827; }
.feedback-history-time { font-size: 12px; color: #6b7280; white-space: nowrap; }
.feedback-history-comment { font-size: 13px; color: #374151; line-height: 1.55; white-space: pre-wrap; }
.feedback-history-empty { font-size: 13px; color: #6b7280; background: #f9fafb; border: 1px dashed #d1d5db; border-radius: 12px; padding: 12px 14px; }
/* New rating-style buttons */
.rating-row { display:flex; gap:12px; margin-bottom:12px; }
.rating-button { flex:1; padding:12px 16px; border-radius:10px; border:1px solid #d1d5db; background:#fff; cursor:pointer; font-weight:700; display:inline-flex; align-items:center; justify-content:center; gap:8px; }
.rating-button.satisfied { background: linear-gradient(180deg,#ecfdf5 0%,#bbf7d0 100%); color:#065f46; border-color:#bbf7d0; }
.rating-button.not_satisfied { background: linear-gradient(180deg,#fff1f2 0%,#fecaca 100%); color:#7f1d1d; border-color:#fecaca; }

/* Ensure rating toggles override global .btn color so text is visible on white background */
.rating-toggle { color: #111827; background: #fff; border-radius: 10px; padding: 12px 14px; border: 1px solid #d1d5db; display: inline-flex; align-items:center; justify-content:center; gap:8px; font-weight:700; }
.rating-toggle.satisfied { background: linear-gradient(180deg,#ecfdf5 0%,#bbf7d0 100%); color:#065f46; border-color:#bbf7d0; }
.rating-toggle.not_satisfied { background: linear-gradient(180deg,#fff1f2 0%,#fecaca 100%); color:#7f1d1d; border-color:#fecaca; }
    /* Timeline & message bubbles */
    .timeline-shell { display:flex; flex-direction:column; gap:18px; }
    .ticket-section { display:flex; flex-direction:column; gap:12px; }
    .section-title { font-size:14px; font-weight:700; color:#111827; letter-spacing:0.01em; }
    .timeline-list { display:flex; flex-direction:column; gap:14px; }
    .timeline-entry { display:flex; gap:12px; align-items:flex-start; }
    .timeline-avatar { width:44px; height:44px; border-radius:999px; display:inline-flex; align-items:center; justify-content:center; overflow:hidden; background:#6b46c1; color:#fff; font-weight:700; font-size:14px; border:1px solid #eef2ff; flex-shrink:0; }
    .timeline-avatar img { width:100%; height:100%; object-fit:cover; }
    .timeline-body { flex:1; min-width:0; display:flex; flex-direction:column; gap:6px; }
    .timeline-heading { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
    .timeline-name { font-weight:700; color:#111827; font-size:14px; }
    .timeline-role { font-size:12px; color:#6b7280; }
    .timeline-time { font-size:12px; color:#6b7280; }
    .timeline-card { display:inline-block; width:fit-content; max-width:min(78%, 720px); padding:16px 18px; border-radius:14px; border:1px solid #e5e7eb; background:#fff; box-shadow:0 6px 18px rgba(15,23,42,0.04); }
    .timeline-card.current-user { background:#f3f0ff; border-color:#c4b5fd; }
    .timeline-card .timeline-text { color:#111827; font-size:14px; line-height:1.6; white-space:pre-wrap; word-break:break-word; text-align:left; }
    <?php echo response_timeline_styles(); ?>
    .pill { display:inline-flex; align-items:center; gap:8px; padding:6px 10px; border-radius:999px; font-weight:700; font-size:13px; }
    .pill.very_satisfied { background:#fef3c7; color:#92400e; }
    .pill.satisfied { background:#dcfce7; color:#065f46; }
    .pill.neutral { background:#f3f4f6; color:#374151; }
    .pill.not_satisfied { background:#ffedd5; color:#9a3412; }
    .pill.very_unsatisfied { background:#fee2e2; color:#b91c1c; }
    .pill.small { padding:4px 8px; font-size:11px; }
    .feedback-panel { border:1px solid #e5e7eb; border-radius:12px; padding:10px; background:#f9fafb; }
    .feedback-summary-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:10px 12px; display:flex; flex-direction:column; gap:8px; box-shadow:0 2px 8px rgba(15,23,42,0.04); align-items:stretch; }
    .feedback-summary-top { display:flex; align-items:center; gap:10px; width:100%; }
    .feedback-avatar { width:40px; height:40px; border-radius:999px; display:inline-flex; align-items:center; justify-content:center; overflow:hidden; background:#6b46c1; color:#fff; font-weight:700; font-size:14px; border:1px solid #eef2ff; flex-shrink:0; }
    .feedback-avatar img { width:100%; height:100%; object-fit:cover; }
    .feedback-summary-info { flex:1; min-width:0; }
    .feedback-head { display:flex; justify-content:space-between; align-items:center; gap:8px; flex-wrap:wrap; }
    .feedback-title { font-weight:700; color:#111827; font-size:13px; line-height:1.3; }
    .feedback-subtext { font-size:11.5px; color:#6b7280; line-height:1.3; }
    .feedback-comment-bubble { display:inline-block; max-width:100%; background:#f8fafc; border:1px solid #eef2ff; border-radius:10px; padding:7px 10px; font-size:12px; color:#374151; line-height:1.5; white-space:pre-wrap; word-break:break-word; margin-left:50px; }
    .empty-card { background:#f9fafb; border:1px dashed #d1d5db; border-radius:12px; padding:14px; color:#6b7280; font-size:13px; }
    .reply-box-card { border:1px solid #e5e7eb; border-radius:14px; padding:16px; background:#fff; box-shadow:0 4px 14px rgba(15,23,42,0.06); }
    .reply-box-card textarea { width:100%; min-height:120px; border-radius:12px; padding:14px; border:1px solid #d1d5db; resize:vertical; background:#fff; font-size:14px; line-height:1.5; }
    .reply-box-card label { display:block; font-size:13px; font-weight:700; color:#374151; margin-bottom:8px; }
    .reply-actions { display:flex; align-items:center; justify-content:flex-end; gap:10px; flex-wrap:wrap; margin-top:12px; }
    .reply-status { flex:1 1 auto; font-size:13px; color:#6b7280; min-height:20px; }
    .status-remarks-card { display:flex; flex-direction:column; gap:20px; }
    .status-remarks-card form { display:flex; flex-direction:column; gap:20px; }
    .status-remarks-card .form-group { margin-bottom:0; }
    .status-remarks-card .form-group label { display:block; font-size:14px; font-weight:700; color:#111827; margin-bottom:10px; }
    .status-remarks-card .form-control { width:100%; max-width:100%; min-width:0; padding:12px 14px; border:1px solid #d1d5db; border-radius:12px; font-size:14px; font-family:'Poppins', sans-serif; color:#111827; background:#ffffff; outline:none; transition:border-color 0.2s ease, box-shadow 0.2s ease; }
    .status-remarks-card .form-control:focus { border-color:#4F8CFF; box-shadow: inset 0 1px 2px rgba(15, 23, 42, 0.08); }
    .status-remarks-card select.form-control { appearance: none; -webkit-appearance: none; -moz-appearance: none; background-image: linear-gradient(45deg, transparent 50%, #6b7280 50%), linear-gradient(135deg, #6b7280 50%, transparent 50%); background-position: calc(100% - 18px) 18px, calc(100% - 13px) 18px; background-size: 6px 6px, 6px 6px; background-repeat: no-repeat; }
    .status-remarks-card textarea.form-control { resize:vertical; min-height:150px; line-height:1.7; }
    .status-remarks-actions { display:flex; justify-content:flex-end; gap:12px; flex-wrap:wrap; margin-top:10px; }
.call-slip-modal { position: fixed; inset: 0; display: none; align-items: center; justify-content: center; z-index: 9999; }
.call-slip-modal.visible { display: flex; }
.call-slip-backdrop { position: absolute; inset: 0; background: rgba(15, 23, 42, 0.48); }
.call-slip-sheet { position: relative; z-index: 1; width: min(680px, 92vw); max-height: 92vh; overflow-y: auto; }
.call-slip-paper { background: linear-gradient(180deg, #ffffff 0%, #fffef7 100%); border: 1px solid #f3e7a9; border-radius: 12px; box-shadow: 0 30px 60px rgba(15, 23, 42, 0.25); padding: 18px 18px 12px; }
@page { size: portrait; margin: 12mm; }
@media print {
    .call-slip-modal { position: static; display: block; }
    .call-slip-backdrop, .call-slip-close-row { display: none; }
    .call-slip-sheet { width: 100%; max-height: none; overflow: visible; }
    .call-slip-paper { border: 0; border-radius: 0; box-shadow: none; }
}
.call-slip-header { display: flex; justify-content: space-between; align-items: center; gap: 20px; padding-bottom: 12px; border-bottom: 1px solid rgba(51, 65, 85, 0.15); }
.call-slip-company-block { display: flex; align-items: center; gap: 16px; flex: 1; }
.call-slip-bisu-mark { width: 72px; height: 72px; display: inline-flex; align-items: center; justify-content: center; background: #f5f1e7; border: 2px solid #d6c38b; border-radius: 50%; overflow: hidden; box-shadow: inset 0 0 0 2px rgba(17,24,39,0.05); }
.call-slip-bisu-mark img { width: 100%; height: 100%; object-fit: cover; display: block; }
.call-slip-company-copy { flex: 1; text-align: center; }
.call-slip-company-title { font-size: 14px; line-height: 1.3; color: #111827; }
.call-slip-company-title.strong { font-weight: 800; font-size: 19px; }
.call-slip-company-sub { font-size: 11px; color: #374151; }
.call-slip-quote { margin-top: 6px; font-size: 11px; font-style: italic; color: #374151; }
.call-slip-right-badge { display: flex; align-items: center; gap: 10px; }
.call-slip-right-badge img { width: 92px; height: 72px; object-fit: contain; display: block; }
.call-slip-brand-text { font-size: 11px; font-weight: 700; text-align: left; color: #111827; }
.call-slip-brand-sub { font-size: 10px; font-weight: 600; }
.call-slip-body { padding-top: 18px; }
.call-slip-form-title { text-align: center; font-size: 22px; letter-spacing: 0.08em; font-weight: 800; color: #111827; margin-bottom: 16px; }
.call-slip-meta-row { display: flex; gap: 20px; margin-bottom: 12px; }
.call-slip-field { display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 600; color: #111827; }
.call-slip-field.half { flex: 1; }
.call-slip-line { flex: 1; min-height: 20px; border-bottom: 1px solid rgba(17,24,39,0.5); }
.call-slip-line.short { width: 180px; }
.call-slip-input { flex: 1; border: none; border-bottom: 1px solid rgba(17,24,39,0.5); background: transparent; padding: 4px 0; font-size: 14px; font-family: 'Poppins', sans-serif; color: #111827; }
.call-slip-input:focus { outline: none; border-bottom-color: #4F8CFF; }
.call-slip-input.short { max-width: 180px; }
.call-slip-notes { margin: 18px 0 20px; font-size: 14px; line-height: 1.5; color: #111827; }
.call-slip-connection { margin: 18px 0; }
.call-slip-connection > span { display: block; font-size: 14px; font-weight: 700; line-height: 1.5; color: #111827; margin-bottom: 6px; }
.call-slip-textarea { width: 100%; min-height: 26px; border: none; border-bottom: 1px solid rgba(17,24,39,0.5); background: transparent; padding: 4px 0; font-size: 14px; font-family: 'Poppins', sans-serif; color: #111827; resize: vertical; }
.call-slip-textarea:focus { outline: none; border-bottom-color: #4F8CFF; }
.call-slip-closing-note { margin: 18px 0 4px; font-size: 14px; color: #111827; line-height: 1.5; }
.call-slip-footer-row { display: flex; justify-content: space-between; gap: 30px; margin-top: 14px; }
.call-slip-signature { display: flex; flex-direction: column; gap: 8px; width: 200px; font-size: 12px; color: #374151; text-align: center; }
.call-slip-signature input { width: 100%; border: none; border-bottom: 1px solid rgba(17,24,39,0.5); background: transparent; padding: 4px 0; font-size: 14px; font-family: 'Poppins', sans-serif; color: #111827; text-align: center; }
.call-slip-signature input:focus { outline: none; border-bottom-color: #4F8CFF; }
.call-slip-close-row { display: flex; justify-content: flex-end; margin-top: 12px; }
.call-slip-success-overlay { position: fixed; inset: 0; z-index: 10000; display: flex; align-items: center; justify-content: center; padding: 20px; background: rgba(15, 23, 42, 0.42); }
.call-slip-success-card { width: min(360px, 100%); padding: 24px 24px 20px; text-align: center; background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; box-shadow: 0 24px 60px rgba(15, 23, 42, 0.22); }
.call-slip-success-icon { width: 48px; height: 48px; margin: 0 auto 14px; display: flex; align-items: center; justify-content: center; border-radius: 999px; background: #dcfce7; color: #16a34a; font-size: 26px; }
.call-slip-success-message { color: #111827; font-size: 13px; line-height: 1.5; margin-bottom: 10px; }
.call-slip-success-time { color: #6b7280; font-size: 12px; margin-bottom: 18px; }
.call-slip-success-ok { min-width: 58px; padding: 9px 22px; border: 0; border-radius: 7px; background: #f7c948; color: #111827; box-shadow: 0 3px 10px rgba(247,201,72,0.35); font-size: 12px; font-weight: 700; cursor: pointer; }
.call-slip-success-ok:hover { background: #eab936; }
.rating-toggle i { margin-right:6px; }
.rating-textarea { width:100%; border:1px solid #d1d5db; border-radius:10px; padding:12px; resize:vertical; }
.feedback-inline-badge { display:inline-flex; gap:8px; align-items:center; padding:6px 10px; border-radius:999px; font-weight:700; font-size:13px; }
.feedback-inline-badge.not_satisfied { background:#fee2e2; color:#b91c1c; border:1px solid #fecaca; }
.btn { background: #4f8cff; color: #fff; border: none; border-radius: 10px; padding: 11px 16px; cursor: pointer; font-weight: 600; }
/* Ensure rating options show their intent even before user clicks */
.rating-toggle[data-value="satisfied"] { background: linear-gradient(180deg,#ecfdf5 0%,#bbf7d0 100%) !important; color:#065f46 !important; border-color:#bbf7d0 !important; }
.rating-toggle[data-value="not_satisfied"] { background: linear-gradient(180deg,#fff1f2 0%,#fecaca 100%) !important; color:#7f1d1d !important; border-color:#fecaca !important; }
/* Active selection shows purple to match theme */
.rating-toggle.active { background: linear-gradient(180deg,#6b46c1 0%,#7c3aed 100%) !important; color: #fff !important; border-color:#6b46c1 !important; }
.rating-toggle.active i { color: #fff !important; }

.feedback-edit-button { background: #eff6ff; color: #1d4ed8; border: 1px solid #c7d2fe; border-radius: 10px; padding: 10px 16px; cursor: pointer; font-weight: 700; margin-bottom: 16px; }
.feedback-edit-button:hover { background: #dbeafe; }
.textarea, .input { width: 100%; border: 1px solid #d1d5db; border-radius: 10px; padding: 12px 14px; background: #fff; }
.grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
@media (max-width: 1024px) { .main { margin-left: 0; } .grid { grid-template-columns: 1fr; } .feedback-row { grid-template-columns: 1fr; } }
.scroll-top-btn { position: fixed; right: 24px; bottom: 24px; width: 44px; height: 44px; border-radius: 999px; background: #6b46c1; color: #fff; border: none; display: none; align-items: center; justify-content: center; cursor: pointer; box-shadow: 0 10px 24px rgba(107, 70, 193, 0.35); z-index: 500; transition: background .15s ease, transform .15s ease, opacity .2s ease; opacity: 0; transform: translateY(8px); }
.scroll-top-btn.visible { display: flex; opacity: 1; transform: translateY(0); }
.scroll-top-btn:hover { background: #5b3aa8; }
.scroll-top-btn i { font-size: 22px; }
</style>
</head>
<body>
<?php include 'dean_topbar.php'; ?>
<?php include 'dean_sidebar.php'; ?>
<?php if ($showCallSlipConfirmation && $flashMessage !== ''): ?>
    <div id="callSlipSuccessOverlay" class="call-slip-success-overlay" role="dialog" aria-modal="true" aria-labelledby="callSlipSuccessMessage">
        <div class="call-slip-success-card">
            <div class="call-slip-success-icon"><i class='bx bx-check'></i></div>
            <div id="callSlipSuccessMessage" class="call-slip-success-message"><?php echo e($flashMessage); ?></div>
            <div class="call-slip-success-time">Submitted <?php echo e(!empty($ticket['created_at']) ? date('M d, Y h:i A', strtotime((string)$ticket['created_at'])) : date('M d, Y h:i A')); ?></div>
            <button type="button" class="call-slip-success-ok" onclick="closeCallSlipSuccess()">OK</button>
        </div>
    </div>
<?php endif; ?>
<div class="main">
    <?php if ($flashMessage !== '' && !$showCallSlipConfirmation): ?>
        <div class="notice <?php echo e($flashType); ?>"><?php echo e($flashMessage); ?></div>
    <?php endif; ?>

    <div class="complaint-document-layout">
    <div class="complaint-document card">
        <div class="document-heading">
            <div class="document-heading-left">
                <a href="dean_complaints.php" id="recordBackBtn" class="document-back-btn" aria-label="Back to Complaints" title="Back to Complaints">
                    <i class='bx bx-arrow-back'></i>
                </a>
                <div>
                    <div class="document-kicker">Official Complaint Record</div>
                    <div class="document-submitted">Submitted <?php echo e(!empty($ticket['created_at']) ? date('M d, Y h:i A', strtotime((string)$ticket['created_at'])) : ''); ?></div>
                </div>
            </div>
            <div class="record-action-dropdown" id="recordActionDropdown">
                <button type="button" class="record-action-btn" id="recordActionBtn" aria-haspopup="true" aria-expanded="false">
                    Action <i class='bx bx-chevron-down'></i>
                </button>
                <div class="record-action-menu" id="recordActionMenu" role="menu">
                    <button type="button" class="record-action-item" id="recordActionCallSlip" role="menuitem"><i class='bx bx-phone-call'></i> Call Slip</button>
                    <button type="button" class="record-action-item" id="recordActionUpdate" role="menuitem"><i class='bx bx-edit-alt'></i> Update</button>
                </div>
            </div>
        </div>
    <script>
        // The status form is rendered further down the page, so this has to
        // wait for the DOM - inline it was bailing out before binding.
        document.addEventListener('DOMContentLoaded', function(){
            var status = document.getElementById('statusSelect');
            var remark = document.getElementById('remarkTextarea');
            if (!status || !remark) return;
            var defaultDismissalRemark = <?php echo json_encode(default_dismissal_remark(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

            function toggleRequired(){
                // Closing a complaint - resolved or dismissed - needs a reason.
                remark.required = (status.value === 'resolved' || status.value === 'dismissed');

                if (status.value === 'dismissed') {
                    // Offer the standard wording as a starting point, but never
                    // overwrite remarks that are already written.
                    if (remark.value.trim() === '') {
                        remark.value = defaultDismissalRemark;
                    }
                } else if (remark.value.trim() === defaultDismissalRemark) {
                    // Switching away from Dismissed: drop the dismissal wording
                    // if it was left untouched, so it does not end up attached
                    // to a different outcome.
                    remark.value = '';
                }
            }
            status.addEventListener('change', toggleRequired);
            // initialize
            toggleRequired();
        });
    </script>

    <!-- Complaint Details -->
    <div class="document-section">
        <div class="document-section-title">
            <div class="section-label">Complainant & Incident Details</div>
        </div>
        <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;">
            <div><div style="font-size:12px;color:#6b7280;">Complainant name</div><div style="margin-top:6px;font-weight:600;"><?php echo e((string)($ticket['complainant_name'] ?? '')); ?></div></div>
            <div><div style="font-size:12px;color:#6b7280;">Contact details</div><div style="margin-top:6px;"><?php echo e((string)($ticket['complainant_contact_details'] ?? '')); ?></div></div>
            <div><div style="font-size:12px;color:#6b7280;">Date / Time</div><div style="margin-top:6px;"><?php echo e((string)($ticket['date_of_incident'] ?? '')); ?> <?php echo e((string)($ticket['time_of_incident'] ?? '')); ?></div></div>
            <div><div style="font-size:12px;color:#6b7280;">Place of Incident</div><div style="margin-top:6px;"><?php echo e((string)($ticket['place_of_incident'] ?? '')); ?></div></div>
            <div class="full" style="grid-column:1 / -1;"><div style="font-size:12px;color:#6b7280;">Person complained of</div><div style="margin-top:6px;"><?php echo e((string)($ticket['person_complained_of'] ?? '')); ?></div></div>
            <div class="full" style="grid-column:1 / -1;"><div style="font-size:12px;color:#6b7280;">Act complained of</div><div style="margin-top:6px;white-space:pre-wrap;"><?php echo nl2br(e((string)($ticket['act_complained_of'] ?? ''))); ?></div></div>
        </div>

        <?php if (!empty($ticket['attachments'])): ?>
            <?php $att = (string)$ticket['attachments']; $attPath = '../' . ltrim($att, '/'); $ext = strtolower(pathinfo($att, PATHINFO_EXTENSION)); $isImage = in_array($ext, ['jpg','jpeg','png','gif'], true); ?>
            <div style="margin-top:12px;">
                <div style="font-size:12px;color:#6b7280;margin-bottom:6px;">Proof / Attachment</div>
                <?php if ($isImage): ?>
                    <a href="<?php echo e($attPath); ?>" target="_blank"><img src="<?php echo e($attPath); ?>" alt="attachment" style="max-width:360px;border-radius:8px;border:1px solid #eef2ff;"></a>
                <?php else: ?>
                    <a href="<?php echo e($attPath); ?>" target="_blank" class="btn" style="display:inline-block;padding:8px 12px;border-radius:8px;">Download attachment</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($ticket['desired_outcome'])): ?>
        <div class="document-section">
            <div class="document-section-title"><div class="section-label">Desired Outcome</div></div>
            <div style="white-space:pre-wrap;"><?php echo nl2br(e((string)$ticket['desired_outcome'])); ?></div>
        </div>
    <?php endif; ?>
    </div>

    <div class="call-slip-history-card card">
        <div style="font-weight:700;color:#111827;margin-bottom:12px;">Call Slip History</div>
        <?php if (empty($callSlipHistory)): ?>
            <div style="color:#6b7280;font-size:13px;padding:10px 0;">No Call Slips have been sent for this complaint.</div>
        <?php else: ?>
            <div class="call-slip-history-list">
                <?php foreach ($callSlipHistory as $callSlip): ?>
                    <div class="call-slip-history-entry">
                        <div>
                            <div class="issuer"><?php echo e((string)($callSlip['issuer_name'] ?? $callSlip['issued_by_role'] ?? 'Unknown issuer')); ?></div>
                            <div class="meta">
                                <?php echo e(date('F j, Y', strtotime((string)$callSlip['issued_at']))); ?> at
                                <?php echo e(date('g:i A', strtotime((string)$callSlip['issued_at']))); ?>
                                &middot; <?php echo e(ucfirst((string)($callSlip['issued_by_role'] ?? 'issuer'))); ?>
                            </div>
                        </div>
                        <span style="display:inline-flex;align-items:center;padding:4px 9px;border-radius:999px;background:#dcfce7;color:#166534;font-size:11px;font-weight:700;">
                            <?php echo e(ucfirst((string)($callSlip['status'] ?? 'issued'))); ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    </div>

    <!-- Status Update & Remarks Form (opened from the Action dropdown) -->
    <div id="updateStatusModal" class="update-status-modal" aria-hidden="true" role="dialog" aria-modal="true">
        <div class="update-status-backdrop" onclick="closeUpdateStatusModal()"></div>
        <div class="update-status-sheet" onclick="event.stopPropagation();">
        <div class="card status-remarks-card">
        <div class="update-status-modal-head">
            <div style="font-weight:700;color:#111827;">Update Status & Official Remarks</div>
            <button type="button" class="update-status-close" onclick="closeUpdateStatusModal()" aria-label="Close"><i class='bx bx-x'></i></button>
        </div>

<form method="POST">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="csrf_token" value="<?php echo e((string)($_SESSION['csrf_token'] ?? '')); ?>">
                <?php if (!empty($deanRemark)): ?>
                    <input type="hidden" name="remark_id" value="<?php echo (int)$deanRemark['id']; ?>">
                <?php endif; ?>

                <div class="form-group">
                    <label for="statusSelect">Complaint Status</label>
                    <select id="statusSelect" name="status" class="form-control">
                        <option value="under_review" <?php echo ((string)$ticket['status'] === 'under_review') ? 'selected' : ''; ?>>Under Review</option>
                        <option value="resolved" <?php echo ((string)$ticket['status'] === 'resolved') ? 'selected' : ''; ?>>Resolved</option>
                        <option value="dismissed" <?php echo ((string)$ticket['status'] === 'dismissed') ? 'selected' : ''; ?>>Dismissed</option>
                        
                </select>
            </div>

            <?php if (!empty($deanRemark)): ?>
                <!-- Display existing remark -->
                <div id="remarkDisplay" style="margin-bottom:12px;">
                    <div style="font-weight:700;margin-bottom:6px;color:#111827;font-size:14px;">Official Remarks (Visible to Student)</div>
                    <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:12px;margin-bottom:12px;color:#374151;line-height:1.6;">
                        <?php
                        $previewText = trim((string)$deanRemark['message']);
                        if (mb_strlen($previewText) > 120) {
                            // Prefer ending at the first sentence terminator if one occurs early.
                            $sentenceEnd = preg_match('/[\.\!\?](\s|$)/u', mb_substr($previewText, 0, 120), $matches, PREG_OFFSET_CAPTURE);
                            if ($sentenceEnd && isset($matches[0][1]) && $matches[0][1] > 0) {
                                $previewText = mb_substr($previewText, 0, $matches[0][1] + 1);
                            } else {
                                $previewText = mb_substr($previewText, 0, 120);
                                $lastSpace = mb_strrpos($previewText, ' ');
                                if ($lastSpace !== false) {
                                    $previewText = mb_substr($previewText, 0, $lastSpace);
                                }
                            }
                            $previewText = rtrim($previewText) . '...';
                        }
                        echo nl2br(e($previewText));
                        ?>
                    </div>
                    <div style="font-size:12px;color:#6b7280;margin-bottom:12px;">Added: <?php echo e(date('M d, Y h:i A', strtotime((string)$deanRemark['created_at']))); ?></div>
                    <button type="button" onclick="toggleRemarkEdit()" class="btn btn-secondary" style="background:#6b46c1;color:#fff;border:none;">
                        <i class='bx bx-edit-alt' style="margin-right:4px;"></i> Edit Remarks
                    </button>
                </div>

                <!-- Edit form (hidden by default) -->
                <div id="remarkFormWrapper" style="display:none;margin-bottom:12px;">
                    <div class="form-group">
                        <label for="remarkTextarea">Edit Official Remarks (Visible to Student)</label>
                        <textarea id="remarkTextarea" name="remark" class="form-control" placeholder="Type your response or next steps here..." required><?php echo e((string)$deanRemark['message']); ?></textarea>
                    </div>
                    <button type="button" onclick="toggleRemarkEdit()" class="btn btn-secondary" style="margin-bottom:4px;">Cancel Editing</button>
                </div>
            <?php else: ?>
                <!-- New remark form -->
                <div class="form-group">
                    <label for="remarkTextarea">Official Remarks (Visible to Student)</label>
                    <textarea id="remarkTextarea" name="remark" class="form-control" placeholder="Type your response or next steps here..." required></textarea>
                </div>
            <?php endif; ?>

            <!-- Status changes (and remark edits, if any) are always saved from here -->
            <!-- so switching just the status dropdown never requires opening "Edit Remarks" first. -->
            <div class="status-remarks-actions">
                <button type="button" class="btn btn-secondary" onclick="closeUpdateStatusModal()">Cancel</button>
                <button type="submit" class="btn">Save Changes</button>
            </div>
        </form>
        </div>
        </div>
    </div>

    <div id="callSlipModal" class="call-slip-modal" aria-hidden="true" role="dialog" aria-modal="true">
        <div class="call-slip-backdrop" onclick="closeCallSlipModal()"></div>
        <div class="call-slip-sheet" onclick="event.stopPropagation();">
            <form method="POST" style="margin:0;">
                <div class="call-slip-paper">
                    <div class="call-slip-header">
                        <div class="call-slip-company-block">
                            <div class="call-slip-bisu-mark">
                                <img src="../assets/images/bisulogo.png" alt="BISU Balilihan Logo">
                            </div>
                            <div class="call-slip-company-copy">
                                <div class="call-slip-company-title">Republic of the Philippines</div>
                                <div class="call-slip-company-title strong">BOHOL ISLAND STATE UNIVERSITY</div>
                                <div class="call-slip-company-sub">Magsija, Bilaran, 6342, Bohol, Philippines</div>
                                <div class="call-slip-company-sub">Office of the College of Computing and Information Sciences</div>
                                <div class="call-slip-quote">Balance | Innovativeness | Stewardship | Uprightness</div>
                            </div>
                        </div>
                        <div class="call-slip-right-badge">
                            <img src="../assets/images/logo-Photoroom.png" alt="VOICE logo">
                            <div class="call-slip-brand-text">
                                <div>Management</div>
                                <div>System</div>
                                <div class="call-slip-brand-sub">ISO 9001:2015</div>
                            </div>
                        </div>
                    </div>
                    <div class="call-slip-body">
                        <div class="call-slip-form-title">CALL SLIP - <?php echo e($deanOwnCollegeCode !== '' ? $deanOwnCollegeCode . ' DEAN\'S OFFICE' : 'DEAN\'S OFFICE'); ?></div>
                        <div class="call-slip-meta-row">
                            <div class="call-slip-field half"><span>To:</span> <input type="text" name="student_name" class="call-slip-input" value="<?php echo e($studentDisplayName); ?>" placeholder="Student / Faculty / Staff Name" required></div>
                            <div class="call-slip-field half"><span>Date Issued:</span> <?php echo e(date('F j, Y')); ?></div>
                        </div>
                        <div class="call-slip-meta-row">
                            <div class="call-slip-field half"><span>Email:</span> <input class="call-slip-input" type="email" name="to_name" value="<?php echo e($studentDisplayEmail); ?>" placeholder="Student Gmail address" required></div>
                        </div>
                        <div class="call-slip-notes"><?php echo e($deanOfficeMessage); ?></div>
                        <div class="call-slip-meta-row">
                            <div class="call-slip-field half"><span>on Date:</span> <input class="call-slip-input" type="date" name="date_issued"></div>
                            <div class="call-slip-field half"><span>at Time:</span> <input class="call-slip-input" type="time" name="time_issued"></div>
                        </div>
                        <div class="call-slip-connection">
                            <span>This is in connection with:</span>
                            <textarea class="call-slip-textarea" name="reason_note" rows="1" maxlength="300" placeholder="General reason / instruction"></textarea>
                        </div>
                        <div class="call-slip-closing-note">Please review this Call Slip and report to the designated office at the scheduled date and time. Thank you.</div>
                        <div class="call-slip-footer-row" style="justify-content:flex-end;">
                            <div class="call-slip-signature"><input type="text" name="issued_by" value="<?php echo e($deanCallSlipProfile['signatory']); ?>" placeholder="Dean name"><span>DEAN</span></div>
                        </div>
                    </div>
                </div>
                <div class="call-slip-close-row" style="gap:12px;justify-content:flex-end;">
                    <input type="hidden" name="action" value="issue_call_slip">
                    <input type="hidden" name="csrf_token" value="<?php echo e((string)($_SESSION['csrf_token'] ?? '')); ?>">
                    <button type="button" class="btn btn-secondary" onclick="closeCallSlipModal()">Close</button>
                    <button type="submit" class="btn" style="background:#f7c948;color:#111827;border:none;box-shadow:0 3px 10px rgba(247,201,72,0.35);font-weight:700;">Send Call Slip</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function closeCallSlipSuccess() {
        const overlay = document.getElementById('callSlipSuccessOverlay');
        if (overlay) {
            overlay.remove();
        }
    }

    function toggleRemarkEdit() {
        const display = document.getElementById('remarkDisplay');
        const form = document.getElementById('remarkFormWrapper');
        if (display && form) {
            display.style.display = display.style.display === 'none' ? 'block' : 'none';
            form.style.display = form.style.display === 'none' ? 'block' : 'none';
        }
    }

    function openCallSlipModal() {
        const modal = document.getElementById('callSlipModal');
        if (modal) {
            modal.classList.add('visible');
            modal.setAttribute('aria-hidden', 'false');
        }
    }

    function closeCallSlipModal() {
        const modal = document.getElementById('callSlipModal');
        if (modal) {
            modal.classList.remove('visible');
            modal.setAttribute('aria-hidden', 'true');
        }
    }

    function openCallSlipModal() {
        const modal = document.getElementById('callSlipModal');
        if (modal) {
            modal.classList.add('visible');
            modal.setAttribute('aria-hidden', 'false');
        }
    }

    function closeCallSlipModal() {
        const modal = document.getElementById('callSlipModal');
        if (modal) {
            modal.classList.remove('visible');
            modal.setAttribute('aria-hidden', 'true');
        }
    }

    function openUpdateStatusModal() {
        const modal = document.getElementById('updateStatusModal');
        if (modal) {
            modal.classList.add('visible');
            modal.setAttribute('aria-hidden', 'false');
        }
    }

    function closeUpdateStatusModal() {
        const modal = document.getElementById('updateStatusModal');
        if (modal) {
            modal.classList.remove('visible');
            modal.setAttribute('aria-hidden', 'true');
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        // Header "Action" dropdown: Call Slip / Update
        const actionDropdown = document.getElementById('recordActionDropdown');
        const actionBtn = document.getElementById('recordActionBtn');
        const actionCallSlipItem = document.getElementById('recordActionCallSlip');
        const actionUpdateItem = document.getElementById('recordActionUpdate');

        function closeActionDropdown() {
            if (actionDropdown) {
                actionDropdown.classList.remove('open');
                if (actionBtn) actionBtn.setAttribute('aria-expanded', 'false');
            }
        }

        if (actionDropdown && actionBtn) {
            actionBtn.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                const isOpen = actionDropdown.classList.toggle('open');
                actionBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            });

            document.addEventListener('click', function (event) {
                if (!actionDropdown.contains(event.target)) {
                    closeActionDropdown();
                }
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    closeActionDropdown();
                }
            });
        }

        if (actionCallSlipItem) {
            actionCallSlipItem.addEventListener('click', function (event) {
                event.preventDefault();
                closeActionDropdown();
                openCallSlipModal();
            });
        }

        if (actionUpdateItem) {
            actionUpdateItem.addEventListener('click', function (event) {
                event.preventDefault();
                closeActionDropdown();
                openUpdateStatusModal();
            });
        }
    });

    function toggleFeedbackReplyForm(button) {
        const feedbackHistoryId = button.dataset.feedbackHistoryId;
        const formWrapper = document.getElementById('replyForm-' + feedbackHistoryId);
        if (formWrapper) {
            formWrapper.style.display = formWrapper.style.display === 'none' ? 'block' : 'none';
        }
    }

    async function submitDeanFeedbackReply(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const statusEl = form.querySelector('.reply-status');
        if (!statusEl) {
            return;
        }

        statusEl.textContent = '';
        const formData = new window.FormData(form);

        try {
            const response = await fetch('../process_feedback_reply.php', {
                method: 'POST',
                body: formData,
            });
            const result = await response.json().catch(() => null);
            if (response.ok && result && result.status === 'ok') {
                statusEl.innerHTML = '<span style="color:#166534;">Reply sent successfully.</span>';
                appendDeanReply(form, result.reply);
                form.reset();
                // Collapse the inline form after sending so the thread stays tidy.
                // Do NOT collapse the global reply form (id="feedbackReplyForm").
                const wrapper = form.closest('.reply-form-wrapper');
                if (wrapper && form.id !== 'feedbackReplyForm') {
                    wrapper.style.display = 'none';
                }
                // Reload so the reply is picked up from the database and the
                // whole thread (and anyone else's view of it) stays in sync,
                // same as the student side already does.
                setTimeout(() => window.location.reload(), 600);
            } else {
                statusEl.innerHTML = '<span style="color:#b91c1c;">Unable to send reply. Please try again.</span>';
            }
        } catch (error) {
            statusEl.innerHTML = '<span style="color:#b91c1c;">Unable to send reply. Please try again.</span>';
        }
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str == null ? '' : String(str);
        return div.innerHTML;
    }

    function appendDeanReply(form, reply) {
        if (!reply || typeof reply !== 'object' || !window.VoiceTimeline) {
            return;
        }

        const roleRaw = String(reply.replier_role || '').toLowerCase();
        const roleLabel = roleRaw === 'dean' ? 'College Dean' : (roleRaw === 'admin' ? 'Administrator' : 'Student');
        const box = document.getElementById('globalReplyBox');

        window.VoiceTimeline.appendReply({
            name: reply.replier_name || 'You',
            roleLabel: roleLabel,
            isCurrentUser: true,
            photo: null,
            timeText: reply.created_at || '',
            messageHtml: escapeHtml(reply.message || '').replace(/\n/g, '<br>'),
            replyTargetId: box ? box.id : null,
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        const replyButtons = document.querySelectorAll('.reply-toggle');
        replyButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                toggleFeedbackReplyForm(button);
            });
        });

        const replyForms = document.querySelectorAll('.dean-feedback-reply-form');
        replyForms.forEach(function (form) {
            form.addEventListener('submit', submitDeanFeedbackReply);
        });
    });
    </script>

    <!-- Combined Response Timeline -->
    <?php
        $timelineReplies = [];
        $officialRemark = null;
        foreach ($replies as $replyItem) {
            $replyRoleRaw = strtolower((string)($replyItem['sender_role'] ?? ''));
            $entry = [
                'kind' => 'thread',
                'created_at' => (string)($replyItem['created_at'] ?? ''),
                'message' => (string)($replyItem['message'] ?? ''),
                'sender_id' => isset($replyItem['sender_id']) ? (int)$replyItem['sender_id'] : 0,
                'sender_role' => $replyRoleRaw,
            ];
            if (($replyRoleRaw === 'dean' || $replyRoleRaw === 'admin') && $officialRemark === null) {
                $officialRemark = $entry;
            } else {
                $timelineReplies[] = $entry;
            }
        }

        foreach ($feedbackReplies as $feedbackReply) {
            $timelineReplies[] = [
                'kind' => 'feedback',
                'created_at' => (string)($feedbackReply['created_at'] ?? ''),
                'message' => (string)($feedbackReply['message'] ?? ''),
                'sender_id' => isset($feedbackReply['replier_id']) ? (int)$feedbackReply['replier_id'] : 0,
                'sender_role' => strtolower((string)($feedbackReply['replier_role'] ?? '')),
            ];
        }

        usort($timelineReplies, function ($a, $b) {
            $ta = strtotime((string)($a['created_at'] ?? ''));
            $tb = strtotime((string)($b['created_at'] ?? ''));
            return ($ta === $tb) ? 0 : (($ta < $tb) ? -1 : 1);
        });

        $currentStatus = strtolower((string)($ticket['status'] ?? ''));
        $threadResolved = in_array($currentStatus, ['resolved'], true);
        $canReply = !$threadResolved;
        $globalFeedbackId = !empty($feedback['id']) ? (int)$feedback['id'] : '';
    ?>
    <?php if ($officialRemark || !empty($feedback) || !empty($timelineReplies)): ?>
    <div class="card response-timeline-card" style="padding:0;margin-bottom:18px;">
        <div style="display:flex;align-items:center;padding:12px 16px;border-bottom:1px solid #eef2ff;">
            <div style="font-weight:700;flex:1;">Response Timeline</div>
        </div>
        <div style="padding:16px;">
                <div class="timeline-shell">
                    <div class="ticket-section">
                        <div class="section-title">Responses</div>
                        <?php if ($officialRemark): ?>
                            <?php
                                $officialRole = strtolower((string)($officialRemark['sender_role'] ?? ''));
                                $officialSenderId = isset($officialRemark['sender_id']) ? (int)$officialRemark['sender_id'] : 0;
                                $officialPerson = $officialSenderId > 0 ? get_person_display($pdo, $officialRole, $officialSenderId) : ['name' => ucfirst($officialRole), 'photo' => null];
                                $officialName = $officialPerson['name'] ?? (ucfirst($officialRole) ?: 'Staff');
                                $officialPhoto = !empty($officialPerson['photo']) ? ('../' . ltrim($officialPerson['photo'], '/')) : null;
                                $officialRoleLabel = $officialRole === 'dean' ? 'College Dean' : ($officialRole === 'admin' ? 'Administrator' : 'Student');
                                $officialCurrentUser = ($officialRole === 'dean');
                                echo response_timeline_entry([
                                    'name' => $officialName,
                                    'role_label' => $officialRoleLabel,
                                    'is_current_user' => $officialCurrentUser,
                                    'photo' => $officialPhoto,
                                    'time_text' => date('M d, Y h:i A', strtotime((string)$officialRemark['created_at'])),
                                    'message_html' => nl2br(e((string)$officialRemark['message'])),
                                    'reply_target_id' => $canReply ? 'globalReplyBox' : null,
                                ]);
                            ?>
                        <?php else: ?>
                            <div class="empty-card">No official remark has been posted yet.</div>
                        <?php endif; ?>

                        <?php if (!empty($timelineReplies)): ?>
                            <div class="timeline-replies">
                                <?php foreach ($timelineReplies as $replyItem): ?>
                                    <?php
                                        $replyRoleRaw = strtolower((string)($replyItem['sender_role'] ?? ''));
                                        $replySenderId = isset($replyItem['sender_id']) ? (int)$replyItem['sender_id'] : 0;
                                        $replyPerson = $replySenderId > 0 ? get_person_display($pdo, $replyRoleRaw, $replySenderId) : ['name' => ucfirst($replyRoleRaw), 'photo' => null];
                                        $replyName = $replyPerson['name'] ?? ucfirst($replyRoleRaw);
                                        $replyPhoto = !empty($replyPerson['photo']) ? ('../' . ltrim($replyPerson['photo'], '/')) : null;
                                        $replyRoleLabel = $replyRoleRaw === 'dean' ? 'College Dean' : ($replyRoleRaw === 'admin' ? 'Administrator' : 'Student');
                                        $replyIsCurrentUser = ($replyRoleRaw === 'dean');
                                        echo response_timeline_entry([
                                            'name' => $replyName,
                                            'role_label' => $replyRoleLabel,
                                            'is_current_user' => $replyIsCurrentUser,
                                            'photo' => $replyPhoto,
                                            'time_text' => date('M d, Y h:i A', strtotime((string)($replyItem['created_at'] ?? ''))),
                                            'message_html' => nl2br(e((string)($replyItem['message'] ?? ''))),
                                            'is_reply' => true,
                                            'reply_target_id' => $canReply ? 'globalReplyBox' : null,
                                        ]);
                                    ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="ticket-section">
                        <div class="section-title">Student Feedback</div>
                        <div class="feedback-panel">
                            <?php if (!empty($feedback)): ?>
                                <?php
                                    $m = feedback_option_meta((string)$feedback['satisfaction']);
                                    $studentInfo = isset($ticket['student_id']) ? get_person_display($pdo, 'student', (int)$ticket['student_id']) : ['name' => 'Student', 'photo' => null];
                                    $stuName = $studentInfo['name'] ?? 'Student';
                                    $stuPhoto = !empty($studentInfo['photo']) ? ('../' . ltrim($studentInfo['photo'], '/')) : null;
                                    $stuInitial = strtoupper(substr(trim($stuName), 0, 1)) ?: 'S';
                                    $stuComment = trim((string)($feedback['comment'] ?? ''));
                                ?>
                                <div class="feedback-summary-card">
                                    <div class="feedback-summary-top">
                                        <div class="feedback-avatar">
                                            <?php if ($stuPhoto): ?>
                                                <img src="<?php echo e($stuPhoto); ?>" alt="<?php echo e($stuName); ?>">
                                            <?php else: ?>
                                                <?php echo e($stuInitial); ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="feedback-summary-info">
                                            <div class="feedback-head">
                                                <div>
                                                    <div class="feedback-title"><?php echo e($stuName); ?></div>
                                                    <div class="feedback-subtext">Submitted feedback for this response</div>
                                                </div>
                                                <span class="pill small <?php echo e((string)$feedback['satisfaction']); ?>">
                                                    <i class='bx <?php echo e($m['icon']); ?>'></i>
                                                    <?php echo e($m['label']); ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    <?php if ($stuComment !== ''): ?>
                                    <div class="feedback-comment-bubble"><?php echo nl2br(e($stuComment)); ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-card">No rating has been submitted yet.</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($canReply): ?>
                        <div class="ticket-section">
                            <div id="globalReplyBox" class="reply-box-card timeline-reply-box">
                                <form id="feedbackReplyForm" class="dean-feedback-reply-form" method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo e((string)($_SESSION['csrf_token'] ?? '')); ?>">
                                    <input type="hidden" name="ticket_type" value="complaint">
                                    <input type="hidden" name="ticket_id" value="<?php echo (int)$ticketId; ?>">
                                    <input type="hidden" name="student_id" value="<?php echo (int)$ticket['student_id']; ?>">
                                    <input type="hidden" name="feedback_history_id" value="<?php echo $globalFeedbackId; ?>">
                                    <label for="globalReplyTextarea">Reply</label>
                                    <textarea id="globalReplyTextarea" name="message" rows="4" placeholder="Reply to the student about their feedback..." required></textarea>
                                    <div class="reply-actions">
                                        <span id="globalReplyStatus" class="reply-status"></span>
                                        <button type="submit" class="btn">Send Reply</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php else: ?>
                        <div style="margin-top:12px;">
                            <div class="notice info">This complaint has been resolved. No further replies can be sent.</div>
                        </div>
                    <?php endif; ?>
                </div>
        </div>
    </div>

    <?php endif; ?>
    <div style="height:18px;"></div>
</div>

<?php echo response_timeline_script(); ?>
<button type="button" id="scrollTopBtn" class="scroll-top-btn" aria-label="Scroll to top" title="Back to top">
    <i class='bx bx-up-arrow-alt'></i>
</button>
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Back arrow: prefer returning to the exact complaints-list page the
    // dean came from (same filters/search/pagination/scroll position, via
    // normal browser back-navigation) instead of a fresh navigation that
    // would reset it back to the top.
    var backBtn = document.getElementById('recordBackBtn');
    if (backBtn) {
        backBtn.addEventListener('click', function (event) {
            var cameFromList = document.referrer && document.referrer.indexOf(window.location.origin) === 0;
            if (cameFromList && window.history.length > 1) {
                event.preventDefault();
                window.history.back();
            }
            // Otherwise let the plain href navigate to dean_complaints.php
            // (e.g. this page was opened directly or from elsewhere).
        });
    }

    var scrollTopBtn = document.getElementById('scrollTopBtn');
    if (!scrollTopBtn) return;
    var toggleScrollTopBtn = function () {
        if (window.scrollY > 300) {
            scrollTopBtn.classList.add('visible');
        } else {
            scrollTopBtn.classList.remove('visible');
        }
    };
    window.addEventListener('scroll', toggleScrollTopBtn, { passive: true });
    toggleScrollTopBtn();
    scrollTopBtn.addEventListener('click', function () {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
});
</script>
</body>
</html>