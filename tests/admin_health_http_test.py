"""Website health admin page (Part 99): access, live checks, ping history + throttle, manual check, page speed check.
Usage: PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8080 -t . router.php   then   python3 tests/admin_health_http_test.py
(The page speed check calls the site itself, so the test server needs more than one worker.)"""
import re, json, subprocess, requests
B = 'http://127.0.0.1:8080'
fails = 0
def sql(q, db='expenseflow_test'): return subprocess.run(['mysql', '--socket=/run/mysqld/mysqld.sock', '-uroot', db, '-N', '-r', '-e', q], capture_output=True, text=True).stdout.strip()
def ok(c, m):
    global fails
    print(('ok   ' if c else 'FAIL ') + m)
    if not c: fails += 1
h = subprocess.run(['php', '-r', 'echo password_hash("Test#12345",PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout
requests.get(B + '/auth/login.php')
sql("DELETE FROM users WHERE email LIKE '%@hl.local'"); sql("DELETE FROM rate_limit_hits")
for n, r in {'sup': 'super', 'u1': 'user'}.items():
    sql(f"INSERT INTO users(name,email,password,role,is_admin) VALUES ('{n}name','{n}@hl.local','{h}','{r}',{0 if r=='user' else 1})")
def login(n):
    s = requests.Session()
    t = re.search(r'name="csrf_token" value="([^"]+)"', s.get(B + '/auth/login.php').text).group(1)
    s.post(B + '/auth/login.php', data={'email': f'{n}@hl.local', 'password': 'Test#12345', 'csrf_token': t}, allow_redirects=False)
    return s
sup, u1 = login('sup'), login('u1')
P = '/admin/health.php'
ok(u1.get(B + P, allow_redirects=False).status_code == 403, 'plain user blocked')
ok(requests.get(B + P, allow_redirects=False).status_code in (302, 303), 'guest redirected')
r = sup.get(B + P); ok(r.status_code == 200, 'super admin opens the page')
ok('Live checks' in r.text and 'Database' in r.text and 'Disk space' in r.text and 'Storage folders' in r.text, 'live checks are listed')
ok('id="hl-state"' in r.text, 'overall state is shown')
ok('/health.php?db=1' in r.text, 'monitor address is shown')
ok('admin/health.php' in sup.get(B + '/admin/index.php').text, 'menu links to Website health')
# ping history: first ping is saved, a second one within 4 minutes is not
sql("DELETE FROM health_log")
requests.get(B + '/health.php?db=1'); requests.get(B + '/health.php?db=1')
ok(sql("SELECT COUNT(*) FROM health_log WHERE source='ping'") == '1', 'a monitor ping is saved once per 4 minutes')
r = sup.get(B + P)
ok('Outside monitor' in r.text and 'Last ping' in r.text, 'page reports the last ping')
ok(sql("SELECT COUNT(*) FROM health_log WHERE source='admin'") == '1', 'opening the page leaves one history row')
sup.get(B + P)
ok(sql("SELECT COUNT(*) FROM health_log WHERE source='admin'") == '1', 'reopening within 10 minutes does not add another')
def post(do):
    t = re.search(r'name="csrf_token" value="([^"]+)"', sup.get(B + P).text).group(1)
    return sup.post(B + '/admin/health-action.php', data={'do': do, 'csrf_token': t}, allow_redirects=False)
r = post('record'); ok(r.status_code in (302, 303), 'manual check redirects back')
ok(sql("SELECT COUNT(*) FROM health_log WHERE source='manual' AND kind='check'") == '1', 'manual check is saved')
r = post('probe'); ok(r.status_code in (302, 303), 'page speed check redirects back')
d = sql("SELECT detail FROM health_log WHERE kind='probe' ORDER BY id DESC LIMIT 1")
rows = json.loads(d or '[]')
ok(any(x['path'] == '/auth/login.php' and x['status'] == 200 for x in rows) and len(rows) == 5, 'page speed check timed five pages incl. login: ' + str([(x['path'], x['status'], x['ms']) for x in rows]))
page = sup.get(B + P).text
ok('/auth/login.php' in page and 'HTTP 200' in page, 'speed results are shown')
# no CSRF -> refused
r = sup.post(B + '/admin/health-action.php', data={'do': 'probe'}, allow_redirects=False)
ok(r.status_code in (400, 403), 'action without CSRF token is refused (got %s)' % r.status_code)
ok(u1.post(B + '/admin/health-action.php', data={'do': 'record'}, allow_redirects=False).status_code == 403, 'plain user cannot run actions')
# old rows are pruned only by age, never by the ping itself
sql("DELETE FROM users WHERE email LIKE '%@hl.local'"); sql("DELETE FROM health_log")
print('admin_health_http_test: ' + ('ALL PASSED' if not fails else f'{fails} FAILED'))
raise SystemExit(1 if fails else 0)
