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

$external = 'storage:import/selectos-release.raw';
$api = static fn(): array => [['volid'=>$volume], ['volid'=>$external], ['volid'=>'storage:import/../private.raw']];
$db->settings['selectos'] += ['source_volume'=>$external,'source_minimum_disk'=>24];
$inventory = cloudImportInventory($api,'node3',$store);
if (count($inventory)!==2 || !isset($inventory[$external])) exit(1);
$registered=publishedCloudImages($db,11,cloudImportVolumes($api,'node3',$store,$db,11,$inventory));
if (($registered['selectos'][0]['volid']??null)!==$external ||
    ($registered['selectos'][0]['minimum_disk']??null)!==24 ||
    empty($registered['selectos'][0]['existing_source'])) exit(1);
unset($inventory[$external]);
if (cloudImportVolumes($api,'node3',$store,$db,11,$inventory)!==[]) exit(1);
echo "PASS exact registered import volume and missing-source suppression\n";
