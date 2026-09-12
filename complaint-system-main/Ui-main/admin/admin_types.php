<?php
session_start();
require_once __DIR__ . '/../db_connection.php';

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$embedded = (string)($_GET['embedded'] ?? $_POST['embedded'] ?? '') === '1';
if ($userId <= 0) {
    header('Location: /complaint-system-main/admin/login.php');
    exit;
}

if ((string)($_SESSION['role'] ?? '') !== 'admin') {
    $roleStmt = $pdo->prepare('SELECT role FROM users WHERE id = :id LIMIT 1');
    $roleStmt->execute([':id' => $userId]);
    if ((string)$roleStmt->fetchColumn() !== 'admin') {
        header('Location: /complaint-system-main/admin/login.php');
        exit;
    }
    $_SESSION['role'] = 'admin';
}

foreach (['complaint_categories', 'suggestion_categories'] as $table) {
    $pdo->exec("ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS description TEXT NULL AFTER category_type");
    $pdo->exec("ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP AFTER is_active");
    $pdo->exec("ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
}

function redirect_types(string $status, string $message): never
{
    $params = ['status' => $status, 'msg' => $message];
    if ((string)($_GET['embedded'] ?? $_POST['embedded'] ?? '') === '1') {
        $params['embedded'] = '1';
    }
    header('Location: admin_types.php?' . http_build_query($params));
    exit;
}

function category_table(string $type): string
{
    return $type === 'suggestion' ? 'suggestion_categories' : 'complaint_categories';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string)$_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
        redirect_types('error', 'Your session expired. Please try again.');
    }

    $action = (string)($_POST['action'] ?? '');
    $type = (string)($_POST['type'] ?? '');
    $table = category_table($type);
    $isKnownType = in_array($type, ['complaint', 'suggestion'], true);

    try {
        if (!$isKnownType) {
            redirect_types('error', 'Please choose a valid type.');
        }

        if ($action === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $scope = (string)($_POST['scope'] ?? '');
            $status = (string)($_POST['status'] ?? 'active');
            if ($name === '') {
                redirect_types('error', 'Type name is required.');
            }
            if (!in_array($scope, ['general', 'college'], true)) {
                redirect_types('error', 'Please choose a valid scope.');
            }
            if (!in_array($status, ['active', 'inactive'], true)) {
                $status = 'active';
            }

            $duplicate = $pdo->prepare("SELECT id FROM {$table} WHERE LOWER(name) = LOWER(:name) AND id <> :id LIMIT 1");
            $duplicate->execute([':name' => $name, ':id' => $id]);
            if ($duplicate->fetchColumn()) {
                redirect_types('error', 'That type name already exists for this submission type.');
            }

            if ($id > 0) {
                if ($type === 'suggestion') {
                    $stmt = $pdo->prepare("UPDATE {$table} SET name = :name, route = :route_scope, category_type = :category_scope, office = :office, is_active = :is_active WHERE id = :id");
                    $stmt->execute([
                        ':name' => $name,
                        ':route_scope' => $scope,
                        ':category_scope' => $scope,
                        ':office' => trim((string)($_POST['office'] ?? '')) ?: null,
                        ':is_active' => $status === 'active' ? 1 : 0,
                        ':id' => $id,
                    ]);
                } else {
                    $stmt = $pdo->prepare("UPDATE {$table} SET name = :name, route = :route_scope, category_type = :category_scope, is_active = :is_active WHERE id = :id");
                    $stmt->execute([
                        ':name' => $name,
                        ':route_scope' => $scope,
                        ':category_scope' => $scope,
                        ':is_active' => $status === 'active' ? 1 : 0,
                        ':id' => $id,
                    ]);
                }
                redirect_types('success', 'Type updated successfully.');
            }

            if ($type === 'suggestion') {
                $stmt = $pdo->prepare("INSERT INTO {$table} (name, route, category_type, office, is_active) VALUES (:name, :route_scope, :category_scope, :office, :is_active)");
                $stmt->execute([
                    ':name' => $name,
                    ':route_scope' => $scope,
                    ':category_scope' => $scope,
                    ':office' => trim((string)($_POST['office'] ?? '')) ?: null,
                    ':is_active' => $status === 'active' ? 1 : 0,
                ]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO {$table} (name, route, category_type, is_active) VALUES (:name, :route_scope, :category_scope, :is_active)");
                $stmt->execute([
                    ':name' => $name,
                    ':route_scope' => $scope,
                    ':category_scope' => $scope,
                    ':is_active' => $status === 'active' ? 1 : 0,
                ]);
            }
            redirect_types('success', 'Type added successfully.');
        }

        if ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("UPDATE {$table} SET is_active = IF(is_active = 1, 0, 1) WHERE id = :id");
            $stmt->execute([':id' => $id]);
            redirect_types('success', 'Type status updated successfully.');
        }
    } catch (PDOException $exception) {
        redirect_types('error', $exception->getCode() === '23000' ? 'That type name already exists.' : 'Unable to save the type.');
    }
}

$search = trim((string)($_GET['search'] ?? ''));
$typeFilter = (string)($_GET['type'] ?? 'all');
$statusFilter = (string)($_GET['status_filter'] ?? 'all');
$where = [];
$params = [];
if ($search !== '') {
    $where[] = 'name LIKE :search';
    $params[':search'] = '%' . $search . '%';
}
if (in_array($statusFilter, ['active', 'inactive'], true)) {
    $where[] = 'is_active = :status_filter';
    $params[':status_filter'] = $statusFilter === 'active' ? 1 : 0;
}

$loadTypes = static function (PDO $pdo, string $table, array $where, array $params): array {
    $columns = $table === 'suggestion_categories' ? 'id, name, category_type, office, is_active' : 'id, name, category_type, is_active';
    $sql = "SELECT {$columns} FROM {$table}";
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY name ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
};

$complaintTypes = $typeFilter === 'suggestion' ? [] : $loadTypes($pdo, 'complaint_categories', $where, $params);
$suggestionTypes = $typeFilter === 'complaint' ? [] : $loadTypes($pdo, 'suggestion_categories', $where, $params);
$editType = null;
$editTypeKind = '';
$editId = (int)($_GET['edit'] ?? 0);
if ($editId > 0) {
    foreach ([['complaint_categories', $complaintTypes, 'complaint'], ['suggestion_categories', $suggestionTypes, 'suggestion']] as [$table, $items, $kind]) {
        foreach ($items as $item) {
            if ((int)$item['id'] === $editId) {
                $editType = $item;
                $editTypeKind = $kind;
                break 2;
            }
        }
    }
}
$status = (string)($_GET['status'] ?? '');
$message = (string)($_GET['msg'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>VOICE - Complaint &amp; Suggestion Types</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
<style>
* { box-sizing:border-box; font-family:'Poppins',sans-serif; }
body { margin:0; background:#f8fafc; color:#1f2937; }
.main { margin-left:260px; margin-top:61px; padding:24px 28px; min-height:calc(100vh - 61px); }
<?php if ($embedded): ?>
.topbar, .sidebar, .mobile-sidebar-overlay { display:none !important; }
.main { margin:0; padding:20px; min-height:0; }
<?php endif; ?>
.page-header { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; margin-bottom:18px; }
.page-header h2 { margin:0 0 5px; font-size:24px; }
.page-header p { margin:0; color:#6b7280; font-size:13px; }
button, .button { border:0; border-radius:7px; padding:9px 13px; background:#6d28d9; color:#fff; font:600 12px 'Poppins',sans-serif; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:6px; }
.button.secondary, button.secondary { background:#f3f4f6; color:#4b5563; }
.card { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:15px 17px; margin-bottom:14px; box-shadow:0 2px 10px rgba(0,0,0,.02); }
.filters { display:grid; grid-template-columns:minmax(220px,1fr) 150px 150px auto; gap:9px; align-items:end; margin-bottom:14px; }
label { display:block; margin-bottom:5px; font-size:11px; font-weight:600; color:#6b7280; }
input, select, textarea { width:100%; border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font:inherit; font-size:12px; color:#1f2937; background:#fff; }
textarea { resize:vertical; min-height:82px; }
.filter-actions { display:flex; gap:7px; }
.section-head { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:10px; }
.section-head h3 { margin:0; font-size:15px; }
.section-head span { color:#9ca3af; font-size:11px; }
.table-wrap { overflow-x:auto; }
table { width:100%; border-collapse:collapse; table-layout:fixed; min-width:700px; }
th:nth-child(1), td:nth-child(1) { width:26%; }
th:nth-child(2), td:nth-child(2) { width:23%; }
th:nth-child(3), td:nth-child(3) { width:23%; }
th:nth-child(4), td:nth-child(4) { width:28%; }
th { padding:9px 10px; text-align:left; background:#fafafa; border-bottom:1px solid #eef0f3; color:#9ca3af; font-size:10px; text-transform:uppercase; letter-spacing:.03em; }
td { padding:9px 10px; border-bottom:1px solid #f1f2f4; font-size:12px; vertical-align:middle; }
tbody tr:last-child td { border-bottom:0; }
.scope { color:#6d28d9; font-weight:600; }
.status { display:inline-flex; padding:4px 8px; border-radius:999px; background:#dcfce7; color:#047857; font-size:10px; font-weight:600; }
.status.off { background:#f3f4f6; color:#9ca3af; }
.actions { display:flex; gap:5px; }
.icon-btn { width:29px; height:29px; padding:0; justify-content:center; background:#eef2ff; color:#4338ca; }
.icon-btn.warning { background:#fff7ed; color:#c2410c; }
.empty { padding:18px 10px; text-align:center; color:#9ca3af; }
.alert { padding:10px 13px; border-radius:7px; margin-bottom:14px; font-size:12px; }
.alert.success { background:#ecfdf5; color:#047857; }
.alert.error { background:#fef2f2; color:#b91c1c; }
.modal { display:none; position:fixed; inset:0; z-index:1100; background:rgba(17,24,39,.42); padding:24px 16px; overflow:auto; }
.modal.open { display:flex; align-items:flex-start; justify-content:center; }
.modal-card { width:min(520px,100%); margin:20px auto; background:#fff; border-radius:10px; padding:20px; box-shadow:0 16px 40px rgba(17,24,39,.2); }
.modal-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:13px; }
.modal-head h3 { margin:0; font-size:16px; }
.close-modal { background:#f3f4f6; color:#6b7280; padding:6px 10px; }
.form-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
.form-group.full { grid-column:1 / -1; }
.form-actions { display:flex; justify-content:flex-end; gap:8px; margin-top:15px; }
@media (max-width:1024px) { .main { margin-left:0; padding:18px; } }
@media (max-width:700px) { .page-header { flex-direction:column; } .filters { grid-template-columns:1fr 1fr; } .filter-actions { grid-column:1 / -1; } .form-grid { grid-template-columns:1fr; } .form-group.full { grid-column:auto; } }
</style>
</head>
<body>
<?php if (!$embedded): ?>
<?php include 'admin_topbar.php'; ?>
<?php include 'admin_sidebar.php'; ?>
<?php endif; ?>
<main class="main">
    <div class="page-header">
        <div><h2>Complaint &amp; Suggestion Types</h2><p>Manage the types available when students submit complaints or suggestions.</p></div>
        <button type="button" data-open-modal="typeModal"><i class="bx bx-plus"></i> Add Type</button>
    </div>

    <?php if ($message !== '' && in_array($status, ['success', 'error'], true)): ?>
        <div class="alert <?php echo e($status); ?>"><?php echo e($message); ?></div>
    <?php endif; ?>

    <form class="card filters" method="get">
        <?php if ($embedded): ?><input type="hidden" name="embedded" value="1"><?php endif; ?>
        <div><label for="searchTypes">Search Types</label><input id="searchTypes" name="search" value="<?php echo e($search); ?>" placeholder="Search by name"></div>
        <div><label for="typeFilter">Type</label><select id="typeFilter" name="type"><option value="all" <?php echo $typeFilter === 'all' ? 'selected' : ''; ?>>All</option><option value="complaint" <?php echo $typeFilter === 'complaint' ? 'selected' : ''; ?>>Complaint</option><option value="suggestion" <?php echo $typeFilter === 'suggestion' ? 'selected' : ''; ?>>Suggestion</option></select></div>
        <div><label for="statusFilter">Status</label><select id="statusFilter" name="status_filter"><option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All</option><option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option><option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option></select></div>
        <div class="filter-actions"><button type="submit"><i class="bx bx-filter-alt"></i> Filter</button><a class="button secondary" href="admin_types.php">Clear</a></div>
    </form>

    <?php foreach ([['Complaint Types', $complaintTypes, 'complaint'], ['Suggestion Types', $suggestionTypes, 'suggestion']] as [$title, $items, $kind]): ?>
    <section class="card">
        <div class="section-head"><h3><?php echo $title; ?></h3><span><?php echo count($items); ?> type(s)</span></div>
        <div class="table-wrap"><table>
            <thead><tr><th>Type Name</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if (!$items): ?><tr><td colspan="3" class="empty">No <?php echo strtolower($kind); ?> types match the current filters.</td></tr><?php endif; ?>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td><strong><?php echo e((string)$item['name']); ?></strong><?php if ($kind === 'suggestion' && !empty($item['office'])): ?><br><small class="scope"><?php echo e((string)$item['office']); ?></small><?php endif; ?></td>
                    <td><span class="status <?php echo (int)$item['is_active'] === 1 ? '' : 'off'; ?>"><?php echo (int)$item['is_active'] === 1 ? 'Active' : 'Inactive'; ?></span></td>
                    <td><div class="actions"><a class="button icon-btn" href="admin_types.php?edit=<?php echo (int)$item['id']; ?>&type=<?php echo e($kind); ?><?php echo $embedded ? '&embedded=1' : ''; ?>" title="Edit type"><i class="bx bx-edit"></i></a><form method="post" onsubmit="return confirm('Are you sure you want to <?php echo (int)$item['is_active'] === 1 ? 'deactivate' : 'activate'; ?> this type?');"><input type="hidden" name="csrf_token" value="<?php echo e((string)$_SESSION['csrf_token']); ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="type" value="<?php echo e($kind); ?>"><input type="hidden" name="id" value="<?php echo (int)$item['id']; ?>"><?php if ($embedded): ?><input type="hidden" name="embedded" value="1"><?php endif; ?><button class="icon-btn warning" type="submit" title="<?php echo (int)$item['is_active'] === 1 ? 'Deactivate' : 'Activate'; ?> type"><i class="bx <?php echo (int)$item['is_active'] === 1 ? 'bx-power-off' : 'bx-check'; ?>"></i></button></form></div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>
    <?php endforeach; ?>
</main>

<div class="modal <?php echo $editType ? 'open' : ''; ?>" id="typeModal" role="dialog" aria-modal="true" aria-labelledby="typeModalTitle">
    <div class="modal-card">
        <div class="modal-head"><h3 id="typeModalTitle"><?php echo $editType ? 'Edit Type' : 'Add Type'; ?></h3><button class="close-modal" type="button" data-close-modal>&times;</button></div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo e((string)$_SESSION['csrf_token']); ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="type" id="modalType" value="<?php echo e($editTypeKind); ?>">
            <?php if ($embedded): ?><input type="hidden" name="embedded" value="1"><?php endif; ?>
            <?php if ($editType): ?><input type="hidden" name="id" value="<?php echo (int)$editType['id']; ?>"><?php endif; ?>
            <div class="form-grid">
                <div class="form-group"><label for="typeName">Type Name *</label><input id="typeName" name="name" maxlength="100" required value="<?php echo e((string)($editType['name'] ?? '')); ?>"></div>
                <?php if (!$editType): ?><div class="form-group"><label for="typeKind">Applies To *</label><select id="typeKind" required><option value="">Choose type...</option><option value="complaint">Complaint</option><option value="suggestion">Suggestion</option></select></div><?php else: ?><div class="form-group"><label>Applies To</label><input value="<?php echo e(ucfirst($editTypeKind)); ?>" disabled></div><?php endif; ?>
                <div class="form-group"><label for="typeScope">Scope *</label><select id="typeScope" name="scope" required><option value="general" <?php echo (($editType['category_type'] ?? '') === 'general') ? 'selected' : ''; ?>>General</option><option value="college" <?php echo (($editType['category_type'] ?? '') === 'college') ? 'selected' : ''; ?>>College</option></select></div>
                <div class="form-group" id="officeField"><label for="typeOffice">Assigned Office</label><input id="typeOffice" name="office" maxlength="100" value="<?php echo e((string)($editType['office'] ?? '')); ?>" placeholder="e.g. ICT, Library"></div>
                <div class="form-group"><label for="typeStatus">Status</label><select id="typeStatus" name="status"><option value="active" <?php echo !$editType || (int)$editType['is_active'] === 1 ? 'selected' : ''; ?>>Active</option><option value="inactive" <?php echo $editType && (int)$editType['is_active'] === 0 ? 'selected' : ''; ?>>Inactive</option></select></div>
            </div>
            <div class="form-actions"><button type="button" class="secondary" data-close-modal>Cancel</button><button type="submit">Save Type</button></div>
        </form>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('typeModal');
    const typeKind = document.getElementById('typeKind');
    const modalType = document.getElementById('modalType');
    document.querySelectorAll('[data-open-modal]').forEach(function (button) {
        button.addEventListener('click', function () { modal.classList.add('open'); });
    });
    document.querySelectorAll('[data-close-modal]').forEach(function (button) {
        button.addEventListener('click', function () { modal.classList.remove('open'); });
    });
    if (typeKind) {
        typeKind.addEventListener('change', function () { modalType.value = typeKind.value; });
    }
    modal.addEventListener('click', function (event) {
        if (event.target === modal) modal.classList.remove('open');
    });
});
</script>
</body>
</html>
