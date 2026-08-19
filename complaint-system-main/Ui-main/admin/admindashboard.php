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
$stats = ['complaints' => 0, 'suggestions' => 0, 'students' => 0];

// Total complaints
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM complaints WHERE ticket_no NOT LIKE 'VOX-C-2026-%'");
$stmt->execute();
$stats['complaints'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Total suggestions
$stmt = $pdo->prepare('SELECT COUNT(*) as count FROM suggestions');
$stmt->execute();
$stats['suggestions'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Registered students
$stmt = $pdo->prepare('SELECT COUNT(*) as count FROM users WHERE role = "student"');
$stmt->execute();
$stats['students'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Get complaints and suggestions per college for chart
$collegeSql = $pdo->prepare('
    SELECT c.code, 
           COUNT(DISTINCT comp.id) as complaint_count,
           COUNT(DISTINCT sug.id) as suggestion_count
    FROM colleges c
    LEFT JOIN complaints comp ON c.id = comp.college_id AND comp.ticket_no NOT LIKE "VOX-C-2026-%"
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
            COALESCE(cc.name, "Uncategorized") as category, c.status, c.created_at as submission_date, c.id
     FROM complaints c
     LEFT JOIN student_profiles sp ON c.student_id = sp.id
     LEFT JOIN complaint_categories cc ON c.category_id = cc.id
    WHERE c.ticket_no NOT LIKE "VOX-C-2026-%"
     ORDER BY c.created_at DESC LIMIT 3)
    UNION ALL
    (SELECT s.ticket_no as ticket_number, sp.first_name, sp.last_name, "suggestion" as type,
            COALESCE(sc.name, "Uncategorized") as category, s.status, s.created_at as submission_date, s.id
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
    WHERE comp.ticket_no NOT LIKE "VOX-C-2026-%" AND comp.created_at < DATE_SUB(NOW(), INTERVAL 48 HOUR) AND (comp.status = "new" OR comp.status = "pending")
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
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
    margin-bottom: 25px;
}

.stat-card {
    background: #fff;
    padding: 20px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    gap: 15px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.03);
}

.stat-icon {
    width: 45px;
    height: 45px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    color: #fff;
}

.stat-info h3 { font-size: 20px; font-weight: 600; color: #333; margin-bottom: 2px; }
.stat-info p  { font-size: 12px; color: #888; }

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

table { width: 100%; border-collapse: collapse; }

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
            <div class="stat-info"><h3><?php echo $stats['complaints']; ?></h3><p>Total Complaints</p></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-green"><i class='bx bx-bulb'></i></div>
            <div class="stat-info"><h3><?php echo $stats['suggestions']; ?></h3><p>Total Suggestions</p></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-purple"><i class='bx bx-group'></i></div>
            <div class="stat-info"><h3><?php echo number_format($stats['students']); ?></h3><p>Registered Students</p></div>
        </div>
    </div>

    <div class="content-layout">

        <div class="data-card">
            <div class="card-header-flex">
                <h4>Recent Suggestions & Complaints</h4>
                <a href="admin_complaints.php" class="btn-blue">View All Tickets</a>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Student</th><th>Type</th>
                        <th>Date</th><th>Category</th><th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recentItems)): ?>
                    <tr>
                        <td colspan="5" style="text-align: center; color: #aaa;">No recent submissions</td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($recentItems as $item): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($item['first_name'] . ' ' . $item['last_name']) ?: 'Anonymous Student'; ?></td>
                            <td><span class="type-badge"><?php echo ucfirst($item['type']); ?></span></td>
                            <td><?php echo date('M d, Y', strtotime($item['submission_date'])); ?></td>
                            <td><?php echo htmlspecialchars($item['category'] ?? 'N/A'); ?></td>
                            <td class="text-<?php echo $item['status'] === 'resolved' ? 'green' : ($item['status'] === 'pending' ? 'yellow' : 'blue'); ?>">
                                <?php echo ucfirst($item['status']); ?>
                            </td>
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