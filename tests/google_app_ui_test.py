"""
The app's "Continue with Google" screen in a real (headless) browser pretending to be the Android/Windows app.
It checks what the person SEES: the waiting panel (no code to compare), and that the app signs in by itself once the
browser brings the one-time return link back. The shell's "open the browser" step is emulated by catching the navigation to /auth/google.php.
Usage: python3 tests/google_app_ui_test.py   (starts its own PHP servers on 8081/8082; needs the test database + Playwright)
"""
import json, os, re, subprocess, sys, tempfile, time, requests
from urllib.parse import urlparse, parse_qs
from playwright.sync_api import sync_playwright
ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..'))
CFG = os.path.join(ROOT, 'config', 'google.local.php')
CLAIMS = os.path.join(tempfile.gettempdir(), 'mock-google-claims.json')
A = 'http://127.0.0.1:8082'
UA = 'Mozilla/5.0 (Linux; Android 14; Pixel 8; wv) AppleWebKit/537.36 Chrome/130 Mobile Safari/537.36 ExpenseFlowApp/1.0.17'
fails = 0
def ok(c, m):
    global fails
    if not c: fails += 1; print('FAIL', m)
def db(sql):
    return subprocess.run(['mysql', '--socket=/run/mysqld/mysqld.sock', '-uroot', 'expenseflow_test', '-N', '-e', sql], capture_output=True, text=True).stdout.strip()

db("DELETE FROM users WHERE email LIKE '%@uitest.local'")
db("DELETE FROM rate_limit_hits WHERE bucket LIKE 'google\\_%'") # earlier runs must not hit the limits
env = dict(os.environ, EXPENSEFLOW_GOOGLE_TOKEN_URL='http://127.0.0.1:8081/mock_google_token.php', MOCK_GOOGLE_CLAIMS=CLAIMS)
procs = [subprocess.Popen(['php', '-S', '127.0.0.1:8081', '-t', os.path.join(ROOT, 'tests')], env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL),
         subprocess.Popen(['php', '-S', '127.0.0.1:8082', '-t', ROOT, 'router.php'], cwd=ROOT, env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)]
time.sleep(1.5)
if os.path.exists(CFG): os.rename(CFG, CFG + '.bak')
try:
    open(CFG, 'w').write("<?php return ['client_id'=>'test-id.apps.googleusercontent.com','client_secret'=>'x'];")
    with sync_playwright() as pw:
        br = pw.chromium.launch()
        ctx = br.new_context(user_agent=UA, viewport={'width': 390, 'height': 800}, service_workers='block')  # (a real web view's service worker fetches pages with the web view's own user agent: covered in google_signin_test.php)
        page = ctx.new_page()
        caught = []
        # headless Chromium forgets the user agent on later navigations/fetches: force it on every request, like a real web view
        ctx.route('**/*', lambda r: r.continue_(headers={**r.request.headers, 'user-agent': UA}))
        ctx.add_init_script("window.efOpenExternal = function (u) { (window.__opened = window.__opened || []).push(u); };")  # the shell opening the browser
        page.goto(A + '/auth/login.php')
        btn = page.locator('#gapp')
        ok(btn.is_visible(), 'Continue with Google button is visible in the app')
        ok(not page.locator('#gapp-panel').is_visible(), 'code panel hidden before the click')
        btn.click()
        page.wait_for_selector('#gapp-panel:visible', timeout=5000)
        ok('code' not in page.locator('#gapp-panel').inner_text().lower(), 'no code to compare anywhere on the screen')
        ok('brought back here automatically' in page.locator('#gapp-status').inner_text(), 'the app says it will come back by itself')
        ok(page.locator('#gapp').is_disabled(), 'button is disabled while waiting')
        page.wait_for_timeout(600)
        caught[:] = page.evaluate('window.__opened || []')
        ok(len(caught) == 1 and '/auth/google.php?app=' in caught[0], 'the browser address was handed to the shell: ' + (caught[0] if caught else 'none'))
        ok(page.url.endswith('/auth/login.php'), 'the app page stays on the login screen')

        # the normal browser does the Google step and is sent back with the one-time link
        json.dump({'iss': 'https://accounts.google.com', 'aud': 'test-id.apps.googleusercontent.com', 'exp': int(time.time()) + 3600, 'sub': 'ui-sub-1', 'email': 'ui1@uitest.local', 'email_verified': True, 'name': 'Ui One'}, open(CLAIMS, 'w'))
        b = requests.Session()
        r = b.get(caught[0], allow_redirects=False)
        state = parse_qs(urlparse(r.headers['Location']).query)['state'][0]
        b.get(A + '/auth/google-callback.php?state=' + state + '&code=abc', allow_redirects=False)
        rp = b.get(A + '/auth/google-app-return.php').text
        m = re.search(r'href="expenseflow://auth\?h=([0-9a-f]{32})&amp;p=([0-9a-f]{48})"', rp)
        ok(m is not None and 'Open ExpenseFlow app' in rp, 'the browser shows "Open ExpenseFlow app" with the deep link')

        # the app sees "approved" while the browser brings the person back
        page.wait_for_function("document.getElementById('gapp-title').textContent.indexOf('Signed in') >= 0", timeout=8000)
        ok('back to the app' in page.locator('#gapp-status').inner_text(), 'while the browser returns, the app says it is taking you back')

        # a link for a DIFFERENT app / hand-off must not sign this app in
        page.goto(A + '/auth/login.php?gh=' + 'a' * 32 + '&gp=' + 'b' * 48)
        page.wait_for_selector('#gapp-status')
        ok('not for this app' in page.locator('#gapp-status').inner_text() or 'expired' in page.locator('#gapp-status').inner_text(), 'a link for someone else\'s hand-off is refused: ' + page.locator('#gapp-status').inner_text()[:60])
        ok('gp=' not in page.url, 'the proof does not stay in the address bar')

        # the app shell (Android intent / Windows local port) loads the login page with the proof: the app signs in by itself
        page.goto(A + '/auth/login.php'); page.locator('#gapp').click(); page.wait_for_selector('#gapp-panel:visible')
        page.wait_for_timeout(300)
        opened = page.evaluate('window.__opened || []')
        b2 = requests.Session()
        r = b2.get(opened[-1], allow_redirects=False)
        state = parse_qs(urlparse(r.headers['Location']).query)['state'][0]
        json.dump({'iss': 'https://accounts.google.com', 'aud': 'test-id.apps.googleusercontent.com', 'exp': int(time.time()) + 3600, 'sub': 'ui-sub-2', 'email': 'ui2@uitest.local', 'email_verified': True, 'name': 'Ui Two'}, open(CLAIMS, 'w'))
        b2.get(A + '/auth/google-callback.php?state=' + state + '&code=abc', allow_redirects=False)
        m2 = re.search(r'href="expenseflow://auth\?h=([0-9a-f]{32})&amp;p=([0-9a-f]{48})"', b2.get(A + '/auth/google-app-return.php').text)
        page.goto(A + '/auth/login.php?gh=' + m2.group(1) + '&gp=' + m2.group(2))   # what MainActivity / main.js do
        page.wait_for_url('**/auth/google-password.php', timeout=15000)
        ok('Set a password?' in page.content(), 'the app signs itself in after the return link and shows the password offer')

        # cancel button
        page2 = ctx.new_page()
        ctx.clear_cookies()
        page2.goto(A + '/auth/login.php'); page2.locator('#gapp').click(); page2.wait_for_selector('#gapp-panel:visible')
        page2.locator('#gapp-cancel').click()
        ok(not page2.locator('#gapp-panel').is_visible() and page2.locator('#gapp').is_enabled(), 'Cancel returns to the normal button')
        br.close()
finally:
    for pr in procs: pr.terminate()
    db("DELETE FROM users WHERE email LIKE '%@uitest.local'")
    if os.path.exists(CFG): os.remove(CFG)
    if os.path.exists(CFG + '.bak'): os.rename(CFG + '.bak', CFG)
print('google_app_ui_test: ALL PASSED' if not fails else f'{fails} FAILED')
sys.exit(1 if fails else 0)
