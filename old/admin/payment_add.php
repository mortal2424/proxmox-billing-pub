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
$users = $pdo->query("SELECT id, email FROM users ORDER BY email")->fetchAll(PDO::FETCH_ASSOC);

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = (int)$_POST['user_id'];
    $amount = (float)$_POST['amount'];
    $description = trim($_POST['description']);
    $status = $_POST['status'];
    $control_word = trim($_POST['control_word']) ?: generateControlWord();
    
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
        $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        if (!$stmt->fetch()) {
            throw new Exception('Пользователь не найден');
        }
        
        // Создание платежа
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("
            INSERT INTO payments 
            (user_id, amount, description, status, control_word, created_at, updated_at) 
            VALUES (?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([$user_id, $amount, $description, $status, $control_word]);
        
        // Если статус "completed", пополняем баланс
        if ($status === 'completed') {
            $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
            $stmt->execute([$amount, $user_id]);
        }
        
        $pdo->commit();
        
        $_SESSION['admin_message'] = ['type' => 'success', 'text' => 'Платеж успешно добавлен'];
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

<div class="container">
    <div class="admin-content">
        <?php require 'admin_sidebar.php'; ?>

        <main class="admin-main">
            <h1 class="admin-title">
                <i class="fas fa-plus-circle"></i> Добавить платеж
            </h1>
            
            <div class="admin-form-container">
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i>
                        <?php foreach ($errors as $error): ?>
                            <p><?= htmlspecialchars($error) ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" class="admin-form">
                    <div class="form-group">
                        <label for="user_id">Пользователь *</label>
                        <select id="user_id" name="user_id" class="form-control" required>
                            <option value="">-- Выберите пользователя --</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?= $user['id'] ?>" <?= isset($_POST['user_id']) && $_POST['user_id'] == $user['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($user['email']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="amount">Сумма (₽) *</label>
                        <input type="number" id="amount" name="amount" class="form-control" 
                               min="0.01" step="0.01" required
                               value="<?= htmlspecialchars($_POST['amount'] ?? '') ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="description">Описание *</label>
                        <input type="text" id="description" name="description" class="form-control" required
                               value="<?= htmlspecialchars($_POST['description'] ?? '') ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="control_word">Контрольное слово</label>
                        <input type="text" id="control_word" name="control_word" class="form-control"
                               value="<?= htmlspecialchars($_POST['control_word'] ?? generateControlWord()) ?>">
                        <small class="text-muted">Оставьте пустым для автоматической генерации</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="status">Статус *</label>
                        <select id="status" name="status" class="form-control" required>
                            <option value="pending" <?= ($_POST['status'] ?? '') === 'pending' ? 'selected' : '' ?>>Ожидание</option>
                            <option value="completed" <?= ($_POST['status'] ?? '') === 'completed' ? 'selected' : '' ?>>Завершен</option>
                            <option value="failed" <?= ($_POST['status'] ?? '') === 'failed' ? 'selected' : '' ?>>Ошибка</option>
                        </select>
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Сохранить
                        </button>
                        <a href="payments.php" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Отмена
                        </a>
                    </div>
                </form>
            </div>
        </main>
    </div>
</div>

<style>
    <?php include '../admin/css/payment_add_styles.css'; ?>
</style>

<?php require 'admin_footer.php'; ?>