<?php
session_start();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../suggestion_flow.php';
require_once __DIR__ . '/../school_year_helpers.php';

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$submissions = [];
$loadError = '';
$schoolYearCurrent = sy_current($pdo);
$schoolYearSelected = $schoolYearCurrent;
$isPastSchoolYear = false;
$semesterCurrent = semester_current($pdo);
$semesterSelected = $semesterCurrent;
$isPastSemester = false;

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
            $schoolYearSelected = sy_get_selected($pdo, $studentProfileId);
            $isPastSchoolYear = $schoolYearSelected !== $schoolYearCurrent;
            $semesterSelected = semester_get_selected($pdo);
            $isPastSemester = $semesterSelected !== $semesterCurrent;

            $sql = "
                SELECT
                    c.id AS record_id,
                    'complaint' AS type,
                    c.act_complained_of AS subject,
                    COALESCE(cc.name, 'Uncategorized') AS category_name,
                    c.created_at AS submitted_at,
                    c.status AS raw_status,
                    c.college_id AS college_id,
                    NULL AS office
                FROM complaints c
                LEFT JOIN complaint_categories cc ON cc.id = c.category_id
                                WHERE c.student_id = :student_id_complaint AND c.school_year = :school_year_complaint AND c.semester = :semester_complaint

                UNION ALL

                SELECT
                    s.id AS record_id,
                    'suggestion' AS type,
                    s.subject AS subject,
                    COALESCE(sc.name, 'Uncategorized') AS category_name,
                    s.created_at AS submitted_at,
                    s.status AS raw_status,
                    s.college_id AS college_id,
                    s.office AS office
                FROM suggestions s
                LEFT JOIN suggestion_categories sc ON sc.id = s.category_id
                WHERE s.student_id = :student_id_suggestion AND s.school_year = :school_year_suggestion AND s.semester = :semester_suggestion

                ORDER BY submitted_at DESC
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':student_id_complaint' => $studentProfileId,
                ':school_year_complaint' => $schoolYearSelected,
                ':semester_complaint' => $semesterSelected,
                ':student_id_suggestion' => $studentProfileId,
                ':school_year_suggestion' => $schoolYearSelected,
                ':semester_suggestion' => $semesterSelected,
            ]);
            $rows = $stmt->fetchAll();

            foreach ($rows as $row) {
                $type = (string)$row['type'];
                $status = strtolower((string)$row['raw_status']);

                if ($type === 'suggestion') {
                    // Suggestions use their own decision/implementation status
                    // vocabulary (needs_info, accepted, planned, in_progress, ...)
                    // - see suggestion_flow.php for the single source of truth.
                    $suggestionMeta = suggestion_status_meta($status);
                    $statusClass = $suggestionMeta['badge'];
                    $statusLabel = $suggestionMeta['label'];
                    $statusIcon = $suggestionMeta['locked'] ? 'bx-check-circle' : 'bx-search-alt-2';
                } else {
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
                }

                // Same "who handles this" logic as admindashboard.php's Recent
                // Suggestions & Complaints table - an office name wins if set,
                // otherwise a college means the dean handles it, otherwise it's
                // routed to the admin (SAS Director).
                $officeName = trim((string)($row['office'] ?? ''));
                if ($officeName !== '') {
                    $managedBy = $officeName;
                } elseif ($row['college_id'] !== null) {
                    $managedBy = 'Dean';
                } else {
                    $managedBy = 'SAS Office';
                }

                $submissions[] = [
                    'record_id' => (int)$row['record_id'],
                    'type' => $type,
                    'type_badge_class' => $type === 'complaint' ? 'badge-type-complaint' : 'badge-type-suggestion',
                    'type_label' => $type === 'complaint' ? 'Complaint' : 'Suggestion',
                    'subject' => (string)$row['subject'],
                    'category' => (string)$row['category_name'],
                    'managed_by' => $managedBy,
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

.sy-readonly-banner {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    background: #fffbeb;
    border: 1px solid #fcd34d;
    color: #92400e;
    border-radius: 10px;
    padding: 14px 16px;
    margin-bottom: 20px;
    font-size: 13.5px;
    line-height: 1.5;
}

.sy-readonly-banner i {
    font-size: 20px;
    flex-shrink: 0;
    margin-top: 1px;
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
.status-needs-info { color: #92400e; font-weight: 600; display: flex; align-items: center; gap: 5px; }
.status-accepted { color: #1d4ed8; font-weight: 600; display: flex; align-items: center; gap: 5px; }
.status-planned { color: #3730a3; font-weight: 600; display: flex; align-items: center; gap: 5px; }
.status-progress { color: #5b21b6; font-weight: 600; display: flex; align-items: center; gap: 5px; }
.status-implemented { color: #065f46; font-weight: 600; display: flex; align-items: center; gap: 5px; }
.status-declined { color: #b91c1c; font-weight: 600; display: flex; align-items: center; gap: 5px; }
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

.scroll-top-btn { position: fixed; right: 24px; bottom: 24px; width: 44px; height: 44px; border-radius: 999px; background: #6b46c1; color: #fff; border: none; display: none; align-items: center; justify-content: center; cursor: pointer; box-shadow: 0 10px 24px rgba(107, 70, 193, 0.35); z-index: 500; transition: background .15s ease, transform .15s ease, opacity .2s ease; opacity: 0; transform: translateY(8px); }
.scroll-top-btn.visible { display: flex; opacity: 1; transform: translateY(0); }
.scroll-top-btn:hover { background: #5b3aa8; }
.scroll-top-btn i { font-size: 22px; }

@media (max-width: 1024px) {
    .main { margin-left: 0 !important; padding: 16px !important; }
}

@media (max-width: 700px) {
    .page-title { font-size: 20px; margin-bottom: 16px; }
    .data-card { padding: 14px; overflow-x: visible; }

    /* A 5-column table can't fit a phone screen readably even scrolled
       sideways, so each submission becomes a stacked card instead - the
       column labels (data-label, set in the PHP above) show through
       td::before since the table header row is hidden here. */
    table { min-width: 0; }
    thead { display: none; }
    table, tbody, tr, td { display: block; width: 100%; }
    tbody tr {
        border: 1px solid #eef0f3;
        border-radius: 10px;
        padding: 12px 14px;
        margin-bottom: 12px;
    }
    tbody tr:last-child { margin-bottom: 0; }
    td { padding: 4px 0; border-bottom: none; }
    td[data-label]::before {
        content: attr(data-label);
        display: block;
        font-size: 11px;
        font-weight: 700;
        color: #9ca3af;
        text-transform: uppercase;
        letter-spacing: .03em;
        margin-bottom: 2px;
    }
    td.td-action { padding-top: 8px; }
    td.td-action .btn-view { width: 100%; justify-content: center; padding: 10px 15px; }
}

</style>
</head>

<body>

<?php include 'student_topbar.php'; ?>

<?php include 'student_sidebar.php'; ?>

<div class="main">

    <h2 class="page-title">My Tracking List</h2>

    <?php if ($isPastSchoolYear || $isPastSemester): ?>
        <div class="sy-readonly-banner">
            <i class='bx bx-lock-alt'></i>
            <span>
                You're viewing School Year <strong><?php echo e($schoolYearSelected); ?></strong>,
                <strong><?php echo e(semester_display_label($semesterSelected)); ?></strong> (read-only).
                Filing new complaints and suggestions is only available for the current school year and semester
                (<strong><?php echo e($schoolYearCurrent); ?></strong>, <strong><?php echo e(semester_display_label($semesterCurrent)); ?></strong>).
            </span>
        </div>
    <?php endif; ?>

    <div class="data-card">
        <table>
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Category & Managed By</th>
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
                        <td colspan="5">No submission data for this semester.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($submissions as $item): ?>
                        <tr>
                            <td data-label="Type"><span class="badge <?php echo e($item['type_badge_class']); ?>"><?php echo e($item['type_label']); ?></span></td>
                            <td data-label="Category & Managed By" class="td-category">
                                <div class="subject-text"><?php echo e($item['category']); ?></div>
                                <div style="font-size: 12px; color: #888;"><?php echo e($item['managed_by']); ?></div>
                            </td>
                            <td data-label="Date Submitted"><span class="date-text"><?php echo e($item['date_text']); ?></span></td>
                            <td data-label="Status"><span class="<?php echo e($item['status_class']); ?>"><?php echo e($item['status_label']); ?></span></td>
                            <td class="td-action"><a class="btn-view" href="ticket_detail.php?type=<?php echo e($item['type']); ?>&id=<?php echo e((string)$item['record_id']); ?>">View <i class='bx bx-right-arrow-alt'></i></a></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>

<button type="button" id="scrollTopBtn" class="scroll-top-btn" aria-label="Scroll to top" title="Back to top">
    <i class='bx bx-up-arrow-alt'></i>
</button>
<script>
document.addEventListener('DOMContentLoaded', function () {
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