<!-- admin_sidebar.php -->

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>

<style>
/* ===== SIDEBAR ===== */
.sidebar {
    width: 260px;
    height: 100vh;
    background: #ffffff;
    display: flex;
    flex-direction: column;
    border-right: 1px solid #eee;
    position: fixed;
    top: 61px; /* sits just below the fixed topbar */
    left: 0;
    bottom: 0;
    overflow-y: auto;
    z-index: 998;
}

.sidebar-separator {
    height: 1px;
    background: #e5e7eb;
    margin-bottom: 20px;
}

.menu {
    list-style: none;
    padding: 20px;
    display: flex;
    flex-direction: column;
    min-height: calc(100vh - 61px - 40px);
}

.menu li {
    margin-bottom: 8px;
}

.menu li a {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px;
    border-radius: 10px;
    text-decoration: none;
    color: #374151;
    transition: 0.3s;
}

.menu li a:hover,
.menu li a.active {
    background: #6d28d9;
    color: #fff;
}

.complaint-badge {
    margin-left: auto;
    min-width: 22px;
    height: 22px;
    padding: 0 6px;
    border-radius: 11px;
    background: #dc2626;
    color: #fff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: 600;
    line-height: 1;
}

.mobile-sidebar-overlay {
    display: none;
}

@media (max-width: 1024px) {
    .sidebar {
        display: flex;
        transform: translateX(-100%);
        transition: transform 0.25s ease;
        box-shadow: 6px 0 20px rgba(0, 0, 0, 0.12);
    }

    .sidebar.open {
        transform: translateX(0);
    }

    .mobile-sidebar-overlay {
        display: block;
        position: fixed;
        top: 61px;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(17, 24, 39, 0.35);
        opacity: 0;
        visibility: hidden;
        transition: opacity 0.25s ease, visibility 0.25s ease;
        z-index: 997;
    }

    .mobile-sidebar-overlay.show {
        opacity: 1;
        visibility: visible;
    }

    .main {
        margin-left: 0 !important;
        padding: 16px !important;
    }
}
</style>

<?php
$current = basename($_SERVER['PHP_SELF']);
$pendingComplaintCount = 0;

try {
    $pendingComplaintStmt = $pdo->query(
        "SELECT COUNT(*) FROM complaints
         WHERE COALESCE(status, 'new') <> 'resolved'
           AND COALESCE(approval_status, 'pending') <> 'rejected'"
    );
    $pendingComplaintCount = (int)$pendingComplaintStmt->fetchColumn();
} catch (PDOException $e) {
}
?>

<div class="sidebar">

    <div class="sidebar-separator"></div>

    <ul class="menu">
        <li>
            <a href="admindashboard.php" class="<?= $current === 'admindashboard.php' ? 'active' : '' ?>">
                <i class='bx bx-home'></i> Dashboard
            </a>
        </li>
        <li>
            <a href="admin_complaints.php" class="<?= $current === 'admin_complaints.php' ? 'active' : '' ?>">
                <i class='bx bx-file'></i> Complaints
                <span class="complaint-badge" aria-label="<?= $pendingComplaintCount ?> unresolved complaints"><?= $pendingComplaintCount ?></span>
            </a>
        </li>
        <li>
            <a href="admin_suggestions.php" class="<?= $current === 'admin_suggestions.php' ? 'active' : '' ?>">
                <i class='bx bx-bulb'></i> Suggestions
            </a>
        </li>
        <li>
            <a href="admin_colleges.php" class="<?= $current === 'admin_colleges.php' ? 'active' : '' ?>">
                <i class='bx bx-buildings'></i> Colleges
            </a>
        </li>
        <li>
            <a href="admin_dean.php" class="<?= $current === 'admin_dean.php' ? 'active' : '' ?>">
                <i class='bx bx-user'></i> Deans
            </a>
        </li>
        <li>
            <a href="admin_faculty.php" class="<?= $current === 'admin_faculty.php' ? 'active' : '' ?>">
                <i class='bx bx-id-card'></i> Faculty & Staff
            </a>
        </li>
        <li>
            <a href="admin_students.php" class="<?= $current === 'admin_students.php' ? 'active' : '' ?>">
                <i class='bx bx-group'></i> Reports
            </a>
        </li>
    
        <li>
            <a href="admin_setting.php" class="<?= $current === 'admin_setting.php' ? 'active' : '' ?>">
                <i class='bx bx-cog'></i> Settings
            </a>
        </li>
        <li class="logout-item" style="margin-top:auto;">
            <a href="../../admin/log_out.php">
                <i class='bx bx-log-out'></i> Log Out
            </a>
        </li>
    </ul>

</div>

<div class="mobile-sidebar-overlay" onclick="closeSidebar()"></div>