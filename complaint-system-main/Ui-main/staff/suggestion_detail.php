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
function staff_profile(PDO $pdo): array
{
    $stmt = $pdo->prepare("SELECT id, office, name FROM staff_profiles WHERE user_id = :user_id AND status = 'active' LIMIT 1");
    $stmt->execute([':user_id' => (int)$_SESSION['user_id']]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}
$profile = staff_profile($pdo);
$office = trim((string)($profile['office'] ?? ''));
if ($office === '') { http_response_code(403); exit('Staff office is not configured.'); }
$suggestionId = (int)($_GET['id'] ?? $_POST['suggestion_id'] ?? 0);
if ($suggestionId <= 0) { http_response_code(400); exit('Invalid suggestion.'); }
if (!isset($_SESSION['staff_csrf'])) $_SESSION['staff_csrf'] = bin2hex(random_bytes(32));
$flash = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['staff_csrf'], (string)($_POST['csrf_token'] ?? ''))) {
        $error = 'Invalid request token. Refresh and try again.';
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
            $error = 'Suggestion not found in your office scope.';
        } elseif (suggestion_thread_is_locked($currentStatus)) {
            $error = 'This suggestion is already closed and can no longer be updated.';
        } elseif (!array_key_exists($status, $allowedNext) || $remarks === '') {
            $error = 'Choose an allowed status and provide an official response.';
        } elseif ($isFinalizingStep && $remarks === '') {
            $error = 'Please provide an official response before finalizing this status.';
        } else {
                try {
                    set_suggestion_status($pdo, $suggestionId, $status);
                    save_ticket_reply($pdo, 'suggestion', $suggestionId, (int)$_SESSION['user_id'], 'staff', $remarks);
                    $studentUserId = (int)($row['student_user_id'] ?? 0);
                    if ($studentUserId > 0) {
                        $notify = $pdo->prepare('INSERT INTO notifications (user_id, type, message, ticket_type, ticket_id, is_read) VALUES (:user_id, :type, :message, :ticket_type, :ticket_id, 0)');
                        $notify->execute([':user_id' => $studentUserId, ':type' => 'suggestion_update', ':message' => 'Your suggestion has a new Staff response. Current status: ' . suggestion_status_meta($status)['label'] . '.', ':ticket_type' => 'suggestion', ':ticket_id' => $suggestionId]);
                    }
                    $flash = 'Suggestion response saved successfully.';
                } catch (Throwable $exception) {
                    $error = 'Unable to save the suggestion response.';
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
$historyStmt = $pdo->prepare("SELECT tr.message, tr.sender_role, tr.created_at, u.username FROM ticket_replies tr LEFT JOIN users u ON u.id = tr.sender_id WHERE tr.ticket_type = 'suggestion' AND tr.ticket_id = :ticket_id ORDER BY tr.created_at ASC, tr.id ASC");
$historyStmt->execute([':ticket_id' => $suggestionId]);
$history = $historyStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Suggestion Details - VOICE</title><link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet"><link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet"><style>*{margin:0;padding:0;box-sizing:border-box;font-family:'Poppins',sans-serif}body{background:#f4f6fb;color:#1f2937}.main{margin-left:260px;margin-top:61px;padding:25px}.shell{max-width:1000px;margin:auto}.back{color:#5b21b6;text-decoration:none;font-size:13px;font-weight:600}.card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px;margin-top:16px;box-shadow:0 2px 10px rgba(0,0,0,.02)}h1{font-size:24px;margin:0 0 6px;font-weight:600;color:#333}h2{font-size:16px;margin:0 0 12px;font-weight:600;color:#333}.muted{color:#6b7280;font-size:13px;margin:4px 0}.meta{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:16px}.meta div{background:#f8fafc;border-radius:8px;padding:10px;font-size:13px}.meta strong{display:block;color:#6b7280;font-size:10px;text-transform:uppercase;margin-bottom:3px}.label{display:block;color:#6b7280;font-size:11px;font-weight:600;text-transform:uppercase;margin:16px 0 5px}.body-text{white-space:pre-wrap;line-height:1.55;font-size:14px}.notice{padding:10px;border-radius:8px;margin-top:14px;font-size:13px}.success{background:#e8f9f0;color:#166534}.error{background:#fff1f1;color:#b42318}.form-grid{display:grid;grid-template-columns:180px 1fr;gap:12px;align-items:start}.select,.textarea,.button{width:100%;padding:10px;border:1px solid #d1d5db;border-radius:8px;font:inherit;font-size:13px}.textarea{min-height:110px;resize:vertical}.button{background:#6d28d9;color:#fff;border:0;font-weight:600;cursor:pointer}.timeline-list{display:flex;flex-direction:column;gap:14px}.timeline-entry{display:flex;gap:10px;align-items:flex-start}.timeline-avatar{width:40px;height:40px;border-radius:999px;display:inline-flex;align-items:center;justify-content:center;overflow:hidden;background:#6d28d9;color:#fff;font-weight:700;font-size:14px;border:1px solid #eef2ff;flex-shrink:0}.timeline-avatar img{width:100%;height:100%;object-fit:cover}.timeline-body{flex:1;min-width:0;display:flex;flex-direction:column}.timeline-card{display:inline-block;width:fit-content;max-width:min(85%,640px);padding:12px 14px;border-radius:14px;border:1px solid #e5e7eb;background:#fff}.timeline-card.current-user{background:#ede9fe;border-color:#c4b5fd}.timeline-heading{display:flex;align-items:baseline;gap:8px;flex-wrap:wrap;margin-bottom:2px}.timeline-name{font-weight:700;color:#111827;font-size:13px}.timeline-role{font-size:11.5px;color:#6b7280;font-weight:600}.timeline-card .timeline-text{color:#111827;font-size:13px;line-height:1.55;white-space:pre-wrap;word-break:break-word}
<?php echo response_timeline_styles(); ?>@media(max-width:1024px){.main{margin-left:0;padding:16px}}@media(max-width:650px){.meta{grid-template-columns:repeat(2,1fr)}.form-grid{grid-template-columns:1fr}}@media(max-width:430px){.meta{grid-template-columns:1fr}}</style></head><body><?php include __DIR__ . '/staff_topbar.php'; ?><?php include __DIR__ . '/staff_sidebar.php'; ?><main class="main"><div class="shell"><a class="back" href="suggestions.php"><i class="bx bx-arrow-back"></i> Back to Suggestions</a><section class="card"><h1><?= e((string)$suggestion['subject']) ?></h1><p class="muted"><?= e((string)$suggestion['ticket_no']) ?> &middot; <?= e($office) ?></p><p class="muted">Submitted by <?= e(trim((string)$suggestion['first_name'].' '.(string)$suggestion['last_name'])) ?> (<?= e((string)$suggestion['student_number']) ?>)</p><?php if ($flash !== ''): ?><div class="notice success"><?= e($flash) ?></div><?php endif; ?><?php if ($error !== ''): ?><div class="notice error"><?= e($error) ?></div><?php endif; ?><div class="meta"><div><strong>Category</strong><?= e($suggestion['category_name'] ?? 'Uncategorized') ?></div><div><strong>Date</strong><?= e($suggestion['date_of_suggestion'] ?: $suggestion['created_at']) ?></div><div><strong>School Year</strong><?= e($suggestion['school_year'] ?? 'Not set') ?></div><div><strong>Status</strong><?= e(suggestion_status_meta((string)$suggestion['status'])['label']) ?></div></div><div class="label">Suggestion description</div><div class="body-text"><?= e($suggestion['description']) ?></div><?php if (!empty($suggestion['expected_outcome'])): ?><div class="label">Expected outcome</div><div class="body-text"><?= e($suggestion['expected_outcome']) ?></div><?php endif; ?></section><section class="card"><h2>Staff Response</h2>
<?php $suggestionAllowedNext = suggestion_allowed_next_statuses((string)$suggestion['status']); ?>
<?php if (empty($suggestionAllowedNext)): ?>
<p class="muted">This suggestion has reached its final status (<?= e(suggestion_status_meta((string)$suggestion['status'])['label']) ?>) and can no longer be updated.</p>
<?php else: ?>
<form method="post"><input type="hidden" name="csrf_token" value="<?= e($_SESSION['staff_csrf']) ?>"><input type="hidden" name="suggestion_id" value="<?= $suggestionId ?>"><div class="form-grid"><label class="label" for="status">Status</label><select class="select" id="status" name="status" required><?php foreach ($suggestionAllowedNext as $statusValue => $statusOptionLabel): ?><option value="<?= e($statusValue) ?>"><?= e($statusOptionLabel) ?></option><?php endforeach; ?></select><label class="label" for="remarks">Official response</label><textarea class="textarea" id="remarks" name="remarks" required placeholder="Write the official response or remark..."></textarea><span></span><button class="button" type="submit">Save Response</button></div></form>
<?php endif; ?>
</section><section class="card"><h2>Suggestion History</h2><?php if (!$history): ?><p class="muted">No responses have been recorded yet.</p><?php else: ?><div class="timeline-list"><?php foreach ($history as $entry):
    $histRole = strtolower((string)($entry['sender_role'] ?? ''));
    $histName = $entry['username'] ? (string)$entry['username'] : ucfirst($histRole ?: 'Staff');
    $histRoleLabel = $histRole === 'dean' ? 'College Dean' : ($histRole === 'admin' ? 'Administrator' : ($histRole === 'staff' ? 'Staff' : 'Student'));
    echo response_timeline_entry([
        'name' => $histName,
        'role_label' => $histRoleLabel,
        'is_current_user' => $histRole === 'staff',
        'photo' => null,
        'time_text' => (string)($entry['created_at'] ?? ''),
        'message_html' => nl2br(e((string)($entry['message'] ?? ''))),
        'reply_target_id' => 'remarks',
    ]);
endforeach; ?></div><?php endif; ?></section></div></main><?php echo response_timeline_script(); ?></body></html>
