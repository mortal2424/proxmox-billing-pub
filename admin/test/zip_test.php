<?php
// Простейший тест ZIP
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Testing ZipArchive...<br>";

if (class_exists('ZipArchive')) {
    echo "ZipArchive class exists<br>";
    
    $zip = new ZipArchive();
    $filename = tempnam(sys_get_temp_dir(), 'test') . '.zip';
    
    if ($zip->open($filename, ZipArchive::CREATE) === TRUE) {
        $zip->addFromString('test.txt', 'Hello World');
        $zip->close();
        echo "ZIP created successfully: " . filesize($filename) . " bytes<br>";
        unlink($filename);
    } else {
        echo "Failed to create ZIP<br>";
    }
} else {
    echo "ZipArchive class NOT found<br>";
}
?>