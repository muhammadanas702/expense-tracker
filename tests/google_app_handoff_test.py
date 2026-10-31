"""
"Continue with Google" inside the apps (hand-off), end to end against LOCAL servers and a fake Google token endpoint.
Usage: python3 tests/google_app_handoff_test.py      (starts its own PHP servers on 8081 and 8082; needs the test database)
Checks: the app asks for a hand-off, the browser flow does not sign the browser in, the person confirms the code,
the app collects the sign-in exactly once, a wrong secret / cancelled / reused hand-off is refused.
"""
import json, os, re, subprocess, sys, tempfile, time, requests
from urllib.parse import urlparse, parse_qs
ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..'))
CFG = os.path.join(ROOT, 'config', 'google.local.php')
CLAIMS = os.path.join(tempfile.gettempdir(), 'mock-google-claims.json')
A = 'http://127.0.0.1:8082'
APPUA = {'User-Agent': 'Mozilla/5.0 (Linux; Android 14; Pixel; wv) ExpenseFlowApp/1.0.16'}
fails = 0
def ok(c, m):
    global fails
    if not c: fails += 1; print('FAIL', m)
def claims(sub):
    json.dump({'iss': 'https://accounts.google.com', 'aud': 'test-id.apps.googleusercontent.com', 'exp': int(time.time()) + 3600, 'sub': sub, 'email': sub + '@google-e2e.local', 'email_verified': True, 'name': 'App ' + sub}, open(CLAIMS, 'w'))
def api(s, csrf, **d):
    d['csrf_token'] = csrf
    return s.post(A + '/auth/google-app.php', data=d, headers=APPUA).json()
def browser_flow(url, sub, do='yes'):
    """The normal browser: Google step (mocked), then the confirm page. Returns (browser session, confirm page html)."""
    b = requests.Session()
    claims(sub)
    r = b.get(url, allow_redirects=False)
    state = parse_qs(urlparse(r.headers['Location']).query)['state'][0]
    r = b.get(A + '/auth/google-callback.php?state=' + state + '&code=abc', allow_redirects=False)
    ok('google-app-confirm.php' in r.headers.get('Location', ''), 'callback leads to the confirm page: ' + r.headers.get('Location', '?'))
    page = b.get(A + '/auth/google-app-confirm.php').text
    ok(b.get(A + '/dashboard.php', allow_redirects=False).status_code == 302, 'the browser itself is NOT signed in')
    if do:
        tok = re.search(r'name="csrf_token" value="([0-9a-f]+)"', page).group(1)
        b.post(A + '/auth/google-app-confirm.php', data={'csrf_token': tok, 'do': do})
    return b, page

env = dict(os.environ, EXPENSEFLOW_GOOGLE_TOKEN_URL='http://127.0.0.1:8081/mock_google_token.php', MOCK_GOOGLE_CLAIMS=CLAIMS)
procs = [subprocess.Popen(['php', '-S', '127.0.0.1:8081', '-t', os.path.join(ROOT, 'tests')], env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL),
         subprocess.Popen(['php', '-S', '127.0.0.1:8082', '-t', ROOT, 'router.php'], cwd=ROOT, env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)]
time.sleep(1.5)
if os.path.exists(CFG): os.rename(CFG, CFG + '.bak')
try:
    open(CFG, 'w').write("<?php return ['client_id'=>'test-id.apps.googleusercontent.com','client_secret'=>'x'];")
    app = requests.Session()
    page = app.get(A + '/auth/login.php', headers=APPUA).text
    csrf = re.search(r'data-csrf="([0-9a-f]+)"', page).group(1)
    ok('id="gapp"' in page and 'google-app.js' in page, 'app login page has the hand-off button')
    ok(api(requests.Session(), 'bad', do='start')['state'] == 'error', 'start needs a valid CSRF token')
    ok(requests.Session().post(A + '/auth/google-app.php', data={'do': 'start'}).status_code == 403, 'a normal browser cannot use the app endpoint')

    h = api(app, csrf, do='start')
    ok(h['state'] == 'started' and re.fullmatch(r'[A-Z2-9]{4}', h['code']) and '/auth/google.php?app=' in h['url'], 'start returns url + code + secret')
    ok(api(app, csrf, do='poll', secret=h['secret'])['state'] == 'wait', 'poll waits before the person confirms')
    ok(api(app, csrf, do='poll', secret='0' * 48)['state'] == 'gone', 'a wrong secret is refused')

    b, confirm = browser_flow(h['url'], 'happy1')
    ok(h['code'] in confirm, 'confirm page shows the same code as the app')
    p = api(app, csrf, do='poll', secret=h['secret'])
    ok(p['state'] == 'ok' and p['url'].endswith('/dashboard.php'), 'app collects the sign-in after confirmation')
    ok(app.get(A + '/dashboard.php', allow_redirects=False).status_code in (200, 302) and 'login.php' not in app.get(A + '/dashboard.php', allow_redirects=False).headers.get('Location', ''), 'the app session is signed in')
    ok(api(requests.Session(), csrf, do='poll', secret=h['secret'])['state'] in ('gone', 'error'), 'a collected hand-off cannot be used again')

    app2 = requests.Session(); csrf2 = re.search(r'data-csrf="([0-9a-f]+)"', app2.get(A + '/auth/login.php', headers=APPUA).text).group(1)
    h2 = api(app2, csrf2, do='start')
    browser_flow(h2['url'], 'cancel1', do='no')
    ok(api(app2, csrf2, do='poll', secret=h2['secret'])['state'] == 'gone', 'cancelled in the browser: the app is not signed in')
    r = requests.get(h2['url'], allow_redirects=False)
    ok('login.php' in r.headers.get('Location', ''), 'a used hand-off link cannot start another Google flow')
    r = requests.get(A + '/auth/google.php?app=' + 'f' * 32, allow_redirects=False)
    ok('login.php' in r.headers.get('Location', ''), 'unknown hand-off id refused')
    r = requests.get(h['url'], allow_redirects=False, headers=APPUA)
    ok('login.php' in r.headers.get('Location', ''), 'the Google step is refused inside the app itself')
finally:
    for pr in procs: pr.terminate()
    if os.path.exists(CFG): os.remove(CFG)
    if os.path.exists(CFG + '.bak'): os.rename(CFG + '.bak', CFG)
print('google_app_handoff_test: ALL PASSED' if not fails else f'{fails} FAILED')
sys.exit(1 if fails else 0)
