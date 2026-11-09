import asyncio
import json
import logging
import os
import secrets
import ssl
import time
from pathlib import Path
from urllib.parse import quote

from aiohttp import ClientError, ClientSession, ClientTimeout, DummyCookieJar, Fingerprint, TCPConnector, WSMsgType, web
from yarl import URL

TICKET_TTL = 30
MAX_PENDING = 256
MAX_SESSIONS = 32
SOCKET = Path("/run/homevlad-console/issuer.sock")
pending = {}
sessions = asyncio.Semaphore(MAX_SESSIONS)


async def issue(reader, writer):
    try:
        data = json.loads(await asyncio.wait_for(reader.readline(), 2))
        now = time.monotonic()
        for token, (expires, _) in list(pending.items()):
            if expires <= now:
                del pending[token]
        if len(pending) < MAX_PENDING and isinstance(data, dict):
            token = secrets.token_hex(32)
            pending[token] = (now + TICKET_TTL, data)
            asyncio.get_running_loop().call_later(TICKET_TTL, pending.pop, token, None)
            writer.write((token + "\n").encode())
            await writer.drain()
    except (ValueError, TimeoutError, ConnectionError):
        pass
    finally:
        writer.close()
        await writer.wait_closed()


async def relay(source, destination):
    async for message in source:
        if message.type == WSMsgType.BINARY:
            await destination.send_bytes(message.data)
        elif message.type == WSMsgType.TEXT:
            await destination.send_str(message.data)
        else:
            break


async def proxy(request):
    if request.headers.get("Origin") != request.app["origin"]:
        raise web.HTTPForbidden()
    browser = web.WebSocketResponse(heartbeat=25)
    if not browser.can_prepare(request).ok:
        raise web.HTTPBadRequest()
    if sessions.locked():
        raise web.HTTPServiceUnavailable()

    async with sessions:
        await browser.prepare(request)
        try:
            token = await browser.receive_str(timeout=5)
            grant = pending.pop(token, None)
            if not grant or grant[0] <= time.monotonic():
                await browser.close(code=1008, message=b"Invalid or expired launch ticket")
                return browser
            await connect_guest(request.app, browser, grant[1])
        except (ClientError, OSError, TimeoutError, ValueError, KeyError, TypeError, RuntimeError) as error:
            status = getattr(error, "status", None)
            logging.warning("Console connection failed: %s status=%s", type(error).__name__, status)
            await browser.close(code=1011, message=b"Proxmox connection failed")
    return browser


async def connect_guest(app, browser, target):
    base = URL.build(scheme="https", host=target["hostname"], port=int(target["api_port"] or 8006))
    api = base / "api2" / "json"
    client = app["client"]
    tls = app["pins"].get(base.authority, True)
    username = target["username"]
    if "@" not in username:
        username += "@pam"
    async with client.post(api / "access" / "ticket", ssl=tls, allow_redirects=False,
                           data={"username": username, "password": target.pop("password")}) as response:
        response.raise_for_status()
        auth = (await response.json())["data"]
    headers = {"Cookie": "PVEAuthCookie=" + auth["ticket"]}
    node = quote(target["node_name"], safe="")
    guest_id = int(target["vmid"])
    guest_type = target["type"]
    if guest_type == "detect":
        async with client.get(api / "cluster" / "resources", params={"type": "vm"},
                              headers=headers, ssl=tls, allow_redirects=False) as response:
            response.raise_for_status()
            guests = (await response.json())["data"]
        guest_type = next((guest["type"] for guest in guests
                           if guest.get("node") == target["node_name"]
                           and guest.get("vmid") == guest_id), None)
    if guest_type not in ("qemu", "lxc"):
        raise ValueError("Unsupported guest type")

    guest = api / "nodes" / node / guest_type / str(guest_id)
    terminal = guest_type == "lxc"
    async with client.post(guest / ("termproxy" if terminal else "vncproxy"),
                           headers=headers | {"CSRFPreventionToken": auth["CSRFPreventionToken"]},
                           data={} if terminal else {"websocket": "1"},
                           ssl=tls, allow_redirects=False) as response:
        response.raise_for_status()
        ticket = (await response.json())["data"]
    upstream_url = (guest / "vncwebsocket").with_query(port=ticket["port"], vncticket=ticket["ticket"])
    async with client.ws_connect(upstream_url, headers=headers, ssl=tls, protocols=("binary",),
                                 heartbeat=25) as upstream:
        if terminal:
            await upstream.send_bytes(f'{ticket["user"]}:{ticket["ticket"]}\n'.encode())
            reply = await upstream.receive(timeout=5)
            answer = reply.data.encode() if reply.type == WSMsgType.TEXT else reply.data
            if not isinstance(answer, bytes) or not answer.startswith(b"OK"):
                raise ValueError("Terminal authentication failed")
            await browser.send_json({"type": "lxc"})
            if answer[2:]:
                await browser.send_bytes(answer[2:])
        else:
            await browser.send_json({"type": "qemu", "password": ticket["ticket"]})
        pumps = [asyncio.create_task(relay(browser, upstream)),
                 asyncio.create_task(relay(upstream, browser))]
        try:
            await asyncio.wait(pumps, return_when=asyncio.FIRST_COMPLETED)
        finally:
            for task in pumps:
                task.cancel()
            await asyncio.gather(*pumps, return_exceptions=True)


async def reject_redirect(session, context, params):
    raise ClientError("Unexpected upstream redirect")


async def resources(app):
    tls = ssl.create_default_context()
    if os.environ.get("PROXMOX_CA_FILE"):
        tls.load_verify_locations(os.environ["PROXMOX_CA_FILE"])
    from aiohttp import TraceConfig
    trace = TraceConfig()
    trace.on_request_redirect.append(reject_redirect)
    pins_path = os.environ.get("PROXMOX_CERT_PINS")
    app["pins"] = {
        authority: Fingerprint(bytes.fromhex(fingerprint))
        for authority, fingerprint in json.loads(Path(pins_path).read_text()).items()
    } if pins_path else {}
    async with ClientSession(trace_configs=[trace], connector=TCPConnector(ssl=tls), cookie_jar=DummyCookieJar(),
                             timeout=ClientTimeout(total=15)) as client:
        app["client"] = client
        SOCKET.unlink(missing_ok=True)
        server = await asyncio.start_unix_server(issue, path=SOCKET, limit=16384)
        SOCKET.chmod(0o660)
        try:
            yield
        finally:
            server.close()
            await server.wait_closed()
            SOCKET.unlink(missing_ok=True)


if __name__ == "__main__":
    app = web.Application()
    app["origin"] = os.environ["CONSOLE_ORIGIN"]
    app.cleanup_ctx.append(resources)
    app.router.add_get("/console/socket", proxy)
    web.run_app(app, host="127.0.0.1", port=8765, access_log=None)
