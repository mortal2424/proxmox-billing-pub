(() => {
    if (document.documentElement.dataset.cabinetDialogs) return;
    document.documentElement.dataset.cabinetDialogs = '1';
    const stylesheet = document.createElement('link');
    stylesheet.rel = 'stylesheet';
    stylesheet.href = '/css/panel-dialog.css?v=166';
    document.head.append(stylesheet);
    let dialogScript;

    document.addEventListener('click', async event => {
        const link = event.target.closest('a[data-cabinet-dialog]');
        if (!link || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey ||
            typeof HTMLDialogElement === 'undefined' || !HTMLDialogElement.prototype.showModal) return;
        const url = new URL(link.href);
        if (url.origin !== location.origin) return;
        event.preventDefault();
        try {
            if (!window.openPanelDialog) {
                dialogScript ??= new Promise((resolve, reject) => {
                    const script = document.createElement('script');
                    script.src = '/js/panel-dialog.js?v=165';
                    script.addEventListener('load', resolve, {once: true});
                    script.addEventListener('error', reject, {once: true});
                    document.head.append(script);
                });
                await dialogScript;
            }
            url.searchParams.set('embed', '1');
            window.openPanelDialog(url, link.dataset.cabinetDialog, true);
            document.querySelector('dialog.panel-dialog[open] > header > a').href = link.href;
        } catch {
            location.assign(link.href);
        }
    });
})();
