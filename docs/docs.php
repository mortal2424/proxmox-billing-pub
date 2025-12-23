<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

checkAuth();

$db = new Database();
$pdo = $db->getConnection();
$user_id = $_SESSION['user']['id'];

// Получаем данные пользователя как в dashboard.php
$user = $db->getConnection()->query("SELECT * FROM users WHERE id = $user_id")->fetch();

// Проверяем права пользователя
$is_admin = $user && $user['is_admin'];

// Получаем параметры
$action = $_GET['action'] ?? 'list';
$category_slug = $_GET['category'] ?? null;
$doc_slug = $_GET['doc'] ?? null;
$search_query = $_GET['q'] ?? '';

// Функции для работы с документацией
function getCategories($pdo, $parent_id = null) {
    $query = "SELECT * FROM doc_categories WHERE is_active = 1";

    if ($parent_id === null) {
        $query .= " AND parent_id IS NULL";
    } else {
        $query .= " AND parent_id = ?";
    }

    $query .= " ORDER BY sort_order, name";

    $stmt = $pdo->prepare($query);
    if ($parent_id !== null) {
        $stmt->execute([$parent_id]);
    } else {
        $stmt->execute();
    }

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getCategoryBySlug($pdo, $slug) {
    $stmt = $pdo->prepare("SELECT * FROM doc_categories WHERE slug = ? AND is_active = 1");
    $stmt->execute([$slug]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getDocsByCategory($pdo, $category_id, $status = 'published') {
    $query = "SELECT d.*, u.email as author_email, u.full_name as author_name
              FROM docs d
              LEFT JOIN users u ON d.author_id = u.id
              WHERE d.category_id = ? AND d.status = ?
              ORDER BY d.is_pinned DESC, d.is_featured DESC, d.title";

    $stmt = $pdo->prepare($query);
    $stmt->execute([$category_id, $status]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getDocBySlug($pdo, $slug, $increment_view = false) {
    // Получаем документ
    $query = "SELECT d.*,
                     u.email as author_email,
                     u.full_name as author_name,
                     u.avatar as author_avatar,
                     c.name as category_name,
                     c.slug as category_slug,
                     c.icon as category_icon,
                     c.color as category_color
              FROM docs d
              LEFT JOIN users u ON d.author_id = u.id
              LEFT JOIN doc_categories c ON d.category_id = c.id
              WHERE d.slug = ?";

    $stmt = $pdo->prepare($query);
    $stmt->execute([$slug]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$doc) {
        return null;
    }

    // Увеличиваем счетчик просмотров
    if ($increment_view) {
        // Обновляем общий счетчик
        $stmt = $pdo->prepare("UPDATE docs SET view_count = view_count + 1 WHERE id = ?");
        $stmt->execute([$doc['id']]);

        // Обновляем дневную статистику
        $today = date('Y-m-d');
        $stmt = $pdo->prepare("
            INSERT INTO doc_statistics (doc_id, view_date, view_count)
            VALUES (?, ?, 1)
            ON DUPLICATE KEY UPDATE view_count = view_count + 1
        ");
        $stmt->execute([$doc['id'], $today]);
    }

    return $doc;
}

function getDocTags($pdo, $doc_id) {
    $stmt = $pdo->prepare("
        SELECT t.* FROM doc_tags t
        INNER JOIN doc_tag_relations r ON t.id = r.tag_id
        WHERE r.doc_id = ?
        ORDER BY t.name
    ");
    $stmt->execute([$doc_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function searchDocs($pdo, $query, $limit = 20) {
    // Сохраняем поисковый запрос
    if (!empty($query)) {
        $stmt = $pdo->prepare("INSERT INTO doc_searches (query, user_id) VALUES (?, ?)");
        $stmt->execute([$query, $GLOBALS['user_id'] ?? null]);
    }

    // Ищем документы
    $search_query = "%$query%";
    $stmt = $pdo->prepare("
        SELECT d.*,
               c.name as category_name,
               c.slug as category_slug,
               MATCH(d.title, d.content, d.excerpt) AGAINST(? IN BOOLEAN MODE) as relevance
        FROM docs d
        LEFT JOIN doc_categories c ON d.category_id = c.id
        WHERE (d.status = 'published' OR ? = 1)
          AND (d.title LIKE ? OR d.excerpt LIKE ? OR MATCH(d.title, d.content, d.excerpt) AGAINST(? IN BOOLEAN MODE))
        ORDER BY relevance DESC, d.is_featured DESC, d.view_count DESC
        LIMIT ?
    ");

    $is_admin = $GLOBALS['is_admin'] ?? false;
    $stmt->execute([$query, $is_admin ? 1 : 0, $search_query, $search_query, $query, $limit]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Обновляем количество результатов
    if (!empty($query) && !empty($results)) {
        $stmt = $pdo->prepare("UPDATE doc_searches SET results_count = ? WHERE id = LAST_INSERT_ID()");
        $stmt->execute([count($results)]);
    }

    return $results;
}

function getPopularDocs($pdo, $limit = 10) {
    $stmt = $pdo->prepare("
        SELECT d.*, c.name as category_name, c.slug as category_slug
        FROM docs d
        LEFT JOIN doc_categories c ON d.category_id = c.id
        WHERE d.status = 'published'
        ORDER BY d.view_count DESC, d.created_at DESC
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getFeaturedDocs($pdo, $limit = 5) {
    $stmt = $pdo->prepare("
        SELECT d.*, c.name as category_name, c.slug as category_slug
        FROM docs d
        LEFT JOIN doc_categories c ON d.category_id = c.id
        WHERE d.status = 'published' AND d.is_featured = 1
        ORDER BY d.is_pinned DESC, d.created_at DESC
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getDocComments($pdo, $doc_id) {
    $stmt = $pdo->prepare("
        SELECT c.*,
               u.email as user_email,
               u.full_name as user_name,
               u.avatar as user_avatar
        FROM doc_comments c
        LEFT JOIN users u ON c.user_id = u.id
        WHERE c.doc_id = ? AND c.is_approved = 1
        ORDER BY c.is_resolved DESC, c.created_at ASC
    ");
    $stmt->execute([$doc_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getDocRating($pdo, $doc_id) {
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) as total_votes,
            AVG(rating) as average_rating,
            SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) as stars_5,
            SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END) as stars_4,
            SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) as stars_3,
            SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END) as stars_2,
            SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) as stars_1
        FROM doc_ratings
        WHERE doc_id = ?
    ");
    $stmt->execute([$doc_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getUserRating($pdo, $doc_id, $user_id) {
    $stmt = $pdo->prepare("SELECT rating FROM doc_ratings WHERE doc_id = ? AND user_id = ?");
    $stmt->execute([$doc_id, $user_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ? $result['rating'] : null;
}

function isBookmarked($pdo, $doc_id, $user_id) {
    $stmt = $pdo->prepare("SELECT id FROM doc_bookmarks WHERE doc_id = ? AND user_id = ?");
    $stmt->execute([$doc_id, $user_id]);
    return $stmt->fetch() ? true : false;
}

// Обработка действий
$message = '';
$message_type = '';

switch ($action) {
    case 'view':
        $doc = getDocBySlug($pdo, $doc_slug, true);
        if (!$doc) {
            header('Location: /docs/docs.php?action=list');
            exit;
        }
        break;

    case 'search':
        $search_results = searchDocs($pdo, $search_query);
        break;

    case 'add_comment':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $doc_id = $_POST['doc_id'] ?? 0;
            $content = trim($_POST['content'] ?? '');

            if ($doc_id && !empty($content)) {
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO doc_comments (doc_id, user_id, content, is_admin, created_at)
                        VALUES (?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([
                        $doc_id,
                        $user_id,
                        $content,
                        $is_admin ? 1 : 0
                    ]);

                    $message = 'Комментарий добавлен';
                    $message_type = 'success';

                    // Редирект на страницу документа
                    header("Location: /docs/docs.php?action=view&doc={$doc_slug}");
                    exit;
                } catch (Exception $e) {
                    $message = 'Ошибка при добавлении комментария: ' . $e->getMessage();
                    $message_type = 'error';
                }
            }
        }
        break;

    case 'rate':
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $doc_slug) {
            $rating = (int)($_POST['rating'] ?? 0);

            if ($rating >= 1 && $rating <= 5) {
                $doc = getDocBySlug($pdo, $doc_slug);
                if ($doc) {
                    try {
                        $stmt = $pdo->prepare("
                            INSERT INTO doc_ratings (doc_id, user_id, rating, created_at)
                            VALUES (?, ?, ?, NOW())
                            ON DUPLICATE KEY UPDATE rating = ?, created_at = NOW()
                        ");
                        $stmt->execute([$doc['id'], $user_id, $rating, $rating]);

                        $message = 'Спасибо за оценку!';
                        $message_type = 'success';
                    } catch (Exception $e) {
                        $message = 'Ошибка при сохранении оценки: ' . $e->getMessage();
                        $message_type = 'error';
                    }
                }
            }
        }
        break;

    case 'bookmark':
        if ($doc_slug) {
            $doc = getDocBySlug($pdo, $doc_slug);
            if ($doc) {
                if (isBookmarked($pdo, $doc['id'], $user_id)) {
                    // Удалить закладку
                    $stmt = $pdo->prepare("DELETE FROM doc_bookmarks WHERE doc_id = ? AND user_id = ?");
                    $stmt->execute([$doc['id'], $user_id]);
                    $message = 'Закладка удалена';
                } else {
                    // Добавить закладку
                    $stmt = $pdo->prepare("INSERT INTO doc_bookmarks (doc_id, user_id, created_at) VALUES (?, ?, NOW())");
                    $stmt->execute([$doc['id'], $user_id]);
                    $message = 'Статья добавлена в закладки';
                }
                $message_type = 'success';
            }
        }
        break;
}

// Получаем данные для отображения
$categories = getCategories($pdo);
$popular_docs = getPopularDocs($pdo);
$featured_docs = getFeaturedDocs($pdo);

$title = "Документация | HomeVlad Cloud";
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $title ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="icon" href="../img/cloud.png" type="image/png">
    <link rel="stylesheet" href="/css/themes.css">
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            --secondary-gradient: linear-gradient(135deg, #00bcd4, #0097a7);
            --success-gradient: linear-gradient(135deg, #10b981, #059669);
            --warning-gradient: linear-gradient(135deg, #f59e0b, #d97706);
            --danger-gradient: linear-gradient(135deg, #ef4444, #dc2626);
            --info-gradient: linear-gradient(135deg, #3b82f6, #2563eb);
            --purple-gradient: linear-gradient(135deg, #8b5cf6, #7c3aed);
            --docs-bg: #f8fafc;
            --docs-sidebar-bg: #ffffff;
            --docs-content-bg: #ffffff;
            --docs-border: #e2e8f0;
            --docs-text: #1e293b;
            --docs-text-secondary: #64748b;
            --docs-text-muted: #94a3b8;
            --docs-hover: #f1f5f9;
            --docs-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            --docs-shadow-hover: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --docs-accent: #00bcd4;
            --docs-success: #10b981;
            --docs-warning: #f59e0b;
            --docs-danger: #ef4444;
            --docs-info: #3b82f6;
            --docs-purple: #8b5cf6;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            color: #1e293b;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        body.dark-theme {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: #cbd5e1;
        }

        /* Основной контейнер */
        .main-container {
            display: flex;
            flex: 1;
            min-height: calc(100vh - 70px);
            margin-top: 70px;
        }

        /* Основной контент */
        .main-content {
            flex: 1;
            padding: 24px;
            margin-left: 280px;
            margin-right: 280px; /* Добавляем отступ для правого сайдбара */
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            width: calc(100% - 560px); /* Учитываем два сайдбара */
        }

        .sidebar-collapsed .main-content {
            margin-left: 80px;
            margin-right: 280px; /* Правый сайдбар остается */
            width: calc(100% - 360px);
        }

        /* Правый сайдбар документации */
        .docs-sidebar-right {
            width: 280px;
            background: var(--docs-sidebar-bg);
            border-left: 1px solid var(--docs-border);
            padding: 20px;
            overflow-y: auto;
            position: fixed;
            height: calc(100vh - 70px);
            top: 70px;
            right: 0;
            z-index: 90;
        }

        body.dark-theme .docs-sidebar-right {
            background: rgba(30, 41, 59, 0.7);
            border-color: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
        }

        .docs-sidebar-right-header {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--docs-border);
        }

        .docs-sidebar-right-header h3 {
            color: var(--docs-text);
            font-size: 16px;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        body.dark-theme .docs-sidebar-right-header h3 {
            color: #f1f5f9;
        }

        .docs-sidebar-right-header h3 i {
            color: var(--docs-accent);
        }

        .docs-search-right {
            margin-bottom: 20px;
        }

        .docs-search-right input {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid var(--docs-border);
            border-radius: 8px;
            background: var(--docs-content-bg);
            color: var(--docs-text);
            font-size: 14px;
        }

        body.dark-theme .docs-search-right input {
            background: rgba(30, 41, 59, 0.5);
            color: #f1f5f9;
            border-color: rgba(255, 255, 255, 0.1);
        }

        .docs-search-right input:focus {
            outline: none;
            border-color: var(--docs-accent);
        }

        .docs-categories-right {
            margin-bottom: 20px;
        }

        .docs-category-item-right {
            margin-bottom: 5px;
        }

        .docs-category-link-right {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 15px;
            color: var(--docs-text-secondary);
            text-decoration: none;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        body.dark-theme .docs-category-link-right {
            color: #94a3b8;
        }

        .docs-category-link-right:hover {
            background: var(--docs-hover);
            color: var(--docs-text);
        }

        body.dark-theme .docs-category-link-right:hover {
            background: rgba(255, 255, 255, 0.1);
            color: #f1f5f9;
        }

        .docs-category-link-right.active {
            background: var(--docs-accent);
            color: white;
        }

        .docs-category-icon-right {
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }

        .docs-category-name-right {
            flex: 1;
            font-size: 14px;
        }

        .docs-category-count-right {
            background: var(--docs-hover);
            color: var(--docs-text-secondary);
            font-size: 12px;
            padding: 2px 8px;
            border-radius: 10px;
        }

        body.dark-theme .docs-category-count-right {
            background: rgba(255, 255, 255, 0.1);
            color: #94a3b8;
        }

        .docs-category-link-right.active .docs-category-count-right {
            background: rgba(255, 255, 255, 0.2);
            color: white;
        }

        .docs-sidebar-right-footer {
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid var(--docs-border);
        }

        .docs-stats-right {
            font-size: 12px;
            color: var(--docs-text-muted);
        }

        body.dark-theme .docs-stats-right {
            color: #94a3b8;
        }

        /* Контент документации */
        .docs-content-inner {
            width: 100%;
            max-width: 100%;
        }

        .docs-header {
            margin-bottom: 30px;
        }

        .docs-header h1 {
            color: var(--docs-text);
            font-size: 24px;
            margin: 0 0 10px 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        body.dark-theme .docs-header h1 {
            color: #f1f5f9;
        }

        .docs-header h1 i {
            color: var(--docs-accent);
        }

        .docs-header p {
            color: var(--docs-text-secondary);
            font-size: 14px;
            margin: 0;
        }

        body.dark-theme .docs-header p {
            color: #94a3b8;
        }

        .docs-actions {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        /* Карточки документации */
        .docs-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .docs-card {
            background: var(--docs-content-bg);
            border: 1px solid var(--docs-border);
            border-radius: 12px;
            padding: 20px;
            transition: all 0.3s ease;
            box-shadow: var(--docs-shadow);
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        body.dark-theme .docs-card {
            background: rgba(30, 41, 59, 0.7);
            border-color: rgba(255, 255, 255, 0.1);
        }

        .docs-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--docs-shadow-hover);
            border-color: var(--docs-accent);
        }

        .docs-card-header {
            display: flex;
            align-items: flex-start;
            margin-bottom: 15px;
        }

        .docs-card-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            color: white;
            margin-right: 12px;
            flex-shrink: 0;
            background: linear-gradient(135deg, var(--docs-accent), #0097a7);
        }

        .docs-card-title {
            flex: 1;
        }

        .docs-card-title h3 {
            color: var(--docs-text);
            font-size: 16px;
            margin: 0 0 5px 0;
            line-height: 1.4;
        }

        body.dark-theme .docs-card-title h3 {
            color: #f1f5f9;
        }

        .docs-card-category {
            font-size: 12px;
            color: var(--docs-text-muted);
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .docs-card-body {
            flex: 1;
            margin-bottom: 15px;
        }

        .docs-card-excerpt {
            color: var(--docs-text-secondary);
            font-size: 14px;
            line-height: 1.5;
            margin: 0;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        body.dark-theme .docs-card-excerpt {
            color: #94a3b8;
        }

        .docs-card-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 15px;
            border-top: 1px solid var(--docs-border);
        }

        .docs-card-meta {
            display: flex;
            gap: 15px;
            font-size: 12px;
            color: var(--docs-text-muted);
        }

        .docs-card-meta-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .docs-card-actions {
            display: flex;
            gap: 8px;
        }

        .docs-action-btn {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--docs-content-bg);
            border: 1px solid var(--docs-border);
            color: var(--docs-text-secondary);
            text-decoration: none;
            transition: all 0.3s ease;
            font-size: 14px;
        }

        .docs-action-btn:hover {
            background: var(--docs-hover);
            color: var(--docs-accent);
        }

        /* Страница просмотра документа */
        .docs-article {
            background: var(--docs-content-bg);
            border-radius: 12px;
            border: 1px solid var(--docs-border);
            padding: 30px;
            box-shadow: var(--docs-shadow);
            margin-bottom: 30px;
        }

        body.dark-theme .docs-article {
            background: rgba(30, 41, 59, 0.7);
            border-color: rgba(255, 255, 255, 0.1);
        }

        .docs-article-header {
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--docs-border);
        }

        .docs-article-title {
            color: var(--docs-text);
            font-size: 28px;
            margin: 0 0 15px 0;
            line-height: 1.3;
        }

        body.dark-theme .docs-article-title {
            color: #f1f5f9;
        }

        .docs-article-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 15px;
        }

        .docs-article-meta-item {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--docs-text-secondary);
            font-size: 14px;
        }

        .docs-article-author {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .docs-article-author-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--docs-accent);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 16px;
        }

        .docs-article-author-info h4 {
            color: var(--docs-text);
            font-size: 14px;
            margin: 0 0 2px 0;
        }

        .docs-article-author-info p {
            color: var(--docs-text-muted);
            font-size: 12px;
            margin: 0;
        }

        .docs-article-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 15px;
        }

        .docs-tag {
            display: inline-block;
            padding: 4px 12px;
            background: var(--docs-hover);
            color: var(--docs-text);
            border-radius: 20px;
            font-size: 12px;
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .docs-tag:hover {
            background: var(--docs-accent);
            color: white;
        }

        .docs-article-content {
            color: var(--docs-text);
            line-height: 1.6;
            font-size: 16px;
        }

        body.dark-theme .docs-article-content {
            color: #cbd5e1;
        }

        .docs-article-content h1,
        .docs-article-content h2,
        .docs-article-content h3,
        .docs-article-content h4,
        .docs-article-content h5,
        .docs-article-content h6 {
            color: var(--docs-text);
            margin-top: 24px;
            margin-bottom: 16px;
            font-weight: 600;
            line-height: 1.25;
        }

        body.dark-theme .docs-article-content h1,
        body.dark-theme .docs-article-content h2,
        body.dark-theme .docs-article-content h3,
        body.dark-theme .docs-article-content h4,
        body.dark-theme .docs-article-content h5,
        body.dark-theme .docs-article-content h6 {
            color: #f1f5f9;
        }

        .docs-article-content h1 { font-size: 2em; }
        .docs-article-content h2 { font-size: 1.5em; }
        .docs-article-content h3 { font-size: 1.25em; }
        .docs-article-content h4 { font-size: 1em; }

        .docs-article-content p {
            margin-bottom: 16px;
        }

        .docs-article-content ul,
        .docs-article-content ol {
            margin-bottom: 16px;
            padding-left: 2em;
        }

        .docs-article-content code {
            background: var(--docs-hover);
            padding: 2px 6px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            font-size: 14px;
        }

        .docs-article-content pre {
            background: var(--docs-bg);
            border: 1px solid var(--docs-border);
            border-radius: 8px;
            padding: 16px;
            overflow-x: auto;
            margin-bottom: 16px;
        }

        body.dark-theme .docs-article-content pre {
            background: rgba(15, 23, 42, 0.5);
        }

        .docs-article-content pre code {
            background: none;
            padding: 0;
            border-radius: 0;
        }

        .docs-article-content blockquote {
            border-left: 4px solid var(--docs-accent);
            padding-left: 16px;
            margin-left: 0;
            color: var(--docs-text-secondary);
            font-style: italic;
        }

        .docs-article-content table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 16px;
        }

        .docs-article-content th,
        .docs-article-content td {
            border: 1px solid var(--docs-border);
            padding: 8px 12px;
            text-align: left;
        }

        .docs-article-content th {
            background: var(--docs-hover);
            font-weight: 600;
        }

        .docs-article-content img {
            max-width: 100%;
            height: auto;
            border-radius: 8px;
            margin: 16px 0;
        }

        .docs-article-footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid var(--docs-border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .docs-article-actions {
            display: flex;
            gap: 10px;
        }

        /* Рейтинг статьи */
        .docs-rating {
            margin: 30px 0;
            padding: 20px;
            background: var(--docs-content-bg);
            border: 1px solid var(--docs-border);
            border-radius: 12px;
        }

        body.dark-theme .docs-rating {
            background: rgba(30, 41, 59, 0.7);
            border-color: rgba(255, 255, 255, 0.1);
        }

        .docs-rating-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .docs-rating-title {
            color: var(--docs-text);
            font-size: 18px;
            margin: 0;
        }

        .docs-rating-stats {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .docs-rating-average {
            font-size: 24px;
            font-weight: bold;
            color: var(--docs-text);
        }

        .docs-rating-stars {
            display: flex;
            gap: 2px;
            color: #ffd700;
        }

        .docs-rating-count {
            color: var(--docs-text-muted);
            font-size: 14px;
        }

        .docs-rating-bars {
            margin-top: 15px;
        }

        .docs-rating-bar {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 8px;
        }

        .docs-rating-label {
            width: 30px;
            font-size: 14px;
            color: var(--docs-text);
        }

        .docs-rating-progress {
            flex: 1;
            height: 8px;
            background: var(--docs-border);
            border-radius: 4px;
            overflow: hidden;
        }

        .docs-rating-progress-fill {
            height: 100%;
            background: #ffd700;
            border-radius: 4px;
        }

        .docs-rating-percent {
            width: 40px;
            font-size: 14px;
            color: var(--docs-text-muted);
            text-align: right;
        }

        .docs-rating-form {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid var(--docs-border);
        }

        .docs-rating-form-title {
            color: var(--docs-text);
            font-size: 16px;
            margin-bottom: 10px;
        }

        .docs-rating-stars-input {
            display: flex;
            gap: 5px;
            margin-bottom: 10px;
        }

        .docs-rating-star {
            font-size: 24px;
            color: var(--docs-border);
            cursor: pointer;
            transition: color 0.3s ease;
        }

        .docs-rating-star:hover,
        .docs-rating-star.active {
            color: #ffd700;
        }

        /* Комментарии */
        .docs-comments {
            margin-top: 30px;
        }

        .docs-comments-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .docs-comments-title {
            color: var(--docs-text);
            font-size: 18px;
            margin: 0;
        }

        .docs-comments-count {
            color: var(--docs-text-muted);
            font-size: 14px;
        }

        .docs-comments-form {
            margin-bottom: 30px;
            padding: 20px;
            background: var(--docs-content-bg);
            border: 1px solid var(--docs-border);
            border-radius: 12px;
        }

        body.dark-theme .docs-comments-form {
            background: rgba(30, 41, 59, 0.7);
            border-color: rgba(255, 255, 255, 0.1);
        }

        .docs-comments-form textarea {
            width: 100%;
            min-height: 100px;
            padding: 12px;
            border: 1px solid var(--docs-border);
            border-radius: 8px;
            background: var(--docs-bg);
            color: var(--docs-text);
            font-size: 14px;
            resize: vertical;
            margin-bottom: 10px;
        }

        .docs-comments-form textarea:focus {
            outline: none;
            border-color: var(--docs-accent);
        }

        .docs-comments-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .docs-comment {
            background: var(--docs-content-bg);
            border: 1px solid var(--docs-border);
            border-radius: 12px;
            padding: 20px;
        }

        body.dark-theme .docs-comment {
            background: rgba(30, 41, 59, 0.7);
            border-color: rgba(255, 255, 255, 0.1);
        }

        .docs-comment-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 10px;
        }

        .docs-comment-author {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .docs-comment-author-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--docs-accent);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 14px;
        }

        .docs-comment-author-info h4 {
            color: var(--docs-text);
            font-size: 14px;
            margin: 0 0 2px 0;
        }

        .docs-comment-author-info p {
            color: var(--docs-text-muted);
            font-size: 12px;
            margin: 0;
        }

        .docs-comment-admin-badge {
            display: inline-block;
            padding: 2px 8px;
            background: var(--docs-accent);
            color: white;
            border-radius: 10px;
            font-size: 10px;
            margin-left: 8px;
        }

        .docs-comment-time {
            color: var(--docs-text-muted);
            font-size: 12px;
        }

        .docs-comment-body {
            color: var(--docs-text);
            line-height: 1.5;
            margin-bottom: 10px;
        }

        .docs-comment-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .docs-comment-actions {
            display: flex;
            gap: 15px;
        }

        .docs-comment-action {
            display: flex;
            align-items: center;
            gap: 5px;
            color: var(--docs-text-muted);
            font-size: 12px;
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .docs-comment-action:hover {
            color: var(--docs-accent);
        }

        /* Поиск */
        .docs-search-results {
            margin-top: 20px;
        }

        .docs-search-count {
            color: var(--docs-text-muted);
            font-size: 14px;
            margin-bottom: 20px;
        }

        .docs-search-item {
            background: var(--docs-content-bg);
            border: 1px solid var(--docs-border);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 15px;
            transition: all 0.3s ease;
        }

        .docs-search-item:hover {
            border-color: var(--docs-accent);
            box-shadow: var(--docs-shadow-hover);
        }

        .docs-search-item-title {
            color: var(--docs-text);
            font-size: 18px;
            margin: 0 0 10px 0;
        }

        .docs-search-item-title a {
            color: inherit;
            text-decoration: none;
        }

        .docs-search-item-title a:hover {
            color: var(--docs-accent);
        }

        .docs-search-item-excerpt {
            color: var(--docs-text-secondary);
            font-size: 14px;
            line-height: 1.5;
            margin-bottom: 10px;
        }

        .docs-search-item-meta {
            display: flex;
            gap: 15px;
            font-size: 12px;
            color: var(--docs-text-muted);
        }

        /* Пустое состояние */
        .docs-empty {
            text-align: center;
            padding: 60px 20px;
            color: var(--docs-text-secondary);
        }

        .docs-empty i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
        }

        .docs-empty h3 {
            color: var(--docs-text);
            margin: 0 0 8px 0;
            font-size: 18px;
        }

        .docs-empty p {
            margin: 0;
            font-size: 14px;
        }

        /* Кнопки */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--docs-accent), #0097a7);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--docs-shadow-hover);
        }

        .btn-secondary {
            background: var(--docs-content-bg);
            color: var(--docs-text);
            border: 1px solid var(--docs-border);
        }

        .btn-secondary:hover {
            background: var(--docs-hover);
            border-color: var(--docs-accent);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--docs-success), #059669);
            color: white;
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--docs-warning), #d97706);
            color: white;
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--docs-danger), #dc2626);
            color: white;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--docs-border);
            color: var(--docs-text);
        }

        .btn-outline:hover {
            background: var(--docs-hover);
            border-color: var(--docs-accent);
        }

        /* Уведомления */
        .alert {
            padding: 16px 20px;
            border-radius: 8px;
            margin-bottom: 24px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            border: 1px solid transparent;
            animation: fadeIn 0.3s ease;
        }

        .alert-success {
            background-color: rgba(16, 185, 129, 0.1);
            border-color: rgba(16, 185, 129, 0.2);
            color: var(--docs-success);
        }

        .alert-error {
            background-color: rgba(239, 68, 68, 0.1);
            border-color: rgba(239, 68, 68, 0.2);
            color: var(--docs-danger);
        }

        .alert-info {
            background-color: rgba(59, 130, 246, 0.1);
            border-color: rgba(59, 130, 246, 0.2);
            color: var(--docs-info);
        }

        .alert i {
            font-size: 18px;
            margin-top: 2px;
        }

        .alert p {
            margin: 4px 0 0 0;
            font-size: 14px;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Хлебные крошки */
        .docs-breadcrumb {
            margin-bottom: 20px;
        }

        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 8px;
            list-style: none;
            padding: 0;
            margin: 0;
            font-size: 14px;
            color: var(--docs-text-muted);
        }

        .breadcrumb-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .breadcrumb-item a {
            color: var(--docs-text-secondary);
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .breadcrumb-item a:hover {
            color: var(--docs-accent);
        }

        .breadcrumb-item.active {
            color: var(--docs-text);
        }

        .breadcrumb-divider {
            color: var(--docs-border);
        }

        /* Статистика */
        .docs-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .docs-stat-card {
            background: var(--docs-content-bg);
            border: 1px solid var(--docs-border);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
        }

        body.dark-theme .docs-stat-card {
            background: rgba(30, 41, 59, 0.7);
            border-color: rgba(255, 255, 255, 0.1);
        }

        .docs-stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: white;
            margin: 0 auto 15px;
            background: linear-gradient(135deg, var(--docs-accent), #0097a7);
        }

        .docs-stat-value {
            color: var(--docs-text);
            font-size: 28px;
            font-weight: 700;
            margin: 0 0 8px 0;
        }

        .docs-stat-label {
            color: var(--docs-text-secondary);
            font-size: 14px;
            margin: 0;
        }

        /* Пагинация */
        .docs-pagination {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-top: 30px;
        }

        .pagination-item {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--docs-content-bg);
            border: 1px solid var(--docs-border);
            color: var(--docs-text-secondary);
            text-decoration: none;
            transition: all 0.3s ease;
            font-size: 14px;
        }

        .pagination-item:hover {
            background: var(--docs-hover);
            border-color: var(--docs-accent);
            color: var(--docs-accent);
        }

        .pagination-item.active {
            background: var(--docs-accent);
            border-color: var(--docs-accent);
            color: white;
        }

        .pagination-item.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Адаптивность */
        @media (max-width: 1200px) {
            .main-content {
                margin-right: 70px;
                width: calc(100% - 350px); /* 280px + 70px */
            }

            .sidebar-collapsed .main-content {
                width: calc(100% - 150px); /* 80px + 70px */
            }

            .docs-sidebar-right {
                width: 70px;
            }

            .docs-category-name-right,
            .docs-category-count-right,
            .docs-sidebar-right-header h3 span,
            .docs-stats-right {
                display: none;
            }

            .docs-search-right input {
                display: none;
            }
        }

        @media (max-width: 992px) {
            .main-content {
                margin-left: 0;
                margin-right: 0;
                width: 100%;
                padding: 20px;
            }

            .sidebar-collapsed .main-content {
                margin-left: 0;
                margin-right: 0;
                width: 100%;
            }

            .docs-sidebar-right {
                position: fixed;
                top: 70px;
                right: -280px;
                z-index: 1000;
                width: 280px;
                height: calc(100vh - 70px);
                transition: right 0.3s ease;
                background: var(--docs-sidebar-bg);
            }

            body.dark-theme .docs-sidebar-right {
                background: rgba(30, 41, 59, 0.95);
            }

            .docs-sidebar-right.active {
                right: 0;
            }

            .docs-sidebar-right-overlay {
                display: none;
                position: fixed;
                top: 70px;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0, 0, 0, 0.5);
                z-index: 999;
            }

            .docs-sidebar-right-overlay.active {
                display: block;
            }

            .docs-grid {
                grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            }
        }

        @media (max-width: 768px) {
            .docs-grid {
                grid-template-columns: 1fr;
            }

            .docs-article {
                padding: 20px;
            }

            .docs-article-meta {
                flex-direction: column;
                gap: 10px;
            }

            .docs-article-footer {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }

            .docs-sidebar-right {
                width: 100%;
                max-width: 280px;
            }
        }
        /* === ОБЩИЙ ФУТЕР === */
        /* Исправляем футер для правильного отображения */
        .modern-footer {
            background: var(--primary-gradient);
            padding: 80px 0 30px;
            color: rgba(255, 255, 255, 0.8);
            position: relative;
            overflow: hidden;
            margin-top: auto;
            width: 100%;
        }

        .modern-footer .container {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }

        .modern-footer::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(0, 188, 212, 0.5), transparent);
        }
    </style>
</head>
<body>
    <?php
    // Подключаем обновленную шапку
    include '../templates/headers/user_header.php';
    ?>

    <!-- Кнопка вверх -->
    <a href="#" class="scroll-to-top" id="scrollToTop">
        <i class="fas fa-chevron-up"></i>
    </a>

    <!-- Оверлей для мобильного правого сайдбара -->
    <div class="docs-sidebar-right-overlay" id="docsSidebarRightOverlay"></div>

    <div class="main-container">
        <?php
        // Подключаем обновленный сайдбар
        include '../templates/headers/user_sidebar.php';
        ?>

        <div class="main-content">
            <!-- Кнопка для показа правого сайдбара на мобильных -->
            <button class="btn btn-outline" id="toggleDocsSidebarBtn" style="margin-bottom: 20px; display: none;">
                <i class="fas fa-book"></i> Навигация по документации
            </button>

            <div class="docs-content-inner">
                <?php if ($message): ?>
                    <div class="alert alert-<?= $message_type === 'error' ? 'error' : 'success' ?>">
                        <i class="fas fa-<?= $message_type === 'error' ? 'exclamation-circle' : 'check-circle' ?>"></i>
                        <div><?= htmlspecialchars($message) ?></div>
                    </div>
                <?php endif; ?>

                <?php if ($action === 'list' || $action === 'search'): ?>
                    <!-- Список статей или результаты поиска -->
                    <div class="docs-header">
                        <h1>
                            <i class="fas fa-<?= $action === 'search' ? 'search' : 'book' ?>"></i>
                            <?php if ($action === 'search'): ?>
                                Результаты поиска
                            <?php elseif ($category_slug): ?>
                                <?php
                                    $category = getCategoryBySlug($pdo, $category_slug);
                                    echo htmlspecialchars($category['name'] ?? 'Категория');
                                ?>
                            <?php else: ?>
                                Все статьи
                            <?php endif; ?>
                        </h1>

                        <?php if ($action === 'search'): ?>
                            <p>По запросу "<?= htmlspecialchars($search_query) ?>"</p>
                        <?php else: ?>
                            <p>База знаний и документация по платформе HomeVlad Cloud</p>
                        <?php endif; ?>
                    </div>

                    <div class="docs-actions">
                        <?php if ($is_admin): ?>
                            <a href="/admin/docs_editor.php?action=create" class="btn btn-primary">
                                <i class="fas fa-plus"></i> Новая статья
                            </a>
                            <a href="/admin/docs_categories.php" class="btn btn-secondary">
                                <i class="fas fa-folder"></i> Управление категориями
                            </a>
                        <?php endif; ?>
                    </div>

                    <?php if ($action === 'search'): ?>
                        <!-- Результаты поиска -->
                        <div class="docs-search-results">
                            <div class="docs-search-count">
                                Найдено <?= count($search_results) ?> результатов
                            </div>

                            <?php if (empty($search_results)): ?>
                                <div class="docs-empty">
                                    <i class="fas fa-search"></i>
                                    <h3>Ничего не найдено</h3>
                                    <p>Попробуйте изменить поисковый запрос</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($search_results as $doc): ?>
                                    <div class="docs-search-item">
                                        <h3 class="docs-search-item-title">
                                            <a href="/docs/docs.php?action=view&doc=<?= $doc['slug'] ?>">
                                                <?= htmlspecialchars($doc['title']) ?>
                                                <?php if ($doc['is_featured']): ?>
                                                    <i class="fas fa-star" style="color: #ffd700; margin-left: 5px;"></i>
                                                <?php endif; ?>
                                            </a>
                                        </h3>
                                        <div class="docs-search-item-excerpt">
                                            <?= htmlspecialchars(substr($doc['excerpt'] ?? $doc['content'], 0, 200)) ?>...
                                        </div>
                                        <div class="docs-search-item-meta">
                                            <span><i class="fas fa-folder"></i> <?= htmlspecialchars($doc['category_name'] ?? 'Без категории') ?></span>
                                            <span><i class="fas fa-eye"></i> <?= $doc['view_count'] ?> просмотров</span>
                                            <span><i class="fas fa-calendar"></i> <?= date('d.m.Y', strtotime($doc['created_at'])) ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <!-- Популярные и избранные статьи -->
                        <?php if (!$category_slug && empty($search_results)): ?>
                            <div class="docs-stats-grid">
                                <div class="docs-stat-card">
                                    <div class="docs-stat-icon">
                                        <i class="fas fa-file-alt"></i>
                                    </div>
                                    <div class="docs-stat-value">
                                        <?php
                                            $total_docs_stmt = $pdo->prepare("SELECT COUNT(*) FROM docs WHERE status = 'published'");
                                            $total_docs_stmt->execute();
                                            echo $total_docs_stmt->fetchColumn();
                                        ?>
                                    </div>
                                    <p class="docs-stat-label">Всего статей</p>
                                </div>

                                <div class="docs-stat-card">
                                    <div class="docs-stat-icon" style="background: linear-gradient(135deg, #10b981, #059669);">
                                        <i class="fas fa-eye"></i>
                                    </div>
                                    <div class="docs-stat-value">
                                        <?php
                                            $total_views_stmt = $pdo->prepare("SELECT SUM(view_count) FROM docs");
                                            $total_views_stmt->execute();
                                            echo number_format($total_views_stmt->fetchColumn());
                                        ?>
                                    </div>
                                    <p class="docs-stat-label">Всего просмотров</p>
                                </div>

                                <div class="docs-stat-card">
                                    <div class="docs-stat-icon" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);">
                                        <i class="fas fa-comments"></i>
                                    </div>
                                    <div class="docs-stat-value">
                                        <?php
                                            $total_comments_stmt = $pdo->prepare("SELECT COUNT(*) FROM doc_comments");
                                            $total_comments_stmt->execute();
                                            echo $total_comments_stmt->fetchColumn();
                                        ?>
                                    </div>
                                    <p class="docs-stat-label">Комментариев</p>
                                </div>
                            </div>

                            <?php if (!empty($featured_docs)): ?>
                                <h2 style="color: var(--docs-text); margin-bottom: 20px;">
                                    <i class="fas fa-star" style="color: #ffd700;"></i> Избранные статьи
                                </h2>
                                <div class="docs-grid">
                                    <?php foreach ($featured_docs as $doc): ?>
                                        <div class="docs-card">
                                            <div class="docs-card-header">
                                                <div class="docs-card-icon" style="background: <?= $doc['category_color'] ?? '#00bcd4' ?>;">
                                                    <i class="fas <?= $doc['category_icon'] ?? 'fa-file' ?>"></i>
                                                </div>
                                                <div class="docs-card-title">
                                                    <h3><?= htmlspecialchars($doc['title']) ?></h3>
                                                    <div class="docs-card-category">
                                                        <i class="fas fa-folder"></i>
                                                        <?= htmlspecialchars($doc['category_name'] ?? 'Без категории') ?>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="docs-card-body">
                                                <p class="docs-card-excerpt"><?= htmlspecialchars(substr($doc['excerpt'] ?? '', 0, 150)) ?></p>
                                            </div>
                                            <div class="docs-card-footer">
                                                <div class="docs-card-meta">
                                                    <span class="docs-card-meta-item">
                                                        <i class="fas fa-eye"></i> <?= $doc['view_count'] ?>
                                                    </span>
                                                    <span class="docs-card-meta-item">
                                                        <i class="fas fa-calendar"></i> <?= date('d.m.Y', strtotime($doc['created_at'])) ?>
                                                    </span>
                                                </div>
                                                <div class="docs-card-actions">
                                                    <a href="/docs/docs.php?action=view&doc=<?= $doc['slug'] ?>"
                                                       class="docs-action-btn" title="Читать">
                                                        <i class="fas fa-book-open"></i>
                                                    </a>
                                                    <?php if ($is_admin): ?>
                                                        <a href="/admin/docs_editor.php?action=edit&id=<?= $doc['id'] ?>"
                                                           class="docs-action-btn" title="Редактировать">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>

                        <!-- Статьи в категории -->
                        <?php if ($category_slug):
                            $category = getCategoryBySlug($pdo, $category_slug);
                            $docs = getDocsByCategory($pdo, $category['id']);
                        ?>
                            <h2 style="color: var(--docs-text); margin-bottom: 20px;">Статьи в категории</h2>

                            <?php if (empty($docs)): ?>
                                <div class="docs-empty">
                                    <i class="fas fa-folder-open"></i>
                                    <h3>В этой категории пока нет статей</h3>
                                    <p>Будьте первым, кто добавит документацию в эту категорию</p>
                                    <?php if ($is_admin): ?>
                                        <a href="/admin/docs_editor.php?action=create&category=<?= $category['id'] ?>" class="btn btn-primary" style="margin-top: 15px;">
                                            <i class="fas fa-plus"></i> Добавить статью
                                        </a>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="docs-grid">
                                    <?php foreach ($docs as $doc): ?>
                                        <div class="docs-card">
                                            <div class="docs-card-header">
                                                <div class="docs-card-icon" style="background: <?= $category['color'] ?>;">
                                                    <i class="fas <?= $category['icon'] ?>"></i>
                                                </div>
                                                <div class="docs-card-title">
                                                    <h3><?= htmlspecialchars($doc['title']) ?></h3>
                                                    <div class="docs-card-category">
                                                        <i class="fas fa-user"></i>
                                                        <?= htmlspecialchars($doc['author_name'] ?? 'Неизвестный автор') ?>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="docs-card-body">
                                                <p class="docs-card-excerpt"><?= htmlspecialchars(substr($doc['excerpt'] ?? '', 0, 150)) ?></p>
                                            </div>
                                            <div class="docs-card-footer">
                                                <div class="docs-card-meta">
                                                    <span class="docs-card-meta-item">
                                                        <i class="fas fa-eye"></i> <?= $doc['view_count'] ?>
                                                    </span>
                                                    <span class="docs-card-meta-item">
                                                        <i class="fas fa-calendar"></i> <?= date('d.m.Y', strtotime($doc['created_at'])) ?>
                                                    </span>
                                                </div>
                                                <div class="docs-card-actions">
                                                    <a href="/docs/docs.php?action=view&doc=<?= $doc['slug'] ?>"
                                                       class="docs-action-btn" title="Читать">
                                                        <i class="fas fa-book-open"></i>
                                                    </a>
                                                    <?php if ($is_admin): ?>
                                                        <a href="/admin/docs_editor.php?action=edit&id=<?= $doc['id'] ?>"
                                                           class="docs-action-btn" title="Редактировать">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php endif; ?>

                <?php elseif ($action === 'view' && $doc): ?>
                    <!-- Просмотр статьи -->
                    <div class="docs-breadcrumb">
                        <ul class="breadcrumb">
                            <li class="breadcrumb-item">
                                <a href="/docs/docs.php"><i class="fas fa-home"></i></a>
                            </li>
                            <li class="breadcrumb-divider">/</li>
                            <?php if ($doc['category_name']): ?>
                                <li class="breadcrumb-item">
                                    <a href="/docs/docs.php?action=list&category=<?= $doc['category_slug'] ?>">
                                        <?= htmlspecialchars($doc['category_name']) ?>
                                    </a>
                                </li>
                                <li class="breadcrumb-divider">/</li>
                            <?php endif; ?>
                            <li class="breadcrumb-item active">
                                <?= htmlspecialchars($doc['title']) ?>
                            </li>
                        </ul>
                    </div>

                    <div class="docs-article">
                        <div class="docs-article-header">
                            <h1 class="docs-article-title"><?= htmlspecialchars($doc['title']) ?></h1>

                            <div class="docs-article-meta">
                                <div class="docs-article-author">
                                    <div class="docs-article-author-avatar">
                                        <?= strtoupper(substr($doc['author_name'], 0, 1)) ?>
                                    </div>
                                    <div class="docs-article-author-info">
                                        <h4><?= htmlspecialchars($doc['author_name']) ?></h4>
                                        <p><?= date('d.m.Y H:i', strtotime($doc['created_at'])) ?></p>
                                    </div>
                                </div>

                                <div class="docs-article-meta-item">
                                    <i class="fas fa-eye"></i> <?= $doc['view_count'] ?> просмотров
                                </div>

                                <div class="docs-article-meta-item">
                                    <i class="fas fa-folder"></i>
                                    <a href="/docs/docs.php?action=list&category=<?= $doc['category_slug'] ?>">
                                        <?= htmlspecialchars($doc['category_name']) ?>
                                    </a>
                                </div>

                                <?php if ($doc['is_featured']): ?>
                                    <div class="docs-article-meta-item">
                                        <i class="fas fa-star" style="color: #ffd700;"></i> Избранная статья
                                    </div>
                                <?php endif; ?>
                            </div>

                            <?php
                                $tags = getDocTags($pdo, $doc['id']);
                                if (!empty($tags)):
                            ?>
                                <div class="docs-article-tags">
                                    <?php foreach ($tags as $tag): ?>
                                        <a href="#" class="docs-tag"><?= htmlspecialchars($tag['name']) ?></a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="docs-article-content">
                            <?= $doc['content'] ?>
                        </div>

                        <div class="docs-article-footer">
                            <div class="docs-article-actions">
                                <button class="btn btn-secondary" onclick="copyLink()" title="Скопировать ссылку">
                                    <i class="fas fa-link"></i> Поделиться
                                </button>

                                <a href="/docs/docs.php?action=bookmark&doc=<?= $doc['slug'] ?>"
                                   class="btn btn-outline" title="Добавить в закладки">
                                    <i class="fas fa-bookmark"></i>
                                    <?= isBookmarked($pdo, $doc['id'], $user_id) ? 'В закладках' : 'В закладки' ?>
                                </a>

                                <?php if ($is_admin): ?>
                                    <a href="/admin/docs_editor.php?action=edit&id=<?= $doc['id'] ?>"
                                       class="btn btn-primary">
                                        <i class="fas fa-edit"></i> Редактировать
                                    </a>
                                <?php endif; ?>
                            </div>

                            <?php if ($doc['updated_at'] !== $doc['created_at']): ?>
                                <div style="color: var(--docs-text-muted); font-size: 12px;">
                                    <i class="fas fa-history"></i> Обновлено: <?= date('d.m.Y H:i', strtotime($doc['updated_at'])) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Рейтинг статьи -->
                    <div class="docs-rating">
                        <?php
                            $rating_stats = getDocRating($pdo, $doc['id']);
                            $user_rating = getUserRating($pdo, $doc['id'], $user_id);
                        ?>
                        <div class="docs-rating-header">
                            <h3 class="docs-rating-title">Оцените статью</h3>
                            <div class="docs-rating-stats">
                                <div class="docs-rating-average">
                                    <?= $rating_stats['average_rating'] ? number_format($rating_stats['average_rating'], 1) : '0.0' ?>
                                </div>
                                <div class="docs-rating-stars">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <?php $filled = $rating_stats['average_rating'] >= $i; ?>
                                        <i class="fas fa-star<?= $filled ? '' : '-o' ?>"></i>
                                    <?php endfor; ?>
                                </div>
                                <div class="docs-rating-count">
                                    (<?= $rating_stats['total_votes'] ?> оценок)
                                </div>
                            </div>
                        </div>

                        <?php if ($rating_stats['total_votes'] > 0): ?>
                            <div class="docs-rating-bars">
                                <?php for ($i = 5; $i >= 1; $i--):
                                    $count = $rating_stats["stars_$i"] ?? 0;
                                    $percent = $rating_stats['total_votes'] > 0 ? ($count / $rating_stats['total_votes']) * 100 : 0;
                                ?>
                                    <div class="docs-rating-bar">
                                        <div class="docs-rating-label"><?= $i ?>★</div>
                                        <div class="docs-rating-progress">
                                            <div class="docs-rating-progress-fill" style="width: <?= $percent ?>%"></div>
                                        </div>
                                        <div class="docs-rating-percent"><?= round($percent) ?>%</div>
                                    </div>
                                <?php endfor; ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!$user_rating): ?>
                            <form class="docs-rating-form" method="POST" action="/docs/docs.php?action=rate&doc=<?= $doc['slug'] ?>">
                                <div class="docs-rating-form-title">Ваша оценка:</div>
                                <div class="docs-rating-stars-input" id="ratingStars">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <i class="fas fa-star docs-rating-star" data-rating="<?= $i ?>"></i>
                                    <?php endfor; ?>
                                </div>
                                <input type="hidden" name="rating" id="ratingInput" value="0">
                                <button type="submit" class="btn btn-sm btn-primary" disabled id="ratingSubmit">
                                    <i class="fas fa-star"></i> Оценить
                                </button>
                            </form>
                        <?php else: ?>
                            <div class="docs-rating-form">
                                <div class="docs-rating-form-title">
                                    <i class="fas fa-check-circle" style="color: var(--docs-success);"></i>
                                    Вы оценили эту статью на <?= $user_rating ?>★
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Комментарии -->
                    <div class="docs-comments">
                        <div class="docs-comments-header">
                            <h3 class="docs-comments-title">Комментарии</h3>
                            <div class="docs-comments-count">
                                <?php
                                    $comments = getDocComments($pdo, $doc['id']);
                                    echo count($comments) . ' комментариев';
                                ?>
                            </div>
                        </div>

                        <?php if ($doc['allow_comments']): ?>
                            <form class="docs-comments-form" method="POST" action="/docs/docs.php?action=add_comment&doc=<?= $doc['slug'] ?>">
                                <input type="hidden" name="doc_id" value="<?= $doc['id'] ?>">
                                <textarea name="content" placeholder="Оставьте ваш комментарий..." required></textarea>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-paper-plane"></i> Отправить
                                </button>
                            </form>
                        <?php endif; ?>

                        <div class="docs-comments-list">
                            <?php if (empty($comments)): ?>
                                <div class="docs-empty">
                                    <i class="fas fa-comments"></i>
                                    <h3>Комментариев пока нет</h3>
                                    <p>Будьте первым, кто оставит комментарий</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($comments as $comment): ?>
                                    <div class="docs-comment">
                                        <div class="docs-comment-header">
                                            <div class="docs-comment-author">
                                                <div class="docs-comment-author-avatar">
                                                    <?= strtoupper(substr($comment['user_name'], 0, 1)) ?>
                                                </div>
                                                <div class="docs-comment-author-info">
                                                    <h4>
                                                        <?= htmlspecialchars($comment['user_name']) ?>
                                                        <?php if ($comment['is_admin']): ?>
                                                            <span class="docs-comment-admin-badge">Админ</span>
                                                        <?php endif; ?>
                                                    </h4>
                                                    <p><?= date('d.m.Y H:i', strtotime($comment['created_at'])) ?></p>
                                                </div>
                                            </div>
                                            <?php if ($comment['is_resolved']): ?>
                                                <span style="color: var(--docs-success); font-size: 12px;">
                                                    <i class="fas fa-check-circle"></i> Решено
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="docs-comment-body">
                                            <?= nl2br(htmlspecialchars($comment['content'])) ?>
                                        </div>
                                        <div class="docs-comment-footer">
                                            <div class="docs-comment-actions">
                                                <a href="#" class="docs-comment-action" onclick="likeComment(<?= $comment['id'] ?>)">
                                                    <i class="fas fa-thumbs-up"></i> <?= $comment['likes'] ?>
                                                </a>
                                                <a href="#" class="docs-comment-action" onclick="dislikeComment(<?= $comment['id'] ?>)">
                                                    <i class="fas fa-thumbs-down"></i> <?= $comment['dislikes'] ?>
                                                </a>
                                                <a href="#" class="docs-comment-action" onclick="replyComment(<?= $comment['id'] ?>, '<?= htmlspecialchars($comment['user_name']) ?>')">
                                                    <i class="fas fa-reply"></i> Ответить
                                                </a>
                                            </div>
                                            <?php if ($is_admin): ?>
                                                <div>
                                                    <a href="#" class="docs-comment-action" onclick="resolveComment(<?= $comment['id'] ?>)">
                                                        <i class="fas fa-check"></i> Отметить решенным
                                                    </a>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Правый сайдбар документации -->
        <div class="docs-sidebar-right" id="docsSidebarRight">
            <div class="docs-sidebar-right-header">
                <h3><i class="fas fa-book"></i> <span>Документация</span></h3>
            </div>

            <div class="docs-search-right">
                <form action="/docs/docs.php" method="GET">
                    <input type="hidden" name="action" value="search">
                    <input type="text" name="q" placeholder="Поиск по документации..."
                           value="<?= htmlspecialchars($search_query) ?>">
                </form>
            </div>

            <div class="docs-categories-right">
                <?php foreach ($categories as $category):
                    $doc_count_stmt = $pdo->prepare("SELECT COUNT(*) FROM docs WHERE category_id = ? AND status = 'published'");
                    $doc_count_stmt->execute([$category['id']]);
                    $doc_count = $doc_count_stmt->fetchColumn();
                ?>
                <div class="docs-category-item-right">
                    <a href="/docs/docs.php?action=list&category=<?= $category['slug'] ?>"
                       class="docs-category-link-right <?= ($category_slug === $category['slug']) ? 'active' : '' ?>">
                        <div class="docs-category-icon-right" style="color: <?= $category['color'] ?>">
                            <i class="fas <?= $category['icon'] ?>"></i>
                        </div>
                        <span class="docs-category-name-right"><?= htmlspecialchars($category['name']) ?></span>
                        <span class="docs-category-count-right"><?= $doc_count ?></span>
                    </a>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="docs-sidebar-right-footer">
                <div class="docs-stats-right">
                    <i class="fas fa-file-alt"></i>
                    <?php
                        $total_docs_stmt = $pdo->prepare("SELECT COUNT(*) FROM docs WHERE status = 'published'");
                        $total_docs_stmt->execute();
                        echo $total_docs_stmt->fetchColumn() . ' статей';
                    ?>
                </div>
            </div>
        </div>
    </div>

    <?php
    // Подключаем общий футер из файла - ТОЛЬКО если файл существует
    $footer_file = __DIR__ . '/../templates/headers/user_footer.php';
    if (file_exists($footer_file)) {
        include $footer_file;
    }
    // Если файл не найден - футер просто не отображается
    ?>

    <script>
        // Глобальные переменные
        let ratingStars = null;
        let ratingInput = null;
        let ratingSubmit = null;

        document.addEventListener('DOMContentLoaded', function() {
            // Инициализация элементов рейтинга
            ratingStars = document.querySelectorAll('.docs-rating-star');
            ratingInput = document.getElementById('ratingInput');
            ratingSubmit = document.getElementById('ratingSubmit');

            // Кнопка "Наверх"
            const scrollToTopBtn = document.getElementById('scrollToTop');

            window.addEventListener('scroll', function() {
                if (window.pageYOffset > 300) {
                    scrollToTopBtn.classList.add('visible');
                } else {
                    scrollToTopBtn.classList.remove('visible');
                }
            });

            scrollToTopBtn.addEventListener('click', function(e) {
                e.preventDefault();
                window.scrollTo({
                    top: 0,
                    behavior: 'smooth'
                });
            });

            // Плавная прокрутка для внутренних ссылок
            document.querySelectorAll('a[href^="#"]').forEach(anchor => {
                anchor.addEventListener('click', function (e) {
                    if (this.getAttribute('href') === '#') return;

                    e.preventDefault();
                    const targetId = this.getAttribute('href');
                    if (targetId.startsWith('#')) {
                        const targetElement = document.querySelector(targetId);
                        if (targetElement) {
                            window.scrollTo({
                                top: targetElement.offsetTop - 100,
                                behavior: 'smooth'
                            });
                        }
                    } else {
                        window.location.href = this.getAttribute('href');
                    }
                });
            });

            // Инициализация рейтинга
            if (ratingStars) {
                ratingStars.forEach(star => {
                    star.addEventListener('mouseover', () => {
                        const rating = parseInt(star.dataset.rating);
                        highlightStars(rating);
                    });

                    star.addEventListener('mouseout', () => {
                        const currentRating = parseInt(ratingInput?.value || 0);
                        highlightStars(currentRating);
                    });

                    star.addEventListener('click', () => {
                        const rating = parseInt(star.dataset.rating);
                        if (ratingInput) ratingInput.value = rating;
                        highlightStars(rating);
                        if (ratingSubmit) ratingSubmit.disabled = false;
                    });
                });
            }

            // Обработка уведомлений из сессии
            <?php if (isset($_SESSION['message'])): ?>
                showNotification("<?= addslashes($_SESSION['message']) ?>", "<?= $_SESSION['message_type'] ?? 'info' ?>");
                <?php unset($_SESSION['message'], $_SESSION['message_type']); ?>
            <?php endif; ?>

            // Управление правым сайдбаром на мобильных
            handleRightSidebar();
            window.addEventListener('resize', handleRightSidebar);
        });

        // Функция для подсветки звезд рейтинга
        function highlightStars(rating) {
            if (!ratingStars) return;

            ratingStars.forEach(star => {
                const starRating = parseInt(star.dataset.rating);
                star.classList.toggle('active', starRating <= rating);
                star.classList.toggle('fa-star-o', starRating > rating);
                star.classList.toggle('fa-star', starRating <= rating);
            });
        }

        // Функция для показа уведомлений
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.innerHTML = `
                <i class="fas ${type === 'success' ? 'fa-check-circle' :
                               type === 'error' ? 'fa-exclamation-circle' :
                               type === 'warning' ? 'fa-exclamation-triangle' :
                               'fa-info-circle'}"></i>
                <span>${message}</span>
                <button onclick="this.parentElement.remove()" style="margin-left: auto; background: none; border: none; color: white; cursor: pointer;">
                    <i class="fas fa-times"></i>
                </button>
            `;

            document.body.appendChild(notification);

            setTimeout(() => {
                if (notification.parentElement) {
                    notification.style.animation = 'slideOut 0.3s ease forwards';
                    setTimeout(() => notification.remove(), 300);
                }
            }, 5000);
        }

        // Управление правым сайдбаром
        function handleRightSidebar() {
            const sidebar = document.getElementById('docsSidebarRight');
            const overlay = document.getElementById('docsSidebarRightOverlay');
            const toggleBtn = document.getElementById('toggleDocsSidebarBtn');

            if (window.innerWidth <= 992) {
                // На мобильных - скрываем правый сайдбар, показываем кнопку
                if (sidebar) sidebar.classList.remove('active');
                if (overlay) overlay.classList.remove('active');
                if (toggleBtn) toggleBtn.style.display = 'inline-flex';

                // Добавляем обработчик для кнопки
                if (toggleBtn) {
                    toggleBtn.onclick = function() {
                        if (sidebar) sidebar.classList.toggle('active');
                        if (overlay) overlay.classList.toggle('active');
                    };
                }

                // Добавляем обработчик для оверлея
                if (overlay) {
                    overlay.onclick = function() {
                        sidebar.classList.remove('active');
                        overlay.classList.remove('active');
                    };
                }
            } else {
                // На десктопе - всегда показываем правый сайдбар
                if (sidebar) sidebar.classList.add('active');
                if (overlay) overlay.classList.remove('active');
                if (toggleBtn) toggleBtn.style.display = 'none';
            }
        }

        // Копирование ссылки
        function copyLink() {
            const url = window.location.href;
            navigator.clipboard.writeText(url).then(() => {
                showNotification('Ссылка скопирована в буфер обмена', 'success');
            });
        }

        // Действия с комментариями
        function likeComment(commentId) {
            // Здесь будет AJAX запрос для лайка
            showNotification('Лайк отправлен', 'info');
        }

        function dislikeComment(commentId) {
            // Здесь будет AJAX запрос для дизлайка
            showNotification('Дизлайк отправлен', 'info');
        }

        function replyComment(commentId, userName) {
            const form = document.querySelector('.docs-comments-form textarea');
            if (form) {
                form.value = `@${userName}, `;
                form.focus();
            }
        }

        function resolveComment(commentId) {
            if (confirm('Отметить комментарий как решенный?')) {
                // Здесь будет AJAX запрос для отметки как решенного
                showNotification('Комментарий отмечен как решенный', 'success');
            }
        }

        // Поиск с автодополнением
        const searchInput = document.querySelector('.docs-search-right input');
        if (searchInput) {
            let timeout = null;

            searchInput.addEventListener('input', function() {
                clearTimeout(timeout);
                timeout = setTimeout(() => {
                    if (this.value.length >= 2) {
                        fetchSearchSuggestions(this.value);
                    }
                }, 300);
            });
        }

        function fetchSearchSuggestions(query) {
            fetch(`/api/docs_search.php?q=${encodeURIComponent(query)}`)
                .then(response => response.json())
                .then(data => {
                    // Здесь можно отобразить подсказки
                    console.log('Search suggestions:', data);
                })
                .catch(error => console.error('Search error:', error));
        }

        // Закладки
        const bookmarkBtn = document.querySelector('[href*="action=bookmark"]');
        if (bookmarkBtn) {
            bookmarkBtn.addEventListener('click', function(e) {
                if (!this.href.includes('action=bookmark')) return;

                e.preventDefault();
                fetch(this.href)
                    .then(response => response.text())
                    .then(() => {
                        location.reload();
                    })
                    .catch(error => console.error('Bookmark error:', error));
            });
        }

        // Добавляем стили для анимаций уведомлений и кнопки наверх
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideIn {
                from {
                    transform: translateX(100%);
                    opacity: 0;
                }
                to {
                    transform: translateX(0);
                    opacity: 1;
                }
            }

            @keyframes slideOut {
                from {
                    transform: translateX(0);
                    opacity: 1;
                }
                to {
                    transform: translateX(100%);
                    opacity: 0;
                }
            }

            .notification {
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 15px 25px;
                border-radius: 12px;
                color: white;
                font-weight: 600;
                z-index: 9999;
                animation: slideIn 0.3s ease;
                box-shadow: 0 10px 25px rgba(0,0,0,0.2);
                display: flex;
                align-items: center;
                gap: 10px;
                max-width: 400px;
            }

            .notification.success {
                background: linear-gradient(135deg, #10b981, #059669);
            }

            .notification.error {
                background: linear-gradient(135deg, #ef4444, #dc2626);
            }

            .notification.warning {
                background: linear-gradient(135deg, #f59e0b, #d97706);
            }

            .notification.info {
                background: linear-gradient(135deg, #00bcd4, #0097a7);
            }

            .scroll-to-top {
                position: fixed;
                bottom: 30px;
                right: 30px;
                width: 50px;
                height: 50px;
                background: linear-gradient(135deg, #00bcd4, #0097a7);
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                color: white;
                text-decoration: none;
                box-shadow: 0 8px 25px rgba(0, 188, 212, 0.4);
                transition: all 0.3s ease;
                opacity: 0;
                visibility: hidden;
                z-index: 999;
            }

            .scroll-to-top.visible {
                opacity: 1;
                visibility: visible;
            }

            .scroll-to-top:hover {
                transform: translateY(-5px);
                box-shadow: 0 12px 30px rgba(0, 188, 212, 0.5);
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>
