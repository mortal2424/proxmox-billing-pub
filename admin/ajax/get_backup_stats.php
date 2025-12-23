<?php
session_start();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/backup_functions.php';

checkAuth();

$db = new Database();
$pdo = $db->getConnection();

// Конфигурация
$backup_dir = BACKUP_DIR;

// Получаем список бэкапов
function getBackupListSimple($backup_dir) {
    $backups = [];
    $files = glob($backup_dir . '*.zip');
    
    foreach ($files as $file) {
        $size = filesize($file);
        $modified = filemtime($file);
        $backups[] = [
            'size' => $size,
            'modified' => $modified
        ];
    }
    
    usort($backups, function($a, $b) {
        return $b['modified'] <=> $a['modified'];
    });
    
    return $backups;
}

function formatBytes($bytes) {
    if ($bytes == 0) return '0 Bytes';
    $units = array('Bytes', 'KB', 'MB', 'GB', 'TB');
    $i = floor(log($bytes, 1024));
    return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
}

$backups = getBackupListSimple($backup_dir);

// Вычисляем статистику
$total_backup_size = 0;
$last_backup_size = 0;

if (!empty($backups)) {
    $last_backup_size = $backups[0]['size'];
    foreach ($backups as $backup) {
        $total_backup_size += $backup['size'];
    }
}

// Возвращаем JSON
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'last_backup_size' => $last_backup_size,
    'total_backup_size' => $total_backup_size,
    'free_space' => disk_free_space($backup_dir)
]);
?>