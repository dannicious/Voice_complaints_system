<?php
session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../suggestion_flow.php';
ensure_role('staff');

$staffStmt = $pdo->prepare("SELECT office, name FROM staff_profiles WHERE user_id = :user_id AND status = 'active' LIMIT 1");
$staffStmt->execute([':user_id' => (int)$_SESSION['user_id']]);
$staffProfile = $staffStmt->fetch(PDO::FETCH_ASSOC);
if (!$staffProfile || trim((string)$staffProfile['office']) === '') {
    http_response_code(403);
    exit('Staff office is not configured.');
}
$office = trim((string)$staffProfile['office']);

// Opportunistic SLA check - nudges the handling office, then admin, if a
// suggestion has gone unanswered too long. Suggestion-only, best-effort.
check_and_send_suggestion_overdue_notifications($pdo);

$stmt = $pdo->prepare("SELECT s.id, s.subject, s.description, s.status, s.created_at, sc.name AS category_name, sp.first_name, sp.last_name FROM suggestions s LEFT JOIN suggestion_categories sc ON sc.id = s.category_id LEFT JOIN student_profiles sp ON sp.id = s.student_id WHERE LOWER(TRIM(s.office)) = LOWER(TRIM(:office)) AND s.status NOT IN ('rejected', 'declined') ORDER BY s.created_at DESC, s.id DESC LIMIT 100");
$stmt->execute([':office' => $office]);
$suggestions = $stmt->fetchAll(PDO::FETCH_ASSOC);
function staff_status_label(string $status): string { return suggestion_status_meta($status)['label']; }
function e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Staff Suggestions - VOICE</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet"><link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box;font-family:'Poppins',sans-serif}body{background:#f4f6fb;color:#1f2937}.main{margin-left:260px;margin-top:61px;padding:25px}.panel{max-width:1100px;margin:auto;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px}.panel-header{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:16px}.panel h1{margin:0;font-size:24px;font-weight:600;color:#333}.muted{color:#6b7280;font-size:13px;margin:4px 0}table{width:100%;border-collapse:collapse}th{font-size:11px;color:#6b7280;text-align:left;text-transform:uppercase;padding:10px;border-bottom:2px solid #f0f1f3}td{padding:12px 10px;border-bottom:1px solid #f5f5f5;font-size:13px}tbody tr:hover{background:#fafafa}.open-link{color:#5b21b6;text-decoration:none;font-weight:600}.status{display:inline-block;padding:5px 9px;border-radius:6px;background:#f3f4f6;font-size:11px;font-weight:600}.empty{text-align:center;color:#9ca3af;padding:24px}@media(max-width:1024px){.main{margin-left:0;padding:16px}.panel{overflow-x:auto}table{min-width:760px}}
</style>
</head>
<body>
<?php include __DIR__ . '/staff_topbar.php'; ?>
<?php include __DIR__ . '/staff_sidebar.php'; ?>
<main class="main"><section class="panel">
    <div class="panel-header"><div><h1>Suggestions</h1><p class="muted">Assigned office: <?= e($office) ?></p></div></div>
    <table><thead><tr><th>Student</th><th>Category</th><th>Status</th><th>Date</th><th>Action</th></tr></thead><tbody>
    <?php if (!$suggestions): ?><tr><td colspan="7" class="empty">No suggestions are assigned to your office.</td></tr><?php else: foreach ($suggestions as $suggestion): ?>
        <tr><td><?= e(trim((string)$suggestion['first_name'] . ' ' . (string)$suggestion['last_name'])) ?></td><td><?= e($suggestion['category_name'] ?? 'Uncategorized') ?></td><td><span class="status"><?= e(staff_status_label((string)$suggestion['status'])) ?></span></td><td><?= e($suggestion['created_at']) ?></td><td><a class="open-link" href="suggestion_detail.php?id=<?= (int)$suggestion['id'] ?>">Open <i class="bx bx-right-arrow-alt"></i></a></td></tr>
    <?php endforeach; endif; ?></tbody></table>
</section></main>
</body></html>
