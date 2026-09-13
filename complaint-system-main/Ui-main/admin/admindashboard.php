<?php
session_start();
require_once '../db_connection.php';

// Check admin access
if (!isset($_SESSION['user_id'])) {
    header('Location: /complaint-system-main/admin/login.php');
    exit;
}

// Function to verify admin role with DB fallback
function require_admin_session($pdo) {
    if (!isset($_SESSION['user_id'])) {
        header('Location: /complaint-system-main/admin/login.php');
        exit;
    }
    
    if (($_SESSION['role'] ?? '') === 'admin') {
        return true;
    }
    
    $stmt = $pdo->prepare('SELECT role FROM users WHERE id = :id');
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user && $user['role'] === 'admin') {
        $_SESSION['role'] = 'admin';
        return true;
    }
    
    header('Location: /complaint-system-main/admin/login.php');
    exit;
}

require_admin_session($pdo);

// Get dashboard statistics
$stats = ['complaints' => 0, 'suggestions' => 0, 'students' => 0, 'pending_complaints' => 0, 'pending_suggestions' => 0];

// Total complaints
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM complaints");
$stmt->execute();
$stats['complaints'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM complaints WHERE COALESCE(status, 'new') <> 'resolved' AND COALESCE(approval_status, 'pending') <> 'rejected'");
$stmt->execute();
$stats['pending_complaints'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Total suggestions
$stmt = $pdo->prepare('SELECT COUNT(*) as count FROM suggestions');
$stmt->execute();
$stats['suggestions'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM suggestions WHERE COALESCE(status, 'pending') NOT IN ('reviewed', 'resolved', 'rejected', 'declined')");
$stmt->execute();
$stats['pending_suggestions'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Registered students
$stmt = $pdo->prepare('SELECT COUNT(*) as count FROM users WHERE role = "student"');
$stmt->execute();
$stats['students'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Complaints + suggestions submitted today
$stmt = $pdo->prepare('SELECT COUNT(*) as count FROM complaints WHERE DATE(created_at) = CURDATE()');
$stmt->execute();
$todayComplaints = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];

$stmt = $pdo->prepare('SELECT COUNT(*) as count FROM suggestions WHERE DATE(created_at) = CURDATE()');
$stmt->execute();
$todaySuggestions = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];

$stats['today_total'] = $todayComplaints + $todaySuggestions;

// Get complaints and suggestions per college for chart
$collegeSql = $pdo->prepare('
    SELECT c.code, 
           COUNT(DISTINCT comp.id) as complaint_count,
           COUNT(DISTINCT sug.id) as suggestion_count
    FROM colleges c
    LEFT JOIN complaints comp ON c.id = comp.college_id
    LEFT JOIN suggestions sug ON c.id = sug.college_id
    GROUP BY c.id, c.code
    ORDER BY c.code
');
$collegeSql->execute();
$collegeData = $collegeSql->fetchAll(PDO::FETCH_ASSOC);

// Prepare chart data
$chartLabels = [];
$chartComplaints = [];
$chartSuggestions = [];
foreach ($collegeData as $college) {
    $chartLabels[] = $college['code'];
    $chartComplaints[] = $college['complaint_count'];
    $chartSuggestions[] = $college['suggestion_count'];
}

// Get recent complaints and suggestions (6 most recent)
$recentSql = $pdo->prepare('
    (SELECT c.ticket_no as ticket_number, sp.first_name, sp.last_name, "complaint" as type,
            COALESCE(cc.name, "Uncategorized") as category, c.status, c.created_at as submission_date, c.id,
            c.college_id, NULL as office
     FROM complaints c
     LEFT JOIN student_profiles sp ON c.student_id = sp.id
     LEFT JOIN complaint_categories cc ON c.category_id = cc.id
    WHERE 1 = 1
     ORDER BY c.created_at DESC LIMIT 3)
    UNION ALL
    (SELECT s.ticket_no as ticket_number, sp.first_name, sp.last_name, "suggestion" as type,
            COALESCE(sc.name, "Uncategorized") as category, s.status, s.created_at as submission_date, s.id,
            s.college_id, s.office
     FROM suggestions s
     LEFT JOIN student_profiles sp ON s.student_id = sp.id
     LEFT JOIN suggestion_categories sc ON s.category_id = sc.id
     ORDER BY s.created_at DESC LIMIT 3)
    ORDER BY submission_date DESC
    LIMIT 6
');
$recentSql->execute();
$recentItems = $recentSql->fetchAll(PDO::FETCH_ASSOC);

// Get colleges with overdue complaints (>48hrs)
$overdueCollegeSql = $pdo->prepare('
    SELECT c.code as college_name, COUNT(comp.id) as overdue_count
    FROM colleges c
    LEFT JOIN complaints comp ON c.id = comp.college_id
    WHERE comp.created_at < DATE_SUB(NOW(), INTERVAL 48 HOUR) AND (comp.status = "new" OR comp.status = "pending")
    GROUP BY c.id, c.code
    ORDER BY overdue_count DESC
    LIMIT 3
');
$overdueCollegeSql->execute();
$overdueColleges = $overdueCollegeSql->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>VOICE Admin Dashboard</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

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
/* pushed right by sidebar width, pushed down by topbar height */
.main {
    margin-left: 260px;
    margin-top: 61px;
    padding: 25px;
    min-height: calc(100vh - 61px);
    overflow-y: auto;
}

/* ===== DASHBOARD CONTENT ===== */
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

/* Stat Cards */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 22px;
    margin-bottom: 25px;
    align-items: stretch;
}

.stat-card {
    background: #fff;
    padding: 22px 20px;
    border-radius: 16px;
    position: relative;
    display: flex;
    align-items: center;
    justify-content: flex-start;
    gap: 16px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.03);
    min-height: 120px;
    border: 1px solid #edf1f7;
}

.dashboard-badge {
    position: absolute;
    top: 14px;
    right: 18px;
    min-width: 28px;
    height: 28px;
    padding: 0 8px;
    border-radius: 50%;
    background: #dc2626;
    color: #fff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: 700;
    line-height: 1;
}

.stat-icon {
    width: 52px;
    height: 52px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    color: #fff;
    flex-shrink: 0;
}

.stat-info {
    display: flex;
    flex-direction: column;
    justify-content: center;
    min-width: 0;
}

.stat-info h3 { font-size: 26px; font-weight: 700; color: #222; margin: 0 0 4px; line-height: 1.1; }
.stat-info p  { font-size: 13px; color: #666; margin: 0; line-height: 1.4; }

.bg-green  { background: #10b981; }
.bg-blue   { background: #3b82f6; }
.bg-yellow { background: #f59e0b; }
.bg-purple { background: #8b5cf6; }

.content-layout {
    display: flex;
    flex-direction: column;
    gap: 25px;
}

.chart-card {
    background: #fff;
    padding: 25px;
    border-radius: 12px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.03);
}

.chart-filters {
    display: flex;
    gap: 10px;
    align-items: center;
}

.filter-select {
    padding: 8px 12px;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    background: #f4f6fb;
    font-size: 12px;
    color: #555;
    outline: none;
    cursor: pointer;
}

.chart-container {
    position: relative;
    height: 300px;
    width: 100%;
    margin-top: 15px;
}

.data-card {
    background: #fff;
    padding: 25px;
    border-radius: 12px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.03);
    overflow-x: auto;
}

.card-header-flex {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.card-header-flex h4 { font-weight: 600; color: #333; }

.btn-blue {
    padding: 6px 15px;
    background: #0056b3;
    color: #fff;
    border-radius: 6px;
    text-decoration: none;
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    border: none;
}

.btn-yellow {
    padding: 6px 15px;
    background: #f1c40f;
    color: #fff;
    border-radius: 6px;
    text-decoration: none;
    font-size: 12px;
    font-weight: 500;
}

.btn-action {
    padding: 5px 10px;
    background: #0056b3;
    color: #fff;
    border-radius: 4px;
    text-decoration: none;
    font-size: 16px;
}

table { width: 100%; border-collapse: collapse; min-width: 760px; }

th {
    text-align: left;
    font-size: 13px;
    color: #888;
    padding-bottom: 15px;
    border-bottom: 1px solid #f0f0f0;
    font-weight: 500;
}

td {
    padding: 15px 0;
    font-size: 13px;
    color: #444;
    border-bottom: 1px solid #f9f9f9;
}

.type-badge {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 500;
    background: #f4f6fb;
    color: #555;
    border: 1px solid #ddd;
}

.text-green  { color: #10b981; font-weight: 500; }
.text-yellow { color: #f59e0b; font-weight: 500; }
.text-blue   { color: #3b82f6; font-weight: 500; }

.issue-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px 0;
    border-bottom: 1px solid #f9f9f9;
}

.issue-row:last-child { border-bottom: none; }

.issue-details h5 { font-size: 14px; color: #333; margin-bottom: 3px; font-weight: 500; }
.issue-details p  { font-size: 12px; color: #888; }

.issue-stats { display: flex; align-items: center; gap: 15px; }

.badge-red {
    background: #ef4444;
    color: white;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
}

@media (max-width: 768px) {
    .welcome-banner { flex-direction: column; text-align: center; gap: 15px; }
    .stats-grid { grid-template-columns: repeat(2, 1fr); }
}

@media (max-width: 480px) {
    .stats-grid { grid-template-columns: 1fr; }
}

@media (max-width: 700px) {
    .card-header-flex { flex-wrap: wrap; gap: 10px; }

    /* A 7-column glance table is the very first thing an admin sees - on a
       phone it should read like a short list of cards, not a table you
       have to scroll sideways to make sense of. */
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
    td.td-action .btn-blue { display: inline-block; width: 100%; text-align: center; box-sizing: border-box; }
}
</style>
</head>
<body>

<!-- TOPBAR (fixed, full width, on top) -->
<?php include 'admin_topbar.php'; ?>

<!-- SIDEBAR (fixed, below topbar) -->
<?php include 'admin_sidebar.php'; ?>

<!-- MAIN CONTENT -->
<div class="main">
    <div class="welcome-banner">
        <div class="welcome-text">
            <h1>Welcome back, Admin!</h1>
            <p>Here's what is happening in VOICE today.</p>
        </div>
        <div class="date-badge">
            <i class='bx bx-calendar'></i>
            <span><?php echo date('l, F j, Y'); ?></span>
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon bg-blue"><i class='bx bx-file'></i></div>
            <div class="stat-info">
                <h3><?php echo $todayComplaints; ?></h3>
                <p>New Complaints Filed Today</p>
            </div>
            <span class="dashboard-badge" aria-label="<?php echo $stats['pending_complaints']; ?> pending complaints"><?php echo $stats['pending_complaints']; ?></span>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-green"><i class='bx bx-bulb'></i></div>
            <div class="stat-info">
                <h3><?php echo $todaySuggestions; ?></h3>
                <p>New Suggestions Submitted Today</p>
            </div>
            <span class="dashboard-badge" aria-label="<?php echo $stats['pending_suggestions']; ?> pending suggestions"><?php echo $stats['pending_suggestions']; ?></span>
        </div>
    </div>

    <div class="content-layout">

        <div class="data-card">
            <div class="card-header-flex">
                <h4>Recent Suggestions & Complaints</h4>
                <a href="report.php" class="btn-blue">View All</a>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Student</th><th>Type</th>
                        <th>Date</th><th>Category</th><th>Managed By</th><th>Status</th><th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recentItems)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; color: #aaa;">No recent submissions</td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($recentItems as $item): ?>
                        <?php
                            $officeName = trim((string)($item['office'] ?? ''));
                            if ($officeName !== '') {
                                $managedBy = $officeName;
                            } elseif ($item['college_id'] !== null) {
                                $managedBy = 'Dean';
                            } else {
                                $managedBy = 'Admin (SAS Director)';
                            }

                            if ($item['type'] === 'complaint') {
                                $viewUrl = 'admin_complaints_details.php?id=' . (int)$item['id'];
                            } else {
                                // Dean-routed suggestions (college_id set) aren't
                                // admin-manageable - same distinction report.php
                                // uses to pick the right detail page per row.
                                $viewUrl = $item['college_id'] !== null
                                    ? 'admin_viewOnly_suggestions.php?id=' . (int)$item['id']
                                    : 'admin_suggestion_detail.php?id=' . (int)$item['id'];
                            }
                        ?>
                        <tr>
                            <td data-label="Student"><?php echo htmlspecialchars($item['first_name'] . ' ' . $item['last_name']) ?: 'Anonymous Student'; ?></td>
                            <td data-label="Type"><span class="type-badge"><?php echo ucfirst($item['type']); ?></span></td>
                            <td data-label="Date"><?php echo date('M d, Y', strtotime($item['submission_date'])); ?></td>
                            <td data-label="Category"><?php echo htmlspecialchars($item['category'] ?? 'N/A'); ?></td>
                            <td data-label="Managed By"><?php echo htmlspecialchars($managedBy); ?></td>
                            <td data-label="Status" class="text-<?php echo $item['status'] === 'resolved' ? 'green' : ($item['status'] === 'pending' ? 'yellow' : 'blue'); ?>">
                                <?php echo ucfirst($item['status']); ?>
                            </td>
                            <td class="td-action"><a href="<?php echo htmlspecialchars($viewUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn-blue" style="padding:6px 14px;font-size:12px;">View</a></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div>
</div>

<script>
</script>

</body>
</html>