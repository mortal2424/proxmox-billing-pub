<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once 'admin_functions.php';

if (!isAdmin()) {
    header('Location: /login/login.php');
    exit;
}

$db = new Database();
$pdo = $db->getConnection();

// Получаем все данные для мониторинга
$system_info = getSystemInfo();
$db_stats = getDatabaseStats($pdo);
$user_stats = getUserStats($pdo);
$vm_stats = getVMStats($pdo);
$payment_stats = getPaymentStats($pdo);
$ticket_stats = getTicketStats($pdo);
$backup_stats = getBackupStats($pdo);
$cluster_stats = getClusterStats($pdo);
$server_stats = getServerStats();
$node_stats = getNodeStats($pdo);
$metrics_stats = getMetricsStats($pdo);
$proxmox_stats = getProxmoxStats($pdo);
$tariff_stats = getTariffStats($pdo);
$other_stats = getOtherStats($pdo);

// Функции для получения данных
function getSystemInfo() {
    $info = [];

    // PHP информация
    $info['php_version'] = phpversion();
    $info['php_memory_limit'] = ini_get('memory_limit');
    $info['php_max_execution_time'] = ini_get('max_execution_time');
    $info['php_extensions'] = get_loaded_extensions();

    // Серверная информация
    $info['server_software'] = $_SERVER['SERVER_SOFTWARE'] ?? 'N/A';
    $info['server_os'] = php_uname();
    $info['server_time'] = date('Y-m-d H:i:s');
    $info['server_ip'] = $_SERVER['SERVER_ADDR'] ?? $_SERVER['SERVER_NAME'] ?? 'N/A';

    return $info;
}

function getDatabaseStats($pdo) {
    $stats = [];

    try {
        // Получаем все таблицы из базы данных
        $stmt = $pdo->query("SHOW TABLES");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $total_size = 0;
        foreach ($tables as $table) {
            try {
                // Получаем количество записей
                $count_stmt = $pdo->query("SELECT COUNT(*) FROM `$table`");
                $count = $count_stmt->fetchColumn();

                // Получаем размер таблицы
                $size = getTableSize($pdo, $table);
                $stats[$table] = [
                    'count' => (int)$count,
                    'size' => formatBytess($size),
                    'size_bytes' => $size
                ];
                $total_size += $size;
            } catch (Exception $e) {
                // Таблица не существует или ошибка, пропускаем
                continue;
            }
        }

        // Сортируем таблицы по размеру
        uasort($stats, function($a, $b) {
            return $b['size_bytes'] - $a['size_bytes'];
        });

        // Общая статистика
        $stats['total'] = [
            'tables' => count($tables),
            'total_size' => formatBytess($total_size),
            'total_size_bytes' => $total_size,
            'avg_rows_per_table' => count($tables) > 0 ? round(array_sum(array_column($stats, 'count')) / count($tables), 2) : 0
        ];

    } catch (Exception $e) {
        error_log("Ошибка в getDatabaseStats: " . $e->getMessage());
        $stats['error'] = 'Ошибка при получении статистики БД';
    }

    return $stats;
}

function getTableSize($pdo, $table) {
    try {
        $stmt = $pdo->prepare(
            "SELECT COALESCE(data_length + index_length, 0) as size
             FROM information_schema.TABLES
             WHERE table_schema = DATABASE() AND table_name = :table"
        );
        $stmt->execute([':table' => $table]);
        $result = $stmt->fetchColumn();
        return $result ?: 0;
    } catch (Exception $e) {
        return 0;
    }
}

function getUserStats($pdo) {
    $stats = [];

    try {
        // Основные статистики
        $stmt = $pdo->query("SELECT COUNT(*) FROM users");
        $stats['total_users'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE is_active = 1");
        $stats['active_users'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE is_admin = 1");
        $stats['admin_users'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE email_verified = 1");
        $stats['verified_users'] = (int)$stmt->fetchColumn();

        // Распределение по типам пользователей
        $stmt = $pdo->query("SELECT user_type, COUNT(*) as count FROM users GROUP BY user_type");
        $stats['by_type'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Новые пользователи
        $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE DATE(created_at) = CURDATE()");
        $stats['new_today'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
        $stats['new_week'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $stats['new_month'] = (int)$stmt->fetchColumn();

        // Балансы
        $stmt = $pdo->query("SELECT COALESCE(SUM(balance), 0) FROM users");
        $stats['total_balance'] = (float)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COALESCE(SUM(bonus_balance), 0) FROM users");
        $stats['total_bonus_balance'] = (float)$stmt->fetchColumn();

        // Пользователи с Telegram
        $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE telegram_id IS NOT NULL");
        $stats['telegram_users'] = (int)$stmt->fetchColumn();

        // Статистика по квотам
        $stmt = $pdo->query("
            SELECT
                COUNT(*) as total_quotas,
                SUM(max_vms) as total_max_vms,
                SUM(max_cpu) as total_max_cpu,
                SUM(max_ram) as total_max_ram,
                SUM(max_disk) as total_max_disk
            FROM user_quotas
        ");
        $stats['quotas'] = $stmt->fetch(PDO::FETCH_ASSOC);

    } catch (Exception $e) {
        error_log("Ошибка в getUserStats: " . $e->getMessage());
        $stats['error'] = 'Ошибка при получении статистики пользователей';
    }

    return $stats;
}

function getVMStats($pdo) {
    $stats = [];

    try {
        // Общая статистика
        $stmt = $pdo->query("SELECT COUNT(*) FROM vms");
        $stats['total_vms'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COUNT(*) FROM vms_admin");
        $stats['admin_vms'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COUNT(DISTINCT user_id) FROM vms");
        $stats['users_with_vms'] = (int)$stmt->fetchColumn();

        // По статусам
        $stmt = $pdo->query("SELECT status, COUNT(*) as count FROM vms GROUP BY status ORDER BY count DESC");
        $stats['by_status'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // По типам
        $stmt = $pdo->query("SELECT vm_type, COUNT(*) as count FROM vms GROUP BY vm_type");
        $stats['by_vm_type'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->query("SELECT os_type, COUNT(*) as count FROM vms GROUP BY os_type");
        $stats['by_os_type'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Ресурсы
        $stmt = $pdo->query("
            SELECT
                SUM(cpu) as total_cpu,
                SUM(ram) as total_ram,
                SUM(disk) as total_disk,
                AVG(cpu) as avg_cpu,
                AVG(ram) as avg_ram,
                AVG(disk) as avg_disk
            FROM vms
        ");
        $stats['resources'] = $stmt->fetch(PDO::FETCH_ASSOC);

        // Последние ВМ
        $stmt = $pdo->query("
            SELECT v.*, u.email
            FROM vms v
            LEFT JOIN users u ON v.user_id = u.id
            ORDER BY v.created_at DESC
            LIMIT 5
        ");
        $stats['recent_vms'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (Exception $e) {
        error_log("Ошибка в getVMStats: " . $e->getMessage());
        $stats['error'] = 'Ошибка при получении статистики ВМ';
    }

    return $stats;
}

function getPaymentStats($pdo) {
    $stats = [];

    try {
        // Общая статистика
        $stmt = $pdo->query("SELECT COUNT(*) FROM payments");
        $stats['total_payments'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COUNT(*) FROM payments WHERE status = 'completed'");
        $stats['completed_payments'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COUNT(*) FROM payments WHERE status = 'pending'");
        $stats['pending_payments'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COUNT(*) FROM payments WHERE status = 'failed'");
        $stats['failed_payments'] = (int)$stmt->fetchColumn();

        // Суммы
        $stmt = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'completed'");
        $stats['total_amount'] = (float)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'completed' AND DATE(created_at) = CURDATE()");
        $stats['today_amount'] = (float)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'completed' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
        $stats['week_amount'] = (float)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'completed' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $stats['month_amount'] = (float)$stmt->fetchColumn();

        // По методам оплаты
        $stmt = $pdo->query("SELECT payment_method, COUNT(*) as count, SUM(amount) as total FROM payments WHERE status = 'completed' GROUP BY payment_method");
        $stats['by_method'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Последние платежи
        $stmt = $pdo->query("
            SELECT p.*, u.email, u.full_name
            FROM payments p
            LEFT JOIN users u ON p.user_id = u.id
            ORDER BY p.created_at DESC
            LIMIT 5
        ");
        $stats['recent_payments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (Exception $e) {
        error_log("Ошибка в getPaymentStats: " . $e->getMessage());
        $stats['error'] = 'Ошибка при получении статистики платежей';
    }

    return $stats;
}

function getTicketStats($pdo) {
    $stats = [];

    try {
        // Общая статистика
        $stmt = $pdo->query("SELECT COUNT(*) FROM tickets");
        $stats['total_tickets'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COUNT(*) FROM tickets WHERE status = 'open'");
        $stats['open_tickets'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COUNT(*) FROM tickets WHERE status = 'answered'");
        $stats['answered_tickets'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COUNT(*) FROM tickets WHERE status = 'closed'");
        $stats['closed_tickets'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COUNT(*) FROM tickets WHERE status = 'pending'");
        $stats['pending_tickets'] = (int)$stmt->fetchColumn();

        // По приоритетам
        $stmt = $pdo->query("SELECT priority, COUNT(*) as count FROM tickets GROUP BY priority");
        $stats['by_priority'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // По отделам
        $stmt = $pdo->query("SELECT department, COUNT(*) as count FROM tickets GROUP BY department");
        $stats['by_department'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Ответы на тикеты
        $stmt = $pdo->query("SELECT COUNT(*) FROM ticket_replies");
        $stats['total_replies'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COUNT(*) FROM ticket_replies WHERE is_admin = 1");
        $stats['admin_replies'] = (int)$stmt->fetchColumn();

        // Вложения
        $stmt = $pdo->query("SELECT COUNT(*) FROM ticket_attachments");
        $stats['total_attachments'] = (int)$stmt->fetchColumn();

        // Последние тикеты
        $stmt = $pdo->query("
            SELECT t.*, u.email, u.full_name
            FROM tickets t
            LEFT JOIN users u ON t.user_id = u.id
            ORDER BY t.created_at DESC
            LIMIT 5
        ");
        $stats['recent_tickets'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (Exception $e) {
        error_log("Ошибка в getTicketStats: " . $e->getMessage());
        $stats['error'] = 'Ошибка при получении статистики тикетов';
    }

    return $stats;
}

function getBackupStats($pdo) {
    $stats = [];

    try {
        // Логи бэкапов
        $stmt = $pdo->query("SELECT COUNT(*) FROM backup_logs");
        $stats['total_backups'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COUNT(*) FROM backup_logs WHERE action = 'create'");
        $stats['created_backups'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COUNT(*) FROM backup_logs WHERE action = 'restore'");
        $stats['restored_backups'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COUNT(*) FROM backup_logs WHERE action = 'delete'");
        $stats['deleted_backups'] = (int)$stmt->fetchColumn();

        // Расписания
        $stmt = $pdo->query("SELECT COUNT(*) FROM backup_schedules");
        $stats['total_schedules'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COUNT(*) FROM backup_schedules WHERE is_active = 1");
        $stats['active_schedules'] = (int)$stmt->fetchColumn();

        // По типам расписаний
        $stmt = $pdo->query("SELECT schedule_type, COUNT(*) as count FROM backup_schedules GROUP BY schedule_type");
        $stats['by_schedule_type'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // По типам бэкапов
        $stmt = $pdo->query("SELECT backup_type, COUNT(*) as count FROM backup_schedules GROUP BY backup_type");
        $stats['by_backup_type'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Последние бэкапы
        $stmt = $pdo->query("
            SELECT * FROM backup_logs
            ORDER BY created_at DESC
            LIMIT 5
        ");
        $stats['recent_backups'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (Exception $e) {
        error_log("Ошибка в getBackupStats: " . $e->getMessage());
        $stats['error'] = 'Ошибка при получении статистики бэкапов';
    }

    return $stats;
}

function getClusterStats($pdo) {
    $stats = [];

    try {
        // Кластеры
        $stmt = $pdo->query("SELECT COUNT(*) FROM proxmox_clusters");
        $stats['total_clusters'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COUNT(*) FROM proxmox_clusters WHERE is_active = 1");
        $stats['active_clusters'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COUNT(*) FROM proxmox_clusters WHERE maintenance_mode = 1");
        $stats['maintenance_clusters'] = (int)$stmt->fetchColumn();

        // Подробная информация о кластерах
        $stmt = $pdo->query("
            SELECT
                id, name, description, is_active,
                enable_auto_healing, enable_vm_migration,
                max_vms_per_node, load_balancing_threshold,
                maintenance_mode, created_at, updated_at
            FROM proxmox_clusters
        ");
        $stats['clusters'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Логи кластера
        $stmt = $pdo->query("SELECT COUNT(*) FROM cluster_logs");
        $stats['total_cluster_logs'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->query("
            SELECT * FROM cluster_logs
            ORDER BY created_at DESC
            LIMIT 5
        ");
        $stats['recent_cluster_logs'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (Exception $e) {
        error_log("Ошибка в getClusterStats: " . $e->getMessage());
        $stats['error'] = 'Ошибка при получении статистики кластеров';
    }

    return $stats;
}

function getNodeStats($pdo) {
    $stats = [];

    try {
        // Ноды
        $stmt = $pdo->query("SELECT COUNT(*) FROM proxmox_nodes");
        $stats['total_nodes'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COUNT(*) FROM proxmox_nodes WHERE is_active = 1");
        $stats['active_nodes'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COUNT(*) FROM proxmox_nodes WHERE available_for_users = 1");
        $stats['available_nodes'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COUNT(*) FROM proxmox_nodes WHERE is_cluster_master = 1");
        $stats['master_nodes'] = (int)$stmt->fetchColumn();

        // По статусам
        $stmt = $pdo->query("SELECT status, COUNT(*) as count FROM proxmox_nodes GROUP BY status");
        $stats['by_status'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Подробная информация о нодах
        $stmt = $pdo->query("
            SELECT
                id, cluster_id, node_name, hostname,
                status, is_active, available_for_users,
                is_cluster_master, ip, created_at, last_check
            FROM proxmox_nodes
            ORDER BY is_cluster_master DESC, node_name ASC
        ");
        $stats['nodes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Статистика нод
        $stmt = $pdo->query("SELECT COUNT(*) FROM node_stats");
        $stats['total_node_stats'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->query("
            SELECT * FROM node_stats
            ORDER BY created_at DESC
            LIMIT 5
        ");
        $stats['recent_node_stats'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Проверки нод
        $stmt = $pdo->query("SELECT COUNT(*) FROM node_checks");
        $stats['total_node_checks'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT status, COUNT(*) as count FROM node_checks GROUP BY status");
        $stats['node_checks_by_status'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (Exception $e) {
        error_log("Ошибка в getNodeStats: " . $e->getMessage());
        $stats['error'] = 'Ошибка при получении статистики нод';
    }

    return $stats;
}

function getMetricsStats($pdo) {
    $stats = [];

    try {
        // Метрики ВМ
        $stmt = $pdo->query("SELECT COUNT(*) FROM vm_metrics");
        $stats['total_vm_metrics'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COUNT(*) FROM lxc_metrics");
        $stats['total_lxc_metrics'] = (int)$stmt->fetchColumn();

        // Логи метрик
        $stmt = $pdo->query("SELECT COUNT(*) FROM metrics_logs");
        $stats['total_metrics_logs'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT type, COUNT(*) as count FROM metrics_logs GROUP BY type");
        $stats['metrics_logs_by_type'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Последние метрики
        $stmt = $pdo->query("
            SELECT * FROM vm_metrics
            ORDER BY timestamp DESC
            LIMIT 5
        ");
        $stats['recent_vm_metrics'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (Exception $e) {
        error_log("Ошибка в getMetricsStats: " . $e->getMessage());
        $stats['error'] = 'Ошибка при получении статистики метрик';
    }

    return $stats;
}

function getProxmoxStats($pdo) {
    $stats = [];

    try {
        // Билеты Proxmox
        $stmt = $pdo->query("SELECT COUNT(*) FROM proxmox_tickets");
        $stats['total_tickets'] = (int)$stmt->fetchColumn();

        // Цены на ресурсы
        $stmt = $pdo->query("SELECT COUNT(*) FROM resource_prices");
        $stats['total_price_sets'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT * FROM resource_prices ORDER BY updated_at DESC LIMIT 1");
        $stats['current_prices'] = $stmt->fetch(PDO::FETCH_ASSOC);

        // Биллинг ВМ
        $stmt = $pdo->query("SELECT COUNT(*) FROM vm_billing");
        $stats['total_billing_records'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COALESCE(SUM(total_per_hour), 0) FROM vm_billing");
        $stats['total_billing_per_hour'] = (float)$stmt->fetchColumn();

    } catch (Exception $e) {
        error_log("Ошибка в getProxmoxStats: " . $e->getMessage());
        $stats['error'] = 'Ошибка при получении статистики Proxmox';
    }

    return $stats;
}

function getTariffStats($pdo) {
    $stats = [];

    try {
        // Тарифы
        $stmt = $pdo->query("SELECT COUNT(*) FROM tariffs");
        $stats['total_tariffs'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COUNT(*) FROM tariffs WHERE is_active = 1");
        $stats['active_tariffs'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COUNT(*) FROM tariffs WHERE is_popular = 1");
        $stats['popular_tariffs'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COUNT(*) FROM tariffs WHERE is_custom = 1");
        $stats['custom_tariffs'] = (int)$stmt->fetchColumn();

        // По типам
        $stmt = $pdo->query("SELECT os_type, COUNT(*) as count FROM tariffs GROUP BY os_type");
        $stats['by_os_type'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->query("SELECT vm_type, COUNT(*) as count FROM tariffs GROUP BY vm_type");
        $stats['by_vm_type'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Услуги пользователей
        $stmt = $pdo->query("SELECT COUNT(*) FROM user_services");
        $stats['total_user_services'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT status, COUNT(*) as count FROM user_services GROUP BY status");
        $stats['services_by_status'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (Exception $e) {
        error_log("Ошибка в getTariffStats: " . $e->getMessage());
        $stats['error'] = 'Ошибка при получении статистики тарифов';
    }

    return $stats;
}

function getOtherStats($pdo) {
    $stats = [];

    try {
        // Транзакции
        $stmt = $pdo->query("SELECT COUNT(*) FROM transactions");
        $stats['total_transactions'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT type, COUNT(*) as count FROM transactions GROUP BY type");
        $stats['transactions_by_type'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->query("SELECT balance_type, COUNT(*) as count FROM transactions GROUP BY balance_type");
        $stats['transactions_by_balance'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE type = 'credit'");
        $stats['total_credited'] = (float)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE type = 'debit'");
        $stats['total_debited'] = (float)$stmt->fetchColumn();

        // История баланса
        $stmt = $pdo->query("SELECT COUNT(*) FROM balance_history");
        $stats['total_balance_history'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT operation_type, COUNT(*) as count FROM balance_history GROUP BY operation_type");
        $stats['balance_history_by_type'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Уведомления
        $stmt = $pdo->query("SELECT COUNT(*) FROM notifications");
        $stats['total_notifications'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0");
        $stats['unread_notifications'] = (int)$stmt->fetchColumn();

        // Промоакции
        $stmt = $pdo->query("SELECT COUNT(*) FROM promotions");
        $stats['total_promotions'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COUNT(*) FROM promotions WHERE is_active = 1");
        $stats['active_promotions'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COUNT(*) FROM promotions WHERE start_date <= CURDATE() AND end_date >= CURDATE()");
        $stats['current_promotions'] = (int)$stmt->fetchColumn();

        // Функции
        $stmt = $pdo->query("SELECT COUNT(*) FROM features");
        $stats['total_features'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COUNT(*) FROM features WHERE is_active = 1");
        $stats['active_features'] = (int)$stmt->fetchColumn();

        // Telegram
        $stmt = $pdo->query("SELECT COUNT(*) FROM telegram_conversations");
        $stats['total_telegram_conversations'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COUNT(*) FROM telegram_queue");
        $stats['total_telegram_queue'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT status, COUNT(*) as count FROM telegram_queue GROUP BY status");
        $stats['telegram_queue_by_status'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Системные обновления
        $stmt = $pdo->query("SELECT COUNT(*) FROM system_updates");
        $stats['total_system_updates'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COUNT(*) FROM system_updates WHERE success = 1");
        $stats['successful_updates'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT update_type, COUNT(*) as count FROM system_updates GROUP BY update_type");
        $stats['updates_by_type'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Версии системы
        $stmt = $pdo->query("SELECT COUNT(*) FROM system_versions");
        $stats['total_system_versions'] = (int)$stmt->fetchColumn();

        // Сбросы паролей
        $stmt = $pdo->query("SELECT COUNT(*) FROM password_resets");
        $stats['total_password_resets'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COUNT(*) FROM password_resets WHERE expires_at > NOW()");
        $stats['active_password_resets'] = (int)$stmt->fetchColumn();

    } catch (Exception $e) {
        error_log("Ошибка в getOtherStats: " . $e->getMessage());
        $stats['error'] = 'Ошибка при получении дополнительной статистики';
    }

    return $stats;
}

function getServerStats() {
    $stats = [];

    // Использование диска
    try {
        $disk_total = disk_total_space('/');
        $disk_free = disk_free_space('/');
        $disk_used = $disk_total - $disk_free;

        $stats['disk'] = [
            'total' => formatBytess($disk_total),
            'free' => formatBytess($disk_free),
            'used' => formatBytess($disk_used),
            'percent' => $disk_total > 0 ? round(($disk_used / $disk_total) * 100, 2) : 0,
            'total_bytes' => $disk_total,
            'free_bytes' => $disk_free,
            'used_bytes' => $disk_used
        ];
    } catch (Exception $e) {
        $stats['disk'] = ['error' => 'Не доступно при работе на хостинге'];
    }

    // Использование памяти
    try {
        if (function_exists('shell_exec') && is_callable('shell_exec')) {
            if (file_exists('/proc/meminfo')) {
                $meminfo = file('/proc/meminfo');
                $mem_total = 0;
                $mem_free = 0;
                $mem_available = 0;

                foreach ($meminfo as $line) {
                    if (preg_match('/^MemTotal:\s+(\d+)\s*kB/i', $line, $matches)) {
                        $mem_total = $matches[1] * 1024;
                    } elseif (preg_match('/^MemFree:\s+(\d+)\s*kB/i', $line, $matches)) {
                        $mem_free = $matches[1] * 1024;
                    } elseif (preg_match('/^MemAvailable:\s+(\d+)\s*kB/i', $line, $matches)) {
                        $mem_available = $matches[1] * 1024;
                    }
                }

                $mem_used = $mem_total - $mem_available;

                $stats['memory'] = [
                    'total' => formatBytess($mem_total),
                    'free' => formatBytess($mem_free),
                    'available' => formatBytess($mem_available),
                    'used' => formatBytess($mem_used),
                    'percent' => $mem_total > 0 ? round(($mem_used / $mem_total) * 100, 2) : 0,
                    'total_bytes' => $mem_total,
                    'used_bytes' => $mem_used
                ];
            } else {
                $stats['memory'] = [
                    'total' => formatBytess(memory_get_peak_usage(true)),
                    'used' => formatBytess(memory_get_usage(true)),
                    'percent' => 0,
                    'info' => 'Ограниченная информация (работает на хостинге)'
                ];
            }
        } else {
            $stats['memory'] = ['error' => 'Не доступно при работе на хостинге (shell_exec отключен)'];
        }
    } catch (Exception $e) {
        $stats['memory'] = ['error' => 'Не доступно при работе на хостинге'];
    }

    // Загрузка CPU
    try {
        if (function_exists('sys_getloadavg') && function_exists('shell_exec')) {
            $load = sys_getloadavg();
            $cores = @shell_exec('nproc') ? (int)@shell_exec('nproc') : 1;

            $stats['cpu'] = [
                'load_1min' => round($load[0], 2),
                'load_5min' => round($load[1], 2),
                'load_15min' => round($load[2], 2),
                'cores' => $cores,
                'usage_percent' => getCpuUsage(),
                'load_per_core' => $cores > 0 ? round($load[0] / $cores, 2) : $load[0]
            ];
        } else {
            $stats['cpu'] = ['error' => 'Не доступно при работе на хостинге'];
        }
    } catch (Exception $e) {
        $stats['cpu'] = ['error' => 'Не доступно при работе на хостинге'];
    }

    // Сетевая статистика
    try {
        if (function_exists('shell_exec')) {
            $stats['network'] = getNetworkStats();
            if (empty($stats['network'])) {
                $stats['network'] = ['info' => 'Статистика сети недоступна на хостинге'];
            }
        } else {
            $stats['network'] = ['error' => 'Не доступно при работе на хостинге (shell_exec отключен)'];
        }
    } catch (Exception $e) {
        $stats['network'] = ['error' => 'Не доступно при работе на хостинге'];
    }

    // Время работы системы
    try {
        if (file_exists('/proc/uptime') && function_exists('shell_exec')) {
            $uptime = file_get_contents('/proc/uptime');
            if ($uptime !== false) {
                $uptime = explode(' ', $uptime);
                $stats['uptime'] = formatUptimee($uptime[0]);
            } else {
                $stats['uptime'] = 'N/A';
            }
        } else {
            $stats['uptime'] = 'Информация недоступна на хостинге';
        }
    } catch (Exception $e) {
        $stats['uptime'] = 'Не доступно при работе на хостинге';
    }

    // Веб-сервер
    $stats['webserver'] = detectWebServer();

    return $stats;
}

function getCpuUsage() {
    static $previousStats = null;

    try {
        if (!file_exists('/proc/stat') || !is_readable('/proc/stat')) {
            return 0;
        }

        $stats = file('/proc/stat');
        if (!$stats) return 0;

        $cpuStats = explode(' ', preg_replace('/\s+/', ' ', trim($stats[0])));

        if ($previousStats === null) {
            $previousStats = $cpuStats;
            sleep(1);
            return getCpuUsage();
        }

        $prevIdle = $previousStats[4] + $previousStats[5];
        $idle = $cpuStats[4] + $cpuStats[5];

        $prevTotal = array_sum(array_slice($previousStats, 1, 7));
        $total = array_sum(array_slice($cpuStats, 1, 7));

        $totalDiff = $total - $prevTotal;
        $idleDiff = $idle - $prevIdle;

        $previousStats = $cpuStats;

        return $totalDiff > 0 ? round((($totalDiff - $idleDiff) / $totalDiff) * 100, 2) : 0;
    } catch (Exception $e) {
        return 0;
    }
}

function getNetworkStats() {
    $stats = [];

    try {
        if (!file_exists('/sys/class/net/') || !is_readable('/sys/class/net/')) {
            return $stats;
        }

        $interfaces = @scandir('/sys/class/net/');
        if (!$interfaces) {
            return $stats;
        }

        foreach ($interfaces as $iface) {
            if ($iface === '.' || $iface === '..' || $iface === 'lo') continue;

            $rx_file = "/sys/class/net/$iface/statistics/rx_bytes";
            $tx_file = "/sys/class/net/$iface/statistics/tx_bytes";

            if (file_exists($rx_file) && file_exists($tx_file)) {
                $rx_bytes = @file_get_contents($rx_file);
                $tx_bytes = @file_get_contents($tx_file);

                if ($rx_bytes !== false && $tx_bytes !== false) {
                    $stats[$iface] = [
                        'rx' => formatBytess($rx_bytes),
                        'tx' => formatBytess($tx_bytes),
                        'rx_bytes' => (int)$rx_bytes,
                        'tx_bytes' => (int)$tx_bytes
                    ];
                }
            }
        }
    } catch (Exception $e) {
        // Игнорируем ошибки для работы на хостинге
    }

    return $stats;
}

function detectWebServer() {
    $server = $_SERVER['SERVER_SOFTWARE'] ?? 'Неизвестно';

    if (strpos($server, 'Apache') !== false) {
        return 'Apache';
    } elseif (strpos($server, 'nginx') !== false) {
        return 'Nginx';
    } elseif (strpos($server, 'LiteSpeed') !== false) {
        return 'LiteSpeed';
    } else {
        return $server;
    }
}

function formatBytess($bytes, $precision = 2) {
    if ($bytes <= 0) return '0 B';

    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);

    return round($bytes, $precision) . ' ' . $units[$pow];
}

function formatUptimee($seconds) {
    $days = floor($seconds / 86400);
    $hours = floor(($seconds % 86400) / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $seconds = floor($seconds % 60);

    $result = '';
    if ($days > 0) $result .= $days . 'д ';
    if ($hours > 0) $result .= $hours . 'ч ';
    if ($minutes > 0) $result .= $minutes . 'м ';
    if ($seconds > 0) $result .= $seconds . 'с';

    return trim($result) ?: '0с';
}

$title = "Мониторинг систем | Админ панель | HomeVlad Cloud";
require 'admin_header.php';
?>

<style>
/* ========== ПЕРЕМЕННЫЕ ТЕМЫ ========== */
:root {
    /* Светлая тема по умолчанию */
    --db-bg: #f8fafc;
    --db-card-bg: #ffffff;
    --db-border: #e2e8f0;
    --db-text: #1e293b;
    --db-text-secondary: #64748b;
    --db-text-muted: #94a3b8;
    --db-hover: #f1f5f9;
    --db-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
    --db-shadow-hover: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
    --db-accent: #00bcd4;
    --db-accent-light: rgba(0, 188, 212, 0.1);
    --db-success: #10b981;
    --db-warning: #f59e0b;
    --db-danger: #ef4444;
    --db-info: #3b82f6;
    --db-purple: #8b5cf6;
}

[data-theme="dark"] {
    --db-bg: #0f172a;
    --db-card-bg: #1e293b;
    --db-border: #334155;
    --db-text: #ffffff;
    --db-text-secondary: #cbd5e1;
    --db-text-muted: #94a3b8;
    --db-hover: #2d3748;
    --db-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.3), 0 1px 2px 0 rgba(0, 0, 0, 0.2);
    --db-shadow-hover: 0 10px 15px -3px rgba(0, 0, 0, 0.3), 0 4px 6px -2px rgba(0, 0, 0, 0.2);
}

/* ========== СТИЛИ МОНИТОРИНГА ========== */
.monitoring-wrapper {
    padding: 20px;
    background: var(--db-bg);
    min-height: calc(100vh - 70px);
    margin-left: 280px;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.admin-sidebar.compact + .monitoring-wrapper {
    margin-left: 70px;
}

.monitoring-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    padding: 24px;
    background: var(--db-card-bg);
    border-radius: 12px;
    border: 1px solid var(--db-border);
    box-shadow: var(--db-shadow);
}

.header-left h1 {
    color: var(--db-text);
    font-size: 24px;
    margin: 0 0 8px 0;
    display: flex;
    align-items: center;
    gap: 12px;
}

.header-left h1 i {
    color: var(--db-accent);
}

.header-left p {
    color: var(--db-text-secondary);
    font-size: 14px;
    margin: 0;
}

.monitoring-quick-actions {
    display: flex;
    gap: 12px;
}

.monitoring-action-btn {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 500;
    font-size: 14px;
    transition: all 0.3s ease;
    border: none;
    cursor: pointer;
}

.monitoring-action-btn-primary {
    background: linear-gradient(135deg, var(--db-accent), #0097a7);
    color: white;
}

.monitoring-action-btn-secondary {
    background: var(--db-card-bg);
    color: var(--db-text);
    border: 1px solid var(--db-border);
}

.monitoring-action-btn:hover {
    transform: translateY(-2px);
    box-shadow: var(--db-shadow-hover);
}

/* ========== СТАТИСТИЧЕСКИЕ КАРТОЧКИ ========== */
.monitoring-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.monitoring-stat-card {
    background: var(--db-card-bg);
    border: 1px solid var(--db-border);
    border-radius: 12px;
    padding: 20px;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
    box-shadow: var(--db-shadow);
}

.monitoring-stat-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: var(--stat-color);
    transform: translateX(-100%);
    transition: transform 0.3s ease;
}

.monitoring-stat-card:hover::before {
    transform: translateX(0);
}

.monitoring-stat-card:hover {
    transform: translateY(-4px);
    border-color: var(--db-accent);
    box-shadow: var(--db-shadow-hover);
}

.monitoring-stat-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 16px;
}

.monitoring-stat-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    color: white;
    background: var(--stat-color);
}

.monitoring-stat-content h3 {
    color: var(--db-text-secondary);
    font-size: 14px;
    font-weight: 500;
    margin: 0 0 8px 0;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.monitoring-stat-value {
    color: var(--db-text);
    font-size: 15px;
    font-weight: 700;
    margin: 0 0 4px 0;
    display: flex;
    align-items: baseline;
    gap: 8px;
}

.monitoring-stat-value span {
    font-size: 16px;
    font-weight: 500;
    color: var(--db-text-muted);
}

.monitoring-stat-subtext {
    color: var(--db-text-muted);
    font-size: 12px;
    margin: 0;
}

/* Цвета для карточек */
.monitoring-stat-card-system { --stat-color: var(--db-info); }
.monitoring-stat-card-disk { --stat-color: var(--db-success); }
.monitoring-stat-card-memory { --stat-color: var(--db-purple); }
.monitoring-stat-card-cpu { --stat-color: var(--db-warning); }
.monitoring-stat-card-uptime { --stat-color: var(--db-accent); }
.monitoring-stat-card-database { --stat-color: #9c27b0; }
.monitoring-stat-card-users { --stat-color: var(--db-success); }
.monitoring-stat-card-vms { --stat-color: var(--db-warning); }
.monitoring-stat-card-payments { --stat-color: var(--db-accent); }
.monitoring-stat-card-tickets { --stat-color: var(--db-danger); }
.monitoring-stat-card-backups { --stat-color: #673ab7; }
.monitoring-stat-card-cluster { --stat-color: #2196f3; }
.monitoring-stat-card-nodes { --stat-color: #4caf50; }
.monitoring-stat-card-metrics { --stat-color: #ff9800; }
.monitoring-stat-card-tariffs { --stat-color: #9c27b0; }
.monitoring-stat-card-proxmox { --stat-color: #3f51b5; }
.monitoring-stat-card-other { --stat-color: #607d8b; }

/* ========== ТАБЫ ========== */
.monitoring-tabs {
    margin-bottom: 30px;
}

.nav-tabs {
    border-bottom: 1px solid var(--db-border);
    flex-wrap: nowrap;
    overflow-x: auto;
    overflow-y: hidden;
}

.nav-tabs .nav-link {
    color: var(--db-text-secondary);
    border: none;
    padding: 12px 20px;
    font-weight: 500;
    white-space: nowrap;
    border-bottom: 2px solid transparent;
    transition: all 0.3s ease;
}

.nav-tabs .nav-link:hover {
    color: var(--db-text);
    border-color: transparent;
}

.nav-tabs .nav-link.active {
    color: var(--db-accent);
    border-bottom-color: var(--db-accent);
    background: transparent;
}

/* ========== ВКЛАДКИ СОДЕРЖИМОГО ========== */
.tab-content {
    background: var(--db-card-bg);
    border-radius: 12px;
    border: 1px solid var(--db-border);
    box-shadow: var(--db-shadow);
    overflow: hidden;
}

.tab-pane {
    padding: 0;
}

/* ========== СЕТКИ ВНУТРИ ВКЛАДОК ========== */
.tab-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 20px;
    padding: 20px;
}

.tab-details-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 20px;
    padding: 20px;
}

/* ========== ТАБЛИЦЫ ========== */
.monitoring-table-container {
    padding: 20px;
    max-height: 600px;
    overflow-y: auto;
}

.monitoring-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
}

.monitoring-table thead th {
    color: var(--db-text-secondary);
    font-size: 12px;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid var(--db-border);
    background: var(--db-hover);
    position: sticky;
    top: 0;
    z-index: 10;
}

.monitoring-table tbody tr {
    border-bottom: 1px solid var(--db-border);
    transition: all 0.3s ease;
}

.monitoring-table tbody tr:hover {
    background: var(--db-hover);
}

.monitoring-table tbody td {
    color: var(--db-text);
    font-size: 14px;
    padding: 12px;
    vertical-align: middle;
}

.table-badge {
    font-size: 11px;
    font-weight: 600;
    padding: 3px 8px;
    border-radius: 10px;
    text-transform: uppercase;
}

.table-badge-success { background: rgba(16, 185, 129, 0.2); color: var(--db-success); }
.table-badge-warning { background: rgba(245, 158, 11, 0.2); color: var(--db-warning); }
.table-badge-danger { background: rgba(239, 68, 68, 0.2); color: var(--db-danger); }
.table-badge-info { background: rgba(59, 130, 246, 0.2); color: var(--db-info); }
.table-badge-accent { background: rgba(0, 188, 212, 0.2); color: var(--db-accent); }

/* ========== ПРОГРЕСС БАРЫ ========== */
.monitoring-progress {
    margin: 15px 0;
}

.progress-header {
    display: flex;
    justify-content: space-between;
    margin-bottom: 5px;
}

.progress-label {
    color: var(--db-text);
    font-size: 14px;
    font-weight: 500;
}

.progress-value {
    color: var(--db-text-secondary);
    font-size: 14px;
}

.progress-bar-container {
    height: 10px;
    background: var(--db-border);
    border-radius: 5px;
    overflow: hidden;
}

.progress-bar {
    height: 100%;
    border-radius: 5px;
    transition: width 0.3s ease;
}

.progress-bar-success { background: linear-gradient(90deg, var(--db-success), #34d399); }
.progress-bar-warning { background: linear-gradient(90deg, var(--db-warning), #fbbf24); }
.progress-bar-danger { background: linear-gradient(90deg, var(--db-danger), #f87171); }
.progress-bar-info { background: linear-gradient(90deg, var(--db-info), #60a5fa); }
.progress-bar-accent { background: linear-gradient(90deg, var(--db-accent), #00bcd4); }

/* ========== СЕТЕВАЯ СТАТИСТИКА ========== */
.network-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 15px;
}

.network-interface {
    background: var(--db-hover);
    border: 1px solid var(--db-border);
    border-radius: 8px;
    padding: 15px;
}

.interface-header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 15px;
}

.interface-icon {
    width: 40px;
    height: 40px;
    border-radius: 8px;
    background: rgba(0, 188, 212, 0.1);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--db-accent);
    font-size: 20px;
}

.interface-name {
    color: var(--db-text);
    font-size: 16px;
    font-weight: 600;
}

.interface-stats {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
}

.stat-direction {
    text-align: center;
    padding: 10px;
    background: var(--db-card-bg);
    border-radius: 6px;
    border: 1px solid var(--db-border);
}

.stat-direction-label {
    color: var(--db-text-secondary);
    font-size: 12px;
    margin-bottom: 5px;
}

.stat-direction-value {
    color: var(--db-text);
    font-size: 14px;
    font-weight: 600;
}

/* ========== АДАПТИВНОСТЬ ========== */
@media (max-width: 1200px) {
    .monitoring-wrapper {
        margin-left: 70px !important;
    }
}

@media (max-width: 992px) {
    .monitoring-stats-grid {
        grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    }

    .tab-stats-grid,
    .tab-details-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .monitoring-wrapper {
        margin-left: 0 !important;
        padding: 15px;
    }

    .monitoring-header {
        flex-direction: column;
        align-items: stretch;
        gap: 15px;
    }

    .monitoring-quick-actions {
        flex-direction: column;
    }

    .monitoring-stats-grid {
        grid-template-columns: 1fr;
    }

    .nav-tabs {
        flex-wrap: wrap;
    }
}

/* ========== ОБНОВЛЕНИЕ ДАННЫХ ========== */
.monitoring-refresh {
    text-align: center;
    margin-top: 30px;
    padding: 20px;
    background: var(--db-card-bg);
    border-radius: 12px;
    border: 1px solid var(--db-border);
    box-shadow: var(--db-shadow);
}

.refresh-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 24px;
    background: linear-gradient(135deg, var(--db-accent), #0097a7);
    color: white;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s ease;
}

.refresh-btn:hover {
    transform: translateY(-2px);
    box-shadow: var(--db-shadow-hover);
}

.refresh-btn:disabled {
    opacity: 0.7;
    cursor: not-allowed;
}

.refresh-time {
    color: var(--db-text-secondary);
    font-size: 12px;
    margin-top: 8px;
}

/* ========== ОШИБКИ ========== */
.monitoring-alert {
    padding: 15px;
    border-radius: 8px;
    margin: 15px;
    display: flex;
    align-items: center;
    gap: 12px;
}

.alert-warning {
    background: rgba(245, 158, 11, 0.1);
    border: 1px solid rgba(245, 158, 11, 0.3);
    color: var(--db-warning);
}

.alert-icon {
    font-size: 20px;
}
</style>

<!-- Подключаем сайдбар -->
<?php require 'admin_sidebar.php'; ?>

<!-- Мониторинг -->
<div class="monitoring-wrapper">
    <!-- Шапка мониторинга -->
    <div class="monitoring-header">
        <div class="header-left">
            <h1><i class="fas fa-chart-line"></i> Системный мониторинг</h1>
            <p>Полная статистика системы, базы данных и всех сервисов</p>
        </div>
        <div class="monitoring-quick-actions">
            <button id="refreshBtn" class="monitoring-action-btn monitoring-action-btn-primary">
                <i class="fas fa-redo"></i> Обновить данные
            </button>
            <a href="/admin/index.php" class="monitoring-action-btn monitoring-action-btn-secondary">
                <i class="fas fa-tachometer-alt"></i> Дашборд
            </a>
        </div>
    </div>

    <!-- Основные метрики -->
    <div class="monitoring-stats-grid">
        <!-- Система -->
        <div class="monitoring-stat-card monitoring-stat-card-system">
            <div class="monitoring-stat-header">
                <div class="monitoring-stat-icon">
                    <i class="fas fa-server"></i>
                </div>
            </div>
            <div class="monitoring-stat-content">
                <h3>Система</h3>
                <div class="monitoring-stat-value"><?= $system_info['server_os'] ?></div>
                <p class="monitoring-stat-subtext">
                    PHP <?= $system_info['php_version'] ?><br>
                    <?= $system_info['server_software'] ?>
                </p>
            </div>
        </div>

        <!-- Диск -->
        <div class="monitoring-stat-card monitoring-stat-card-disk">
            <div class="monitoring-stat-header">
                <div class="monitoring-stat-icon">
                    <i class="fas fa-hdd"></i>
                </div>
            </div>
            <div class="monitoring-stat-content">
                <h3>Диск</h3>
                <?php if (isset($server_stats['disk']['error'])): ?>
                    <div class="monitoring-stat-value">N/A</div>
                    <p class="monitoring-stat-subtext"><?= $server_stats['disk']['error'] ?></p>
                <?php else: ?>
                    <div class="monitoring-stat-value"><?= $server_stats['disk']['percent'] ?>%</div>
                    <p class="monitoring-stat-subtext">
                        <?= $server_stats['disk']['used'] ?> / <?= $server_stats['disk']['total'] ?><br>
                        Свободно: <?= $server_stats['disk']['free'] ?>
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Память -->
        <div class="monitoring-stat-card monitoring-stat-card-memory">
            <div class="monitoring-stat-header">
                <div class="monitoring-stat-icon">
                    <i class="fas fa-memory"></i>
                </div>
            </div>
            <div class="monitoring-stat-content">
                <h3>Память</h3>
                <?php if (isset($server_stats['memory']['error'])): ?>
                    <div class="monitoring-stat-value">N/A</div>
                    <p class="monitoring-stat-subtext"><?= $server_stats['memory']['error'] ?></p>
                <?php elseif (isset($server_stats['memory']['info'])): ?>
                    <div class="monitoring-stat-value"><?= $server_stats['memory']['percent'] ?>%</div>
                    <p class="monitoring-stat-subtext"><?= $server_stats['memory']['info'] ?></p>
                <?php else: ?>
                    <div class="monitoring-stat-value"><?= $server_stats['memory']['percent'] ?>%</div>
                    <p class="monitoring-stat-subtext">
                        <?= $server_stats['memory']['used'] ?> / <?= $server_stats['memory']['total'] ?><br>
                        Доступно: <?= $server_stats['memory']['available'] ?>
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <!-- CPU -->
        <div class="monitoring-stat-card monitoring-stat-card-cpu">
            <div class="monitoring-stat-header">
                <div class="monitoring-stat-icon">
                    <i class="fas fa-microchip"></i>
                </div>
            </div>
            <div class="monitoring-stat-content">
                <h3>Процессор</h3>
                <?php if (isset($server_stats['cpu']['error'])): ?>
                    <div class="monitoring-stat-value">N/A</div>
                    <p class="monitoring-stat-subtext"><?= $server_stats['cpu']['error'] ?></p>
                <?php else: ?>
                    <div class="monitoring-stat-value"><?= $server_stats['cpu']['usage_percent'] ?>%</div>
                    <p class="monitoring-stat-subtext">
                        Нагрузка: <?= $server_stats['cpu']['load_1min'] ?> / <?= $server_stats['cpu']['load_5min'] ?> / <?= $server_stats['cpu']['load_15min'] ?><br>
                        Ядер: <?= $server_stats['cpu']['cores'] ?>
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Аптайм -->
        <div class="monitoring-stat-card monitoring-stat-card-uptime">
            <div class="monitoring-stat-header">
                <div class="monitoring-stat-icon">
                    <i class="fas fa-clock"></i>
                </div>
            </div>
            <div class="monitoring-stat-content">
                <h3>Аптайм</h3>
                <div class="monitoring-stat-value"><?= $server_stats['uptime'] ?></div>
                <p class="monitoring-stat-subtext">
                    <?= $server_stats['webserver'] ?><br>
                    IP: <?= $system_info['server_ip'] ?>
                </p>
            </div>
        </div>

        <!-- База данных -->
        <div class="monitoring-stat-card monitoring-stat-card-database">
            <div class="monitoring-stat-header">
                <div class="monitoring-stat-icon">
                    <i class="fas fa-database"></i>
                </div>
            </div>
            <div class="monitoring-stat-content">
                <h3>База данных</h3>
                <?php if (isset($db_stats['total'])): ?>
                    <div class="monitoring-stat-value"><?= $db_stats['total']['tables'] ?></div>
                    <p class="monitoring-stat-subtext">
                        Таблиц: <?= $db_stats['total']['tables'] ?><br>
                        Размер: <?= $db_stats['total']['total_size'] ?>
                    </p>
                <?php else: ?>
                    <div class="monitoring-stat-value">N/A</div>
                    <p class="monitoring-stat-subtext">Статистика недоступна</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Пользователи -->
        <div class="monitoring-stat-card monitoring-stat-card-users">
            <div class="monitoring-stat-header">
                <div class="monitoring-stat-icon">
                    <i class="fas fa-users"></i>
                </div>
            </div>
            <div class="monitoring-stat-content">
                <h3>Пользователи</h3>
                <div class="monitoring-stat-value"><?= $user_stats['total_users'] ?? 0 ?></div>
                <p class="monitoring-stat-subtext">
                    Активных: <?= $user_stats['active_users'] ?? 0 ?><br>
                    Баланс: <?= number_format($user_stats['total_balance'] ?? 0, 2) ?> ₽
                </p>
            </div>
        </div>

        <!-- ВМ -->
        <div class="monitoring-stat-card monitoring-stat-card-vms">
            <div class="monitoring-stat-header">
                <div class="monitoring-stat-icon">
                    <i class="fas fa-server"></i>
                </div>
            </div>
            <div class="monitoring-stat-content">
                <h3>Виртуальные машины</h3>
                <div class="monitoring-stat-value"><?= $vm_stats['total_vms'] ?? 0 ?></div>
                <p class="monitoring-stat-subtext">
                    Пользователей: <?= $vm_stats['users_with_vms'] ?? 0 ?><br>
                    CPU: <?= $vm_stats['resources']['total_cpu'] ?? 0 ?> ядер
                </p>
            </div>
        </div>
    </div>

    <!-- Вкладки с детальной информацией -->
    <div class="monitoring-tabs">
        <ul class="nav nav-tabs" id="monitoringTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="system-tab" data-bs-toggle="tab" data-bs-target="#system" type="button" role="tab">
                    <i class="fas fa-cogs me-2"></i>Система
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="database-tab" data-bs-toggle="tab" data-bs-target="#database" type="button" role="tab">
                    <i class="fas fa-database me-2"></i>База данных
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="users-tab" data-bs-toggle="tab" data-bs-target="#users" type="button" role="tab">
                    <i class="fas fa-users me-2"></i>Пользователи
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="vms-tab" data-bs-toggle="tab" data-bs-target="#vms" type="button" role="tab">
                    <i class="fas fa-server me-2"></i>ВМ
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="payments-tab" data-bs-toggle="tab" data-bs-target="#payments" type="button" role="tab">
                    <i class="fas fa-credit-card me-2"></i>Платежи
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tickets-tab" data-bs-toggle="tab" data-bs-target="#tickets" type="button" role="tab">
                    <i class="fas fa-ticket-alt me-2"></i>Тикеты
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="clusters-tab" data-bs-toggle="tab" data-bs-target="#clusters" type="button" role="tab">
                    <i class="fas fa-network-wired me-2"></i>Кластеры
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="other-tab" data-bs-toggle="tab" data-bs-target="#other" type="button" role="tab">
                    <i class="fas fa-chart-pie me-2"></i>Другое
                </button>
            </li>
        </ul>

        <div class="tab-content" id="monitoringTabsContent">
            <!-- Вкладка Система -->
            <div class="tab-pane fade show active" id="system" role="tabpanel">
                <div class="tab-details-grid">
                    <!-- Системная информация -->
                    <div class="monitoring-stat-card">
                        <div class="monitoring-stat-header">
                            <div class="monitoring-stat-icon" style="background: var(--db-info);">
                                <i class="fas fa-info-circle"></i>
                            </div>
                        </div>
                        <div class="monitoring-stat-content">
                            <h3>Системная информация</h3>
                            <div style="font-size: 13px; line-height: 1.6;">
                                <div><strong>ОС:</strong> <?= $system_info['server_os'] ?></div>
                                <div><strong>Веб-сервер:</strong> <?= $system_info['server_software'] ?></div>
                                <div><strong>PHP:</strong> <?= $system_info['php_version'] ?></div>
                                <div><strong>Память:</strong> <?= $system_info['php_memory_limit'] ?></div>
                                <div><strong>Время:</strong> <?= $system_info['server_time'] ?></div>
                                <div><strong>IP:</strong> <?= $system_info['server_ip'] ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Диск -->
                    <?php if (!isset($server_stats['disk']['error'])): ?>
                    <div class="monitoring-stat-card">
                        <div class="monitoring-stat-header">
                            <div class="monitoring-stat-icon" style="background: var(--db-success);">
                                <i class="fas fa-hdd"></i>
                            </div>
                        </div>
                        <div class="monitoring-stat-content">
                            <h3>Дисковое пространство</h3>
                            <div class="monitoring-progress">
                                <div class="progress-header">
                                    <span class="progress-label">Использование</span>
                                    <span class="progress-value"><?= $server_stats['disk']['percent'] ?>%</span>
                                </div>
                                <div class="progress-bar-container">
                                    <div class="progress-bar <?= $server_stats['disk']['percent'] > 90 ? 'progress-bar-danger' : ($server_stats['disk']['percent'] > 70 ? 'progress-bar-warning' : 'progress-bar-success') ?>"
                                         style="width: <?= $server_stats['disk']['percent'] ?>%"></div>
                                </div>
                                <div style="font-size: 12px; color: var(--db-text-muted); margin-top: 5px;">
                                    <?= $server_stats['disk']['used'] ?> / <?= $server_stats['disk']['total'] ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Память -->
                    <?php if (!isset($server_stats['memory']['error'])): ?>
                    <div class="monitoring-stat-card">
                        <div class="monitoring-stat-header">
                            <div class="monitoring-stat-icon" style="background: var(--db-purple);">
                                <i class="fas fa-memory"></i>
                            </div>
                        </div>
                        <div class="monitoring-stat-content">
                            <h3>Оперативная память</h3>
                            <div class="monitoring-progress">
                                <div class="progress-header">
                                    <span class="progress-label">Использование</span>
                                    <span class="progress-value"><?= $server_stats['memory']['percent'] ?>%</span>
                                </div>
                                <div class="progress-bar-container">
                                    <div class="progress-bar <?= $server_stats['memory']['percent'] > 90 ? 'progress-bar-danger' : ($server_stats['memory']['percent'] > 70 ? 'progress-bar-warning' : 'progress-bar-success') ?>"
                                         style="width: <?= $server_stats['memory']['percent'] ?>%"></div>
                                </div>
                                <div style="font-size: 12px; color: var(--db-text-muted); margin-top: 5px;">
                                    <?= $server_stats['memory']['used'] ?> / <?= $server_stats['memory']['total'] ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- CPU -->
                    <?php if (!isset($server_stats['cpu']['error'])): ?>
                    <div class="monitoring-stat-card">
                        <div class="monitoring-stat-header">
                            <div class="monitoring-stat-icon" style="background: var(--db-warning);">
                                <i class="fas fa-microchip"></i>
                            </div>
                        </div>
                        <div class="monitoring-stat-content">
                            <h3>Процессор</h3>
                            <div class="monitoring-progress">
                                <div class="progress-header">
                                    <span class="progress-label">Использование</span>
                                    <span class="progress-value"><?= $server_stats['cpu']['usage_percent'] ?>%</span>
                                </div>
                                <div class="progress-bar-container">
                                    <div class="progress-bar <?= $server_stats['cpu']['usage_percent'] > 90 ? 'progress-bar-danger' : ($server_stats['cpu']['usage_percent'] > 70 ? 'progress-bar-warning' : 'progress-bar-success') ?>"
                                         style="width: <?= $server_stats['cpu']['usage_percent'] ?>%"></div>
                                </div>
                                <div style="font-size: 12px; color: var(--db-text-muted); margin-top: 5px;">
                                    Нагрузка: <?= $server_stats['cpu']['load_1min'] ?> (1м) / <?= $server_stats['cpu']['load_5min'] ?> (5м) / <?= $server_stats['cpu']['load_15min'] ?> (15м)
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Вкладка База данных -->
            <div class="tab-pane fade" id="database" role="tabpanel">
                <div class="monitoring-table-container">
                    <table class="monitoring-table">
                        <thead>
                            <tr>
                                <th>Таблица</th>
                                <th>Записей</th>
                                <th>Размер</th>
                                <th>Строк/размер</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (isset($db_stats['error'])): ?>
                                <tr>
                                    <td colspan="4" class="text-center">
                                        <div class="monitoring-alert alert-warning">
                                            <div class="alert-icon"><i class="fas fa-exclamation-triangle"></i></div>
                                            <div><?= $db_stats['error'] ?></div>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($db_stats as $table => $data): ?>
                                    <?php if ($table !== 'total' && $table !== 'error' && is_array($data)): ?>
                                    <tr>
                                        <td><strong><?= $table ?></strong></td>
                                        <td><?= number_format($data['count']) ?></td>
                                        <td><?= $data['size'] ?></td>
                                        <td>
                                            <div style="font-size: 12px; color: var(--db-text-muted);">
                                                <?= number_format($data['count']) ?> записей
                                                <?php if ($data['size_bytes'] > 0): ?>
                                                    (<?= round($data['size_bytes'] / max($data['count'], 1)) ?> байт/запись)
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                <?php if (isset($db_stats['total'])): ?>
                                    <tr style="background: var(--db-hover);">
                                        <td><strong>Итого</strong></td>
                                        <td><strong><?= array_sum(array_column(array_filter($db_stats, function($k) { return $k !== 'total' && $k !== 'error'; }, ARRAY_FILTER_USE_KEY), 'count')) ?></strong></td>
                                        <td><strong><?= $db_stats['total']['total_size'] ?></strong></td>
                                        <td>
                                            <div style="font-size: 12px; color: var(--db-text-muted);">
                                                <?= $db_stats['total']['tables'] ?> таблиц,
                                                Средний размер: <?= formatBytess($db_stats['total']['total_size_bytes'] / max($db_stats['total']['tables'], 1)) ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Вкладка Пользователи -->
            <div class="tab-pane fade" id="users" role="tabpanel">
                <div class="tab-stats-grid">
                    <?php if (isset($user_stats['error'])): ?>
                        <div class="monitoring-alert alert-warning">
                            <div class="alert-icon"><i class="fas fa-exclamation-triangle"></i></div>
                            <div><?= $user_stats['error'] ?></div>
                        </div>
                    <?php else: ?>
                        <!-- Всего пользователей -->
                        <div class="monitoring-stat-card">
                            <div class="monitoring-stat-icon" style="background: var(--db-success);">
                                <i class="fas fa-users"></i>
                            </div>
                            <div class="monitoring-stat-content">
                                <h3>Всего пользователей</h3>
                                <div class="monitoring-stat-value"><?= $user_stats['total_users'] ?></div>
                            </div>
                        </div>

                        <!-- Активных -->
                        <div class="monitoring-stat-card">
                            <div class="monitoring-stat-icon" style="background: var(--db-accent);">
                                <i class="fas fa-user-check"></i>
                            </div>
                            <div class="monitoring-stat-content">
                                <h3>Активных</h3>
                                <div class="monitoring-stat-value"><?= $user_stats['active_users'] ?></div>
                                <p class="monitoring-stat-subtext"><?= round(($user_stats['active_users'] / max($user_stats['total_users'], 1)) * 100, 1) ?>%</p>
                            </div>
                        </div>

                        <!-- Админов -->
                        <div class="monitoring-stat-card">
                            <div class="monitoring-stat-icon" style="background: var(--db-info);">
                                <i class="fas fa-user-shield"></i>
                            </div>
                            <div class="monitoring-stat-content">
                                <h3>Администраторов</h3>
                                <div class="monitoring-stat-value"><?= $user_stats['admin_users'] ?></div>
                            </div>
                        </div>

                        <!-- Верифицированных -->
                        <div class="monitoring-stat-card">
                            <div class="monitoring-stat-icon" style="background: var(--db-success);">
                                <i class="fas fa-check-circle"></i>
                            </div>
                            <div class="monitoring-stat-content">
                                <h3>Верифицированных</h3>
                                <div class="monitoring-stat-value"><?= $user_stats['verified_users'] ?></div>
                                <p class="monitoring-stat-subtext"><?= round(($user_stats['verified_users'] / max($user_stats['total_users'], 1)) * 100, 1) ?>%</p>
                            </div>
                        </div>

                        <!-- Баланс -->
                        <div class="monitoring-stat-card">
                            <div class="monitoring-stat-icon" style="background: var(--db-warning);">
                                <i class="fas fa-wallet"></i>
                            </div>
                            <div class="monitoring-stat-content">
                                <h3>Общий баланс</h3>
                                <div class="monitoring-stat-value"><?= number_format($user_stats['total_balance'], 0) ?> ₽</div>
                                <p class="monitoring-stat-subtext">Бонусы: <?= number_format($user_stats['total_bonus_balance'], 0) ?> ₽</p>
                            </div>
                        </div>

                        <!-- Новые сегодня -->
                        <div class="monitoring-stat-card">
                            <div class="monitoring-stat-icon" style="background: var(--db-purple);">
                                <i class="fas fa-user-plus"></i>
                            </div>
                            <div class="monitoring-stat-content">
                                <h3>Новые сегодня</h3>
                                <div class="monitoring-stat-value"><?= $user_stats['new_today'] ?></div>
                                <p class="monitoring-stat-subtext">За неделю: <?= $user_stats['new_week'] ?></p>
                            </div>
                        </div>

                        <!-- Telegram -->
                        <div class="monitoring-stat-card">
                            <div class="monitoring-stat-icon" style="background: var(--db-info);">
                                <i class="fab fa-telegram"></i>
                            </div>
                            <div class="monitoring-stat-content">
                                <h3>Telegram</h3>
                                <div class="monitoring-stat-value"><?= $user_stats['telegram_users'] ?></div>
                                <p class="monitoring-stat-subtext"><?= round(($user_stats['telegram_users'] / max($user_stats['total_users'], 1)) * 100, 1) ?>% пользователей</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Вкладка ВМ -->
            <div class="tab-pane fade" id="vms" role="tabpanel">
                <div class="tab-stats-grid">
                    <?php if (isset($vm_stats['error'])): ?>
                        <div class="monitoring-alert alert-warning">
                            <div class="alert-icon"><i class="fas fa-exclamation-triangle"></i></div>
                            <div><?= $vm_stats['error'] ?></div>
                        </div>
                    <?php else: ?>
                        <!-- Всего ВМ -->
                        <div class="monitoring-stat-card">
                            <div class="monitoring-stat-icon" style="background: var(--db-warning);">
                                <i class="fas fa-server"></i>
                            </div>
                            <div class="monitoring-stat-content">
                                <h3>Всего ВМ</h3>
                                <div class="monitoring-stat-value"><?= $vm_stats['total_vms'] ?></div>
                                <p class="monitoring-stat-subtext">Пользователей: <?= $vm_stats['users_with_vms'] ?></p>
                            </div>
                        </div>

                        <!-- Админ ВМ -->
                        <div class="monitoring-stat-card">
                            <div class="monitoring-stat-icon" style="background: var(--db-info);">
                                <i class="fas fa-user-shield"></i>
                            </div>
                            <div class="monitoring-stat-content">
                                <h3>Админ ВМ</h3>
                                <div class="monitoring-stat-value"><?= $vm_stats['admin_vms'] ?></div>
                            </div>
                        </div>

                        <!-- CPU -->
                        <div class="monitoring-stat-card">
                            <div class="monitoring-stat-icon" style="background: var(--db-accent);">
                                <i class="fas fa-microchip"></i>
                            </div>
                            <div class="monitoring-stat-content">
                                <h3>CPU (ядер)</h3>
                                <div class="monitoring-stat-value"><?= $vm_stats['resources']['total_cpu'] ?? 0 ?></div>
                                <p class="monitoring-stat-subtext">Среднее: <?= round($vm_stats['resources']['avg_cpu'] ?? 0, 1) ?></p>
                            </div>
                        </div>

                        <!-- RAM -->
                        <div class="monitoring-stat-card">
                            <div class="monitoring-stat-icon" style="background: var(--db-purple);">
                                <i class="fas fa-memory"></i>
                            </div>
                            <div class="monitoring-stat-content">
                                <h3>RAM (GB)</h3>
                                <div class="monitoring-stat-value"><?= round(($vm_stats['resources']['total_ram'] ?? 0) / 1024, 1) ?></div>
                                <p class="monitoring-stat-subtext">Среднее: <?= round(($vm_stats['resources']['avg_ram'] ?? 0) / 1024, 1) ?> GB</p>
                            </div>
                        </div>

                        <!-- Disk -->
                        <div class="monitoring-stat-card">
                            <div class="monitoring-stat-icon" style="background: var(--db-success);">
                                <i class="fas fa-hdd"></i>
                            </div>
                            <div class="monitoring-stat-content">
                                <h3>Disk (GB)</h3>
                                <div class="monitoring-stat-value"><?= $vm_stats['resources']['total_disk'] ?? 0 ?></div>
                                <p class="monitoring-stat-subtext">Среднее: <?= round($vm_stats['resources']['avg_disk'] ?? 0, 1) ?> GB</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Вкладка Платежи -->
            <div class="tab-pane fade" id="payments" role="tabpanel">
                <div class="tab-stats-grid">
                    <?php if (isset($payment_stats['error'])): ?>
                        <div class="monitoring-alert alert-warning">
                            <div class="alert-icon"><i class="fas fa-exclamation-triangle"></i></div>
                            <div><?= $payment_stats['error'] ?></div>
                        </div>
                    <?php else: ?>
                        <!-- Всего платежей -->
                        <div class="monitoring-stat-card">
                            <div class="monitoring-stat-icon" style="background: var(--db-accent);">
                                <i class="fas fa-credit-card"></i>
                            </div>
                            <div class="monitoring-stat-content">
                                <h3>Всего платежей</h3>
                                <div class="monitoring-stat-value"><?= $payment_stats['total_payments'] ?></div>
                            </div>
                        </div>

                        <!-- Успешных -->
                        <div class="monitoring-stat-card">
                            <div class="monitoring-stat-icon" style="background: var(--db-success);">
                                <i class="fas fa-check-circle"></i>
                            </div>
                            <div class="monitoring-stat-content">
                                <h3>Успешных</h3>
                                <div class="monitoring-stat-value"><?= $payment_stats['completed_payments'] ?></div>
                                <p class="monitoring-stat-subtext"><?= round(($payment_stats['completed_payments'] / max($payment_stats['total_payments'], 1)) * 100, 1) ?>%</p>
                            </div>
                        </div>

                        <!-- Ожидающих -->
                        <div class="monitoring-stat-card">
                            <div class="monitoring-stat-icon" style="background: var(--db-warning);">
                                <i class="fas fa-clock"></i>
                            </div>
                            <div class="monitoring-stat-content">
                                <h3>Ожидающих</h3>
                                <div class="monitoring-stat-value"><?= $payment_stats['pending_payments'] ?></div>
                            </div>
                        </div>

                        <!-- Неудачных -->
                        <div class="monitoring-stat-card">
                            <div class="monitoring-stat-icon" style="background: var(--db-danger);">
                                <i class="fas fa-times-circle"></i>
                            </div>
                            <div class="monitoring-stat-content">
                                <h3>Неудачных</h3>
                                <div class="monitoring-stat-value"><?= $payment_stats['failed_payments'] ?></div>
                            </div>
                        </div>

                        <!-- Общая сумма -->
                        <div class="monitoring-stat-card">
                            <div class="monitoring-stat-icon" style="background: var(--db-warning);">
                                <i class="fas fa-wallet"></i>
                            </div>
                            <div class="monitoring-stat-content">
                                <h3>Общая сумма</h3>
                                <div class="monitoring-stat-value"><?= number_format($payment_stats['total_amount'], 0) ?> ₽</div>
                            </div>
                        </div>

                        <!-- За сегодня -->
                        <div class="monitoring-stat-card">
                            <div class="monitoring-stat-icon" style="background: var(--db-success);">
                                <i class="fas fa-calendar-day"></i>
                            </div>
                            <div class="monitoring-stat-content">
                                <h3>За сегодня</h3>
                                <div class="monitoring-stat-value"><?= number_format($payment_stats['today_amount'], 0) ?> ₽</div>
                            </div>
                        </div>

                        <!-- За неделю -->
                        <div class="monitoring-stat-card">
                            <div class="monitoring-stat-icon" style="background: var(--db-info);">
                                <i class="fas fa-calendar-week"></i>
                            </div>
                            <div class="monitoring-stat-content">
                                <h3>За неделю</h3>
                                <div class="monitoring-stat-value"><?= number_format($payment_stats['week_amount'], 0) ?> ₽</div>
                            </div>
                        </div>

                        <!-- За месяц -->
                        <div class="monitoring-stat-card">
                            <div class="monitoring-stat-icon" style="background: var(--db-purple);">
                                <i class="fas fa-calendar-alt"></i>
                            </div>
                            <div class="monitoring-stat-content">
                                <h3>За месяц</h3>
                                <div class="monitoring-stat-value"><?= number_format($payment_stats['month_amount'], 0) ?> ₽</div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Вкладка Тикеты -->
            <div class="tab-pane fade" id="tickets" role="tabpanel">
                <div class="tab-stats-grid">
                    <?php if (isset($ticket_stats['error'])): ?>
                        <div class="monitoring-alert alert-warning">
                            <div class="alert-icon"><i class="fas fa-exclamation-triangle"></i></div>
                            <div><?= $ticket_stats['error'] ?></div>
                        </div>
                    <?php else: ?>
                        <!-- Всего тикетов -->
                        <div class="monitoring-stat-card">
                            <div class="monitoring-stat-icon" style="background: var(--db-danger);">
                                <i class="fas fa-ticket-alt"></i>
                            </div>
                            <div class="monitoring-stat-content">
                                <h3>Всего тикетов</h3>
                                <div class="monitoring-stat-value"><?= $ticket_stats['total_tickets'] ?></div>
                            </div>
                        </div>

                        <!-- Открытых -->
                        <div class="monitoring-stat-card">
                            <div class="monitoring-stat-icon" style="background: var(--db-warning);">
                                <i class="fas fa-exclamation-circle"></i>
                            </div>
                            <div class="monitoring-stat-content">
                                <h3>Открытых</h3>
                                <div class="monitoring-stat-value"><?= $ticket_stats['open_tickets'] ?></div>
                            </div>
                        </div>

                        <!-- Ответов -->
                        <div class="monitoring-stat-card">
                            <div class="monitoring-stat-icon" style="background: var(--db-info);">
                                <i class="fas fa-reply"></i>
                            </div>
                            <div class="monitoring-stat-content">
                                <h3>Ответов</h3>
                                <div class="monitoring-stat-value"><?= $ticket_stats['total_replies'] ?></div>
                                <p class="monitoring-stat-subtext"><?= $ticket_stats['admin_replies'] ?> от админов</p>
                            </div>
                        </div>

                        <!-- Вложений -->
                        <div class="monitoring-stat-card">
                            <div class="monitoring-stat-icon" style="background: var(--db-accent);">
                                <i class="fas fa-paperclip"></i>
                            </div>
                            <div class="monitoring-stat-content">
                                <h3>Вложений</h3>
                                <div class="monitoring-stat-value"><?= $ticket_stats['total_attachments'] ?></div>
                            </div>
                        </div>

                        <!-- В работе -->
                        <div class="monitoring-stat-card">
                            <div class="monitoring-stat-icon" style="background: var(--db-info);">
                                <i class="fas fa-cogs"></i>
                            </div>
                            <div class="monitoring-stat-content">
                                <h3>В работе</h3>
                                <div class="monitoring-stat-value"><?= $ticket_stats['pending_tickets'] ?></div>
                            </div>
                        </div>

                        <!-- Закрытых -->
                        <div class="monitoring-stat-card">
                            <div class="monitoring-stat-icon" style="background: var(--db-success);">
                                <i class="fas fa-check-circle"></i>
                            </div>
                            <div class="monitoring-stat-content">
                                <h3>Закрытых</h3>
                                <div class="monitoring-stat-value"><?= $ticket_stats['closed_tickets'] ?></div>
                            </div>
                        </div>

                        <!-- Отвеченных -->
                        <div class="monitoring-stat-card">
                            <div class="monitoring-stat-icon" style="background: var(--db-success);">
                                <i class="fas fa-reply"></i>
                            </div>
                            <div class="monitoring-stat-content">
                                <h3>Отвеченных</h3>
                                <div class="monitoring-stat-value"><?= $ticket_stats['answered_tickets'] ?></div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Вкладка Кластеры -->
            <div class="tab-pane fade" id="clusters" role="tabpanel">
                <div class="tab-stats-grid">
                    <!-- Кластеры -->
                    <?php if (isset($cluster_stats['error'])): ?>
                        <div class="monitoring-alert alert-warning">
                            <div class="alert-icon"><i class="fas fa-exclamation-triangle"></i></div>
                            <div><?= $cluster_stats['error'] ?></div>
                        </div>
                    <?php else: ?>
                        <!-- Всего кластеров -->
                        <div class="monitoring-stat-card">
                            <div class="monitoring-stat-icon" style="background: #2196f3;">
                                <i class="fas fa-network-wired"></i>
                            </div>
                            <div class="monitoring-stat-content">
                                <h3>Кластеры</h3>
                                <div class="monitoring-stat-value"><?= $cluster_stats['total_clusters'] ?></div>
                                <p class="monitoring-stat-subtext">Активных: <?= $cluster_stats['active_clusters'] ?></p>
                            </div>
                        </div>

                        <!-- Ноды -->
                        <div class="monitoring-stat-card">
                            <div class="monitoring-stat-icon" style="background: #4caf50;">
                                <i class="fas fa-server"></i>
                            </div>
                            <div class="monitoring-stat-content">
                                <h3>Ноды</h3>
                                <div class="monitoring-stat-value"><?= $node_stats['total_nodes'] ?></div>
                                <p class="monitoring-stat-subtext">Активных: <?= $node_stats['active_nodes'] ?></p>
                            </div>
                        </div>

                        <!-- Доступных нод -->
                        <div class="monitoring-stat-card">
                            <div class="monitoring-stat-icon" style="background: var(--db-success);">
                                <i class="fas fa-check-circle"></i>
                            </div>
                            <div class="monitoring-stat-content">
                                <h3>Доступных</h3>
                                <div class="monitoring-stat-value"><?= $node_stats['available_nodes'] ?></div>
                                <p class="monitoring-stat-subtext">Для пользователей</p>
                            </div>
                        </div>

                        <!-- Главных нод -->
                        <div class="monitoring-stat-card">
                            <div class="monitoring-stat-icon" style="background: var(--db-info);">
                                <i class="fas fa-crown"></i>
                            </div>
                            <div class="monitoring-stat-content">
                                <h3>Главных нод</h3>
                                <div class="monitoring-stat-value"><?= $node_stats['master_nodes'] ?></div>
                            </div>
                        </div>

                        <!-- Статистика нод -->
                        <div class="monitoring-stat-card">
                            <div class="monitoring-stat-icon" style="background: var(--db-accent);">
                                <i class="fas fa-chart-bar"></i>
                            </div>
                            <div class="monitoring-stat-content">
                                <h3>Статистика</h3>
                                <div class="monitoring-stat-value"><?= $node_stats['total_node_stats'] ?></div>
                                <p class="monitoring-stat-subtext">записей метрик</p>
                            </div>
                        </div>

                        <!-- Проверки -->
                        <div class="monitoring-stat-card">
                            <div class="monitoring-stat-icon" style="background: var(--db-warning);">
                                <i class="fas fa-search"></i>
                            </div>
                            <div class="monitoring-stat-content">
                                <h3>Проверки</h3>
                                <div class="monitoring-stat-value"><?= $node_stats['total_node_checks'] ?></div>
                                <p class="monitoring-stat-subtext">выполнено проверок</p>
                            </div>
                        </div>

                        <!-- Логи кластера -->
                        <div class="monitoring-stat-card">
                            <div class="monitoring-stat-icon" style="background: var(--db-purple);">
                                <i class="fas fa-history"></i>
                            </div>
                            <div class="monitoring-stat-content">
                                <h3>Логи кластера</h3>
                                <div class="monitoring-stat-value"><?= $cluster_stats['total_cluster_logs'] ?></div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Вкладка Другое -->
            <div class="tab-pane fade" id="other" role="tabpanel">
                <div class="tab-stats-grid">
                    <!-- Тарифы -->
                    <div class="monitoring-stat-card">
                        <div class="monitoring-stat-icon" style="background: #9c27b0;">
                            <i class="fas fa-list-alt"></i>
                        </div>
                        <div class="monitoring-stat-content">
                            <h3>Тарифы</h3>
                            <div class="monitoring-stat-value"><?= $tariff_stats['total_tariffs'] ?></div>
                            <p class="monitoring-stat-subtext">Активных: <?= $tariff_stats['active_tariffs'] ?></p>
                        </div>
                    </div>

                    <!-- Транзакции -->
                    <div class="monitoring-stat-card">
                        <div class="monitoring-stat-icon" style="background: var(--db-accent);">
                            <i class="fas fa-exchange-alt"></i>
                        </div>
                        <div class="monitoring-stat-content">
                            <h3>Транзакции</h3>
                            <div class="monitoring-stat-value"><?= $other_stats['total_transactions'] ?></div>
                            <p class="monitoring-stat-subtext">Пополнено: <?= number_format($other_stats['total_credited'] ?? 0, 2) ?> ₽</p>
                        </div>
                    </div>

                    <!-- Промоакции -->
                    <div class="monitoring-stat-card">
                        <div class="monitoring-stat-icon" style="background: var(--db-warning);">
                            <i class="fas fa-percentage"></i>
                        </div>
                        <div class="monitoring-stat-content">
                            <h3>Промоакции</h3>
                            <div class="monitoring-stat-value"><?= $other_stats['total_promotions'] ?></div>
                            <p class="monitoring-stat-subtext">Активных: <?= $other_stats['active_promotions'] ?></p>
                        </div>
                    </div>

                    <!-- Уведомления -->
                    <div class="monitoring-stat-card">
                        <div class="monitoring-stat-icon" style="background: var(--db-info);">
                            <i class="fas fa-bell"></i>
                        </div>
                        <div class="monitoring-stat-content">
                            <h3>Уведомления</h3>
                            <div class="monitoring-stat-value"><?= $other_stats['total_notifications'] ?></div>
                            <p class="monitoring-stat-subtext">Непрочитанных: <?= $other_stats['unread_notifications'] ?></p>
                        </div>
                    </div>

                    <!-- Функции -->
                    <div class="monitoring-stat-card">
                        <div class="monitoring-stat-icon" style="background: var(--db-success);">
                            <i class="fas fa-cogs"></i>
                        </div>
                        <div class="monitoring-stat-content">
                            <h3>Функции</h3>
                            <div class="monitoring-stat-value"><?= $other_stats['total_features'] ?></div>
                            <p class="monitoring-stat-subtext">Активных: <?= $other_stats['active_features'] ?></p>
                        </div>
                    </div>

                    <!-- Telegram -->
                    <div class="monitoring-stat-card">
                        <div class="monitoring-stat-icon" style="background: #0088cc;">
                            <i class="fab fa-telegram"></i>
                        </div>
                        <div class="monitoring-stat-content">
                            <h3>Telegram</h3>
                            <div class="monitoring-stat-value"><?= $other_stats['total_telegram_conversations'] ?></div>
                            <p class="monitoring-stat-subtext">В очереди: <?= $other_stats['total_telegram_queue'] ?></p>
                        </div>
                    </div>

                    <!-- Обновления -->
                    <div class="monitoring-stat-card">
                        <div class="monitoring-stat-icon" style="background: var(--db-purple);">
                            <i class="fas fa-sync-alt"></i>
                        </div>
                        <div class="monitoring-stat-content">
                            <h3>Обновления</h3>
                            <div class="monitoring-stat-value"><?= $other_stats['total_system_updates'] ?></div>
                            <p class="monitoring-stat-subtext">Успешных: <?= $other_stats['successful_updates'] ?></p>
                        </div>
                    </div>

                    <!-- Сбросы паролей -->
                    <div class="monitoring-stat-card">
                        <div class="monitoring-stat-icon" style="background: var(--db-danger);">
                            <i class="fas fa-key"></i>
                        </div>
                        <div class="monitoring-stat-content">
                            <h3>Сбросы паролей</h3>
                            <div class="monitoring-stat-value"><?= $other_stats['total_password_resets'] ?></div>
                            <p class="monitoring-stat-subtext">Активных: <?= $other_stats['active_password_resets'] ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Обновление данных -->
    <div class="monitoring-refresh">
        <button id="refreshBtnBottom" class="refresh-btn">
            <i class="fas fa-redo"></i> Обновить данные
        </button>
        <div class="refresh-time">Последнее обновление: <?= date('d.m.Y H:i:s') ?></div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Анимация карточек при загрузке
    const statCards = document.querySelectorAll('.monitoring-stat-card');
    statCards.forEach((card, index) => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(20px)';

        setTimeout(() => {
            card.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
            card.style.opacity = '1';
            card.style.transform = 'translateY(0)';
        }, index * 50);
    });

    // Обновление данных
    function refreshMonitoringData() {
        const refreshBtn = document.getElementById('refreshBtn');
        const refreshBtnBottom = document.getElementById('refreshBtnBottom');
        const refreshTime = document.querySelector('.refresh-time');

        if (refreshBtn) refreshBtn.disabled = true;
        if (refreshBtnBottom) refreshBtnBottom.disabled = true;

        if (refreshBtn) refreshBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Обновление...';
        if (refreshBtnBottom) refreshBtnBottom.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Обновление...';

        setTimeout(() => {
            location.reload();
        }, 1000);
    }

    // Обработчики для кнопок обновления
    document.getElementById('refreshBtn')?.addEventListener('click', refreshMonitoringData);
    document.getElementById('refreshBtnBottom')?.addEventListener('click', refreshMonitoringData);

    // Автообновление каждые 5 минут
    setTimeout(() => {
        location.reload();
    }, 300000);

    // Обновление отступа при сворачивании сайдбара
    const sidebar = document.querySelector('.admin-sidebar');
    const monitoring = document.querySelector('.monitoring-wrapper');

    if (sidebar && monitoring) {
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.attributeName === 'class') {
                    if (sidebar.classList.contains('compact')) {
                        monitoring.style.marginLeft = '70px';
                    } else {
                        monitoring.style.marginLeft = '280px';
                    }
                }
            });
        });

        observer.observe(sidebar, { attributes: true });
    }

    // Сохраняем активную вкладку
    const monitoringTabs = document.getElementById('monitoringTabs');
    if (monitoringTabs) {
        monitoringTabs.addEventListener('shown.bs.tab', function(event) {
            localStorage.setItem('activeMonitoringTab', event.target.id);
        });

        // Восстанавливаем активную вкладку
        const activeTabId = localStorage.getItem('activeMonitoringTab');
        if (activeTabId) {
            const activeTab = document.querySelector(`#${activeTabId}`);
            if (activeTab) {
                const tab = new bootstrap.Tab(activeTab);
                tab.show();
            }
        }
    }
});
</script>

<?php
require 'admin_footer.php';
?>
