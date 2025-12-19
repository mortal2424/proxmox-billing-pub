<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';


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

$title = "Юридическая информация | Админ панель | HomeVlad Cloud";
require 'admin_header.php';
?>

<style>
/* ========== ПЕРЕМЕННЫЕ ТЕМЫ ========== */
:root {
    /* Светлая тема по умолчанию */
    --db-bg: #f8fafc;
    --db-card-bg: #ffffff;
    --db-border: #e2e8f0;
    --db-text: #1e293b;
    --db-text-secondary: #64748b;
    --db-text-muted: #94a3b8;
    --db-hover: #f1f5f9;
    --db-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
    --db-shadow-hover: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
    --db-accent: #00bcd4;
    --db-accent-light: rgba(0, 188, 212, 0.1);
    --db-success: #10b981;
    --db-warning: #f59e0b;
    --db-danger: #ef4444;
    --db-info: #3b82f6;
    --db-purple: #8b5cf6;
}

[data-theme="dark"] {
    --db-bg: #0f172a;
    --db-card-bg: #1e293b;
    --db-border: #334155;
    --db-text: #ffffff;
    --db-text-secondary: #cbd5e1;
    --db-text-muted: #94a3b8;
    --db-hover: #2d3748;
    --db-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.3), 0 1px 2px 0 rgba(0, 0, 0, 0.2);
    --db-shadow-hover: 0 10px 15px -3px rgba(0, 0, 0, 0.3), 0 4px 6px -2px rgba(0, 0, 0, 0.2);
}

/* ========== ОСНОВНЫЕ СТИЛИ ДАШБОРДА ========== */
.dashboard-wrapper {
    padding: 20px;
    background: var(--db-bg);
    min-height: calc(100vh - 70px);
    margin-left: 280px;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.admin-sidebar.compact + .dashboard-wrapper {
    margin-left: 70px;
}

.dashboard-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    padding: 24px;
    background: var(--db-card-bg);
    border-radius: 12px;
    border: 1px solid var(--db-border);
    box-shadow: var(--db-shadow);
}

.header-left h1 {
    color: var(--db-text);
    font-size: 24px;
    margin: 0 0 8px 0;
    display: flex;
    align-items: center;
    gap: 12px;
}

.header-left h1 i {
    color: var(--db-accent);
}

.header-left p {
    color: var(--db-text-secondary);
    font-size: 14px;
    margin: 0;
}

/* ========== СТИЛИ ФОРМЫ ========== */
.legal-form-container {
    background: var(--db-card-bg);
    border-radius: 12px;
    border: 1px solid var(--db-border);
    box-shadow: var(--db-shadow);
    overflow: hidden;
}

.legal-form {
    padding: 24px;
}

.form-section {
    margin-bottom: 32px;
    padding-bottom: 24px;
    border-bottom: 1px solid var(--db-border);
}

.form-section:last-child {
    border-bottom: none;
    margin-bottom: 0;
}

.section-title {
    color: var(--db-text);
    font-size: 18px;
    margin: 0 0 20px 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.section-title i {
    color: var(--db-accent);
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
    margin-bottom: 20px;
}

.form-group {
    margin-bottom: 20px;
}

.form-label {
    display: block;
    margin-bottom: 8px;
    color: var(--db-text);
    font-weight: 500;
    font-size: 14px;
}

.form-input,
.form-textarea {
    width: 87%;
    padding: 12px 16px;
    border: 1px solid var(--db-border);
    border-radius: 8px;
    background: var(--db-card-bg);
    color: var(--db-text);
    font-size: 14px;
    transition: all 0.3s ease;
}

.form-input:focus,
.form-textarea:focus {
    outline: none;
    border-color: var(--db-accent);
    box-shadow: 0 0 0 3px var(--db-accent-light);
}

.form-textarea {
    min-height: 100px;
    resize: vertical;
    font-family: inherit;
}

.form-actions {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    padding-top: 24px;
    border-top: 1px solid var(--db-border);
}

.btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 24px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 500;
    border: none;
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
}

.btn-primary {
    background: linear-gradient(135deg, var(--db-accent), #0097a7);
    color: white;
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: var(--db-shadow-hover);
}

.btn-secondary {
    background: var(--db-card-bg);
    color: var(--db-text);
    border: 1px solid var(--db-border);
}

.btn-secondary:hover {
    background: var(--db-hover);
    border-color: var(--db-accent);
}

/* ========== УВЕДОМЛЕНИЯ ========== */
.alert {
    padding: 16px 20px;
    border-radius: 8px;
    margin-bottom: 24px;
    display: flex;
    align-items: flex-start;
    gap: 12px;
    border: 1px solid transparent;
}

.alert-danger {
    background-color: rgba(239, 68, 68, 0.1);
    border-color: rgba(239, 68, 68, 0.2);
    color: var(--db-danger);
}

.alert-success {
    background-color: rgba(16, 185, 129, 0.1);
    border-color: rgba(16, 185, 129, 0.2);
    color: var(--db-success);
}

.alert i {
    font-size: 18px;
    margin-top: 2px;
}

.alert strong {
    font-weight: 600;
}

.alert p {
    margin: 4px 0 0 0;
    font-size: 14px;
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
    }

    .form-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .dashboard-wrapper {
        padding: 15px;
    }

    .dashboard-header {
        flex-direction: column;
        align-items: stretch;
        gap: 15px;
    }

    .legal-form {
        padding: 20px;
    }

    .form-actions {
        flex-direction: column;
    }

    .btn {
        width: 100%;
        justify-content: center;
    }
}

@media (max-width: 480px) {
    .legal-form {
        padding: 16px;
    }

    .form-section {
        margin-bottom: 24px;
        padding-bottom: 16px;
    }

    .section-title {
        font-size: 16px;
    }
}
</style>

<!-- Подключаем сайдбар -->
<?php require 'admin_sidebar.php'; ?>

<!-- Дашборд -->
<div class="dashboard-wrapper">
    <!-- Шапка страницы -->
    <div class="dashboard-header">
        <div class="header-left">
            <h1><i class="fas fa-building"></i> Юридическая информация</h1>
            <p>Управление реквизитами компании</p>
        </div>
        <div class="header-right">
            <button onclick="window.print()" class="btn btn-secondary">
                <i class="fas fa-print"></i> Распечатать
            </button>
        </div>
    </div>

    <!-- Уведомления -->
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger fade-in">
            <i class="fas fa-exclamation-circle"></i>
            <div>
                <strong>Ошибка при сохранении данных:</strong>
                <?php foreach ($errors as $error): ?>
                    <p><?= htmlspecialchars($error) ?></p>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success fade-in">
            <i class="fas fa-check-circle"></i>
            <div>
                <strong>Успешно!</strong>
                <p>Юридическая информация сохранена.</p>
            </div>
        </div>
    <?php endif; ?>

    <!-- Форма -->
    <div class="legal-form-container">
        <form method="POST" class="legal-form">
            <!-- Основная информация -->
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
                    <textarea name="legal_address" class="form-textarea" required><?=
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

            <!-- Банковские реквизиты -->
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

            <!-- Руководитель -->
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

            <!-- Контактная информация -->
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

            <!-- Кнопки действий -->
            <div class="form-actions">
                <a href="/admin/index.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Назад
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Сохранить изменения
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Анимация появления элементов
    const elements = document.querySelectorAll('.dashboard-header, .alert, .legal-form-container');
    elements.forEach((element, index) => {
        element.style.opacity = '0';
        element.style.transform = 'translateY(20px)';

        setTimeout(() => {
            element.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
            element.style.opacity = '1';
            element.style.transform = 'translateY(0)';
        }, index * 100);
    });

    // Валидация формы
    const form = document.querySelector('.legal-form');
    if (form) {
        form.addEventListener('submit', function(e) {
            const requiredFields = form.querySelectorAll('[required]');
            let isValid = true;

            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    isValid = false;
                    field.style.borderColor = 'var(--db-danger)';
                    field.style.boxShadow = '0 0 0 3px rgba(239, 68, 68, 0.2)';
                } else {
                    field.style.borderColor = '';
                    field.style.boxShadow = '';
                }
            });

            if (!isValid) {
                e.preventDefault();
                const alertDiv = document.createElement('div');
                alertDiv.className = 'alert alert-danger fade-in';
                alertDiv.innerHTML = `
                    <i class="fas fa-exclamation-circle"></i>
                    <div>
                        <strong>Ошибка!</strong>
                        <p>Пожалуйста, заполните все обязательные поля.</p>
                    </div>
                `;
                form.insertBefore(alertDiv, form.firstChild);

                setTimeout(() => {
                    alertDiv.style.transition = 'opacity 0.3s ease';
                    alertDiv.style.opacity = '0';
                    setTimeout(() => alertDiv.remove(), 300);
                }, 5000);
            }
        });
    }

    // Автоматическое скрытие успешных уведомлений
    const successAlert = document.querySelector('.alert-success');
    if (successAlert) {
        setTimeout(() => {
            successAlert.style.transition = 'opacity 0.3s ease';
            successAlert.style.opacity = '0';
            setTimeout(() => successAlert.remove(), 300);
        }, 5000);
    }

    // Обновление отступа при сворачивании сайдбара
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
});

// Функция для предпросмотра перед печатью
function printForm() {
    const printWindow = window.open('', '_blank');
    const formData = new FormData(document.querySelector('.legal-form'));

    let htmlContent = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>Юридическая информация - Печать</title>
            <style>
                body { font-family: Arial, sans-serif; padding: 20px; }
                h1 { color: #333; border-bottom: 2px solid #00bcd4; padding-bottom: 10px; }
                .section { margin-bottom: 30px; }
                .section h3 { color: #555; background: #f5f5f5; padding: 10px; border-radius: 4px; }
                .field { margin-bottom: 15px; }
                .field strong { display: inline-block; width: 200px; color: #666; }
                .footer { margin-top: 50px; text-align: center; color: #999; font-size: 12px; }
            </style>
        </head>
        <body>
            <h1>Юридическая информация компании</h1>
            <p>Дата печати: ${new Date().toLocaleDateString('ru-RU')}</p>
    `;

    // Добавляем данные формы
    for (let [key, value] of formData) {
        if (value) {
            const label = document.querySelector(`[name="${key}"]`).previousElementSibling?.textContent || key;
            htmlContent += `
                <div class="field">
                    <strong>${label}:</strong>
                    <span>${value}</span>
                </div>
            `;
        }
    }

    htmlContent += `
            <div class="footer">
                <p>Документ сгенерирован системой HomeVlad Cloud</p>
            </div>
        </body>
        </html>
    `;

    printWindow.document.write(htmlContent);
    printWindow.document.close();
    printWindow.print();
}
</script>

<?php
require 'admin_footer.php';
?>
