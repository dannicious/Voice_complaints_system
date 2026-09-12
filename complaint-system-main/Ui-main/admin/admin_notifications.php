<?php
session_start();
require_once __DIR__ . '/../db_connection.php';

function require_admin_session(PDO $pdo): void
{
    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    $sessionRole = strtolower(trim((string)($_SESSION['role'] ?? '')));

    if ($userId <= 0) {
        header('Location: ../login.php?error=' . urlencode('Please log in as admin.'));
        exit;
    }

    if ($sessionRole === 'admin') {
        return;
    }

    try {
        $stmt = $pdo->prepare('SELECT role FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch();
        $dbRole = strtolower(trim((string)($row['role'] ?? '')));

        if ($dbRole === 'admin') {
            $_SESSION['role'] = 'admin';
            return;
        }
    } catch (PDOException $e) {
    }

    header('Location: ../login.php?error=' . urlencode('Please log in as admin.'));
    exit;
}

require_admin_session($pdo);

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

// admin_topbar.php (included in <body> below) defines topbar_time_ago(),
// topbar_icon_for_type(), topbar_link_for_type(), and topbar_submitter_avatar().
// PHP function definitions at file scope are hoisted at compile time, so
// calling them further down this same request after the include works fine.

$allNotifications = [];
try {
    $stmt = $pdo->prepare(
        'SELECT id, type, message, ticket_type, ticket_id, is_read, created_at
         FROM notifications
         WHERE user_id = :user_id
         ORDER BY created_at DESC
         LIMIT 200'
    );
    $stmt->execute([':user_id' => (int)$_SESSION['user_id']]);
    $allNotifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Notifications - VOICE</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
body { background: #f4f6fb; }
.notif-page-main { margin-left: 260px; margin-top: 61px; padding: 25px; min-height: calc(100vh - 61px); }
.notif-page-card { max-width: 640px; margin: 0 auto; background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); overflow: hidden; }
.notif-page-header { display: flex; justify-content: space-between; align-items: center; padding: 20px 24px 6px; }
.notif-page-header h1 { font-size: 26px; font-weight: 700; color: #1c1e21; }
.notif-page-tabs { display: flex; gap: 8px; padding: 14px 24px; }
.notif-page-tab { border: none; background: transparent; padding: 8px 16px; border-radius: 20px; font-size: 15px; font-weight: 600; color: #65676b; cursor: pointer; font-family: 'Poppins', sans-serif; }
.notif-page-tab.active { background: #ede9fe; color: #6d28d9; }
.notif-page-tab:hover:not(.active) { background: #f2f2f2; }
.notif-page-section-label { padding: 10px 24px 6px; font-size: 17px; font-weight: 700; color: #1c1e21; }
.notif-page-section + .notif-page-section { border-top: 1px solid #eef0f5; }
.notif-page-list { display: flex; flex-direction: column; }
.notif-page-item { display: flex; align-items: flex-start; gap: 12px; padding: 12px 24px; text-decoration: none; color: inherit; }
.notif-page-item:hover { background: #f2f2f2; }
.notif-page-icon { width: 48px; height: 48px; border-radius: 50%; background: #e4e6eb; display: flex; align-items: center; justify-content: center; flex-shrink: 0; color: #050505; font-size: 22px; overflow: hidden; }
.notif-page-icon img { width: 100%; height: 100%; object-fit: cover; }
.notif-page-icon span { width: 100%; height: 100%; border-radius: 50%; background: #6d28d9; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 17px; font-weight: 700; }
.notif-page-content { flex: 1; min-width: 0; }
.notif-page-text { font-size: 14px; line-height: 1.4; color: #050505; }
.notif-page-time { font-size: 13px; color: #6d28d9; font-weight: 600; margin-top: 4px; }
.notif-page-item.read .notif-page-time { color: #65676b; font-weight: 500; }
.notif-page-dot { width: 10px; height: 10px; border-radius: 50%; background: #1877f2; flex-shrink: 0; margin-top: 18px; }
.notif-page-empty { text-align: center; color: #9ca3af; padding: 60px 20px; font-size: 14px; }
@media (max-width: 1024px) { .notif-page-main { margin-left: 0; padding: 16px; } }
</style>
</head>
<body>
<?php include 'admin_topbar.php'; ?>
<?php include 'admin_sidebar.php'; ?>

<div class="notif-page-main">
    <div class="notif-page-card">
        <div class="notif-page-header">
            <h1>Notifications</h1>
        </div>
        <div class="notif-page-tabs">
            <button type="button" class="notif-page-tab active" data-filter="all">All</button>
            <button type="button" class="notif-page-tab" data-filter="unread">Unread</button>
        </div>

        <?php if (!$allNotifications): ?>
            <div class="notif-page-empty">You don't have any notifications yet.</div>
        <?php else: ?>
            <?php
                $pageNewList = array_filter($allNotifications, 'topbar_is_new_notification');
                $pageEarlierList = array_filter($allNotifications, function ($n) { return !topbar_is_new_notification($n); });

                function render_notif_page_item(PDO $pdo, array $notification): void
                {
                    $notifType = (string)$notification['type'];
                    $isUnread = (int)$notification['is_read'] === 0;
                    $notifAvatar = ['photo' => null, 'initial' => '?'];
                    if (in_array($notifType, ['new_complaint', 'new_suggestion'], true)) {
                        $notifAvatar = topbar_submitter_avatar($pdo, (string)($notification['ticket_type'] ?? ''), (int)($notification['ticket_id'] ?? 0));
                    }
                    ?>
                    <a href="<?php echo e(topbar_link_for_type($notifType, (int)($notification['ticket_id'] ?? 0))); ?>"
                       class="notif-page-item <?php echo $isUnread ? 'unread' : 'read'; ?>"
                       data-notification-id="<?php echo (int)$notification['id']; ?>">
                        <div class="notif-page-icon">
                            <?php if ($notifAvatar['photo'] !== null): ?>
                                <img src="<?php echo e($notifAvatar['photo']); ?>" alt="">
                            <?php elseif (in_array($notifType, ['new_complaint', 'new_suggestion'], true)): ?>
                                <span><?php echo e($notifAvatar['initial']); ?></span>
                            <?php else: ?>
                                <i class='bx <?php echo e(topbar_icon_for_type($notifType)); ?>'></i>
                            <?php endif; ?>
                        </div>
                        <div class="notif-page-content">
                            <div class="notif-page-text"><?php echo e((string)$notification['message']); ?></div>
                            <div class="notif-page-time"><?php echo e(topbar_time_ago((string)$notification['created_at'])); ?></div>
                        </div>
                        <?php if ($isUnread): ?><div class="notif-page-dot"></div><?php endif; ?>
                    </a>
                    <?php
                }
            ?>
            <?php if ($pageNewList): ?>
                <div class="notif-page-section notif-page-section-new">
                    <div class="notif-page-section-label">New</div>
                    <div class="notif-page-list">
                        <?php foreach ($pageNewList as $notification): render_notif_page_item($pdo, $notification); endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            <?php if ($pageEarlierList): ?>
                <div class="notif-page-section notif-page-section-earlier">
                    <div class="notif-page-section-label">Earlier</div>
                    <div class="notif-page-list">
                        <?php foreach ($pageEarlierList as $notification): render_notif_page_item($pdo, $notification); endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<script>
document.querySelectorAll('.notif-page-item[data-notification-id]').forEach(function (item) {
    item.addEventListener('click', function () {
        if (!item.classList.contains('unread')) return;
        const id = item.dataset.notificationId;
        const body = 'scope=single&notification_id=' + encodeURIComponent(id);
        if (navigator.sendBeacon) {
            navigator.sendBeacon('../mark_notifications_read.php', new Blob([body], { type: 'application/x-www-form-urlencoded;charset=UTF-8' }));
        } else {
            fetch('../mark_notifications_read.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                body: body,
                keepalive: true
            }).catch(function () {});
        }
    });
});

document.querySelectorAll('.notif-page-tab').forEach(function (tab) {
    tab.addEventListener('click', function () {
        document.querySelectorAll('.notif-page-tab').forEach(function (t) { t.classList.remove('active'); });
        tab.classList.add('active');
        const filter = tab.dataset.filter;
        // "New" vs "Earlier" is purely age-based, so either section can hold
        // a mix of read/unread items. Filter items individually, then hide
        // whichever section (if any) is left with nothing visible.
        document.querySelectorAll('.notif-page-section').forEach(function (section) {
            let anyVisible = false;
            section.querySelectorAll('.notif-page-item').forEach(function (item) {
                const show = filter === 'all' || item.classList.contains('unread');
                item.style.display = show ? '' : 'none';
                if (show) anyVisible = true;
            });
            section.style.display = anyVisible ? '' : 'none';
        });
    });
});
</script>
</body>
</html>
