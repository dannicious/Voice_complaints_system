<?php

session_start();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../ticket_flow.php';
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
if (!function_exists('e')) {
    function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('get_person_display')) {
    function get_person_display(PDO $pdo, string $role, int|string $id): array
    {
        $role = strtolower(trim((string)$role));
        try {
            if ($role === 'student') {
                $stmt = $pdo->prepare('SELECT sp.first_name, sp.last_name, u.profile_pic FROM student_profiles sp LEFT JOIN users u ON u.id = sp.user_id WHERE sp.id = :id LIMIT 1');
                $stmt->execute([':id' => (int)$id]);
                $r = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($r) {
                    $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: 'Student';
                    return ['name' => $name, 'photo' => $r['profile_pic'] ?? null];
                }
            }

            if ($role === 'dean') {
                $stmt = $pdo->prepare('SELECT dp.first_name, dp.last_name, dp.profile_photo, u.profile_pic FROM dean_profiles dp LEFT JOIN users u ON u.id = dp.user_id WHERE dp.id = :id LIMIT 1');
                $stmt->execute([':id' => (int)$id]);
                $r = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($r && (!empty($r['first_name']) || !empty($r['last_name']) || !empty($r['profile_photo']) || !empty($r['profile_pic']))) {
                    $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: 'Dean';
                    return ['name' => $name, 'photo' => $r['profile_photo'] ?? $r['profile_pic'] ?? null];
                }

                $stmt = $pdo->prepare('SELECT dp.first_name, dp.last_name, dp.profile_photo, u.profile_pic FROM dean_profiles dp LEFT JOIN users u ON u.id = dp.user_id WHERE dp.user_id = :id LIMIT 1');
                $stmt->execute([':id' => (int)$id]);
                $r = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($r && (!empty($r['first_name']) || !empty($r['last_name']) || !empty($r['profile_photo']) || !empty($r['profile_pic']))) {
                    $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: 'Dean';
                    return ['name' => $name, 'photo' => $r['profile_photo'] ?? $r['profile_pic'] ?? null];
                }

                $stmt = $pdo->prepare('SELECT u.first_name, u.last_name, u.profile_pic FROM users u WHERE u.id = :id LIMIT 1');
                $stmt->execute([':id' => (int)$id]);
                $r = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($r) {
                    $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: 'Dean';
                    return ['name' => $name, 'photo' => $r['profile_pic'] ?? null];
                }
            }

            if ($role === 'admin') {
                $stmt = $pdo->prepare('SELECT ap.name, u.profile_pic FROM admin_profiles ap LEFT JOIN users u ON u.id = ap.user_id WHERE ap.id = :id LIMIT 1');
                $stmt->execute([':id' => (int)$id]);
                $r = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($r) {
                    return ['name' => $r['name'] ?? 'Admin', 'photo' => $r['profile_pic'] ?? null];
                }
            }
        } catch (Throwable $e) {
            // ignore and fallback
        }

        return ['name' => ucfirst($role), 'photo' => null];
    }
}
// ensure admin
if (!isset($_SESSION['user_id']) || (string)($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403); echo 'Admin profile required.'; exit;
}
$adminUserId = (int)$_SESSION['user_id'];
$adminProfileId = 0;
try {
    $stmt = $pdo->prepare('SELECT id FROM admin_profiles WHERE user_id = :user_id LIMIT 1');
    $stmt->execute([':user_id' => $adminUserId]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($r) { $adminProfileId = (int)$r['id']; }
} catch (PDOException $e) { }
if ($adminProfileId <= 0) { http_response_code(403); echo 'Admin profile not found.'; exit; }

$flashMessage = ''; $flashType = 'info';
$suggestionId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($suggestionId <= 0) { $flashMessage = 'Suggestion id missing.'; $flashType = 'error'; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_suggestion') {
    $postedId = isset($_POST['suggestion_id']) ? (int)$_POST['suggestion_id'] : 0;
    $newStatus = trim((string)($_POST['status'] ?? ''));
    $remark = trim((string)($_POST['remark'] ?? ''));
    $remarkId = isset($_POST['remark_id']) ? (int)$_POST['remark_id'] : 0;
    $allowed = ['new','under_review','reviewed'];
    if ($postedId <= 0 || !in_array($newStatus,$allowed,true)) {
        $flashMessage = 'Invalid request.'; $flashType = 'error';
    } else {
        if ($newStatus === 'reviewed' && $remark === '' && $remarkId <= 0) {
            $flashMessage = 'Please provide Official Remarks before marking Reviewed.'; $flashType='error';
        } else {
            try {
                $update = $pdo->prepare('UPDATE suggestions SET status = :status WHERE id = :id');
                $update->execute([':status'=>$newStatus, ':id'=>$postedId]);
                if ($remarkId > 0 && $remark !== '') {
                    $up = $pdo->prepare('UPDATE ticket_replies SET message = :message WHERE id = :id AND sender_role = :role');
                    $up->execute([':message'=>$remark, ':id'=>$remarkId, ':role'=>'admin']);
                } elseif ($remarkId <= 0 && $remark !== '') {
                    $ins = $pdo->prepare('INSERT INTO ticket_replies (ticket_type, ticket_id, sender_id, sender_role, message, created_at) VALUES ("suggestion", :ticket_id, :sender_id, "admin", :message, NOW())');
                    $ins->execute([':ticket_id'=>$postedId, ':sender_id'=>$adminProfileId, ':message'=>$remark]);
                }
                header('Location: ?id='.$postedId);
                exit;
            } catch (Throwable $e) {
                $flashMessage = 'Unable to save.';
                $flashType='error';
            }
        }
    }
}

// load suggestion (admin scope: only suggestions routed to admin have college_id NULL)
$suggestion = null;
$replies = [];
$feedback = null;
$feedbackReplies = [];
$adminRemark = null;
try {
    $stmt = $pdo->prepare('SELECT s.*, sc.name AS category_name, sp.first_name, sp.last_name FROM suggestions s LEFT JOIN suggestion_categories sc ON sc.id = s.category_id LEFT JOIN student_profiles sp ON sp.id = s.student_id WHERE s.id = :id AND s.college_id IS NULL LIMIT 1');
    $stmt->execute([':id'=>$suggestionId]);
    $suggestion = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$suggestion) { $flashMessage = 'Suggestion not found.'; $flashType='error'; }
    else {
        $r = $pdo->prepare('SELECT id,sender_id,sender_role,message,created_at FROM ticket_replies WHERE ticket_type = "suggestion" AND ticket_id = :id ORDER BY created_at ASC, id ASC');
        $r->execute([':id'=>$suggestionId]);
        $replies = $r->fetchAll(PDO::FETCH_ASSOC);
        foreach ($replies as $rep) {
            if ((int)$rep['sender_id'] === $adminProfileId && strtolower((string)$rep['sender_role']) === 'admin') { $adminRemark = $rep; break; }
        }
        $f = $pdo->prepare('SELECT id,satisfaction,comment,created_at FROM ticket_feedback WHERE ticket_type = "suggestion" AND ticket_id = :id LIMIT 1');
        $f->execute([':id'=>$suggestionId]); $feedback = $f->fetch(PDO::FETCH_ASSOC);
        $fr = $pdo->prepare('SELECT id,feedback_history_id,replier_id,replier_role,message,created_at FROM ticket_feedback_replies WHERE ticket_type = "suggestion" AND ticket_id = :id ORDER BY created_at ASC, id ASC');
        $fr->execute([':id'=>$suggestionId]);
        $feedbackReplies = $fr->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) { $flashMessage = 'Unable to load suggestion.'; $flashType='error'; }

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage Suggestion - Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
<style>
/* Small reset to match other pages (copied from dean) */
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
/* Feedback & timeline styles (copied from dean) */
.feedback-badge { display: inline-flex; gap: 8px; align-items: center; padding: 6px 10px; border-radius: 999px; font-weight: 700; font-size: 13px; }
.feedback-badge.satisfied { background: #dcfce7; color: #065f46; }
.feedback-badge.neutral { background: #f3f4f6; color: #374151; }
.feedback-badge.not_satisfied { background: #fee2e2; color: #b91c1c; }
.feedback-edit-button { background: #eff6ff; color: #1d4ed8; border: 1px solid #c7d2fe; border-radius: 10px; padding: 10px 16px; cursor: pointer; font-weight: 700; margin-bottom: 16px; }
.feedback-edit-button:hover { background: #dbeafe; }
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
.btn { background: #4f8cff; color: #fff; border: none; border-radius: 10px; padding: 11px 16px; cursor: pointer; font-weight: 600; }
@media (max-width: 1024px) { .main { margin-left: 0; } .grid { grid-template-columns: 1fr; } .feedback-row { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<?php include 'admin_topbar.php'; ?>
<?php include 'admin_sidebar.php'; ?>
<div class="main">
    <?php if ($flashMessage !== ''): ?>
        <div class="notice <?php echo e($flashType); ?>"><?php echo e($flashMessage); ?></div>
    <?php endif; ?>

    <div class="ticket-header card" style="display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:16px;">
        <div>
            <div style="font-weight:700;font-size:18px;">Suggestion <?php echo e((string)($suggestion['ticket_no'] ?? '')); ?></div>
            <div class="muted">Category: <?php echo e((string)($suggestion['category_name'] ?? '')); ?> · Submitted <?php echo e(!empty($suggestion['created_at']) ? date('M d, Y h:i A', strtotime((string)$suggestion['created_at'])) : ''); ?></div>
        </div>
        <?php
            $status = strtolower(trim((string)($suggestion['status'] ?? '')));
            $statusMap = [
                'new' => 'Open',
                'open' => 'Open',
                'under_review' => 'Under review',
                'reviewed' => 'Reviewed',
                'resolved' => 'Resolved',
            ];
            $statusLabel = $statusMap[$status] ?? 'Under review';
            $statusColor = 'background:#f59e0b;color:#1f2937;';
            if ($status === 'reviewed') { $statusColor = 'background:#dcfce7;color:#065f46;'; }
            elseif ($status === 'under_review') { $statusColor = 'background:#f59e0b;color:#1f2937;'; }
            elseif ($status === 'new' || $status === 'open') { $statusColor = 'background:#fee2e2;color:#b91c1c;'; }
        ?>
        <div style="display:flex;align-items:center;gap:10px;">
            <span style="padding:8px 12px;border-radius:999px;font-weight:700;<?php echo $statusColor; ?>"><?php echo e($statusLabel); ?></span>
        </div>
    </div>

    <!-- Suggestion Details -->
    <div class="card" style="margin-bottom:18px;">
        <div style="font-weight:700;margin-bottom:8px;color:#111827;">Suggestion Details</div>
        <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;">
            <div><div style="font-size:12px;color:#6b7280;">Submitted By</div><div style="margin-top:6px;font-weight:600;"><?php echo e(trim(((int)($suggestion['is_anonymous'] ?? 0) === 1) ? 'Anonymous Student' : trim((string)($suggestion['first_name'] ?? '') . ' ' . (string)($suggestion['last_name'] ?? '')))); ?></div></div>
            <div><div style="font-size:12px;color:#6b7280;">Category</div><div style="margin-top:6px;"><?php echo e((string)($suggestion['category_name'] ?? '')); ?></div></div>
            <div><div style="font-size:12px;color:#6b7280;">Date of Suggestion</div><div style="margin-top:6px;"><?php echo e(!empty($suggestion['date_of_suggestion']) ? date('M d, Y', strtotime((string)$suggestion['date_of_suggestion'])) : 'N/A'); ?></div></div>
            <div><div style="font-size:12px;color:#6b7280;">Terms of Agreement</div><div style="margin-top:6px;"><?php echo e(array_key_exists('terms_agreement_accepted', $suggestion) ? (((int)$suggestion['terms_agreement_accepted'] === 1) ? 'Yes' : 'No') : 'Unknown'); ?></div></div>
            <div class="full" style="grid-column:1 / -1;"><div style="font-size:12px;color:#6b7280;">Subject / Idea</div><div style="margin-top:6px;"><?php echo e((string)($suggestion['subject'] ?? '')); ?></div></div>
            <div class="full" style="grid-column:1 / -1;"><div style="font-size:12px;color:#6b7280;">Detailed Suggestion</div><div style="margin-top:6px;white-space:pre-wrap;"><?php echo nl2br(e((string)($suggestion['description'] ?? ''))); ?></div></div>
        </div>

        <?php if (!empty($suggestion['attachment'])): ?>
            <?php $att = (string)$suggestion['attachment']; $attPath = '../' . ltrim($att, '/'); $ext = strtolower(pathinfo($att, PATHINFO_EXTENSION)); $isImage = in_array($ext, ['jpg','jpeg','png','gif'], true); ?>
            <div style="margin-top:12px;">
                <div style="font-size:12px;color:#6b7280;margin-bottom:6px;">Attachments / References</div>
                <?php if ($isImage): ?>
                    <a href="<?php echo e($attPath); ?>" target="_blank"><img src="<?php echo e($attPath); ?>" alt="attachment" style="max-width:360px;border-radius:8px;border:1px solid #eef2ff;"></a>
                <?php else: ?>
                    <a href="<?php echo e($attPath); ?>" target="_blank" class="btn" style="display:inline-block;padding:8px 12px;border-radius:8px;">Download attachment</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div style="margin-top:12px;">
                <div style="font-size:12px;color:#6b7280;margin-bottom:6px;">Attachments / References</div>
                <div style="margin-top:6px;color:#374151;">No attachment uploaded.</div>
            </div>
        <?php endif; ?>

        <div style="margin-top:12px;">
            <div style="font-size:12px;color:#6b7280;margin-bottom:6px;">Expected Outcome & Benefits</div>
            <div style="margin-top:6px;white-space:pre-wrap;"><?php echo nl2br(e((string)($suggestion['expected_outcome'] ?? 'No expected outcome provided.'))); ?></div>
        </div>
    </div>

    <!-- Status Update & Remarks Form -->
    <div class="card status-remarks-card" style="margin-bottom:18px;">
        <div style="font-weight:700;margin-bottom:12px;color:#111827;">Update Status & Official Remarks</div>

        <form method="POST">
            <input type="hidden" name="action" value="update_suggestion">
            <input type="hidden" name="suggestion_id" value="<?php echo (int)$suggestionId; ?>">
            <input type="hidden" name="csrf_token" value="<?php echo e((string)($_SESSION['csrf_token'] ?? '')); ?>">
            <?php if (!empty($adminRemark)): ?>
                <input type="hidden" name="remark_id" value="<?php echo (int)$adminRemark['id']; ?>">
            <?php endif; ?>

            <div class="form-group">
                <label for="statusSelect">Suggestion Status</label>
                <select id="statusSelect" name="status" class="form-control">
                    <option value="under_review" <?php echo ((string)$suggestion['status'] === 'under_review') ? 'selected' : ''; ?>>Under Review</option>
                    <option value="reviewed" <?php echo ((string)$suggestion['status'] === 'reviewed') ? 'selected' : ''; ?>>Reviewed</option>
                </select>
            </div>

            <?php if (!empty($adminRemark)): ?>
                <div id="remarkDisplay" style="margin-bottom:12px;">
                    <div style="font-weight:700;margin-bottom:6px;color:#111827;font-size:14px;">Official Remarks (Visible to Student)</div>
                    <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:12px;margin-bottom:12px;color:#374151;line-height:1.6;">
                        <?php echo nl2br(e((string)$adminRemark['message'])); ?>
                    </div>
                    <div style="font-size:12px;color:#6b7280;margin-bottom:12px;">Added: <?php echo e(date('M d, Y h:i A', strtotime((string)$adminRemark['created_at']))); ?></div>
                    <button type="button" onclick="toggleRemarkEdit()" class="btn" style="background:#6b46c1;color:#fff;border:none;">
                        <i class='bx bx-edit-alt' style="margin-right:4px;"></i> Edit Remarks
                    </button>
                </div>

                <div id="remarkFormWrapper" style="display:none;margin-bottom:12px;">
                    <div class="form-group">
                        <label for="remarkTextarea">Edit Official Remarks (Visible to Student)</label>
                        <textarea id="remarkTextarea" name="remark" class="form-control" placeholder="Type your response or next steps here..." required><?php echo e((string)$adminRemark['message']); ?></textarea>
                    </div>
                    <div class="status-remarks-actions">
                        <button type="submit" class="btn">Save Changes</button>
                        <button type="button" onclick="toggleRemarkEdit()" class="btn" style="background:#6b7280;">Cancel</button>
                    </div>
                </div>
            <?php else: ?>
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

    <script>
    function toggleRemarkEdit() {
        const display = document.getElementById('remarkDisplay');
        const form = document.getElementById('remarkFormWrapper');
        if (display && form) {
            display.style.display = display.style.display === 'none' ? 'block' : 'none';
            form.style.display = form.style.display === 'none' ? 'block' : 'none';
        }
    }

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
        const status = document.getElementById('statusSelect');
        const remark = document.getElementById('remarkTextarea');
        if (status && remark) {
            function toggleRequired() { remark.required = status.value === 'reviewed'; }
            status.addEventListener('change', toggleRequired);
            toggleRequired();
        }

        const replyForms = document.querySelectorAll('.admin-feedback-reply-form');
        replyForms.forEach(function (form) {
            form.addEventListener('submit', submitAdminFeedbackReply);
        });
    });
    </script>

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

        $currentStatus = strtolower((string)($suggestion['status'] ?? ''));
        $threadResolved = in_array($currentStatus, ['reviewed'], true);
        $canReply = !$threadResolved;
        $feedbackId = !empty($feedback['id']) ? (int)$feedback['id'] : '';
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
                                    $meta = feedback_option_meta((string)$feedback['satisfaction']);
                                    $studentInfo = get_person_display($pdo, 'student', (int)$suggestion['student_id']);
                                    $studentName = $studentInfo['name'] ?? 'Student';
                                ?>
                                <div class="feedback-summary-card">
                                    <div class="feedback-head">
                                        <div>
                                            <div class="feedback-title"><?php echo e($studentName); ?></div>
                                            <div class="feedback-subtext">Submitted feedback for this response</div>
                                        </div>
                                        <span class="pill <?php echo e((string)$feedback['satisfaction']); ?>">
                                            <i class='bx <?php echo e($meta['icon']); ?>'></i>
                                            <?php echo e($meta['label']); ?>
                                        </span>
                                    </div>
                                    <div class="timeline-text" style="display:block;width:100%;font-size:13px;color:#374151;white-space:pre-line;text-align:left;word-break:break-word;margin:0;padding:0;line-height:1.6;">
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
                                        $replyIsCurrentUser = $replyRoleRaw === 'admin';
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
                            <div class="reply-box-card reply-form-wrapper">
                                <form id="feedbackReplyForm" class="admin-feedback-reply-form" method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo e((string)($_SESSION['csrf_token'] ?? '')); ?>">
                                    <input type="hidden" name="ticket_type" value="suggestion">
                                    <input type="hidden" name="ticket_id" value="<?php echo (int)$suggestionId; ?>">
                                    <input type="hidden" name="student_id" value="<?php echo (int)$suggestion['student_id']; ?>">
                                    <input type="hidden" name="feedback_history_id" value="<?php echo $feedbackId; ?>">
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
                        <div class="ticket-section">
                            <div style="margin-top:16px;color:#111827;font-size:13px;line-height:1.5;">This suggestion has been reviewed. No further replies can be sent.</div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>
</body>
</html>
