<?php

session_start();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../ticket_flow.php';
require_once __DIR__ . '/../suggestion_flow.php';
require_once __DIR__ . '/../school_year_helpers.php';
require_once __DIR__ . '/../response_timeline_ui.php';
// Prevent PHP warnings from being printed to the page (they break layout). Logging still occurs.
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
// Generate CSRF token for form submissions if not already present
if (!isset($_SESSION['csrf_token'])) {
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
            // Try student_profiles by profile id
            $stmt = $pdo->prepare('SELECT sp.first_name, sp.last_name, u.profile_pic FROM student_profiles sp LEFT JOIN users u ON u.id = sp.user_id WHERE sp.id = :id LIMIT 1');
            $stmt->execute([':id' => (int)$id]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($r && (!empty($r['first_name']) || !empty($r['last_name']) || !empty($r['profile_pic']))) {
                $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: 'Student';
                $photo = $r['profile_pic'] ?? null;
                return ['name' => $name, 'photo' => $photo];
            }

            // Maybe the stored id is actually users.id — try users table
            $stmt = $pdo->prepare('SELECT u.first_name, u.last_name, u.profile_pic FROM users u WHERE u.id = :id LIMIT 1');
            $stmt->execute([':id' => (int)$id]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($r && (!empty($r['first_name']) || !empty($r['last_name']) || !empty($r['profile_pic']))) {
                $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: 'Student';
                $photo = $r['profile_pic'] ?? null;
                return ['name' => $name, 'photo' => $photo];
            }

            // As a last attempt, try student_profiles by user_id (id may be user id)
            $stmt = $pdo->prepare('SELECT sp.first_name, sp.last_name, u.profile_pic FROM student_profiles sp LEFT JOIN users u ON u.id = sp.user_id WHERE sp.user_id = :id LIMIT 1');
            $stmt->execute([':id' => (int)$id]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($r && (!empty($r['first_name']) || !empty($r['last_name']) || !empty($r['profile_pic']))) {
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
            // Try admin_profiles by profile id
            $stmt = $pdo->prepare('SELECT ap.name, u.profile_pic FROM admin_profiles ap LEFT JOIN users u ON u.id = ap.user_id WHERE ap.id = :id LIMIT 1');
            $stmt->execute([':id' => (int)$id]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($r && (!empty($r['name']) || !empty($r['profile_pic']))) {
                $name = $r['name'] ?? 'Admin';
                $photo = $r['profile_pic'] ?? null;
                return ['name' => $name, 'photo' => $photo];
            }

            // Try admin_profiles by user_id
            $stmt = $pdo->prepare('SELECT ap.name, u.profile_pic FROM admin_profiles ap LEFT JOIN users u ON u.id = ap.user_id WHERE ap.user_id = :id LIMIT 1');
            $stmt->execute([':id' => (int)$id]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($r && (!empty($r['name']) || !empty($r['profile_pic']))) {
                $name = $r['name'] ?? 'Admin';
                $photo = $r['profile_pic'] ?? null;
                return ['name' => $name, 'photo' => $photo];
            }

            // Fall back to users table
            $stmt = $pdo->prepare('SELECT u.first_name, u.last_name, u.profile_pic FROM users u WHERE u.id = :id LIMIT 1');
            $stmt->execute([':id' => (int)$id]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($r && (!empty($r['first_name']) || !empty($r['last_name']) || !empty($r['profile_pic']))) {
                $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: 'Admin';
                $photo = $r['profile_pic'] ?? null;
                return ['name' => $name, 'photo' => $photo];
            }
        }
    } catch (Exception $e) {
        // ignore and fallback
    }

    return ['name' => ucfirst($role), 'photo' => null];
}
// Ensure we have the current student's profile ID available
$studentProfileId = 0;
if (!isset($_SESSION['user_id']) || (string)($_SESSION['role'] ?? '') !== 'student') {
    $studentProfileId = 0;
} else {
    try {
        $studentStmt = $pdo->prepare('SELECT id FROM student_profiles WHERE user_id = :user_id LIMIT 1');
        $studentStmt->execute([':user_id' => (int)$_SESSION['user_id']]);
        $student = $studentStmt->fetch();
        if ($student) {
            $studentProfileId = (int)$student['id'];
        } else {
            $studentProfileId = 0;
        }
    } catch (PDOException $e) {
        $studentProfileId = 0;
    }
}
if ($studentProfileId <= 0) {
    http_response_code(403);
    echo 'Student profile not found.';
    exit;
}

// Initialize template variables
$flashMessage = '';
$flashType = 'info';
$ticketType = (string)($_GET['type'] ?? '');
$ticketId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($ticketType !== 'complaint' && $ticketType !== 'suggestion') {
    $flashMessage = 'Ticket type is invalid.';
    $flashType = 'error';
}

if ($ticketId <= 0) {
    $flashMessage = 'Ticket identifier is missing.';
    $flashType = 'error';
}

$ticket = null;
$replies = [];
$feedback = null;
$feedbackReplies = [];
$feedbackHistory = [];
$feedbackSubmissionReason = null;
$threadState = ['status' => 'unknown', 'can_reply' => false, 'can_reopen' => false, 'is_closed' => true];
$ticketSchoolYear = sy_current($pdo);
$ticketIsPastSchoolYear = false;

$isSubmitter = false;
$ticketOwnerId = 0;
$callSlips = [];

if ($flashMessage === '') {
    try {
        $table = $ticketType === 'complaint' ? 'complaints' : 'suggestions';
        $stmt = $pdo->prepare("SELECT t.*, c.name AS category_name FROM {$table} t LEFT JOIN " . ($ticketType === 'complaint' ? 'complaint_categories' : 'suggestion_categories') . " c ON c.id = t.category_id WHERE t.id = :id LIMIT 1");
        $stmt->execute([':id' => $ticketId]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

        // Full ticket access (complaint narrative, complainant details, the
        // rest of the thread) stays restricted to whoever actually filed
        // it. A student a call slip names as the reported party does NOT
        // get in here - their "Call Slip issued" notification instead links
        // to call_slip_view.php, which shows only the call slip itself.
        $hasAccess = false;
        if ($ticket) {
            $isSubmitter = (int)($ticket['student_id'] ?? 0) === $studentProfileId;
            $hasAccess = $isSubmitter;
        }

        if (!$ticket || !$hasAccess) {
            $ticket = null;
            $flashMessage = 'Ticket not found or you do not have access to view it.';
            $flashType = 'error';
        } else {
            $ticket['ticket_no'] = '';
            // A ticket's school year is fixed at the time it was filed, so a
            // ticket from a past school year stays read-only wherever it's
            // opened from, regardless of the topbar's current SY selection.
            $ticketSchoolYear = (string)($ticket['school_year'] ?? '');
            $ticketSchoolYear = sy_is_valid_label($ticketSchoolYear) ? $ticketSchoolYear : sy_current($pdo);
            $ticketIsPastSchoolYear = $ticketSchoolYear !== sy_current($pdo);

            // Feedback and replies belong to whoever filed the ticket, not
            // necessarily whoever is viewing it (a reported student can now
            // view too - see the access check above), so they're always
            // looked up under the actual owner's id.
            $ticketOwnerId = (int)($ticket['student_id'] ?? 0);

            // POST handling for feedback submission - submitter only; a
            // reported student has read-only access to this ticket.
            if ($isSubmitter && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_feedback') {
                $postedTicketType = (string)($_POST['ticket_type'] ?? '');
                $postedTicketId = isset($_POST['ticket_id']) ? (int)$_POST['ticket_id'] : 0;
                $satisfaction = (string)($_POST['satisfaction'] ?? '');
                $comment = trim((string)($_POST['comment'] ?? ''));

                if ($ticketIsPastSchoolYear) {
                    $flashMessage = 'This ticket is from a past school year (' . $ticketSchoolYear . ') and is read-only.';
                    $flashType = 'error';
                } elseif ($postedTicketType !== $ticketType || $postedTicketId !== $ticketId) {
                    $flashMessage = 'Invalid submission.';
                    $flashType = 'error';
                } elseif ($satisfaction === '') {
                    $flashMessage = 'Please choose a rating before submitting.';
                    $flashType = 'error';
                } else {
                    try {
                        $reason = get_ticket_feedback_submission_reason($pdo, $ticketType, $ticketId, $ticketOwnerId);
                        if ($reason !== null) {
                            $flashMessage = $reason;
                            $flashType = 'error';
                        } else {
                            save_ticket_feedback($pdo, $ticketType, $ticketId, $ticketOwnerId, $satisfaction, $comment);

                            // Let the handling dean/admin/staff know a rating came in.
                            $raterInfo = get_person_display($pdo, 'student', $ticketOwnerId);
                            $raterName = $raterInfo['name'] ?? 'A student';
                            $notifType = $ticketType === 'complaint' ? 'complaint_update' : 'suggestion_update';
                            notify_ticket_handlers($pdo, $ticketType, $ticketId, $notifType, $raterName . ' submitted a rating for their ' . $ticketType . '.');

                            header('Location: ' . $_SERVER['REQUEST_URI']);
                            exit;
                        }
                    } catch (Throwable $e) {
                        $flashMessage = 'Unable to save feedback at this time.';
                        $flashType = 'error';
                    }
                }
            }

            // load thread state
            $threadState = get_ticket_status_state($pdo, $ticketType, $ticketId);

            // load replies
            $replyStmt = $pdo->prepare('SELECT id, sender_id, sender_role, message, created_at FROM ticket_replies WHERE ticket_type = :ticket_type AND ticket_id = :ticket_id ORDER BY created_at ASC, id ASC');
            $replyStmt->execute([':ticket_type' => $ticketType, ':ticket_id' => $ticketId]);
            $replies = $replyStmt->fetchAll(PDO::FETCH_ASSOC);

            // load feedback and history
            $feedback = get_ticket_feedback($pdo, $ticketType, $ticketId, $ticketOwnerId);
            $feedbackHistory = get_ticket_feedback_history($pdo, $ticketType, $ticketId, $ticketOwnerId);
            $feedbackReplies = get_ticket_feedback_replies($pdo, $ticketType, $ticketId, $ticketOwnerId);
            try {
                $feedbackSubmissionReason = get_ticket_feedback_submission_reason($pdo, $ticketType, $ticketId, $ticketOwnerId);
            } catch (Throwable $e) {
                $feedbackSubmissionReason = 'Unable to determine whether you can submit feedback at this time.';
            }

            // load call slip(s) issued for this ticket, most recent first -
            // this is what a "Call Slip issued" notification links here for.
            if ($ticketType === 'complaint') {
                try {
                    $callSlipStmt = $pdo->prepare(
                        "SELECT cs.id, cs.issued_at, cs.status, cs.issued_by_role, cs.report_date, cs.report_time, cs.office_message, cs.reason_note,
                                COALESCE(
                                    NULLIF(TRIM(cs.issued_by_name), ''),
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
                         ORDER BY cs.issued_at DESC, cs.id DESC"
                    );
                    $callSlipStmt->execute([':ticket_id' => $ticketId]);
                    $callSlips = $callSlipStmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (PDOException $e) {
                    $callSlips = [];
                }
            }
        }
    } catch (PDOException $e) {
        $flashMessage = 'Unable to load ticket details at this time.';
        $flashType = 'error';
    }
}

    // Ensure $ticket is an array to avoid template notices when fields are accessed
    $ticketFound = is_array($ticket);
    if (!$ticketFound) {
        $ticket = [
            'subject' => '',
            'description' => '',
            'category_name' => '',
            'created_at' => null,
            'desired_outcome' => '',
            'ticket_no' => '',
        ];
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Ticket Details - VOICE</title>
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

/* Card utility to match other student pages */
.card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 14px; box-shadow: 0 6px 18px rgba(15,23,42,0.04); }
.ticket-header-left { display: flex; align-items: center; gap: 10px; min-width: 0; }
.document-back-btn { display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 8px; border: 1px solid #d1d5db; background: #fff; color: #374151; text-decoration: none; flex-shrink: 0; transition: border-color .15s ease, background .15s ease, color .15s ease; }
.document-back-btn:hover { background: #f3f4f6; border-color: #a5b4fc; color: #111827; }
.document-back-btn i { font-size: 18px; }

/* Official record document card, matching the dean/admin ticket view */
.complaint-document-layout { max-width: 1180px; margin: 0 auto 18px; display: grid; grid-template-columns: minmax(0, 2fr) minmax(280px, 1fr); gap: 18px; align-items: start; }
.complaint-document { padding: 0; overflow: hidden; }
.call-slip-history-card { padding: 18px; position: sticky; top: 80px; }
.call-slip-history-card-title { color: #111827; font-size: 15px; font-weight: 700; margin-bottom: 12px; display: flex; align-items: center; gap: 6px; }
<?php if (!empty($callSlips)): ?>
/* The document card only sits in a two-column grid when there's a call slip
   sidebar to show. In that case it needs the plain .card 980px centering
   overridden so it can use the grid's full column width instead - and the
   Response Timeline below it (which is not part of that grid) is
   widened/positioned to still line up with the document column above it
   instead of defaulting back to the page's plain 980px centered width. */
.complaint-document, .call-slip-history-card { max-width: none !important; margin: 0 !important; }
.response-timeline-card {
    max-width: calc((min(1180px, 100%) - 18px) * 2 / 3) !important;
    width: calc((min(1180px, 100%) - 18px) * 2 / 3);
    margin-left: max(0px, calc((100% - 1180px) / 2)) !important;
    margin-right: auto !important;
}
<?php endif; ?>
@media (max-width: 900px) {
    .complaint-document-layout { grid-template-columns: 1fr; }
    .call-slip-history-card { position: static; }
    .response-timeline-card { max-width: 100% !important; width: 100%; margin-left: auto !important; }
}
.document-heading { padding: 16px 22px; border-bottom: 1px solid #e5e7eb; background: linear-gradient(180deg, #ffffff 0%, #fafafa 100%); display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.document-heading-left { min-width: 0; display: flex; align-items: center; gap: 10px; }
.document-kicker { color: #6b7280; font-size: 11px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; margin-bottom: 5px; }
.document-submitted { color: #374151; font-size: 13px; }
.document-section { padding: 20px 22px; border-bottom: 1px solid #e5e7eb; }
.document-section:last-child { border-bottom: 0; }
.document-section-title { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 14px; color: #111827; font-weight: 700; }
.document-section-title .section-label { font-size: 15px; }
.document-status-pill { padding: 8px 12px; border-radius: 999px; font-weight: 700; font-size: 13px; flex-shrink: 0; }

/* Call Slip - same plain card style dean/admin see in their Call Slip History */
.call-slip-card { border: 1px solid #e5e7eb; background: #f9fafb; border-radius: 10px; padding: 12px; }
.call-slip-card + .call-slip-card { margin-top: 10px; }
.call-slip-card-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; }
.call-slip-issuer { font-weight: 600; color: #111827; font-size: 13px; }
.call-slip-issued-at { font-size: 12px; color: #6b7280; margin-top: 3px; line-height: 1.5; }
.call-slip-status-pill { flex-shrink: 0; display: inline-flex; align-items: center; padding: 4px 9px; border-radius: 999px; background: #dcfce7; color: #166534; font-size: 11px; font-weight: 700; }
.call-slip-report-when { margin-top: 10px; font-size: 13px; color: #111827; }
.call-slip-reason { margin-top: 8px; font-size: 13px; color: #374151; line-height: 1.5; white-space: pre-wrap; }
.scroll-top-btn { position: fixed; right: 24px; bottom: 24px; width: 44px; height: 44px; border-radius: 999px; background: #6b46c1; color: #fff; border: none; display: none; align-items: center; justify-content: center; cursor: pointer; box-shadow: 0 10px 24px rgba(107, 70, 193, 0.35); z-index: 500; transition: background .15s ease, transform .15s ease, opacity .2s ease; opacity: 0; transform: translateY(8px); }
.scroll-top-btn.visible { display: flex; opacity: 1; transform: translateY(0); }
.scroll-top-btn:hover { background: #5b3aa8; }
.scroll-top-btn i { font-size: 22px; }
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
.feedback-question { font-size: 14px; font-weight: 700; color: #111827; text-align: center; margin-bottom: 18px; }
.feedback-row { display: flex; justify-content: center; align-items: flex-start; gap: 26px; margin-bottom: 16px; flex-wrap: wrap; }
.feedback-option {
    position: relative;
    border: none;
    background: transparent;
    padding: 4px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
    cursor: pointer;
    width: 104px;
    transition: transform 0.2s ease;
}
.feedback-option:hover { transform: translateY(-2px); }
.feedback-option:hover .feedback-option-icon { filter: grayscale(0.35) opacity(0.85); }
.feedback-option input { position: absolute; opacity: 0; pointer-events: none; }
.feedback-option-icon {
    width: 68px;
    height: 68px;
    border-radius: 999px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 30px;
    color: #6b7280;
    background: #f3f4f6;
    border: 2px solid #e5e7eb;
    flex: 0 0 auto;
    filter: grayscale(1) opacity(0.6);
    transition: all 0.2s ease;
}
.feedback-option-label { font-size: 13px; font-weight: 600; color: #6b7280; text-align: center; line-height: 1.3; transition: color 0.2s ease; }
.feedback-option.active .feedback-option-label { color: #111827; font-weight: 700; }
.feedback-option.active .feedback-option-icon { filter: none; transform: scale(1.08); color: #fff; }
.feedback-option[data-option="very_satisfied"].active .feedback-option-icon { background: linear-gradient(180deg, #fde68a 0%, #eab308 100%); border-color: #eab308; box-shadow: 0 8px 18px rgba(234, 179, 8, 0.35); }
.feedback-option[data-option="satisfied"].active .feedback-option-icon { background: linear-gradient(180deg, #86efac 0%, #22c55e 100%); border-color: #22c55e; box-shadow: 0 8px 18px rgba(34, 197, 94, 0.32); }
.feedback-option[data-option="not_satisfied"].active .feedback-option-icon { background: linear-gradient(180deg, #fed7aa 0%, #f97316 100%); border-color: #f97316; box-shadow: 0 8px 18px rgba(249, 115, 22, 0.32); }
.feedback-option[data-option="very_unsatisfied"].active .feedback-option-icon { background: linear-gradient(180deg, #fca5a5 0%, #ef4444 100%); border-color: #ef4444; box-shadow: 0 8px 18px rgba(239, 68, 68, 0.32); }
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
        .feedback-panel { border:1px solid #e5e7eb; border-radius:14px; padding:16px; background:#f9fafb; }
        .feedback-summary-card { background:#fff; border:1px solid #e5e7eb; border-radius:14px; padding:18px; display:flex; flex-direction:column; gap:14px; box-shadow:0 4px 14px rgba(15,23,42,0.04); align-items:stretch; }
        .feedback-summary-top { display:flex; align-items:flex-start; gap:14px; width:100%; }
        .feedback-avatar { width:46px; height:46px; border-radius:999px; display:inline-flex; align-items:center; justify-content:center; overflow:hidden; background:#6b46c1; color:#fff; font-weight:700; font-size:16px; border:1px solid #eef2ff; flex-shrink:0; }
        .feedback-avatar img { width:100%; height:100%; object-fit:cover; }
        .feedback-summary-info { flex:1; min-width:0; }
        .feedback-head { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap; }
        .feedback-title { font-weight:700; color:#111827; font-size:14px; }
        .feedback-subtext { font-size:13px; color:#6b7280; }
        .feedback-comment-bubble { width:100%; background:#f8fafc; border:1px solid #eef2ff; border-radius:12px; padding:12px 14px; font-size:13px; color:#374151; line-height:1.6; white-space:pre-wrap; word-break:break-word; }
        .empty-card { background:#f9fafb; border:1px dashed #d1d5db; border-radius:12px; padding:14px; color:#6b7280; font-size:13px; }
        .reply-box-card { border:1px solid #e5e7eb; border-radius:14px; padding:16px; background:#fff; box-shadow:0 4px 14px rgba(15,23,42,0.06); }
        .reply-box-card textarea { width:100%; min-height:120px; border-radius:12px; padding:14px; border:1px solid #d1d5db; resize:vertical; background:#fff; font-size:14px; line-height:1.5; }
        .reply-box-card label { display:block; font-size:13px; font-weight:700; color:#374151; margin-bottom:8px; }
        .reply-actions { display:flex; align-items:center; justify-content:flex-end; gap:10px; flex-wrap:wrap; margin-top:12px; }
        .reply-status { flex:1 1 auto; font-size:13px; color:#6b7280; min-height:20px; }
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
@media (max-width: 1024px) { .main { margin-left: 0; } .grid { grid-template-columns: 1fr; } .feedback-row { gap: 16px; } }
</style>
</head>
<body>
<?php include 'student_topbar.php'; ?>
<?php include 'student_sidebar.php'; ?>
<div class="main" style="margin-left:260px;margin-top:61px;padding:25px;min-height:calc(100vh - 61px);">
    <?php if ($flashMessage !== ''): ?>
        <div class="notice <?php echo e($flashType); ?>"><?php echo e($flashMessage); ?></div>
    <?php endif; ?>

    <?php if (!$ticketFound): ?>
        <div class="card" style="max-width:480px;margin:40px auto;text-align:center;padding:36px 28px;">
            <i class='bx bx-folder-open' style="font-size:40px;color:#9ca3af;margin-bottom:14px;"></i>
            <div style="font-size:14px;color:#374151;margin-bottom:22px;line-height:1.5;">This ticket is no longer available. It may have been removed.</div>
            <a href="student_mysubmission.php" class="btn" style="display:inline-block;text-decoration:none;padding:10px 20px;">Back to My Submissions</a>
        </div>
    <?php else: ?>

    <?php if ($ticketIsPastSchoolYear): ?>
        <div class="notice" style="background:#fffbeb;border:1px solid #fcd34d;color:#92400e;display:flex;align-items:flex-start;gap:10px;">
            <i class='bx bx-lock-alt' style="font-size:20px;flex-shrink:0;"></i>
            <span>This ticket is from School Year <strong><?php echo e($ticketSchoolYear); ?></strong> and is read-only. Rating and replying are only available for tickets filed in the current school year.</span>
        </div>
    <?php endif; ?>

    <?php
    // Build rounds: each staff reply becomes a round. Assign feedback by time-window.
    $staffReplies = [];
    foreach ($replies as $r) {
        $role = strtolower((string)($r['sender_role'] ?? ''));
        if ($role === 'dean' || $role === 'admin') {
            $staffReplies[] = $r;
        }
    }

    $rounds = [];
    foreach ($staffReplies as $idx => $dr) {
        $next = $staffReplies[$idx + 1] ?? null;
        $assignedFeedback = null;
        if (!empty($feedback) && !empty($feedback['created_at'])) {
            try {
                $fbTime = new DateTimeImmutable((string)$feedback['created_at']);
                $drTime = new DateTimeImmutable((string)$dr['created_at']);
                $nextTime = $next ? new DateTimeImmutable((string)$next['created_at']) : null;
                if ($fbTime >= $drTime && ($nextTime === null || $fbTime < $nextTime)) {
                    $assignedFeedback = $feedback;
                }
            } catch (Exception $e) {
            }
        }

        $roundFeedbackReplies = [];
        foreach ($feedbackReplies as $fr) {
            try {
                $frTime = new DateTimeImmutable((string)$fr['created_at']);
                $drTime = new DateTimeImmutable((string)$dr['created_at']);
                $nextTime = $next ? new DateTimeImmutable((string)$next['created_at']) : null;
                if ($frTime >= $drTime && ($nextTime === null || $frTime < $nextTime)) {
                    $roundFeedbackReplies[] = $fr;
                }
            } catch (Exception $e) {
            }
        }

        $rounds[] = [
            'dean' => $dr,
            'feedback' => $assignedFeedback,
            'feedbackReplies' => $roundFeedbackReplies,
        ];
    }

    // active round: most recent round without feedback
    $activeRoundIndex = null;
    for ($i = count($rounds) - 1; $i >= 0; $i--) {
        if (empty($rounds[$i]['feedback'])) { $activeRoundIndex = $i; break; }
    }
    ?>

    <?php if (!empty($callSlips)): ?>
    <div class="complaint-document-layout">
    <?php endif; ?>
    <div class="card complaint-document" style="margin-bottom:18px;">
        <div class="document-heading">
            <div class="document-heading-left">
                <a href="student_mysubmission.php" id="recordBackBtn" class="document-back-btn" aria-label="Back to My Submissions" title="Back to My Submissions">
                    <i class='bx bx-arrow-back'></i>
                </a>
                <div>
                    <div class="document-kicker">Official <?php echo e(ucfirst($ticketType)); ?> Record</div>
                    <div class="document-submitted">Submitted <?php echo e(!empty($ticket['created_at']) ? date('M d, Y h:i A', strtotime((string)$ticket['created_at'])) : ''); ?></div>
                </div>
            </div>
            <?php
                $status = strtolower((string)$threadState['status']);
                if ($ticketType === 'suggestion') {
                    $suggestionStatusMeta = suggestion_status_meta($status);
                    $statusLabel = $suggestionStatusMeta['label'];
                    $statusColorMap = [
                        'status-review' => 'background:#f59e0b;color:#1f2937;',
                        'status-needs-info' => 'background:#fde68a;color:#78350f;',
                        'status-accepted' => 'background:#dbeafe;color:#1d4ed8;',
                        'status-planned' => 'background:#e0e7ff;color:#3730a3;',
                        'status-progress' => 'background:#ede9fe;color:#5b21b6;',
                        'status-implemented' => 'background:#dcfce7;color:#065f46;',
                        'status-declined' => 'background:#fee2e2;color:#b91c1c;',
                    ];
                    $statusColor = $statusColorMap[$suggestionStatusMeta['badge']] ?? 'background:#f3f4f6;color:#374151;';
                } else {
                    $statusLabel = $status === 'new' ? 'Open' : ($status === 'under_review' ? 'Under review' : ucfirst($status));
                    $statusColor = 'background:#f59e0b;color:#1f2937;';
                    if ($status === 'resolved' || $status === 'reviewed') {
                        $statusColor = 'background:#dcfce7;color:#065f46;';
                    } elseif ($status === 'dismissed') {
                        // Closed without action; neutral rather than the amber used
                        // for complaints that are still in progress.
                        $statusColor = 'background:#e5e7eb;color:#374151;';
                    }
                }
            ?>
            <div class="document-status-pill" style="<?php echo $statusColor; ?>"><?php echo e($statusLabel); ?></div>
        </div>

        <?php if ($ticketType === 'complaint'): ?>
            <div class="document-section">
                <div class="document-section-title"><div class="section-label">Complainant & Incident Details</div></div>
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
        <?php elseif ($ticketType === 'suggestion'): ?>
            <div class="document-section">
                <div class="document-section-title"><div class="section-label">Suggestion Details</div></div>
                <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;">
                    <div><div style="font-size:12px;color:#6b7280;">Subject</div><div style="margin-top:6px;font-weight:600;"><?php echo e((string)($ticket['subject'] ?? '')); ?></div></div>
                    <div><div style="font-size:12px;color:#6b7280;">Category</div><div style="margin-top:6px;"><?php echo e((string)($ticket['category_name'] ?? '')); ?></div></div>
                    <div><div style="font-size:12px;color:#6b7280;">Date submitted</div><div style="margin-top:6px;"><?php echo e(!empty($ticket['created_at']) ? date('M d, Y', strtotime((string)$ticket['created_at'])) : ''); ?></div></div>
                    <div class="full" style="grid-column:1 / -1;"><div style="font-size:12px;color:#6b7280;">Description</div><div style="margin-top:6px;white-space:pre-wrap;"><?php echo nl2br(e((string)($ticket['description'] ?? ''))); ?></div></div>
                    <?php if (!empty($ticket['expected_outcome'])): ?>
                        <div class="full" style="grid-column:1 / -1;"><div style="font-size:12px;color:#6b7280;">Expected outcome</div><div style="margin-top:6px;white-space:pre-wrap;"><?php echo nl2br(e((string)$ticket['expected_outcome'])); ?></div></div>
                    <?php endif; ?>
                </div>

                <?php if (!empty($ticket['attachment'])): ?>
                    <?php $att = (string)$ticket['attachment']; $attPath = '../' . ltrim($att, '/'); $ext = strtolower(pathinfo($att, PATHINFO_EXTENSION)); $isImage = in_array($ext, ['jpg','jpeg','png','gif'], true); ?>
                    <div style="margin-top:12px;">
                        <div style="font-size:12px;color:#6b7280;margin-bottom:6px;">Reference / Attachment</div>
                        <?php if ($isImage): ?>
                            <a href="<?php echo e($attPath); ?>" target="_blank"><img src="<?php echo e($attPath); ?>" alt="attachment" style="max-width:360px;border-radius:8px;border:1px solid #eef2ff;"></a>
                        <?php else: ?>
                            <a href="<?php echo e($attPath); ?>" target="_blank" class="btn" style="display:inline-block;padding:8px 12px;border-radius:8px;">Download attachment</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($ticketType === 'complaint' && !empty($ticket['desired_outcome'])): ?>
            <div class="document-section">
                <div class="document-section-title"><div class="section-label">Desired Outcome</div></div>
                <div style="white-space:pre-wrap;"><?php echo nl2br(e((string)$ticket['desired_outcome'])); ?></div>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($callSlips)): ?>
    <div class="call-slip-history-card card">
        <div class="call-slip-history-card-title"><i class='bx bx-phone-call'></i> Call Slip</div>
        <?php foreach ($callSlips as $callSlip): ?>
            <?php
                $callSlipIssuer = (string)($callSlip['issuer_name'] ?? ($callSlip['issued_by_role'] ?? 'Unknown issuer'));
                $callSlipRoleLabel = ucfirst((string)($callSlip['issued_by_role'] ?? 'issuer'));
                $callSlipReportDate = trim((string)($callSlip['report_date'] ?? ''));
                $callSlipReportTime = trim((string)($callSlip['report_time'] ?? ''));
            ?>
            <div class="call-slip-card">
                <div class="call-slip-card-top">
                    <div>
                        <div class="call-slip-issuer"><?php echo e($callSlipIssuer); ?></div>
                        <div class="call-slip-issued-at">
                            <?php echo e(date('F j, Y', strtotime((string)$callSlip['issued_at']))); ?> at
                            <?php echo e(date('g:i A', strtotime((string)$callSlip['issued_at']))); ?>
                            &middot; <?php echo e($callSlipRoleLabel); ?>
                        </div>
                    </div>
                    <span class="call-slip-status-pill"><?php echo e(ucfirst((string)($callSlip['status'] ?? 'issued'))); ?></span>
                </div>
                <?php if ($callSlipReportDate !== '' || $callSlipReportTime !== ''): ?>
                    <div class="call-slip-report-when">
                        Please report on
                        <strong><?php echo e($callSlipReportDate !== '' ? date('M d, Y', strtotime($callSlipReportDate)) : ''); ?></strong>
                        <?php if ($callSlipReportTime !== ''): ?> at <strong><?php echo e($callSlipReportTime); ?></strong><?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    </div>
    <?php endif; ?>

    <div class="card response-timeline-card" style="padding:0;margin-bottom:18px;">
        <div style="display:flex;align-items:center;padding:12px 16px;border-bottom:1px solid #eef2ff;">
            <div style="font-weight:700;flex:1;">Response timeline</div>
        </div>

        <div style="padding:16px;">
            <?php if (count($rounds) === 0 && empty($feedback) && empty($replies)): ?>
                <div class="muted">No responses yet.</div>
            <?php else: ?>
                <?php
                    $timelineReplies = [];
                    $officialRemark = null;

                    foreach ($replies as $replyItem) {
                        $replyRoleRaw = strtolower((string)($replyItem['sender_role'] ?? ''));
                        $replyEntry = [
                            'kind' => 'thread',
                            'created_at' => (string)($replyItem['created_at'] ?? ''),
                            'message' => (string)($replyItem['message'] ?? ''),
                            'sender_id' => isset($replyItem['sender_id']) ? (int)$replyItem['sender_id'] : 0,
                            'sender_role' => $replyRoleRaw,
                        ];

                        if (($replyRoleRaw === 'dean' || $replyRoleRaw === 'admin') && $officialRemark === null) {
                            $officialRemark = $replyEntry;
                        } else {
                            $timelineReplies[] = $replyEntry;
                        }
                    }

                    foreach ($feedbackReplies as $replyItem) {
                        $timelineReplies[] = [
                            'kind' => 'feedback',
                            'created_at' => (string)($replyItem['created_at'] ?? ''),
                            'message' => (string)($replyItem['message'] ?? ''),
                            'sender_id' => isset($replyItem['replier_id']) ? (int)$replyItem['replier_id'] : 0,
                            'sender_role' => strtolower((string)($replyItem['replier_role'] ?? '')),
                        ];
                    }

                    usort($timelineReplies, function ($a, $b) {
                        $ta = strtotime((string)($a['created_at'] ?? ''));
                        $tb = strtotime((string)($b['created_at'] ?? ''));
                        return ($ta === $tb) ? 0 : (($ta < $tb) ? -1 : 1);
                    });
                ?>

                <div class="timeline-shell">
                    <?php
                        // Whether the student can currently send a reply - same condition
                        // used below to decide if the reply form is shown, computed early
                        // so the per-entry "Reply" links know whether to appear.
                        //
                        // Suggestions use their own richer status vocabulary (needs_info,
                        // accepted, planned, in_progress, ...) that the shared
                        // ticket_thread_is_active()/is_locked() bucket logic above doesn't
                        // know about, so they're computed separately here via
                        // suggestion_flow.php instead of trusting $threadState['can_reply'].
                        if ($ticketType === 'suggestion') {
                            $threadWouldAllowReply = !suggestion_thread_is_locked((string)$threadState['status']);
                            $ticketIsClosed = suggestion_thread_is_locked((string)($threadState['status'] ?? ''));
                        } else {
                            $threadWouldAllowReply = ($threadState['can_reply'] ?? false) && strtolower((string)$threadState['status']) !== 'resolved';

                            // The Student Feedback section (rating form or an already-submitted
                            // rating) is only shown once the ticket has reached its closed status -
                            // not while it's still open/under review. Past feedback stays visible
                            // even if something later reopens the ticket.
                            $ticketIsClosed = in_array(strtolower((string)($threadState['status'] ?? '')), ['resolved', 'reviewed'], true);
                        }
                        $studentCanReplyNow = $isSubmitter && $threadWouldAllowReply && !$ticketIsPastSchoolYear;
                        $showFeedbackSection = $ticketIsClosed || !empty($feedback);
                    ?>
                    <div class="ticket-section">
                        <div class="section-title">Responses</div>
                        <?php if ($officialRemark): ?>
                            <?php
                                $officialRole = strtolower((string)($officialRemark['sender_role'] ?? ''));
                                $officialSenderId = isset($officialRemark['sender_id']) ? (int)$officialRemark['sender_id'] : 0;
                                $officialPerson = $officialSenderId > 0 ? get_person_display($pdo, $officialRole, $officialSenderId) : ['name' => 'Staff', 'photo' => null];
                                $officialName = $officialPerson['name'] ?? (ucfirst($officialRole) ?: 'Staff');
                                $officialPhoto = !empty($officialPerson['photo']) ? ('../' . ltrim($officialPerson['photo'], '/')) : null;
                                $officialRoleLabel = $officialRole === 'dean' ? 'College Dean' : ($officialRole === 'admin' ? 'Administrator' : 'Student');
                                $officialCurrentUser = false;
                                $currentSessionUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
                                if ($officialRole === 'student') {
                                    $officialCurrentUser = $officialSenderId === $studentProfileId;
                                } elseif ($currentSessionUserId > 0) {
                                    if ($officialRole === 'dean') {
                                        $tmp = $pdo->prepare('SELECT user_id FROM dean_profiles WHERE id = :id LIMIT 1');
                                        $tmp->execute([':id' => $officialSenderId]);
                                        $r = $tmp->fetch(PDO::FETCH_ASSOC);
                                        $senderUserId = $r ? (int)$r['user_id'] : $officialSenderId;
                                        $officialCurrentUser = $senderUserId === $currentSessionUserId;
                                    } elseif ($officialRole === 'admin') {
                                        $tmp = $pdo->prepare('SELECT user_id FROM admin_profiles WHERE id = :id LIMIT 1');
                                        $tmp->execute([':id' => $officialSenderId]);
                                        $r = $tmp->fetch(PDO::FETCH_ASSOC);
                                        $senderUserId = $r ? (int)$r['user_id'] : $officialSenderId;
                                        $officialCurrentUser = $senderUserId === $currentSessionUserId;
                                    }
                                }
                                echo response_timeline_entry([
                                    'name' => $officialName,
                                    'role_label' => $officialRoleLabel,
                                    'is_current_user' => $officialCurrentUser,
                                    'photo' => $officialPhoto,
                                    'time_text' => date('M d, Y h:i A', strtotime((string)$officialRemark['created_at'])),
                                    'message_html' => nl2br(e((string)$officialRemark['message'])),
                                    'reply_target_id' => $studentCanReplyNow ? 'studentReplyBox' : null,
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
                                        $replyIsCurrentUser = false;
                                        $currentSessionUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
                                        if ($replyRoleRaw === 'student') {
                                            $replyIsCurrentUser = $replySenderId === $studentProfileId;
                                        } elseif ($currentSessionUserId > 0) {
                                            if ($replyRoleRaw === 'dean') {
                                                $tmp = $pdo->prepare('SELECT user_id FROM dean_profiles WHERE id = :id LIMIT 1');
                                                $tmp->execute([':id' => $replySenderId]);
                                                $r = $tmp->fetch(PDO::FETCH_ASSOC);
                                                $senderUserId = $r ? (int)$r['user_id'] : $replySenderId;
                                                $replyIsCurrentUser = $senderUserId === $currentSessionUserId;
                                            } elseif ($replyRoleRaw === 'admin') {
                                                $tmp = $pdo->prepare('SELECT user_id FROM admin_profiles WHERE id = :id LIMIT 1');
                                                $tmp->execute([':id' => $replySenderId]);
                                                $r = $tmp->fetch(PDO::FETCH_ASSOC);
                                                $senderUserId = $r ? (int)$r['user_id'] : $replySenderId;
                                                $replyIsCurrentUser = $senderUserId === $currentSessionUserId;
                                            }
                                        }
                                        echo response_timeline_entry([
                                            'name' => $replyName,
                                            'role_label' => $replyRoleLabel,
                                            'is_current_user' => $replyIsCurrentUser,
                                            'photo' => $replyPhoto,
                                            'time_text' => date('M d, Y h:i A', strtotime((string)($replyItem['created_at'] ?? ''))),
                                            'message_html' => nl2br(e((string)($replyItem['message'] ?? ''))),
                                            'is_reply' => true,
                                            'reply_target_id' => $studentCanReplyNow ? 'studentReplyBox' : null,
                                        ]);
                                    ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($showFeedbackSection): ?>
                    <div class="ticket-section">
                        <div class="section-title">Student Feedback</div>
                        <div class="feedback-panel">
                            <?php if (!empty($feedback)): ?>
                                <?php
                                    $m = feedback_option_meta((string)$feedback['satisfaction']);
                                    $studentInfo = get_person_display($pdo, 'student', $ticketOwnerId);
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
                                                <span class="pill <?php echo e((string)$feedback['satisfaction']); ?>">
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
                            <?php elseif (!$isSubmitter): ?>
                                <div class="empty-card">Only the student who filed this can rate the response.</div>
                            <?php elseif ($isSubmitter && empty($feedback) && $feedbackSubmissionReason === null && !$ticketIsPastSchoolYear): ?>
                                <form class="rating-form" method="POST">
                                    <input type="hidden" name="action" value="submit_feedback">
                                    <input type="hidden" name="ticket_type" value="<?php echo e($ticketType); ?>">
                                    <input type="hidden" name="ticket_id" value="<?php echo e($ticketId); ?>">
                                    <input type="hidden" name="satisfaction" class="satisfaction-input" value="">
                                    <div class="feedback-question">How satisfied are you with the response to your concern?</div>
                                    <div class="feedback-row">
                                        <button type="button" class="feedback-option" data-option="very_satisfied" aria-pressed="false">
                                            <div class="feedback-option-icon"><i class='bx bxs-star'></i></div>
                                            <div class="feedback-option-label">Very Satisfied</div>
                                        </button>
                                        <button type="button" class="feedback-option" data-option="satisfied" aria-pressed="false">
                                            <div class="feedback-option-icon"><i class='bx bxs-happy'></i></div>
                                            <div class="feedback-option-label">Satisfied</div>
                                        </button>
                                        <button type="button" class="feedback-option" data-option="not_satisfied" aria-pressed="false">
                                            <div class="feedback-option-icon"><i class='bx bxs-meh'></i></div>
                                            <div class="feedback-option-label">Unsatisfied</div>
                                        </button>
                                        <button type="button" class="feedback-option" data-option="very_unsatisfied" aria-pressed="false">
                                            <div class="feedback-option-icon"><i class='bx bxs-sad'></i></div>
                                            <div class="feedback-option-label">Very Unsatisfied</div>
                                        </button>
                                    </div>
                                    <div style="display:flex;flex-direction:column;gap:8px;">
                                        <label style="font-size:13px;color:#6b7280;font-weight:600;">Additional comments (optional)</label>
                                        <textarea name="comment" rows="2" placeholder="Share your feedback..." style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:10px;font-size:13px;resize:vertical;"></textarea>
                                    </div>
                                    <div class="reply-actions">
                                        <button type="submit" class="btn">Submit Rating</button>
                                    </div>
                                </form>
                            <?php elseif ($ticketIsPastSchoolYear): ?>
                                <div class="empty-card">Read-only: this ticket is from a past school year, so a new rating can no longer be submitted.</div>
                            <?php else: ?>
                                <div class="empty-card"><?php echo e($feedbackSubmissionReason ?? 'No rating has been submitted yet.'); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($studentCanReplyNow): ?>
                        <div class="ticket-section">
                            <div id="studentReplyBox" class="reply-box-card timeline-reply-box">
                                <form id="studentReplyForm" class="student-feedback-reply-form" method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo e((string)($_SESSION['csrf_token'] ?? '')); ?>">
                                    <input type="hidden" name="ticket_type" value="<?php echo e($ticketType); ?>">
                                    <input type="hidden" name="ticket_id" value="<?php echo e($ticketId); ?>">
                                    <input type="hidden" name="student_id" value="<?php echo e($studentProfileId); ?>">
                                    <input type="hidden" name="feedback_history_id" value="<?php echo !empty($feedback) && isset($feedback['id']) ? (int)$feedback['id'] : ''; ?>">
                                    <label for="studentReplyTextarea">Reply</label>
                                    <textarea id="studentReplyTextarea" name="message" rows="4" placeholder="Type your reply..." required></textarea>
                                    <div class="reply-actions">
                                        <span class="reply-status"></span>
                                        <button type="submit" class="btn">Send Reply</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($ticketIsPastSchoolYear && $threadWouldAllowReply): ?>
                        <div style="margin-top:16px;color:#111827;font-size:13px;line-height:1.5;">This ticket is from a past school year and is read-only. No further replies can be sent.</div>
                    <?php elseif (!$threadWouldAllowReply && $ticketIsClosed): ?>
                        <?php if (strtolower($ticketType) === 'suggestion'): ?>
                            <div style="margin-top:16px;color:#111827;font-size:13px;line-height:1.5;">This suggestion has reached its final status (<?php echo e(suggestion_status_meta((string)$threadState['status'])['label']); ?>). No further replies can be sent.</div>
                        <?php elseif (!empty($feedback)): ?>
                            <div style="margin-top:16px;color:#111827;font-size:13px;line-height:1.5;">This complaint has been resolved and the student has already submitted feedback. This ticket is now complete.</div>
                        <?php else: ?>
                            <div style="margin-top:16px;color:#111827;font-size:13px;line-height:1.5;">This complaint has been resolved. No further replies can be sent.</div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
<script>
// Rating UI handlers for the redesigned timeline
document.querySelectorAll('.feedback-option').forEach((btn) => {
    btn.addEventListener('click', () => {
        const parent = btn.closest('.rating-form');
        if (!parent) return;
        parent.querySelectorAll('.feedback-option').forEach(b => b.classList.remove('active'));

        const val = (btn.dataset.option || '').toString();
        btn.classList.add('active');

        parent.querySelectorAll('.feedback-option').forEach(b => b.setAttribute('aria-pressed', b === btn ? 'true' : 'false'));

        const input = parent.querySelector('.satisfaction-input');
        if (input) input.value = val;
    });
});

document.querySelectorAll('.rating-form').forEach((form) => {
    form.addEventListener('submit', (ev) => {
        const input = form.querySelector('.satisfaction-input');
        if (!input || input.value === '') {
            ev.preventDefault();
            alert('Please choose a rating before submitting.');
        }
    });
});

// On load, sync any pre-filled value to the UI (useful if form is re-rendered)
document.querySelectorAll('.rating-form').forEach((form) => {
    const input = form.querySelector('.satisfaction-input');
    if (input && input.value) {
        const val = input.value;
        const btn = form.querySelector('.feedback-option[data-option="' + val + '"]');
        if (btn) {
            btn.classList.add('active');
            form.querySelectorAll('.feedback-option').forEach(b => b.setAttribute('aria-pressed', b === btn ? 'true' : 'false'));
        }
    }
});

// Keep legacy edit/history toggles functional if present
const feedbackEditedToggle = document.getElementById('feedbackEditedToggle');
const feedbackEditedHistory = document.getElementById('feedbackEditedHistory');
if (feedbackEditedToggle && feedbackEditedHistory) {
    feedbackEditedToggle.addEventListener('click', () => {
        const hidden = feedbackEditedHistory.hasAttribute('hidden');
        if (hidden) {
            feedbackEditedHistory.removeAttribute('hidden');
            feedbackEditedToggle.setAttribute('aria-expanded', 'true');
        } else {
            feedbackEditedHistory.setAttribute('hidden', '');
            feedbackEditedToggle.setAttribute('aria-expanded', 'false');
        }
    });
}

const editFeedbackButton = document.getElementById('editFeedbackButton');
const feedbackFormWrapper = document.getElementById('feedbackFormWrapper');
if (editFeedbackButton && feedbackFormWrapper) {
    editFeedbackButton.addEventListener('click', () => {
        const currentlyHidden = feedbackFormWrapper.style.display === 'none';
        if (currentlyHidden) {
            feedbackFormWrapper.style.display = 'block';
            editFeedbackButton.textContent = 'Cancel Edit';
        } else {
            feedbackFormWrapper.style.display = 'none';
            editFeedbackButton.textContent = 'Edit Feedback';
        }
    });
}

</script>
<script>
async function submitStudentFeedbackReply(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const statusEl = form.querySelector('.reply-status');
    if (!statusEl) return;
    statusEl.textContent = 'Sending reply...';
    statusEl.style.color = '#374151';

    const formData = new window.FormData(form);
    try {
        const resp = await fetch('../process_feedback_reply.php', { method: 'POST', body: formData });
        let result = null;
        try {
            result = await resp.json();
        } catch (e) {
            // Ignore non-JSON responses and handle below.
        }

        if (resp.ok && result && result.status === 'ok') {
            statusEl.innerHTML = '<span style="color:#166534;">Reply sent successfully.</span>';
            form.reset();
            setTimeout(() => window.location.reload(), 600);
        } else if (result && result.status === 'error') {
            statusEl.innerHTML = '<span style="color:#b91c1c;">' + escapeHtml(result.message || 'Unable to send reply.') + '</span>';
        } else {
            statusEl.innerHTML = '<span style="color:#b91c1c;">Unable to send reply (HTTP ' + resp.status + ').</span>';
        }
    } catch (err) {
        statusEl.innerHTML = '<span style="color:#b91c1c;">Network error: ' + escapeHtml(err.message) + '</span>';
    }
}

function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (m) {
        return {'&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;'}[m];
    });
}

document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('studentReplyForm');
    if (form) {
        form.addEventListener('submit', submitStudentFeedbackReply);
    }
});
</script>

<?php echo response_timeline_script(); ?>
<button type="button" id="scrollTopBtn" class="scroll-top-btn" aria-label="Scroll to top" title="Back to top">
    <i class='bx bx-up-arrow-alt'></i>
</button>
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Back arrow: prefer returning to the exact My Submissions page the
    // student came from (same filters/search/pagination/scroll position,
    // via normal browser back-navigation) instead of a fresh navigation
    // that would reset it back to the top.
    var backBtn = document.getElementById('recordBackBtn');
    if (backBtn) {
        backBtn.addEventListener('click', function (event) {
            var cameFromList = document.referrer && document.referrer.indexOf(window.location.origin) === 0;
            if (cameFromList && window.history.length > 1) {
                event.preventDefault();
                window.history.back();
            }
            // Otherwise let the plain href navigate to student_mysubmission.php
            // (e.g. this page was opened directly or from elsewhere).
        });
    }

    var scrollTopBtn = document.getElementById('scrollTopBtn');
    if (scrollTopBtn) {
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
    }
});
</script>
</body>
</html>