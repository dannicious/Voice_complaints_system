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
 * Two-level suggestion category structure: a student-facing "area" (broad,
 * jargon-free grouping, e.g. "Technology & Internet") containing one or more
 * specific categories (e.g. "Wi-Fi / Internet"), each of which carries its
 * own routing destination. The area is purely a picker aid for the student -
 * it is never itself the routing destination.
 *
 * Idempotent and safe to call on every request that touches suggestion
 * categories (student form, admin config, staff self-service, submission).
 * The one-time office -> route_type backfill and the starter taxonomy seed
 * each only ever run once, guarded by checking what already exists first.
 */
function ensure_suggestion_area_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS suggestion_areas (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(150) NOT NULL,
                display_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id), UNIQUE KEY uq_suggestion_area_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    } catch (PDOException $e) {
    }

    // Detect whether route_type already existed *before* adding it, so the
    // one-time office -> route_type backfill below runs exactly once, ever -
    // never re-derived from office on a later request where an admin may
    // have deliberately changed route_type without touching office.
    $routeTypeExisted = true;
    try {
        $columnCheck = $pdo->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'suggestion_categories' AND COLUMN_NAME = 'route_type'"
        );
        $columnCheck->execute();
        $routeTypeExisted = (bool)$columnCheck->fetchColumn();
    } catch (PDOException $e) {
    }

    try {
        $pdo->exec('ALTER TABLE suggestion_categories ADD COLUMN IF NOT EXISTS area_id INT UNSIGNED NULL');
        $pdo->exec("ALTER TABLE suggestion_categories ADD COLUMN IF NOT EXISTS route_type ENUM('office','dean','admin') NOT NULL DEFAULT 'admin'");
    } catch (PDOException $e) {
    }

    if (!$routeTypeExisted) {
        try {
            // Preserve today's actual behavior exactly: a category with an
            // office was staff-routed, everything else fell through to
            // admin. Never touches the suggestions table itself.
            $pdo->exec("UPDATE suggestion_categories SET route_type = 'office' WHERE office IS NOT NULL AND TRIM(office) <> ''");
        } catch (PDOException $e) {
        }
    }

    seed_default_suggestion_areas($pdo);

    // Any category left without an area (pre-existing ones from before this
    // change, or an area that got deleted) lands in the "Other" catch-all
    // rather than disappearing from the student form or the admin board.
    // "Other" is only (re)created here if something actually needs it right
    // now - deleting it while it's empty is meant to stick, not get silently
    // undone on the next page load.
    try {
        $orphanCount = (int)$pdo->query('SELECT COUNT(*) FROM suggestion_categories WHERE area_id IS NULL')->fetchColumn();
        if ($orphanCount > 0) {
            $otherAreaId = ensure_other_suggestion_area($pdo);
            if ($otherAreaId > 0) {
                $pdo->prepare('UPDATE suggestion_categories SET area_id = :area_id WHERE area_id IS NULL')
                    ->execute([':area_id' => $otherAreaId]);
            }
        }
    } catch (PDOException $e) {
    }
}

/**
 * Finds the "Other" catch-all Area, creating it if it doesn't currently
 * exist (an admin is allowed to delete it, same as any other Area, but the
 * moment something needs a fallback - a new bulk-upload row with no Area, a
 * staff member's self-added category, another Area's own deletion - it gets
 * recreated on demand rather than leaving that category with nowhere to go).
 * Returns 0 only if even creating it failed (e.g. no ALTER/INSERT rights).
 */
function ensure_other_suggestion_area(PDO $pdo): int
{
    try {
        $id = (int)$pdo->query("SELECT id FROM suggestion_areas WHERE name = 'Other' LIMIT 1")->fetchColumn();
        if ($id > 0) {
            return $id;
        }
        $nextOrder = (int)$pdo->query('SELECT COALESCE(MAX(display_order), 0) + 10 FROM suggestion_areas')->fetchColumn();
        $pdo->prepare("INSERT INTO suggestion_areas (name, display_order) VALUES ('Other', :display_order)")
            ->execute([':display_order' => $nextOrder]);
        return (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        return 0;
    }
}

/**
 * One-time starter taxonomy so the new Area -> Category -> Office structure
 * isn't empty on first use. Only runs while suggestion_areas has zero rows;
 * an admin who has already built out their own areas is never touched
 * again, and a category name that already exists (from the old flat system)
 * is left alone rather than duplicated.
 */
function seed_default_suggestion_areas(PDO $pdo): void
{
    try {
        $existingAreaCount = (int)$pdo->query('SELECT COUNT(*) FROM suggestion_areas')->fetchColumn();
    } catch (PDOException $e) {
        return;
    }
    if ($existingAreaCount > 0) {
        return;
    }

    // [area name, [[category name, route_type, office], ...]]
    $starterTaxonomy = [
        ['Technology & Internet', [
            ['Wi-Fi / Internet', 'office', 'ICT'],
            ['Computer Laboratories', 'office', 'ICT'],
            ['Student Portal', 'office', 'ICT'],
            ['School Website', 'office', 'ICT'],
            ['Online Systems', 'office', 'ICT'],
            ['Other Technology Concern', 'office', 'ICT'],
        ]],
        ['Library & Learning Resources', [
            ['Book Availability & Requests', 'office', 'Library'],
            ['Library Facilities', 'office', 'Library'],
            ['Online Library Resources', 'office', 'Library'],
            ['Other Library Concern', 'office', 'Library'],
        ]],
        ['Academic & Student Records', [
            ['Enrollment & Registration', 'office', 'Registrar'],
            ['Student Records', 'office', 'Registrar'],
            ['Certificates & Documents', 'office', 'Registrar'],
        ]],
        ['Academic Programs & College', [
            ['Curriculum & Academic Programs', 'dean', null],
            ['Courses & Class Offerings', 'dean', null],
            ['College/Department Concerns', 'dean', null],
        ]],
        ['Student Activities & Services', [
            ['Student Activities & Events', 'admin', null],
            ['Student Organizations', 'admin', null],
            ['Student Programs & Services', 'admin', null],
            ['Student Support Services', 'admin', null],
        ]],
        ['Guidance & Student Support', [
            ['Counseling Services', 'office', 'Guidance'],
            ['Peer Support Programs', 'office', 'Guidance'],
            ['Personal / Behavioral Concerns', 'office', 'Guidance'],
            ['Other Guidance Concern', 'office', 'Guidance'],
        ]],
        ['Health Services', [
            ['Clinic Services', 'office', 'Clinic'],
            ['Health & Wellness Programs', 'office', 'Clinic'],
            ['Medical / Dental Concerns', 'office', 'Clinic'],
            ['Other Health Concern', 'office', 'Clinic'],
        ]],
        // Catch-all: also where any pre-existing, not-yet-sorted category
        // lands (see ensure_suggestion_area_schema above).
        ['Other', [
            ['Other Suggestion', 'admin', null],
        ]],
    ];

    $insertArea = $pdo->prepare('INSERT INTO suggestion_areas (name, display_order) VALUES (:name, :display_order)');
    $findCategory = $pdo->prepare('SELECT id FROM suggestion_categories WHERE LOWER(name) = LOWER(:name) LIMIT 1');
    $insertCategory = $pdo->prepare(
        'INSERT INTO suggestion_categories (name, area_id, route_type, office, is_active) VALUES (:name, :area_id, :route_type, :office, 1)'
    );
    $assignCategoryArea = $pdo->prepare('UPDATE suggestion_categories SET area_id = :area_id WHERE id = :id');

    $order = 0;
    foreach ($starterTaxonomy as [$areaName, $categories]) {
        try {
            $insertArea->execute([':name' => $areaName, ':display_order' => $order]);
            $areaId = (int)$pdo->lastInsertId();
        } catch (PDOException $e) {
            continue;
        }
        $order += 10;

        foreach ($categories as [$categoryName, $routeType, $office]) {
            try {
                $findCategory->execute([':name' => $categoryName]);
                $existingId = (int)$findCategory->fetchColumn();
                if ($existingId > 0) {
                    // Name already exists from before this change - keep it
                    // (and whatever routing it already has), just file it
                    // under the matching new area instead of duplicating it.
                    $assignCategoryArea->execute([':area_id' => $areaId, ':id' => $existingId]);
                    continue;
                }
                $insertCategory->execute([
                    ':name' => $categoryName,
                    ':area_id' => $areaId,
                    ':route_type' => $routeType,
                    ':office' => $office,
                ]);
            } catch (PDOException $e) {
            }
        }
    }
}

/**
 * Looks for a recent, still-open suggestion to the same office with a
 * similar description. Returns the closest match (ticket_no + similarity
 * percent) above the threshold, or null. Informational only - never blocks
 * submission.
 */
function check_suggestion_duplicate(PDO $pdo, int $categoryId, string $description, int $excludeId = 0): ?array
{
    $description = trim($description);
    if ($categoryId <= 0 || $description === '') {
        return null;
    }

    $sql = "SELECT id, ticket_no, description
            FROM suggestions
            WHERE category_id = :category_id
              AND status NOT IN ('not_feasible', 'implemented', 'declined', 'rejected', 'reviewed')
              AND created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)";
    $params = [':category_id' => $categoryId];
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
 * How long ago a suggestion was submitted, as a short handler-facing label.
 * Same-day submissions are "New"; older submissions use days, weeks, or
 * months so the label stays readable in report tables.
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
    $elapsedDays = intdiv($elapsedSeconds, 86400);

    if ($elapsedDays < 1) {
        return 'New';
    }
    if ($elapsedDays < 7) {
        return $elapsedDays . ($elapsedDays === 1 ? ' day ago' : ' days ago');
    }

    $elapsedWeeks = intdiv($elapsedDays, 7);
    if ($elapsedDays < 30) {
        return $elapsedWeeks . ($elapsedWeeks === 1 ? ' week ago' : ' weeks ago');
    }

    $elapsedMonths = intdiv($elapsedDays, 30);
    return $elapsedMonths . ($elapsedMonths === 1 ? ' month ago' : ' months ago');
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

/**
 * The exact Terms of Agreement wording shown on the student suggestion form
 * (see Ui-main/student/student_complaints.php, "TERMS & AGREEMENT SECTION"
 * of the suggestion form). Kept here so every suggestion detail view quotes
 * the same text the student actually agreed to, instead of a paraphrase -
 * mirrors complaint_terms_agreement_statement() in ticket_flow.php.
 */
function suggestion_terms_agreement_statement(): string
{
    return 'Upon filling-up this form, I declare that the information provided is true and accurate to the best of my knowledge. I understand that my suggestion will be reviewed by the University administration for consideration.';
}

function suggestion_terms_agreement_checkbox_label(): string
{
    return 'I agree that the provided information is true and may be used by the University for improvement purposes.';
}
