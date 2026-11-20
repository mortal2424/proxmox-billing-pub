"""Check console transport selection and ensure tokens never enter password login."""
import asyncio
import importlib.util
from pathlib import Path
import unittest
from aiohttp import WSMsgType

spec = importlib.util.spec_from_file_location('bridge', Path(__file__).resolve().parents[1] / 'console/bridge.py')
bridge = importlib.util.module_from_spec(spec)
spec.loader.exec_module(bridge)

class Response:
    def __init__(self, data): self.data = data
    async def __aenter__(self): return self
    async def __aexit__(self, *args): pass
    def raise_for_status(self): pass
    async def json(self): return {'data': self.data}

class Socket:
    async def __aenter__(self): return self
    async def __aexit__(self, *args): pass
    def __aiter__(self): return self
    async def __anext__(self): raise StopAsyncIteration
    async def send_bytes(self, data): pass
    async def receive(self, **kwargs):
        return type('Message', (), {'type': WSMsgType.BINARY, 'data': b'OK'})()

class Client:
    def __init__(self): self.calls = []
    def post(self, url, **kwargs):
        self.calls.append(('post', str(url), kwargs))
        if str(url).endswith('/access/ticket'):
            return Response({'ticket':'login-ticket', 'CSRFPreventionToken':'csrf'})
        return Response({'port':5900, 'ticket':'vnc-ticket', 'password':'vnc-password', 'user':'console-user'})
    def ws_connect(self, url, **kwargs):
        self.calls.append(('ws', str(url), kwargs))
        return Socket()

class Browser(Socket):
    def __init__(self): self.messages=[]
    async def send_json(self, message): self.messages.append(message)

class ConsoleTokenTests(unittest.IsolatedAsyncioTestCase):
    async def check_transport(self, guest_type, token):
        client=Client();browser=Browser()
        target={'hostname':'pve.example.test','api_port':8006,'node_name':'node2','vmid':120,
                'type':guest_type,'username':'billing@pve!panel' if token else 'root@pam','password':'test-secret'}
        await bridge.connect_guest({'client':client,'pins':{}},browser,target)
        proxy=[c for c in client.calls if c[0]=='post' and not c[1].endswith('/access/ticket')][0]
        terminal=guest_type=='lxc' and not token
        self.assertTrue(proxy[1].endswith('/termproxy' if terminal else '/vncproxy'))
        if token:
            self.assertFalse(any('/access/ticket' in c[1] for c in client.calls))
            for call in client.calls:
                self.assertEqual(call[2]['headers'],{'Authorization':'PVEAPIToken=billing@pve!panel=test-secret'})
        else:
            self.assertEqual(proxy[2]['headers']['CSRFPreventionToken'],'csrf')
        self.assertEqual(browser.messages,[{'type':'lxc'}] if terminal else [{'type':'qemu','password':'vnc-password'}])
        self.assertNotIn('password',target)
    async def test_token_qemu(self): await self.check_transport('qemu',True)
    async def test_token_lxc(self): await self.check_transport('lxc',True)
    async def test_password_qemu(self): await self.check_transport('qemu',False)
    async def test_password_lxc(self): await self.check_transport('lxc',False)

if __name__=='__main__': unittest.main()
