<?php
require_once 'includes/db.php';

$db = new Database();
$pdo = $db->getConnection();

$email = 'admin@homevlad.ru';
$password = password_hash('nt[ybr275nt[ybr275', PASSWORD_BCRYPT);

$stmt = $pdo->prepare("INSERT INTO users (email, password_hash, is_admin) VALUES (?, ?, 1)");
$stmt->execute([$email, $password]);

echo "Администратор создан! Логин: $email";
?>