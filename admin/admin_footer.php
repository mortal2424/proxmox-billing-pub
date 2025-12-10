<?php
// Получаем информацию о системе
$system_info = [];
try {
    require_once '../includes/db.php';
    $db = new Database();
    $pdo = $db->getConnection();
    
    // Время работы системы - используем альтернативный метод
    $start_time = time(); // Время запуска скрипта как пример
    $system_info['uptime'] = formatUptime($start_time);
    
    // Загрузка системы
    $load = sys_getloadavg();
    $system_info['load'] = $load ? implode(', ', array_map(function($v) { return round($v, 2); }, $load)) : 'Недоступно';
    
    // Использование памяти PHP
    $memory_usage = memory_get_usage(true);
    $memory_peak = memory_get_peak_usage(true);
    $system_info['memory_usage'] = formatBytes($memory_usage);
    $system_info['memory_peak'] = formatBytes($memory_peak);
    
    // Активные пользователи
    $active_users = $pdo->query("SELECT COUNT(DISTINCT user_id) as count FROM user_sessions WHERE last_activity > DATE_SUB(NOW(), INTERVAL 5 MINUTE)")->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    $system_info['active_users'] = $active_users;
    
    // Общее количество пользователей
    $total_users = $pdo->query("SELECT COUNT(*) as count FROM users")->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    $system_info['total_users'] = $total_users;
    
    // Время работы сервера из логов
    $server_start = $pdo->query("SELECT DATE_FORMAT(MIN(created_at), '%d.%m.%Y') as start_date FROM system_logs")->fetch(PDO::FETCH_ASSOC)['start_date'] ?? date('d.m.Y');
    $system_info['server_start'] = $server_start;
    
    // Статистика ВМ
    $running_vms = $pdo->query("SELECT COUNT(*) as count FROM vms WHERE status = 'running'")->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    $total_vms = $pdo->query("SELECT COUNT(*) as count FROM vms WHERE status != 'deleted'")->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    $system_info['running_vms'] = $running_vms;
    $system_info['total_vms'] = $total_vms;
    
    // Статистика тикетов
    $open_tickets = $pdo->query("SELECT COUNT(*) as count FROM tickets WHERE status = 'open'")->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    $system_info['open_tickets'] = $open_tickets;
    
} catch (Exception $e) {
    error_log("Error loading system info: " . $e->getMessage());
}

// Функция для форматирования времени работы
function formatUptime($timestamp) {
    $diff = time() - $timestamp;
    $days = floor($diff / (60 * 60 * 24));
    $hours = floor(($diff % (60 * 60 * 24)) / (60 * 60));
    $minutes = floor(($diff % (60 * 60)) / 60);
    
    if ($days > 0) {
        return $days . ' дн. ' . $hours . ' ч.';
    } elseif ($hours > 0) {
        return $hours . ' ч. ' . $minutes . ' мин.';
    } else {
        return $minutes . ' мин.';
    }
}

// Функция для форматирования байтов
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    
    return round($bytes, $precision) . ' ' . $units[$pow];
}
?>

<style>
/* ========== ФУТЕР ========== */
.admin-footer {
    background: linear-gradient(180deg, #0f172a 0%, #1e293b 100%);
    border-top: 1px solid rgba(255, 255, 255, 0.05);
    padding: 20px 0;
    margin-top: 40px;
    position: relative;
    overflow: hidden;
}

.admin-footer::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 2px;
    background: linear-gradient(90deg, #00bcd4, #0097a7);
    opacity: 0.5;
}

.footer-content {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 20px;
}

/* Информационная панель */
.footer-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
    padding-bottom: 20px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
}

.stat-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 15px;
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid rgba(255, 255, 255, 0.05);
    border-radius: 10px;
    transition: all 0.3s ease;
}

.stat-item:hover {
    background: rgba(255, 255, 255, 0.05);
    border-color: rgba(0, 188, 212, 0.2);
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(0, 188, 212, 0.1);
}

.stat-icon {
    width: 40px;
    height: 40px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    flex-shrink: 0;
}

.stat-icon.uptime { background: rgba(0, 188, 212, 0.1); color: #00bcd4; }
.stat-icon.load { background: rgba(16, 185, 129, 0.1); color: #10b981; }
.stat-icon.memory { background: rgba(139, 92, 246, 0.1); color: #8b5cf6; }
.stat-icon.users { background: rgba(245, 158, 11, 0.1); color: #f59e0b; }
.stat-icon.server { background: rgba(239, 68, 68, 0.1); color: #ef4444; }
.stat-icon.vms { background: rgba(59, 130, 246, 0.1); color: #3b82f6; }

.stat-content {
    flex: 1;
    min-width: 0;
}

.stat-label {
    font-size: 12px;
    font-weight: 500;
    color: rgba(255, 255, 255, 0.7);
    margin-bottom: 4px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.stat-value {
    font-size: 14px;
    font-weight: 600;
    color: white;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Нижняя часть футера */
.footer-bottom {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 20px;
}

.footer-left {
    display: flex;
    align-items: center;
    gap: 20px;
}

.footer-logo {
    display: flex;
    align-items: center;
    gap: 10px;
    text-decoration: none;
    color: white;
}

.footer-logo-icon {
    width: 32px;
    height: 32px;
    background: linear-gradient(135deg, #00bcd4, #0097a7);
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
}

.footer-logo-text {
    font-size: 16px;
    font-weight: 700;
}

.copyright {
    font-size: 12px;
    color: rgba(255, 255, 255, 0.7);
}

.copyright a {
    color: #00bcd4;
    text-decoration: none;
    transition: color 0.3s ease;
}

.copyright a:hover {
    color: #0097a7;
    text-decoration: underline;
}

.footer-right {
    display: flex;
    align-items: center;
    gap: 20px;
}

.footer-links {
    display: flex;
    gap: 15px;
}

.footer-link {
    color: rgba(255, 255, 255, 0.7);
    text-decoration: none;
    font-size: 12px;
    transition: all 0.3s ease;
    padding: 6px 12px;
    border-radius: 6px;
}

.footer-link:hover {
    color: white;
    background: rgba(255, 255, 255, 0.05);
}

.system-status {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    background: rgba(16, 185, 129, 0.1);
    border: 1px solid rgba(16, 185, 129, 0.2);
    border-radius: 6px;
}

.status-indicator {
    width: 8px;
    height: 8px;
    background: #10b981;
    border-radius: 50%;
    animation: pulse 2s infinite;
}

.status-text {
    font-size: 12px;
    font-weight: 600;
    color: #10b981;
}

@keyframes pulse {
    0% {
        box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
    }
    70% {
        box-shadow: 0 0 0 6px rgba(16, 185, 129, 0);
    }
    100% {
        box-shadow: 0 0 0 0 rgba(16, 185, 129, 0);
    }
}

/* Быстрые действия в футере */
.footer-quick-actions {
    display: flex;
    gap: 10px;
    margin-top: 20px;
    flex-wrap: wrap;
}

.quick-action-btn {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 12px;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 6px;
    color: rgba(255, 255, 255, 0.8);
    text-decoration: none;
    font-size: 11px;
    font-weight: 500;
    transition: all 0.3s ease;
}

.quick-action-btn:hover {
    background: rgba(0, 188, 212, 0.1);
    border-color: rgba(0, 188, 212, 0.3);
    color: #00bcd4;
    transform: translateY(-1px);
}

/* Адаптивность */
@media (max-width: 768px) {
    .footer-stats {
        grid-template-columns: repeat(2, 1fr);
        gap: 10px;
    }
    
    .footer-bottom {
        flex-direction: column;
        text-align: center;
    }
    
    .footer-left, .footer-right {
        flex-direction: column;
        width: 100%;
    }
    
    .footer-links {
        justify-content: center;
        flex-wrap: wrap;
    }
    
    .footer-quick-actions {
        justify-content: center;
    }
}

@media (max-width: 480px) {
    .footer-stats {
        grid-template-columns: 1fr;
    }
    
    .stat-item {
        padding: 12px;
    }
}
</style>

<!-- Футер -->
<footer class="admin-footer">
    <div class="footer-content">
        <!-- Статистика системы -->
        <div class="footer-stats">
            <div class="stat-item">
                <div class="stat-icon users">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Пользователи</div>
                    <div class="stat-value">
                        <?= $system_info['active_users'] ?> онлайн / <?= $system_info['total_users'] ?> всего
                    </div>
                </div>
            </div>

            <div class="stat-item">
                <div class="stat-icon vms">
                    <i class="fas fa-server"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Виртуальные машины</div>
                    <div class="stat-value">
                        <?= $system_info['running_vms'] ?> запущ. / <?= $system_info['total_vms'] ?> всего
                    </div>
                </div>
            </div>

            <div class="stat-item">
                <div class="stat-icon load">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Загрузка системы</div>
                    <div class="stat-value" id="loadStat"><?= htmlspecialchars($system_info['load']) ?></div>
                </div>
            </div>

            <div class="stat-item">
                <div class="stat-icon memory">
                    <i class="fas fa-memory"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Использование памяти PHP</div>
                    <div class="stat-value"><?= $system_info['memory_usage'] ?> / <?= $system_info['memory_peak'] ?></div>
                </div>
            </div>

            <div class="stat-item">
                <div class="stat-icon server">
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-label">База данных с</div>
                    <div class="stat-value"><?= $system_info['server_start'] ?></div>
                </div>
            </div>

            <div class="stat-item">
                <div class="stat-icon uptime">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Текущая сессия</div>
                    <div class="stat-value" id="uptimeStat"><?= $system_info['uptime'] ?></div>
                </div>
            </div>
        </div>

        <!-- Быстрые действия -->
        <div class="footer-quick-actions">
            <a href="/admin/logs.php" class="quick-action-btn">
                <i class="fas fa-clipboard-list"></i>
                <span>Системные логи</span>
            </a>
            <a href="/admin/backup.php" class="quick-action-btn">
                <i class="fas fa-database"></i>
                <span>Резервное копирование</span>
            </a>
            <a href="/admin/settings.php" class="quick-action-btn">
                <i class="fas fa-cog"></i>
                <span>Настройки системы</span>
            </a>
            <a href="/admin/help.php" class="quick-action-btn">
                <i class="fas fa-question-circle"></i>
                <span>Помощь</span>
            </a>
            <?php if ($system_info['open_tickets'] > 0): ?>
            <a href="/admin/ticket.php" class="quick-action-btn" style="background: rgba(245, 158, 11, 0.1); border-color: rgba(245, 158, 11, 0.3); color: #f59e0b;">
                <i class="fas fa-ticket-alt"></i>
                <span>Тикеты (<?= $system_info['open_tickets'] ?>)</span>
            </a>
            <?php endif; ?>
        </div>

        <!-- Нижняя часть -->
        <div class="footer-bottom">
            <div class="footer-left">
                <a href="/admin/" class="footer-logo">
                    <div class="footer-logo-icon">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                    <span class="footer-logo-text">HomeVlad Cloud Admin</span>
                </a>
                
                <div class="copyright">
                    &copy; <?= date('Y') ?> <a href="/">HomeVlad Cloud</a>. 
                    <span id="currentYear"><?= date('Y') ?></span>. 
                    Админ панель v2.5.1
                    <br>
                    <small style="color: rgba(255, 255, 255, 0.5);">
                        Время генерации: <span id="pageGenTime"><?= round(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], 3) ?> сек.</span>
                    </small>
                </div>
            </div>

            <div class="footer-right">
                <div class="footer-links">
                    <a href="/admin/privacy.php" class="footer-link">Конфиденциальность</a>
                    <a href="/admin/terms.php" class="footer-link">Условия</a>
                    <a href="/admin/status.php" class="footer-link">Статус</a>
                    <a href="/admin/contact.php" class="footer-link">Контакты</a>
                </div>

                <div class="system-status">
                    <span class="status-indicator"></span>
                    <span class="status-text">Система онлайн</span>
                </div>
            </div>
        </div>
    </div>
</footer>

<!-- Основные скрипты -->
<script src="/admin/js/admin_scripts.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Обновление статистики в реальном времени
    function updateSystemStats() {
        fetch('/admin/ajax/system_stats.php')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Обновляем значения статистики
                    updateElement('loadStat', data.load || 'Недоступно');
                    
                    // Обновляем время сессии
                    const sessionTime = calculateSessionTime(data.session_start);
                    updateElement('uptimeStat', sessionTime);
                    
                    // Обновляем статус системы
                    updateSystemStatus(data.system_status);
                    
                    // Обновляем время генерации страницы если есть
                    if (data.page_gen_time) {
                        updateElement('pageGenTime', data.page_gen_time.toFixed(3) + ' сек.');
                    }
                }
            })
            .catch(error => console.error('Ошибка загрузки статистики:', error));
    }

    function calculateSessionTime(sessionStart) {
        if (!sessionStart) return 'Недоступно';
        
        const start = new Date(sessionStart).getTime();
        const now = new Date().getTime();
        const diff = now - start;
        
        const days = Math.floor(diff / (1000 * 60 * 60 * 24));
        const hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
        const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
        
        if (days > 0) {
            return days + ' дн. ' + hours + ' ч.';
        } else if (hours > 0) {
            return hours + ' ч. ' + minutes + ' мин.';
        } else {
            return minutes + ' мин.';
        }
    }

    function updateElement(elementId, value) {
        const element = document.getElementById(elementId);
        if (element && element.textContent !== value) {
            // Анимация изменения значения
            element.style.transform = 'scale(1.1)';
            element.style.color = '#00bcd4';
            element.textContent = value;
            
            setTimeout(() => {
                element.style.transform = 'scale(1)';
                element.style.color = '';
            }, 300);
        }
    }

    function updateSystemStatus(status) {
        const statusIndicator = document.querySelector('.status-indicator');
        const statusText = document.querySelector('.status-text');
        const systemStatus = document.querySelector('.system-status');
        
        if (status === 'online') {
            statusIndicator.style.background = '#10b981';
            statusIndicator.style.animation = 'pulse 2s infinite';
            statusText.textContent = 'Система онлайн';
            statusText.style.color = '#10b981';
            systemStatus.style.background = 'rgba(16, 185, 129, 0.1)';
            systemStatus.style.borderColor = 'rgba(16, 185, 129, 0.2)';
        } else if (status === 'warning') {
            statusIndicator.style.background = '#f59e0b';
            statusIndicator.style.animation = 'pulse 1s infinite';
            statusText.textContent = 'Частично доступен';
            statusText.style.color = '#f59e0b';
            systemStatus.style.background = 'rgba(245, 158, 11, 0.1)';
            systemStatus.style.borderColor = 'rgba(245, 158, 11, 0.2)';
        } else {
            statusIndicator.style.background = '#ef4444';
            statusIndicator.style.animation = 'none';
            statusText.textContent = 'Проблемы с системой';
            statusText.style.color = '#ef4444';
            systemStatus.style.background = 'rgba(239, 68, 68, 0.1)';
            systemStatus.style.borderColor = 'rgba(239, 68, 68, 0.2)';
        }
    }

    // Анимация появления футера
    function animateFooter() {
        const footer = document.querySelector('.admin-footer');
        footer.style.opacity = '0';
        footer.style.transform = 'translateY(20px)';
        footer.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
        
        setTimeout(() => {
            footer.style.opacity = '1';
            footer.style.transform = 'translateY(0)';
        }, 100);
    }

    // Анимация карточек статистики
    function animateStats() {
        const stats = document.querySelectorAll('.stat-item');
        stats.forEach((stat, index) => {
            stat.style.opacity = '0';
            stat.style.transform = 'translateY(10px)';
            
            setTimeout(() => {
                stat.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
                stat.style.opacity = '1';
                stat.style.transform = 'translateY(0)';
            }, index * 100);
        });
    }

    // Подсветка текущего года в копирайте
    function highlightCurrentYear() {
        const yearElement = document.getElementById('currentYear');
        if (yearElement) {
            setInterval(() => {
                yearElement.style.color = yearElement.style.color === 'rgb(0, 188, 212)' ? '' : '#00bcd4';
            }, 1000);
        }
    }

    // Создаем AJAX endpoint для получения статистики
    function createAjaxEndpoint() {
        // Если файла не существует, создаем заглушку
        if (typeof window.systemStatsEndpoint === 'undefined') {
            window.systemStatsEndpoint = '/admin/ajax/system_stats.php';
        }
    }

    // Инициализация
    createAjaxEndpoint();
    animateFooter();
    animateStats();
    highlightCurrentYear();
    
    // Загружаем статистику при загрузке страницы
    updateSystemStats();
    
    // Обновляем статистику каждые 60 секунд
    setInterval(updateSystemStats, 60000);

    // Анимация при наведении на быстрые действия
    document.querySelectorAll('.quick-action-btn').forEach(btn => {
        btn.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-2px) scale(1.05)';
        });
        
        btn.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0) scale(1)';
        });
    });

    // Динамическое обновление времени в футере
    function updateTime() {
        const now = new Date();
        const timeElements = document.querySelectorAll('.current-time');
        if (timeElements.length === 0) {
            // Создаем элемент для отображения времени
            const timeContainer = document.createElement('div');
            timeContainer.className = 'current-time';
            timeContainer.style.cssText = 'font-size: 11px; color: rgba(255, 255, 255, 0.5); margin-top: 5px;';
            timeContainer.textContent = now.toLocaleTimeString('ru-RU');
            document.querySelector('.copyright').appendChild(timeContainer);
        } else {
            timeElements.forEach(element => {
                element.textContent = now.toLocaleTimeString('ru-RU');
            });
        }
    }
    
    setInterval(updateTime, 1000);
    updateTime();
    
    // Создаем файл system_stats.php если его нет
    function createStatsFileIfNotExists() {
        fetch('/admin/ajax/system_stats.php')
            .then(response => {
                if (response.status === 404) {
                    // Файл не существует, создаем его
                    createSystemStatsFile();
                }
            })
            .catch(() => {
                createSystemStatsFile();
            });
    }
    
    function createSystemStatsFile() {
        const statsCode = `<?php
header('Content-Type: application/json');
session_start();

// Симуляция данных статистики
$data = [
    'success' => true,
    'load' => implode(', ', sys_getloadavg()),
    'session_start' => date('Y-m-d H:i:s', $_SERVER['REQUEST_TIME']),
    'system_status' => 'online',
    'active_users' => 0,
    'page_gen_time' => microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']
];

// Пытаемся получить реальные данные из базы
try {
    require_once '../../includes/db.php';
    $db = new Database();
    $pdo = $db->getConnection();
    
    $active_users = $pdo->query("SELECT COUNT(DISTINCT user_id) as count FROM user_sessions WHERE last_activity > DATE_SUB(NOW(), INTERVAL 5 MINUTE)")->fetch(PDO::FETCH_ASSOC);
    $data['active_users'] = $active_users['count'] ?? 0;
    
} catch (Exception $e) {
    // Продолжаем с симулированными данными
}

echo json_encode($data);
?>`;
        
        // Сохраняем файл
        fetch('/admin/ajax/create_stats.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'code=' + encodeURIComponent(statsCode)
        });
    }
});
</script>
</body>
</html>