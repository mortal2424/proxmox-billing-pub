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

// Получаем данные пользователя для редактирования
$user = null;
$payment_info = null;
$user_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($user_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT * FROM payment_info WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $payment_info = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $email = trim($_POST['email']);
        $full_name = trim($_POST['full_name']);
        $balance = (float)$_POST['balance'];
        $is_admin = isset($_POST['is_admin']) ? 1 : 0;
        $is_active = isset($_POST['is_active']) ? 1 : 0; // Получаем значение чекбокса активации
        $user_type = $_POST['user_type'];
        $inn = trim($_POST['inn'] ?? '');
        $kpp = trim($_POST['kpp'] ?? '');
        $company_name = trim($_POST['company_name'] ?? '');
        $telegram_id = trim($_POST['telegram_id'] ?? '');

        // Валидация
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Некорректный email");
        }

        if ($balance < 0) {
            throw new Exception("Баланс не может быть отрицательным");
        }

        if (!empty($telegram_id) && !preg_match('/^\d+$/', $telegram_id)) {
            throw new Exception("Telegram ID должен содержать только цифры");
        }

        // Проверка на изменение email
        if ($user_id > 0) {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmt->execute([$email, $user_id]);
            if ($stmt->fetch()) {
                throw new Exception("Пользователь с таким email уже существует");
            }
        }

        if ($user_id > 0) {
            // Обновление существующего пользователя
            $stmt = $pdo->prepare("UPDATE users SET
                email = ?,
                full_name = ?,
                balance = ?,
                is_admin = ?,
                is_active = ?,  -- Добавляем обновление поля is_active
                user_type = ?,
                inn = ?,
                kpp = ?,
                company_name = ?,
                telegram_id = ?
                WHERE id = ?");
            $stmt->execute([
                $email,
                $full_name,
                $balance,
                $is_admin,
                $is_active,  // Добавляем параметр is_active
                $user_type,
                $inn,
                $kpp,
                $company_name,
                !empty($telegram_id) ? $telegram_id : null,
                $user_id
            ]);
            $message = "Данные пользователя обновлены";

            // Обработка смены пароля (если указан)
            if (!empty($_POST['new_password'])) {
                $new_password = trim($_POST['new_password']);
                $confirm_password = trim($_POST['confirm_password']);

                if ($new_password !== $confirm_password) {
                    throw new Exception("Пароли не совпадают");
                }

                if (strlen($new_password) < 8) {
                    throw new Exception("Пароль должен содержать минимум 8 символов");
                }

                $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                $stmt->execute([$password_hash, $user_id]);
                $message .= ". Пароль обновлен";
            }
        } else {
            throw new Exception("Редактирование несуществующего пользователя");
        }

        // Обработка платежной информации
        if (isset($_POST['card_holder'])) {
            $card_holder = trim($_POST['card_holder']);
            $card_number = preg_replace('/\s+/', '', $_POST['card_number']);
            $card_expiry = trim($_POST['card_expiry']);
            $card_cvv = trim($_POST['card_cvv']);

            if ($payment_info) {
                // Обновление существующей платежной информации
                $stmt = $pdo->prepare("UPDATE payment_info SET
                    card_holder = ?,
                    card_number = ?,
                    card_expiry = ?,
                    card_cvv = ?
                    WHERE user_id = ?");
                $stmt->execute([$card_holder, $card_number, $card_expiry, $card_cvv, $user_id]);
            } else {
                // Добавление новой платежной информации
                $stmt = $pdo->prepare("INSERT INTO payment_info
                    (user_id, card_holder, card_number, card_expiry, card_cvv)
                    VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$user_id, $card_holder, $card_number, $card_expiry, $card_cvv]);
            }
        }

        $_SESSION['success'] = $message;
        header("Location: users.php");
        exit;
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
    }
}

$title = "Редактирование пользователя | HomeVlad Cloud";
require 'admin_header.php';

// Если пользователь не найден, покажем сообщение
if (!$user && $user_id > 0) {
    ?>
    <style>
    <?php include '../admin/css/user_edit_styles.css'; ?>
    </style>
    <?php require 'admin_sidebar.php'; ?>
    <div class="dashboard-wrapper">
        <div class="dashboard-header">
            <div class="header-left">
                <h1><i class="fas fa-user-edit"></i> Редактирование пользователя</h1>
                <p>Пользователь не найден</p>
            </div>
            <div class="dashboard-quick-actions">
                <a href="users.php" class="dashboard-action-btn dashboard-action-btn-secondary">
                    <i class="fas fa-arrow-left"></i> Назад к списку
                </a>
            </div>
        </div>
        <div class="user-form-container">
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle"></i> Пользователь с ID #<?= $user_id ?> не найден
            </div>
        </div>
    </div>
    <?php require 'admin_footer.php'; ?>
    <?php exit;
}
?>

<style>
/* ОСНОВНЫЕ ПЕРЕМЕННЫЕ (СИНХРОНИЗИРОВАНЫ С ШАПКОЙ И САЙДБАРОМ) */
:root {
    --admin-bg: #f8fafc;
    --admin-card-bg: #ffffff;
    --admin-text: #1e293b;
    --admin-text-secondary: #475569;
    --admin-border: #cbd5e1;
    --admin-accent: #0ea5e9;
    --admin-accent-hover: #0284c7;
    --admin-accent-light: rgba(14, 165, 233, 0.15);
    --admin-danger: #ef4444;
    --admin-success: #10b981;
    --admin-warning: #f59e0b;
    --admin-info: #3b82f6;
    --admin-purple: #8b5cf6;
    --admin-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    --admin-hover-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

[data-theme="dark"] {
    --admin-bg: #1e293b;
    --admin-card-bg: #1e293b;
    --admin-text: #f1f5f9;
    --admin-text-secondary: #cbd5e1;
    --admin-border: #334155;
    --admin-accent: #38bdf8;
    --admin-accent-hover: #0ea5e9;
    --admin-accent-light: rgba(56, 189, 248, 0.15);
    --admin-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
    --admin-hover-shadow: 0 4px 12px rgba(0, 0, 0, 0.4);
}

/* ========== ОСНОВНОЙ МАКЕТ ========== */
.dashboard-wrapper {
    padding: 20px;
    background: var(--admin-bg);
    min-height: calc(100vh - 70px);
    margin-left: 280px;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.admin-sidebar.compact + .dashboard-wrapper {
    margin-left: 70px;
}

/* ========== ШАПКА СТРАНИЦЫ ========== */
.dashboard-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    padding: 24px;
    background: var(--admin-card-bg);
    border-radius: 12px;
    border: 1px solid var(--admin-border);
    box-shadow: var(--admin-shadow);
}

.header-left h1 {
    color: var(--admin-text);
    font-size: 24px;
    margin: 0 0 8px 0;
    display: flex;
    align-items: center;
    gap: 12px;
}

.header-left h1 i {
    color: var(--admin-accent);
}

.header-left p {
    color: var(--admin-text-secondary);
    font-size: 14px;
    margin: 0;
}

.user-info-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 12px;
    background: var(--admin-accent-light);
    border: 1px solid var(--admin-accent);
    border-radius: 20px;
    color: var(--admin-accent);
    font-size: 12px;
    font-weight: 600;
}

.user-info-badge i {
    font-size: 14px;
}

.dashboard-quick-actions {
    display: flex;
    gap: 12px;
}

.dashboard-action-btn {
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

.dashboard-action-btn-primary {
    background: linear-gradient(135deg, var(--admin-accent), var(--admin-accent-hover));
    color: white;
}

.dashboard-action-btn-secondary {
    background: var(--admin-card-bg);
    color: var(--admin-text);
    border: 1px solid var(--admin-border);
}

.dashboard-action-btn-danger {
    background: linear-gradient(135deg, var(--admin-danger), #dc2626);
    color: white;
    border: none;
}

.dashboard-action-btn:hover {
    transform: translateY(-2px);
    box-shadow: var(--admin-hover-shadow);
}

/* ========== УВЕДОМЛЕНИЯ ========== */
.alert {
    padding: 16px 20px;
    border-radius: 12px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 12px;
    animation: slideIn 0.3s ease;
    border: 1px solid transparent;
}

@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.alert-success {
    background: rgba(16, 185, 129, 0.15);
    border-color: rgba(16, 185, 129, 0.3);
    color: #047857;
}

.alert-danger {
    background: rgba(239, 68, 68, 0.15);
    border-color: rgba(239, 68, 68, 0.3);
    color: #b91c1c;
}

.alert-warning {
    background: rgba(245, 158, 11, 0.15);
    border-color: rgba(245, 158, 11, 0.3);
    color: #d97706;
}

.alert i {
    font-size: 18px;
}

.alert-success i {
    color: #10b981;
}

.alert-danger i {
    color: #ef4444;
}

.alert-warning i {
    color: #f59e0b;
}

/* ========== ФОРМА РЕДАКТИРОВАНИЯ ПОЛЬЗОВАТЕЛЯ ========== */
.user-form-container {
    background: var(--admin-card-bg);
    border-radius: 12px;
    border: 1px solid var(--admin-border);
    padding: 30px;
    box-shadow: var(--admin-shadow);
}

.form-section {
    margin-bottom: 40px;
    padding-bottom: 30px;
    border-bottom: 1px solid var(--admin-border);
}

.form-section:last-child {
    margin-bottom: 0;
    padding-bottom: 0;
    border-bottom: none;
}

.form-section-title {
    color: var(--admin-text);
    font-size: 18px;
    margin: 0 0 20px 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.form-section-title i {
    color: var(--admin-accent);
}

/* ========== СЕТКА ФОРМЫ ========== */
.form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 20px;
}

.form-group {
    margin-bottom: 20px;
}

.form-label {
    display: block;
    color: var(--admin-text);
    font-weight: 500;
    font-size: 14px;
    margin-bottom: 8px;
}

.form-label.required::after {
    content: " *";
    color: var(--admin-danger);
}

.form-input {
    width: 87%;
    padding: 12px 16px;
    border: 1px solid var(--admin-border);
    border-radius: 8px;
    background: var(--admin-card-bg);
    color: var(--admin-text);
    font-size: 14px;
    transition: all 0.3s ease;
}

.form-input:focus {
    outline: none;
    border-color: var(--admin-accent);
    box-shadow: 0 0 0 3px var(--admin-accent-light);
}

.form-input::placeholder {
    color: var(--admin-text-secondary);
    opacity: 0.7;
}

.form-hint {
    display: block;
    color: var(--admin-text-secondary);
    font-size: 12px;
    margin-top: 6px;
    line-height: 1.4;
}

.form-hint a {
    color: var(--admin-accent);
    text-decoration: none;
}

.form-hint a:hover {
    text-decoration: underline;
}

.form-hint code {
    background: var(--admin-accent-light);
    padding: 2px 6px;
    border-radius: 4px;
    font-family: monospace;
    font-size: 11px;
    color: var(--admin-accent);
}

/* ========== ПОЛЯ ПАРОЛЯ С КНОПКАМИ ========== */
.password-input-group {
    position: relative;
}

.password-toggle-btn {
    position: absolute;
    right: 40px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: var(--admin-text-secondary);
    cursor: pointer;
    padding: 8px;
    border-radius: 6px;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
}

.password-toggle-btn:hover {
    color: var(--admin-accent);
    background: var(--admin-accent-light);
}

.password-generate-btn {
    position: absolute;
    right: 8px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: var(--admin-text-secondary);
    cursor: pointer;
    padding: 8px;
    border-radius: 6px;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
}

.password-generate-btn:hover {
    color: var(--admin-accent);
    background: var(--admin-accent-light);
}

.password-input-group .form-input {
    padding-right: 80px;
}

/* ========== НАСТРОЙКИ ГЕНЕРАТОРА ПАРОЛЕЙ ========== */
.password-generator-settings {
    background: var(--admin-accent-light);
    border: 1px solid var(--admin-border);
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 20px;
}

.password-settings-title {
    color: var(--admin-text);
    font-size: 14px;
    font-weight: 600;
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.password-settings-title i {
    color: var(--admin-accent);
}

.password-settings-options {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 12px;
    margin-bottom: 15px;
}

.password-length-slider {
    grid-column: 1 / -1;
}

.length-display {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}

.length-label {
    color: var(--admin-text);
    font-size: 13px;
    font-weight: 500;
}

.length-value {
    color: var(--admin-accent);
    font-weight: 600;
    font-family: monospace;
    font-size: 14px;
}

.length-slider {
    width: 100%;
    height: 6px;
    -webkit-appearance: none;
    appearance: none;
    background: var(--admin-border);
    border-radius: 3px;
    outline: none;
}

.length-slider::-webkit-slider-thumb {
    -webkit-appearance: none;
    appearance: none;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: var(--admin-accent);
    cursor: pointer;
    border: 2px solid white;
    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
}

.password-options-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 10px;
    margin-bottom: 15px;
}

.password-option {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    user-select: none;
}

.password-option input[type="checkbox"] {
    width: 16px;
    height: 16px;
    margin: 0;
}

.password-option-label {
    color: var(--admin-text);
    font-size: 13px;
    font-weight: 500;
}

.password-generator-actions {
    display: flex;
    gap: 10px;
}

.password-generator-btn {
    flex: 1;
    padding: 10px 16px;
    border: none;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
}

.password-generator-btn-primary {
    background: linear-gradient(135deg, var(--admin-accent), var(--admin-accent-hover));
    color: white;
}

.password-generator-btn-secondary {
    background: var(--admin-card-bg);
    color: var(--admin-text);
    border: 1px solid var(--admin-border);
}

.password-generator-btn:hover {
    transform: translateY(-2px);
    box-shadow: var(--admin-hover-shadow);
}

.password-strength-meter {
    grid-column: 1 / -1;
    margin-top: 15px;
}

.strength-label {
    color: var(--admin-text);
    font-size: 13px;
    font-weight: 500;
    margin-bottom: 8px;
    display: flex;
    justify-content: space-between;
}

.strength-value {
    color: var(--admin-success);
    font-weight: 600;
    font-size: 12px;
}

.strength-bar {
    height: 8px;
    background: var(--admin-border);
    border-radius: 4px;
    overflow: hidden;
}

.strength-fill {
    height: 100%;
    width: 0%;
    border-radius: 4px;
    transition: all 0.3s ease;
}

.strength-weak .strength-fill {
    background: var(--admin-danger);
    width: 25%;
}

.strength-medium .strength-fill {
    background: var(--admin-warning);
    width: 50%;
}

.strength-good .strength-fill {
    background: var(--admin-accent);
    width: 75%;
}

.strength-strong .strength-fill {
    background: var(--admin-success);
    width: 100%;
}

/* ========== CHECKBOX И SWITCH ========== */
.checkbox-container {
    display: flex;
    align-items: center;
    cursor: pointer;
    color: var(--admin-text);
    font-size: 14px;
    user-select: none;
}

.checkbox-container input[type="checkbox"] {
    display: none;
}

.checkbox-container .checkmark {
    width: 20px;
    height: 20px;
    border: 2px solid var(--admin-border);
    border-radius: 6px;
    margin-right: 10px;
    position: relative;
    transition: all 0.3s ease;
}

.checkbox-container:hover .checkmark {
    border-color: var(--admin-accent);
}

.checkbox-container input[type="checkbox"]:checked + .checkmark {
    background: var(--admin-accent);
    border-color: var(--admin-accent);
}

.checkbox-container input[type="checkbox"]:checked + .checkmark::after {
    content: "";
    position: absolute;
    left: 5px;
    top: 2px;
    width: 6px;
    height: 10px;
    border: solid white;
    border-width: 0 2px 2px 0;
    transform: rotate(45deg);
}

/* ========== КНОПКИ ФОРМЫ ========== */
.form-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-top: 30px;
    border-top: 1px solid var(--admin-border);
}

.actions-right {
    display: flex;
    gap: 12px;
}

.btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 24px;
    border-radius: 8px;
    font-weight: 500;
    font-size: 14px;
    text-decoration: none;
    border: none;
    cursor: pointer;
    transition: all 0.3s ease;
}

.btn i {
    font-size: 16px;
}

.btn-secondary {
    background: var(--admin-card-bg);
    color: var(--admin-text);
    border: 1px solid var(--admin-border);
}

.btn-secondary:hover {
    background: var(--admin-accent-light);
    border-color: var(--admin-accent);
    transform: translateY(-2px);
    box-shadow: var(--admin-hover-shadow);
}

.btn-outline {
    background: transparent;
    color: var(--admin-text);
    border: 1px solid var(--admin-border);
}

.btn-outline:hover {
    background: var(--admin-accent-light);
    border-color: var(--admin-accent);
    transform: translateY(-2px);
    box-shadow: var(--admin-hover-shadow);
}

.btn-primary {
    background: linear-gradient(135deg, var(--admin-accent), var(--admin-accent-hover));
    color: white;
    border: none;
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: var(--admin-hover-shadow);
}

.btn-danger {
    background: linear-gradient(135deg, var(--admin-danger), #dc2626);
    color: white;
    border: none;
}

.btn-danger:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
}

/* ========== КАРТОЧКИ ИНФОРМАЦИИ ========== */
.info-card {
    background: var(--admin-accent-light);
    border: 1px solid var(--admin-accent);
    border-radius: 8px;
    padding: 16px;
    margin: 20px 0;
}

.info-card h4 {
    color: var(--admin-accent);
    font-size: 14px;
    margin: 0 0 8px 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.info-card p {
    color: var(--admin-text);
    font-size: 13px;
    margin: 0;
    line-height: 1.5;
}

/* ========== СТАТИСТИКА ПОЛЬЗОВАТЕЛЯ ========== */
.user-stats-overview {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-bottom: 30px;
}

.user-stat-item {
    background: var(--admin-card-bg);
    border: 1px solid var(--admin-border);
    border-radius: 8px;
    padding: 15px;
    display: flex;
    align-items: center;
    gap: 12px;
}

.user-stat-icon {
    width: 40px;
    height: 40px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    color: white;
}

.user-stat-icon.balance { background: linear-gradient(135deg, var(--admin-success), #059669); }
.user-stat-icon.machines { background: linear-gradient(135deg, var(--admin-accent), var(--admin-accent-hover)); }
.user-stat-icon.date { background: linear-gradient(135deg, var(--admin-purple), #7c3aed); }
.user-stat-icon.status { background: linear-gradient(135deg, var(--admin-warning), #d97706); }

.user-stat-content h4 {
    color: var(--admin-text-secondary);
    font-size: 11px;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin: 0 0 4px 0;
}

.user-stat-value {
    color: var(--admin-text);
    font-size: 18px;
    font-weight: 700;
    margin: 0;
}

/* ========== АДАПТИВНОСТЬ ========== */
@media (max-width: 1200px) {
    .dashboard-wrapper {
        margin-left: 70px !important;
    }
}

@media (max-width: 992px) {
    .dashboard-wrapper {
        margin-left: 0 !important;
        padding: 15px;
    }

    .dashboard-header {
        flex-direction: column;
        align-items: stretch;
        gap: 15px;
    }

    .user-form-container {
        padding: 20px;
    }

    .form-grid {
        grid-template-columns: 1fr;
        gap: 15px;
    }

    .form-actions {
        flex-direction: column;
        gap: 15px;
        align-items: stretch;
    }

    .actions-right {
        flex-direction: column;
        width: 100%;
    }

    .btn {
        width: 100%;
        justify-content: center;
    }

    .password-settings-options {
        grid-template-columns: 1fr;
    }

    .password-options-grid {
        grid-template-columns: 1fr;
    }

    .user-stats-overview {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .dashboard-header {
        padding: 20px;
    }

    .header-left h1 {
        font-size: 20px;
    }

    .form-section {
        padding-bottom: 20px;
        margin-bottom: 30px;
    }

    .form-section-title {
        font-size: 16px;
    }

    .password-generator-actions {
        flex-direction: column;
    }
}

/* ========== ВАЛИДАЦИЯ ========== */
.form-input:invalid:not(:focus):not(:placeholder-shown) {
    border-color: var(--admin-danger);
}

.form-input:valid:not(:focus):not(:placeholder-shown) {
    border-color: var(--admin-success);
}

/* ========== КАРТОЧНЫЕ ПОЛЯ ========== */
.card-number,
.card-expiry,
.card-cvv {
    font-family: 'Courier New', monospace;
    letter-spacing: 1px;
}

.card-number {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='20' height='16' viewBox='0 0 20 16'%3E%3Cpath fill='%239CA3AF' d='M18 0H2C.9 0 0 .9 0 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V2c0-1.1-.9-2-2-2zm0 14H2V6h16v8z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 16px center;
    background-size: 20px;
}

.card-expiry {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 16 16'%3E%3Cpath fill='%239CA3AF' d='M8 0C3.6 0 0 3.6 0 8s3.6 8 8 8 8-3.6 8-8-3.6-8-8-8zm0 14c-3.3 0-6-2.7-6-6s2.7-6 6-6 6 2.7 6 6-2.7 6-6 6zm.5-10H7v5l3.5 2.1.8-1.2-2.8-1.7V4z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 16px center;
    background-size: 16px;
}

/* ========== БЕЙДЖИ ========== */
.user-role-badge {
    display: inline-flex;
    align-items: center;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.role-admin {
    background: linear-gradient(135deg, var(--admin-danger), #dc2626);
    color: white;
}

.role-user {
    background: rgba(16, 185, 129, 0.2);
    color: #047857;
    border: 1px solid rgba(16, 185, 129, 0.3);
}

.user-type-badge {
    display: inline-flex;
    align-items: center;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
}

.type-individual {
    background: rgba(14, 165, 233, 0.15);
    color: var(--admin-accent);
    border: 1px solid rgba(14, 165, 233, 0.3);
}

.type-entrepreneur {
    background: rgba(245, 158, 11, 0.15);
    color: #f59e0b;
    border: 1px solid rgba(245, 158, 11, 0.3);
}

.type-legal {
    background: rgba(139, 92, 246, 0.15);
    color: #8b5cf6;
    border: 1px solid rgba(139, 92, 246, 0.3);
}
</style>

<!-- Подключаем сайдбар -->
<?php require 'admin_sidebar.php'; ?>

<!-- Дашборд -->
<div class="dashboard-wrapper">
    <!-- Шапка страницы -->
    <div class="dashboard-header">
        <div class="header-left">
            <h1><i class="fas fa-user-edit"></i> Редактирование пользователя</h1>
            <div style="display: flex; align-items: center; gap: 15px;">
                <span class="user-info-badge">
                    <i class="fas fa-hashtag"></i> ID: #<?= $user['id'] ?>
                </span>
                <span class="user-role-badge role-<?= $user['is_admin'] ? 'admin' : 'user' ?>">
                    <?= $user['is_admin'] ? 'Администратор' : 'Пользователь' ?>
                </span>
                <span class="user-type-badge type-<?= $user['user_type'] ?>">
                    <?=
                        $user['user_type'] === 'individual' ? 'Физ. лицо' :
                        ($user['user_type'] === 'entrepreneur' ? 'ИП' : 'Юр. лицо')
                    ?>
                </span>
            </div>
        </div>
        <div class="dashboard-quick-actions">
            <a href="users.php" class="dashboard-action-btn dashboard-action-btn-secondary">
                <i class="fas fa-arrow-left"></i> Назад к списку
            </a>
            <?php if (!$user['is_admin']): ?>
            <button type="button" class="dashboard-action-btn dashboard-action-btn-danger" id="deleteUserBtn">
                <i class="fas fa-trash"></i> Удалить
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Статистика пользователя -->
    <?php
    // Получаем статистику пользователя
    $stmt = $pdo->prepare("SELECT COUNT(*) as vm_count FROM vms WHERE user_id = ?");
    $stmt->execute([$user['id']]);
    $vm_stats = $stmt->fetch();

    $stmt = $pdo->prepare("SELECT COUNT(*) as payments_count, SUM(amount) as total_payments FROM payments WHERE user_id = ? AND status = 'completed'");
    $stmt->execute([$user['id']]);
    $payment_stats = $stmt->fetch();
    ?>

    <div class="user-stats-overview">
        <div class="user-stat-item">
            <div class="user-stat-icon balance">
                <i class="fas fa-credit-card"></i>
            </div>
            <div class="user-stat-content">
                <h4>Баланс</h4>
                <div class="user-stat-value"><?= number_format($user['balance'], 2) ?> ₽</div>
            </div>
        </div>

        <div class="user-stat-item">
            <div class="user-stat-icon machines">
                <i class="fas fa-server"></i>
            </div>
            <div class="user-stat-content">
                <h4>Виртуальные машины</h4>
                <div class="user-stat-value"><?= $vm_stats['vm_count'] ?? 0 ?></div>
            </div>
        </div>

        <div class="user-stat-item">
            <div class="user-stat-icon date">
                <i class="fas fa-calendar-alt"></i>
            </div>
            <div class="user-stat-content">
                <h4>Дата регистрации</h4>
                <div class="user-stat-value"><?= date('d.m.Y', strtotime($user['created_at'])) ?></div>
            </div>
        </div>

        <div class="user-stat-item">
            <div class="user-stat-icon status">
                <i class="fas fa-chart-line"></i>
            </div>
            <div class="user-stat-content">
                <h4>Всего платежей</h4>
                <div class="user-stat-value"><?= $payment_stats['total_payments'] ? number_format($payment_stats['total_payments'], 2) . ' ₽' : '0 ₽' ?></div>
            </div>
        </div>
    </div>

    <!-- Уведомления -->
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($_SESSION['error']) ?>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> <?= htmlspecialchars($_SESSION['success']) ?>
        </div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <!-- Форма редактирования пользователя -->
    <div class="user-form-container">
        <form method="POST" id="userEditForm">
            <!-- Основная информация -->
            <div class="form-section">
                <h3 class="form-section-title">
                    <i class="fas fa-user"></i> Основная информация
                </h3>

                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label required">Email</label>
                        <input type="email" name="email" class="form-input" required
                               value="<?= htmlspecialchars($user['email']) ?>"
                               placeholder="user@example.com" autocomplete="off">
                    </div>

                    <div class="form-group">
                        <label class="form-label required">ФИО</label>
                        <input type="text" name="full_name" class="form-input" required
                               value="<?= htmlspecialchars($user['full_name']) ?>"
                               placeholder="Иванов Иван Иванович" autocomplete="off">
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Баланс (руб.)</label>
                        <input type="number" name="balance" min="0" step="0.01"
                               class="form-input" value="<?= htmlspecialchars($user['balance']) ?>" required autocomplete="off">
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Тип пользователя</label>
                        <select name="user_type" class="form-input" id="user-type-select" required>
                            <option value="individual" <?= $user['user_type'] === 'individual' ? 'selected' : '' ?>>Физическое лицо</option>
                            <option value="entrepreneur" <?= $user['user_type'] === 'entrepreneur' ? 'selected' : '' ?>>Индивидуальный предприниматель</option>
                            <option value="legal" <?= $user['user_type'] === 'legal' ? 'selected' : '' ?>>Юридическое лицо</option>
                        </select>
                    </div>
                </div>

                <div class="form-grid">
                    <div class="form-group" id="company-name-group" style="display: <?= $user['user_type'] === 'individual' ? 'none' : 'block' ?>;">
                        <label class="form-label required" id="company-name-label">Название компании</label>
                        <input type="text" name="company_name" class="form-input"
                               value="<?= htmlspecialchars($user['company_name']) ?>"
                               placeholder="<?= $user['user_type'] === 'entrepreneur' ? "ИП 'Иванов Иван Иванович'" : "ООО 'Ромашка'" ?>"
                               autocomplete="off">
                    </div>

                    <div class="form-group" id="inn-group">
                        <label class="form-label required">ИНН</label>
                        <input type="text" name="inn" class="form-input"
                               value="<?= htmlspecialchars($user['inn']) ?>"
                               placeholder="<?= $user['user_type'] === 'individual' ? '123456789012' : '1234567890' ?>"
                               pattern="<?= $user['user_type'] === 'individual' ? '\d{12}' : '\d{10}' ?>"
                               title="<?= $user['user_type'] === 'individual' ? 'ИНН должен содержать 12 цифр для физ. лица' : 'ИНН должен содержать 10 цифр для ИП/юр. лица' ?>"
                               autocomplete="off">
                        <small class="form-hint">
                            <?= $user['user_type'] === 'individual' ? '12 цифр для физ. лиц' : '10 цифр для ИП и юр. лиц' ?>
                        </small>
                    </div>

                    <div class="form-group" id="kpp-group" style="display: <?= $user['user_type'] === 'legal' ? 'block' : 'none' ?>;">
                        <label class="form-label required">КПП</label>
                        <input type="text" name="kpp" class="form-input"
                               value="<?= htmlspecialchars($user['kpp']) ?>"
                               pattern="\d{9}" title="КПП должен содержать 9 цифр"
                               placeholder="123456789" autocomplete="off">
                        <small class="form-hint">Только для юридических лиц</small>
                    </div>
                </div>
            </div>

            <!-- Смена пароля -->
            <div class="form-section">
                <h3 class="form-section-title">
                    <i class="fas fa-lock"></i> Смена пароля
                </h3>

                <div class="alert alert-warning">
                    <i class="fas fa-info-circle"></i> Заполняйте только если хотите изменить пароль пользователя
                </div>

                <!-- Генератор паролей -->
                <div class="password-generator-settings" id="passwordGenerator">
                    <div class="password-settings-title">
                        <i class="fas fa-key"></i> Генератор паролей
                    </div>

                    <div class="password-settings-options">
                        <div class="password-length-slider">
                            <div class="length-display">
                                <span class="length-label">Длина пароля:</span>
                                <span class="length-value" id="passwordLengthValue">12</span>
                            </div>
                            <input type="range" min="8" max="32" value="12" class="length-slider" id="passwordLengthSlider">
                        </div>

                        <div class="password-options-grid">
                            <label class="password-option">
                                <input type="checkbox" id="includeUppercase" checked>
                                <span class="password-option-label">A-Z (Прописные)</span>
                            </label>
                            <label class="password-option">
                                <input type="checkbox" id="includeLowercase" checked>
                                <span class="password-option-label">a-z (Строчные)</span>
                            </label>
                            <label class="password-option">
                                <input type="checkbox" id="includeNumbers" checked>
                                <span class="password-option-label">0-9 (Цифры)</span>
                            </label>
                            <label class="password-option">
                                <input type="checkbox" id="includeSymbols" checked>
                                <span class="password-option-label">!@#$% (Символы)</span>
                            </label>
                        </div>

                        <div class="password-strength-meter" id="passwordStrength">
                            <div class="strength-label">
                                <span>Надежность пароля:</span>
                                <span class="strength-value" id="strengthValue">Сильный</span>
                            </div>
                            <div class="strength-bar">
                                <div class="strength-fill"></div>
                            </div>
                        </div>
                    </div>

                    <div class="password-generator-actions">
                        <button type="button" class="password-generator-btn password-generator-btn-secondary" id="generatePasswordBtn">
                            <i class="fas fa-sync-alt"></i> Сгенерировать
                        </button>
                        <button type="button" class="password-generator-btn password-generator-btn-primary" id="applyPasswordBtn">
                            <i class="fas fa-check"></i> Применить пароль
                        </button>
                    </div>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Новый пароль</label>
                        <div class="password-input-group">
                            <input type="password" name="new_password" class="form-input" id="passwordInput"
                                   minlength="8" autocomplete="new-password">
                            <button type="button" class="password-toggle-btn" id="passwordToggle">
                                <i class="fas fa-eye"></i>
                            </button>
                            <button type="button" class="password-generate-btn" id="quickGenerateBtn" title="Сгенерировать пароль">
                                <i class="fas fa-key"></i>
                            </button>
                        </div>
                        <small class="form-hint">Минимум 8 символов</small>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Подтверждение пароля</label>
                        <div class="password-input-group">
                            <input type="password" name="confirm_password" class="form-input" id="confirmPasswordInput"
                                   minlength="8" autocomplete="new-password">
                            <button type="button" class="password-toggle-btn" id="confirmPasswordToggle">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Telegram ID -->
            <div class="form-section">
                <h3 class="form-section-title">
                    <i class="fab fa-telegram"></i> Telegram уведомления
                </h3>

                <div class="info-card">
                    <h4><i class="fas fa-info-circle"></i> Как получить Telegram ID?</h4>
                    <p>
                        1. Пользователь должен написать <code>/start</code> боту
                        <a href="https://t.me/homevlad_notify_bot" target="_blank">@homevlad_notify_bot</a><br>
                        2. Или использовать <a href="https://t.me/userinfobot" target="_blank">@userinfobot</a> для получения ID
                    </p>
                </div>

                <div class="form-group">
                    <label class="form-label">Telegram ID</label>
                    <input type="text" name="telegram_id" class="form-input"
                           value="<?= htmlspecialchars($user['telegram_id'] ?? '') ?>"
                           pattern="\d+" title="Только цифры"
                           placeholder="123456789" autocomplete="off">
                    <small class="form-hint">Оставьте пустым, если Telegram не используется</small>
                </div>
            </div>

            <!-- Права доступа -->
            <div class="form-section">
                <h3 class="form-section-title">
                    <i class="fas fa-shield-alt"></i> Права доступа
                </h3>

                <div class="form-group">
                    <label class="checkbox-container">
                        <input type="checkbox" name="is_admin" id="is_admin" <?= $user['is_admin'] ? 'checked' : '' ?>>
                        <span class="checkmark"></span>
                        Предоставить права администратора
                    </label>
                    <small class="form-hint">Администраторы имеют полный доступ к панели управления</small>
                </div>

                <div class="form-group">
                    <label class="checkbox-container">
                        <input type="checkbox" name="is_active" id="is_active" <?= $user['is_active'] ? 'checked' : '' ?>>
                        <span class="checkmark"></span>
                        Активировать пользователя
                    </label>
                    <small class="form-hint">Предоставить пользователю доступ к личному кабинету</small>
                </div>
            </div>

            <!-- Платежная информация -->
            <div class="form-section">
                <h3 class="form-section-title">
                    <i class="fas fa-credit-card"></i> Платежная информация
                </h3>
                <small class="form-hint" style="display: block; margin-bottom: 20px;">
                    Эти данные можно оставить пустыми
                </small>

                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Имя владельца карты</label>
                        <input type="text" name="card_holder" class="form-input"
                               value="<?= htmlspecialchars($payment_info['card_holder'] ?? '') ?>"
                               placeholder="IVAN IVANOV" autocomplete="off">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Номер карты</label>
                        <input type="text" name="card_number" class="form-input card-number"
                               value="<?= htmlspecialchars($payment_info['card_number'] ?? '') ?>"
                               placeholder="XXXX XXXX XXXX XXXX" maxlength="19" autocomplete="off">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Срок действия</label>
                        <input type="text" name="card_expiry" class="form-input card-expiry"
                               value="<?= htmlspecialchars($payment_info['card_expiry'] ?? '') ?>"
                               placeholder="MM/YY" maxlength="5" autocomplete="off">
                    </div>

                    <div class="form-group">
                        <label class="form-label">CVV код</label>
                        <input type="text" name="card_cvv" class="form-input card-cvv"
                               value="<?= htmlspecialchars($payment_info['card_cvv'] ?? '') ?>"
                               placeholder="XXX" maxlength="3" autocomplete="off">
                    </div>
                </div>
            </div>

            <!-- Кнопки действий -->
            <div class="form-actions">
                <a href="users.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Отмена
                </a>
                <div class="actions-right">
                    <button type="reset" class="btn btn-outline">
                        <i class="fas fa-undo"></i> Сбросить изменения
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Сохранить изменения
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Анимация появления формы
    const formContainer = document.querySelector('.user-form-container');
    formContainer.style.opacity = '0';
    formContainer.style.transform = 'translateY(20px)';

    setTimeout(() => {
        formContainer.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
        formContainer.style.opacity = '1';
        formContainer.style.transform = 'translateY(0)';
    }, 100);

    // Управление отступом при сворачивании сайдбара
    const sidebar = document.querySelector('.admin-sidebar');
    const dashboard = document.querySelector('.dashboard-wrapper');

    if (sidebar && dashboard) {
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.attributeName === 'class') {
                    if (sidebar.classList.contains('compact')) {
                        dashboard.style.marginLeft = '70px';
                    } else {
                        dashboard.style.marginLeft = '280px';
                    }
                }
            });
        });

        observer.observe(sidebar, { attributes: true });
    }

    // Управление полями в зависимости от типа пользователя
    const userTypeSelect = document.getElementById('user-type-select');
    const companyNameGroup = document.getElementById('company-name-group');
    const companyNameInput = document.querySelector('input[name="company_name"]');
    const companyNameLabel = document.getElementById('company-name-label');
    const innGroup = document.getElementById('inn-group');
    const innInput = document.querySelector('input[name="inn"]');
    const kppGroup = document.getElementById('kpp-group');
    const kppInput = document.querySelector('input[name="kpp"]');

    function updateUserTypeFields() {
        const userType = userTypeSelect.value;

        // Сбрасываем required для скрытых полей
        companyNameInput.required = false;
        kppInput.required = false;

        if (userType === 'individual') {
            companyNameGroup.style.display = 'none';
            kppGroup.style.display = 'none';
            innInput.pattern = "\\d{12}";
            innInput.placeholder = "123456789012";
            innInput.title = "ИНН должен содержать 12 цифр для физ. лица";
        } else if (userType === 'entrepreneur') {
            companyNameGroup.style.display = 'block';
            companyNameLabel.innerHTML = 'Название ИП <span style="color: var(--admin-danger)">*</span>';
            companyNameInput.placeholder = "ИП 'Иванов Иван Иванович'";
            companyNameInput.required = true;
            kppGroup.style.display = 'none';
            innInput.pattern = "\\d{10}";
            innInput.placeholder = "1234567890";
            innInput.title = "ИНН должен содержать 10 цифр для ИП";
        } else if (userType === 'legal') {
            companyNameGroup.style.display = 'block';
            companyNameLabel.innerHTML = 'Название компании <span style="color: var(--admin-danger)">*</span>';
            companyNameInput.placeholder = "ООО 'Ромашка'";
            companyNameInput.required = true;
            kppGroup.style.display = 'block';
            kppInput.required = true;
            innInput.pattern = "\\d{10}";
            innInput.placeholder = "1234567890";
            innInput.title = "ИНН должен содержать 10 цифр для юр. лица";
        }
    }

    userTypeSelect.addEventListener('change', updateUserTypeFields);
    updateUserTypeFields(); // Инициализация при загрузке

    // ========== ГЕНЕРАТОР ПАРОЛЕЙ ==========
    const passwordInput = document.getElementById('passwordInput');
    const confirmPasswordInput = document.getElementById('confirmPasswordInput');
    const passwordToggle = document.getElementById('passwordToggle');
    const confirmPasswordToggle = document.getElementById('confirmPasswordToggle');
    const quickGenerateBtn = document.getElementById('quickGenerateBtn');
    const generatePasswordBtn = document.getElementById('generatePasswordBtn');
    const applyPasswordBtn = document.getElementById('applyPasswordBtn');
    const passwordLengthSlider = document.getElementById('passwordLengthSlider');
    const passwordLengthValue = document.getElementById('passwordLengthValue');
    const includeUppercase = document.getElementById('includeUppercase');
    const includeLowercase = document.getElementById('includeLowercase');
    const includeNumbers = document.getElementById('includeNumbers');
    const includeSymbols = document.getElementById('includeSymbols');
    const passwordStrength = document.getElementById('passwordStrength');
    const strengthValue = document.getElementById('strengthValue');

    // Символы для генерации пароля
    const uppercaseChars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    const lowercaseChars = 'abcdefghijklmnopqrstuvwxyz';
    const numberChars = '0123456789';
    const symbolChars = '!@#$%^&*()_+-=[]{}|;:,.<>?';

    // Функция генерации пароля
    function generatePassword() {
        const length = parseInt(passwordLengthSlider.value);
        let charset = '';
        let password = '';

        if (includeUppercase.checked) charset += uppercaseChars;
        if (includeLowercase.checked) charset += lowercaseChars;
        if (includeNumbers.checked) charset += numberChars;
        if (includeSymbols.checked) charset += symbolChars;

        // Если ничего не выбрано, используем все символы
        if (charset.length === 0) {
            charset = uppercaseChars + lowercaseChars + numberChars;
            includeUppercase.checked = true;
            includeLowercase.checked = true;
            includeNumbers.checked = true;
        }

        // Генерируем пароль
        for (let i = 0; i < length; i++) {
            const randomIndex = Math.floor(Math.random() * charset.length);
            password += charset[randomIndex];
        }

        return password;
    }

    // Функция проверки надежности пароля
    function checkPasswordStrength(password) {
        let score = 0;

        if (password.length >= 8) score++;
        if (password.length >= 12) score++;
        if (password.length >= 16) score++;

        if (/[A-Z]/.test(password)) score++;
        if (/[a-z]/.test(password)) score++;
        if (/[0-9]/.test(password)) score++;
        if (/[^A-Za-z0-9]/.test(password)) score++;

        // Оцениваем сложность
        if (score <= 2) {
            return { level: 'weak', text: 'Слабый' };
        } else if (score <= 4) {
            return { level: 'medium', text: 'Средний' };
        } else if (score <= 6) {
            return { level: 'good', text: 'Хороший' };
        } else {
            return { level: 'strong', text: 'Сильный' };
        }
    }

    // Функция обновления отображения надежности пароля
    function updatePasswordStrength(password) {
        const strength = checkPasswordStrength(password);
        passwordStrength.className = 'password-strength-meter strength-' + strength.level;
        strengthValue.textContent = strength.text;

        // Анимация изменения
        passwordStrength.style.transform = 'scale(1.02)';
        setTimeout(() => {
            passwordStrength.style.transform = 'scale(1)';
        }, 200);
    }

    // Обновление длины пароля
    passwordLengthSlider.addEventListener('input', function() {
        passwordLengthValue.textContent = this.value;
    });

    // Быстрая генерация пароля
    quickGenerateBtn.addEventListener('click', function() {
        const password = generatePassword();
        passwordInput.value = password;
        confirmPasswordInput.value = password;
        updatePasswordStrength(password);

        // Показываем пароль на секунду
        passwordInput.type = 'text';
        confirmPasswordInput.type = 'text';
        setTimeout(() => {
            passwordInput.type = 'password';
            confirmPasswordInput.type = 'password';
        }, 1000);
    });

    // Генерация и показ пароля
    generatePasswordBtn.addEventListener('click', function() {
        const password = generatePassword();
        passwordInput.value = password;
        updatePasswordStrength(password);

        // Анимация кнопки
        this.style.transform = 'rotate(360deg)';
        setTimeout(() => {
            this.style.transform = '';
        }, 500);
    });

    // Применение сгенерированного пароля к обоим полям
    applyPasswordBtn.addEventListener('click', function() {
        const password = passwordInput.value;
        if (password.length >= 8) {
            confirmPasswordInput.value = password;

            // Анимация успеха
            this.innerHTML = '<i class="fas fa-check"></i> Применено!';
            this.style.background = 'linear-gradient(135deg, var(--admin-success), #059669)';

            setTimeout(() => {
                this.innerHTML = '<i class="fas fa-check"></i> Применить пароль';
                this.style.background = 'linear-gradient(135deg, var(--admin-accent), var(--admin-accent-hover))';
            }, 1500);
        } else {
            alert('Пароль должен содержать минимум 8 символов');
        }
    });

    // Отслеживание изменения пароля
    passwordInput.addEventListener('input', function() {
        updatePasswordStrength(this.value);
    });

    // Переключение видимости пароля
    passwordToggle.addEventListener('click', function() {
        const type = passwordInput.type === 'password' ? 'text' : 'password';
        passwordInput.type = type;
        this.innerHTML = type === 'password' ? '<i class="fas fa-eye"></i>' : '<i class="fas fa-eye-slash"></i>';
    });

    // Переключение видимости подтверждения пароля
    confirmPasswordToggle.addEventListener('click', function() {
        const type = confirmPasswordInput.type === 'password' ? 'text' : 'password';
        confirmPasswordInput.type = type;
        this.innerHTML = type === 'password' ? '<i class="fas fa-eye"></i>' : '<i class="fas fa-eye-slash"></i>';
    });

    // Форматирование номера карты
    const cardNumberInput = document.querySelector('input.card-number');
    if (cardNumberInput) {
        cardNumberInput.addEventListener('input', function(e) {
            let value = e.target.value.replace(/\s+/g, '');
            if (value.length > 0) {
                value = value.match(new RegExp('.{1,4}', 'g')).join(' ');
            }
            e.target.value = value;
        });
    }

    // Форматирование срока действия карты
    const cardExpiryInput = document.querySelector('input.card-expiry');
    if (cardExpiryInput) {
        cardExpiryInput.addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length > 2) {
                value = value.substring(0, 2) + '/' + value.substring(2, 4);
            }
            e.target.value = value;
        });
    }

    // Ограничение CVV кода
    const cardCvvInput = document.querySelector('input.card-cvv');
    if (cardCvvInput) {
        cardCvvInput.addEventListener('input', function(e) {
            e.target.value = e.target.value.replace(/\D/g, '').substring(0, 3);
        });
    }

    // Валидация формы
    const form = document.getElementById('userEditForm');
    form.addEventListener('submit', function(e) {
        const newPassword = document.querySelector('input[name="new_password"]').value;
        const confirmPassword = document.querySelector('input[name="confirm_password"]').value;

        if (newPassword && newPassword !== confirmPassword) {
            e.preventDefault();
            alert('Пароли не совпадают!');
            return false;
        }

        if (newPassword && newPassword.length < 8) {
            e.preventDefault();
            alert('Новый пароль должен содержать минимум 8 символов!');
            return false;
        }

        // Валидация Telegram ID
        const telegramId = document.querySelector('input[name="telegram_id"]').value;
        if (telegramId && !/^\d+$/.test(telegramId)) {
            e.preventDefault();
            alert('Telegram ID должен содержать только цифры!');
            return false;
        }

        // Валидация баланса
        const balance = parseFloat(document.querySelector('input[name="balance"]').value);
        if (balance < 0) {
            e.preventDefault();
            alert('Баланс не может быть отрицательным!');
            return false;
        }

        // Валидация ИНН в зависимости от типа пользователя
        const userType = userTypeSelect.value;
        const inn = innInput.value;

        if (userType === 'individual' && inn && !/^\d{12}$/.test(inn)) {
            e.preventDefault();
            alert('ИНН для физического лица должен содержать 12 цифр!');
            return false;
        }

        if ((userType === 'entrepreneur' || userType === 'legal') && inn && !/^\d{10}$/.test(inn)) {
            e.preventDefault();
            alert('ИНН для ИП и юр. лиц должен содержать 10 цифр!');
            return false;
        }

        // Валидация КПП для юр. лиц
        if (userType === 'legal' && kppInput.value && !/^\d{9}$/.test(kppInput.value)) {
            e.preventDefault();
            alert('КПП должен содержать 9 цифр для юридического лица!');
            return false;
        }

        // Предупреждение о смене пароля
        if (newPassword) {
            if (!confirm('Вы уверены, что хотите изменить пароль пользователя?')) {
                e.preventDefault();
                return false;
            }
        }

        return true;
    });

    // Показать предупреждение при выборе администратора
    const adminCheckbox = document.getElementById('is_admin');
    adminCheckbox.addEventListener('change', function() {
        if (this.checked) {
            if (!confirm('Вы уверены, что хотите предоставить права администратора? Пользователь получит полный доступ к панели управления.')) {
                this.checked = false;
            }
        }
    });

    // Кнопка удаления пользователя
    const deleteUserBtn = document.getElementById('deleteUserBtn');
    if (deleteUserBtn) {
        deleteUserBtn.addEventListener('click', function() {
            if (confirm('ВНИМАНИЕ! Вы уверены, что хотите удалить пользователя?\n\nВсе связанные данные (ВМ, платежи, тикеты, баланс) будут удалены без возможности восстановления!')) {
                window.location.href = 'users.php?delete_id=<?= $user['id'] ?>';
            }
        });
    }

    // Анимация фокуса на полях формы
    const formInputs = document.querySelectorAll('.form-input');
    formInputs.forEach(input => {
        input.addEventListener('focus', function() {
            this.parentElement.style.transform = 'translateY(-2px)';
        });

        input.addEventListener('blur', function() {
            this.parentElement.style.transform = 'translateY(0)';
        });
    });

    // Отслеживание изменений формы
    let formChanged = false;
    const initialFormData = new FormData(form);

    form.addEventListener('input', function() {
        formChanged = true;
    });

    form.addEventListener('submit', function() {
        formChanged = false;
    });

    window.addEventListener('beforeunload', function(e) {
        if (formChanged) {
            e.preventDefault();
            e.returnValue = 'У вас есть несохраненные изменения. Вы уверены, что хотите покинуть страницу?';
        }
    });
});
</script>

<?php require 'admin_footer.php'; ?>
