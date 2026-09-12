<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$current = basename($_SERVER['PHP_SELF']);
?>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
<style>
.sidebar, .sidebar * { font-family: 'Poppins', sans-serif; }
.sidebar { width: 260px; height: 100vh; background: #ffffff; display: flex; flex-direction: column; border-right: 1px solid #eee; position: fixed; top: 61px; left: 0; bottom: 0; overflow-y: auto; z-index: 998; }
.sidebar-separator { height: 1px; background: #e5e7eb; margin-bottom: 20px; }
.menu { list-style: none; padding: 20px; margin: 0; display: flex; flex-direction: column; min-height: calc(100vh - 61px - 40px); }
.menu li { margin-bottom: 8px; }
.menu li a { display: flex; align-items: center; gap: 10px; padding: 12px; border-radius: 10px; text-decoration: none; color: #374151; transition: 0.3s; }
.menu li a:hover, .menu li a.active { background: #6d28d9; color: #fff; }
.mobile-sidebar-overlay { display: none; }
@media (max-width: 1024px) {
    .sidebar { display: flex; transform: translateX(-100%); transition: transform 0.25s ease; box-shadow: 6px 0 20px rgba(0, 0, 0, 0.12); }
    .sidebar.open { transform: translateX(0); }
    .mobile-sidebar-overlay { display: block; position: fixed; top: 61px; left: 0; right: 0; bottom: 0; background: rgba(17, 24, 39, 0.35); opacity: 0; visibility: hidden; transition: opacity 0.25s ease, visibility 0.25s ease; z-index: 997; }
    .mobile-sidebar-overlay.show { opacity: 1; visibility: visible; }
    .main { margin-left: 0 !important; padding: 16px !important; }
}
</style>
<div class="sidebar">
    <div class="sidebar-separator"></div>
    <ul class="menu">
        <li><a href="/complaint-system/complaint-system-main/staff/dashboard.php" class="<?= $current === 'dashboard.php' ? 'active' : '' ?>"><i class="bx bx-home"></i> Dashboard</a></li>
        <li><a href="/complaint-system/complaint-system-main/Ui-main/staff/suggestions.php" class="<?= $current === 'suggestions.php' ? 'active' : '' ?>"><i class="bx bx-bulb"></i> Suggestions</a></li>
        <li><a href="/complaint-system/complaint-system-main/Ui-main/staff/areas.php" class="<?= $current === 'areas.php' ? 'active' : '' ?>"><i class="bx bx-category"></i> Categories I Handle</a></li>
        <li><a href="/complaint-system/complaint-system-main/Ui-main/staff/profile.php" class="<?= $current === 'profile.php' ? 'active' : '' ?>"><i class="bx bx-user"></i> My Profile</a></li>
        <li class="logout-item" style="margin-top:auto;"><a href="/complaint-system/complaint-system-main/staff/logout.php"><i class="bx bx-log-out"></i> Log Out</a></li>
    </ul>
</div>
<div class="mobile-sidebar-overlay" onclick="closeSidebar()"></div>
<script>
function closeSidebar() {
    document.querySelector('.sidebar')?.classList.remove('open');
    document.querySelector('.mobile-sidebar-overlay')?.classList.remove('show');
}
</script>
