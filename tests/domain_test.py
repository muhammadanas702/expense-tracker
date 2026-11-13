"""Own domain (Part 106): live check, switch, revert, and the config/redirect rules.
Usage: python3 tests/domain_test.py   (starts its own PHP servers on 8091 (the app) and 8092 (a fake "new domain" that answers /health.php))"""
import subprocess, requests, re, os, time, json, sys
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
B = 'http://127.0.0.1:8091'
fails = 0
def sql(q): return subprocess.run(['mysql', '--socket=/run/mysqld/mysqld.sock', '-uroot', 'expenseflow_test', '-N', '-e', q], capture_output=True, text=True).stdout.strip()
def ok(c, m):
    global fails
    print(('ok   ' if c else 'FAIL ') + m)
    if not c: fails += 1
site = os.path.join(ROOT, 'storage', 'site.json')
if os.path.exists(site): os.remove(site)
env = dict(os.environ, EXPENSEFLOW_2FA_OFF='1', EXPENSEFLOW_SITE_CHECK_BASE='http://127.0.0.1:8092')
app = subprocess.Popen(['php', '-S', '127.0.0.1:8091', '-t', ROOT, 'router.php'], cwd=ROOT, env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
fake = subprocess.Popen(['php', '-S', '127.0.0.1:8092', '-t', ROOT, 'router.php'], cwd=ROOT, env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
time.sleep(1.5)
h = subprocess.run(['php', '-r', 'echo password_hash("Test#12345",PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout
sql("DELETE FROM users WHERE email='dm@dm.local'"); sql("DELETE FROM rate_limit_hits")
sql(f"INSERT INTO users(name,email,password,role,is_admin) VALUES ('DM','dm@dm.local','{h}','super',1)")
try:
    s = requests.Session()
    r = s.get(B + '/auth/login.php'); tok = re.search(r'name="csrf_token" value="([^"]+)"', r.text)
    d = {'email': 'dm@dm.local', 'password': 'Test#12345'}
    if tok: d['csrf_token'] = tok.group(1)
    s.post(B + '/auth/login.php', data=d)
    def page():
        return s.get(B + '/admin/domain.php').text
    def act(do, host):
        t = page(); c = re.search(r'name="csrf_token" value="([^"]+)"', t).group(1)
        return s.post(B + '/admin/domain-action.php', data={'csrf_token': c, 'do': do, 'host': host}, allow_redirects=True).text
    t = page(); ok('Main address' in t and 'expenseflow.rf.gd' in t and 'free address' in t, 'page shows the free address first')
    t = act('check', 'not a host'); ok('plain host name' in t, 'a bad host name is explained')
    t = act('check', 'expenseflow.page'); ok('1. The domain points' in t and ('OK' in t), 'check runs the steps (dns is faked to 127.0.0.1 in tests)')
    ok('2. The secure address' in t and '3. ExpenseFlow answers' in t and 'is ready' in t, 'all three steps pass against the fake domain')
    t = act('switch', 'expenseflow.page'); ok('now the main address' in t, 'switch works after a passing check')
    ok(json.load(open(site)).get('live') is True and json.load(open(site))['host'] == 'expenseflow.page', 'site.json written')
    ok('your own domain' in page(), 'page shows the own domain as main address')
    t = act('revert', ''); ok('Back to expenseflow.rf.gd' in t and json.load(open(site))['live'] is False, 'go back works')
finally:
    sql("DELETE FROM users WHERE email='dm@dm.local'")
    app.kill(); fake.kill()
    if os.path.exists(site): os.remove(site)
# a failing check never passes (unit level: no DNS answer, and a closed port)
def chk(host, base=None):
    e = dict(os.environ); e.pop('EXPENSEFLOW_SITE_CHECK_BASE', None)
    if base: e['EXPENSEFLOW_SITE_CHECK_BASE'] = base
    r = subprocess.run(['php', '-r', 'require "modules/site/host.php"; $s=site_check($argv[1]); echo json_encode([site_check_passed($s), count($s)]);', host], cwd=ROOT, capture_output=True, text=True, env=e)
    return json.loads(r.stdout or '[null,0]')
ok(chk('no-such-domain-xyz.invalid') == [False, 1], 'a domain without DNS fails at step 1 and stops')
ok(chk('expenseflow.page', 'http://127.0.0.1:1') == [False, 2], 'a domain whose https does not answer fails at step 2')
ok(chk('Bad Host!') == [False, 1], 'a malformed host name fails')
# the config and redirect rules (pure PHP)
code = r'''
require "config/app.php";
$f=0; function ok($c,$m){global $f; if(!$c){$f++; echo "FAIL $m\n";}}
$P="https://expenseflow.page"; $O="expenseflow.rf.gd";
ok(app_redirect_target($P,$O,"/about.php",true,false)===$P."/about.php","old host goes to the new main address");
ok(app_redirect_target($P,$O,"/admin/index.php",true,false)===null,"old host keeps /admin/");
ok(app_redirect_target($P,$O,"/health.php?db=1",true,false)===null,"old host keeps /health.php");
ok(app_redirect_target($P,"www.expenseflow.page","/admin/x.php",true,false)===$P."/admin/x.php","other hosts do not get the admin exception");
ok(app_redirect_target("https://expenseflow.rf.gd",$O,"/admin/x.php",false,false)==="https://expenseflow.rf.gd/admin/x.php?ef_https=1","http is still sent to https on the free address");
echo $f? "$f FAILED\n":"redirect rules: ALL PASSED\n";'''
r = subprocess.run(['php', '-r', code], cwd=ROOT, capture_output=True, text=True, env=dict(os.environ, EXPENSEFLOW_NO_REDIRECT='1'))
print(r.stdout.strip()); fails += 0 if 'ALL PASSED' in r.stdout else 1
# config reads site.json
open(site, 'w').write(json.dumps({'host': 'expenseflow.page', 'live': True, 'since': 1}))
r = subprocess.run(['php', '-r', 'require "config/app.php"; echo $base_url;'], cwd=ROOT, capture_output=True, text=True, env=dict(os.environ, EXPENSEFLOW_NO_REDIRECT='1', HTTP_HOST='example.org'))
ok(r.stdout.strip() == 'https://expenseflow.page', 'config/app.php uses the host from site.json when live: ' + r.stdout.strip())
open(site, 'w').write(json.dumps({'host': 'bad host!', 'live': True}))
r = subprocess.run(['php', '-r', 'require "config/app.php"; echo $base_url;'], cwd=ROOT, capture_output=True, text=True, env=dict(os.environ, EXPENSEFLOW_NO_REDIRECT='1'))
ok(r.stdout.strip() == 'https://expenseflow.rf.gd', 'a broken site.json falls back to the free address')
os.remove(site)
print('domain_test: ' + ('ALL PASSED' if not fails else f'{fails} FAILED')); sys.exit(1 if fails else 0)
