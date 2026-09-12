<?php

session_start();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../ticket_flow.php';
require_once __DIR__ . '/../suggestion_flow.php';
require_once __DIR__ . '/../response_timeline_ui.php';
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
// ensure dean
if (!isset($_SESSION['user_id']) || (string)($_SESSION['role'] ?? '') !== 'dean') {
    http_response_code(403); echo 'Dean profile required.'; exit;
}
$deanUserId = (int)$_SESSION['user_id'];
$deanProfileId = 0;
try {
    $stmt = $pdo->prepare('SELECT id, college_id FROM dean_profiles WHERE user_id = :user_id LIMIT 1');
    $stmt->execute([':user_id' => $deanUserId]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($r) { $deanProfileId = (int)$r['id']; $deanCollegeId = $r['college_id']; } else { $deanCollegeId = null; }
} catch (PDOException $e) { $deanCollegeId = null; }
if ($deanCollegeId === null) { http_response_code(403); echo 'Dean college not configured.'; exit; }

$flashMessage = ''; $flashType = 'info';
$suggestionId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($suggestionId <= 0) { $flashMessage = 'Suggestion id missing.'; $flashType = 'error'; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_suggestion') {
    $postedId = isset($_POST['suggestion_id']) ? (int)$_POST['suggestion_id'] : 0;
    $newStatus = trim((string)($_POST['status'] ?? ''));
    $remark = trim((string)($_POST['remark'] ?? ''));
    $remarkId = isset($_POST['remark_id']) ? (int)$_POST['remark_id'] : 0;

    $currentStatus = '';
    if ($postedId > 0) {
        $currentStmt = $pdo->prepare('SELECT status FROM suggestions WHERE id = :id AND college_id = :college_id LIMIT 1');
        $currentStmt->execute([':id' => $postedId, ':college_id' => $deanCollegeId]);
        $currentStatus = (string)($currentStmt->fetchColumn() ?: '');
    }
    $allowedNext = suggestion_allowed_next_statuses($currentStatus);
    $isFinalizingStep = in_array($newStatus, ['accepted', 'not_feasible', 'implemented'], true);

    if ($postedId <= 0 || $currentStatus === '') {
        $flashMessage = 'Suggestion not found in your college scope.'; $flashType = 'error';
    } elseif (suggestion_thread_is_locked($currentStatus)) {
        $flashMessage = 'This suggestion is already closed and can no longer be updated.'; $flashType = 'error';
    } elseif (!array_key_exists($newStatus, $allowedNext)) {
        $flashMessage = 'Invalid status for this suggestion\'s current stage.'; $flashType = 'error';
    } elseif ($isFinalizingStep && $remark === '' && $remarkId <= 0) {
        $flashMessage = 'Please provide Official Remarks before finalizing this status.'; $flashType = 'error';
    } else {
        try {
            set_suggestion_status($pdo, $postedId, $newStatus);
            if ($remarkId > 0 && $remark !== '') {
                $up = $pdo->prepare('UPDATE ticket_replies SET message = :message WHERE id = :id AND sender_role = :role');
                $up->execute([':message'=>$remark, ':id'=>$remarkId, ':role'=>'dean']);
            } elseif ($remarkId <= 0 && $remark !== '') {
                $ins = $pdo->prepare('INSERT INTO ticket_replies (ticket_type, ticket_id, sender_id, sender_role, message, created_at) VALUES ("suggestion", :ticket_id, :sender_id, "dean", :message, NOW())');
                $ins->execute([':ticket_id'=>$postedId, ':sender_id'=>$deanProfileId, ':message'=>$remark]);
            }

            // Let the student know their suggestion was updated.
            $ownerStmt = $pdo->prepare('SELECT student_id FROM suggestions WHERE id = :id LIMIT 1');
            $ownerStmt->execute([':id' => $postedId]);
            $suggestionStudentId = (int)($ownerStmt->fetchColumn() ?: 0);
            if ($suggestionStudentId > 0) {
                $actorInfo = get_person_display($pdo, 'dean', $deanProfileId);
                $actorName = $actorInfo['name'] ?? 'Your dean';
                $statusLabel = suggestion_status_meta($newStatus)['label'];
                $updateMessage = $remark !== ''
                    ? $actorName . ' posted an official remark and updated your suggestion status to ' . $statusLabel . '.'
                    : $actorName . ' updated your suggestion status to ' . $statusLabel . '.';
                notify_ticket_owner($pdo, 'suggestion', $postedId, $suggestionStudentId, 'suggestion_update', $updateMessage);
            }

            header('Location: ?id='.$postedId);
            exit;
        } catch (Throwable $e) {
            $flashMessage = 'Unable to save.';
            $flashType='error';
        }
    }
}

// load suggestion
$suggestion = null;
$replies = [];
$feedback = null;
$feedbackReplies = [];
$deanRemark = null;
try {
    $stmt = $pdo->prepare('SELECT s.*, sc.name AS category_name, sp.first_name, sp.last_name FROM suggestions s LEFT JOIN suggestion_categories sc ON sc.id = s.category_id LEFT JOIN student_profiles sp ON sp.id = s.student_id WHERE s.id = :id AND s.college_id = :college_id LIMIT 1');
    $stmt->execute([':id'=>$suggestionId, ':college_id'=>$deanCollegeId]);
    $suggestion = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$suggestion) { $flashMessage = 'Suggestion not found.'; $flashType='error'; }
    else {
        // replies
        $r = $pdo->prepare('SELECT id,sender_id,sender_role,message,created_at FROM ticket_replies WHERE ticket_type = "suggestion" AND ticket_id = :id ORDER BY created_at ASC, id ASC');
        $r->execute([':id'=>$suggestionId]);
        $replies = $r->fetchAll(PDO::FETCH_ASSOC);
        foreach ($replies as $rep) {
            if ((int)$rep['sender_id'] === $deanProfileId && strtolower((string)$rep['sender_role']) === 'dean') { $deanRemark = $rep; break; }
        }
        // feedback
        $f = $pdo->prepare('SELECT id,satisfaction,comment,created_at FROM ticket_feedback WHERE ticket_type = "suggestion" AND ticket_id = :id LIMIT 1');
        $f->execute([':id'=>$suggestionId]); $feedback = $f->fetch(PDO::FETCH_ASSOC);
        $fr = $pdo->prepare('SELECT id,feedback_history_id,replier_id,replier_role,message,created_at FROM ticket_feedback_replies WHERE ticket_type = "suggestion" AND ticket_id = :id ORDER BY created_at ASC, id ASC');
        $fr->execute([':id'=>$suggestionId]);
        $feedbackReplies = $fr->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) { $flashMessage = 'Unable to load suggestion.'; $flashType='error'; }

// Whether a real suggestion was loaded - checked before the fallback below
// replaces $suggestion with an empty placeholder, so the page can show just
// the "not found" notice instead of rendering a broken, half-empty record.
$suggestionFound = is_array($suggestion);

// Ensure $suggestion is an array to avoid template notices when fields are accessed
if (!is_array($suggestion)) {
    $suggestion = [
        'subject' => '',
        'description' => '',
        'category_name' => '',
        'created_at' => null,
        'expected_outcome' => '',
        'status' => 'new',
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage Suggestion - VOICE</title>
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

/* Card utility to match other dean pages */
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
<div class="main">
    <?php if ($flashMessage !== ''): ?>
        <div class="notice <?php echo e($flashType); ?>"><?php echo e($flashMessage); ?></div>
    <?php endif; ?>

    <?php if ($suggestionFound): ?>
    <div class="complaint-document card" style="margin-bottom:18px;">
        <div class="document-heading">
            <div class="document-heading-left">
                <a href="dean_suggestions.php" id="recordBackBtn" class="document-back-btn" aria-label="Back to Suggestions" title="Back to Suggestions">
                    <i class='bx bx-arrow-back'></i>
                </a>
                <div>
                    <div class="document-kicker">Official Suggestion Record</div>
                    <div class="document-submitted">Submitted <?php echo e(!empty($suggestion['created_at']) ? date('M d, Y h:i A', strtotime((string)$suggestion['created_at'])) : ''); ?> &middot; Status: <?php echo e(suggestion_status_meta((string)($suggestion['status'] ?? ''))['label']); ?></div>
                </div>
            </div>
            <?php $suggestionAllowedNext = suggestion_allowed_next_statuses((string)($suggestion['status'] ?? '')); ?>
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
            <?php endif; ?>
        </div>

        <?php if (!empty($suggestion['expected_outcome'])): ?>
            <div class="document-section">
                <div class="document-section-title"><div class="section-label">Expected Outcome</div></div>
                <div style="white-space:pre-wrap;"><?php echo nl2br(e((string)$suggestion['expected_outcome'])); ?></div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Status Update & Remarks Form (opened from the Action dropdown) -->
    <?php if (!empty($suggestionAllowedNext)): ?>
    <div id="updateStatusModal" class="update-status-modal" aria-hidden="true" role="dialog" aria-modal="true">
        <div class="update-status-backdrop" onclick="closeUpdateStatusModal()"></div>
        <div class="update-status-sheet" onclick="event.stopPropagation();">
        <div class="card status-remarks-card">
        <div class="update-status-modal-head">
            <div style="font-weight:700;color:#111827;">Update Status & Official Remarks</div>
            <button type="button" class="update-status-close" onclick="closeUpdateStatusModal()" aria-label="Close"><i class='bx bx-x'></i></button>
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="update_suggestion">
            <input type="hidden" name="suggestion_id" value="<?php echo (int)$suggestionId; ?>">
            <input type="hidden" name="csrf_token" value="<?php echo e((string)($_SESSION['csrf_token'] ?? '')); ?>">
            <?php if (!empty($deanRemark)): ?>
                <input type="hidden" name="remark_id" value="<?php echo (int)$deanRemark['id']; ?>">
            <?php endif; ?>

            <div class="form-group">
                <label for="statusSelect">Suggestion Status</label>
                <select id="statusSelect" name="status" class="form-control">
                    <?php foreach ($suggestionAllowedNext as $statusValue => $statusOptionLabel): ?>
                        <option value="<?php echo e($statusValue); ?>" <?php echo ((string)$suggestion['status'] === $statusValue) ? 'selected' : ''; ?>><?php echo e($statusOptionLabel); ?></option>
                    <?php endforeach; ?>
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
    <?php endif; ?>

    <script>
    function toggleRemarkEdit() {
        const display = document.getElementById('remarkDisplay');
        const form = document.getElementById('remarkFormWrapper');
        if (display && form) {
            display.style.display = display.style.display === 'none' ? 'block' : 'none';
            form.style.display = form.style.display === 'none' ? 'block' : 'none';
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
        // Header "Action" dropdown: Update only (no Call Slip for suggestions)
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

        // The status form lives in a modal that starts hidden, so this has
        // to wait for the DOM instead of running inline.
        const status = document.getElementById('statusSelect');
        const remark = document.getElementById('remarkTextarea');
        if (status && remark) {
            const finalizingStatuses = ['accepted', 'not_feasible', 'implemented'];
            function toggleRequired() { remark.required = finalizingStatuses.includes(status.value); }
            status.addEventListener('change', toggleRequired);
            toggleRequired();
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

        $currentStatus = strtolower((string)($suggestion['status'] ?? ''));
        $threadResolved = in_array($currentStatus, ['reviewed'], true);
        $canReply = !$threadResolved;
        $feedbackId = !empty($feedback['id']) ? (int)$feedback['id'] : '';
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
                                    $meta = feedback_option_meta((string)$feedback['satisfaction']);
                                    $studentInfo = get_person_display($pdo, 'student', (int)$suggestion['student_id']);
                                    $studentName = $studentInfo['name'] ?? 'Student';
                                    $stuPhoto = !empty($studentInfo['photo']) ? ('../' . ltrim($studentInfo['photo'], '/')) : null;
                                    $stuInitial = strtoupper(substr(trim($studentName), 0, 1)) ?: 'S';
                                    $stuComment = trim((string)($feedback['comment'] ?? ''));
                                ?>
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
                            <?php else: ?>
                                <div class="empty-card">No rating has been submitted yet.</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($canReply): ?>
                        <div class="ticket-section">
                            <div id="globalReplyBox" class="reply-box-card reply-form-wrapper timeline-reply-box">
                                <form id="feedbackReplyForm" class="dean-feedback-reply-form" method="POST">
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
        </div>
    </div>

    <?php endif; ?>
    <div style="height:18px;"></div>
    <?php endif; ?>
</div>

<?php echo response_timeline_script(); ?>
<button type="button" id="scrollTopBtn" class="scroll-top-btn" aria-label="Scroll to top" title="Back to top">
    <i class='bx bx-up-arrow-alt'></i>
</button>
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Back arrow: prefer returning to the exact suggestions-list page the
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
            // Otherwise let the plain href navigate to dean_suggestions.php
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
