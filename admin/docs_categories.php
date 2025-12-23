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

// Действия
$action = $_GET['action'] ?? 'list';
$category_id = $_GET['id'] ?? 0;

// Обработка форм
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $parent_id = $_POST['parent_id'] ?? null;
    $icon = $_POST['icon'] ?? 'fa-file';
    $color = $_POST['color'] ?? '#00bcd4';
    $sort_order = (int)($_POST['sort_order'] ?? 0);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    try {
        if ($action === 'edit' && $category_id > 0) {
            // Обновление категории
            $stmt = $pdo->prepare("
                UPDATE doc_categories SET
                name = ?,
                slug = ?,
                description = ?,
                parent_id = ?,
                icon = ?,
                color = ?,
                sort_order = ?,
                is_active = ?
                WHERE id = ?
            ");
            
            $stmt->execute([$name, $slug, $description, $parent_id, $icon, $color, $sort_order, $is_active, $category_id]);
            
            $message = 'Категория обновлена';
        } else {
            // Создание категории
            $stmt = $pdo->prepare("
                INSERT INTO doc_categories (name, slug, description, parent_id, icon, color, sort_order, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([$name, $slug, $description, $parent_id, $icon, $color, $sort_order, $is_active]);
            
            $message = 'Категория создана';
        }
        
        $message_type = 'success';
        header('Location: /admin/docs_categories.php');
        exit;
        
    } catch (Exception $e) {
        $message = 'Ошибка: ' . $e->getMessage();
        $message_type = 'error';
    }
}

// Получение категорий
function getCategoriesTree($pdo, $parent_id = null, $level = 0) {
    $query = "SELECT * FROM doc_categories WHERE 1=1";
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

// Получение конкретной категории
function getCategory($pdo, $id) {
    $stmt = $pdo->prepare("SELECT * FROM doc_categories WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Получение количества статей в категории
function getCategoryStats($pdo) {
    $stmt = $pdo->query("
        SELECT 
            c.id,
            c.name,
            COUNT(d.id) as doc_count,
            SUM(d.view_count) as total_views
        FROM doc_categories c
        LEFT JOIN docs d ON c.id = d.category_id AND d.status = 'published'
        GROUP BY c.id
        ORDER BY c.sort_order
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$categories = getCategoriesTree($pdo);
$stats = getCategoryStats($pdo);

$title = "Управление категориями | Админ панель | HomeVlad Cloud";
require 'admin_header.php';
?>

<style>
.categories-wrapper {
    padding: 20px;
    background: var(--db-bg);
    min-height: calc(100vh - 70px);
    margin-left: 280px;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.admin-sidebar.compact + .categories-wrapper {
    margin-left: 70px;
}

.categories-header {
    margin-bottom: 30px;
    padding: 24px;
    background: var(--db-card-bg);
    border-radius: 12px;
    border: 1px solid var(--db-border);
    box-shadow: var(--db-shadow);
}

.categories-header h1 {
    color: var(--db-text);
    font-size: 24px;
    margin: 0 0 8px 0;
    display: flex;
    align-items: center;
    gap: 12px;
}

.categories-header h1 i {
    color: var(--db-accent);
}

.categories-actions {
    display: flex;
    gap: 12px;
    margin-bottom: 20px;
}

.categories-list {
    background: var(--db-card-bg);
    border-radius: 12px;
    border: 1px solid var(--db-border);
    padding: 20px;
    box-shadow: var(--db-shadow);
}

.category-item {
    display: flex;
    align-items: center;
    padding: 15px;
    border-bottom: 1px solid var(--db-border);
    transition: all 0.3s ease;
}

.category-item:hover {
    background: var(--db-hover);
}

.category-item:last-child {
    border-bottom: none;
}

.category-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    color: white;
    margin-right: 15px;
    flex-shrink: 0;
}

.category-info {
    flex: 1;
}

.category-name {
    color: var(--db-text);
    font-size: 16px;
    font-weight: 500;
    margin: 0 0 5px 0;
}

.category-description {
    color: var(--db-text-muted);
    font-size: 14px;
    margin: 0;
}

.category-slug {
    color: var(--db-text-secondary);
    font-size: 12px;
    margin-top: 5px;
}

.category-stats {
    display: flex;
    gap: 20px;
    margin-left: 20px;
    flex-shrink: 0;
}

.category-stat {
    text-align: center;
    min-width: 60px;
}

.category-stat-value {
    color: var(--db-text);
    font-size: 18px;
    font-weight: 600;
    display: block;
}

.category-stat-label {
    color: var(--db-text-muted);
    font-size: 12px;
    display: block;
}

.category-actions {
    display: flex;
    gap: 8px;
    margin-left: 20px;
}

.category-action-btn {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--db-card-bg);
    border: 1px solid var(--db-border);
    color: var(--db-text-secondary);
    text-decoration: none;
    transition: all 0.3s ease;
    font-size: 14px;
}

.category-action-btn:hover {
    background: var(--db-hover);
    color: var(--db-accent);
}

.category-action-btn-danger:hover {
    color: var(--db-danger);
    border-color: var(--db-danger);
}

.empty-categories {
    text-align: center;
    padding: 40px 20px;
    color: var(--db-text-secondary);
}

.empty-categories i {
    font-size: 48px;
    margin-bottom: 16px;
    opacity: 0.5;
}

.empty-categories h3 {
    color: var(--db-text);
    margin: 0 0 8px 0;
    font-size: 18px;
}

.empty-categories p {
    margin: 0;
    font-size: 14px;
}

/* Форма категории */
.category-form {
    background: var(--db-card-bg);
    border-radius: 12px;
    border: 1px solid var(--db-border);
    padding: 30px;
    box-shadow: var(--db-shadow);
    max-width: 600px;
    margin: 0 auto;
}

.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

@media (max-width: 768px) {
    .form-grid {
        grid-template-columns: 1fr;
    }
}

.form-group {
    margin-bottom: 20px;
}

.form-group.full-width {
    grid-column: 1 / -1;
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
    width: 100%;
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
    min-height: 100px;
    resize: vertical;
}

.color-picker {
    display: flex;
    gap: 10px;
    align-items: center;
}

.color-input {
    flex: 1;
}

.color-preview {
    width: 40px;
    height: 40px;
    border-radius: 8px;
    border: 1px solid var(--db-border);
}

.icon-picker {
    display: flex;
    gap: 10px;
    align-items: center;
}

.icon-input {
    flex: 1;
}

.icon-preview {
    width: 40px;
    height: 40px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    color: white;
    background: var(--db-accent);
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
    color: white;
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

/* Адаптивность */
@media (max-width: 1200px) {
    .categories-wrapper {
        margin-left: 70px !important;
    }
}

@media (max-width: 768px) {
    .categories-wrapper {
        margin-left: 0 !important;
        padding: 15px;
    }
    
    .category-item {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .category-stats,
    .category-actions {
        margin-left: 0;
        margin-top: 15px;
        width: 100%;
        justify-content: flex-end;
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

<div class="categories-wrapper">
    <div class="categories-header">
        <h1>
            <i class="fas fa-folder"></i>
            <?= $action === 'list' ? 'Управление категориями' : ($action === 'edit' ? 'Редактирование категории' : 'Создание категории') ?>
        </h1>
        <p>Организация статей документации по категориям</p>
    </div>
    
    <?php if ($message): ?>
        <div class="alert alert-<?= $message_type === 'error' ? 'error' : 'success' ?>">
            <i class="fas fa-<?= $message_type === 'error' ? 'exclamation-circle' : 'check-circle' ?>"></i>
            <div><?= htmlspecialchars($message) ?></div>
        </div>
    <?php endif; ?>
    
    <?php if ($action === 'list'): ?>
        <!-- Список категорий -->
        <div class="categories-actions">
            <a href="/admin/docs_categories.php?action=create" class="btn btn-primary">
                <i class="fas fa-plus"></i> Новая категория
            </a>
            <a href="/admin/docs_editor.php" class="btn btn-secondary">
                <i class="fas fa-file-alt"></i> Управление статьями
            </a>
        </div>
        
        <div class="categories-list">
            <?php if (empty($categories)): ?>
                <div class="empty-categories">
                    <i class="fas fa-folder-open"></i>
                    <h3>Категорий пока нет</h3>
                    <p>Создайте первую категорию для организации документации</p>
                </div>
            <?php else: ?>
                <?php foreach ($categories as $category): 
                    $stat = array_filter($stats, fn($s) => $s['id'] == $category['id']);
                    $stat = $stat ? reset($stat) : ['doc_count' => 0, 'total_views' => 0];
                ?>
                    <div class="category-item" style="padding-left: <?= 20 + ($category['level'] * 20) ?>px;">
                        <div class="category-icon" style="background: <?= $category['color'] ?>;">
                            <i class="fas <?= $category['icon'] ?>"></i>
                        </div>
                        
                        <div class="category-info">
                            <h3 class="category-name">
                                <?= htmlspecialchars($category['name']) ?>
                                <?php if (!$category['is_active']): ?>
                                    <span style="color: var(--db-danger); font-size: 12px;"> (скрыта)</span>
                                <?php endif; ?>
                            </h3>
                            <?php if ($category['description']): ?>
                                <p class="category-description"><?= htmlspecialchars($category['description']) ?></p>
                            <?php endif; ?>
                            <div class="category-slug">/docs/docs.php?action=list&category=<?= $category['slug'] ?></div>
                        </div>
                        
                        <div class="category-stats">
                            <div class="category-stat">
                                <span class="category-stat-value"><?= $stat['doc_count'] ?></span>
                                <span class="category-stat-label">статей</span>
                            </div>
                            <div class="category-stat">
                                <span class="category-stat-value"><?= number_format($stat['total_views']) ?></span>
                                <span class="category-stat-label">просмотров</span>
                            </div>
                        </div>
                        
                        <div class="category-actions">
                            <a href="/docs/docs.php?action=list&category=<?= $category['slug'] ?>" 
                               class="category-action-btn" title="Просмотреть">
                                <i class="fas fa-eye"></i>
                            </a>
                            <a href="/admin/docs_categories.php?action=edit&id=<?= $category['id'] ?>" 
                               class="category-action-btn" title="Редактировать">
                                <i class="fas fa-edit"></i>
                            </a>
                            <a href="/admin/docs_editor.php?action=create&category=<?= $category['id'] ?>" 
                               class="category-action-btn" title="Добавить статью">
                                <i class="fas fa-plus"></i>
                            </a>
                            <a href="#" 
                               onclick="deleteCategory(<?= $category['id'] ?>, '<?= htmlspecialchars($category['name']) ?>')"
                               class="category-action-btn category-action-btn-danger" title="Удалить">
                                <i class="fas fa-trash"></i>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
    <?php else: ?>
        <!-- Форма создания/редактирования категории -->
        <?php
            if ($action === 'edit' && $category_id > 0) {
                $category = getCategory($pdo, $category_id);
                if (!$category) {
                    header('Location: /admin/docs_categories.php');
                    exit;
                }
            } else {
                $category = [
                    'name' => '',
                    'slug' => '',
                    'description' => '',
                    'parent_id' => null,
                    'icon' => 'fa-file',
                    'color' => '#00bcd4',
                    'sort_order' => 0,
                    'is_active' => 1
                ];
            }
        ?>
        
        <form method="POST" class="category-form">
            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label required">Название категории</label>
                    <input type="text" name="name" class="form-input" 
                           value="<?= htmlspecialchars($category['name']) ?>" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label required">URL категории</label>
                    <input type="text" name="slug" class="form-input" 
                           value="<?= htmlspecialchars($category['slug']) ?>" required
                           pattern="[a-z0-9\-]+"
                           title="Только латинские буквы в нижнем регистре, цифры и дефисы">
                </div>
                
                <div class="form-group full-width">
                    <label class="form-label">Описание</label>
                    <textarea name="description" class="form-textarea"><?= htmlspecialchars($category['description']) ?></textarea>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Родительская категория</label>
                    <select name="parent_id" class="form-select">
                        <option value="">-- Без родительской категории --</option>
                        <?php foreach ($categories as $cat): 
                            if ($action === 'edit' && $cat['id'] == $category_id) continue;
                        ?>
                            <option value="<?= $cat['id'] ?>" 
                                <?= $cat['id'] == $category['parent_id'] ? 'selected' : '' ?>
                                style="padding-left: <?= $cat['level'] * 20 ?>px">
                                <?= str_repeat('— ', $cat['level']) . htmlspecialchars($cat['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Иконка</label>
                    <div class="icon-picker">
                        <input type="text" name="icon" class="form-input icon-input" 
                               value="<?= htmlspecialchars($category['icon']) ?>"
                               placeholder="fa-file">
                        <div class="icon-preview" id="iconPreview" style="background: <?= $category['color'] ?>;">
                            <i class="fas <?= $category['icon'] ?>"></i>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Цвет</label>
                    <div class="color-picker">
                        <input type="color" name="color" class="color-input" 
                               value="<?= htmlspecialchars($category['color']) ?>"
                               onchange="updateColorPreview(this.value)">
                        <div class="color-preview" id="colorPreview" style="background: <?= $category['color'] ?>;"></div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Порядок сортировки</label>
                    <input type="number" name="sort_order" class="form-input" 
                           value="<?= $category['sort_order'] ?>" min="0">
                </div>
                
                <div class="form-group">
                    <div class="checkbox-item">
                        <input type="checkbox" name="is_active" id="is_active" 
                               <?= $category['is_active'] ? 'checked' : '' ?>>
                        <label for="is_active">Активная категория</label>
                    </div>
                </div>
            </div>
            
            <div class="form-actions">
                <a href="/admin/docs_categories.php" class="btn btn-secondary">
                    <i class="fas fa-times"></i> Отмена
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> <?= $action === 'edit' ? 'Обновить' : 'Создать' ?>
                </button>
            </div>
        </form>
    <?php endif; ?>
</div>

<script>
// Обновление превью иконки
const iconInput = document.querySelector('input[name="icon"]');
const iconPreview = document.getElementById('iconPreview');

if (iconInput && iconPreview) {
    iconInput.addEventListener('input', function() {
        const icon = this.value || 'fa-file';
        const i = iconPreview.querySelector('i');
        i.className = 'fas ' + icon;
    });
}

// Обновление превью цвета
function updateColorPreview(color) {
    const colorPreview = document.getElementById('colorPreview');
    const iconPreview = document.getElementById('iconPreview');
    
    if (colorPreview) colorPreview.style.background = color;
    if (iconPreview) iconPreview.style.background = color;
}

// Удаление категории
function deleteCategory(id, name) {
    if (confirm(`Вы уверены, что хотите удалить категорию "${name}"? Все статьи в этой категории будут перемещены в категорию "Без категории".`)) {
        fetch('/admin/ajax/delete_category.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ id: id })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification('Категория удалена', 'success');
                setTimeout(() => location.reload(), 1000);
            } else {
                showNotification('Ошибка: ' + data.error, 'error');
            }
        })
        .catch(error => {
            showNotification('Ошибка сети', 'error');
        });
    }
}

// Уведомления
function showNotification(message, type = 'info') {
    const alert = document.createElement('div');
    alert.className = `alert alert-${type}`;
    alert.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 9999;
        max-width: 400px;
        animation: fadeIn 0.3s ease;
    `;

    const icon = type === 'success' ? 'check-circle' :
                 type === 'error' ? 'exclamation-circle' : 'info-circle';

    alert.innerHTML = `
        <i class="fas fa-${icon}"></i>
        <div>${message}</div>
    `;

    document.body.appendChild(alert);

    setTimeout(() => {
        alert.style.transition = 'opacity 0.3s ease';
        alert.style.opacity = '0';
        setTimeout(() => alert.remove(), 300);
    }, 3000);
}

// Генерация slug из названия
document.querySelector('input[name="name"]')?.addEventListener('input', function() {
    const slugInput = document.querySelector('input[name="slug"]');
    if (!slugInput.value || slugInput.value === '<?= $category["slug"] ?? "" ?>') {
        const slug = this.value.toLowerCase()
            .replace(/[^\w\s-]/g, '')
            .replace(/\s+/g, '-')
            .replace(/^-+|-+$/g, '');
        slugInput.value = slug;
    }
});
</script>

<?php require 'admin_footer.php'; ?>