<?php
require_once __DIR__ . '/../includes/cli_only.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/cloud_image_import.php';

$pdo = (new Database())->getConnection();
try {
    withGuestOperationLocks(['cloud-image-worker'], static function () use ($pdo): void {
        $job = $pdo->query('SELECT s.* FROM cloud_image_settings s
            JOIN proxmox_nodes n ON n.id=s.node_id WHERE s.pending_id IS NOT NULL AND n.is_active=1
            ORDER BY s.node_id,s.image_key LIMIT 1')->fetch();
        if (!$job) return;

        $error = null;
        try {
            $query = $pdo->prepare('SELECT * FROM proxmox_nodes WHERE id=? AND is_active=1');
            $query->execute([$job['node_id']]);
            $node = $query->fetch();
            if (!$node) throw new RuntimeException('Нода недоступна');
            if (empty($job['pending_source'])) {
                importCloudImage($node, $job['pending_storage'], $job['image_key']);
            } else {
                convertMountedCloudImage($node, $job['pending_storage'], $job['image_key'], $job['pending_source']);
            }
        } catch (Throwable $failure) {
            $error = $failure instanceof RuntimeException && !($failure instanceof PDOException)
                ? mb_substr($failure->getMessage(), 0, 255)
                : 'Не удалось импортировать образ';
        }

        // The downloader can run for minutes, so reconnect before recording the result.
        $current = (new Database())->getConnection();
        $key = [$job['node_id'], $job['image_key'], $job['pending_id']];
        $clearSource = array_key_exists('pending_source', $job) ? 'pending_source=NULL,' : '';
        if ($error === null) {
            $clearAlias = array_key_exists('source_volume',$job) ? 'source_volume=NULL,source_minimum_disk=NULL,' : '';
            $query = $current->prepare("UPDATE cloud_image_settings SET enabled=1,storage=pending_storage,
                pending_storage=NULL,pending_id=NULL,{$clearSource}{$clearAlias}import_error=NULL WHERE node_id=? AND image_key=? AND pending_id=?");
            $query->execute($key);
        } else {
            $query = $current->prepare("UPDATE cloud_image_settings SET pending_storage=NULL,pending_id=NULL,{$clearSource}import_error=?
                WHERE node_id=? AND image_key=? AND pending_id=?");
            $query->execute([$error, ...$key]);
        }
    });
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'Уже выполняется другая операция. Дождитесь её завершения') throw $error;
}
