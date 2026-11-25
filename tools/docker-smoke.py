"""Docker smoke with a small TLS Proxmox fixture and real panel endpoints."""
import asyncio
import json
from pathlib import Path
import ssl
import sys
from urllib.parse import parse_qs

from aiohttp import ClientSession, ClientTimeout, DummyCookieJar, WSMsgType, web

CERT = '/etc/proxmox-tls/smoke.pem'
KINDS = {501: 'qemu', 502: 'lxc', 503: 'qemu'}
states = {guest: 'stopped' for guest in KINDS}
eventlog = Path('/tmp/homevlad-docker-proxmox.log')


def record(message):
    with eventlog.open('a') as output:
        output.write(message + '\n')


@web.middleware
async def authorize(request, handler):
    if request.headers.get('Authorization') != 'PVEAPIToken=qa@pve!panel=docker-smoke-token':
        raise web.HTTPUnauthorized()
    return await handler(request)


async def inventory(request):
    return web.json_response({'data': [
        {'type': kind, 'vmid': guest, 'node': 'qa'} for guest, kind in KINDS.items()
    ]})


async def guest(request):
    kind, vmid, operation = (request.match_info[key] for key in ('kind', 'id', 'operation'))
    vmid = int(vmid)
    if KINDS.get(vmid) != kind:
        raise web.HTTPNotFound()
    if operation == 'vncproxy' and request.method == 'POST' and (await request.post()).get('websocket') == '1':
        return web.json_response({'data': {'port': 5900, 'ticket': 'fixture-ticket', 'password': 'fixture-rfb'}})
    if operation == 'vncwebsocket' and request.query.get('vncticket') == 'fixture-ticket':
        socket = web.WebSocketResponse(protocols=('binary',))
        await socket.prepare(request)
        await socket.send_bytes(f'fixture-frame:{kind}:{vmid}'.encode())
        message = await socket.receive()
        if message.type == WSMsgType.BINARY:
            await socket.send_bytes(b'fixture-input:' + message.data)
        await socket.close()
        return socket
    if operation == 'config' and request.method == 'GET':
        return web.json_response({'data': {'cores': 1, 'sockets': 1, 'memory': 256, 'scsi0': 'qa-storage:disk,size=1G'}})
    if operation == 'config' and request.method == 'PUT':
        record(f'{kind}:{vmid}:config')
        return web.json_response({'data': None})
    if operation == 'status/current' and request.method == 'GET':
        return web.json_response({'data': {'status': states[vmid]}})
    if operation in ('status/start', 'status/reboot', 'status/shutdown', 'status/stop') and request.method == 'POST':
        action = operation.split('/')[1]
        states[vmid] = 'running' if action in ('start', 'reboot') else 'stopped'
        record(f'{kind}:{vmid}:{action}')
        return web.json_response({'data': f'UPID:qa:fixture:{kind}:{vmid}:{action}'})
    raise web.HTTPNotFound()


async def task(request):
    return web.json_response({'data': {'status': 'stopped', 'exitstatus': 'OK'}})


def serve():
    tls = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    tls.load_cert_chain(CERT, '/tmp/homevlad-docker-pve.key')
    app = web.Application(middlewares=[authorize])
    app.router.add_get('/api2/json/cluster/resources', inventory)
    async def standalone(_request):
        return web.json_response({'data': []})
    async def node_status(_request):
        return web.json_response({'data': {'status': 'online'}})
    app.router.add_get('/api2/json/cluster/status', standalone)
    app.router.add_get('/api2/json/nodes/qa-extra/status', node_status)
    app.router.add_get('/api2/json/nodes/qa/tasks/{id}/status', task)
    app.router.add_route('*', '/api2/json/nodes/qa/{kind}/{id}/{operation:.+}', guest)
    web.run_app(app, host='0.0.0.0', port=8006, ssl_context=tls, print=None)


async def smoke():
    config = json.loads(Path('/tmp/homevlad-docker-sessions.json').read_text())
    node = config['node']
    origin = 'https://nginx'
    tls = ssl.create_default_context(cafile=CERT)

    async with ClientSession(timeout=ClientTimeout(total=15), cookie_jar=DummyCookieJar()) as browser:
        async def launch(route, vmid, role, expected=200):
            headers = {'Cookie': 'PHPSESSID=' + config['sessions'][role]['id']} if role else {}
            params = {'node_id': node, 'vm_id': vmid}
            async with browser.get(origin + route, params=params, headers=headers, ssl=tls) as response:
                assert response.status == expected, (route, vmid, response.status, await response.text())
                return await response.json()

        customer = '/templates/vnc_console.php'
        inventory_route = '/admin/vnc_console_prox.php'
        await launch(customer, 501, None, 401)
        await launch(customer, 501, 'other', 404)
        await launch(inventory_route, 501, 'customer', 403)
        cases = [
            (customer, 501, 'customer'), (customer, 502, 'customer'),
            (inventory_route, 501, 'admin'), (inventory_route, 502, 'admin'),
            ('/admin/vnc_console_admin.php', 503, 'admin'),
        ]
        for route, vmid, role in cases:
            result = await launch(route, vmid, role)
            token, *options = result['data']['url'].split('#')[1].split('&')
            control = parse_qs('&'.join(options))['control'][0]
            kind = KINDS[vmid]
            async with browser.ws_connect(origin + '/console/socket', origin=origin, ssl=tls) as ws:
                await ws.send_str(token)
                assert await ws.receive_json() == {'type': 'qemu', 'password': 'fixture-rfb'}
                assert await ws.receive_bytes() == f'fixture-frame:{kind}:{vmid}'.encode()
                await ws.send_bytes(b'typed')
                assert await ws.receive_bytes() == b'fixture-input:typed'
            actions = ('start', 'reboot', 'shutdown', 'start', 'stop')
            for action in actions:
                with_cookie = {'Cookie': 'PHPSESSID=' + config['sessions'][role]['id']}
                async with browser.post(origin + '/console/control.php', ssl=tls, headers=with_cookie,
                                        json={'control': control, 'action': action, 'type': kind}) as response:
                    assert response.status == 200 and (await response.json())['success'], (
                        route, kind, action, response.status, await response.text())
            print(f'{role} {kind} {vmid}: WSS input/output and four power actions')
        events = eventlog.read_text().splitlines()
        assert events == [f'{KINDS[vmid]}:{vmid}:{action}' for _, vmid, _ in cases for action in actions], events
        async with browser.get(origin + '/console/assets/novnc/core/rfb.js', ssl=tls) as response:
            assert response.status == 200 and 'RFB' in await response.text()
        print('Bundled noVNC served by Nginx')


async def license_power_smoke():
    config = json.loads(Path('/tmp/homevlad-docker-sessions.json').read_text())
    tls = ssl.create_default_context(cafile=CERT)
    origin = 'https://nginx'
    key = Path('/var/lib/homevlad-private/license/activation.key')
    headers = {'Cookie': 'PHPSESSID=' + config['sessions']['customer']['id']}

    async with ClientSession(timeout=ClientTimeout(total=15), cookie_jar=DummyCookieJar()) as browser:
        async with browser.get(origin + '/templates/vnc_console.php',
                               params={'node_id': config['node'], 'vm_id': 501},
                               headers=headers, ssl=tls) as response:
            assert response.status == 200, await response.text()
            fragment = (await response.json())['data']['url'].split('#')[1]
            control = parse_qs(fragment.split('&', 1)[1])['control'][0]

        async def power(action):
            async with browser.post(origin + '/console/control.php', ssl=tls, headers=headers,
                                    json={'control': control, 'action': action, 'type': 'qemu'}) as response:
                return response.status, await response.json()

        before = eventlog.read_text()
        try:
            key.write_text('limit-fixture')
            status, payload = await power('start')
            assert status == 400 and not payload.get('success') and 'Лимит лицензии' in str(payload), payload
            for cpu, ram in [(2, 256), (1, 512)]:
                async with browser.post(origin + '/templates/vm_settings.php',
                                        params={'id': config['guests']['501']}, headers=headers, ssl=tls,
                                        data={'change_resources': '1', 'plan_id': '0', 'custom_cpu': str(cpu),
                                              'custom_ram': str(ram), 'custom_disk': '1',
                                              'guest_settings_csrf': config['sessions']['customer']['guest_settings_csrf']}) as response:
                    assert response.status == 200 and 'Лимит лицензии' in await response.text(), response.status
            assert eventlog.read_text() == before
            admin = config['sessions']['admin']
            async with browser.post(origin + '/api/mobile_admin.php', ssl=tls,
                                    headers={'Cookie': 'PHPSESSID=' + admin['id']},
                                    json={'action': 'node_save', 'csrf': admin['mobile_csrf'],
                                          'zoneId': config['zone'], 'name': 'qa-extra', 'host': 'localhost',
                                          'port': 8006, 'tokenId': 'qa@pve!panel', 'secret': 'docker-smoke-token',
                                          'active': True, 'primary': False, 'orders': False}) as response:
                node_response = await response.json()
                assert response.status == 422 and 'Лимит лицензии' in node_response.get('error', ''), node_response
            key.write_text('rejected-fixture')
            for action in ('start', 'stop'):
                status, payload = await power(action)
                assert status == 200 and payload.get('success'), payload
        finally:
            key.unlink(missing_ok=True)
        assert eventlog.read_text() == before + 'qemu:501:start\nqemu:501:stop\n'
        print('Running limit blocks Proxmox start, and restricted mode preserves existing guest controls')


if __name__ == '__main__':
    mode = sys.argv[1]
    if mode == 'serve':
        serve()
    elif mode == 'license':
        asyncio.run(license_power_smoke())
    else:
        asyncio.run(smoke())
