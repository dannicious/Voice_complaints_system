<?php
session_start();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../suggestion_flow.php';

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
ensure_suggestion_area_schema($pdo);

// Downloadable CSV template for the bulk upload below. Sent before any page
// output, so it must stay above the HTML further down.
if ((string)($_GET['download'] ?? '') === 'categories_template') {
    $headers = ['type', 'area', 'name', 'route_type', 'office', 'status'];
    $rows = [
        ['suggestion', 'Technology & Internet', 'Printer / Scanner Issues', 'office', 'ICT', 'active'],
        ['suggestion', 'Academic Programs & College', 'Thesis / Capstone Concerns', 'dean', '', 'active'],
        ['suggestion', 'Student Activities & Services', 'Sports Fest Feedback', 'admin', '', 'active'],
        ['complaint', '', 'Academic Concerns', '', '', 'active'],
    ];
    $handle = fopen('php://temp', 'r+');
    fputcsv($handle, $headers);
    foreach ($rows as $row) {
        fputcsv($handle, $row);
    }
    rewind($handle);
    $csv = (string)stream_get_contents($handle);
    fclose($handle);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="categories_bulk_upload_template.csv"');
    header('Content-Length: ' . strlen($csv));
    echo $csv;
    exit;
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

/**
 * Renders one row of the flat Suggestion Categories table: Area and
 * "Routes to" are compact dropdowns that auto-submit on change (no card, no
 * modal, one column each) - the same one-click reassignment as before, just
 * laid out as a normal table row so many categories/Areas stay scannable in
 * a single column instead of a wide grid of cards.
 */
function render_suggestion_category_row(array $item, array $allAreas, array $knownOffices, bool $embedded): string
{
    $csrfToken = (string)($_SESSION['csrf_token'] ?? '');
    $embeddedField = $embedded ? '<input type="hidden" name="embedded" value="1">' : '';
    $itemId = (int)$item['id'];
    $itemName = (string)$item['name'];
    $itemAreaId = (int)($item['area_id'] ?? 0);
    $itemOffice = trim((string)($item['office'] ?? ''));
    $itemRouteType = (string)($item['route_type'] ?? 'admin');
    if (!in_array($itemRouteType, ['office', 'dean', 'admin'], true)) {
        $itemRouteType = 'admin';
    }
    $isActive = (int)$item['is_active'] === 1;
    $currentRouteValue = $itemRouteType === 'office' ? ('office:' . $itemOffice) : $itemRouteType;
    $officeKnown = $itemOffice !== '' && in_array($itemOffice, $knownOffices, true);

    ob_start();
    ?>
    <tr>
        <td>
            <form method="post" class="row-inline-form">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                <input type="hidden" name="action" value="reassign_area">
                <input type="hidden" name="type" value="suggestion">
                <input type="hidden" name="id" value="<?php echo $itemId; ?>">
                <?php echo $embeddedField; ?>
                <select class="row-select" onchange="this.form.submit()" title="Move to another Area">
                    <?php foreach ($allAreas as $otherArea): ?>
                        <option value="<?php echo (int)$otherArea['id']; ?>" <?php echo (int)$otherArea['id'] === $itemAreaId ? 'selected' : ''; ?>><?php echo e((string)$otherArea['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </td>
        <td>
            <form method="post" class="row-inline-form">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                <input type="hidden" name="action" value="rename">
                <input type="hidden" name="type" value="suggestion">
                <input type="hidden" name="id" value="<?php echo $itemId; ?>">
                <input type="hidden" name="name" class="cat-rename-name" value="<?php echo e($itemName); ?>">
                <?php echo $embeddedField; ?>
                <button type="button" class="cat-name<?php echo $isActive ? '' : ' inactive'; ?>" data-current-name="<?php echo e($itemName); ?>" title="Click to rename"><?php echo e($itemName); ?></button>
            </form>
        </td>
        <td>
            <form method="post" class="row-inline-form">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                <input type="hidden" name="action" value="reassign_route">
                <input type="hidden" name="type" value="suggestion">
                <input type="hidden" name="id" value="<?php echo $itemId; ?>">
                <?php echo $embeddedField; ?>
                <select name="route_value" class="row-select" onchange="this.form.submit()" title="Change routing destination">
                    <option value="admin" <?php echo $itemRouteType === 'admin' ? 'selected' : ''; ?>>Admin (SAS Director)</option>
                    <option value="dean" <?php echo $itemRouteType === 'dean' ? 'selected' : ''; ?>>Dean (student's college)</option>
                    <?php if ($knownOffices !== []): ?>
                    <optgroup label="Office">
                        <?php foreach ($knownOffices as $officeLabel): ?>
                            <option value="office:<?php echo e($officeLabel); ?>" <?php echo ($itemRouteType === 'office' && strcasecmp($itemOffice, $officeLabel) === 0) ? 'selected' : ''; ?>><?php echo e($officeLabel); ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                    <?php endif; ?>
                    <?php if ($itemRouteType === 'office' && $itemOffice !== '' && !$officeKnown): ?>
                        <option value="<?php echo e($currentRouteValue); ?>" selected><?php echo e($itemOffice); ?> (no active staff)</option>
                    <?php endif; ?>
                </select>
            </form>
        </td>
        <td><span class="status <?php echo $isActive ? '' : 'off'; ?>"><?php echo $isActive ? 'Active' : 'Inactive'; ?></span></td>
        <td>
            <div class="actions">
                <form method="post" class="row-inline-form">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="type" value="suggestion">
                    <input type="hidden" name="id" value="<?php echo $itemId; ?>">
                    <?php echo $embeddedField; ?>
                    <button type="submit" class="icon-btn warning" title="<?php echo $isActive ? 'Deactivate' : 'Activate'; ?> category"><i class="bx <?php echo $isActive ? 'bx-power-off' : 'bx-check'; ?>"></i></button>
                </form>
                <form method="post" class="row-inline-form">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="action" value="delete_category">
                    <input type="hidden" name="type" value="suggestion">
                    <input type="hidden" name="id" value="<?php echo $itemId; ?>">
                    <?php echo $embeddedField; ?>
                    <button type="submit" class="icon-btn danger" onclick="return confirm('Delete &quot;<?php echo e(addslashes($itemName)); ?>&quot;? Any suggestion that used it will show as Uncategorized. This cannot be undone.');" title="Delete category"><i class="bx bx-trash"></i></button>
                </form>
            </div>
        </td>
    </tr>
    <?php
    return (string)ob_get_clean();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string)$_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
        redirect_types('error', 'Your session expired. Please try again.');
    }

    $action = (string)($_POST['action'] ?? '');

    // Handled separately from the single-category actions below: a bulk
    // upload's rows can be a mix of complaint and suggestion categories, so
    // there is no single $type/$table for the whole request.
    if ($action === 'bulk_upload') {
        try {
            if (!isset($_FILES['categories_csv']) || (int)$_FILES['categories_csv']['error'] !== UPLOAD_ERR_OK) {
                redirect_types('error', 'Please choose a valid CSV file.');
            }

            $handle = fopen((string)$_FILES['categories_csv']['tmp_name'], 'rb');
            if ($handle === false) {
                redirect_types('error', 'Unable to read the CSV file.');
            }

            $header = fgetcsv($handle);
            $header = array_map(static function ($value): string {
                $value = trim((string)$value);
                $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
                return strtolower(trim($value));
            }, $header ?: []);
            $requiredColumns = ['type', 'name'];
            if (array_diff($requiredColumns, $header) !== []) {
                fclose($handle);
                redirect_types('error', 'CSV must contain at least type and name columns (office and status are optional).');
            }

            $columnIndex = array_flip($header);
            $findStmt = [
                'complaint' => $pdo->prepare('SELECT id FROM complaint_categories WHERE LOWER(name) = LOWER(:name) LIMIT 1'),
                'suggestion' => $pdo->prepare('SELECT id FROM suggestion_categories WHERE LOWER(name) = LOWER(:name) LIMIT 1'),
            ];
            $updateComplaintStmt = $pdo->prepare('UPDATE complaint_categories SET is_active = :is_active WHERE id = :id');
            $insertComplaintStmt = $pdo->prepare('INSERT INTO complaint_categories (name, is_active) VALUES (:name, :is_active)');
            $updateSuggestionStmt = $pdo->prepare('UPDATE suggestion_categories SET area_id = :area_id, route_type = :route_type, office = :office, is_active = :is_active WHERE id = :id');
            $insertSuggestionStmt = $pdo->prepare('INSERT INTO suggestion_categories (name, area_id, route_type, office, is_active) VALUES (:name, :area_id, :route_type, :office, :is_active)');
            $findAreaStmt = $pdo->prepare('SELECT id FROM suggestion_areas WHERE LOWER(name) = LOWER(:name) LIMIT 1');
            $insertAreaStmt = $pdo->prepare('INSERT INTO suggestion_areas (name, display_order) VALUES (:name, (SELECT next_order FROM (SELECT COALESCE(MAX(display_order), 0) + 10 AS next_order FROM suggestion_areas) t))');
            $otherAreaId = 0; // resolved lazily below, only if a row actually needs it

            $created = 0;
            $updated = 0;
            $skipped = 0;

            $pdo->beginTransaction();
            while (($row = fgetcsv($handle)) !== false) {
                $rowType = strtolower(trim((string)($row[$columnIndex['type']] ?? '')));
                $name = trim((string)($row[$columnIndex['name']] ?? ''));
                $office = isset($columnIndex['office']) ? trim((string)($row[$columnIndex['office']] ?? '')) : '';
                $rowStatus = isset($columnIndex['status']) ? strtolower(trim((string)($row[$columnIndex['status']] ?? ''))) : 'active';
                $isActive = $rowStatus !== 'inactive' ? 1 : 0;

                if (!in_array($rowType, ['complaint', 'suggestion'], true) || $name === '') {
                    $skipped++;
                    continue;
                }

                $findStmt[$rowType]->execute([':name' => $name]);
                $existingId = (int)$findStmt[$rowType]->fetchColumn();

                if ($rowType === 'suggestion') {
                    $routeType = isset($columnIndex['route_type']) ? strtolower(trim((string)($row[$columnIndex['route_type']] ?? ''))) : '';
                    if (!in_array($routeType, ['office', 'dean', 'admin'], true)) {
                        $routeType = $office !== '' ? 'office' : 'admin';
                    }
                    if ($routeType === 'office' && $office === '') {
                        $routeType = 'admin';
                    }

                    $areaName = isset($columnIndex['area']) ? trim((string)($row[$columnIndex['area']] ?? '')) : '';
                    if ($areaName === '' && $otherAreaId <= 0) {
                        $otherAreaId = ensure_other_suggestion_area($pdo);
                    }
                    $areaId = $otherAreaId;
                    if ($areaName !== '') {
                        $findAreaStmt->execute([':name' => $areaName]);
                        $existingAreaId = (int)$findAreaStmt->fetchColumn();
                        if ($existingAreaId > 0) {
                            $areaId = $existingAreaId;
                        } else {
                            $insertAreaStmt->execute([':name' => $areaName]);
                            $areaId = (int)$pdo->lastInsertId();
                        }
                    }

                    $params = [
                        ':area_id' => $areaId > 0 ? $areaId : null,
                        ':route_type' => $routeType,
                        ':office' => $routeType === 'office' ? $office : null,
                        ':is_active' => $isActive,
                    ];
                    if ($existingId > 0) {
                        $updateSuggestionStmt->execute($params + [':id' => $existingId]);
                        $updated++;
                    } else {
                        $insertSuggestionStmt->execute($params + [':name' => $name]);
                        $created++;
                    }
                } else {
                    if ($existingId > 0) {
                        $updateComplaintStmt->execute([':is_active' => $isActive, ':id' => $existingId]);
                        $updated++;
                    } else {
                        $insertComplaintStmt->execute([':name' => $name, ':is_active' => $isActive]);
                        $created++;
                    }
                }
            }
            fclose($handle);
            $pdo->commit();
            redirect_types('success', "Bulk upload done - {$created} added, {$updated} updated, {$skipped} skipped.");
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            redirect_types('error', 'Unable to process the CSV file.');
        }
    }

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
            $status = (string)($_POST['status'] ?? 'active');
            if ($name === '') {
                redirect_types('error', 'Type name is required.');
            }
            if (!in_array($status, ['active', 'inactive'], true)) {
                $status = 'active';
            }

            $duplicate = $pdo->prepare("SELECT id FROM {$table} WHERE LOWER(name) = LOWER(:name) AND id <> :id LIMIT 1");
            $duplicate->execute([':name' => $name, ':id' => $id]);
            if ($duplicate->fetchColumn()) {
                redirect_types('error', 'That type name already exists for this submission type.');
            }

            // Only reached for a brand-new suggestion category submitted
            // outside the normal per-Area add box (that box always sets
            // area_id/route_type itself) - default to "Other" + Admin so
            // nothing is left unroutable.
            $areaId = (int)($_POST['area_id'] ?? 0);
            if ($type === 'suggestion' && $areaId <= 0) {
                $areaId = ensure_other_suggestion_area($pdo);
            }
            $routeType = (string)($_POST['route_type'] ?? 'admin');
            if (!in_array($routeType, ['office', 'dean', 'admin'], true)) {
                $routeType = 'admin';
            }
            $office = trim((string)($_POST['office'] ?? '')) ?: null;
            if ($routeType !== 'office') {
                $office = null;
            } elseif ($office === null) {
                $routeType = 'admin';
            }

            if ($id > 0) {
                if ($type === 'suggestion') {
                    $stmt = $pdo->prepare("UPDATE {$table} SET name = :name, area_id = :area_id, route_type = :route_type, office = :office, is_active = :is_active WHERE id = :id");
                    $stmt->execute([
                        ':name' => $name,
                        ':area_id' => $areaId > 0 ? $areaId : null,
                        ':route_type' => $routeType,
                        ':office' => $office,
                        ':is_active' => $status === 'active' ? 1 : 0,
                        ':id' => $id,
                    ]);
                } else {
                    $stmt = $pdo->prepare("UPDATE {$table} SET name = :name, is_active = :is_active WHERE id = :id");
                    $stmt->execute([
                        ':name' => $name,
                        ':is_active' => $status === 'active' ? 1 : 0,
                        ':id' => $id,
                    ]);
                }
                redirect_types('success', 'Type updated successfully.');
            }

            if ($type === 'suggestion') {
                $stmt = $pdo->prepare("INSERT INTO {$table} (name, area_id, route_type, office, is_active) VALUES (:name, :area_id, :route_type, :office, :is_active)");
                $stmt->execute([
                    ':name' => $name,
                    ':area_id' => $areaId > 0 ? $areaId : null,
                    ':route_type' => $routeType,
                    ':office' => $office,
                    ':is_active' => $status === 'active' ? 1 : 0,
                ]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO {$table} (name, is_active) VALUES (:name, :is_active)");
                $stmt->execute([
                    ':name' => $name,
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

        // Quick move: drop a category into a different Area card, no modal.
        if ($action === 'reassign_area') {
            $id = (int)($_POST['id'] ?? 0);
            $areaId = (int)($_POST['area_id'] ?? 0);
            if ($id <= 0 || $areaId <= 0) {
                redirect_types('error', 'Invalid request.');
            }
            $stmt = $pdo->prepare('UPDATE suggestion_categories SET area_id = :area_id WHERE id = :id');
            $stmt->execute([':area_id' => $areaId, ':id' => $id]);
            redirect_types('success', 'Category moved.');
        }

        // Quick re-route: the "Routes to" dropdown on a category card. One
        // combined value ("admin" / "dean" / "office:<Office Name>") instead
        // of two fields, since only one of them is ever meaningful at once.
        if ($action === 'reassign_route') {
            $id = (int)($_POST['id'] ?? 0);
            $routeValue = (string)($_POST['route_value'] ?? 'admin');
            $routeType = 'admin';
            $office = null;
            if ($routeValue === 'dean') {
                $routeType = 'dean';
            } elseif (str_starts_with($routeValue, 'office:')) {
                $office = trim(substr($routeValue, 7));
                $routeType = $office !== '' ? 'office' : 'admin';
            }
            if ($id <= 0) {
                redirect_types('error', 'Invalid category.');
            }
            $stmt = $pdo->prepare('UPDATE suggestion_categories SET route_type = :route_type, office = :office WHERE id = :id');
            $stmt->execute([':route_type' => $routeType, ':office' => $office, ':id' => $id]);
            $label = $routeType === 'office' ? $office : ($routeType === 'dean' ? "the student's Dean" : 'Admin (SAS Director)');
            redirect_types('success', 'Category now routes to ' . $label . '.');
        }

        // Suggestion Areas (Level 1) CRUD - separate from the category
        // actions above since areas have no office/route_type of their own.
        if ($action === 'save_area') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $status = (string)($_POST['status'] ?? 'active');
            if ($name === '') {
                redirect_types('error', 'Area name is required.');
            }
            if (!in_array($status, ['active', 'inactive'], true)) {
                $status = 'active';
            }

            $duplicate = $pdo->prepare('SELECT id FROM suggestion_areas WHERE LOWER(name) = LOWER(:name) AND id <> :id LIMIT 1');
            $duplicate->execute([':name' => $name, ':id' => $id]);
            if ($duplicate->fetchColumn()) {
                redirect_types('error', 'That area name already exists.');
            }

            if ($id > 0) {
                $stmt = $pdo->prepare('UPDATE suggestion_areas SET name = :name, is_active = :is_active WHERE id = :id');
                $stmt->execute([':name' => $name, ':is_active' => $status === 'active' ? 1 : 0, ':id' => $id]);
                redirect_types('success', 'Area updated successfully.');
            }

            $nextOrder = (int)$pdo->query('SELECT COALESCE(MAX(display_order), 0) + 10 FROM suggestion_areas')->fetchColumn();
            $stmt = $pdo->prepare('INSERT INTO suggestion_areas (name, display_order, is_active) VALUES (:name, :display_order, :is_active)');
            $stmt->execute([':name' => $name, ':display_order' => $nextOrder, ':is_active' => $status === 'active' ? 1 : 0]);
            redirect_types('success', 'Area added successfully.');
        }

        if ($action === 'toggle_area') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare('UPDATE suggestion_areas SET is_active = IF(is_active = 1, 0, 1) WHERE id = :id');
            $stmt->execute([':id' => $id]);
            redirect_types('success', 'Area status updated successfully.');
        }

        // Deleting a normal Area never deletes its categories or any
        // suggestion that already used them - a non-empty Area moves its
        // categories into "Other" first (recreating "Other" on demand if it
        // was itself deleted earlier), then the (now-empty) Area is removed.
        // "Other" itself has no further fallback to move into, so deleting
        // it while non-empty instead permanently deletes its categories too
        // (a suggestion that used one keeps existing, just shows
        // "Uncategorized" afterward) - the confirm() dialog spells this out
        // before it ever reaches here.
        if ($action === 'delete_area') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                redirect_types('error', 'Invalid area.');
            }

            $areaNameStmt = $pdo->prepare('SELECT name FROM suggestion_areas WHERE id = :id LIMIT 1');
            $areaNameStmt->execute([':id' => $id]);
            $areaName = (string)$areaNameStmt->fetchColumn();
            if ($areaName === '') {
                redirect_types('error', 'Area not found.');
            }

            $categoryCountStmt = $pdo->prepare('SELECT COUNT(*) FROM suggestion_categories WHERE area_id = :id');
            $categoryCountStmt->execute([':id' => $id]);
            $categoryCount = (int)$categoryCountStmt->fetchColumn();
            $isOtherArea = strcasecmp($areaName, 'Other') === 0;

            $fallbackAreaId = 0;
            if ($categoryCount > 0 && !$isOtherArea) {
                $fallbackAreaId = ensure_other_suggestion_area($pdo);
                if ($fallbackAreaId <= 0) {
                    redirect_types('error', 'Unable to find or create an "Other" area to move categories into.');
                }
            }

            $pdo->beginTransaction();
            $movedCount = 0;
            $deletedCategoryCount = 0;
            if ($fallbackAreaId > 0) {
                $moveStmt = $pdo->prepare('UPDATE suggestion_categories SET area_id = :fallback_id WHERE area_id = :id');
                $moveStmt->execute([':fallback_id' => $fallbackAreaId, ':id' => $id]);
                $movedCount = $moveStmt->rowCount();
            } elseif ($categoryCount > 0 && $isOtherArea) {
                $deleteCatStmt = $pdo->prepare('DELETE FROM suggestion_categories WHERE area_id = :id');
                $deleteCatStmt->execute([':id' => $id]);
                $deletedCategoryCount = $deleteCatStmt->rowCount();
            }
            $pdo->prepare('DELETE FROM suggestion_areas WHERE id = :id')->execute([':id' => $id]);
            $pdo->commit();

            $note = '';
            if ($movedCount > 0) {
                $note = ' ' . $movedCount . ' categor' . ($movedCount === 1 ? 'y was' : 'ies were') . ' moved to Other.';
            } elseif ($deletedCategoryCount > 0) {
                $note = ' ' . $deletedCategoryCount . ' categor' . ($deletedCategoryCount === 1 ? 'y was' : 'ies were') . ' permanently deleted with it.';
            }
            redirect_types('success', 'Area deleted.' . $note);
        }

        // Deleting a suggestion category never deletes the suggestions that
        // used it - it's a leaf node, nothing needs to move anywhere. Any
        // suggestion that used it keeps existing, it just shows as
        // "Uncategorized" afterward (same graceful fallback already used
        // everywhere a category is looked up for display).
        if ($action === 'delete_category') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                redirect_types('error', 'Invalid category.');
            }
            $catNameStmt = $pdo->prepare('SELECT name FROM suggestion_categories WHERE id = :id LIMIT 1');
            $catNameStmt->execute([':id' => $id]);
            $catName = (string)$catNameStmt->fetchColumn();
            if ($catName === '') {
                redirect_types('error', 'Category not found.');
            }
            $pdo->prepare('DELETE FROM suggestion_categories WHERE id = :id')->execute([':id' => $id]);
            redirect_types('success', 'Category "' . $catName . '" deleted.');
        }

        // Quick rename, triggered from the Area board (no modal).
        if ($action === 'rename') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            if ($id <= 0 || $name === '') {
                redirect_types('error', 'Please enter a name.');
            }
            $duplicate = $pdo->prepare("SELECT id FROM {$table} WHERE LOWER(name) = LOWER(:name) AND id <> :id LIMIT 1");
            $duplicate->execute([':name' => $name, ':id' => $id]);
            if ($duplicate->fetchColumn()) {
                redirect_types('error', 'That type name already exists for this submission type.');
            }
            $stmt = $pdo->prepare("UPDATE {$table} SET name = :name WHERE id = :id");
            $stmt->execute([':name' => $name, ':id' => $id]);
            redirect_types('success', 'Renamed successfully.');
        }
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        redirect_types('error', $exception->getCode() === '23000' ? 'That type name already exists.' : 'Unable to save the type.');
    }
}

$search = trim((string)($_GET['search'] ?? ''));
$typeFilter = (string)($_GET['type'] ?? 'all');
$statusFilter = (string)($_GET['status_filter'] ?? 'all');
$areaFilter = (int)($_GET['area'] ?? 0);
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
    $columns = $table === 'suggestion_categories' ? 'id, name, area_id, office, route_type, is_active' : 'id, name, is_active';
    $sql = "SELECT {$columns} FROM {$table}";
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY name ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
};

$suggestionWhere = $where;
$suggestionParams = $params;
if ($areaFilter > 0) {
    $suggestionWhere[] = 'area_id = :area_filter';
    $suggestionParams[':area_filter'] = $areaFilter;
}

$complaintTypes = $typeFilter === 'suggestion' ? [] : $loadTypes($pdo, 'complaint_categories', $where, $params);
$suggestionTypes = $typeFilter === 'complaint' ? [] : $loadTypes($pdo, 'suggestion_categories', $suggestionWhere, $suggestionParams);
$editType = null;
$editTypeKind = '';
$editId = (int)($_GET['edit'] ?? 0);
if ($editId > 0) {
    foreach ([['complaint_categories', $complaintTypes, 'complaint']] as [$table, $items, $kind]) {
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

// Known offices - only used to populate the "Routes to" dropdown now
// (Areas replace offices as the board's grouping axis). Sourced from active
// staff accounts, same as the student-facing office matching at submission.
$knownOffices = [];
try {
    $officeStmt = $pdo->query(
        "SELECT DISTINCT office FROM staff_profiles WHERE office IS NOT NULL AND TRIM(office) <> '' ORDER BY office ASC"
    );
    foreach ($officeStmt->fetchAll(PDO::FETCH_COLUMN) as $officeName) {
        $officeName = trim((string)$officeName);
        if ($officeName !== '') {
            $knownOffices[] = $officeName;
        }
    }
} catch (PDOException $e) {
}

// All Areas (Level 1) - used for the Area filter, the per-row Area dropdown,
// and the "Add Category" modal.
$allAreas = [];
try {
    $allAreas = $pdo->query('SELECT id, name, display_order, is_active FROM suggestion_areas ORDER BY display_order ASC, name ASC')->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
}
$areaNameById = [];
foreach ($allAreas as $area) {
    $areaNameById[(int)$area['id']] = (string)$area['name'];
}

// One flat, sortable table instead of a card-per-Area board - avoids the
// wide, scroll-heavy layout a card grid produces once there are several
// Areas. Sorted by Area then name so related categories still land near
// each other; the Area filter above narrows it down further on request.
usort($suggestionTypes, static function (array $a, array $b) use ($areaNameById): int {
    $areaA = $areaNameById[(int)($a['area_id'] ?? 0)] ?? '';
    $areaB = $areaNameById[(int)($b['area_id'] ?? 0)] ?? '';
    return strcasecmp($areaA, $areaB) ?: strcasecmp((string)$a['name'], (string)$b['name']);
});

$editArea = null;
$editAreaId = (int)($_GET['edit_area'] ?? 0);
if ($editAreaId > 0) {
    foreach ($allAreas as $area) {
        if ((int)$area['id'] === $editAreaId) {
            $editArea = $area;
            break;
        }
    }
}

// "Office" column on the Suggestion Areas table below: a quick, at-a-glance
// summary of where each Area's active categories currently route (an office
// name, "Dean", and/or "Admin") - computed independently of the categories
// table's own search/status/Area filters, since an Area's own row should
// always reflect its true current setup. Remember: the Area itself is never
// the routing destination - this is just a summary of what's inside it.
$areaRouteSummaries = [];
try {
    $routeStmt = $pdo->query(
        'SELECT area_id, office, route_type FROM suggestion_categories WHERE is_active = 1 AND area_id IS NOT NULL'
    );
    $areaDestinations = [];
    foreach ($routeStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rowAreaId = (int)$row['area_id'];
        $rowRouteType = (string)($row['route_type'] ?? 'admin');
        if ($rowRouteType === 'office') {
            $label = trim((string)($row['office'] ?? ''));
            if ($label === '') {
                $label = 'Admin';
            }
        } else {
            $label = $rowRouteType === 'dean' ? 'Dean' : 'Admin';
        }
        $areaDestinations[$rowAreaId][$label] = true;
    }
    foreach ($areaDestinations as $rowAreaId => $labels) {
        $areaRouteSummaries[$rowAreaId] = implode(', ', array_keys($labels));
    }
} catch (PDOException $e) {
}

// Category count per Area (active + inactive) so the delete confirmation
// can warn exactly how many categories are about to be moved into "Other".
$areaCategoryCounts = [];
try {
    $countStmt = $pdo->query('SELECT area_id, COUNT(*) AS total FROM suggestion_categories WHERE area_id IS NOT NULL GROUP BY area_id');
    foreach ($countStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $areaCategoryCounts[(int)$row['area_id']] = (int)$row['total'];
    }
} catch (PDOException $e) {
}
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
html { scrollbar-width:none; }
html::-webkit-scrollbar { display:none; }
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
.filters { display:grid; grid-template-columns:minmax(200px,1fr) 140px 140px 180px auto; gap:9px; align-items:end; margin-bottom:14px; }
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
.icon-btn.danger { background:#fef2f2; color:#b91c1c; }
.icon-btn.danger:hover { background:#fee2e2; }
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
.section-note { margin:0 0 12px; color:#6b7280; font-size:12px; line-height:1.6; }
.upload-card { display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap; padding:10px 16px; }
.upload-card h3 { margin:0; font-size:13px; white-space:nowrap; }
.upload-card p { margin:1px 0 0; color:#9ca3af; font-size:10.5px; }
.upload-form { display:flex; align-items:center; gap:7px; flex-wrap:wrap; flex:0 0 auto; }
.upload-form input[type="file"] { width:auto; max-width:190px; padding:5px; font-size:11px; }
.upload-form .button, .upload-form button { padding:7px 11px; font-size:11.5px; white-space:nowrap; }
@media (max-width:640px) { .upload-card { flex-direction:column; align-items:flex-start; } .upload-form { width:100%; } }
.areas-table th:nth-child(1), .areas-table td:nth-child(1) { width:30%; }
.areas-table th:nth-child(2), .areas-table td:nth-child(2) { width:32%; }
.areas-table th:nth-child(3), .areas-table td:nth-child(3) { width:18%; }
.areas-table th:nth-child(4), .areas-table td:nth-child(4) { width:20%; }
.muted-cell { color:#6b7280; }
.suggestion-table { min-width:820px; }
.suggestion-table th:nth-child(1), .suggestion-table td:nth-child(1) { width:20%; }
.suggestion-table th:nth-child(2), .suggestion-table td:nth-child(2) { width:26%; }
.suggestion-table th:nth-child(3), .suggestion-table td:nth-child(3) { width:26%; }
.suggestion-table th:nth-child(4), .suggestion-table td:nth-child(4) { width:14%; }
.suggestion-table th:nth-child(5), .suggestion-table td:nth-child(5) { width:14%; }
.row-inline-form { display:contents; }
.row-select { padding:6px 7px; font-size:12px; border-radius:6px; }
.cat-name { border:0; background:none; padding:0; font:600 12px 'Poppins',sans-serif; color:#4338ca; cursor:pointer; text-align:left; }
.cat-name.inactive { color:#9ca3af; text-decoration:line-through; }
.back-to-top { position:fixed; right:24px; bottom:24px; width:44px; height:44px; border-radius:50%; border:0; background:#6d28d9; color:#fff; font-size:20px; display:flex; align-items:center; justify-content:center; cursor:pointer; box-shadow:0 6px 16px rgba(109,40,217,.35); z-index:500; opacity:0; visibility:hidden; transform:translateY(8px); transition:opacity .2s, transform .2s, visibility .2s; }
.back-to-top.visible { opacity:1; visibility:visible; transform:translateY(0); }
.back-to-top:hover { background:#5b21b6; }
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
        <div><h2>Complaint &amp; Suggestion Categories</h2></div>
    </div>

    <?php if ($message !== '' && in_array($status, ['success', 'error'], true)): ?>
        <div class="alert <?php echo e($status); ?>"><?php echo e($message); ?></div>
    <?php endif; ?>

    <div class="card upload-card">
        <div>
            <h3>Bulk Upload Categories</h3>
            <p title="Columns: type (complaint/suggestion), area, name, route_type (office/dean/admin), office, status. Area and route_type apply to suggestion rows only.">CSV: type, area, name, route_type, office, status - matches update, new rows add.</p>
        </div>
        <form class="upload-form" method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo e((string)$_SESSION['csrf_token']); ?>">
            <input type="hidden" name="action" value="bulk_upload">
            <?php if ($embedded): ?><input type="hidden" name="embedded" value="1"><?php endif; ?>
            <a class="button secondary" href="admin_types.php?download=categories_template<?php echo $embedded ? '&embedded=1' : ''; ?>"><i class="bx bx-download"></i> Download Template</a>
            <input type="file" name="categories_csv" accept=".csv,text/csv" required>
            <button type="submit"><i class="bx bx-upload"></i> Upload CSV</button>
        </form>
    </div>

    <form class="card filters" method="get" id="typesFilterForm">
        <?php if ($embedded): ?><input type="hidden" name="embedded" value="1"><?php endif; ?>
        <div><label for="searchTypes">Search Types</label><input id="searchTypes" name="search" value="<?php echo e($search); ?>" placeholder="Search by name"></div>
        <div><label for="typeFilter">Type</label><select id="typeFilter" name="type" onchange="document.getElementById('typesFilterForm').submit()"><option value="all" <?php echo $typeFilter === 'all' ? 'selected' : ''; ?>>All</option><option value="complaint" <?php echo $typeFilter === 'complaint' ? 'selected' : ''; ?>>Complaint</option><option value="suggestion" <?php echo $typeFilter === 'suggestion' ? 'selected' : ''; ?>>Suggestion</option></select></div>
        <div><label for="statusFilter">Status</label><select id="statusFilter" name="status_filter" onchange="document.getElementById('typesFilterForm').submit()"><option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All</option><option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option><option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option></select></div>
        <div><label for="areaFilter">Area (suggestions)</label><select id="areaFilter" name="area" <?php echo $typeFilter === 'complaint' ? 'disabled' : ''; ?> onchange="document.getElementById('typesFilterForm').submit()"><option value="0">All Areas</option><?php foreach ($allAreas as $areaOption): ?><option value="<?php echo (int)$areaOption['id']; ?>" <?php echo $areaFilter === (int)$areaOption['id'] ? 'selected' : ''; ?>><?php echo e((string)$areaOption['name']); ?></option><?php endforeach; ?></select></div>
        <div class="filter-actions"><a class="button secondary" href="admin_types.php<?php echo $embedded ? '?embedded=1' : ''; ?>">Clear</a></div>
    </form>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        // Deferred to DOMContentLoaded (rather than running inline right
        // here) because several elements this script looks up - the results
        // anchors, the back-to-top button - are declared further down in the
        // HTML; looking them up immediately, before the parser reaches them,
        // would silently find nothing and skip that behavior entirely.

        // This page is normally loaded inside a same-origin iframe (Settings
        // > Types), sized to fit the visible viewport - its own content
        // scrolls inside that iframe box (the iframe's own window), the
        // outer Settings page doesn't need to move. So every scroll-related
        // feature below just operates on this document's own window.
        const scrollWin = window;

        const searchInput = document.getElementById('searchTypes');
        const form = document.getElementById('typesFilterForm');
        const typeSelect = document.getElementById('typeFilter');
        const areaSelect = document.getElementById('areaFilter');
        if (searchInput && form) {
            let debounceTimer;
            searchInput.addEventListener('input', function () {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(function () { form.submit(); }, 400);
            });

            // The live search reloads the whole page, which drops focus like
            // any normal navigation would - put it right back in the search
            // box (cursor at the end) so typing can continue without having
            // to click back in after every pause.
            if (searchInput.value !== '') {
                searchInput.focus({ preventScroll: true });
                const end = searchInput.value.length;
                searchInput.setSelectionRange(end, end);
            }
        }
        if (typeSelect && areaSelect) {
            typeSelect.addEventListener('change', function () {
                areaSelect.disabled = typeSelect.value === 'complaint';
            });
        }

        // Floating back-to-top button: hidden right at the top of the page
        // (where it would otherwise sit on top of real content, like a
        // table row's own action buttons), fades in after just a small
        // scroll, and jumps straight back to the very top on click. Uses the
        // same scrollWin resolved above, for the same reason.
        const backToTop = document.getElementById('backToTopBtn');
        if (backToTop) {
            const toggleBackToTop = function () {
                backToTop.classList.toggle('visible', scrollWin.scrollY > 80);
            };
            scrollWin.addEventListener('scroll', toggleBackToTop, { passive: true });
            toggleBackToTop();
            backToTop.addEventListener('click', function () {
                scrollWin.scrollTo({ top: 0, behavior: 'smooth' });
            });
        }
    });
    </script>
    <button type="button" id="backToTopBtn" class="back-to-top" title="Back to top" aria-label="Back to top"><i class="bx bx-up-arrow-alt"></i></button>

    <div id="resultsAnchor"></div>
    <?php if ($typeFilter !== 'suggestion'): ?>
    <section class="card">
        <div class="section-head">
            <h3>Complaint Categories</h3>
            <div style="display:flex; align-items:center; gap:10px;">
                <span><?php echo count($complaintTypes); ?> categor<?php echo count($complaintTypes) === 1 ? 'y' : 'ies'; ?></span>
                <button type="button" data-open-modal="typeModal"><i class="bx bx-plus"></i> Add Complaint Category</button>
            </div>
        </div>
        <p class="section-note">The AI classifier picks one of these for every complaint automatically - no routing to set here, just keep the list accurate.</p>
        <div class="table-wrap"><table>
            <thead><tr><th>Category Name</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if (!$complaintTypes): ?><tr><td colspan="3" class="empty">No complaint categories match the current filters.</td></tr><?php endif; ?>
            <?php foreach ($complaintTypes as $item): ?>
                <tr>
                    <td><strong><?php echo e((string)$item['name']); ?></strong></td>
                    <td><span class="status <?php echo (int)$item['is_active'] === 1 ? '' : 'off'; ?>"><?php echo (int)$item['is_active'] === 1 ? 'Active' : 'Inactive'; ?></span></td>
                    <td><div class="actions"><a class="button icon-btn" href="admin_types.php?edit=<?php echo (int)$item['id']; ?>&type=complaint<?php echo $embedded ? '&embedded=1' : ''; ?>" title="Edit category"><i class="bx bx-edit"></i></a><form method="post" onsubmit="return confirm('Are you sure you want to <?php echo (int)$item['is_active'] === 1 ? 'deactivate' : 'activate'; ?> this category?');"><input type="hidden" name="csrf_token" value="<?php echo e((string)$_SESSION['csrf_token']); ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="type" value="complaint"><input type="hidden" name="id" value="<?php echo (int)$item['id']; ?>"><?php if ($embedded): ?><input type="hidden" name="embedded" value="1"><?php endif; ?><button class="icon-btn warning" type="submit" title="<?php echo (int)$item['is_active'] === 1 ? 'Deactivate' : 'Activate'; ?> category"><i class="bx <?php echo (int)$item['is_active'] === 1 ? 'bx-power-off' : 'bx-check'; ?>"></i></button></form></div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>
    <?php endif; ?>

    <?php if ($typeFilter !== 'complaint'): ?>
    <section class="card">
        <div class="section-head"><h3>Suggestion Areas</h3><span><?php echo count($allAreas); ?> area<?php echo count($allAreas) === 1 ? '' : 's'; ?></span></div>
        <p class="section-note">The broad, jargon-free groupings students pick from first (e.g. "Technology &amp; Internet"). An Area is never itself a routing destination - Office below is just a summary of where its categories currently send suggestions; change it per category in the table further down.</p>
        <div class="table-wrap"><table class="areas-table">
            <thead><tr><th>Area Name</th><th>Office</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if (!$allAreas): ?><tr><td colspan="4" class="empty">No suggestion areas yet.</td></tr><?php endif; ?>
            <?php foreach ($allAreas as $area): ?>
                <tr>
                    <td><strong><?php echo e((string)$area['name']); ?></strong></td>
                    <td class="muted-cell"><?php echo e($areaRouteSummaries[(int)$area['id']] ?? '—'); ?></td>
                    <td><span class="status <?php echo (int)$area['is_active'] === 1 ? '' : 'off'; ?>"><?php echo (int)$area['is_active'] === 1 ? 'Active' : 'Inactive'; ?></span></td>
                    <td><div class="actions">
                        <a class="button icon-btn" href="admin_types.php?edit_area=<?php echo (int)$area['id']; ?><?php echo $embedded ? '&embedded=1' : ''; ?>" title="Edit area"><i class="bx bx-edit"></i></a>
                        <form method="post"><input type="hidden" name="csrf_token" value="<?php echo e((string)$_SESSION['csrf_token']); ?>"><input type="hidden" name="action" value="toggle_area"><input type="hidden" name="type" value="suggestion"><input type="hidden" name="id" value="<?php echo (int)$area['id']; ?>"><?php if ($embedded): ?><input type="hidden" name="embedded" value="1"><?php endif; ?><button class="icon-btn warning" type="submit" onclick="return confirm('Are you sure you want to <?php echo (int)$area['is_active'] === 1 ? 'deactivate' : 'activate'; ?> this area?');" title="<?php echo (int)$area['is_active'] === 1 ? 'Deactivate' : 'Activate'; ?> area"><i class="bx <?php echo (int)$area['is_active'] === 1 ? 'bx-power-off' : 'bx-check'; ?>"></i></button></form>
                        <?php
                        $areaCatCount = $areaCategoryCounts[(int)$area['id']] ?? 0;
                        $isOtherArea = strcasecmp((string)$area['name'], 'Other') === 0;
                        $deleteConfirmExtra = '';
                        if ($areaCatCount > 0) {
                            $deleteConfirmExtra = $isOtherArea
                                ? ' It still has ' . $areaCatCount . ' categor' . ($areaCatCount === 1 ? 'y' : 'ies') . ' with no fallback of ' . ($areaCatCount === 1 ? 'its' : 'their') . ' own - deleting Other will PERMANENTLY DELETE ' . ($areaCatCount === 1 ? 'it' : 'them') . ' too, and any suggestion that used ' . ($areaCatCount === 1 ? 'it' : 'them') . ' will show as Uncategorized.'
                                : ' Its ' . $areaCatCount . ' categor' . ($areaCatCount === 1 ? 'y' : 'ies') . ' will be moved to Other.';
                        }
                        ?>
                        <form method="post"><input type="hidden" name="csrf_token" value="<?php echo e((string)$_SESSION['csrf_token']); ?>"><input type="hidden" name="action" value="delete_area"><input type="hidden" name="type" value="suggestion"><input type="hidden" name="id" value="<?php echo (int)$area['id']; ?>"><?php if ($embedded): ?><input type="hidden" name="embedded" value="1"><?php endif; ?><button class="icon-btn danger" type="submit" onclick="return confirm('Delete &quot;<?php echo e(addslashes((string)$area['name'])); ?>&quot;?<?php echo $deleteConfirmExtra; ?> This cannot be undone.');" title="Delete area"><i class="bx bx-trash"></i></button></form>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>

    <div id="suggestionResultsAnchor"></div>
    <section class="card">
        <div class="section-head">
            <h3>Suggestion Categories</h3>
            <div style="display:flex; align-items:center; gap:10px;">
                <span><?php echo count($suggestionTypes); ?> categor<?php echo count($suggestionTypes) === 1 ? 'y' : 'ies'; ?></span>
                <button type="button" data-open-modal="addCategoryModal"><i class="bx bx-plus"></i> Add Category</button>
            </div>
        </div>
        <p class="section-note">One row per category, sorted by Area. Change "Area" or "Routes to" right here - they save instantly, no need to open anything. Click a category's name to rename it. Students only ever see the Area and category name, never the routing.</p>
        <div class="table-wrap"><table class="suggestion-table">
            <thead><tr><th>Area</th><th>Category Name</th><th>Routes To</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if (!$suggestionTypes): ?><tr><td colspan="5" class="empty">No suggestion categories match the current filters.</td></tr><?php endif; ?>
            <?php foreach ($suggestionTypes as $item): ?>
                <?php echo render_suggestion_category_row($item, $allAreas, $knownOffices, $embedded); ?>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>
    <?php endif; ?>
</main>

<div class="modal <?php echo $editType ? 'open' : ''; ?>" id="typeModal" role="dialog" aria-modal="true" aria-labelledby="typeModalTitle">
    <div class="modal-card">
        <div class="modal-head"><h3 id="typeModalTitle"><?php echo $editType ? 'Edit Complaint Category' : 'Add Complaint Category'; ?></h3><button class="close-modal" type="button" data-close-modal>&times;</button></div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo e((string)$_SESSION['csrf_token']); ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="type" value="complaint">
            <?php if ($embedded): ?><input type="hidden" name="embedded" value="1"><?php endif; ?>
            <?php if ($editType): ?><input type="hidden" name="id" value="<?php echo (int)$editType['id']; ?>"><?php endif; ?>
            <div class="form-grid">
                <div class="form-group full"><label for="typeName">Category Name *</label><input id="typeName" name="name" maxlength="100" required value="<?php echo e((string)($editType['name'] ?? '')); ?>"></div>
                <div class="form-group"><label for="typeStatus">Status</label><select id="typeStatus" name="status"><option value="active" <?php echo !$editType || (int)$editType['is_active'] === 1 ? 'selected' : ''; ?>>Active</option><option value="inactive" <?php echo $editType && (int)$editType['is_active'] === 0 ? 'selected' : ''; ?>>Inactive</option></select></div>
            </div>
            <div class="form-actions"><button type="button" class="secondary" data-close-modal>Cancel</button><button type="submit">Save Category</button></div>
        </form>
    </div>
</div>

<div class="modal <?php echo $editArea ? 'open' : ''; ?>" id="areaModal" role="dialog" aria-modal="true" aria-labelledby="areaModalTitle">
    <div class="modal-card">
        <div class="modal-head"><h3 id="areaModalTitle"><?php echo $editArea ? 'Edit Area' : 'Add Area'; ?></h3><button class="close-modal" type="button" data-close-modal>&times;</button></div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo e((string)$_SESSION['csrf_token']); ?>">
            <input type="hidden" name="action" value="save_area">
            <input type="hidden" name="type" value="suggestion">
            <?php if ($embedded): ?><input type="hidden" name="embedded" value="1"><?php endif; ?>
            <?php if ($editArea): ?><input type="hidden" name="id" value="<?php echo (int)$editArea['id']; ?>"><?php endif; ?>
            <div class="form-grid">
                <div class="form-group full"><label for="areaName">Area Name *</label><input id="areaName" name="name" maxlength="150" required value="<?php echo e((string)($editArea['name'] ?? '')); ?>" placeholder="e.g. Technology &amp; Internet"></div>
                <div class="form-group"><label for="areaStatus">Status</label><select id="areaStatus" name="status"><option value="active" <?php echo !$editArea || (int)$editArea['is_active'] === 1 ? 'selected' : ''; ?>>Active</option><option value="inactive" <?php echo $editArea && (int)$editArea['is_active'] === 0 ? 'selected' : ''; ?>>Inactive</option></select></div>
            </div>
            <div class="form-actions"><button type="button" class="secondary" data-close-modal>Cancel</button><button type="submit">Save Area</button></div>
        </form>
    </div>
</div>

<div class="modal" id="addCategoryModal" role="dialog" aria-modal="true" aria-labelledby="addCategoryModalTitle">
    <div class="modal-card">
        <div class="modal-head"><h3 id="addCategoryModalTitle">Add Suggestion Category</h3><button class="close-modal" type="button" data-close-modal>&times;</button></div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo e((string)$_SESSION['csrf_token']); ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="type" value="suggestion">
            <?php if ($embedded): ?><input type="hidden" name="embedded" value="1"><?php endif; ?>
            <div class="form-grid">
                <div class="form-group full"><label for="newCatName">Category Name *</label><input id="newCatName" name="name" maxlength="100" required placeholder="e.g. Wi-Fi / Internet"></div>
                <div class="form-group"><label for="newCatArea">Area *</label><select id="newCatArea" name="area_id" required>
                    <?php foreach ($allAreas as $areaOption): ?>
                        <option value="<?php echo (int)$areaOption['id']; ?>" <?php echo $areaFilter === (int)$areaOption['id'] ? 'selected' : ''; ?>><?php echo e((string)$areaOption['name']); ?></option>
                    <?php endforeach; ?>
                </select></div>
                <div class="form-group"><label for="newCatRoute">Routes To</label><select id="newCatRoute" name="route_type" onchange="document.getElementById('newCatOfficeGroup').style.display = this.value === 'office' ? 'block' : 'none';">
                    <option value="admin" selected>Admin (SAS Director)</option>
                    <option value="dean">Dean (student's college)</option>
                    <option value="office">Office...</option>
                </select></div>
                <div class="form-group" id="newCatOfficeGroup" style="display:none;"><label for="newCatOffice">Office</label><select id="newCatOffice" name="office">
                    <?php foreach ($knownOffices as $officeOption): ?>
                        <option value="<?php echo e($officeOption); ?>"><?php echo e($officeOption); ?></option>
                    <?php endforeach; ?>
                </select></div>
                <div class="form-group"><label for="newCatStatus">Status</label><select id="newCatStatus" name="status"><option value="active" selected>Active</option><option value="inactive">Inactive</option></select></div>
            </div>
            <div class="form-actions"><button type="button" class="secondary" data-close-modal>Cancel</button><button type="submit">Save Category</button></div>
        </form>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.modal').forEach(function (modal) {
        modal.addEventListener('click', function (event) {
            if (event.target === modal) modal.classList.remove('open');
        });
        // Also covers a modal the server already opened on this page load
        // (an Edit link, e.g. ?edit=5), which never goes through the
        // data-open-modal click handler below.
        if (modal.classList.contains('open')) {
            modal.scrollTop = 0;
        }
    });
    document.querySelectorAll('[data-open-modal]').forEach(function (button) {
        button.addEventListener('click', function () {
            const modal = document.getElementById(button.getAttribute('data-open-modal'));
            if (!modal) return;
            modal.classList.add('open');
            // The overlay is the scroll container (.modal { overflow:auto }) and
            // its scroll position persists across close/reopen since the element
            // is only hidden, never removed - without this it can reopen still
            // scrolled to wherever it was left (showing the bottom of the form,
            // Status/Save, instead of the top).
            modal.scrollTop = 0;
            const card = modal.querySelector('.modal-card');
            if (card) card.scrollTop = 0;
        });
    });
    document.querySelectorAll('[data-close-modal]').forEach(function (button) {
        button.addEventListener('click', function () {
            const modal = button.closest('.modal');
            if (modal) modal.classList.remove('open');
        });
    });

    // Suggestion Categories table: rename is the only control that needs
    // JS - Area and Routes To are plain selects that auto-submit their own
    // tiny form on change.
    document.querySelectorAll('.cat-name').forEach(function (button) {
        button.addEventListener('click', function () {
            const form = button.closest('form');
            const current = button.getAttribute('data-current-name') || '';
            const next = window.prompt('Rename category', current);
            if (next === null) {
                return;
            }
            const trimmed = next.trim();
            if (trimmed === '' || trimmed === current) {
                return;
            }
            form.querySelector('.cat-rename-name').value = trimmed;
            form.submit();
        });
    });
});
</script>
</body>
</html>
