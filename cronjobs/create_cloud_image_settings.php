<?php
require_once __DIR__ . '/../includes/cli_only.php';
require_once __DIR__ . '/../includes/db.php';

(new Database())->getConnection()->exec("CREATE TABLE IF NOT EXISTS cloud_image_settings (
    node_id INT NOT NULL,
    image_key VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    display_name VARCHAR(100) NOT NULL DEFAULT '',
    storage VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    pending_storage VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    pending_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    import_error VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (node_id, image_key),
    FOREIGN KEY (node_id) REFERENCES proxmox_nodes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
echo "Cloud image settings schema ready\n";
