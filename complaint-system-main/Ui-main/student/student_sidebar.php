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

/* Removed specific icon font sizes to match admin */

.menu li a:hover,
.menu li a.active {
    background: #6d28d9;
    color: #fff;
    /* Removed box-shadow to match admin */
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
$pageMode = (string)($_GET['mode'] ?? '');
?>

<div class="sidebar">

    <div class="sidebar-separator"></div>

    <ul class="menu">
        <li>
            <a href="student_dashboard.php" class="<?= $current === 'student_dashboard.php' ? 'active' : '' ?>">
                <i class='bx bx-home'></i> Student Guide
            </a>
        </li>
        <li>
            <a href="student_complaints.php" class="<?= $current === 'student_complaints.php' && $pageMode !== 'suggestion' ? 'active' : '' ?>">
                <i class='bx bx-edit'></i> File Complaint
            </a>
        </li>
        <li>
            <a href="student_complaints.php?mode=suggestion" class="<?= $current === 'student_complaints.php' && $pageMode === 'suggestion' ? 'active' : '' ?>">
                <i class='bx bx-bulb'></i> Send Suggestion
            </a>
        </li>
        <li>
            <a href="student_mysubmission.php" class="<?= $current === 'student_mysubmission.php' ? 'active' : '' ?>">
                <i class='bx bx-list-ul'></i> My Submissions
            </a>
        </li>
        <li>
            <a href="student_chatbott.php" class="<?= $current === 'student_chatbott.php' ? 'active' : '' ?>">
                <i class='bx bx-bot'></i> VOICE Assistant
            </a>
        </li>
        <li>
            <a href="student_profile.php" class="<?= $current === 'student_profile.php' ? 'active' : '' ?>">
                <i class='bx bx-user'></i> My Profile
            </a>
        </li>
        <li class="logout-item" style="margin-top:auto;">
            <a href="../../student/log_out.php">
                <i class='bx bx-log-out'></i> Log Out
            </a>
        </li>
    </ul>

</div>

<div class="mobile-sidebar-overlay" onclick="closeSidebar()"></div>