'use strict';

const page = document.querySelector('#page');
const rubles = new Intl.NumberFormat('ru-RU', {style: 'currency', currency: 'RUB', maximumFractionDigits: 2});
const number = new Intl.NumberFormat('ru-RU', {maximumFractionDigits: 2});
const labels = {running: 'Работает', stopped: 'Остановлена', suspended: 'Приостановлена', creating: 'Создаётся', error: 'Ошибка'};
const tabs = {overview: 'Обзор', network: 'Сеть', metrics: 'Метрики', access: 'Доступ'};
let data;
let renderId = 0;

function html(value) {
    return String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;'}[char]));
}
function show(value) { return html(value === null || value === undefined || value === '' ? '—' : value); }
function kind(value) { return value === 'lxc' ? 'LXC' : 'ВМ'; }
function status(value) { return '<span class="pill ' + html(Object.hasOwn(labels, value) ? value : '') + '">' + (labels[value] || 'Неизвестен') + '</span>'; }
function rows(values) {
    return '<dl>' + values.map(([label, value]) => '<div><dt>' + html(label) + '</dt><dd>' + show(value) + '</dd></div>').join('') + '</dl>';
}
function opts(items) {
    return items.map(([value, label]) => '<option value="' + html(value) + '">' + html(label) + '</option>').join('');
}
function title(name, info = '', action = '') {
    return '<div class="title"><div><h1>' + html(name) + '</h1>' + (info ? '<p>' + html(info) + '</p>' : '') + '</div>' + action + '</div>';
}
function serverList() {
    page.innerHTML = title('Серверы', 'Виртуальные машины и контейнеры', '<a class="button primary" href="#order">Новый сервер</a>') +
        '<div class="controls"><input id="query" type="search" aria-label="Поиск по имени, IP и VMID" placeholder="Имя, IP или VMID">' +
        '<select id="type" aria-label="Тип"><option value="">Все типы</option><option value="qemu">Виртуальные машины</option><option value="lxc">Контейнеры</option></select>' +
        '<select id="state" aria-label="Статус"><option value="">Все статусы</option>' + opts(Object.entries(labels)) + '</select>' +
        '<select id="zone" aria-label="Зона"><option value="">Все зоны</option>' + opts([...new Set(data.guests.map(g => g.zone).filter(Boolean))].map(zone => [zone,zone])) + '</select></div>' +
        '<div class="table-wrap"><table><thead><tr><th>Имя</th><th>Статус</th><th>Тип</th><th>Зона</th><th>IP-адрес</th><th>Ресурсы</th></tr></thead>' +
        '<tbody id="guests"></tbody></table><div class="no-data" id="empty" hidden>Серверы по этим фильтрам не найдены.</div></div>';
    const query = document.querySelector('#query');
    const type = document.querySelector('#type');
    const state = document.querySelector('#state');
    const zone = document.querySelector('#zone');
    const matches = () => {
        const search = query.value.trim().toLocaleLowerCase('ru');
        const found = data.guests.filter(g => (!type.value || g.vm_type === type.value) &&
            (!state.value || g.status === state.value) && (!zone.value || g.zone === zone.value) &&
            [g.hostname, g.ip_address, g.vm_id].some(value => String(value ?? '').toLocaleLowerCase('ru').includes(search)));
        document.querySelector('#guests').innerHTML = found.map(g =>
            '<tr><td><a href="#server/' + Number(g.id) + '">' + show(g.hostname) + '</a><br><small class="muted">VMID ' + Number(g.vm_id) +
            '</small></td><td>' + status(g.status) + '</td><td>' + kind(g.vm_type) + '</td><td>' + show(g.zone) +
            '</td><td>' + show(g.ip_address) + '</td><td>' + Number(g.cpu) + ' vCPU / ' + number.format(g.ram / 1024) +
            ' ГБ / ' + Number(g.disk) + ' ГБ</td></tr>').join('');
        document.querySelector('#empty').hidden = found.length > 0;
    };
    [query, type, state, zone].forEach(control => control.addEventListener('input', matches));
    matches();
}
function chart(metrics, key, label, unit) {
    const series = metrics.map(point => Number(point[key])).filter(Number.isFinite);
    if (!series.length) return '';
    const maximum = Math.max(...series, 1);
    const coords = series.map((value,index) => (index * 240 / Math.max(series.length - 1, 1)).toFixed(1) + ',' + (72 - 68 * Math.max(0,value) / maximum).toFixed(1)).join(' ');
    return '<figure class="chart card"><figcaption>' + html(label) + ' · <strong>' + number.format(series.at(-1)) + ' ' + html(unit) +
        '</strong></figcaption><svg viewBox="0 0 240 76" aria-hidden="true"><path d="M0 72H240 M0 38H240 M0 4H240" stroke="var(--line)"/>' +
        '<polyline points="' + coords + '" fill="none" stroke="currentColor" stroke-width="2" vector-effect="non-scaling-stroke"/></svg></figure>';
}
async function detail(id, tab, ticket) {
    const g = data.guests.find(guest => Number(guest.id) === id);
    if (!g) { page.innerHTML = title('Сервер недоступен') + '<a href="#servers">К списку серверов</a>'; return; }
    page.innerHTML = '<a href="#servers" class="crumb">← Серверы</a>' + title(g.hostname, kind(g.vm_type) + ' · VMID ' + g.vm_id, status(g.status)) +
        '<nav class="tabs" aria-label="Разделы сервера">' + Object.entries(tabs).map(([key,label]) =>
            '<button type="button" data-tab="' + key + '" aria-pressed="' + (tab === key) + '">' + label + '</button>').join('') +
        '</nav><section id="detail" aria-label="' + tabs[tab] + '"></section>';
    page.querySelectorAll('[data-tab]').forEach(button => button.addEventListener('click', () => location.hash = '#server/' + id + '/' + button.dataset.tab));
    const detailPanel = document.querySelector('#detail');
    if (tab === 'overview') detailPanel.innerHTML = '<section class="card"><h2>Конфигурация</h2>' + rows([
        ['Зона доступности', g.zone], ['ОС', [g.os_type === 'windows' ? 'Windows' : 'Linux', g.os_version].filter(Boolean).join(' ')],
        ['vCPU', g.cpu], ['Оперативная память', number.format(g.ram / 1024) + ' ГБ'], ['Диск', g.disk + ' ГБ'], ['Тариф', g.tariff]
    ]) + '</section>';
    if (tab === 'network') detailPanel.innerHTML = '<section class="card"><h2>Подключение</h2>' +
        rows([['IP-адрес', g.ip_address]]) + '</section>';
    if (tab === 'access') detailPanel.innerHTML = '<section class="card"><h2>Подключение к серверу</h2><p class="muted">Консоль и команды управления доступны в рабочем кабинете.</p><a class="button" href="https://homevlad.ru/templates/my_vms.php">Открыть рабочий кабинет</a></section>';
    if (tab !== 'metrics') return;
    detailPanel.textContent = 'Загружаем последние измерения…';
    try {
        const response = await fetch('data.php?metrics=' + id, {cache:'no-store'});
        if (!response.ok) throw new Error();
        const {metrics} = await response.json();
        if (ticket !== renderId) return;
        detailPanel.innerHTML = metrics.length ? '<div class="charts">' + [
            ['cpu_usage','Процессор','%'], ['mem_usage','Память','ГБ'],
            ['net_in','Входящий трафик','Мбит/с'], ['net_out','Исходящий трафик','Мбит/с'],
            ['disk_read','Чтение с диска','МБ/с'], ['disk_write','Запись на диск','МБ/с']
        ].map(spec => chart(metrics, ...spec)).join('') + '</div>' : '<p class="no-data">Для сервера пока нет доступных измерений.</p>';
    } catch {
        if (ticket === renderId) detailPanel.textContent = 'Измерения временно недоступны.';
    }
}
function order() {
    page.innerHTML = '<a href="#servers" class="crumb">← Серверы</a>' + title('Новый сервер', 'Подберите конфигурацию и оцените стоимость.') +
        '<div class="config-grid"><form id="config" class="sections">' +
        '<section class="card"><h2>Размещение</h2><div class="fields"><label>Имя<input id="host" maxlength="63" placeholder="Например, app-01"></label>' +
        '<label>Зона доступности<select id="order-zone"></select></label></div></section>' +
        '<section class="card"><h2>Источник</h2><div class="fields"><label>Тип<select id="order-kind"><option value="qemu">Виртуальная машина</option><option value="lxc">Контейнер LXC</option></select></label>' +
        '<label>Операционная система<select id="image"></select></label></div></section>' +
        '<section class="card"><h2>Ресурсы</h2><div class="fields"><label>Тариф<select id="plan"></select></label></div></section>' +
        '<section class="card"><h2>Диск и сеть</h2><div class="fields"><label>Пул хранилищ<select id="storage"></select></label>' +
        '<label>Сетевой пул<select id="network"></select></label></div></section></form>' +
        '<aside class="summary card"><h2>Стоимость</h2><div id="quote"></div><button class="button primary" id="review" type="button">Проверить конфигурацию</button>' +
        '<p class="muted" id="review-result" role="status"></p></aside></div>';
    const pick = id => document.getElementById(id);
    const form = pick('config');
    const zone = pick('order-zone');
    const type = pick('order-kind');
    const image = pick('image');
    const plan = pick('plan');
    function choices(element, entries, empty) {
        const selected = element.value;
        element.innerHTML = opts([['', empty], ...entries]);
        if (entries.some(([id]) => String(id) === selected)) element.value = selected;
        else if (entries.length === 1) element.value = String(entries[0][0]);
    }
    function selectedZone() { return data.zones.find(item => String(item.id) === zone.value); }
    function selectedImage() { return data.images.find(item => item.id === image.value); }
    function selectedPlan() { return data.plans.find(item => String(item.id) === plan.value); }
    function pools() {
        const current = selectedZone();
        for (const name of ['storage','network']) {
            choices(pick(name), (current?.[name] || []).map(value => [value,value]), 'Выберите пул');
        }
    }
    function plans() {
        const os = selectedImage()?.os || 'linux';
        choices(plan, data.plans.filter(t => t.vm_type === type.value && t.os_type === os).map(t => [t.id,t.name]), 'Выберите тариф');
    }
    function images() {
        choices(image, data.images.filter(os => os.kind === type.value).map(os => [os.id,os.label]), 'Выберите ОС');
        plans();
    }
    function quote() {
        const tariff = selectedPlan();
        pick('review-result').textContent = '';
        pick('quote').innerHTML = rows([
            ['Имя', pick('host').value], ['Зона', selectedZone()?.name], ['ОС', selectedImage()?.label],
            ['CPU', tariff ? tariff.cpu + ' vCPU' : ''], ['Память', tariff ? number.format(tariff.ram / 1024) + ' ГБ' : ''],
            ['Диск', tariff ? tariff.disk + ' ГБ' : ''], ['Сетевой пул', pick('network').value]
        ]) + '<p class="summary-total">' + (tariff ? rubles.format(tariff.price) + '<span class="summary-period">в месяц</span>' : '—') + '</p>';
    }
    choices(zone, data.zones.map(item => [item.id,item.name]), 'Выберите зону');
    pools();
    images();
    quote();
    zone.addEventListener('change', pools);
    type.addEventListener('change', images);
    image.addEventListener('change', plans);
    form.addEventListener('change', quote);
    pick('host').addEventListener('input', quote);
    form.addEventListener('submit', event => event.preventDefault());
    pick('review').addEventListener('click', () => {
        const hostname = pick('host').value.trim();
        const allSelected = selectedZone() && selectedImage() && selectedPlan() && pick('storage').value && pick('network').value;
        pick('review-result').textContent = !/^[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$/.test(hostname)
            ? 'Укажите допустимое имя сервера: латинские буквы, цифры и дефисы.'
            : allSelected ? 'Конфигурация заполнена. Это предпросмотр, заказ не отправлен.' : 'Выберите зону, ОС, тариф и оба пула.';
    });
}
function zones() {
    page.innerHTML = title('Зоны и пулы', 'Ресурсы, открытые для новых заказов') + '<div class="sections">' +
        (data.zones.map(z => '<section class="card"><h2>' + show(z.name) + '</h2>' +
            rows([['Сетевые пулы', z.network.join(', ')], ['Пулы хранилищ', z.storage.join(', ')]]) + '</section>').join('') ||
        '<p class="no-data">Доступных зон нет.</p>') + '</div>';
}
function render() {
    const ticket = ++renderId;
    const [section, guest, selectedTab] = location.hash.slice(1).split('/');
    const view = ['order','zones'].includes(section) ? section : 'servers';
    for (const link of document.querySelectorAll('[data-nav]')) {
        if (link.dataset.nav === view) link.setAttribute('aria-current','page');
        else link.removeAttribute('aria-current');
    }
    if (section === 'server') void detail(Number(guest), Object.hasOwn(tabs, selectedTab) ? selectedTab : 'overview', ticket);
    else if (view === 'order') order();
    else if (view === 'zones') zones();
    else serverList();
}
async function start() {
    try {
        const response = await fetch('data.php', {cache:'no-store'});
        if (!response.ok) throw new Error();
        data = await response.json();
        document.querySelector('#account').textContent = data.account || 'HomeVlad';
        window.addEventListener('hashchange', render);
        render();
    } catch {
        page.innerHTML = title('Кабинет недоступен', 'Обновите страницу или проверьте доступ к предпросмотру.');
    }
}
void start();
