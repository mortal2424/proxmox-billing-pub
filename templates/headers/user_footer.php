<!-- Современный футер -->
<footer class="modern-footer">
    <div class="container">
        <div class="footer-content">
            <!-- Верхняя часть футера -->
            <div class="footer-top">
                <div class="footer-brand">
                    <a href="/" class="footer-logo">
                        <div class="footer-logo-icon">
                            <i class="fas fa-cloud"></i>
                        </div>
                        <div class="footer-logo-text">
                            <span class="footer-logo-title">HomeVlad</span>
                            <span class="footer-logo-subtitle">Cloud Platform</span>
                        </div>
                    </a>
                    <p class="footer-description">
                        Профессиональная облачная платформа для хостинга, виртуализации
                        и управления IT-инфраструктурой. Безопасность, надежность и
                        производительность.
                    </p>
                    <div class="social-links">
                        <a href="#" class="social-link" aria-label="Telegram">
                            <i class="fab fa-telegram"></i>
                        </a>
                        <a href="#" class="social-link" aria-label="VK">
                            <i class="fab fa-vk"></i>
                        </a>
                        <a href="#" class="social-link" aria-label="GitHub">
                            <i class="fab fa-github"></i>
                        </a>
                        <a href="#" class="social-link" aria-label="YouTube">
                            <i class="fab fa-youtube"></i>
                        </a>
                        <a href="#" class="social-link" aria-label="Discord">
                            <i class="fab fa-discord"></i>
                        </a>
                    </div>
                </div>

                <div class="footer-linkss">
                    <div class="footer-column">
                        <h4 class="footer-column-title">Продукты</h4>
                        <ul class="footer-menu">
                            <li><a href="/services/vps">Виртуальные серверы</a></li>
                            <li><a href="/services/dedicated">Выделенные серверы</a></li>
                            <li><a href="/services/cloud">Облачный хостинг</a></li>
                            <li><a href="/services/storage">Облачное хранилище</a></li>
                            <li><a href="/services/backup">Резервное копирование</a></li>
                        </ul>
                    </div>

                    <div class="footer-column">
                        <h4 class="footer-column-title">Поддержка</h4>
                        <ul class="footer-menu">
                            <li><a href="/support/help">Центр помощи</a></li>
                            <li><a href="/support/docs">Документация</a></li>
                            <li><a href="/support/status">Статус системы</a></li>
                            <li><a href="/support/tutorials">Обучающие материалы</a></li>
                            <li><a href="/support/contact">Связаться с нами</a></li>
                        </ul>
                    </div>

                    <div class="footer-column">
                        <h4 class="footer-column-title">Компания</h4>
                        <ul class="footer-menu">
                            <li><a href="/company/about">О нас</a></li>
                            <li><a href="/company/blog">Блог</a></li>
                            <li><a href="/company/news">Новости</a></li>
                            <li><a href="/company/careers">Карьера</a></li>
                            <li><a href="/company/partners">Партнерам</a></li>
                        </ul>
                    </div>

                    <div class="footer-column">
                        <h4 class="footer-column-title">Правовая информация</h4>
                        <ul class="footer-menu">
                            <li><a href="/legal/terms">Условия использования</a></li>
                            <li><a href="/legal/privacy">Политика конфиденциальности</a></li>
                            <li><a href="/legal/cookies">Политика cookies</a></li>
                            <li><a href="/legal/sla">Соглашение об уровне услуг</a></li>
                            <li><a href="/legal/refund">Политика возврата</a></li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Разделитель -->
            <div class="footer-divider"></div>

            <!-- Нижняя часть футера -->
            <div class="footer-bottom">
                <div class="copyright">
                    <p>&copy; <?= date('Y') ?> HomeVlad Cloud. Все права защищены.</p>
                    <p class="additional-info">
                        <span>ИНН: 1234567890</span>
                        <span>ОГРН: 1234567890123</span>
                        <span>Адрес: Москва, ул. Примерная, д. 1</span>
                    </p>
                </div>

                <div class="footer-badges">
                    <div class="badge-item" title="SSL безопасность">
                        <i class="fas fa-lock"></i> SSL
                    </div>
                    <div class="badge-item" title="Защита данных">
                        <i class="fas fa-shield-alt"></i> GDPR
                    </div>
                    <div class="badge-item" title="Доступность 99.9%">
                        <i class="fas fa-uptime"></i> 99.9% Uptime
                    </div>
                    <div class="badge-item" title="Поддержка 24/7">
                        <i class="fas fa-headset"></i> 24/7 Support
                    </div>
                </div>

                <div class="payment-methods">
                    <div class="payment-title">Принимаем к оплате:</div>
                    <div class="payment-icons">
                        <i class="fab fa-cc-visa" title="Visa"></i>
                        <i class="fab fa-cc-mastercard" title="MasterCard"></i>
                        <i class="fab fa-cc-amex" title="American Express"></i>
                        <i class="fab fa-cc-paypal" title="PayPal"></i>
                        <i class="fas fa-credit-card" title="Банковские карты"></i>
                        <i class="fab fa-bitcoin" title="Криптовалюты"></i>
                        <i class="fas fa-qrcode" title="QR-коды"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Кнопка "Наверх" -->
    <button class="scroll-to-top" id="scrollToTop" aria-label="Наверх">
        <i class="fas fa-chevron-up"></i>
    </button>
</footer>

<style>
/* ========== ОСНОВНЫЕ СТИЛИ ФУТЕРА ========== */
.modern-footer {
    background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
    color: #cbd5e1;
    padding: 60px 0 30px;
    margin-top: auto;
    position: relative;
    overflow: hidden;
}

.modern-footer::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 1px;
    background: linear-gradient(90deg, transparent, rgba(59, 130, 246, 0.5), transparent);
}

/* ========== ВЕРХНЯЯ ЧАСТЬ ФУТЕРА ========== */
.footer-top {
    display: grid;
    grid-template-columns: 1fr 2fr;
    gap: 60px;
    margin-bottom: 50px;
}

@media (max-width: 992px) {
    .footer-top {
        grid-template-columns: 1fr;
        gap: 40px;
    }
}

/* ========== БРЕНД И ОПИСАНИЕ ========== */
.footer-brand {
    max-width: 400px;
}

.footer-logo {
    display: flex;
    align-items: center;
    gap: 15px;
    text-decoration: none;
    margin-bottom: 25px;
}

.footer-logo-icon {
    width: 50px;
    height: 50px;
    background: linear-gradient(135deg, #00bcd4, #0097a7);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    color: white;
    box-shadow: 0 4px 12px rgba(0, 188, 212, 0.3);
}

.footer-logo-text {
    display: flex;
    flex-direction: column;
}

.footer-logo-title {
    font-size: 24px;
    font-weight: 700;
    color: white;
    line-height: 1.2;
}

.footer-logo-subtitle {
    font-size: 14px;
    color: #94a3b8;
    font-weight: 400;
    letter-spacing: 0.5px;
}

.footer-description {
    font-size: 15px;
    line-height: 1.6;
    color: #94a3b8;
    margin-bottom: 25px;
}

/* ========== СОЦИАЛЬНЫЕ ССЫЛКИ ========== */
.social-links {
    display: flex;
    gap: 15px;
}

.social-link {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.1);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #cbd5e1;
    font-size: 18px;
    transition: all 0.3s ease;
    text-decoration: none;
}

.social-link:hover {
    background: #3b82f6;
    color: white;
    transform: translateY(-3px);
    box-shadow: 0 6px 15px rgba(59, 130, 246, 0.3);
    border-color: transparent;
}

/* ========== ССЫЛКИ ФУТЕРА ========== */
.footer-linkss {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 40px;
}

@media (max-width: 1200px) {
    .footer-linkss {
        grid-template-columns: repeat(2, 1fr);
        gap: 30px;
    }
}

@media (max-width: 576px) {
    .footer-linkss {
        grid-template-columns: 1fr;
        gap: 30px;
    }
}

.footer-column-title {
    font-size: 16px;
    font-weight: 600;
    color: white;
    margin-bottom: 20px;
    position: relative;
    padding-bottom: 10px;
}

.footer-column-title::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    width: 40px;
    height: 2px;
    background: linear-gradient(90deg, #3b82f6, transparent);
    border-radius: 2px;
}

.footer-menu {
    list-style: none;
    padding: 0;
    margin: 0;
}

.footer-menu li {
    margin-bottom: 12px;
}

.footer-menu li:last-child {
    margin-bottom: 0;
}

.footer-menu a {
    color: #94a3b8;
    text-decoration: none;
    font-size: 14px;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.footer-menu a:hover {
    color: #3b82f6;
    transform: translateX(5px);
}

.footer-menu a::before {
    content: '→';
    opacity: 0;
    transform: translateX(-10px);
    transition: all 0.3s ease;
}

.footer-menu a:hover::before {
    opacity: 1;
    transform: translateX(0);
}

/* ========== РАЗДЕЛИТЕЛЬ ========== */
.footer-divider {
    height: 1px;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.1), transparent);
    margin: 40px 0;
}

/* ========== НИЖНЯЯ ЧАСТЬ ФУТЕРА ========== */
.footer-bottom {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 30px;
}

@media (max-width: 768px) {
    .footer-bottom {
        flex-direction: column;
        text-align: center;
        gap: 25px;
    }
}

.copyright {
    flex: 1;
}

.copyright p {
    margin: 0;
    font-size: 14px;
    color: #94a3b8;
}

.additional-info {
    margin-top: 10px !important;
    font-size: 12px !important;
    opacity: 0.7;
}

.additional-info span {
    display: inline-block;
    margin-right: 20px;
}

.additional-info span:last-child {
    margin-right: 0;
}

/* ========== БЕЙДЖИ ========== */
.footer-badges {
    display: flex;
    gap: 15px;
    flex-wrap: wrap;
    justify-content: center;
}

.badge-item {
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 8px;
    padding: 8px 15px;
    font-size: 12px;
    color: #cbd5e1;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
}

.badge-item:hover {
    background: rgba(59, 130, 246, 0.1);
    border-color: rgba(59, 130, 246, 0.3);
    transform: translateY(-2px);
}

.badge-item i {
    color: #3b82f6;
    font-size: 14px;
}

/* ========== СПОСОБЫ ОПЛАТЫ ========== */
.payment-methods {
    display: flex;
    align-items: center;
    gap: 15px;
}

.payment-title {
    font-size: 12px;
    color: #94a3b8;
    white-space: nowrap;
}

.payment-icons {
    display: flex;
    gap: 12px;
    font-size: 24px;
}

.payment-icons i {
    color: #94a3b8;
    transition: all 0.3s ease;
    cursor: pointer;
}

.payment-icons i:hover {
    color: #3b82f6;
    transform: translateY(-2px);
}

/* ========== КНОПКА "НАВЕРХ" ========== */
.scroll-to-top {
    position: fixed;
    bottom: 30px;
    right: 30px;
    width: 50px;
    height: 50px;
    background: linear-gradient(135deg, #3b82f6, #1d4ed8);
    border: none;
    border-radius: 12px;
    color: white;
    font-size: 18px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    visibility: hidden;
    transform: translateY(20px);
    transition: all 0.3s ease;
    z-index: 999;
    box-shadow: 0 6px 20px rgba(59, 130, 246, 0.3);
}

.scroll-to-top.visible {
    opacity: 1;
    visibility: visible;
    transform: translateY(0);
}

.scroll-to-top:hover {
    background: linear-gradient(135deg, #1d4ed8, #1e40af);
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(59, 130, 246, 0.4);
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

.modern-footer {
    animation: fadeInUp 0.6s ease-out;
}

/* ========== ТЕМНАЯ ТЕМА (если нужно переопределить) ========== */
body.dark-theme .modern-footer {
    background: linear-gradient(135deg, #0a0a0a 0%, #1a1a1a 100%);
}

body.dark-theme .social-link {
    background: rgba(255, 255, 255, 0.03);
    border-color: rgba(255, 255, 255, 0.05);
}

body.dark-theme .badge-item {
    background: rgba(255, 255, 255, 0.03);
    border-color: rgba(255, 255, 255, 0.05);
}

/* ========== АДАПТИВНОСТЬ ========== */
@media (max-width: 768px) {
    .modern-footer {
        padding: 40px 0 25px;
    }

    .footer-top {
        margin-bottom: 30px;
    }

    .social-links {
        gap: 12px;
    }

    .social-link {
        width: 40px;
        height: 40px;
        font-size: 16px;
    }

    .payment-methods {
        flex-direction: column;
        gap: 10px;
    }

    .additional-info span {
        display: block;
        margin-right: 0;
        margin-bottom: 5px;
    }

    .additional-info span:last-child {
        margin-bottom: 0;
    }
}

@media (max-width: 480px) {
    .modern-footer {
        padding: 30px 0 20px;
    }

    .footer-logo {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }

    .footer-logo-icon {
        width: 45px;
        height: 45px;
        font-size: 20px;
    }

    .scroll-to-top {
        bottom: 20px;
        right: 20px;
        width: 45px;
        height: 45px;
        font-size: 16px;
    }
}

/* ========== ЭФФЕКТЫ ПРИ НАВЕДЕНИИ ========== */
.footer-menu a,
.social-link,
.badge-item,
.payment-icons i {
    position: relative;
    overflow: hidden;
}

.footer-menu a::after,
.social-link::after,
.badge-item::after {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    width: 0;
    height: 0;
    border-radius: 50%;
    background: rgba(59, 130, 246, 0.1);
    transform: translate(-50%, -50%);
    transition: width 0.6s, height 0.6s;
}

.footer-menu a:hover::after,
.social-link:hover::after,
.badge-item:hover::after {
    width: 300px;
    height: 300px;
}

/* ========== ПАРАЛЛАКС ЭФФЕКТ ДЛЯ ФОНА ========== */
.modern-footer {
    background-attachment: fixed;
}
</style>

<script>
// Скрипт для футера
document.addEventListener('DOMContentLoaded', function() {
    // Кнопка "Наверх"
    const scrollToTopBtn = document.getElementById('scrollToTop');

    if (scrollToTopBtn) {
        // Показать/скрыть кнопку при скролле
        window.addEventListener('scroll', function() {
            if (window.scrollY > 300) {
                scrollToTopBtn.classList.add('visible');
            } else {
                scrollToTopBtn.classList.remove('visible');
            }
        });

        // Плавная прокрутка наверх
        scrollToTopBtn.addEventListener('click', function() {
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        });
    }

    // Анимация появления элементов при скролле
    const observerOptions = {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px'
    };

    const observer = new IntersectionObserver(function(entries) {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
            }
        });
    }, observerOptions);

    // Анимируем элементы футера
    const animatedElements = document.querySelectorAll('.footer-column, .footer-brand, .footer-badges, .payment-methods');
    animatedElements.forEach(el => {
        el.style.opacity = '0';
        el.style.transform = 'translateY(20px)';
        el.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
        observer.observe(el);
    });

    // Тултипы для бейджей
    const badges = document.querySelectorAll('.badge-item');
    badges.forEach(badge => {
        const title = badge.getAttribute('title');
        if (title) {
            badge.addEventListener('mouseenter', function(e) {
                // Создаем тултип
                const tooltip = document.createElement('div');
                tooltip.className = 'badge-tooltip';
                tooltip.textContent = title;
                tooltip.style.cssText = `
                    position: absolute;
                    background: #1e293b;
                    color: white;
                    padding: 6px 12px;
                    border-radius: 6px;
                    font-size: 12px;
                    white-space: nowrap;
                    z-index: 1000;
                    transform: translateY(-100%) translateX(-50%);
                    left: 50%;
                    top: -10px;
                    border: 1px solid #334155;
                    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
                `;

                document.body.appendChild(tooltip);

                // Позиционируем тултип
                const rect = badge.getBoundingClientRect();
                tooltip.style.left = (rect.left + rect.width / 2) + 'px';
                tooltip.style.top = (rect.top - 10) + 'px';

                badge._tooltip = tooltip;
            });

            badge.addEventListener('mouseleave', function() {
                if (badge._tooltip) {
                    badge._tooltip.remove();
                    badge._tooltip = null;
                }
            });
        }
    });

    // Анимация социальных иконок
    const socialLinks = document.querySelectorAll('.social-link');
    socialLinks.forEach(link => {
        link.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-3px) scale(1.1)';
        });

        link.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0) scale(1)';
        });
    });

    // Анимация платежных иконок
    const paymentIcons = document.querySelectorAll('.payment-icons i');
    paymentIcons.forEach(icon => {
        icon.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-3px) scale(1.2)';
        });

        icon.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0) scale(1)';
        });
    });

    // Параллакс эффект для фона футера
    window.addEventListener('scroll', function() {
        const footer = document.querySelector('.modern-footer');
        if (footer) {
            const scrolled = window.pageYOffset;
            const rate = scrolled * -0.5;
            footer.style.backgroundPosition = `center ${rate}px`;
        }
    });
});
</script>
