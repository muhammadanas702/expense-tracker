"""
Resilience in a real headless browser with the service worker ON.
Usage: php -S 127.0.0.1:8080 -t . router.php   then   python3 tests/resilience_ui_test.py   (TEST database)
Checks: a signed-in page keeps working with the network cut or the server very slow (saved copy + bar, auto-recovery),
admin pages are saved too, a page never seen before shows the smart offline page (device offline vs server down),
logging out wipes the saved private pages, health.php, and a dead database gives a friendly 503 fast.
"""
import subprocess, time, requests
from playwright.sync_api import sync_playwright
B = 'http://127.0.0.1:8080'
fails = 0
def sql(q): return subprocess.run(['mysql', '--socket=/run/mysqld/mysqld.sock', '-uroot', 'expenseflow_test', '-N', '-e', q], capture_output=True, text=True).stdout.strip()
def ok(c, m):
    global fails
    if not c: fails += 1; print('FAIL', m)
h = subprocess.run(['php', '-r', 'echo password_hash("Test#12345",PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout
sql("DELETE FROM users WHERE email='rs1@rs.local'"); sql("DELETE FROM rate_limit_hits")
sql(f"INSERT INTO users(name,email,password,role,is_admin) VALUES ('Res One','rs1@rs.local','{h}','super',1)")
sql("INSERT INTO user_settings (user_id, savings_goal, notifications, setup_done) SELECT id, 20, 1, 1 FROM users WHERE email='rs1@rs.local'")  # no first-run setup page

# --- health.php
r = requests.get(B + '/health.php'); ok(r.status_code == 200 and r.json()['ok'] is True and 'no-store' in r.headers.get('Cache-Control', ''), 'health.php answers, never cached')
r = requests.get(B + '/health.php?db=1'); ok(r.status_code == 200 and r.json()['db'] is True, 'health.php?db=1 sees the database')

import os, signal
PORT = 8096
APP = f'http://127.0.0.1:{PORT}'
def start(slow=None):
    env = dict(os.environ, PHP_CLI_SERVER_WORKERS='4', EXPENSEFLOW_2FA_OFF='1')
    if slow: env['EF_SLOW'] = str(slow)
    pr = subprocess.Popen(['php', '-S', f'127.0.0.1:{PORT}', '-t', '.', 'tests/slow_router.php'], env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    for _ in range(30):
        try: requests.get(APP + '/health.php', timeout=1); break
        except Exception: time.sleep(0.2)
    return pr
def stop(pr):
    pr.terminate(); pr.wait()

srv = start()
with sync_playwright() as pw:
    br = pw.chromium.launch()
    ctx = br.new_context(viewport={'width': 1100, 'height': 800})
    p = ctx.new_page()
    p.goto(APP + '/auth/login.php'); p.wait_for_timeout(800)
    p.fill('input[name=email]', 'rs1@rs.local'); p.fill('input[name=password]', 'Test#12345'); p.click('button[type=submit]'); p.wait_for_load_state(); p.wait_for_timeout(1500)
    ok('/dashboard.php' in p.url, 'signed in')
    for path in ('/dashboard.php', '/budgets.php', '/admin/index.php', '/admin/settings.php'):
        p.goto(APP + path); p.wait_for_load_state(); p.wait_for_timeout(300)
    p.reload(); p.wait_for_timeout(500)
    ok(p.evaluate("navigator.serviceWorker.controller !== null"), 'page is controlled by the service worker')
    saved = p.evaluate("caches.open('expenseflow-pages-v1').then(c => c.keys()).then(k => k.map(r => new URL(r.url).pathname))")
    ok(all(x in saved for x in ('/dashboard.php', '/budgets.php', '/admin/index.php', '/admin/settings.php')), f'pages incl. admin are saved: {saved}')
    ok('/auth/login.php' not in saved, 'the login page is never saved')

    # --- the server is DOWN (connection refused): saved copies with the bar, quickly
    stop(srv)
    for path, word in (('/dashboard.php', 'Welcome back'), ('/budgets.php', 'Set a budget'), ('/admin/settings.php', 'Settings')):
        t0 = time.time(); p.goto(APP + path); p.wait_for_timeout(300)
        ok(p.locator('#ef-stale').count() == 1 and word in p.content(), f'server down: saved copy with the bar: {path} ({time.time()-t0:.1f}s)')
    p.goto(APP + '/quick-add.php'); p.wait_for_timeout(1500)
    ok(p.locator('#oh').count() == 1, 'a page never saved shows the offline page')
    p.wait_for_function("document.getElementById('oh').textContent.indexOf('not answering') >= 0", timeout=15000)
    ok(p.locator('#saved').is_visible(), 'offline page: says the SERVER is the problem and offers the saved dashboard')
    # the same page when the DEVICE is offline
    p3 = ctx.new_page(); p3.add_init_script("Object.defineProperty(navigator, 'onLine', {get: () => false})")
    p3.goto(APP + '/offline.html'); p3.wait_for_timeout(500)
    ok('No internet' in p3.inner_text('#oh'), 'offline page: says the DEVICE has no internet when navigator.onLine is false')
    p3.close()

    # --- the server is SLOW (answers after 12 s): saved copy after about 4 s, then it recovers by itself
    srv = start(slow=12)
    t0 = time.time(); p.goto(APP + '/dashboard.php', timeout=40000); dt = time.time() - t0
    ok(3.5 < dt < 8 and p.locator('#ef-stale').count() == 1, f'slow server: saved copy after about 4 s with the bar ({dt:.1f}s)')
    stop(srv); srv = start()
    p.wait_for_function("document.getElementById('ef-stale') === null && document.title.indexOf('Welcome') >= 0", timeout=30000)
    ok(True, 'when the server is healthy again the page reloads by itself (no bar)')

    # --- logout wipes the saved private pages
    p.goto(APP + '/dashboard.php'); p.wait_for_timeout(300)
    tok = p.locator('a[href$="auth/logout.php"]').first.get_attribute('data-csrf')
    p.evaluate("""(t) => { var f = document.createElement('form'); f.method='POST'; f.action='auth/logout.php'; var i=document.createElement('input'); i.name='csrf_token'; i.value=t; f.appendChild(i); document.body.appendChild(f); f.submit(); }""", tok)
    p.wait_for_load_state(); p.wait_for_timeout(800)
    n = p.evaluate("caches.open('expenseflow-pages-v1').then(c => c.keys()).then(k => k.map(r => r.url).filter(u => /dashboard|budgets|admin/.test(u)).length)")
    ok(n == 0, f'after logout no private page stays saved ({n} left)')
    br.close()
stop(srv)

# --- database host that never answers: friendly 503 within a few seconds, not a frozen page
env = {'EXPENSEFLOW_DB_HOST': '10.255.255.1', 'EXPENSEFLOW_DB_NAME': 'x', 'EXPENSEFLOW_DB_USERNAME': 'x', 'EXPENSEFLOW_DB_PASSWORD': 'x', 'PATH': '/usr/bin:/bin:/usr/local/bin'}
import os, shutil
priv = 'config/db.local.php'; bak = '/tmp/claude-0/db.local.php.bak'
shutil.copy(priv, bak); os.remove(priv)
srv = subprocess.Popen(['php', '-S', '127.0.0.1:8097', '-t', '.', 'router.php'], env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
try:
    time.sleep(1)
    t = time.time(); r = requests.get('http://127.0.0.1:8097/auth/login.php', timeout=30); dt = time.time() - t
    ok(r.status_code == 503 and 'database is busy' in r.text and r.headers.get('Retry-After') == '30' and dt < 12, f'dead database: friendly 503 in {dt:.1f}s (status {r.status_code})')
    r = requests.get('http://127.0.0.1:8097/health.php?db=1', timeout=30)
    ok(r.status_code == 503 and r.json()['db'] is False, 'health.php?db=1 reports the dead database')
    r = requests.get('http://127.0.0.1:8097/health.php', timeout=30)
    ok(r.status_code == 200, 'health.php (no db) still answers')
finally:
    srv.terminate(); shutil.copy(bak, priv)
sql("DELETE FROM user_settings WHERE user_id IN (SELECT id FROM users WHERE email='rs1@rs.local')"); sql("DELETE FROM users WHERE email='rs1@rs.local'")
print('resilience tests:', 'ALL PASSED' if not fails else f'{fails} FAILED')
raise SystemExit(1 if fails else 0)
