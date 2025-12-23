-- Тестовый дамп таблицы backup_logs
CREATE TABLE `backup_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `filename` varchar(255) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `backup_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `backup_logs` (`id`, `user_id`, `action`, `filename`, `details`, `ip_address`, `created_at`) VALUES
('2', '1', 'create', 'backup_2025-12-15_14-54-02_full.zip', '', '95.154.74.31', '2025-12-15 14:54:17'),
('3', '1', 'create', 'backup_2025-12-15_14-54-37_full.zip', '', '95.154.74.31', '2025-12-15 14:54:52'),
('5', '1', 'create', 'backup_2025-12-15_15-22-39_full.zip', '', '95.154.74.31', '2025-12-15 15:22:55'),
('6', '1', 'create', 'backup_2025-12-15_15-22-39_full.zip', 'Тип: full, Комментарий: ', '95.154.74.31', '2025-12-15 15:22:55'),
('7', '1', 'upload', 'backup_2025-12-15_10-36-15_files.zip', 'Загружен через веб-интерфейс', '95.154.74.31', '2025-12-15 15:24:29');
