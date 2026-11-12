"""Off-site backup to a private GitHub repo (Part 104), against a fake GitHub API on :8093 and an own PHP server on :8092.
Checks: validation, public repo refused, bad token explained, encrypted upload that decrypts back, no plain SQL leaves the server,
auto upload from the admin overview, trimming to the newest 30, switch-off removes the token, attention rows.
Usage: python3 tests/offsite_backup_test.py   (needs mysqld with the expenseflow_test database)"""
import re, os, json, time, base64, threading, subprocess, requests
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
B = 'http://127.0.0.1:8092'
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CFG = ROOT + '/storage/offsite.json'
fails = 0
def sql(q): return subprocess.run(['mysql', '--socket=/run/mysqld/mysqld.sock', '-uroot', 'expenseflow_test', '-N', '-r', '-e', q], capture_output=True, text=True).stdout.strip()
def ok(c, m):
    global fails
    print(('ok   ' if c else 'FAIL ') + m)
    if not c: fails += 1

FILES = {}      # path -> base64 content
CALLS = []
STATE = {'private': True, 'token': 'good-token'}
class H(BaseHTTPRequestHandler):
    def log_message(self, *a): pass
    def _send(self, code, obj):
        b = json.dumps(obj).encode(); self.send_response(code); self.send_header('Content-Type', 'application/json'); self.send_header('Content-Length', str(len(b))); self.end_headers(); self.wfile.write(b)
    def _auth(self):
        if self.headers.get('Authorization') != 'Bearer ' + STATE['token']:
            self._send(401, {'message': 'Bad credentials'}); return False
        return True
    def do_GET(self):
        CALLS.append(('GET', self.path))
        if not self._auth(): return
        if self.path == '/repos/me/backups':
            return self._send(200, {'full_name': 'me/backups', 'private': STATE['private']})
        if self.path == '/repos/me/backups/contents/backups':
            return self._send(200, [{'name': p.split('/')[-1], 'path': p, 'sha': 'sha-' + p} for p in FILES])
        self._send(404, {'message': 'Not Found'})
    def do_PUT(self):
        n = int(self.headers.get('Content-Length', 0)); d = json.loads(self.rfile.read(n) or b'{}')
        CALLS.append(('PUT', self.path))
        if not self._auth(): return
        pre = '/repos/me/backups/contents/'
        if not self.path.startswith(pre): return self._send(404, {})
        p = self.path[len(pre):]
        if p in FILES: return self._send(422, {'message': 'sha wasn\'t supplied'})
        FILES[p] = d['content']; self._send(201, {'content': {'path': p}})
    def do_DELETE(self):
        n = int(self.headers.get('Content-Length', 0)); json.loads(self.rfile.read(n) or b'{}')
        CALLS.append(('DELETE', self.path))
        if not self._auth(): return
        p = self.path[len('/repos/me/backups/contents/'):]
        FILES.pop(p, None); self._send(200, {})
gh = ThreadingHTTPServer(('127.0.0.1', 8093), H)
threading.Thread(target=gh.serve_forever, daemon=True).start()

env = dict(os.environ, EXPENSEFLOW_2FA_OFF='1', PHP_CLI_SERVER_WORKERS='4', EXPENSEFLOW_GITHUB_API='http://127.0.0.1:8093')
srv = subprocess.Popen(['php', '-S', '127.0.0.1:8092', '-t', ROOT, os.path.join(ROOT, 'router.php')], cwd=ROOT, env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
if os.path.exists(CFG): os.remove(CFG)
try:
    for _ in range(40):
        try: requests.get(B + '/health.php', timeout=1); break
        except Exception: time.sleep(0.25)
    h = subprocess.run(['php', '-r', 'echo password_hash("Test#12345",PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout
    sql("DELETE FROM users WHERE email LIKE '%@off.local'"); sql("DELETE FROM rate_limit_hits")
    sql(f"INSERT INTO users(name,email,password,role,is_admin) VALUES ('off','sup@off.local','{h}','super',1)")
    s = requests.Session()
    t = re.search(r'name="csrf_token" value="([^"]+)"', s.get(B + '/auth/login.php').text).group(1)
    s.post(B + '/auth/login.php', data={'email': 'sup@off.local', 'password': 'Test#12345', 'csrf_token': t}, allow_redirects=False)
    def csrf(): return re.search(r'name="csrf_token" value="([^"]+)"', s.get(B + '/admin/backups.php').text).group(1)
    def act(do, **kw):
        return s.post(B + '/admin/offsite-action.php', data=dict(do=do, csrf_token=csrf(), **kw), allow_redirects=True).text

    r = s.get(B + '/admin/backups.php')
    ok('Off-site copy (GitHub)' in r.text and 'Switched off.' in r.text, 'card shows, switched off by default')
    ok('Backups are only kept on this server' in s.get(B + '/admin/index.php').text, 'admin home tells that backups are only on this server')

    ok('owner/name' in act('save', repo='nonsense', token='x', **{'pass': 'longpassphrase'}), 'bad repository name is refused')
    ok('did not accept the token' in act('save', repo='me/backups', token='wrong', **{'pass': 'longpassphrase'}), 'wrong token is explained in plain words')
    ok(not os.path.exists(CFG) or not json.load(open(CFG)).get('on'), 'nothing is switched on after a failed test')
    ok('at least 8 characters' in act('save', repo='me/backups', token='good-token', **{'pass': 'short'}), 'short passphrase is refused')
    STATE['private'] = False
    ok('public' in act('save', repo='me/backups', token='good-token', **{'pass': 'longpassphrase'}), 'a PUBLIC repository is refused')
    STATE['private'] = True
    t2 = act('save', repo='https://github.com/me/backups.git', token='good-token', **{'pass': 'longpassphrase'})
    ok('Off-site backup is on' in t2, 'a pasted GitHub address is accepted and saved')
    cfg = json.load(open(CFG))
    ok(cfg['on'] and cfg['repo'] == 'me/backups' and cfg['token'] == 'good-token', 'settings are stored')
    ok(oct(os.stat(CFG).st_mode & 0o777) == '0o600', 'settings file is private (0600)')
    page = s.get(B + '/admin/backups.php').text
    ok('good-token' not in page and 'longpassphrase' not in page, 'token and passphrase are never shown again')
    ok(requests.get(B + '/storage/offsite.json').status_code in (403, 404), 'settings file is not downloadable')

    # manual push of a freshly made backup
    s.post(B + '/admin/backup-action.php', data={'do': 'create', 'csrf_token': csrf()})
    out = act('push')
    ok('Uploaded backup-' in out and len(FILES) == 1, 'send now uploads one file')
    path, content = list(FILES.items())[0]
    blob = base64.b64decode(content)
    ok(path.startswith('backups/backup-') and path.endswith('.enc') and blob.startswith(b'EFBK1'), 'the file in the repository is encrypted (EFBK1)')
    ok(b'CREATE TABLE' not in blob and b'INSERT INTO' not in blob, 'no readable SQL leaves the server')
    plain = subprocess.run(['php', '-r', 'require "%s/modules/backup/lib.php"; $b=file_get_contents("php://stdin"); $p=backup_decrypt($b,"longpassphrase"); if($p!==null && substr($p,0,2)=="\\x1f\\x8b")$p=gzdecode($p); echo $p===null?"NULL":(str_contains($p,"CREATE TABLE")?"SQLOK":"OTHER");' % ROOT], input=blob, capture_output=True).stdout.decode()
    ok(plain == 'SQLOK', 'it decrypts back to a real SQL dump with the passphrase')
    wrong = subprocess.run(['php', '-r', 'require "%s/modules/backup/lib.php"; echo backup_decrypt(file_get_contents("php://stdin"),"wrong-pass-123")===null?"NULL":"LEAK";' % ROOT], input=blob, capture_output=True).stdout.decode()
    ok(wrong == 'NULL', 'a wrong passphrase cannot open it')
    ok('Working.' in s.get(B + '/admin/backups.php').text, 'card shows Working after the upload')

    # automatic upload from the admin overview when a newer backup exists
    FILES.clear()
    d = ROOT + '/storage/backups'
    for f in os.listdir(d):
        if f.startswith('backup-'): os.remove(d + '/' + f)
    s.get(B + '/admin/index.php')   # creates the daily backup, then uploads it
    ok(len(FILES) == 1, 'opening the admin overview makes the daily backup and uploads it by itself')
    n = len(CALLS); s.get(B + '/admin/index.php')
    ok(not [c for c in CALLS[n:] if c[0] == 'PUT'], 'the same backup is not uploaded twice')

    # trimming
    for i in range(33): FILES['backups/backup-20200101-%06d-aaaaaaaa.enc' % i] = 'x'
    act('push')   # same newest name -> 422 treated as already there, trimming still runs only after success
    s.post(B + '/admin/backup-action.php', data={'do': 'create', 'csrf_token': csrf()}); time.sleep(31)
    s.post(B + '/admin/backup-action.php', data={'do': 'create', 'csrf_token': csrf()})
    act('push')
    ok(len(FILES) <= 30, 'only the newest 30 copies stay in the repository (%d)' % len(FILES))

    # failures are remembered and shown
    STATE['token'] = 'rotated'
    out = act('push')
    ok('did not accept the token' in out, 'a failed upload shows a plain reason')
    ok('The off-site backup is not working' in s.get(B + '/admin/index.php').text, 'admin home warns that the off-site backup is not working')

    # switch off
    out = act('off')
    cfg = json.load(open(CFG))
    ok(not cfg['on'] and cfg['token'] == '' and cfg['pass'] == '', 'switch off removes the token and passphrase from the server')
    ok(sql("SELECT COUNT(*) FROM user_logs WHERE action='admin_backup_offsite'") != '0', 'changes are written to the audit log')
    ok(requests.post(B + '/admin/offsite-action.php', data={'do': 'off'}, allow_redirects=False).status_code in (302, 401, 403), 'not allowed without signing in')
    sql("DELETE FROM users WHERE email LIKE '%@off.local'")
finally:
    srv.terminate(); gh.shutdown()
    if os.path.exists(CFG): os.remove(CFG)
print('offsite_backup_test: ' + ('ALL PASSED' if not fails else f'{fails} FAILED'))
raise SystemExit(1 if fails else 0)
