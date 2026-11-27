<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

session_start();


require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/proxmox_functions.php';
require_once __DIR__ . '/admin_functions.php';
require_once __DIR__ . '/../includes/node_admin_guard.php';
require_once __DIR__ . '/../includes/node_api_credentials.php';
require_once __DIR__ . '/../includes/cloud_images.php';
header('Cache-Control: no-store');

checkAuth();

$db = new Database();
$pdo = $db->getConnection();
requireNodeAdministrator($pdo);
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

// Получаем список нод
try {
    $nodes_stmt = safeQuery($pdo, "
        SELECT n.*, c.name as cluster_name
        FROM proxmox_nodes n
        JOIN proxmox_clusters c ON c.id = n.cluster_id
        WHERE n.is_active = 1
        ORDER BY c.name, n.node_name
    ");
    $nodes = $nodes_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    die("Ошибка при получении списка нод: " . $e->getMessage());
}

// Устанавливаем текущую ноду и тип образа
$current_node_id = filter_var($_GET['node_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) ?: null;
$image_type = $_GET['type'] ?? 'iso';
if (!in_array($image_type, ['cloud','iso','lxc','templates'], true)) $image_type='iso'; // 'iso', 'lxc' или 'templates'

// Определяем, находимся ли мы в разделе шаблонов из репозитория
$is_templates_section = ($image_type === 'templates');

if (!$current_node_id && !empty($nodes)) {
    $current_node_id = $nodes[0]['id'];
}

// Переменные для сообщений
$error = '';
$success = '';
$uploaded_filename = '';
$cloudNotice = $_SESSION['cloud_image_notice'] ?? null;
unset($_SESSION['cloud_image_notice']);
if (is_array($cloudNotice)) {
    if ($cloudNotice[0] === 'success') $success = $cloudNotice[1];
    else $error = $cloudNotice[1];
}

// Обработка загрузки файла (только для ISO и LXC, не для templates)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['image_file']) && !$is_templates_section) {
    try {
        $node_id = (int)$_POST['node_id'];
        $storage_name = trim($_POST['storage']);
        $image_type_post = $_POST['image_type'];
        $image_file = $_FILES['image_file'];

        // Проверки
        if (!$node_id) throw new Exception('Не выбрана нода');
        if (empty($storage_name)) throw new Exception('Не выбрано хранилище');
        if ($image_file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Ошибка загрузки файла: ' . $image_file['error']);
        }
        if ($image_file['size'] > 10 * 1024 * 1024 * 1024) { // 10GB max
            throw new Exception('Файл слишком большой (максимум 10GB)');
        }

        // Получаем информацию о ноде
        $node_stmt = safeQuery($pdo, "SELECT * FROM proxmox_nodes WHERE id = ? AND is_active=1", [$node_id], 'proxmox_nodes');
        $node = $node_stmt->fetch();
        if (!$node) throw new Exception('Нода не найдена');

        $filename = (string)$image_file['name'];
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.+-]{0,254}$/D', $filename) ||
            !in_array($image_type_post, ['iso','lxc'], true)) {
            throw new Exception('Некорректное имя или тип образа');
        }
        if (($image_type_post === 'iso' && !preg_match('/\.iso$/iD',$filename)) ||
            ($image_type_post === 'lxc' && !preg_match('/\.tar\.(?:gz|xz|zst)$/iD',$filename))) {
            throw new Exception('Поддерживаются .iso и шаблоны .tar.gz, .tar.xz, .tar.zst');
        }
        if (!is_uploaded_file($image_file['tmp_name'])) throw new Exception('Не найден загруженный файл');
        $api = new ProxmoxApiClient(panelNodeApiCredentials($node));
        // Stream the PHP upload directly; never create publicly accessible temporary copies.
        set_time_limit(3600);
        $api->uploadImage($node['node_name'], $storage_name, $image_file['tmp_name'], $filename,
            $image_type_post === 'iso' ? 'iso' : 'vztmpl');

        $uploaded_filename = $filename;
        $success = $image_type_post === 'iso'
            ? "ISO образ '{$filename}' успешно загружен в хранилище '{$storage_name}'"
            : "Шаблон LXC '{$filename}' успешно загружен в хранилище '{$storage_name}'";

        // Обновляем страницу для показа новых данных
        header("Location: image.php?node_id={$node_id}&type={$image_type_post}&success=" . urlencode($success));
        exit;

    } catch (Exception $e) {
        $error = $e->getMessage();
        // Удаляем временный файл если он был создан
        if (isset($temp_path) && file_exists($temp_path)) {
            @unlink($temp_path);
        }
    }
}

// Обработка скачивания шаблона из репозитория
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'download_template') {
    try {
        $node_id = (int)$_POST['node_id'];
        $storage_name = trim($_POST['storage']);
        $template_name = trim($_POST['template_name']);
        $section = $_POST['section'] ?? 'system';

        if (!$node_id) throw new Exception('Не выбрана нода');
        if (empty($storage_name)) throw new Exception('Не выбрано хранилище');
        if (empty($template_name)) throw new Exception('Не выбран шаблон');

        // Получаем информацию о ноде
        $node_stmt = safeQuery($pdo, "SELECT * FROM proxmox_nodes WHERE id = ? AND is_active=1", [$node_id], 'proxmox_nodes');
        $node = $node_stmt->fetch();
        if (!$node) throw new Exception('Нода не найдена');

        // Подключаемся к Proxmox через API
        $proxmoxApi = new ProxmoxAPI(
            $node['hostname'],
            $node['username'],
            $node['password'],
            $node['ssh_port'] ?? 22,
            $node['node_name'],
            $node['id'],
            $pdo
        );

        $proxmoxApi->downloadTemplate($storage_name, $template_name);
        $success = "Шаблон '{$template_name}' успешно скачан в хранилище '{$storage_name}'";

        // Обновляем страницу для показа новых данных
        header("Location: image.php?node_id={$node_id}&type=templates&success=" . urlencode($success));
        exit;

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Получаем информацию о текущей ноде
$current_node = null;
$storages = [];
$images = [];
$available_templates = [];
$cloudVolumes = [];
$cloudStores = [];
$mountedImages = [];
if ($image_type === 'cloud' && getenv('HOMEVLAD_CLOUD_IMAGE_SOURCE_DIR')) {
    require_once __DIR__ . '/../includes/cloud_image_import.php';
    try {
        if ($pdo->query("SHOW COLUMNS FROM cloud_image_settings LIKE 'pending_source'")->fetch()) {
            $mountedImages = mountedCloudImageNames();
        }
    } catch (PDOException $error) {
        error_log('Mounted cloud image inventory unavailable');
    }
}
$cloudSettings = [];
$cloudCanDelete = [];
$cloudExisting = [];
$image_delete_permissions = [];

if ($current_node_id) {
    // Находим выбранную ноду
    foreach ($nodes as $node) {
        if ($node['id'] == $current_node_id) {
            $current_node = $node;
            break;
        }
    }

    if ($current_node) {
        // Подключаемся к Proxmox
        try {
            $proxmoxApi = new ProxmoxAPI(
                $current_node['hostname'],
                $current_node['username'],
                $current_node['password'],
                $current_node['ssh_port'] ?? 22,
                $current_node['node_name'],
                $current_node['id'],
                $pdo
            );

            if ($image_type === 'cloud') {
                $nodePath = '/nodes/' . rawurlencode($current_node['node_name']);
                $storage = $proxmoxApi->request('GET', $nodePath . '/storage');
                foreach ($storage as $store) {
                    if (!empty($store['active']) && !empty($store['enabled']) &&
                        in_array('import', explode(',', $store['content'] ?? ''), true) &&
                        preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $store['storage'])) {
                        $cloudStores[] = $store['storage'];
                    }
                }
                $inStorage = cloudImportInventory([$proxmoxApi, 'request'], $current_node['node_name'], $storage);
                $cloudVolumes = cloudImportVolumes([$proxmoxApi, 'request'], $current_node['node_name'], $storage, $pdo, (int)$current_node_id, $inStorage);
                if ($pdo->query("SHOW COLUMNS FROM cloud_image_settings LIKE 'source_volume'")->fetch()) {
                    $cloudExisting = array_filter(array_keys($inStorage), static fn(string $volume): bool =>
                        !preg_match('~:import/homevlad-.*-[a-f0-9]{12}\.qcow2$~D',$volume));
                }
                $cloudSettings = cloudImageSettings($pdo, (int)$current_node_id);
                $permissions = $proxmoxApi->request('GET', '/access/permissions');
                foreach ($cloudStores as $store) {
                    if (!empty($permissions['/storage/' . $store]['Datastore.Allocate']) ||
                        !empty($permissions['/storage']['Datastore.Allocate']) || !empty($permissions['/']['Datastore.Allocate'])) {
                        $cloudCanDelete[$store] = true;
                    }
                }
            } else {
                $permissions = $proxmoxApi->request('GET','/access/permissions');
                foreach ($permissions as $path => $grants) {
                    if (str_starts_with($path,'/storage/') && !empty($grants['Datastore.Allocate'])) {
                        $image_delete_permissions[substr($path,9)] = true;
                    }
                }
                if (!empty($permissions['/storage']['Datastore.Allocate']) || !empty($permissions['/']['Datastore.Allocate'])) {
                    $image_delete_permissions['*'] = true;
                }

                // Получаем все хранилища
                $all_storages = $proxmoxApi->getNodeStorages(null);

                // Фильтруем хранилища по типу контента
                foreach ($all_storages as $storage) {
                    if ($is_templates_section || $image_type === 'lxc') {
                        if (isset($storage['content']) && strpos($storage['content'], 'vztmpl') !== false) {
                            $storages[] = $storage;
                        }
                    } elseif ($image_type === 'iso') {
                        if (isset($storage['content']) && strpos($storage['content'], 'iso') !== false) {
                            $storages[] = $storage;
                        }
                    }
                }

                // Получаем существующие образы
                if ($image_type === 'iso') {
                    $images = $proxmoxApi->getISOImages();
                } elseif ($image_type === 'lxc') {
                    $images = $proxmoxApi->getLXCTemplates();
                }

                // Получаем доступные шаблоны из репозитория
                if ($is_templates_section) {
                    $available_templates = $proxmoxApi->getAvailableTemplates();
                }

            }

        } catch (Exception $e) {
            $error = "Ошибка подключения к ноде: " . $e->getMessage();
        }
    }
}

// Обработка удаления образа
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_image' && $current_node_id && !$is_templates_section) {
    try {
        $volid = (string)($_POST['volid'] ?? '');

        if ($current_node) {
            $proxmoxApi = new ProxmoxAPI(
                $current_node['hostname'],
                $current_node['username'],
                $current_node['password'],
                $current_node['ssh_port'] ?? 22,
                $current_node['node_name'],
                $current_node['id'],
                $pdo
            );

            $content = $image_type === 'iso' ? 'iso' : 'vztmpl';
            if (!preg_match('#^([A-Za-z0-9_.-]+):'. $content .'/[A-Za-z0-9_.+-]+$#D', $volid, $match)) {
                throw new Exception('Неверный идентификатор образа');
            }
            if (empty($image_delete_permissions[$match[1]]) && empty($image_delete_permissions['*'])) {
                throw new Exception('Удаление образов недоступно текущему токену Proxmox: требуется Datastore.Allocate на хранилище');
            }
            // Full volid must remain one URL segment; omitting the storage prefix is invalid.
            $proxmoxApi->request('DELETE', '/nodes/'.rawurlencode($current_node['node_name']).
                '/storage/'.$match[1].'/content/'.rawurlencode($volid));

            $success = "Образ успешно удален";
            // Обновляем список образов
            header("Location: image.php?node_id=$current_node_id&type=$image_type&success=" . urlencode($success));
            exit;
        }
    } catch (Exception $e) {
        $error = "Ошибка удаления: " . $e->getMessage();
    }
}

// Проверяем success параметр в URL
if (isset($_GET['success'])) {
    $success = urldecode($_GET['success']);
}

// Функция для форматирования размера файла
function formatBytess($bytes, $decimals = 2) {
    // Преобразуем в число, если это строка
    if (is_string($bytes)) {
        $bytes = floatval($bytes);
    }

    if ($bytes <= 0) return '0 Bytes';

    $k = 1024;
    $dm = $decimals < 0 ? 0 : $decimals;
    $sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];

    $i = floor(log($bytes) / log($k));

    return number_format($bytes / pow($k, $i), $dm) . ' ' . $sizes[$i];
}

// Альтернативная функция для форматирования
function formatFileSizee($size) {
    if ($size <= 0) return '0 Bytes';

    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;

    while ($size >= 1024 && $i < count($units) - 1) {
        $size /= 1024;
        $i++;
    }

    return round($size, 2) . ' ' . $units[$i];
}

$title = "Управление образами | HomeVlad Cloud";
ob_start();
?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
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
            transition: margin-left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .sidebar-collapsed .main-content {
            margin-left: 80px;
        }

        @media (max-width: 992px) {
            .main-content {
                margin-left: 0;
                padding: 20px;
            }
        }

        /* Заголовок страницы */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid rgba(148, 163, 184, 0.1);
        }

        .page-title {
            font-size: 28px;
            font-weight: 700;
            background: linear-gradient(135deg, #00bcd4, #0097a7);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .page-title i {
            font-size: 32px;
        }

        /* Контейнер с двумя колонками */
        .two-column-layout {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
        }

        @media (max-width: 1200px) {
            .two-column-layout {
                grid-template-columns: 1fr;
            }
        }

        /* Карточки */
        .card {
            background: white;
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 24px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(148, 163, 184, 0.1);
        }

        body.dark-theme .card {
            background: rgba(30, 41, 59, 0.7);
            border-color: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
        }

        .card h3 {
            font-size: 18px;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        body.dark-theme .card h3 {
            color: #f1f5f9;
        }

        .card h3 i {
            color: #00bcd4;
            font-size: 20px;
        }

        /* Форма загрузки */
        .upload-form .form-group {
            margin-bottom: 25px;
        }

        .upload-form .form-label {
            display: block;
            margin-bottom: 8px;
            color: #1e293b;
            font-weight: 500;
        }

        body.dark-theme .upload-form .form-label {
            color: #f1f5f9;
        }

        .upload-form .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid rgba(148, 163, 184, 0.2);
            border-radius: 10px;
            background: white;
            color: #1e293b;
            font-size: 15px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        body.dark-theme .upload-form .form-control {
            background: rgba(30, 41, 59, 0.5);
            border-color: rgba(255, 255, 255, 0.1);
            color: #f1f5f9;
        }

        .upload-form .form-control:focus {
            outline: none;
            border-color: #00bcd4;
            box-shadow: 0 0 0 3px rgba(0, 188, 212, 0.1);
        }

        /* Загрузка файла */
        .file-upload-container {
            border: 2px dashed rgba(0, 188, 212, 0.3);
            border-radius: 12px;
            padding: 40px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            background: rgba(0, 188, 212, 0.05);
        }

        .file-upload-container:hover {
            border-color: #00bcd4;
            background: rgba(0, 188, 212, 0.1);
        }

        .file-upload-container i {
            font-size: 48px;
            color: #00bcd4;
            margin-bottom: 15px;
        }

        .file-upload-text {
            color: #64748b;
            margin-bottom: 10px;
            font-size: 16px;
        }

        body.dark-theme .file-upload-text {
            color: #94a3b8;
        }

        .file-upload-hint {
            color: #94a3b8;
            font-size: 14px;
        }

        body.dark-theme .file-upload-hint {
            color: #64748b;
        }

        .file-input {
            display: none;
        }

        .file-name {
            margin-top: 10px;
            color: #00bcd4;
            font-weight: 500;
        }

        /* Кнопка отправки */
        .btn-submit {
            display: block;
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #00bcd4, #0097a7);
            border: none;
            border-radius: 12px;
            color: white;
            font-size: 18px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            margin-top: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 188, 212, 0.3);
        }

        .btn-submit:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        /* Выбор типа образа */
        .image-type-selector {
            display: flex;
            gap: 10px;
            margin-bottom: 25px;
        }

        .image-type-btn {
            flex: 1;
            padding: 15px;
            border: 2px solid rgba(148, 163, 184, 0.2);
            border-radius: 10px;
            background: white;
            color: #64748b;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        body.dark-theme .image-type-btn {
            background: rgba(30, 41, 59, 0.5);
            border-color: rgba(255, 255, 255, 0.1);
            color: #94a3b8;
        }

        .image-type-btn:hover {
            border-color: #00bcd4;
            color: #00bcd4;
        }

        .image-type-btn.active {
            border-color: #00bcd4;
            background: rgba(0, 188, 212, 0.1);
            color: #00bcd4;
        }

        /* Таблица образов */
        .images-table {
            width: 100%;
            border-collapse: collapse;
        }

        .images-table th {
            text-align: left;
            padding: 15px;
            background: rgba(0, 188, 212, 0.1);
            color: #00bcd4;
            font-weight: 600;
            border-bottom: 2px solid rgba(0, 188, 212, 0.2);
        }

        .images-table td {
            padding: 15px;
            border-bottom: 1px solid rgba(148, 163, 184, 0.1);
            vertical-align: middle;
        }

        body.dark-theme .images-table td {
            border-color: rgba(255, 255, 255, 0.1);
        }

        .images-table tr:hover {
            background: rgba(0, 188, 212, 0.05);
        }

        .image-name {
            font-weight: 500;
            color: #1e293b;
        }

        body.dark-theme .image-name {
            color: #f1f5f9;
        }

        .image-storage {
            color: #64748b;
            font-size: 14px;
        }

        body.dark-theme .image-storage {
            color: #94a3b8;
        }

        .image-size {
            color: #00bcd4;
            font-weight: 500;
        }

        /* Кнопка удаления */
        .btn-delete {
            padding: 8px 16px;
            background: linear-gradient(135deg, #ef4444, #dc2626);
            border: none;
            border-radius: 8px;
            color: white;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-delete:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
        }

        /* Выбор ноды */
        .node-selector {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 30px;
            padding: 20px;
            background: rgba(0, 188, 212, 0.1);
            border-radius: 12px;
            border: 1px solid rgba(0, 188, 212, 0.2);
        }

        .node-selector label {
            font-weight: 600;
            color: #00bcd4;
            white-space: nowrap;
        }

        /* Уведомления */
        .notification {
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 30px;
            border: 1px solid;
            animation: slideIn 0.3s ease;
        }

        .notification-success {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(5, 150, 105, 0.1));
            border-color: rgba(16, 185, 129, 0.2);
            color: #10b981;
        }

        .notification-danger {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.1), rgba(220, 38, 38, 0.1));
            border-color: rgba(239, 68, 68, 0.2);
            color: #ef4444;
        }

        .notification i {
            font-size: 20px;
            margin-right: 10px;
        }

        /* Нет данных */
        .no-data {
            text-align: center;
            padding: 40px;
            color: #64748b;
            font-size: 16px;
            background: rgba(248, 250, 252, 0.5);
            border-radius: 12px;
            border: 2px dashed rgba(148, 163, 184, 0.2);
        }

        body.dark-theme .no-data {
            background: rgba(30, 41, 59, 0.3);
            color: #94a3b8;
            border-color: rgba(255, 255, 255, 0.1);
        }

        .no-data i {
            font-size: 48px;
            margin-bottom: 15px;
            display: block;
            color: #cbd5e1;
        }

        /* Прогресс загрузки */
        .upload-progress {
            margin-top: 20px;
            padding: 20px;
            background: rgba(0, 188, 212, 0.1);
            border-radius: 12px;
            border: 1px solid rgba(0, 188, 212, 0.2);
            display: none;
        }

        .progress-bar {
            height: 8px;
            background: rgba(148, 163, 184, 0.2);
            border-radius: 4px;
            overflow: hidden;
            margin-top: 10px;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(135deg, #00bcd4, #0097a7);
            border-radius: 4px;
            width: 0%;
            transition: width 0.3s ease;
        }

        /* Новые стили для секции шаблонов */
        .template-filter {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .template-filter-btn {
            padding: 8px 16px;
            background: white;
            border: 1px solid rgba(148, 163, 184, 0.2);
            border-radius: 8px;
            color: #64748b;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 14px;
        }

        body.dark-theme .template-filter-btn {
            background: rgba(30, 41, 59, 0.5);
            border-color: rgba(255, 255, 255, 0.1);
            color: #94a3b8;
        }

        .template-filter-btn:hover {
            border-color: #00bcd4;
            color: #00bcd4;
        }

        .template-filter-btn.active {
            background: rgba(0, 188, 212, 0.1);
            border-color: #00bcd4;
            color: #00bcd4;
        }

        .template-info {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 5px;
        }

        .template-distro {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
            background: rgba(0, 188, 212, 0.1);
            color: #00bcd4;
        }

        .template-section {
            font-size: 12px;
            color: #94a3b8;
            background: rgba(148, 163, 184, 0.1);
            padding: 2px 6px;
            border-radius: 4px;
        }

        .btn-download {
            padding: 8px 16px;
            background: linear-gradient(135deg, #10b981, #059669);
            border: none;
            border-radius: 8px;
            color: white;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-download:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }

        .btn-update {
            padding: 8px 16px;
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            border: none;
            border-radius: 8px;
            color: white;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-left: 10px;
        }

        .btn-update:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }

        .template-size {
            font-weight: 500;
            color: #8b5cf6;
        }

        .download-form {
            background: rgba(0, 188, 212, 0.05);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid rgba(0, 188, 212, 0.1);
        }

        .distro-icon {
            width: 20px;
            height: 20px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-right: 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
        }

        .distro-alpine { background: #0d597f; color: white; }
        .distro-debian { background: #a80030; color: white; }
        .distro-ubuntu { background: #e95420; color: white; }
        .distro-centos { background: #932279; color: white; }
        .distro-fedora { background: #294172; color: white; }
        .distro-arch { background: #1793d1; color: white; }
        .distro-opensuse { background: #73ba25; color: white; }
        .distro-turnkey { background: #333333; color: white; }
        .distro-unknown { background: #6b7280; color: white; }

        /* Анимации */
        @keyframes slideIn {
            from { opacity: 0; transform: translateX(20px); }
            to { opacity: 1; transform: translateX(0); }
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Мобильное меню */
        .mobile-menu-toggle {
            display: none;
            position: fixed;
            top: 20px;
            left: 20px;
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #00bcd4, #0097a7);
            border-radius: 12px;
            color: white;
            font-size: 24px;
            cursor: pointer;
            z-index: 1000;
            align-items: center;
            justify-content: center;
            box-shadow: 0 8px 25px rgba(0, 188, 212, 0.3);
        }

        @media (max-width: 992px) {
            .mobile-menu-toggle {
                display: flex;
            }
        }

        /* Адаптивность */
        @media (max-width: 768px) {
            .main-content {
                padding: 16px;
            }

            .card {
                padding: 20px;
            }

            .page-title {
                font-size: 24px;
            }

            .node-selector {
                flex-direction: column;
                align-items: stretch;
            }

            .image-type-selector {
                flex-direction: column;
            }

            .images-table {
                display: block;
                overflow-x: auto;
            }
        }
        .cloud-toolbar, .cloud-image-actions, .cloud-image-file {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: .5rem;
        }
        .cloud-toolbar { justify-content: space-between; margin-bottom: .75rem; }
        .cloud-toolbar h3 { margin: 0; }
        .cloud-image-entry { border: 1px solid #8799b254; border-radius: .6rem; margin-block: .4rem; }
        .cloud-image-entry > summary { padding: .55rem .75rem; cursor: pointer; overflow-wrap: anywhere; }
        .cloud-image-entry > summary small { margin-inline-start: .35rem; opacity: .85; }
        .cloud-image-entry > :not(summary) { margin-inline: .75rem; }
        .cloud-image-entry p { margin-block: .5rem; overflow-wrap: anywhere; }
        .cloud-image-form {
            display: grid;
            align-items: end;
            grid-template-columns: repeat(auto-fit, minmax(min(100%, 11rem), 1fr));
            gap: .5rem .65rem;
            margin-block: .5rem .75rem;
        }
        .cloud-image-form label { display: grid; min-width: 0; gap: .25rem; }
        .cloud-image-form .form-control { min-width: 0; width: 100%; }
        .cloud-image-actions, .cloud-image-form .cloud-checkbox { grid-column: 1 / -1; }
        .cloud-image-form .cloud-checkbox { display: flex; align-items: baseline; gap: .45rem; }
        .cloud-image-form > button { justify-self: start; }
        .cloud-images :is(.btn-update, .btn-delete) { margin: 0; padding: .4rem .7rem; max-width: 100%; white-space: normal; }
        .cloud-image-method { border-top: 1px solid #8799b254; padding-block: .4rem; }
        .cloud-image-method summary { cursor: pointer; }
        .cloud-image-file { margin-block: .5rem; overflow-wrap: anywhere; }
    </style>
<?php
$adminHeadContent = ob_get_clean();
require __DIR__ . '/admin_header.php';
?>
    <!-- Кнопка мобильного меню -->
    <button type="button" class="mobile-menu-toggle" id="mobileMenuToggle">
        <i class="fas fa-bars"></i>
    </button>

    <div class="main-container">
        <?php include 'admin_sidebar.php'; ?>

        <div class="main-content">
            <!-- Заголовок страницы -->
            <div class="page-header">
                <h1 class="page-title">
                    <i class="fas fa-images"></i> Управление образами
                </h1>
            </div>

            <!-- Уведомления -->
            <?php if ($error): ?>
                <div class="notification notification-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="notification notification-success">
                    <i class="fas fa-check-circle"></i>
                    <?= htmlspecialchars($success) ?>
                </div>
            <?php endif; ?>

            <!-- Выбор ноды -->
            <div class="node-selector">
                <label><i class="fas fa-server"></i> Нода:</label>
                <select id="node-select" class="form-control" style="flex: 1;">
                    <option value="">-- Выберите ноду --</option>
                    <?php foreach ($nodes as $node): ?>
                        <option value="<?= $node['id'] ?>"
                            <?= $node['id'] == $current_node_id ? 'selected' : '' ?>
                            data-hostname="<?= htmlspecialchars($node['hostname']) ?>">
                            <?= htmlspecialchars($node['node_name']) ?>
                            (<?= htmlspecialchars($node['cluster_name']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Выбор типа образа -->
            <div class="image-type-selector">
                <button type="button" class="image-type-btn <?= $image_type === 'cloud' ? 'active' : '' ?>" data-type="cloud">
                    <i class="fas fa-cloud"></i> Cloud-образы
                </button>
                <button type="button" class="image-type-btn <?= $image_type === 'iso' ? 'active' : '' ?>"
                        data-type="iso">
                    <i class="fas fa-compact-disc"></i> ISO образы
                </button>
                <button type="button" class="image-type-btn <?= $image_type === 'lxc' ? 'active' : '' ?>"
                        data-type="lxc">
                    <i class="fas fa-box"></i> Шаблоны LXC
                </button>
                <button type="button" class="image-type-btn <?= $image_type === 'templates' ? 'active' : '' ?>"
                        data-type="templates">
                    <i class="fas fa-cloud-download-alt"></i> Шаблоны из репозитория
                </button>
            </div>

            <?php if (empty($nodes)): ?>
                <div class="notification notification-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    Нет доступных нод. Пожалуйста, добавьте ноды в панели администратора.
                </div>
            <?php elseif (!$current_node): ?>
                <div class="notification notification-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    Выберите ноду для управления образами.
                </div>
            <?php elseif ($image_type === 'cloud'): ?>
                <section class="card cloud-images">
                    <div class="cloud-toolbar">
                        <h3><i class="fas fa-cloud"></i> Cloud-образы QEMU</h3>
                        <a class="btn-update" href="?node_id=<?= (int)$current_node_id ?>&amp;type=cloud">Обновить из Import</a>
                    </div>
                    <?php if (!$cloudStores): ?>
                        <p class="notification notification-warning">На ноде нет активного хранилища с типом Import.</p>
                    <?php endif ?>
                    <details class="cloud-image-entry">
                        <summary><strong>Создать группу образов</strong></summary>
                        <form class="cloud-image-form" method="post" action="cloud_image_action.php">
                            <input type="hidden" name="node_csrf" value="<?= htmlspecialchars($_SESSION['node_csrf'], ENT_QUOTES) ?>">
                            <input type="hidden" name="node_id" value="<?= (int)$current_node_id ?>">
                            <label>Ключ группы <input class="form-control" name="image_key" pattern="[a-z][a-z0-9\-]{0,31}" maxlength="32" placeholder="selectos" required></label>
                            <label>Название <input class="form-control" name="display_name" maxlength="100" placeholder="SelectOS" required></label>
                            <label>Пользователь cloud-init <input class="form-control" name="guest_user" pattern="[a-z_][a-z0-9_\-]{0,31}" maxlength="32" required></label>
                            <button class="btn-update" name="action" value="create_group">Создать группу</button>
                        </form>
                    </details>
                    <?php foreach (cloudImageCatalog($pdo) as $key => $cloud): ?>
                        <?php
                        $setting = $cloudSettings[$key] ?? [];
                        $files = $cloudVolumes[$key] ?? [];
                        $source = $setting['storage'] ?? '';
                        $preparedWindows = ($cloud['os_type'] ?? '') === 'windows';
                        $requiresPublication = $preparedWindows || !empty($cloud['custom']);
                        $label = $setting['display_name'] ?? '';
                        $enabled = !isset($setting['enabled']) || (bool)$setting['enabled'];
                        $published = $enabled && (!$requiresPublication || $source !== '') && array_filter($files, static fn($file) =>
                            $source === '' || str_starts_with($file['volid'], $source . ':'));
                        ?>
                        <details class="cloud-image-entry" <?= !empty($setting['pending_storage']) || !empty($setting['import_error']) ? 'open' : '' ?>>
                            <summary><strong><?= htmlspecialchars($label === '' ? $cloud['label'] : $label) ?></strong>
                                <small><?= $published ? 'В каталоге заказов' : 'Скрыт или отсутствует' ?></small>
                            </summary>
                            <p><?= htmlspecialchars($cloud['label']) ?> · ключ <code><?= htmlspecialchars($key) ?></code> · пользователь <code><?= htmlspecialchars($cloud['user']) ?></code>
                                <?php if ($source !== ''): ?> · источник <code><?= htmlspecialchars($source) ?></code><?php endif ?>
                            </p>
                            <?php if (!empty($setting['pending_storage'])): ?>
                                <p role="status">В очереди на импорт в <?= htmlspecialchars($setting['pending_storage']) ?>. Обновите страницу, чтобы проверить результат.</p>
                            <?php endif ?>
                            <?php if (!empty($setting['import_error'])): ?>
                                <p role="alert"><?= htmlspecialchars($setting['import_error']) ?></p>
                            <?php endif ?>
                            <form class="cloud-image-form" method="post" action="cloud_image_action.php">
                                <input type="hidden" name="node_csrf" value="<?= htmlspecialchars($_SESSION['node_csrf']) ?>">
                                <input type="hidden" name="node_id" value="<?= (int)$current_node_id ?>">
                                <input type="hidden" name="image_key" value="<?= htmlspecialchars($key) ?>">
                                <label>Название для пользователей
                                    <input class="form-control" name="display_name" maxlength="100" placeholder="<?= htmlspecialchars($cloud['label']) ?>" value="<?= htmlspecialchars($label) ?>">
                                </label>
                                <label>Хранилище исходника
                                    <select class="form-control" name="storage">
                                        <option value="">Выберите хранилище</option>
                                        <?php foreach ($cloudStores as $store): ?>
                                            <option value="<?= htmlspecialchars($store) ?>" <?= $store === ($setting['pending_storage'] ?? $source) ? 'selected' : '' ?>><?= htmlspecialchars($store) ?></option>
                                        <?php endforeach ?>
                                    </select>
                                </label>
                                <div class="cloud-image-actions">
                                    <button class="btn-update" type="submit" name="action" value="rename">Сохранить название</button>
                                    <button class="btn-update" type="submit" name="action" value="import" <?= !$cloudStores ? 'disabled' : '' ?>><?= $published ? 'Выбрать хранилище' : 'Добавить в каталог' ?></button>
                                    <?php if ($published || !empty($setting['pending_id'])): ?>
                                        <button class="btn-delete" type="submit" name="action" value="hide" onclick="return confirm('Убрать этот образ из заказов на текущей ноде?')">Убрать из каталога</button>
                                    <?php endif ?>
                                </div>
                            </form>
                            <?php if ($cloudExisting): ?>
                                <details class="cloud-image-method">
                                    <summary>Добавить готовый диск из Import</summary>
                                    <form class="cloud-image-form" method="post" action="cloud_image_action.php">
                                        <input type="hidden" name="node_csrf" value="<?= htmlspecialchars($_SESSION['node_csrf'], ENT_QUOTES) ?>">
                                        <input type="hidden" name="node_id" value="<?= (int)$current_node_id ?>">
                                        <input type="hidden" name="image_key" value="<?= htmlspecialchars($key, ENT_QUOTES) ?>">
                                        <label>Название в каталоге
                                            <input class="form-control" name="display_name" maxlength="100" value="<?= htmlspecialchars($label ?: $cloud['label'], ENT_QUOTES) ?>">
                                        </label>
                                        <label>Готовый диск в хранилище Proxmox
                                            <select class="form-control" name="volume" required>
                                                <?php foreach ($cloudExisting as $volume): ?>
                                                    <option value="<?= htmlspecialchars($volume, ENT_QUOTES) ?>"><?= htmlspecialchars($volume) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label>Минимальный размер диска (ГиБ)
                                            <input class="form-control" type="number" name="minimum_disk" min="1" max="1000" required>
                                        </label>
                                        <label class="cloud-checkbox"><input type="checkbox" name="standalone" value="1" required> Образ самостоятельный и подготовлен для Cloud-Init или Cloudbase-Init</label>
                                        <button class="btn-update" name="action" value="register_existing">Добавить файл в каталог</button>
                                    </form>
                                </details>
                            <?php endif; ?>
                            <?php if ($cloudStores && $mountedImages): ?>
                                <details class="cloud-image-method">
                                    <summary>Преобразовать файл из хранилища</summary>
                                    <form class="cloud-image-form" method="post" action="cloud_image_action.php">
                                        <input type="hidden" name="node_csrf" value="<?= htmlspecialchars($_SESSION['node_csrf'], ENT_QUOTES) ?>">
                                        <input type="hidden" name="node_id" value="<?= (int)$current_node_id ?>">
                                        <input type="hidden" name="image_key" value="<?= htmlspecialchars($key, ENT_QUOTES) ?>">
                                        <input type="hidden" name="display_name" value="<?= htmlspecialchars($label, ENT_QUOTES) ?>">
                                        <label>Файл на подключённом хранилище
                                            <select class="form-control" name="source_file" required>
                                                <?php foreach ($mountedImages as $filename): ?>
                                                    <option value="<?= htmlspecialchars($filename, ENT_QUOTES) ?>"><?= htmlspecialchars($filename) ?></option>
                                                <?php endforeach ?>
                                            </select>
                                        </label>
                                        <label>Хранилище Import на ноде
                                            <select class="form-control" name="storage" required>
                                                <?php foreach ($cloudStores as $store): ?>
                                                    <option value="<?= htmlspecialchars($store, ENT_QUOTES) ?>"><?= htmlspecialchars($store) ?></option>
                                                <?php endforeach ?>
                                            </select>
                                        </label>
                                        <button class="btn-update" name="action" value="convert_mounted">Преобразовать и добавить</button>
                                    </form>
                                </details>
                            <?php endif; ?>
                            <?php if ($cloudStores): ?>
                                <details class="cloud-image-method">
                                    <summary>Загрузить QCOW2 с компьютера</summary>
                                    <form class="cloud-image-form" method="post" action="cloud_image_action.php" enctype="multipart/form-data">
                                        <input type="hidden" name="node_csrf" value="<?= htmlspecialchars($_SESSION['node_csrf'], ENT_QUOTES) ?>">
                                        <input type="hidden" name="node_id" value="<?= (int)$current_node_id ?>">
                                        <input type="hidden" name="image_key" value="<?= htmlspecialchars($key, ENT_QUOTES) ?>">
                                        <label>Хранилище Import
                                            <select class="form-control" name="storage" required>
                                                <option value="">Выберите хранилище</option>
                                                <?php foreach ($cloudStores as $store): ?>
                                                    <option value="<?= htmlspecialchars($store, ENT_QUOTES) ?>"><?= htmlspecialchars($store) ?></option>
                                                <?php endforeach ?>
                                            </select>
                                        </label>
                                        <label>Готовый файл QCOW2 <input type="file" name="image_file" accept=".qcow2" required></label>
                                        <button class="btn-update" name="action" value="upload">Загрузить образ</button>
                                    </form>
                                </details>
                            <?php endif ?>
                            <?php foreach ($files as $file): ?>
                                <?php $store = explode(':', $file['volid'], 2)[0]; ?>
                                <form class="cloud-image-file" method="post" action="cloud_image_action.php">
                                    <code><?= htmlspecialchars($file['volid']) ?></code> · диск от <?= (int)$file['minimum_disk'] ?> ГБ
                                    <?php if (empty($file['existing_source']) && isset($cloudCanDelete[$store])): ?>
                                        <input type="hidden" name="node_csrf" value="<?= htmlspecialchars($_SESSION['node_csrf']) ?>">
                                        <input type="hidden" name="node_id" value="<?= (int)$current_node_id ?>">
                                        <input type="hidden" name="image_key" value="<?= htmlspecialchars($key) ?>">
                                        <input type="hidden" name="volume" value="<?= htmlspecialchars($file['volid']) ?>">
                                        <button class="btn-delete" type="submit" name="action" value="delete_file" onclick="return confirm('Удалить файл из Proxmox? Общее хранилище может использоваться несколькими нодами.')">Удалить файл</button>
                                    <?php endif ?>
                                </form>
                            <?php endforeach ?>
                            <?php if ($preparedWindows): ?>
                                <p>Загрузите подготовленный QCOW2 с Cloudbase-Init в хранилище Import через Proxmox, затем опубликуйте его здесь.</p>
                            <?php else: ?>
                                <p><a href="<?= htmlspecialchars($cloud['base']) ?>" target="_blank" rel="noopener noreferrer">Каталог издателя</a></p>
                            <?php endif ?>
                        </details>
                    <?php endforeach ?>
                </section>
            <?php elseif ($is_templates_section): ?>
                <!-- Секция шаблонов из репозитория -->
                <div class="card" style="grid-column: span 2;">
                    <h3>
                        <i class="fas fa-cloud-download-alt"></i> Шаблоны из репозитория Proxmox
                        <button class="btn-update" onclick="updateTemplatesList()">
                            <i class="fas fa-sync-alt"></i> Обновить список шаблонов
                        </button>
                    </h3>

                    <?php if (empty($storages)): ?>
                        <div class="notification notification-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            На выбранной ноде нет хранилищ, поддерживающих шаблоны LXC (vztmpl).
                            Добавьте хранилище в Proxmox перед использованием этой функции.
                        </div>
                    <?php else: ?>
                        <div class="template-filter" id="template-filter">
                            <button class="template-filter-btn active" data-section="all">Все шаблоны</button>
                            <button class="template-filter-btn" data-section="system">Системные</button>
                            <button class="template-filter-btn" data-section="turnkeylinux">TurnKey Linux</button>
                            <button class="template-filter-btn" data-distro="alpine">Alpine</button>
                            <button class="template-filter-btn" data-distro="debian">Debian</button>
                            <button class="template-filter-btn" data-distro="ubuntu">Ubuntu</button>
                            <button class="template-filter-btn" data-distro="centos">CentOS</button>
                        </div>

                        <?php if (empty($available_templates)): ?>
                            <div class="no-data">
                                <i class="fas fa-folder-open"></i>
                                Нет доступных шаблонов или ошибка подключения к репозиторию.
                                <br><br>
                                <button class="btn-update" onclick="updateTemplatesList()">
                                    <i class="fas fa-sync-alt"></i> Попробовать обновить список
                                </button>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="images-table">
                                    <thead>
                                        <tr>
                                            <th>Шаблон</th>
                                            <th>Раздел</th>
                                            <th>Размер</th>
                                            <th>Действия</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($available_templates as $template):
                                            $template_name = $template['name'] ?? '';
                                            $section = $template['section'] ?? '';
                                            $size = $template['size'] ?? 'N/A';
                                            $template_id = $template['id'] ?? $template_name;

                                            // Определяем дистрибутив по имени
                                            $distro = 'unknown';
                                            $distro_name = 'Unknown';
                                            $distro_class = 'distro-unknown';

                                            $template_lower = strtolower($template_name);
                                            if (strpos($template_lower, 'alpine') !== false) {
                                                $distro = 'alpine';
                                                $distro_name = 'Alpine';
                                                $distro_class = 'distro-alpine';
                                            } elseif (strpos($template_lower, 'debian') !== false) {
                                                $distro = 'debian';
                                                $distro_name = 'Debian';
                                                $distro_class = 'distro-debian';
                                            } elseif (strpos($template_lower, 'ubuntu') !== false) {
                                                $distro = 'ubuntu';
                                                $distro_name = 'Ubuntu';
                                                $distro_class = 'distro-ubuntu';
                                            } elseif (strpos($template_lower, 'centos') !== false) {
                                                $distro = 'centos';
                                                $distro_name = 'CentOS';
                                                $distro_class = 'distro-centos';
                                            } elseif (strpos($template_lower, 'fedora') !== false) {
                                                $distro = 'fedora';
                                                $distro_name = 'Fedora';
                                                $distro_class = 'distro-fedora';
                                            } elseif (strpos($template_lower, 'archlinux') !== false || strpos($template_lower, 'arch') !== false) {
                                                $distro = 'arch';
                                                $distro_name = 'Arch';
                                                $distro_class = 'distro-arch';
                                            } elseif (strpos($template_lower, 'opensuse') !== false) {
                                                $distro = 'opensuse';
                                                $distro_name = 'openSUSE';
                                                $distro_class = 'distro-opensuse';
                                            } elseif ($section === 'turnkeylinux') {
                                                $distro = 'turnkey';
                                                $distro_name = 'TurnKey';
                                                $distro_class = 'distro-turnkey';
                                            }
                                        ?>
                                            <tr class="template-row" data-section="<?= htmlspecialchars($section) ?>" data-distro="<?= $distro ?>">
                                                <td>
                                                    <div class="template-info">
                                                        <span class="<?= $distro_class ?>"><?= substr($distro_name, 0, 1) ?></span>
                                                        <div class="image-name"><?= htmlspecialchars($template_name) ?></div>
                                                    </div>
                                                    <small class="text-muted"><?= $distro_name ?> • ID: <?= htmlspecialchars($template_id) ?></small>
                                                </td>
                                                <td>
                                                    <span class="template-section"><?= htmlspecialchars($section) ?></span>
                                                </td>
                                                <td>
                                                    <span class="template-size">
                                                        <?= htmlspecialchars(is_numeric($size) ? formatBytess((float)$size) : (string)$size, ENT_QUOTES) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <button class="btn-download"
                                                            onclick="showDownloadModal(<?= htmlspecialchars(json_encode($template_name), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($section), ENT_QUOTES) ?>)">
                                                        <i class="fas fa-download"></i> Скачать
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <!-- Существующий код для ISO и LXC -->
                <div class="two-column-layout">
                    <!-- Левая колонка: Загрузка образа -->
                    <div class="card">
                        <h3><i class="fas fa-upload"></i> Загрузить <?= $image_type === 'iso' ? 'ISO образ' : 'шаблон LXC' ?></h3>

                        <form method="POST" enctype="multipart/form-data" class="upload-form" id="upload-form">
                            <input type="hidden" name="node_csrf" value="<?= htmlspecialchars($_SESSION['node_csrf'], ENT_QUOTES) ?>">
                            <input type="hidden" name="node_id" value="<?= $current_node_id ?>">
                            <input type="hidden" name="image_type" value="<?= $image_type ?>">

                            <div class="form-group">
                                <label class="form-label">Хранилище:</label>
                                <select name="storage" class="form-control" required>
                                    <option value="">-- Выберите хранилище --</option>
                                    <?php foreach ($storages as $storage): ?>
                                        <option value="<?= htmlspecialchars($storage['name']) ?>">
                                            <?= htmlspecialchars($storage['name']) ?>
                                            (<?= $storage['type'] ?>, <?= $storage['available'] ?? 0 ?>GB свободно)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">
                                    <?php if ($image_type === 'iso'): ?>
                                        Поддерживаемый формат: .iso
                                    <?php else: ?>
                                        Поддерживаемые форматы: .tar.gz, .tar.xz, .tar.zst
                                    <?php endif; ?>
                                </small>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Файл:</label>
                                <div class="file-upload-container" id="file-upload-area">
                                    <i class="fas fa-cloud-upload-alt"></i>
                                    <div class="file-upload-text">
                                        Нажмите для выбора файла или перетащите его сюда
                                    </div>
                                    <div class="file-upload-hint">
                                        Максимальный размер: 10GB
                                    </div>
                                    <div class="file-name" id="file-name"></div>
                                    <input type="file" name="image_file" class="file-input"
                                           id="image-file" required
                                           accept="<?= $image_type === 'iso' ? '.iso' : '.tar.gz,.tar.xz,.tar.zst' ?>">
                                </div>
                            </div>

                            <!-- Прогресс загрузки -->
                            <div class="upload-progress" id="upload-progress">
                                <div class="d-flex justify-content-between">
                                    <span>Загрузка...</span>
                                    <span id="progress-percent">0%</span>
                                </div>
                                <div class="progress-bar">
                                    <div class="progress-fill" id="progress-fill"></div>
                                </div>
                            </div>

                            <button type="submit" class="btn-submit" id="submit-btn">
                                <i class="fas fa-upload"></i> Загрузить
                            </button>
                        </form>
                    </div>

                    <!-- Правая колонка: Список образов -->
                    <div class="card">
                        <h3><i class="fas fa-list"></i> Доступные <?= $image_type === 'iso' ? 'ISO образы' : 'шаблоны LXC' ?></h3>

                        <?php if (empty($images)): ?>
                            <div class="no-data">
                                <i class="fas fa-folder-open"></i>
                                Нет <?= $image_type === 'iso' ? 'ISO образов' : 'шаблонов LXC' ?>
                            </div>
                        <?php else: ?>
                            <?php if (empty($image_delete_permissions)): ?>
                                <div class="notification notification-warning">Текущий токен позволяет загружать образы. Удаление требует права Datastore.Allocate, которое также разрешает менять настройки хранилища. Для удаления используйте интерфейс Proxmox.</div>
                            <?php endif; ?>
                            <div class="table-responsive">
                                <table class="images-table">
                                    <thead>
                                        <tr>
                                            <th>Имя файла</th>
                                            <th>Хранилище</th>
                                            <th>Размер</th>
                                            <th>Действия</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($images as $image):
                                            // Безопасное получение данных
                                            $image_name = $image['name'] ?? (isset($image['volid']) ? basename($image['volid']) : 'Unknown');
                                            $storage = $image['storage'] ?? 'N/A';
                                            $size = $image['size'] ?? 0;
                                            $volid = $image['volid'] ?? '';
                                        ?>
                                            <tr>
                                                <td>
                                                    <div class="image-name"><?= htmlspecialchars($image_name) ?></div>
                                                </td>
                                                <td>
                                                    <span class="image-storage"><?= htmlspecialchars($storage) ?></span>
                                                </td>
                                                <td>
                                                    <span class="image-size">
                                                        <?= htmlspecialchars(is_numeric($size) ? formatBytess((float)$size) : (string)$size, ENT_QUOTES) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if (!empty($volid) && (!empty($image_delete_permissions[$storage]) || !empty($image_delete_permissions['*']))): ?>
                                                        <button class="btn-delete"
                                                                onclick="confirmDelete(<?= htmlspecialchars(json_encode($volid), ENT_QUOTES) ?>)">
                                                            <i class="fas fa-trash"></i> Удалить
                                                        </button>
                                                    <?php else: ?>
                                                        <span class="text-muted" title="Для удаления Proxmox требует Datastore.Allocate на хранилище">Удаление недоступно токену</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Модальное окно для скачивания шаблона -->
    <div class="modal fade" id="downloadModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-download"></i> Скачать шаблон</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="download-template-form">
                    <input type="hidden" name="node_csrf" value="<?= htmlspecialchars($_SESSION['node_csrf'], ENT_QUOTES) ?>">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="download_template">
                        <input type="hidden" name="node_id" id="modal-node-id" value="<?= $current_node_id ?>">
                        <input type="hidden" name="template_name" id="modal-template-name">
                        <input type="hidden" name="section" id="modal-template-section">

                        <div class="mb-3">
                            <label class="form-label">Выбранный шаблон:</label>
                            <div class="form-control" id="selected-template-name" style="background: #f8f9fa; font-weight: 500;"></div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Хранилище для скачивания:</label>
                            <select name="storage" class="form-control" required id="modal-storage-select">
                                <option value="">-- Выберите хранилище --</option>
                                <?php foreach ($storages as $storage): ?>
                                    <option value="<?= htmlspecialchars($storage['name']) ?>">
                                        <?= htmlspecialchars($storage['name']) ?>
                                        (<?= $storage['type'] ?>, <?= $storage['available'] ?? 0 ?>GB свободно)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">
                                Шаблон будет скачан в выбранное хранилище. Убедитесь, что есть достаточно свободного места.
                            </small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                        <button type="submit" class="btn-download">
                            <i class="fas fa-download"></i> Начать скачивание
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php include 'admin_footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Функция для форматирования байтов (JavaScript версия)
            function formatBytess(bytes, decimals = 2) {
                if (bytes === 0) return '0 Bytes';
                const k = 1024;
                const dm = decimals < 0 ? 0 : decimals;
                const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
                const i = Math.floor(Math.log(bytes) / Math.log(k));
                return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
            }

            // Переключение типа образа
            const imageTypeBtns = document.querySelectorAll('.image-type-btn');
            const nodeSelect = document.getElementById('node-select');

            imageTypeBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    const type = this.dataset.type;
                    const nodeId = nodeSelect ? nodeSelect.value : null;

                    if (!nodeId) {
                        Swal.fire({
                            title: 'Выберите ноду',
                            text: 'Пожалуйста, сначала выберите ноду',
                            icon: 'warning',
                            confirmButtonText: 'OK'
                        });
                        return;
                    }

                    window.location.href = `image.php?node_id=${nodeId}&type=${type}`;
                });
            });

            // Смена ноды
            if (nodeSelect) {
                nodeSelect.addEventListener('change', function() {
                    const nodeId = this.value;
                    if (!nodeId) return;

                    const urlParams = new URLSearchParams(window.location.search);
                    const type = urlParams.get('type') || 'iso';

                    window.location.href = `image.php?node_id=${nodeId}&type=${type}`;
                });
            }

            // Загрузка файла
            const fileUploadArea = document.getElementById('file-upload-area');
            const fileInput = document.getElementById('image-file');
            const fileName = document.getElementById('file-name');

            if (fileUploadArea && fileInput) {
                // Клик по области
                fileUploadArea.addEventListener('click', function() {
                    fileInput.click();
                });

                // Перетаскивание файла
                fileUploadArea.addEventListener('dragover', function(e) {
                    e.preventDefault();
                    this.style.borderColor = '#00bcd4';
                    this.style.background = 'rgba(0, 188, 212, 0.15)';
                });

                fileUploadArea.addEventListener('dragleave', function(e) {
                    e.preventDefault();
                    this.style.borderColor = 'rgba(0, 188, 212, 0.3)';
                    this.style.background = 'rgba(0, 188, 212, 0.05)';
                });

                fileUploadArea.addEventListener('drop', function(e) {
                    e.preventDefault();
                    this.style.borderColor = 'rgba(0, 188, 212, 0.3)';
                    this.style.background = 'rgba(0, 188, 212, 0.05)';

                    if (e.dataTransfer.files.length) {
                        fileInput.files = e.dataTransfer.files;
                        updateFileName();
                    }
                });

                // Выбор файла через диалог
                fileInput.addEventListener('change', updateFileName);

                function updateFileName() {
                    if (fileInput.files.length > 0) {
                        const file = fileInput.files[0];
                        fileName.textContent = `${file.name} (${formatBytess(file.size)})`;
                        fileName.style.display = 'block';
                    } else {
                        fileName.style.display = 'none';
                    }
                }
            }

            // Отправка формы с прогрессом
            const uploadForm = document.getElementById('upload-form');
            const uploadProgress = document.getElementById('upload-progress');
            const progressFill = document.getElementById('progress-fill');
            const progressPercent = document.getElementById('progress-percent');
            const submitBtn = document.getElementById('submit-btn');

            if (uploadForm) {
                uploadForm.addEventListener('submit', function(e) {
                    e.preventDefault();

                    const formData = new FormData(this);
                    const xhr = new XMLHttpRequest();

                    // Показываем прогресс
                    uploadProgress.style.display = 'block';
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Загрузка...';

                    // Отслеживаем прогресс
                    xhr.upload.addEventListener('progress', function(e) {
                        if (e.lengthComputable) {
                            const percent = (e.loaded / e.total) * 100;
                            progressFill.style.width = percent + '%';
                            progressPercent.textContent = Math.round(percent) + '%';
                        }
                    });

                    // Завершение загрузки
                    xhr.addEventListener('load', function() {
                        if (xhr.status === 200) {
                            // Перезагружаем страницу
                            window.location.reload();
                        } else {
                            Swal.fire({
                                title: 'Ошибка',
                                text: 'Произошла ошибка при загрузке файла',
                                icon: 'error',
                                confirmButtonText: 'OK'
                            });
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = '<i class="fas fa-upload"></i> Загрузить';
                            uploadProgress.style.display = 'none';
                        }
                    });

                    // Ошибка
                    xhr.addEventListener('error', function() {
                        Swal.fire({
                            title: 'Ошибка',
                            text: 'Произошла ошибка сети',
                            icon: 'error',
                            confirmButtonText: 'OK'
                        });
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = '<i class="fas fa-upload"></i> Загрузить';
                        uploadProgress.style.display = 'none';
                    });

                    // Отправляем запрос
                    xhr.open('POST', 'image.php');
                    xhr.send(formData);
                });
            }

            // Подтверждение удаления
            window.confirmDelete = function(volid) {
                Swal.fire({
                    title: 'Удалить образ?',
                    text: 'Вы уверены, что хотите удалить этот образ? Это действие нельзя отменить.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#ef4444',
                    cancelButtonColor: '#64748b',
                    confirmButtonText: 'Да, удалить',
                    cancelButtonText: 'Отмена'
                }).then((result) => {
                    if (result.isConfirmed) {
                        const urlParams = new URLSearchParams(window.location.search);
                        const nodeId = urlParams.get('node_id');
                        const type = urlParams.get('type') || 'iso';

                        const form = document.createElement('form');
                        form.method = 'POST';
                        form.action = `image.php?node_id=${encodeURIComponent(nodeId || <?= json_encode((string)$current_node_id) ?>)}&type=${encodeURIComponent(type)}`;
                        const fields = {action:'delete_image', volid, node_csrf:<?= json_encode($_SESSION['node_csrf']) ?>};
                        for (const [name,value] of Object.entries(fields)) {
                            const input=document.createElement('input');input.type='hidden';input.name=name;input.value=value;form.appendChild(input);
                        }
                        document.body.appendChild(form);form.submit();
                    }
                });
            };

            // Фильтрация шаблонов
            const filterBtns = document.querySelectorAll('.template-filter-btn');
            const templateRows = document.querySelectorAll('.template-row');

            if (filterBtns.length > 0) {
                filterBtns.forEach(btn => {
                    btn.addEventListener('click', function() {
                        // Убираем активный класс у всех кнопок
                        filterBtns.forEach(b => b.classList.remove('active'));
                        // Добавляем активный класс текущей кнопке
                        this.classList.add('active');

                        const section = this.dataset.section;
                        const distro = this.dataset.distro;

                        // Показываем/скрываем строки таблицы
                        templateRows.forEach(row => {
                            if (section === 'all') {
                                row.style.display = '';
                            } else if (section && row.dataset.section === section) {
                                row.style.display = '';
                            } else if (distro && row.dataset.distro === distro) {
                                row.style.display = '';
                            } else {
                                row.style.display = 'none';
                            }
                        });
                    });
                });
            }

            // Показать модальное окно скачивания
            window.showDownloadModal = function(templateName, section) {
                document.getElementById('modal-template-name').value = templateName;
                document.getElementById('modal-template-section').value = section;
                document.getElementById('selected-template-name').textContent = templateName;
                document.getElementById('modal-node-id').value = '<?= $current_node_id ?>';

                // Инициализируем Bootstrap модальное окно
                const downloadModal = new bootstrap.Modal(document.getElementById('downloadModal'));
                downloadModal.show();
            };

            // Обновление списка шаблонов
            window.updateTemplatesList = function() {
                const nodeId = '<?= $current_node_id ?>';
                if (!nodeId) {
                    Swal.fire({
                        title: 'Выберите ноду',
                        text: 'Пожалуйста, сначала выберите ноду',
                        icon: 'warning',
                        confirmButtonText: 'OK'
                    });
                    return;
                }

                Swal.fire({
                    title: 'Обновление списка шаблонов',
                    text: 'Пожалуйста, подождите...',
                    icon: 'info',
                    showConfirmButton: false,
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });

                window.location.href = `image.php?node_id=${nodeId}&type=templates`;
            };

            // Мобильное меню
            const mobileMenuToggle = document.getElementById('mobileMenuToggle');
            if (mobileMenuToggle) {
                mobileMenuToggle.addEventListener('click', function() {
                    document.body.classList.toggle('sidebar-open');
                });

                function checkScreenSize() {
                    if (window.innerWidth <= 992) {
                        document.body.classList.add('sidebar-closed');
                    } else {
                        document.body.classList.remove('sidebar-closed', 'sidebar-open');
                    }
                }

                checkScreenSize();
                window.addEventListener('resize', checkScreenSize);
            }

            // Обработка формы скачивания шаблона
            const downloadForm = document.getElementById('download-template-form');
            if (downloadForm) {
                downloadForm.addEventListener('submit', function(e) {
                    const storageSelect = document.getElementById('modal-storage-select');
                    if (!storageSelect.value) {
                        e.preventDefault();
                        Swal.fire({
                            title: 'Ошибка',
                            text: 'Пожалуйста, выберите хранилище для скачивания',
                            icon: 'warning',
                            confirmButtonText: 'OK'
                        });
                        return;
                    }

                    // Показываем уведомление о начале скачивания
                    const modal = bootstrap.Modal.getInstance(document.getElementById('downloadModal'));
                    modal.hide();

                    Swal.fire({
                        title: 'Начинаем скачивание',
                        text: 'Шаблон скачивается из репозитория. Это может занять несколько минут.',
                        icon: 'info',
                        showConfirmButton: false,
                        allowOutsideClick: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                });
            }
        });
    </script>
</body>
</html>
