<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

session_start();
header('Content-Type: application/json');

// Проверяем авторизацию (если нужно)
if (!isset($_SESSION['user'])) {
    echo json_encode(['error' => 'Не авторизован']);
    exit;
}

$db = new Database();
$pdo = $db->getConnection();
$user_id = $_SESSION['user']['id'];

// Получаем данные из запроса
$cpu = (int)($_POST['cpu'] ?? 0);
$ram = (int)($_POST['ram'] ?? 0);
$disk = (int)($_POST['disk'] ?? 0);

// Получаем квоты пользователя
$quota = $pdo->query("SELECT * FROM user_quotas WHERE user_id = $user_id")->fetch();
if (!$quota) {
    // Создаем квоты по умолчанию, если их нет
    $pdo->exec("INSERT INTO user_quotas (user_id) VALUES ($user_id)");
    $quota = $pdo->query("SELECT * FROM user_quotas WHERE user_id = $user_id")->fetch();
}

// Получаем текущее использование ресурсов пользователем
$usage = $pdo->query("
    SELECT 
        COUNT(*) as vm_count,
        SUM(cpu) as total_cpu,
        SUM(ram) as total_ram,
        SUM(disk) as total_disk
    FROM vms 
    WHERE user_id = $user_id AND status != 'deleted'
")->fetch();

// Проверяем квоты
$quota_exceeded = false;
$errors = [];

if ($usage['vm_count'] >= $quota['max_vms']) {
    $quota_exceeded = true;
    $errors[] = "Превышено максимальное количество виртуальных машин ({$quota['max_vms']})";
}

if (($usage['total_cpu'] + $cpu) > $quota['max_cpu']) {
    $quota_exceeded = true;
    $errors[] = "Превышена квота CPU (используется {$usage['total_cpu']} из {$quota['max_cpu']})";
}

if (($usage['total_ram'] + $ram) > $quota['max_ram']) {
    $quota_exceeded = true;
    $errors[] = "Превышена квота RAM (используется {$usage['total_ram']}MB из {$quota['max_ram']}MB)";
}

if (($usage['total_disk'] + $disk) > $quota['max_disk']) {
    $quota_exceeded = true;
    $errors[] = "Превышена квота дискового пространства (используется {$usage['total_disk']}GB из {$quota['max_disk']}GB)";
}

echo json_encode([
    'quota_exceeded' => $quota_exceeded,
    'errors' => $errors
]);