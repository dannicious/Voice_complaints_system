<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../ticket_flow.php';
require_once __DIR__ . '/../suggestion_flow.php';
require_once __DIR__ . '/../response_timeline_ui.php';
ensure_role('staff');

function e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }

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

            if ($role === 'staff') {
                $stmt = $pdo->prepare('SELECT sp.name, u.profile_pic FROM staff_profiles sp LEFT JOIN users u ON u.id = sp.user_id WHERE sp.id = :id LIMIT 1');
                $stmt->execute([':id' => (int)$id]);
                $r = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($r) {
                    return ['name' => $r['name'] ?? 'Staff', 'photo' => $r['profile_pic'] ?? null];
                }
            }

            if ($role === 'dean') {
                $stmt = $pdo->prepare('SELECT dp.first_name, dp.last_name, dp.profile_photo, u.profile_pic FROM dean_profiles dp LEFT JOIN users u ON u.id = dp.user_id WHERE dp.id = :id LIMIT 1');
                $stmt->execute([':id' => (int)$id]);
                $r = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($r) {
                    $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: 'Dean';
                    return ['name' => $name, 'photo' => $r['profile_photo'] ?? $r['profile_pic'] ?? null];
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

function staff_profile(PDO $pdo): array
{
    $stmt = $pdo->prepare("SELECT id, office, name FROM staff_profiles WHERE user_id = :user_id AND status = 'active' LIMIT 1");
    $stmt->execute([':user_id' => (int)$_SESSION['user_id']]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}
$profile = staff_profile($pdo);
$office = trim((string)($profile['office'] ?? ''));
$staffProfileId = (int)($profile['id'] ?? 0);
if ($office === '') { http_response_code(403); exit('Staff office is not configured.'); }
$suggestionId = (int)($_GET['id'] ?? $_POST['suggestion_id'] ?? 0);
if ($suggestionId <= 0) { http_response_code(400); exit('Invalid suggestion.'); }
if (!isset($_SESSION['staff_csrf'])) $_SESSION['staff_csrf'] = bin2hex(random_bytes(32));
$flashMessage = '';
$flashType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['staff_csrf'], (string)($_POST['csrf_token'] ?? ''))) {
        $flashMessage = 'Invalid request token. Refresh and try again.';
        $flashType = 'error';
    } else {
        $status = trim((string)($_POST['status'] ?? ''));
        $remarks = trim((string)($_POST['remarks'] ?? ''));
        $verify = $pdo->prepare("SELECT s.id, s.status AS current_status, sp.user_id AS student_user_id FROM suggestions s LEFT JOIN student_profiles sp ON sp.id = s.student_id WHERE s.id = :id AND LOWER(TRIM(s.office)) = LOWER(TRIM(:office)) LIMIT 1");
        $verify->execute([':id' => $suggestionId, ':office' => $office]);
        $row = $verify->fetch(PDO::FETCH_ASSOC);
        $currentStatus = (string)($row['current_status'] ?? '');
        $allowedNext = suggestion_allowed_next_statuses($currentStatus);
        $isFinalizingStep = in_array($status, ['accepted', 'not_feasible', 'implemented'], true);

        if (!$row) {
            $flashMessage = 'Suggestion not found in your office scope.';
            $flashType = 'error';
        } elseif (suggestion_thread_is_locked($currentStatus)) {
            $flashMessage = 'This suggestion is already closed and can no longer be updated.';
            $flashType = 'error';
        } elseif (!array_key_exists($status, $allowedNext) || $remarks === '') {
            $flashMessage = 'Choose an allowed status and provide an official response.';
            $flashType = 'error';
        } elseif ($isFinalizingStep && $remarks === '') {
            $flashMessage = 'Please provide an official response before finalizing this status.';
            $flashType = 'error';
        } else {
            try {
                set_suggestion_status($pdo, $suggestionId, $status);
                save_ticket_reply($pdo, 'suggestion', $suggestionId, (int)$_SESSION['user_id'], 'staff', $remarks);
                $studentUserId = (int)($row['student_user_id'] ?? 0);
                if ($studentUserId > 0) {
                    $notify = $pdo->prepare('INSERT INTO notifications (user_id, type, message, ticket_type, ticket_id, is_read) VALUES (:user_id, :type, :message, :ticket_type, :ticket_id, 0)');
                    $notify->execute([':user_id' => $studentUserId, ':type' => 'suggestion_update', ':message' => 'Your suggestion has a new Staff response. Current status: ' . suggestion_status_meta($status)['label'] . '.', ':ticket_type' => 'suggestion', ':ticket_id' => $suggestionId]);
                }
                header('Location: suggestion_detail.php?id=' . $suggestionId);
                exit;
            } catch (Throwable $exception) {
                $flashMessage = 'Unable to save the suggestion response.';
                $flashType = 'error';
            }
        }
    }
}

if (isset($_GET['notification_id'])) {
    $read = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :user_id');
    $read->execute([':id' => (int)$_GET['notification_id'], ':user_id' => (int)$_SESSION['user_id']]);
}

$stmt = $pdo->prepare("SELECT s.*, sc.name AS category_name, sp.first_name, sp.last_name, sp.student_number FROM suggestions s LEFT JOIN suggestion_categories sc ON sc.id = s.category_id LEFT JOIN student_profiles sp ON sp.id = s.student_id WHERE s.id = :id AND LOWER(TRIM(s.office)) = LOWER(TRIM(:office)) LIMIT 1");
$stmt->execute([':id' => $suggestionId, ':office' => $office]);
$suggestion = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$suggestion) { http_response_code(404); exit('Suggestion not found.'); }
$suggestion['ticket_no'] = '';

$replies = [];
$staffRemark = null;
$historyStmt = $pdo->prepare("SELECT id, sender_id, sender_role, message, created_at FROM ticket_replies WHERE ticket_type = 'suggestion' AND ticket_id = :ticket_id ORDER BY created_at ASC, id ASC");
$historyStmt->execute([':ticket_id' => $suggestionId]);
$replies = $historyStmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($replies as $rep) {
    if ((int)($rep['sender_id'] ?? 0) === $staffProfileId && strtolower((string)($rep['sender_role'] ?? '')) === 'staff') {
        $staffRemark = $rep;
        break;
    }
}

$status = strtolower(trim((string)($suggestion['status'] ?? '')));
$statusMeta = suggestion_status_meta($status);
$statusLabel = $statusMeta['label'];
$suggestionAllowedNext = suggestion_allowed_next_statuses((string)($suggestion['status'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Suggestion Details - VOICE</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
body { background: #f4f6fb; }
.main { margin-left: 260px; margin-top: 61px; padding: 25px; min-height: calc(100vh - 61px); }
.main .card, .main .ticket-header, .main .timeline { max-width: 980px; margin: 0 auto; }
.card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 14px; box-shadow: 0 6px 18px rgba(15,23,42,0.04); }
.notice { max-width: 980px; margin: 0 auto 16px; padding: 10px 14px; border-radius: 8px; font-size: 13px; }
.notice.success { background: #e8f9f0; color: #166534; }
.notice.error { background: #fff1f1; color: #b42318; }

/* Record header, Action dropdown, status modal, and field label/value
   typography matched to admin_suggestion_detail.php / dean_suggestion_detail.php,
   so a suggestion record looks and behaves the same on every side. */
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
.label { font-size: 12px; color: #6b7280; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.08em; }
.detail-value { color: #111827; font-size: 15px; line-height: 1.8; font-weight: 500; }
.detail-value--paragraph { white-space: pre-wrap; }

.status-remarks-card { display:flex; flex-direction:column; gap:20px; }
.status-remarks-card form { display:flex; flex-direction:column; gap:20px; }
.status-remarks-card .form-group { margin-bottom:0; }
.status-remarks-card .form-group label { display:block; font-size:14px; font-weight:700; color:#111827; margin-bottom:10px; }
.status-remarks-card .form-control { width:100%; max-width:100%; min-width:0; padding:12px 14px; border:1px solid #d1d5db; border-radius:12px; font-size:14px; font-family:'Poppins', sans-serif; color:#111827; background:#ffffff; outline:none; transition:border-color 0.2s ease, box-shadow 0.2s ease; }
.status-remarks-card .form-control:focus { border-color:#4F8CFF; box-shadow: inset 0 1px 2px rgba(15, 23, 42, 0.08); }
.status-remarks-card select.form-control { appearance: none; -webkit-appearance: none; -moz-appearance: none; background-image: linear-gradient(45deg, transparent 50%, #6b7280 50%), linear-gradient(135deg, #6b7280 50%, transparent 50%); background-position: calc(100% - 18px) 18px, calc(100% - 13px) 18px; background-size: 6px 6px, 6px 6px; background-repeat: no-repeat; }
.status-remarks-card textarea.form-control { resize:vertical; min-height:150px; line-height:1.7; }
.status-remarks-actions { display:flex; justify-content:flex-end; gap:12px; flex-wrap:wrap; margin-top:10px; }
.btn { background: #4f8cff; color: #fff; border: none; border-radius: 10px; padding: 11px 16px; cursor: pointer; font-weight: 600; font-family: 'Poppins', sans-serif; }

.timeline-shell { display:flex; flex-direction:column; gap:18px; }
.ticket-section { display:flex; flex-direction:column; gap:12px; }
.section-title { font-size:14px; font-weight:700; color:#111827; letter-spacing:0.01em; }
.timeline-replies { display:flex; flex-direction:column; gap:14px; }
.empty-card { background:#f9fafb; border:1px dashed #d1d5db; border-radius:12px; padding:14px; color:#6b7280; font-size:13px; }
<?php echo response_timeline_styles(); ?>

@media(max-width:1024px){.main{margin-left:0;padding:16px}}
</style>
</head>
<body>
<?php include __DIR__ . '/staff_topbar.php'; ?>
<?php include __DIR__ . '/staff_sidebar.php'; ?>
<div class="main">
    <?php if ($flashMessage !== ''): ?>
        <div class="notice <?php echo e($flashType); ?>"><?php echo e($flashMessage); ?></div>
    <?php endif; ?>

    <div class="complaint-document card" style="margin-bottom:18px;">
        <div class="document-heading">
            <div class="document-heading-left">
                <a href="suggestions.php" id="recordBackBtn" class="document-back-btn" aria-label="Back to Suggestions" title="Back to Suggestions">
                    <i class='bx bx-arrow-back'></i>
                </a>
                <div>
                    <div class="document-kicker">Official Suggestion Record</div>
                    <div class="document-submitted">Submitted <?php echo e(!empty($suggestion['created_at']) ? date('M d, Y h:i A', strtotime((string)$suggestion['created_at'])) : ''); ?> &middot; Status: <?php echo e($statusLabel); ?></div>
                </div>
            </div>
            <?php if (!empty($suggestionAllowedNext)): ?>
            <div class="record-action-dropdown" id="recordActionDropdown">
                <button type="button" class="record-action-btn" id="recordActionBtn" aria-haspopup="true" aria-expanded="false">
                    Action <i class='bx bx-chevron-down'></i>
                </button>
                <div class="record-action-menu" id="recordActionMenu" role="menu">
                    <button type="button" class="record-action-item" id="recordActionUpdate" role="menuitem"><i class='bx bx-edit-alt'></i> Update</button>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Suggestion Details -->
        <div class="document-section">
            <div class="document-section-title">
                <div class="section-label">Suggestion Details</div>
            </div>
            <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;">
                <div><div class="label">Submitted By</div><div class="detail-value"><?php echo e(trim(((int)($suggestion['is_anonymous'] ?? 0) === 1) ? 'Anonymous Student' : trim((string)($suggestion['first_name'] ?? '') . ' ' . (string)($suggestion['last_name'] ?? '')))); ?></div></div>
                <div><div class="label">Category</div><div class="detail-value"><?php echo e((string)($suggestion['category_name'] ?? 'Uncategorized')); ?></div></div>
                <div><div class="label">Date of Suggestion</div><div class="detail-value"><?php echo e(!empty($suggestion['date_of_suggestion']) ? date('M d, Y', strtotime((string)$suggestion['date_of_suggestion'])) : (!empty($suggestion['created_at']) ? date('M d, Y', strtotime((string)$suggestion['created_at'])) : 'N/A')); ?></div></div>
                <div class="full" style="grid-column:1 / -1;"><div class="label">Subject / Idea</div><div class="detail-value"><?php echo e((string)($suggestion['subject'] ?? '')); ?></div></div>
                <div class="full" style="grid-column:1 / -1;"><div class="label">Detailed Suggestion</div><div class="detail-value detail-value--paragraph"><?php echo nl2br(e((string)($suggestion['description'] ?? ''))); ?></div></div>
            </div>

            <?php if (!empty($suggestion['attachment'])): ?>
                <?php $att = (string)$suggestion['attachment']; $attPath = '../' . ltrim($att, '/'); $ext = strtolower(pathinfo($att, PATHINFO_EXTENSION)); $isImage = in_array($ext, ['jpg','jpeg','png','gif'], true); ?>
                <div style="margin-top:12px;">
                    <div class="label" style="margin-bottom:6px;">Attachments / References</div>
                    <?php if ($isImage): ?>
                        <a href="<?php echo e($attPath); ?>" target="_blank"><img src="<?php echo e($attPath); ?>" alt="attachment" style="max-width:360px;border-radius:8px;border:1px solid #eef2ff;"></a>
                    <?php else: ?>
                        <a href="<?php echo e($attPath); ?>" target="_blank" class="btn" style="display:inline-block;padding:8px 12px;border-radius:8px;">Download attachment</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($suggestion['expected_outcome'])): ?>
            <div class="document-section">
                <div class="document-section-title"><div class="section-label">Expected Outcome</div></div>
                <div class="detail-value detail-value--paragraph"><?php echo nl2br(e((string)$suggestion['expected_outcome'])); ?></div>
            </div>
        <?php endif; ?>

        <?php $suggestionTermsAccepted = array_key_exists('terms_agreement_accepted', $suggestion) ? (int)$suggestion['terms_agreement_accepted'] : null; ?>
        <div class="document-section">
            <div class="label">Terms of Agreement</div>
            <?php if ($suggestionTermsAccepted === 1): ?>
                <div class="detail-value detail-value--paragraph"><?php echo e(suggestion_terms_agreement_statement()); ?> <?php echo e(suggestion_terms_agreement_checkbox_label()); ?></div>
            <?php elseif ($suggestionTermsAccepted === 0): ?>
                <div class="detail-value">Not agreed</div>
            <?php else: ?>
                <div class="detail-value">Unknown</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Status Update & Remarks Form (opened from the Action dropdown) -->
    <?php if (!empty($suggestionAllowedNext)): ?>
    <div id="updateStatusModal" class="update-status-modal" aria-hidden="true" role="dialog" aria-modal="true">
        <div class="update-status-backdrop" onclick="closeUpdateStatusModal()"></div>
        <div class="update-status-sheet" onclick="event.stopPropagation();">
        <div class="card status-remarks-card">
        <div class="update-status-modal-head">
            <div style="font-weight:700;color:#111827;">Update Status & Official Response</div>
            <button type="button" class="update-status-close" onclick="closeUpdateStatusModal()" aria-label="Close"><i class='bx bx-x'></i></button>
        </div>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['staff_csrf']); ?>">
            <input type="hidden" name="suggestion_id" value="<?php echo (int)$suggestionId; ?>">

            <div class="form-group">
                <label for="statusSelect">Suggestion Status</label>
                <select id="statusSelect" name="status" class="form-control">
                    <?php foreach ($suggestionAllowedNext as $statusValue => $statusOptionLabel): ?>
                        <option value="<?php echo e($statusValue); ?>"><?php echo e($statusOptionLabel); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="remarksTextarea">Official Response (Visible to Student)</label>
                <textarea id="remarksTextarea" name="remarks" class="form-control" placeholder="Write the official response or remark..." required></textarea>
            </div>

            <div class="status-remarks-actions">
                <button type="button" class="btn" style="background:#6b7280;" onclick="closeUpdateStatusModal()">Cancel</button>
                <button type="submit" class="btn">Save Response</button>
            </div>
        </form>
        </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Response Timeline -->
    <?php if (!empty($replies)): ?>
    <div class="card response-timeline-card" style="padding:0;margin-bottom:18px;">
        <div style="display:flex;align-items:center;padding:12px 16px;border-bottom:1px solid #eef2ff;">
            <div style="font-weight:700;flex:1;">Response Timeline</div>
        </div>
        <div style="padding:16px;">
                <div class="timeline-shell">
                    <div class="ticket-section">
                        <div class="section-title">Responses</div>
                        <div class="timeline-replies">
                            <?php foreach ($replies as $entry): ?>
                                <?php
                                    $histRole = strtolower((string)($entry['sender_role'] ?? ''));
                                    $histSenderId = isset($entry['sender_id']) ? (int)$entry['sender_id'] : 0;
                                    $histPerson = $histSenderId > 0 ? get_person_display($pdo, $histRole, $histSenderId) : ['name' => ucfirst($histRole ?: 'Staff'), 'photo' => null];
                                    $histName = $histPerson['name'] ?? ucfirst($histRole ?: 'Staff');
                                    $histPhoto = !empty($histPerson['photo']) ? ('../' . ltrim($histPerson['photo'], '/')) : null;
                                    $histRoleLabel = $histRole === 'dean' ? 'College Dean' : ($histRole === 'admin' ? 'Administrator' : ($histRole === 'staff' ? 'Staff' : 'Student'));
                                    echo response_timeline_entry([
                                        'name' => $histName,
                                        'role_label' => $histRoleLabel,
                                        'is_current_user' => $histRole === 'staff' && $histSenderId === $staffProfileId,
                                        'photo' => $histPhoto,
                                        'time_text' => date('M d, Y h:i A', strtotime((string)($entry['created_at'] ?? ''))),
                                        'message_html' => nl2br(e((string)($entry['message'] ?? ''))),
                                        'is_reply' => true,
                                    ]);
                                ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php echo response_timeline_script(); ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const actionDropdown = document.getElementById('recordActionDropdown');
    const actionBtn = document.getElementById('recordActionBtn');
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

    if (actionUpdateItem) {
        actionUpdateItem.addEventListener('click', function (event) {
            event.preventDefault();
            closeActionDropdown();
            openUpdateStatusModal();
        });
    }

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
});

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
</script>
</body>
</html>
