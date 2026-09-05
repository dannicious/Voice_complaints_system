<?php
session_start();
require_once '../db_connection.php';

// Ensure dean access
function require_dean_session($pdo) {
    if (!isset($_SESSION['user_id'])) {
        header('Location: /complaint-system-main/dean/login.php');
        exit;
    }

    if (($_SESSION['role'] ?? '') === 'dean') {
        return true;
    }

    $stmt = $pdo->prepare('SELECT role FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && $user['role'] === 'dean') {
        $_SESSION['role'] = 'dean';
        return true;
    }

    header('Location: /complaint-system-main/dean/login.php');
    exit;
}

require_dean_session($pdo);
require_once __DIR__ . '/../report_period_helpers.php';

// Get dean's college
$deanProfileStmt = $pdo->prepare('SELECT college_id FROM dean_profiles WHERE user_id = ?');
$deanProfileStmt->execute([$_SESSION['user_id']]);
$deanProfile = $deanProfileStmt->fetch(PDO::FETCH_ASSOC);
$collegeId = $deanProfile['college_id'] ?? null;

if (!$collegeId) {
    $collegeStmt = $pdo->prepare('SELECT id FROM colleges LIMIT 1');
    $collegeStmt->execute();
    $college = $collegeStmt->fetch(PDO::FETCH_ASSOC);
    $collegeId = $college['id'] ?? 1;
}

// Get college information
$collegeStmt = $pdo->prepare('SELECT code, name FROM colleges WHERE id = ?');
$collegeStmt->execute([$collegeId]);
$collegeInfo = $collegeStmt->fetch(PDO::FETCH_ASSOC);
$collegeName = $collegeInfo['name'] ?? 'Unknown College';
$collegeCode = $collegeInfo['code'] ?? 'N/A';

// Total students in college
$studentCountStmt = $pdo->prepare('SELECT COUNT(*) as count FROM student_profiles WHERE college_id = ?');
$studentCountStmt->execute([$collegeId]);
$totalStudents = $studentCountStmt->fetch(PDO::FETCH_ASSOC)['count'];

// Student search and counts
$q = trim((string)($_GET['q'] ?? ''));
$searchWhere = '';
$searchParams = [];
if ($q !== '') {
    $like = '%' . $q . '%';
    $searchWhere = ' AND (sp.student_number LIKE ? OR sp.first_name LIKE ? OR sp.last_name LIKE ? OR u.username LIKE ? OR prog.name LIKE ?)';
    $searchParams = [$like, $like, $like, $like, $like];
}

$reportRange = trim((string)($_GET['range'] ?? 'month'));

// Sorting
$sort = trim((string)($_GET['sort'] ?? ''));
$sortSql = ' ORDER BY sp.first_name, sp.last_name';
if ($sort === 'complaints_desc') {
    $sortSql = ' ORDER BY complaints_filed DESC, sp.first_name, sp.last_name';
} elseif ($sort === 'reports_desc') {
    $sortSql = ' ORDER BY times_reported DESC, sp.first_name, sp.last_name';
} elseif ($sort === 'name_desc') {
    $sortSql = ' ORDER BY sp.last_name DESC, sp.first_name DESC';
} else {
    $sortSql = ' ORDER BY sp.first_name, sp.last_name';
}

$baseSql = 'SELECT 
        sp.id as sp_id,
        sp.student_number,
        sp.first_name,
        sp.last_name,
        u.username as username,
        prog.name as program,
        sp.year_level,
        sp.status,
        (SELECT COUNT(*) FROM complaint_student_links csl WHERE csl.student_id = sp.id) AS times_reported,
        (SELECT COUNT(*) FROM complaints c WHERE c.student_id = sp.id) AS complaints_filed
    FROM student_profiles sp
    LEFT JOIN users u ON sp.user_id = u.id
    LEFT JOIN programs prog ON sp.program_id = prog.id
    WHERE sp.college_id = ?' . $searchWhere;

$sql = $baseSql . $sortSql . ' LIMIT 50';
$studentStmt = $pdo->prepare($sql);
$studentStmt->execute(array_merge([$collegeId], $searchParams));
$students = $studentStmt->fetchAll(PDO::FETCH_ASSOC);
// If AJAX request, return only table rows HTML for live search
if ((string)($_GET['ajax'] ?? '') === '1') {
    if (empty($students)) {
        echo '<tr><td colspan="7" style="text-align:center;color:#aaa">No students found</td></tr>';
        exit;
    }
    foreach ($students as $student) {
        $sid = htmlspecialchars($student['student_number'] ?? $student['username'] ?? 'N/A');
        $name = htmlspecialchars(trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? '')));
        $program = htmlspecialchars($student['program'] ?? 'N/A');
        $year = htmlspecialchars($student['year_level'] ?? 'N/A');
        $reported = (int)($student['times_reported'] ?? 0);
        $complaints = (int)($student['complaints_filed'] ?? 0);
        $status = htmlspecialchars(ucfirst($student['status'] ?? 'active'));
        $statusClass = 'status-' . strtolower($student['status'] ?? 'active');
        echo "<tr>";
        echo "<td><strong>{$sid}</strong></td>";
        echo "<td>{$name}</td>";
        echo "<td>{$program}</td>";
        echo "<td>{$year}</td>";
        echo '<td><a class="complaint-count-badge" href="../reported_complaints.php?student_id=' . (int)$student['sp_id'] . '" title="View reported complaints">' . $reported . '</a></td>';
        echo '<td><a class="complaint-count-badge" href="../reported_complaints.php?student_id=' . (int)$student['sp_id'] . '&view=complaints" title="View student complaints">' . $complaints . '</a></td>';
        echo "<td><span class=\"{$statusClass}\">{$status}</span></td>";
        echo "</tr>";
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>VOICE - Reports Management</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>

<style>
* { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
body { background: #f9fafb; }
.main { margin-left: 260px; margin-top: 61px; padding: 25px; min-height: calc(100vh - 61px); overflow-y: auto; }
.page-header { margin-bottom: 20px; }
.page-header h2 { font-size: 24px; font-weight: 600; color: #333; }
.page-subtitle { color: #6b7280; font-size: 14px; margin-bottom: 25px; }
.panel, .data-card { background: #fff; padding: 20px; border-radius: 12px; border: 1px solid #e5e7eb; box-shadow: 0 2px 10px rgba(0,0,0,0.02); }
.search-box { display:flex; align-items:center; background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; padding:8px 12px; width:280px }
.search-box i{color:#888;margin-right:8px}
.search-box input{border:none;background:transparent;outline:none;width:100%}
table{width:100%;border-collapse:collapse;min-width:800px}
.complaint-count-badge { display: inline-flex; align-items: center; justify-content: center; min-width: 36px; padding: 6px 10px; border-radius: 999px; background: #eef2ff; color: #4338ca; font-weight: 600; text-decoration: none; }
th{ text-align:left; font-size:12px; color:#888; padding:15px 10px; border-bottom:2px solid #f0f0f0; font-weight:600; text-transform:uppercase }
td{ padding:15px 10px; font-size:14px; color:#444; border-bottom:1px solid #f9f9f9; vertical-align:middle }
.status-active{ background:#dcfce7; color:#16a34a; padding:5px 10px; border-radius:6px; font-size:12px; font-weight:500 }
.status-inactive{ background:#fee2e2; color:#dc2626; padding:5px 10px; border-radius:6px; font-size:12px; font-weight:500 }
</style>
</head>

<body>

<!-- TOPBAR (fixed, full width) -->
<?php include 'dean_topbar.php'; ?>

<!-- SIDEBAR (fixed, below topbar) -->
<?php include 'dean_sidebar.php'; ?>

<!-- MAIN CONTENT -->
<div class="main">
    <div class="dashboard-container">

        <div class="page-header">
            <h2>Reports Management</h2>
        </div>

        <p class="page-subtitle"><?php echo htmlspecialchars($collegeName); ?> (<?php echo htmlspecialchars($collegeCode); ?>) Performance Overview</p>

        <div class="metrics-grid">
            <div class="metric-card">
                <h4>Registered Students</h4>
                <div class="value"><?php echo number_format($totalStudents); ?></div>
            </div>
        </div>

        <?php render_report_period_widget($pdo, (int)$collegeId, $reportRange, 'dean_reports.php', array_filter(['q' => $q, 'sort' => $sort])); ?>

        <div class="data-card">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                <h3><?php echo htmlspecialchars($collegeName); ?> Enrollees Directory</h3>
            </div>

            <form method="GET" style="display:flex;gap:12px;align-items:center;margin:12px 0 18px 0;">
                <div class="search-box" style="flex:1;min-width:220px;">
                    <i class='bx bx-search'></i>
                    <input type="text" name="q" placeholder="Search by ID, Name or Program..." value="<?php echo htmlspecialchars($q); ?>">
                </div>
                <div>
                    <select name="sort" style="padding:9px 12px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;font-size:13px;">
                        <option value="complaints_desc" <?php echo $sort === 'complaints_desc' ? 'selected' : ''; ?>>Complaints</option>
                        <option value="reports_desc" <?php echo $sort === 'reports_desc' ? 'selected' : ''; ?>>Reports</option>
                    </select>
                </div>
            </form>

            <table>
                <thead>
                    <tr>
                        <th>Student ID</th>
                        <th>Full Name</th>
                        <th>Program</th>
                        <th>Year Level</th>
                        <th>Reported</th>
                        <th>Complaints</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($students)): ?>
                    <tr>
                        <td colspan="7" style="text-align:center;color:#aaa">No students found</td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($students as $student): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($student['student_number'] ?? $student['username'] ?? 'N/A'); ?></strong></td>
                            <td><?php echo htmlspecialchars(trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? ''))); ?></td>
                            <td><?php echo htmlspecialchars($student['program'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($student['year_level'] ?? 'N/A'); ?></td>
                            <td><a class="complaint-count-badge" href="../reported_complaints.php?student_id=<?php echo (int)$student['sp_id']; ?>" title="View reported complaints"><?php echo (int)($student['times_reported'] ?? 0); ?></a></td>
                            <td><a class="complaint-count-badge" href="../reported_complaints.php?student_id=<?php echo (int)$student['sp_id']; ?>&view=complaints" title="View student complaints"><?php echo (int)($student['complaints_filed'] ?? 0); ?></a></td>
                            <td><span class="status-<?php echo strtolower($student['status'] ?? 'active'); ?>"><?php echo ucfirst($student['status'] ?? 'active'); ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div>
</div>

</body>
</html>

<script>
document.addEventListener('DOMContentLoaded', function(){
    const qInput = document.querySelector('input[name="q"]');
    const sortSelect = document.querySelector('select[name="sort"]');
    const tbody = document.querySelector('table tbody');
    let timer = null;

    function doSearch() {
        const params = new URLSearchParams();
        if (qInput && qInput.value.trim() !== '') params.set('q', qInput.value.trim());
        if (sortSelect && sortSelect.value) params.set('sort', sortSelect.value);
        params.set('ajax','1');
        fetch(window.location.pathname + '?' + params.toString(), {headers: {'X-Requested-With': 'XMLHttpRequest'}})
            .then(r => r.text())
            .then(html => {
                if (tbody) tbody.innerHTML = html;
            }).catch(err => console.error('Search error', err));
    }

    if (qInput) {
        qInput.addEventListener('input', function(){
            clearTimeout(timer);
            timer = setTimeout(doSearch, 300);
        });
    }
    if (sortSelect) {
        sortSelect.addEventListener('change', function(){ doSearch(); });
    }
});
</script>