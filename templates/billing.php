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
    case 'all':
        // Без фильтра по времени
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
    case 'all':
        // Без фильтра по времени
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

// Получаем статистику по платежам
$stmt = $pdo->prepare("
    SELECT
        SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as total_paid,
        SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END) as total_pending,
        COUNT(*) as total_payments
    FROM payments
    WHERE user_id = ?
");
$stmt->execute([$user_id]);
$payments_stats = $stmt->fetch();

// Получаем среднемесячные расходы
$stmt = $pdo->prepare("
    SELECT
        AVG(daily_cost) as avg_monthly_cost
    FROM (
        SELECT
            DATE(created_at) as day,
            SUM(amount) as daily_cost
        FROM transactions
        WHERE user_id = ?
            AND type = 'debit'
            AND created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
        GROUP BY DATE(created_at)
    ) daily_costs
");
$stmt->execute([$user_id]);
$avg_monthly_cost = $stmt->fetchColumn() * 30;

// Получаем данные для графиков за 30 дней
$stmt = $pdo->prepare("
    SELECT
        DATE(created_at) as date,
        SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END) as debits,
        SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END) as credits
    FROM transactions
    WHERE user_id = ?
        AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY DATE(created_at)
    ORDER BY date ASC
");
$stmt->execute([$user_id]);
$daily_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Получаем данные для графиков по часам (последние 24 часа)
$stmt = $pdo->prepare("
    SELECT
        DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') as hour,
        SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END) as debits
    FROM transactions
    WHERE user_id = ?
        AND type = 'debit'
        AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00')
    ORDER BY hour ASC
");
$stmt->execute([$user_id]);
$hourly_debits = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Получаем детализацию списаний по ресурсам за последние 7 дней
$stmt = $pdo->prepare("
    SELECT
        DATE(created_at) as date,
        metadata
    FROM transactions
    WHERE user_id = ?
        AND type = 'debit'
        AND metadata IS NOT NULL
        AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ORDER BY created_at ASC
");
$stmt->execute([$user_id]);
$resource_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Анализируем метаданные для получения данных по ресурсам
$resource_data = [
    'cpu' => ['dates' => [], 'values' => []],
    'ram' => ['dates' => [], 'values' => []],
    'disk' => ['dates' => [], 'values' => []]
];

foreach ($resource_transactions as $transaction) {
    $date = date('d.m', strtotime($transaction['date']));
    $metadata = json_decode($transaction['metadata'], true);

    if ($metadata) {
        if (isset($metadata['cpu'])) {
            if (!in_array($date, $resource_data['cpu']['dates'])) {
                $resource_data['cpu']['dates'][] = $date;
                $resource_data['cpu']['values'][] = 0;
            }
            $lastIndex = count($resource_data['cpu']['values']) - 1;
            $resource_data['cpu']['values'][$lastIndex] += ($metadata['cpu'] * ($metadata['cpu_price'] ?? 0));
        }

        if (isset($metadata['memory'])) {
            if (!in_array($date, $resource_data['ram']['dates'])) {
                $resource_data['ram']['dates'][] = $date;
                $resource_data['ram']['values'][] = 0;
            }
            $lastIndex = count($resource_data['ram']['values']) - 1;
            $resource_data['ram']['values'][$lastIndex] += ($metadata['memory'] * ($metadata['memory_price'] ?? 0));
        }

        if (isset($metadata['disk'])) {
            if (!in_array($date, $resource_data['disk']['dates'])) {
                $resource_data['disk']['dates'][] = $date;
                $resource_data['disk']['values'][] = 0;
            }
            $lastIndex = count($resource_data['disk']['values']) - 1;
            $resource_data['disk']['values'][$lastIndex] += ($metadata['disk'] * ($metadata['disk_price'] ?? 0));
        }
    }
}

// Получаем данные для графика платежей
$stmt = $pdo->prepare("
    SELECT
        DATE(created_at) as date,
        SUM(amount) as payments
    FROM payments
    WHERE user_id = ?
        AND status = 'completed'
        AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY DATE(created_at)
    ORDER BY date ASC
");
$stmt->execute([$user_id]);
$daily_payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Подготавливаем данные для JavaScript
$chart_labels = [];
$chart_debits = [];
$chart_credits = [];
$chart_payments = [];

// Данные за 30 дней
foreach ($daily_transactions as $day) {
    $chart_labels[] = date('d.m', strtotime($day['date']));
    $chart_debits[] = (float)$day['debits'];
    $chart_credits[] = (float)$day['credits'];
}

// Данные по часам
$hourly_labels = [];
$hourly_values = [];
foreach ($hourly_debits as $hour) {
    $hourly_labels[] = date('H:00', strtotime($hour['hour']));
    $hourly_values[] = (float)$hour['debits'];
}

$payments_map = [];
foreach ($daily_payments as $payment) {
    $payments_map[date('d.m', strtotime($payment['date']))] = (float)$payment['payments'];
}

// Синхронизируем платежи с датами транзакций
foreach ($chart_labels as $label) {
    $chart_payments[] = $payments_map[$label] ?? 0;
}

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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="icon" href="../img/cloud.png" type="image/png">
    <link rel="stylesheet" href="/css/themes.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            --secondary-gradient: linear-gradient(135deg, #00bcd4, #0097a7);
            --success-gradient: linear-gradient(135deg, #10b981, #059669);
            --warning-gradient: linear-gradient(135deg, #f59e0b, #d97706);
            --danger-gradient: linear-gradient(135deg, #ef4444, #dc2626);
            --info-gradient: linear-gradient(135deg, #3b82f6, #2563eb);
            --purple-gradient: linear-gradient(135deg, #8b5cf6, #7c3aed);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            color: #1e293b;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        body.dark-theme {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: #cbd5e1;
        }

        /* Основной контейнер */
        .main-container {
            display: flex;
            flex: 1;
            min-height: calc(100vh - 70px);
            margin-top: 70px;
        }

        /* Основной контент */
        .main-content {
            flex: 1;
            padding: 24px;
            margin-left: 280px;
            transition: margin-left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .sidebar-collapsed .main-content {
            margin-left: 80px;
        }

        @media (max-width: 992px) {
            .main-content {
                margin-left: 0;
                padding: 20px;
            }
        }

        /* Заголовок страницы */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid rgba(148, 163, 184, 0.1);
        }

        .page-title {
            font-size: 28px;
            font-weight: 700;
            background: linear-gradient(135deg, #00bcd4, #0097a7);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .page-title i {
            font-size: 32px;
        }

        /* Статистика */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(148, 163, 184, 0.1);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        body.dark-theme .stat-card {
            background: rgba(30, 41, 59, 0.7);
            border-color: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.12);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--secondary-gradient);
            border-radius: 16px 16px 0 0;
        }

        .stat-card.balance::before {
            background: var(--success-gradient);
        }

        .stat-card.bonus::before {
            background: var(--purple-gradient);
        }

        .stat-card.payments::before {
            background: var(--info-gradient);
        }

        .stat-card.spending::before {
            background: var(--warning-gradient);
        }

        .stat-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }

        .stat-icon {
            width: 56px;
            height: 56px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
            background: var(--secondary-gradient);
            box-shadow: 0 4px 12px rgba(0, 188, 212, 0.3);
        }

        .stat-icon.balance {
            background: var(--success-gradient);
        }

        .stat-icon.bonus {
            background: var(--purple-gradient);
        }

        .stat-icon.payments {
            background: var(--info-gradient);
        }

        .stat-icon.spending {
            background: var(--warning-gradient);
        }

        .stat-value {
            font-size: 32px;
            font-weight: 700;
            margin: 8px 0;
            color: #1e293b;
        }

        body.dark-theme .stat-value {
            color: #f1f5f9;
        }

        .stat-label {
            font-size: 14px;
            color: #64748b;
            margin-bottom: 4px;
            font-weight: 500;
        }

        body.dark-theme .stat-label {
            color: #94a3b8;
        }

        .stat-details {
            font-size: 12px;
            color: #94a3b8;
            margin-top: 4px;
        }

        /* Форма пополнения */
        .payment-section {
            background: white;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(148, 163, 184, 0.1);
        }

        body.dark-theme .payment-section {
            background: rgba(30, 41, 59, 0.7);
            border-color: rgba(255, 255, 255, 0.1);
        }

        .section-title {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 20px;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        body.dark-theme .section-title {
            color: #f1f5f9;
        }

        /* Методы оплаты */
        .payment-methods-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin: 20px 0;
        }

        .payment-method-card {
            background: rgba(248, 250, 252, 0.5);
            border: 2px solid rgba(148, 163, 184, 0.1);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        body.dark-theme .payment-method-card {
            background: rgba(30, 41, 59, 0.5);
        }

        .payment-method-card:hover {
            transform: translateY(-4px);
            border-color: #00bcd4;
            box-shadow: 0 8px 24px rgba(0, 188, 212, 0.15);
        }

        .payment-method-card.active {
            border-color: #00bcd4;
            background: rgba(0, 188, 212, 0.05);
        }

        .payment-method-icon {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: var(--secondary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 12px;
            color: white;
            font-size: 20px;
        }

        .payment-method-card.bonus .payment-method-icon {
            background: var(--purple-gradient);
        }

        .payment-method-card.invoice .payment-method-icon {
            background: var(--warning-gradient);
        }

        .payment-method-title {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 8px;
            color: #1e293b;
        }

        body.dark-theme .payment-method-title {
            color: #f1f5f9;
        }

        .payment-method-description {
            font-size: 12px;
            color: #64748b;
        }

        body.dark-theme .payment-method-description {
            color: #94a3b8;
        }

        /* Детали оплаты */
        .payment-details-container {
            background: rgba(248, 250, 252, 0.5);
            border-radius: 12px;
            padding: 20px;
            margin-top: 20px;
            border: 1px solid rgba(148, 163, 184, 0.1);
            display: none;
        }

        body.dark-theme .payment-details-container {
            background: rgba(30, 41, 59, 0.5);
        }

        .payment-details-container.active {
            display: block;
            animation: slideIn 0.3s ease forwards;
        }

        .qr-container {
            text-align: center;
            margin: 20px 0;
        }

        .qr-code {
            display: inline-block;
            background: white;
            padding: 16px;
            border-radius: 12px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
        }

        .bank-details {
            background: rgba(248, 250, 252, 0.8);
            border-radius: 12px;
            padding: 16px;
            margin: 16px 0;
            border: 1px solid rgba(148, 163, 184, 0.2);
        }

        body.dark-theme .bank-details {
            background: rgba(30, 41, 59, 0.8);
        }

        /* История */
        .history-tabs {
            display: flex;
            gap: 4px;
            margin-bottom: 20px;
            background: white;
            padding: 4px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        body.dark-theme .history-tabs {
            background: rgba(30, 41, 59, 0.7);
        }

        .history-tab {
            flex: 1;
            padding: 12px 16px;
            text-align: center;
            cursor: pointer;
            border-radius: 8px;
            font-weight: 500;
            color: #64748b;
            transition: all 0.3s ease;
        }

        .history-tab:hover {
            background: rgba(0, 188, 212, 0.1);
            color: #00bcd4;
        }

        .history-tab.active {
            background: var(--secondary-gradient);
            color: white;
            box-shadow: 0 2px 8px rgba(0, 188, 212, 0.3);
        }

        .history-content {
            display: none;
        }

        .history-content.active {
            display: block;
            animation: slideIn 0.3s ease forwards;
        }

        .history-filters {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: nowrap; /* Заменяем wrap на nowrap */
            gap: 20px; /* Увеличиваем расстояние между элементами */
            justify-content: flex-start; /* Выравниваем по левому краю */
        }

       .filter-form {
           display: flex;
           align-items: center;
           gap: 20px;
           flex-wrap: nowrap;
        }

       .filter-group {
           display: flex;
           align-items: center;
           gap: 8px;
           white-space: nowrap; /* Запрещаем перенос текста */
        }

       .filter-group label {
           font-size: 14px;
           color: #64748b;
           font-weight: 500;
           white-space: nowrap; /* Запрещаем перенос текста в label */
        }

       .filter-group select {
           padding: 8px 12px;
           border: 1px solid rgba(148, 163, 184, 0.3);
           border-radius: 8px;
           background: white;
           color: #1e293b;
           font-size: 14px;
           cursor: pointer;
           transition: all 0.3s ease;
           min-width: 120px; /* Минимальная ширина для select */
        }

        body.dark-theme .filter-group select {
            background: rgba(30, 41, 59, 0.7);
            border-color: rgba(255, 255, 255, 0.2);
            color: #cbd5e1;
        }

        .filter-group select:hover {
            border-color: #00bcd4;
        }

        /* Платежи и списания */
        .payment-item, .debit-item {
            background: white;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 12px;
            border: 1px solid rgba(148, 163, 184, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.3s ease;
        }

        body.dark-theme .payment-item,
        body.dark-theme .debit-item {
            background: rgba(30, 41, 59, 0.7);
        }

        .payment-item:hover, .debit-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
            border-color: rgba(0, 188, 212, 0.3);
        }

        .payment-description, .debit-description {
            font-weight: 500;
            color: #1e293b;
            margin-bottom: 4px;
        }

        body.dark-theme .payment-description,
        body.dark-theme .debit-description {
            color: #f1f5f9;
        }

        .payment-date, .debit-date {
            font-size: 12px;
            color: #64748b;
        }

        .payment-amount {
            font-size: 18px;
            font-weight: 700;
            color: #10b981;
            text-align: right;
        }

        .debit-amount {
            font-size: 18px;
            font-weight: 700;
            color: #ef4444;
            text-align: right;
        }

        .payment-status, .debit-balance-type {
            font-size: 12px;
            padding: 4px 8px;
            border-radius: 20px;
            text-align: center;
            margin-top: 4px;
        }

        .status-completed {
            background: rgba(16, 185, 129, 0.1);
            color: #10b981;
        }

        .status-pending {
            background: rgba(245, 158, 11, 0.1);
            color: #f59e0b;
        }

        .status-failed {
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
        }

        .debit-balance-type.main {
            background: rgba(0, 188, 212, 0.1);
            color: #00bcd4;
        }

        .debit-balance-type.bonus {
            background: rgba(139, 92, 246, 0.1);
            color: #8b5cf6;
        }

        /* Пагинация */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 16px;
            margin-top: 24px;
            padding-top: 16px;
            border-top: 1px solid rgba(148, 163, 184, 0.1);
        }

        .page-link {
            padding: 8px 16px;
            border-radius: 8px;
            background: rgba(0, 188, 212, 0.1);
            color: #00bcd4;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .page-link:hover {
            background: rgba(0, 188, 212, 0.2);
            transform: translateY(-2px);
        }

        .page-info {
            color: #64748b;
            font-size: 14px;
        }

        /* Форма */
        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #1e293b;
        }

        body.dark-theme .form-label {
            color: #cbd5e1;
        }

        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid rgba(148, 163, 184, 0.3);
            border-radius: 8px;
            background: white;
            color: #1e293b;
            font-size: 16px;
            transition: all 0.3s ease;
        }

        body.dark-theme .form-control {
            background: rgba(30, 41, 59, 0.7);
            border-color: rgba(255, 255, 255, 0.2);
            color: #cbd5e1;
        }

        .form-control:focus {
            outline: none;
            border-color: #00bcd4;
            box-shadow: 0 0 0 3px rgba(0, 188, 212, 0.1);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            font-size: 16px;
        }

        .btn-primary {
            background: var(--secondary-gradient);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(0, 188, 212, 0.3);
        }

        .btn-success {
            background: var(--success-gradient);
            color: white;
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(16, 185, 129, 0.3);
        }

        /* Уведомления */
        .alert {
            padding: 16px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideIn 0.3s ease forwards;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.2);
            color: #10b981;
        }

        .alert-danger {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: #ef4444;
        }

        .alert i {
            font-size: 20px;
        }

        /* Детали списания */
        .debit-tooltip {
            position: absolute;
            background: white;
            border-radius: 12px;
            padding: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.15);
            border: 1px solid rgba(148, 163, 184, 0.2);
            z-index: 1000;
            width: 320px;
            max-width: 400px;
            min-width: 300px;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
            bottom: 100%;
            margin-top: 8px;
        }
        .debit-item:hover .debit-tooltip {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .tooltip-header {
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1px solid rgba(148, 163, 184, 0.2);
        }

        .tooltip-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 13px;
        }

        .tooltip-icon {
            margin-right: 6px;
            color: #00bcd4;
            width: 16px;
        }

        .tooltip-value {
            font-weight: 500;
            color: #1e293b;
        }

        /* Модальное окно графиков */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 9999;
            overflow-y: auto;
        }

        .modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.3s ease;
        }

        .modal-content {
            background: white;
            border-radius: 16px;
            width: 95%;
            max-width: 1200px;
            max-height: 95vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }

        body.dark-theme .modal-content {
            background: rgba(30, 41, 59, 0.95);
            backdrop-filter: blur(10px);
        }

        .modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid rgba(148, 163, 184, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-title {
            font-size: 20px;
            font-weight: 600;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        body.dark-theme .modal-title {
            color: #f1f5f9;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 24px;
            color: #64748b;
            cursor: pointer;
            transition: color 0.3s ease;
        }

        .close-modal:hover {
            color: #ef4444;
        }

        .modal-body {
            padding: 24px;
        }

        .chart-container {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
            border: 1px solid rgba(148, 163, 184, 0.1);
        }

        body.dark-theme .chart-container {
            background: rgba(30, 41, 59, 0.5);
        }

        .chart-title {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 16px;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        body.dark-theme .chart-title {
            color: #f1f5f9;
        }

        .chart-wrapper {
            height: 300px;
            position: relative;
        }

        .chart-legend {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-top: 16px;
            flex-wrap: wrap;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }

        .legend-color {
            width: 12px;
            height: 12px;
            border-radius: 50%;
        }

        .chart-actions {
            display: flex;
            gap: 12px;
            margin-top: 16px;
        }

        /* Табы для переключения графиков */
        .graph-tabs {
            display: flex;
            gap: 4px;
            margin-bottom: 20px;
            background: white;
            padding: 4px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        body.dark-theme .graph-tabs {
            background: rgba(30, 41, 59, 0.7);
        }

        .graph-tab {
            flex: 1;
            padding: 12px 16px;
            text-align: center;
            cursor: pointer;
            border-radius: 8px;
            font-weight: 500;
            color: #64748b;
            transition: all 0.3s ease;
        }

        .graph-tab:hover {
            background: rgba(0, 188, 212, 0.1);
            color: #00bcd4;
        }

        .graph-tab.active {
            background: var(--secondary-gradient);
            color: white;
            box-shadow: 0 2px 8px rgba(0, 188, 212, 0.3);
        }

        .graph-content {
            display: none;
        }

        .graph-content.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }

        /* Адаптивность */
        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 16px;
            }

            .page-title {
                font-size: 24px;
            }

            .stat-card {
                padding: 20px;
            }

            .payment-section {
                padding: 20px;
            }

            .history-filters {
                flex-direction: column;
                align-items: stretch;
            }

            .filter-group {
                width: 100%;
                justify-content: space-between;
            }

            .payment-item, .debit-item {
                flex-direction: column;
                gap: 12px;
                align-items: flex-start;
            }

            .payment-amount, .debit-amount {
                text-align: left;
            }

            .payment-methods-grid {
                grid-template-columns: 1fr;
            }

            .modal-content {
                width: 95%;
                margin: 10px;
            }

            .chart-wrapper {
                height: 250px;
            }

            .graph-tabs {
                flex-direction: column;
            }
        }

        /* Анимации */
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

        @keyframes fadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }

        .stat-card {
            animation: slideIn 0.5s ease forwards;
        }

        .stat-card:nth-child(2) { animation-delay: 0.1s; }
        .stat-card:nth-child(3) { animation-delay: 0.2s; }
        .stat-card:nth-child(4) { animation-delay: 0.3s; }

        /* Кнопка вверх */
        .scroll-to-top {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 50px;
            height: 50px;
            background: var(--secondary-gradient);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            text-decoration: none;
            box-shadow: 0 8px 25px rgba(0, 188, 212, 0.4);
            transition: all 0.3s ease;
            opacity: 0;
            visibility: hidden;
            z-index: 999;
        }

        .scroll-to-top.visible {
            opacity: 1;
            visibility: visible;
        }

        .scroll-to-top:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 30px rgba(0, 188, 212, 0.5);
        }
    </style>
</head>
<body>
    <?php
    // Подключаем обновленную шапку
    include '../templates/headers/user_header.php';
    ?>

    <!-- Кнопка вверх -->
    <a href="#" class="scroll-to-top" id="scrollToTop">
        <i class="fas fa-chevron-up"></i>
    </a>

    <!-- Модальное окно с графиками -->
    <div class="modal" id="graphModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">
                    <i class="fas fa-chart-line"></i> Аналитика потребления платформы
                </h2>
                <button class="close-modal" id="closeGraphModal">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <!-- Табы для переключения между типами графиков -->
                <div class="graph-tabs">
                    <div class="graph-tab active" data-graph="hourly">По часам</div>
                    <div class="graph-tab" data-graph="daily">По дням</div>
                    <div class="graph-tab" data-graph="monthly">За месяц</div>
                    <div class="graph-tab" data-graph="resources">По ресурсам</div>
                </div>

                <!-- График по часам -->
                <div class="graph-content active" id="hourly-graph">
                    <div class="chart-container">
                        <h3 class="chart-title">
                            <i class="fas fa-clock"></i> Списания за последние 24 часа
                        </h3>
                        <div class="chart-wrapper">
                            <canvas id="hourlyChart"></canvas>
                        </div>
                        <div class="chart-legend">
                            <div class="legend-item">
                                <span class="legend-color" style="background-color: #ef4444;"></span>
                                <span>Списания за час</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- График по дням -->
                <div class="graph-content" id="daily-graph">
                    <div class="chart-container">
                        <h3 class="chart-title">
                            <i class="fas fa-calendar-day"></i> Списания за 30 дней
                        </h3>
                        <div class="chart-wrapper">
                            <canvas id="dailyChart"></canvas>
                        </div>
                        <div class="chart-legend">
                            <div class="legend-item">
                                <span class="legend-color" style="background-color: #ef4444;"></span>
                                <span>Списания за день</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- График за месяц -->
                <div class="graph-content" id="monthly-graph">
                    <!-- График списаний за 30 дней -->
                    <div class="chart-container">
                        <h3 class="chart-title">
                            <i class="fas fa-money-bill-wave"></i> Суточные списания
                        </h3>
                        <div class="chart-wrapper">
                            <canvas id="debitsChart"></canvas>
                        </div>
                        <div class="chart-legend">
                            <div class="legend-item">
                                <span class="legend-color" style="background-color: #ef4444;"></span>
                                <span>Списания (расходы)</span>
                            </div>
                        </div>
                    </div>

                    <!-- График платежей за 30 дней -->
                    <div class="chart-container">
                        <h3 class="chart-title">
                            <i class="fas fa-credit-card"></i> Пополнения баланса
                        </h3>
                        <div class="chart-wrapper">
                            <canvas id="paymentsChart"></canvas>
                        </div>
                        <div class="chart-legend">
                            <div class="legend-item">
                                <span class="legend-color" style="background-color: #10b981;"></span>
                                <span>Пополнения</span>
                            </div>
                        </div>
                    </div>

                    <!-- График баланса -->
                    <div class="chart-container">
                        <h3 class="chart-title">
                            <i class="fas fa-balance-scale"></i> Динамика баланса
                        </h3>
                        <div class="chart-wrapper">
                            <canvas id="balanceChart"></canvas>
                        </div>
                        <div class="chart-legend">
                            <div class="legend-item">
                                <span class="legend-color" style="background-color: #3b82f6;"></span>
                                <span>Баланс</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- График по ресурсам -->
                <div class="graph-content" id="resources-graph">
                    <div class="chart-container">
                        <h3 class="chart-title">
                            <i class="fas fa-microchip"></i> Распределение затрат по ресурсам (7 дней)
                        </h3>
                        <div class="chart-wrapper">
                            <canvas id="resourcesChart"></canvas>
                        </div>
                        <div class="chart-legend">
                            <div class="legend-item">
                                <span class="legend-color" style="background-color: #ef4444;"></span>
                                <span>CPU</span>
                            </div>
                            <div class="legend-item">
                                <span class="legend-color" style="background-color: #10b981;"></span>
                                <span>RAM</span>
                            </div>
                            <div class="legend-item">
                                <span class="legend-color" style="background-color: #3b82f6;"></span>
                                <span>Диск</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="chart-actions">
                    <button class="btn btn-primary" onclick="downloadCharts()">
                        <i class="fas fa-download"></i> Скачать графики
                    </button>
                    <button class="btn btn-secondary" onclick="printCharts()">
                        <i class="fas fa-print"></i> Печать
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="main-container">
        <?php
        // Подключаем обновленный сайдбар
        include '../templates/headers/user_sidebar.php';
        ?>

        <div class="main-content">
            <!-- Заголовок страницы -->
            <div class="page-header">
                <h1 class="page-title">
                    <i class="fas fa-credit-card"></i> Биллинг и платежи
                </h1>
                <div class="header-actions" style="display: flex; gap: 12px;">
                    <button class="btn btn-primary" id="showGraphBtn">
                        <i class="fas fa-chart-line"></i> Потребление платформы
                    </button>
                    <button class="btn btn-primary" onclick="window.location.reload()">
                        <i class="fas fa-sync-alt"></i> Обновить
                    </button>
                </div>
            </div>

            <!-- Статистика -->
            <div class="stats-grid">
                <!-- Текущий баланс -->
                <div class="stat-card balance">
                    <div class="stat-header">
                        <div class="stat-icon balance">
                            <i class="fas fa-wallet"></i>
                        </div>
                        <div class="stat-label">Текущий баланс</div>
                    </div>
                    <div class="stat-value"><?= number_format($user['balance'], 2) ?> ₽</div>
                    <div class="stat-details"><?= $user['balance'] >= 0 ? 'Доступно' : 'Задолженность' ?></div>
                </div>

                <!-- Бонусный баланс -->
                <div class="stat-card bonus">
                    <div class="stat-header">
                        <div class="stat-icon bonus">
                            <i class="fas fa-gift"></i>
                        </div>
                        <div class="stat-label">Бонусный баланс</div>
                    </div>
                    <div class="stat-value"><?= number_format($user['bonus_balance'], 2) ?> ₽</div>
                    <div class="stat-details"><?= $user['bonus_balance'] > 0 ? 'Доступно' : 'Нет бонусов' ?></div>
                </div>

                <!-- Общая сумма платежей -->
                <div class="stat-card payments">
                    <div class="stat-header">
                        <div class="stat-icon payments">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <div class="stat-label">Всего пополнено</div>
                    </div>
                    <div class="stat-value"><?= number_format($payments_stats['total_paid'] ?? 0, 2) ?> ₽</div>
                    <div class="stat-details"><?= $payments_stats['total_payments'] ?? 0 ?> платежей</div>
                </div>

                <!-- Среднемесячные расходы -->
                <div class="stat-card spending">
                    <div class="stat-header">
                        <div class="stat-icon spending">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div class="stat-label">Средний расход</div>
                    </div>
                    <div class="stat-value"><?= number_format($avg_monthly_cost, 2) ?> ₽</div>
                    <div class="stat-details">в месяц</div>
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
            <div class="payment-section">
                <h2 class="section-title">
                    <i class="fas fa-plus-circle"></i> Пополнение баланса
                </h2>

                <form method="POST" id="payment-form">
                    <div class="form-group">
                        <label class="form-label" for="amount">Сумма пополнения (₽)</label>
                        <input type="number" id="amount" name="amount" class="form-control"
                               min="50" max="50000" step="1" required
                               placeholder="Введите сумму от 50 до 50,000 рублей">
                    </div>

                    <div class="payment-methods-grid">
                        <!-- СБП -->
                        <div class="payment-method-card" data-method="sbp">
                            <div class="payment-method-icon">
                                <i class="fas fa-qrcode"></i>
                            </div>
                            <h3 class="payment-method-title">СБП</h3>
                            <p class="payment-method-description">Оплата через QR-код</p>
                        </div>

                        <!-- Перевод на карту -->
                        <div class="payment-method-card" data-method="card">
                            <div class="payment-method-icon">
                                <i class="fas fa-credit-card"></i>
                            </div>
                            <h3 class="payment-method-title">Перевод на карту</h3>
                            <p class="payment-method-description">Ручной перевод по реквизитам</p>
                        </div>

                        <!-- Выставить счет -->
                        <div class="payment-method-card invoice" data-method="invoice">
                            <div class="payment-method-icon">
                                <i class="fas fa-file-invoice"></i>
                            </div>
                            <h3 class="payment-method-title">Выставить счет</h3>
                            <p class="payment-method-description">Для юридических лиц</p>
                        </div>
                    </div>

                    <input type="hidden" name="payment_method" id="payment_method" value="sbp">

                    <!-- Детали оплаты по СБП -->
                    <div class="payment-details-container active" id="sbp-details">
                        <h3 class="section-title" style="font-size: 18px; margin-bottom: 16px;">
                            <i class="fas fa-qrcode"></i> Оплата через СБП
                        </h3>
                        <div class="qr-container">
                            <div class="qr-code">
                                <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=<?= urlencode("tel:+7 (999) 999-99-99&sum=$amount") ?>"
                                     alt="QR-код для оплаты" width="200" height="200">
                            </div>
                        </div>

                        <div class="bank-details">
                            <p><strong>Контрольное слово:</strong> <span id="sbp-control-word"><?= $control_word ?></span></p>
                            <p><strong>Телефон для оплаты:</strong> +7 (999) 999-99-99</p>
                            <p><strong>Получатель:</strong> Компания</p>
                        </div>

                        <div class="payment-instructions" style="margin-top: 20px; padding: 16px; background: rgba(0, 188, 212, 0.05); border-radius: 8px;">
                            <h4><i class="fas fa-info-circle"></i> Инструкция по оплате:</h4>
                            <ol style="margin-top: 8px; padding-left: 20px;">
                                <li>Откройте приложение вашего банка</li>
                                <li>Выберите оплату по QR-коду или СБП</li>
                                <li>Отсканируйте QR-код или введите номер телефона</li>
                                <li>Укажите контрольное слово в комментарии</li>
                                <li>Подтвердите платеж</li>
                            </ol>
                        </div>

                        <button type="submit" class="btn btn-primary" style="margin-top: 20px; width: 100%;">
                            <i class="fas fa-check"></i> Подтвердить платеж
                        </button>
                    </div>

                    <!-- Детали оплаты переводом на карту -->
                    <div class="payment-details-container" id="card-details">
                        <h3 class="section-title" style="font-size: 18px; margin-bottom: 16px;">
                            <i class="fas fa-credit-card"></i> Перевод на карту
                        </h3>

                        <div class="bank-details">
                            <h4><i class="fas fa-university"></i> Реквизиты для перевода:</h4>
                            <p><strong>Номер карты:</strong> 2200 1514 4839 6171</p>
                            <p><strong>Получатель:</strong> Вадим К.</p>
                            <p><strong>Банк:</strong> Альфа-Банк</p>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="card_control_word">Контрольное слово</label>
                            <input type="text" id="card_control_word" name="control_word" class="form-control"
                                   value="<?= $success && $payment_method === 'card' ? $control_word : '' ?>" readonly>
                            <small style="color: #64748b; font-size: 12px;">Укажите это слово в комментарии к переводу</small>
                        </div>

                        <div class="payment-instructions" style="margin-top: 20px; padding: 16px; background: rgba(0, 188, 212, 0.05); border-radius: 8px;">
                            <h4><i class="fas fa-info-circle"></i> Инструкция по оплате:</h4>
                            <p>Выполните перевод по указанным реквизитам через приложение вашего банка. Обязательно укажите контрольное слово в комментарии.</p>
                        </div>

                        <button type="submit" class="btn btn-primary" style="margin-top: 20px; width: 100%;">
                            <i class="fas fa-check"></i> Подтвердить платеж
                        </button>
                    </div>

                    <!-- Детали оплаты по счету -->
                    <div class="payment-details-container" id="invoice-details">
                        <?php if ($success && $payment_method === 'invoice'): ?>
                            <h3 class="section-title" style="font-size: 18px; margin-bottom: 16px;">
                                <i class="fas fa-file-invoice-dollar"></i> Счет № <?= $invoice_number ?>
                            </h3>

                            <div class="bank-details">
                                <p><strong>Дата:</strong> <?= date('d.m.Y') ?></p>
                                <p><strong>Сумма:</strong> <?= number_format($amount, 2) ?> ₽</p>
                                <p><strong>Статус:</strong> <span style="color: #f59e0b;">Ожидает оплаты</span></p>
                            </div>

                            <a href="billing.php?download_invoice=1&invoice_number=<?= $invoice_number ?>&amount=<?= $amount ?>"
                               class="btn btn-success" style="width: 100%; margin-bottom: 16px;">
                                <i class="fas fa-download"></i> Скачать счет в PDF
                            </a>

                            <div class="payment-instructions" style="padding: 16px; background: rgba(245, 158, 11, 0.05); border-radius: 8px;">
                                <h4><i class="fas fa-info-circle"></i> Инструкция по оплате:</h4>
                                <ol style="margin-top: 8px; padding-left: 20px;">
                                    <li>Скачайте счет в PDF</li>
                                    <li>Оплатите счет по указанным реквизитам</li>
                                    <li>Создайте тикет с темой "Биллинг и оплата"</li>
                                    <li>Приложите копию платежного поручения</li>
                                </ol>
                            </div>
                        <?php else: ?>
                            <h3 class="section-title" style="font-size: 18px; margin-bottom: 16px;">
                                <i class="fas fa-file-invoice"></i> Выставление счета
                            </h3>

                            <div class="form-group">
                                <label class="form-label" for="invoice_control_word">Контрольное слово</label>
                                <input type="text" id="invoice_control_word" name="control_word" class="form-control" readonly>
                                <small style="color: #64748b; font-size: 12px;">Это слово будет указано в счете</small>
                            </div>

                            <div class="payment-instructions" style="margin-top: 20px; padding: 16px; background: rgba(245, 158, 11, 0.05); border-radius: 8px;">
                                <h4><i class="fas fa-info-circle"></i> Важно:</h4>
                                <p>После создания счета, оплатите его по реквизитам, которые появятся на следующем экране. Для юридических лиц и ИП.</p>
                            </div>

                            <button type="submit" class="btn btn-primary" style="margin-top: 20px; width: 100%;">
                                <i class="fas fa-file-invoice"></i> Создать счет
                            </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Табы истории -->
            <div class="history-tabs">
                <div class="history-tab active" data-tab="payments">История платежей</div>
                <div class="history-tab" data-tab="debits">История списаний</div>
            </div>

            <!-- История платежей -->
            <div class="history-content active" id="payments-history">
                <div class="history-filters">
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

                <?php if (empty($payments)): ?>
                    <div style="text-align: center; padding: 40px 20px; color: #64748b;">
                        <i class="fas fa-money-bill-wave" style="font-size: 48px; margin-bottom: 16px; color: #cbd5e1;"></i>
                        <p>У вас пока нет платежей</p>
                        <button class="btn btn-primary" onclick="document.getElementById('amount').focus()" style="margin-top: 16px;">
                            <i class="fas fa-plus"></i> Пополнить баланс
                        </button>
                    </div>
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
                            <div>
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

            <!-- История списаний -->
            <div class="history-content" id="debits-history">
                <div class="history-filters">
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

                <?php if (empty($debits)): ?>
                    <div style="text-align: center; padding: 40px 20px; color: #64748b;">
                        <i class="fas fa-receipt" style="font-size: 48px; margin-bottom: 16px; color: #cbd5e1;"></i>
                        <p>У вас пока нет списаний</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($debits as $debit): ?>
                        <div class="debit-item" style="position: relative;">
                            <div>
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
                                <div class="debit-balance-type <?= $debit['balance_type'] ?>">
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
                                                <span><i class="fas fa-hdd tooltip-icon"></i> SSD:</span>
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

                                        <div class="tooltip-row" style="margin-top: 8px; border-top: 1px solid rgba(148, 163, 184, 0.2); padding-top: 6px;">
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
        </div>
    </div>

    <script>
        // Данные для графиков из PHP
        const chartData = {
            labels: <?= json_encode($chart_labels) ?>,
            debits: <?= json_encode($chart_debits) ?>,
            credits: <?= json_encode($chart_credits) ?>,
            payments: <?= json_encode($chart_payments) ?>,
            hourlyLabels: <?= json_encode($hourly_labels) ?>,
            hourlyValues: <?= json_encode($hourly_values) ?>,
            resourceData: <?= json_encode($resource_data) ?>
        };

        // Переменные для хранения экземпляров графиков
        let hourlyChart = null;
        let dailyChart = null;
        let debitsChart = null;
        let paymentsChart = null;
        let balanceChart = null;
        let resourcesChart = null;

        document.addEventListener('DOMContentLoaded', function() {
            // Анимация прогресс-баров при загрузке
            const statCards = document.querySelectorAll('.stat-card');
            statCards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
            });

            // Выбор метода оплаты
            const paymentMethods = document.querySelectorAll('.payment-method-card');
            const paymentDetails = document.querySelectorAll('.payment-details-container');
            const paymentMethodInput = document.getElementById('payment_method');

            paymentMethods.forEach(method => {
                method.addEventListener('click', function() {
                    // Убираем активный класс у всех методов
                    paymentMethods.forEach(m => m.classList.remove('active'));
                    // Добавляем активный класс текущему методу
                    this.classList.add('active');

                    // Скрываем все детали оплаты
                    paymentDetails.forEach(detail => detail.classList.remove('active'));
                    // Показываем нужные детали
                    const methodName = this.dataset.method;
                    document.getElementById(`${methodName}-details`).classList.add('active');

                    // Устанавливаем значение скрытого поля
                    paymentMethodInput.value = methodName;

                    // Генерируем контрольное слово
                    generateControlWord();
                });
            });

            // Переключение между вкладками истории
            const historyTabs = document.querySelectorAll('.history-tab');
            const historyContents = document.querySelectorAll('.history-content');

            historyTabs.forEach(tab => {
                tab.addEventListener('click', function() {
                    // Убираем активный класс у всех вкладок
                    historyTabs.forEach(t => t.classList.remove('active'));
                    // Добавляем активный класс текущей вкладке
                    this.classList.add('active');

                    // Скрываем все контенты
                    historyContents.forEach(c => c.classList.remove('active'));
                    // Показываем нужный контент
                    const tabId = this.dataset.tab;
                    document.getElementById(`${tabId}-history`).classList.add('active');
                });
            });

            // Кнопка "Наверх"
            const scrollToTopBtn = document.getElementById('scrollToTop');

            window.addEventListener('scroll', function() {
                if (window.pageYOffset > 300) {
                    scrollToTopBtn.classList.add('visible');
                } else {
                    scrollToTopBtn.classList.remove('visible');
                }
            });

            scrollToTopBtn.addEventListener('click', function(e) {
                e.preventDefault();
                window.scrollTo({
                    top: 0,
                    behavior: 'smooth'
                });
            });

            // Генерация контрольного слова
            function generateControlWord() {
                const adjectives = ['быстрый', 'надежный', 'безопасный', 'удобный', 'современный', 'умный', 'цифровой'];
                const nouns = ['платеж', 'перевод', 'взнос', 'депозит', 'баланс', 'счет', 'кошелек'];
                const animals = ['тигр', 'медведь', 'волк', 'орел', 'дельфин', 'ястреб', 'сокол'];

                const adj = adjectives[Math.floor(Math.random() * adjectives.length)];
                const noun = nouns[Math.floor(Math.random() * nouns.length)];
                const animal = animals[Math.floor(Math.random() * animals.length)];

                const controlWord = adj.charAt(0).toUpperCase() + adj.slice(1) +
                                    noun.charAt(0).toUpperCase() + noun.slice(1) +
                                    animal.charAt(0).toUpperCase() + animal.slice(1) +
                                    (Math.floor(Math.random() * 900) + 100);

                // Обновляем во всех местах
                document.getElementById('sbp-control-word').textContent = controlWord;
                document.getElementById('card_control_word').value = controlWord;
                document.getElementById('invoice_control_word').value = controlWord;
            }

            // Инициализируем контрольное слово
            generateControlWord();

            // Автоматическое обновление QR-кода при изменении суммы
            const amountInput = document.getElementById('amount');
            amountInput.addEventListener('input', function() {
                const amount = this.value || 0;
                const qrImg = document.querySelector('.qr-code img');
                if (qrImg) {
                    qrImg.src = `https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(`tel:+79644384646&sum=${amount}`)}`;
                }
            });

            // Обработка уведомлений из сессии
            <?php if (isset($_SESSION['message'])): ?>
                showNotification("<?= addslashes($_SESSION['message']) ?>", "<?= $_SESSION['message_type'] ?? 'info' ?>");
                <?php unset($_SESSION['message'], $_SESSION['message_type']); ?>
            <?php endif; ?>

            // Инициализация модального окна графиков
            const graphModal = document.getElementById('graphModal');
            const showGraphBtn = document.getElementById('showGraphBtn');
            const closeGraphModal = document.getElementById('closeGraphModal');

            // Открытие модального окна
            showGraphBtn.addEventListener('click', function() {
                graphModal.classList.add('show');
                document.body.style.overflow = 'hidden';
                renderCharts();
            });

            // Закрытие модального окна
            closeGraphModal.addEventListener('click', function() {
                graphModal.classList.remove('show');
                document.body.style.overflow = 'auto';
            });

            // Закрытие при клике вне модального окна
            graphModal.addEventListener('click', function(e) {
                if (e.target === graphModal) {
                    graphModal.classList.remove('show');
                    document.body.style.overflow = 'auto';
                }
            });

            // Закрытие по Escape
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && graphModal.classList.contains('show')) {
                    graphModal.classList.remove('show');
                    document.body.style.overflow = 'auto';
                }
            });

            // Переключение между табами графиков
            const graphTabs = document.querySelectorAll('.graph-tab');
            const graphContents = document.querySelectorAll('.graph-content');

            graphTabs.forEach(tab => {
                tab.addEventListener('click', function() {
                    // Убираем активный класс у всех табов
                    graphTabs.forEach(t => t.classList.remove('active'));
                    // Добавляем активный класс текущему табу
                    this.classList.add('active');

                    // Скрываем все контенты
                    graphContents.forEach(c => c.classList.remove('active'));
                    // Показываем нужный контент
                    const graphId = this.dataset.graph;
                    document.getElementById(`${graphId}-graph`).classList.add('active');
                });
            });
        });

        // Функция для отрисовки всех графиков
        function renderCharts() {
            // Уничтожаем старые графики, если они существуют
            if (hourlyChart) hourlyChart.destroy();
            if (dailyChart) dailyChart.destroy();
            if (debitsChart) debitsChart.destroy();
            if (paymentsChart) paymentsChart.destroy();
            if (balanceChart) balanceChart.destroy();
            if (resourcesChart) resourcesChart.destroy();

            // График по часам
            const hourlyCtx = document.getElementById('hourlyChart').getContext('2d');
            hourlyChart = new Chart(hourlyCtx, {
                type: 'line',
                data: {
                    labels: chartData.hourlyLabels,
                    datasets: [{
                        label: 'Списания за час',
                        data: chartData.hourlyValues,
                        backgroundColor: 'rgba(239, 68, 68, 0.1)',
                        borderColor: 'rgba(239, 68, 68, 1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4,
                        pointBackgroundColor: 'rgba(239, 68, 68, 1)',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 2,
                        pointRadius: 3
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return `Списания: ${context.raw.toFixed(6)} ₽`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return value.toFixed(6) + ' ₽';
                                }
                            },
                            grid: {
                                color: 'rgba(148, 163, 184, 0.1)'
                            }
                        },
                        x: {
                            grid: {
                                color: 'rgba(148, 163, 184, 0.1)'
                            }
                        }
                    }
                }
            });

            // График по дням (списания за 30 дней)
            const dailyCtx = document.getElementById('dailyChart').getContext('2d');
            dailyChart = new Chart(dailyCtx, {
                type: 'bar',
                data: {
                    labels: chartData.labels,
                    datasets: [{
                        label: 'Списания за день',
                        data: chartData.debits,
                        backgroundColor: 'rgba(239, 68, 68, 0.7)',
                        borderColor: 'rgba(239, 68, 68, 1)',
                        borderWidth: 1,
                        borderRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return `Списания: ${context.raw.toFixed(2)} ₽`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return value.toFixed(2) + ' ₽';
                                }
                            },
                            grid: {
                                color: 'rgba(148, 163, 184, 0.1)'
                            }
                        },
                        x: {
                            grid: {
                                color: 'rgba(148, 163, 184, 0.1)'
                            }
                        }
                    }
                }
            });

            // График списаний за 30 дней (для вкладки "За месяц")
            const debitsCtx = document.getElementById('debitsChart').getContext('2d');
            debitsChart = new Chart(debitsCtx, {
                type: 'bar',
                data: {
                    labels: chartData.labels,
                    datasets: [{
                        label: 'Списания (расходы)',
                        data: chartData.debits,
                        backgroundColor: 'rgba(239, 68, 68, 0.7)',
                        borderColor: 'rgba(239, 68, 68, 1)',
                        borderWidth: 1,
                        borderRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return `Списания: ${context.raw.toFixed(2)} ₽`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return value.toFixed(2) + ' ₽';
                                }
                            },
                            grid: {
                                color: 'rgba(148, 163, 184, 0.1)'
                            }
                        },
                        x: {
                            grid: {
                                color: 'rgba(148, 163, 184, 0.1)'
                            }
                        }
                    }
                }
            });

            // График платежей за 30 дней
            const paymentsCtx = document.getElementById('paymentsChart').getContext('2d');
            paymentsChart = new Chart(paymentsCtx, {
                type: 'bar',
                data: {
                    labels: chartData.labels,
                    datasets: [{
                        label: 'Пополнения',
                        data: chartData.payments,
                        backgroundColor: 'rgba(16, 185, 129, 0.7)',
                        borderColor: 'rgba(16, 185, 129, 1)',
                        borderWidth: 1,
                        borderRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return `Пополнения: ${context.raw.toFixed(2)} ₽`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return value.toFixed(2) + ' ₽';
                                }
                            },
                            grid: {
                                color: 'rgba(148, 163, 184, 0.1)'
                            }
                        },
                        x: {
                            grid: {
                                color: 'rgba(148, 163, 184, 0.1)'
                            }
                        }
                    }
                }
            });

            // График баланса (разница между платежами и списаниями)
            const balanceData = [];
            let runningBalance = 0;

            for (let i = 0; i < chartData.labels.length; i++) {
                runningBalance += (chartData.payments[i] - chartData.debits[i]);
                balanceData.push(runningBalance);
            }

            const balanceCtx = document.getElementById('balanceChart').getContext('2d');
            balanceChart = new Chart(balanceCtx, {
                type: 'line',
                data: {
                    labels: chartData.labels,
                    datasets: [{
                        label: 'Баланс',
                        data: balanceData,
                        backgroundColor: 'rgba(59, 130, 246, 0.1)',
                        borderColor: 'rgba(59, 130, 246, 1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4,
                        pointBackgroundColor: 'rgba(59, 130, 246, 1)',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 2,
                        pointRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return `Баланс: ${context.raw.toFixed(2)} ₽`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            ticks: {
                                callback: function(value) {
                                    return value.toFixed(2) + ' ₽';
                                }
                            },
                            grid: {
                                color: 'rgba(148, 163, 184, 0.1)'
                            }
                        },
                        x: {
                            grid: {
                                color: 'rgba(148, 163, 184, 0.1)'
                            }
                        }
                    }
                }
            });

            // График распределения по ресурсам
            const resourcesCtx = document.getElementById('resourcesChart').getContext('2d');
            const resourceLabels = chartData.resourceData.cpu.dates;

            resourcesChart = new Chart(resourcesCtx, {
                type: 'bar',
                data: {
                    labels: resourceLabels,
                    datasets: [
                        {
                            label: 'CPU',
                            data: chartData.resourceData.cpu.values,
                            backgroundColor: 'rgba(239, 68, 68, 0.7)',
                            borderColor: 'rgba(239, 68, 68, 1)',
                            borderWidth: 1
                        },
                        {
                            label: 'RAM',
                            data: chartData.resourceData.ram.values,
                            backgroundColor: 'rgba(16, 185, 129, 0.7)',
                            borderColor: 'rgba(16, 185, 129, 1)',
                            borderWidth: 1
                        },
                        {
                            label: 'Диск',
                            data: chartData.resourceData.disk.values,
                            backgroundColor: 'rgba(59, 130, 246, 0.7)',
                            borderColor: 'rgba(59, 130, 246, 1)',
                            borderWidth: 1
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return `${context.dataset.label}: ${context.raw.toFixed(6)} ₽`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            stacked: false,
                            ticks: {
                                callback: function(value) {
                                    return value.toFixed(6) + ' ₽';
                                }
                            },
                            grid: {
                                color: 'rgba(148, 163, 184, 0.1)'
                            }
                        },
                        x: {
                            stacked: false,
                            grid: {
                                color: 'rgba(148, 163, 184, 0.1)'
                            }
                        }
                    }
                }
            });
        }

        // Функция для скачивания графиков
        function downloadCharts() {
            // Получаем активный таб
            const activeTab = document.querySelector('.graph-tab.active').dataset.graph;
            let activeChart = null;

            // Выбираем активный график
            switch(activeTab) {
                case 'hourly':
                    activeChart = hourlyChart;
                    break;
                case 'daily':
                    activeChart = dailyChart;
                    break;
                case 'monthly':
                    // Для месячного таба создаем комбинированный график
                    createCombinedChart();
                    return;
                case 'resources':
                    activeChart = resourcesChart;
                    break;
            }

            if (activeChart) {
                const link = document.createElement('a');
                link.download = `График_${activeTab}_${new Date().toISOString().split('T')[0]}.png`;
                link.href = activeChart.toBase64Image();
                link.click();

                showNotification('График успешно скачан', 'success');
            }
        }

        // Функция для создания комбинированного графика (для скачивания)
        function createCombinedChart() {
            const canvas = document.createElement('canvas');
            canvas.width = 1200;
            canvas.height = 2400;
            const ctx = canvas.getContext('2d');

            // Фон
            ctx.fillStyle = document.body.classList.contains('dark-theme') ? '#0f172a' : '#ffffff';
            ctx.fillRect(0, 0, canvas.width, canvas.height);

            // Заголовок
            ctx.fillStyle = document.body.classList.contains('dark-theme') ? '#f1f5f9' : '#1e293b';
            ctx.font = 'bold 24px Inter';
            ctx.textAlign = 'center';
            ctx.fillText('Аналитика потребления платформы', canvas.width / 2, 50);

            ctx.font = '14px Inter';
            ctx.fillText('Пользователь: <?= htmlspecialchars($user['full_name']) ?>', canvas.width / 2, 80);
            ctx.fillText('Период: последние 30 дней', canvas.width / 2, 100);
            ctx.fillText(`Дата генерации: ${new Date().toLocaleDateString('ru-RU')}`, canvas.width / 2, 120);

            // Вставляем все три графика
            ctx.drawImage(debitsChart.canvas, 100, 150, 1000, 400);
            ctx.drawImage(paymentsChart.canvas, 100, 600, 1000, 400);
            ctx.drawImage(balanceChart.canvas, 100, 1050, 1000, 400);

            // Создаем ссылку для скачивания
            const link = document.createElement('a');
            link.download = `Аналитика_платформы_${new Date().toISOString().split('T')[0]}.png`;
            link.href = canvas.toDataURL('image/png');
            link.click();

            showNotification('Графики успешно скачаны', 'success');
        }

        // Функция для печати графиков
        function printCharts() {
            const activeTab = document.querySelector('.graph-tab.active').dataset.graph;
            let chartToPrint = null;
            let title = '';

            switch(activeTab) {
                case 'hourly':
                    chartToPrint = hourlyChart;
                    title = 'Списания за последние 24 часа';
                    break;
                case 'daily':
                    chartToPrint = dailyChart;
                    title = 'Списания за 30 дней';
                    break;
                case 'resources':
                    chartToPrint = resourcesChart;
                    title = 'Распределение затрат по ресурсам';
                    break;
                case 'monthly':
                    // Для месячного таба печатаем все три графика
                    printMonthlyCharts();
                    return;
            }

            if (chartToPrint) {
                const printWindow = window.open('', '_blank');
                printWindow.document.write(`
                    <!DOCTYPE html>
                    <html>
                    <head>
                        <title>${title}</title>
                        <style>
                            body {
                                font-family: Arial, sans-serif;
                                padding: 20px;
                            }
                            .print-header {
                                text-align: center;
                                margin-bottom: 30px;
                                border-bottom: 2px solid #333;
                                padding-bottom: 10px;
                            }
                            img {
                                max-width: 100%;
                                height: auto;
                            }
                            @media print {
                                .no-print {
                                    display: none;
                                }
                            }
                        </style>
                    </head>
                    <body>
                        <div class="print-header">
                            <h1>${title}</h1>
                            <p>Пользователь: <?= htmlspecialchars($user['full_name']) ?></p>
                            <p>Дата: ${new Date().toLocaleDateString('ru-RU')}</p>
                        </div>

                        <div>
                            <img src="${chartToPrint.toBase64Image()}">
                        </div>

                        <script>
                            window.onload = function() {
                                window.print();
                                setTimeout(function() {
                                    window.close();
                                }, 1000);
                            }
                        <\/script>
                    </body>
                    </html>
                `);
                printWindow.document.close();
            }
        }

        // Функция для печати всех месячных графиков
        function printMonthlyCharts() {
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Аналитика потребления платформы</title>
                    <style>
                        body {
                            font-family: Arial, sans-serif;
                            padding: 20px;
                        }
                        .print-header {
                            text-align: center;
                            margin-bottom: 30px;
                            border-bottom: 2px solid #333;
                            padding-bottom: 10px;
                        }
                        .chart-container {
                            margin-bottom: 40px;
                            page-break-inside: avoid;
                        }
                        .chart-title {
                            font-size: 18px;
                            font-weight: bold;
                            margin-bottom: 10px;
                            color: #333;
                        }
                        img {
                            max-width: 100%;
                            height: auto;
                        }
                        @media print {
                            .no-print {
                                display: none;
                            }
                        }
                    </style>
                </head>
                <body>
                    <div class="print-header">
                        <h1>Аналитика потребления платформы</h1>
                        <p>Пользователь: <?= htmlspecialchars($user['full_name']) ?></p>
                        <p>Период: последние 30 дней</p>
                        <p>Дата: ${new Date().toLocaleDateString('ru-RU')}</p>
                    </div>

                    <div class="chart-container">
                        <div class="chart-title">Суточные списания</div>
                        <img src="${debitsChart.toBase64Image()}">
                    </div>

                    <div class="chart-container">
                        <div class="chart-title">Пополнения баланса</div>
                        <img src="${paymentsChart.toBase64Image()}">
                    </div>

                    <div class="chart-container">
                        <div class="chart-title">Динамика баланса</div>
                        <img src="${balanceChart.toBase64Image()}">
                    </div>

                    <script>
                        window.onload = function() {
                            window.print();
                            setTimeout(function() {
                                window.close();
                            }, 1000);
                        }
                    <\/script>
                </body>
                </html>
            `);
            printWindow.document.close();
        }

        // Функция для показа уведомлений
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.innerHTML = `
                <i class="fas ${type === 'success' ? 'fa-check-circle' :
                               type === 'error' ? 'fa-exclamation-circle' :
                               type === 'warning' ? 'fa-exclamation-triangle' :
                               'fa-info-circle'}"></i>
                <span>${message}</span>
                <button onclick="this.parentElement.remove()" style="margin-left: auto; background: none; border: none; color: inherit; cursor: pointer;">
                    <i class="fas fa-times"></i>
                </button>
            `;

            // Стили для уведомления
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 15px 25px;
                border-radius: 12px;
                color: white;
                font-weight: 600;
                z-index: 9999;
                animation: slideIn 0.3s ease;
                box-shadow: 0 10px 25px rgba(0,0,0,0.2);
                display: flex;
                align-items: center;
                gap: 10px;
                max-width: 400px;
                background: ${type === 'success' ? 'linear-gradient(135deg, #10b981, #059669)' :
                           type === 'error' ? 'linear-gradient(135deg, #ef4444, #dc2626)' :
                           type === 'warning' ? 'linear-gradient(135deg, #f59e0b, #d97706)' :
                           'linear-gradient(135deg, #00bcd4, #0097a7)'};
            `;

            document.body.appendChild(notification);

            setTimeout(() => {
                if (notification.parentElement) {
                    notification.style.animation = 'slideOut 0.3s ease forwards';
                    setTimeout(() => notification.remove(), 300);
                }
            }, 5000);
        }

        // Добавляем стили для анимаций уведомлений
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideIn {
                from {
                    transform: translateX(100%);
                    opacity: 0;
                }
                to {
                    transform: translateX(0);
                    opacity: 1;
                }
            }

            @keyframes slideOut {
                from {
                    transform: translateX(0);
                    opacity: 1;
                }
                to {
                    transform: translateX(100%);
                    opacity: 0;
                }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>
