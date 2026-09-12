<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../school_year_helpers.php';

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

if (!function_exists('topbar_is_new_notification')) {
    // "New" is purely age-based: a notification counts as New for 24 hours
    // after it's created, then moves to "Earlier" regardless of read state.
    function topbar_is_new_notification(array $notification): bool
    {
        $ts = strtotime((string)($notification['created_at'] ?? ''));
        return $ts !== false && $ts >= (time() - 86400);
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

function topbar_link_for_type(string $type, string $ticketType = '', int $ticketId = 0): string
{
    if (($type === 'complaint_update' || $type === 'suggestion_update' || $type === 'call_slip_issued') && $ticketId > 0) {
        $resolvedType = $ticketType !== '' ? $ticketType : ($type === 'suggestion_update' ? 'suggestion' : 'complaint');
        return 'ticket_detail.php?type=' . urlencode($resolvedType) . '&id=' . $ticketId;
    }
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

if (!function_exists('topbar_call_slip_link')) {
    // A "call slip issued" notification goes to two different people: the
    // student who filed the complaint (who already has full access to their
    // own ticket) and the student being summoned (who does not file it, and
    // shouldn't see who filed it or the incident details - only the call
    // slip itself). Route each to the right page.
    function topbar_call_slip_link(PDO $pdo, int $viewerStudentId, string $ticketType, int $ticketId): string
    {
        if ($ticketId <= 0) {
            return 'student_mysubmission.php';
        }
        $ticketType = $ticketType !== '' ? $ticketType : 'complaint';

        try {
            $table = $ticketType === 'complaint' ? 'complaints' : 'suggestions';
            $ownerStmt = $pdo->prepare("SELECT student_id FROM {$table} WHERE id = :id LIMIT 1");
            $ownerStmt->execute([':id' => $ticketId]);
            $ownerId = (int)($ownerStmt->fetchColumn() ?: 0);

            if ($ownerId > 0 && $ownerId === $viewerStudentId) {
                return 'ticket_detail.php?type=' . urlencode($ticketType) . '&id=' . $ticketId;
            }
        } catch (PDOException $e) {
        }

        return 'call_slip_view.php?type=' . urlencode($ticketType) . '&id=' . $ticketId;
    }
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

if (!function_exists('topbar_render_notification_item')) {
    // Renders one notification row. Pulled into a function so it can be
    // called once per "New" (unread) item and once per "Earlier" (read) item
    // without duplicating the markup.
    function topbar_render_notification_item(PDO $pdo, array $notification, int $viewerStudentId = 0): void
    {
        $notifType = (string)$notification['type'];
        if ($notifType === 'call_slip_issued') {
            $notifAvatar = topbar_call_slip_avatar($pdo, (string)($notification['ticket_type'] ?? 'complaint'), (int)($notification['ticket_id'] ?? 0));
        } elseif ($notifType === 'complaint_update' || $notifType === 'suggestion_update') {
            $notifAvatar = topbar_responder_avatar(
                $pdo,
                (string)($notification['ticket_type'] ?? ($notifType === 'suggestion_update' ? 'suggestion' : 'complaint')),
                (int)($notification['ticket_id'] ?? 0)
            );
        } else {
            $notifAvatar = '';
        }
        $isUnread = (int)$notification['is_read'] === 0;
        $href = $notifType === 'call_slip_issued'
            ? topbar_call_slip_link($pdo, $viewerStudentId, (string)($notification['ticket_type'] ?? 'complaint'), (int)($notification['ticket_id'] ?? 0))
            : topbar_link_for_type($notifType, (string)($notification['ticket_type'] ?? ''), (int)($notification['ticket_id'] ?? 0));
        ?>
        <a href="<?php echo htmlspecialchars($href, ENT_QUOTES, 'UTF-8'); ?>" class="notification-item <?php echo $isUnread ? 'unread' : ''; ?>" data-notification-id="<?php echo (int)$notification['id']; ?>">
            <div class="notif-icon">
                <?php if ($notifAvatar !== ''): ?>
                    <img src="<?php echo e($notifAvatar); ?>" alt="">
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
}

if (!function_exists('topbar_call_slip_avatar')) {
    // For a "call slip issued" notification, show the picture of the dean/admin
    // who issued it (falling back to the default avatar) instead of a generic
    // icon, so the notification reads like it came from a person.
    function topbar_call_slip_avatar(PDO $pdo, string $ticketType, int $ticketId): string
    {
        if ($ticketId <= 0) {
            return '';
        }

        try {
            $issuerStmt = $pdo->prepare(
                'SELECT issued_by_role, issued_by_user_id FROM call_slips
                 WHERE ticket_type = :ticket_type AND ticket_id = :ticket_id
                 ORDER BY issued_at DESC LIMIT 1'
            );
            $issuerStmt->execute([':ticket_type' => $ticketType, ':ticket_id' => $ticketId]);
            $issuer = $issuerStmt->fetch(PDO::FETCH_ASSOC);
            if (!$issuer || empty($issuer['issued_by_user_id'])) {
                return '';
            }

            $role = (string)$issuer['issued_by_role'];
            $userId = (int)$issuer['issued_by_user_id'];

            if ($role === 'dean') {
                $photoStmt = $pdo->prepare(
                    'SELECT dp.profile_photo, u.profile_pic FROM dean_profiles dp
                     LEFT JOIN users u ON u.id = dp.user_id WHERE dp.user_id = :user_id LIMIT 1'
                );
            } elseif ($role === 'admin') {
                $photoStmt = $pdo->prepare(
                    'SELECT u.profile_pic FROM admin_profiles ap
                     LEFT JOIN users u ON u.id = ap.user_id WHERE ap.user_id = :user_id LIMIT 1'
                );
            } else {
                return '';
            }
            $photoStmt->execute([':user_id' => $userId]);
            $photoRow = $photoStmt->fetch(PDO::FETCH_ASSOC);
            if (!$photoRow) {
                return '';
            }

            $storedPath = trim((string)($photoRow['profile_photo'] ?? ''));
            if ($storedPath === '') {
                $storedPath = trim((string)($photoRow['profile_pic'] ?? ''));
            }
            return resolve_student_photo($storedPath);
        } catch (PDOException $e) {
            return '';
        }
    }
}

if (!function_exists('topbar_responder_avatar')) {
    // For a "complaint/suggestion update" notification, show the picture of
    // whoever most recently responded (dean/admin/staff) instead of a
    // generic icon - same treatment as the call-slip avatar above, but
    // looking at the latest reply across both reply tables instead of a
    // call slip record.
    function topbar_responder_avatar(PDO $pdo, string $ticketType, int $ticketId): string
    {
        if ($ticketId <= 0) {
            return '';
        }

        try {
            $stmt = $pdo->prepare(
                "SELECT sender_id AS actor_id, sender_role AS actor_role, created_at
                 FROM ticket_replies
                 WHERE ticket_type = :ticket_type1 AND ticket_id = :ticket_id1 AND sender_role IN ('dean', 'admin', 'staff')
                 UNION ALL
                 SELECT replier_id AS actor_id, replier_role AS actor_role, created_at
                 FROM ticket_feedback_replies
                 WHERE ticket_type = :ticket_type2 AND ticket_id = :ticket_id2 AND replier_role IN ('dean', 'admin', 'staff')
                 ORDER BY created_at DESC
                 LIMIT 1"
            );
            $stmt->execute([
                ':ticket_type1' => $ticketType, ':ticket_id1' => $ticketId,
                ':ticket_type2' => $ticketType, ':ticket_id2' => $ticketId,
            ]);
            $actor = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$actor) {
                return '';
            }

            $role = (string)$actor['actor_role'];
            $actorId = (int)$actor['actor_id'];
            $storedPath = '';

            if ($role === 'dean') {
                $photoStmt = $pdo->prepare(
                    'SELECT dp.profile_photo, u.profile_pic FROM dean_profiles dp
                     LEFT JOIN users u ON u.id = dp.user_id WHERE dp.id = :id LIMIT 1'
                );
                $photoStmt->execute([':id' => $actorId]);
                $row = $photoStmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $storedPath = trim((string)($row['profile_photo'] ?? '')) ?: trim((string)($row['profile_pic'] ?? ''));
                }
            } elseif ($role === 'admin') {
                $photoStmt = $pdo->prepare(
                    'SELECT u.profile_pic FROM admin_profiles ap
                     LEFT JOIN users u ON u.id = ap.user_id WHERE ap.id = :id LIMIT 1'
                );
                $photoStmt->execute([':id' => $actorId]);
                $row = $photoStmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $storedPath = trim((string)($row['profile_pic'] ?? ''));
                }
            } elseif ($role === 'staff') {
                // Staff replies store the raw users.id as sender/replier id,
                // unlike dean/admin which store their role-profile id.
                $photoStmt = $pdo->prepare('SELECT profile_pic FROM users WHERE id = :id LIMIT 1');
                $photoStmt->execute([':id' => $actorId]);
                $row = $photoStmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $storedPath = trim((string)($row['profile_pic'] ?? ''));
                }
            }

            if ($storedPath === '') {
                return '';
            }
            return resolve_student_photo($storedPath);
        } catch (PDOException $e) {
            return '';
        }
    }
}

$topbarNotifications = [];
$topbarUnreadCount = 0;
$studentName = 'Student';
$studentEmail = '';
$studentPhoto = '../assets/images/default-avatar.svg';
$studentProfileId = 0;
$schoolYearCurrent = sy_current($pdo);
$schoolYearSelected = $schoolYearCurrent;
$semesterCurrent = semester_current();
$semesterSelected = $semesterCurrent;

if (isset($_SESSION['user_id']) && isset($pdo)) {
    try {
        // Fetch student profile data
        $profileStmt = $pdo->prepare(
            'SELECT sp.id AS student_profile_id, sp.first_name, sp.last_name, sp.student_number, sp.year_level, sp.section,
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
            $studentProfileId = (int)($studentProfile['student_profile_id'] ?? 0);
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
        // Silently fail - use defaults
        error_log('Topbar DB Error: ' . $e->getMessage());
    }

    if ($studentProfileId > 0) {
        $schoolYearSelected = sy_get_selected($pdo, $studentProfileId);
        $semesterSelected = semester_get_selected();
    }
}
$isPastSchoolYear = $schoolYearSelected !== $schoolYearCurrent;
$isPastSemester = $semesterSelected !== $semesterCurrent;
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

/* ===== SCHOOL YEAR SWITCHER ===== */
.topbar-sy {
    display: flex;
    align-items: center;
    gap: 8px;
}

.topbar-sy-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: rgba(255,255,255,0.14);
    border: 1px solid rgba(255,255,255,0.35);
    color: #fff;
    font-family: 'Poppins', sans-serif;
    font-size: 13px;
    font-weight: 600;
    padding: 7px 12px;
    border-radius: 8px;
    cursor: pointer;
    outline: none;
    transition: 0.2s;
}

.topbar-sy-pill:hover,
.topbar-sy-pill:focus {
    background-color: rgba(255,255,255,0.24);
}

.topbar-sy-pill i {
    font-size: 14px;
}

/* ===== SCHOOL YEAR / SEMESTER MODAL ===== */
.school-period-modal { position: fixed; inset: 0; display: none; align-items: center; justify-content: center; z-index: 9998; padding: 20px; }
.school-period-modal.visible { display: flex; }
.school-period-backdrop { position: absolute; inset: 0; background: rgba(15, 23, 42, 0.48); }
.school-period-sheet { position: relative; z-index: 1; width: min(420px, 100%); background: #fff; border-radius: 14px; box-shadow: 0 30px 60px rgba(15, 23, 42, 0.25); padding: 20px; }
.school-period-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; padding-bottom: 14px; border-bottom: 1px solid #e5e7eb; }
.school-period-head > div:first-child { font-weight: 700; font-size: 16px; color: #111827; font-family: 'Poppins', sans-serif; }
.school-period-close { background: transparent; border: none; cursor: pointer; color: #6b7280; font-size: 20px; display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 8px; flex-shrink: 0; }
.school-period-close:hover { background: #f3f4f6; color: #111827; }
.school-period-fields { display: flex; gap: 14px; margin-bottom: 18px; }
.school-period-field { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 6px; }
.school-period-field label { font-size: 11px; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: .06em; font-family: 'Poppins', sans-serif; }
.school-period-field label .required { color: #ef4444; }
.school-period-field input,
.school-period-field select {
    height: 42px;
    padding: 8px 12px;
    border: 1px solid #d1d5db;
    border-radius: 10px;
    font-size: 14px;
    font-family: 'Poppins', sans-serif;
    color: #111827;
    outline: none;
    background: #fff;
}
.school-period-field input:focus,
.school-period-field select:focus { border-color: #6b46c1; box-shadow: 0 0 0 3px rgba(107,70,193,0.12); }
.school-period-submit {
    width: 100%;
    height: 44px;
    border: none;
    border-radius: 10px;
    background: #6b46c1;
    color: #fff;
    font-weight: 700;
    font-size: 14px;
    cursor: pointer;
    font-family: 'Poppins', sans-serif;
}
.school-period-submit:hover { background: #5b3aa8; }

.topbar-sy-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 5px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    white-space: nowrap;
    background: #fef3c7;
    color: #92400e;
    border: 1px solid #fcd34d;
}

@media (max-width: 640px) {
    .topbar-sy-badge span.sy-badge-label {
        display: none;
    }
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

    <a class="topbar-logo" href="student_dashboard.php">
        <img src="../assets/images/logo.PNG" alt="Logo">
        <span>VOICE</span>
    </a>

    <div class="topbar-right">

        <?php if ($studentProfileId > 0): ?>
            <div class="topbar-sy">
                <button type="button" class="topbar-sy-pill" id="schoolPeriodPillBtn" title="Change school year / semester">
                    <i class='bx bx-calendar'></i>
                    <?php echo e($schoolYearSelected); ?> &middot; <?php echo e(semester_display_label($semesterSelected)); ?>
                </button>
                <?php if ($isPastSchoolYear || $isPastSemester): ?>
                    <span class="topbar-sy-badge" title="You're viewing a past school year/semester. These records are read-only.">
                        <i class='bx bx-lock-alt'></i><span class="sy-badge-label">Read-only</span>
                    </span>
                <?php endif; ?>
            </div>

            <div id="schoolPeriodModal" class="school-period-modal" aria-hidden="true" role="dialog" aria-modal="true">
                <div class="school-period-backdrop" onclick="closeSchoolPeriodModal()"></div>
                <div class="school-period-sheet" onclick="event.stopPropagation();">
                    <div class="school-period-head">
                        <div>Change School Year</div>
                        <button type="button" class="school-period-close" onclick="closeSchoolPeriodModal()" aria-label="Close"><i class='bx bx-x'></i></button>
                    </div>
                    <form method="POST" action="set_school_year.php" id="schoolYearForm">
                        <input type="hidden" name="redirect_to" value="<?php echo e($_SERVER['REQUEST_URI'] ?? 'student_dashboard.php'); ?>">
                        <div class="school-period-fields">
                            <div class="school-period-field">
                                <label for="schoolYearSelect">School Year <span class="required">*</span></label>
                                <input type="text" name="school_year" id="schoolYearSelect"
                                       inputmode="numeric" maxlength="9" autocomplete="off"
                                       placeholder="e.g. 2024" value="<?php echo e($schoolYearSelected); ?>"
                                       oninput="formatSchoolYearInput(this, false, event)" onkeydown="schoolYearInputKeydown(event, this)">
                            </div>
                            <div class="school-period-field">
                                <label for="semesterSelect">Semester <span class="required">*</span></label>
                                <select name="semester" id="semesterSelect">
                                    <option value="1" <?php echo $semesterSelected === '1' ? 'selected' : ''; ?>>1st Semester</option>
                                    <option value="2" <?php echo $semesterSelected === '2' ? 'selected' : ''; ?>>2nd Semester</option>
                                </select>
                            </div>
                        </div>
                        <button type="submit" class="school-period-submit">Change School Year</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <div class="topbar-bell" id="bellIcon">
            <i class='bx bx-bell'></i>
            <?php if ($topbarUnreadCount > 0): ?>
                <span class="notification-badge" id="notificationBadge"><?php echo $topbarUnreadCount > 99 ? '99+' : $topbarUnreadCount; ?></span>
            <?php endif; ?>

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
                                    <a href="student_notifications.php">See all</a>
                                </div>
                                <?php foreach ($topbarNewList as $notification): topbar_render_notification_item($pdo, $notification, $studentProfileId); endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($topbarEarlierList): ?>
                            <div class="notif-section notif-section-earlier">
                                <div class="notif-section-label">
                                    <span>Earlier</span>
                                    <?php if (!$topbarNewList): ?><a href="student_notifications.php">See all</a><?php endif; ?>
                                </div>
                                <?php foreach ($topbarEarlierList as $notification): topbar_render_notification_item($pdo, $notification, $studentProfileId); endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
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

    // The dropdown panel lives inside #bellIcon, so clicks on things inside it
    // (the All/Unread tabs, etc.) bubble up here too. Only toggle when the
    // click actually landed on the bell trigger itself, not inside the panel.
    if (e.target.closest('#notificationDropdown')) {
        e.stopPropagation();
        return;
    }

    const profileDropdown = document.getElementById('profileDropdown');

    if (profileDropdown) profileDropdown.style.display = 'none';
    notifDropdown.style.display = notifDropdown.style.display === 'block' ? 'none' : 'block';
    e.stopPropagation();
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

function decrementNotificationBadge() {
    const badge = document.getElementById('notificationBadge');
    if (!badge) return;
    const remaining = parseInt(badge.textContent, 10) - 1;
    if (remaining > 0) {
        badge.textContent = remaining;
    } else {
        badge.remove();
    }
}

document.querySelectorAll('.notification-item[data-notification-id]').forEach(function (item) {
    item.addEventListener('click', function () {
        if (!item.classList.contains('unread')) return;
        markNotificationRead(item.dataset.notificationId);
        item.classList.remove('unread');
        decrementNotificationBadge();
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

function openSchoolPeriodModal() {
    const modal = document.getElementById('schoolPeriodModal');
    if (modal) {
        modal.classList.add('visible');
        modal.setAttribute('aria-hidden', 'false');
    }
}

function closeSchoolPeriodModal() {
    const modal = document.getElementById('schoolPeriodModal');
    if (modal) {
        modal.classList.remove('visible');
        modal.setAttribute('aria-hidden', 'true');
    }
}

const schoolPeriodPillBtn = document.getElementById('schoolPeriodPillBtn');
if (schoolPeriodPillBtn) {
    schoolPeriodPillBtn.addEventListener('click', openSchoolPeriodModal);
}
</script>
<?php echo sy_smart_input_script(); ?>