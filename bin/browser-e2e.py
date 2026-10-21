#!/usr/bin/env python3
"""Browser checks for bin/e2e.sh: the reporting app in headless Chrome, as each kind of user.

Usage: browser-e2e.py <site> <reports page id> <calls page id> <all-areas.json> <area-1001.json> <webservice host:port>

The JSON files are the fake backend's oracle (fake-yap expected) for the last 30 days. Uses only the standard
library: Chrome is driven over the DevTools protocol through a minimal WebSocket client.
"""
import base64, json, os, socket, struct, subprocess, sys, tempfile, time, urllib.request
from urllib.parse import urlparse

SITE, REPORTS, CALLS, ALL_JSON, AREA_JSON, SERVICE = sys.argv[1:7]
ALL = json.load(open(ALL_JSON))
AREA = json.load(open(AREA_JSON))
PORT = int(os.environ.get('CHROME_DEBUG_PORT', '9334'))


class WS:
    def __init__(self, url):
        u = urlparse(url)
        self.s = socket.create_connection((u.hostname, u.port))
        key = base64.b64encode(os.urandom(16)).decode()
        self.s.sendall((f'GET {u.path} HTTP/1.1\r\nHost: {u.hostname}:{u.port}\r\nUpgrade: websocket\r\n'
                        f'Connection: Upgrade\r\nSec-WebSocket-Key: {key}\r\nSec-WebSocket-Version: 13\r\n\r\n').encode())
        head = b''
        while b'\r\n\r\n' not in head:
            head += self.s.recv(1)
        assert b' 101 ' in head.split(b'\r\n')[0], head

    def _read(self, n):
        out = b''
        while len(out) < n:
            chunk = self.s.recv(n - len(out))
            if not chunk:
                raise ConnectionError('closed')
            out += chunk
        return out

    def send(self, text):
        data = text.encode()
        mask = os.urandom(4)
        header = bytes([0x81])
        n = len(data)
        if n < 126:
            header += bytes([0x80 | n])
        elif n < 65536:
            header += bytes([0x80 | 126]) + struct.pack('>H', n)
        else:
            header += bytes([0x80 | 127]) + struct.pack('>Q', n)
        self.s.sendall(header + mask + bytes(b ^ mask[i % 4] for i, b in enumerate(data)))

    def recv(self):
        message = b''
        while True:
            b1, b2 = self._read(2)
            n = b2 & 0x7f
            if n == 126:
                n = struct.unpack('>H', self._read(2))[0]
            elif n == 127:
                n = struct.unpack('>Q', self._read(8))[0]
            payload = self._read(n)
            if (b1 & 0x0f) == 9:
                continue
            message += payload
            if b1 & 0x80:
                return message.decode()


class Browser:
    """Headless Chrome with a fresh profile; collects console errors, exceptions and every request URL."""

    def __init__(self):
        self.profile = tempfile.mkdtemp(prefix='yapr-chrome-')
        self.proc = subprocess.Popen([os.environ.get('CHROME', 'google-chrome'), '--headless=new', f'--remote-debugging-port={PORT}',
                                      f'--user-data-dir={self.profile}', '--no-first-run', '--no-default-browser-check', '--disable-gpu',
                                      '--lang=en-US', 'about:blank'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        pages = []
        for i in range(180):
            try:
                pages = [t for t in json.load(urllib.request.urlopen(f'http://127.0.0.1:{PORT}/json/list', timeout=5)) if t['type'] == 'page']
                if pages:
                    break
                if i >= 8:
                    urllib.request.urlopen(urllib.request.Request(f'http://127.0.0.1:{PORT}/json/new?about:blank', method='PUT'), timeout=5).read()
            except Exception:
                pass
            time.sleep(0.25)
        if not pages:
            self.close()
            sys.exit('Chrome did not start')
        self.ws = WS(pages[0]['webSocketDebuggerUrl'])
        self.n = 0
        self.errors, self.requests = [], []
        for domain in ('Page', 'Runtime', 'Network', 'Log'):
            self.call(domain + '.enable')

    def _event(self, msg):
        m, p = msg.get('method'), msg.get('params', {})
        if m == 'Runtime.exceptionThrown':
            self.errors.append('exception: ' + p['exceptionDetails'].get('text', '') + ' ' + json.dumps(p['exceptionDetails'].get('exception', {}).get('description', ''))[:300])
        elif m == 'Runtime.consoleAPICalled' and p.get('type') in ('error', 'assert'):
            self.errors.append('console: ' + ' '.join(str(a.get('value', a.get('description', ''))) for a in p.get('args', [])))
        elif m == 'Log.entryAdded' and p['entry'].get('level') == 'error' and 'favicon' not in p['entry'].get('url', ''):
            self.errors.append('log: ' + p['entry'].get('text', '') + ' ' + p['entry'].get('url', ''))
        elif m == 'Network.requestWillBeSent':
            self.requests.append(p['request']['url'])

    def call(self, method, **params):
        self.n += 1
        self.ws.send(json.dumps({'id': self.n, 'method': method, 'params': params}))
        while True:
            msg = json.loads(self.ws.recv())
            if msg.get('id') == self.n:
                if 'error' in msg:
                    raise RuntimeError(f'{method}: {msg["error"]}')
                return msg.get('result', {})
            self._event(msg)

    def js(self, expression):
        r = self.call('Runtime.evaluate', expression=expression, returnByValue=True, awaitPromise=True)
        return r.get('result', {}).get('value')

    def wait(self, check, timeout=30):
        end = time.time() + timeout
        while time.time() < end:
            try:
                if self.js(check):
                    return
            except Exception:
                pass
            time.sleep(0.25)
        raise TimeoutError(check + '\n' + (self.js('document.body ? document.body.innerText.slice(0, 1500) : ""') or ''))

    def go(self, url):
        self.call('Page.navigate', url=url)
        time.sleep(0.3)
        self.wait('document.readyState === "complete"')

    def login(self, user, password):
        self.call('Network.clearBrowserCookies')
        self.go(SITE + '/wp-login.php')
        self.wait('!!document.getElementById("wp-submit")')
        self.js(f'document.getElementById("user_login").value={json.dumps(user)};'
                f'document.getElementById("user_pass").value={json.dumps(password)};'
                'document.getElementById("wp-submit").click(); true')
        time.sleep(0.5)
        self.wait('document.readyState === "complete" && !location.href.includes("wp-login.php")')

    def close(self):
        try:
            self.proc.terminate()
            self.proc.wait(10)
        except Exception:
            pass
        subprocess.run(['rm', '-rf', self.profile])


def ok(text):
    print('  ok   ' + text)


def fail(text):
    print('  FAIL ' + text)
    sys.exit(1)


def number(text):
    t = (text or '').replace(',', '').replace('%', '').strip()
    return float(t) if t not in ('', '—') else None


def kpis(b):
    return b.js('Object.fromEntries([...document.querySelectorAll(".yapr-kpi")].map(k => [[...k.classList].find(c => c.startsWith("yapr-kpi-") && c !== "yapr-kpi").slice(9), k.querySelector(".yapr-kpi-value").innerText]))')


def compare(shown, expected, keys, label):
    for k in keys:
        if k not in shown:
            fail(f'{label}: no {k} card')
        if number(shown[k]) != (float(expected[k]) if expected[k] is not None else None):
            fail(f'{label}: {k} shows {shown[k]!r}, the scenario says {expected[k]}')


KPI_KEYS = ['total_calls', 'volunteer_routed_calls', 'answered_calls', 'answer_rate', 'volunteer_calls_to_voicemail',
            'meeting_lookup_calls', 'sms_meeting_lookups', 'sms_volunteer_requests', 'jft_calls', 'jft_sms', 'spad_calls', 'spad_sms']
REPORT_READY = '!!document.querySelector(".yapr-kpi-total_calls .yapr-kpi-value") && !document.querySelector(".yapr-app .yapr-loading")'

b = Browser()
try:
    b.login('phoneline', 'Phone-pass-1')
    b.go(f'{SITE}/?page_id={REPORTS}')
    b.wait(REPORT_READY)
    compare(kpis(b), ALL, KPI_KEYS, 'All my Areas')
    ok('Phoneline Administrator, All my Areas: every KPI matches the scenario, region-wide activity included')
    charts = b.js('document.querySelectorAll(".yapr-app svg").length')
    if not charts or charts < 3:
        fail(f'only {charts} charts drawn')
    ok(f'{charts} charts drawn')

    # Choose one Area in the app's own Area selector; wait until the figures shown are replaced by the new ones.
    b.js('window.__yaprOld = document.querySelector(".yapr-kpi-total_calls"); true')
    b.js('(() => { const s = [...document.querySelectorAll(".yapr-app select")].find(s => [...s.options].some(o => o.value === "1001")); s.value = "1001"; s.dispatchEvent(new Event("change")); return true; })()')
    b.wait('!document.body.contains(window.__yaprOld) && ' + REPORT_READY)
    if b.js('!!document.querySelector(".yapr-app .yapr-error, .yapr-app [role=alert].yapr-error")'):
        fail('the report for one Area failed: ' + b.js('document.querySelector(".yapr-app").innerText.slice(0, 300)'))
    compare(kpis(b), AREA, ['total_calls', 'volunteer_routed_calls', 'answered_calls', 'answer_rate'], 'Riverside Area')
    ok('Area selector: Riverside Area matches the scenario')

    b.go(f'{SITE}/?page_id={CALLS}')
    b.wait('document.querySelectorAll("table.yapr-calls tbody tr").length > 0')
    pager = b.js('document.querySelector(".yapr-pager, .yapr-app [class*=pager]") ? document.querySelector(".yapr-pager, .yapr-app [class*=pager]").innerText : document.body.innerText')
    total = int(pager.split('(')[-1].split(')')[0].replace(',', '')) if '(' in pager else -1
    if total != ALL['total_calls']:
        fail(f'call list total {total}, the scenario says {ALL["total_calls"]} ({pager[:200]!r})')
    ok(f'call list: {total} calls, as in the scenario')
    text = b.js('document.body.innerText')
    for bad in ('5555550', '555-01', 'CA0', 'yapr_'):
        if bad in text:
            fail(f'{bad!r} appears on the call list')
    b.js('[...document.querySelectorAll(".yapr-tab")].find(t => t.innerText.includes("Volunteer")).click(); true')
    b.wait('document.body.innerText.includes("Alex Riverside") && document.body.innerText.includes("Times dialed")')
    ok('call list shows no numbers or ids; the Volunteers tab names volunteers from YAP\'s lists')

    leaked = [u for u in b.requests if SERVICE in u]
    if leaked:
        fail('the browser contacted the webservice directly: ' + leaked[0])
    ok('the browser only talks to WordPress (the credential never leaves the server)')

    b.login('reporter', 'Report-pass-1')
    b.go(f'{SITE}/?page_id={REPORTS}')
    b.wait(REPORT_READY)
    compare(kpis(b), AREA, ['total_calls', 'answered_calls', 'answer_rate'], 'Area Reporter')
    choices = b.js('[...document.querySelectorAll(".yapr-app select option")].map(o => o.innerText).join("|")')
    if 'Hillcrest' in (choices or ''):
        fail('an Area Reporter for Riverside can choose Hillcrest')
    ok('Area Reporter sees Riverside only, with the right figures')
    b.go(f'{SITE}/?page_id={CALLS}')
    b.wait('document.body.innerText.includes("permission")')
    ok('Area Reporter is told the call list needs more permission')

    if b.errors:
        fail('browser errors: ' + '; '.join(b.errors[:5]))
    ok('no JavaScript errors or failed requests')
finally:
    b.close()
