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
$embedded = (string)($_GET['embedded'] ?? '') === '1';
if ($userId <= 0) {
    header('Location: /complaint-system-main/admin/login.php');
    exit;
}

if ((string)($_SESSION['role'] ?? '') !== 'admin') {
    $roleStmt = $pdo->prepare('SELECT role FROM users WHERE id = :id LIMIT 1');
    $roleStmt->execute([':id' => $userId]);
    $role = $roleStmt->fetchColumn();
    if ($role !== 'admin') {
        header('Location: /complaint-system-main/admin/login.php');
        exit;
    }
    $_SESSION['role'] = 'admin';
}

$pdo->exec("CREATE TABLE IF NOT EXISTS faq_categories (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(150) NOT NULL,
    display_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id), UNIQUE KEY uq_faq_category_name (name), KEY idx_faq_category_active_order (is_active, display_order, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
$pdo->exec("CREATE TABLE IF NOT EXISTS faq_questions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    category_id INT UNSIGNED NOT NULL,
    question VARCHAR(255) NOT NULL,
    answer TEXT NOT NULL,
    display_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id), KEY idx_faq_question_category_active_order (category_id, is_active, display_order, question),
    CONSTRAINT fk_faq_question_category FOREIGN KEY (category_id) REFERENCES faq_categories (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

function redirect_faq(string $status, string $message): never
{
    $params = ['status' => $status, 'msg' => $message];
    if ((string)($_GET['embedded'] ?? $_POST['embedded'] ?? '') === '1') {
        $params['embedded'] = '1';
    }
    header('Location: admin_faq.php?' . http_build_query($params));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals((string)$_SESSION['csrf_token'], $csrf)) {
        redirect_faq('error', 'Your session expired. Please try again.');
    }

    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'bulk_upload') {
            if (!isset($_FILES['faq_csv']) || (int)$_FILES['faq_csv']['error'] !== UPLOAD_ERR_OK) {
                redirect_faq('error', 'Please choose a valid CSV file.');
            }

            $handle = fopen((string)$_FILES['faq_csv']['tmp_name'], 'rb');
            if ($handle === false) {
                redirect_faq('error', 'Unable to read the CSV file.');
            }

            $header = fgetcsv($handle);
            $header = array_map(static function ($value): string {
                $value = trim((string)$value);
                $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
                return strtolower(trim($value));
            }, $header ?: []);
            $requiredColumns = ['category', 'question', 'answer'];
            if (array_diff($requiredColumns, $header) !== []) {
                fclose($handle);
                redirect_faq('error', 'CSV must contain category, question, and answer columns.');
            }

            $columnIndex = array_flip($header);
            $categoryStmt = $pdo->prepare('SELECT id FROM faq_categories WHERE name = :name LIMIT 1');
            $createCategoryStmt = $pdo->prepare('INSERT INTO faq_categories (name) VALUES (:name)');
            $questionStmt = $pdo->prepare('SELECT id FROM faq_questions WHERE category_id = :category_id AND question = :question LIMIT 1');
            $updateQuestionStmt = $pdo->prepare('UPDATE faq_questions SET answer = :answer, is_active = 1 WHERE id = :id');
            $createQuestionStmt = $pdo->prepare('INSERT INTO faq_questions (category_id, question, answer) VALUES (:category_id, :question, :answer)');
            $imported = 0;
            $skipped = 0;

            $pdo->beginTransaction();
            while (($row = fgetcsv($handle)) !== false) {
                $categoryName = trim((string)($row[$columnIndex['category']] ?? ''));
                $questionText = trim((string)($row[$columnIndex['question']] ?? ''));
                $answerText = trim((string)($row[$columnIndex['answer']] ?? ''));
                if ($categoryName === '' || $questionText === '' || $answerText === '') {
                    $skipped++;
                    continue;
                }

                $categoryStmt->execute([':name' => $categoryName]);
                $categoryId = (int)$categoryStmt->fetchColumn();
                if ($categoryId <= 0) {
                    $createCategoryStmt->execute([':name' => $categoryName]);
                    $categoryId = (int)$pdo->lastInsertId();
                }

                $questionStmt->execute([':category_id' => $categoryId, ':question' => $questionText]);
                $questionId = (int)$questionStmt->fetchColumn();
                if ($questionId > 0) {
                    $updateQuestionStmt->execute([':answer' => $answerText, ':id' => $questionId]);
                } else {
                    $createQuestionStmt->execute([':category_id' => $categoryId, ':question' => $questionText, ':answer' => $answerText]);
                }
                $imported++;
            }
            fclose($handle);
            $pdo->commit();
            redirect_faq('success', "Imported {$imported} FAQ row(s). Skipped {$skipped} incomplete row(s).");
        }

        if ($action === 'save_category') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') {
                redirect_faq('error', 'Category name is required.');
            }
            if ($id > 0) {
                $stmt = $pdo->prepare('UPDATE faq_categories SET name = :name WHERE id = :id');
                $stmt->execute([':name' => $name, ':id' => $id]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO faq_categories (name) VALUES (:name)');
                $stmt->execute([':name' => $name]);
            }
            redirect_faq('success', 'FAQ category saved.');
        }

        if ($action === 'save_question') {
            $id = (int)($_POST['id'] ?? 0);
            $categoryId = (int)($_POST['category_id'] ?? 0);
            $question = trim((string)($_POST['question'] ?? ''));
            $answer = trim((string)($_POST['answer'] ?? ''));
            if ($categoryId <= 0 || $question === '' || $answer === '') {
                redirect_faq('error', 'Category, question, and answer are required.');
            }
            if ($id > 0) {
                $stmt = $pdo->prepare('UPDATE faq_questions SET category_id = :category_id, question = :question, answer = :answer WHERE id = :id');
                $stmt->execute([':category_id' => $categoryId, ':question' => $question, ':answer' => $answer, ':id' => $id]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO faq_questions (category_id, question, answer) VALUES (:category_id, :question, :answer)');
                $stmt->execute([':category_id' => $categoryId, ':question' => $question, ':answer' => $answer]);
            }
            redirect_faq('success', 'FAQ question saved.');
        }

        if ($action === 'toggle_category') {
            $stmt = $pdo->prepare('UPDATE faq_categories SET is_active = IF(is_active = 1, 0, 1) WHERE id = :id');
            $stmt->execute([':id' => (int)($_POST['id'] ?? 0)]);
            redirect_faq('success', 'FAQ category status updated.');
        }

        if ($action === 'delete_category') {
            $stmt = $pdo->prepare('DELETE FROM faq_categories WHERE id = :id');
            $stmt->execute([':id' => (int)($_POST['id'] ?? 0)]);
            redirect_faq('success', 'FAQ category and its questions were deleted.');
        }

        if ($action === 'toggle_question') {
            $stmt = $pdo->prepare('UPDATE faq_questions SET is_active = IF(is_active = 1, 0, 1) WHERE id = :id');
            $stmt->execute([':id' => (int)($_POST['id'] ?? 0)]);
            redirect_faq('success', 'FAQ question status updated.');
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        redirect_faq('error', $e->getCode() === '23000' ? 'That category name already exists.' : 'Unable to save FAQ data.');
    }
}

$categories = $pdo->query('SELECT id, name, is_active FROM faq_categories ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$questions = $pdo->query('SELECT q.id, q.category_id, q.question, q.answer, q.is_active, c.name AS category_name FROM faq_questions q INNER JOIN faq_categories c ON c.id = q.category_id ORDER BY c.name, q.question')->fetchAll(PDO::FETCH_ASSOC);
$selectedCategoryId = (int)($_GET['category'] ?? ($categories[0]['id'] ?? 0));
$categoryQuestionCounts = [];
foreach ($questions as $question) {
    $categoryQuestionCounts[(int)$question['category_id']] = ($categoryQuestionCounts[(int)$question['category_id']] ?? 0) + 1;
}
$selectedCategory = null;
foreach ($categories as $category) {
    if ((int)$category['id'] === $selectedCategoryId) {
        $selectedCategory = $category;
        break;
    }
}
$selectedQuestions = array_values(array_filter($questions, static fn(array $question): bool => (int)$question['category_id'] === $selectedCategoryId));
$editCategory = null;
$editQuestion = null;
foreach ($categories as $category) {
    if ((int)$category['id'] === (int)($_GET['edit_category'] ?? 0)) {
        $editCategory = $category;
        break;
    }
}
foreach ($questions as $question) {
    if ((int)$question['id'] === (int)($_GET['edit_question'] ?? 0)) {
        $editQuestion = $question;
        break;
    }
}
$status = (string)($_GET['status'] ?? '');
$message = (string)($_GET['msg'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>VOICE - FAQ Management</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
* { box-sizing: border-box; font-family: 'Poppins', sans-serif; }
body { margin: 0; background: #f8fafc; color: #1f2937; }
.main { margin-left: 260px; margin-top: 61px; padding: 28px; min-height: calc(100vh - 61px); }
<?php if ($embedded): ?>
body { background:#f8fafc; }
.topbar, .sidebar, .mobile-sidebar-overlay { display:none !important; }
.main { margin:0; padding:20px; min-height:0; }
<?php endif; ?>
.page-header { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; margin-bottom:22px; }
.page-header h2 { margin: 0 0 6px; font-size: 24px; }
.page-header p { margin: 0; color: #6b7280; font-size: 14px; }
.grid { display:none; }
.card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 18px; margin-bottom: 18px; box-shadow: 0 2px 10px rgba(0,0,0,.02); }
.card h3 { margin: 0; font-size: 15px; }
label { display: block; margin: 12px 0 6px; font-size: 12px; font-weight: 600; color: #6b7280; }
input, select, textarea { width: 100%; border: 1px solid #d1d5db; border-radius: 7px; padding: 10px 11px; font: inherit; font-size: 13px; }
textarea { min-height: 110px; resize: vertical; }
button { border: 0; border-radius: 7px; padding: 10px 14px; background: #6d28d9; color: #fff; font-weight: 600; cursor: pointer; }
button.secondary { background: #eef2ff; color: #4338ca; }
button.warning { background: #fff7ed; color: #c2410c; }
.form-actions { display: flex; gap: 8px; margin-top: 16px; }
.overview-table, .questions-table { width:100%; border-collapse:collapse; }
.overview-table th, .questions-table th { padding:10px 12px; text-align:left; background:#fafafa; border-bottom:1px solid #eef0f3; color:#9ca3af; font-size:10px; text-transform:uppercase; letter-spacing:.03em; }
.overview-table td, .questions-table td { padding:11px 12px; border-bottom:1px solid #f1f2f4; font-size:12px; vertical-align:middle; }
.overview-table tr.selected td { background:#faf7ff; }
.category-link { display:flex; align-items:center; gap:9px; color:#1f2937; text-decoration:none; font-weight:600; }
.category-link:hover { color:#6d28d9; }
.category-icon { width:24px; height:24px; display:grid; place-items:center; border-radius:7px; background:#ede9fe; color:#7c3aed; }
.badge { display:inline-flex; padding:4px 8px; border-radius:999px; background:#ede9fe; color:#6d28d9; font-size:11px; font-weight:600; }
.status { display:inline-flex; padding:4px 8px; border-radius:999px; background:#dcfce7; color:#047857; font-size:10px; font-weight:600; }
.status.off { background:#f3f4f6; color:#9ca3af; }
.actions { display:flex; gap:6px; align-items:center; }
.icon-btn { width:30px; height:30px; padding:0; display:grid; place-items:center; background:#eef2ff; color:#4338ca; }
.icon-btn.warning { background:#fff1f2; color:#e11d48; }
.table-wrap { overflow-x:auto; }
.section-head { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:14px; }
.section-head p { margin:4px 0 0; color:#9ca3af; font-size:11px; }
.management-grid { display:grid; grid-template-columns:190px minmax(0,1fr); gap:18px; }
.category-nav { border-right:1px solid #eef0f3; padding-right:12px; }
.category-nav a { display:flex; justify-content:space-between; gap:8px; padding:10px; border-radius:7px; text-decoration:none; color:#4b5563; font-size:12px; }
.category-nav a:hover, .category-nav a.active { background:#ede9fe; color:#5b21b6; }
.category-nav .nav-count { color:#9ca3af; }
.answer-cell { max-width:360px; color:#6b7280; line-height:1.4; }
.empty { padding:18px 12px; color:#9ca3af; font-size:12px; }
.upload-card { display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap; }
.upload-form { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.upload-form input[type="file"] { width:auto; max-width:260px; padding:7px; }
.modal { display:none; position:fixed; inset:0; z-index:1100; background:rgba(17,24,39,.42); padding:30px 18px; overflow:auto; }
.modal.open { display:flex; align-items:flex-start; justify-content:center; }
.modal-card { width:min(560px, 100%); margin:30px auto; background:#fff; border-radius:10px; padding:22px; box-shadow:0 16px 40px rgba(17,24,39,.2); }
.modal-head { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:12px; }
.modal-head h3 { margin:0; font-size:16px; }
.close-modal { background:#f3f4f6; color:#6b7280; padding:7px 10px; }
.delete-copy { margin:0 0 18px; color:#6b7280; font-size:13px; line-height:1.5; }
.delete-copy strong { color:#111827; }
.alert { padding: 11px 14px; border-radius: 7px; margin-bottom: 18px; font-size: 13px; }
.alert.success { background: #ecfdf5; color: #047857; }
.alert.error { background: #fef2f2; color: #b91c1c; }
@media (max-width: 1000px) { .main { margin-left: 0; padding: 18px; } .management-grid { grid-template-columns:1fr; } .category-nav { border-right:0; border-bottom:1px solid #eef0f3; padding:0 0 12px; display:flex; gap:6px; overflow:auto; } .category-nav a { white-space:nowrap; } }
</style>
</head>
<body>
<?php if (!$embedded): ?>
<?php include 'admin_topbar.php'; ?>
<?php include 'admin_sidebar.php'; ?>
<?php endif; ?>
<main class="main">
    <div class="page-header">
        <div><h2>FAQ Management</h2><p>Create and manage the categories, questions, and answers students see.</p></div>
    </div>
    <?php if ($message !== '' && in_array($status, ['success', 'error'], true)): ?>
        <div class="alert <?php echo e($status); ?>"><?php echo e($message); ?></div>
    <?php endif; ?>

    <div class="card upload-card">
        <div><h3>Bulk Upload FAQs</h3><p class="section-head" style="margin:4px 0 0;color:#9ca3af;font-size:11px;">CSV columns: category, question, answer. Existing matching questions are updated.</p></div>
        <form class="upload-form" method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo e((string)$_SESSION['csrf_token']); ?>">
            <input type="hidden" name="action" value="bulk_upload">
            <input type="file" name="faq_csv" accept=".csv,text/csv" required>
            <button type="submit"><i class="bx bx-upload"></i> Upload CSV</button>
        </form>
    </div>

    <div class="card">
        <div class="section-head">
            <div><h3>FAQ Categories</h3><p>Manage the categories shown to students.</p></div>
            <button type="button" data-open-modal="categoryModal"><i class="bx bx-plus"></i> Add Category</button>
        </div>
        <div class="table-wrap">
            <table class="overview-table">
                <thead><tr><th>Category Name</th><th>Questions</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                <?php if (!$categories): ?><tr><td colspan="4" class="empty">No FAQ categories yet.</td></tr><?php endif; ?>
                <?php foreach ($categories as $index => $category): ?>
                    <tr class="<?php echo (int)$category['id'] === $selectedCategoryId ? 'selected' : ''; ?>">
                        <td><a class="category-link" href="admin_faq.php?category=<?php echo (int)$category['id']; ?>"><span class="category-icon"><i class="bx <?php echo ['bx-book-open', 'bx-bulb', 'bx-user', 'bx-bell'][$index % 4]; ?>"></i></span><?php echo e((string)$category['name']); ?></a></td>
                        <td><span class="badge"><?php echo (int)($categoryQuestionCounts[(int)$category['id']] ?? 0); ?></span></td>
                        <td><span class="status <?php echo (int)$category['is_active'] === 1 ? '' : 'off'; ?>"><?php echo (int)$category['is_active'] === 1 ? 'Active' : 'Inactive'; ?></span></td>
                        <td><div class="actions"><a href="admin_faq.php?edit_category=<?php echo (int)$category['id']; ?>"><button class="icon-btn" type="button" title="Edit category"><i class="bx bx-edit"></i></button></a><button class="icon-btn warning" type="button" title="Delete category" data-delete-category="<?php echo (int)$category['id']; ?>" data-category-name="<?php echo e((string)$category['name']); ?>"><i class="bx bx-trash"></i></button></div></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="section-head">
            <div><h3>Questions in: <?php echo e((string)($selectedCategory['name'] ?? 'No category')); ?> <span class="badge"><?php echo count($selectedQuestions); ?></span></h3><p>Manage the predefined questions and answers for this category.</p></div>
            <button type="button" data-open-modal="questionModal"><i class="bx bx-plus"></i> Add Question</button>
        </div>
        <div class="management-grid">
            <nav class="category-nav" aria-label="FAQ categories">
                <?php foreach ($categories as $category): ?>
                    <a class="<?php echo (int)$category['id'] === $selectedCategoryId ? 'active' : ''; ?>" href="admin_faq.php?category=<?php echo (int)$category['id']; ?>"><?php echo e((string)$category['name']); ?><span class="nav-count"><?php echo (int)($categoryQuestionCounts[(int)$category['id']] ?? 0); ?></span></a>
                <?php endforeach; ?>
            </nav>
            <div class="table-wrap">
                <table class="questions-table">
                    <thead><tr><th>Question</th><th>Answer Preview</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php if (!$selectedQuestions): ?><tr><td colspan="4" class="empty">No questions in this category yet.</td></tr><?php endif; ?>
                    <?php foreach ($selectedQuestions as $question): ?>
                        <tr><td><?php echo e((string)$question['question']); ?></td><td class="answer-cell"><?php echo e((string)$question['answer']); ?></td><td><span class="status <?php echo (int)$question['is_active'] === 1 ? '' : 'off'; ?>"><?php echo (int)$question['is_active'] === 1 ? 'Active' : 'Inactive'; ?></span></td><td><div class="actions"><a href="admin_faq.php?category=<?php echo $selectedCategoryId; ?>&edit_question=<?php echo (int)$question['id']; ?>"><button class="icon-btn" type="button" title="Edit question"><i class="bx bx-edit"></i></button></a><form method="post"><input type="hidden" name="csrf_token" value="<?php echo e((string)$_SESSION['csrf_token']); ?>"><input type="hidden" name="action" value="toggle_question"><input type="hidden" name="id" value="<?php echo (int)$question['id']; ?>"><button class="icon-btn warning" type="submit" title="Toggle question"><i class="bx bx-trash"></i></button></form></div></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="modal" id="categoryModal" role="dialog" aria-modal="true" aria-labelledby="categoryModalTitle">
        <div class="modal-card">
            <div class="modal-head"><h3 id="categoryModalTitle"><?php echo $editCategory ? 'Edit Category' : 'Add Category'; ?></h3><button class="close-modal" type="button" data-close-modal>&times;</button></div>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo e((string)$_SESSION['csrf_token']); ?>">
                <input type="hidden" name="action" value="save_category">
                <?php if ($editCategory): ?><input type="hidden" name="id" value="<?php echo (int)$editCategory['id']; ?>"><?php endif; ?>
                <label for="modalCategoryName">Category name</label>
                <input id="modalCategoryName" name="name" maxlength="150" value="<?php echo e((string)($editCategory['name'] ?? '')); ?>" required>
                <div class="form-actions"><button type="submit"><?php echo $editCategory ? 'Save Category' : 'Add Category'; ?></button></div>
            </form>
        </div>
    </div>

    <div class="modal" id="questionModal" role="dialog" aria-modal="true" aria-labelledby="questionModalTitle">
        <div class="modal-card">
            <div class="modal-head"><h3 id="questionModalTitle"><?php echo $editQuestion ? 'Edit Question' : 'Add Question'; ?></h3><button class="close-modal" type="button" data-close-modal>&times;</button></div>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo e((string)$_SESSION['csrf_token']); ?>">
                <input type="hidden" name="action" value="save_question">
                <?php if ($editQuestion): ?><input type="hidden" name="id" value="<?php echo (int)$editQuestion['id']; ?>"><?php endif; ?>
                <label for="modalQuestionCategory">Category</label>
                <select id="modalQuestionCategory" name="category_id" required>
                    <option value="">Select a category</option>
                    <?php foreach ($categories as $category): ?><option value="<?php echo (int)$category['id']; ?>" <?php echo $editQuestion && (int)$editQuestion['category_id'] === (int)$category['id'] ? 'selected' : ''; ?>><?php echo e((string)$category['name']); ?></option><?php endforeach; ?>
                </select>
                <label for="modalQuestionText">Question</label>
                <input id="modalQuestionText" name="question" maxlength="255" value="<?php echo e((string)($editQuestion['question'] ?? '')); ?>" required>
                <label for="modalAnswerText">Answer</label>
                <textarea id="modalAnswerText" name="answer" required><?php echo e((string)($editQuestion['answer'] ?? '')); ?></textarea>
                <div class="form-actions"><button type="submit"><?php echo $editQuestion ? 'Save Question' : 'Add Question'; ?></button></div>
            </form>
        </div>
    </div>

    <div class="modal" id="deleteCategoryModal" role="dialog" aria-modal="true" aria-labelledby="deleteCategoryTitle">
        <div class="modal-card">
            <div class="modal-head"><h3 id="deleteCategoryTitle">Delete Category?</h3><button class="close-modal" type="button" data-close-modal>&times;</button></div>
            <p class="delete-copy">Are you sure you want to delete <strong id="deleteCategoryName"></strong>? This will also permanently delete all questions inside this category.</p>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo e((string)$_SESSION['csrf_token']); ?>">
                <input type="hidden" name="action" value="delete_category">
                <input type="hidden" name="id" id="deleteCategoryId" value="">
                <div class="form-actions"><button class="warning" type="submit">Delete Category</button><button class="secondary" type="button" data-close-modal>Cancel</button></div>
            </form>
        </div>
    </div>

    <div class="grid">
        <section>
            <div class="card" id="category-form">
                <h3><?php echo $editCategory ? 'Edit Category' : 'Add Category'; ?></h3>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo e((string)$_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="action" value="save_category">
                    <?php if ($editCategory): ?><input type="hidden" name="id" value="<?php echo (int)$editCategory['id']; ?>"><?php endif; ?>
                    <label for="categoryName">Category name</label>
                    <input id="categoryName" name="name" maxlength="150" value="<?php echo e((string)($editCategory['name'] ?? '')); ?>" required>
                    <div class="form-actions"><button type="submit"><?php echo $editCategory ? 'Save Category' : 'Add Category'; ?></button><?php if ($editCategory): ?><a href="admin_faq.php"><button class="secondary" type="button">Cancel</button></a><?php endif; ?></div>
                </form>
            </div>
            <div class="card" id="question-form">
                <h3>Categories</h3>
                <?php if (!$categories): ?><p class="meta">No FAQ categories yet.</p><?php endif; ?>
                <?php foreach ($categories as $category): ?>
                    <div class="item">
                        <div class="item-title">
                            <h4><?php echo e((string)$category['name']); ?></h4>
                            <span class="status <?php echo (int)$category['is_active'] === 1 ? '' : 'off'; ?>"><?php echo (int)$category['is_active'] === 1 ? 'Active' : 'Inactive'; ?></span>
                        </div>
                        <div class="actions">
                            <a href="admin_faq.php?edit_category=<?php echo (int)$category['id']; ?>"><button class="secondary" type="button">Edit</button></a>
                            <form method="post"><input type="hidden" name="csrf_token" value="<?php echo e((string)$_SESSION['csrf_token']); ?>"><input type="hidden" name="action" value="toggle_category"><input type="hidden" name="id" value="<?php echo (int)$category['id']; ?>"><button class="warning" type="submit"><?php echo (int)$category['is_active'] === 1 ? 'Deactivate' : 'Activate'; ?></button></form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section>
            <div class="card">
                <h3><?php echo $editQuestion ? 'Edit Question' : 'Add Question'; ?></h3>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo e((string)$_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="action" value="save_question">
                    <?php if ($editQuestion): ?><input type="hidden" name="id" value="<?php echo (int)$editQuestion['id']; ?>"><?php endif; ?>
                    <label for="questionCategory">Category</label>
                    <select id="questionCategory" name="category_id" required>
                        <option value="">Select a category</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo (int)$category['id']; ?>" <?php echo $editQuestion && (int)$editQuestion['category_id'] === (int)$category['id'] ? 'selected' : ''; ?>><?php echo e((string)$category['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label for="questionText">Question</label>
                    <input id="questionText" name="question" maxlength="255" value="<?php echo e((string)($editQuestion['question'] ?? '')); ?>" required>
                    <label for="answerText">Answer</label>
                    <textarea id="answerText" name="answer" required><?php echo e((string)($editQuestion['answer'] ?? '')); ?></textarea>
                    <div class="form-actions"><button type="submit"><?php echo $editQuestion ? 'Save Question' : 'Add Question'; ?></button><?php if ($editQuestion): ?><a href="admin_faq.php"><button class="secondary" type="button">Cancel</button></a><?php endif; ?></div>
                </form>
            </div>
            <div class="card">
                <h3>Questions</h3>
                <?php if (!$questions): ?><p class="meta">No FAQ questions yet.</p><?php endif; ?>
                <?php foreach ($questions as $question): ?>
                    <div class="item">
                        <div class="item-title">
                            <h4><?php echo e((string)$question['question']); ?></h4>
                            <span class="status <?php echo (int)$question['is_active'] === 1 ? '' : 'off'; ?>"><?php echo (int)$question['is_active'] === 1 ? 'Active' : 'Inactive'; ?></span>
                        </div>
                        <p><?php echo e((string)$question['answer']); ?></p>
                        <div class="actions">
                            <a href="admin_faq.php?edit_question=<?php echo (int)$question['id']; ?>"><button class="secondary" type="button">Edit</button></a>
                            <form method="post"><input type="hidden" name="csrf_token" value="<?php echo e((string)$_SESSION['csrf_token']); ?>"><input type="hidden" name="action" value="toggle_question"><input type="hidden" name="id" value="<?php echo (int)$question['id']; ?>"><button class="warning" type="submit"><?php echo (int)$question['is_active'] === 1 ? 'Deactivate' : 'Activate'; ?></button></form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    </div>
</main>
<script>
document.querySelectorAll('[data-open-modal]').forEach(function (button) {
    button.addEventListener('click', function () {
        document.getElementById(button.dataset.openModal).classList.add('open');
    });
});
document.querySelectorAll('[data-close-modal]').forEach(function (button) {
    button.addEventListener('click', function () {
        button.closest('.modal').classList.remove('open');
    });
});
document.querySelectorAll('.modal').forEach(function (modal) {
    modal.addEventListener('click', function (event) {
        if (event.target === modal) modal.classList.remove('open');
    });
});
<?php if ($embedded): ?>
document.querySelectorAll('a[href]').forEach(function (link) {
    const url = new URL(link.href, window.location.href);
    if (url.pathname.endsWith('/admin_faq.php')) {
        url.searchParams.set('embedded', '1');
        link.href = url.toString();
    }
});
document.querySelectorAll('form').forEach(function (form) {
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'embedded';
    input.value = '1';
    form.appendChild(input);
});
<?php endif; ?>
document.querySelectorAll('[data-delete-category]').forEach(function (button) {
    button.addEventListener('click', function () {
        document.getElementById('deleteCategoryId').value = button.dataset.deleteCategory;
        document.getElementById('deleteCategoryName').textContent = button.dataset.categoryName;
        document.getElementById('deleteCategoryModal').classList.add('open');
    });
});
<?php if ($editCategory): ?>document.getElementById('categoryModal').classList.add('open');<?php endif; ?>
<?php if ($editQuestion): ?>document.getElementById('questionModal').classList.add('open');<?php endif; ?>
</script>
</body>
</html>
