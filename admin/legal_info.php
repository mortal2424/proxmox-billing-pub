<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

session_start();
checkAuth();

$db = new Database();
$pdo = $db->getConnection();
$user_id = $_SESSION['user']['id'];

/**
 * Безопасное выполнение запроса с проверкой существования таблицы
 * 
 * @param PDO $pdo Объект PDO
 * @param string $query SQL-запрос
 * @param array $params Параметры запроса
 * @param string $tableName Название таблицы для проверки
 * @return PDOStatement|false
 */
function safeQuery($pdo, $query, $params = [], $tableName = null, $skipTableCheck = false) {
    // Для запросов CREATE TABLE пропускаем проверку
    if (!$skipTableCheck && $tableName !== null && stripos($query, 'CREATE TABLE') === false) {
        try {
            // Улучшенная проверка существования таблицы
            $checkQuery = "SELECT COUNT(*) FROM information_schema.tables 
                          WHERE table_schema = DATABASE() AND table_name = ?";
            $stmt = $pdo->prepare($checkQuery);
            $stmt->execute([$tableName]);
            
            if ($stmt->fetchColumn() == 0) {
                throw new Exception("Таблица {$tableName} не существует в базе данных");
            }
        } catch (PDOException $e) {
            throw new Exception("Ошибка при проверке таблицы: " . $e->getMessage());
        }
    }
    
    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        return $stmt;
    } catch (PDOException $e) {
        throw new Exception("Ошибка выполнения запроса: " . $e->getMessage());
    }
}

// Проверяем, является ли пользователь администратором
try {
    $stmt = safeQuery($pdo, "SELECT is_admin FROM users WHERE id = ?", [$user_id], 'users');
    $user = $stmt->fetch();
} catch (Exception $e) {
    die("Ошибка базы данных: " . $e->getMessage());
}

if (!$user || !$user['is_admin']) {
    header('Location: /templates/access_denied.php');
    exit;
}

$errors = [];
$success = false;

// Получаем текущие данные (если есть)
try {
    $currentInfo = safeQuery($pdo, "SELECT * FROM legal_entity_info ORDER BY id DESC LIMIT 1", [], 'legal_entity_info')->fetch();
} catch (Exception $e) {
    $currentInfo = false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $company_name = trim($_POST['company_name'] ?? '');
        $legal_address = trim($_POST['legal_address'] ?? '');
        $tax_number = trim($_POST['tax_number'] ?? '');
        $registration_number = trim($_POST['registration_number'] ?? '');
        $bank_name = trim($_POST['bank_name'] ?? '');
        $bank_account = trim($_POST['bank_account'] ?? '');
        $bic = trim($_POST['bic'] ?? '');
        $director_name = trim($_POST['director_name'] ?? '');
        $director_position = trim($_POST['director_position'] ?? '');
        $contact_phone = trim($_POST['contact_phone'] ?? '');
        $contact_email = trim($_POST['contact_email'] ?? '');

        // Валидация
        if (empty($company_name)) throw new Exception('Укажите название компании');
        if (empty($legal_address)) throw new Exception('Укажите юридический адрес');
        if (empty($tax_number)) throw new Exception('Укажите ИНН');
        if (empty($registration_number)) throw new Exception('Укажите ОГРН');
        if (empty($bank_name)) throw new Exception('Укажите название банка');
        if (empty($bank_account)) throw new Exception('Укажите расчетный счет');
        if (empty($bic)) throw new Exception('Укажите БИК');
        if (empty($director_name)) throw new Exception('Укажите ФИО директора');
        if (empty($director_position)) throw new Exception('Укажите должность директора');
        if (empty($contact_phone)) throw new Exception('Укажите контактный телефон');
        if (empty($contact_email) || !filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Укажите корректный email');
        }

        // Сохраняем данные с использованием safeQuery
        $stmt = safeQuery($pdo, "
            INSERT INTO legal_entity_info (
                company_name, legal_address, tax_number, registration_number,
                bank_name, bank_account, bic, director_name, director_position,
                contact_phone, contact_email
            ) VALUES (
                :company_name, :legal_address, :tax_number, :registration_number,
                :bank_name, :bank_account, :bic, :director_name, :director_position,
                :contact_phone, :contact_email
            )
        ", [
            ':company_name' => $company_name,
            ':legal_address' => $legal_address,
            ':tax_number' => $tax_number,
            ':registration_number' => $registration_number,
            ':bank_name' => $bank_name,
            ':bank_account' => $bank_account,
            ':bic' => $bic,
            ':director_name' => $director_name,
            ':director_position' => $director_position,
            ':contact_phone' => $contact_phone,
            ':contact_email' => $contact_email
        ], 'legal_entity_info');

        $success = true;
        $currentInfo = [
            'company_name' => $company_name,
            'legal_address' => $legal_address,
            'tax_number' => $tax_number,
            'registration_number' => $registration_number,
            'bank_name' => $bank_name,
            'bank_account' => $bank_account,
            'bic' => $bic,
            'director_name' => $director_name,
            'director_position' => $director_position,
            'contact_phone' => $contact_phone,
            'contact_email' => $contact_email
        ];

    } catch (Exception $e) {
        $errors[] = $e->getMessage();
    }
}

$title = "Юридическая информация | HomeVlad Cloud";
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $title ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&family=Poppins:wght@600&display=swap" rel="stylesheet">
    <link rel="icon" href="../../img/cloud.png" type="image/png">
    <style>
        <?php include '../../admin/css/admin_style.css'; ?>
        <?php include '../../css/order_vm_styles.css'; ?>
        <?php include '../../css/header_styles.css'; ?>
        
        .legal-info-form {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            background: var(--card-bg);
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        
        .legal-info-form .form-group {
            margin-bottom: 15px;
        }
        
        .legal-info-form label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
        }
        
        .legal-info-form input[type="text"],
        .legal-info-form input[type="email"],
        .legal-info-form input[type="tel"],
        .legal-info-form textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            background: var(--input-bg);
            color: var(--text-color);
        }
        
        .legal-info-form textarea {
            min-height: 100px;
            resize: vertical;
        }
    </style>
</head>
<body>
    <?php include 'admin_header.php'; ?>

    <div class="container">
        <div class="admin-content">
            <?php include 'admin_sidebar.php'; ?>

            <div class="admin-main">
    <div class="admin-header-container">
        <h1 class="admin-title">
            <i class="fas fa-building"></i> Юридическая информация
        </h1>
    </div>
    
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i>
            <strong>Ошибка при сохранении данных:</strong>
            <?php foreach ($errors as $error): ?>
                <p><?= $error ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="alert alert-success fade-in">
            <i class="fas fa-check-circle"></i>
            <strong>Юридическая информация успешно сохранена!</strong>
        </div>
    <?php endif; ?>
    
    <div class="legal-form-container">
        <form method="POST" class="legal-form">
            <div class="form-section">
                <h3 class="section-title">
                    <i class="fas fa-info-circle"></i> Основная информация
                </h3>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Название компании</label>
                        <input type="text" name="company_name" 
                               value="<?= htmlspecialchars($currentInfo['company_name'] ?? '') ?>" 
                               class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">ИНН</label>
                        <input type="text" name="tax_number" 
                               value="<?= htmlspecialchars($currentInfo['tax_number'] ?? '') ?>" 
                               class="form-input" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Юридический адрес</label>
                    <textarea name="legal_address" class="form-textarea" style="height: 100px; width: 1000px;" required><?= 
                        htmlspecialchars($currentInfo['legal_address'] ?? '') 
                    ?></textarea>
                </div>
                
                <div class="form-group">
                    <label class="form-label">ОГРН/ОГРНИП</label>
                    <input type="text" name="registration_number" 
                           value="<?= htmlspecialchars($currentInfo['registration_number'] ?? '') ?>" 
                           class="form-input" required>
                </div>
            </div>
            
            <div class="form-section">
                <h3 class="section-title">
                    <i class="fas fa-university"></i> Банковские реквизиты
                </h3>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Название банка</label>
                        <input type="text" name="bank_name" 
                               value="<?= htmlspecialchars($currentInfo['bank_name'] ?? '') ?>" 
                               class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Расчетный счет</label>
                        <input type="text" name="bank_account" 
                               value="<?= htmlspecialchars($currentInfo['bank_account'] ?? '') ?>" 
                               class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">БИК</label>
                        <input type="text" name="bic" 
                               value="<?= htmlspecialchars($currentInfo['bic'] ?? '') ?>" 
                               class="form-input" required>
                    </div>
                </div>
            </div>
            
            <div class="form-section">
                <h3 class="section-title">
                    <i class="fas fa-user-tie"></i> Руководитель
                </h3>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">ФИО директора</label>
                        <input type="text" name="director_name" 
                               value="<?= htmlspecialchars($currentInfo['director_name'] ?? '') ?>" 
                               class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Должность</label>
                        <input type="text" name="director_position" 
                               value="<?= htmlspecialchars($currentInfo['director_position'] ?? 'Генеральный директор') ?>" 
                               class="form-input" required>
                    </div>
                </div>
            </div>
            
            <div class="form-section">
                <h3 class="section-title">
                    <i class="fas fa-phone"></i> Контактная информация
                </h3>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Контактный телефон</label>
                        <input type="tel" name="contact_phone" 
                               value="<?= htmlspecialchars($currentInfo['contact_phone'] ?? '') ?>" 
                               class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Контактный email</label>
                        <input type="email" name="contact_email" 
                               value="<?= htmlspecialchars($currentInfo['contact_email'] ?? '') ?>" 
                               class="form-input" required>
                    </div>
                </div>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Сохранить изменения
                </button>
            </div>
        </form>
    </div>
</div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Адаптивное меню для мобильных устройств
        const menuToggle = document.createElement('div');
        menuToggle.innerHTML = '<i class="fas fa-bars"></i>';
        menuToggle.style.position = 'fixed';
        menuToggle.style.top = '15px';
        menuToggle.style.left = '15px';
        menuToggle.style.zIndex = '1000';
        menuToggle.style.fontSize = '1.5rem';
        menuToggle.style.color = 'var(--primary)';
        menuToggle.style.cursor = 'pointer';
        menuToggle.style.display = 'none';
        document.body.appendChild(menuToggle);

        function checkScreenSize() {
            if (window.innerWidth <= 992) {
                menuToggle.style.display = 'block';
                document.body.classList.add('sidebar-closed');
            } else {
                menuToggle.style.display = 'none';
                document.body.classList.remove('sidebar-closed');
            }
        }

        menuToggle.addEventListener('click', function() {
            document.body.classList.toggle('sidebar-open');
        });

        window.addEventListener('resize', checkScreenSize);
        checkScreenSize();
    });
    </script>
</body>
</html>