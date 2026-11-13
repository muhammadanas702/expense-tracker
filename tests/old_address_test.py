"""Free address stays alive (Part 106b): with the own domain as main address, the free address redirects to it while it answers, and serves the site itself while it does not.
Usage: python3 tests/old_address_test.py"""
import subprocess, os, json, time, sys
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
site = os.path.join(ROOT, 'storage', 'site.json'); hf = os.path.join(ROOT, 'storage', 'site-health.json')
fails = 0
def ok(c, m):
    global fails
    print(('ok   ' if c else 'FAIL ') + m)
    if not c: fails += 1
def run(base, host='expenseflow.rf.gd', https='on'):
    code = r'''$_SERVER["HTTP_HOST"]=getenv("H"); $_SERVER["REQUEST_URI"]="/about.php"; $_SERVER["REQUEST_METHOD"]="GET"; $_SERVER["HTTPS"]=getenv("S");
ob_start(); register_shutdown_function(function(){ foreach (headers_list() as $h) if (stripos($h,"Location:")===0) echo $h; });
require "config/app.php"; echo "SERVED";'''
    e = dict(os.environ, H=host, S=https); e.pop('EXPENSEFLOW_NO_REDIRECT', None)
    if base: e['EXPENSEFLOW_SITE_CHECK_BASE'] = base
    else: e.pop('EXPENSEFLOW_SITE_CHECK_BASE', None)
    r = subprocess.run(['php', '-d', 'cli_server=1', '-r', code], cwd=ROOT, capture_output=True, text=True, env=e)
    return r.stdout
# php -r is CLI: app_enforce_site returns early for CLI, so test the helper + the decision directly
def helper(base):
    e = dict(os.environ); e['EXPENSEFLOW_SITE_CHECK_BASE'] = base
    r = subprocess.run(['php', '-r', 'require "modules/site/host.php"; echo site_main_healthy() ? "up" : "down";'], cwd=ROOT, capture_output=True, text=True, env=e)
    return r.stdout.strip()
fake = subprocess.Popen(['php', '-S', '127.0.0.1:8093', '-t', ROOT, 'router.php'], cwd=ROOT, env=dict(os.environ, EXPENSEFLOW_2FA_OFF='1'), stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
time.sleep(1.2)
try:
    for f in (hf,):
        if os.path.exists(f): os.remove(f)
    open(site, 'w').write(json.dumps({'host': 'expenseflow.page', 'live': True, 'since': 1}))
    ok(helper('http://127.0.0.1:8093') == 'up', 'main address answering -> healthy')
    os.remove(hf)
    ok(helper('http://127.0.0.1:1') == 'down', 'main address not answering -> down')
    ok(json.load(open(hf))['ok'] is False, 'the answer is remembered')
    ok(helper('http://127.0.0.1:8093') == 'down', 'a "down" answer is kept for 30 seconds (no check on every visit)')
    d = json.load(open(hf)); d['t'] -= 40; open(hf, 'w').write(json.dumps(d))
    ok(helper('http://127.0.0.1:8093') == 'up', 'after 30 seconds it is checked again and recovers')
    os.remove(site)
    ok(helper('http://127.0.0.1:1') == 'up', 'no own domain set -> always healthy (nothing to check)')
    # the real web path through the PHP server, Host: expenseflow.rf.gd over https
    import requests
    def serve(port, base):
        return subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', ROOT, 'router.php'], cwd=ROOT, env=dict(os.environ, EXPENSEFLOW_2FA_OFF='1', EXPENSEFLOW_SITE_CHECK_BASE=base), stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    open(site, 'w').write(json.dumps({'host': 'expenseflow.page', 'live': True, 'since': 1}))
    up = serve(8094, 'http://127.0.0.1:8093'); down = serve(8095, 'http://127.0.0.1:1'); time.sleep(1.5)
    H = {'Host': 'expenseflow.rf.gd', 'X-Forwarded-Proto': 'https'}
    try:
        for f in (hf,):
            if os.path.exists(f): os.remove(f)
        r = requests.get('http://127.0.0.1:8094/about.php', headers=H, allow_redirects=False)
        ok(r.status_code == 301 and r.headers.get('Location') == 'https://expenseflow.page/about.php', f'own domain up: the free address sends people to it ({r.status_code} {r.headers.get("Location")})')
        os.remove(hf)
        r = requests.get('http://127.0.0.1:8095/about.php', headers=H, allow_redirects=False)
        ok(r.status_code == 200, f'own domain down: the free address serves the page itself ({r.status_code})')
        r = requests.get('http://127.0.0.1:8095/about.php', headers={'Host': 'expenseflow.rf.gd'}, allow_redirects=False)
        ok(r.status_code == 301 and r.headers.get('Location', '').startswith('https://expenseflow.rf.gd/'), f'own domain down: plain http still goes to https on the free address ({r.headers.get("Location")})')
    finally:
        up.kill(); down.kill()
finally:
    fake.kill()
    for f in (site, hf):
        if os.path.exists(f): os.remove(f)
print('old_address_test: ' + ('ALL PASSED' if not fails else f'{fails} FAILED')); sys.exit(1 if fails else 0)
