<?php
// ВРЕМЕННЫЙ ФАЙЛ ДЛЯ ОТЛАДКИ - УДАЛИТЬ ПОСЛЕ ФИКСА
// backup_debug.php - временная версия без сложной проверки сессий

// Определяем корень проекта
define('PROJECT_ROOT', realpath(dirname(__FILE__) . '/../'));
define('DEBUG_MODE', true);

// Включаем отладку
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Простая проверка доступа - ВНИМАНИЕ: для отладки только!
if (!isset($_GET['debug_token']) || $_GET['debug_token'] !== 'temp_allow_123') {
    die("Требуется debug_token для доступа. Добавьте ?debug_token=temp_allow_123 к URL");
}

// Подключаем необходимые файлы
$files_to_require = [
    PROJECT_ROOT . '/includes/db.php',
    PROJECT_ROOT . '/includes/backup_functions.php'
];

foreach ($files_to_require as $file) {
    if (!file_exists($file)) {
        die("Файл не найден: $file");
    }
    require_once $file;
}

// Создаем подключение к базе
try {
    $db = new Database();
    $pdo = $db->getConnection();
    
    // Берем первого администратора из базы
    $stmt = $pdo->query("SELECT id FROM users WHERE is_admin = 1 ORDER BY id LIMIT 1");
    $admin = $stmt->fetch();
    
    if (!$admin) {
        die("Не найден администратор в базе данных. Создайте хотя бы одного администратора.");
    }
    
    $user_id = $admin['id'];
    echo "<!-- DEBUG: Используем пользователя ID: $user_id -->\n";
    
} catch (Exception $e) {
    die("Ошибка подключения к базе данных: " . $e->getMessage());
}

// Конфигурация резервного копирования
$backup_dir = PROJECT_ROOT . '/backups/';
$project_root = PROJECT_ROOT;
$max_backups = 50;
$backup_types = [
    'full' => 'Полный бэкап (файлы + БД)',
    'files' => 'Только файлы',
    'db' => 'Только база данных'
];

// Создаем папку для бэкапов, если ее нет
if (!file_exists($backup_dir)) {
    if (!mkdir($backup_dir, 0755, true)) {
        die("Не удалось создать директорию для бэкапов: $backup_dir");
    }
}

// УПРОЩЕННЫЕ ФУНКЦИИ ДЛЯ ОТЛАДКИ

function formatBytesss($bytes) {
    if ($bytes == 0) return '0 Bytes';
    $units = array('Bytes', 'KB', 'MB', 'GB', 'TB');
    $i = floor(log($bytes, 1024));
    return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
}

// Минимальная версия функции логирования
function logBackupAction($pdo, $user_id, $action, $filename, $details) {
    try {
        error_log("Backup action: $action, File: $filename, Details: $details");
        return true;
    } catch (Exception $e) {
        error_log("Logging failed: " . $e->getMessage());
        return false;
    }
}

// Обработка действий - упрощенная версия
$action = $_GET['action'] ?? '';
$message = '';
$message_type = '';

if ($action === 'create') {
    handleCreateBackup();
} elseif ($action === 'delete') {
    deleteBackup();
} elseif ($action === 'download') {
    downloadBackup();
}

// Остальные функции остаются как есть, но без сложной логики

/**
 * Получение списка бэкапов - упрощенная версия
 */
function getBackupList() {
    global $backup_dir;
    
    $backups = [];
    $files = glob($backup_dir . '*.zip');
    
    foreach ($files as $file) {
        $backups[] = [
            'filename' => basename($file),
            'size' => filesize($file),
            'modified' => filemtime($file),
            'date' => date('d.m.Y H:i:s', filemtime($file)),
            'size_formatted' => formatBytesss(filesize($file)),
            'type' => 'unknown',
            'comment' => '',
            'is_auto' => false
        ];
    }
    
    // Сортируем по дате (новые сверху)
    usort($backups, function($a, $b) {
        return $b['modified'] <=> $a['modified'];
    });
    
    return $backups;
}

/**
 * Создание бэкапа - упрощенная версия
 */
function handleCreateBackup() {
    global $message, $message_type, $pdo, $user_id, $backup_types;
    
    $type = $_POST['backup_type'] ?? 'full';
    $comment = trim($_POST['comment'] ?? '');
    
    if (!array_key_exists($type, $backup_types)) {
        $message = 'Неверный тип бэкапа';
        $message_type = 'error';
        return;
    }
    
    // Простая имитация создания бэкапа
    $filename = 'backup_' . date('Y-m-d_H-i-s') . '.zip';
    $filepath = PROJECT_ROOT . '/backups/' . $filename;
    
    // Создаем простой zip-файл с тестовым содержимым
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($filepath, ZipArchive::CREATE) === TRUE) {
            $zip->addFromString('readme.txt', 'Тестовый бэкап создан в режиме отладки');
            $zip->close();
            
            $message = "Тестовый бэкап создан: $filename";
            $message_type = 'success';
            
            logBackupAction($pdo, $user_id, 'create', $filename, "Тип: $type");
        } else {
            $message = "Ошибка при создании ZIP архива";
            $message_type = 'error';
        }
    } else {
        $message = "Класс ZipArchive не доступен";
        $message_type = 'error';
    }
}

/**
 * Удаление бэкапа
 */
function deleteBackup() {
    global $backup_dir, $message, $message_type;
    
    $filename = $_GET['file'] ?? '';
    if (!$filename) {
        $message = 'Не указан файл для удаления';
        $message_type = 'error';
        return;
    }
    
    $filepath = $backup_dir . basename($filename);
    if (!file_exists($filepath)) {
        $message = 'Файл не найден';
        $message_type = 'error';
        return;
    }
    
    if (unlink($filepath)) {
        $message = 'Бэкап успешно удален';
        $message_type = 'success';
    } else {
        $message = 'Ошибка при удалении файла';
        $message_type = 'error';
    }
    
    // Перенаправляем обратно
    header('Location: backup_debug.php?debug_token=temp_allow_123');
    exit;
}

/**
 * Скачивание бэкапа
 */
function downloadBackup() {
    global $backup_dir;
    
    $filename = $_GET['file'] ?? '';
    if (!$filename) {
        die('Не указан файл для скачивания');
    }
    
    $filepath = $backup_dir . basename($filename);
    if (!file_exists($filepath)) {
        die('Файл не найден');
    }
    
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . basename($filepath) . '"');
    header('Content-Length: ' . filesize($filepath));
    
    readfile($filepath);
    exit;
}

// Получаем список бэкапов
$backups = getBackupList();

// HTML интерфейс - упрощенный
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Резервное копирование - Отладка</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .alert { padding: 15px; margin: 20px 0; border-radius: 5px; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .alert-info { background: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }
        .backup-list { width: 100%; border-collapse: collapse; margin-top: 20px; }
        .backup-list th, .backup-list td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        .backup-list th { background: #f8f9fa; font-weight: bold; }
        .btn { padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-primary { background: #007bff; color: white; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-success { background: #28a745; color: white; }
        .form-group { margin: 15px 0; }
        .form-control { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
        .warning-box { background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; margin: 20px 0; border-radius: 5px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="warning-box">
            <strong>ВНИМАНИЕ:</strong> Это отладочная версия без проверки безопасности. 
            Используйте только для тестирования. После устранения проблем верните оригинальный файл.
        </div>
        
        <h1>Резервное копирование (Отладка)</h1>
        <p>Пользователь ID: <?php echo $user_id; ?></p>
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <h2>Создать бэкап</h2>
        <form method="POST" action="?debug_token=temp_allow_123&action=create">
            <div class="form-group">
                <label>Тип бэкапа:</label>
                <select name="backup_type" class="form-control">
                    <?php foreach ($backup_types as $value => $label): ?>
                        <option value="<?php echo $value; ?>"><?php echo $label; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Комментарий:</label>
                <input type="text" name="comment" class="form-control" placeholder="Комментарий">
            </div>
            <button type="submit" class="btn btn-primary">Создать тестовый бэкап</button>
        </form>
        
        <h2>Список бэкапов</h2>
        <?php if (empty($backups)): ?>
            <p>Бэкапы отсутствуют</p>
        <?php else: ?>
            <table class="backup-list">
                <thead>
                    <tr>
                        <th>Имя файла</th>
                        <th>Размер</th>
                        <th>Дата</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($backups as $backup): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($backup['filename']); ?></td>
                            <td><?php echo $backup['size_formatted']; ?></td>
                            <td><?php echo $backup['date']; ?></td>
                            <td>
                                <a href="?debug_token=temp_allow_123&action=download&file=<?php echo urlencode($backup['filename']); ?>" 
                                   class="btn btn-success">Скачать</a>
                                <a href="?debug_token=temp_allow_123&action=delete&file=<?php echo urlencode($backup['filename']); ?>" 
                                   class="btn btn-danger" 
                                   onclick="return confirm('Удалить бэкап?')">Удалить</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        
        <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd;">
            <h3>Отладочная информация:</h3>
            <pre style="background: #f8f9fa; padding: 15px; border-radius: 5px;">
Путь к бэкапам: <?php echo $backup_dir; ?>

Количество бэкапов: <?php echo count($backups); ?>

Директория существует: <?php echo file_exists($backup_dir) ? 'Да' : 'Нет'; ?>

Доступ на запись: <?php echo is_writable($backup_dir) ? 'Да' : 'Нет'; ?>

Общий размер: <?php 
    $total_size = 0;
    foreach ($backups as $b) $total_size += $b['size'];
    echo formatBytesss($total_size);
?>
            </pre>
        </div>
    </div>
</body>
</html>