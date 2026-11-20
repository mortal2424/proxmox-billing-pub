<?php
require_once __DIR__ . '/../includes/cli_only.php';
require_once __DIR__ . '/../includes/node_credentials.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/backup_database.php';

$operationLock = null;
try {
    if (($argv[1] ?? '') === '--check') {
        $pdo = (new Database())->getConnection();
        $result = ['encrypted' => 0, 'legacy' => 0, 'key_ready' => false];
        try { nodeCredentialKey(); $result['key_ready'] = true; } catch (RuntimeException $keyError) {}
        foreach ($pdo->query("SELECT password FROM proxmox_nodes WHERE password IS NOT NULL AND password <> ''") as $node) {
            if (str_starts_with($node['password'], NODE_PASSWORD_PREFIX)) { decryptNodePassword($node['password']); $result['encrypted']++; }
            else $result['legacy']++;
        }
        echo json_encode($result, JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit;
    }
    if (isset($argv[1])) throw new InvalidArgumentException('Неизвестный параметр');
    nodeCredentialKey();
    $operationLock = backupOperationLock(dirname(__DIR__));
    $pdo = (new Database())->getConnection();
    $pdo->beginTransaction();
    $nodes = $pdo->query("SELECT id, password FROM proxmox_nodes WHERE password IS NOT NULL AND password <> '' FOR UPDATE")->fetchAll();
    $update = $pdo->prepare('UPDATE proxmox_nodes SET password = ? WHERE id = ?');
    $count = 0;
    foreach ($nodes as $node) {
        if (str_starts_with($node['password'], NODE_PASSWORD_PREFIX)) {
            decryptNodePassword($node['password']);
            continue;
        }
        $update->execute([encryptNodePassword($node['password']), $node['id']]);
        ++$count;
    }
    $pdo->commit();
    echo "Зашифровано паролей узлов: $count\n";
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Миграция паролей узлов прервана. Проверьте доступ к БД, ключ и формат записей.\n");
    exit(1);
} finally {
    if ($operationLock) { flock($operationLock, LOCK_UN); fclose($operationLock); }
}
