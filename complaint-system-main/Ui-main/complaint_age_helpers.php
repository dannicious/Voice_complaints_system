<?php

/**
 * Time-based indicators for complaints.
 *
 * A complaint that still reads "New" days after it was filed tells nobody how
 * long it has actually been waiting. These helpers turn `created_at` into a
 * plain "3 days ago" label, and flag how urgent an untouched complaint has
 * become so it stands out in the list.
 */

require_once __DIR__ . '/ticket_flow.php';

/**
 * Relative age of a complaint, e.g. "Just now", "2 hours ago", "3 days ago",
 * "1 week ago". Falls back to an absolute date past ~2 months, where an exact
 * day is more useful than "9 weeks ago".
 */
function complaint_time_ago(string $dateTime): string
{
    $timestamp = strtotime($dateTime);
    if ($timestamp === false) {
        return '';
    }

    $seconds = time() - $timestamp;
    if ($seconds < 0) {
        $seconds = 0;
    }

    if ($seconds < 60) {
        return 'Just now';
    }

    $units = [
        ['limit' => 3600,    'divisor' => 60,     'singular' => 'minute'],
        ['limit' => 86400,   'divisor' => 3600,   'singular' => 'hour'],
        ['limit' => 604800,  'divisor' => 86400,  'singular' => 'day'],
        ['limit' => 5259492, 'divisor' => 604800, 'singular' => 'week'],
    ];

    foreach ($units as $unit) {
        if ($seconds < $unit['limit']) {
            $value = (int)floor($seconds / $unit['divisor']);
            $value = max(1, $value);
            return $value . ' ' . $unit['singular'] . ($value === 1 ? '' : 's') . ' ago';
        }
    }

    return 'on ' . date('M d, Y', $timestamp);
}

/**
 * True when a complaint has not been acted on yet, so its age is worth
 * flagging. Resolved and closed complaints are done; under_review means
 * somebody already picked it up.
 */
function complaint_is_awaiting_action(string $status): bool
{
    return in_array(strtolower(trim($status)), ['new', 'pending', ''], true);
}

/**
 * How overdue an untouched complaint is: 'fresh' (under a day), 'waiting'
 * (1-3 days), or 'overdue' (3 days or more). Returns '' once someone has
 * picked it up, since age stops being a warning at that point.
 */
function complaint_age_level(string $dateTime, string $status): string
{
    if (!complaint_is_awaiting_action($status)) {
        return '';
    }

    $timestamp = strtotime($dateTime);
    if ($timestamp === false) {
        return '';
    }

    $hours = (time() - $timestamp) / 3600;
    if ($hours >= 72) {
        return 'overdue';
    }
    if ($hours >= 24) {
        return 'waiting';
    }

    return 'fresh';
}

/**
 * The age indicator markup for one complaint row, e.g. a red "3 days ago"
 * chip on an untouched complaint. Pair it with the status badge.
 */
function complaint_age_badge(string $dateTime, string $status): string
{
    $label = complaint_time_ago($dateTime);
    if ($label === '') {
        return '';
    }

    $level = complaint_age_level($dateTime, $status);
    $class = 'age-chip' . ($level !== '' ? ' age-' . $level : ' age-done');

    return '<span class="' . $class . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
}

/**
 * Styles for the age chip. Emitted once per page.
 */
function complaint_age_styles(): string
{
    static $printed = false;
    if ($printed) {
        return '';
    }
    $printed = true;

    return '<style>
    .age-chip { display:inline-block; margin-top:4px; font-size:11px; font-weight:600; padding:2px 8px; border-radius:999px; white-space:nowrap; }
    .age-chip.age-fresh { background:#eff6ff; color:#2563eb; }
    .age-chip.age-waiting { background:#fef3c7; color:#b45309; }
    .age-chip.age-overdue { background:#fee2e2; color:#b91c1c; }
    .age-chip.age-done { background:#f3f4f6; color:#6b7280; }
    </style>';
}

/**
 * The status badge's display label, so an untouched complaint stops calling
 * itself "New" once it no longer is: "New" for the first hour, then a short
 * elapsed-time label ("1 hr", "2 hrs", ... "1 day", "2 days", ...). Returns
 * null once the complaint has actually been acted on (status left the
 * new/pending bucket) - callers should fall back to their normal status
 * label in that case.
 */
function complaint_new_status_label(string $dateTime, string $status): ?string
{
    if (!complaint_is_awaiting_action($status)) {
        return null;
    }

    $timestamp = strtotime($dateTime);
    if ($timestamp === false) {
        return 'New';
    }

    $hours = max(0, time() - $timestamp) / 3600;
    if ($hours < 1) {
        return 'New';
    }
    if ($hours < 24) {
        $value = (int)floor($hours);
        return $value . ' hr' . ($value === 1 ? '' : 's');
    }

    $days = (int)floor($hours / 24);
    return $days . ' day' . ($days === 1 ? '' : 's');
}

/**
 * CSS class to pair with complaint_new_status_label(), reusing the same
 * fresh/waiting/overdue colors as the age chip so the badge itself escalates
 * in color the longer a complaint sits untouched. Returns null once the
 * complaint has been acted on - callers should fall back to their normal
 * status badge class in that case.
 */
function complaint_new_status_badge_class(string $dateTime, string $status): ?string
{
    if (!complaint_is_awaiting_action($status)) {
        return null;
    }

    $level = complaint_age_level($dateTime, $status);

    return 'age-' . ($level !== '' ? $level : 'fresh');
}

/**
 * Opportunistic SLA check: notifies whoever handles a complaint (its
 * college's dean, or every admin for a general complaint) the first time it
 * has sat untouched for 24 hours. No real cron job exists in this app, so
 * this is meant to be called from the complaint inbox pages on page load;
 * a dedicated tracking column keeps it to a single notification per
 * complaint until someone actually acts on it.
 */
function check_and_send_complaint_overdue_notifications(PDO $pdo): void
{
    try {
        $pdo->exec('ALTER TABLE complaints ADD COLUMN IF NOT EXISTS overdue_notified_at TIMESTAMP NULL DEFAULT NULL');
    } catch (PDOException $e) {
        // Ignore - either already applied or the DB user lacks ALTER rights.
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT id FROM complaints
             WHERE status IN ('new', 'pending')
               AND overdue_notified_at IS NULL
               AND created_at <= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );
        $stmt->execute();
        $overdueIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if ($overdueIds) {
            $updateStmt = $pdo->prepare('UPDATE complaints SET overdue_notified_at = NOW() WHERE id = :id');
            foreach ($overdueIds as $complaintId) {
                notify_ticket_handlers(
                    $pdo,
                    'complaint',
                    (int)$complaintId,
                    'complaint_overdue',
                    'Reminder: a complaint has been waiting 24+ hours with no response.'
                );
                $updateStmt->execute([':id' => (int)$complaintId]);
            }
        }
    } catch (PDOException $e) {
        // Best-effort - never break a page load over this.
    }
}

/**
 * Marks a complaint as being looked at the moment a dean or the SAS Director
 * opens it, so it stops sitting in the list as "New" while somebody is
 * already handling it. Only promotes untouched complaints.
 */
function mark_complaint_under_review(PDO $pdo, int $complaintId): void
{
    if ($complaintId <= 0) {
        return;
    }

    try {
        $stmt = $pdo->prepare(
            "UPDATE complaints
             SET status = 'under_review'
             WHERE id = :id AND (status = 'new' OR status = 'pending')"
        );
        $stmt->execute([':id' => $complaintId]);
    } catch (PDOException $e) {
        error_log('mark_complaint_under_review: ' . $e->getMessage());
    }
}
