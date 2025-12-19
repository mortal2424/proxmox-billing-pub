<?php

session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/admin_functions.php';


checkAuth();

$db = new Database();
$pdo = $db->getConnection();
$user_id = $_SESSION['user']['id'];

// Проверяем, является ли пользователь администратором
try {
    $stmt = safeQuery($pdo, "SELECT is_admin FROM users WHERE id = ?", [$user_id], 'users');
    $user = $stmt->fetch();
} catch (Exception $e) {
    die("Ошибка базы данных: " . $e->getMessage());
}

if (!$user || !$user['is_admin']) {
    header('Location: /templates/access_denied.php');
    exit;
}

// Путь к папке с логами - исправленный путь
$root_dir = realpath(__DIR__ . '/../../');
$logs_dir = $root_dir . '../logs/';

$current_file = $_GET['file'] ?? '';

// Если папка logs не существует, создаем ее
if (!file_exists($logs_dir)) {
    mkdir($logs_dir, 0755, true);
}

// Получаем список файлов логов
$log_files = [];
if (is_dir($logs_dir)) {
    $files = scandir($logs_dir);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        $filepath = $logs_dir . $file;
        if (is_file($filepath) && preg_match('/\.(log|txt)$/i', $file)) {
            $log_files[] = [
                'name' => $file,
                'size' => filesize($filepath),
                'modified' => filemtime($filepath),
                'path' => $filepath
            ];
        }
    }

    // Сортируем по дате изменения (новые сверху)
    usort($log_files, function($a, $b) {
        return $b['modified'] <=> $a['modified'];
    });
}

// Обработка действий
$action = $_GET['action'] ?? '';
$message = '';
$message_type = '';

switch ($action) {
    case 'view':
        if (empty($current_file)) {
            $message = 'Не указан файл для просмотра';
            $message_type = 'error';
        }
        break;

    case 'download':
        downloadLogFile();
        break;

    case 'delete':
        deleteLogFile();
        break;

    case 'clear':
        clearLogFile();
        break;

    case 'search':
        // Поиск будет обработан в JavaScript
        break;
}

// Читаем содержимое выбранного файла
$log_content = '';
$total_lines = 0;
$filtered_lines = 0;
$log_stats = [];

if ($current_file && file_exists($logs_dir . $current_file)) {
    $filepath = $logs_dir . $current_file;

    // Проверяем, что файл существует и доступен для чтения
    if (!is_readable($filepath)) {
        $message = "Файл '{$current_file}' недоступен для чтения";
        $message_type = 'error';
    } else {
        // Читаем файл построчно
        $lines = file($filepath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $total_lines = count($lines);

        // Фильтруем по уровню логирования (если указан)
        $filter_level = $_GET['level'] ?? '';
        $search_term = $_GET['search'] ?? '';

        $filtered_content = [];
        foreach ($lines as $line) {
            $include_line = true;

            // Фильтр по уровню
            if ($filter_level && stripos($line, $filter_level) === false) {
                $include_line = false;
            }

            // Поиск по тексту
            if ($search_term && stripos($line, $search_term) === false) {
                $include_line = false;
            }

            if ($include_line) {
                $filtered_content[] = $line;
            }
        }

        $filtered_lines = count($filtered_content);

        // Ограничиваем количество отображаемых строк
        $limit = min(1000, $filtered_lines);
        $offset = max(0, $filtered_lines - $limit);

        // Берем последние N строк
        $display_content = array_slice($filtered_content, $offset);
        $log_content = implode("\n", $display_content);

        // Статистика по уровням логирования
        $log_stats = analyzeLogLevels($lines);
    }
}

// Функции
function downloadLogFile() {
    global $logs_dir, $current_file;

    if (empty($current_file)) {
        die('Не указан файл для скачивания');
    }

    $filepath = $logs_dir . basename($current_file);
    if (!file_exists($filepath)) {
        die('Файл не найден');
    }

    header('Content-Type: text/plain');
    header('Content-Disposition: attachment; filename="' . basename($filepath) . '"');
    header('Content-Length: ' . filesize($filepath));
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: 0');

    readfile($filepath);
    exit;
}

function deleteLogFile() {
    global $logs_dir, $current_file, $message, $message_type;

    if (empty($current_file)) {
        $message = 'Не указан файл для удаления';
        $message_type = 'error';
        return;
    }

    $filepath = $logs_dir . basename($current_file);
    if (!file_exists($filepath)) {
        $message = 'Файл не найден';
        $message_type = 'error';
        return;
    }

    if (unlink($filepath)) {
        $message = 'Файл логов успешно удален';
        $message_type = 'success';
        $current_file = '';
    } else {
        $message = 'Ошибка при удалении файла';
        $message_type = 'error';
    }
}

function clearLogFile() {
    global $logs_dir, $current_file, $message, $message_type;

    if (empty($current_file)) {
        $message = 'Не указан файл для очистки';
        $message_type = 'error';
        return;
    }

    $filepath = $logs_dir . basename($current_file);
    if (!file_exists($filepath)) {
        $message = 'Файл не найден';
        $message_type = 'error';
        return;
    }

    if (file_put_contents($filepath, '') !== false) {
        $message = 'Файл логов успешно очищен';
        $message_type = 'success';
    } else {
        $message = 'Ошибка при очистке файла';
        $message_type = 'error';
    }
}

function analyzeLogLevels($lines) {
    $stats = [
        'total' => count($lines),
        'levels' => [
            'ERROR' => 0,
            'WARN' => 0,
            'WARNING' => 0,
            'INFO' => 0,
            'DEBUG' => 0,
            'NOTICE' => 0,
            'FATAL' => 0,
            'CRITICAL' => 0
        ],
        'time_period' => [
            'last_hour' => 0,
            'today' => 0,
            'last_24h' => 0
        ]
    ];

    $now = time();
    $one_hour_ago = $now - 3600;
    $twenty_four_hours_ago = $now - 86400;
    $today_start = strtotime('today');

    foreach ($lines as $line) {
        // Анализ уровней логирования
        foreach ($stats['levels'] as $level => $count) {
            if (stripos($line, $level) !== false) {
                $stats['levels'][$level]++;
            }
        }

        // Попытка извлечь timestamp из строки лога
        if (preg_match('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $matches)) {
            $log_time = strtotime($matches[1]);

            if ($log_time >= $one_hour_ago) {
                $stats['time_period']['last_hour']++;
            }
            if ($log_time >= $today_start) {
                $stats['time_period']['today']++;
            }
            if ($log_time >= $twenty_four_hours_ago) {
                $stats['time_period']['last_24h']++;
            }
        }
    }

    return $stats;
}

function formatBytess($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);

    return round($bytes, $precision) . ' ' . $units[$pow];
}

function formatDate($timestamp) {
    if ($timestamp > strtotime('today')) {
        return date('H:i:s', $timestamp);
    } elseif ($timestamp > strtotime('yesterday')) {
        return 'Вчера, ' . date('H:i:s', $timestamp);
    } else {
        return date('d.m.Y H:i:s', $timestamp);
    }
}

function highlightLogLine($line) {
    // Определяем уровень логирования для подсветки
    if (stripos($line, 'ERROR') !== false || stripos($line, 'FATAL') !== false || stripos($line, 'CRITICAL') !== false) {
        return '<span class="log-error">' . htmlspecialchars($line) . '</span>';
    } elseif (stripos($line, 'WARN') !== false || stripos($line, 'WARNING') !== false) {
        return '<span class="log-warning">' . htmlspecialchars($line) . '</span>';
    } elseif (stripos($line, 'DEBUG') !== false) {
        return '<span class="log-debug">' . htmlspecialchars($line) . '</span>';
    } elseif (stripos($line, 'INFO') !== false || stripos($line, 'NOTICE') !== false) {
        return '<span class="log-info">' . htmlspecialchars($line) . '</span>';
    } else {
        return htmlspecialchars($line);
    }
}

$title = "Просмотр логов | Админ панель | HomeVlad Cloud";
require 'admin_header.php';
?>

<style>
/* ========== ПЕРЕМЕННЫЕ ТЕМЫ ========== */
:root {
    --db-bg: #f8fafc;
    --db-card-bg: #ffffff;
    --db-border: #e2e8f0;
    --db-text: #1e293b;
    --db-text-secondary: #64748b;
    --db-text-muted: #94a3b8;
    --db-hover: #f1f5f9;
    --db-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
    --db-shadow-hover: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
    --db-accent: #00bcd4;
    --db-accent-light: rgba(0, 188, 212, 0.1);
    --db-success: #10b981;
    --db-warning: #f59e0b;
    --db-danger: #ef4444;
    --db-info: #3b82f6;
    --db-purple: #8b5cf6;
}

[data-theme="dark"] {
    --db-bg: #0f172a;
    --db-card-bg: #1e293b;
    --db-border: #334155;
    --db-text: #ffffff;
    --db-text-secondary: #cbd5e1;
    --db-text-muted: #94a3b8;
    --db-hover: #2d3748;
    --db-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.3), 0 1px 2px 0 rgba(0, 0, 0, 0.2);
    --db-shadow-hover: 0 10px 15px -3px rgba(0, 0, 0, 0.3), 0 4px 6px -2px rgba(0, 0, 0, 0.2);
}

/* ========== ОСНОВНЫЕ СТИЛИ ========== */
.dashboard-wrapper {
    padding: 20px;
    background: var(--db-bg);
    min-height: calc(100vh - 70px);
    margin-left: 280px;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.admin-sidebar.compact + .dashboard-wrapper {
    margin-left: 70px;
}

.dashboard-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    padding: 24px;
    background: var(--db-card-bg);
    border-radius: 12px;
    border: 1px solid var(--db-border);
    box-shadow: var(--db-shadow);
}

.header-left h1 {
    color: var(--db-text);
    font-size: 24px;
    margin: 0 0 8px 0;
    display: flex;
    align-items: center;
    gap: 12px;
}

.header-left h1 i {
    color: var(--db-accent);
}

.header-left p {
    color: var(--db-text-secondary);
    font-size: 14px;
    margin: 0;
}

/* ========== КАРТОЧКИ СТАТИСТИКИ ========== */
.logs-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}

.log-stat-card {
    background: var(--db-card-bg);
    border: 1px solid var(--db-border);
    border-radius: 8px;
    padding: 15px;
    text-align: center;
    transition: all 0.3s ease;
}

.log-stat-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--db-shadow-hover);
}

.log-stat-value {
    color: var(--db-text);
    font-size: 24px;
    font-weight: 700;
    margin: 0 0 5px 0;
}

.log-stat-label {
    color: var(--db-text-secondary);
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin: 0;
}

.log-stat-error { border-left: 4px solid var(--db-danger); }
.log-stat-warning { border-left: 4px solid var(--db-warning); }
.log-stat-info { border-left: 4px solid var(--db-info); }
.log-stat-debug { border-left: 4px solid var(--db-purple); }
.log-stat-total { border-left: 4px solid var(--db-accent); }

/* ========== ОСНОВНАЯ СЕТКА ========== */
.logs-main-grid {
    display: grid;
    grid-template-columns: 300px 1fr;
    gap: 20px;
    margin-bottom: 30px;
}

@media (max-width: 992px) {
    .logs-main-grid {
        grid-template-columns: 1fr;
    }
}

/* ========== СТИЛИ ДЛЯ ФАЙЛОВ ЛОГОВ ========== */
.logs-files-container {
    background: var(--db-card-bg);
    border-radius: 12px;
    border: 1px solid var(--db-border);
    overflow: hidden;
    box-shadow: var(--db-shadow);
}

.logs-files-header {
    padding: 20px;
    border-bottom: 1px solid var(--db-border);
}

.logs-files-header h3 {
    color: var(--db-text);
    font-size: 18px;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.logs-files-header h3 i {
    color: var(--db-accent);
}

.logs-files-list {
    max-height: 500px;
    overflow-y: auto;
}

.log-file-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 15px 20px;
    border-bottom: 1px solid var(--db-border);
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
    color: inherit;
}

.log-file-item:hover {
    background: var(--db-hover);
}

.log-file-item.active {
    background: var(--db-accent-light);
    border-left: 4px solid var(--db-accent);
}

.log-file-icon {
    width: 40px;
    height: 40px;
    border-radius: 8px;
    background: var(--db-accent-light);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--db-accent);
    font-size: 18px;
}

.log-file-info {
    flex: 1;
    min-width: 0;
}

.log-file-name {
    color: var(--db-text);
    font-size: 14px;
    font-weight: 500;
    margin: 0 0 4px 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.log-file-meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.log-file-size {
    color: var(--db-text-secondary);
    font-size: 12px;
    font-family: monospace;
}

.log-file-date {
    color: var(--db-text-muted);
    font-size: 11px;
}

/* ========== КОНТЕЙНЕР ДЛЯ ПРОСМОТРА ЛОГОВ ========== */
.logs-viewer-container {
    background: var(--db-card-bg);
    border-radius: 12px;
    border: 1px solid var(--db-border);
    overflow: hidden;
    box-shadow: var(--db-shadow);
    display: flex;
    flex-direction: column;
    height: 700px;
}

.logs-viewer-header {
    padding: 20px;
    border-bottom: 1px solid var(--db-border);
    background: var(--db-hover);
}

.logs-viewer-title {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}

.logs-viewer-title h3 {
    color: var(--db-text);
    font-size: 18px;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.logs-viewer-title h3 i {
    color: var(--db-accent);
}

.logs-viewer-actions {
    display: flex;
    gap: 8px;
}

.logs-controls {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.control-group {
    display: flex;
    align-items: center;
    gap: 8px;
}

.control-group label {
    color: var(--db-text-secondary);
    font-size: 13px;
    font-weight: 500;
}

.logs-viewer-body {
    flex: 1;
    overflow: hidden;
    position: relative;
}

.logs-viewer-content {
    height: 100%;
    overflow-y: auto;
    background: var(--db-bg);
    font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
    font-size: 13px;
    line-height: 1.5;
    padding: 20px;
    white-space: pre-wrap;
    word-wrap: break-word;
}

/* ========== СТИЛИ ДЛЯ СТРОК ЛОГОВ ========== */
.log-line {
    padding: 2px 0;
    border-bottom: 1px solid rgba(0, 0, 0, 0.05);
    transition: background-color 0.2s ease;
}

.log-line:hover {
    background-color: var(--db-hover);
}

.log-error {
    color: var(--db-danger);
    font-weight: 600;
}

.log-warning {
    color: var(--db-warning);
    font-weight: 500;
}

.log-info {
    color: var(--db-info);
}

.log-debug {
    color: var(--db-purple);
    font-style: italic;
}

/* ========== ПАНЕЛЬ ФИЛЬТРОВ ========== */
.logs-filters {
    background: var(--db-card-bg);
    border-radius: 12px;
    border: 1px solid var(--db-border);
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: var(--db-shadow);
}

.filters-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}

.filters-header h4 {
    color: var(--db-text);
    font-size: 16px;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.filters-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
}

.filter-group {
    margin-bottom: 10px;
}

.filter-label {
    display: block;
    margin-bottom: 5px;
    color: var(--db-text);
    font-size: 13px;
    font-weight: 500;
}

.filter-input,
.filter-select {
    width: 87%;
    padding: 8px 12px;
    border: 1px solid var(--db-border);
    border-radius: 6px;
    background: var(--db-card-bg);
    color: var(--db-text);
    font-size: 13px;
}

.filter-input:focus,
.filter-select:focus {
    outline: none;
    border-color: var(--db-accent);
    box-shadow: 0 0 0 2px var(--db-accent-light);
}

.filter-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    margin-top: 15px;
    padding-top: 15px;
    border-top: 1px solid var(--db-border);
}

/* ========== УВЕДОМЛЕНИЯ ========== */
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
    color: var(--db-success);
}

.alert-error {
    background-color: rgba(239, 68, 68, 0.1);
    border-color: rgba(239, 68, 68, 0.2);
    color: var(--db-danger);
}

.alert-warning {
    background-color: rgba(245, 158, 11, 0.1);
    border-color: rgba(245, 158, 11, 0.2);
    color: var(--db-warning);
}

.alert-info {
    background-color: rgba(59, 130, 246, 0.1);
    border-color: rgba(59, 130, 246, 0.2);
    color: var(--db-info);
}

.alert i {
    font-size: 18px;
    margin-top: 2px;
}

.alert strong {
    font-weight: 600;
}

.alert p {
    margin: 4px 0 0 0;
    font-size: 14px;
}

/* ========== КНОПКИ ========== */
.btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 500;
    border: none;
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
    white-space: nowrap;
}

.btn-primary {
    background: linear-gradient(135deg, var(--db-accent), #0097a7);
    color: white;
}

.btn-primary:hover:not(:disabled) {
    transform: translateY(-1px);
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

.btn-success {
    background: linear-gradient(135deg, var(--db-success), #059669);
    color: white;
}

.btn-danger {
    background: linear-gradient(135deg, var(--db-danger), #dc2626);
    color: white;
}

.btn-warning {
    background: linear-gradient(135deg, var(--db-warning), #d97706);
    color: white;
}

.btn-sm {
    padding: 6px 12px;
    font-size: 12px;
}

.btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none !important;
    box-shadow: none !important;
}

/* ========== ПУСТОЕ СОСТОЯНИЕ ========== */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: var(--db-text-secondary);
}

.empty-state i {
    font-size: 48px;
    margin-bottom: 16px;
    opacity: 0.5;
}

.empty-state h3 {
    color: var(--db-text);
    margin: 0 0 8px 0;
    font-size: 18px;
}

.empty-state p {
    margin: 0;
    font-size: 14px;
}

/* ========== АНИМАЦИИ ========== */
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

@keyframes highlight {
    0% { background-color: var(--db-accent-light); }
    100% { background-color: transparent; }
}

.highlight {
    animation: highlight 2s ease;
}

/* ========== МОДАЛЬНЫЕ ОКНА - ИСПРАВЛЕННЫЕ СТИЛИ ========== */
.modal-backdrop {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    z-index: 9999;
    display: none;
}

.modal-backdrop.show {
    display: block;
}

.modal {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 10000;
    display: none;
    overflow: hidden;
    outline: 0;
}

.modal.show {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.modal-dialog {
    position: relative;
    width: 100%;
    max-width: 500px;
    margin: 0;
    pointer-events: none;
}

.modal-content {
    position: relative;
    display: flex;
    flex-direction: column;
    width: 100%;
    pointer-events: auto;
    background-color: var(--db-card-bg);
    background-clip: padding-box;
    border: 1px solid var(--db-border);
    border-radius: 12px;
    outline: 0;
    box-shadow: var(--db-shadow-hover);
    max-height: 90vh;
    overflow: hidden;
}

.modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 20px;
    border-bottom: 1px solid var(--db-border);
}

.modal-title {
    margin: 0;
    color: var(--db-text);
    font-size: 18px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.modal-title i {
    color: var(--db-accent);
}

.btn-close {
    background: none;
    border: none;
    font-size: 20px;
    color: var(--db-text-secondary);
    cursor: pointer;
    padding: 0;
    width: 30px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 4px;
}

.btn-close:hover {
    background: var(--db-hover);
    color: var(--db-text);
}

.modal-body {
    padding: 20px;
    color: var(--db-text);
    overflow-y: auto;
    flex: 1;
}

.modal-footer {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    padding: 20px;
    border-top: 1px solid var(--db-border);
    gap: 12px;
}

/* Стили для форм внутри модальных окон */
.modal-body .form-group {
    margin-bottom: 15px;
}

.modal-body .form-label {
    display: block;
    margin-bottom: 5px;
    color: var(--db-text);
    font-size: 14px;
    font-weight: 500;
}

.modal-body .form-input,
.modal-body .form-select {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid var(--db-border);
    border-radius: 6px;
    background: var(--db-card-bg);
    color: var(--db-text);
    font-size: 14px;
}

.modal-body .form-input:focus,
.modal-body .form-select:focus {
    outline: none;
    border-color: var(--db-accent);
    box-shadow: 0 0 0 2px var(--db-accent-light);
}

/* ========== АДАПТИВНОСТЬ ========== */
@media (max-width: 1200px) {
    .dashboard-wrapper {
        margin-left: 70px !important;
    }
}

@media (max-width: 992px) {
    .dashboard-wrapper {
        margin-left: 0 !important;
    }

    .logs-viewer-container {
        height: 600px;
    }

    .modal.show {
        padding: 10px;
    }
}

@media (max-width: 768px) {
    .dashboard-wrapper {
        padding: 15px;
    }

    .dashboard-header {
        flex-direction: column;
        align-items: stretch;
        gap: 15px;
    }

    .logs-stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }

    .logs-viewer-title {
        flex-direction: column;
        align-items: stretch;
        gap: 10px;
    }

    .logs-viewer-actions {
        justify-content: flex-start;
        flex-wrap: wrap;
    }

    .filters-grid {
        grid-template-columns: 1fr;
    }

    .modal-dialog {
        max-width: calc(100% - 20px);
    }
}

@media (max-width: 480px) {
    .logs-stats-grid {
        grid-template-columns: 1fr;
    }

    .filter-actions {
        flex-direction: column;
    }

    .btn {
        width: 100%;
        justify-content: center;
    }

    .modal-footer {
        flex-direction: column;
    }

    .modal-footer .btn {
        width: 100%;
    }
}

/* ========== ПРОГРЕСС-БАР ЗАГРУЗКИ ========== */
.loading-overlay {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(255, 255, 255, 0.8);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10;
    display: none;
}

[data-theme="dark"] .loading-overlay {
    background: rgba(0, 0, 0, 0.8);
}

.spinner {
    width: 40px;
    height: 40px;
    border: 4px solid var(--db-border);
    border-top: 4px solid var(--db-accent);
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

/* ========== ПАГИНАЦИЯ ========== */
.pagination {
    display: flex;
    justify-content: center;
    gap: 5px;
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid var(--db-border);
}

.pagination-btn {
    min-width: 36px;
    height: 36px;
    padding: 0 12px;
    border-radius: 6px;
    background: var(--db-card-bg);
    border: 1px solid var(--db-border);
    color: var(--db-text);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.3s ease;
}

.pagination-btn:hover:not(.disabled) {
    background: var(--db-hover);
    border-color: var(--db-accent);
}

.pagination-btn.active {
    background: var(--db-accent);
    color: white;
    border-color: var(--db-accent);
}

.pagination-btn.disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* ========== ТУЛТИПЫ ========== */
.tooltip {
    position: relative;
    display: inline-block;
}

.tooltip .tooltip-text {
    visibility: hidden;
    width: 200px;
    background-color: var(--db-text);
    color: var(--db-card-bg);
    text-align: center;
    border-radius: 6px;
    padding: 8px;
    position: absolute;
    z-index: 1;
    bottom: 125%;
    left: 50%;
    transform: translateX(-50%);
    opacity: 0;
    transition: opacity 0.3s;
    font-size: 12px;
    font-weight: normal;
}

.tooltip:hover .tooltip-text {
    visibility: visible;
    opacity: 1;
}
</style>

<!-- Подключаем сайдбар -->
<?php require 'admin_sidebar.php'; ?>

<!-- Дашборд -->
<div class="dashboard-wrapper">
    <!-- Шапка страницы -->
    <div class="dashboard-header">
        <div class="header-left">
            <h1><i class="fas fa-clipboard-list"></i> Просмотр логов системы</h1>
            <p>Мониторинг и анализ системных событий</p>
        </div>
        <div class="header-right">
            <button onclick="refreshLogs()" class="btn btn-primary">
                <i class="fas fa-sync-alt"></i> Обновить
            </button>
        </div>
    </div>

    <!-- Уведомления -->
    <?php if ($message): ?>
        <div class="alert alert-<?= $message_type === 'error' ? 'error' : 'success' ?> fade-in">
            <i class="fas fa-<?= $message_type === 'error' ? 'exclamation-circle' : 'check-circle' ?>"></i>
            <div>
                <strong><?= $message_type === 'error' ? 'Ошибка!' : 'Успешно!' ?></strong>
                <p><?= htmlspecialchars($message) ?></p>
            </div>
        </div>
    <?php endif; ?>

    <!-- Панель фильтров -->
    <div class="logs-filters">
        <div class="filters-header">
            <h4><i class="fas fa-filter"></i> Фильтры и поиск</h4>
            <div class="control-group">
                <label>
                    <input type="checkbox" id="autoRefresh" checked>
                    Автообновление (10 сек)
                </label>
            </div>
        </div>

        <div class="filters-grid">
            <div class="filter-group">
                <label class="filter-label">Уровень логирования</label>
                <select class="filter-select" id="logLevelFilter" onchange="applyFilters()">
                    <option value="">Все уровни</option>
                    <option value="ERROR">ERROR</option>
                    <option value="WARN">WARNING</option>
                    <option value="INFO">INFO</option>
                    <option value="DEBUG">DEBUG</option>
                </select>
            </div>

            <div class="filter-group">
                <label class="filter-label">Поиск по тексту</label>
                <input type="text" class="filter-input" id="logSearchFilter"
                       placeholder="Введите текст для поиска..." onkeyup="applyFilters()">
            </div>

            <div class="filter-group">
                <label class="filter-label">Период времени</label>
                <select class="filter-select" id="timePeriodFilter" onchange="applyFilters()">
                    <option value="">Весь период</option>
                    <option value="1h">Последний час</option>
                    <option value="24h">Последние 24 часа</option>
                    <option value="today">Сегодня</option>
                </select>
            </div>

            <div class="filter-group">
                <label class="filter-label">Количество строк</label>
                <select class="filter-select" id="linesLimitFilter" onchange="applyFilters()">
                    <option value="100">100 строк</option>
                    <option value="500" selected>500 строк</option>
                    <option value="1000">1000 строк</option>
                    <option value="5000">5000 строк</option>
                    <option value="all">Все строки</option>
                </select>
            </div>
        </div>

        <div class="filter-actions">
            <button class="btn btn-secondary" onclick="clearFilters()">
                <i class="fas fa-broom"></i> Сбросить фильтры
            </button>
            <button class="btn btn-primary" onclick="exportLogs()">
                <i class="fas fa-download"></i> Экспорт логов
            </button>
        </div>
    </div>

    <!-- Статистика (если файл выбран) -->
    <?php if ($current_file && isset($log_stats['total']) && $log_stats['total'] > 0): ?>
        <div class="logs-stats-grid">
            <div class="log-stat-card log-stat-total">
                <div class="log-stat-value"><?= number_format($log_stats['total']) ?></div>
                <p class="log-stat-label">Всего записей</p>
            </div>

            <div class="log-stat-card log-stat-error">
                <div class="log-stat-value"><?= number_format($log_stats['levels']['ERROR'] + $log_stats['levels']['FATAL'] + $log_stats['levels']['CRITICAL']) ?></div>
                <p class="log-stat-label">Ошибки</p>
            </div>

            <div class="log-stat-card log-stat-warning">
                <div class="log-stat-value"><?= number_format($log_stats['levels']['WARN'] + $log_stats['levels']['WARNING']) ?></div>
                <p class="log-stat-label">Предупреждения</p>
            </div>

            <div class="log-stat-card log-stat-info">
                <div class="log-stat-value"><?= number_format($log_stats['levels']['INFO'] + $log_stats['levels']['NOTICE']) ?></div>
                <p class="log-stat-label">Информация</p>
            </div>

            <div class="log-stat-card log-stat-debug">
                <div class="log-stat-value"><?= number_format($log_stats['levels']['DEBUG']) ?></div>
                <p class="log-stat-label">Отладка</p>
            </div>

            <div class="log-stat-card log-stat-total">
                <div class="log-stat-value"><?= number_format($log_stats['time_period']['last_hour']) ?></div>
                <p class="log-stat-label">За последний час</p>
            </div>
        </div>
    <?php endif; ?>

    <!-- Основная сетка -->
    <div class="logs-main-grid">
        <!-- Левая колонка - список файлов -->
        <div class="logs-files-container">
            <div class="logs-files-header">
                <h3><i class="fas fa-file-alt"></i> Файлы логов</h3>
            </div>

            <div class="logs-files-list">
                <?php if (empty($log_files)): ?>
                    <div class="empty-state" style="padding: 40px 20px;">
                        <i class="fas fa-folder-open"></i>
                        <p>Файлы логов не найдены</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($log_files as $log_file): ?>
                        <a href="?action=view&file=<?= urlencode($log_file['name']) ?>"
                           class="log-file-item <?= $current_file === $log_file['name'] ? 'active' : '' ?>">
                            <div class="log-file-icon">
                                <i class="fas fa-file-code"></i>
                            </div>
                            <div class="log-file-info">
                                <div class="log-file-name"><?= htmlspecialchars($log_file['name']) ?></div>
                                <div class="log-file-meta">
                                    <span class="log-file-size"><?= formatBytess($log_file['size']) ?></span>
                                    <span class="log-file-date"><?= formatDate($log_file['modified']) ?></span>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Правая колонка - просмотр логов -->
        <div class="logs-viewer-container">
            <div class="logs-viewer-header">
                <div class="logs-viewer-title">
                    <h3>
                        <i class="fas fa-search"></i>
                        <?php if ($current_file): ?>
                            Просмотр: <?= htmlspecialchars($current_file) ?>
                            <small style="font-size: 12px; color: var(--db-text-muted); margin-left: 10px;">
                                <?= number_format($filtered_lines) ?> из <?= number_format($total_lines) ?> строк
                            </small>
                        <?php else: ?>
                            Выберите файл для просмотра
                        <?php endif; ?>
                    </h3>

                    <?php if ($current_file): ?>
                        <div class="logs-viewer-actions">
                            <button class="btn btn-sm btn-secondary" onclick="scrollToTop()" title="В начало">
                                <i class="fas fa-arrow-up"></i>
                            </button>
                            <button class="btn btn-sm btn-secondary" onclick="scrollToBottom()" title="В конец">
                                <i class="fas fa-arrow-down"></i>
                            </button>
                            <button class="btn btn-sm btn-secondary" onclick="toggleWrap()" title="Перенос строк">
                                <i class="fas fa-text-width"></i>
                            </button>
                            <a href="?action=download&file=<?= urlencode($current_file) ?>"
                               class="btn btn-sm btn-success" title="Скачать">
                                <i class="fas fa-download"></i>
                            </a>
                            <button class="btn btn-sm btn-warning" onclick="clearLogFile()" title="Очистить файл">
                                <i class="fas fa-broom"></i>
                            </button>
                            <button class="btn btn-sm btn-danger" onclick="deleteLogFile()" title="Удалить файл">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($current_file): ?>
                    <div class="logs-controls">
                        <div class="control-group">
                            <label for="highlightErrors">
                                <input type="checkbox" id="highlightErrors" checked> Подсветка ошибок
                            </label>
                        </div>
                        <div class="control-group">
                            <label for="showTimestamps">
                                <input type="checkbox" id="showTimestamps" checked> Показывать время
                            </label>
                        </div>
                        <div class="control-group">
                            <label for="lineNumbers">
                                <input type="checkbox" id="lineNumbers" checked> Номера строк
                            </label>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="logs-viewer-body">
                <div class="loading-overlay" id="loadingOverlay">
                    <div class="spinner"></div>
                </div>

                <?php if ($current_file): ?>
                    <div class="logs-viewer-content" id="logsContent" style="white-space: pre;">
                        <?php
                        if (!empty($log_content)) {
                            $lines = explode("\n", $log_content);
                            foreach ($lines as $index => $line) {
                                $line_number = $filtered_lines - count($lines) + $index + 1;
                                echo '<div class="log-line" id="line-' . $line_number . '">';
                                echo '<span style="color: var(--db-text-muted); margin-right: 10px; font-size: 11px;">' . $line_number . '</span>';
                                echo highlightLogLine($line);
                                echo '</div>';
                            }
                        } else {
                            echo '<div class="empty-state" style="padding: 40px 20px;">';
                            echo '<i class="fas fa-info-circle"></i>';
                            echo '<p>Файл пуст или не содержит записей по выбранным фильтрам</p>';
                            echo '</div>';
                        }
                        ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state" style="height: 100%; display: flex; flex-direction: column; justify-content: center;">
                        <i class="fas fa-file-alt" style="font-size: 64px;"></i>
                        <h3>Выберите файл логов для просмотра</h3>
                        <p>Все доступные файлы отображаются в левой панели</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Быстрые действия -->
    <div style="margin-top: 30px; display: flex; gap: 12px; justify-content: center; flex-wrap: wrap;">
        <button class="btn btn-warning" onclick="compressOldLogs()">
            <i class="fas fa-file-archive"></i> Архивировать старые логи
        </button>
        <button class="btn btn-danger" onclick="deleteOldLogs()">
            <i class="fas fa-trash-alt"></i> Удалить логи старше 30 дней
        </button>
        <button class="btn btn-info" onclick="showLogSettings()">
            <i class="fas fa-cog"></i> Настройки логирования
        </button>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Анимация появления элементов
    const elements = document.querySelectorAll('.dashboard-header, .logs-filters, .logs-files-container, .logs-viewer-container');
    elements.forEach((element, index) => {
        element.style.opacity = '0';
        element.style.transform = 'translateY(20px)';

        setTimeout(() => {
            element.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
            element.style.opacity = '1';
            element.style.transform = 'translateY(0)';
        }, index * 100);
    });

    // Обновление отступа при сворачивании сайдбара
    const sidebar = document.querySelector('.admin-sidebar');
    const dashboard = document.querySelector('.dashboard-wrapper');

    if (sidebar && dashboard) {
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.attributeName === 'class') {
                    if (sidebar.classList.contains('compact')) {
                        dashboard.style.marginLeft = '70px';
                    } else {
                        dashboard.style.marginLeft = '280px';
                    }
                }
            });
        });

        observer.observe(sidebar, { attributes: true });
    }

    // Инициализация элементов управления
    initializeControls();

    // Автообновление логов
    setupAutoRefresh();
});

// Инициализация элементов управления
function initializeControls() {
    // Восстановление сохраненных фильтров
    const savedFilters = localStorage.getItem('logFilters');
    if (savedFilters) {
        try {
            const filters = JSON.parse(savedFilters);
            if (filters.level) document.getElementById('logLevelFilter').value = filters.level;
            if (filters.search) document.getElementById('logSearchFilter').value = filters.search;
            if (filters.period) document.getElementById('timePeriodFilter').value = filters.period;
            if (filters.lines) document.getElementById('linesLimitFilter').value = filters.lines;
        } catch (e) {
            console.error('Error parsing saved filters:', e);
        }
    }

    // Применение изменений к элементам управления
    const highlightErrorsCheckbox = document.getElementById('highlightErrors');
    if (highlightErrorsCheckbox) {
        highlightErrorsCheckbox.addEventListener('change', function() {
            const logsContent = document.getElementById('logsContent');
            if (logsContent) {
                const errorLines = logsContent.querySelectorAll('.log-error');
                errorLines.forEach(line => {
                    line.style.backgroundColor = this.checked ? 'rgba(239, 68, 68, 0.1)' : 'transparent';
                });
            }
        });
    }

    const lineNumbersCheckbox = document.getElementById('lineNumbers');
    if (lineNumbersCheckbox) {
        lineNumbersCheckbox.addEventListener('change', function() {
            const logsContent = document.getElementById('logsContent');
            if (logsContent) {
                const lines = logsContent.querySelectorAll('.log-line');
                lines.forEach(line => {
                    const numberSpan = line.querySelector('span');
                    if (numberSpan) {
                        numberSpan.style.display = this.checked ? 'inline' : 'none';
                    }
                });
            }
        });
    }

    // Добавление кнопок для копирования строк
    addCopyButtons();
}

// Настройка автообновления
function setupAutoRefresh() {
    const autoRefreshCheckbox = document.getElementById('autoRefresh');
    let refreshInterval = null;

    if (autoRefreshCheckbox) {
        autoRefreshCheckbox.addEventListener('change', function() {
            if (this.checked && window.location.search.includes('action=view')) {
                refreshInterval = setInterval(refreshLogs, 10000); // 10 секунд
            } else {
                clearInterval(refreshInterval);
            }
        });

        // Запускаем автообновление если чекбокс отмечен
        if (autoRefreshCheckbox.checked && window.location.search.includes('action=view')) {
            refreshInterval = setInterval(refreshLogs, 10000);
        }
    }

    // Остановка автообновления при уходе со страницы
    window.addEventListener('beforeunload', function() {
        clearInterval(refreshInterval);
    });
}

// Функции для работы с логами
function refreshLogs() {
    const currentFile = '<?= $current_file ?>';
    if (!currentFile) return;

    const loadingOverlay = document.getElementById('loadingOverlay');
    const logsContent = document.getElementById('logsContent');

    if (loadingOverlay && logsContent) {
        loadingOverlay.style.display = 'flex';

        // Получаем текущие значения фильтров
        const level = document.getElementById('logLevelFilter').value;
        const search = document.getElementById('logSearchFilter').value;
        const period = document.getElementById('timePeriodFilter').value;
        const lines = document.getElementById('linesLimitFilter').value;

        // Сохраняем текущую позицию прокрутки
        const scrollTop = logsContent.scrollTop;
        const scrollHeight = logsContent.scrollHeight;

        // Обновляем страницу с фильтрами
        const url = new URL(window.location.href);
        url.searchParams.set('level', level);
        url.searchParams.set('search', search);
        url.searchParams.set('period', period);
        url.searchParams.set('lines', lines);

        window.location.href = url.toString();
    }
}

function applyFilters() {
    const level = document.getElementById('logLevelFilter').value;
    const search = document.getElementById('logSearchFilter').value;
    const period = document.getElementById('timePeriodFilter').value;
    const lines = document.getElementById('linesLimitFilter').value;

    // Сохраняем фильтры в localStorage
    localStorage.setItem('logFilters', JSON.stringify({
        level: level,
        search: search,
        period: period,
        lines: lines
    }));

    // Обновляем URL с фильтрами
    const url = new URL(window.location.href);
    url.searchParams.set('level', level);
    url.searchParams.set('search', search);
    url.searchParams.set('period', period);
    url.searchParams.set('lines', lines);

    window.location.href = url.toString();
}

function clearFilters() {
    document.getElementById('logLevelFilter').value = '';
    document.getElementById('logSearchFilter').value = '';
    document.getElementById('timePeriodFilter').value = '';
    document.getElementById('linesLimitFilter').value = '500';

    localStorage.removeItem('logFilters');

    const url = new URL(window.location.href);
    url.searchParams.delete('level');
    url.searchParams.delete('search');
    url.searchParams.delete('period');
    url.searchParams.delete('lines');

    window.location.href = url.toString();
}

function scrollToTop() {
    const logsContent = document.getElementById('logsContent');
    if (logsContent) {
        logsContent.scrollTop = 0;
        showNotification('Прокручено в начало', 'info');
    }
}

function scrollToBottom() {
    const logsContent = document.getElementById('logsContent');
    if (logsContent) {
        logsContent.scrollTop = logsContent.scrollHeight;
        showNotification('Прокручено в конец', 'info');
    }
}

function toggleWrap() {
    const logsContent = document.getElementById('logsContent');
    if (logsContent) {
        const currentWhiteSpace = logsContent.style.whiteSpace;
        logsContent.style.whiteSpace = currentWhiteSpace === 'pre' ? 'pre-wrap' : 'pre';
        showNotification('Перенос строк ' + (currentWhiteSpace === 'pre' ? 'включен' : 'выключен'), 'info');
    }
}

function clearLogFile() {
    const currentFile = '<?= $current_file ?>';
    if (!currentFile) {
        showNotification('Файл не выбран', 'error');
        return;
    }

    if (confirm('Вы уверены, что хотите очистить файл "' + currentFile + '"?\nЭто действие нельзя отменить.')) {
        window.location.href = '?action=clear&file=' + encodeURIComponent(currentFile);
    }
}

function deleteLogFile() {
    const currentFile = '<?= $current_file ?>';
    if (!currentFile) {
        showNotification('Файл не выбран', 'error');
        return;
    }

    if (confirm('Вы уверены, что хотите удалить файл "' + currentFile + '"?\nЭто действие нельзя отменить.')) {
        window.location.href = '?action=delete&file=' + encodeURIComponent(currentFile);
    }
}

function exportLogs() {
    const currentFile = '<?= $current_file ?>';
    if (!currentFile) {
        showNotification('Файл не выбран', 'error');
        return;
    }

    // Экспорт с текущими фильтрами
    const level = document.getElementById('logLevelFilter').value;
    const search = document.getElementById('logSearchFilter').value;

    let exportUrl = '?action=download&file=' + encodeURIComponent(currentFile);
    if (level) exportUrl += '&level=' + encodeURIComponent(level);
    if (search) exportUrl += '&search=' + encodeURIComponent(search);

    window.location.href = exportUrl;
}

function compressOldLogs() {
    if (confirm('Архивировать логи старше 7 дней?\nСтарые файлы будут сжаты в ZIP архив.')) {
        showNotification('Архивация запущена...', 'info');

        fetch('/admin/ajax/compress_logs.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                days: 7
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification('Архивация завершена: ' + data.message, 'success');
                setTimeout(() => location.reload(), 1500);
            } else {
                showNotification('Ошибка: ' + data.error, 'error');
            }
        })
        .catch(error => {
            showNotification('Ошибка сети', 'error');
        });
    }
}

function deleteOldLogs() {
    if (confirm('Удалить логи старше 30 дней?\nЭто действие нельзя отменить.')) {
        showNotification('Удаление запущено...', 'info');

        fetch('/admin/ajax/delete_old_logs.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                days: 30
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification('Удалено ' + data.deleted + ' файлов', 'success');
                setTimeout(() => location.reload(), 1500);
            } else {
                showNotification('Ошибка: ' + data.error, 'error');
            }
        })
        .catch(error => {
            showNotification('Ошибка сети', 'error');
        });
    }
}

function showLogSettings() {
    // Удаляем старые модальные окна, если есть
    document.querySelectorAll('.modal-backdrop, .modal').forEach(el => el.remove());

    const modalHTML = `
    <div class="modal-backdrop show"></div>
    <div class="modal show" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-cog"></i> Настройки логирования</h5>
                    <button type="button" class="btn-close" onclick="closeModal()">×</button>
                </div>
                <div class="modal-body">
                    <form id="logSettingsForm">
                        <div style="margin-bottom: 15px;">
                            <label style="display: block; margin-bottom: 5px; color: var(--db-text); font-size: 14px; font-weight: 500;">Уровень логирования по умолчанию</label>
                            <select name="log_level" style="width: 100%; padding: 10px 12px; border: 1px solid var(--db-border); border-radius: 6px; background: var(--db-card-bg); color: var(--db-text); font-size: 14px;">
                                <option value="DEBUG">DEBUG (все сообщения)</option>
                                <option value="INFO">INFO (информационные и выше)</option>
                                <option value="WARNING">WARNING (предупреждения и выше)</option>
                                <option value="ERROR">ERROR (только ошибки)</option>
                            </select>
                        </div>

                        <div style="margin-bottom: 15px;">
                            <label style="display: flex; align-items: center; gap: 8px; color: var(--db-text);">
                                <input type="checkbox" name="log_errors" checked>
                                Логировать ошибки PHP
                            </label>
                        </div>

                        <div style="margin-bottom: 15px;">
                            <label style="display: flex; align-items: center; gap: 8px; color: var(--db-text);">
                                <input type="checkbox" name="log_queries" checked>
                                Логировать SQL запросы
                            </label>
                        </div>

                        <div style="margin-bottom: 15px;">
                            <label style="display: flex; align-items: center; gap: 8px; color: var(--db-text);">
                                <input type="checkbox" name="log_access" checked>
                                Логировать доступы пользователей
                            </label>
                        </div>

                        <div style="margin-bottom: 15px;">
                            <label style="display: block; margin-bottom: 5px; color: var(--db-text); font-size: 14px; font-weight: 500;">Максимальный размер файла (MB)</label>
                            <input type="number" name="max_size" style="width: 100%; padding: 10px 12px; border: 1px solid var(--db-border); border-radius: 6px; background: var(--db-card-bg); color: var(--db-text); font-size: 14px;" value="10" min="1" max="100">
                        </div>

                        <div style="margin-bottom: 15px;">
                            <label style="display: block; margin-bottom: 5px; color: var(--db-text); font-size: 14px; font-weight: 500;">Ротация логов</label>
                            <select name="rotation" style="width: 100%; padding: 10px 12px; border: 1px solid var(--db-border); border-radius: 6px; background: var(--db-card-bg); color: var(--db-text); font-size: 14px;">
                                <option value="daily">Ежедневно</option>
                                <option value="weekly">Еженедельно</option>
                                <option value="monthly">Ежемесячно</option>
                                <option value="size">По размеру</option>
                            </select>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Отмена</button>
                    <button type="button" class="btn btn-primary" onclick="saveLogSettings()">
                        <i class="fas fa-save"></i> Сохранить
                    </button>
                </div>
            </div>
        </div>
    </div>`;

    document.body.insertAdjacentHTML('beforeend', modalHTML);
}

function closeModal() {
    document.querySelectorAll('.modal-backdrop, .modal').forEach(el => el.remove());
}

function saveLogSettings() {
    const form = document.getElementById('logSettingsForm');
    const formData = new FormData(form);

    showNotification('Сохранение настроек...', 'info');

    fetch('/admin/ajax/save_log_settings.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Настройки сохранены', 'success');
            closeModal();
        } else {
            showNotification('Ошибка: ' + data.error, 'error');
        }
    })
    .catch(error => {
        showNotification('Ошибка сети', 'error');
    });
}

function addCopyButtons() {
    const logsContent = document.getElementById('logsContent');
    if (!logsContent) return;

    const lines = logsContent.querySelectorAll('.log-line');
    lines.forEach(line => {
        // Удаляем старые кнопки, если есть
        const oldBtn = line.querySelector('.copy-line-btn');
        if (oldBtn) oldBtn.remove();

        // Добавляем новую кнопку
        const copyBtn = document.createElement('button');
        copyBtn.className = 'copy-line-btn';
        copyBtn.innerHTML = '<i class="fas fa-copy"></i>';
        copyBtn.style.cssText = `
            float: right;
            background: none;
            border: none;
            color: var(--db-text-muted);
            cursor: pointer;
            padding: 2px 5px;
            font-size: 11px;
            opacity: 0;
            transition: opacity 0.2s ease;
        `;

        copyBtn.addEventListener('mouseenter', function() {
            this.style.opacity = '1';
        });

        copyBtn.addEventListener('mouseleave', function() {
            this.style.opacity = '0';
        });

        copyBtn.addEventListener('click', function() {
            const lineText = line.textContent.replace(/^\d+\s*/, '').trim();
            navigator.clipboard.writeText(lineText).then(() => {
                showNotification('Строка скопирована в буфер обмена', 'success');
            }).catch(err => {
                console.error('Ошибка копирования:', err);
                showNotification('Ошибка копирования', 'error');
            });
        });

        line.appendChild(copyBtn);

        // Показываем кнопку при наведении на строку
        line.addEventListener('mouseenter', function() {
            const btn = this.querySelector('.copy-line-btn');
            if (btn) btn.style.opacity = '1';
        });

        line.addEventListener('mouseleave', function() {
            const btn = this.querySelector('.copy-line-btn');
            if (btn) btn.style.opacity = '0';
        });
    });
}

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
                 type === 'error' ? 'exclamation-circle' :
                 type === 'warning' ? 'exclamation-triangle' : 'info-circle';

    alert.innerHTML = `
        <i class="fas fa-${icon}"></i>
        <div>
            <strong>${type === 'success' ? 'Успешно!' :
                      type === 'error' ? 'Ошибка!' :
                      type === 'warning' ? 'Внимание!' : 'Информация'}</strong>
            <p>${message}</p>
        </div>
    `;

    document.body.appendChild(alert);

    setTimeout(() => {
        alert.style.transition = 'opacity 0.3s ease';
        alert.style.opacity = '0';
        setTimeout(() => alert.remove(), 300);
    }, 3000);
}

// Горячие клавиши
document.addEventListener('keydown', function(e) {
    // Ctrl + F - поиск
    if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
        e.preventDefault();
        document.getElementById('logSearchFilter').focus();
    }

    // Ctrl + R - обновить
    if ((e.ctrlKey || e.metaKey) && e.key === 'r') {
        e.preventDefault();
        refreshLogs();
    }

    // ESC - закрыть модальные окна
    if (e.key === 'Escape') {
        closeModal();
    }
});

// Закрытие модальных окон при клике на бэкдроп
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal-backdrop')) {
        closeModal();
    }
});
</script>

<?php
require 'admin_footer.php';
?>
