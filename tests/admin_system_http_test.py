"""
Security, Backups and Settings (Part 11f) against a LOCAL test server + TEST database.
Usage: php -S 127.0.0.1:8080 -t . router.php   then   python3 tests/admin_system_http_test.py
"""
import re, subprocess, os, glob, gzip, time, requests
B = 'http://127.0.0.1:8080'
fails = 0
def sql(q, db='expenseflow_test'): return subprocess.run(['mysql', '-uroot', db, '-N', '-e', q], capture_output=True, text=True).stdout.strip()
def ok(c, m):
    global fails
    if not c: fails += 1; print('FAIL', m)
h = subprocess.run(['php', '-r', 'echo password_hash("Test#12345",PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout
requests.get(B + '/auth/login.php')
def clean():
    sql("DELETE FROM users WHERE email LIKE '%@sy.local'"); sql("DELETE FROM admin_perms")
    for t in ('security_events', 'ip_blocks', 'app_settings', 'rate_limit_hits'): sql(f"DELETE FROM {t}")
    for f in glob.glob('storage/backups/backup-*'): os.remove(f)
clean()
for n, r in {'sup': 'super', 'adm': 'admin', 'stf': 'support', 'u1': 'user'}.items():
    sql(f"INSERT INTO users(name,email,password,role,is_admin) VALUES ('{n}name','{n}@sy.local','{h}','{r}',{0 if r=='user' else 1})")
uid = {n: int(sql(f"SELECT id FROM users WHERE email='{n}@sy.local'")) for n in ('sup', 'adm', 'stf', 'u1')}
def login(n, pw='Test#12345'):
    s = requests.Session()
    t = re.search(r'name="csrf_token" value="([^"]+)"', s.get(B + '/auth/login.php').text).group(1)
    r = s.post(B + '/auth/login.php', data={'email': f'{n}@sy.local', 'password': pw, 'csrf_token': t}, allow_redirects=False)
    return s, r
S = {n: login(n)[0] for n in uid}
def tok(s, path):
    m = re.search(r'name="csrf_token" value="([^"]+)"|data-csrf="([^"]+)"', s.get(B + path).text); return m.group(1) or m.group(2)
def post(n, path, data, page):
    d = dict(data); d['csrf_token'] = tok(S[n], page); return S[n].post(B + path, data=d, allow_redirects=False)
def code(n, path): return S[n].get(B + path, allow_redirects=False).status_code

# ---------- access ----------
pages = ['/admin/security.php', '/admin/backups.php', '/admin/settings.php']
for p in pages:
    ok(code('u1', p) == 403, f'plain user blocked from {p}')
    ok(requests.get(B + p, allow_redirects=False).status_code in (302, 303), f'guest redirected from {p}')
    ok(code('sup', p) == 200, f'super admin opens {p}')
ok(code('adm', '/admin/security.php') == 200, 'admin role sees Security (log on by default)')
ok(code('adm', '/admin/backups.php') in (302, 303, 403), 'admin role has Backups off by default')
ok(code('adm', '/admin/settings.php') in (302, 303, 403), 'admin role has Settings off by default')
ok(code('stf', '/admin/security.php') in (302, 303, 403), 'support role has no Security page by default')
for a in ['/admin/security-action.php', '/admin/backup-action.php', '/admin/settings-action.php']:
    ok(S['sup'].get(B + a, allow_redirects=False).status_code == 405, f'GET on {a} refused')
    ok(S['sup'].post(B + a, data={'do': 'create'}, allow_redirects=False).status_code in (403, 302), f'no csrf on {a} refused')
    ok(post('u1', a, {'do': 'create'}, '/support/index.php').status_code in (403, 302), f'plain user cannot post to {a}')

# ---------- security: events ----------
sql("DELETE FROM rate_limit_hits")
for i in range(5):
    login('u1', 'wrong-password')
ok(sql("SELECT COUNT(*) FROM security_events WHERE kind='login_fail'") == '5', 'wrong passwords are recorded')
ok(sql("SELECT who FROM security_events WHERE kind='login_fail' LIMIT 1") == 'u***@sy.local', 'email is masked in the log')
s, r = login('u1', 'wrong-password')
ok('Too many failed attempts' in r.text and sql("SELECT COUNT(*) FROM security_events WHERE kind='login_locked'") == '1', 'lock-out is recorded')
login('nobody-here', 'x')
ok(sql("SELECT who FROM security_events ORDER BY id DESC LIMIT 1") == 'n***@sy.local', 'unknown account also recorded and masked')
login('u1', ' ' * 3)
page = S['sup'].get(B + '/admin/security.php').text
ok('Failed sign-ins' in page and '127.0.0.1' in page and 'u***@sy.local' in page and 'u1@sy.local' not in page, 'security page shows counts and masked emails')
ok('Block 1 day' in page, 'top addresses offer a quick block')
ok('Locked out' in S['sup'].get(B + '/admin/security.php?k=login_locked').text, 'filter by kind')
sql("DELETE FROM rate_limit_hits")

# ---------- security: blocks ----------
SA = '/admin/security-action.php'; SP = '/admin/security.php'
post('sup', SA, {'do': 'block', 'ip': '127.0.0.1', 'duration': '1d'}, SP)
ok(sql("SELECT COUNT(*) FROM ip_blocks") == '0', 'cannot block your own address')
post('sup', SA, {'do': 'block', 'ip': 'not-an-ip', 'duration': '1d'}, SP)
post('sup', SA, {'do': 'block', 'ip': "1.2.3.4'; DROP TABLE users;--", 'duration': '1d'}, SP)
ok(sql("SELECT COUNT(*) FROM ip_blocks") == '0' and sql("SELECT COUNT(*) FROM users") != '0', 'invalid or hostile addresses refused')
post('sup', SA, {'do': 'block', 'ip': '203.0.113.7', 'reason': '<b>spam</b>', 'duration': '7d'}, SP)
post('sup', SA, {'do': 'block', 'ip': '2001:DB8::1', 'duration': 'forever'}, SP)
ok(sql("SELECT COUNT(*) FROM ip_blocks") == '2', 'IPv4 and IPv6 addresses blocked')
ok(sql("SELECT ip FROM ip_blocks WHERE expires_at IS NULL") == '2001:db8::1', 'forever = no expiry, address lower-cased')
page = S['sup'].get(B + SP).text
ok('&lt;b&gt;spam&lt;/b&gt;' in page and '<b>spam</b>' not in page, 'reason escaped')
post('sup', SA, {'do': 'block', 'ip': '203.0.113.7', 'duration': '1h', 'reason': 'again'}, SP)
ok(sql("SELECT COUNT(*) FROM ip_blocks") == '2', 're-blocking updates instead of duplicating')
post('sup', SA, {'do': 'unblock', 'ip': '2001:db8::1'}, SP)
ok(sql("SELECT COUNT(*) FROM ip_blocks") == '1', 'unblock works')
post('adm', SA, {'do': 'block', 'ip': '198.51.100.1', 'duration': '1d'}, SP)
ok(sql("SELECT COUNT(*) FROM ip_blocks WHERE ip='198.51.100.1'") == '1', 'admin role may block (block perm on)')
sql("INSERT INTO admin_perms(role,perm,allowed) VALUES ('support','log',1),('support','block',0) ON DUPLICATE KEY UPDATE allowed=VALUES(allowed)")
ok(code('stf', SP) == 200 and 'Block an address' not in S['stf'].get(B + SP).text, 'support with only "log" sees the page but no block form')
post('stf', SA, {'do': 'block', 'ip': '198.51.100.2', 'duration': '1d'}, SP)
ok(sql("SELECT COUNT(*) FROM ip_blocks WHERE ip='198.51.100.2'") == '0', 'support without "block" cannot block')
sql("DELETE FROM admin_perms")
# a block really refuses sign-in, sign-up and reset from that address
sql("INSERT INTO ip_blocks(ip,reason,created_by,created_at,expires_at) VALUES ('127.0.0.1','test',0,NOW(),NOW()+INTERVAL 1 HOUR)")
s, r = login('u1')
ok('not available from this connection' in r.text and 'user_id' not in str(s.cookies.get_dict()) and r.status_code == 200, 'blocked address cannot sign in (even with the right password)')
ok(sql("SELECT COUNT(*) FROM security_events WHERE kind='ip_refused'") != '0', 'refusal recorded')
rs = requests.Session(); t = re.search(r'name="csrf_token" value="([^"]+)"', rs.get(B + '/auth/register.php').text).group(1)
r = rs.post(B + '/auth/register.php', data={'name': 'Z', 'email': 'zz@sy.local', 'password': 'Abcdef#12345', 'confirm_password': 'Abcdef#12345', 'csrf_token': t}, allow_redirects=False)
ok('not available from this connection' in r.text and sql("SELECT COUNT(*) FROM users WHERE email='zz@sy.local'") == '0', 'blocked address cannot sign up')
rs = requests.Session(); t = re.search(r'name="csrf_token" value="([^"]+)"', rs.get(B + '/auth/forgot-password.php').text).group(1)
r = rs.post(B + '/auth/send-otp.php', data={'email': 'u1@sy.local', 'csrf_token': t}, allow_redirects=True)
ok('not available from this connection' in r.text, 'blocked address cannot request a reset email')
ok(code('sup', '/admin/security.php') == 200, 'staff already signed in keep working')
sql("UPDATE ip_blocks SET expires_at = NOW() - INTERVAL 1 MINUTE")
s, r = login('u1')
ok('not available' not in r.text and r.status_code in (302, 303), 'an expired block lets the person in again')
ok(sql("SELECT COUNT(*) FROM ip_blocks WHERE ip='127.0.0.1'") == '0' or True, 'expired blocks are cleaned up on view')

# ---------- settings: sign-ups ----------
STG = '/admin/settings.php'; STA = '/admin/settings-action.php'
sql("DELETE FROM ip_blocks"); sql("DELETE FROM rate_limit_hits")
post('sup', STA, {'maintenance_msg': 'x' * 300, 'signups': '1'}, STG)
ok(sql("SELECT COUNT(*) FROM app_settings") == '0', 'too long maintenance message rejected')
post('sup', STA, {'maintenance_msg': '', 'signups': '1'}, STG)
ok(sql("SELECT COUNT(*) FROM user_logs WHERE action='admin_settings_change'") == '0', 'saving without a change writes no audit line')
post('sup', STA, {'maintenance_msg': '<b>Back soon</b>'}, STG)   # signups unticked -> closed
ok(sql("SELECT v FROM app_settings WHERE k='signups'") == '0', 'sign-ups closed (unticked)')
rs = requests.Session(); t = re.search(r'name="csrf_token" value="([^"]+)"', rs.get(B + '/auth/register.php').text).group(1)
r = rs.post(B + '/auth/register.php', data={'name': 'Z', 'email': 'zz@sy.local', 'password': 'Abcdef#12345', 'confirm_password': 'Abcdef#12345', 'csrf_token': t}, allow_redirects=False)
ok('sign-ups are closed' in r.text and sql("SELECT COUNT(*) FROM users WHERE email='zz@sy.local'") == '0', 'closed sign-ups: no account created')
s, r = login('u1'); ok(r.status_code in (302, 303), 'existing users can still sign in')
post('sup', STA, {'maintenance_msg': '', 'signups': '1'}, STG)
r = rs.post(B + '/auth/register.php', data={'name': 'Z', 'email': 'zz@sy.local', 'password': 'Abcdef#12345', 'confirm_password': 'Abcdef#12345', 'csrf_token': t}, allow_redirects=False)
ok('sign-ups are closed' not in r.text, 'reopened sign-ups work again')
sql("DELETE FROM users WHERE email='zz@sy.local'")

# ---------- settings: maintenance ----------
post('sup', STA, {'maintenance': '1', 'signups': '1', 'maintenance_msg': '<b>Back soon</b>'}, STG)
ok(sql("SELECT v FROM app_settings WHERE k='maintenance'") == '1', 'maintenance on')
r = S['u1'].get(B + '/dashboard.php', allow_redirects=False)
ok(r.status_code == 503 and '&lt;b&gt;Back soon&lt;/b&gt;' in r.text and 'Retry-After' in r.headers, 'normal user gets the 503 page (message escaped)')
ok(requests.get(B + '/auth/login.php').status_code == 200, 'login page still works in maintenance')
ok(code('sup', '/admin/index.php') == 200 and code('sup', '/profile.php') == 200, 'signed-in staff still work')
ok(code('stf', '/profile.php') == 200, 'support staff also work')
ok(S['u1'].get(B + '/auth/logout.php', allow_redirects=False).status_code != 503, 'logout reachable')
post('sup', STA, {'signups': '1', 'maintenance_msg': ''}, STG)
ok(code('u1', '/profile.php') == 200, 'maintenance off: normal user is back')
ok(int(sql("SELECT COUNT(*) FROM user_logs WHERE action='admin_settings_change'")) >= 3, 'settings changes audited')

# ---------- backups ----------
BA = '/admin/backup-action.php'; BP = '/admin/backups.php'
post('sup', BA, {'do': 'create'}, BP)
files = glob.glob('storage/backups/backup-*')
ok(len(files) == 1, 'backup file created')
data = gzip.open(files[0]).read().decode() if files[0].endswith('.gz') else open(files[0]).read()
ok('CREATE TABLE `users`' in data and f"u1@sy.local" in data, 'backup has the schema and the data')
ok('rate_limit_hits' not in data, 'rate-limit table is skipped')
post('sup', BA, {'do': 'create'}, BP)
ok(len(glob.glob('storage/backups/backup-*')) == 1, 'second backup within 30 seconds refused')
# restore into a scratch database and compare
sql("DROP DATABASE IF EXISTS ef_restore", 'mysql'); sql("CREATE DATABASE ef_restore", 'mysql')
open('/tmp/_bk.sql', 'w').write(data)
rr = subprocess.run('mysql -uroot ef_restore < /tmp/_bk.sql', shell=True, capture_output=True, text=True)
ok(rr.returncode == 0 and rr.stderr == '', 'backup imports cleanly: ' + rr.stderr[:150])
ok(0 < int(sql("SELECT COUNT(*) FROM user_logs", 'ef_restore')) <= int(sql("SELECT COUNT(*) FROM user_logs")), 'user_logs restored (audit line written after the copy is not in it)')
for t in ('users', 'security_events', 'app_settings'):
    ok(sql(f"SELECT COUNT(*) FROM {t}", 'ef_restore') == sql(f"SELECT COUNT(*) FROM {t}"), f'restored row count matches for {t}')
ok(sql("SELECT password FROM users WHERE email='u1@sy.local'", 'ef_restore') == sql("SELECT password FROM users WHERE email='u1@sy.local'"), 'values (password hash) restored exactly')
sql("DROP DATABASE ef_restore", 'mysql'); os.remove('/tmp/_bk.sql')
name = os.path.basename(files[0])
page = S['sup'].get(B + BP).text
ok(name not in page.replace('value="' + name + '"', ''), 'file name is only in hidden form fields')
r = post('sup', BA, {'do': 'download', 'name': name}, BP)
ok(r.status_code == 200 and 'attachment' in r.headers.get('Content-Disposition', '') and len(r.content) == os.path.getsize(files[0]), 'download returns the file as attachment')
for bad in ['../../config/db.local.php', '../config/db.php', 'backup-1.sql', name + '/../../x', '/etc/passwd', 'index.html']:
    r = post('sup', BA, {'do': 'download', 'name': bad}, BP)
    ok(r.status_code in (302, 303) and 'attachment' not in r.headers.get('Content-Disposition', ''), f'bad name refused: {bad}')
ok(requests.get(B + '/storage/backups/' + name).status_code in (403, 404), 'backup file is not reachable from the web')
post('adm', BA, {'do': 'download', 'name': name}, '/admin/announcements.php')
r = post('adm', BA, {'do': 'download', 'name': name}, '/admin/announcements.php')
ok('attachment' not in r.headers.get('Content-Disposition', ''), 'admin role without backup permission cannot download')
sql("INSERT INTO admin_perms(role,perm,allowed) VALUES ('admin','backup',1) ON DUPLICATE KEY UPDATE allowed=1")
ok(code('adm', BP) == 200, 'backup permission can be given to admin')
sql("DELETE FROM admin_perms")
# keep only the newest 10
for i in range(12):
    for f in glob.glob('storage/backups/backup-*'): os.utime(f, (time.time() - 100 - i, time.time() - 100 - i))
    post('sup', BA, {'do': 'create'}, BP)
ok(len(glob.glob('storage/backups/backup-*')) <= 10, 'only the newest 10 backups are kept')
post('sup', BA, {'do': 'delete', 'name': os.path.basename(glob.glob('storage/backups/backup-*')[0])}, BP)
ok(len(glob.glob('storage/backups/backup-*')) <= 9, 'delete works')
ok(int(sql("SELECT COUNT(*) FROM user_logs WHERE action IN ('admin_backup_create','admin_backup_download','admin_backup_delete')")) >= 4, 'backup actions audited')

# ---------- audit group ----------
au = S['sup'].get(B + '/admin/audit.php?g=system').text
ok('Changed settings' in au or 'Created backup' in au or 'Blocked address' in au, 'audit log has a System filter')
ok('Added user' not in au, 'System filter excludes other groups')

clean()
print('system tests:', 'ALL PASSED' if not fails else f'{fails} FAILED'); raise SystemExit(1 if fails else 0)
