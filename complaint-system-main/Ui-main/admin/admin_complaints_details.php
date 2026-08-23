<?php

session_start();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../ticket_flow.php';
require_once __DIR__ . '/../call_slip_helpers.php';
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
            $stmt = $pdo->prepare('SELECT dp.first_name, dp.last_name, dp.profile_photo, u.profile_pic FROM dean_profiles dp LEFT JOIN users u ON u.id = dp.user_id WHERE dp.id = :id LIMIT 1');
            $stmt->execute([':id' => (int)$id]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($r && (!empty($r['first_name']) || !empty($r['last_name']) || !empty($r['profile_photo']) || !empty($r['profile_pic']))) {
                $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: 'Dean';
                $photo = $r['profile_photo'] ?? $r['profile_pic'] ?? null;
                return ['name' => $name, 'photo' => $photo];
            }

            $stmt = $pdo->prepare('SELECT dp.first_name, dp.last_name, dp.profile_photo, u.profile_pic FROM dean_profiles dp LEFT JOIN users u ON u.id = dp.user_id WHERE dp.user_id = :id LIMIT 1');
            $stmt->execute([':id' => (int)$id]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($r && (!empty($r['first_name']) || !empty($r['last_name']) || !empty($r['profile_photo']) || !empty($r['profile_pic']))) {
                $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: 'Dean';
                $photo = $r['profile_photo'] ?? $r['profile_pic'] ?? null;
                return ['name' => $name, 'photo' => $photo];
            }

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

// Ensure we have the current admin's profile ID available
$adminProfileId = 0;
if (!isset($_SESSION['user_id']) || (string)($_SESSION['role'] ?? '') !== 'admin') {
    $adminProfileId = 0;
} else {
    try {
        $adminStmt = $pdo->prepare('SELECT id FROM admin_profiles WHERE user_id = :user_id LIMIT 1');
        $adminStmt->execute([':user_id' => (int)$_SESSION['user_id']]);
        $adm = $adminStmt->fetch();
        if ($adm) {
            $adminProfileId = (int)$adm['id'];
        } else {
            $adminProfileId = 0;
        }
    } catch (PDOException $e) {
        $adminProfileId = 0;
    }
}

if ($adminProfileId <= 0) {
    http_response_code(403);
    echo 'Admin profile not found.';
    exit;
}

function ensure_call_slip_table(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS call_slips (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        ticket_type ENUM('complaint', 'suggestion') NOT NULL DEFAULT 'complaint',
        ticket_id INT UNSIGNED NOT NULL,
        student_id INT UNSIGNED NOT NULL,
        issued_by_role VARCHAR(40) NOT NULL,
        issued_by_user_id INT UNSIGNED DEFAULT NULL,
        issued_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        status VARCHAR(20) NOT NULL DEFAULT 'issued',
        PRIMARY KEY (id),
        KEY idx_ticket (ticket_type, ticket_id),
        KEY idx_student (student_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

function issue_call_slip(PDO $pdo, int $ticketId, string $ticketType, int $studentProfileId, string $issuedByRole, int $issuedByUserId): array
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
        $ticketNoStmt = $pdo->prepare('SELECT ticket_no FROM ' . $table . ' WHERE id = :id LIMIT 1');
        $ticketNoStmt->execute([':id' => $ticketId]);
        $ticketRow = $ticketNoStmt->fetch(PDO::FETCH_ASSOC);
        $ticketNo = $ticketRow['ticket_no'] ?? 'UNKNOWN';

        $insertStmt = $pdo->prepare(
            'INSERT INTO call_slips (ticket_type, ticket_id, student_id, issued_by_role, issued_by_user_id, status)
             VALUES (:ticket_type, :ticket_id, :student_id, :issued_by_role, :issued_by_user_id, :status)'
        );
        $insertStmt->execute([
            ':ticket_type' => $ticketType,
            ':ticket_id' => $ticketId,
            ':student_id' => $studentProfileId,
            ':issued_by_role' => $issuedByRole,
            ':issued_by_user_id' => $issuedByUserId > 0 ? $issuedByUserId : null,
            ':status' => 'issued',
        ]);

        $notifyStmt = $pdo->prepare(
            'INSERT INTO notifications (user_id, type, message, ticket_type, ticket_id, is_read)
             VALUES (:user_id, :type, :message, :ticket_type, :ticket_id, 0)'
        );
        $notifyStmt->execute([
            ':user_id' => (int)$studentRow['user_id'],
            ':type' => 'call_slip_issued',
            ':message' => 'A call slip has been issued for complaint ' . $ticketNo . '.',
            ':ticket_type' => $ticketType,
            ':ticket_id' => $ticketId,
        ]);

        return ['ok' => true, 'message' => 'Call Slip issued successfully.'];
    } catch (Throwable $e) {
        error_log('issue_call_slip: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Unable to issue call slip right now.'];
    }
}

// Initialize template variables
$flashMessage = '';
$flashType = 'info';
if (!empty($_SESSION['call_slip_flash']) && is_array($_SESSION['call_slip_flash'])) {
    $flashMessage = (string)($_SESSION['call_slip_flash']['message'] ?? '');
    $flashType = (string)($_SESSION['call_slip_flash']['type'] ?? 'info');
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
$adminRemark = null;

if ($flashMessage === '') {
    try {
        $stmt = $pdo->prepare('SELECT c.*, cc.name AS category_name, sp.first_name, sp.last_name FROM complaints c LEFT JOIN complaint_categories cc ON cc.id = c.category_id LEFT JOIN student_profiles sp ON sp.id = c.student_id WHERE c.id = :id LIMIT 1');
        $stmt->execute([':id' => $ticketId]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$ticket) {
            $flashMessage = 'Complaint not found.';
            $flashType = 'error';
        } else {
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
                $result = issue_call_slip($pdo, $ticketId, 'complaint', $reportedStudentId > 0 ? $reportedStudentId : (int)$ticket['student_id'], 'admin', (int)$_SESSION['user_id']);
                if ($result['ok']) {
                    $mailResult = send_call_slip_email(
                        $recipientEmail,
                        $recipientName,
                        (string)($ticket['ticket_no'] ?? ''),
                        $dateIssued,
                        $timeIssued,
                        'Please see the SAS Director at the SAS Office.',
                        'SAS Director - JOCELYN P. LUMACATUD'
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

                        if (in_array($newStatus, ['under_review', 'resolved'], true)) {
                            // If marking as resolved, require a remark
                            if ($newStatus === 'resolved' && $remark === '') {
                                $flashMessage = 'Please provide Official Remarks before marking this complaint as Resolved.';
                                $flashType = 'error';
                            } else {
                            try {
                                $updateStmt = $pdo->prepare('UPDATE complaints SET status = :status WHERE id = :id');
                                $updateStmt->execute([':status' => $newStatus, ':id' => $ticketId]);

                                // Handle remark update/create for admin
                                if ($remarkId > 0) {
                                    $updateReplyStmt = $pdo->prepare('UPDATE ticket_replies SET message = :message WHERE id = :id AND sender_id = :sender_id AND sender_role = :sender_role');
                                    $updateReplyStmt->execute([
                                        ':message' => $remark,
                                        ':id' => $remarkId,
                                        ':sender_id' => $adminProfileId,
                                        ':sender_role' => 'admin'
                                    ]);
                                } else {
                                    if ($remark !== '') {
                                        $checkStmt = $pdo->prepare('SELECT id FROM ticket_replies WHERE ticket_type = :ticket_type AND ticket_id = :ticket_id AND sender_id = :sender_id AND sender_role = :sender_role LIMIT 1');
                                        $checkStmt->execute([
                                            ':ticket_type' => 'complaint',
                                            ':ticket_id' => $ticketId,
                                            ':sender_id' => $adminProfileId,
                                            ':sender_role' => 'admin'
                                        ]);
                                        $existingRemark = $checkStmt->fetch();

                                        if (!$existingRemark) {
                                            $replyStmt = $pdo->prepare('INSERT INTO ticket_replies (ticket_type, ticket_id, sender_id, sender_role, message, created_at) VALUES (:ticket_type, :ticket_id, :sender_id, :sender_role, :message, NOW())');
                                            $replyStmt->execute([
                                                ':ticket_type' => 'complaint',
                                                ':ticket_id' => $ticketId,
                                                ':sender_id' => $adminProfileId,
                                                ':sender_role' => 'admin',
                                                ':message' => $remark
                                            ]);
                                        }
                                    }
                                }

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

            // Find admin's remark (first reply from this admin)
            foreach ($replies as $reply) {
                if ((int)$reply['sender_id'] === $adminProfileId && strtolower((string)$reply['sender_role']) === 'admin') {
                    $adminRemark = $reply;
                    break;
                }
            }

            // load feedback
            $feedback = get_ticket_feedback($pdo, 'complaint', $ticketId, (int)$ticket['student_id']);
            $feedbackReplies = get_ticket_feedback_replies($pdo, 'complaint', $ticketId, (int)$ticket['student_id']);
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
<title>Manage Complaint - Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
<style>
/* Copy of dean styles to match UI exactly */
* { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
body { background: #f4f6fb; }
.main { margin-left: 260px; margin-top: 61px; padding: 25px; min-height: calc(100vh - 61px); }
.main .card, .main .ticket-header, .main .timeline { max-width: 980px; margin: 0 auto; }
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
.feedback-option { position: relative; border: 1px solid #d1d5db; border-radius: 16px; padding: 14px; background: #fff; display: flex; flex-direction: column; gap: 8px; cursor: pointer; transition: transform 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease; min-height: 108px; }
.feedback-option:hover { transform: translateY(-1px); border-color: #a5b4fc; box-shadow: 0 12px 24px rgba(15, 23, 42, 0.08); }
.feedback-option.active { border-color: #4f8cff; background: linear-gradient(180deg, #f8fbff 0%, #eef4ff 100%); box-shadow: 0 12px 26px rgba(79, 140, 255, 0.16); }
.feedback-option input { position: absolute; opacity: 0; pointer-events: none; }
.feedback-option-head { display: flex; align-items: center; gap: 10px; }
.feedback-option-icon { width: 40px; height: 40px; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center; font-size: 20px; flex: 0 0 auto; }
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
.feedback-history-item { border: 1px solid #e5e7eb; border-radius: 14px; padding: 14px 16px; background: #fff; }
.feedback-history-head { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; margin-bottom: 8px; }
.feedback-history-label { display: flex; align-items: center; gap: 10px; font-weight: 700; color: #111827; }
.feedback-history-time { font-size: 12px; color: #6b7280; white-space: nowrap; }
.feedback-history-comment { font-size: 13px; color: #374151; line-height: 1.55; white-space: pre-wrap; }
.feedback-history-empty { font-size: 13px; color: #6b7280; background: #f9fafb; border: 1px dashed #d1d5db; border-radius: 12px; padding: 12px 14px; }
.rating-row { display:flex; gap:12px; margin-bottom:12px; }
.rating-button { flex:1; padding:12px 16px; border-radius:10px; border:1px solid #d1d5db; background:#fff; cursor:pointer; font-weight:700; display:inline-flex; align-items:center; justify-content:center; gap:8px; }
.rating-button.satisfied { background: linear-gradient(180deg,#ecfdf5 0%,#bbf7d0 100%); color:#065f46; border-color:#bbf7d0; }
.rating-button.not_satisfied { background: linear-gradient(180deg,#fff1f2 0%,#fecaca 100%); color:#7f1d1d; border-color:#fecaca; }
.rating-toggle { color: #111827; background: #fff; border-radius: 10px; padding: 12px 14px; border: 1px solid #d1d5db; display: inline-flex; align-items:center; justify-content:center; gap:8px; font-weight:700; }
.rating-toggle.satisfied { background: linear-gradient(180deg,#ecfdf5 0%,#bbf7d0 100%); color:#065f46; border-color:#bbf7d0; }
.rating-toggle.not_satisfied { background: linear-gradient(180deg,#fff1f2 0%,#fecaca 100%); color:#7f1d1d; border-color:#fecaca; }
.rating-toggle.active { background: linear-gradient(180deg,#6b46c1 0%,#7c3aed 100%) !important; color: #fff !important; border-color:#6b46c1 !important; }
.rating-toggle.active i { color: #fff !important; }
.feedback-edit-button { background: #eff6ff; color: #1d4ed8; border: 1px solid #c7d2fe; border-radius: 10px; padding: 10px 16px; cursor: pointer; font-weight: 700; margin-bottom: 16px; }
.feedback-edit-button:hover { background: #dbeafe; }
.textarea, .input { width: 100%; border: 1px solid #d1d5db; border-radius: 10px; padding: 12px 14px; background: #fff; }
.grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
@media (max-width: 1024px) { .main { margin-left: 0; } .grid { grid-template-columns: 1fr; } .feedback-row { grid-template-columns: 1fr; } }
/* Form & button helpers (copied from dean templates) */
.form-group { margin-bottom: 14px; }
.form-group label { display:block; font-size:13px; font-weight:600; color:#374151; margin-bottom:8px; }
.form-control { width:100%; padding:10px 12px; border:1px solid #d1d5db; border-radius:10px; font-size:13px; font-family:'Poppins',sans-serif; outline:none; transition:0.15s; background:#fff; }
.form-control:focus { border-color:#6b46c1; box-shadow:0 6px 18px rgba(15,23,42,0.04); }
textarea.form-control { resize:vertical; min-height:100px; }
.btn { display:inline-flex; align-items:center; gap:8px; padding:8px 12px; border-radius:8px; background:#111827; color:#fff; border:none; cursor:pointer; font-weight:700; }
.btn:hover { opacity:0.95; }
.btn-secondary { background:#6b46c1; color:#fff; border:none; }
.status-remarks-actions { margin-top:12px; display:flex; gap:8px; align-items:center; }
.reply-actions { display:flex; justify-content:flex-end; gap:12px; align-items:center; margin-top:8px; }
.notice { padding:10px 12px; border-radius:8px; margin-bottom:12px; }
.notice.info { background:#eef2ff; color:#1e3a8a; border:1px solid #e0e7ff; }
.muted { color:#6b7280; }
/* Timeline & message bubbles (match dean styles) */
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
.call-slip-notes { margin: 18px 0 20px; font-size: 15px; color: #111827; }
.call-slip-footer-row { display: flex; justify-content: space-between; gap: 30px; margin-top: 14px; }
.call-slip-signature { display: flex; flex-direction: column; gap: 8px; width: 200px; font-size: 12px; color: #374151; }
.call-slip-signature input { width: 100%; border: none; border-bottom: 1px solid rgba(17,24,39,0.5); background: transparent; padding: 4px 0; font-size: 14px; font-family: 'Poppins', sans-serif; color: #111827; }
.call-slip-signature input:focus { outline: none; border-bottom-color: #4F8CFF; }
.call-slip-close-row { display: flex; justify-content: flex-end; margin-top: 12px; }
</style>
</head>
<body>
<?php include 'admin_topbar.php'; ?>
<?php include 'admin_sidebar.php'; ?>
<div class="main">
    <?php if ($flashMessage !== ''): ?>
        <div class="notice <?php echo e($flashType); ?>"><?php echo e($flashMessage); ?></div>
    <?php endif; ?>

    <div class="ticket-header card" style="margin-bottom:16px;">
        <div class="muted">Submitted <?php echo e(!empty($ticket['created_at']) ? date('M d, Y h:i A', strtotime((string)$ticket['created_at'])) : ''); ?></div>
    </div>
    <script>
        (function(){
            var status = document.getElementById('statusSelect');
            var remark = document.getElementById('remarkTextarea');
            if (!status || !remark) return;
            function toggleRequired(){
                if (status.value === 'resolved') {
                    remark.required = true;
                } else {
                    remark.required = false;
                }
            }
            status.addEventListener('change', toggleRequired);
            // initialize
            toggleRequired();
        })();
    </script>

    <!-- Complaint Details -->
    <div class="card" style="margin-bottom:18px;">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:12px;">
            <div style="font-weight:700;color:#111827;">Complainant & Incident Details</div>
            <button id="callSlipIssueBtn" type="button" class="btn" style="background:#f7c948;color:#111827;border:none;box-shadow:0 3px 10px rgba(247,201,72,0.35);font-weight:700;">
                Call Slip
            </button>
        </div>
        <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;">
            <div><div style="font-size:12px;color:#6b7280;">Complainant name</div><div style="margin-top:6px;font-weight:600;"><?php echo e((string)($ticket['complainant_name'] ?? '')); ?></div></div>
            <div><div style="font-size:12px;color:#6b7280;">Contact details</div><div style="margin-top:6px;"><?php echo e((string)($ticket['complainant_contact_details'] ?? '')); ?></div></div>
            <div><div style="font-size:12px;color:#6b7280;">Date / Time</div><div style="margin-top:6px;"><?php echo e((string)($ticket['date_of_incident'] ?? '')); ?> <?php echo e((string)($ticket['time_of_incident'] ?? '')); ?></div></div>
            <div><div style="font-size:12px;color:#6b7280;">Place of Incident</div><div style="margin-top:6px;"><?php echo e((string)($ticket['place_of_incident'] ?? '')); ?></div></div>
            <div class="full" style="grid-column:1 / -1;"><div style="font-size:12px;color:#6b7280;">Person / Office complained of</div><div style="margin-top:6px;"><?php echo e((string)($ticket['person_complained_of'] ?? '')); ?></div></div>
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
        <div class="card" style="margin-bottom:18px;">
            <div style="font-weight:700;margin-bottom:8px;color:#111827;">Desired Outcome</div>
            <div style="white-space:pre-wrap;"><?php echo nl2br(e((string)$ticket['desired_outcome'])); ?></div>
        </div>
    <?php endif; ?>

    <!-- Status Update & Remarks Form -->
    <div class="card status-remarks-card" style="margin-bottom:18px;">
        <div style="font-weight:700;margin-bottom:12px;color:#111827;">Update Status & Official Remarks</div>

<form method="POST">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="csrf_token" value="<?php echo e((string)($_SESSION['csrf_token'] ?? '')); ?>">
                <?php if (!empty($adminRemark)): ?>
                    <input type="hidden" name="remark_id" value="<?php echo (int)$adminRemark['id']; ?>">
                <?php endif; ?>

                <div class="form-group">
                    <label for="statusSelect">Complaint Status</label>
                    <select id="statusSelect" name="status" class="form-control">
                        <option value="under_review" <?php echo ((string)$ticket['status'] === 'under_review') ? 'selected' : ''; ?>>Under Review</option>
                        <option value="resolved" <?php echo ((string)$ticket['status'] === 'resolved') ? 'selected' : ''; ?>>Resolved</option>
                        
                </select>
            </div>

            <?php if (!empty($adminRemark)): ?>
                <!-- Display existing remark -->
                <div id="remarkDisplay" style="margin-bottom:12px;">
                    <div style="font-weight:700;margin-bottom:6px;color:#111827;font-size:14px;">Official Remarks (Visible to Student)</div>
                    <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:12px;margin-bottom:12px;color:#374151;line-height:1.6;">
                        <?php
                        $previewText = trim((string)$adminRemark['message']);
                        if (mb_strlen($previewText) > 120) {
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
                    <div style="font-size:12px;color:#6b7280;margin-bottom:12px;">Added: <?php echo e(date('M d, Y h:i A', strtotime((string)$adminRemark['created_at']))); ?></div>
                    <button type="button" onclick="toggleRemarkEdit()" class="btn btn-secondary" style="background:#6b46c1;color:#fff;border:none;">
                        <i class='bx bx-edit-alt' style="margin-right:4px;"></i> Edit Remarks
                    </button>
                </div>

                <!-- Edit form (hidden by default) -->
                <div id="remarkFormWrapper" style="display:none;margin-bottom:12px;">
                    <div class="form-group">
                        <label for="remarkTextarea">Edit Official Remarks (Visible to Student)</label>
                        <textarea id="remarkTextarea" name="remark" class="form-control" placeholder="Type your response or next steps here..." required><?php echo e((string)$adminRemark['message']); ?></textarea>
                    </div>
                    <div class="status-remarks-actions">
                        <button type="submit" class="btn">Save Changes</button>
                        <button type="button" onclick="toggleRemarkEdit()" class="btn btn-secondary">Cancel</button>
                    </div>
                </div>
            <?php else: ?>
                <!-- New remark form -->
                <div class="form-group">
                    <label for="remarkTextarea">Official Remarks (Visible to Student)</label>
                    <textarea id="remarkTextarea" name="remark" class="form-control" placeholder="Type your response or next steps here..." required></textarea>
                </div>
                <div class="status-remarks-actions">
                    <button type="submit" class="btn">Save Changes</button>
                </div>
            <?php endif; ?>
        </form>
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
                        <div class="call-slip-form-title">CALL SLIP - GUIDANCE</div>
                        <div class="call-slip-meta-row">
                            <div class="call-slip-field half"><span>To:</span> <input class="call-slip-input" type="email" name="to_name" value="<?php echo e($studentDisplayEmail); ?>" placeholder="Student Gmail address" required></div>
                            <div class="call-slip-field half"><span>Date:</span> <input class="call-slip-input" type="date" name="date_issued"></div>
                        </div>
                        <div class="call-slip-notes">Please see the SAS Director at the SAS Office.</div>
                        <div class="call-slip-meta-row">
                            <div class="call-slip-field half"><span>Time:</span> <input class="call-slip-input" type="time" name="time_issued"></div>
                        </div>
                        <div class="call-slip-footer-row">
                            <div class="call-slip-signature"><span>Student Name</span><input type="text" name="student_name" value="<?php echo e($studentDisplayName); ?>" placeholder="Student Name"></div>
                            <div class="call-slip-signature"><span>SAS Director</span><input type="text" name="issued_by" value="JOCELYN P. LUMACATUD"></div>
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

    document.addEventListener('DOMContentLoaded', function () {
        const callSlipButton = document.getElementById('callSlipIssueBtn');
        if (callSlipButton) {
            callSlipButton.addEventListener('click', function (event) {
                event.preventDefault();
                openCallSlipModal();
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

    async function submitAdminFeedbackReply(event) {
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
                appendAdminReply(form, result.reply);
                form.reset();
                const wrapper = form.closest('.reply-form-wrapper');
                if (wrapper && form.id !== 'feedbackReplyForm') {
                    wrapper.style.display = 'none';
                }
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

    function appendAdminReply(form, reply) {
        if (!reply || typeof reply !== 'object') {
            return;
        }

        const wrapper = form.closest('.reply-form-wrapper');
        if (!wrapper) {
            return;
        }

        const newReply = document.createElement('div');
        newReply.className = 'feedback-reply current-user-reply';
        newReply.style.cssText = 'border:1px solid #e5e7eb;border-radius:10px;padding:14px;display:flex;gap:12px;align-items:flex-start;width:100%;max-width:880px;background:#fff;margin-left:0;';
        newReply.innerHTML = `
            <div class="avatar-circle" style="width:36px;height:36px;flex-shrink:0;background:#4f46e5;">
                <span style="font-size:16px;color:#fff;">${escapeHtml((reply.replier_name || 'You').charAt(0).toUpperCase())}</span>
            </div>
            <div style="flex:1;min-width:0;">
                <div style="display:flex;flex-direction:column;gap:4px;margin-bottom:6px;">
                    <div style="font-weight:700;font-size:13px;color:#111827;">${escapeHtml(reply.replier_name || 'You')}</div>
                    <div style="font-size:12px;color:#6b7280;">${escapeHtml(reply.replier_role ? reply.replier_role.charAt(0).toUpperCase() + reply.replier_role.slice(1) : 'Responder')}</div>
                </div>
                <div style="font-size:11px;color:#6b7280;margin-bottom:6px;">${escapeHtml(reply.created_at || '')}</div>
                <div style="font-size:13px;color:#111827;white-space:pre-wrap;">${escapeHtml(reply.message || '')}</div>
            </div>
        `;

        if (wrapper.parentNode) {
            wrapper.parentNode.insertBefore(newReply, wrapper);
        } else {
            wrapper.insertAdjacentElement('afterend', newReply);
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        const replyButtons = document.querySelectorAll('.reply-toggle');
        replyButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                toggleFeedbackReplyForm(button);
            });
        });

        const replyForms = document.querySelectorAll('.admin-feedback-reply-form');
        replyForms.forEach(function (form) {
            form.addEventListener('submit', submitAdminFeedbackReply);
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
    <div class="card" style="padding:0;margin-bottom:18px;">
        <div style="display:flex;align-items:center;padding:12px 16px;border-bottom:1px solid #eef2ff;">
            <div style="font-weight:700;flex:1;">Response Timeline</div>
        </div>
        <div style="padding:16px;">
            <?php if (!$officialRemark && empty($feedback) && empty($timelineReplies)): ?>
                <div class="muted">No responses yet.</div>
            <?php else: ?>
                <div class="timeline-shell">
                    <div class="ticket-section">
                        <div class="section-title">Official Remark</div>
                        <?php if ($officialRemark): ?>
                            <?php
                                $officialRole = strtolower((string)($officialRemark['sender_role'] ?? ''));
                                $officialSenderId = isset($officialRemark['sender_id']) ? (int)$officialRemark['sender_id'] : 0;
                                $officialPerson = $officialSenderId > 0 ? get_person_display($pdo, $officialRole, $officialSenderId) : ['name' => ucfirst($officialRole), 'photo' => null];
                                $officialName = $officialPerson['name'] ?? (ucfirst($officialRole) ?: 'Staff');
                                $officialPhoto = !empty($officialPerson['photo']) ? ('../' . ltrim($officialPerson['photo'], '/')) : null;
                                $officialInitial = strtoupper(substr(trim($officialName), 0, 1));
                                $officialRoleLabel = $officialRole === 'dean' ? 'College Dean' : ($officialRole === 'admin' ? 'Administrator' : 'Student');
                                $officialCurrentUser = ($officialRole === 'admin');
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
                                    <div class="timeline-card current-user">
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
                                    $studentInfo = isset($ticket['student_id']) ? get_person_display($pdo, 'student', (int)$ticket['student_id']) : ['name' => 'Student', 'photo' => null];
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
                            <?php else: ?>
                                <div class="empty-card">No rating has been submitted yet.</div>
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
                                        $replyIsCurrentUser = ($replyRoleRaw === 'admin');
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

                    <?php if ($canReply): ?>
                        <div class="ticket-section">
                            <div class="reply-box-card">
                                <form id="feedbackReplyForm" class="admin-feedback-reply-form" method="POST">
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
            <?php endif; ?>
        </div>
    </div>

    <div style="height:18px;"></div>
</div>
</body>
</html>
