<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/admin_functions.php';

if (!isAdmin()) {
    header('Location: /login/login.php');
    exit;
}

$db = new Database();
$pdo = $db->getConnection();
$user_id = $_SESSION['user']['id'];

// Получаем параметры
$action = $_GET['action'] ?? 'create';
$doc_id = $_GET['id'] ?? 0;
$category_id = $_GET['category'] ?? null;

// Функция для генерации slug
function generateSlug($string) {
    $string = transliterator_transliterate('Any-Latin; Latin-ASCII; [^A-Za-z0-9] remove; Lower()', $string);
    $string = preg_replace('/[^a-z0-9]+/', '-', $string);
    $string = trim($string, '-');
    
    // Если slug пустой после генерации
    if (empty($string)) {
        $string = 'article-' . time();
    }
    
    return $string;
}

// Функция для получения уникального slug
function getUniqueSlug($pdo, $slug, $exclude_id = 0) {
    $original_slug = $slug;
    $counter = 1;
    
    while (true) {
        $stmt = $pdo->prepare("SELECT id FROM docs WHERE slug = ? AND id != ?");
        $stmt->execute([$slug, $exclude_id]);
        
        if (!$stmt->fetch()) {
            return $slug;
        }
        
        $slug = $original_slug . '-' . $counter;
        $counter++;
        
        // Защита от бесконечного цикла
        if ($counter > 100) {
            return $original_slug . '-' . time();
        }
    }
}

// Функции
function getDocById($pdo, $id) {
    $stmt = $pdo->prepare("
        SELECT d.*,
               GROUP_CONCAT(t.id) as tag_ids,
               GROUP_CONCAT(t.name) as tag_names
        FROM docs d
        LEFT JOIN doc_tag_relations r ON d.id = r.doc_id
        LEFT JOIN doc_tags t ON r.tag_id = t.id
        WHERE d.id = ?
        GROUP BY d.id
    ");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getAllTags($pdo) {
    $stmt = $pdo->query("SELECT * FROM doc_tags ORDER BY name");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getCategoriesTree($pdo, $parent_id = null, $level = 0) {
    $query = "SELECT * FROM doc_categories WHERE is_active = 1";
    $params = [];

    if ($parent_id === null) {
        $query .= " AND parent_id IS NULL";
    } else {
        $query .= " AND parent_id = ?";
        $params[] = $parent_id;
    }

    $query .= " ORDER BY sort_order, name";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = [];
    foreach ($categories as $category) {
        $category['level'] = $level;
        $result[] = $category;

        $children = getCategoriesTree($pdo, $category['id'], $level + 1);
        $result = array_merge($result, $children);
    }

    return $result;
}

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    $content = $_POST['content'] ?? '';
    $excerpt = trim($_POST['excerpt'] ?? '');
    $category_id = $_POST['category_id'] ?? null;
    $status = $_POST['status'] ?? 'draft';
    $is_featured = isset($_POST['is_featured']) ? 1 : 0;
    $is_pinned = isset($_POST['is_pinned']) ? 1 : 0;
    $allow_comments = isset($_POST['allow_comments']) ? 1 : 0;
    $meta_keywords = trim($_POST['meta_keywords'] ?? '');
    $meta_description = trim($_POST['meta_description'] ?? '');
    $tag_ids = $_POST['tags'] ?? [];

    // Валидация
    if (empty($title) || empty($content)) {
        $message = 'Заполните обязательные поля: заголовок и содержание';
        $message_type = 'error';
    } else {
        try {
            // Генерация slug если он пустой или содержит только пробелы
            if (empty($slug)) {
                $slug = generateSlug($title);
            } else {
                // Очистка slug
                $slug = generateSlug($slug);
            }
            
            // Получаем уникальный slug
            $slug = getUniqueSlug($pdo, $slug, $doc_id);

            if ($action === 'edit' && $doc_id > 0) {
                // Обновление существующей статьи
                $stmt = $pdo->prepare("
                    UPDATE docs SET
                    title = ?,
                    slug = ?,
                    content = ?,
                    excerpt = ?,
                    category_id = ?,
                    status = ?,
                    is_featured = ?,
                    is_pinned = ?,
                    allow_comments = ?,
                    meta_keywords = ?,
                    meta_description = ?,
                    editor_id = ?,
                    version = version + 1,
                    updated_at = NOW()
                    WHERE id = ?
                ");

                $stmt->execute([
                    $title, $slug, $content, $excerpt, $category_id, $status,
                    $is_featured, $is_pinned, $allow_comments,
                    $meta_keywords, $meta_description, $user_id, $doc_id
                ]);

                // Сохраняем версию
                $stmt = $pdo->prepare("
                    INSERT INTO doc_versions (doc_id, version, title, content, excerpt, author_id, change_reason)
                    SELECT id, version, title, content, excerpt, editor_id, 'Редактирование'
                    FROM docs WHERE id = ?
                ");
                $stmt->execute([$doc_id]);

                $message = 'Статья обновлена';
            } else {
                // Создание новой статьи
                $stmt = $pdo->prepare("
                    INSERT INTO docs (
                        title, slug, content, excerpt, category_id,
                        author_id, status, is_featured, is_pinned,
                        allow_comments, meta_keywords, meta_description,
                        created_at, published_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(),
                        CASE WHEN ? = 'published' THEN NOW() ELSE NULL END)
                ");

                $stmt->execute([
                    $title, $slug, $content, $excerpt, $category_id,
                    $user_id, $status, $is_featured, $is_pinned,
                    $allow_comments, $meta_keywords, $meta_description,
                    $status
                ]);

                $doc_id = $pdo->lastInsertId();
                $message = 'Статья создана';
            }

            // Обновляем теги
            if ($doc_id) {
                // Удаляем старые теги
                $stmt = $pdo->prepare("DELETE FROM doc_tag_relations WHERE doc_id = ?");
                $stmt->execute([$doc_id]);

                // Добавляем новые теги
                if (!empty($tag_ids)) {
                    $stmt = $pdo->prepare("INSERT INTO doc_tag_relations (doc_id, tag_id) VALUES (?, ?)");
                    foreach ($tag_ids as $tag_id) {
                        $stmt->execute([$doc_id, $tag_id]);
                    }
                }
            }

            $message_type = 'success';

            // Редирект на просмотр статьи
            header("Location: /docs/docs.php?action=view&doc=" . urlencode($slug));
            exit;

        } catch (Exception $e) {
            $message = 'Ошибка сохранения: ' . $e->getMessage();
            $message_type = 'error';
            // Для отладки можно добавить:
            error_log('Ошибка при сохранении статьи: ' . $e->getMessage());
            error_log('Slug: ' . $slug);
            error_log('Title: ' . $title);
        }
    }
}

// Получаем данные для формы
if ($action === 'edit' && $doc_id > 0) {
    $doc = getDocById($pdo, $doc_id);
    if (!$doc) {
        header('Location: /admin/docs_editor.php');
        exit;
    }
} else {
    $doc = [
        'title' => '',
        'slug' => '',
        'content' => '',
        'excerpt' => '',
        'category_id' => $category_id,
        'status' => 'draft',
        'is_featured' => 0,
        'is_pinned' => 0,
        'allow_comments' => 1,
        'meta_keywords' => '',
        'meta_description' => ''
    ];
}

$categories = getCategoriesTree($pdo);
$all_tags = getAllTags($pdo);
$selected_tag_ids = $doc['tag_ids'] ? explode(',', $doc['tag_ids']) : [];

$title = ($action === 'edit' ? 'Редактирование' : 'Создание') . " статьи | Админ панель | HomeVlad Cloud";
require 'admin_header.php';
?>

<style>
.editor-wrapper {
    padding: 20px;
    background: var(--db-bg);
    min-height: calc(100vh - 70px);
    margin-left: 280px;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.admin-sidebar.compact + .editor-wrapper {
    margin-left: 70px;
}

.editor-header {
    margin-bottom: 30px;
    padding: 24px;
    background: var(--db-card-bg);
    border-radius: 12px;
    border: 1px solid var(--db-border);
    box-shadow: var(--db-shadow);
}

.editor-header h1 {
    color: var(--db-text);
    font-size: 24px;
    margin: 0 0 8px 0;
    display: flex;
    align-items: center;
    gap: 12px;
}

.editor-header h1 i {
    color: var(--db-accent);
}

.editor-header p {
    color: var(--db-text-secondary);
    font-size: 14px;
    margin: 0;
}

.editor-form {
    background: var(--db-card-bg);
    border-radius: 12px;
    border: 1px solid var(--db-border);
    padding: 30px;
    box-shadow: var(--db-shadow);
}

.form-grid {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 30px;
}

@media (max-width: 1200px) {
    .form-grid {
        grid-template-columns: 1fr;
    }
}

.form-main {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.form-sidebar {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.form-group {
    margin-bottom: 20px;
}

.form-label {
    display: block;
    margin-bottom: 8px;
    color: var(--db-text);
    font-weight: 500;
    font-size: 14px;
}

.form-label.required::after {
    content: ' *';
    color: var(--db-danger);
}

.form-input,
.form-select,
.form-textarea {
    width: 87%;
    padding: 12px 16px;
    border: 1px solid var(--db-border);
    border-radius: 8px;
    background: var(--db-card-bg);
    color: var(--db-text);
    font-size: 14px;
    transition: all 0.3s ease;
}

.form-input:focus,
.form-select:focus,
.form-textarea:focus {
    outline: none;
    border-color: var(--db-accent);
    box-shadow: 0 0 0 3px var(--db-accent-light);
}

.form-textarea {
    min-height: 150px;
    resize: vertical;
    font-family: inherit;
}

#contentEditor {
    min-height: 400px;
}

/* WYSIWYG редактор */
.editor-toolbar {
    background: var(--db-hover);
    border: 1px solid var(--db-border);
    border-bottom: none;
    border-radius: 8px 8px 0 0;
    padding: 10px;
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
}

.editor-btn {
    width: 36px;
    height: 36px;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--db-card-bg);
    border: 1px solid var(--db-border);
    color: var(--db-text);
    cursor: pointer;
    transition: all 0.3s ease;
}

.editor-btn:hover {
    background: var(--db-hover);
    border-color: var(--db-accent);
    color: var(--db-accent);
}

.editor-content {
    min-height: 400px;
    border: 1px solid var(--db-border);
    border-radius: 0 0 8px 8px;
    padding: 16px;
    background: var(--db-card-bg);
    color: var(--db-text);
    font-size: 14px;
    line-height: 1.6;
    overflow-y: auto;
}

.editor-content:focus {
    outline: none;
    border-color: var(--db-accent);
}

.editor-content h1,
.editor-content h2,
.editor-content h3 {
    margin-top: 20px;
    margin-bottom: 10px;
    font-weight: 600;
    line-height: 1.25;
}

.editor-content h1 { font-size: 2em; }
.editor-content h2 { font-size: 1.5em; }
.editor-content h3 { font-size: 1.25em; }

.editor-content p {
    margin-bottom: 16px;
}

.editor-content ul,
.editor-content ol {
    margin-bottom: 16px;
    padding-left: 2em;
}

.editor-content code {
    background: var(--db-hover);
    padding: 2px 6px;
    border-radius: 4px;
    font-family: 'Courier New', monospace;
    font-size: 14px;
}

.editor-content pre {
    background: var(--db-bg);
    border: 1px solid var(--db-border);
    border-radius: 8px;
    padding: 16px;
    overflow-x: auto;
}

.editor-content blockquote {
    border-left: 4px solid var(--db-accent);
    padding-left: 16px;
    margin-left: 0;
    color: var(--db-text-secondary);
    font-style: italic;
}

/* Чекбоксы и радиокнопки */
.checkbox-group {
    display: flex;
    gap: 20px;
    flex-wrap: wrap;
}

.checkbox-item {
    display: flex;
    align-items: center;
    gap: 8px;
}

.checkbox-item input[type="checkbox"] {
    width: 18px;
    height: 18px;
    border-radius: 4px;
    border: 1px solid var(--db-border);
    background: var(--db-card-bg);
    cursor: pointer;
}

.checkbox-item label {
    color: var(--db-text);
    font-size: 14px;
    cursor: pointer;
}

.radio-group {
    display: flex;
    gap: 20px;
}

.radio-item {
    display: flex;
    align-items: center;
    gap: 8px;
}

.radio-item input[type="radio"] {
    width: 18px;
    height: 18px;
    border-radius: 50%;
    border: 1px solid var(--db-border);
    background: var(--db-card-bg);
    cursor: pointer;
}

.radio-item label {
    color: var(--db-text);
    font-size: 14px;
    cursor: pointer;
}

/* Теги */
.tags-container {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 10px;
}

.tag-item {
    display: flex;
    align-items: center;
    gap: 5px;
    padding: 6px 12px;
    background: var(--db-hover);
    border: 1px solid var(--db-border);
    border-radius: 20px;
    font-size: 14px;
}

.tag-item input[type="checkbox"] {
    margin: 0;
}

.tag-item label {
    margin: 0;
    cursor: pointer;
}

/* Действия формы */
.form-actions {
    display: flex;
    gap: 12px;
    justify-content: flex-end;
    margin-top: 30px;
    padding-top: 20px;
    border-top: 1px solid var(--db-border);
}

.btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 24px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 500;
    border: none;
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
}

.btn-primary {
    background: linear-gradient(135deg, var(--db-accent), #0097a7);
    color: var(--db-text);
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: var(--db-shadow-hover);
}

.btn-secondary {
    background: var(--db-card-bg);
    color: var(--db-text);
    border: 1px solid var(--db-border);
}

.btn-secondary:hover {
    background: var(--db-hover);
    border-color: var(--db-accent);
}

.btn-danger {
    background: linear-gradient(135deg, var(--db-danger), #dc2626);
    color: white;
}

/* Превью slug */
.slug-preview {
    margin-top: 5px;
    font-size: 12px;
    color: var(--db-text-muted);
}

.slug-preview a {
    color: var(--db-accent);
    text-decoration: none;
}

/* Счетчик символов */
.char-counter {
    text-align: right;
    font-size: 12px;
    color: var(--db-text-muted);
    margin-top: 5px;
}

.char-counter.warning {
    color: var(--db-warning);
}

.char-counter.error {
    color: var(--db-danger);
}

/* Адаптивность */
@media (max-width: 1200px) {
    .editor-wrapper {
        margin-left: 70px !important;
    }
}

@media (max-width: 768px) {
    .editor-wrapper {
        margin-left: 0 !important;
        padding: 15px;
    }

    .editor-form {
        padding: 20px;
    }

    .form-actions {
        flex-direction: column;
    }

    .btn {
        width: 100%;
        justify-content: center;
    }
}
</style>

<?php require 'admin_sidebar.php'; ?>

<div class="editor-wrapper">
    <div class="editor-header">
        <h1>
            <i class="fas fa-<?= $action === 'edit' ? 'edit' : 'plus-circle' ?>"></i>
            <?= $action === 'edit' ? 'Редактирование статьи' : 'Создание новой статьи' ?>
        </h1>
        <p><?= $action === 'edit' ? 'Измените содержание статьи ниже' : 'Заполните форму для создания новой статьи' ?></p>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?= $message_type === 'error' ? 'error' : 'success' ?>">
            <i class="fas fa-<?= $message_type === 'error' ? 'exclamation-circle' : 'check-circle' ?>"></i>
            <div><?= htmlspecialchars($message) ?></div>
        </div>
    <?php endif; ?>

    <form method="POST" class="editor-form" id="docForm">
        <div class="form-grid">
            <div class="form-main">
                <div class="form-group">
                    <label class="form-label required">Заголовок статьи</label>
                    <input type="text" name="title" class="form-input"
                           value="<?= htmlspecialchars($doc['title']) ?>"
                           required
                           oninput="updateSlug(this.value)">
                </div>

                <div class="form-group">
                    <label class="form-label">URL статьи (если оставить пустым, будет сгенерирован автоматически)</label>
                    <input type="text" name="slug" class="form-input"
                           value="<?= htmlspecialchars($doc['slug']) ?>"
                           pattern="[a-z0-9\-]+"
                           title="Только латинские буквы в нижнем регистре, цифры и дефисы">
                    <div class="slug-preview">
                        Ссылка будет: <span id="slugPreview"></span>
                    </div>
                    <div style="font-size: 12px; color: #666; margin-top: 5px;">
                        Только латинские буквы, цифры и дефисы. Например: "moja-statja" или "article-about-something"
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label required">Содержание статьи</label>
                    <div id="contentEditor" class="editor-content" contenteditable="true">
                        <?= $doc['content'] ?>
                    </div>
                    <textarea name="content" id="contentInput" style="display: none;" required><?= htmlspecialchars($doc['content']) ?></textarea>
                    <div class="char-counter" id="contentCounter">0 символов</div>
                </div>

                <div class="form-group">
                    <label class="form-label">Краткое описание</label>
                    <textarea name="excerpt" class="form-textarea" rows="3"><?= htmlspecialchars($doc['excerpt']) ?></textarea>
                    <div class="char-counter" id="excerptCounter"><?= strlen($doc['excerpt']) ?> символов</div>
                </div>
            </div>

            <div class="form-sidebar">
                <div class="form-group">
                    <label class="form-label required">Категория</label>
                    <select name="category_id" class="form-select" required>
                        <option value="">-- Выберите категорию --</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= $category['id'] ?>"
                                <?= $category['id'] == $doc['category_id'] ? 'selected' : '' ?>
                                style="padding-left: <?= $category['level'] * 20 ?>px">
                                <?= str_repeat('— ', $category['level']) . htmlspecialchars($category['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label required">Статус</label>
                    <div class="radio-group">
                        <div class="radio-item">
                            <input type="radio" name="status" value="draft"
                                   id="status_draft" <?= $doc['status'] === 'draft' ? 'checked' : '' ?>>
                            <label for="status_draft">Черновик</label>
                        </div>
                        <div class="radio-item">
                            <input type="radio" name="status" value="published"
                                   id="status_published" <?= $doc['status'] === 'published' ? 'checked' : '' ?>>
                            <label for="status_published">Опубликовано</label>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Настройки</label>
                    <div class="checkbox-group">
                        <div class="checkbox-item">
                            <input type="checkbox" name="is_featured" id="is_featured"
                                   <?= $doc['is_featured'] ? 'checked' : '' ?>>
                            <label for="is_featured">Избранная статья</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" name="is_pinned" id="is_pinned"
                                   <?= $doc['is_pinned'] ? 'checked' : '' ?>>
                            <label for="is_pinned">Закрепленная</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" name="allow_comments" id="allow_comments"
                                   <?= $doc['allow_comments'] ? 'checked' : '' ?>>
                            <label for="allow_comments">Разрешить комментарии</label>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Теги</label>
                    <div class="tags-container">
                        <?php foreach ($all_tags as $tag): ?>
                            <div class="tag-item">
                                <input type="checkbox" name="tags[]"
                                       value="<?= $tag['id'] ?>"
                                       id="tag_<?= $tag['id'] ?>"
                                       <?= in_array($tag['id'], $selected_tag_ids) ? 'checked' : '' ?>>
                                <label for="tag_<?= $tag['id'] ?>"><?= htmlspecialchars($tag['name']) ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Ключевые слова (для SEO)</label>
                    <input type="text" name="meta_keywords" class="form-input"
                           value="<?= htmlspecialchars($doc['meta_keywords']) ?>"
                           placeholder="через запятую">
                </div>

                <div class="form-group">
                    <label class="form-label">Мета-описание (для SEO)</label>
                    <textarea name="meta_description" class="form-textarea" rows="3"><?= htmlspecialchars($doc['meta_description']) ?></textarea>
                    <div class="char-counter" id="metaCounter"><?= strlen($doc['meta_description']) ?> символов (рекомендуется 150-160)</div>
                </div>
            </div>
        </div>

        <div class="form-actions">
            <a href="/docs/docs.php" class="btn btn-secondary">
                <i class="fas fa-times"></i> Отмена
            </a>

            <?php if ($action === 'edit'): ?>
                <a href="/docs/docs.php?action=view&doc=<?= $doc['slug'] ?>"
                   class="btn btn-secondary" target="_blank">
                    <i class="fas fa-eye"></i> Просмотр
                </a>
            <?php endif; ?>

            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> <?= $action === 'edit' ? 'Обновить статью' : 'Создать статью' ?>
            </button>
        </div>
    </form>
</div>

<script>
// Генерация slug из заголовка
function updateSlug(title) {
    const slugInput = document.querySelector('input[name="slug"]');
    const slugPreview = document.getElementById('slugPreview');
    
    // Генерируем slug только если поле slug пустое
    if (!slugInput.value || slugInput.value === '<?= $doc["slug"] ?>') {
        // Простая функция для транслитерации кириллицы в латиницу
        function transliterate(text) {
            const translitMap = {
                'а': 'a', 'б': 'b', 'в': 'v', 'г': 'g', 'д': 'd', 'е': 'e', 'ё': 'yo', 'ж': 'zh',
                'з': 'z', 'и': 'i', 'й': 'y', 'к': 'k', 'л': 'l', 'м': 'm', 'н': 'n', 'о': 'o',
                'п': 'p', 'р': 'r', 'с': 's', 'т': 't', 'у': 'u', 'ф': 'f', 'х': 'h', 'ц': 'ts',
                'ч': 'ch', 'ш': 'sh', 'щ': 'shch', 'ъ': '', 'ы': 'y', 'ь': '', 'э': 'e', 'ю': 'yu',
                'я': 'ya',
                'А': 'A', 'Б': 'B', 'В': 'V', 'Г': 'G', 'Д': 'D', 'Е': 'E', 'Ё': 'Yo', 'Ж': 'Zh',
                'З': 'Z', 'И': 'I', 'Й': 'Y', 'К': 'K', 'Л': 'L', 'М': 'M', 'Н': 'N', 'О': 'O',
                'П': 'P', 'Р': 'R', 'С': 'S', 'Т': 'T', 'У': 'U', 'Ф': 'F', 'Х': 'H', 'Ц': 'Ts',
                'Ч': 'Ch', 'Ш': 'Sh', 'Щ': 'Shch', 'Ъ': '', 'Ы': 'Y', 'Ь': '', 'Э': 'E', 'Ю': 'Yu',
                'Я': 'Ya'
            };
            
            return text.split('').map(char => translitMap[char] || char).join('');
        }
        
        let slug = transliterate(title)
            .toLowerCase()
            .replace(/[^\w\s-]/g, '')      // Удаляем спецсимволы
            .replace(/\s+/g, '-')          // Заменяем пробелы на дефисы
            .replace(/--+/g, '-')          // Заменяем множественные дефисы на один
            .replace(/^-+/, '')            // Убираем дефисы с начала
            .replace(/-+$/, '');           // Убираем дефисы с конца
        
        // Если после преобразования slug пустой, добавляем префикс
        if (!slug) {
            slug = 'article-' + Date.now();
        }
        
        slugInput.value = slug;
        
        // Обновляем превью
        if (slugPreview) {
            slugPreview.textContent = '/docs/docs.php?action=view&doc=' + slug;
        }
    }
}

// Счетчики символов
function setupCharCounter(textareaId, counterId, maxLength = null) {
    const textarea = document.querySelector(`[name="${textareaId}"]`);
    const counter = document.getElementById(counterId);

    if (textarea && counter) {
        function updateCounter() {
            const length = textarea.value.length;
            counter.textContent = `${length} символов` + (maxLength ? ` (макс: ${maxLength})` : '');

            if (maxLength) {
                const percent = (length / maxLength) * 100;
                if (percent > 90) {
                    counter.classList.add('warning');
                    counter.classList.remove('error');
                } else if (percent > 100) {
                    counter.classList.remove('warning');
                    counter.classList.add('error');
                } else {
                    counter.classList.remove('warning', 'error');
                }
            }
        }

        textarea.addEventListener('input', updateCounter);
        updateCounter();
    }
}

// WYSIWYG редактор
function initEditor() {
    const editor = document.getElementById('contentEditor');
    const input = document.getElementById('contentInput');
    const counter = document.getElementById('contentCounter');

    if (!editor || !input) return;

    // Синхронизация с textarea
    function syncContent() {
        input.value = editor.innerHTML;
        const length = editor.textContent.length;
        counter.textContent = `${length} символов`;
    }

    editor.addEventListener('input', syncContent);
    syncContent();

    // Форматирование текста
    document.querySelectorAll('.editor-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const command = this.dataset.command;
            const value = this.dataset.value;

            if (command === 'createLink') {
                const url = prompt('Введите URL:');
                if (url) {
                    document.execCommand(command, false, url);
                }
            } else if (command === 'formatBlock') {
                document.execCommand(command, false, value);
            } else {
                document.execCommand(command, false, null);
            }

            editor.focus();
            syncContent();
        });
    });
}

// Инициализация
document.addEventListener('DOMContentLoaded', function() {
    // Инициализация превью slug
    const slugInput = document.querySelector('input[name="slug"]');
    const slugPreview = document.getElementById('slugPreview');
    
    if (slugInput && slugPreview) {
        function updatePreview() {
            slugPreview.textContent = '/docs/docs.php?action=view&doc=' + slugInput.value;
        }
        
        slugInput.addEventListener('input', updatePreview);
        updatePreview();
    }
    
    // Настройка счетчиков символов
    setupCharCounter('excerpt', 'excerptCounter', 300);
    setupCharCounter('meta_description', 'metaCounter', 160);

    // Инициализация редактора
    initEditor();

    // Валидация формы
    const form = document.getElementById('docForm');
    form.addEventListener('submit', function(e) {
        // Обязательно синхронизируем контент перед отправкой
        const editor = document.getElementById('contentEditor');
        const input = document.getElementById('contentInput');
        if (editor && input) {
            input.value = editor.innerHTML;
        }

        // Проверка slug (если заполнен)
        const slug = document.querySelector('input[name="slug"]').value;
        if (slug && !/^[a-z0-9\-]+$/.test(slug)) {
            e.preventDefault();
            alert('URL может содержать только латинские буквы в нижнем регистре, цифры и дефисы');
            return;
        }

        // Проверка длины описания
        const metaDesc = document.querySelector('[name="meta_description"]').value;
        if (metaDesc.length > 160) {
            if (!confirm('Мета-описание превышает рекомендуемую длину (160 символов). Продолжить?')) {
                e.preventDefault();
                return;
            }
        }
    });

    // Автоматическое сохранение черновика
    let saveTimeout;
    const saveDraft = () => {
        const formData = new FormData(form);
        formData.append('auto_save', '1');

        fetch(window.location.href, {
            method: 'POST',
            body: formData
        }).then(() => {
            console.log('Черновик сохранен');
        });
    };

    form.addEventListener('input', () => {
        clearTimeout(saveTimeout);
        saveTimeout = setTimeout(saveDraft, 5000);
    });
});
</script>

<?php require 'admin_footer.php'; ?>