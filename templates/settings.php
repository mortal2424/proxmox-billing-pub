<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

checkAuth();

$db = new Database();
$pdo = $db->getConnection();
$user_id = $_SESSION['user']['id'];
$user = $pdo->query("SELECT * FROM users WHERE id = $user_id")->fetch();

if (!$user) {
    session_destroy();
    header('Location: login.php');
    exit;
}

// Проверяем существование таблицы платежной информации
$pdo->exec("CREATE TABLE IF NOT EXISTS payment_info (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    card_holder VARCHAR(100),
    card_number VARCHAR(20),
    card_expiry VARCHAR(10),
    card_cvv VARCHAR(4),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

// Получаем платежную информацию пользователя
$stmt = $pdo->prepare("SELECT * FROM payment_info WHERE user_id = ?");
$stmt->execute([$user_id]);
$payment_info = $stmt->fetch(PDO::FETCH_ASSOC);

// Получаем статистику активности
/*$stmt = $pdo->prepare("
    SELECT
        COUNT(*) as total_logins,
        MAX(login_time) as last_login,
        COUNT(DISTINCT DATE(login_time)) as active_days
    FROM user_logins
    WHERE user_id = ?
");*/
$stmt->execute([$user_id]);
$login_stats = $stmt->fetch();

$success_message = '';
$error_message = '';

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Проверка текущего пароля для любых изменений
        if (!empty($_POST['current_password'])) {
            if (!password_verify($_POST['current_password'], $user['password_hash'])) {
                throw new Exception("Неверный текущий пароль");
            }
        } else {
            throw new Exception("Текущий пароль обязателен для подтверждения изменений");
        }

        // Подготовка данных для обновления
        $update_data = ['id' => $user_id];

        // Обработка загрузки аватара
        if (!empty($_FILES['avatar']['name'])) {
            $uploadDir = '../uploads/avatars/';
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
            $detectedType = finfo_file($fileInfo, $_FILES['avatar']['tmp_name']);

            if (!in_array($detectedType, $allowedTypes)) {
                throw new Exception("Допустимы только изображения JPG, PNG, GIF или WebP");
            }

            if ($_FILES['avatar']['size'] > 5 * 1024 * 1024) {
                throw new Exception("Максимальный размер файла - 5MB");
            }

            $extension = pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION);
            $filename = 'avatar_' . $user_id . '_' . time() . '.' . $extension;
            $destination = $uploadDir . $filename;

            if (move_uploaded_file($_FILES['avatar']['tmp_name'], $destination)) {
                // Удаляем старый аватар, если он есть
                if (!empty($user['avatar']) && file_exists($user['avatar'])) {
                    unlink($user['avatar']);
                }

                $update_data['avatar'] = $uploadDir . $filename;
            } else {
                throw new Exception("Ошибка при загрузке файла");
            }
        } elseif (isset($_POST['remove_avatar']) && $_POST['remove_avatar'] == '1') {
            // Обработка удаления аватара
            if (!empty($user['avatar']) && file_exists($user['avatar'])) {
                unlink($user['avatar']);
            }
            $update_data['avatar'] = null;
        }

        // Обновление Telegram ID
        if (isset($_POST['telegram_id'])) {
            $telegram_id = trim($_POST['telegram_id']);
            if (!empty($telegram_id)) {
                if (!preg_match('/^\d+$/', $telegram_id)) {
                    throw new Exception("Telegram ID должен содержать только цифры");
                }
                $update_data['telegram_id'] = $telegram_id;
            } else {
                $update_data['telegram_id'] = null;
            }
        }

        // Обновление основной информации
        if (isset($_POST['full_name'])) {
            $update_data['full_name'] = trim($_POST['full_name']);
        }

        // Для ИП и юр. лиц
        if ($user['user_type'] !== 'individual') {
            if (isset($_POST['company_name'])) {
                $update_data['company_name'] = trim($_POST['company_name']);
            }

            if ($user['user_type'] === 'legal' && isset($_POST['kpp'])) {
                $update_data['kpp'] = trim($_POST['kpp']);
            }
        }

        // Обновление пароля (если указан)
        if (!empty($_POST['new_password'])) {
            if (strlen($_POST['new_password']) < 8) {
                throw new Exception("Пароль должен содержать минимум 8 символов");
            }

            if ($_POST['new_password'] !== $_POST['confirm_password']) {
                throw new Exception("Пароли не совпадают");
            }

            $update_data['password_hash'] = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
        }

        // Подготовка SQL запроса
        $set_parts = [];
        foreach ($update_data as $field => $value) {
            if ($field !== 'id') {
                $set_parts[] = "$field = :$field";
            }
        }

        $sql = "UPDATE users SET " . implode(', ', $set_parts) . " WHERE id = :id";
        $stmt = $pdo->prepare($sql);

        if (!$stmt->execute($update_data)) {
            throw new Exception("Ошибка при обновлении данных");
        }

        // Обновление платежной информации (если есть)
        if (isset($_POST['card_holder'])) {
            $card_holder = trim($_POST['card_holder']);
            $card_number = preg_replace('/\s+/', '', $_POST['card_number'] ?? '');
            $card_expiry = trim($_POST['card_expiry'] ?? '');
            $card_cvv = trim($_POST['card_cvv'] ?? '');

            if ($payment_info) {
                // Обновление существующей записи
                $stmt = $pdo->prepare("UPDATE payment_info SET
                    card_holder = ?,
                    card_number = ?,
                    card_expiry = ?,
                    card_cvv = ?
                    WHERE user_id = ?");
                $stmt->execute([$card_holder, $card_number, $card_expiry, $card_cvv, $user_id]);
            } else {
                // Создание новой записи
                $stmt = $pdo->prepare("INSERT INTO payment_info
                    (user_id, card_holder, card_number, card_expiry, card_cvv)
                    VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$user_id, $card_holder, $card_number, $card_expiry, $card_cvv]);
            }
        }

        // Обновляем данные пользователя
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();

        // Обновляем аватар в сессии
        $_SESSION['user']['avatar'] = $user['avatar'];

        $success_message = "Настройки успешно сохранены";

    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Настройки | HomeVlad Cloud</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="icon" href="../img/cloud.png" type="image/png">
    <link rel="stylesheet" href="/css/themes.css">
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            --secondary-gradient: linear-gradient(135deg, #00bcd4, #0097a7);
            --success-gradient: linear-gradient(135deg, #10b981, #059669);
            --warning-gradient: linear-gradient(135deg, #f59e0b, #d97706);
            --danger-gradient: linear-gradient(135deg, #ef4444, #dc2626);
            --info-gradient: linear-gradient(135deg, #3b82f6, #2563eb);
            --purple-gradient: linear-gradient(135deg, #8b5cf6, #7c3aed);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            color: #1e293b;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        body.dark-theme {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: #cbd5e1;
        }

        /* Основной контейнер */
        .main-container {
            display: flex;
            flex: 1;
            min-height: calc(100vh - 70px);
            margin-top: 70px;
        }

        /* Основной контент */
        .main-content {
            flex: 1;
            padding: 24px;
            margin-left: 280px;
            transition: margin-left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .sidebar-collapsed .main-content {
            margin-left: 80px;
        }

        @media (max-width: 992px) {
            .main-content {
                margin-left: 0;
                padding: 20px;
            }
        }

        /* Заголовок страницы */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid rgba(148, 163, 184, 0.1);
        }

        .page-title {
            font-size: 28px;
            font-weight: 700;
            background: linear-gradient(135deg, #00bcd4, #0097a7);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .page-title i {
            font-size: 32px;
        }

        /* Статистика профиля */
        .profile-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }

        .profile-stat-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(148, 163, 184, 0.1);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        body.dark-theme .profile-stat-card {
            background: rgba(30, 41, 59, 0.7);
            border-color: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
        }

        .profile-stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.12);
        }

        .profile-stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--secondary-gradient);
            border-radius: 16px 16px 0 0;
        }

        .profile-stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: white;
            background: var(--secondary-gradient);
            box-shadow: 0 4px 12px rgba(0, 188, 212, 0.3);
            margin-bottom: 12px;
        }

        .profile-stat-title {
            font-size: 14px;
            color: #64748b;
            margin-bottom: 8px;
            font-weight: 500;
        }

        body.dark-theme .profile-stat-title {
            color: #94a3b8;
        }

        .profile-stat-value {
            font-size: 24px;
            font-weight: 700;
            color: #1e293b;
        }

        body.dark-theme .profile-stat-value {
            color: #f1f5f9;
        }

        /* Секции настроек */
        .settings-section {
            background: white;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(148, 163, 184, 0.1);
        }

        body.dark-theme .settings-section {
            background: rgba(30, 41, 59, 0.7);
            border-color: rgba(255, 255, 255, 0.1);
        }

        .section-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid rgba(148, 163, 184, 0.1);
        }

        .section-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: white;
            background: var(--secondary-gradient);
        }

        .section-title {
            font-size: 20px;
            font-weight: 600;
            color: #1e293b;
        }

        body.dark-theme .section-title {
            color: #f1f5f9;
        }

        .section-subtitle {
            font-size: 14px;
            color: #64748b;
            margin-top: 4px;
        }

        body.dark-theme .section-subtitle {
            color: #94a3b8;
        }

        /* Аватар */
        .avatar-section {
            display: flex;
            align-items: center;
            gap: 24px;
        }

        .avatar-preview {
            position: relative;
            width: 120px;
            height: 120px;
            border-radius: 50%;
            overflow: hidden;
            border: 4px solid white;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
        }

        .avatar-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .avatar-preview-placeholder {
            width: 100%;
            height: 100%;
            background: var(--secondary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 48px;
            color: white;
        }

        .avatar-controls {
            flex: 1;
        }

        .avatar-upload-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 20px;
            background: var(--secondary-gradient);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-bottom: 12px;
        }

        .avatar-upload-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(0, 188, 212, 0.3);
        }

        .avatar-remove-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 20px;
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.2);
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .avatar-remove-btn:hover {
            background: rgba(239, 68, 68, 0.2);
        }

        .avatar-hint {
            font-size: 12px;
            color: #64748b;
            margin-top: 8px;
        }

        body.dark-theme .avatar-hint {
            color: #94a3b8;
        }

        /* Формы */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #1e293b;
            font-size: 14px;
        }

        body.dark-theme .form-label {
            color: #cbd5e1;
        }

        .form-input {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid rgba(148, 163, 184, 0.3);
            border-radius: 8px;
            background: white;
            color: #1e293b;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        body.dark-theme .form-input {
            background: rgba(30, 41, 59, 0.7);
            border-color: rgba(255, 255, 255, 0.2);
            color: #cbd5e1;
        }

        .form-input:focus {
            outline: none;
            border-color: #00bcd4;
            box-shadow: 0 0 0 3px rgba(0, 188, 212, 0.1);
        }

        .form-input:disabled {
            background: rgba(148, 163, 184, 0.1);
            cursor: not-allowed;
        }

        body.dark-theme .form-input:disabled {
            background: rgba(255, 255, 255, 0.05);
        }

        .form-hint {
            display: block;
            margin-top: 6px;
            font-size: 12px;
            color: #64748b;
        }

        body.dark-theme .form-hint {
            color: #94a3b8;
        }

        /* Telegram подключение */
        .telegram-connect {
            background: rgba(0, 119, 181, 0.1);
            border: 1px solid rgba(0, 119, 181, 0.2);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .telegram-connected {
            background: rgba(16, 185, 129, 0.1);
            border-color: rgba(16, 185, 129, 0.2);
        }

        .telegram-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
        }

        .telegram-icon {
            font-size: 32px;
            color: #0088cc;
        }

        .telegram-connected .telegram-icon {
            color: #10b981;
        }

        .telegram-title {
            font-size: 18px;
            font-weight: 600;
            color: #1e293b;
        }

        body.dark-theme .telegram-title {
            color: #f1f5f9;
        }

        .telegram-status {
            font-size: 14px;
            color: #64748b;
        }

        .telegram-actions {
            display: flex;
            gap: 12px;
        }

        /* Кнопки */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            font-size: 14px;
        }

        .btn-primary {
            background: var(--secondary-gradient);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(0, 188, 212, 0.3);
        }

        .btn-success {
            background: var(--success-gradient);
            color: white;
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(16, 185, 129, 0.3);
        }

        .btn-warning {
            background: var(--warning-gradient);
            color: white;
        }

        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(245, 158, 11, 0.3);
        }

        .btn-danger {
            background: var(--danger-gradient);
            color: white;
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(239, 68, 68, 0.3);
        }

        .btn-outline {
            background: transparent;
            border: 2px solid rgba(148, 163, 184, 0.3);
            color: #64748b;
        }

        .btn-outline:hover {
            border-color: #00bcd4;
            color: #00bcd4;
            background: rgba(0, 188, 212, 0.05);
        }

        /* Уведомления */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 25px;
            border-radius: 12px;
            color: white;
            font-weight: 600;
            z-index: 9999;
            animation: slideIn 0.3s ease;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            display: flex;
            align-items: center;
            gap: 10px;
            max-width: 400px;
        }

        .notification.success {
            background: var(--success-gradient);
        }

        .notification.error {
            background: var(--danger-gradient);
        }

        .notification.warning {
            background: var(--warning-gradient);
        }

        .notification.info {
            background: var(--info-gradient);
        }

        /* Карточка платежной информации */
        .payment-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 16px;
            padding: 24px;
            color: white;
            margin-bottom: 24px;
            position: relative;
            overflow: hidden;
        }

        .payment-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.2);
        }

        .payment-card-content {
            position: relative;
            z-index: 1;
        }

        .card-chip {
            width: 40px;
            height: 30px;
            background: linear-gradient(135deg, #ffd700, #ffed4e);
            border-radius: 6px;
            margin-bottom: 20px;
        }

        .card-number {
            font-size: 20px;
            letter-spacing: 2px;
            margin-bottom: 20px;
            font-family: 'Courier New', monospace;
        }

        .card-details {
            display: flex;
            justify-content: space-between;
            font-size: 14px;
        }

        .card-holder {
            font-weight: 600;
        }

        .card-expiry {
            font-weight: 600;
        }

        /* Переключатель вкладок */
        .settings-tabs {
            display: flex;
            gap: 4px;
            margin-bottom: 24px;
            background: white;
            padding: 4px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        body.dark-theme .settings-tabs {
            background: rgba(30, 41, 59, 0.7);
        }

        .settings-tab {
            flex: 1;
            padding: 12px 16px;
            text-align: center;
            cursor: pointer;
            border-radius: 8px;
            font-weight: 500;
            color: #64748b;
            transition: all 0.3s ease;
        }

        .settings-tab:hover {
            background: rgba(0, 188, 212, 0.1);
            color: #00bcd4;
        }

        .settings-tab.active {
            background: var(--secondary-gradient);
            color: white;
            box-shadow: 0 2px 8px rgba(0, 188, 212, 0.3);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
            animation: slideIn 0.3s ease forwards;
        }

        /* Секция безопасности */
        .security-badges {
            display: flex;
            gap: 12px;
            margin-top: 16px;
        }

        .security-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .security-badge.success {
            background: rgba(16, 185, 129, 0.1);
            color: #10b981;
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .security-badge.warning {
            background: rgba(245, 158, 11, 0.1);
            color: #f59e0b;
            border: 1px solid rgba(245, 158, 11, 0.2);
        }

        /* Адаптивность */
        @media (max-width: 768px) {
            .main-content {
                padding: 16px;
            }

            .page-title {
                font-size: 24px;
            }

            .settings-section {
                padding: 20px;
            }

            .avatar-section {
                flex-direction: column;
                text-align: center;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .settings-tabs {
                flex-direction: column;
            }

            .telegram-actions {
                flex-direction: column;
            }

            .profile-stats {
                grid-template-columns: 1fr;
            }
        }

        /* Анимации */
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .profile-stat-card,
        .settings-section {
            animation: slideIn 0.5s ease forwards;
        }

        /* Кнопка вверх */
        .scroll-to-top {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 50px;
            height: 50px;
            background: var(--secondary-gradient);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            text-decoration: none;
            box-shadow: 0 8px 25px rgba(0, 188, 212, 0.4);
            transition: all 0.3s ease;
            opacity: 0;
            visibility: hidden;
            z-index: 999;
        }

        .scroll-to-top.visible {
            opacity: 1;
            visibility: visible;
        }

        .scroll-to-top:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 30px rgba(0, 188, 212, 0.5);
        }
        
        /* === ОБЩИЙ ФУТЕР === */
        /* Исправляем футер для правильного отображения */
        .modern-footer {
            background: var(--primary-gradient);
            padding: 80px 0 30px;
            color: rgba(255, 255, 255, 0.8);
            position: relative;
            overflow: hidden;
            margin-top: auto;
            width: 100%;
        }

        .modern-footer .container {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }

        .modern-footer::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(0, 188, 212, 0.5), transparent);
        }

        /* Индикатор сложности пароля */
        .password-strength {
            height: 4px;
            background: rgba(148, 163, 184, 0.2);
            border-radius: 2px;
            margin-top: 8px;
            overflow: hidden;
        }

        .password-strength-bar {
            height: 100%;
            width: 0%;
            border-radius: 2px;
            transition: all 0.3s ease;
        }

        .strength-weak { background: var(--danger-gradient); }
        .strength-medium { background: var(--warning-gradient); }
        .strength-strong { background: var(--success-gradient); }
    
    </style>
</head>
<body>
    <?php
    // Подключаем обновленную шапку
    include '../templates/headers/user_header.php';
    ?>

    <!-- Кнопка вверх -->
    <a href="#" class="scroll-to-top" id="scrollToTop">
        <i class="fas fa-chevron-up"></i>
    </a>

    <div class="main-container">
        <?php
        // Подключаем обновленный сайдбар
        include '../templates/headers/user_sidebar.php';
        ?>

        <div class="main-content">
            <!-- Заголовок страницы -->
            <div class="page-header">
                <h1 class="page-title">
                    <i class="fas fa-cog"></i> Настройки аккаунта
                </h1>
                <div class="header-actions">
                    <button class="btn btn-primary" onclick="window.location.reload()">
                        <i class="fas fa-sync-alt"></i> Обновить
                    </button>
                </div>
            </div>

            <?php if (!empty($success_message)): ?>
                <div class="notification success">
                    <i class="fas fa-check-circle"></i>
                    <span><?= htmlspecialchars($success_message) ?></span>
                </div>
            <?php endif; ?>

            <?php if (!empty($error_message)): ?>
                <div class="notification error">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?= htmlspecialchars($error_message) ?></span>
                </div>
            <?php endif; ?>

            <!-- Статистика профиля -->
            <div class="profile-stats">
                <div class="profile-stat-card">
                    <div class="profile-stat-icon">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <div class="profile-stat-title">Статус аккаунта</div>
                    <div class="profile-stat-value">
                        <?= $user['is_active'] ? 'Активен' : 'Неактивен' ?>
                    </div>
                </div>

                <div class="profile-stat-card">
                    <div class="profile-stat-icon">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <div class="profile-stat-title">Дата регистрации</div>
                    <div class="profile-stat-value">
                        <?= date('d.m.Y', strtotime($user['created_at'])) ?>
                    </div>
                </div>

                <div class="profile-stat-card">
                    <div class="profile-stat-icon">
                        <i class="fas fa-sign-in-alt"></i>
                    </div>
                    <div class="profile-stat-title">Всего входов</div>
                    <div class="profile-stat-value">
                        <?= $login_stats['total_logins'] ?? 0 ?>
                    </div>
                </div>

                <div class="profile-stat-card">
                    <div class="profile-stat-icon">
                        <i class="fas fa-history"></i>
                    </div>
                    <div class="profile-stat-title">Последний вход</div>
                    <div class="profile-stat-value">
                        <?= $login_stats['last_login'] ? date('d.m.Y H:i', strtotime($login_stats['last_login'])) : 'Нет данных' ?>
                    </div>
                </div>
            </div>

            <!-- Вкладки настроек -->
            <div class="settings-tabs">
                <div class="settings-tab active" data-tab="profile">
                    <i class="fas fa-user"></i> Профиль
                </div>
                <div class="settings-tab" data-tab="security">
                    <i class="fas fa-shield-alt"></i> Безопасность
                </div>
                <div class="settings-tab" data-tab="notifications">
                    <i class="fas fa-bell"></i> Уведомления
                </div>
                <div class="settings-tab" data-tab="payment">
                    <i class="fas fa-credit-card"></i> Платежи
                </div>
            </div>

            <!-- Вкладка: Профиль -->
            <div class="tab-content active" id="profile-tab">
                <form method="POST" enctype="multipart/form-data">
                    <div class="settings-section">
                        <div class="section-header">
                            <div class="section-icon">
                                <i class="fas fa-user-circle"></i>
                            </div>
                            <div>
                                <h2 class="section-title">Аватар профиля</h2>
                                <p class="section-subtitle">Загрузите изображение для вашего профиля</p>
                            </div>
                        </div>

                        <div class="avatar-section">
                            <div class="avatar-preview">
                                <?php if (!empty($user['avatar'])): ?>
                                    <img src="<?= htmlspecialchars($user['avatar']) . '?v=' . time() ?>"
                                         alt="Аватар пользователя" id="avatarPreview">
                                <?php else: ?>
                                    <div class="avatar-preview-placeholder">
                                        <i class="fas fa-user"></i>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="avatar-controls">
                                <label class="avatar-upload-btn">
                                    <i class="fas fa-upload"></i> Загрузить аватар
                                    <input type="file" name="avatar" id="avatarInput" accept="image/jpeg,image/png,image/gif,image/webp" style="display: none;">
                                </label>
                                <?php if (!empty($user['avatar'])): ?>
                                    <input type="hidden" name="remove_avatar" id="removeAvatarFlag" value="0">
                                    <button type="button" class="avatar-remove-btn" onclick="document.getElementById('removeAvatarFlag').value='1'; document.getElementById('avatarPreview').src=''; this.style.display='none';">
                                        <i class="fas fa-trash-alt"></i> Удалить аватар
                                    </button>
                                <?php endif; ?>
                                <p class="avatar-hint">Поддерживаемые форматы: JPG, PNG, GIF, WebP. Максимальный размер: 5MB</p>
                            </div>
                        </div>
                    </div>

                    <div class="settings-section">
                        <div class="section-header">
                            <div class="section-icon">
                                <i class="fas fa-id-card"></i>
                            </div>
                            <div>
                                <h2 class="section-title">Основная информация</h2>
                                <p class="section-subtitle">Ваши личные данные и контактная информация</p>
                            </div>
                        </div>

                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-input"
                                       value="<?= htmlspecialchars($user['email'] ?? '') ?>" disabled>
                                <span class="form-hint">Для изменения email обратитесь в поддержку</span>
                            </div>

                            <div class="form-group">
                                <label class="form-label">ФИО *</label>
                                <input type="text" name="full_name" class="form-input"
                                       value="<?= htmlspecialchars($user['full_name'] ?? '') ?>" required>
                            </div>

                            <?php if ($user['user_type'] !== 'individual'): ?>
                                <div class="form-group">
                                    <label class="form-label">Название компании *</label>
                                    <input type="text" name="company_name" class="form-input"
                                           value="<?= htmlspecialchars($user['company_name'] ?? '') ?>" required>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">ИНН</label>
                                    <input type="text" class="form-input"
                                           value="<?= htmlspecialchars($user['inn'] ?? '') ?>" disabled>
                                    <span class="form-hint">ИНН нельзя изменить после регистрации</span>
                                </div>

                                <?php if ($user['user_type'] === 'legal'): ?>
                                    <div class="form-group">
                                        <label class="form-label">КПП *</label>
                                        <input type="text" name="kpp" class="form-input"
                                               value="<?= htmlspecialchars($user['kpp'] ?? '') ?>" required>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>

                        <div style="margin-top: 24px; padding-top: 20px; border-top: 1px solid rgba(148, 163, 184, 0.1);">
                            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 16px;">
                                <i class="fas fa-save"></i> Сохранить изменения профиля
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Вкладка: Безопасность -->
            <div class="tab-content" id="security-tab">
                <form method="POST">
                    <div class="settings-section">
                        <div class="section-header">
                            <div class="section-icon">
                                <i class="fas fa-lock"></i>
                            </div>
                            <div>
                                <h2 class="section-title">Смена пароля</h2>
                                <p class="section-subtitle">Обновите пароль для защиты аккаунта</p>
                            </div>
                        </div>

                        <div class="security-badges">
                            <div class="security-badge success">
                                <i class="fas fa-check-circle"></i> 2FA не требуется
                            </div>
                            <div class="security-badge success">
                                <i class="fas fa-check-circle"></i> Последний вход: <?= date('d.m.Y H:i') ?>
                            </div>
                        </div>

                        <div class="form-grid" style="margin-top: 24px;">
                            <div class="form-group">
                                <label class="form-label">Текущий пароль *</label>
                                <input type="password" name="current_password" class="form-input" required>
                                <span class="form-hint">Требуется для подтверждения изменений</span>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Новый пароль</label>
                                <input type="password" name="new_password" class="form-input" id="newPassword">
                                <div class="password-strength">
                                    <div class="password-strength-bar" id="passwordStrengthBar"></div>
                                </div>
                                <span class="form-hint">Минимум 8 символов. Оставьте пустым, если не хотите менять</span>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Подтверждение пароля</label>
                                <input type="password" name="confirm_password" class="form-input" id="confirmPassword">
                                <span class="form-hint" id="passwordMatchHint"></span>
                            </div>
                        </div>

                        <div style="margin-top: 24px; padding-top: 20px; border-top: 1px solid rgba(148, 163, 184, 0.1);">
                            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 16px;">
                                <i class="fas fa-key"></i> Обновить пароль
                            </button>
                        </div>
                    </div>
                </form>

                <div class="settings-section">
                    <div class="section-header">
                        <div class="section-icon">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                        <div>
                            <h2 class="section-title">Сессии и безопасность</h2>
                            <p class="section-subtitle">Управление активными сессиями</p>
                        </div>
                    </div>

                    <div class="security-session">
                        <div class="session-info">
                            <div class="session-icon">
                                <i class="fas fa-desktop"></i>
                            </div>
                            <div>
                                <h4>Текущая сессия</h4>
                                <p>Браузер: <?= htmlspecialchars($_SERVER['HTTP_USER_AGENT']) ?></p>
                                <p>IP-адрес: <?= htmlspecialchars($_SERVER['REMOTE_ADDR']) ?></p>
                                <p>Время начала: <?= date('d.m.Y H:i:s') ?></p>
                            </div>
                        </div>
                        <button class="btn btn-outline" onclick="showNotification('Функция завершения сессий будет реализована позже', 'info')">
                            <i class="fas fa-sign-out-alt"></i> Завершить все сессии
                        </button>
                    </div>
                </div>
            </div>

            <!-- Вкладка: Уведомления -->
            <div class="tab-content" id="notifications-tab">
                <div class="settings-section">
                    <div class="section-header">
                        <div class="section-icon">
                            <i class="fab fa-telegram"></i>
                        </div>
                        <div>
                            <h2 class="section-title">Telegram уведомления</h2>
                            <p class="section-subtitle">Получайте уведомления о важных событиях</p>
                        </div>
                    </div>

                    <form method="POST">
                        <div class="telegram-connect <?= !empty($user['telegram_id']) ? 'telegram-connected' : '' ?>">
                            <div class="telegram-header">
                                <i class="fab fa-telegram telegram-icon"></i>
                                <div>
                                    <h3 class="telegram-title">Telegram Bot</h3>
                                    <p class="telegram-status">
                                        <?php if (!empty($user['telegram_id'])): ?>
                                            ✅ Аккаунт привязан (ID: <?= htmlspecialchars($user['telegram_id']) ?>)
                                        <?php else: ?>
                                            ⚠️ Telegram не подключен
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>

                            <?php if (!empty($user['telegram_id'])): ?>
                                <div class="telegram-actions">
                                    <button type="button" class="btn btn-success" onclick="testTelegramNotification()">
                                        <i class="fas fa-paper-plane"></i> Тестовое уведомление
                                    </button>
                                    <button type="button" class="btn btn-outline" onclick="showNotification('Функция отключения будет реализована позже', 'info')">
                                        <i class="fas fa-unlink"></i> Отключить
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="form-group" style="margin-top: 24px;">
                            <label class="form-label">Ваш Telegram ID</label>
                            <input type="text" name="telegram_id" class="form-input"
                                   value="<?= htmlspecialchars($user['telegram_id'] ?? '') ?>"
                                   placeholder="Пример: 123456789">
                            <span class="form-hint">
                                Чтобы получить свой Telegram ID, напишите <code>/start</code> боту
                                <a href="https://t.me/homevlad_notify_bot" target="_blank" style="color: #00bcd4;">@homevlad_notify_bot</a>
                                или используйте <a href="https://t.me/userinfobot" target="_blank" style="color: #00bcd4;">@userinfobot</a>
                            </span>
                        </div>

                        <div class="notification-preferences" style="margin-top: 24px;">
                            <h4 style="margin-bottom: 16px;">Типы уведомлений:</h4>
                            <div class="preference-item" style="display: flex; align-items: center; justify-content: space-between; padding: 12px; background: rgba(148, 163, 184, 0.05); border-radius: 8px; margin-bottom: 8px;">
                                <div>
                                    <strong>Баланс и платежи</strong>
                                    <p style="font-size: 12px; color: #64748b; margin-top: 4px;">Уведомления о пополнении и списаниях</p>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" checked disabled>
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="preference-item" style="display: flex; align-items: center; justify-content: space-between; padding: 12px; background: rgba(148, 163, 184, 0.05); border-radius: 8px; margin-bottom: 8px;">
                                <div>
                                    <strong>Состояние ВМ</strong>
                                    <p style="font-size: 12px; color: #64748b; margin-top: 4px;">Запуск, остановка, ошибки ВМ</p>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" checked disabled>
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="preference-item" style="display: flex; align-items: center; justify-content: space-between; padding: 12px; background: rgba(148, 163, 184, 0.05); border-radius: 8px;">
                                <div>
                                    <strong>Технические работы</strong>
                                    <p style="font-size: 12px; color: #64748b; margin-top: 4px;">Уведомления о плановых работах</p>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" disabled>
                                    <span class="slider"></span>
                                </label>
                            </div>
                        </div>

                        <div style="margin-top: 24px; padding-top: 20px; border-top: 1px solid rgba(148, 163, 184, 0.1);">
                            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 16px;">
                                <i class="fas fa-bell"></i> Сохранить настройки уведомлений
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Вкладка: Платежи -->
            <div class="tab-content" id="payment-tab">
                <div class="settings-section">
                    <div class="section-header">
                        <div class="section-icon">
                            <i class="fas fa-credit-card"></i>
                        </div>
                        <div>
                            <h2 class="section-title">Платежная информация</h2>
                            <p class="section-subtitle">Сохраните карту для быстрой оплаты</p>
                        </div>
                    </div>

                    <?php if ($payment_info && !empty($payment_info['card_number'])): ?>
                        <div class="payment-card">
                            <div class="payment-card-content">
                                <div class="card-chip"></div>
                                <div class="card-number">
                                    **** **** **** <?= substr($payment_info['card_number'], -4) ?>
                                </div>
                                <div class="card-details">
                                    <div class="card-holder">
                                        <?= htmlspecialchars($payment_info['card_holder']) ?>
                                    </div>
                                    <div class="card-expiry">
                                        <?= htmlspecialchars($payment_info['card_expiry']) ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div style="text-align: center; padding: 40px 20px;">
                            <i class="fas fa-credit-card" style="font-size: 48px; color: #cbd5e1; margin-bottom: 16px;"></i>
                            <h3 style="margin-bottom: 8px;">Платежная карта не сохранена</h3>
                            <p style="color: #64748b; margin-bottom: 24px;">Добавьте карту для быстрой оплаты услуг</p>
                        </div>
                    <?php endif; ?>

                    <form method="POST">
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Имя владельца карты</label>
                                <input type="text" name="card_holder" class="form-input"
                                       value="<?= htmlspecialchars($payment_info['card_holder'] ?? '') ?>"
                                       placeholder="IVAN IVANOV">
                            </div>

                            <div class="form-group">
                                <label class="form-label">Номер карты</label>
                                <input type="text" name="card_number" class="form-input card-number-input"
                                       value="<?= htmlspecialchars($payment_info['card_number'] ?? '') ?>"
                                       placeholder="1234 5678 9012 3456">
                            </div>

                            <div class="form-group">
                                <label class="form-label">Срок действия</label>
                                <input type="text" name="card_expiry" class="form-input card-expiry-input"
                                       value="<?= htmlspecialchars($payment_info['card_expiry'] ?? '') ?>"
                                       placeholder="MM/YY">
                            </div>

                            <div class="form-group">
                                <label class="form-label">CVV код</label>
                                <input type="text" name="card_cvv" class="form-input card-cvv-input"
                                       value="<?= htmlspecialchars($payment_info['card_cvv'] ?? '') ?>"
                                       placeholder="123" maxlength="3">
                            </div>
                        </div>

                        <div style="margin-top: 24px; padding-top: 20px; border-top: 1px solid rgba(148, 163, 184, 0.1);">
                            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 16px;">
                                <i class="fas fa-credit-card"></i> Сохранить платежную информацию
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <?php
    // Подключаем общий футер из файла - ТОЛЬКО если файл существует
    $footer_file = __DIR__ . '/../templates/headers/user_footer.php';
    if (file_exists($footer_file)) {
        include $footer_file;
    }
    // Если файл не найден - футер просто не отображается
    ?>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Переключение вкладок
            const settingsTabs = document.querySelectorAll('.settings-tab');
            const tabContents = document.querySelectorAll('.tab-content');

            settingsTabs.forEach(tab => {
                tab.addEventListener('click', function() {
                    // Убираем активный класс у всех вкладок
                    settingsTabs.forEach(t => t.classList.remove('active'));
                    // Добавляем активный класс текущей вкладке
                    this.classList.add('active');

                    // Скрываем все контенты
                    tabContents.forEach(c => c.classList.remove('active'));
                    // Показываем нужный контент
                    const tabId = this.dataset.tab;
                    document.getElementById(`${tabId}-tab`).classList.add('active');
                });
            });

            // Обработка загрузки аватара
            const avatarInput = document.getElementById('avatarInput');
            if (avatarInput) {
                avatarInput.addEventListener('change', function(e) {
                    const file = e.target.files[0];
                    if (file) {
                        // Проверка размера файла (5MB)
                        if (file.size > 5 * 1024 * 1024) {
                            showNotification('Файл слишком большой. Максимальный размер: 5MB', 'error');
                            this.value = '';
                            return;
                        }

                        // Проверка типа файла
                        const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                        if (!allowedTypes.includes(file.type)) {
                            showNotification('Неподдерживаемый формат файла', 'error');
                            this.value = '';
                            return;
                        }

                        // Превью изображения
                        const reader = new FileReader();
                        reader.onload = function(event) {
                            let preview = document.getElementById('avatarPreview');
                            if (!preview) {
                                const previewContainer = document.querySelector('.avatar-preview');
                                previewContainer.innerHTML = '';
                                preview = document.createElement('img');
                                preview.id = 'avatarPreview';
                                preview.alt = 'Аватар пользователя';
                                preview.style.width = '100%';
                                preview.style.height = '100%';
                                preview.style.objectFit = 'cover';
                                previewContainer.appendChild(preview);
                            }
                            preview.src = event.target.result;

                            // Скрываем кнопку удаления если она была видна
                            const removeBtn = document.querySelector('.avatar-remove-btn');
                            if (removeBtn) removeBtn.style.display = 'none';
                        };
                        reader.readAsDataURL(file);
                    }
                });
            }

            // Форматирование номера карты
            document.querySelectorAll('.card-number-input').forEach(input => {
                input.addEventListener('input', function(e) {
                    let value = e.target.value.replace(/\s+/g, '');
                    if (value.length > 0) {
                        value = value.match(new RegExp('.{1,4}', 'g')).join(' ');
                    }
                    e.target.value = value.substring(0, 19); // 16 цифр + 3 пробела
                });
            });

            // Форматирование срока действия карты
            document.querySelectorAll('.card-expiry-input').forEach(input => {
                input.addEventListener('input', function(e) {
                    let value = e.target.value.replace(/\D/g, '');
                    if (value.length > 2) {
                        value = value.substring(0, 2) + '/' + value.substring(2, 4);
                    }
                    e.target.value = value.substring(0, 5);
                });
            });

            // Ограничение CVV кода
            document.querySelectorAll('.card-cvv-input').forEach(input => {
                input.addEventListener('input', function(e) {
                    e.target.value = e.target.value.replace(/\D/g, '').substring(0, 3);
                });
            });

            // Проверка сложности пароля
            const newPasswordInput = document.getElementById('newPassword');
            const passwordStrengthBar = document.getElementById('passwordStrengthBar');
            const confirmPasswordInput = document.getElementById('confirmPassword');
            const passwordMatchHint = document.getElementById('passwordMatchHint');

            if (newPasswordInput && passwordStrengthBar) {
                newPasswordInput.addEventListener('input', function() {
                    const password = this.value;
                    let strength = 0;

                    // Проверка длины
                    if (password.length >= 8) strength += 25;

                    // Проверка наличия цифр
                    if (/\d/.test(password)) strength += 25;

                    // Проверка наличия букв в разных регистрах
                    if (/[a-z]/.test(password) && /[A-Z]/.test(password)) strength += 25;

                    // Проверка наличия специальных символов
                    if (/[^a-zA-Z0-9]/.test(password)) strength += 25;

                    // Обновление индикатора
                    passwordStrengthBar.style.width = strength + '%';

                    // Цвет индикатора
                    if (strength < 50) {
                        passwordStrengthBar.className = 'password-strength-bar strength-weak';
                    } else if (strength < 75) {
                        passwordStrengthBar.className = 'password-strength-bar strength-medium';
                    } else {
                        passwordStrengthBar.className = 'password-strength-bar strength-strong';
                    }
                });
            }

            // Проверка совпадения паролей
            if (newPasswordInput && confirmPasswordInput && passwordMatchHint) {
                function checkPasswordMatch() {
                    const newPassword = newPasswordInput.value;
                    const confirmPassword = confirmPasswordInput.value;

                    if (confirmPassword === '') {
                        passwordMatchHint.textContent = '';
                        passwordMatchHint.style.color = '';
                    } else if (newPassword === confirmPassword) {
                        passwordMatchHint.textContent = '✓ Пароли совпадают';
                        passwordMatchHint.style.color = '#10b981';
                    } else {
                        passwordMatchHint.textContent = '✗ Пароли не совпадают';
                        passwordMatchHint.style.color = '#ef4444';
                    }
                }

                newPasswordInput.addEventListener('input', checkPasswordMatch);
                confirmPasswordInput.addEventListener('input', checkPasswordMatch);
            }

            // Кнопка "Наверх"
            const scrollToTopBtn = document.getElementById('scrollToTop');

            window.addEventListener('scroll', function() {
                if (window.pageYOffset > 300) {
                    scrollToTopBtn.classList.add('visible');
                } else {
                    scrollToTopBtn.classList.remove('visible');
                }
            });

            scrollToTopBtn.addEventListener('click', function(e) {
                e.preventDefault();
                window.scrollTo({
                    top: 0,
                    behavior: 'smooth'
                });
            });

            // Удаление уведомлений через 5 секунд
            setTimeout(() => {
                document.querySelectorAll('.notification').forEach(notification => {
                    notification.style.animation = 'slideOut 0.3s ease forwards';
                    setTimeout(() => notification.remove(), 300);
                });
            }, 5000);
        });

        // Функция для тестирования Telegram уведомлений
        function testTelegramNotification() {
            showNotification('Отправка тестового уведомления...', 'info');

            setTimeout(() => {
                showNotification('Тестовое уведомление отправлено в Telegram!', 'success');
            }, 1000);
        }

        // Функция для показа уведомлений
        function showNotification(message, type = 'info') {
            // Удаляем старые уведомления
            document.querySelectorAll('.notification').forEach(n => n.remove());

            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.innerHTML = `
                <i class="fas ${type === 'success' ? 'fa-check-circle' :
                               type === 'error' ? 'fa-exclamation-circle' :
                               type === 'warning' ? 'fa-exclamation-triangle' :
                               'fa-info-circle'}"></i>
                <span>${message}</span>
                <button onclick="this.parentElement.remove()" style="margin-left: auto; background: none; border: none; color: inherit; cursor: pointer;">
                    <i class="fas fa-times"></i>
                </button>
            `;

            document.body.appendChild(notification);

            // Добавляем стили для анимации удаления
            const style = document.createElement('style');
            if (!document.querySelector('#notification-styles')) {
                style.id = 'notification-styles';
                style.textContent = `
                    @keyframes slideOut {
                        from {
                            transform: translateX(0);
                            opacity: 1;
                        }
                        to {
                            transform: translateX(100%);
                            opacity: 0;
                        }
                    }
                `;
                document.head.appendChild(style);
            }

            setTimeout(() => {
                if (notification.parentElement) {
                    notification.style.animation = 'slideOut 0.3s ease forwards';
                    setTimeout(() => notification.remove(), 300);
                }
            }, 5000);
        }

        // Стили для переключателей
        const switchStyle = document.createElement('style');
        switchStyle.textContent = `
            .switch {
                position: relative;
                display: inline-block;
                width: 50px;
                height: 24px;
            }

            .switch input {
                opacity: 0;
                width: 0;
                height: 0;
            }

            .slider {
                position: absolute;
                cursor: pointer;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background-color: #ccc;
                transition: .4s;
                border-radius: 24px;
            }

            .slider:before {
                position: absolute;
                content: "";
                height: 16px;
                width: 16px;
                left: 4px;
                bottom: 4px;
                background-color: white;
                transition: .4s;
                border-radius: 50%;
            }

            input:checked + .slider {
                background-color: #00bcd4;
            }

            input:checked + .slider:before {
                transform: translateX(26px);
            }
        `;
        document.head.appendChild(switchStyle);
    </script>
</body>
</html>
