<?php
require_once __DIR__ . '/../includes/cli_only.php';
require_once __DIR__ . '/../includes/db.php';

(new Database())->getConnection()->exec("CREATE TABLE IF NOT EXISTS node_placement_pools (
    node_id INT NOT NULL,
    kind ENUM('network','storage') NOT NULL,
    pool_name VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    resource VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY(node_id,kind,pool_name,resource),
    FOREIGN KEY (node_id) REFERENCES proxmox_nodes(id) ON DELETE CASCADE
) ENGINE=InnoDB");
echo "Placement pool schema ready\n";
