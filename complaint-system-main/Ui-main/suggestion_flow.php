<?php

declare(strict_types=1);

/**
 * Suggestion-only status/flow helpers.
 *
 * Kept separate from ticket_flow.php on purpose: complaints keep using the
 * new/under_review/resolved/dismissed vocabulary in ticket_flow.php exactly
 * as before. Nothing in here is called from complaint code paths.
 */

require_once __DIR__ . '/ticket_flow.php';

/**
 * Widen suggestions.status to include the real decision/implementation
 * values the app didn't previously expose in its UI. All existing values
 * are kept so old rows keep their stored status.
 */
function ensure_suggestion_status_enum(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $stmt = $pdo->prepare(
            "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'suggestions' AND COLUMN_NAME = 'status'"
        );
        $stmt->execute();
        $columnType = (string)$stmt->fetchColumn();
        if (strpos($columnType, 'accepted') !== false) {
            return; // already migrated
        }

        $pdo->exec(
            "ALTER TABLE suggestions MODIFY status ENUM(
                'new','under_review','approved','implemented','declined','rejected','reviewed',
                'accepted','not_feasible','needs_info','planned','in_progress'
            ) DEFAULT 'under_review'"
        );
    } catch (PDOException $e) {
        // Ignore - either already applied or the DB user lacks ALTER rights.
    }
}

/**
 * Extra timestamp columns used to track the decision/implementation
 * timeline and one-shot SLA reminder flags, added only if missing.
 */
function ensure_suggestion_tracking_columns(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $columns = [
        'decided_at' => 'TIMESTAMP NULL DEFAULT NULL',
        'implemented_at' => 'TIMESTAMP NULL DEFAULT NULL',
        'overdue_notified_at' => 'TIMESTAMP NULL DEFAULT NULL',
        'escalated_notified_at' => 'TIMESTAMP NULL DEFAULT NULL',
    ];

    foreach ($columns as $column => $definition) {
        try {
            $pdo->exec("ALTER TABLE suggestions ADD COLUMN IF NOT EXISTS `{$column}` {$definition}");
        } catch (PDOException $e) {
            // Ignore - either already applied or the DB user lacks ALTER rights.
        }
    }
}

/**
 * Single source of truth for how a suggestion status is labeled, badged,
 * and whether it locks the reply thread. Legacy values from before this
 * change (approved/reviewed/declined/rejected) are mapped to a sensible
 * bucket so old rows keep displaying correctly; new code never writes them.
 */
function suggestion_status_meta(string $status): array
{
    $status = strtolower(trim($status));

    $map = [
        // Open / decision stage
        'new' => ['label' => 'Under Review', 'stage' => 'open', 'badge' => 'status-review', 'locked' => false],
        'under_review' => ['label' => 'Under Review', 'stage' => 'open', 'badge' => 'status-review', 'locked' => false],
        'needs_info' => ['label' => 'Needs More Info', 'stage' => 'open', 'badge' => 'status-needs-info', 'locked' => false],

        // Decision outcomes
        'accepted' => ['label' => 'Accepted', 'stage' => 'implementation', 'badge' => 'status-accepted', 'locked' => false],
        'not_feasible' => ['label' => 'Not Feasible', 'stage' => 'closed', 'badge' => 'status-declined', 'locked' => true],

        // Implementation stage (only reachable once accepted)
        'planned' => ['label' => 'Planned', 'stage' => 'implementation', 'badge' => 'status-planned', 'locked' => false],
        'in_progress' => ['label' => 'In Progress', 'stage' => 'implementation', 'badge' => 'status-progress', 'locked' => false],
        'implemented' => ['label' => 'Implemented', 'stage' => 'closed', 'badge' => 'status-implemented', 'locked' => true],

        // Legacy values kept readable for old rows; never written going forward
        'approved' => ['label' => 'Under Review', 'stage' => 'open', 'badge' => 'status-review', 'locked' => false],
        'reviewed' => ['label' => 'Reviewed (Legacy)', 'stage' => 'closed', 'badge' => 'status-implemented', 'locked' => true],
        'declined' => ['label' => 'Declined (Legacy)', 'stage' => 'closed', 'badge' => 'status-declined', 'locked' => true],
        'rejected' => ['label' => 'Rejected (Legacy)', 'stage' => 'closed', 'badge' => 'status-declined', 'locked' => true],
    ];

    return $map[$status] ?? [
        'label' => ucfirst(str_replace('_', ' ', $status !== '' ? $status : 'unknown')),
        'stage' => 'open',
        'badge' => 'status-review',
        'locked' => false,
    ];
}

function suggestion_thread_is_locked(string $status): bool
{
    return (bool)suggestion_status_meta($status)['locked'];
}

/**
 * Valid next statuses a handler (staff/dean/admin) can move a suggestion to
 * from its current status, keyed by value with a human label. Empty once
 * the suggestion is in a terminal/locked state.
 */
function suggestion_allowed_next_statuses(string $current): array
{
    $current = strtolower(trim($current));

    // Decision stage: still open, or the handler wants more detail first.
    if (in_array($current, ['new', 'under_review', 'approved', 'needs_info', ''], true)) {
        return [
            'under_review' => 'Keep Under Review',
            'needs_info' => 'Ask for More Info',
            'accepted' => 'Accept',
            'not_feasible' => 'Not Feasible',
        ];
    }

    // Implementation stage: only reachable once a decision to accept was made.
    if (in_array($current, ['accepted', 'planned', 'in_progress'], true)) {
        return [
            'planned' => 'Planned',
            'in_progress' => 'In Progress',
            'implemented' => 'Mark Implemented',
        ];
    }

    // Terminal states (implemented, not_feasible, legacy reviewed/declined/rejected): locked.
    return [];
}

/**
 * Suggestion-only status writer. Deliberately does not call the shared
 * set_ticket_status() in ticket_flow.php - that helper only knows the
 * complaint vocabulary (resolved/dismissed) and would silently coerce any
 * other value to under_review.
 */
function set_suggestion_status(PDO $pdo, int $suggestionId, string $newStatus): void
{
    ensure_suggestion_status_enum($pdo);
    ensure_suggestion_tracking_columns($pdo);

    $newStatus = strtolower(trim($newStatus));

    $parts = ['status = :status'];
    if (in_array($newStatus, ['accepted', 'not_feasible'], true)) {
        $parts[] = 'decided_at = NOW()';
    }
    if ($newStatus === 'implemented') {
        $parts[] = 'implemented_at = NOW()';
    }
    // A fresh status change means any prior overdue flag no longer applies.
    $parts[] = 'overdue_notified_at = NULL';

    $sql = 'UPDATE suggestions SET ' . implode(', ', $parts) . ' WHERE id = :id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':status' => $newStatus, ':id' => $suggestionId]);
}

/**
 * Looks for a recent, still-open suggestion to the same office with a
 * similar description. Returns the closest match (ticket_no + similarity
 * percent) above the threshold, or null. Informational only - never blocks
 * submission.
 */
function check_suggestion_duplicate(PDO $pdo, string $office, string $description, int $excludeId = 0): ?array
{
    $office = trim($office);
    $description = trim($description);
    if ($office === '' || $description === '') {
        return null;
    }

    $sql = "SELECT id, ticket_no, description
            FROM suggestions
            WHERE LOWER(TRIM(office)) = LOWER(TRIM(:office))
              AND status NOT IN ('not_feasible', 'implemented', 'declined', 'rejected', 'reviewed')
              AND created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)";
    $params = [':office' => $office];
    if ($excludeId > 0) {
        $sql .= ' AND id <> :exclude_id';
        $params[':exclude_id'] = $excludeId;
    }
    $sql .= ' ORDER BY created_at DESC LIMIT 50';

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return null;
    }

    $best = null;
    $bestPercent = 0.0;
    foreach ($rows as $row) {
        $candidate = (string)$row['description'];
        similar_text(strtolower($description), strtolower($candidate), $percent);
        if ($percent > $bestPercent) {
            $bestPercent = $percent;
            $best = $row;
        }
    }

    if ($best !== null && $bestPercent >= 55.0) {
        return [
            'id' => (int)$best['id'],
            'ticket_no' => (string)$best['ticket_no'],
            'percent' => round($bestPercent, 1),
        ];
    }

    return null;
}

/**
 * How long ago a suggestion was submitted, as a short handler-facing label:
 * "New" for anything under 1 hour old, otherwise the whole number of hours
 * elapsed ("1hr", "2hr", "27hr", ...) - it just keeps counting, it doesn't
 * switch to a "days" format.
 */
function suggestion_age_label(string $createdAt): string
{
    try {
        $created = new DateTimeImmutable($createdAt);
    } catch (Exception $e) {
        return '';
    }

    $now = new DateTimeImmutable('now');
    $elapsedSeconds = $now->getTimestamp() - $created->getTimestamp();
    if ($elapsedSeconds < 0) {
        $elapsedSeconds = 0;
    }
    $elapsedHours = intdiv($elapsedSeconds, 3600);

    return $elapsedHours < 1 ? 'New' : $elapsedHours . 'hr';
}

/**
 * Opportunistic SLA check, meant to be called once at the top of the
 * staff/dean/admin suggestion inbox pages. No real cron job exists in this
 * app, so this runs cheaply on page load instead; each suggestion is only
 * notified once per breach thanks to the overdue_notified_at stamp
 * (cleared automatically on the next status change by
 * set_suggestion_status()).
 *
 * Rule: a suggestion still sitting open (not yet decided) 24 hours after it
 * was submitted counts as "ignored" and the handler (staff/dean/admin,
 * whoever it's routed to) gets notified once.
 */
function check_and_send_suggestion_overdue_notifications(PDO $pdo): void
{
    ensure_suggestion_tracking_columns($pdo);

    $reminderHours = 24;
    $openStatuses = "'new','under_review','needs_info','approved'";

    try {
        $stmt = $pdo->prepare(
            "SELECT id FROM suggestions
             WHERE status IN ({$openStatuses})
               AND overdue_notified_at IS NULL
               AND created_at <= DATE_SUB(NOW(), INTERVAL :hours HOUR)"
        );
        $stmt->execute([':hours' => $reminderHours]);
        $overdueIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if ($overdueIds) {
            $updateStmt = $pdo->prepare('UPDATE suggestions SET overdue_notified_at = NOW() WHERE id = :id');
            foreach ($overdueIds as $suggestionId) {
                notify_ticket_handlers(
                    $pdo,
                    'suggestion',
                    (int)$suggestionId,
                    'suggestion_overdue',
                    'Reminder: this suggestion has been ignored for over 24 hours with no response yet.'
                );
                $updateStmt->execute([':id' => (int)$suggestionId]);
            }
        }
    } catch (PDOException $e) {
        // Best-effort - never break a page load over this.
    }
}
