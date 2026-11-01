"""
Speed check admin page + schema marker fallback. Usage: php -S 127.0.0.1:8080 -t . router.php   then   python3 tests/speed_check_test.py (TEST database)
"""
import re, subprocess, requests, os, glob, shutil
B = 'http://127.0.0.1:8080'
fails = 0
def sql(q): return subprocess.run(['mysql', '--socket=/run/mysqld/mysqld.sock', '-uroot', 'expenseflow_test', '-N', '-e', q], capture_output=True, text=True).stdout.strip()
def ok(c, m):
    global fails
    if not c: fails += 1; print('FAIL', m)
def token(h): return re.search(r'name="csrf_token" value="([^"]+)"', h).group(1)
h = subprocess.run(['php', '-r', 'echo password_hash("Test#12345",PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout
requests.get(B + '/auth/login.php')
sql("DELETE FROM users WHERE email LIKE '%@sp.local'"); sql("DELETE FROM rate_limit_hits")
sql(f"INSERT INTO users(name,email,password,role,is_admin) VALUES ('Sup','sup@sp.local','{h}','super',1),('Usr','usr@sp.local','{h}','user',0)")
def login(n):
    s = requests.Session()
    s.post(B + '/auth/login.php', data={'email': f'{n}@sp.local', 'password': 'Test#12345', 'csrf_token': token(s.get(B + '/auth/login.php').text)})
    return s
r = requests.get(B + '/admin/speed.php', allow_redirects=False)
ok(r.status_code in (302, 403), 'signed-out: not shown')
u = login('usr'); r = u.get(B + '/admin/speed.php', allow_redirects=False)
ok(r.status_code in (302, 403, 404) and 'Database round trip' not in r.text, 'normal user: not shown')
a = login('sup')
for p in ('/budgets.php', '/dashboard.php'): a.get(B + p)  # makes markers
r = a.get(B + '/admin/speed.php')
ok(r.status_code == 200 and 'Database round trip' in r.text and 'Schema markers' in r.text and 'Cold schema check per module' in r.text, 'super admin sees the page')
ok('personal_ensure_schema' in r.text and 'budgets_ensure_schema' in r.text, 'cold checks listed')
ok('Working (' in r.text, 'markers reported as working')
ok('speed.php' in a.get(B + '/admin/menu.php').text, 'linked from the admin menu')

# fallback: two marker folders, storage first and a temp folder second (for hosts where storage/ cannot be written)
out = subprocess.run(['php', '-r', "require 'includes/schema.php'; $d = ef_schema_dirs(); echo count($d), '|', basename($d[0]), '|', strpos($d[1], 'ef-schema-') !== false ? 'tmp' : 'no';"], capture_output=True, text=True).stdout
ok(out == '2|schema|tmp', 'two marker folders, storage first, temp second: ' + out)
sql("DELETE FROM users WHERE email LIKE '%@sp.local'")
print('speed check tests:', 'ALL PASSED' if not fails else f'{fails} FAILED')
raise SystemExit(1 if fails else 0)
