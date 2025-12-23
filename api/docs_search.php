<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

try {
    $db = new Database();
    $pdo = $db->getConnection();
    
    $query = $_GET['q'] ?? '';
    
    if (strlen($query) < 2) {
        echo json_encode([]);
        exit;
    }
    
    $search_query = "%$query%";
    $stmt = $pdo->prepare("
        SELECT id, title, slug
        FROM docs 
        WHERE status = 'published' 
          AND (title LIKE ? OR excerpt LIKE ?)
        ORDER BY is_featured DESC, view_count DESC
        LIMIT 10
    ");
    
    $stmt->execute([$search_query, $search_query]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($results);
    
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}