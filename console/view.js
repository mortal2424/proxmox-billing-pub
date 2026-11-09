import RFB from '/console/assets/novnc/core/rfb.js';

const status = document.querySelector('#status');
const screen = document.querySelector('#screen');
const cad = document.querySelector('#cad');
const token = location.hash.slice(1);
history.replaceState(null, '', location.pathname);

function disconnected() {
    cad.hidden = true;
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
    terminal.onData(data => send('0:' + encoder.encode(data).length + ':' + data));
    terminal.onResize(({cols, rows}) => send('1:' + cols + ':' + rows + ':'));
    socket.addEventListener('message', event => {
        terminal.write(typeof event.data === 'string' ? event.data : new Uint8Array(event.data));
    });
    fit.fit();
    send('1:' + terminal.cols + ':' + terminal.rows + ':');
    terminal.focus();
    status.textContent = 'Консоль LXC';
    const observer = new ResizeObserver(() => fit.fit());
    observer.observe(screen);
    const timer = setInterval(() => send('2'), 30000);
    socket.addEventListener('close', () => {
        observer.disconnect();
        clearInterval(timer);
    }, {once: true});
}
