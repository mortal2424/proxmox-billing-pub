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
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

// Получаем платежную информацию пользователя
$stmt = $pdo->prepare("SELECT * FROM payment_info WHERE user_id = ?");
$stmt->execute([$user_id]);
$payment_info = $stmt->fetch(PDO::FETCH_ASSOC);

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
            $uploadDir = '../uploads/avatars/'; // Изменен путь для корректного сохранения в БД
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
            $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
            $detectedType = finfo_file($fileInfo, $_FILES['avatar']['tmp_name']);
            
            if (!in_array($detectedType, $allowedTypes)) {
                throw new Exception("Допустимы только изображения JPG, PNG или GIF");
            }
            
            if ($_FILES['avatar']['size'] > 2 * 1024 * 1024) {
                throw new Exception("Максимальный размер файла - 2MB");
            }
            
            $extension = pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION);
            $filename = 'avatar_' . $user_id . '_' . time() . '.' . $extension;
            $destination = $uploadDir . $filename;
            
            if (move_uploaded_file($_FILES['avatar']['tmp_name'], $destination)) {
                // Удаляем старый аватар, если он есть
                if (!empty($user['avatar']) && file_exists($user['avatar'])) {
                    unlink($user['avatar']);
                }
                
                $update_data['avatar'] = $uploadDir . $filename; // Сохраняем относительный путь
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
            $card_number = preg_replace('/\s+/', '', $_POST['card_number']);
            $card_expiry = trim($_POST['card_expiry']);
            $card_cvv = trim($_POST['card_cvv']);
            
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
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&family=Poppins:wght@600&display=swap" rel="stylesheet">
    <link rel="icon" href="../img/cloud.png" type="image/png">
    <link rel="stylesheet" href="/css/themes.css">
    <style>
        <?php include '../admin/css/admin_style.css'; ?>
        <?php include '../css/settings_styles.css'; ?>
        <?php include '../css/tg_support_styles.css'; ?>
        <?php include '../css/header_styles.css'; ?>
    </style>
    <script src="/js/theme.js" defer></script>
</head>
<body>
    <?php include '../templates/headers/user_header.php'; ?>

    <div class="container">
        <div class="admin-content">
            <?php include '../templates/headers/user_sidebar.php'; ?>

            <main class="admin-main">
                <h1 class="admin-title">
                    <i class="fas fa-cog"></i> Настройки аккаунта
                </h1>
                
                <?php if (!empty($success_message)): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_message) ?>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($error_message)): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error_message) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" class="settings-form" enctype="multipart/form-data">
        <!-- Загрузка аватара -->
        <div class="form-section">
            <h3 class="form-section-title">
                <i class="fas fa-user-circle"></i> Аватар профиля
            </h3>
            
            <div class="avatar-upload">
                <div class="avatar-preview">
                    <?php if (!empty($user['avatar'])): ?>
                        <img src="<?= htmlspecialchars($user['avatar']) . '?v=' . time() ?>" alt="Аватар пользователя" id="avatarPreview">
                    <?php else: ?>
                        <i class="fas fa-user" style="font-size: 40px; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: #777;"></i>
                    <?php endif; ?>
                </div>
                <div class="avatar-upload-controls">
                    <label class="avatar-upload-btn">
                        <i class="fas fa-upload"></i> Выбрать файл
                        <input type="file" name="avatar" id="avatarInput" accept="image/jpeg,image/png,image/gif">
                    </label>
                    <?php if (!empty($user['avatar'])): ?>
                        <input type="hidden" name="remove_avatar" id="removeAvatarFlag" value="0">
                        <span class="remove-avatar" onclick="document.getElementById('removeAvatarFlag').value='1'; document.getElementById('avatarPreview').src=''; this.style.display='none';">
                            <i class="fas fa-trash-alt"></i> Удалить
                        </span>
                    <?php endif; ?>
                    <div class="avatar-filename" id="avatarFilename"></div>
                    <small class="form-hint">Допустимые форматы: JPG, PNG, GIF. Максимальный размер: 2MB</small>
                </div>
            </div>
        </div>
                    
                    <!-- Telegram уведомления -->
                    <div class="form-section">
                        <h3 class="form-section-title">
                            <i class="fab fa-telegram"></i> Telegram уведомления
                        </h3>
                        
                        <div class="telegram-connect <?= !empty($user['telegram_id']) ? 'telegram-connected' : '' ?>">
                            <div class="telegram-info">
                                <i class="fab fa-telegram telegram-icon"></i>
                                <div>
                                    <h4>Telegram уведомления</h4>
                                    <p>
                                        <?php if (!empty($user['telegram_id'])): ?>
                                            Ваш аккаунт привязан (ID: <?= htmlspecialchars($user['telegram_id']) ?>)
                                        <?php else: ?>
                                            Привяжите Telegram для получения уведомлений
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>
                            <?php if (!empty($user['telegram_id'])): ?>
                                <button type="button" class="telegram-test-btn" onclick="testTelegramNotification()">
                                    <i class="fas fa-paper-plane"></i> Тест
                                </button>
                            <?php endif; ?>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Ваш Telegram ID</label>
                            <input type="text" name="telegram_id" class="form-input" 
                                   value="<?= htmlspecialchars($user['telegram_id'] ?? '') ?>"
                                   placeholder="Пример: 123456789">
                            <small class="form-hint">
                                Чтобы получить свой Telegram ID, напишите <code>/start</code> боту 
                                <a href="https://t.me/homevlad_notify_bot" target="_blank">@homevlad_notify_bot</a> 
                                или используйте <a href="https://t.me/userinfobot" target="_blank">@userinfobot</a>
                            </small>
                        </div>
                    </div>
                    
                    <!-- Основная информация -->
                    <div class="form-section">
                        <h3 class="form-section-title">
                            <i class="fas fa-user"></i> Основная информация
                        </h3>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-input" 
                                       value="<?= htmlspecialchars($user['email'] ?? '') ?>" disabled>
                                <small class="form-hint">Для изменения email обратитесь в поддержку</small>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">ФИО*</label>
                                <input type="text" name="full_name" class="form-input" 
                                       value="<?= htmlspecialchars($user['full_name'] ?? '') ?>" required>
                            </div>
                            
                            <?php if ($user['user_type'] !== 'individual'): ?>
                                <div class="form-group">
                                    <label class="form-label">Название компании*</label>
                                    <input type="text" name="company_name" class="form-input" 
                                           value="<?= htmlspecialchars($user['company_name'] ?? '') ?>" required>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">ИНН</label>
                                    <input type="text" class="form-input" 
                                           value="<?= htmlspecialchars($user['inn'] ?? '') ?>" disabled>
                                    <small class="form-hint">ИНН нельзя изменить после регистрации</small>
                                </div>
                                
                                <?php if ($user['user_type'] === 'legal'): ?>
                                    <div class="form-group">
                                        <label class="form-label">КПП*</label>
                                        <input type="text" name="kpp" class="form-input" 
                                               value="<?= htmlspecialchars($user['kpp'] ?? '') ?>" required>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Смена пароля -->
                    <div class="form-section">
                        <h3 class="form-section-title">
                            <i class="fas fa-lock"></i> Смена пароля
                        </h3>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Текущий пароль*</label>
                                <input type="password" name="current_password" class="form-input" required>
                                <small class="form-hint">Необходим для подтверждения изменений</small>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Новый пароль</label>
                                <input type="password" name="new_password" class="form-input">
                                <small class="form-hint">Оставьте пустым, если не хотите менять</small>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Подтверждение пароля</label>
                                <input type="password" name="confirm_password" class="form-input">
                            </div>
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
                            <i class="fas fa-save"></i> Сохранить изменения
                        </button>
                    </div>
                </form>
            </main>
        </div>
    </div>

    <?php include '../templates/headers/user_footer.php'; ?>

    <script>
        // Обработка выбора аватара
        document.getElementById('avatarInput')?.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                // Показываем имя файла
                document.getElementById('avatarFilename').textContent = file.name;
                
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
                        previewContainer.appendChild(preview);
                    }
                    preview.src = event.target.result;
                    
                    // Скрываем кнопку удаления при загрузке нового аватара
                    const removeBtn = document.querySelector('.remove-avatar');
                    if (removeBtn) removeBtn.style.display = 'none';
                };
                reader.readAsDataURL(file);
            }
        });
        
        // Удаление аватара
        document.getElementById('removeAvatar')?.addEventListener('click', function() {
            if (confirm('Вы уверены, что хотите удалить аватар?')) {
                fetch('../api/remove_avatar.php', {
                    method: 'POST',
                    credentials: 'same-origin'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert('Ошибка: ' + (data.error || 'Не удалось удалить аватар'));
                    }
                })
                .catch(error => {
                    alert('Ошибка сети: ' + error.message);
                });
            }
        });
        
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
        
        // Тест Telegram уведомления
        function testTelegramNotification() {
            fetch('../api/test_telegram.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Тестовое уведомление отправлено! Проверьте Telegram');
                    } else {
                        alert('Ошибка: ' + (data.error || 'Не удалось отправить уведомление'));
                    }
                })
                .catch(error => {
                    alert('Ошибка сети: ' + error.message);
                });
        }
        
        // Адаптивное меню
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
</body>
</html>