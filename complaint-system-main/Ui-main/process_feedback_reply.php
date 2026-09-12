<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/ticket_flow.php';
require_once __DIR__ . '/school_year_helpers.php';
require_once __DIR__ . '/../config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo 'Method not allowed';
    exit;
}

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    http_response_code(403);
    echo 'Unauthorized';
    exit;
}

$role = (string)$_SESSION['role'];
// Allow admin, dean, and student roles. Students are restricted to acting on their own tickets only.
if ($role !== 'admin' && $role !== 'dean' && $role !== 'student') {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

function can_manage_ticket(PDO $pdo, string $role, string $ticketType, int $ticketId, int $actorId): bool
{
    if ($role === 'admin') {
        if ($ticketType === 'complaint') {
            $stmt = $pdo->prepare(
                'SELECT c.id
                 FROM complaints c
                 LEFT JOIN complaint_categories cc ON cc.id = c.category_id
                 WHERE c.id = :id
                   AND (COALESCE(cc.category_type, cc.route, "general") = "general" OR c.college_id IS NULL)
                 LIMIT 1'
            );
            $stmt->execute([':id' => $ticketId]);
            return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
        }

        if ($ticketType === 'suggestion') {
            $stmt = $pdo->prepare('SELECT id FROM suggestions WHERE id = :id AND college_id IS NULL LIMIT 1');
            $stmt->execute([':id' => $ticketId]);
            return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
        }

        return false;
    }

    if ($role !== 'dean') {
        return false;
    }

    $deanStmt = $pdo->prepare('SELECT college_id FROM dean_profiles WHERE user_id = :user_id LIMIT 1');
    $deanStmt->execute([':user_id' => $actorId]);
    $dean = $deanStmt->fetch(PDO::FETCH_ASSOC);
    if (!$dean || $dean['college_id'] === null) {
        return false;
    }

    $collegeId = (int)$dean['college_id'];

    if ($ticketType === 'complaint') {
        $stmt = $pdo->prepare('SELECT id FROM complaints WHERE id = :id AND college_id = :college_id LIMIT 1');
        $stmt->execute([':id' => $ticketId, ':college_id' => $collegeId]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }

    if ($ticketType === 'suggestion') {
        $stmt = $pdo->prepare('SELECT id FROM suggestions WHERE id = :id AND college_id = :college_id LIMIT 1');
        $stmt->execute([':id' => $ticketId, ':college_id' => $collegeId]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }

    return false;
}

/**
 * Resolve the role-specific profile id (dean_profiles.id / student_profiles.id / admin_profiles.id)
 * for a given users.id. This is the id that must be stored as sender_id / replier_id everywhere,
 * so it lines up with how dean_profiles.id, student_profiles.id etc. are used elsewhere
 * (e.g. manage_complaint.php's $deanProfileId, complaints.student_id).
 * Returns 0 if no matching profile is found.
 */
function resolve_actor_profile_id(PDO $pdo, string $role, int $userId): int
{
    if ($role === 'dean') {
        $stmt = $pdo->prepare('SELECT id FROM dean_profiles WHERE user_id = :user_id LIMIT 1');
        $stmt->execute([':user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['id'] : 0;
    }

    if ($role === 'student') {
        $stmt = $pdo->prepare('SELECT id FROM student_profiles WHERE user_id = :user_id LIMIT 1');
        $stmt->execute([':user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['id'] : 0;
    }

    if ($role === 'admin') {
        $stmt = $pdo->prepare('SELECT id FROM admin_profiles WHERE user_id = :user_id LIMIT 1');
        $stmt->execute([':user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['id'] : 0;
    }

    return 0;
}

/**
 * Real display name for the immediate AJAX response, matching how the rest of the app
 * shows names (first + last name from the role's profile table), not just the username.
 */
function get_actor_display_name(PDO $pdo, string $role, int $profileId): string
{
    if ($role === 'dean') {
        $stmt = $pdo->prepare('SELECT first_name, last_name FROM dean_profiles WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $profileId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($r) {
            $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }
    }

    if ($role === 'student') {
        $stmt = $pdo->prepare('SELECT first_name, last_name FROM student_profiles WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $profileId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($r) {
            $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }
    }

    if ($role === 'admin') {
        $stmt = $pdo->prepare('SELECT name FROM admin_profiles WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $profileId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($r && !empty($r['name'])) {
            return (string)$r['name'];
        }
    }

    return ucfirst($role);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && (string)($_GET['action'] ?? '') === 'list_replies') {
    $ticketType = trim((string)($_GET['ticket_type'] ?? ''));
    $ticketId = isset($_GET['ticket_id']) ? (int)$_GET['ticket_id'] : 0;
    $studentId = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
    $feedbackHistoryId = isset($_GET['feedback_history_id']) && is_numeric($_GET['feedback_history_id']) ? (int)$_GET['feedback_history_id'] : null;

    if ($ticketType === '' || $ticketId <= 0 || $studentId <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid parameters']);
        exit;
    }

    // Students may list replies only for their own student profile
    if ($role === 'student') {
        $stmt = $pdo->prepare('SELECT id FROM student_profiles WHERE user_id = :user_id LIMIT 1');
        $stmt->execute([':user_id' => (int)$_SESSION['user_id']]);
        $sp = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$sp || (int)$sp['id'] !== $studentId) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
            exit;
        }
    } else {
        if (!can_manage_ticket($pdo, $role, $ticketType, $ticketId, (int)$_SESSION['user_id'])) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
            exit;
        }
    }

    try {
        $query = 'SELECT tfr.id, tfr.feedback_history_id, tfr.replier_id, tfr.replier_role, tfr.message, tfr.created_at,
                    COALESCE(
                        NULLIF(CONCAT_WS(" ", dp_profile.first_name, dp_profile.last_name), ""),
                        NULLIF(CONCAT_WS(" ", sp_profile.first_name, sp_profile.last_name), ""),
                        NULLIF(ap_profile.name, ""),
                        NULLIF(u.username, ""),
                        tfr.replier_role
                    ) AS replier_name,
                    COALESCE(
                        NULLIF(dp_profile.profile_photo, ""),
                        NULLIF(sp_profile.profile_pic, ""),
                        NULLIF(u.profile_pic, "")
                    ) AS replier_photo
             FROM ticket_feedback_replies tfr
             LEFT JOIN dean_profiles dp_profile ON tfr.replier_role = "dean" AND dp_profile.id = tfr.replier_id
             LEFT JOIN student_profiles sp_profile ON tfr.replier_role = "student" AND sp_profile.id = tfr.replier_id
             LEFT JOIN admin_profiles ap_profile ON tfr.replier_role = "admin" AND ap_profile.id = tfr.replier_id
             LEFT JOIN users u ON u.id = COALESCE(dp_profile.user_id, sp_profile.user_id, ap_profile.user_id)
             WHERE tfr.ticket_type = :ticket_type AND tfr.ticket_id = :ticket_id AND tfr.student_id = :student_id';
        $params = [
            ':ticket_type' => $ticketType,
            ':ticket_id' => $ticketId,
            ':student_id' => $studentId,
        ];
        if ($feedbackHistoryId !== null) {
            $query .= ' AND tfr.feedback_history_id = :feedback_history_id';
            $params[':feedback_history_id'] = $feedbackHistoryId;
        }
        $query .= ' ORDER BY tfr.created_at ASC, tfr.id ASC';

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $replies = $stmt->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: application/json');
        echo json_encode(['status' => 'ok', 'replies' => $replies]);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Server error']);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && (string)($_GET['action'] ?? '') === 'list_thread_replies') {
    $ticketType = trim((string)($_GET['ticket_type'] ?? ''));
    $ticketId = isset($_GET['ticket_id']) ? (int)$_GET['ticket_id'] : 0;

    if ($ticketType === '' || $ticketId <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid parameters']);
        exit;
    }

    // Allow students to list thread replies only for their own tickets
    if ($role === 'student') {
        $stmt = $pdo->prepare('SELECT student_id FROM ' . ($ticketType === 'complaint' ? 'complaints' : 'suggestions') . ' WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $ticketId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt2 = $pdo->prepare('SELECT id FROM student_profiles WHERE user_id = :user_id LIMIT 1');
        $stmt2->execute([':user_id' => (int)$_SESSION['user_id']]);
        $sp = $stmt2->fetch(PDO::FETCH_ASSOC);
        if (!$row || !$sp || (int)($row['student_id'] ?? 0) !== (int)$sp['id']) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
            exit;
        }
    } else {
        if (!can_manage_ticket($pdo, $role, $ticketType, $ticketId, (int)$_SESSION['user_id'])) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
            exit;
        }
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT tr.id, tr.sender_id, tr.sender_role, tr.message, tr.created_at,
                    COALESCE(
                        NULLIF(CONCAT_WS(" ", dp_profile.first_name, dp_profile.last_name), ""),
                        NULLIF(CONCAT_WS(" ", sp_profile.first_name, sp_profile.last_name), ""),
                        NULLIF(ap_profile.name, ""),
                        NULLIF(u.username, ""),
                        tr.sender_role
                    ) AS sender_name,
                    COALESCE(
                        NULLIF(dp_profile.profile_photo, ""),
                        NULLIF(sp_profile.profile_pic, ""),
                        NULLIF(u.profile_pic, "")
                    ) AS sender_photo
             FROM ticket_replies tr
             LEFT JOIN dean_profiles dp_profile ON tr.sender_role = "dean" AND dp_profile.id = tr.sender_id
             LEFT JOIN student_profiles sp_profile ON tr.sender_role = "student" AND sp_profile.id = tr.sender_id
             LEFT JOIN admin_profiles ap_profile ON tr.sender_role = "admin" AND ap_profile.id = tr.sender_id
             LEFT JOIN users u ON u.id = COALESCE(dp_profile.user_id, sp_profile.user_id, ap_profile.user_id)
             WHERE tr.ticket_type = :ticket_type AND tr.ticket_id = :ticket_id
             ORDER BY tr.created_at ASC, tr.id ASC'
        );
        $stmt->execute([
            ':ticket_type' => $ticketType,
            ':ticket_id' => $ticketId,
        ]);
        $replies = $stmt->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: application/json');
        echo json_encode(['status' => 'ok', 'replies' => $replies]);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Server error']);
        exit;
    }
}

$csrfToken = trim((string)($_POST['csrf_token'] ?? ''));
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Invalid request token']);
    exit;
}

$ticketType = trim((string)($_POST['ticket_type'] ?? ''));
$ticketId = isset($_POST['ticket_id']) ? (int)$_POST['ticket_id'] : 0;
$studentId = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
$message = trim((string)($_POST['message'] ?? ''));
$feedbackHistoryId = isset($_POST['feedback_history_id']) && is_numeric($_POST['feedback_history_id']) ? (int)$_POST['feedback_history_id'] : null;

if ($ticketType === '' || $ticketId <= 0 || $studentId <= 0 || $message === '') {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Invalid parameters']);
    exit;
}

// Authorization for POST: students may post replies only for their own student profile; admin/dean must pass can_manage_ticket
if ($role === 'student') {
    $stmt = $pdo->prepare('SELECT id FROM student_profiles WHERE user_id = :user_id LIMIT 1');
    $stmt->execute([':user_id' => (int)$_SESSION['user_id']]);
    $sp = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$sp || (int)$sp['id'] !== $studentId) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'You are not authorized to reply to this feedback']);
        exit;
    }
} else {
    if (!can_manage_ticket($pdo, $role, $ticketType, $ticketId, (int)$_SESSION['user_id'])) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'You are not authorized to reply to this feedback']);
        exit;
    }
}

// Resolve the role-specific profile id (dean_profiles.id / student_profiles.id / admin_profiles.id).
// This MUST match the id space used everywhere else (e.g. manage_complaint.php's $deanProfileId,
// complaints.student_id) or replies end up attributed to the wrong person / wrong avatar.
$actorProfileId = resolve_actor_profile_id($pdo, $role, (int)$_SESSION['user_id']);
if ($actorProfileId <= 0) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Unable to resolve your profile.']);
    exit;
}

// Prevent replies before the student has submitted a rating and when the ticket is resolved/closed
try {
    $stmt = $pdo->prepare('SELECT status, school_year FROM ' . ($ticketType === 'complaint' ? 'complaints' : 'suggestions') . ' WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $ticketId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $status = strtolower((string)($row['status'] ?? ''));

    // A ticket's school year is fixed at filing time: students may not reply
    // to a ticket from a past school year, even if it's still "open".
    if ($role === 'student') {
        $ticketSy = (string)($row['school_year'] ?? '');
        $ticketSy = sy_is_valid_label($ticketSy) ? $ticketSy : sy_current($pdo);
        if ($ticketSy !== sy_current($pdo)) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'This ticket is from a past school year (' . $ticketSy . ') and is read-only.']);
            exit;
        }
    }

    if ($status === 'resolved') {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Conversation locked: ticket is resolved.']);
        exit;
    }

    if (!ticket_thread_is_unlocked_for_replies($pdo, $ticketType, $ticketId)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Conversation is not available until the student submits a rating after the first staff remark.']);
        exit;
    }
} catch (Throwable $e) {
    // If check fails, allow existing authorization to decide
}

try {
    save_ticket_feedback_reply($pdo, $ticketType, $ticketId, $studentId, $feedbackHistoryId, $actorProfileId, $role, $message);

    // Let the other side know a reply came in - the student when a
    // dean/admin replies, or the handling dean/admin/staff when the
    // student replies.
    $actorName = get_actor_display_name($pdo, $role, $actorProfileId);
    $notifType = $ticketType === 'complaint' ? 'complaint_update' : 'suggestion_update';
    if ($role === 'student') {
        notify_ticket_handlers($pdo, $ticketType, $ticketId, $notifType, $actorName . ' replied regarding their ' . $ticketType . '.');
    } else {
        notify_ticket_owner($pdo, $ticketType, $ticketId, $studentId, $notifType, $actorName . ' replied to your ' . $ticketType . '.');
    }

    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'ok',
        'reply' => [
            'replier_role' => $role,
            'replier_name' => $actorName,
            'message' => $message,
            'created_at' => date('M d, Y h:i A'),
        ],
    ]);
    exit;
} catch (RuntimeException $e) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Server error']);
    exit;
}