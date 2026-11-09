<?php
require_once __DIR__ . '/../includes/cli_only.php';
require_once __DIR__ . '/../includes/db.php';

$guides = require __DIR__ . '/user-guides.php';
$pdo = (new Database())->getConnection();
$author = $pdo->query('SELECT id FROM users WHERE is_admin = 1 AND is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
if (!$author) {
    fwrite(STDERR, "Сначала создайте активного администратора.\n");
    exit(1);
}

$pdo->beginTransaction();
try {
    $category = $pdo->prepare('INSERT INTO doc_categories (name, slug, icon, sort_order)
        VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)');
    $article = $pdo->prepare("INSERT INTO docs
        (category_id, title, slug, content, excerpt, author_id, status, allow_comments, published_at)
        VALUES (?, ?, ?, ?, ?, ?, 'published', 0, NOW()) ON DUPLICATE KEY UPDATE id = id");
    $added = 0;
    $order = 0;
    foreach ($guides as $key => [$name, $articles]) {
        $category->execute([$name, 'user-guide-' . $key, 'fa-book', ++$order]);
        $categoryId = $pdo->lastInsertId();
        foreach ($articles as [$slug, $title, $paragraphs]) {
            $html = '<ol><li>' . implode('</li><li>', array_map(
                static fn ($paragraph) => htmlspecialchars($paragraph, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                $paragraphs
            )) . '</li></ol>';
            $article->execute([$categoryId, $title, 'user-guide-' . $slug, $html, $paragraphs[0], $author]);
            $added += $article->rowCount() === 1 ? 1 : 0;
        }
    }
    $pdo->commit();
    echo "Опубликовано новых инструкций: {$added}\n";
} catch (Throwable $exception) {
    $pdo->rollBack();
    throw $exception;
}
