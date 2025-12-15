<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

session_start();
checkAuth();

header('Content-Type: application/json');

$days = $_POST['days'] ?? 30;
$keep = $_POST['keep'] ?? 10;
$backup_dir = __DIR__ . '/../../../backups/';

$files = glob($backup_dir . 'backup_*.zip');
$deleted = 0;

if (count($files) > $keep) {
    usort($files, function($a, $b) {
        return filemtime($a) < filemtime($b);
    });
    
    $cutoff_time = time() - ($days * 24 * 60 * 60);
    
    for ($i = $keep; $i < count($files); $i++) {
        if (filemtime($files[$i]) < $cutoff_time) {
            if (unlink($files[$i])) {
                $deleted++;
            }
        }
    }
}

echo json_encode(['success' => true, 'deleted' => $deleted]);
?>