"""
Part 61: Safe Browsing trust fixes. LOCAL server + TEST database. Usage: python3 tests/trust_test.py
Checks: .htaccess redirect is guarded, landing page has no install-bypass wording, the Android app skips the warning page only for its own host,
Force HTTPS switch (refuses on plain http), tools/switch-domain.php (dry run, apply, bad input, closed to the web).
"""
import os, re, shutil, subprocess, tempfile, requests
B = 'http://127.0.0.1:8080'
fails = 0
def sql(q): return subprocess.run(['mysql', '--socket=/run/mysqld/mysqld.sock', '-uroot', 'expenseflow_test', '-N', '-e', q], capture_output=True, text=True).stdout.strip()
def ok(c, m):
    global fails
    if not c: fails += 1; print('FAIL', m)
ht = open('.htaccess').read()
ok('storage/force-https.on -f' in ht and 'X-Forwarded-Proto} !https' in ht and 'RewriteRule ^(.*)$ https://%{HTTP_HOST}/$1 [R=301,L]' in ht, '.htaccess redirect is switchable, loop-guarded')
ok(re.search(r'^# RewriteRule', ht, re.M) is None, 'no commented-out redirect left')
ok('Strict-Transport-Security' in ht, 'HSTS header present')
land = requests.get(B + '/').text
ok('allow installing from this source' not in land and 'protected your PC' not in land and 'Run anyway' not in land, 'landing page has no install-bypass instructions')
ok('GitHub releases' in land, 'landing page points to the releases page')
jv = open('apps/android/app/src/main/java/pk/expenseflow/app/MainActivity.java').read()
ok('onSafeBrowsingHit' in jv and 'HOST.equals(r.getUrl().getHost())) cb.proceed(false)' in jv and 'cb.backToSafety(true)' in jv, 'Android skips the warning only for its own host')
# Force HTTPS helpers
php = lambda code: subprocess.run(['php', '-r', 'require "modules/settings/lib.php"; ' + code], capture_output=True, text=True).stdout.strip()
f = 'storage/force-https.on'
if os.path.exists(f): os.remove(f)
ok(php('echo settings_force_https_set(true, false);').startswith('Open the admin panel through https'), 'cannot be turned on from plain http')
ok(not os.path.exists(f), 'no file written when refused')
ok(php('var_dump(settings_force_https_set(true, true)); var_dump(settings_force_https_on());').replace('\n', ' ') == 'NULL bool(true)', 'turns on from https')
ok(os.path.exists(f), 'marker file exists')
ok(php('var_dump(settings_force_https_set(false)); var_dump(settings_force_https_on());').replace('\n', ' ') == 'NULL bool(false)', 'turns off')
ok(php('$_SERVER["HTTP_X_FORWARDED_PROTO"]="https"; var_dump(settings_is_https());').strip() == 'bool(true)', 'proxy https is recognised')
# admin page
h = subprocess.run(['php', '-r', 'echo password_hash("Test#12345",PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout
sql("DELETE FROM users WHERE email='tr@tr.local'"); sql(f"INSERT INTO users(name,email,password,role,is_admin) VALUES ('tr','tr@tr.local','{h}','super',1)")
s = requests.Session()
tok = lambda p: re.search(r'name="csrf_token" value="([^"]+)"', s.get(B + p).text).group(1)
s.post(B + '/auth/login.php', data={'email': 'tr@tr.local', 'password': 'Test#12345', 'csrf_token': tok('/auth/login.php')})
pg = s.get(B + '/admin/settings.php').text
ok('id="https-card"' in pg and 'Force HTTPS' in pg and 'disabled' in pg, 'admin shows Force HTTPS, disabled on plain http')
r = s.post(B + '/admin/https-action.php', data={'do': 'on', 'csrf_token': tok('/admin/settings.php')}); ok(not os.path.exists(f), 'POST on over http does nothing')
ok(requests.post(B + '/admin/https-action.php', data={'do': 'on'}).status_code in (302, 303, 403, 200) and not os.path.exists(f), 'anonymous cannot switch it')
# switch-domain
d = tempfile.mkdtemp()
for src in ['config/app.php', 'sitemap.xml', 'robots.txt', 'apps/desktop/main.js']:
    os.makedirs(os.path.dirname(os.path.join(d, src)), exist_ok=True); shutil.copy(src, os.path.join(d, src))
run = lambda *a: subprocess.run(['php', 'tools/switch-domain.php', *a, '--root=' + d], capture_output=True, text=True)
r = run('expenseflow.rf.gd', 'expenseflow.example.com')
ok('would change sitemap.xml' in r.stdout and 'expenseflow.rf.gd' in open(d + '/sitemap.xml').read(), 'dry run changes nothing')
r = run('expenseflow.rf.gd', 'expenseflow.example.com', '--apply')
ok('expenseflow.rf.gd' not in open(d + '/sitemap.xml').read() and 'expenseflow.example.com' in open(d + '/config/app.php').read() and "const HOST = 'expenseflow.example.com'" in open(d + '/apps/desktop/main.js').read(), '--apply rewrites the files')
ok(subprocess.run(['php', 'tools/switch-domain.php', 'bad host', 'x'], capture_output=True, text=True).returncode == 2, 'bad domain refused')
ok(subprocess.run(['php', 'tools/switch-domain.php', 'a.example.com', "x.com'; rm -rf /"], capture_output=True, text=True).returncode == 2, 'injection refused')
ok(requests.get(B + '/tools/switch-domain.php').status_code == 404, 'the tool is closed to the web')
shutil.rmtree(d); sql("DELETE FROM users WHERE email='tr@tr.local'")
print('trust tests:', 'ALL PASSED' if not fails else f'{fails} FAILED')
