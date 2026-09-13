<?php
session_start();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../ticket_flow.php';
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
    $status = strtolower(trim($status));
    if ($status === 'new' || $status === 'open') {
        return 'Open';
    }
    if ($status === 'under_review') {
        return 'Under Review';
    }
    if ($status === 'resolved') {
        return 'Resolved';
    }
    return ucfirst(str_replace('_', ' ', $status));
}

$flashMessage = '';
$flashType = 'info';
$complaintId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($complaintId <= 0) {
    $flashMessage = 'Complaint ID is required.';
    $flashType = 'error';
}

$complaint = null;
$replies = [];
$feedback = null;
$feedbackReplies = [];
$callSlipHistory = [];

try {
    if ($complaintId > 0) {
        $stmt = $pdo->prepare(
            'SELECT c.*, cc.name AS category_name, sp.first_name, sp.last_name
             FROM complaints c
             LEFT JOIN complaint_categories cc ON cc.id = c.category_id
             LEFT JOIN student_profiles sp ON sp.id = c.student_id
             WHERE c.id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $complaintId]);
        $complaint = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$complaint) {
            $flashMessage = 'Complaint not found.';
            $flashType = 'error';
        } else {
            // Full conversation, not just the first remark - so this view
            // shows the whole exchange, same as the manage-complaint page.
            $replyStmt = $pdo->prepare(
                'SELECT id, sender_id, sender_role, message, created_at
                 FROM ticket_replies
                 WHERE ticket_type = "complaint" AND ticket_id = :id
                 ORDER BY created_at ASC, id ASC'
            );
            $replyStmt->execute([':id' => $complaintId]);
            $replies = $replyStmt->fetchAll(PDO::FETCH_ASSOC);

            $feedback = get_ticket_feedback($pdo, 'complaint', $complaintId, (int)$complaint['student_id']);
            $feedbackReplies = get_ticket_feedback_replies($pdo, 'complaint', $complaintId, (int)$complaint['student_id']);

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
                $callSlipHistoryStmt->execute([':ticket_id' => $complaintId]);
                $callSlipHistory = $callSlipHistoryStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                $callSlipHistory = [];
            }
        }
    }
} catch (PDOException $e) {
    $flashMessage = 'Unable to load complaint details.';
    $flashType = 'error';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>View Complaint - Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
body { background: #f4f6fb; }
.main { margin-left: 260px; margin-top: 61px; padding: 25px; min-height: calc(100vh - 61px); }
.main .card, .main .ticket-header, .main .timeline { max-width: 980px; margin: 0 auto; }
.complaint-document-layout { max-width: 1180px; margin: 0 auto 18px; display: grid; grid-template-columns: minmax(0, 2fr) minmax(280px, 1fr); gap: 18px; align-items: start; }
.complaint-document, .call-slip-history-card { max-width: none !important; margin: 0 !important; }
.response-timeline-card {
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
.status-pill { display:inline-flex; align-items:center; gap:8px; padding:8px 12px; border-radius:999px; font-weight:700; flex-shrink: 0; }
.status-pill.open { background:#e0f2fe;color:#0369a1; }
.status-pill.under_review { background:#fef3c7;color:#92400e; }
.status-pill.resolved { background:#dcfce7;color:#065f46; }
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
    .response-timeline-card {
        max-width: 100% !important;
        width: 100%;
        margin-left: auto !important;
    }
}
.card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 14px; box-shadow: 0 6px 18px rgba(15,23,42,0.04); }
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
.pill { display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:999px;font-weight:700;font-size:13px; }
.pill.very_satisfied { background:#fef3c7;color:#92400e; }
.pill.satisfied { background:#dcfce7;color:#065f46; }
.pill.neutral { background:#f3f4f6;color:#374151; }
.pill.not_satisfied { background:#ffedd5;color:#9a3412; }
.pill.very_unsatisfied { background:#fee2e2;color:#b91c1c; }
.pill.small { padding:4px 8px; font-size:11px; }
.empty-message, .empty-card { background:#f9fafb;border:1px dashed #d1d5db;border-radius:12px;padding:14px;color:#475569; }
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

    <?php if ($complaint): ?>
        <?php
            $status = strtolower(trim((string)($complaint['status'] ?? '')));
            $statusLabel = status_label($status);
            $statusClass = $status === 'resolved' ? 'resolved' : ($status === 'under_review' ? 'under_review' : 'open');
        ?>

    <div class="complaint-document-layout">
    <div class="complaint-document card">
        <div class="document-heading">
            <div class="document-heading-left">
                <a href="admin_complaints.php?tab=view_only" id="recordBackBtn" class="document-back-btn" aria-label="Back to Complaints" title="Back to Complaints">
                    <i class='bx bx-arrow-back'></i>
                </a>
                <div>
                    <div class="document-kicker">Official Complaint Record</div>
                    <div class="document-submitted">Submitted <?php echo e(!empty($complaint['created_at']) ? date('M d, Y h:i A', strtotime((string)$complaint['created_at'])) : ''); ?></div>
                </div>
            </div>
            <div class="status-pill <?php echo e($statusClass); ?>"><?php echo e($statusLabel); ?></div>
        </div>

        <!-- Complaint Details -->
        <div class="document-section">
            <div class="document-section-title">
                <div class="section-label">Complainant & Incident Details</div>
            </div>
            <div class="grid">
                <div class="detail-item">
                    <div class="label">Complainant</div>
                    <div class="detail-value"><?php echo e((string)($complaint['complainant_name'] ?? 'N/A')); ?></div>
                </div>
                <div class="detail-item">
                    <div class="label">Contact details</div>
                    <div class="detail-value"><?php echo e((string)($complaint['complainant_contact_details'] ?? 'N/A')); ?></div>
                </div>
                <div class="detail-item">
                    <div class="label">Date / Time of Incident</div>
                    <div class="detail-value"><?php echo e((string)($complaint['date_of_incident'] ?? 'N/A')); ?> <?php echo e((string)($complaint['time_of_incident'] ?? '')); ?></div>
                </div>
                <div class="detail-item">
                    <div class="label">Place of Incident</div>
                    <div class="detail-value"><?php echo e((string)($complaint['place_of_incident'] ?? 'N/A')); ?></div>
                </div>
                <div class="detail-item full" style="grid-column:1 / -1;">
                    <div class="label">Person Complained Of</div>
                    <div class="detail-value"><?php echo e((string)($complaint['person_complained_of'] ?? 'N/A')); ?></div>
                </div>
                <div class="detail-item full" style="grid-column:1 / -1;">
                    <div class="label">Act Complained Of</div>
                    <div class="detail-value detail-value--paragraph"><?php echo nl2br(e((string)($complaint['act_complained_of'] ?? 'N/A'))); ?></div>
                </div>
            </div>

            <?php if (!empty($complaint['attachments'])): ?>
                <?php $attachment = '../' . ltrim((string)$complaint['attachments'], '/'); $attachmentExt = strtolower(pathinfo((string)$complaint['attachments'], PATHINFO_EXTENSION)); $isImage = in_array($attachmentExt, ['jpg','jpeg','png','webp','gif'], true); ?>
                <div class="section-block">
                    <div class="label">Proof of Complaint</div>
                    <div class="attachments-grid">
                        <?php if ($isImage): ?>
                            <a href="<?php echo e($attachment); ?>" target="_blank" class="attachment-thumb"><img src="<?php echo e($attachment); ?>" alt="attachment"></a>
                        <?php else: ?>
                            <a href="<?php echo e($attachment); ?>" target="_blank" class="attachment-thumb" style="padding:12px 16px;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;color:#2563eb;font-weight:600;min-width:160px;">
                                Open attachment
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($complaint['desired_outcome'])): ?>
        <div class="document-section">
            <div class="document-section-title"><div class="section-label">Expected Outcome</div></div>
            <div class="detail-value detail-value--paragraph"><?php echo nl2br(e((string)($complaint['desired_outcome'] ?? ''))); ?></div>
        </div>
        <?php endif; ?>

        <?php $termsAccepted = array_key_exists('terms_agreement_accepted', $complaint) ? (int)$complaint['terms_agreement_accepted'] : null; ?>
        <div class="document-section">
            <div class="document-section-title"><div class="section-label">Terms of Agreement</div></div>
            <?php if ($termsAccepted === 1): ?>
                <div class="detail-value detail-value--paragraph"><?php echo e(complaint_terms_agreement_statement()); ?> <?php echo e(complaint_terms_agreement_checkbox_label()); ?></div>
            <?php elseif ($termsAccepted === 0): ?>
                <div class="detail-value">Not agreed</div>
            <?php else: ?>
                <div class="detail-value">Unknown</div>
            <?php endif; ?>
        </div>
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
                        $studentInfo = get_person_display($pdo, 'student', (int)($complaint['student_id'] ?? 0));
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
    // Back arrow: prefer returning to the exact complaints-list page the
    // admin came from (same filters/search/pagination/scroll position, via
    // normal browser back-navigation) instead of a fresh navigation that
    // would reset it back to the top of the view-only tab.
    var backBtn = document.getElementById('recordBackBtn');
    if (backBtn) {
        backBtn.addEventListener('click', function (event) {
            var cameFromList = document.referrer && document.referrer.indexOf(window.location.origin) === 0;
            if (cameFromList && window.history.length > 1) {
                event.preventDefault();
                window.history.back();
            }
            // Otherwise let the plain href navigate to admin_complaints.php?tab=view_only
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
