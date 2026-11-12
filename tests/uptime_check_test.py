"""Uptime probe (Part 105): classification of the website's answers, retries, and a valid workflow file.
Usage: python3 tests/uptime_check_test.py   (no database or PHP needed)"""
import os, sys, json, time, threading, importlib.util
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
spec = importlib.util.spec_from_file_location('uptime_check', ROOT + '/.github/scripts/uptime_check.py')
uc = importlib.util.module_from_spec(spec); spec.loader.exec_module(uc)
fails = 0
def ok(c, m):
    global fails
    print(('ok   ' if c else 'FAIL ') + m)
    if not c: fails += 1

MODE = {'v': 'ok'}; HITS = {'n': 0}
class H(BaseHTTPRequestHandler):
    def log_message(self, *a): pass
    def do_GET(self):
        HITS['n'] += 1
        m = MODE['v']
        if m == 'flaky' and HITS['n'] < 2: m = '500'
        body, code = b'{}', 200
        if m in ('ok', 'flaky'): body = json.dumps({'ok': True, 'db': True}).encode()
        elif m == 'dbdown': body = json.dumps({'ok': True, 'db': False}).encode()
        elif m == '500': code, body = 500, b'oops'
        elif m == '403': code, body = 403, b'no'
        elif m == 'challenge': body = b'<html><script src="/aes.js"></script>This site requires Javascript</html>'
        elif m == 'suspended': body = b'<html><h1>Account Suspended</h1></html>'
        elif m == 'slow': time.sleep(3)
        try:
            self.send_response(code); self.send_header('Content-Length', str(len(body))); self.end_headers(); self.wfile.write(body)
        except (BrokenPipeError, ConnectionResetError):
            pass
srv = ThreadingHTTPServer(('127.0.0.1', 8094), H); threading.Thread(target=srv.serve_forever, daemon=True).start()
U = 'http://127.0.0.1:8094'
def run(mode, **kw):
    MODE['v'] = mode; HITS['n'] = 0
    return uc.check(U, pause=0, sleep=lambda s: None, **kw)

r = run('ok');          ok(r['state'] == 'up' and r['code'] == 200 and HITS['n'] == 1, 'healthy site is UP after one try')
r = run('dbdown');      ok(r['state'] == 'down' and 'database' in r['detail'] and HITS['n'] == 3, 'database down is DOWN (3 tries)')
r = run('500');         ok(r['state'] == 'down' and '500' in r['detail'], 'HTTP 500 is DOWN with the code in the text')
r = run('403');         ok(r['state'] == 'down', 'HTTP 403 is DOWN')
r = run('challenge');   ok(r['state'] == 'up' and 'bot check' in r['detail'], "the host's own bot-check page still counts as UP")
r = run('suspended');   ok(r['state'] == 'down' and 'suspended' in r['detail'], 'a "suspended" page is DOWN')
r = run('flaky');       ok(r['state'] == 'up' and HITS['n'] == 2, 'one failed try followed by success is UP (blip ignored)')
r = run('slow', timeout=1); ok(r['state'] == 'down' and 'No answer' in r['detail'], 'a timeout is DOWN')
srv.shutdown()
r = uc.check('http://127.0.0.1:9', attempts=2, timeout=2, pause=0, sleep=lambda s: None)
ok(r['state'] == 'down' and 'No answer' in r['detail'] and r['code'] is None, 'connection refused is DOWN')
ok(r['checked_at'].endswith('Z') and r['url'] == 'http://127.0.0.1:9', 'result carries time (UTC) and address')

import subprocess
out = subprocess.run([sys.executable, ROOT + '/.github/scripts/uptime_check.py'], capture_output=True, text=True)
ok(out.returncode != 0, 'script without an address stops with a usage message')
try:
    import yaml
    w = yaml.safe_load(open(ROOT + '/.github/workflows/uptime.yml'))
    ok('schedule' in w[True] and w['permissions'] == {'contents': 'write', 'issues': 'write'}, 'workflow is valid YAML with a schedule and only the permissions it needs')
    ok(w['jobs']['check']['if'].startswith('github.repository'), 'workflow does not run on forks')
except ImportError:
    print('skip workflow YAML check (PyYAML missing)')
page = open(ROOT + '/.github/status-page/index.html').read()
ok('status.json' in page and 'prefers-color-scheme:dark' in page and 'viewport' in page, 'status page reads status.json, has dark mode and a phone viewport')
print('uptime_check_test: ' + ('ALL PASSED' if not fails else f'{fails} FAILED'))
raise SystemExit(1 if fails else 0)
