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

// Обработка POST запроса
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Проверяем наличие скрытого поля или кнопки
    if (isset($_POST['add_cluster']) || isset($_POST['cluster_form'])) {
        try {
            $name = trim($_POST['name']);
            $description = trim($_POST['description'] ?? '');
            $is_active = isset($_POST['is_active']) ? 1 : 0;

            if (empty($name)) {
                throw new Exception("Имя кластера обязательно");
            }

            // Проверка уникальности имени кластера
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM proxmox_clusters WHERE name = ?");
            $stmt->execute([$name]);
            if ($stmt->fetchColumn() > 0) {
                throw new Exception("Кластер с таким именем уже существует");
            }

            // Валидация длины имени
            if (strlen($name) > 50) {
                throw new Exception("Имя кластера не должно превышать 50 символов");
            }

            // Валидация длины описания
            if (strlen($description) > 500) {
                throw new Exception("Описание не должно превышать 500 символов");
            }

            // Подготовка SQL запроса
            $sql = "INSERT INTO proxmox_clusters (name, description, is_active, created_at) VALUES (?, ?, ?, NOW())";
            $stmt = $pdo->prepare($sql);

            if (!$stmt) {
                throw new Exception("Ошибка подготовки запроса: " . implode(", ", $pdo->errorInfo()));
            }

            // Выполнение запроса
            $result = $stmt->execute([$name, $description, $is_active]);

            if (!$result) {
                $errorInfo = $stmt->errorInfo();
                throw new Exception("Ошибка выполнения запроса: " . $errorInfo[2]);
            }

            $clusterId = $pdo->lastInsertId();

            // Логирование успешного создания
            error_log("Кластер создан: ID = $clusterId, Name = $name");

            $_SESSION['success'] = "Кластер '{$name}' успешно создан";
            header("Location: nodes.php");
            exit;
        } catch (Exception $e) {
            $_SESSION['error'] = $e->getMessage();
            // Логирование ошибки
            error_log("Ошибка при создании кластера: " . $e->getMessage());
        }
    }
}

$title = "Добавление кластера | HomeVlad Cloud";
require 'admin_header.php';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $title ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&family=Poppins:wght@600&display=swap" rel="stylesheet">
    <link rel="icon" href="../img/cloud.png" type="image/png">
    <style>
        <?php include __DIR__ . '/../admin/css/admin_style.css'; ?>

        /* ========== СТИЛИ ДЛЯ ФОРМЫ ДОБАВЛЕНИЯ КЛАСТЕРА ========== */
        :root {
            --form-bg: #f8fafc;
            --form-card-bg: #ffffff;
            --form-border: #e2e8f0;
            --form-text: #1e293b;
            --form-text-secondary: #64748b;
            --form-text-muted: #94a3b8;
            --form-hover: #f1f5f9;
            --form-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            --form-shadow-hover: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --form-accent: #00bcd4;
            --form-accent-light: rgba(0, 188, 212, 0.1);
            --form-success: #10b981;
            --form-warning: #f59e0b;
            --form-danger: #ef4444;
            --form-info: #3b82f6;
            --form-purple: #8b5cf6;
        }

        [data-theme="dark"] {
            --form-bg: #0f172a;
            --form-card-bg: #1e293b;
            --form-border: #334155;
            --form-text: #ffffff;
            --form-text-secondary: #cbd5e1;
            --form-text-muted: #94a3b8;
            --form-hover: #2d3748;
            --form-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.3), 0 1px 2px 0 rgba(0, 0, 0, 0.2);
            --form-shadow-hover: 0 10px 15px -3px rgba(0, 0, 0, 0.3), 0 4px 6px -2px rgba(0, 0, 0, 0.2);
        }

        /* ========== ОСНОВНАЯ ОБЕРТКА ========== */
        .form-wrapper {
            padding: 20px;
            background: var(--form-bg);
            min-height: calc(100vh - 70px);
            margin-left: 280px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .admin-sidebar.compact + .form-wrapper {
            margin-left: 70px;
        }

        @media (max-width: 1200px) {
            .form-wrapper {
                margin-left: 70px !important;
            }
        }

        @media (max-width: 768px) {
            .form-wrapper {
                margin-left: 0 !important;
                padding: 15px;
            }
        }

        /* ========== ШАПКА СТРАНИЦЫ ========== */
        .form-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding: 24px;
            background: var(--form-card-bg);
            border-radius: 12px;
            border: 1px solid var(--form-border);
            box-shadow: var(--form-shadow);
        }

        .form-header-left h1 {
            color: var(--form-text);
            font-size: 24px;
            margin: 0 0 8px 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .form-header-left h1 i {
            color: var(--form-accent);
        }

        .form-header-left p {
            color: var(--form-text-secondary);
            font-size: 14px;
            margin: 0;
        }

        .form-header-right {
            display: flex;
            gap: 10px;
        }

        /* ========== КАРТОЧКА ФОРМЫ ========== */
        .form-card {
            background: var(--form-card-bg);
            border-radius: 12px;
            border: 1px solid var(--form-border);
            box-shadow: var(--form-shadow);
            overflow: hidden;
            margin-bottom: 30px;
            animation: slideIn 0.5s ease;
        }

        .form-card-header {
            padding: 20px;
            border-bottom: 1px solid var(--form-border);
            background: linear-gradient(135deg, var(--form-accent), #0097a7);
            color: white;
        }

        .form-card-header h2 {
            margin: 0;
            font-size: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-card-header h2 i {
            font-size: 20px;
        }

        .form-card-body {
            padding: 30px;
        }

        /* ========== ФОРМА ========== */
        .cluster-form {
            display: grid;
            grid-template-columns: 1fr;
            gap: 24px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .form-label {
            color: var(--form-text);
            font-size: 14px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-label.required::after {
            content: '*';
            color: var(--form-danger);
            margin-left: 4px;
        }

        .form-input {
            padding: 12px 16px;
            border: 2px solid var(--form-border);
            border-radius: 8px;
            background: var(--form-card-bg);
            color: var(--form-text);
            font-size: 14px;
            transition: all 0.3s ease;
            font-family: inherit;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--form-accent);
            box-shadow: 0 0 0 3px var(--form-accent-light);
        }

        .form-input:hover {
            border-color: var(--form-accent);
        }

        .form-input::placeholder {
            color: var(--form-text-muted);
        }

        textarea.form-input {
            min-height: 120px;
            resize: vertical;
            line-height: 1.5;
        }

        /* ========== ЧЕКБОКС ========== */
        .checkbox-container {
            display: flex;
            align-items: center;
            gap: 12px;
            cursor: pointer;
            user-select: none;
            padding: 10px;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .checkbox-container:hover {
            background: var(--form-hover);
        }

        .checkbox-container input[type="checkbox"] {
            display: none;
        }

        .checkmark {
            width: 20px;
            height: 20px;
            border: 2px solid var(--form-border);
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            position: relative;
        }

        .checkmark::after {
            content: '';
            position: absolute;
            display: none;
            width: 10px;
            height: 10px;
            border-radius: 2px;
            background: var(--form-accent);
        }

        .checkbox-container input[type="checkbox"]:checked ~ .checkmark {
            border-color: var(--form-accent);
            background: var(--form-accent-light);
        }

        .checkbox-container input[type="checkbox"]:checked ~ .checkmark::after {
            display: block;
        }

        .checkbox-container span:not(.checkmark) {
            color: var(--form-text);
            font-size: 14px;
            font-weight: 500;
        }

        /* ========== КНОПКИ ФОРМЫ ========== */
        .form-actions {
            display: flex;
            gap: 16px;
            padding-top: 24px;
            border-top: 1px solid var(--form-border);
            margin-top: 16px;
        }

        .form-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            outline: none;
            font-family: inherit;
        }

        .form-btn-primary {
            background: linear-gradient(135deg, var(--form-accent), #0097a7);
            color: white;
        }

        .form-btn-primary:hover {
            background: linear-gradient(135deg, #0097a7, #00838f);
            transform: translateY(-2px);
            box-shadow: var(--form-shadow-hover);
        }

        .form-btn-secondary {
            background: var(--form-hover);
            color: var(--form-text);
            border: 1px solid var(--form-border);
        }

        .form-btn-secondary:hover {
            background: var(--form-border);
            transform: translateY(-2px);
            box-shadow: var(--form-shadow-hover);
        }

        .form-btn-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            font-size: 14px;
        }

        .form-btn-back {
            background: rgba(148, 163, 184, 0.1);
            color: var(--form-text-muted);
            border: 1px solid var(--form-border);
        }

        .form-btn-back:hover {
            background: var(--form-hover);
            color: var(--form-text);
            transform: translateY(-2px);
        }

        /* ========== ИНФОРМАЦИОННАЯ ПАНЕЛЬ ========== */
        .info-panel {
            background: linear-gradient(135deg, var(--form-info), #2563eb);
            border-radius: 12px;
            padding: 20px;
            color: white;
            margin-bottom: 30px;
            animation: slideIn 0.5s ease 0.1s both;
        }

        .info-panel-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
        }

        .info-panel-header i {
            font-size: 24px;
        }

        .info-panel-header h3 {
            margin: 0;
            font-size: 16px;
        }

        .info-panel-body {
            font-size: 14px;
            opacity: 0.9;
            line-height: 1.5;
        }

        .info-panel-list {
            margin: 10px 0 0 0;
            padding-left: 20px;
        }

        .info-panel-list li {
            margin-bottom: 6px;
        }

        /* ========== ПРЕДУПРЕЖДЕНИЯ ========== */
        .form-alerts {
            margin-bottom: 30px;
        }

        .alert {
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            animation: slideIn 0.3s ease;
        }

        .alert-danger {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: var(--form-danger);
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.2);
            color: var(--form-success);
        }

        .alert i {
            font-size: 18px;
            flex-shrink: 0;
        }

        .alert-content {
            flex: 1;
        }

        .alert-content p {
            margin: 0;
            font-size: 14px;
            line-height: 1.4;
        }

        /* ========== СЧЕТЧИК СИМВОЛОВ ========== */
        .char-counter {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 4px;
            font-size: 12px;
            color: var(--form-text-muted);
        }

        .char-counter.warning {
            color: var(--form-warning);
        }

        .char-counter.danger {
            color: var(--form-danger);
        }

        /* ========== АДАПТИВНОСТЬ ========== */
        @media (max-width: 768px) {
            .form-header {
                flex-direction: column;
                align-items: stretch;
                gap: 15px;
            }

            .form-card-body {
                padding: 20px;
            }

            .form-actions {
                flex-direction: column;
            }

            .form-btn {
                justify-content: center;
                width: 100%;
            }
        }

        @media (max-width: 480px) {
            .form-wrapper {
                padding: 10px;
            }

            .form-header {
                padding: 16px;
            }

            .form-header-left h1 {
                font-size: 20px;
            }

            .form-card-body {
                padding: 16px;
            }
        }

        /* ========== АНИМАЦИИ ========== */
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

        @keyframes pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(0, 188, 212, 0.4);
            }
            70% {
                box-shadow: 0 0 0 10px rgba(0, 188, 212, 0);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(0, 188, 212, 0);
            }
        }

        .form-input:focus {
            animation: pulse 2s infinite;
        }

        /* ========== ПУСТОЕ СОСТОЯНИЕ ========== */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--form-text-secondary);
        }

        .empty-state i {
            font-size: 48px;
            color: var(--form-text-muted);
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .empty-state h3 {
            color: var(--form-text);
            font-size: 18px;
            margin-bottom: 10px;
        }

        .empty-state p {
            color: var(--form-text-secondary);
            font-size: 14px;
            margin-bottom: 20px;
        }

        /* ========== ЗАГРУЗКА ========== */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .loading-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .loading-spinner {
            width: 50px;
            height: 50px;
            border: 3px solid var(--form-border);
            border-top-color: var(--form-accent);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }
    </style>
</head>
<body>
    <!-- Подключаем сайдбар -->
    <?php require 'admin_sidebar.php'; ?>

    <!-- Основной контент -->
    <div class="form-wrapper">
        <!-- Шапка страницы -->
        <div class="form-header">
            <div class="form-header-left">
                <h1><i class="fas fa-network-wired"></i> Добавление нового кластера</h1>
                <p>Создайте новый кластер для организации Proxmox серверов</p>
            </div>
            <div class="form-header-right">
                <a href="nodes.php" class="form-btn-icon form-btn-back" title="Вернуться к списку">
                    <i class="fas fa-arrow-left"></i>
                </a>
            </div>
        </div>

        <!-- Информационная панель -->
        <div class="info-panel">
            <div class="info-panel-header">
                <i class="fas fa-info-circle"></i>
                <h3>Что такое кластер Proxmox?</h3>
            </div>
            <div class="info-panel-body">
                Кластер — это группа серверов Proxmox, объединенных для совместного управления ресурсами.
                Это позволяет:
                <ul class="info-panel-list">
                    <li>Объединить несколько нод в единую систему</li>
                    <li>Осуществлять миграцию ВМ между нодами</li>
                    <li>Распределять нагрузку между серверами</li>
                    <li>Повысить отказоустойчивость системы</li>
                </ul>
            </div>
        </div>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="form-alerts">
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <div class="alert-content">
                        <p><?= htmlspecialchars($_SESSION['error']) ?></p>
                    </div>
                </div>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <!-- Карточка формы -->
        <div class="form-card">
            <div class="form-card-header">
                <h2><i class="fas fa-plus-circle"></i> Основные параметры кластера</h2>
            </div>
            <div class="form-card-body">
                <form method="POST" class="cluster-form" id="clusterForm">
                    <!-- Добавляем скрытое поле для идентификации формы -->
                    <input type="hidden" name="cluster_form" value="1">

                    <div class="form-group">
                        <label class="form-label required">
                            <i class="fas fa-tag"></i> Имя кластера
                        </label>
                        <input type="text"
                               name="name"
                               class="form-input"
                               placeholder="Введите уникальное имя кластера"
                               required
                               maxlength="50"
                               id="clusterName">
                        <div class="char-counter" id="nameCounter">
                            <span>Макс. 50 символов</span>
                            <span>0/50</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-align-left"></i> Описание кластера
                        </label>
                        <textarea name="description"
                                  class="form-input"
                                  placeholder="Опишите назначение кластера, особенности конфигурации и т.д."
                                  maxlength="500"
                                  id="clusterDescription"></textarea>
                        <div class="char-counter" id="descCounter">
                            <span>Макс. 500 символов</span>
                            <span>0/500</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="checkbox-container">
                            <input type="checkbox" name="is_active" checked id="isActive">
                            <span class="checkmark"></span>
                            <span>
                                <i class="fas fa-power-off"></i> Активный кластер
                                <small style="display: block; color: var(--form-text-muted); font-weight: normal; margin-top: 4px;">
                                    Неактивные кластеры не будут использоваться для создания новых ВМ
                                </small>
                            </span>
                        </label>
                    </div>

                    <div class="form-actions">
                        <button type="submit"
                                name="add_cluster"
                                class="form-btn form-btn-primary"
                                id="submitBtn">
                            <i class="fas fa-save"></i> Создать кластер
                        </button>
                        <a href="nodes.php" class="form-btn form-btn-secondary">
                            <i class="fas fa-times"></i> Отмена
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Вспомогательная информация -->
        <div class="info-panel" style="background: linear-gradient(135deg, var(--form-success), #059669);">
            <div class="info-panel-header">
                <i class="fas fa-lightbulb"></i>
                <h3>Рекомендации по настройке</h3>
            </div>
            <div class="info-panel-body">
                <ul class="info-panel-list">
                    <li>Используйте понятные имена, отражающие назначение кластера</li>
                    <li>Укажите описание для удобства управления несколькими кластерами</li>
                    <li>Активируйте кластер только после полной настройки всех нод</li>
                    <li>Проверьте подключение всех нод перед добавлением в продакшн</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Оверлей загрузки -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner"></div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Элементы формы
        const clusterForm = document.getElementById('clusterForm');
        const clusterName = document.getElementById('clusterName');
        const clusterDescription = document.getElementById('clusterDescription');
        const nameCounter = document.getElementById('nameCounter');
        const descCounter = document.getElementById('descCounter');
        const submitBtn = document.getElementById('submitBtn');
        const loadingOverlay = document.getElementById('loadingOverlay');

        // Инициализация счетчиков символов
        updateCharCounter(clusterName, nameCounter, 50);
        updateCharCounter(clusterDescription, descCounter, 500);

        // Обработчики изменения полей
        clusterName.addEventListener('input', function() {
            updateCharCounter(this, nameCounter, 50);
            validateName();
        });

        clusterDescription.addEventListener('input', function() {
            updateCharCounter(this, descCounter, 500);
        });

        // Валидация формы
        clusterForm.addEventListener('submit', function(e) {
            // Не отменяем отправку формы по умолчанию
            if (!validateForm()) {
                e.preventDefault();
                return;
            }

            // Показываем загрузку
            loadingOverlay.classList.add('active');

            // Форма отправится стандартным способом
        });

        // Функция обновления счетчика символов
        function updateCharCounter(input, counter, maxLength) {
            const length = input.value.length;
            const counterText = counter.querySelector('span:last-child');
            counterText.textContent = `${length}/${maxLength}`;

            // Меняем цвет при приближении к лимиту
            counter.classList.remove('warning', 'danger');
            if (length > maxLength * 0.8 && length <= maxLength * 0.9) {
                counter.classList.add('warning');
            } else if (length > maxLength * 0.9) {
                counter.classList.add('danger');
            }
        }

        // Валидация имени кластера
        function validateName() {
            const name = clusterName.value.trim();
            const errorMessages = [];

            if (name.length === 0) {
                errorMessages.push('Имя кластера обязательно');
            }

            if (name.length > 50) {
                errorMessages.push('Имя не должно превышать 50 символов');
            }

            // Проверка на специальные символы
            const invalidChars = /[<>:"\/\\|?*]/;
            if (invalidChars.test(name)) {
                errorMessages.push('Имя не должно содержать символы: < > : " / \\ | ? *');
            }

            // Показываем ошибки под полем
            showFieldError(clusterName, errorMessages);

            return errorMessages.length === 0;
        }

        // Показ ошибок для поля
        function showFieldError(field, messages) {
            // Удаляем старые сообщения об ошибках
            const oldError = field.parentNode.querySelector('.field-error');
            if (oldError) {
                oldError.remove();
            }

            // Если есть ошибки - показываем их
            if (messages.length > 0) {
                const errorDiv = document.createElement('div');
                errorDiv.className = 'field-error';
                errorDiv.style.cssText = `
                    color: var(--form-danger);
                    font-size: 12px;
                    margin-top: 4px;
                    display: flex;
                    align-items: center;
                    gap: 6px;
                `;

                errorDiv.innerHTML = `
                    <i class="fas fa-exclamation-circle"></i>
                    <span>${messages[0]}</span>
                `;

                field.parentNode.appendChild(errorDiv);
                field.style.borderColor = 'var(--form-danger)';
            } else {
                field.style.borderColor = '';
            }
        }

        // Общая валидация формы
        function validateForm() {
            const nameValid = validateName();
            const description = clusterDescription.value.trim();

            if (!nameValid) {
                // Фокусируемся на поле с ошибкой
                clusterName.focus();
                return false;
            }

            // Проверка описания
            if (description.length > 500) {
                clusterDescription.focus();
                showFieldError(clusterDescription, ['Описание не должно превышать 500 символов']);
                return false;
            }

            return true;
        }

        // Предварительная проверка доступности имени
        clusterName.addEventListener('blur', function() {
            const name = this.value.trim();

            if (name.length === 0 || name.length > 50) {
                return;
            }

            // Можно добавить AJAX проверку на уникальность имени
            // fetch('check_cluster_name.php?name=' + encodeURIComponent(name))
            //     .then(response => response.json())
            //     .then(data => {
            //         if (!data.available) {
            //             showFieldError(this, ['Кластер с таким именем уже существует']);
            //         }
            //     });
        });

        // Анимация при загрузке страницы
        const formCard = document.querySelector('.form-card');
        formCard.style.opacity = '0';
        formCard.style.transform = 'translateY(20px)';

        setTimeout(() => {
            formCard.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
            formCard.style.opacity = '1';
            formCard.style.transform = 'translateY(0)';
        }, 100);

        // Обновление отступа при изменении размера окна
        function updateWrapperMargin() {
            const wrapper = document.querySelector('.form-wrapper');
            const sidebar = document.querySelector('.admin-sidebar');

            if (window.innerWidth <= 768) {
                wrapper.style.marginLeft = '0';
            } else if (sidebar.classList.contains('compact')) {
                wrapper.style.marginLeft = '70px';
            } else {
                wrapper.style.marginLeft = '280px';
            }
        }

        window.addEventListener('resize', updateWrapperMargin);

        // Наблюдатель за изменением класса сайдбара
        const sidebar = document.querySelector('.admin-sidebar');
        if (sidebar) {
            const observer = new MutationObserver(updateWrapperMargin);
            observer.observe(sidebar, { attributes: true, attributeFilter: ['class'] });
        }

        // Сохранение данных формы при случайном обновлении
        const formData = {
            name: clusterName.value,
            description: clusterDescription.value,
            isActive: document.getElementById('isActive').checked
        };

        // Сохраняем данные в sessionStorage
        function saveFormData() {
            sessionStorage.setItem('clusterFormData', JSON.stringify({
                name: clusterName.value,
                description: clusterDescription.value,
                isActive: document.getElementById('isActive').checked
            }));
        }

        // Восстанавливаем данные из sessionStorage
        function restoreFormData() {
            const savedData = sessionStorage.getItem('clusterFormData');
            if (savedData) {
                const data = JSON.parse(savedData);
                clusterName.value = data.name || '';
                clusterDescription.value = data.description || '';
                document.getElementById('isActive').checked = data.isActive !== false;

                // Обновляем счетчики
                updateCharCounter(clusterName, nameCounter, 50);
                updateCharCounter(clusterDescription, descCounter, 500);
            }
        }

        // Очищаем сохраненные данные при успешной отправке
        clusterForm.addEventListener('submit', function() {
            sessionStorage.removeItem('clusterFormData');
        });

        // Сохраняем данные при изменении
        clusterName.addEventListener('input', saveFormData);
        clusterDescription.addEventListener('input', saveFormData);
        document.getElementById('isActive').addEventListener('change', saveFormData);

        // Восстанавливаем данные при загрузке
        restoreFormData();
    });
    </script>
    <?php require 'admin_footer.php'; ?>
</body>
</html>
