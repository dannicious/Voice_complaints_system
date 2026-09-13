<?php

session_start();
require_once __DIR__ . '/../db_connection.php';

if (!function_exists('e')) {
    function e(string $value = ''): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

// This page is deliberately narrow: it exists only so a student who is
// summoned by a call slip (but did not file the complaint) can see the call
// slip itself - who issued it, when/where to report, why - without being
// able to see the rest of the complaint (who filed it, the incident
// narrative, desired outcome, etc). That fuller record stays restricted to
// whoever actually filed it, via the regular ticket_detail.php.

$studentProfileId = 0;
if (!isset($_SESSION['user_id']) || (string)($_SESSION['role'] ?? '') !== 'student') {
    header('Location: login.php?error=' . urlencode('Please log in as student.'));
    exit;
}
try {
    $studentStmt = $pdo->prepare('SELECT id FROM student_profiles WHERE user_id = :user_id LIMIT 1');
    $studentStmt->execute([':user_id' => (int)$_SESSION['user_id']]);
    $studentProfileId = (int)($studentStmt->fetchColumn() ?: 0);
} catch (PDOException $e) {
    $studentProfileId = 0;
}
if ($studentProfileId <= 0) {
    http_response_code(403);
    echo 'Student profile not found.';
    exit;
}

$ticketType = (string)($_GET['type'] ?? 'complaint');
$ticketType = $ticketType === 'suggestion' ? 'suggestion' : 'complaint';
$ticketId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$errorMessage = '';
$callSlip = null;
$recipientName = '';

if ($ticketId <= 0) {
    $errorMessage = 'Call slip not found.';
} else {
    try {
        // Access: either the student who filed it, or the student a call
        // slip on it names as the reported party.
        $table = $ticketType === 'complaint' ? 'complaints' : 'suggestions';
        $ownerStmt = $pdo->prepare("SELECT student_id FROM {$table} WHERE id = :id LIMIT 1");
        $ownerStmt->execute([':id' => $ticketId]);
        $ownerId = (int)($ownerStmt->fetchColumn() ?: 0);

        $hasAccess = $ownerId > 0 && $ownerId === $studentProfileId;
        if (!$hasAccess) {
            $linkStmt = $pdo->prepare('SELECT 1 FROM complaint_student_links WHERE complaint_id = :id AND student_id = :student_id LIMIT 1');
            $linkStmt->execute([':id' => $ticketId, ':student_id' => $studentProfileId]);
            $hasAccess = (bool)$linkStmt->fetchColumn();
        }

        if (!$hasAccess) {
            $errorMessage = 'Call slip not found or you do not have access to view it.';
        } else {
            $callSlipStmt = $pdo->prepare(
                "SELECT cs.id, cs.student_id, cs.issued_at, cs.status, cs.issued_by_role, cs.report_date, cs.report_time, cs.office_message, cs.reason_note,
                        COALESCE(
                            NULLIF(TRIM(cs.issued_by_name), ''),
                            NULLIF(TRIM(CONCAT_WS(' ', dp.first_name, dp.last_name)), ''),
                            NULLIF(ap.name, ''),
                            NULLIF(u.username, ''),
                            cs.issued_by_role
                        ) AS issuer_name,
                        col.code AS issuer_college_code
                 FROM call_slips cs
                 LEFT JOIN users u ON u.id = cs.issued_by_user_id
                 LEFT JOIN dean_profiles dp ON dp.user_id = cs.issued_by_user_id
                 LEFT JOIN admin_profiles ap ON ap.user_id = cs.issued_by_user_id
                 LEFT JOIN colleges col ON col.id = dp.college_id
                 WHERE cs.ticket_type = :ticket_type AND cs.ticket_id = :ticket_id
                 ORDER BY cs.issued_at DESC, cs.id DESC
                 LIMIT 1"
            );
            $callSlipStmt->execute([':ticket_type' => $ticketType, ':ticket_id' => $ticketId]);
            $callSlip = $callSlipStmt->fetch(PDO::FETCH_ASSOC);

            if (!$callSlip) {
                $errorMessage = 'No call slip has been issued for this yet.';
            } else {
                $recipientStmt = $pdo->prepare('SELECT first_name, last_name FROM student_profiles WHERE id = :id LIMIT 1');
                $recipientStmt->execute([':id' => (int)($callSlip['student_id'] ?? 0)]);
                $recipientRow = $recipientStmt->fetch(PDO::FETCH_ASSOC);
                $recipientName = $recipientRow
                    ? trim((string)($recipientRow['first_name'] ?? '') . ' ' . (string)($recipientRow['last_name'] ?? ''))
                    : 'Student';

                // Mark the notification read now that they've actually opened it.
                try {
                    $pdo->prepare(
                        "UPDATE notifications SET is_read = 1
                         WHERE user_id = :user_id AND type = 'call_slip_issued' AND ticket_type = :ticket_type AND ticket_id = :ticket_id"
                    )->execute([
                        ':user_id' => (int)$_SESSION['user_id'],
                        ':ticket_type' => $ticketType,
                        ':ticket_id' => $ticketId,
                    ]);
                } catch (PDOException $e) {
                }
            }
        }
    } catch (PDOException $e) {
        $errorMessage = 'Unable to load this call slip right now.';
    }
}

$issuerRole = (string)($callSlip['issued_by_role'] ?? '');
$issuerCollegeCode = trim((string)($callSlip['issuer_college_code'] ?? ''));
if ($issuerRole === 'dean') {
    $formTitle = 'CALL SLIP - ' . ($issuerCollegeCode !== '' ? $issuerCollegeCode . " DEAN'S OFFICE" : "DEAN'S OFFICE");
} elseif ($issuerRole === 'admin') {
    $formTitle = 'CALL SLIP - SAS';
} else {
    $formTitle = 'CALL SLIP';
}

$reportDateRaw = trim((string)($callSlip['report_date'] ?? ''));
$reportDateDisplay = $reportDateRaw;
$parsedReportDate = $reportDateRaw !== '' ? strtotime($reportDateRaw) : false;
if ($parsedReportDate !== false) {
    $reportDateDisplay = date('Y-m-d', $parsedReportDate) . ' (' . date('l', $parsedReportDate) . ')';
}
$reportTime = trim((string)($callSlip['report_time'] ?? ''));
$officeMessage = trim((string)($callSlip['office_message'] ?? ''));
$reasonNote = trim((string)($callSlip['reason_note'] ?? ''));
$issuerName = trim((string)($callSlip['issuer_name'] ?? 'Unknown issuer'));
$issuedAt = (string)($callSlip['issued_at'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Call Slip - VOICE</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
body { background: #f4f6fb; }

.call-slip-page-overlay { position: fixed; inset: 0; background: rgba(15,23,42,0.55); display: flex; align-items: center; justify-content: center; padding: 20px; z-index: 100; }
.call-slip-page-sheet { width: min(640px, 100%); max-height: 90vh; overflow-y: auto; background: #fffdf6; border-radius: 14px; box-shadow: 0 30px 60px rgba(15,23,42,0.3); }
.call-slip-page-paper { padding: 26px 30px 30px; }

.call-slip-header { display: flex; align-items: center; justify-content: space-between; gap: 16px; border-bottom: 1px solid #e5e7eb; padding-bottom: 16px; margin-bottom: 4px; }
.call-slip-company-block { display: flex; align-items: center; gap: 14px; }
.call-slip-bisu-mark img { width: 62px; height: 62px; object-fit: contain; }
.call-slip-company-title { font-size: 13px; font-weight: 600; color: #111827; }
.call-slip-company-title.strong { font-size: 16px; font-weight: 800; }
.call-slip-company-sub { font-size: 11px; color: #111827; }
.call-slip-quote { margin-top: 6px; font-size: 11px; font-style: italic; color: #111827; }
.call-slip-right-badge { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
.call-slip-right-badge img { width: 72px; height: 56px; object-fit: contain; display: block; }
.call-slip-brand-text { font-size: 11px; font-weight: 700; text-align: left; color: #111827; }
.call-slip-brand-sub { font-size: 10px; font-weight: 600; }

/* One consistent small gap drives the rhythm between every block below the
   title (status row, each meta row, the connection paragraph, the closing
   note) - no per-block one-off margins, so nothing stacks into a large gap. */
.call-slip-body { padding-top: 22px; text-align: left; }
.call-slip-body > * { margin-top: 0; margin-bottom: 8px; }
.call-slip-body > *:last-child { margin-bottom: 0; }

.call-slip-form-title { text-align: center; font-size: 20px; letter-spacing: 0.03em; font-weight: 700; color: #111827; margin-bottom: 14px !important; }

.call-slip-status-row { display: flex; align-items: center; justify-content: center; gap: 10px; }
.call-slip-status-pill { display: inline-flex; align-items: center; padding: 3px 11px; border-radius: 999px; background: #fef3c7; color: #92400e; font-size: 11px; font-weight: 600; }
.call-slip-issued-note { font-size: 12px; font-weight: 400; color: #111827; }

.call-slip-meta-row { display: flex; gap: 36px; font-size: 14px; font-weight: 400; line-height: 1.6; color: #111827; }
.call-slip-field { flex: 1; }
.call-slip-field .label,
.call-slip-connection .label { font-size: 14px; font-weight: 600; color: #111827; }

.call-slip-closing-note { display: block; width: 100%; margin-top: 26px; font-size: 14px; font-weight: 400; line-height: 1.65; color: #111827; text-align: justify; }
.call-slip-connection { margin-top: 26px; }
.call-slip-connection .label { display: block; text-align: left; margin-bottom: 4px; }
.call-slip-connection-text { display: block; width: 100%; font-size: 14px; font-weight: 400; line-height: 1.65; color: #111827; text-align: justify; white-space: pre-wrap; }

.call-slip-footer-row { display: flex; justify-content: flex-end; }
.call-slip-signature { text-align: center; min-width: 180px; }
.call-slip-signature .name { font-size: 15px; font-weight: 500; color: #111827; }
.call-slip-signature .role { margin-top: 4px; font-size: 11px; font-weight: 400; letter-spacing: 0.04em; color: #111827; }

.call-slip-page-actions { display: flex; justify-content: flex-end; padding: 16px 30px 26px; }
.call-slip-close-btn { border: none; background: #6d28d9; color: #fff; font-weight: 700; padding: 10px 22px; border-radius: 8px; cursor: pointer; font-size: 14px; }
.call-slip-close-btn:hover { background: #5b21b6; }

.call-slip-error-card { width: min(440px, 100%); background: #fff; border-radius: 14px; box-shadow: 0 30px 60px rgba(15,23,42,0.3); padding: 30px; text-align: center; }
.call-slip-error-card i { font-size: 40px; color: #f59e0b; margin-bottom: 12px; }
.call-slip-error-card p { font-size: 14px; color: #111827; margin-bottom: 18px; line-height: 1.5; }

@media (max-width: 480px) {
    .call-slip-page-overlay { padding: 10px; }
    .call-slip-page-paper { padding: 18px 16px 20px; }
    .call-slip-header { flex-wrap: wrap; gap: 12px; }
    .call-slip-company-block { gap: 10px; }
    .call-slip-bisu-mark img { width: 46px; height: 46px; }
    .call-slip-right-badge img { width: 54px; height: 42px; }
    .call-slip-meta-row { flex-direction: column; gap: 4px; }
    .call-slip-page-actions { padding: 14px 16px 20px; }
    .call-slip-error-card { padding: 22px; }
}
</style>
</head>
<body>

<div class="call-slip-page-overlay">
    <?php if ($errorMessage !== ''): ?>
        <div class="call-slip-error-card">
            <i class='bx bx-error-circle'></i>
            <p><?php echo e($errorMessage); ?></p>
            <button type="button" class="call-slip-close-btn" onclick="window.location.href='student_mysubmission.php'">Back to My Submissions</button>
        </div>
    <?php else: ?>
        <div class="call-slip-page-sheet">
            <div class="call-slip-page-paper">
                <div class="call-slip-header">
                    <div class="call-slip-company-block">
                        <div class="call-slip-bisu-mark">
                            <img src="../assets/images/bisulogo.png" alt="BISU Balilihan Logo">
                        </div>
                        <div class="call-slip-company-copy">
                            <div class="call-slip-company-title">Republic of the Philippines</div>
                            <div class="call-slip-company-title strong">BOHOL ISLAND STATE UNIVERSITY</div>
                            <div class="call-slip-company-sub">Magsija, Bilaran, 6342, Bohol, Philippines</div>
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
                    <div class="call-slip-form-title"><?php echo e($formTitle); ?></div>

                    <div class="call-slip-status-row">
                        <span class="call-slip-status-pill"><?php echo e(ucfirst((string)($callSlip['status'] ?? 'issued'))); ?></span>
                        <?php if ($issuedAt !== ''): ?>
                            <span class="call-slip-issued-note">Issued <?php echo e(date('M d, Y h:i A', strtotime($issuedAt))); ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="call-slip-meta-row">
                        <div class="call-slip-field"><span class="label">To:</span> <?php echo e($recipientName); ?></div>
                        <div class="call-slip-field"><span class="label">Date Issued:</span> <?php echo e($issuedAt !== '' ? date('F j, Y', strtotime($issuedAt)) : ''); ?></div>
                    </div>

                    <div class="call-slip-meta-row">
                        <div class="call-slip-field"><span class="label">on Date:</span> <?php echo e($reportDateDisplay !== '' ? $reportDateDisplay : '—'); ?></div>
                        <div class="call-slip-field"><span class="label">at Time:</span> <?php echo e($reportTime !== '' ? $reportTime : '—'); ?></div>
                    </div>

                    <?php if ($reasonNote !== ''): ?>
                        <div class="call-slip-connection">
                            <div class="label">This is in connection with:</div>
                            <div class="call-slip-connection-text"><?php echo nl2br(e($reasonNote)); ?></div>
                        </div>
                    <?php endif; ?>

                    <div class="call-slip-closing-note">Please review this Call Slip and report to the designated office at the scheduled date and time. Thank you.</div>

                    <div class="call-slip-footer-row">
                        <div class="call-slip-signature">
                            <div class="name"><?php echo e($issuerName); ?></div>
                            <div class="role"><?php echo e($issuerRole === 'dean' ? 'Dean' : ($issuerRole === 'admin' ? 'SAS Director' : ucfirst($issuerRole))); ?></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="call-slip-page-actions">
                <button type="button" class="call-slip-close-btn" onclick="window.location.href='student_mysubmission.php'">Close</button>
            </div>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
