if ('serviceWorker' in navigator && window.isSecureContext) {
    navigator.serviceWorker.register('/service-worker.js').catch(error => {
        console.warn('Service worker registration failed', error);
    });
}

document.querySelectorAll('main table, .main-content table, .dashboard-content table').forEach(table => {
    if (table.closest('.mobile-table-scroll, .table-responsive')) return;

    const scrollArea = document.createElement('div');
    scrollArea.className = 'mobile-table-scroll';
    scrollArea.tabIndex = 0;
    scrollArea.setAttribute('role', 'region');
    scrollArea.setAttribute('aria-label', table.caption?.textContent.trim() || 'Таблица с прокруткой');
    table.before(scrollArea);
    scrollArea.append(table);
});
