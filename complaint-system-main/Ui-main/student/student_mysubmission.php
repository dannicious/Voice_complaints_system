<?php
session_start();
require_once __DIR__ . '/../db_connection.php';

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$submissions = [];
$loadError = '';

if (!isset($_SESSION['user_id']) || (string)($_SESSION['role'] ?? '') !== 'student') {
    $loadError = 'Please log in as a student to view your submissions.';
} else {
    try {
        $studentStmt = $pdo->prepare(
            'SELECT id
             FROM student_profiles
             WHERE user_id = :user_id
             LIMIT 1'
        );
        $studentStmt->execute([':user_id' => (int)$_SESSION['user_id']]);
        $student = $studentStmt->fetch();

        if (!$student) {
            $loadError = 'Student profile not found.';
        } else {
            $studentProfileId = (int)$student['id'];

            $sql = "
                SELECT
                    c.id AS ticket_id,
                    c.ticket_no AS ticket_no,
                    'complaint' AS type,
                    c.act_complained_of AS subject,
                    COALESCE(cc.name, 'Uncategorized') AS category_name,
                    c.created_at AS submitted_at,
                    c.status AS raw_status
                FROM complaints c
                LEFT JOIN complaint_categories cc ON cc.id = c.category_id
                                WHERE c.student_id = :student_id_complaint

                UNION ALL

                SELECT
                    s.id AS ticket_id,
                    s.ticket_no AS ticket_no,
                    'suggestion' AS type,
                    s.subject AS subject,
                    COALESCE(sc.name, 'Uncategorized') AS category_name,
                    s.created_at AS submitted_at,
                    s.status AS raw_status
                FROM suggestions s
                LEFT JOIN suggestion_categories sc ON sc.id = s.category_id
                WHERE s.student_id = :student_id_suggestion

                ORDER BY submitted_at DESC
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':student_id_complaint' => $studentProfileId,
                ':student_id_suggestion' => $studentProfileId,
            ]);
            $rows = $stmt->fetchAll();

            foreach ($rows as $row) {
                $type = (string)$row['type'];
                $status = strtolower((string)$row['raw_status']);

                $statusClass = 'status-review';
                $statusIcon = 'bx-search-alt-2';
                $statusLabel = 'Under Review';

                if (in_array($status, ['new', 'pending'], true)) {
                    $statusClass = 'status-pending';
                    $statusIcon = 'bx-time-five';
                    $statusLabel = 'Pending';
                } elseif (in_array($status, ['under_review', 'review', 'flagged'], true)) {
                    $statusClass = 'status-review';
                    $statusIcon = 'bx-search-alt-2';
                    $statusLabel = 'Under Review';
                } elseif (in_array($status, ['resolved'], true)) {
                    $statusClass = 'status-resolved';
                    $statusIcon = 'bx-check-circle';
                    $statusLabel = 'Resolved';
                } elseif (in_array($status, ['dismissed'], true)) {
                    $statusClass = 'status-dismissed';
                    $statusIcon = 'bx-x-circle';
                    $statusLabel = 'Dismissed';
                } elseif (in_array($status, ['approved'], true)) {
                    $statusClass = 'status-approved';
                    $statusIcon = 'bx-check-circle';
                    $statusLabel = 'Approved';
                } elseif (in_array($status, ['reviewed'], true)) {
                    $statusClass = 'status-reviewed';
                    $statusIcon = 'bx-check-circle';
                    $statusLabel = 'Reviewed';
                } elseif (in_array($status, ['declined', 'rejected', 'inactive'], true)) {
                    $statusClass = 'status-pending';
                    $statusIcon = 'bx-x-circle';
                    $statusLabel = ucfirst($status);
                } else {
                    $statusClass = 'status-review';
                    $statusIcon = 'bx-question-mark';
                    $statusLabel = ucfirst(str_replace('_', ' ', $status));
                }

                $submissions[] = [
                    'ticket_id' => (int)$row['ticket_id'],
                    'ticket_no' => (string)$row['ticket_no'],
                    'type' => $type,
                    'type_badge_class' => $type === 'complaint' ? 'badge-type-complaint' : 'badge-type-suggestion',
                    'type_label' => $type === 'complaint' ? 'Complaint' : 'Suggestion',
                    'subject' => (string)$row['subject'],
                    'category' => (string)$row['category_name'],
                    'date_text' => date('M d, Y', strtotime((string)$row['submitted_at'])),
                    'status_class' => $statusClass,
                    'status_icon' => $statusIcon,
                    'status_label' => $statusLabel,
                ];
            }
        }
    } catch (PDOException $e) {
        $loadError = 'Unable to load submissions right now.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>My Submissions - VOICE</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>

<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: 'Poppins', sans-serif;
}

body {
    background: #f4f6fb; 
}

/* ===== MAIN CONTENT ===== */
/* Pushed right by sidebar width, pushed down by topbar height */
.main {
    margin-left: 260px;
    margin-top: 61px;
    padding: 25px;
    min-height: calc(100vh - 61px);
    overflow-y: auto;
}

/* ===== PAGE STYLES ===== */
.page-title {
    margin-bottom: 25px;
    color: #333;
    font-weight: 600;
    font-size: 24px;
}

.data-card {
    background: #fff;
    padding: 25px;
    border-radius: 12px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.03);
    width: 100%;
    border: 1px solid #eee;
    overflow-x: auto; /* Ensures table is scrollable on small screens */
}

/* Table Styles */
table { 
    width: 100%; 
    border-collapse: collapse; 
    min-width: 800px; /* Prevents columns from getting too squished */
}

th { 
    text-align: left; 
    font-size: 13px; 
    color: #888; 
    padding: 15px 10px; 
    border-bottom: 2px solid #f0f0f0; 
    font-weight: 600; 
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

td { 
    padding: 18px 10px; 
    font-size: 14px; 
    color: #444; 
    border-bottom: 1px solid #f9f9f9; 
    vertical-align: middle;
}

tr:hover td {
    background: #fcfcfc;
}

/* Badges */
.badge {
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 500;
    display: inline-block;
}

.badge-type-complaint { background: #fef2f2; color: #ef4444; border: 1px solid #fca5a5; }
.badge-type-suggestion { background: #f0fdf4; color: #10b981; border: 1px solid #6ee7b7; }

.status-pending { color: #f59e0b; font-weight: 600; display: flex; align-items: center; gap: 5px; }
.status-review { color: #3b82f6; font-weight: 600; display: flex; align-items: center; gap: 5px; }
.status-resolved { color: #10b981; font-weight: 600; display: flex; align-items: center; gap: 5px; }
.status-dismissed { color: #6b7280; font-weight: 600; display: flex; align-items: center; gap: 5px; }
.status-approved { color: #0f766e; font-weight: 600; display: flex; align-items: center; gap: 5px; }
.status-reviewed { color: #047857; font-weight: 600; display: flex; align-items: center; gap: 5px; }
.status-closed { color: #6b7280; font-weight: 600; display: flex; align-items: center; gap: 5px; }

/* Action Button */
.btn-view {
    padding: 8px 15px;
    background: #f4f6fb;
    color: #6d28d9;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    transition: 0.2s;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

.btn-view:hover {
    background: #6d28d9;
    color: white;
    border-color: #4F8CFF;
}

.subject-text {
    font-weight: 500;
    color: #1f2937;
}

.date-text {
    color: #6b7280;
    font-size: 13px;
}

</style>
</head>

<body>

<?php include 'student_topbar.php'; ?>

<?php include 'student_sidebar.php'; ?>

<div class="main">

    <h2 class="page-title">My Tracking List</h2>
    
    <div class="data-card">
        <table>
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Subject & Category</th>
                    <th>Date Submitted</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($loadError !== ''): ?>
                    <tr>
                        <td colspan="5"><?php echo e($loadError); ?></td>
                    </tr>
                <?php elseif (count($submissions) === 0): ?>
                    <tr>
                        <td colspan="5">No submissions yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($submissions as $item): ?>
                        <tr>
                            <td><span class="badge <?php echo e($item['type_badge_class']); ?>"><?php echo e($item['type_label']); ?></span></td>
                            <td>
                                <div class="subject-text"><?php echo e($item['subject']); ?></div>
                                <div style="font-size: 12px; color: #888;"><?php echo e($item['category']); ?></div>
                            </td>
                            <td><span class="date-text"><?php echo e($item['date_text']); ?></span></td>
                            <td><span class="<?php echo e($item['status_class']); ?>"><?php echo e($item['status_label']); ?></span></td>
                            <td><a class="btn-view" href="ticket_detail.php?type=<?php echo e($item['type']); ?>&id=<?php echo e((string)$item['ticket_id']); ?>">View <i class='bx bx-right-arrow-alt'></i></a></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>

</body>
</html>