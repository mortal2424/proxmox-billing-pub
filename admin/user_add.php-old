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

// Обработка формы добавления пользователя
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $email = trim($_POST['email']);
        $full_name = trim($_POST['full_name']);
        $balance = (float)$_POST['balance'];
        $is_admin = isset($_POST['is_admin']) ? 1 : 0;
        $password = trim($_POST['password']);
        $confirm_password = trim($_POST['confirm_password']);
        $user_type = $_POST['user_type'] ?? 'individual';
        $inn = trim($_POST['inn'] ?? '');
        $kpp = $user_type === 'legal' ? trim($_POST['kpp'] ?? '') : null;
        $company_name = $user_type !== 'individual' ? trim($_POST['company_name'] ?? '') : null;
        $telegram_id = trim($_POST['telegram_id'] ?? '');
        
        // Валидация данных
        if (empty($email)) {
            throw new Exception("Email обязателен для заполнения");
        }
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Некорректный формат email");
        }
        
        if (empty($password)) {
            throw new Exception("Пароль обязателен для заполнения");
        }
        
        if ($password !== $confirm_password) {
            throw new Exception("Пароли не совпадают");
        }
        
        if (strlen($password) < 8) {
            throw new Exception("Пароль должен содержать минимум 8 символов");
        }
        
        if ($balance < 0) {
            throw new Exception("Баланс не может быть отрицательным");
        }

        if (!empty($telegram_id) && !preg_match('/^\d+$/', $telegram_id)) {
            throw new Exception("Telegram ID должен содержать только цифры");
        }

        // Проверка существования пользователя
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        
        if ($stmt->fetch()) {
            throw new Exception("Пользователь с таким email уже существует");
        }

        // Создание пользователя
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users 
            (email, full_name, balance, is_admin, password_hash, user_type, inn, kpp, company_name, telegram_id) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $email, 
            $full_name, 
            $balance, 
            $is_admin, 
            $password_hash,
            $user_type,
            $inn,
            $kpp,
            $company_name,
            !empty($telegram_id) ? $telegram_id : null
        ]);
        $user_id = $pdo->lastInsertId();

        // Обработка платежной информации (если указана)
        if (!empty($_POST['card_holder'])) {
            $card_holder = trim($_POST['card_holder']);
            $card_number = preg_replace('/\s+/', '', $_POST['card_number']);
            $card_expiry = trim($_POST['card_expiry']);
            $card_cvv = trim($_POST['card_cvv']);

            $stmt = $pdo->prepare("INSERT INTO payment_info 
                (user_id, card_holder, card_number, card_expiry, card_cvv) 
                VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$user_id, $card_holder, $card_number, $card_expiry, $card_cvv]);
        }

        $_SESSION['success'] = "Пользователь успешно создан";
        header("Location: users.php");
        exit;
        
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
        header("Location: user_add.php");
        exit;
    }
}

$title = "Добавление нового пользователя | HomeVlad Cloud";
require 'admin_header.php';
?>

<div class="container">
    <div class="admin-content">
        <?php require 'admin_sidebar.php'; ?>
        
        <main class="admin-main">
            <div class="admin-header-container">
                <div class="admin-header-content">
                    <h1 class="admin-title">
                        <i class="fas fa-user-plus"></i> Добавление нового пользователя
                    </h1>
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
                                <input type="email" name="email" class="form-input" required>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">ФИО*</label>
                                <input type="text" name="full_name" class="form-input" required>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Баланс (руб.)*</label>
                                <input type="number" name="balance" min="0" step="0.01" class="form-input" value="0.00" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Тип пользователя*</label>
                                <select name="user_type" class="form-input" id="user-type-select" required>
                                    <option value="individual">Физическое лицо</option>
                                    <option value="entrepreneur">Индивидуальный предприниматель</option>
                                    <option value="legal">Юридическое лицо</option>
                                </select>
                            </div>

                            <div class="form-group" id="company-name-group" style="display: none;">
                                <label class="form-label">Название компании*</label>
                                <input type="text" name="company_name" class="form-input">
                            </div>

                            <div class="form-group" id="inn-group">
                                <label class="form-label">ИНН*</label>
                                <input type="text" name="inn" class="form-input" pattern="\d{10,12}" title="ИНН должен содержать 10 или 12 цифр">
                            </div>

                            <div class="form-group" id="kpp-group" style="display: none;">
                                <label class="form-label">КПП*</label>
                                <input type="text" name="kpp" class="form-input" pattern="\d{9}" title="КПП должен содержать 9 цифр">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Пароль -->
                    <div class="form-section">
                        <h3 class="form-section-title">
                            <i class="fas fa-lock"></i> Пароль
                        </h3>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Пароль*</label>
                                <input type="password" name="password" class="form-input" required minlength="8">
                                <small class="form-hint">Минимум 8 символов</small>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Подтверждение пароля*</label>
                                <input type="password" name="confirm_password" class="form-input" required minlength="8">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Добавляем поле для Telegram ID в форму -->
<div class="form-section">
    <h3 class="form-section-title">
        <i class="fab fa-telegram"></i> Telegram ID
    </h3>
    
    <div class="form-grid">
        <div class="form-group">
            <label class="form-label">Telegram ID</label>
            <input type="text" name="telegram_id" class="form-input" 
                   placeholder="Пример: 123456789">
            <small class="form-hint">
                Чтобы получить Telegram ID, пользователь может написать <code>/start</code> боту 
                <a href="https://t.me/homevlad_notify_bot" target="_blank">@homevlad_notify_bot</a> 
                или использовать <a href="https://t.me/userinfobot" target="_blank">@userinfobot</a>
            </small>
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
                                <input type="checkbox" name="is_admin">
                                <span class="checkmark"></span>
                                Администратор
                            </label>
                        </div>
                    </div>
                    
                    <!-- Платежная информация -->
                    <div class="form-section">
                        <h3 class="form-section-title">
                            <i class="fas fa-credit-card"></i> Платежная информация (необязательно)
                        </h3>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Имя владельца карты</label>
                                <input type="text" name="card_holder" class="form-input">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Номер карты</label>
                                <input type="text" name="card_number" class="form-input card-number" placeholder="XXXX XXXX XXXX XXXX">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Срок действия</label>
                                <input type="text" name="card_expiry" class="form-input card-expiry" placeholder="MM/YY">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">CVV код</label>
                                <input type="text" name="card_cvv" class="form-input card-cvv" placeholder="XXX" maxlength="3">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Кнопки действий -->
                    <div class="form-actions">
                        <a href="users.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Назад
                        </a>
                        <div class="actions-right">
                            <button type="reset" class="btn btn-outline">
                                <i class="fas fa-undo"></i> Очистить
                            </button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Создать
                            </button>
                        </div>
                    </div>
                </form>
            </section>
        </main>
    </div>
</div>

<style>
    <?php include '../admin/css/user_add_styles.css'; ?>
</style>

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

// Валидация формы
document.querySelector('form')?.addEventListener('submit', function(e) {
    const password = document.querySelector('input[name="password"]').value;
    const confirm = document.querySelector('input[name="confirm_password"]').value;
    
    if (password !== confirm) {
        alert('Пароли не совпадают!');
        e.preventDefault();
    }
});

// Управление полями в зависимости от типа пользователя
const userTypeSelect = document.getElementById('user-type-select');
const companyNameGroup = document.getElementById('company-name-group');
const innGroup = document.getElementById('inn-group');
const kppGroup = document.getElementById('kpp-group');

userTypeSelect?.addEventListener('change', function() {
    const userType = this.value;
    
    if (userType === 'individual') {
        companyNameGroup.style.display = 'none';
        kppGroup.style.display = 'none';
    } else if (userType === 'entrepreneur') {
        companyNameGroup.style.display = 'block';
        kppGroup.style.display = 'none';
    } else if (userType === 'legal') {
        companyNameGroup.style.display = 'block';
        kppGroup.style.display = 'block';
    }
});

// Адаптивное меню для мобильных устройств
const menuToggle = document.createElement('button');
menuToggle.innerHTML = '<i class="fas fa-bars"></i>';
menuToggle.className = 'btn btn-icon';
menuToggle.style.position = 'fixed';
menuToggle.style.top = '15px';
menuToggle.style.left = '15px';
menuToggle.style.zIndex = '1000';
document.body.appendChild(menuToggle);

const sidebar = document.querySelector('.admin-sidebar');

function checkScreenSize() {
    if (window.innerWidth <= 992) {
        sidebar.style.display = 'none';
        menuToggle.style.display = 'block';
    } else {
        sidebar.style.display = 'block';
        menuToggle.style.display = 'none';
    }
}

menuToggle.addEventListener('click', function() {
    sidebar.style.display = sidebar.style.display === 'none' ? 'block' : 'none';
});

window.addEventListener('resize', checkScreenSize);
checkScreenSize();
</script>

<?php require 'admin_footer.php'; ?>