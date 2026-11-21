(() => {
    const group = document.querySelector('.guest-password-actions');
    if (!group) return;
    const reveal = group.querySelector('[data-reveal]');
    const copy = group.querySelector('[data-copy]');
    const result = group.querySelector('[data-result]');
    const form = document.querySelector('#guest-password [data-generate]');
    const mask = '••••••••••••';
    const expires = Date.now() + Number(group.dataset.ttl) * 1000;
    let revision = 0;
    let revealTimer;

    function hide() {
        revision++;
        clearTimeout(revealTimer);
        reveal.textContent = mask;
        reveal.setAttribute('aria-pressed', 'false');
    }
    function expire() {
        hide();
        group.hidden = true;
        form.hidden = false;
    }
    async function read() {
        if (Date.now() >= expires) {
            expire();
            throw new Error('Период показа истёк');
        }
        const response = await fetch('/api/guest_credentials.php', {
            method: 'POST', credentials: 'same-origin', cache: 'no-store',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'password', id: Number(group.dataset.guestId), csrf: group.dataset.csrf}),
        });
        const data = await response.json().catch(() => null);
        if (!data) throw new Error('Сервис просмотра пароля временно недоступен');
        if (response.status === 404) expire();
        if (!response.ok || typeof data.value !== 'string') throw new Error(data.error || 'Пароль недоступен');
        if (Date.now() >= expires) {
            expire();
            throw new Error('Период показа истёк');
        }
        return data.value;
    }
    async function show() {
        const request = ++revision;
        result.textContent = '';
        try {
            const password = await read();
            if (request !== revision) return;
            reveal.textContent = password;
            reveal.setAttribute('aria-pressed', 'true');
            revealTimer = setTimeout(hide, 8000);
        } catch (error) {
            if (request === revision) result.textContent = error.message;
        }
    }
    reveal.addEventListener('pointerenter', event => { if (event.pointerType === 'mouse') show(); });
    reveal.addEventListener('pointerleave', hide);
    reveal.addEventListener('blur', hide);
    reveal.addEventListener('click', () => {
        if (reveal.getAttribute('aria-pressed') === 'true') hide();
        else show();
    });
    copy.addEventListener('click', async () => {
        hide();
        try {
            if (!navigator.clipboard) throw new Error('Копирование недоступно в этом браузере');
            if (window.ClipboardItem && navigator.clipboard.write) {
                const text = read().then(value => new Blob([value], {type: 'text/plain'}));
                await navigator.clipboard.write([new ClipboardItem({'text/plain': text})]);
            } else {
                await navigator.clipboard.writeText(await read());
            }
            result.textContent = 'Пароль скопирован';
        } catch {
            result.textContent = 'Не удалось скопировать пароль';
        }
    });
    window.addEventListener('pagehide', hide);
    document.addEventListener('visibilitychange', () => {
        hide();
        if (Date.now() >= expires) expire();
    });
    setTimeout(expire, Math.max(0, expires - Date.now()));
})();
