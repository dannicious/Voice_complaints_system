<?php
session_start();
require_once __DIR__ . '/../db_connection.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../student/login.php');
    exit;
}

$categories = [];
$questions = [];
try {
    $categories = $pdo->query('SELECT id, name FROM faq_categories WHERE is_active = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
    $questions = $pdo->query('SELECT id, category_id, question, answer FROM faq_questions WHERE is_active = 1 ORDER BY question')->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $categories = [];
    $questions = [];
}

$current = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>VOICE - FAQ</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
<style>
* { box-sizing: border-box; font-family: 'Poppins', sans-serif; }
body { margin: 0; background: #f8fafc; color: #1f2937; }
.main { margin-left: 260px; margin-top: 61px; padding: 28px; min-height: calc(100vh - 61px); }
.page-header { margin-bottom: 22px; }
.page-header h2 { margin: 0 0 5px; font-size: 22px; color: #111827; }
.page-header p { margin: 0; color: #6b7280; font-size: 12px; }
.faq-heading { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 12px; }
.faq-heading h3 { margin: 0; font-size: 13px; color: #111827; }
.faq-heading span { color: #9ca3af; font-size: 11px; }
.category-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(145px, 1fr)); gap: 12px; margin-bottom: 18px; }
.category-card { min-height: 112px; padding: 15px; border: 1px solid #e5e7eb; border-radius: 9px; background: #fff; cursor: pointer; text-align: left; transition: border-color .2s, box-shadow .2s, transform .2s; }
.category-card:hover { transform: translateY(-2px); box-shadow: 0 5px 16px rgba(31,41,55,.08); }
.category-card.active { border-color: #8b5cf6; box-shadow: 0 0 0 2px rgba(139,92,246,.12); }
.category-icon { width: 32px; height: 32px; border-radius: 9px; display: grid; place-items: center; margin-bottom: 10px; font-size: 17px; }
.category-card:nth-child(4n+1) .category-icon { background: #ede9fe; color: #7c3aed; }
.category-card:nth-child(4n+2) .category-icon { background: #dcfce7; color: #16a34a; }
.category-card:nth-child(4n+3) .category-icon { background: #dbeafe; color: #2563eb; }
.category-card:nth-child(4n) .category-icon { background: #fef3c7; color: #d97706; }
.category-name { display: block; color: #111827; font-size: 12px; font-weight: 600; }
.category-count { display: block; margin-top: 4px; color: #9ca3af; font-size: 10px; }
.faq-workspace { display: grid; grid-template-columns: minmax(210px, .72fr) minmax(360px, 1.5fr); min-height: 360px; border: 1px solid #e5e7eb; border-radius: 9px; overflow: hidden; background: #fff; }
.question-pane { border-right: 1px solid #eef0f3; padding: 18px 12px; background: #fbfbfd; }
.question-pane h3 { padding: 0 8px; margin: 0 0 12px; font-size: 13px; color: #111827; }
.question-list { display: grid; gap: 4px; }
.question-button { width: 100%; padding: 10px 9px; border: 0; border-radius: 6px; background: transparent; color: #4b5563; cursor: pointer; text-align: left; font-size: 11px; line-height: 1.4; }
.question-button:hover, .question-button.active { background: #ede9fe; color: #5b21b6; }
.answer-pane { padding: 24px 28px; }
.answer-kicker { color: #7c3aed; font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; }
.answer-pane h3 { margin: 8px 0 14px; color: #111827; font-size: 18px; line-height: 1.35; }
.answer { color: #4b5563; font-size: 13px; line-height: 1.7; white-space: pre-wrap; }
.answer.empty, .no-faq { color: #9ca3af; }
.no-faq { padding: 24px; border: 1px solid #e5e7eb; border-radius: 9px; background: #fff; font-size: 13px; }
@media (max-width: 1024px) { .main { margin-left: 0; padding: 20px; } }
@media (max-width: 700px) { .faq-workspace { grid-template-columns: 1fr; } .question-pane { border-right: 0; border-bottom: 1px solid #eef0f3; } .question-list { grid-template-columns: repeat(2, 1fr); } .answer-pane { padding: 20px; } }
</style>
</head>
<body>
<?php include 'student_topbar.php'; ?>
<?php include 'student_sidebar.php'; ?>

<main class="main">
    <div class="page-header">
        <h2>Help Center / FAQ</h2>
        <p>Select a category and a question to view the answer.</p>
    </div>

    <?php if (!$categories || !$questions): ?>
        <div class="no-faq">No FAQs are available yet.</div>
    <?php else: ?>
        <div class="faq-heading"><h3>FAQ Categories</h3><span>Select a category below</span></div>
        <section class="category-grid" aria-label="FAQ categories">
            <?php foreach ($categories as $index => $category): ?>
                <button class="category-card" type="button" data-category-id="<?php echo (int)$category['id']; ?>">
                    <span class="category-icon"><i class="bx <?php echo ['bx-book-open', 'bx-bulb', 'bx-user', 'bx-bell'][$index % 4]; ?>"></i></span>
                    <span class="category-name"><?php echo htmlspecialchars((string)$category['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="category-count"><span data-question-count="<?php echo (int)$category['id']; ?>">0</span> questions</span>
                </button>
            <?php endforeach; ?>
        </section>

        <section class="faq-workspace" aria-label="FAQ questions and answer">
            <aside class="question-pane">
                <h3 id="selectedCategoryName">Questions</h3>
                <div class="question-list" id="questionList"></div>
            </aside>
            <article class="answer-pane">
                <div class="answer-kicker" id="answerCategory">FAQ Answer</div>
                <h3 id="answerQuestion">Select a question</h3>
                <div id="faqAnswer" class="answer empty">Choose a category and question to view the answer.</div>
            </article>
        </section>
    <?php endif; ?>
</main>

<?php if ($categories && $questions): ?>
<script>
const faqQuestions = <?php echo json_encode($questions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const faqCategories = <?php echo json_encode($categories, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const categoryCards = document.querySelectorAll('.category-card');
const questionList = document.getElementById('questionList');
const selectedCategoryName = document.getElementById('selectedCategoryName');
const answerCategory = document.getElementById('answerCategory');
const answerQuestion = document.getElementById('answerQuestion');
const answer = document.getElementById('faqAnswer');

function selectQuestion(item, button) {
    document.querySelectorAll('.question-button').forEach(function (element) { element.classList.remove('active'); });
    if (button) button.classList.add('active');
    answerQuestion.textContent = item.question;
    answer.textContent = item.answer;
    answer.classList.remove('empty');
}

function selectCategory(categoryId) {
    const category = faqCategories.find(function (item) { return String(item.id) === String(categoryId); });
    const available = faqQuestions.filter(function (item) { return String(item.category_id) === String(categoryId); });
    categoryCards.forEach(function (card) { card.classList.toggle('active', card.dataset.categoryId === String(categoryId)); });
    selectedCategoryName.textContent = category ? category.name : 'Questions';
    answerCategory.textContent = category ? category.name : 'FAQ Answer';
    questionList.innerHTML = '';
    available.forEach(function (item, index) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'question-button';
        button.textContent = item.question;
        button.addEventListener('click', function () { selectQuestion(item, button); });
        questionList.appendChild(button);
        if (index === 0) selectQuestion(item, button);
    });
    if (!available.length) {
        answerQuestion.textContent = 'No questions available';
        answer.textContent = 'This category has no active questions yet.';
        answer.classList.add('empty');
    }
}

faqCategories.forEach(function (category) {
    const count = faqQuestions.filter(function (item) { return String(item.category_id) === String(category.id); }).length;
    const countElement = document.querySelector('[data-question-count="' + category.id + '"]');
    if (countElement) countElement.textContent = count;
});
categoryCards.forEach(function (card) {
    card.addEventListener('click', function () { selectCategory(card.dataset.categoryId); });
});
selectCategory(String(faqCategories[0].id));
</script>
<?php endif; ?>
</body>
</html>
