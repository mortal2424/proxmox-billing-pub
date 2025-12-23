<?php
require_once __DIR__ . '/../includes/db.php';

$db = new Database();
$pdo = $db->getConnection();

// Удаляем данные старше 1 года для VM
$stmt = $pdo->prepare("DELETE FROM vm_metrics WHERE timestamp < DATE_SUB(NOW(), INTERVAL 1 YEAR)");
$stmt->execute();
$vmDeleted = $stmt->rowCount();

// Удаляем данные старше 1 года для LXC
$stmt = $pdo->prepare("DELETE FROM lxc_metrics WHERE timestamp < DATE_SUB(NOW(), INTERVAL 1 YEAR)");
$stmt->execute();
$lxcDeleted = $stmt->rowCount();

// Логируем результат
error_log("Cleaned old metrics: VM - $vmDeleted records, LXC - $lxcDeleted records");