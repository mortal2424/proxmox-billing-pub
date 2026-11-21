<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/node_admin_guard.php';
require_once __DIR__ . '/../includes/zone_placement.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

$pdo = (new Database())->getConnection();
requireNodeAdministrator($pdo);
$nodeId = filter_input(INPUT_POST, 'node_id', FILTER_VALIDATE_INT);
$key = $_POST['image_key'] ?? '';
$action = $_POST['action'] ?? '';
$label = $_POST['display_name'] ?? '';
$storage = $_POST['storage'] ?? '';
$volume = $_POST['volume'] ?? '';

try {
    if (!$nodeId || !is_string($key) || !isset(cloudImageCatalog()[$key]) ||
        !in_array($action, ['rename', 'import', 'hide', 'delete_file'], true)) {
        throw new InvalidArgumentException('Неизвестный cloud-образ или действие');
    }
    if (!is_string($label) || mb_strlen(trim($label)) > 100 || preg_match('/[[:cntrl:]]/u', $label)) {
        throw new InvalidArgumentException('Название должно содержать до 100 символов без управляющих знаков');
    }
    $query = $pdo->prepare('SELECT * FROM proxmox_nodes WHERE id=? AND is_active=1');
    $query->execute([$nodeId]);
    $node = $query->fetch();
    if (!$node) throw new InvalidArgumentException('Активная нода не найдена');

    if ($action === 'hide') {
        $query = $pdo->prepare('INSERT INTO cloud_image_settings (node_id,image_key,enabled) VALUES (?,?,0)
            ON DUPLICATE KEY UPDATE enabled=0,pending_storage=NULL,pending_id=NULL,import_error=NULL');
        $query->execute([$nodeId, $key]);
        $message = 'Образ убран из заказов на этой ноде.';
    } elseif ($action === 'rename') {
        $query = $pdo->prepare('INSERT INTO cloud_image_settings (node_id,image_key,display_name) VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE display_name=VALUES(display_name)');
        $query->execute([$nodeId, $key, trim($label)]);
        $message = 'Название сохранено.';
    } else {
        $api = placementClient($node, $pdo);
        $nodePath = '/nodes/' . rawurlencode($node['node_name']);
        $stores = $api->request('GET', $nodePath . '/storage');
        $inventories = cloudImportVolumes([$api, 'request'], $node['node_name'], $stores);
        $sources = array_column($inventories[$key] ?? [], 'volid');

        if ($action === 'import') {
            $target = is_string($storage) ? array_column($stores, null, 'storage')[$storage] ?? null : null;
            if (!$target || empty($target['active']) || empty($target['enabled']) ||
                !in_array('import', explode(',', $target['content'] ?? ''), true)) {
                throw new InvalidArgumentException('Выберите активное хранилище с типом Import');
            }
            $existing = array_filter($sources, static fn($source) => str_starts_with($source, "$storage:"));
            if ($existing) {
                $query = $pdo->prepare('INSERT INTO cloud_image_settings (node_id,image_key,display_name,storage) VALUES (?,?,?,?)
                    ON DUPLICATE KEY UPDATE display_name=VALUES(display_name),storage=VALUES(storage),enabled=1,
                    pending_storage=NULL,pending_id=NULL,import_error=NULL');
                $query->execute([$nodeId, $key, trim($label), $storage]);
                $message = 'Образ опубликован из выбранного хранилища.';
            } else {
                $query = $pdo->prepare('INSERT INTO cloud_image_settings (node_id,image_key,display_name,enabled,pending_storage,pending_id)
                    VALUES (?,?,?,0,?,?) ON DUPLICATE KEY UPDATE display_name=VALUES(display_name),pending_storage=VALUES(pending_storage),
                    pending_id=VALUES(pending_id),import_error=NULL');
                $query->execute([$nodeId, $key, trim($label), $storage, bin2hex(random_bytes(16))]);
                $message = 'Импорт поставлен в очередь. Источник заказов переключится после загрузки.';
            }
        } else {
            if (!is_string($volume) || !in_array($volume, $sources, true)) {
                throw new InvalidArgumentException('Файл не найден в каталоге облачных образов ноды');
            }
            $sourceStore = explode(':', $volume, 2)[0];
            $permissions = $api->request('GET', '/access/permissions', ['path' => '/storage/' . $sourceStore]);
            if (empty($permissions['/storage/' . $sourceStore]['Datastore.Allocate'])) {
                throw new InvalidArgumentException('Токен не может удалять файлы из этого хранилища. Доступно снятие с публикации.');
            }
            withGuestOperationLocks(["cloud-image-volume:$volume"], static function () use ($api, $nodePath, $sourceStore, $volume): void {
                $path = $nodePath . '/storage/' . rawurlencode($sourceStore) . '/content';
                $api->request('DELETE', $path . '/' . rawurlencode($volume));
                foreach ($api->request('GET', $path, ['content' => 'import']) as $file) {
                    if (($file['volid'] ?? '') === $volume) throw new RuntimeException('Файл ещё присутствует в хранилище');
                }
            });
            $message = 'Файл образа удалён из хранилища.';
        }
    }
    $_SESSION['cloud_image_notice'] = ['success', $message];
} catch (Throwable $error) {
    $_SESSION['cloud_image_notice'] = ['error', $error instanceof InvalidArgumentException ? $error->getMessage() : 'Операция с образом не завершилась. Проверьте подключение или журнал.'];
    if (!$error instanceof InvalidArgumentException) error_log('Cloud image admin operation failed: ' . get_class($error));
}

header('Location: image.php?' . http_build_query(['type' => 'cloud', 'node_id' => $nodeId ?: '']), true, 303);
