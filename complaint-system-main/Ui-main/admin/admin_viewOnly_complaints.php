<?php
session_start();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../ticket_flow.php';

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

$flashMessage = '';
$flashType = 'info';
$complaintId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($complaintId <= 0) {
    $flashMessage = 'Complaint ID is required.';
    $flashType = 'error';
}

$complaint = null;
$officialRemark = null;
$feedback = null;
$hasFeedback = false;

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
            $remarkStmt = $pdo->prepare(
                'SELECT id, sender_id, sender_role, message, created_at
                 FROM ticket_replies
                 WHERE ticket_type = "complaint" AND ticket_id = :id AND LOWER(sender_role) IN ("dean", "admin")
                 ORDER BY created_at ASC, id ASC
                 LIMIT 1'
            );
            $remarkStmt->execute([':id' => $complaintId]);
            $officialRemark = $remarkStmt->fetch(PDO::FETCH_ASSOC);

            $feedbackStmt = $pdo->prepare(
                'SELECT id, satisfaction, comment, created_at
                 FROM ticket_feedback
                 WHERE ticket_type = "complaint" AND ticket_id = :id
                 LIMIT 1'
            );
            $feedbackStmt->execute([':id' => $complaintId]);
            $feedback = $feedbackStmt->fetch(PDO::FETCH_ASSOC);
            $hasFeedback = !empty($feedback);
        }
    }
} catch (PDOException $e) {
    $flashMessage = 'Unable to load complaint details.';
    $flashType = 'error';
}

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

function feedback_badge_meta(string $satisfaction): array
{
    $satisfaction = strtolower(trim($satisfaction));
    if ($satisfaction === 'satisfied') {
        return ['label' => 'Satisfied', 'class' => 'satisfied', 'icon' => 'bx-check-circle'];
    }
    if ($satisfaction === 'neutral') {
        return ['label' => 'Neutral', 'class' => 'neutral', 'icon' => 'bx-minus-circle'];
    }
    if ($satisfaction === 'not_satisfied' || $satisfaction === 'not satisfied') {
        return ['label' => 'Not Satisfied', 'class' => 'not_satisfied', 'icon' => 'bx-x-circle'];
    }
    return ['label' => ucfirst($satisfaction), 'class' => 'neutral', 'icon' => 'bx-question-mark'];
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
.card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 14px; box-shadow: 0 6px 18px rgba(15,23,42,0.04); }
.ticket-header { display:flex; justify-content:space-between; align-items:center; gap:16px; margin-bottom:16px; }
.ticket-header div { min-width: 0; }
.ticket-header .muted { color:#6b7280; font-size:13px; }
.status-pill { display:inline-flex; align-items:center; gap:8px; padding:8px 12px; border-radius:999px; font-weight:700; }
.status-pill.open { background:#e0f2fe;color:#0369a1; }
.status-pill.under_review { background:#fef3c7;color:#92400e; }
.status-pill.resolved { background:#dcfce7;color:#065f46; }
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
.feedback-section { border:1px solid #e5e7eb;border-radius:14px;padding:20px;background:#f9fafb; }
.feedback-header { display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;margin-bottom:16px; }
.feedback-title { font-weight:700;color:#111827; }
.feedback-card { background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:18px; }
.pill { display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:999px;font-weight:700;font-size:13px; }
.pill.satisfied { background:#dcfce7;color:#065f46; }
.pill.neutral { background:#f3f4f6;color:#374151; }
.pill.not_satisfied { background:#fee2e2;color:#b91c1c; }
.empty-message, .empty-card { background:#f9fafb;border:1px dashed #d1d5db;border-radius:12px;padding:14px;color:#475569; }
.feedback-panel { border:1px solid #e5e7eb; border-radius:14px; padding:16px; background:#f9fafb; }
.feedback-summary-card { background:#fff; border:1px solid #e5e7eb; border-radius:14px; padding:18px; display:flex; flex-direction:column; gap:12px; box-shadow:0 4px 14px rgba(15,23,42,0.04); align-items:flex-start; }
.feedback-head { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap; }
.feedback-subtext { font-size:13px; color:#6b7280; }
@media (max-width: 1024px) { .main { margin-left:0; } .grid { grid-template-columns:1fr; } }
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

        <div class="ticket-header card">
            <div>
                <div style="font-weight:700;font-size:18px;">Complaint <?php echo e((string)($complaint['ticket_no'] ?? '')); ?></div>
                <div class="muted">Category: <?php echo e((string)($complaint['category_name'] ?? '')); ?> · Submitted <?php echo e(!empty($complaint['created_at']) ? date('M d, Y h:i A', strtotime((string)$complaint['created_at'])) : ''); ?></div>
            </div>
            <div class="status-pill <?php echo e($statusClass); ?>"><?php echo e($statusLabel); ?></div>
        </div>

        <div class="card" style="margin-bottom:18px;">
            <div style="font-weight:700;margin-bottom:8px;color:#111827;">Complainant & Incident Details</div>
            <div class="grid" style="margin-top:14px;">
                <div class="detail-item">
                    <div class="label">Complainant</div>
                    <div class="detail-value"><?php echo e((string)($complaint['complainant_name'] ?? 'N/A')); ?></div>
                </div>
                <div class="detail-item">
                    <div class="label">Contact details</div>
                    <div class="detail-value"><?php echo e((string)($complaint['complainant_contact_details'] ?? 'N/A')); ?></div>
                </div>
                <div class="detail-item">
                    <div class="label">Address</div>
                    <div class="detail-value"><?php echo e((string)($complaint['complainant_address'] ?? 'N/A')); ?></div>
                </div>
                <div class="detail-item">
                    <div class="label">Category</div>
                    <div class="detail-value"><?php echo e((string)($complaint['category_name'] ?? 'N/A')); ?></div>
                </div>
                <div class="detail-item">
                    <div class="label">Sex / Age / Civil status</div>
                    <div class="detail-value"><?php echo e((string)($complaint['complainant_sex'] ?? 'N/A')); ?> · <?php echo e((string)($complaint['complainant_age'] ?? 'N/A')); ?> · <?php echo e((string)($complaint['complainant_civil_status'] ?? 'N/A')); ?></div>
                </div>
                <div class="detail-item">
                    <div class="label">Date / Time</div>
                    <div class="detail-value"><?php echo e((string)($complaint['date_of_incident'] ?? 'N/A')); ?> <?php echo e((string)($complaint['time_of_incident'] ?? '')); ?></div>
                </div>
                <div class="detail-item">
                    <div class="label">Place of Incident</div>
                    <div class="detail-value"><?php echo e((string)($complaint['place_of_incident'] ?? 'N/A')); ?></div>
                </div>
                <div class="detail-item full">
                    <div class="label">Person / Office Complained Of</div>
                    <div class="detail-value"><?php echo e((string)($complaint['person_complained_of'] ?? 'N/A')); ?></div>
                </div>
                <div class="detail-item full">
                    <div class="label">Act Complained Of</div>
                    <div class="detail-value detail-value--paragraph"><?php echo nl2br(e((string)($complaint['act_complained_of'] ?? 'N/A'))); ?></div>
                </div>
                <div class="detail-item full">
                    <div class="label">Narrative Report</div>
                    <div class="detail-value detail-value--paragraph"><?php echo nl2br(e((string)($complaint['narrative_report'] ?? 'N/A'))); ?></div>
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
            <?php else: ?>
                <div class="section-block">
                    <div class="empty-message">No attachment uploaded.</div>
                </div>
            <?php endif; ?>

            <?php if (!empty($complaint['desired_outcome'])): ?>
                <div class="section-block">
                    <div class="label">Desired Outcome</div>
                    <div class="detail-value detail-value--paragraph"><?php echo nl2br(e((string)($complaint['desired_outcome'] ?? ''))); ?></div>
                </div>
            <?php endif; ?>
        </div>

        <div class="card" style="margin-bottom:18px;">
            <div style="font-weight:700;margin-bottom:16px;color:#111827;">Official Remark</div>
            <?php if ($officialRemark): ?>
                <?php
                    $remarkRole = strtolower(trim((string)($officialRemark['sender_role'] ?? '')));
                    $remarkSenderId = isset($officialRemark['sender_id']) ? (int)$officialRemark['sender_id'] : 0;
                    $remarkPerson = $remarkSenderId > 0 ? get_person_display($pdo, $remarkRole, $remarkSenderId) : ['name' => ucfirst($remarkRole), 'photo' => null];
                    $remarkName = $remarkPerson['name'] ?? ucfirst($remarkRole);
                    $remarkPhoto = !empty($remarkPerson['photo']) ? ('../' . ltrim($remarkPerson['photo'], '/')) : null;
                    $remarkInitial = strtoupper(substr(trim($remarkName), 0, 1));
                    $remarkRoleLabel = $remarkRole === 'dean' ? 'College Dean' : ($remarkRole === 'admin' ? 'Administrator' : 'Student');
                ?>
                <div class="timeline-entry">
                    <div class="timeline-avatar">
                        <?php if ($remarkPhoto): ?>
                            <img src="<?php echo e($remarkPhoto); ?>" alt="<?php echo e($remarkName); ?>">
                        <?php else: ?>
                            <?php echo e($remarkInitial); ?>
                        <?php endif; ?>
                    </div>
                    <div class="timeline-body">
                        <div class="timeline-heading">
                            <div class="timeline-name"><?php echo e($remarkName); ?></div>
                            <div class="timeline-role"><?php echo e($remarkRoleLabel); ?></div>
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

        <div class="card feedback-section">
            <div class="feedback-header">
                <div class="feedback-title">Student Feedback</div>
                <?php if ($hasFeedback): ?>
                    <?php $badge = feedback_badge_meta((string)$feedback['satisfaction']); ?>
                    <span class="pill <?php echo e($badge['class']); ?>"><i class="bx <?php echo e($badge['icon']); ?>"></i><?php echo e($badge['label']); ?></span>
                <?php endif; ?>
            </div>
            <?php if ($hasFeedback): ?>
                <?php
                    $studentInfo = get_person_display($pdo, 'student', (int)($complaint['student_id'] ?? 0));
                    $studentName = $studentInfo['name'] ?? 'Student';
                ?>
                <div class="feedback-panel">
                    <div class="feedback-summary-card">
                        <div class="feedback-head">
                            <div>
                                <div class="feedback-title"><?php echo e($studentName); ?></div>
                                <div class="feedback-subtext">Submitted feedback for this response</div>
                            </div>
                            <span class="pill <?php echo e($badge['class']); ?>">
                                <i class="bx <?php echo e($badge['icon']); ?>"></i>
                                <?php echo e($badge['label']); ?>
                            </span>
                        </div>
                        <div class="timeline-text" style="display:block;width:100%;font-size:13px;color:#374151;white-space:pre-line;text-align:left;word-break:break-word;margin:0;padding:0;line-height:1.6;">
                            <?php echo nl2br(e((string)($feedback['comment'] ?? ''))); ?>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="empty-card">No student rating or feedback has been recorded for this complaint.</div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
