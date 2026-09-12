<?php
session_start();
require_once __DIR__ . '/../db_connection.php';

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0 || strtolower((string)($_SESSION['role'] ?? '')) !== 'admin') {
    header('Location: login.php?error=' . urlencode('Please log in as admin.'));
    exit;
}

$studentId = (int)($_GET['student_id'] ?? 0);
$view = (string)($_GET['view'] ?? 'reported');
$view = $view === 'complaints' ? 'complaints' : 'reported';
$complaints = [];
$studentName = 'Reported student';
$loadError = '';

try {
    $studentStmt = $pdo->prepare('SELECT first_name, last_name FROM student_profiles WHERE id = :student_id LIMIT 1');
    $studentStmt->execute([':student_id' => $studentId]);
    $student = $studentStmt->fetch(PDO::FETCH_ASSOC);
    if (!$student) {
        throw new RuntimeException('Reported student was not found.');
    }
    $studentName = trim((string)$student['first_name'] . ' ' . (string)$student['last_name']) ?: $studentName;

    $sql = 'SELECT c.id, c.ticket_no, c.complainant_name, c.complainant_contact_details,
                   c.date_of_incident, c.time_of_incident, c.place_of_incident,
                   c.person_complained_of, c.act_complained_of, c.created_at
            FROM complaints c';
    if ($view === 'reported') {
        $sql .= ' INNER JOIN complaint_student_links csl ON csl.complaint_id = c.id
                  WHERE csl.student_id = :student_id';
    } else {
        $sql .= ' WHERE c.student_id = :student_id';
    }
    $sql .= ' ORDER BY c.created_at DESC, c.id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':student_id' => $studentId]);
    $complaints = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $loadError = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reported Complaints - VOICE</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
<style>
* { box-sizing: border-box; font-family: 'Poppins', sans-serif; }
body { margin: 0; background: #f4f6fb; color: #1f2937; }
.main { margin-left: 260px; margin-top: 61px; padding: 25px; min-height: calc(100vh - 61px); }
.shell { max-width: 1100px; margin: 0 auto; }
.header { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 20px; }
h1 { margin: 0; font-size: 24px; }
.subtitle { color: #6b7280; margin: 5px 0 0; font-size: 13px; }
.back { color: #6d28d9; text-decoration: none; font-size: 13px; font-weight: 600; }
.card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 22px; margin-bottom: 18px; box-shadow: 0 4px 15px rgba(0,0,0,.03); }
.detail-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px 24px; }
.detail label { display: block; color: #6b7280; font-size: 12px; margin-bottom: 5px; }
.detail p { margin: 0; white-space: pre-wrap; line-height: 1.5; font-size: 14px; }
.detail.full { grid-column: 1 / -1; }
.ticket-title { display: flex; justify-content: space-between; gap: 12px; margin-bottom: 18px; font-weight: 700; }
.empty, .error { color: #6b7280; text-align: center; }
.error { color: #b91c1c; }
@media (max-width: 1024px) { .main { margin-left: 0; padding: 16px; } }
@media (max-width: 700px) { .header { align-items: flex-start; flex-direction: column; } .detail-grid { grid-template-columns: 1fr; } .detail.full { grid-column: auto; } }
</style>
</head>
<body>
<?php include 'admin_topbar.php'; ?>
<?php include 'admin_sidebar.php'; ?>
<div class="main"><div class="shell">
    <div class="header">
        <div>
            <h1><?php echo $view === 'complaints' ? 'Student Complaints' : 'Reported Complaints'; ?></h1>
            <p class="subtitle"><?php echo $view === 'complaints' ? 'Recent complaints filed by ' : 'Complaints involving '; ?><?php echo e($studentName); ?></p>
        </div>
        <a class="back" href="javascript:history.back()"><i class='bx bx-arrow-back'></i> Back to Reports</a>
    </div>

    <?php if ($loadError !== ''): ?>
        <div class="card error"><?php echo e($loadError); ?></div>
    <?php elseif (count($complaints) === 0): ?>
        <div class="card empty">No complaint details were found for this student.</div>
    <?php else: ?>
        <?php foreach ($complaints as $complaint): ?>
            <section class="card">
                <div class="ticket-title">
                    <span>Complainant &amp; Incident Details</span>
                </div>
                <div class="detail-grid">
                    <div class="detail"><label>Complainant Name</label><p><?php echo e((string)$complaint['complainant_name']); ?></p></div>
                    <div class="detail"><label>Contact Details</label><p><?php echo e((string)$complaint['complainant_contact_details']); ?></p></div>
                    <div class="detail"><label>Date / Time</label><p><?php echo e(trim((string)$complaint['date_of_incident'] . ' ' . (string)$complaint['time_of_incident'])); ?></p></div>
                    <div class="detail"><label>Place of Incident</label><p><?php echo e((string)$complaint['place_of_incident']); ?></p></div>
                    <div class="detail full"><label>Person Complained Of</label><p><?php echo e((string)$complaint['person_complained_of']); ?></p></div>
                    <div class="detail full"><label>Act Complained Of</label><p><?php echo e((string)$complaint['act_complained_of']); ?></p></div>
                </div>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>
</div></div>
</body>
</html>
