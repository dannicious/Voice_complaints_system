<?php
require_once __DIR__ . '/../../config/auth.php';
require_once '../db_connection.php';

ensure_role('dean');

// Get dean's college
$deanProfileStmt = $pdo->prepare('SELECT college_id FROM dean_profiles WHERE user_id = :user_id');
$deanProfileStmt->execute([':user_id' => $_SESSION['user_id']]);
$deanProfile = $deanProfileStmt->fetch(PDO::FETCH_ASSOC);
$collegeId = $deanProfile['college_id'] ?? null;

if (!$collegeId) {
    // Fallback: get any college if no profile found
    $collegeStmt = $pdo->prepare('SELECT id FROM colleges LIMIT 1');
    $collegeStmt->execute();
    $college = $collegeStmt->fetch(PDO::FETCH_ASSOC);
    $collegeId = $college['id'] ?? 1;
}

// Get college information
$collegeStmt = $pdo->prepare('SELECT code, name FROM colleges WHERE id = :id');
$collegeStmt->execute([':id' => $collegeId]);
$collegeInfo = $collegeStmt->fetch(PDO::FETCH_ASSOC);
$collegeName = $collegeInfo['name'] ?? 'Unknown College';

// Get metrics for dean's college
 $metrics = ['new_complaints' => 0, 'new_suggestions' => 0, 'reviewed_suggestions' => 0, 'resolved_month' => 0];

// New complaints (admin approved, dean hasn't started)
$newComplaintsStmt = $pdo->prepare('
    SELECT COUNT(*) as count FROM complaints 
    WHERE college_id = ? AND approval_status = "approved" AND status = "new" AND ticket_no NOT LIKE "VOX-C-2026-%"
');
$newComplaintsStmt->execute([$collegeId]);
$metrics['new_complaints'] = $newComplaintsStmt->fetch(PDO::FETCH_ASSOC)['count'];

// New suggestions (admin approved and ready for dean review)
$newSuggestionsStmt = $pdo->prepare('
    SELECT COUNT(*) as count FROM suggestions 
    WHERE college_id = ? AND status IN ("approved", "new")
');
$newSuggestionsStmt->execute([$collegeId]);
$metrics['new_suggestions'] = $newSuggestionsStmt->fetch(PDO::FETCH_ASSOC)['count'];

// Implemented suggestions
$implementedStmt = $pdo->prepare('
    SELECT COUNT(*) as count FROM suggestions
    WHERE college_id = ? AND status = "reviewed"
');
$implementedStmt->execute([$collegeId]);
$metrics['reviewed_suggestions'] = $implementedStmt->fetch(PDO::FETCH_ASSOC)['count'];

// Resolved this month
$monthStartStmt = $pdo->prepare('
    SELECT COUNT(*) as count FROM (
        SELECT id FROM complaints WHERE college_id = ? AND approval_status = "approved" AND status = "resolved" AND ticket_no NOT LIKE "VOX-C-2026-%" AND MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW())
        UNION ALL
        SELECT id FROM suggestions WHERE college_id = ? AND status IN ("approved", "reviewed") AND MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW())
    ) as resolved_items
');
$monthStartStmt->execute([$collegeId, $collegeId]);
$metrics['resolved_month'] = $monthStartStmt->fetch(PDO::FETCH_ASSOC)['count'];

// Get recent submissions for dean's college (6 most recent)
// Only show admin-approved items
$recentStmt = $pdo->prepare('
    (SELECT c.ticket_no as ticket_number, sp.first_name, sp.last_name, "complaint" as type,
            COALESCE(cc.name, "Uncategorized") as category, c.status, c.created_at as submission_date, c.id
     FROM complaints c
     LEFT JOIN student_profiles sp ON c.student_id = sp.id
     LEFT JOIN complaint_categories cc ON c.category_id = cc.id
    WHERE c.college_id = ? AND c.approval_status = "approved" AND c.ticket_no NOT LIKE "VOX-C-2026-%"
     ORDER BY c.created_at DESC LIMIT 3)
    UNION ALL
    (SELECT s.ticket_no as ticket_number, sp.first_name, sp.last_name, "suggestion" as type,
            COALESCE(sc.name, "Uncategorized") as category, s.status, s.created_at as submission_date, s.id
     FROM suggestions s
     LEFT JOIN student_profiles sp ON s.student_id = sp.id
     LEFT JOIN suggestion_categories sc ON s.category_id = sc.id
     WHERE s.college_id = ? AND s.status NOT IN ("new", "rejected", "declined")
     ORDER BY s.created_at DESC LIMIT 3)
    ORDER BY submission_date DESC
    LIMIT 6
');
$recentStmt->execute([$collegeId, $collegeId]);
$recentItems = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

// Function to format time difference
function getTimeAgo($datetime) {
    $now = new DateTime();
    $then = new DateTime($datetime);
    $diff = $now->diff($then);
    
    if ($diff->days > 0) {
        return $diff->days . ' day' . ($diff->days > 1 ? 's' : '') . ' ago';
    }
    if ($diff->h > 0) {
        return $diff->h . ' hr' . ($diff->h > 1 ? 's' : '') . ' ago';
    }
    if ($diff->i > 0) {
        return $diff->i . ' min' . ($diff->i > 1 ? 's' : '') . ' ago';
    }
    return 'Just now';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Dean Dashboard - VOICE</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>

<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: 'Poppins', sans-serif;
}

body {
    background: #f9fafb;
}

/* ===== MAIN CONTENT ===== */
.main {
    margin-left: 260px;
    margin-top: 61px;
    padding: 25px;
    min-height: calc(100vh - 61px);
    overflow-y: auto;
}

/* ===== WELCOME BANNER ===== */
.welcome-banner {
    background: #6d28d9;
    border-radius: 16px;
    padding: 30px;
    color: white;
    margin-bottom: 30px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    box-shadow: 0 4px 15px rgba(109, 40, 217, 0.2);
}

.welcome-text h1 {
    font-size: 24px;
    font-weight: 600;
    margin-bottom: 5px;
}

.welcome-text p {
    font-size: 14px;
    opacity: 0.9;
}

.date-badge {
    background: rgba(255, 255, 255, 0.2);
    padding: 8px 16px;
    border-radius: 30px;
    font-size: 13px;
    font-weight: 500;
    backdrop-filter: blur(5px);
    display: flex;
    align-items: center;
    gap: 8px;
}

/* ===== METRICS GRID ===== */
.metrics-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
    margin-bottom: 30px;
}

@media (max-width: 1200px) {
    .metrics-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .metrics-grid {
        grid-template-columns: 1fr;
    }
}

.metric-card {
    background: #fff;
    padding: 20px;
    border-radius: 12px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 2px 10px rgba(0,0,0,0.02);
    display: flex;
    align-items: center;
    gap: 20px;
    transition: transform 0.2s ease;
}

.metric-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.05);
}

.metric-icon {
    width: 60px;
    height: 60px;
    border-radius: 12px;
    display: flex;
    justify-content: center;
    align-items: center;
    font-size: 28px;
}

.icon-blue { background: #eff6ff; color: #3b82f6; }
.icon-yellow { background: #fef3c7; color: #d97706; }
.icon-green { background: #dcfce7; color: #16a34a; }
.icon-red { background: #fee2e2; color: #dc2626; }
.icon-purple { background: #f3e8ff; color: #7c3aed; }

.metric-info h4 {
    color: #6b7280;
    font-size: 13px;
    font-weight: 500;
    text-transform: uppercase;
    margin-bottom: 5px;
}

.metric-info .value {
    font-size: 24px;
    font-weight: 700;
    color: #1f2937;
}

/* ===== DASHBOARD SPLIT GRID ===== */
.dashboard-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 20px;
    align-items: start;
}

.dashboard-grid .dash-card {
    grid-column: 1;
    width: 100%;
}

/* Cards Shared Styles */
.dash-card {
    background: #fff;
    padding: 25px;
    border-radius: 12px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 2px 10px rgba(0,0,0,0.02);
}

.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.card-header h3 {
    font-size: 16px;
    color: #1f2937;
    font-weight: 600;
}

.view-all {
    color: #6d28d9;
    font-size: 13px;
    text-decoration: none;
    font-weight: 500;
}

.view-all:hover { text-decoration: underline; }

/* ===== RECENT SUBMISSIONS TABLE ===== */
table { width: 100%; border-collapse: collapse; }
th { text-align: left; font-size: 12px; color: #888; padding-bottom: 15px; border-bottom: 2px solid #f0f0f0; font-weight: 600; text-transform: uppercase; }
td { padding: 15px 0; font-size: 14px; color: #444; border-bottom: 1px solid #f9f9f9; vertical-align: middle; }
.type-complaint { color: #dc2626; font-weight: 500; background: #fee2e2; padding: 4px 8px; border-radius: 6px; font-size: 12px;}
.type-suggestion { color: #16a34a; font-weight: 500; background: #dcfce7; padding: 4px 8px; border-radius: 6px; font-size: 12px;}

/* ===== URGENT LIST ===== */
.urgent-list {
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.urgent-item {
    border-left: 4px solid #ef4444;
    background: #fafafa;
    padding: 15px;
    border-radius: 0 8px 8px 0;
    transition: 0.2s;
    cursor: pointer;
}

.urgent-item:hover { background: #f3f4f6; }

.urgent-header {
    display: flex;
    justify-content: space-between;
    margin-bottom: 8px;
}

.urgent-title {
    font-size: 14px;
    font-weight: 600;
    color: #1f2937;
}

.urgent-time {
    font-size: 12px;
    color: #9ca3af;
}

.urgent-desc {
    font-size: 13px;
    color: #6b7280;
    line-height: 1.4;
    display: -webkit-box;
    line-clamp: 2;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

@media (max-width: 1024px) {
    .dashboard-grid { grid-template-columns: 1fr; }
}

@media (max-width: 768px) {
    .welcome-banner { flex-direction: column; text-align: center; gap: 15px; }
}
</style>
</head>

<body>

<!-- TOPBAR -->
<?php include 'dean_topbar.php'; ?>

<!-- SIDEBAR -->
<?php include 'dean_sidebar.php'; ?>

<!-- MAIN CONTENT -->
<div class="main">

    <!-- Welcome Banner -->
    <div class="welcome-banner">
        <div class="welcome-text">
            <h1>Welcome back, Dean!</h1>
            <p>Here's what is happening in <?php echo htmlspecialchars($collegeName); ?> today.</p>
        </div>
        <div class="date-badge">
            <i class='bx bx-calendar'></i>
            <span id="currentDate"><?php echo date('l, F j, Y'); ?></span>
        </div>
    </div>

    <!-- Quick Action Metrics -->
    <div class="metrics-grid">
        <div class="metric-card">
            <div class="metric-icon icon-blue"><i class='bx bx-message-square-detail'></i></div>
            <div class="metric-info">
                <h4>New Complaints</h4>
                <div class="value"><?php echo $metrics['new_complaints']; ?></div>
            </div>
        </div>
        <div class="metric-card">
            <div class="metric-icon icon-purple"><i class='bx bx-bulb'></i></div>
            <div class="metric-info">
                <h4>New Suggestions</h4>
                <div class="value"><?php echo $metrics['new_suggestions']; ?></div>
            </div>
        </div>
        <div class="metric-card">
            <div class="metric-icon icon-yellow"><i class='bx bx-rocket'></i></div>
            <div class="metric-info">
                <h4>Reviewed Suggestions</h4>
                <div class="value"><?php echo $metrics['reviewed_suggestions']; ?></div>
            </div>
        </div>
        <div class="metric-card">
            <div class="metric-icon icon-green"><i class='bx bx-check-circle'></i></div>
            <div class="metric-info">
                <h4>Resolved Complaints</h4>
                <div class="value"><?php echo $metrics['resolved_month']; ?></div>
            </div>
        </div>
    </div>

    <!-- Main Content Split -->
    <div class="dashboard-grid">
        
        <!-- Full Width: Recent Activity -->
        <div class="dash-card">
            <div class="card-header">
                <h3>Recent Submissions</h3>
                <a href="dean_reports.php" class="view-all">View All</a>
            </div>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Subject</th>
                            <th>Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentItems)): ?>
                        <tr>
                            <td colspan="4" style="text-align: center; color: #aaa;">No recent submissions</td>
                        </tr>
                        <?php else: ?>
                            <?php foreach ($recentItems as $item): ?>
                            <tr>
                                <td><span class="type-<?php echo strtolower($item['type']); ?>"><?php echo ucfirst($item['type']); ?></span></td>
                                <td><?php echo htmlspecialchars($item['category'] ?? 'No subject'); ?></td>
                                <td><?php echo date('M d, Y', strtotime($item['submission_date'])); ?></td>
                                <td><span style="color:<?php echo $item['status'] === 'resolved' ? '#16a34a' : (in_array($item['status'], ['new', 'pending']) ? '#3b82f6' : '#d97706'); ?>; font-size:13px; font-weight:500;">
                                    <?php
                                        $displayStatus = $item['status'];
                                        if ($displayStatus === 'pending') {
                                            $displayStatus = 'new';
                                        }
                                        echo ucfirst(str_replace('_', ' ', $displayStatus));
                                    ?>
                                </span></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

</div>

</body>
</html>