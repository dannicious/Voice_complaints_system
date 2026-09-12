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

/**
 * The photo + initial of the student who filed the complaint/suggestion a
 * "new_complaint"/"new_suggestion" notification is about, so the dropdown
 * can show their avatar on the left instead of a generic icon.
 *
 * @return array{photo: ?string, initial: string}
 */
function topbar_submitter_avatar(PDO $pdo, string $ticketType, int $ticketId): array
{
    $fallback = ['photo' => null, 'initial' => '?'];

    if ($ticketId <= 0 || !in_array($ticketType, ['complaint', 'suggestion'], true)) {
        return $fallback;
    }

    $table = $ticketType === 'complaint' ? 'complaints' : 'suggestions';

    try {
        $stmt = $pdo->prepare(
            "SELECT sp.first_name, sp.last_name, u.profile_pic
             FROM {$table} t
             INNER JOIN student_profiles sp ON sp.id = t.student_id
             LEFT JOIN users u ON u.id = sp.user_id
             WHERE t.id = :id
             LIMIT 1"
        );
        $stmt->execute([':id' => $ticketId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return $fallback;
        }

        $name = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
        $initial = $name !== '' ? strtoupper(substr($name, 0, 1)) : '?';

        $photo = trim((string)($row['profile_pic'] ?? ''));
        $photoSrc = null;
        if ($photo !== '' && is_file(__DIR__ . '/../' . ltrim($photo, '/'))) {
            $photoSrc = '../' . ltrim($photo, '/');
        }

        return ['photo' => $photoSrc, 'initial' => $initial];
    } catch (PDOException $e) {
        return $fallback;
    }
}

// "New" is purely age-based: a notification counts as New for 24 hours
// after it's created, then moves to "Earlier" regardless of read state.
function topbar_is_new_notification(array $notification): bool
{
    $ts = strtotime((string)($notification['created_at'] ?? ''));
    return $ts !== false && $ts >= (time() - 86400);
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

    return 'bx-bell';
}

// Renders one notification row. Pulled into a function so it can be called
// once per "New" (unread) item and once per "Earlier" (read) item without
// duplicating the markup.
function topbar_render_notification_item(PDO $pdo, array $notification): void
{
    $notifType = (string)$notification['type'];
    $notifAvatar = ['photo' => null, 'initial' => '?'];
    // "new_complaint"/"new_suggestion" are about a student filing a ticket;
    // "complaint_update"/"suggestion_update" arriving here are always about
    // a student action too (they replied, or submitted a rating) - so both
    // groups show the student's avatar on the left instead of a generic icon.
    if (in_array($notifType, ['new_complaint', 'new_suggestion', 'complaint_update', 'suggestion_update'], true)) {
        $notifAvatar = topbar_submitter_avatar($pdo, (string)($notification['ticket_type'] ?? ''), (int)($notification['ticket_id'] ?? 0));
    }
    $isUnread = (int)$notification['is_read'] === 0;
    ?>
    <a href="<?php echo htmlspecialchars(topbar_link_for_type($notifType, (int)($notification['ticket_id'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?>" class="notification-item <?php echo $isUnread ? 'unread' : ''; ?>" data-notification-id="<?php echo (int)$notification['id']; ?>">
        <div class="notif-icon">
            <?php if ($notifAvatar['photo'] !== null): ?>
                <img src="<?php echo htmlspecialchars($notifAvatar['photo'], ENT_QUOTES, 'UTF-8'); ?>" alt="">
            <?php elseif (in_array($notifType, ['new_complaint', 'new_suggestion', 'complaint_update', 'suggestion_update'], true)): ?>
                <span><?php echo htmlspecialchars($notifAvatar['initial'], ENT_QUOTES, 'UTF-8'); ?></span>
            <?php else: ?>
                <i class='bx <?php echo topbar_icon_for_type($notifType); ?>'></i>
            <?php endif; ?>
        </div>
        <div class="notif-content">
            <div class="notif-text"><?php echo htmlspecialchars((string)$notification['message'], ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="notif-time"><?php echo topbar_time_ago((string)$notification['created_at']); ?></div>
        </div>
    </a>
    <?php
}

function topbar_link_for_type(string $type, int $ticketId = 0): string
{
    if (($type === 'new_complaint' || $type === 'complaint_update') && $ticketId > 0) {
        return 'dean_ticket_detail.php?id=' . $ticketId;
    }
    if ($type === 'new_complaint' || $type === 'complaint_update') {
        return 'dean_complaints.php';
    }
    if (($type === 'new_suggestion' || $type === 'suggestion_update') && $ticketId > 0) {
        return 'dean_suggestion_detail.php?id=' . $ticketId;
    }
    if ($type === 'new_suggestion' || $type === 'suggestion_update') {
        return 'dean_suggestions.php';
    }

    return 'dean_dashboard.php';
}

$topbarNotifications = [];
$topbarUnreadCount = 0;
// Default to the local placeholder so the avatar is never just missing -
// only overwritten below if the dean actually has a photo on file.
$topbarProfilePic = '../assets/images/default-avatar.svg';
$topbarDisplayName = trim((string)($_SESSION['full_name'] ?? 'Dean User'));

if (isset($_SESSION['user_id'])) {
    try {
        $stmt = $pdo->prepare(
            'SELECT id, type, message, ticket_type, ticket_id, is_read, created_at
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
        $topbarNotifications = [];
        $topbarUnreadCount = 0;
    }

    try {
        $hasPhotoColumn = false;
        $photoCheck = $pdo->query("SHOW COLUMNS FROM dean_profiles LIKE 'profile_photo'");
        if ($photoCheck && $photoCheck->fetch()) {
            $hasPhotoColumn = true;
        }

        if ($hasPhotoColumn) {
            $deanStmt = $pdo->prepare(
                'SELECT dp.first_name, dp.last_name, dp.profile_photo, c.code AS college_code
                 FROM dean_profiles dp
                 LEFT JOIN colleges c ON c.id = dp.college_id
                 WHERE dp.user_id = :user_id
                 LIMIT 1'
            );
            $deanStmt->execute([':user_id' => (int)$_SESSION['user_id']]);
            $deanProfile = $deanStmt->fetch(PDO::FETCH_ASSOC) ?: null;

            if ($deanProfile) {
                $fullName = trim(((string)($deanProfile['first_name'] ?? '')) . ' ' . ((string)($deanProfile['last_name'] ?? '')));
                if ($fullName !== '') {
                    $topbarDisplayName = $fullName;
                }

                $collegeCode = trim((string)($deanProfile['college_code'] ?? ''));
                if ($collegeCode !== '') {
                    $topbarDisplayName .= ' (' . $collegeCode . ')';
                }

                $photo = trim((string)($deanProfile['profile_photo'] ?? ''));
                if ($photo !== '') {
                    if (stripos($photo, 'http://') === 0 || stripos($photo, 'https://') === 0) {
                        $topbarProfilePic = $photo;
                    } else {
                        $topbarProfilePic = '../' . ltrim($photo, '/');
                    }
                }
            }
        }
    } catch (PDOException $e) {
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
    font-size: 14px;
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

.notif-menu-btn {
    border: none;
    background: transparent;
    color: #65676b;
    font-size: 20px;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    flex-shrink: 0;
}

.notif-menu-btn:hover {
    background: #f2f2f2;
}

.notif-tabs {
    display: flex;
    gap: 8px;
    padding: 10px 16px;
    border-bottom: 1px solid #eef0f5;
}

.notif-tab {
    border: none;
    background: transparent;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 14px;
    font-weight: 600;
    color: #65676b;
    cursor: pointer;
}

.notif-tab.active {
    background: #ede9fe;
    color: #6d28d9;
}

.notif-tab:hover:not(.active) {
    background: #f2f2f2;
}

.notif-section-label {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 16px 6px;
}

.notif-section-label span {
    font-size: 15px;
    font-weight: 700;
    color: #1c1e21;
}

.notif-section-label a {
    font-size: 13px;
    color: #6d28d9;
    text-decoration: none;
    font-weight: 500;
}

.notif-section-label a:hover {
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
    background-color: #6d28d9;
    border-radius: 50%;
}

.notification-item.unread .notif-time {
    color: #6d28d9;
    font-weight: 600;
}

.notif-section + .notif-section {
    border-top: 1px solid #eef0f5;
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

.notif-icon span {
    width: 100%;
    height: 100%;
    border-radius: 50%;
    background: #6d28d9;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 15px;
    font-weight: 700;
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

    <a class="topbar-logo" href="dean_dashboard.php">
        <img src="../assets/images/logo.png" alt="Logo">
        <span>VOICE</span>
    </a>

    <div class="topbar-right">

        <div class="topbar-bell" id="bellIcon">
            <i class='bx bx-bell'></i>
            <span class="notification-badge" id="notificationBadge" style="<?php echo $topbarUnreadCount > 0 ? '' : 'display:none;'; ?>"><?php echo $topbarUnreadCount; ?></span>
            
            <div class="notification-dropdown" id="notificationDropdown">
                
                <div class="notif-header">
                    <h3>Notifications</h3>
                    <button type="button" class="notif-menu-btn" aria-label="Notification options" title="Notification options"><i class='bx bx-dots-horizontal-rounded'></i></button>
                </div>
                <div class="notif-tabs">
                    <button type="button" class="notif-tab active" data-filter="all">All</button>
                    <button type="button" class="notif-tab" data-filter="unread">Unread</button>
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
                        <?php
                            $topbarNewList = array_filter($topbarNotifications, 'topbar_is_new_notification');
                            $topbarEarlierList = array_filter($topbarNotifications, function ($n) { return !topbar_is_new_notification($n); });
                        ?>
                        <?php if ($topbarNewList): ?>
                            <div class="notif-section notif-section-new">
                                <div class="notif-section-label">
                                    <span>New</span>
                                    <a href="dean_notifications.php">See all</a>
                                </div>
                                <?php foreach ($topbarNewList as $notification): topbar_render_notification_item($pdo, $notification); endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($topbarEarlierList): ?>
                            <div class="notif-section notif-section-earlier">
                                <div class="notif-section-label">
                                    <span>Earlier</span>
                                    <?php if (!$topbarNewList): ?><a href="dean_notifications.php">See all</a><?php endif; ?>
                                </div>
                                <?php foreach ($topbarEarlierList as $notification): topbar_render_notification_item($pdo, $notification); endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

            </div>
        </div>

        <div class="profile-container" id="profileIcon">
            <img class="profile-pic" src="<?php echo htmlspecialchars($topbarProfilePic, ENT_QUOTES, 'UTF-8'); ?>" alt="profile">
            <span><?php echo htmlspecialchars($topbarDisplayName, ENT_QUOTES, 'UTF-8'); ?></span>
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
});

const deanNotificationDropdown = document.getElementById('notificationDropdown');
if (deanNotificationDropdown) {
    deanNotificationDropdown.addEventListener('click', function(e) {
        e.stopPropagation();
    });
}

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
    window.location.href = "../../dean/log_out.php";
}

function markNotificationRead(id) {
    const body = 'scope=single&notification_id=' + encodeURIComponent(id);
    if (navigator.sendBeacon) {
        navigator.sendBeacon('../mark_notifications_read.php', new Blob([body], { type: 'application/x-www-form-urlencoded;charset=UTF-8' }));
    } else {
        fetch('../mark_notifications_read.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            body: body,
            keepalive: true
        }).catch(() => {});
    }
}

document.querySelectorAll('.notification-item[data-notification-id]').forEach(function (item) {
    item.addEventListener('click', function () {
        if (!item.classList.contains('unread')) return;
        markNotificationRead(item.dataset.notificationId);
        item.classList.remove('unread');
        const badge = document.getElementById('notificationBadge');
        if (badge) {
            const next = Math.max(0, parseInt(badge.textContent || '0', 10) - 1);
            badge.textContent = String(next);
            if (next === 0) badge.style.display = 'none';
        }
    });
});

document.querySelectorAll('.notif-tab').forEach(function (tab) {
    tab.addEventListener('click', function () {
        document.querySelectorAll('.notif-tab').forEach(function (t) { t.classList.remove('active'); });
        tab.classList.add('active');
        const filter = tab.dataset.filter;
        // "New" vs "Earlier" is purely age-based, so either section can hold
        // a mix of read/unread items. Filter items individually, then hide
        // whichever section (if any) is left with nothing visible.
        document.querySelectorAll('.notif-section').forEach(function (section) {
            let anyVisible = false;
            section.querySelectorAll('.notification-item').forEach(function (item) {
                const show = filter === 'all' || item.classList.contains('unread');
                item.style.display = show ? '' : 'none';
                if (show) anyVisible = true;
            });
            section.style.display = anyVisible ? '' : 'none';
        });
    });
});

document.querySelectorAll('.sidebar .menu a').forEach(link => {
    link.addEventListener('click', closeSidebar);
});
</script>