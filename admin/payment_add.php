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

$errors = [];
$success = false;

// Получение списка пользователей
$users = $pdo->query("SELECT id, email, full_name FROM users ORDER BY email")->fetchAll(PDO::FETCH_ASSOC);

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = (int)$_POST['user_id'];
    $amount = (float)$_POST['amount'];
    $description = trim($_POST['description']);
    $status = $_POST['status'];
    $control_word = trim($_POST['control_word']) ?: generateControlWord();
    $payment_type = $_POST['payment_type'] ?? 'manual';

    try {
        // Валидация
        if ($user_id <= 0) {
            throw new Exception('Выберите пользователя');
        }

        if ($amount <= 0) {
            throw new Exception('Сумма должна быть больше 0');
        }

        if (empty($description)) {
            throw new Exception('Укажите описание платежа');
        }

        // Проверка существования пользователя
        $stmt = $pdo->prepare("SELECT id, balance FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        
        if (!$user) {
            throw new Exception('Пользователь не найден');
        }

        // Создание платежа
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO payments 
            (user_id, amount, description, status, control_word, payment_type, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([$user_id, $amount, $description, $status, $control_word, $payment_type]);

        $payment_id = $pdo->lastInsertId();

        // Если статус "completed", пополняем баланс
        if ($status === 'completed') {
            $new_balance = $user['balance'] + $amount;
            $stmt = $pdo->prepare("UPDATE users SET balance = ? WHERE id = ?");
            $stmt->execute([$new_balance, $user_id]);
            
            // Логируем изменение баланса
            $stmt = $pdo->prepare("
                INSERT INTO balance_history 
                (user_id, payment_id, old_balance, amount, new_balance, type, description, created_at)
                VALUES (?, ?, ?, ?, ?, 'deposit', ?, NOW())
            ");
            $stmt->execute([$user_id, $payment_id, $user['balance'], $amount, $new_balance, $description]);
        }

        $pdo->commit();

        $_SESSION['admin_message'] = [
            'type' => 'success', 
            'text' => 'Платеж успешно добавлен' . ($status === 'completed' ? ' и баланс пополнен' : '')
        ];
        header("Location: payments.php");
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        $errors[] = $e->getMessage();
    }
}

// Функция генерации контрольного слова
function generateControlWord() {
    $adjectives = ['быстрый', 'надежный', 'безопасный', 'удобный', 'современный', 'умный', 'цифровой'];
    $nouns = ['платеж', 'перевод', 'взнос', 'депозит', 'баланс', 'счет', 'кошелек'];
    $animals = ['тигр', 'медведь', 'волк', 'орел', 'дельфин', 'ястреб', 'сокол'];

    $adj = $adjectives[array_rand($adjectives)];
    $noun = $nouns[array_rand($nouns)];
    $animal = $animals[array_rand($animals)];

    return ucfirst($adj) . ucfirst($noun) . ucfirst($animal) . rand(100, 999);
}

$title = "Добавить платеж | HomeVlad Cloud";
require 'admin_header.php';
?>

<style>
/* Стили для формы добавления платежа */
:root {
    --form-bg: #f8fafc;
    --form-card-bg: #ffffff;
    --form-border: #e2e8f0;
    --form-text: #1e293b;
    --form-text-secondary: #64748b;
    --form-hover: #f1f5f9;
    --form-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
    --form-accent: #00bcd4;
    --form-success: #10b981;
    --form-warning: #f59e0b;
    --form-danger: #ef4444;
    --form-info: #3b82f6;
}

[data-theme="dark"] {
    --form-bg: #0f172a;
    --form-card-bg: #1e293b;
    --form-border: #334155;
    --form-text: #ffffff;
    --form-text-secondary: #cbd5e1;
    --form-hover: #2d3748;
    --form-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.3), 0 1px 2px 0 rgba(0, 0, 0, 0.2);
}

.payment-add-wrapper {
    padding: 20px;
    background: var(--form-bg);
    min-height: calc(100vh - 70px);
    margin-left: 280px;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.admin-sidebar.compact + .payment-add-wrapper {
    margin-left: 70px;
}

.payment-add-header {
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

.header-left h1 {
    color: var(--form-text);
    font-size: 24px;
    margin: 0 0 8px 0;
    display: flex;
    align-items: center;
    gap: 12px;
}

.header-left h1 i {
    color: var(--form-accent);
}

.header-left p {
    color: var(--form-text-secondary);
    font-size: 14px;
    margin: 0;
}

.payment-add-content {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 25px;
}

.payment-form-card {
    background: var(--form-card-bg);
    border: 1px solid var(--form-border);
    border-radius: 12px;
    overflow: hidden;
    box-shadow: var(--form-shadow);
}

.form-card-header {
    padding: 20px;
    border-bottom: 1px solid var(--form-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: linear-gradient(135deg, var(--form-accent), #0097a7);
    color: white;
}

.form-card-header h3 {
    font-size: 18px;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.form-card-body {
    padding: 25px;
}

/* Стили формы */
.admin-form {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.form-group {
    position: relative;
}

.form-group label {
    display: block;
    color: var(--form-text);
    font-size: 14px;
    font-weight: 500;
    margin-bottom: 8px;
}

.form-group label.required::after {
    content: ' *';
    color: var(--form-danger);
}

.form-control {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid var(--form-border);
    border-radius: 8px;
    background: var(--form-bg);
    color: var(--form-text);
    font-size: 14px;
    transition: all 0.3s ease;
}

.form-control:focus {
    outline: none;
    border-color: var(--form-accent);
    box-shadow: 0 0 0 3px rgba(0, 188, 212, 0.1);
}

.form-control:disabled {
    background: var(--form-hover);
    opacity: 0.7;
    cursor: not-allowed;
}

.form-control-select {
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 16px center;
    padding-right: 40px;
}

.text-muted {
    color: var(--form-text-secondary);
    font-size: 12px;
    margin-top: 4px;
    display: block;
}

/* Стили для опций пользователей */
.user-option {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 0;
}

.user-email {
    font-weight: 500;
    color: var(--form-text);
}

.user-balance {
    font-size: 12px;
    color: var(--form-text-secondary);
}

.user-balance.positive {
    color: var(--form-success);
    font-weight: 600;
}

.user-balance.negative {
    color: var(--form-danger);
}

/* Иконка обновления контрольного слова */
.control-word-group {
    position: relative;
}

.refresh-control-word {
    position: absolute;
    right: 12px;
    top: 35px;
    background: none;
    border: none;
    color: var(--form-accent);
    cursor: pointer;
    padding: 4px;
    border-radius: 4px;
    transition: all 0.3s ease;
}

.refresh-control-word:hover {
    background: rgba(0, 188, 212, 0.1);
    transform: rotate(180deg);
}

/* Статусы платежа */
.payment-status-option {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 12px;
    border-radius: 6px;
    margin: 4px 0;
    transition: all 0.3s ease;
}

.payment-status-option:hover {
    background: var(--form-hover);
}

.status-indicator {
    width: 8px;
    height: 8px;
    border-radius: 50%;
}

.status-indicator.pending {
    background: var(--form-warning);
    animation: pulse 2s infinite;
}

.status-indicator.completed {
    background: var(--form-success);
}

.status-indicator.failed {
    background: var(--form-danger);
}

/* Информационная панель */
.payment-info-card {
    background: var(--form-card-bg);
    border: 1px solid var(--form-border);
    border-radius: 12px;
    overflow: hidden;
    box-shadow: var(--form-shadow);
}

.info-card-header {
    padding: 20px;
    border-bottom: 1px solid var(--form-border);
}

.info-card-header h3 {
    color: var(--form-text);
    font-size: 16px;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.info-card-body {
    padding: 20px;
}

.info-list {
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.info-item {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.info-label {
    color: var(--form-text-secondary);
    font-size: 12px;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.info-value {
    color: var(--form-text);
    font-size: 14px;
    font-weight: 600;
}

.info-value.success {
    color: var(--form-success);
}

.info-value.warning {
    color: var(--form-warning);
}

.info-value.danger {
    color: var(--form-danger);
}

/* Подсказки */
.payment-hints {
    margin-top: 25px;
    padding: 20px;
    background: rgba(0, 188, 212, 0.05);
    border: 1px solid rgba(0, 188, 212, 0.2);
    border-radius: 8px;
}

.payment-hints h4 {
    color: var(--form-accent);
    font-size: 14px;
    margin: 0 0 10px 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.payment-hints ul {
    margin: 0;
    padding-left: 20px;
}

.payment-hints li {
    color: var(--form-text-secondary);
    font-size: 13px;
    margin-bottom: 6px;
    line-height: 1.4;
}

/* Кнопки */
.form-actions {
    display: flex;
    gap: 12px;
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid var(--form-border);
}

.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 12px 24px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 500;
    text-decoration: none;
    cursor: pointer;
    transition: all 0.3s ease;
    border: none;
}

.btn-primary {
    background: linear-gradient(135deg, var(--form-accent), #0097a7);
    color: white;
    flex: 1;
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 20px rgba(0, 188, 212, 0.2);
}

.btn-secondary {
    background: var(--form-card-bg);
    color: var(--form-text);
    border: 1px solid var(--form-border);
}

.btn-secondary:hover {
    background: var(--form-hover);
    transform: translateY(-2px);
}

.btn-icon {
    font-size: 16px;
}

/* Оповещения */
.alert {
    padding: 16px 20px;
    border-radius: 8px;
    margin-bottom: 20px;
    border-left: 4px solid;
    animation: slideIn 0.3s ease;
}

.alert-danger {
    background: rgba(239, 68, 68, 0.1);
    border-left-color: var(--form-danger);
    color: var(--form-danger);
}

.alert-danger i {
    margin-right: 8px;
}

.alert-danger p {
    margin: 4px 0;
}

/* Анимации */
@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
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

/* Адаптивность */
@media (max-width: 1200px) {
    .payment-add-wrapper {
        margin-left: 70px !important;
    }
}

@media (max-width: 992px) {
    .payment-add-content {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .payment-add-wrapper {
        margin-left: 0 !important;
        padding: 15px;
    }
    
    .payment-add-header {
        flex-direction: column;
        align-items: stretch;
        gap: 15px;
    }
    
    .form-actions {
        flex-direction: column;
    }
    
    .btn {
        width: 100%;
    }
}

/* Стили для выбранного пользователя */
.selected-user-info {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px;
    background: var(--form-hover);
    border: 1px solid var(--form-border);
    border-radius: 8px;
    margin-bottom: 15px;
}

.selected-user-avatar {
    width: 40px;
    height: 40px;
    border-radius: 8px;
    background: linear-gradient(135deg, var(--form-accent), #0097a7);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 16px;
}

.selected-user-details {
    flex: 1;
}

.selected-user-name {
    color: var(--form-text);
    font-weight: 500;
    margin-bottom: 2px;
}

.selected-user-balance {
    font-size: 12px;
    color: var(--form-text-secondary);
}

.balance-change {
    font-size: 14px;
    font-weight: 600;
    color: var(--form-success);
}

.balance-change::before {
    content: '+';
}
</style>

<!-- Подключаем сайдбар -->
<?php require 'admin_sidebar.php'; ?>

<!-- Основной контент -->
<div class="payment-add-wrapper">
    <!-- Шапка -->
    <div class="payment-add-header">
        <div class="header-left">
            <h1><i class="fas fa-plus-circle"></i> Добавить платеж</h1>
            <p>Создание нового платежа в системе</p>
        </div>
        <div class="dashboard-quick-actions">
            <a href="payments.php" class="dashboard-action-btn dashboard-action-btn-secondary">
                <i class="fas fa-arrow-left"></i> К списку платежей
            </a>
        </div>
    </div>

    <!-- Основной контент -->
    <div class="payment-add-content">
        <!-- Левая колонка - Форма -->
        <div class="left-column">
            <div class="payment-form-card">
                <div class="form-card-header">
                    <h3><i class="fas fa-credit-card"></i> Информация о платеже</h3>
                </div>
                <div class="form-card-body">
                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle"></i>
                            <?php foreach ($errors as $error): ?>
                                <p><?= htmlspecialchars($error) ?></p>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" class="admin-form" id="paymentForm">
                        <!-- Пользователь -->
                        <div class="form-group">
                            <label for="user_id" class="required">Пользователь</label>
                            <select id="user_id" name="user_id" class="form-control form-control-select" required onchange="updateUserInfo(this)">
                                <option value="">-- Выберите пользователя --</option>
                                <?php foreach ($users as $user): 
                                    $user_balance = $user['balance'] ?? 0;
                                    $balance_class = $user_balance >= 0 ? 'positive' : 'negative';
                                ?>
                                    <option value="<?= $user['id'] ?>" 
                                            data-balance="<?= $user_balance ?>"
                                            data-name="<?= htmlspecialchars($user['full_name'] ?? $user['email']) ?>"
                                            <?= isset($_POST['user_id']) && $_POST['user_id'] == $user['id'] ? 'selected' : '' ?>>
                                        <div class="user-option">
                                            <span class="user-email"><?= htmlspecialchars($user['email']) ?></span>
                                            <span class="user-balance <?= $balance_class ?>">
                                                <?= number_format($user_balance, 2) ?> ₽
                                            </span>
                                        </div>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div id="selectedUserInfo" class="selected-user-info" style="display: none;">
                                <div class="selected-user-avatar">
                                    <i class="fas fa-user"></i>
                                </div>
                                <div class="selected-user-details">
                                    <div class="selected-user-name" id="selectedUserName"></div>
                                    <div class="selected-user-balance">
                                        Текущий баланс: <span id="selectedUserBalance"></span> ₽
                                    </div>
                                </div>
                                <div class="balance-change" id="newBalance"></div>
                            </div>
                        </div>

                        <!-- Сумма -->
                        <div class="form-group">
                            <label for="amount" class="required">Сумма платежа</label>
                            <div style="position: relative;">
                                <input type="number" id="amount" name="amount" class="form-control"
                                       min="0.01" step="0.01" required
                                       value="<?= htmlspecialchars($_POST['amount'] ?? '') ?>"
                                       oninput="updateBalancePreview()"
                                       placeholder="0.00">
                                <span style="position: absolute; right: 16px; top: 50%; transform: translateY(-50%); color: var(--form-text-secondary);">₽</span>
                            </div>
                        </div>

                        <!-- Описание -->
                        <div class="form-group">
                            <label for="description" class="required">Описание платежа</label>
                            <input type="text" id="description" name="description" class="form-control" required
                                   value="<?= htmlspecialchars($_POST['description'] ?? '') ?>"
                                   placeholder="Например: Пополнение баланса, Оплата тарифа и т.д.">
                        </div>

                        <!-- Контрольное слово -->
                        <div class="form-group control-word-group">
                            <label for="control_word">Контрольное слово</label>
                            <input type="text" id="control_word" name="control_word" class="form-control"
                                   value="<?= htmlspecialchars($_POST['control_word'] ?? generateControlWord()) ?>"
                                   placeholder="Автоматически сгенерировано">
                            <button type="button" class="refresh-control-word" onclick="generateNewControlWord()" title="Сгенерировать новое">
                                <i class="fas fa-redo"></i>
                            </button>
                            <small class="text-muted">Уникальный идентификатор платежа. Оставьте пустым для автоматической генерации.</small>
                        </div>

                        <!-- Тип платежа -->
                        <div class="form-group">
                            <label for="payment_type" class="required">Тип платежа</label>
                            <select id="payment_type" name="payment_type" class="form-control form-control-select" required>
                                <option value="manual" <?= ($_POST['payment_type'] ?? 'manual') === 'manual' ? 'selected' : '' ?>>Ручной платеж</option>
                                <option value="bank_card" <?= ($_POST['payment_type'] ?? '') === 'bank_card' ? 'selected' : '' ?>>Банковская карта</option>
                                <option value="yoomoney" <?= ($_POST['payment_type'] ?? '') === 'yoomoney' ? 'selected' : '' ?>>ЮMoney</option>
                                <option value="qiwi" <?= ($_POST['payment_type'] ?? '') === 'qiwi' ? 'selected' : '' ?>>QIWI</option>
                                <option value="cryptocurrency" <?= ($_POST['payment_type'] ?? '') === 'cryptocurrency' ? 'selected' : '' ?>>Криптовалюта</option>
                                <option value="bank_transfer" <?= ($_POST['payment_type'] ?? '') === 'bank_transfer' ? 'selected' : '' ?>>Банковский перевод</option>
                            </select>
                        </div>

                        <!-- Статус -->
                        <div class="form-group">
                            <label for="status" class="required">Статус платежа</label>
                            <select id="status" name="status" class="form-control form-control-select" required onchange="updateStatusInfo()">
                                <option value="pending" <?= ($_POST['status'] ?? 'pending') === 'pending' ? 'selected' : '' ?>>
                                    <div class="payment-status-option">
                                        <span class="status-indicator pending"></span>
                                        <span>Ожидание</span>
                                    </div>
                                </option>
                                <option value="completed" <?= ($_POST['status'] ?? '') === 'completed' ? 'selected' : '' ?>>
                                    <div class="payment-status-option">
                                        <span class="status-indicator completed"></span>
                                        <span>Завершен</span>
                                    </div>
                                </option>
                                <option value="failed" <?= ($_POST['status'] ?? '') === 'failed' ? 'selected' : '' ?>>
                                    <div class="payment-status-option">
                                        <span class="status-indicator failed"></span>
                                        <span>Ошибка</span>
                                    </div>
                                </option>
                            </select>
                            <small class="text-muted" id="statusHelp">
                                <?php if (($_POST['status'] ?? 'pending') === 'completed'): ?>
                                    При выборе "Завершен" баланс пользователя будет автоматически пополнен.
                                <?php else: ?>
                                    Выберите статус обработки платежа.
                                <?php endif; ?>
                            </small>
                        </div>

                        <!-- Кнопки -->
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save btn-icon"></i> Сохранить платеж
                            </button>
                            <a href="payments.php" class="btn btn-secondary">
                                <i class="fas fa-times btn-icon"></i> Отмена
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Правая колонка - Информация -->
        <div class="right-column">
            <!-- Быстрая информация -->
            <div class="payment-info-card">
                <div class="info-card-header">
                    <h3><i class="fas fa-info-circle"></i> Информация</h3>
                </div>
                <div class="info-card-body">
                    <div class="info-list">
                        <div class="info-item">
                            <span class="info-label">Статус системы</span>
                            <span class="info-value success">Активен</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Всего пользователей</span>
                            <span class="info-value"><?= count($users) ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Последний платеж</span>
                            <span class="info-value">
                                <?php
                                $last_payment = $pdo->query("SELECT MAX(created_at) as last_date FROM payments")->fetch(PDO::FETCH_ASSOC);
                                echo $last_payment['last_date'] ? date('d.m.Y H:i', strtotime($last_payment['last_date'])) : 'Нет платежей';
                                ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Подсказки -->
            <div class="payment-hints">
                <h4><i class="fas fa-lightbulb"></i> Полезные подсказки</h4>
                <ul>
                    <li>При выборе статуса "Завершен" баланс пользователя будет автоматически пополнен</li>
                    <li>Контрольное слово используется для идентификации платежа в системе</li>
                    <li>Тип платежа помогает классифицировать способ оплаты</li>
                    <li>Все платежи логируются в истории операций</li>
                </ul>
            </div>

            <!-- Предварительный просмотр -->
            <div class="payment-info-card" id="previewCard" style="display: none;">
                <div class="info-card-header">
                    <h3><i class="fas fa-eye"></i> Предпросмотр</h3>
                </div>
                <div class="info-card-body">
                    <div class="info-list">
                        <div class="info-item">
                            <span class="info-label">Пользователь</span>
                            <span class="info-value" id="previewUserName"></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Сумма</span>
                            <span class="info-value" id="previewAmount"></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Новый баланс</span>
                            <span class="info-value success" id="previewNewBalance"></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Статус</span>
                            <span class="info-value" id="previewStatus"></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Анимация появления
    const elements = document.querySelectorAll('.payment-form-card, .payment-info-card, .payment-hints');
    elements.forEach((el, index) => {
        el.style.opacity = '0';
        el.style.transform = 'translateY(20px)';
        
        setTimeout(() => {
            el.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
            el.style.opacity = '1';
            el.style.transform = 'translateY(0)';
        }, index * 100);
    });

    // Инициализация при загрузке
    updateUserInfo(document.getElementById('user_id'));
    updateStatusInfo();
    updateBalancePreview();
});

// Обновление информации о пользователе
function updateUserInfo(select) {
    const selectedOption = select.options[select.selectedIndex];
    const userInfoDiv = document.getElementById('selectedUserInfo');
    
    if (selectedOption.value) {
        const userName = selectedOption.getAttribute('data-name');
        const userBalance = selectedOption.getAttribute('data-balance') || '0';
        
        document.getElementById('selectedUserName').textContent = userName;
        document.getElementById('selectedUserBalance').textContent = parseFloat(userBalance).toFixed(2);
        
        userInfoDiv.style.display = 'flex';
        updateBalancePreview();
    } else {
        userInfoDiv.style.display = 'none';
        document.getElementById('previewCard').style.display = 'none';
    }
}

// Генерация нового контрольного слова
function generateNewControlWord() {
    const adjectives = ['быстрый', 'надежный', 'безопасный', 'удобный', 'современный', 'умный', 'цифровой'];
    const nouns = ['платеж', 'перевод', 'взнос', 'депозит', 'баланс', 'счет', 'кошелек'];
    const animals = ['тигр', 'медведь', 'волк', 'орел', 'дельфин', 'ястреб', 'сокол'];

    const adj = adjectives[Math.floor(Math.random() * adjectives.length)];
    const noun = nouns[Math.floor(Math.random() * nouns.length)];
    const animal = animals[Math.floor(Math.random() * animals.length)];
    const number = Math.floor(Math.random() * 900) + 100;

    const controlWord = adj.charAt(0).toUpperCase() + adj.slice(1) +
                       noun.charAt(0).toUpperCase() + noun.slice(1) +
                       animal.charAt(0).toUpperCase() + animal.slice(1) +
                       number;

    document.getElementById('control_word').value = controlWord;
    
    // Анимация обновления
    const input = document.getElementById('control_word');
    input.style.transform = 'scale(1.05)';
    input.style.backgroundColor = 'rgba(0, 188, 212, 0.1)';
    
    setTimeout(() => {
        input.style.transform = '';
        input.style.backgroundColor = '';
    }, 300);
}

// Обновление информации о статусе
function updateStatusInfo() {
    const statusSelect = document.getElementById('status');
    const statusHelp = document.getElementById('statusHelp');
    const selectedStatus = statusSelect.value;
    
    let helpText = '';
    switch(selectedStatus) {
        case 'completed':
            helpText = 'При выборе "Завершен" баланс пользователя будет автоматически пополнен.';
            break;
        case 'pending':
            helpText = 'Платеж ожидает обработки. Баланс не будет изменен до завершения платежа.';
            break;
        case 'failed':
            helpText = 'Платеж завершился ошибкой. Баланс пользователя не будет изменен.';
            break;
    }
    
    statusHelp.textContent = helpText;
}

// Предварительный просмотр баланса
function updateBalancePreview() {
    const userSelect = document.getElementById('user_id');
    const amountInput = document.getElementById('amount');
    const statusSelect = document.getElementById('status');
    const previewCard = document.getElementById('previewCard');
    
    const selectedOption = userSelect.options[userSelect.selectedIndex];
    const userName = selectedOption.getAttribute('data-name');
    const currentBalance = parseFloat(selectedOption.getAttribute('data-balance') || 0);
    const amount = parseFloat(amountInput.value) || 0;
    const status = statusSelect.value;
    
    if (userSelect.value && amount > 0) {
        // Обновляем информацию о выбранном пользователе
        const newBalanceDiv = document.getElementById('newBalance');
        if (status === 'completed') {
            const newBalance = currentBalance + amount;
            newBalanceDiv.textContent = newBalance.toFixed(2) + ' ₽';
            newBalanceDiv.style.display = 'block';
        } else {
            newBalanceDiv.style.display = 'none';
        }
        
        // Обновляем карточку предпросмотра
        document.getElementById('previewUserName').textContent = userName;
        document.getElementById('previewAmount').textContent = amount.toFixed(2) + ' ₽';
        document.getElementById('previewStatus').textContent = 
            status === 'completed' ? 'Завершен' : 
            status === 'pending' ? 'Ожидание' : 'Ошибка';
        
        if (status === 'completed') {
            document.getElementById('previewNewBalance').textContent = (currentBalance + amount).toFixed(2) + ' ₽';
            document.getElementById('previewStatus').className = 'info-value success';
        } else if (status === 'pending') {
            document.getElementById('previewNewBalance').textContent = currentBalance.toFixed(2) + ' ₽';
            document.getElementById('previewStatus').className = 'info-value warning';
        } else {
            document.getElementById('previewNewBalance').textContent = currentBalance.toFixed(2) + ' ₽';
            document.getElementById('previewStatus').className = 'info-value danger';
        }
        
        previewCard.style.display = 'block';
    } else {
        previewCard.style.display = 'none';
    }
}

// Обновление отступа при сворачивании сайдбара
const sidebar = document.querySelector('.admin-sidebar');
const content = document.querySelector('.payment-add-wrapper');

if (sidebar && content) {
    const observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.attributeName === 'class') {
                if (sidebar.classList.contains('compact')) {
                    content.style.marginLeft = '70px';
                } else {
                    content.style.marginLeft = '280px';
                }
            }
        });
    });

    observer.observe(sidebar, { attributes: true });
}

// Валидация формы
document.getElementById('paymentForm').addEventListener('submit', function(e) {
    const amount = parseFloat(document.getElementById('amount').value);
    const userId = document.getElementById('user_id').value;
    
    if (!userId) {
        e.preventDefault();
        alert('Пожалуйста, выберите пользователя');
        return;
    }
    
    if (amount <= 0 || isNaN(amount)) {
        e.preventDefault();
        alert('Пожалуйста, введите корректную сумму платежа');
        return;
    }
    
    // Добавляем анимацию отправки
    const submitBtn = this.querySelector('button[type="submit"]');
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Сохранение...';
    submitBtn.disabled = true;
});
</script>

<?php require 'admin_footer.php'; ?>