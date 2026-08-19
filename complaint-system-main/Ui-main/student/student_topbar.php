<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../db_connection.php';

function topbar_time_ago(string $dateTime): string
{
    $ts = strtotime($dateTime);
    if ($ts === false) {
        return 'Just now';
    }

    $diff = time() - $ts;
    if ($diff < 60) {
        return 'Just now';
    }
    if ($diff < 3600) {
        return floor($diff / 60) . ' minutes ago';
    }
    if ($diff < 86400) {
        return floor($diff / 3600) . ' hours ago';
    }
    if ($diff < 172800) {
        return 'Yesterday';
    }

    return date('M d, Y', $ts);
}

if (!function_exists('e')) {
    function e(string $value = ''): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

function topbar_icon_for_type(string $type): string
{
    if ($type === 'new_complaint') {
        return 'bx-message-error';
    }
    if ($type === 'new_suggestion') {
        return 'bx-bulb';
    }
    if ($type === 'complaint_update') {
        return 'bx-message-detail';
    }
    if ($type === 'suggestion_update') {
        return 'bx-check-circle';
    }
    if ($type === 'call_slip_issued') {
        return 'bx-file';
    }

    return 'bx-bell';
}

function topbar_link_for_type(string $type): string
{
    if ($type === 'new_complaint') {
        return 'student_complaints.php';
    }
    if ($type === 'new_suggestion') {
        return 'student_complaints.php?mode=suggestion';
    }
    if ($type === 'complaint_update' || $type === 'suggestion_update' || $type === 'call_slip_issued') {
        return 'student_mysubmission.php';
    }

    return 'student_dashboard.php';
}

if (!function_exists('resolve_student_photo')) {
    function resolve_student_photo(string $storedPath): string
    {
        $storedPath = trim($storedPath);
        if ($storedPath === '') {
            return '../assets/images/default-avatar.svg';
        }

        $relativePath = ltrim($storedPath, '/');
        $absolutePath = __DIR__ . '/../' . $relativePath;
        if (is_file($absolutePath)) {
            return '../' . $relativePath;
        }

        return '../assets/images/default-avatar.svg';
    }
}

$topbarNotifications = [];
$topbarUnreadCount = 0;
$studentName = 'Student';
$studentEmail = '';
$studentPhoto = '../assets/images/default-avatar.svg';

if (isset($_SESSION['user_id']) && isset($pdo)) {
    try {
        // Fetch student profile data
        $profileStmt = $pdo->prepare(
            'SELECT sp.first_name, sp.last_name, sp.student_number, sp.year_level, sp.section,
                    c.name AS college_name, p.name AS program_name,
                    u.username, u.email, u.profile_pic
             FROM student_profiles sp
             LEFT JOIN users u ON u.id = sp.user_id
             LEFT JOIN colleges c ON c.id = sp.college_id
             LEFT JOIN programs p ON p.id = sp.program_id
             WHERE sp.user_id = :user_id'
        );
        $profileStmt->execute([':user_id' => (int)$_SESSION['user_id']]);
        $studentProfile = $profileStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($studentProfile) {
            $studentName = trim($studentProfile['first_name'] . ' ' . $studentProfile['last_name']);
            if (empty($studentName)) {
                $studentName = trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? $studentProfile['username'] ?? 'Student'));
            }
            $studentEmail = $studentProfile['email'] ?? '';
            $studentPhoto = resolve_student_photo((string)($studentProfile['profile_pic'] ?? ''));
        } elseif (!empty($_SESSION['full_name']) || !empty($_SESSION['username'])) {
            $studentName = trim((string)($_SESSION['full_name'] ?? $_SESSION['username']));
        }
        
        // Fetch notifications
        $stmt = $pdo->prepare(
            'SELECT id, type, message, is_read, created_at
             FROM notifications
             WHERE user_id = :user_id
             ORDER BY created_at DESC
             LIMIT 12'
        );
        $stmt->execute([':user_id' => (int)$_SESSION['user_id']]);
        $topbarNotifications = $stmt->fetchAll();

        foreach ($topbarNotifications as $n) {
            if ((int)$n['is_read'] === 0) {
                $topbarUnreadCount++;
            }
        }
    } catch (PDOException $e) {
        // Silently fail - use defaults
        error_log('Topbar DB Error: ' . $e->getMessage());
    }
}
?>
<link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>

<style>
/* ===== TOPBAR ===== */
.topbar {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    z-index: 999;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    padding: 12px 25px;
    background: #6d28d9;
    box-shadow: 0 2px 10px rgba(109,40,217,0.3);
}

.menu-toggle {
    display: none;
    border: none;
    background: transparent;
    color: #fff;
    font-size: 26px;
    line-height: 1;
    cursor: pointer;
    padding: 0;
}

/* ===== LOGO + VOICE (left side) ===== */
.topbar-logo {
    display: flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
}

.topbar-logo img {
    width: 28px;
    height: 28px;
    object-fit: contain;
    filter: brightness(0) invert(1);
}

.topbar-logo span {
    font-size: 18px;
    font-weight: 700;
    color: #fff;
    letter-spacing: 2px;
}

/* ===== RIGHT SIDE ===== */
.topbar-right {
    display: flex;
    align-items: center;
    gap: 20px;
}

/* ===== PROFILE ===== */
.profile-container {
    display: flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
    position: relative;
}

.profile-container img.profile-pic {
    width: 35px;
    height: 35px;
    border-radius: 50%;
    border: 2px solid rgba(255,255,255,0.6);
}

.profile-container span {
    font-weight: 500;
    color: #fff;
}

.profile-dropdown {
    position: absolute;
    top: 50px;
    right: 0;
    background: #fff;
    border-radius: 10px;
    box-shadow: 0 6px 20px rgba(0,0,0,0.15);
    display: none;
    overflow: hidden;
    z-index: 1000;
}

.profile-dropdown button {
    width: 100%;
    padding: 10px 20px;
    background: #6d28d9;
    border: none;
    color: #fff;
    font-weight: 500;
    cursor: pointer;
    white-space: nowrap;
    font-family: 'Poppins', sans-serif;
}

.profile-dropdown button:hover {
    background: #5d1fa0;
}

/* ===== NOTIFICATION CARD (Facebook Style) ===== */
.topbar-bell {
    position: relative;
    cursor: pointer;
}

.topbar-bell i.bx-bell {
    font-size: 24px;
    color: #fff;
    transition: 0.2s;
}

.topbar-bell:hover i.bx-bell {
    transform: scale(1.1);
}

.notification-badge {
    position: absolute;
    top: -4px;
    right: -4px;
    background: #ff3b30;
    color: #fff;
    font-size: 11px;
    font-weight: 600;
    padding: 2px 5px;
    border-radius: 50px;
    border: 2px solid #6d28d9;
}

.notification-dropdown {
    position: absolute;
    top: 45px;
    right: -10px;
    width: 360px;
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 8px 30px rgba(0,0,0,0.2);
    display: none;
    overflow: hidden;
    z-index: 1000;
    cursor: default;
    font-family: 'Poppins', sans-serif;
}

/* Card Header */
.notif-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px 20px;
    border-bottom: 1px solid #eef0f5;
}

.notif-header h3 {
    margin: 0;
    font-size: 18px;
    color: #1c1e21;
}

.notif-header a {
    font-size: 13px;
    color: #6d28d9;
    text-decoration: none;
    font-weight: 500;
}

.notif-header a:hover {
    text-decoration: underline;
}

/* Scrollable Body */
.notif-body {
    max-height: 350px;
    overflow-y: auto;
}

.notif-body::-webkit-scrollbar {
    width: 6px;
}
.notif-body::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 10px;
}

/* Individual Item */
.notification-item {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 12px 20px;
    text-decoration: none;
    color: #333;
    transition: background 0.2s;
    position: relative;
}

.notification-item:hover {
    background: #f0f2f5;
}

/* Unread State */
.notification-item.unread {
    background: #f5f3f7;
}
.notification-item.unread::after {
    content: '';
    position: absolute;
    right: 20px;
    top: 50%;
    transform: translateY(-50%);
    width: 10px;
    height: 10px;
    background-color: #ffc107;
    border-radius: 50%;
}

/* Icon / Avatar */
.notif-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: #e4e6eb;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    color: #050505;
    font-size: 20px;
}

.notif-icon img {
    width: 100%;
    height: 100%;
    border-radius: 50%;
    object-fit: cover;
}

/* Text Content */
.notif-content {
    display: flex;
    flex-direction: column;
    padding-right: 15px;
}

.notif-text {
    font-size: 13.5px;
    line-height: 1.4;
    color: #050505;
    margin-bottom: 4px;
}

.notif-text strong {
    font-weight: 600;
}

.notif-time {
    font-size: 12px;
    color: #65676b;
    font-weight: 500;
}

/* Card Footer */
.notif-footer {
    text-align: center;
    padding: 12px;
    border-top: 1px solid #eef0f5;
}

.notif-footer a {
    color: #6d28d9;
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
}

.notif-footer a:hover {
    text-decoration: underline;
}

/* ===== RESPONSIVE ===== */
@media (max-width: 768px) {
    .topbar {
        padding: 10px 14px;
        gap: 10px;
    }

    .topbar-logo span {
        font-size: 16px;
        letter-spacing: 1px;
    }

    .topbar-right {
        gap: 12px;
    }

    .profile-container span {
        display: none;
    }

    .notification-dropdown {
        right: -50px;
        width: 90vw;
        max-width: 360px;
    }
}

@media (max-width: 1024px) {
    .menu-toggle {
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }
}
</style>

<div class="topbar">

    <button class="menu-toggle" type="button" onclick="toggleSidebar()" aria-label="Toggle menu">
        <i class='bx bx-menu'></i>
    </button>

    <a class="topbar-logo" href="student_dashboard.php">
        <img src="../assets/images/logo.PNG" alt="Logo">
        <span>VOICE</span>
    </a>

    <div class="topbar-right">

        <div class="topbar-bell" id="bellIcon">
            <i class='bx bx-bell'></i>
            <span class="notification-badge" id="notificationBadge" style="<?php echo $topbarUnreadCount > 0 ? '' : 'display:none;'; ?>"><?php echo $topbarUnreadCount; ?></span>
            
            <div class="notification-dropdown" id="notificationDropdown">
                
                <div class="notif-header">
                    <h3>Notifications</h3>
                    <a href="#" id="markAllRead">Mark all as read</a>
                </div>

                <div class="notif-body">
                    <?php if (count($topbarNotifications) === 0): ?>
                        <div class="notification-item">
                            <div class="notif-icon"><i class='bx bx-bell'></i></div>
                            <div class="notif-content">
                                <div class="notif-text">No notifications yet.</div>
                                <div class="notif-time">You're all caught up.</div>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($topbarNotifications as $notification): ?>
                            <a href="<?php echo htmlspecialchars(topbar_link_for_type((string)$notification['type']), ENT_QUOTES, 'UTF-8'); ?>" class="notification-item <?php echo (int)$notification['is_read'] === 0 ? 'unread' : ''; ?>" data-notification-id="<?php echo (int)$notification['id']; ?>">
                                <div class="notif-icon">
                                    <i class='bx <?php echo topbar_icon_for_type((string)$notification['type']); ?>'></i>
                                </div>
                                <div class="notif-content">
                                    <div class="notif-text"><?php echo htmlspecialchars((string)$notification['message'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="notif-time"><?php echo topbar_time_ago((string)$notification['created_at']); ?></div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="notif-footer">
                    <a href="student_mysubmission.php">See all notifications</a>
                </div>

            </div>
        </div>

        <div class="profile-container" id="profileIcon">
            <img class="profile-pic" src="<?php echo e($studentPhoto); ?>" alt="profile">
            <span><?php echo e($studentName); ?></span>
        </div>

    </div>
</div>

<script>
// Toggle Notifications
document.getElementById('bellIcon').addEventListener('click', function(e) {
    const notifDropdown = document.getElementById('notificationDropdown');
    const profileDropdown = document.getElementById('profileDropdown');
    
    if (profileDropdown) profileDropdown.style.display = 'none';
    notifDropdown.style.display = notifDropdown.style.display === 'block' ? 'none' : 'block';
    e.stopPropagation();

    if (notifDropdown.style.display === 'block') {
        markAllNotificationsRead();
    }
});

// Toggle Profile
document.getElementById('profileIcon').addEventListener('click', function(e) {
    const profileDropdown = document.getElementById('profileDropdown');
    const notifDropdown = document.getElementById('notificationDropdown');
    
    if (notifDropdown) notifDropdown.style.display = 'none';
    if (!profileDropdown) return;
    profileDropdown.style.display = profileDropdown.style.display === 'block' ? 'none' : 'block';
    e.stopPropagation();
});

// Sidebar logic
function toggleSidebar() {
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.querySelector('.mobile-sidebar-overlay');
    if (!sidebar || !overlay) return;
    sidebar.classList.toggle('open');
    overlay.classList.toggle('show');
}

function closeSidebar() {
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.querySelector('.mobile-sidebar-overlay');
    if (!sidebar || !overlay) return;
    sidebar.classList.remove('open');
    overlay.classList.remove('show');
}

// Close dropdowns on outside click
window.onclick = function(event) {
    const notificationDropdown = document.getElementById('notificationDropdown');
    if (notificationDropdown && !event.target.closest('.notification-dropdown')) {
        notificationDropdown.style.display = 'none';
    }
    const profileDropdown = document.getElementById('profileDropdown');
    if (profileDropdown && !event.target.closest('.profile-dropdown')) {
        profileDropdown.style.display = 'none';
    }
}

function logout() {
    window.location.href = "../../student/log_out.php";
}

function markAllNotificationsRead() {
    const unreadItems = document.querySelectorAll('.notification-item.unread');
    if (unreadItems.length === 0) return;

    fetch('../mark_notifications_read.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
        body: 'scope=all'
    }).then(() => {
        unreadItems.forEach(item => item.classList.remove('unread'));
        const badge = document.getElementById('notificationBadge');
        if (badge) {
            badge.style.display = 'none';
            badge.textContent = '0';
        }
    }).catch(() => {});
}

const markAllLink = document.getElementById('markAllRead');
if (markAllLink) {
    markAllLink.addEventListener('click', function(e) {
        e.preventDefault();
        markAllNotificationsRead();
    });
}

document.querySelectorAll('.sidebar .menu a').forEach(link => {
    link.addEventListener('click', closeSidebar);
});
</script>