<?php
session_start();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Доступ запрещен']);
    exit;
}

header('Content-Type: application/json');

try {
    $db = new Database();
    $pdo = $db->getConnection();
    
    $data = json_decode(file_get_contents('php://input'), true);
    $category_id = $data['id'] ?? 0;
    
    if (!$category_id) {
        throw new Exception('Не указан ID категории');
    }
    
    // Начинаем транзакцию
    $pdo->beginTransaction();
    
    // Перемещаем статьи в категорию "Без категории" (NULL)
    $stmt = $pdo->prepare("UPDATE docs SET category_id = NULL WHERE category_id = ?");
    $stmt->execute([$category_id]);
    
    // Удаляем подкатегории
    $stmt = $pdo->prepare("UPDATE doc_categories SET parent_id = NULL WHERE parent_id = ?");
    $stmt->execute([$category_id]);
    
    // Удаляем саму категорию
    $stmt = $pdo->prepare("DELETE FROM doc_categories WHERE id = ?");
    $stmt->execute([$category_id]);
    
    $pdo->commit();
    
    echo json_encode(['success' => true, 'message' => 'Категория удалена']);
    
} catch (Exception $e) {
    if (isset($pdo)) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}