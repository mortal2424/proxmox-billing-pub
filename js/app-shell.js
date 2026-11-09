if ('serviceWorker' in navigator && window.isSecureContext) {
    navigator.serviceWorker.register('/service-worker.js').catch(error => {
        console.warn('Service worker registration failed', error);
    });
}

function makeTableScrollable(table) {
    if (table.closest('.mobile-table-scroll, .table-responsive, td, th')) return;

    const scrollArea = document.createElement('div');
    scrollArea.className = 'mobile-table-scroll';
    scrollArea.tabIndex = 0;
    scrollArea.setAttribute('role', 'region');
    scrollArea.setAttribute('aria-label', table.caption?.textContent.trim() || 'Таблица с прокруткой');
    table.before(scrollArea);
    scrollArea.append(table);
}

document.querySelectorAll('table').forEach(makeTableScrollable);
new MutationObserver(records => {
    for (const {addedNodes} of records) {
        for (const node of addedNodes) {
            if (!(node instanceof Element) || !node.isConnected) continue;
            if (node.matches('table')) makeTableScrollable(node);
            else node.querySelectorAll('table').forEach(makeTableScrollable);
        }
    }
}).observe(document.body, {childList: true, subtree: true});
