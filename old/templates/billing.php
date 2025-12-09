<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

checkAuth();

$db = new Database();
$pdo = $db->getConnection();
$user_id = $_SESSION['user']['id'];

// Получаем данные пользователя
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Параметры фильтрации для истории платежей
$payments_timeframe = $_GET['payments_timeframe'] ?? 'month';
$payments_per_page = $_GET['payments_per_page'] ?? 20;
$payments_page = $_GET['payments_page'] ?? 1;

// Параметры фильтрации для истории списаний
$debits_timeframe = $_GET['debits_timeframe'] ?? 'month';
$debits_per_page = $_GET['debits_per_page'] ?? 20;
$debits_page = $_GET['debits_page'] ?? 1;

// Валидация параметров
$payments_per_page = in_array($payments_per_page, [10, 20, 50, 100]) ? $payments_per_page : 20;
$debits_per_page = in_array($debits_per_page, [10, 20, 50, 100]) ? $debits_per_page : 20;
$payments_page = max(1, (int)$payments_page);
$debits_page = max(1, (int)$debits_page);

// Получаем историю платежей с фильтрацией и пагинацией
$payments_where = "WHERE user_id = ?";
$payments_params = [$user_id];

// Добавляем фильтр по времени для платежей
switch ($payments_timeframe) {
    case 'day':
        $payments_where .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)";
        break;
    case 'week':
        $payments_where .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 1 WEEK)";
        break;
    case 'month':
        $payments_where .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)";
        break;
    case 'year':
        $payments_where .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR)";
        break;
}

// Получаем общее количество платежей для пагинации
$stmt = $pdo->prepare("SELECT COUNT(*) FROM payments $payments_where");
$stmt->execute($payments_params);
$payments_total = $stmt->fetchColumn();
$payments_total_pages = ceil($payments_total / $payments_per_page);

// Получаем платежи с пагинацией
$payments_offset = ($payments_page - 1) * $payments_per_page;
$stmt = $pdo->prepare("
    SELECT * FROM payments 
    $payments_where 
    ORDER BY created_at DESC 
    LIMIT ? OFFSET ?
");
$payments_params[] = $payments_per_page;
$payments_params[] = $payments_offset;
$stmt->execute($payments_params);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Получаем историю списаний с фильтрацией и пагинацией
$debits_where = "WHERE user_id = ? AND type = 'debit'";
$debits_params = [$user_id];

// Добавляем фильтр по времени для списаний
switch ($debits_timeframe) {
    case 'day':
        $debits_where .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)";
        break;
    case 'week':
        $debits_where .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 1 WEEK)";
        break;
    case 'month':
        $debits_where .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)";
        break;
    case 'year':
        $debits_where .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR)";
        break;
}

// Получаем общее количество списаний для пагинации
$stmt = $pdo->prepare("SELECT COUNT(*) FROM transactions $debits_where");
$stmt->execute($debits_params);
$debits_total = $stmt->fetchColumn();
$debits_total_pages = ceil($debits_total / $debits_per_page);

// Получаем списания с пагинацией
$debits_offset = ($debits_page - 1) * $debits_per_page;
$stmt = $pdo->prepare("
    SELECT * FROM transactions 
    $debits_where 
    ORDER BY created_at DESC 
    LIMIT ? OFFSET ?
");
$debits_params[] = $debits_per_page;
$debits_params[] = $debits_offset;
$stmt->execute($debits_params);
$debits = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Получаем данные юридического лица
$stmt = $pdo->prepare("SELECT * FROM legal_entity_info LIMIT 1");
$stmt->execute();
$legal_entity = $stmt->fetch();

$errors = [];
$success = false;
$payment_method = '';
$control_word = '';
$amount = 0;
$invoice_number = '';

// Обработка генерации PDF счета
if (isset($_GET['download_invoice'])) {
    generateInvoicePDF($pdo, $user_id, $_GET['invoice_number'], $_GET['amount'], $legal_entity);
    exit;
}

// Обработка формы пополнения баланса
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['amount'])) {
    try {
        $amount = (float)$_POST['amount'];
        $payment_method = $_POST['payment_method'] ?? '';
        
        // Валидация суммы
        if ($amount < 50) {
            throw new Exception('Минимальная сумма пополнения - 50 рублей');
        }
        if ($amount > 50000) {
            throw new Exception('Максимальная сумма пополнения - 50,000 рублей');
        }

        // Генерируем контрольное слово
        $control_word = generateControlWord();
        
        // Генерируем номер счета, если выбран этот метод
        if ($payment_method === 'invoice') {
            $invoice_number = 'INV-' . date('Ymd') . '-' . str_pad($user_id, 5, '0', STR_PAD_LEFT) . '-' . rand(100, 999);
        }
        
        // Создаем запись о платеже
        $stmt = $pdo->prepare("
            INSERT INTO payments 
            (user_id, amount, description, status, control_word, payment_method, invoice_number, created_at) 
            VALUES (?, ?, ?, 'pending', ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $user_id,
            $amount,
            $payment_method === 'card' ? "Пополнение баланса (перевод на карту)" : 
            ($payment_method === 'invoice' ? "Пополнение баланса (счет №$invoice_number)" : "Пополнение баланса через СБП"),
            $control_word,
            $payment_method,
            $payment_method === 'invoice' ? $invoice_number : null
        ]);
        
        $payment_id = $pdo->lastInsertId();
        $success = true;
        
    } catch (Exception $e) {
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

// Функция генерации PDF счета
function generateInvoicePDF($pdo, $user_id, $invoice_number, $amount, $legal_entity) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    
    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('isPhpEnabled', true);
    $options->set('isFontSubsettingEnabled', true);
    
    $dompdf = new Dompdf($options);
    
    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
        <style>
            @page {
                margin: 1.5cm 1cm 1cm 1cm;
                size: A4;
            }
            * {
                box-sizing: border-box;
                margin: 0;
                padding: 0;
            }
            body {
                font-family: DejaVu Sans, sans-serif;
                font-size: 12px;
                line-height: 1.35;
                color: #334155;
                width: 100%;
                padding: 0;
                margin: 0;
            }
            .invoice-container {
                width: 19cm;
                margin: 0 auto;
                padding: 0;
            }
            .invoice-header {
                background: linear-gradient(135deg, #3b82f6, #1d4ed8);
                color: #334155;
                padding: 15px 20px;
                margin-bottom: 12px;
                text-align: center;
                position: relative;
                border-radius: 5px 5px 0 0;
                /* Убрана рамка border: 1px solid #e2e8f0; */
            }
            .logo {
                font-size: 16px;
                font-weight: bold;
                margin-bottom: 8px;
                display: block;
                color: #1e40af;
            }
            .invoice-title {
                font-size: 18px;
                font-weight: bold;
                margin: 0 0 8px 0;
                color: #1e40af;
            }
            .invoice-number {
                font-size: 16px;
                font-weight: 600;
                margin: 8px 0;
                background-color: rgba(255,255,255,0.5);
                display: inline-block;
                padding: 5px 12px;
                border-radius: 4px;
                color: #1e40af;
            }
            .invoice-date {
                font-size: 12px;
                margin: 3px 0 0;
                color: #64748b;
            }
            .company-info {
                background-color: #f1f5f9;
                padding: 12px 15px;
                border-radius: 5px;
                margin-bottom: 12px;
                font-size: 12px;
                border: 1px solid #e2e8f0;
            }
            .company-name {
                font-weight: bold;
                font-size: 14px;
                color: #1e40af;
                margin-bottom: 4px;
            }
            .invoice-details {
                display: flex;
                justify-content: space-between;
                margin-bottom: 12px;
                gap: 12px;
                font-size: 12px;
            }
            .details-block {
                flex: 1;
                background-color: #f1f5f9;
                padding: 10px 12px;
                border-radius: 5px;
                border: 1px solid #e2e8f0;
            }
            .details-title {
                font-weight: bold;
                color: #1e40af;
                margin-bottom: 5px;
                font-size: 13px;
            }
            .invoice-table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 12px;
                font-size: 12px;
            }
            .invoice-table th {
                background-color: #3b82f6;
                color: white;
                padding: 6px 8px;
                text-align: left;
                font-weight: 500;
                font-size: 12px;
            }
            .invoice-table td {
                padding: 6px 8px;
                border-bottom: 1px solid #e2e8f0;
            }
            .invoice-table tr:last-child td {
                border-bottom: none;
            }
            .total {
                text-align: right;
                background-color: #f1f5f9;
                padding: 10px 12px;
                border-radius: 5px;
                margin-bottom: 15px;
                font-size: 13px;
                border: 1px solid #e2e8f0;
            }
            .total-amount {
                font-size: 15px;
                font-weight: bold;
                color: #1e40af;
            }
            .signature {
                display: flex;
                justify-content: space-between;
                margin-top: 20px;
                font-size: 12px;
            }
            .signature-block {
                width: 45%;
                text-align: center;
            }
            .signature-line {
                border-top: 1px solid #cbd5e1;
                margin: 15px auto 5px;
                width: 80%;
            }
            .signature-name {
                font-weight: bold;
                margin-top: 3px;
                color: #1e40af;
            }
            .footer {
                margin-top: 15px;
                font-size: 11px;
                color: #64748b;
                text-align: center;
                padding-top: 8px;
                border-top: 1px solid #e2e8f0;
                page-break-inside: avoid;
            }
            .badge {
                display: inline-block;
                padding: 2px 6px;
                background-color: #dbeafe;
                color: #1e40af;
                border-radius: 3px;
                font-size: 11px;
                font-weight: 500;
                margin-top: 3px;
            }
        </style>
    </head>
    <body>
        <div class="invoice-container">
            <div class="invoice-header">
                <div class="logo">HomeVlad Cloud</div>
                <h1 class="invoice-title">СЧЕТ НА ОПЛАТУ</h1>
                <div class="invoice-number">№ ' . htmlspecialchars($invoice_number) . '</div>
                <div class="invoice-date">от ' . date('d.m.Y') . '</div>
            </div>
            
            <div class="company-info">
                <div class="company-name">' . htmlspecialchars($legal_entity['company_name']) . '</div>
                <div>ИНН ' . htmlspecialchars($legal_entity['tax_number']) . ', КПП ' . htmlspecialchars($legal_entity['registration_number']) . '</div>
                <div>' . htmlspecialchars($legal_entity['legal_address']) . '</div>
                <div>Банк: ' . htmlspecialchars($legal_entity['bank_name']) . '</div>
                <div>Р/с: ' . htmlspecialchars($legal_entity['bank_account']) . ', БИК: ' . htmlspecialchars($legal_entity['bic']) . '</div>
            </div>
            
            <div class="invoice-details">
                <div class="details-block">
                    <div class="details-title">Поставщик</div>
                    <div>' . htmlspecialchars($legal_entity['company_name']) . '</div>
                    <div>ИНН ' . htmlspecialchars($legal_entity['tax_number']) . '</div>
                </div>
                <div class="details-block">
                    <div class="details-title">Покупатель</div>
                    <div>' . htmlspecialchars($user['full_name']) . '</div>
                    <div>Email: ' . htmlspecialchars($user['email']) . '</div>
                </div>
            </div>
            
            <table class="invoice-table">
                <thead>
                    <tr>
                        <th width="5%">№</th>
                        <th width="60%">Наименование</th>
                        <th width="10%">Кол-во</th>
                        <th width="12%">Цена</th>
                        <th width="13%">Сумма</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>1</td>
                        <td>Пополнение баланса в HomeVlad Cloud</td>
                        <td>1</td>
                        <td>' . number_format($amount, 2) . ' ₽</td>
                        <td>' . number_format($amount, 2) . ' ₽</td>
                    </tr>
                </tbody>
            </table>
            
            <div class="total">
                <div>Итого к оплате: <span class="total-amount">' . number_format($amount, 2) . ' ₽</span></div>
                <div><span class="badge">Без НДС</span></div>
            </div>
            
            <div class="signature">
                <div class="signature-block">
                    <div class="signature-line"></div>
                    <div class="signature-name">' . htmlspecialchars($legal_entity['director_name']) . '</div>
                    <div>' . htmlspecialchars($legal_entity['director_position']) . '</div>
                </div>
                <div class="signature-block">
                    <div class="signature-line"></div>
                    <div class="signature-name">Покупатель</div>
                    <div>Подпись</div>
                </div>
            </div>
            
            <div class="footer">
                <div>Счет действителен в течение 5 банковских дней с даты выставления</div>
                <div>Контактная информация: ' . htmlspecialchars($legal_entity['contact_phone']) . ', ' . htmlspecialchars($legal_entity['contact_email']) . '</div>
            </div>
        </div>
    </body>
    </html>
    ';
    
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    
    $dompdf->stream("Счет_$invoice_number.pdf", array("Attachment" => true));
}

$title = "Биллинг | HomeVlad Cloud";
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
    <link rel="stylesheet" href="/css/themes.css">
    <style>
        <?php include '../admin/css/admin_style.css'; ?>
        <?php include '../css/billing_styles.css'; ?>
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
                <!-- Заголовок страницы -->
                <div class="admin-header-container">
                    <h1 class="admin-title">
                        <i class="fas fa-credit-card"></i> Биллинг и платежи
                    </h1>
                </div>
                
                <!-- Карточки баланса -->
                <div class="balance-cards">
                    <!-- Текущий баланс -->
                    <div class="balance-card">
                        <div class="balance-icon">
                            <i class="fas fa-wallet"></i>
                        </div>
                        <h2>Текущий баланс</h2>
                        <div class="balance-amount"><?= number_format($user['balance'], 2) ?> ₽</div>
                        <p>Основной баланс для оплаты услуг</p>
                    </div>
                    
                    <!-- Бонусный баланс -->
                    <div class="balance-card bonus">
                        <div class="balance-icon">
                            <i class="fas fa-gift"></i>
                        </div>
                        <h2>Бонусный баланс</h2>
                        <div class="balance-amount"><?= number_format($user['bonus_balance'], 2) ?> ₽</div>
                        <p>Приветственные и бонусные средства</p>
                    </div>
                </div>
                
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i>
                        <?php foreach ($errors as $error): ?>
                            <p><?= htmlspecialchars($error) ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <p>Платеж успешно создан! Используйте инструкции ниже для пополнения баланса.</p>
                    </div>
                <?php endif; ?>
                
                <!-- Форма пополнения баланса -->
                <div class="payment-form">
                    <h2><i class="fas fa-plus-circle"></i> Пополнение баланса</h2>
                    
                    <form method="POST" id="payment-form">
                        <div class="form-group">
                            <label class="form-label" for="amount">Сумма пополнения (₽)</label>
                            <input type="number" id="amount" name="amount" class="form-control" 
                                   min="50" max="50000" step="1" required
                                   placeholder="Введите сумму от 50 до 50,000 рублей">
                        </div>
                        
                        <div class="payment-methods">
                            <div class="payment-method" data-method="sbp">
                                <i class="fas fa-qrcode"></i>
                                <h3>СБП</h3>
                                <p>Оплата через Систему Быстрых Платежей</p>
                            </div>
                            
                            <div class="payment-method" data-method="card">
                                <i class="fas fa-credit-card"></i>
                                <h3>Перевод на карту</h3>
                                <p>Ручной перевод по реквизитам карты</p>
                            </div>
                            
                            <div class="payment-method" data-method="invoice">
                                <i class="fas fa-file-invoice"></i>
                                <h3>Выставить счет</h3>
                                <p>Для юридических лиц и ИП</p>
                            </div>
                        </div>
                        
                        <input type="hidden" name="payment_method" id="payment_method" value="">
                        
                        <!-- Детали оплаты по СБП -->
                        <div class="payment-details" id="sbp-details">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-qrcode"></i> Продолжить с СБП
                            </button>
                        </div>
                        
                        <!-- Детали оплаты переводом на карту -->
                        <div class="payment-details" id="card-details">
                            <div class="bank-card">
                                <h3><i class="fas fa-credit-card"></i> Реквизиты для перевода</h3>
                                <p>Используйте эти реквизиты для перевода с карты на карту</p>
                                
                                <div class="bank-details">
                                    <p><strong>Номер карты:</strong> 2200 1514 4839 6171</p>
                                    <p><strong>Получатель:</strong> Вадим К.</p>
                                    <p><strong>Банк:</strong> Альфа-Банк</p>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="card_control_word">Контрольное слово</label>
                                <input type="text" id="card_control_word" name="control_word" class="form-control" 
                                       value="<?= $success && $payment_method === 'card' ? $control_word : '' ?>" readonly>
                                <small>Укажите это слово в комментарии к переводу</small>
                            </div>
                            
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-check"></i> Подтвердить платеж
                            </button>
                        </div>
                        
                        <!-- Детали оплаты по счету -->
                        <div class="payment-details" id="invoice-details">
                            <?php if ($success && $payment_method === 'invoice'): ?>
                                <div class="invoice-preview">
                                    <h3><i class="fas fa-file-invoice-dollar"></i> Счет № <?= $invoice_number ?></h3>
                                    <p><strong>Дата:</strong> <?= date('d.m.Y') ?></p>
                                    <p><strong>Сумма:</strong> <?= number_format($amount, 2) ?> ₽</p>
                                    <p><strong>Статус:</strong> Ожидает оплаты</p>
                                    
                                    <a href="billing.php?download_invoice=1&invoice_number=<?= $invoice_number ?>&amount=<?= $amount ?>" class="btn btn-primary">
                                        <i class="fas fa-download"></i> Скачать счет в PDF
                                    </a>
                                </div>
                                
                                <div class="company-info">
                                    <h4>Реквизиты для оплаты:</h4>
                                    <p><strong>Получатель:</strong> <?= htmlspecialchars($legal_entity['company_name']) ?></p>
                                    <p><strong>ИНН:</strong> <?= htmlspecialchars($legal_entity['tax_number']) ?></p>
                                    <p><strong>Банк:</strong> <?= htmlspecialchars($legal_entity['bank_name']) ?></p>
                                    <p><strong>Р/с:</strong> <?= htmlspecialchars($legal_entity['bank_account']) ?></p>
                                    <p><strong>БИК:</strong> <?= htmlspecialchars($legal_entity['bic']) ?></p>
                                </div>
                                
                                <div class="payment-instructions">
                                    <p><i class="fas fa-info-circle"></i> <strong>Инструкция по оплате:</strong></p>
                                    <ol>
                                        <li>Скачайте счет в PDF</li>
                                        <li>Оплатите счет по указанным реквизитам</li>
                                        <li>После оплаты создайте тикет с темой "Биллинг и оплата"</li>
                                        <li>Приложите копию платежного поручения или чека</li>
                                    </ol>
                                    <p>После проверки платежа администратором, сумма будет зачислена на ваш баланс.</p>
                                </div>
                            <?php else: ?>
                                <div class="form-group">
                                    <label class="form-label" for="invoice_control_word">Контрольное слово</label>
                                    <input type="text" id="invoice_control_word" name="control_word" class="form-control" readonly>
                                    <small>Это слово будет указано в счете</small>
                                </div>
                                
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-file-invoice"></i> Создать счет
                                </button>
                            <?php endif; ?>
                        </div>
                    </form>
                    
                    <?php if ($success && $payment_method === 'sbp'): ?>
                        <div class="qr-code-container" id="qr-code-container">
                            <h3><i class="fas fa-mobile-alt"></i> Оплата через СБП</h3>
                            <div class="qr-code">
                                <a href="http://www.stqr.ru/qrcodes/QR-code_yamoney_7_Apr_2025_10480_5409.svg" alt="QR-код для оплаты"><img src="http://www.stqr.ru/qrcodes/QR-code_yamoney_7_Apr_2025_10480_5409.svg" width=300 height=300></a>
                            </div>
                            
                            <div class="payment-details">
                                <p><strong>Контрольное слово:</strong> <?= $control_word ?></p>
                                <p><strong>Сумма к оплате:</strong> <?= number_format($amount, 2) ?> ₽</p>
                                <p><strong>Реквизиты:</strong> СБП (Система быстрых платежей)</p>
                                <p><strong>Получатель:</strong> HomeVlad Cloud</p>
                                <p><strong>Телефон:</strong> +7 (964) 438-46-46</p>
                            </div>
                            
                            <div class="payment-instructions">
                                <p><i class="fas fa-info-circle"></i> <strong>Инструкция по оплате:</strong></p>
                                <ol>
                                    <li>Откройте приложение вашего банка</li>
                                    <li>Выберите оплату по QR-коду или СБП</li>
                                    <li>Отсканируйте QR-код или введите реквизиты вручную</li>
                                    <li>Укажите контрольное слово в комментарии к платежу</li>
                                    <li>Подтвердите платеж</li>
                                    <li>Создайте тикет выберите тему Билинг и оплата и приложите чек об оплате</li>
                                </ol>
                                <p>После проверки платежа администратором, сумма будет зачислена на ваш баланс.</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Табы истории -->
                <div class="history-tabs">
                    <div class="history-tab active" data-tab="payments">История платежей</div>
                    <div class="history-tab" data-tab="debits">История списаний</div>
                </div>
                
                <!-- Фильтры для истории платежей -->
                <div class="history-filters active" id="payments-filters">
                    <form method="get" class="filter-form">
                        <input type="hidden" name="payments_page" value="1">
                        
                        <div class="filter-group">
                            <label for="payments_timeframe">Период:</label>
                            <select name="payments_timeframe" id="payments_timeframe" onchange="this.form.submit()">
                                <option value="day" <?= $payments_timeframe === 'day' ? 'selected' : '' ?>>День</option>
                                <option value="week" <?= $payments_timeframe === 'week' ? 'selected' : '' ?>>Неделя</option>
                                <option value="month" <?= $payments_timeframe === 'month' ? 'selected' : '' ?>>Месяц</option>
                                <option value="year" <?= $payments_timeframe === 'year' ? 'selected' : '' ?>>Год</option>
                                <option value="all" <?= $payments_timeframe === 'all' ? 'selected' : '' ?>>Все время</option>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label for="payments_per_page">Показывать по:</label>
                            <select name="payments_per_page" id="payments_per_page" onchange="this.form.submit()">
                                <option value="10" <?= $payments_per_page == 10 ? 'selected' : '' ?>>10</option>
                                <option value="20" <?= $payments_per_page == 20 ? 'selected' : '' ?>>20</option>
                                <option value="50" <?= $payments_per_page == 50 ? 'selected' : '' ?>>50</option>
                                <option value="100" <?= $payments_per_page == 100 ? 'selected' : '' ?>>100</option>
                            </select>
                        </div>
                    </form>
                </div>
                
                <!-- История платежей -->
                <div class="history-content active" id="payments-history">
                    <?php if (empty($payments)): ?>
                        <p>У вас пока нет платежей</p>
                    <?php else: ?>
                        <?php foreach ($payments as $payment): ?>
                            <div class="payment-item">
                                <div>
                                    <div class="payment-description">
                                        <?= htmlspecialchars($payment['description']) ?>
                                        <?php if (!empty($payment['control_word'])): ?>
                                            <small>(<?= htmlspecialchars($payment['control_word']) ?>)</small>
                                        <?php endif; ?>
                                    </div>
                                    <div class="payment-date">
                                        <?= date('d.m.Y H:i', strtotime($payment['created_at'])) ?>
                                    </div>
                                </div>
                                <div style="text-align: right;">
                                    <div class="payment-amount">
                                        +<?= number_format($payment['amount'], 2) ?> ₽
                                    </div>
                                    <div class="payment-status 
                                        <?= $payment['status'] === 'completed' ? 'status-completed' : 
                                           ($payment['status'] === 'failed' ? 'status-failed' : 'status-pending') ?>">
                                        <?= $payment['status'] === 'completed' ? 'Завершен' : 
                                           ($payment['status'] === 'failed' ? 'Ошибка' : 'Ожидание') ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        
                        <?php if ($payments_total_pages > 1): ?>
                            <div class="pagination">
                                <?php if ($payments_page > 1): ?>
                                    <a href="?payments_page=<?= $payments_page - 1 ?>&payments_timeframe=<?= $payments_timeframe ?>&payments_per_page=<?= $payments_per_page ?>&debits_timeframe=<?= $debits_timeframe ?>&debits_per_page=<?= $debits_per_page ?>&debits_page=<?= $debits_page ?>" class="page-link">
                                        <i class="fas fa-chevron-left"></i> Назад
                                    </a>
                                <?php endif; ?>
                                
                                <span class="page-info">Страница <?= $payments_page ?> из <?= $payments_total_pages ?></span>
                                
                                <?php if ($payments_page < $payments_total_pages): ?>
                                    <a href="?payments_page=<?= $payments_page + 1 ?>&payments_timeframe=<?= $payments_timeframe ?>&payments_per_page=<?= $payments_per_page ?>&debits_timeframe=<?= $debits_timeframe ?>&debits_per_page=<?= $debits_per_page ?>&debits_page=<?= $debits_page ?>" class="page-link">
                                        Вперед <i class="fas fa-chevron-right"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                
                <!-- Фильтры для истории списаний -->
                <div class="history-filters" id="debits-filters">
                    <form method="get" class="filter-form">
                        <input type="hidden" name="debits_page" value="1">
                        
                        <div class="filter-group">
                            <label for="debits_timeframe">Период:</label>
                            <select name="debits_timeframe" id="debits_timeframe" onchange="this.form.submit()">
                                <option value="day" <?= $debits_timeframe === 'day' ? 'selected' : '' ?>>День</option>
                                <option value="week" <?= $debits_timeframe === 'week' ? 'selected' : '' ?>>Неделя</option>
                                <option value="month" <?= $debits_timeframe === 'month' ? 'selected' : '' ?>>Месяц</option>
                                <option value="year" <?= $debits_timeframe === 'year' ? 'selected' : '' ?>>Год</option>
                                <option value="all" <?= $debits_timeframe === 'all' ? 'selected' : '' ?>>Все время</option>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label for="debits_per_page">Показывать по:</label>
                            <select name="debits_per_page" id="debits_per_page" onchange="this.form.submit()">
                                <option value="10" <?= $debits_per_page == 10 ? 'selected' : '' ?>>10</option>
                                <option value="20" <?= $debits_per_page == 20 ? 'selected' : '' ?>>20</option>
                                <option value="50" <?= $debits_per_page == 50 ? 'selected' : '' ?>>50</option>
                                <option value="100" <?= $debits_per_page == 100 ? 'selected' : '' ?>>100</option>
                            </select>
                        </div>
                    </form>
                </div>
                
                <!-- История списаний -->
<div class="history-content" id="debits-history">
    <?php if (empty($debits)): ?>
        <p class="no-debits">У вас пока нет списаний</p>
    <?php else: ?>
        <?php foreach ($debits as $debit): ?>
            <div class="debit-item">
                <div class="debit-info">
                    <div class="debit-description">
                        <?= htmlspecialchars($debit['description']) ?>
                    </div>
                    <div class="debit-date">
                        <?= date('d.m.Y H:i', strtotime($debit['created_at'])) ?>
                    </div>
                </div>
                <div>
                    <div class="debit-amount">
                        -<?= number_format($debit['amount'], 6) ?> ₽
                    </div>
                    <div class="debit-balance-type">
                        <?= $debit['balance_type'] === 'main' ? 'Основной баланс' : 'Бонусный баланс' ?>
                    </div>
                </div>
                
                <?php if (!empty($debit['metadata'])): 
                    $metadata = json_decode($debit['metadata'], true);
                    if ($metadata && (isset($metadata['cpu']) || isset($metadata['tariff_name']) || isset($metadata['disk']))): ?>
                        <div class="debit-tooltip">
                            <div class="tooltip-header">Детализация списания</div>
                            
                            <?php if (isset($metadata['cpu'])): ?>
                                <!-- Показываем для кастомных ВМ -->
                                <div class="tooltip-row">
                                    <span><i class="fas fa-microchip tooltip-icon"></i> vCPU:</span>
                                    <span class="tooltip-value">
                                        <?= $metadata['cpu'] ?> × <?= number_format($metadata['cpu_price'], 6) ?> ₽
                                    </span>
                                </div>
                                <div class="tooltip-row">
                                    <span><i class="fas fa-memory tooltip-icon"></i> RAM:</span>
                                    <span class="tooltip-value">
                                        <?= $metadata['memory'] ?>MB × <?= number_format($metadata['memory_price'], 6) ?> ₽
                                    </span>
                                </div>
                                <div class="tooltip-row">
                                    <span><i class="fas fa-hdd tooltip-icon"></i> SDD:</span>
                                    <span class="tooltip-value">
                                        <?= $metadata['disk'] ?>GB × <?= number_format($metadata['disk_price'], 6) ?> ₽
                                    </span>
                                </div>
                            <?php elseif (isset($metadata['tariff_name'])): ?>
                                <!-- Показываем для тарифных ВМ -->
                                <div class="tooltip-row">
                                    <span><i class="fas fa-box tooltip-icon"></i> Тариф:</span>
                                    <span class="tooltip-value">
                                        <?= htmlspecialchars($metadata['tariff_name']) ?>
                                    </span>
                                </div>
                                <div class="tooltip-row">
                                    <span><i class="fas fa-money-bill-wave tooltip-icon"></i> Стоимость:</span>
                                    <span class="tooltip-value">
                                        <?= number_format($metadata['tariff_price'], 2) ?> ₽/мес
                                    </span>
                                </div>
                                <div class="tooltip-row">
                                    <span><i class="fas fa-clock tooltip-icon"></i> Списание:</span>
                                    <span class="tooltip-value">
                                        <?= number_format($debit['amount'], 6) ?> ₽/час
                                    </span>
                                </div>
                            <?php elseif (isset($metadata['disk'])): ?>
                                <!-- Показываем для остановленных ВМ (только диск) -->
                                <div class="tooltip-row">
                                    <span><i class="fas fa-hdd tooltip-icon"></i> Диск:</span>
                                    <span class="tooltip-value">
                                        <?= $metadata['disk'] ?>GB × <?= number_format($metadata['disk_price'], 6) ?> ₽
                                    </span>
                                </div>
                                <div class="tooltip-row">
                                    <span><i class="fas fa-info-circle tooltip-icon"></i> Статус:</span>
                                    <span class="tooltip-value">
                                        Списание только за диск
                                    </span>
                                </div>
                            <?php endif; ?>
                            
                            <div class="tooltip-row" style="margin-top: 8px; border-top: 1px solid var(--border-color); padding-top: 6px;">
                                <span><i class="fas fa-calculator tooltip-icon"></i> Итого:</span>
                                <span class="tooltip-value">
                                    <?= number_format($debit['amount'], 6) ?> ₽
                                </span>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        
        <?php if ($debits_total_pages > 1): ?>
            <div class="pagination">
                <?php if ($debits_page > 1): ?>
                    <a href="?debits_page=<?= $debits_page - 1 ?>&debits_timeframe=<?= $debits_timeframe ?>&debits_per_page=<?= $debits_per_page ?>&payments_timeframe=<?= $payments_timeframe ?>&payments_per_page=<?= $payments_per_page ?>&payments_page=<?= $payments_page ?>" class="page-link">
                        <i class="fas fa-chevron-left"></i> Назад
                    </a>
                <?php endif; ?>
                
                <span class="page-info">Страница <?= $debits_page ?> из <?= $debits_total_pages ?></span>
                
                <?php if ($debits_page < $debits_total_pages): ?>
                    <a href="?debits_page=<?= $debits_page + 1 ?>&debits_timeframe=<?= $debits_timeframe ?>&debits_per_page=<?= $debits_per_page ?>&payments_timeframe=<?= $payments_timeframe ?>&payments_per_page=<?= $payments_per_page ?>&payments_page=<?= $payments_page ?>" class="page-link">
                        Вперед <i class="fas fa-chevron-right"></i>
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

            </main>
        </div>
    </div>

    <?php include '../templates/headers/user_footer.php'; ?>

    <script>
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

    // Переключение между вкладками истории
    document.querySelectorAll('.history-tab').forEach(tab => {
        tab.addEventListener('click', function() {
            // Убираем активный класс у всех вкладок
            document.querySelectorAll('.history-tab').forEach(t => {
                t.classList.remove('active');
            });
            
            // Добавляем активный класс текущей вкладке
            this.classList.add('active');
            
            // Скрываем все блоки с контентом
            document.querySelectorAll('.history-content').forEach(c => {
                c.classList.remove('active');
            });
            
            // Скрываем все фильтры
            document.querySelectorAll('.history-filters').forEach(f => {
                f.classList.remove('active');
            });
            
            // Показываем нужный блок
            const tabId = this.getAttribute('data-tab');
            document.getElementById(tabId + '-history').classList.add('active');
            document.getElementById(tabId + '-filters').classList.add('active');
        });
    });

    // Выбор метода оплаты
    document.querySelectorAll('.payment-method').forEach(method => {
        method.addEventListener('click', function() {
            // Убираем активный класс у всех методов
            document.querySelectorAll('.payment-method').forEach(m => {
                m.classList.remove('active');
            });
            
            // Добавляем активный класс текущему методу
            this.classList.add('active');
            
            // Скрываем все детали оплаты
            document.querySelectorAll('.payment-details').forEach(d => {
                d.classList.remove('active');
            });
            
            // Показываем нужные детали
            const methodName = this.getAttribute('data-method');
            document.getElementById(methodName + '-details').classList.add('active');
            
            // Устанавливаем значение скрытого поля
            document.getElementById('payment_method').value = methodName;
            
            // Генерируем контрольное слово для карты и счета
            if (methodName === 'card' || methodName === 'invoice') {
                const adjectives = ['быстрый', 'надежный', 'безопасный', 'удобный', 'современный', 'умный', 'цифровой'];
                const nouns = ['платеж', 'перевод', 'взнос', 'депозит', 'баланс', 'счет', 'кошелек'];
                const animals = ['тигр', 'медведь', 'волк', 'орел', 'дельфин', 'ястреб', 'сокол'];
                
                const adj = adjectives[Math.floor(Math.random() * adjectives.length)];
                const noun = nouns[Math.floor(Math.random() * nouns.length)];
                const animal = animals[Math.floor(Math.random() * animals.length)];
                
                const controlWord = adj.charAt(0).toUpperCase() + adj.slice(1) + 
                                    noun.charAt(0).toUpperCase() + noun.slice(1) + 
                                    animal.charAt(0).toUpperCase() + animal.slice(1) + 
                                    Math.floor(Math.random() * 900) + 100;
                
                if (methodName === 'card') {
                    document.getElementById('card_control_word').value = controlWord;
                } else {
                    document.getElementById('invoice_control_word').value = controlWord;
                }
            }
        });
    });

    // Если был создан счет, автоматически показываем его
    <?php if ($success && $payment_method === 'invoice'): ?>
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelector('.payment-method[data-method="invoice"]').click();
        });
    <?php endif; ?>

    // По умолчанию выбираем СБП
    document.querySelector('.payment-method[data-method="sbp"]').click();

    window.addEventListener('resize', checkScreenSize);
    checkScreenSize();
    </script>
</body>
</html>