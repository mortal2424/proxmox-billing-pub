-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Хост: localhost
-- Время создания: Апр 20 2025 г., 14:11
-- Версия сервера: 8.0.41-0ubuntu0.24.04.1
-- Версия PHP: 7.4.33

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- База данных: `anna_eosrus_`
--

-- --------------------------------------------------------

--
-- Структура таблицы `balance_history`
--

CREATE TABLE `balance_history` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `operation_type` enum('payment','bonus','withdrawal','correction') NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Структура таблицы `features`
--

CREATE TABLE `features` (
  `id` int NOT NULL,
  `title` varchar(100) NOT NULL,
  `description` text NOT NULL,
  `icon` varchar(50) NOT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Структура таблицы `legal_entity_info`
--

CREATE TABLE `legal_entity_info` (
  `id` int NOT NULL,
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
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Структура таблицы `node_stats`
--

CREATE TABLE `node_stats` (
  `id` int NOT NULL,
  `node_id` int NOT NULL,
  `cluster_name` varchar(255) NOT NULL,
  `node_name` varchar(255) NOT NULL,
  `cpu_usage` float NOT NULL,
  `ram_usage` float NOT NULL,
  `ram_total` int NOT NULL,
  `network_rx_bytes` bigint NOT NULL,
  `network_tx_bytes` bigint NOT NULL,
  `created_at` datetime NOT NULL,
  `network_rx_mbits` decimal(10,2) DEFAULT '0.00',
  `network_tx_mbits` decimal(10,2) DEFAULT '0.00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Структура таблицы `notifications`
--

CREATE TABLE `notifications` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `title` varchar(100) NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Структура таблицы `password_resets`
--

CREATE TABLE `password_resets` (
  `email` varchar(255) NOT NULL,
  `token` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Структура таблицы `payments`
--

CREATE TABLE `payments` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `control_word` varchar(50) DEFAULT NULL,
  `status` enum('pending','completed','failed') DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `payment_method` varchar(20) DEFAULT NULL COMMENT 'Метод оплаты (sbp, card, invoice)',
  `invoice_number` varchar(50) DEFAULT NULL COMMENT 'Номер счета для оплаты',
  `paid_at` datetime DEFAULT NULL COMMENT 'Дата и время оплаты',
  `payment_proof` varchar(255) DEFAULT NULL COMMENT 'Ссылка на подтверждение оплаты'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Структура таблицы `payment_info`
--

CREATE TABLE `payment_info` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `card_holder` varchar(100) DEFAULT NULL,
  `card_number` varchar(20) DEFAULT NULL,
  `card_expiry` varchar(10) DEFAULT NULL,
  `card_cvv` varchar(4) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Структура таблицы `promotions`
--

CREATE TABLE `promotions` (
  `id` int NOT NULL,
  `title` varchar(100) NOT NULL,
  `description` text NOT NULL,
  `image` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Структура таблицы `proxmox_clusters`
--

CREATE TABLE `proxmox_clusters` (
  `id` int NOT NULL,
  `name` varchar(50) NOT NULL,
  `description` text,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `proxmox_nodes`
--

CREATE TABLE `proxmox_nodes` (
  `id` int NOT NULL,
  `cluster_id` int NOT NULL,
  `node_name` varchar(50) NOT NULL,
  `hostname` varchar(255) NOT NULL,
  `api_port` int DEFAULT '8006',
  `username` varchar(100) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `description` text,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `available_for_users` tinyint(1) DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `proxmox_tickets`
--

CREATE TABLE `proxmox_tickets` (
  `id` int NOT NULL,
  `node_id` int NOT NULL,
  `ticket` text NOT NULL,
  `csrf_token` text NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Структура таблицы `resource_prices`
--

CREATE TABLE `resource_prices` (
  `id` int NOT NULL,
  `price_per_hour_cpu` decimal(10,6) NOT NULL DEFAULT '0.001000',
  `price_per_hour_ram` decimal(10,6) NOT NULL DEFAULT '0.000010',
  `price_per_hour_disk` decimal(10,6) NOT NULL DEFAULT '0.000050',
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Структура таблицы `tariffs`
--

CREATE TABLE `tariffs` (
  `id` int NOT NULL,
  `name` varchar(100) NOT NULL,
  `cpu` int NOT NULL,
  `ram` int NOT NULL,
  `disk` int NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `traffic` varchar(50) DEFAULT NULL,
  `backups` varchar(50) DEFAULT NULL,
  `support` varchar(50) DEFAULT NULL,
  `is_popular` tinyint(1) DEFAULT '0',
  `description` text,
  `os_type` enum('linux','windows') NOT NULL DEFAULT 'linux' COMMENT 'Тип операционной системы: linux или windows',
  `price_per_hour_cpu` decimal(10,4) DEFAULT '0.0000',
  `price_per_hour_ram` decimal(10,4) DEFAULT '0.0000',
  `price_per_hour_disk` decimal(10,4) DEFAULT '0.0000',
  `is_custom` tinyint(1) DEFAULT '0' COMMENT 'Является ли тариф кастомным (с почасовой оплатой)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Структура таблицы `tickets`
--

CREATE TABLE `tickets` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `subject` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `status` enum('open','answered','closed','pending') DEFAULT 'open',
  `priority` enum('low','medium','high','critical') DEFAULT 'medium',
  `department` enum('billing','technical','general') DEFAULT 'general',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Структура таблицы `ticket_attachments`
--

CREATE TABLE `ticket_attachments` (
  `id` int NOT NULL,
  `ticket_id` int NOT NULL,
  `reply_id` int DEFAULT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_size` int NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Структура таблицы `ticket_replies`
--

CREATE TABLE `ticket_replies` (
  `id` int NOT NULL,
  `ticket_id` int NOT NULL,
  `user_id` int NOT NULL,
  `message` text NOT NULL,
  `is_admin` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Структура таблицы `transactions`
--

CREATE TABLE `transactions` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `amount` decimal(10,6) NOT NULL,
  `type` enum('credit','debit') NOT NULL,
  `description` varchar(255) NOT NULL,
  `balance_type` enum('main','bonus') NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Структура таблицы `users`
--

CREATE TABLE `users` (
  `id` int NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `full_name` varchar(100) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `balance` decimal(10,2) DEFAULT '0.00',
  `is_admin` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `user_type` enum('individual','entrepreneur','legal') NOT NULL DEFAULT 'individual',
  `company_name` varchar(255) DEFAULT NULL,
  `inn` varchar(12) DEFAULT NULL,
  `kpp` varchar(9) DEFAULT NULL,
  `email_verified` tinyint(1) NOT NULL DEFAULT '0',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `bonus_balance` decimal(10,2) DEFAULT '0.00',
  `telegram_id` varchar(50) DEFAULT NULL,
  `telegram_username` varchar(50) DEFAULT NULL,
  `avatar` varchar(255) DEFAULT NULL COMMENT 'URL аватара пользователя'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Структура таблицы `user_quotas`
--

CREATE TABLE `user_quotas` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `max_vms` int NOT NULL DEFAULT '3',
  `max_cpu` int NOT NULL DEFAULT '10',
  `max_ram` int NOT NULL DEFAULT '10240',
  `max_disk` int NOT NULL DEFAULT '200',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Структура таблицы `user_services`
--

CREATE TABLE `user_services` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `tariff_id` int NOT NULL,
  `vm_id` varchar(50) DEFAULT NULL,
  `status` varchar(20) DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `vms`
--

CREATE TABLE `vms` (
  `id` int NOT NULL,
  `user_id` int NOT NULL COMMENT 'ID пользователя',
  `vm_id` int NOT NULL COMMENT 'ID виртуальной машины в Proxmox',
  `node_id` int NOT NULL COMMENT 'ID ноды Proxmox',
  `tariff_id` int NOT NULL DEFAULT '0' COMMENT 'ID тарифного плана',
  `hostname` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Имя хоста',
  `cpu` int NOT NULL COMMENT 'Количество ядер CPU',
  `ram` int NOT NULL COMMENT 'Объем RAM (MB)',
  `disk` int NOT NULL COMMENT 'Размер диска (GB)',
  `network` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'vmbr0' COMMENT 'Основной сетевой интерфейс',
  `sdn` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Дополнительная SDN сеть',
  `storage` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Хранилище диска',
  `iso` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'ISO образ',
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'creating' COMMENT 'Статус ВМ',
  `scsihw` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'virtio-scsi-pci' COMMENT 'Тип SCSI контроллера',
  `ostype` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'l26' COMMENT 'Тип ОС',
  `onboot` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Автозагрузка',
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Описание ВМ',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Дата создания',
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Дата обновления',
  `os_type` enum('linux','windows') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'linux' COMMENT 'Тип операционной системы виртуальной машины',
  `is_custom` tinyint(1) DEFAULT '0',
  `suspended_at` timestamp NULL DEFAULT NULL,
  `suspend_reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_charged_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Виртуальные машины Proxmox';

-- --------------------------------------------------------

--
-- Структура таблицы `vm_billing`
--

CREATE TABLE `vm_billing` (
  `id` int NOT NULL,
  `vm_id` int NOT NULL,
  `user_id` int NOT NULL,
  `cpu` int NOT NULL,
  `ram` int NOT NULL,
  `disk` int NOT NULL,
  `price_per_hour_cpu` decimal(10,6) NOT NULL,
  `price_per_hour_ram` decimal(10,6) NOT NULL,
  `price_per_hour_disk` decimal(10,6) NOT NULL,
  `total_per_hour` decimal(10,6) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

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
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_proxmox_tickets_node` (`node_id`),
  ADD KEY `idx_proxmox_tickets_expires` (`expires_at`);

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
-- Индексы таблицы `vm_billing`
--
ALTER TABLE `vm_billing`
  ADD PRIMARY KEY (`id`),
  ADD KEY `vm_id` (`vm_id`),
  ADD KEY `user_id` (`user_id`);

--
-- AUTO_INCREMENT для сохранённых таблиц
--

--
-- AUTO_INCREMENT для таблицы `balance_history`
--
ALTER TABLE `balance_history`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `features`
--
ALTER TABLE `features`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `legal_entity_info`
--
ALTER TABLE `legal_entity_info`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `node_stats`
--
ALTER TABLE `node_stats`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `payment_info`
--
ALTER TABLE `payment_info`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `promotions`
--
ALTER TABLE `promotions`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `proxmox_clusters`
--
ALTER TABLE `proxmox_clusters`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT для таблицы `proxmox_nodes`
--
ALTER TABLE `proxmox_nodes`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT для таблицы `proxmox_tickets`
--
ALTER TABLE `proxmox_tickets`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT для таблицы `resource_prices`
--
ALTER TABLE `resource_prices`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `tariffs`
--
ALTER TABLE `tariffs`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT для таблицы `tickets`
--
ALTER TABLE `tickets`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT для таблицы `ticket_attachments`
--
ALTER TABLE `ticket_attachments`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `ticket_replies`
--
ALTER TABLE `ticket_replies`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT для таблицы `transactions`
--
ALTER TABLE `transactions`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `users`
--
ALTER TABLE `users`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `user_quotas`
--
ALTER TABLE `user_quotas`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `user_services`
--
ALTER TABLE `user_services`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `vms`
--
ALTER TABLE `vms`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `vm_billing`
--
ALTER TABLE `vm_billing`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

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
