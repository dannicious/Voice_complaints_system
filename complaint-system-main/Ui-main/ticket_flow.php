<?php

declare(strict_types=1);

function normalize_ticket_route(?string $route): ?string
{
    $value = strtolower(trim((string)$route));
    if ($value === '') {
        return null;
    }

    $generalAliases = ['general', 'admin'];
    $collegeAliases = ['college', 'dean'];

    if (in_array($value, $generalAliases, true)) {
        return 'general';
    }

    if (in_array($value, $collegeAliases, true)) {
        return 'college';
    }

    return null;
}

function ticket_route_role(string $ticketType, ?string $categoryName, ?string $explicitRoute = null): string
{
    $normalized = strtolower(trim((string)$categoryName));

    $route = normalize_ticket_route($explicitRoute);
    if ($route === 'general') {
        return 'admin';
    }

    if ($route === 'college') {
        return 'dean';
    }

    if ($ticketType === 'complaint') {
        $collegeRelated = [
            'faculty / teaching concerns',
            'academic concerns',
            'enrollment / registrar issues',
        ];

        if (in_array($normalized, $collegeRelated, true)) {
            return 'dean';
        }

        return 'admin';
    }

    $collegeRelated = [
        'academics & curriculum',
    ];

    if (in_array($normalized, $collegeRelated, true)) {
        return 'dean';
    }

    return 'admin';
}

function resolve_ticket_route(PDO $pdo, string $ticketType, ?int $categoryId, ?int $studentCollegeId): array
{
    ensure_category_route_columns($pdo);

    $categoryTable = $ticketType === 'suggestion' ? 'suggestion_categories' : 'complaint_categories';
    $categoryName = null;
    $categoryRoute = null;

    if ($categoryId === null || $categoryId <= 0) {
        throw new RuntimeException('Selected category is invalid.');
    }

    try {
        $stmt = $pdo->prepare("SELECT name, category_type, route FROM {$categoryTable} WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $categoryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $categoryName = (string)($row['name'] ?? '');
            $categoryRoute = normalize_ticket_route($row['category_type'] ?? null);
            if ($categoryRoute === null) {
                $categoryRoute = normalize_ticket_route($row['route'] ?? null);
            }
        }
    } catch (PDOException $e) {
    }

    // If we found a category name but no explicit route, allow inference
    // from the category name (ticket_route_role) instead of hard-failing.
    if ($categoryName === null || $categoryName === '') {
        throw new RuntimeException('Selected category is invalid. Please contact the administrator.');
    }

    $routeRole = ticket_route_role($ticketType, $categoryName, $categoryRoute);
    $routeUserId = null;

    if ($routeRole === 'dean' && $studentCollegeId !== null && $studentCollegeId > 0) {
        try {
            $deanStmt = $pdo->prepare(
                'SELECT u.id
                 FROM users u
                 INNER JOIN dean_profiles dp ON dp.user_id = u.id
                 WHERE u.role = :role AND u.is_active = 1 AND dp.status = :status AND dp.college_id = :college_id
                 LIMIT 1'
            );
            $deanStmt->execute([
                ':role' => 'dean',
                ':status' => 'active',
                ':college_id' => $studentCollegeId,
            ]);
            $dean = $deanStmt->fetch(PDO::FETCH_ASSOC);
            if ($dean) {
                $routeUserId = (int)$dean['id'];
            }
        } catch (PDOException $e) {
        }
    }

    return [
        'role' => $routeRole,
        'user_id' => $routeUserId,
        'category_name' => $categoryName,
        'category_route' => $categoryRoute,
    ];
}

function ensure_category_route_columns(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $done = true;

    $tables = ['complaint_categories', 'suggestion_categories'];
    foreach ($tables as $table) {
        try {
            $columnCheck = $pdo->prepare(
                'SELECT COLUMN_NAME
                 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = :table_name
                     AND COLUMN_NAME IN (\'route\', \'category_type\')'
            );
            $columnCheck->execute([':table_name' => $table]);
            $presentColumns = $columnCheck->fetchAll(PDO::FETCH_COLUMN);

            if (!in_array('route', $presentColumns, true)) {
                $pdo->exec("ALTER TABLE {$table} ADD COLUMN route ENUM('general','college') DEFAULT NULL AFTER name");
            }

            if (!in_array('category_type', $presentColumns, true)) {
                $pdo->exec("ALTER TABLE {$table} ADD COLUMN category_type ENUM('general','college') DEFAULT NULL AFTER route");
            }

            try {
                $pdo->exec("UPDATE {$table} SET category_type = route WHERE category_type IS NULL AND route IS NOT NULL");
            } catch (PDOException $e) {
            }
        } catch (PDOException $e) {
        }
    }
}

function ensure_ticket_status_tracking_columns(PDO $pdo): void
{
    $tables = ['complaints', 'suggestions'];
    foreach ($tables as $table) {
        foreach (['resolved_at', 'closed_at'] as $column) {
            try {
                $checkStmt = $pdo->prepare('SHOW COLUMNS FROM `' . $table . '` LIKE :column');
                $checkStmt->execute([':column' => $column]);
                if (!$checkStmt->fetch(PDO::FETCH_ASSOC)) {
                    $pdo->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` TIMESTAMP NULL DEFAULT NULL');
                }
            } catch (PDOException $e) {
            }
        }
    }
}

function normalize_ticket_status(string $status): string
{
    $value = strtolower(trim($status));
    $map = [
        'new' => 'new',
        'open' => 'new',
        'under_review' => 'under_review',
        'pending' => 'under_review',
        'resolved' => 'resolved',
        'dismissed' => 'dismissed',
    ];

    return $map[$value] ?? $value;
}

function ticket_thread_is_active(string $status): bool
{
    $status = normalize_ticket_status($status);
    return in_array($status, ['new', 'under_review'], true);
}

/**
 * Standard wording used when a complaint is dismissed. It pre-fills the
 * Official Remarks box so a dismissal always carries a reason, and the dean or
 * SAS Director can replace it with their own. Also applied server-side when
 * the box is submitted empty, so the default holds even without JavaScript.
 */
function default_dismissal_remark(): string
{
    return 'The complaint has been determined to have no sufficient or valid basis, '
        . 'is considered a false or unserious report, or does not require further action.';
}

function ticket_thread_is_locked(string $status): bool
{
    $status = normalize_ticket_status($status);
    return in_array($status, ['resolved', 'dismissed'], true);
}

function get_ticket_status_state(PDO $pdo, string $ticketType, int $ticketId): array
{
    ensure_ticket_status_tracking_columns($pdo);
    $table = $ticketType === 'complaint' ? 'complaints' : 'suggestions';

    // Only select columns that actually exist to avoid SQL errors on older schemas
    $selectCols = ['status'];
    try {
        $colCheck = $pdo->prepare('SHOW COLUMNS FROM `' . $table . '` LIKE :col');
        foreach (['resolved_at', 'closed_at'] as $col) {
            try {
                $colCheck->execute([':col' => $col]);
                if ($colCheck->fetch(PDO::FETCH_ASSOC)) {
                    $selectCols[] = $col;
                }
            } catch (PDOException $e) {
                // ignore and continue
            }
        }
    } catch (PDOException $e) {
        // ignore, we'll fall back to selecting only status
    }

    $sql = 'SELECT ' . implode(', ', $selectCols) . ' FROM `' . $table . '` WHERE id = :id LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $ticketId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return ['status' => 'unknown', 'can_reply' => false, 'can_reopen' => false, 'is_closed' => false, 'resolved_at' => null, 'closed_at' => null];
    }

    $status = normalize_ticket_status((string)($row['status'] ?? ''));
    $resolvedAt = $row['resolved_at'] ?? null;
    $closedAt = $row['closed_at'] ?? null;

    if ($status === 'resolved' && $resolvedAt !== null) {
        $resolvedTime = new DateTimeImmutable((string)$resolvedAt);
        $expiryTime = $resolvedTime->modify('+7 days');
        $now = new DateTimeImmutable('now');
        $canReopen = $now <= $expiryTime;
    } else {
        $canReopen = false;
    }

    return [
        'status' => $status,
        'can_reply' => ticket_thread_is_active($status),
        'can_reopen' => $canReopen,
        'is_closed' => false,
        'resolved_at' => $resolvedAt,
        'closed_at' => $closedAt,
    ];
}

function auto_close_expired_tickets(PDO $pdo): void
{
    ensure_ticket_status_tracking_columns($pdo);

    foreach (['complaints', 'suggestions'] as $table) {
        try {
            // ensure the resolved_at column exists before attempting to query it
            $colCheck = $pdo->prepare('SHOW COLUMNS FROM `' . $table . '` LIKE :col');
            $colCheck->execute([':col' => 'resolved_at']);
            if (!$colCheck->fetch(PDO::FETCH_ASSOC)) {
                // skip tables without resolved_at
                continue;
            }

            $stmt = $pdo->prepare(
                'SELECT id
                 FROM `' . $table . '`
                 WHERE status = :status
                   AND resolved_at IS NOT NULL
                   AND resolved_at <= DATE_SUB(NOW(), INTERVAL 7 DAY)
                   AND (closed_at IS NULL OR closed_at < resolved_at)'
            );
            $stmt->execute([':status' => 'resolved']);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Previously this code auto-closed resolved tickets. The system now uses the
            // `resolved` status combined with the presence of student feedback to
            // determine whether a ticket is fully complete. Do not change the status here.
            // No action required.
        } catch (PDOException $e) {
        }
    }
}

function set_ticket_status(PDO $pdo, string $ticketType, int $ticketId, string $newStatus): void
{
    ensure_ticket_status_tracking_columns($pdo);

    $status = normalize_ticket_status($newStatus);
    $table = $ticketType === 'complaint' ? 'complaints' : 'suggestions';

    // detect whether resolved_at / closed_at columns exist
    $hasResolved = false;
    $hasClosed = false;
    try {
        $colCheck = $pdo->prepare('SHOW COLUMNS FROM `' . $table . '` LIKE :col');
        $colCheck->execute([':col' => 'resolved_at']);
        $hasResolved = (bool)$colCheck->fetch(PDO::FETCH_ASSOC);
        $colCheck->execute([':col' => 'closed_at']);
        $hasClosed = (bool)$colCheck->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // ignore and assume columns missing
    }

    if ($status === 'resolved') {
        $parts = ['status = :status'];
        if ($hasResolved) {
            $parts[] = 'resolved_at = NOW()';
        }
        if ($hasClosed) {
            $parts[] = 'closed_at = NULL';
        }
        $sql = 'UPDATE `' . $table . '` SET ' . implode(', ', $parts) . ' WHERE id = :id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':status' => 'resolved', ':id' => $ticketId]);
        return;
    }

    // Dismissed is terminal like resolved, but the complaint was never acted
    // on - it had no valid basis, was a prank, or needed no further action -
    // so no resolved_at is recorded. It needs its own branch because the
    // fallback below coerces anything unrecognised into 'under_review'.
    if ($status === 'dismissed') {
        $parts = ['status = :status'];
        if ($hasResolved) {
            $parts[] = 'resolved_at = NULL';
        }
        if ($hasClosed) {
            $parts[] = 'closed_at = NOW()';
        }
        $sql = 'UPDATE `' . $table . '` SET ' . implode(', ', $parts) . ' WHERE id = :id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':status' => 'dismissed', ':id' => $ticketId]);
        return;
    }

    // 'closed' status has been removed. All tickets that would previously be
    // marked closed remain as 'resolved' and completion is determined by the
    // presence of student feedback. Fall through to default update for other statuses.

    $parts = ['status = :status'];
    if ($hasResolved) {
        $parts[] = 'resolved_at = NULL';
    }
    if ($hasClosed) {
        $parts[] = 'closed_at = NULL';
    }
    $sql = 'UPDATE `' . $table . '` SET ' . implode(', ', $parts) . ' WHERE id = :id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':status' => $status === 'new' ? 'new' : 'under_review', ':id' => $ticketId]);
}

function reopen_ticket(PDO $pdo, string $ticketType, int $ticketId): void
{
    set_ticket_status($pdo, $ticketType, $ticketId, 'under_review');
}

function can_send_ticket_reply(PDO $pdo, string $ticketType, int $ticketId): bool
{
    $state = get_ticket_status_state($pdo, $ticketType, $ticketId);
    return $state['can_reply'];
}

function create_ticket_feedback_table(PDO $pdo): void
{
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS ticket_feedback (
                id INT NOT NULL AUTO_INCREMENT,
                ticket_type ENUM("complaint", "suggestion") NOT NULL,
                ticket_id INT NOT NULL,
                student_id INT NOT NULL,
                satisfaction ENUM("satisfied", "neutral", "not_satisfied") NOT NULL,
                comment TEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY unique_ticket_feedback (ticket_type, ticket_id, student_id),
                KEY idx_ticket_type (ticket_type),
                KEY idx_ticket_id (ticket_id),
                KEY idx_student_id (student_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
        );
    } catch (PDOException $e) {
        // Silently ignore creation errors to avoid breaking page loads in restricted environments
    }
}

function create_ticket_feedback_history_table(PDO $pdo): void
{
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS ticket_feedback_history (
                id INT NOT NULL AUTO_INCREMENT,
                ticket_type ENUM("complaint", "suggestion") NOT NULL,
                ticket_id INT NOT NULL,
                student_id INT NOT NULL,
                satisfaction ENUM("satisfied", "neutral", "not_satisfied") NOT NULL,
                comment TEXT NULL,
                archived_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_ticket_type (ticket_type),
                KEY idx_ticket_id (ticket_id),
                KEY idx_student_id (student_id),
                KEY idx_archived_at (archived_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
        );
    } catch (PDOException $e) {
        // Ignore creation errors
    }
}

function save_ticket_reply(PDO $pdo, string $ticketType, int $ticketId, int $senderId, string $senderRole, string $message): void
{
    // Prevent adding replies to resolved or closed threads
    try {
        $state = get_ticket_status_state($pdo, $ticketType, $ticketId);
        $status = strtolower((string)($state['status'] ?? ''));
        // Prevent replies to threads that have been marked resolved.
        if ($status === 'resolved') {
            return;
        }
    } catch (Throwable $e) {
        // If we cannot determine state, allow save (fail open)
    }

    $stmt = $pdo->prepare(
        'INSERT INTO ticket_replies (ticket_type, ticket_id, sender_id, sender_role, message, is_read)
         VALUES (:ticket_type, :ticket_id, :sender_id, :sender_role, :message, 0)'
    );

    $stmt->execute([
        ':ticket_type' => $ticketType,
        ':ticket_id' => $ticketId,
        ':sender_id' => $senderId,
        ':sender_role' => $senderRole,
        ':message' => $message,
    ]);
}

function ticket_has_staff_reply_after(PDO $pdo, string $ticketType, int $ticketId, ?string $afterDateTime = null): bool
{
    $query = 'SELECT COUNT(*) FROM ticket_replies WHERE ticket_type = :ticket_type AND ticket_id = :ticket_id AND LOWER(sender_role) IN ("dean", "admin")';
    if ($afterDateTime !== null) {
        $query .= ' AND created_at > :after_date';
    }

    $stmt = $pdo->prepare($query);
    $params = [
        ':ticket_type' => $ticketType,
        ':ticket_id' => $ticketId,
    ];
    if ($afterDateTime !== null) {
        $params[':after_date'] = $afterDateTime;
    }

    $stmt->execute($params);
    return (int)$stmt->fetchColumn() > 0;
}

function get_latest_staff_reply_time(PDO $pdo, string $ticketType, int $ticketId, ?string $afterDateTime = null): ?string
{
    $query = 'SELECT MAX(created_at) AS latest_reply_at FROM ticket_replies WHERE ticket_type = :ticket_type AND ticket_id = :ticket_id AND LOWER(sender_role) IN ("dean", "admin")';
    if ($afterDateTime !== null) {
        $query .= ' AND created_at > :after_date';
    }

    $stmt = $pdo->prepare($query);
    $params = [
        ':ticket_type' => $ticketType,
        ':ticket_id' => $ticketId,
    ];
    if ($afterDateTime !== null) {
        $params[':after_date'] = $afterDateTime;
    }

    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row && !empty($row['latest_reply_at']) ? (string)$row['latest_reply_at'] : null;
}

function ticket_feedback_has_staff_replies(PDO $pdo, int $feedbackId): bool
{
    create_ticket_feedback_replies_table($pdo);

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM ticket_feedback_replies WHERE feedback_history_id = :feedback_history_id AND LOWER(replier_role) IN ("dean", "admin")'
    );
    $stmt->execute([':feedback_history_id' => $feedbackId]);

    return (int)$stmt->fetchColumn() > 0;
}

function get_latest_feedback_reply_time(PDO $pdo, int $feedbackId): ?string
{
    create_ticket_feedback_replies_table($pdo);

    $stmt = $pdo->prepare(
        'SELECT MAX(created_at) AS latest_reply_at FROM ticket_feedback_replies WHERE feedback_history_id = :feedback_history_id'
    );
    $stmt->execute([':feedback_history_id' => $feedbackId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row && !empty($row['latest_reply_at']) ? (string)$row['latest_reply_at'] : null;
}

function get_ticket_feedback_submission_reason(PDO $pdo, string $ticketType, int $ticketId, int $studentId): ?string
{
    $state = get_ticket_status_state($pdo, $ticketType, $ticketId);
    $status = $state['status'];

    if (!ticket_has_staff_reply_after($pdo, $ticketType, $ticketId, null)) {
        return 'You can only rate after a staff response is posted.';
    }

    $feedback = get_ticket_feedback($pdo, $ticketType, $ticketId, $studentId);
    // If the ticket is resolved and the student already submitted feedback,
    // treat the ticket as fully completed and do not allow further rating.
    if ($status === 'resolved' && $feedback) {
        return 'This complaint has already received feedback and is now complete.';
    }
    if (!$feedback) {
        return null;
    }

    $feedbackId = isset($feedback['id']) ? (int)$feedback['id'] : 0;
    if ($feedbackId <= 0) {
        return null;
    }

    if (!ticket_feedback_has_staff_replies($pdo, $feedbackId)) {
        return 'You have already rated the current response. Please wait for a staff reply before rating again.';
    }

    $latestFeedbackReplyAt = get_latest_feedback_reply_time($pdo, $feedbackId);
    if ($latestFeedbackReplyAt === null) {
        return 'You have already rated the current response. Please wait for a staff reply before rating again.';
    }

    if (!ticket_has_staff_reply_after($pdo, $ticketType, $ticketId, $latestFeedbackReplyAt)) {
        return 'Please wait for another staff response before rating again.';
    }

    return null;
}

function resolve_feedback_history_id(PDO $pdo, string $ticketType, int $ticketId, int $studentId, ?int $feedbackHistoryId): ?int
{
    create_ticket_feedback_table($pdo);
    create_ticket_feedback_history_table($pdo);

    if ($feedbackHistoryId !== null) {
        $stmt = $pdo->prepare(
            'SELECT id FROM ticket_feedback WHERE id = :id AND ticket_type = :ticket_type AND ticket_id = :ticket_id AND student_id = :student_id LIMIT 1'
        );
        $stmt->execute([
            ':id' => $feedbackHistoryId,
            ':ticket_type' => $ticketType,
            ':ticket_id' => $ticketId,
            ':student_id' => $studentId,
        ]);
        if ($stmt->fetch(PDO::FETCH_ASSOC)) {
            return $feedbackHistoryId;
        }

        $stmt = $pdo->prepare(
            'SELECT id FROM ticket_feedback_history WHERE id = :id AND ticket_type = :ticket_type AND ticket_id = :ticket_id AND student_id = :student_id LIMIT 1'
        );
        $stmt->execute([
            ':id' => $feedbackHistoryId,
            ':ticket_type' => $ticketType,
            ':ticket_id' => $ticketId,
            ':student_id' => $studentId,
        ]);
        if ($stmt->fetch(PDO::FETCH_ASSOC)) {
            return $feedbackHistoryId;
        }
    }

    $stmt = $pdo->prepare(
        'SELECT id FROM ticket_feedback WHERE ticket_type = :ticket_type AND ticket_id = :ticket_id AND student_id = :student_id LIMIT 1'
    );
    $stmt->execute([
        ':ticket_type' => $ticketType,
        ':ticket_id' => $ticketId,
        ':student_id' => $studentId,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)$row['id'] : null;
}

function save_ticket_feedback(PDO $pdo, string $ticketType, int $ticketId, int $studentId, string $satisfaction, ?string $comment): void
{
    create_ticket_feedback_table($pdo);
    create_ticket_feedback_history_table($pdo);
    create_ticket_feedback_replies_table($pdo);

    $reason = get_ticket_feedback_submission_reason($pdo, $ticketType, $ticketId, $studentId);
    if ($reason !== null) {
        throw new RuntimeException($reason);
    }

    $pdo->beginTransaction();

    try {
        $existingStmt = $pdo->prepare(
            'SELECT id, satisfaction, comment
             FROM ticket_feedback
             WHERE ticket_type = :ticket_type AND ticket_id = :ticket_id AND student_id = :student_id
             LIMIT 1'
        );
        $existingStmt->execute([
            ':ticket_type' => $ticketType,
            ':ticket_id' => $ticketId,
            ':student_id' => $studentId,
        ]);
        $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $feedbackId = (int)$existing['id'];
            $archiveStmt = $pdo->prepare(
                'INSERT INTO ticket_feedback_history (ticket_type, ticket_id, student_id, satisfaction, comment)
                 VALUES (:ticket_type, :ticket_id, :student_id, :satisfaction, :comment)'
            );
            $archiveStmt->execute([
                ':ticket_type' => $ticketType,
                ':ticket_id' => $ticketId,
                ':student_id' => $studentId,
                ':satisfaction' => $existing['satisfaction'],
                ':comment' => $existing['comment'],
            ]);

            $deleteStmt = $pdo->prepare('DELETE FROM ticket_feedback WHERE id = :id');
            $deleteStmt->execute([':id' => $feedbackId]);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO ticket_feedback (ticket_type, ticket_id, student_id, satisfaction, comment)
             VALUES (:ticket_type, :ticket_id, :student_id, :satisfaction, :comment)'
        );
        $stmt->execute([
            ':ticket_type' => $ticketType,
            ':ticket_id' => $ticketId,
            ':student_id' => $studentId,
            ':satisfaction' => $satisfaction,
            ':comment' => $comment !== null && $comment !== '' ? $comment : null,
        ]);

        // If the ticket was already marked resolved, close it after the student submits their one-time rating.
        // Do not change ticket status to 'closed'. The system now keeps the
        // status as 'resolved' and uses the presence of feedback to determine
        // whether the ticket is fully complete.

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}

function get_ticket_feedback(PDO $pdo, string $ticketType, int $ticketId, int $studentId): ?array
{
    create_ticket_feedback_table($pdo);

    $stmt = $pdo->prepare(
        'SELECT id, satisfaction, comment, created_at, updated_at
         FROM ticket_feedback
         WHERE ticket_type = :ticket_type AND ticket_id = :ticket_id AND student_id = :student_id
         LIMIT 1'
    );
    $stmt->execute([
        ':ticket_type' => $ticketType,
        ':ticket_id' => $ticketId,
        ':student_id' => $studentId,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function get_ticket_feedback_history(PDO $pdo, string $ticketType, int $ticketId, int $studentId): array
{
    create_ticket_feedback_history_table($pdo);

    $stmt = $pdo->prepare(
        'SELECT satisfaction, comment, archived_at
         FROM ticket_feedback_history
         WHERE ticket_type = :ticket_type AND ticket_id = :ticket_id AND student_id = :student_id
         ORDER BY archived_at DESC, id DESC'
    );
    $stmt->execute([
        ':ticket_type' => $ticketType,
        ':ticket_id' => $ticketId,
        ':student_id' => $studentId,
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function create_ticket_feedback_replies_table(PDO $pdo): void
{
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS ticket_feedback_replies (
                id INT NOT NULL AUTO_INCREMENT,
                ticket_type ENUM("complaint", "suggestion") NOT NULL,
                ticket_id INT NOT NULL,
                student_id INT NOT NULL,
                feedback_history_id INT DEFAULT NULL,
                replier_id INT NOT NULL,
                replier_role VARCHAR(32) NOT NULL,
                message TEXT NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_ticket (ticket_type, ticket_id),
                KEY idx_student (student_id),
                KEY idx_feedback_history (feedback_history_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
        );
    } catch (PDOException $e) {
        // Ignore creation errors
    }
}

function feedback_option_meta(string $option): array
{
    $opt = strtolower(trim($option));
    $map = [
        'satisfied' => [
            'label' => 'Satisfied',
            'description' => 'You were satisfied with the response.',
            'icon' => 'bx-smile',
        ],
        'neutral' => [
            'label' => 'Neutral',
            'description' => 'Neutral response.',
            'icon' => 'bx-meh',
        ],
        'not_satisfied' => [
            'label' => 'Not satisfied',
            'description' => 'You were not satisfied with the response.',
            'icon' => 'bx-sad',
        ],
    ];

    return $map[$opt] ?? ['label' => ucfirst($opt ?: 'Unknown'), 'description' => '', 'icon' => 'bx-question-mark'];
}

function save_ticket_feedback_reply(PDO $pdo, string $ticketType, int $ticketId, int $studentId, ?int $feedbackHistoryId, int $replierId, string $replierRole, string $message): void
{
    create_ticket_feedback_replies_table($pdo);

    $resolvedFeedbackHistoryId = resolve_feedback_history_id($pdo, $ticketType, $ticketId, $studentId, $feedbackHistoryId);

    // Prevent replies if the ticket has been fully completed (resolved + feedback)
    try {
        $state = get_ticket_status_state($pdo, $ticketType, $ticketId);
        $status = strtolower((string)($state['status'] ?? ''));
        $currentFeedback = get_ticket_feedback($pdo, $ticketType, $ticketId, $studentId);
        if ($status === 'resolved' && $currentFeedback) {
            throw new RuntimeException('Cannot reply to feedback: ticket is completed.');
        }
    } catch (PDOException $e) {
        // ignore and continue
    }

    $stmt = $pdo->prepare(
        'INSERT INTO ticket_feedback_replies (ticket_type, ticket_id, student_id, feedback_history_id, replier_id, replier_role, message)
         VALUES (:ticket_type, :ticket_id, :student_id, :feedback_history_id, :replier_id, :replier_role, :message)'
    );

    $stmt->execute([
        ':ticket_type' => $ticketType,
        ':ticket_id' => $ticketId,
        ':student_id' => $studentId,
        ':feedback_history_id' => $resolvedFeedbackHistoryId,
        ':replier_id' => $replierId,
        ':replier_role' => $replierRole,
        ':message' => $message,
    ]);
}

function get_ticket_feedback_replies(PDO $pdo, string $ticketType, int $ticketId, int $studentId): array
{
    create_ticket_feedback_replies_table($pdo);

    $stmt = $pdo->prepare(
        'SELECT id, feedback_history_id, replier_id, replier_role, message, created_at
         FROM ticket_feedback_replies
         WHERE ticket_type = :ticket_type AND ticket_id = :ticket_id AND student_id = :student_id
         ORDER BY created_at ASC, id ASC'
    );
    $stmt->execute([
        ':ticket_type' => $ticketType,
        ':ticket_id' => $ticketId,
        ':student_id' => $studentId,
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Determine whether the ticket thread is unlocked for replies.
 * Rules:
 * - Closed/resolved threads are locked.
 * - If there are no staff replies yet, the thread is unlocked.
 * - If there is at least one staff reply, the student must submit a rating (feedback)
 *   after the latest staff reply for the thread to be unlocked again.
 */
function ticket_thread_is_unlocked_for_replies(PDO $pdo, string $ticketType, int $ticketId): bool
{
    try {
        $state = get_ticket_status_state($pdo, $ticketType, $ticketId);
        // Allow replies for any non-locked thread (i.e., unless resolved/closed)
        return !ticket_thread_is_locked($state['status']);
    } catch (Throwable $e) {
        // On errors, be permissive
        return true;
    }
}
