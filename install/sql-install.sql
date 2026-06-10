-- phpMyAdmin SQL Dump
-- version 5.2.1deb1+deb12u1
-- https://www.phpmyadmin.net/
--
-- Хост: localhost:3306
-- Время создания: Дек 10 2025 г., 17:06
-- Версия сервера: 10.11.14-MariaDB-0+deb12u2
-- Версия PHP: 8.2.29

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- База данных: `mortal_prox1`
--

-- --------------------------------------------------------

--
-- Структура таблицы `balance_history`
--

CREATE TABLE `balance_history` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `operation_type` enum('payment','bonus','withdrawal','correction') NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `features`
--

CREATE TABLE `features` (
  `id` int(11) NOT NULL,
  `title` varchar(100) NOT NULL,
  `description` text NOT NULL,
  `icon` varchar(50) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `legal_entity_info`
--

CREATE TABLE `legal_entity_info` (
  `id` int(11) NOT NULL,
  `company_name` varchar(255) NOT NULL,
  `legal_address` text NOT NULL,
  `tax_number` varchar(50) NOT NULL,
  `registration_number` varchar(50) NOT NULL,
  `bank_name` varchar(255) NOT NULL,
  `bank_account` varchar(50) NOT NULL,
  `bic` varchar(50) NOT NULL,
  `director_name` varchar(255) NOT NULL,
  `director_position` varchar(255) NOT NULL,
  `contact_phone` varchar(50) NOT NULL,
  `contact_email` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `lxc_metrics`
--

CREATE TABLE `lxc_metrics` (
  `id` int(11) NOT NULL,
  `vm_id` int(11) NOT NULL,
  `timestamp` datetime NOT NULL,
  `cpu_usage` decimal(5,2) NOT NULL COMMENT 'CPU usage in %',
  `mem_usage` decimal(10,2) NOT NULL COMMENT 'Memory usage in GB',
  `mem_total` decimal(10,2) NOT NULL COMMENT 'Total memory in GB',
  `net_in` decimal(10,2) NOT NULL COMMENT 'Network in (Mbit/s)',
  `net_out` decimal(10,2) NOT NULL COMMENT 'Network out (Mbit/s)',
  `disk_read` decimal(10,2) NOT NULL COMMENT 'Disk read (MB/s)',
  `disk_write` decimal(10,2) NOT NULL COMMENT 'Disk write (MB/s)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `metrics_logs`
--

CREATE TABLE `metrics_logs` (
  `id` int(11) NOT NULL,
  `type` enum('info','warning','error') NOT NULL DEFAULT 'info',
  `message` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `node_stats`
--

CREATE TABLE `node_stats` (
  `id` int(11) NOT NULL,
  `node_id` int(11) NOT NULL,
  `cluster_name` varchar(255) NOT NULL,
  `node_name` varchar(255) NOT NULL,
  `cpu_usage` float NOT NULL,
  `ram_usage` float NOT NULL,
  `ram_total` int(11) NOT NULL,
  `network_rx_bytes` bigint(20) NOT NULL,
  `network_tx_bytes` bigint(20) NOT NULL,
  `created_at` datetime NOT NULL,
  `network_rx_mbits` decimal(10,2) DEFAULT 0.00,
  `network_tx_mbits` decimal(10,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(100) NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `password_resets`
--

CREATE TABLE `password_resets` (
  `email` varchar(255) NOT NULL,
  `token` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `payments`
--

CREATE TABLE `payments` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `control_word` varchar(50) DEFAULT NULL,
  `status` enum('pending','completed','failed') DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `payment_method` varchar(20) DEFAULT NULL COMMENT 'Метод оплаты (sbp, card, invoice)',
  `invoice_number` varchar(50) DEFAULT NULL COMMENT 'Номер счета для оплаты',
  `paid_at` datetime DEFAULT NULL COMMENT 'Дата и время оплаты',
  `payment_proof` varchar(255) DEFAULT NULL COMMENT 'Ссылка на подтверждение оплаты'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `payment_info`
--

CREATE TABLE `payment_info` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `card_holder` varchar(100) DEFAULT NULL,
  `card_number` varchar(20) DEFAULT NULL,
  `card_expiry` varchar(10) DEFAULT NULL,
  `card_cvv` varchar(4) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `promotions`
--

CREATE TABLE `promotions` (
  `id` int(11) NOT NULL,
  `title` varchar(100) NOT NULL,
  `description` text NOT NULL,
  `image` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `proxmox_clusters`
--

CREATE TABLE `proxmox_clusters` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `proxmox_nodes`
--

CREATE TABLE `proxmox_nodes` (
  `id` int(11) NOT NULL,
  `cluster_id` int(11) NOT NULL,
  `node_name` varchar(50) NOT NULL,
  `hostname` varchar(255) NOT NULL,
  `api_port` int(11) DEFAULT 8006,
  `username` varchar(100) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `description` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `available_for_users` tinyint(1) DEFAULT 1,
  `is_cluster_master` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Флаг главной ноды кластера',
  `ip` varchar(45) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `proxmox_tickets`
--

CREATE TABLE `proxmox_tickets` (
  `node_id` int(11) NOT NULL,
  `ticket` text NOT NULL,
  `csrf_token` text NOT NULL,
  `expires_at` datetime NOT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `resource_prices`
--

CREATE TABLE `resource_prices` (
  `id` int(11) NOT NULL,
  `price_per_hour_cpu` decimal(10,6) NOT NULL DEFAULT 0.001000,
  `price_per_hour_ram` decimal(10,6) NOT NULL DEFAULT 0.000010,
  `price_per_hour_disk` decimal(10,6) NOT NULL DEFAULT 0.000050,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `price_per_hour_lxc_cpu` decimal(10,6) NOT NULL DEFAULT 0.000800 COMMENT 'Цена за 1 vCPU/час для LXC',
  `price_per_hour_lxc_ram` decimal(10,6) NOT NULL DEFAULT 0.000008 COMMENT 'Цена за 1 MB RAM/час для LXC',
  `price_per_hour_lxc_disk` decimal(10,6) NOT NULL DEFAULT 0.000030 COMMENT 'Цена за 1 GB Disk/час для LXC'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

--
-- Дамп данных таблицы `resource_prices`
--

INSERT INTO `resource_prices` (`id`, `price_per_hour_cpu`, `price_per_hour_ram`, `price_per_hour_disk`, `updated_at`, `price_per_hour_lxc_cpu`, `price_per_hour_lxc_ram`, `price_per_hour_lxc_disk`) VALUES
(1, 0.900000, 0.000300, 0.013500, '2025-04-22 05:28:55', 0.450800, 0.000158, 0.000030);

-- --------------------------------------------------------

--
-- Структура таблицы `tariffs`
--

CREATE TABLE `tariffs` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `cpu` int(11) NOT NULL,
  `ram` int(11) NOT NULL,
  `disk` int(11) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `traffic` varchar(50) DEFAULT NULL,
  `backups` varchar(50) DEFAULT NULL,
  `support` varchar(50) DEFAULT NULL,
  `is_popular` tinyint(1) DEFAULT 0,
  `description` text DEFAULT NULL,
  `os_type` enum('linux','windows') NOT NULL DEFAULT 'linux' COMMENT 'Тип операционной системы: linux или windows',
  `price_per_hour_cpu` decimal(10,4) DEFAULT 0.0000,
  `price_per_hour_ram` decimal(10,4) DEFAULT 0.0000,
  `price_per_hour_disk` decimal(10,4) DEFAULT 0.0000,
  `is_custom` tinyint(1) DEFAULT 0 COMMENT 'Является ли тариф кастомным (с почасовой оплатой)',
  `vm_type` enum('qemu','lxc') NOT NULL DEFAULT 'qemu' COMMENT 'Для какого типа VM предназначен тариф'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

--
-- Дамп данных таблицы `tariffs`
--

INSERT INTO `tariffs` (`id`, `name`, `cpu`, `ram`, `disk`, `price`, `is_active`, `created_at`, `traffic`, `backups`, `support`, `is_popular`, `description`, `os_type`, `price_per_hour_cpu`, `price_per_hour_ram`, `price_per_hour_disk`, `is_custom`, `vm_type`) VALUES
(4, 'Start', 1, 1024, 20, 1300.00, 1, '2025-04-04 02:16:29', '500 GB', 'Еженедельные бэкапы', 'Только критические запросы', 0, '', 'linux', 0.0000, 0.0000, 0.0000, 0, 'qemu'),
(5, 'Basic', 2, 2048, 40, 2500.00, 1, '2025-04-04 02:16:51', '1 ТБ', 'Еженедельные бэкапы', 'Техническая поддержка', 1, '', 'linux', 0.0000, 0.0001, 0.0000, 0, 'qemu'),
(6, 'Pro', 4, 4096, 80, 5100.00, 1, '2025-04-04 02:17:11', '2 ТБ', 'Ежедневные бэкапы', 'Техническая поддержка', 0, '', 'windows', 0.0000, 0.0000, 0.0000, 0, 'qemu'),
(7, 'Business', 8, 16384, 100, 11000.00, 1, '2025-04-04 07:02:29', '3 ТБ', 'Ежедневные бэкапы', 'Премиальная поддержка', 0, '', 'linux', 0.0000, 0.0000, 0.0000, 0, 'qemu'),
(8, 'Ultimate', 6, 32768, 250, 16000.00, 1, '2025-04-08 06:12:27', '5 ТБ', 'Ежедневные бэкапы', 'Премиальная поддержка', 0, '', 'linux', 0.0000, 0.0000, 0.0000, 0, 'qemu'),
(12, 'Свой', 3, 2048, 17, 3073.82, 1, '2025-04-13 11:18:23', '', '', '', 0, '', 'linux', 0.8100, 0.0080, 0.0400, 1, 'qemu'),
(13, 'Свой', 1, 2048, 13, 1606.75, 1, '2025-04-15 04:44:20', '', '', '', 0, '', 'linux', 0.9100, 0.0051, 0.0300, 1, 'qemu'),
(14, 'Свой', 1, 512, 3, 816.91, 1, '2025-04-16 06:34:40', NULL, NULL, NULL, 0, NULL, 'linux', 0.0000, 0.0000, 0.0000, 1, 'qemu'),
(15, 'Promo', 1, 1024, 10, 150.00, 1, '2025-04-16 06:52:01', '100 ГБ', 'Нет', 'Нет', 0, 'Промо тариф', 'linux', 0.1285, 0.0000, 0.0039, 0, 'qemu'),
(17, 'test2', 1, 512, 10, 50.00, 1, '2025-04-20 06:00:30', '', '', '', 0, '', '', 0.0001, 0.0000, 0.0000, 1, 'qemu'),
(20, 'Linux-1-1-10', 1, 1024, 10, 150.00, 1, '2025-04-22 07:06:37', '', '', '', 0, '', '', 0.0300, 0.0005, 0.0200, 1, 'lxc'),
(22, 'Свой', 1, 1536, 15, 499.64, 1, '2025-04-22 09:05:32', NULL, NULL, NULL, 0, NULL, 'linux', 0.0000, 0.0000, 0.0000, 1, 'lxc'),
(26, 'Свой', 1, 1024, 20, 441.50, 1, '2025-04-22 09:56:26', NULL, NULL, NULL, 0, NULL, 'linux', 0.0000, 0.0000, 0.0000, 1, 'lxc'),
(27, 'Свой', 1, 2048, 10, 557.77, 1, '2025-04-27 03:45:03', NULL, NULL, NULL, 0, NULL, 'linux', 0.0000, 0.0000, 0.0000, 1, 'lxc'),
(28, 'Свой', 2, 2048, 20, 882.56, 1, '2025-04-27 08:50:10', NULL, NULL, NULL, 0, NULL, 'linux', 0.0000, 0.0000, 0.0000, 1, 'lxc'),
(34, 'Свой', 2, 2048, 30, 2029.97, 1, '2025-05-01 10:11:42', NULL, NULL, NULL, 0, NULL, 'linux', 0.0000, 0.0000, 0.0000, 1, 'qemu'),
(35, 'Свой', 1, 2048, 15, 557.88, 1, '2025-12-09 04:41:22', NULL, NULL, NULL, 0, NULL, 'linux', 0.0000, 0.0000, 0.0000, 1, 'lxc');

-- --------------------------------------------------------

--
-- Структура таблицы `telegram_conversations`
--

CREATE TABLE `telegram_conversations` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `message_id` varchar(100) DEFAULT NULL,
  `message_text` text DEFAULT NULL,
  `message_type` enum('user','bot') NOT NULL,
  `telegram_id` bigint(20) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `sent_at` timestamp NULL DEFAULT current_timestamp(),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `telegram_message_id` bigint(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `telegram_last_check`
--

CREATE TABLE `telegram_last_check` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `last_message_id` bigint(20) DEFAULT 0,
  `last_check_time` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `telegram_queue`
--

CREATE TABLE `telegram_queue` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `telegram_id` bigint(20) NOT NULL,
  `message_text` text NOT NULL,
  `message_type` enum('user','bot') DEFAULT 'user',
  `status` enum('pending','sent','failed') DEFAULT 'pending',
  `error_message` text DEFAULT NULL,
  `attempts` int(11) DEFAULT 0,
  `max_attempts` int(11) DEFAULT 3,
  `sent_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `tickets`
--

CREATE TABLE `tickets` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `status` enum('open','answered','closed','pending') DEFAULT 'open',
  `priority` enum('low','medium','high','critical') DEFAULT 'medium',
  `department` enum('billing','technical','general') DEFAULT 'general',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `ticket_attachments`
--

CREATE TABLE `ticket_attachments` (
  `id` int(11) NOT NULL,
  `ticket_id` int(11) NOT NULL,
  `reply_id` int(11) DEFAULT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_size` int(11) NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `ticket_replies`
--

CREATE TABLE `ticket_replies` (
  `id` int(11) NOT NULL,
  `ticket_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `is_admin` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `transactions`
--

CREATE TABLE `transactions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `amount` decimal(10,6) NOT NULL,
  `type` enum('credit','debit') NOT NULL,
  `description` varchar(255) NOT NULL,
  `balance_type` enum('main','bonus') NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `metadata` text DEFAULT NULL COMMENT 'Дополнительные метаданные в формате JSON'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `full_name` varchar(100) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `balance` decimal(10,2) DEFAULT 0.00,
  `is_admin` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `user_type` enum('individual','entrepreneur','legal') NOT NULL DEFAULT 'individual',
  `company_name` varchar(255) DEFAULT NULL,
  `inn` varchar(12) DEFAULT NULL,
  `kpp` varchar(9) DEFAULT NULL,
  `email_verified` tinyint(1) NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `bonus_balance` decimal(10,2) DEFAULT 0.00,
  `telegram_id` varchar(50) DEFAULT NULL,
  `telegram_username` varchar(50) DEFAULT NULL,
  `avatar` varchar(255) DEFAULT NULL COMMENT 'URL аватара пользователя',
  `verification_code` varchar(10) DEFAULT NULL,
  `verification_sent_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `user_quotas`
--

CREATE TABLE `user_quotas` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `max_vms` int(11) NOT NULL DEFAULT 3,
  `max_cpu` int(11) NOT NULL DEFAULT 10,
  `max_ram` int(11) NOT NULL DEFAULT 10240,
  `max_disk` int(11) NOT NULL DEFAULT 200,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `user_services`
--

CREATE TABLE `user_services` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `tariff_id` int(11) NOT NULL,
  `vm_id` varchar(50) DEFAULT NULL,
  `status` varchar(20) DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `vms`
--

CREATE TABLE `vms` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL COMMENT 'ID пользователя',
  `vm_id` int(11) NOT NULL COMMENT 'ID виртуальной машины в Proxmox',
  `node_id` int(11) NOT NULL COMMENT 'ID ноды Proxmox',
  `tariff_id` int(11) NOT NULL DEFAULT 0 COMMENT 'ID тарифного плана',
  `hostname` varchar(255) NOT NULL COMMENT 'Имя хоста',
  `cpu` int(11) NOT NULL COMMENT 'Количество ядер CPU',
  `ram` int(11) NOT NULL COMMENT 'Объем RAM (MB)',
  `disk` int(11) NOT NULL COMMENT 'Размер диска (GB)',
  `network` varchar(50) NOT NULL DEFAULT 'vmbr0' COMMENT 'Основной сетевой интерфейс',
  `sdn` varchar(255) DEFAULT NULL COMMENT 'Дополнительная SDN сеть',
  `storage` varchar(255) NOT NULL COMMENT 'Хранилище диска',
  `iso` varchar(255) DEFAULT NULL COMMENT 'ISO образ',
  `status` varchar(20) NOT NULL DEFAULT 'creating' COMMENT 'Статус ВМ',
  `scsihw` varchar(50) NOT NULL DEFAULT 'virtio-scsi-pci' COMMENT 'Тип SCSI контроллера',
  `ostype` varchar(50) NOT NULL DEFAULT 'l26' COMMENT 'Тип ОС',
  `onboot` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Автозагрузка',
  `description` text DEFAULT NULL COMMENT 'Описание ВМ',
  `created_at` timestamp NULL DEFAULT current_timestamp() COMMENT 'Дата создания',
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT 'Дата обновления',
  `os_type` enum('linux','windows') NOT NULL DEFAULT 'linux' COMMENT 'Тип операционной системы виртуальной машины',
  `is_custom` tinyint(1) DEFAULT 0,
  `suspended_at` timestamp NULL DEFAULT NULL,
  `suspend_reason` varchar(255) DEFAULT NULL,
  `last_charged_at` timestamp NULL DEFAULT current_timestamp(),
  `ip_address` varchar(255) DEFAULT NULL COMMENT 'IP адрес виртуальной машины',
  `os_version` varchar(20) DEFAULT NULL COMMENT 'Версия операционной системы (например: 6.x, 11, 2022)',
  `vm_type` enum('qemu','lxc') NOT NULL DEFAULT 'qemu' COMMENT 'Тип виртуальной машины (KVM или LXC)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Виртуальные машины Proxmox';

-- --------------------------------------------------------

--
-- Структура таблицы `vms_admin`
--

CREATE TABLE `vms_admin` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL COMMENT 'ID пользователя',
  `vm_id` int(11) NOT NULL COMMENT 'ID виртуальной машины в Proxmox',
  `node_id` int(11) NOT NULL COMMENT 'ID ноды Proxmox',
  `tariff_id` int(11) NOT NULL DEFAULT 0 COMMENT 'ID тарифного плана',
  `hostname` varchar(255) NOT NULL COMMENT 'Имя хоста',
  `cpu` int(11) NOT NULL COMMENT 'Количество ядер CPU',
  `ram` int(11) NOT NULL COMMENT 'Объем RAM (MB)',
  `disk` int(11) NOT NULL COMMENT 'Размер диска (GB)',
  `network` varchar(50) NOT NULL DEFAULT 'vmbr0' COMMENT 'Основной сетевой интерфейс',
  `sdn` varchar(255) DEFAULT NULL COMMENT 'Дополнительная SDN сеть',
  `storage` varchar(255) NOT NULL COMMENT 'Хранилище диска',
  `iso` varchar(255) DEFAULT NULL COMMENT 'ISO образ',
  `status` varchar(20) NOT NULL DEFAULT 'creating' COMMENT 'Статус ВМ',
  `scsihw` varchar(50) NOT NULL DEFAULT 'virtio-scsi-pci' COMMENT 'Тип SCSI контроллера',
  `ostype` varchar(50) NOT NULL DEFAULT 'l26' COMMENT 'Тип ОС',
  `onboot` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Автозагрузка',
  `description` text DEFAULT NULL COMMENT 'Описание ВМ',
  `created_at` timestamp NULL DEFAULT current_timestamp() COMMENT 'Дата создания',
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT 'Дата обновления',
  `os_type` enum('linux','windows') NOT NULL DEFAULT 'linux' COMMENT 'Тип операционной системы виртуальной машины',
  `is_custom` tinyint(1) DEFAULT 0,
  `suspended_at` timestamp NULL DEFAULT NULL,
  `suspend_reason` varchar(255) DEFAULT NULL,
  `last_charged_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Административные виртуальные машины Proxmox';

-- --------------------------------------------------------

--
-- Структура таблицы `vm_billing`
--

CREATE TABLE `vm_billing` (
  `id` int(11) NOT NULL,
  `vm_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `cpu` int(11) NOT NULL,
  `ram` int(11) NOT NULL,
  `disk` int(11) NOT NULL,
  `price_per_hour_cpu` decimal(10,6) NOT NULL,
  `price_per_hour_ram` decimal(10,6) NOT NULL,
  `price_per_hour_disk` decimal(10,6) NOT NULL,
  `total_per_hour` decimal(10,6) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `vm_metrics`
--

CREATE TABLE `vm_metrics` (
  `id` int(11) NOT NULL,
  `vm_id` int(11) NOT NULL,
  `timestamp` datetime NOT NULL,
  `cpu_usage` decimal(5,2) NOT NULL COMMENT 'CPU usage in %',
  `mem_usage` decimal(10,2) NOT NULL COMMENT 'Memory usage in GB',
  `mem_total` decimal(10,2) NOT NULL COMMENT 'Total memory in GB',
  `net_in` decimal(10,2) NOT NULL COMMENT 'Network in (Mbit/s)',
  `net_out` decimal(10,2) NOT NULL COMMENT 'Network out (Mbit/s)',
  `disk_read` decimal(10,2) NOT NULL COMMENT 'Disk read (MB/s)',
  `disk_write` decimal(10,2) NOT NULL COMMENT 'Disk write (MB/s)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

--
-- Индексы сохранённых таблиц
--

--
-- Индексы таблицы `balance_history`
--
ALTER TABLE `balance_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Индексы таблицы `features`
--
ALTER TABLE `features`
  ADD PRIMARY KEY (`id`);

--
-- Индексы таблицы `legal_entity_info`
--
ALTER TABLE `legal_entity_info`
  ADD PRIMARY KEY (`id`);

--
-- Индексы таблицы `lxc_metrics`
--
ALTER TABLE `lxc_metrics`
  ADD PRIMARY KEY (`id`),
  ADD KEY `vm_id` (`vm_id`),
  ADD KEY `timestamp` (`timestamp`);

--
-- Индексы таблицы `metrics_logs`
--
ALTER TABLE `metrics_logs`
  ADD PRIMARY KEY (`id`);

--
-- Индексы таблицы `node_stats`
--
ALTER TABLE `node_stats`
  ADD PRIMARY KEY (`id`),
  ADD KEY `node_id` (`node_id`,`created_at`);

--
-- Индексы таблицы `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Индексы таблицы `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`email`),
  ADD KEY `token` (`token`);

--
-- Индексы таблицы `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Индексы таблицы `payment_info`
--
ALTER TABLE `payment_info`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Индексы таблицы `promotions`
--
ALTER TABLE `promotions`
  ADD PRIMARY KEY (`id`);

--
-- Индексы таблицы `proxmox_clusters`
--
ALTER TABLE `proxmox_clusters`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Индексы таблицы `proxmox_nodes`
--
ALTER TABLE `proxmox_nodes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `cluster_node` (`cluster_id`,`node_name`);

--
-- Индексы таблицы `proxmox_tickets`
--
ALTER TABLE `proxmox_tickets`
  ADD PRIMARY KEY (`node_id`);

--
-- Индексы таблицы `resource_prices`
--
ALTER TABLE `resource_prices`
  ADD PRIMARY KEY (`id`);

--
-- Индексы таблицы `tariffs`
--
ALTER TABLE `tariffs`
  ADD PRIMARY KEY (`id`);

--
-- Индексы таблицы `telegram_conversations`
--
ALTER TABLE `telegram_conversations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_telegram_message_id` (`telegram_message_id`),
  ADD KEY `idx_user_telegram` (`user_id`,`telegram_id`),
  ADD KEY `idx_message_id` (`message_id`);

--
-- Индексы таблицы `telegram_last_check`
--
ALTER TABLE `telegram_last_check`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- Индексы таблицы `telegram_queue`
--
ALTER TABLE `telegram_queue`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_user_id` (`user_id`);

--
-- Индексы таблицы `tickets`
--
ALTER TABLE `tickets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Индексы таблицы `ticket_attachments`
--
ALTER TABLE `ticket_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ticket_id` (`ticket_id`),
  ADD KEY `reply_id` (`reply_id`);

--
-- Индексы таблицы `ticket_replies`
--
ALTER TABLE `ticket_replies`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ticket_id` (`ticket_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Индексы таблицы `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Индексы таблицы `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Индексы таблицы `user_quotas`
--
ALTER TABLE `user_quotas`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- Индексы таблицы `user_services`
--
ALTER TABLE `user_services`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `tariff_id` (`tariff_id`);

--
-- Индексы таблицы `vms`
--
ALTER TABLE `vms`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_vm_id` (`vm_id`),
  ADD KEY `idx_node_id` (`node_id`),
  ADD KEY `idx_tariff_id` (`tariff_id`),
  ADD KEY `idx_status` (`status`);

--
-- Индексы таблицы `vms_admin`
--
ALTER TABLE `vms_admin`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `vm_node_unique` (`vm_id`,`node_id`);

--
-- Индексы таблицы `vm_billing`
--
ALTER TABLE `vm_billing`
  ADD PRIMARY KEY (`id`),
  ADD KEY `vm_id` (`vm_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Индексы таблицы `vm_metrics`
--
ALTER TABLE `vm_metrics`
  ADD PRIMARY KEY (`id`),
  ADD KEY `vm_id` (`vm_id`),
  ADD KEY `timestamp` (`timestamp`);

--
-- AUTO_INCREMENT для сохранённых таблиц
--

--
-- AUTO_INCREMENT для таблицы `balance_history`
--
ALTER TABLE `balance_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `features`
--
ALTER TABLE `features`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `legal_entity_info`
--
ALTER TABLE `legal_entity_info`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `lxc_metrics`
--
ALTER TABLE `lxc_metrics`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `metrics_logs`
--
ALTER TABLE `metrics_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `node_stats`
--
ALTER TABLE `node_stats`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `payment_info`
--
ALTER TABLE `payment_info`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `promotions`
--
ALTER TABLE `promotions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `proxmox_clusters`
--
ALTER TABLE `proxmox_clusters`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `proxmox_nodes`
--
ALTER TABLE `proxmox_nodes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `resource_prices`
--
ALTER TABLE `resource_prices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT для таблицы `tariffs`
--
ALTER TABLE `tariffs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=36;

--
-- AUTO_INCREMENT для таблицы `telegram_conversations`
--
ALTER TABLE `telegram_conversations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `telegram_last_check`
--
ALTER TABLE `telegram_last_check`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `telegram_queue`
--
ALTER TABLE `telegram_queue`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `tickets`
--
ALTER TABLE `tickets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `ticket_attachments`
--
ALTER TABLE `ticket_attachments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `ticket_replies`
--
ALTER TABLE `ticket_replies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `transactions`
--
ALTER TABLE `transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `user_quotas`
--
ALTER TABLE `user_quotas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `user_services`
--
ALTER TABLE `user_services`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `vms`
--
ALTER TABLE `vms`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `vms_admin`
--
ALTER TABLE `vms_admin`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `vm_billing`
--
ALTER TABLE `vm_billing`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `vm_metrics`
--
ALTER TABLE `vm_metrics`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Ограничения внешнего ключа сохраненных таблиц
--

--
-- Ограничения внешнего ключа таблицы `balance_history`
--
ALTER TABLE `balance_history`
  ADD CONSTRAINT `balance_history_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Ограничения внешнего ключа таблицы `node_stats`
--
ALTER TABLE `node_stats`
  ADD CONSTRAINT `node_stats_ibfk_1` FOREIGN KEY (`node_id`) REFERENCES `proxmox_nodes` (`id`);

--
-- Ограничения внешнего ключа таблицы `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Ограничения внешнего ключа таблицы `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Ограничения внешнего ключа таблицы `payment_info`
--
ALTER TABLE `payment_info`
  ADD CONSTRAINT `payment_info_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `proxmox_nodes`
--
ALTER TABLE `proxmox_nodes`
  ADD CONSTRAINT `fk_cluster` FOREIGN KEY (`cluster_id`) REFERENCES `proxmox_clusters` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `proxmox_tickets`
--
ALTER TABLE `proxmox_tickets`
  ADD CONSTRAINT `proxmox_tickets_ibfk_1` FOREIGN KEY (`node_id`) REFERENCES `proxmox_nodes` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `telegram_conversations`
--
ALTER TABLE `telegram_conversations`
  ADD CONSTRAINT `telegram_conversations_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `telegram_last_check`
--
ALTER TABLE `telegram_last_check`
  ADD CONSTRAINT `telegram_last_check_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `tickets`
--
ALTER TABLE `tickets`
  ADD CONSTRAINT `tickets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `ticket_attachments`
--
ALTER TABLE `ticket_attachments`
  ADD CONSTRAINT `ticket_attachments_ibfk_1` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `ticket_attachments_ibfk_2` FOREIGN KEY (`reply_id`) REFERENCES `ticket_replies` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `ticket_replies`
--
ALTER TABLE `ticket_replies`
  ADD CONSTRAINT `ticket_replies_ibfk_1` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `ticket_replies_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `transactions`
--
ALTER TABLE `transactions`
  ADD CONSTRAINT `transactions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Ограничения внешнего ключа таблицы `user_quotas`
--
ALTER TABLE `user_quotas`
  ADD CONSTRAINT `user_quotas_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `user_services`
--
ALTER TABLE `user_services`
  ADD CONSTRAINT `user_services_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `user_services_ibfk_2` FOREIGN KEY (`tariff_id`) REFERENCES `tariffs` (`id`);

--
-- Ограничения внешнего ключа таблицы `vm_billing`
--
ALTER TABLE `vm_billing`
  ADD CONSTRAINT `vm_billing_ibfk_1` FOREIGN KEY (`vm_id`) REFERENCES `vms` (`vm_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `vm_billing_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
