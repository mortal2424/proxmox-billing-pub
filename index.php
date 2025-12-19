<?php
session_start();
require_once __DIR__ . '/includes/db.php';

$db = new Database();
$pdo = $db->getConnection();

// Получаем активные готовые тарифы (is_custom = 0)
$tariffs = [];
try {
    $stmt = $pdo->query("SELECT * FROM tariffs WHERE is_active = 1 AND is_custom = 0 AND vm_type = 'qemu' ORDER BY price ASC");
    $tariffs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
}

// Получаем возможности
$features = [];
try {
    $stmt = $pdo->query("SELECT * FROM features WHERE is_active = 1 ORDER BY id ASC LIMIT 6");
    $features = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
}

// Получаем активные акции
$promotions = [];
try {
    $currentDate = date('Y-m-d');
    $stmt = $pdo->prepare("SELECT * FROM promotions WHERE is_active = 1 AND start_date <= ? AND end_date >= ? ORDER BY start_date DESC");
    $stmt->execute([$currentDate, $currentDate]);
    $promotions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
}

// Получаем отзывы
/*$reviews = [];
try {
    $stmt = $pdo->query("SELECT r.*, u.username, u.avatar FROM reviews r
                         LEFT JOIN users u ON r.user_id = u.id
                         WHERE r.is_active = 1 AND r.is_approved = 1
                         ORDER BY r.created_at DESC LIMIT 6");
    $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
}*/

// Получаем FAQ
/*$faqs = [];
try {
    $stmt = $pdo->query("SELECT * FROM faqs WHERE is_active = 1 ORDER BY display_order ASC LIMIT 10");
    $faqs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
}*/

// Получаем статистику
$stats = [
    'servers' => 1000,
    'users' => 500,
    'uptime' => 98.9,
    'response_time' => 15

];

/*try {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM vps_servers WHERE status = 'active'");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['servers'] = $result['count'] ?? 0;

    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['users'] = $result['count'] ?? 0;
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
}*/
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HomeVlad | Cloud VPS на Proxmox</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="img/cloud.png" type="image/png">
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            --secondary-gradient: linear-gradient(135deg, #00bcd4, #0097a7);
            --accent-gradient: linear-gradient(135deg, #8b5cf6, #7c3aed);
            --success-gradient: linear-gradient(135deg, #10b981, #059669);
            --warning-gradient: linear-gradient(135deg, #f59e0b, #d97706);
            --danger-gradient: linear-gradient(135deg, #ef4444, #dc2626);
            --light-bg: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            --dark-bg: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            --card-bg: rgba(255, 255, 255, 0.95);
            --card-shadow: 0 20px 40px rgba(0, 0, 0, 0.08);
            --text-primary: #1e293b;
            --text-secondary: #64748b;
            --text-light: #94a3b8;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--light-bg);
            color: var(--text-primary);
            line-height: 1.6;
            overflow-x: hidden;
        }

        .container {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }

        /* ========== ХЕДЕР ========== */
        .modern-header {
            background: var(--primary-gradient);
            padding: 18px 0;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            transition: transform 0.3s ease;
        }

        .logo:hover {
            transform: translateY(-2px);
        }

        .logo-image {
            height: 45px;
            width: auto;
            filter: drop-shadow(0 4px 12px rgba(0, 188, 212, 0.3));
        }

        .logo-text {
            font-size: 22px;
            font-weight: 800;
            background: linear-gradient(135deg, #00bcd4, #0097a7);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            letter-spacing: -0.5px;
        }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .nav-btn {
            padding: 10px 20px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .nav-btn-secondary {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.15);
            color: rgba(255, 255, 255, 0.9);
        }

        .nav-btn-secondary:hover {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.2);
        }

        .nav-btn-primary {
            background: linear-gradient(135deg, #00bcd4, #0097a7);
            border: 1px solid rgba(0, 188, 212, 0.3);
            color: white;
            box-shadow: 0 4px 12px rgba(0, 188, 212, 0.3);
        }

        .nav-btn-primary:hover {
            background: linear-gradient(135deg, #0097a7, #00838f);
            transform: translateY(-2px) scale(1.05);
            box-shadow: 0 8px 25px rgba(0, 188, 212, 0.4);
        }

        /* ========== ГЕРОЙ СЕКЦИЯ ========== */
        .hero-section {
            padding: 180px 0 100px;
            background: var(--primary-gradient);
            position: relative;
            overflow: hidden;
        }

        .hero-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background:
                radial-gradient(circle at 20% 50%, rgba(0, 188, 212, 0.15) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(139, 92, 246, 0.1) 0%, transparent 50%);
        }

        .hero-content {
            text-align: center;
            position: relative;
            z-index: 2;
            max-width: 800px;
            margin: 0 auto;
        }

        .hero-title {
            font-size: 56px;
            font-weight: 800;
            margin-bottom: 20px;
            background: linear-gradient(135deg, #ffffff, #e2e8f0);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            line-height: 1.2;
        }

        .hero-subtitle {
            font-size: 20px;
            color: rgba(255, 255, 255, 0.8);
            margin-bottom: 40px;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }

        .hero-actions {
            display: flex;
            gap: 20px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .btn-main {
            padding: 16px 36px;
            background: linear-gradient(135deg, #00bcd4, #0097a7);
            border: none;
            border-radius: 12px;
            color: white;
            font-size: 16px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 8px 25px rgba(0, 188, 212, 0.3);
        }

        .btn-main:hover {
            transform: translateY(-5px) scale(1.05);
            box-shadow: 0 15px 35px rgba(0, 188, 212, 0.4);
            background: linear-gradient(135deg, #0097a7, #00838f);
        }

        .btn-secondary {
            padding: 16px 36px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 12px;
            color: white;
            font-size: 16px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            backdrop-filter: blur(10px);
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }

        /* ========== СЕКЦИИ ========== */
        .section {
            padding: 100px 0;
        }

        .section-title {
            text-align: center;
            font-size: 42px;
            font-weight: 800;
            margin-bottom: 60px;
            background: linear-gradient(135deg, #0f172a, #1e293b);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            position: relative;
        }

        .section-title::after {
            content: '';
            position: absolute;
            bottom: -15px;
            left: 50%;
            transform: translateX(-50%);
            width: 80px;
            height: 4px;
            background: linear-gradient(135deg, #00bcd4, #0097a7);
            border-radius: 2px;
        }

        /* ========== ТАРИФЫ ========== */
        .tariffs-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 30px;
            margin-top: 40px;
        }

        .tariff-card {
            background: var(--card-bg);
            border-radius: 20px;
            padding: 40px 30px;
            box-shadow: var(--card-shadow);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(148, 163, 184, 0.1);
        }

        .tariff-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, #00bcd4, #0097a7);
            border-radius: 20px 20px 0 0;
        }

        .tariff-card.popular {
            transform: translateY(-20px);
            box-shadow: 0 30px 60px rgba(0, 0, 0, 0.15);
            border-color: rgba(0, 188, 212, 0.3);
        }

        .tariff-card.popular::before {
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
            height: 6px;
        }

        .tariff-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.12);
        }

        .popular-badge {
            position: absolute;
            top: 20px;
            right: 20px;
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
            color: white;
            padding: 6px 15px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3);
        }

        .tariff-name {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 15px;
        }

        .tariff-price {
            font-size: 42px;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 30px;
            line-height: 1;
        }

        .tariff-price span {
            font-size: 16px;
            font-weight: 500;
            color: var(--text-light);
        }

        .tariff-features {
            list-style: none;
            margin-bottom: 40px;
        }

        .tariff-features li {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px solid rgba(148, 163, 184, 0.1);
            color: var(--text-secondary);
            font-size: 15px;
        }

        .tariff-features li:last-child {
            border-bottom: none;
        }

        .tariff-features li i {
            color: #00bcd4;
            font-size: 16px;
            width: 20px;
            text-align: center;
        }

        .btn-tariff {
            display: block;
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #00bcd4, #0097a7);
            border: none;
            border-radius: 12px;
            color: white;
            font-size: 16px;
            font-weight: 600;
            text-decoration: none;
            text-align: center;
            transition: all 0.3s ease;
            box-shadow: 0 6px 20px rgba(0, 188, 212, 0.25);
        }

        .btn-tariff:hover {
            background: linear-gradient(135deg, #0097a7, #00838f);
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0, 188, 212, 0.35);
        }

        /* ========== ВОЗМОЖНОСТИ ========== */
        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 30px;
            margin-top: 40px;
        }

        .feature-card {
            background: var(--card-bg);
            border-radius: 20px;
            padding: 40px 30px;
            box-shadow: var(--card-shadow);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid rgba(148, 163, 184, 0.1);
            text-align: center;
        }

        .feature-card:hover {
            transform: translateY(-10px) scale(1.02);
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.12);
            border-color: rgba(0, 188, 212, 0.2);
        }

        .feature-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #00bcd4, #0097a7);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 25px;
            font-size: 36px;
            color: white;
            box-shadow: 0 10px 25px rgba(0, 188, 212, 0.3);
        }

        .feature-title {
            font-size: 22px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 15px;
        }

        .feature-description {
            color: var(--text-secondary);
            font-size: 15px;
            line-height: 1.7;
        }

        /* ========== АКЦИИ ========== */
        .promotions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 30px;
            margin-top: 40px;
        }

        .promo-card {
            background: var(--card-bg);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: var(--card-shadow);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid rgba(148, 163, 184, 0.1);
        }

        .promo-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.12);
        }

        .promo-image {
            height: 200px;
            background: linear-gradient(135deg, #0f172a, #1e293b);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 48px;
            position: relative;
            overflow: hidden;
        }

        .promo-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .promo-image i {
            position: relative;
            z-index: 2;
        }

        .promo-content {
            padding: 30px;
        }

        .promo-title {
            font-size: 22px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 15px;
        }

        .promo-description {
            color: var(--text-secondary);
            margin-bottom: 20px;
            line-height: 1.7;
        }

        .promo-date {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text-light);
            font-size: 14px;
        }

        .promo-date i {
            color: #ef4444;
        }

        /* ========== ОТЗЫВЫ ========== */
        .reviews-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
            margin-top: 40px;
        }

        .review-card {
            background: var(--card-bg);
            border-radius: 20px;
            padding: 30px;
            box-shadow: var(--card-shadow);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid rgba(148, 163, 184, 0.1);
        }

        .review-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.12);
        }

        .review-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
        }

        .review-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, #00bcd4, #0097a7);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 20px;
        }

        .review-avatar img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
        }

        .review-user {
            flex: 1;
        }

        .review-name {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 5px;
        }

        .review-date {
            font-size: 14px;
            color: var(--text-light);
        }

        .review-rating {
            color: #f59e0b;
            font-size: 14px;
        }

        .review-text {
            color: var(--text-secondary);
            line-height: 1.7;
            font-style: italic;
        }

        /* ========== FAQ ========== */
        .faq-container {
            max-width: 800px;
            margin: 40px auto 0;
        }

        .faq-item {
            background: var(--card-bg);
            border-radius: 15px;
            margin-bottom: 15px;
            overflow: hidden;
            border: 1px solid rgba(148, 163, 184, 0.1);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
        }

        .faq-question {
            padding: 20px 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .faq-question:hover {
            background: rgba(0, 188, 212, 0.05);
        }

        .faq-question h3 {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-primary);
            margin: 0;
        }

        .faq-icon {
            color: #00bcd4;
            transition: transform 0.3s ease;
        }

        .faq-item.active .faq-icon {
            transform: rotate(180deg);
        }

        .faq-answer {
            padding: 0 25px;
            max-height: 0;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .faq-item.active .faq-answer {
            padding: 0 25px 20px;
            max-height: 500px;
        }

        .faq-answer p {
            color: var(--text-secondary);
            line-height: 1.7;
            margin: 0;
        }

        /* ========== ТАЙМЕР АКЦИИ ========== */
        .promo-timer {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 20px;
        }

        .timer-item {
            background: linear-gradient(135deg, #0f172a, #1e293b);
            color: white;
            padding: 15px;
            border-radius: 12px;
            text-align: center;
            min-width: 70px;
        }

        .timer-value {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .timer-label {
            font-size: 12px;
            color: rgba(255, 255, 255, 0.7);
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* ========== КЛАССЫ ДЛЯ ПУСТЫХ СОСТОЯНИЙ ========== */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            grid-column: 1 / -1;
        }

        .empty-icon {
            font-size: 64px;
            color: #cbd5e1;
            margin-bottom: 20px;
            display: inline-block;
        }

        .empty-text {
            color: #64748b;
            font-size: 18px;
            margin-bottom: 30px;
        }

        /* ========== ИНФО БЛОК ========== */
        .info-section {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            padding: 80px 0;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 40px;
            margin-top: 40px;
        }

        .info-card {
            text-align: center;
            padding: 30px;
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
        }

        .info-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.1);
        }

        .info-number {
            font-size: 48px;
            font-weight: 800;
            background: linear-gradient(135deg, #00bcd4, #0097a7);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            margin-bottom: 10px;
        }

        .info-label {
            font-size: 16px;
            color: #64748b;
            font-weight: 500;
        }

        /* ========== КНОПКА ВВЕРХ ========== */
        .scroll-to-top {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #00bcd4, #0097a7);
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

        /* ========== ПРЕЛОАДЕР ========== */
        .loader {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: var(--primary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            transition: opacity 0.5s ease, visibility 0.5s ease;
        }

        .loader.hidden {
            opacity: 0;
            visibility: hidden;
        }

        .loader-content {
            text-align: center;
        }

        .loader-spinner {
            width: 60px;
            height: 60px;
            border: 4px solid rgba(255, 255, 255, 0.1);
            border-top-color: #00bcd4;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* ========== АДАПТИВНОСТЬ ========== */
        @media (max-width: 992px) {
            .hero-title {
                font-size: 42px;
            }

            .hero-subtitle {
                font-size: 18px;
            }

            .section-title {
                font-size: 36px;
            }

            .tariffs-grid,
            .features-grid,
            .promotions-grid,
            .reviews-grid {
                grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
                gap: 25px;
            }
        }

        @media (max-width: 768px) {
            .modern-header {
                padding: 15px 0;
            }

            .header-content {
                flex-direction: column;
                gap: 15px;
            }

            .nav-links {
                flex-wrap: wrap;
                justify-content: center;
            }

            .hero-section {
                padding: 150px 0 80px;
            }

            .hero-title {
                font-size: 36px;
            }

            .hero-subtitle {
                font-size: 16px;
            }

            .hero-actions {
                flex-direction: column;
                align-items: center;
            }

            .section {
                padding: 70px 0;
            }

            .section-title {
                font-size: 32px;
                margin-bottom: 40px;
            }

            .promo-timer {
                flex-wrap: wrap;
            }
        }

        @media (max-width: 576px) {
            .container {
                padding: 0 15px;
            }

            .hero-title {
                font-size: 32px;
            }

            .section-title {
                font-size: 28px;
            }

            .tariff-card,
            .feature-card,
            .promo-card,
            .review-card {
                padding: 30px 20px;
            }

            .tariff-price {
                font-size: 36px;
            }

            .info-number {
                font-size: 36px;
            }

            .scroll-to-top {
                bottom: 20px;
                right: 20px;
                width: 45px;
                height: 45px;
            }
        }

        /* ========== АНИМАЦИИ ========== */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes float {
            0%, 100% {
                transform: translateY(0);
            }
            50% {
                transform: translateY(-10px);
            }
        }

        .fade-in {
            animation: fadeInUp 0.8s ease forwards;
        }

        .float-animation {
            animation: float 3s ease-in-out infinite;
        }
    </style>
</head>
<body>
    <!-- Прелоадер -->
    <div class="loader" id="pageLoader">
        <div class="loader-content">
            <div class="loader-spinner"></div>
            <div style="color: white; font-weight: 600;">Загрузка HomeVlad Cloud...</div>
        </div>
    </div>

    <!-- Кнопка вверх -->
    <a href="#" class="scroll-to-top" id="scrollToTop">
        <i class="fas fa-chevron-up"></i>
    </a>

    <!-- Модернизированный хедер -->
    <header class="modern-header">
        <div class="container">
            <div class="header-content">
                <a href="/" class="logo">
                    <!--<img src="img/cloud-icon1.png" alt="HomeVlad Cloud" class="logo-image">-->
                    <span class="logo-text">HomeVlad Cloud</span>
                </a>

                <div class="nav-links">
                    <a href="#tariffs" class="nav-btn nav-btn-secondary">
                        <i class="fas fa-server"></i> Тарифы
                    </a>
                    <a href="#features" class="nav-btn nav-btn-secondary">
                        <i class="fas fa-bolt"></i> Возможности
                    </a>
                    <a href="#promo" class="nav-btn nav-btn-secondary">
                        <i class="fas fa-gift"></i> Акции
                    </a>
                    <?php if (isset($_SESSION['user'])): ?>
                        <a href="/templates/dashboard.php" class="nav-btn nav-btn-primary">
                            <i class="fas fa-user-circle"></i> Личный кабинет
                        </a>
                    <?php else: ?>
                        <a href="/login/register.php" class="nav-btn nav-btn-primary">
                            <i class="fas fa-rocket"></i> Начать работу
                        </a>
                        <a href="/login/login.php" class="nav-btn nav-btn-secondary">
                            <i class="fas fa-sign-in-alt"></i> Войти
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </header>

    <!-- Герой секция -->
    <section class="hero-section">
        <div class="container">
            <div class="hero-content fade-in">
                <h1 class="hero-title">Мощные VPS на Proxmox</h1>
                <p class="hero-subtitle">
                    Надежные виртуальные серверы с мгновенным развертыванием, автоматической оплатой<br>
                    и круглосуточной технической поддержкой
                </p>
                <div class="hero-actions">
                    <a href="#tariffs" class="btn-main float-animation">
                        <i class="fas fa-play-circle"></i> Выбрать тариф
                    </a>
                    <a href="/login/register.php" class="btn-secondary">
                        <i class="fas fa-rocket"></i> Начать бесплатно
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- Инфо блок -->
    <section class="info-section">
        <div class="container">
            <div class="info-grid">
                <div class="info-card fade-in" style="animation-delay: 0.1s">
                    <div class="info-number"><?= $stats['uptime'] ?>%</div>
                    <div class="info-label">Доступность</div>
                </div>
                <div class="info-card fade-in" style="animation-delay: 0.2s">
                    <div class="info-number"><?= $stats['users'] ?></div>
                    <div class="info-label">Количество пользователей</div>
                </div>
                <div class="info-card fade-in" style="animation-delay: 0.3s">
                    <div class="info-number"><?= $stats['response_time'] ?></div>
                    <div class="info-label">Время развертывание</div>
                </div>
                <div class="info-card fade-in" style="animation-delay: 0.4s">
                    <div class="info-number"><?= $stats['servers'] ?>+</div>
                    <div class="info-label">Серверов создано</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Тарифы -->
    <section id="tariffs" class="section">
        <div class="container">
            <h2 class="section-title fade-in">Тарифные планы</h2>
            <div class="tariffs-grid">
                <?php if (empty($tariffs)): ?>
                    <div class="empty-state fade-in">
                        <div class="empty-icon">
                            <i class="fas fa-cloud"></i>
                        </div>
                        <p class="empty-text">В данный момент нет доступных тарифов</p>
                        <a href="/login/register.php" class="btn-main">
                            <i class="fas fa-envelope"></i> Связаться для настройки
                        </a>
                    </div>
                <?php else: ?>
                    <?php foreach ($tariffs as $index => $tariff): ?>
                        <div class="tariff-card <?= $tariff['is_popular'] ? 'popular' : '' ?> fade-in" style="animation-delay: <?= ($index + 1) * 0.1 ?>s">
                            <?php if ($tariff['is_popular']): ?>
                                <div class="popular-badge">Популярный</div>
                            <?php endif; ?>
                            <h3 class="tariff-name"><?= htmlspecialchars($tariff['name']) ?></h3>
                            <div class="tariff-price">~<?= number_format($tariff['price'], 0, '', ' ') ?> ₽<span>/месяц</span></div>
                            <ul class="tariff-features">
                                <li><i class="fas fa-microchip"></i> <?= $tariff['cpu'] ?> vCPU</li>
                                <li><i class="fas fa-memory"></i> <?= ($tariff['ram'] / 1024) ?> GB RAM</li>
                                <li><i class="fas fa-hdd"></i> <?= $tariff['disk'] ?> GB SSD</li>
                                <?php if (!empty($tariff['traffic'])): ?>
                                    <li><i class="fas fa-network-wired"></i> <?= $tariff['traffic'] ?> Трафика</li>
                                <?php endif; ?>
                                <?php if (!empty($tariff['backups'])): ?>
                                    <li><i class="fas fa-cloud"></i> <?= $tariff['backups'] ?></li>
                                <?php endif; ?>
                                <?php if (!empty($tariff['support'])): ?>
                                    <li><i class="fas fa-headset"></i> <?= $tariff['support'] ?></li>
                                <?php endif; ?>
                            </ul>
                            <a href="/login/register.php?tariff_id=<?= $tariff['id'] ?>" class="btn-tariff">
                                <i class="fas fa-shopping-cart"></i> Заказать сейчас
                            </a>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Возможности -->
    <section id="features" class="section" style="background: #f8fafc;">
        <div class="container">
            <h2 class="section-title fade-in">Наши возможности</h2>
            <div class="features-grid">
                <?php if (empty($features)): ?>
                    <div class="empty-state fade-in">
                        <div class="empty-icon">
                            <i class="fas fa-cogs"></i>
                        </div>
                        <p class="empty-text">Возможности временно недоступны</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($features as $index => $feature): ?>
                        <div class="feature-card fade-in" style="animation-delay: <?= ($index + 1) * 0.1 ?>s">
                            <div class="feature-icon">
                                <i class="<?= htmlspecialchars($feature['icon']) ?>"></i>
                            </div>
                            <h3 class="feature-title"><?= htmlspecialchars($feature['title']) ?></h3>
                            <p class="feature-description"><?= htmlspecialchars($feature['description']) ?></p>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Акции -->
    <?php if (!empty($promotions)): ?>
    <section id="promo" class="section">
        <div class="container">
            <h2 class="section-title fade-in">Акции и спецпредложения</h2>
            <div class="promotions-grid">
                <?php foreach ($promotions as $index => $promo): ?>
                    <div class="promo-card fade-in" style="animation-delay: <?= ($index + 1) * 0.1 ?>s">
                        <div class="promo-image">
                            <?php if (!empty($promo['image_url'])): ?>
                                <img src="<?= htmlspecialchars($promo['image_url']) ?>" alt="<?= htmlspecialchars($promo['title']) ?>" loading="lazy">
                            <?php else: ?>
                                <i class="fas fa-gift"></i>
                            <?php endif; ?>
                        </div>
                        <div class="promo-content">
                            <h3 class="promo-title"><?= htmlspecialchars($promo['title']) ?></h3>
                            <p class="promo-description"><?= htmlspecialchars($promo['description']) ?></p>
                            <?php if (!empty($promo['end_date'])): ?>
                                <div class="promo-timer" data-end="<?= $promo['end_date'] ?>">
                                    <div class="timer-item">
                                        <div class="timer-value" data-days>00</div>
                                        <div class="timer-label">дней</div>
                                    </div>
                                    <div class="timer-item">
                                        <div class="timer-value" data-hours>00</div>
                                        <div class="timer-label">часов</div>
                                    </div>
                                    <div class="timer-item">
                                        <div class="timer-value" data-minutes>00</div>
                                        <div class="timer-label">минут</div>
                                    </div>
                                    <div class="timer-item">
                                        <div class="timer-value" data-seconds>00</div>
                                        <div class="timer-label">секунд</div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="promo-date">
                                    <i class="far fa-clock"></i>
                                    <span>Действует до: <?= date('d.m.Y', strtotime($promo['end_date'])) ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- Отзывы -->
    <?php if (!empty($reviews)): ?>
    <section class="section" style="background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);">
        <div class="container">
            <h2 class="section-title fade-in">Отзывы наших клиентов</h2>
            <div class="reviews-grid">
                <?php foreach ($reviews as $index => $review): ?>
                    <div class="review-card fade-in" style="animation-delay: <?= ($index + 1) * 0.1 ?>s">
                        <div class="review-header">
                            <div class="review-avatar">
                                <?php if (!empty($review['avatar'])): ?>
                                    <img src="<?= htmlspecialchars($review['avatar']) ?>" alt="<?= htmlspecialchars($review['username']) ?>">
                                <?php else: ?>
                                    <?= strtoupper(substr($review['username'], 0, 1)) ?>
                                <?php endif; ?>
                            </div>
                            <div class="review-user">
                                <div class="review-name"><?= htmlspecialchars($review['username']) ?></div>
                                <div class="review-date"><?= date('d.m.Y', strtotime($review['created_at'])) ?></div>
                            </div>
                            <div class="review-rating">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <i class="fas fa-star<?= $i <= $review['rating'] ? '' : '-o' ?>"></i>
                                <?php endfor; ?>
                            </div>
                        </div>
                        <div class="review-text">
                            <?= htmlspecialchars($review['comment']) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- FAQ -->
    <?php if (!empty($faqs)): ?>
    <section class="section">
        <div class="container">
            <h2 class="section-title fade-in">Часто задаваемые вопросы</h2>
            <div class="faq-container">
                <?php foreach ($faqs as $index => $faq): ?>
                    <div class="faq-item fade-in" style="animation-delay: <?= ($index + 1) * 0.1 ?>s">
                        <div class="faq-question">
                            <h3><?= htmlspecialchars($faq['question']) ?></h3>
                            <i class="fas fa-chevron-down faq-icon"></i>
                        </div>
                        <div class="faq-answer">
                            <p><?= nl2br(htmlspecialchars($faq['answer'])) ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- CTA секция -->
    <section class="section" style="padding: 60px 0;">
        <div class="container">
            <div class="feature-card" style="text-align: center; max-width: 800px; margin: 0 auto; background: linear-gradient(135deg, #00bcd4, #0097a7); color: white;">
                <div class="feature-icon" style="background: rgba(255, 255, 255, 0.2); margin-bottom: 20px;">
                    <i class="fas fa-rocket" style="color: white;"></i>
                </div>
                <h3 class="feature-title" style="color: white; font-size: 32px; margin-bottom: 20px;">Готовы начать?</h3>
                <p class="feature-description" style="color: rgba(255, 255, 255, 0.9); font-size: 18px; margin-bottom: 30px;">
                    Создайте свой первый виртуальный сервер за 5 минут. Бесплатный тестовый период на 7 дней для новых пользователей.
                </p>
                <div style="display: flex; gap: 20px; justify-content: center; flex-wrap: wrap;">
                    <a href="/login/register.php" class="btn-main" style="background: white; color: #00bcd4;">
                        <i class="fas fa-play-circle"></i> Начать бесплатно
                    </a>
                    <a href="#tariffs" class="btn-secondary" style="background: rgba(255, 255, 255, 0.2);">
                        <i class="fas fa-server"></i> Смотреть тарифы
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- Подключение общего футера -->
    <?php
    $footer_file = __DIR__ . '/templates/headers/user_footer.php';
    if (file_exists($footer_file)) {
        include $footer_file;
    } else {
        // Fallback футер если файл не найден
        echo '<footer class="modern-footer">
            <div class="container">
                <div class="footer-content">
                    <div class="footer-column">
                        <a href="/" class="footer-logo">
                            <img src="img/logo.png" alt="HomeVlad Cloud" style="height: 40px;">
                            <span class="footer-logo-text">HomeVlad Cloud</span>
                        </a>
                        <p class="footer-description">
                            Предоставляем надежные и производительные VPS серверы на базе Proxmox VE.
                        </p>
                    </div>
                </div>
                <div class="footer-bottom">
                    <div class="copyright">
                        © 2024 HomeVlad Cloud. Все права защищены.
                    </div>
                </div>
            </div>
        </footer>';
    }
    ?>

    <script>
        // Управление прелоадером
        window.addEventListener('load', function() {
            setTimeout(function() {
                document.getElementById('pageLoader').classList.add('hidden');
            }, 500);
        });

        // Плавная прокрутка
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                if (this.getAttribute('href') === '#') return;

                e.preventDefault();
                const targetId = this.getAttribute('href');
                if (targetId.startsWith('#')) {
                    const targetElement = document.querySelector(targetId);
                    if (targetElement) {
                        window.scrollTo({
                            top: targetElement.offsetTop - 100,
                            behavior: 'smooth'
                        });
                    }
                } else {
                    window.location.href = this.getAttribute('href');
                }
            });
        });

        // Анимация при скролле
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, observerOptions);

        // Добавляем анимации для карточек
        document.querySelectorAll('.fade-in').forEach(el => {
            el.style.opacity = '0';
            el.style.transform = 'translateY(30px)';
            el.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
            observer.observe(el);
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

        // FAQ аккордеон
        document.querySelectorAll('.faq-question').forEach(question => {
            question.addEventListener('click', () => {
                const item = question.parentElement;
                item.classList.toggle('active');
            });
        });

        // Таймеры акций
        document.querySelectorAll('.promo-timer').forEach(timer => {
            const endDate = new Date(timer.dataset.end).getTime();

            function updateTimer() {
                const now = new Date().getTime();
                const distance = endDate - now;

                if (distance < 0) {
                    timer.innerHTML = '<div style="color: #ef4444; font-weight: 600;">Акция завершена</div>';
                    return;
                }

                const days = Math.floor(distance / (1000 * 60 * 60 * 24));
                const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
                const seconds = Math.floor((distance % (1000 * 60)) / 1000);

                timer.querySelector('[data-days]').textContent = days.toString().padStart(2, '0');
                timer.querySelector('[data-hours]').textContent = hours.toString().padStart(2, '0');
                timer.querySelector('[data-minutes]').textContent = minutes.toString().padStart(2, '0');
                timer.querySelector('[data-seconds]').textContent = seconds.toString().padStart(2, '0');
            }

            updateTimer();
            setInterval(updateTimer, 1000);
        });

        // Обработка состояния пользователя
        document.addEventListener('DOMContentLoaded', function() {
            // Проверяем, есть ли уведомления в сессии
            <?php if (isset($_SESSION['message'])): ?>
                showNotification("<?= addslashes($_SESSION['message']) ?>", "<?= $_SESSION['message_type'] ?? 'info' ?>");
                <?php unset($_SESSION['message'], $_SESSION['message_type']); ?>
            <?php endif; ?>

            // Анимация чисел в статистике
            animateNumbers();
        });

        // Анимация чисел
        function animateNumbers() {
            const counters = document.querySelectorAll('.info-number');
            const speed = 200;

            counters.forEach(counter => {
                const animate = () => {
                    const value = +counter.innerText.replace('%', '').replace('+', '');
                    const data = +counter.getAttribute('data-target') || value;
                    const increment = data / speed;

                    if (value < data) {
                        counter.innerText = Math.ceil(value + increment);
                        setTimeout(animate, 1);
                    } else {
                        counter.innerText = data + (counter.innerText.includes('%') ? '%' :
                                                counter.innerText.includes('+') ? '+' : '');
                    }
                };

                animate();
            });
        }

        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
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
            `;

            const bgColors = {
                success: 'linear-gradient(135deg, #10b981, #059669)',
                error: 'linear-gradient(135deg, #ef4444, #dc2626)',
                warning: 'linear-gradient(135deg, #f59e0b, #d97706)',
                info: 'linear-gradient(135deg, #00bcd4, #0097a7)'
            };

            notification.style.background = bgColors[type] || bgColors.info;

            const icon = {
                success: 'fa-check-circle',
                error: 'fa-exclamation-circle',
                warning: 'fa-exclamation-triangle',
                info: 'fa-info-circle'
            }[type];

            notification.innerHTML = `
                <i class="fas ${icon}"></i>
                <span>${message}</span>
                <button onclick="this.parentElement.remove()" style="margin-left: auto; background: none; border: none; color: white; cursor: pointer;">
                    <i class="fas fa-times"></i>
                </button>
            `;

            document.body.appendChild(notification);

            setTimeout(() => {
                if (notification.parentElement) {
                    notification.style.animation = 'slideOut 0.3s ease forwards';
                    setTimeout(() => notification.remove(), 300);
                }
            }, 5000);
        }

        // Добавляем стили для анимаций
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

            .pulse {
                animation: pulse 2s infinite;
            }

            @keyframes pulse {
                0% {
                    box-shadow: 0 0 0 0 rgba(0, 188, 212, 0.7);
                }
                70% {
                    box-shadow: 0 0 0 10px rgba(0, 188, 212, 0);
                }
                100% {
                    box-shadow: 0 0 0 0 rgba(0, 188, 212, 0);
                }
            }

            @keyframes countUp {
                from {
                    transform: translateY(20px);
                    opacity: 0;
                }
                to {
                    transform: translateY(0);
                    opacity: 1;
                }
            }
        `;
        document.head.appendChild(style);

        // Lazy loading изображений
        if ('IntersectionObserver' in window) {
            const lazyImages = document.querySelectorAll('img[loading="lazy"]');

            const imageObserver = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const img = entry.target;
                        img.src = img.dataset.src;
                        img.classList.add('loaded');
                        imageObserver.unobserve(img);
                    }
                });
            });

            lazyImages.forEach(img => imageObserver.observe(img));
        }
    </script>
</body>
</html>
