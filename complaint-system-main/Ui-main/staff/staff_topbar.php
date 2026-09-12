<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../config/db.php';

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

$staffName = (string)($_SESSION['username'] ?? 'Staff');
$staffNotifications = [];
$staffUnreadCount = 0;
$staffBasePath = '/complaint-system/complaint-system-main/Ui-main/';
$staffPhotoSrc = $staffBasePath . 'assets/images/default-avatar.svg';
try {
    $stmt = $pdo->prepare(
        "SELECT sp.name, u.profile_pic
         FROM users u
         LEFT JOIN staff_profiles sp ON sp.user_id = u.id AND sp.status = 'active'
         WHERE u.id = :user_id
         LIMIT 1"
    );
    $stmt->execute([':user_id' => (int)($_SESSION['user_id'] ?? 0)]);
    $staffRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $profileName = trim((string)($staffRow['name'] ?? ''));
    if ($profileName !== '') $staffName = $profileName;

    $storedPhoto = trim((string)($staffRow['profile_pic'] ?? ''));
    if ($storedPhoto !== '') {
        $relativePhoto = ltrim($storedPhoto, '/');
        if (is_file(__DIR__ . '/../' . $relativePhoto)) {
            $staffPhotoSrc = $staffBasePath . $relativePhoto;
        }
    }

    $notificationStmt = $pdo->prepare('SELECT id, type, message, ticket_type, ticket_id, is_read, created_at FROM notifications WHERE user_id = :user_id ORDER BY created_at DESC LIMIT 12');
    $notificationStmt->execute([':user_id' => (int)($_SESSION['user_id'] ?? 0)]);
    $staffNotifications = $notificationStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($staffNotifications as $notification) {
        if ((int)$notification['is_read'] === 0) $staffUnreadCount++;
    }
} catch (PDOException $e) {
}

$staffNewNotifications = array_filter($staffNotifications, 'topbar_is_new_notification');
$staffEarlierNotifications = array_filter($staffNotifications, function ($n) { return !topbar_is_new_notification($n); });

/**
 * The photo + initial of the student who filed the complaint/suggestion a
 * "new_complaint"/"new_suggestion" notification is about, so the dropdown
 * can show their avatar on the left instead of a generic icon.
 *
 * @return array{photo: ?string, initial: string}
 */
function staff_submitter_avatar(PDO $pdo, string $basePath, string $ticketType, int $ticketId): array
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
            $photoSrc = $basePath . ltrim($photo, '/');
        }

        return ['photo' => $photoSrc, 'initial' => $initial];
    } catch (PDOException $e) {
        return $fallback;
    }
}

function staff_render_notification_item(PDO $pdo, array $notification, string $basePath): void
{
    $notifType = (string)$notification['type'];
    $isUnread = (int)$notification['is_read'] === 0;
    $href = $basePath . 'staff/suggestion_detail.php?id=' . (int)$notification['ticket_id'] . '&notification_id=' . (int)$notification['id'];
    $notifAvatar = ['photo' => null, 'initial' => '?'];
    // "new_complaint"/"new_suggestion" are about a student filing a ticket;
    // "suggestion_update" arriving here is always about a student action too
    // (they replied, or submitted a rating) - so both groups show the
    // student's avatar on the left instead of a generic icon.
    if (in_array($notifType, ['new_complaint', 'new_suggestion', 'complaint_update', 'suggestion_update'], true)) {
        $notifAvatar = staff_submitter_avatar($pdo, $basePath, (string)($notification['ticket_type'] ?? ''), (int)($notification['ticket_id'] ?? 0));
    }
    ?>
    <a class="notification-item <?php echo $isUnread ? 'unread' : ''; ?>" href="<?php echo htmlspecialchars($href, ENT_QUOTES, 'UTF-8'); ?>">
        <div class="notif-icon">
            <?php if ($notifAvatar['photo'] !== null): ?>
                <img src="<?php echo htmlspecialchars($notifAvatar['photo'], ENT_QUOTES, 'UTF-8'); ?>" alt="">
            <?php elseif (in_array($notifType, ['new_complaint', 'new_suggestion', 'complaint_update', 'suggestion_update'], true)): ?>
                <span><?php echo htmlspecialchars($notifAvatar['initial'], ENT_QUOTES, 'UTF-8'); ?></span>
            <?php else: ?>
                <i class="bx <?php echo topbar_icon_for_type($notifType); ?>"></i>
            <?php endif; ?>
        </div>
        <div class="notif-content">
            <div class="notif-text"><?php echo htmlspecialchars((string)$notification['message'], ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="notif-time"><?php echo topbar_time_ago((string)$notification['created_at']); ?></div>
        </div>
    </a>
    <?php
}
?>
<style>
.staff-topbar, .staff-topbar * { font-family: 'Poppins', sans-serif; }
.staff-topbar { position: fixed; top: 0; left: 0; right: 0; z-index: 999; display: flex; justify-content: space-between; align-items: center; gap: 20px; padding: 12px 25px; background: #6d28d9; box-shadow: 0 2px 10px rgba(109,40,217,0.3); color: #fff; }
.staff-brand { display: flex; align-items: center; gap: 9px; font-size: 20px; font-weight: 600; }
.staff-brand img { width: 28px; height: 28px; object-fit: contain; filter: brightness(0) invert(1); }
.staff-account { display: flex; align-items: center; gap: 10px; font-size: 14px; font-weight: 600; }
.staff-notifications { position: relative; }
.staff-notifications > button { border: 0; background: transparent; color: #fff; font-size: 24px; cursor: pointer; position: relative; display: inline-flex; }
.staff-notifications > button i.bx-bell { transition: 0.2s; }
.staff-notifications > button:hover i.bx-bell { transform: scale(1.1); }

/* ===== NOTIFICATION CARD (Facebook style) ===== */
.notification-badge {
    position: absolute;
    top: -4px;
    right: -6px;
    background: #ff3b30;
    color: #fff;
    font-size: 11px;
    font-weight: 600;
    padding: 2px 5px;
    border-radius: 50px;
    border: 2px solid #6d28d9;
}

.notification-dropdown {
    display: none;
    position: absolute;
    right: 0;
    top: 34px;
    width: 360px;
    max-width: calc(100vw - 32px);
    background: #fff;
    color: #1f2937;
    border-radius: 12px;
    box-shadow: 0 8px 30px rgba(0,0,0,.2);
    overflow: hidden;
    z-index: 1000;
    cursor: default;
}

.staff-notifications.open .notification-dropdown {
    display: block;
}

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

.notif-section + .notif-section {
    border-top: 1px solid #eef0f5;
}

.notif-section-label {
    padding: 12px 16px 6px;
    font-size: 15px;
    font-weight: 700;
    color: #1c1e21;
}

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
    overflow: hidden;
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

.notif-time {
    font-size: 12px;
    color: #65676b;
    font-weight: 500;
}

.staff-account i { font-size: 25px; }
.staff-profile-pic { width: 35px; height: 35px; border-radius: 50%; object-fit: cover; border: 2px solid rgba(255,255,255,0.6); }
.staff-menu-toggle { display: none; border: 0; background: transparent; color: #fff; font-size: 26px; cursor: pointer; }
@media (max-width: 1024px) { .staff-menu-toggle { display: block; } .staff-topbar { padding: 12px 16px; } }
@media (max-width: 768px) { .notification-dropdown { right: -50px; width: 90vw; max-width: 360px; } }
</style>
<header class="staff-topbar">
    <button class="staff-menu-toggle" type="button" aria-label="Open menu" onclick="document.querySelector('.sidebar')?.classList.toggle('open');document.querySelector('.mobile-sidebar-overlay')?.classList.toggle('show');"><i class="bx bx-menu"></i></button>
    <div class="staff-brand"><img src="/complaint-system/complaint-system-main/Ui-main/assets/images/logo.png" alt="VOICE Logo"><span>VOICE</span></div>
    <div class="staff-account">
        <div class="staff-notifications" id="staffNotifications">
            <button type="button" aria-label="Notifications" onclick="document.getElementById('staffNotifications').classList.toggle('open'); event.stopPropagation();">
                <i class="bx bx-bell"></i>
                <?php if ($staffUnreadCount > 0): ?><span class="notification-badge" id="notificationBadge"><?php echo $staffUnreadCount > 99 ? '99+' : $staffUnreadCount; ?></span><?php endif; ?>
            </button>
            <div class="notification-dropdown" id="notificationDropdown">
                <div class="notif-header">
                    <h3>Notifications</h3>
                </div>
                <div class="notif-tabs">
                    <button type="button" class="notif-tab active" data-filter="all">All</button>
                    <button type="button" class="notif-tab" data-filter="unread">Unread</button>
                </div>
                <div class="notif-body">
                    <?php if (!$staffNotifications): ?>
                        <div class="notification-item">
                            <div class="notif-icon"><i class='bx bx-bell'></i></div>
                            <div class="notif-content">
                                <div class="notif-text">No notifications yet.</div>
                                <div class="notif-time">You're all caught up.</div>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php if ($staffNewNotifications): ?>
                            <div class="notif-section notif-section-new">
                                <div class="notif-section-label"><span>New</span></div>
                                <?php foreach ($staffNewNotifications as $notification): staff_render_notification_item($pdo, $notification, $staffBasePath); endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($staffEarlierNotifications): ?>
                            <div class="notif-section notif-section-earlier">
                                <div class="notif-section-label"><span>Earlier</span></div>
                                <?php foreach ($staffEarlierNotifications as $notification): staff_render_notification_item($pdo, $notification, $staffBasePath); endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <img class="staff-profile-pic" src="<?= htmlspecialchars($staffPhotoSrc, ENT_QUOTES, 'UTF-8') ?>" alt="Profile">
        <span><?= htmlspecialchars($staffName, ENT_QUOTES, 'UTF-8') ?></span>
    </div>
</header>
<script>
document.querySelectorAll('#staffNotifications .notif-tab').forEach(function (tab) {
    tab.addEventListener('click', function () {
        document.querySelectorAll('#staffNotifications .notif-tab').forEach(function (t) { t.classList.remove('active'); });
        tab.classList.add('active');
        const filter = tab.dataset.filter;
        // "New" vs "Earlier" is purely age-based, so either section can hold
        // a mix of read/unread items. Filter items individually, then hide
        // whichever section (if any) is left with nothing visible.
        document.querySelectorAll('#staffNotifications .notif-section').forEach(function (section) {
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

// Clicks inside the dropdown (tabs, items) shouldn't bubble out and get
// treated as an "outside click" that closes the panel.
const staffNotificationDropdown = document.getElementById('notificationDropdown');
if (staffNotificationDropdown) {
    staffNotificationDropdown.addEventListener('click', function (e) {
        e.stopPropagation();
    });
}

document.addEventListener('click', function (event) {
    const staffNotifications = document.getElementById('staffNotifications');
    if (staffNotifications && !event.target.closest('#staffNotifications')) {
        staffNotifications.classList.remove('open');
    }
});
</script>
