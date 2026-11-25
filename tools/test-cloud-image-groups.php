<?php
require __DIR__ . '/../includes/cloud_images.php';

final class CloudGroupFixtureStatement extends PDOStatement
{
    public function __construct(private array $rows) {}
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
    public function execute(?array $params = null): bool { return true; }
}
final class CloudGroupFixtureDb extends PDO
{
    public array $settings = [];
    public function __construct() {}
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        return new CloudGroupFixtureStatement([['image_key'=>'selectos','label'=>'SelectOS','guest_user'=>'cloud']]);
    }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new CloudGroupFixtureStatement($this->settings);
    }
}
$db = new CloudGroupFixtureDb();
$volume = 'storage:import/homevlad-selectos-20g-a1b2c3d4e5f6.qcow2';
$api = static fn(): array => [['volid'=>$volume]];
$store = [['storage'=>'storage','active'=>1,'enabled'=>1,'content'=>'import']];
$images = cloudImportVolumes($api, 'node3', $store, $db);
if (($images['selectos'][0]['user'] ?? null) !== 'cloud' || publishedCloudImages($db, 11, $images) !== []) exit(1);
$db->settings = ['selectos'=>['display_name'=>'','storage'=>'storage','enabled'=>1]];
if ((publishedCloudImages($db, 11, $images)['selectos'][0]['volid'] ?? null) !== $volume) exit(1);
try {
    createCloudImageGroup($db, 'ubuntu-24.04', 'Duplicate', 'cloud');
    exit(1);
} catch (InvalidArgumentException) {
    echo "PASS custom image inventory and explicit node publication\n";
}
