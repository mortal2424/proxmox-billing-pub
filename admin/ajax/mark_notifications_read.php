<?php
session_start();
require_once '../../includes/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$admin_id = $_SESSION['admin_id'];

$db = new Database();
$pdo = $db->getConnection();

try {
    $stmt = $pdo->prepare("
        UPDATE admin_notifications 
        SET is_read = 1, read_at = NOW() 
        WHERE admin_id = ? AND is_read = 0
    ");
    $stmt->execute([$admin_id]);
    
    echo json_encode(['success' => true, 'updated' => $stmt->rowCount()]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}