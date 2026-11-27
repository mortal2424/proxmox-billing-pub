(() => {
    const stylesheet = document.createElement('link');
    stylesheet.rel = 'stylesheet';
    stylesheet.href = '/css/panel-dialog.css?v=165';
    document.head.append(stylesheet);
    let modal;
    let previousFocus;

    function open(url, title, wide = false) {
        const target = new URL(url, location.href);
        if (target.origin !== location.origin) return;
        if (!modal) {
            modal = document.createElement('dialog');
            modal.className = 'panel-dialog';
            modal.setAttribute('aria-labelledby', 'panel-dialog-title');
            modal.innerHTML = '<header><h2 id="panel-dialog-title"></h2><a target="_blank" rel="noopener">Открыть отдельно</a><form method="dialog"><button autofocus aria-label="Закрыть">×</button></form></header><iframe></iframe>';
            modal.addEventListener('close', () => {
                modal.querySelector('iframe').src = 'about:blank';
                previousFocus?.focus();
            });
            document.body.append(modal);
        }
        previousFocus = document.activeElement;
        modal.classList.toggle('panel-dialog-wide', wide);
        modal.querySelector('h2').textContent = title;
        modal.querySelector('a').href = target.href;
        const frame = modal.querySelector('iframe');
        frame.title = title;
        frame.src = target.href;
        if (!modal.open) modal.showModal();
    }

    document.addEventListener('click', event => {
        const link = event.target.closest('a[data-panel-dialog]');
        if (!link || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey ||
            new URL(link.href).origin !== location.origin || !HTMLDialogElement.prototype.showModal) return;
        event.preventDefault();
        open(link.href, link.dataset.panelDialog, link.hasAttribute('data-panel-dialog-wide'));
    });
    window.openPanelDialog = open;
})();
