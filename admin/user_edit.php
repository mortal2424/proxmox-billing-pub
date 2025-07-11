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

        if ($user_id > 0) {
            // Обновление существующего пользователя
            $stmt = $pdo->prepare("UPDATE users SET 
                email = ?, 
                full_name = ?, 
                balance = ?, 
                is_admin = ?, 
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
                $user_type, 
                $inn, 
                $kpp, 
                $company_name,
                !empty($telegram_id) ? $telegram_id : null,
                $user_id
            ]);
            $message = "Данные пользователя обновлены";
        } else {
            // Создание нового пользователя
            $password = password_hash('temp_password', PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users 
                (email, full_name, balance, is_admin, password_hash, user_type, inn, kpp, company_name, telegram_id) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $email, 
                $full_name, 
                $balance, 
                $is_admin, 
                $password, 
                $user_type, 
                $inn, 
                $kpp, 
                $company_name,
                !empty($telegram_id) ? $telegram_id : null
            ]);
            $user_id = $pdo->lastInsertId();
            $message = "Пользователь создан. Временный пароль: temp_password";
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

$title = ($user_id > 0 ? "Редактирование" : "Добавление") . " пользователя | HomeVlad Cloud";
require 'admin_header.php';
?>

<div class="container">
    <div class="admin-content">
        <?php require 'admin_sidebar.php'; ?>
        
        <main class="admin-main">
            <div class="admin-header-container">
                <div class="admin-header-content">
                    <h1 class="admin-title">
                        <i class="fas fa-user-edit"></i> <?= $user_id > 0 ? "Редактирование пользователя" : "Добавление нового пользователя" ?>
                    </h1>
                    <a href="users.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Назад
                    </a>
                </div>
            </div>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($_SESSION['error']) ?>
                </div>
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>

            <section class="section">
                <form method="POST" class="user-form">
                    <!-- Основная информация -->
                    <div class="form-section">
                        <h3 class="form-section-title">
                            <i class="fas fa-user"></i> Основная информация
                        </h3>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Email*</label>
                                <input type="email" name="email" class="form-input" 
                                       value="<?= htmlspecialchars($user['email'] ?? '') ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">ФИО*</label>
                                <input type="text" name="full_name" class="form-input" 
                                       value="<?= htmlspecialchars($user['full_name'] ?? '') ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Баланс (руб.)*</label>
                                <input type="number" name="balance" min="0" step="0.01" class="form-input" 
                                       value="<?= htmlspecialchars($user['balance'] ?? '0.00') ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Тип аккаунта*</label>
                                <select name="user_type" class="form-input" required>
                                    <option value="individual" <?= isset($user['user_type']) && $user['user_type'] === 'individual' ? 'selected' : '' ?>>Физическое лицо</option>
                                    <option value="entrepreneur" <?= isset($user['user_type']) && $user['user_type'] === 'entrepreneur' ? 'selected' : '' ?>>ИП/Самозанятый</option>
                                    <option value="legal" <?= isset($user['user_type']) && $user['user_type'] === 'legal' ? 'selected' : '' ?>>Юридическое лицо</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Добавляем поле для Telegram ID в форму -->
<div class="form-section">
    <h3 class="form-section-title">
        <i class="fab fa-telegram"></i> Telegram уведомления
    </h3>
    
    <div class="form-grid">
        <div class="form-group">
            <label class="form-label">Telegram ID</label>
            <input type="text" name="telegram_id" class="form-input" 
                   value="<?= htmlspecialchars($user['telegram_id'] ?? '') ?>"
                   placeholder="Пример: 123456789">
                            <!--<small class="form-hint">
                                Чтобы получить свой Telegram ID, напишите <code>/start</code> боту 
                                <a href="https://t.me/homevlad_notify_bot" target="_blank">@homevlad_notify_bot</a> 
                                или используйте <a href="https://t.me/userinfobot" target="_blank">@userinfobot</a>
                            </small>-->
        </div>
    </div>
</div>

                    <!-- Информация о компании -->
                    <div class="form-section" id="company-info-section">
                        <h3 class="form-section-title">
                            <i class="fas fa-building"></i> Информация о компании
                        </h3>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Название компании</label>
                                <input type="text" name="company_name" class="form-input" 
                                       value="<?= htmlspecialchars($user['company_name'] ?? '') ?>">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">ИНН</label>
                                <input type="text" name="inn" class="form-input" 
                                       value="<?= htmlspecialchars($user['inn'] ?? '') ?>">
                            </div>
                            
                            <div class="form-group" id="kpp-field">
                                <label class="form-label">КПП</label>
                                <input type="text" name="kpp" class="form-input" 
                                       value="<?= htmlspecialchars($user['kpp'] ?? '') ?>">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Права доступа -->
                    <div class="form-section">
                        <h3 class="form-section-title">
                            <i class="fas fa-shield-alt"></i> Права доступа
                        </h3>
                        
                        <div class="form-group">
                            <label class="checkbox-container">
                                <input type="checkbox" name="is_admin" <?= isset($user['is_admin']) && $user['is_admin'] ? 'checked' : '' ?>>
                                <span class="checkmark"></span>
                                Администратор
                            </label>
                        </div>
                    </div>
                    
                    <!-- Платежная информация -->
                    <div class="form-section">
                        <h3 class="form-section-title">
                            <i class="fas fa-credit-card"></i> Платежная информация
                        </h3>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Имя владельца карты</label>
                                <input type="text" name="card_holder" class="form-input" 
                                       value="<?= htmlspecialchars($payment_info['card_holder'] ?? '') ?>">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Номер карты</label>
                                <input type="text" name="card_number" class="form-input card-number" 
                                       value="<?= htmlspecialchars($payment_info['card_number'] ?? '') ?>"
                                       placeholder="XXXX XXXX XXXX XXXX">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Срок действия</label>
                                <input type="text" name="card_expiry" class="form-input card-expiry" 
                                       value="<?= htmlspecialchars($payment_info['card_expiry'] ?? '') ?>"
                                       placeholder="MM/YY">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">CVV код</label>
                                <input type="text" name="card_cvv" class="form-input card-cvv" 
                                       value="<?= htmlspecialchars($payment_info['card_cvv'] ?? '') ?>"
                                       placeholder="XXX" maxlength="3">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Сохранить
                        </button>
                    </div>
                </form>
            </section>
        </main>
    </div>
</div>

<script>
// Форматирование номера карты при вводе
document.querySelector('input.card-number')?.addEventListener('input', function(e) {
    let value = e.target.value.replace(/\s+/g, '');
    if (value.length > 0) {
        value = value.match(new RegExp('.{1,4}', 'g')).join(' ');
    }
    e.target.value = value;
});

// Форматирование срока действия карты
document.querySelector('input.card-expiry')?.addEventListener('input', function(e) {
    let value = e.target.value.replace(/\D/g, '');
    if (value.length > 2) {
        value = value.substring(0, 2) + '/' + value.substring(2, 4);
    }
    e.target.value = value;
});

// Ограничение CVV кода
document.querySelector('input.card-cvv')?.addEventListener('input', function(e) {
    e.target.value = e.target.value.replace(/\D/g, '').substring(0, 3);
});

// Показать/скрыть поля компании в зависимости от типа пользователя
function toggleCompanyFields() {
    const userType = document.querySelector('select[name="user_type"]').value;
    const companySection = document.getElementById('company-info-section');
    const kppField = document.getElementById('kpp-field');
    
    if (userType === 'individual') {
        companySection.style.display = 'none';
    } else {
        companySection.style.display = 'block';
        kppField.style.display = userType === 'legal' ? 'block' : 'none';
    }
}

// Инициализация при загрузке
document.addEventListener('DOMContentLoaded', function() {
    toggleCompanyFields();
    document.querySelector('select[name="user_type"]').addEventListener('change', toggleCompanyFields);
});
</script>

<?php require 'admin_footer.php'; ?>