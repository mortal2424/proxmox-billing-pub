<?php
if (!function_exists('ssh_connect_and_auth')) {
    function ssh_connect_and_auth($hostname, $username, $password) {
        $connection = @ssh2_connect($hostname, 22);
        if (!$connection) {
            throw new Exception("Не удалось подключиться к {$hostname}");
        }
        
        if (!@ssh2_auth_password($connection, $username, $password)) {
            throw new Exception("Ошибка аутентификации для {$username}");
        }
        
        return $connection;
    }
}

if (!function_exists('exec_ssh_command')) {
    function exec_ssh_command($connection, $command) {
        $stream = @ssh2_exec($connection, $command);
        if (!$stream) {
            throw new Exception("Не удалось выполнить команду: {$command}");
        }
        
        stream_set_blocking($stream, true);
        $output = stream_get_contents($stream);
        fclose($stream);
        
        return trim($output);
    }
}

if (!function_exists('getNodeStats')) {
    function getNodeStats($hostname, $username, $password) {
        try {
            $connection = ssh_connect_and_auth($hostname, $username, $password);
            
            $result = [
                'cpu_usage' => (float)exec_ssh_command($connection, "awk '{print \$1}' /proc/loadavg"),
                'ram_usage' => 0,
                'ram_total' => 0,
                'network_rx_bytes' => 0,
                'network_tx_bytes' => 0
            ];
            
            // Получаем данные о памяти
            $ram = exec_ssh_command($connection, "free -m | awk '/Mem:/ {print \$2,\$3}'");
            list($result['ram_total'], $ramUsed) = explode(' ', $ram);
            $result['ram_usage'] = round(($ramUsed / $result['ram_total']) * 100, 2);
            
            // Получаем сетевую статистику
            $network = exec_ssh_command($connection, "cat /sys/class/net/vmbr0/statistics/rx_bytes /sys/class/net/vmbr0/statistics/tx_bytes");
            list($result['network_rx_bytes'], $result['network_tx_bytes']) = array_pad(explode("\n", $network), 2, 0);
            
            return $result;
            
        } finally {
            if (isset($connection) && is_resource($connection)) {
                @ssh2_disconnect($connection);
            }
        }
    }
}