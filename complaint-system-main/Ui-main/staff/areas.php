<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../suggestion_flow.php';
ensure_role('staff');

ensure_suggestion_area_schema($pdo);

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$userId = (int)$_SESSION['user_id'];
$staffStmt = $pdo->prepare("SELECT office FROM staff_profiles WHERE user_id = :user_id AND status = 'active' LIMIT 1");
$staffStmt->execute([':user_id' => $userId]);
$office = trim((string)$staffStmt->fetchColumn());
if ($office === '') {
    http_response_code(403);
    exit('Staff office is not configured.');
}

if (!isset($_SESSION['staff_csrf'])) {
    $_SESSION['staff_csrf'] = bin2hex(random_bytes(32));
}

$flash = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['staff_csrf'], (string)($_POST['csrf_token'] ?? ''))) {
        $error = 'Invalid request token. Refresh and try again.';
    } else {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'add_area') {
            $name = trim((string)($_POST['name'] ?? ''));

            if ($name === '') {
                $error = 'Category name is required.';
            } else {
                try {
                    $dupStmt = $pdo->prepare(
                        'SELECT id FROM suggestion_categories WHERE LOWER(name) = LOWER(:name) LIMIT 1'
                    );
                    $dupStmt->execute([':name' => $name]);
                    if ($dupStmt->fetch()) {
                        throw new RuntimeException('A category with that name already exists.');
                    }

                    // Filed under the "Other" Area by default (Areas are the
                    // student-facing Level 1 picker, not something staff need
                    // to manage) - the admin board can move it into a more
                    // fitting Area later if it makes sense to. Always routes
                    // straight back to this staff member's own office.
                    $otherAreaId = ensure_other_suggestion_area($pdo);

                    $insert = $pdo->prepare(
                        "INSERT INTO suggestion_categories (name, area_id, route_type, office, is_active)
                         VALUES (:name, :area_id, 'office', :office, 1)"
                    );
                    $insert->execute([
                        ':name' => $name,
                        ':area_id' => $otherAreaId > 0 ? $otherAreaId : null,
                        ':office' => $office,
                    ]);

                    $flash = 'Category added successfully.';
                } catch (RuntimeException $exception) {
                    $error = $exception->getMessage();
                } catch (PDOException $exception) {
                    $error = 'Unable to add category right now.';
                }
            }
        } elseif ($action === 'toggle_area') {
            $id = (int)($_POST['id'] ?? 0);

            if ($id <= 0) {
                $error = 'Invalid request.';
            } else {
                try {
                    $check = $pdo->prepare(
                        'SELECT is_active FROM suggestion_categories WHERE id = :id AND LOWER(TRIM(office)) = LOWER(TRIM(:office)) LIMIT 1'
                    );
                    $check->execute([':id' => $id, ':office' => $office]);
                    $row = $check->fetch(PDO::FETCH_ASSOC);

                    if (!$row) {
                        throw new RuntimeException('Category not found in your office.');
                    }

                    $newStatus = (int)$row['is_active'] === 1 ? 0 : 1;
                    $update = $pdo->prepare('UPDATE suggestion_categories SET is_active = :is_active WHERE id = :id');
                    $update->execute([':is_active' => $newStatus, ':id' => $id]);

                    $flash = $newStatus === 1 ? 'Category activated.' : 'Category deactivated.';
                } catch (RuntimeException $exception) {
                    $error = $exception->getMessage();
                } catch (PDOException $exception) {
                    $error = 'Unable to update category right now.';
                }
            }
        }
    }
}

$listStmt = $pdo->prepare(
    'SELECT id, name, is_active, created_at
     FROM suggestion_categories
     WHERE LOWER(TRIM(office)) = LOWER(TRIM(:office))
     ORDER BY name ASC'
);
$listStmt->execute([':office' => $office]);
$areas = $listStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Categories I Handle - VOICE</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
body { background: #f4f6fb; color: #1f2937; }
.main { margin-left: 260px; margin-top: 61px; padding: 25px; min-height: calc(100vh - 61px); }
.panel { max-width: 1100px; margin: auto; background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 20px; }
.panel-header { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; margin-bottom: 16px; flex-wrap: wrap; }
.panel h1 { margin: 0; font-size: 24px; font-weight: 600; color: #333; }
.muted { color: #6b7280; font-size: 13px; margin: 4px 0; }
.btn-add { display: inline-flex; align-items: center; gap: 6px; padding: 10px 18px; border: none; border-radius: 8px; font-size: 13px; font-weight: 600; background: #6d28d9; color: #fff; cursor: pointer; }
.btn-add:hover { background: #5b21b6; }
.notice { padding: 10px 12px; border-radius: 8px; margin-bottom: 14px; font-size: 13px; }
.success { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
.error { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
table { width: 100%; border-collapse: collapse; }
th { font-size: 11px; color: #6b7280; text-align: left; text-transform: uppercase; padding: 10px; border-bottom: 2px solid #f0f1f3; }
td { padding: 12px 10px; border-bottom: 1px solid #f5f5f5; font-size: 13px; vertical-align: middle; }
tbody tr:hover { background: #fafafa; }
.status { display: inline-block; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
.status-active { background: #d1fae5; color: #059669; }
.status-inactive { background: #fee2e2; color: #b91c1c; }
.desc-cell { color: #6b7280; }
.empty { text-align: center; color: #9ca3af; padding: 24px; }
.btn-icon { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 6px; color: #fff; border: none; font-size: 16px; cursor: pointer; }
.btn-toggle { background: #f59e0b; }
.btn-toggle:hover { background: #d97706; }

/* Modal */
.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: none; align-items: center; justify-content: center; z-index: 100; }
.modal-overlay.show { display: flex; }
.modal-content { background: #fff; width: 450px; max-width: 92vw; border-radius: 12px; padding: 25px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
.modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.modal-header h3 { font-size: 18px; color: #333; }
.close-btn { font-size: 24px; color: #888; cursor: pointer; background: none; border: none; }
.close-btn:hover { color: #ef4444; }
.form-group { margin-bottom: 15px; }
.form-group label { display: block; font-size: 13px; color: #555; margin-bottom: 5px; font-weight: 500; }
.form-group input, .form-group textarea { width: 100%; padding: 10px 15px; border: 1px solid #ddd; border-radius: 8px; font-size: 13px; font-family: 'Poppins', sans-serif; outline: none; }
.form-group input:focus, .form-group textarea:focus { border-color: #6d28d9; }
.form-group textarea { min-height: 90px; resize: vertical; }
.modal-footer { display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px; }
.btn-cancel { padding: 10px 20px; border: none; border-radius: 8px; font-size: 13px; cursor: pointer; background: #f4f6fb; color: #555; }
.btn-cancel:hover { background: #e5e7eb; }
.btn-submit { padding: 10px 20px; border: none; border-radius: 8px; font-size: 13px; cursor: pointer; background: #6d28d9; color: #fff; font-weight: 500; }
.btn-submit:hover { background: #5b21b6; }

@media (max-width: 1024px) { .main { margin-left: 0; padding: 16px; } }
@media (max-width: 700px) { .panel { overflow-x: auto; } table { min-width: 640px; } }
</style>
</head>
<body>
<?php include __DIR__ . '/staff_topbar.php'; ?>
<?php include __DIR__ . '/staff_sidebar.php'; ?>
<main class="main">
<section class="panel">
    <div class="panel-header">
        <div>
            <h1>Categories I Handle</h1>
            <p class="muted">Specific suggestion categories that route straight to your office: <strong><?= e($office) ?></strong></p>
        </div>
        <button type="button" class="btn-add" onclick="openAreaModal()"><i class="bx bx-plus"></i> Add Category</button>
    </div>

    <?php if ($flash !== ''): ?><div class="notice success"><?= e($flash) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="notice error"><?= e($error) ?></div><?php endif; ?>

    <table>
        <thead><tr><th>Category Name</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if (!$areas): ?>
            <tr><td colspan="3" class="empty">No categories have been added for your office yet.</td></tr>
        <?php else: foreach ($areas as $area): ?>
            <tr>
                <td><strong><?= e((string)$area['name']) ?></strong></td>
                <td><span class="status <?= (int)$area['is_active'] === 1 ? 'status-active' : 'status-inactive' ?>"><?= (int)$area['is_active'] === 1 ? 'Active' : 'Inactive' ?></span></td>
                <td>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Are you sure you want to <?= (int)$area['is_active'] === 1 ? 'deactivate' : 'activate' ?> this category?');">
                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['staff_csrf']) ?>">
                        <input type="hidden" name="action" value="toggle_area">
                        <input type="hidden" name="id" value="<?= (int)$area['id'] ?>">
                        <button type="submit" class="btn-icon btn-toggle" title="<?= (int)$area['is_active'] === 1 ? 'Deactivate' : 'Activate' ?> category">
                            <i class="bx <?= (int)$area['is_active'] === 1 ? 'bx-power-off' : 'bx-check' ?>"></i>
                        </button>
                    </form>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</section>
</main>

<div class="modal-overlay" id="addAreaModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Add Category</h3>
            <button type="button" class="close-btn" onclick="closeAreaModal()">&times;</button>
        </div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['staff_csrf']) ?>">
            <input type="hidden" name="action" value="add_area">
            <div class="form-group">
                <label for="areaName">Category Name</label>
                <input id="areaName" name="name" placeholder="e.g. Book Availability" required>
            </div>
            <div class="form-group">
                <label>Office</label>
                <input value="<?= e($office) ?>" disabled title="Categories you add always route to your own office.">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeAreaModal()">Cancel</button>
                <button type="submit" class="btn-submit">Save Category</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAreaModal() { document.getElementById('addAreaModal').classList.add('show'); }
function closeAreaModal() { document.getElementById('addAreaModal').classList.remove('show'); }
window.addEventListener('click', function (event) {
    var modal = document.getElementById('addAreaModal');
    if (event.target === modal) closeAreaModal();
});
<?php if ($error !== '' && isset($_POST['action']) && $_POST['action'] === 'add_area'): ?>
document.addEventListener('DOMContentLoaded', openAreaModal);
<?php endif; ?>
</script>
</body>
</html>
