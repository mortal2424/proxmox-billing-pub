import RFB from '/console/assets/novnc/core/rfb.js';

const status = document.querySelector('#status');
const screen = document.querySelector('#screen');
const cad = document.querySelector('#cad');
const [token, ...fragmentParameters] = location.hash.slice(1).split('&');
const fragment = new URLSearchParams(fragmentParameters.join('&'));
const control = fragment.get('control') || '';
const credentialPanel = document.querySelector('#guest-credentials');
const passwordButton = credentialPanel.querySelector('[data-credential="password"]');
let sendCredential = null;
const power = document.querySelector('#power');
const powerResult = document.querySelector('#power-result');
if (/^[a-f0-9]{48}$/.test(control)) {
    power.hidden = false;
    power.addEventListener('click', async event => {
        const action = event.target.dataset.action;
        if (!action) return;
        const labels = {start: 'Запустить', shutdown: 'Выключить ОС', reboot: 'Перезагрузить', stop: 'Принудительно остановить'};
        if (action !== 'start' && !confirm(labels[action] + ' машину?')) return;
        const buttons = [...power.querySelectorAll('button')];
        buttons.forEach(button => { button.disabled = true; });
        powerResult.textContent = 'Отправка команды…';
        try {
            const response = await fetch('/console/control.php', {
                method: 'POST', credentials: 'same-origin',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({control, action}),
            });
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.error || 'Команда не выполнена');
            powerResult.textContent = result.already_stopped
                ? 'Машина уже остановлена.'
                : 'Команда принята. При отключении откройте консоль повторно из кабинета.';
        } catch (error) {
            powerResult.textContent = error.message;
        } finally {
            buttons.forEach(button => { button.disabled = false; });
        }
    });
}
async function requestCredential(action) {
    const response = await fetch('/api/guest_credentials.php', {
        method: 'POST', credentials: 'same-origin', cache: 'no-store',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({control, action}),
    });
    const data = await response.json().catch(() => null);
    if (!data) throw new Error('Сервис данных гостя временно недоступен');
    if (!response.ok) throw new Error(data.error || 'Данные недоступны');
    return data;
}

async function enableGuestCredentials(send) {
    if (fragment.get('owner') !== '1' || !/^[a-f0-9]{48}$/.test(control)) return;
    sendCredential = send;
    try {
        const availability = await requestCredential('available');
        if (sendCredential !== send) return;
        passwordButton.hidden = !availability.password;
        credentialPanel.hidden = false;
    } catch {
        // Guest console stays usable when its credential endpoint is unavailable.
    }
}

credentialPanel.addEventListener('click', async event => {
    const button = event.target.closest('[data-credential]');
    const send = sendCredential;
    if (!button || !send) return;
    const action = button.dataset.credential;
    button.disabled = true;
    try {
        const {value} = await requestCredential(action);
        if (sendCredential !== send || typeof value !== 'string' || !/^[ -~]{1,128}$/.test(value)) return;
        send(value);
        powerResult.textContent = 'Данные введены, Enter отправлен.';
    } catch (error) {
        powerResult.textContent = error.message;
        if (action === 'password') passwordButton.hidden = true;
    } finally {
        button.disabled = false;
    }
});

function typeGuestCredential(rfb, value) {
    for (const character of value) {
        const shifted = /[A-Z!]/.test(character);
        let code;
        if (/[a-z]/i.test(character)) code = 'Key' + character.toUpperCase();
        if (/[0-9]/.test(character)) code = 'Digit' + character;
        if (character === '!') code = 'Digit1';

        // QEMU's extended key events carry physical scancodes. Send Shift explicitly.
        if (shifted) rfb.sendKey(0xffe1, 'ShiftLeft', true);
        try {
            rfb.sendKey(character.charCodeAt(0), code);
        } finally {
            if (shifted) rfb.sendKey(0xffe1, 'ShiftLeft', false);
        }
    }
    rfb.sendKey(0xff0d, 'Enter');
    rfb.focus();
}

history.replaceState(null, '', location.pathname);

function disconnected() {
    cad.hidden = true;
    credentialPanel.hidden = true;
    sendCredential = null;
    status.textContent = 'Консоль отключена. Откройте её снова из биллинга.';
}

if (!/^[a-f0-9]{64}$/.test(token)) {
    disconnected();
} else {
    // Load the terminal before opening the socket, so initial frames are not lost.
    try {
        for (const path of ['xterm/lib/xterm.js', 'fit/lib/addon-fit.js']) {
            await new Promise((resolve, reject) => {
                const script = document.createElement('script');
                script.src = '/console/assets/' + path;
                script.onload = resolve;
                script.onerror = reject;
                document.head.append(script);
            });
        }
        const socket = new WebSocket('wss://' + location.host + '/console/socket');
        socket.binaryType = 'arraybuffer';
        socket.addEventListener('open', () => socket.send(token));
        socket.addEventListener('close', disconnected);
        socket.addEventListener('message', event => {
            try {
                const setup = JSON.parse(event.data);
                if (setup.type === 'qemu') {
                    const rfb = new RFB(screen, socket, {credentials: {password: setup.password}});
                    rfb.scaleViewport = true;
                    rfb.addEventListener('connect', () => {
                        status.textContent = 'Консоль QEMU';
                        cad.hidden = false;
                        enableGuestCredentials(value => typeGuestCredential(rfb, value));
                    });
                    cad.addEventListener('click', () => rfb.sendCtrlAltDel());
                    rfb.addEventListener('disconnect', disconnected);
                } else if (setup.type === 'lxc') {
                    openTerminal(socket);
                } else {
                    socket.close();
                }
            } catch {
                socket.close();
            }
        }, {once: true});
    } catch {
        disconnected();
    }
}

function openTerminal(socket) {
    const terminal = new window.Terminal({cursorBlink: true});
    const fit = new window.FitAddon.FitAddon();
    terminal.loadAddon(fit);
    terminal.open(screen);
    const send = data => {
        if (socket.readyState === WebSocket.OPEN) socket.send(data);
    };
    const encoder = new TextEncoder();
    const enterText = data => send('0:' + encoder.encode(data).length + ':' + data);
    terminal.onData(enterText);
    terminal.onResize(({cols, rows}) => send('1:' + cols + ':' + rows + ':'));
    socket.addEventListener('message', event => {
        terminal.write(typeof event.data === 'string' ? event.data : new Uint8Array(event.data));
    });
    fit.fit();
    send('1:' + terminal.cols + ':' + terminal.rows + ':');
    terminal.focus();
    status.textContent = 'Консоль LXC';
    enableGuestCredentials(value => {
        enterText(value + '\r');
        terminal.focus();
    });
    const observer = new ResizeObserver(() => fit.fit());
    observer.observe(screen);
    const timer = setInterval(() => send('2'), 30000);
    socket.addEventListener('close', () => {
        observer.disconnect();
        clearInterval(timer);
    }, {once: true});
}
