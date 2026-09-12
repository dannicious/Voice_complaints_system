<?php
session_start();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../ticket_flow.php';
require_once __DIR__ . '/../suggestion_flow.php';
require_once __DIR__ . '/../response_timeline_ui.php';

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

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

function require_admin_session(PDO $pdo): void
{
    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    $role = strtolower(trim((string)($_SESSION['role'] ?? '')));
    if ($userId <= 0 || $role !== 'admin') {
        header('Location: ../login.php?error=' . urlencode('Please log in as admin.'));
        exit;
    }
}

require_admin_session($pdo);

function status_label(string $status): string
{
    return suggestion_status_meta($status)['label'];
}

$flashMessage = '';
$flashType = 'info';
$suggestionId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($suggestionId <= 0) {
    $flashMessage = 'Suggestion ID is required.';
    $flashType = 'error';
}

$suggestion = null;
$replies = [];
$feedback = null;
$feedbackReplies = [];

try {
    if ($suggestionId > 0) {
        $stmt = $pdo->prepare(
            'SELECT s.*, sc.name AS category_name, sp.first_name, sp.last_name, col.code AS college_code, col.name AS college_name
             FROM suggestions s
             LEFT JOIN suggestion_categories sc ON sc.id = s.category_id
             LEFT JOIN student_profiles sp ON sp.id = s.student_id
             LEFT JOIN colleges col ON col.id = s.college_id
             WHERE s.id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $suggestionId]);
        $suggestion = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$suggestion) {
            $flashMessage = 'Suggestion not found.';
            $flashType = 'error';
        } else {
            // Full conversation, not just the first remark - so this view
            // shows the whole exchange, same as the manage-suggestion page.
            $replyStmt = $pdo->prepare(
                'SELECT id, sender_id, sender_role, message, created_at
                 FROM ticket_replies
                 WHERE ticket_type = "suggestion" AND ticket_id = :id
                 ORDER BY created_at ASC, id ASC'
            );
            $replyStmt->execute([':id' => $suggestionId]);
            $replies = $replyStmt->fetchAll(PDO::FETCH_ASSOC);

            $feedback = get_ticket_feedback($pdo, 'suggestion', $suggestionId, (int)$suggestion['student_id']);
            $feedbackReplies = get_ticket_feedback_replies($pdo, 'suggestion', $suggestionId, (int)$suggestion['student_id']);
        }
    }
} catch (PDOException $e) {
    $flashMessage = 'Unable to load suggestion details.';
    $flashType = 'error';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>View Suggestion - Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
body { background: #f4f6fb; }
.main { margin-left: 260px; margin-top: 61px; padding: 25px; min-height: calc(100vh - 61px); }
.main .card, .main .ticket-header, .main .timeline { max-width: 980px; margin: 0 auto; }
.card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 14px; box-shadow: 0 6px 18px rgba(15,23,42,0.04); }
.ticket-header { display:flex; justify-content:space-between; align-items:center; gap:16px; margin-bottom:16px; }
.ticket-header div { min-width: 0; }
.ticket-header .muted { color:#6b7280; font-size:13px; }
.ticket-header-left { display:flex; align-items:center; gap:10px; min-width:0; }
.document-back-btn { display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 8px; border: 1px solid #d1d5db; background: #fff; color: #374151; text-decoration: none; flex-shrink: 0; transition: border-color .15s ease, background .15s ease, color .15s ease; }
.document-back-btn:hover { background: #f3f4f6; border-color: #a5b4fc; color: #111827; }
.document-back-btn i { font-size: 18px; }
.status-pill { display:inline-flex; align-items:center; gap:8px; padding:8px 12px; border-radius:999px; font-weight:700; }
.status-pill.new { background:#e0f2fe;color:#0369a1; }
.status-pill.under_review { background:#fef3c7;color:#92400e; }
.status-pill.reviewed { background:#dcfce7;color:#065f46; }
.status-pill.approved { background:#d1fae5;color:#047857; }
.status-pill.rejected, .status-pill.declined { background:#fee2e2;color:#b91c1c; }
.grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; }
.grid.full { grid-column:1 / -1; }
.detail-item { padding-bottom:18px; }
.detail-item:last-child { padding-bottom:0; }
.label { font-size:12px;color:#6b7280;margin-bottom:8px; text-transform:uppercase; letter-spacing:0.08em; }
.detail-value { color:#111827; font-size:15px; line-height:1.8; font-weight:500; }
.detail-value--paragraph { white-space:pre-wrap; }
.section-block { margin-top:24px; }
.attachments-grid { display:flex; flex-wrap:wrap; gap:12px; margin-top:12px; }
.attachment-thumb { border-radius:12px; overflow:hidden; box-shadow:0 8px 20px rgba(15,23,42,0.08); border:1px solid #e5e7eb; display:inline-block; }
.attachment-thumb img { display:block; width:160px; height:120px; object-fit:cover; }
.empty-message, .empty-card { background:#f9fafb;border:1px dashed #d1d5db;border-radius:12px;padding:14px;color:#475569; }
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
.pill.small { padding:4px 8px; font-size:11px; }
.pill.very_satisfied { background:#fef3c7; color:#92400e; }
.pill.satisfied { background:#dcfce7; color:#065f46; }
.pill.neutral { background:#f3f4f6; color:#374151; }
.pill.not_satisfied { background:#ffedd5; color:#9a3412; }
.pill.very_unsatisfied { background:#fee2e2; color:#b91c1c; }
.feedback-panel { border:1px solid #e5e7eb; border-radius:12px; padding:10px; background:#f9fafb; }
.feedback-summary-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:10px 12px; display:flex; flex-direction:column; gap:8px; box-shadow:0 2px 8px rgba(15,23,42,0.04); align-items:stretch; }
.feedback-summary-top { display:flex; align-items:center; gap:10px; width:100%; }
.feedback-avatar { width:40px; height:40px; border-radius:999px; display:inline-flex; align-items:center; justify-content:center; overflow:hidden; background:#6b46c1; color:#fff; font-weight:700; font-size:14px; border:1px solid #eef2ff; flex-shrink:0; }
.feedback-avatar img { width:100%; height:100%; object-fit:cover; }
.feedback-summary-info { flex:1; min-width:0; }
.feedback-title { font-weight:700;color:#111827; font-size:13px; line-height:1.3; }
.feedback-head { display:flex; justify-content:space-between; align-items:center; gap:8px; flex-wrap:wrap; }
.feedback-subtext { font-size:11.5px; color:#6b7280; line-height:1.3; }
.feedback-comment-bubble { display:inline-block; max-width:100%; background:#f8fafc; border:1px solid #eef2ff; border-radius:10px; padding:7px 10px; font-size:12px; color:#374151; line-height:1.5; white-space:pre-wrap; word-break:break-word; margin-left:50px; }
@media (max-width: 1024px) { .main { margin-left:0; } .grid { grid-template-columns:1fr; } }
.scroll-top-btn { position: fixed; right: 24px; bottom: 24px; width: 44px; height: 44px; border-radius: 999px; background: #6b46c1; color: #fff; border: none; display: none; align-items: center; justify-content: center; cursor: pointer; box-shadow: 0 10px 24px rgba(107, 70, 193, 0.35); z-index: 500; transition: background .15s ease, transform .15s ease, opacity .2s ease; opacity: 0; transform: translateY(8px); }
.scroll-top-btn.visible { display: flex; opacity: 1; transform: translateY(0); }
.scroll-top-btn:hover { background: #5b3aa8; }
.scroll-top-btn i { font-size: 22px; }
</style>
</head>
<body>
<?php include 'admin_topbar.php'; ?>
<?php include 'admin_sidebar.php'; ?>
<div class="main">
    <?php if ($flashMessage !== ''): ?>
        <div class="notice <?php echo e($flashType); ?>"><?php echo e($flashMessage); ?></div>
    <?php endif; ?>

    <?php if ($suggestion): ?>
        <?php
            $status = strtolower(trim((string)($suggestion['status'] ?? '')));
            $viewOnlyStatusMeta = suggestion_status_meta($status);
            $statusLabel = $viewOnlyStatusMeta['label'];
            $statusColorMap = [
                'status-review' => 'background:#f59e0b;color:#1f2937;',
                'status-needs-info' => 'background:#fde68a;color:#78350f;',
                'status-accepted' => 'background:#dbeafe;color:#1d4ed8;',
                'status-planned' => 'background:#e0e7ff;color:#3730a3;',
                'status-progress' => 'background:#ede9fe;color:#5b21b6;',
                'status-implemented' => 'background:#dcfce7;color:#065f46;',
                'status-declined' => 'background:#fee2e2;color:#b91c1c;',
            ];
            $statusColor = $statusColorMap[$viewOnlyStatusMeta['badge']] ?? 'background:#f3f4f6;color:#374151;';
        ?>
        <div class="ticket-header card">
            <div class="ticket-header-left">
                <a href="admin_suggestions.php?tab=view_only" id="recordBackBtn" class="document-back-btn" aria-label="Back to Suggestions" title="Back to Suggestions">
                    <i class='bx bx-arrow-back'></i>
                </a>
                <div>
                    <div style="font-weight:700;font-size:18px;">Suggestion Details</div>
                    <div class="muted">Category: <?php echo e((string)($suggestion['category_name'] ?? '')); ?> &middot; Submitted <?php echo e(!empty($suggestion['created_at']) ? date('M d, Y h:i A', strtotime((string)$suggestion['created_at'])) : ''); ?></div>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:10px;">
                <span class="status-pill" style="<?php echo $statusColor; ?>"><?php echo e($statusLabel); ?></span>
            </div>
        </div>

        <div class="card" style="margin-bottom:18px;">
            <div style="font-weight:700;margin-bottom:8px;color:#111827;">Suggestion Details</div>

            <?php
                $suggestionDate = '';
                if (!empty($suggestion['date_of_suggestion'])) {
                    $suggestionDate = date('M d, Y', strtotime((string)$suggestion['date_of_suggestion']));
                } elseif (!empty($suggestion['created_at'])) {
                    $suggestionDate = date('M d, Y', strtotime((string)$suggestion['created_at']));
                }
                $termsAgreement = 'Unknown';
                if (array_key_exists('terms_agreement_accepted', $suggestion)) {
                    $termsAgreement = ((int)$suggestion['terms_agreement_accepted'] === 1) ? 'Yes' : 'No';
                }
            ?>

            <div class="grid">
                <div class="detail-item">
                    <div class="label">Submitted By</div>
                    <div class="detail-value"><?php echo e(((int)$suggestion['is_anonymous'] === 1) ? 'Anonymous Student' : trim((string)($suggestion['first_name'] ?? '') . ' ' . (string)($suggestion['last_name'] ?? ''))); ?></div>
                </div>
                <div class="detail-item">
                    <div class="label">Category</div>
                    <div class="detail-value"><?php echo e((string)($suggestion['category_name'] ?? 'General')); ?></div>
                </div>
                <div class="detail-item">
                    <div class="label">Date of Suggestion</div>
                    <div class="detail-value"><?php echo e($suggestionDate !== '' ? $suggestionDate : 'N/A'); ?></div>
                </div>
                <div class="detail-item">
                    <div class="label">Terms of Agreement</div>
                    <div class="detail-value"><?php echo e($termsAgreement); ?></div>
                </div>
            </div>

            <div class="section-block">
                <div class="label">Subject / Idea</div>
                <div class="detail-value"><?php echo e((string)($suggestion['subject'] ?? '-')); ?></div>
            </div>

            <div class="section-block">
                <div class="label">Detailed Suggestion</div>
                <div class="detail-value detail-value--paragraph"><?php echo nl2br(e((string)($suggestion['description'] ?? '-'))); ?></div>
            </div>

            <div class="section-block">
                <div class="label">Attachments / References</div>
                <div class="attachments-grid">
                    <?php if (trim((string)$suggestion['attachment']) !== ''): ?>
                        <?php $attachment = '../' . ltrim((string)$suggestion['attachment'], '/'); $attachmentExt = strtolower(pathinfo((string)$suggestion['attachment'], PATHINFO_EXTENSION)); $isImage = in_array($attachmentExt, ['jpg','jpeg','png','webp','gif'], true); ?>
                        <?php if ($isImage): ?>
                            <a href="<?php echo e($attachment); ?>" target="_blank" class="attachment-thumb"><img src="<?php echo e($attachment); ?>" alt="attachment"></a>
                        <?php else: ?>
                            <a href="<?php echo e($attachment); ?>" target="_blank" style="color:#2563eb;text-decoration:none;font-weight:600;">Open attachment</a>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="detail-value">No attachment uploaded.</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="section-block">
                <div class="label">Expected Outcome & Benefits</div>
                <div class="detail-value detail-value--paragraph"><?php echo nl2br(e((string)($suggestion['expected_outcome'] ?? 'No expected outcome provided.'))); ?></div>
            </div>
        </div>

        <!-- Combined Response Timeline (read-only: official remark, replies, feedback - no reply box) -->
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

            $hasFeedback = !empty($feedback);
        ?>
        <?php if ($officialRemark || $hasFeedback || !empty($timelineReplies)): ?>
        <div class="card" style="padding:0;margin-bottom:18px;">
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
                                echo response_timeline_entry([
                                    'name' => $officialName,
                                    'role_label' => $officialRoleLabel,
                                    'is_current_user' => ($officialRole === 'admin'),
                                    'photo' => $officialPhoto,
                                    'time_text' => date('M d, Y h:i A', strtotime((string)$officialRemark['created_at'])),
                                    'message_html' => nl2br(e((string)$officialRemark['message'])),
                                    'reply_target_id' => null,
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
                                        echo response_timeline_entry([
                                            'name' => $replyName,
                                            'role_label' => $replyRoleLabel,
                                            'is_current_user' => ($replyRoleRaw === 'admin'),
                                            'photo' => $replyPhoto,
                                            'time_text' => date('M d, Y h:i A', strtotime((string)($replyItem['created_at'] ?? ''))),
                                            'message_html' => nl2br(e((string)($replyItem['message'] ?? ''))),
                                            'is_reply' => true,
                                            'reply_target_id' => null,
                                        ]);
                                    ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($hasFeedback): ?>
                    <div class="ticket-section">
                        <div class="section-title">Student Feedback</div>
                        <?php
                            $meta = feedback_option_meta((string)$feedback['satisfaction']);
                            $studentInfo = get_person_display($pdo, 'student', (int)$suggestion['student_id']);
                            $studentName = $studentInfo['name'] ?? 'Student';
                            $stuPhoto = !empty($studentInfo['photo']) ? ('../' . ltrim($studentInfo['photo'], '/')) : null;
                            $stuInitial = strtoupper(substr(trim($studentName), 0, 1)) ?: 'S';
                            $stuComment = trim((string)($feedback['comment'] ?? ''));
                        ?>
                        <div class="feedback-panel">
                            <div class="feedback-summary-card">
                                <div class="feedback-summary-top">
                                    <div class="feedback-avatar">
                                        <?php if ($stuPhoto): ?>
                                            <img src="<?php echo e($stuPhoto); ?>" alt="<?php echo e($studentName); ?>">
                                        <?php else: ?>
                                            <?php echo e($stuInitial); ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="feedback-summary-info">
                                        <div class="feedback-head">
                                            <div>
                                                <div class="feedback-title"><?php echo e($studentName); ?></div>
                                                <div class="feedback-subtext">Submitted feedback for this response</div>
                                            </div>
                                            <span class="pill small <?php echo e((string)$feedback['satisfaction']); ?>">
                                                <i class='bx <?php echo e($meta['icon']); ?>'></i>
                                                <?php echo e($meta['label']); ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <?php if ($stuComment !== ''): ?>
                                <div class="feedback-comment-bubble"><?php echo nl2br(e($stuComment)); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

    <?php endif; ?>
</div>

<?php echo response_timeline_script(); ?>
<button type="button" id="scrollTopBtn" class="scroll-top-btn" aria-label="Scroll to top" title="Back to top">
    <i class='bx bx-up-arrow-alt'></i>
</button>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var backBtn = document.getElementById('recordBackBtn');
    if (backBtn) {
        backBtn.addEventListener('click', function (event) {
            var cameFromList = document.referrer && document.referrer.indexOf(window.location.origin) === 0;
            if (cameFromList && window.history.length > 1) {
                event.preventDefault();
                window.history.back();
            }
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
