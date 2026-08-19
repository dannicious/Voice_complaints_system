<?php

session_start();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../ticket_flow.php';
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

if ($flashMessage === '') {
    try {
        $table = $ticketType === 'complaint' ? 'complaints' : 'suggestions';
        $stmt = $pdo->prepare("SELECT t.*, c.name AS category_name FROM {$table} t LEFT JOIN " . ($ticketType === 'complaint' ? 'complaint_categories' : 'suggestion_categories') . " c ON c.id = t.category_id WHERE t.id = :id AND t.student_id = :student_id LIMIT 1");
        $stmt->execute([':id' => $ticketId, ':student_id' => $studentProfileId]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$ticket) {
            $flashMessage = 'Ticket not found or you do not have access to view it.';
            $flashType = 'error';
        } else {
            // POST handling for feedback submission
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_feedback') {
                $postedTicketType = (string)($_POST['ticket_type'] ?? '');
                $postedTicketId = isset($_POST['ticket_id']) ? (int)$_POST['ticket_id'] : 0;
                $satisfaction = (string)($_POST['satisfaction'] ?? '');
                $comment = trim((string)($_POST['comment'] ?? ''));

                if ($postedTicketType !== $ticketType || $postedTicketId !== $ticketId) {
                    $flashMessage = 'Invalid submission.';
                    $flashType = 'error';
                } elseif ($satisfaction === '') {
                    $flashMessage = 'Please choose a rating before submitting.';
                    $flashType = 'error';
                } else {
                    try {
                        $reason = get_ticket_feedback_submission_reason($pdo, $ticketType, $ticketId, $studentProfileId);
                        if ($reason !== null) {
                            $flashMessage = $reason;
                            $flashType = 'error';
                        } else {
                            save_ticket_feedback($pdo, $ticketType, $ticketId, $studentProfileId, $satisfaction, $comment);
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
            $feedback = get_ticket_feedback($pdo, $ticketType, $ticketId, $studentProfileId);
            $feedbackHistory = get_ticket_feedback_history($pdo, $ticketType, $ticketId, $studentProfileId);
            $feedbackReplies = get_ticket_feedback_replies($pdo, $ticketType, $ticketId, $studentProfileId);
            try {
                $feedbackSubmissionReason = get_ticket_feedback_submission_reason($pdo, $ticketType, $ticketId, $studentProfileId);
            } catch (Throwable $e) {
                $feedbackSubmissionReason = 'Unable to determine whether you can submit feedback at this time.';
            }
        }
    } catch (PDOException $e) {
        $flashMessage = 'Unable to load ticket details at this time.';
        $flashType = 'error';
    }
}

    // Ensure $ticket is an array to avoid template notices when fields are accessed
    if (!is_array($ticket)) {
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
        .pill { display:inline-flex; align-items:center; gap:8px; padding:6px 10px; border-radius:999px; font-weight:700; font-size:13px; }
        .pill.satisfied { background:#dcfce7; color:#065f46; }
        .pill.neutral { background:#f3f4f6; color:#374151; }
        .pill.not_satisfied { background:#fee2e2; color:#b91c1c; }
        .pill.small { padding:4px 8px; font-size:11px; }
        .feedback-panel { border:1px solid #e5e7eb; border-radius:14px; padding:16px; background:#f9fafb; }
        .feedback-summary-card { background:#fff; border:1px solid #e5e7eb; border-radius:14px; padding:18px; display:flex; flex-direction:column; gap:12px; box-shadow:0 4px 14px rgba(15,23,42,0.04); align-items:flex-start; }
        .feedback-head { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap; }
        .feedback-title { font-weight:700; color:#111827; font-size:14px; }
        .feedback-subtext { font-size:13px; color:#6b7280; }
        .feedback-summary-card .timeline-text { display:block; width:100%; text-align:left !important; align-self:flex-start; word-break:break-word; white-space:pre-line; margin:0; padding:0; text-indent:0; line-height:1.6; }
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
@media (max-width: 1024px) { .main { margin-left: 0; } .grid { grid-template-columns: 1fr; } .feedback-row { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<?php include 'student_topbar.php'; ?>
<?php include 'student_sidebar.php'; ?>
<div class="main" style="margin-left:260px;margin-top:61px;padding:25px;min-height:calc(100vh - 61px);">
    <?php if ($flashMessage !== ''): ?>
        <div class="notice <?php echo e($flashType); ?>"><?php echo e($flashMessage); ?></div>
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

    <div class="ticket-header card" style="display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:16px;">
        <div>
            <div style="font-weight:700;font-size:18px;"><?php echo e(ucfirst($ticketType)); ?> Ticket <?php echo e((string)$ticket['ticket_no']); ?></div>
            <div class="muted">Category: <?php echo e((string)$ticket['category_name']); ?> · Submitted <?php echo e(!empty($ticket['created_at']) ? date('M d, Y h:i A', strtotime((string)$ticket['created_at'])) : ''); ?></div>
        </div>
        <?php
            $status = strtolower((string)$threadState['status']);
            $statusLabel = $status === 'new' ? 'Open' : ($status === 'under_review' ? 'Under review' : ucfirst($status));
            $statusColor = 'background:#f59e0b;color:#1f2937;';
            if ($status === 'resolved' || $status === 'reviewed') {
                $statusColor = 'background:#dcfce7;color:#065f46;';
            }
        ?>
        <div style="padding:8px 12px;border-radius:999px;font-weight:700;<?php echo $statusColor; ?>"><?php echo e($statusLabel); ?></div>
    </div>

    <div class="card" style="margin-bottom:18px;">

        <?php if ($ticketType === 'complaint'): ?>
            <div class="card" style="margin-bottom:12px;">
                <div style="font-weight:700;margin-bottom:8px;color:#111827;">Complainant & Incident Details</div>
                <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;">
                    <div><div style="font-size:12px;color:#6b7280;">Complainant name</div><div style="margin-top:6px;font-weight:600;"><?php echo e((string)($ticket['complainant_name'] ?? '')); ?></div></div>
                    <div><div style="font-size:12px;color:#6b7280;">Contact details</div><div style="margin-top:6px;"><?php echo e((string)($ticket['complainant_contact_details'] ?? '')); ?></div></div>
                    <div><div style="font-size:12px;color:#6b7280;">Address</div><div style="margin-top:6px;"><?php echo e((string)($ticket['complainant_address'] ?? '')); ?></div></div>
                    <div><div style="font-size:12px;color:#6b7280;">Sex / Age / Civil status</div><div style="margin-top:6px;"><?php echo e((string)($ticket['complainant_sex'] ?? '')); ?> · <?php echo e((string)($ticket['complainant_age'] ?? '')); ?> · <?php echo e((string)($ticket['complainant_civil_status'] ?? '')); ?></div></div>
                    <div class="full" style="grid-column:1 / -1;"><div style="font-size:12px;color:#6b7280;">Person / Office complained of</div><div style="margin-top:6px;"><?php echo e((string)($ticket['person_complained_of'] ?? '')); ?></div></div>
                    <div><div style="font-size:12px;color:#6b7280;">Date / Time</div><div style="margin-top:6px;"><?php echo e((string)($ticket['date_of_incident'] ?? '')); ?> <?php echo e((string)($ticket['time_of_incident'] ?? '')); ?></div></div>
                    <div><div style="font-size:12px;color:#6b7280;">Place</div><div style="margin-top:6px;"><?php echo e((string)($ticket['place_of_incident'] ?? '')); ?></div></div>
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
            <div class="card" style="margin-bottom:12px;">
                <div style="font-weight:700;margin-bottom:8px;color:#111827;">Suggestion Details</div>
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
            <div style="color:#6b7280;font-size:13px;margin-bottom:6px;">Desired outcome</div>
            <div style="font-size:14px;margin-bottom:6px;white-space:pre-wrap;"><?php echo nl2br(e((string)$ticket['desired_outcome'])); ?></div>
        <?php endif; ?>
    </div>

    <div class="card" style="padding:0;margin-bottom:18px;">
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
                    <div class="ticket-section">
                        <div class="section-title">Official Remark</div>
                        <?php if ($officialRemark): ?>
                            <?php
                                $officialRole = strtolower((string)($officialRemark['sender_role'] ?? ''));
                                $officialSenderId = isset($officialRemark['sender_id']) ? (int)$officialRemark['sender_id'] : 0;
                                $officialPerson = $officialSenderId > 0 ? get_person_display($pdo, $officialRole, $officialSenderId) : ['name' => 'Staff', 'photo' => null];
                                $officialName = $officialPerson['name'] ?? (ucfirst($officialRole) ?: 'Staff');
                                $officialPhoto = !empty($officialPerson['photo']) ? ('../' . ltrim($officialPerson['photo'], '/')) : null;
                                $officialInitial = strtoupper(substr(trim($officialName), 0, 1));
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
                            ?>
                            <div class="timeline-entry">
                                <div class="timeline-avatar">
                                    <?php if ($officialPhoto): ?>
                                        <img src="<?php echo e($officialPhoto); ?>" alt="<?php echo e($officialName); ?>">
                                    <?php else: ?>
                                        <?php echo e($officialInitial); ?>
                                    <?php endif; ?>
                                </div>
                                <div class="timeline-body">
                                    <div class="timeline-heading">
                                        <div class="timeline-name"><?php echo e($officialName . ($officialCurrentUser ? ' (You)' : '')); ?></div>
                                        <div class="timeline-role"><?php echo e($officialRoleLabel); ?></div>
                                    </div>
                                    <div class="timeline-time"><?php echo e(date('M d, Y h:i A', strtotime((string)$officialRemark['created_at']))); ?></div>
                                    <div class="timeline-card<?php echo $officialCurrentUser ? ' current-user' : ''; ?>">
                                        <div class="timeline-text"><?php echo nl2br(e((string)$officialRemark['message'])); ?></div>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="empty-card">No official remark has been posted yet.</div>
                        <?php endif; ?>
                    </div>

                    <div class="ticket-section">
                        <div class="section-title">Student Feedback</div>
                        <div class="feedback-panel">
                            <?php if (!empty($feedback)): ?>
                                <?php
                                    $m = feedback_option_meta((string)$feedback['satisfaction']);
                                    $studentInfo = get_person_display($pdo, 'student', $studentProfileId);
                                    $stuName = $studentInfo['name'] ?? 'Student';
                                ?>
                                <div class="feedback-summary-card">
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
                                    <div class="timeline-text" style="display:block; width:100%; font-size:13px; color:#374151; white-space:pre-line; text-align:left; word-break:break-word; margin:0; padding:0; text-indent:0; line-height:1.6;">
<?php echo nl2br(e((string)($feedback['comment'] ?? ''))); ?>
                                    </div>
                                </div>
                            <?php elseif (empty($feedback) && $feedbackSubmissionReason === null): ?>
                                <form class="rating-form" method="POST">
                                    <input type="hidden" name="action" value="submit_feedback">
                                    <input type="hidden" name="ticket_type" value="<?php echo e($ticketType); ?>">
                                    <input type="hidden" name="ticket_id" value="<?php echo e($ticketId); ?>">
                                    <input type="hidden" name="satisfaction" class="satisfaction-input" value="">
                                    <div class="feedback-row">
                                        <button type="button" class="feedback-option" data-option="satisfied">
                                            <div class="feedback-option-head">
                                                <div class="feedback-option-icon">😊</div>
                                                <div>
                                                    <div class="feedback-option-label">Satisfied</div>
                                                    <div class="feedback-option-desc">Resolved</div>
                                                </div>
                                            </div>
                                        </button>
                                        <button type="button" class="feedback-option" data-option="neutral">
                                            <div class="feedback-option-head">
                                                <div class="feedback-option-icon">😐</div>
                                                <div>
                                                    <div class="feedback-option-label">Neutral</div>
                                                    <div class="feedback-option-desc">Fair response</div>
                                                </div>
                                            </div>
                                        </button>
                                        <button type="button" class="feedback-option" data-option="not_satisfied">
                                            <div class="feedback-option-head">
                                                <div class="feedback-option-icon">😞</div>
                                                <div>
                                                    <div class="feedback-option-label">Not Satisfied</div>
                                                    <div class="feedback-option-desc">Needs improvement</div>
                                                </div>
                                            </div>
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
                            <?php else: ?>
                                <div class="empty-card"><?php echo e($feedbackSubmissionReason ?? 'No rating has been submitted yet.'); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="ticket-section">
                        <div class="section-title">Conversation Replies</div>
                        <?php if (empty($timelineReplies)): ?>
                            <div class="empty-card">No conversation replies yet.</div>
                        <?php else: ?>
                            <div class="timeline-list">
                                <?php foreach ($timelineReplies as $replyItem): ?>
                                    <?php
                                        $replyRoleRaw = strtolower((string)($replyItem['sender_role'] ?? ''));
                                        $replySenderId = isset($replyItem['sender_id']) ? (int)$replyItem['sender_id'] : 0;
                                        $replyPerson = $replySenderId > 0 ? get_person_display($pdo, $replyRoleRaw, $replySenderId) : ['name' => ucfirst($replyRoleRaw), 'photo' => null];
                                        $replyName = $replyPerson['name'] ?? ucfirst($replyRoleRaw);
                                        $replyPhoto = !empty($replyPerson['photo']) ? ('../' . ltrim($replyPerson['photo'], '/')) : null;
                                        $replyInitial = strtoupper(substr(trim($replyName), 0, 1));
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
                                    ?>
                                    <div class="timeline-entry">
                                        <div class="timeline-avatar">
                                            <?php if ($replyPhoto): ?>
                                                <img src="<?php echo e($replyPhoto); ?>" alt="<?php echo e($replyName); ?>">
                                            <?php else: ?>
                                                <?php echo e($replyInitial); ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="timeline-body">
                                            <div class="timeline-heading">
                                                <div class="timeline-name"><?php echo e($replyName . ($replyIsCurrentUser ? ' (You)' : '')); ?></div>
                                                <div class="timeline-role"><?php echo e($replyRoleLabel); ?></div>
                                            </div>
                                            <div class="timeline-time"><?php echo e(date('M d, Y h:i A', strtotime((string)($replyItem['created_at'] ?? '')))); ?></div>
                                            <div class="timeline-card<?php echo $replyIsCurrentUser ? ' current-user' : ''; ?>">
                                                <div class="timeline-text"><?php echo nl2br(e((string)($replyItem['message'] ?? ''))); ?></div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if (($threadState['can_reply'] ?? false) && strtolower((string)$threadState['status']) !== 'resolved'): ?>
                        <div class="ticket-section">
                            <div class="reply-box-card">
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

                    <?php if ((($threadState['can_reply'] ?? false) === false) && in_array(strtolower((string)($threadState['status'] ?? '')), ['resolved', 'reviewed'], true)): ?>
                        <?php if (strtolower($ticketType) === 'suggestion'): ?>
                            <div style="margin-top:16px;color:#111827;font-size:13px;line-height:1.5;">This suggestion has been reviewed. No further replies can be sent.</div>
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
</div>
<script>
// Rating UI handlers for the redesigned timeline
document.querySelectorAll('.feedback-option').forEach((btn) => {
    btn.addEventListener('click', () => {
        const parent = btn.closest('.rating-form');
        if (!parent) return;
        parent.querySelectorAll('.feedback-option').forEach(b => {
            b.classList.remove('active');
            b.classList.remove('satisfied');
            b.classList.remove('not_satisfied');
            b.classList.remove('neutral');
        });

        const val = (btn.dataset.option || '').toString();
        btn.classList.add('active');
        if (val) btn.classList.add(val);

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
            btn.classList.add(val);
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
</body>
</html>