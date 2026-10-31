"""
"Continue with Google" inside the apps (hand-off), end to end against LOCAL servers and a fake Google token endpoint.
Usage: python3 tests/google_app_handoff_test.py      (starts its own PHP servers on 8081 and 8082; needs the test database)
Checks: the app asks for a hand-off, the browser flow does not sign the browser in, the browser is sent back to the app
(Android link / Windows local port) with a one-time proof, the app signs in exactly once, and wrong secret / wrong proof /
reused / bad return address are refused.
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
def browser_flow(url, sub):
    """The normal browser: Google step (mocked), then the page that sends the person back to the app. Returns (browser session, return link)."""
    b = requests.Session()
    claims(sub)
    r = b.get(url, allow_redirects=False)
    state = parse_qs(urlparse(r.headers['Location']).query)['state'][0]
    r = b.get(A + '/auth/google-callback.php?state=' + state + '&code=abc', allow_redirects=False)
    ok('google-app-return.php' in r.headers.get('Location', ''), 'callback leads to the return page: ' + r.headers.get('Location', '?'))
    page = b.get(A + '/auth/google-app-return.php').text
    ok(b.get(A + '/dashboard.php', allow_redirects=False).status_code == 302, 'the browser itself is NOT signed in')
    m = re.search(r'href="((?:expenseflow://auth|http://127\.0\.0\.1:\d+/done)\?h=[0-9a-f]{32}&amp;p=[0-9a-f]{48})"', page)
    ok(b.get(A + '/auth/google-app-return.php').text.count('Open ExpenseFlow app') == 0, 'the proof is shown once, not kept in the browser')
    return b, (m.group(1).replace('&amp;', '&') if m else None)

def db(sql):
    return subprocess.run(['mysql', '--socket=/run/mysqld/mysqld.sock', '-uroot', 'expenseflow_test', '-N', '-e', sql], capture_output=True, text=True).stdout.strip()
db("DELETE FROM rate_limit_hits WHERE bucket LIKE 'google\\_%'") # earlier runs must not hit the limits
db("DELETE FROM users WHERE email LIKE '%@google-e2e.local'")
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

    ok(api(app, csrf, do='start', ret='evil')['state'] == 'started', 'start accepts a request even with a bad return value (it is just ignored)')
    h = api(app, csrf, do='start', ret='android')
    ok(h['state'] == 'started' and re.fullmatch(r'[0-9a-f]{32}', h['id']) and '/auth/google.php?app=' + h['id'] in h['url'] and 'code' not in h, 'start returns id + url + secret and no code')
    ok(api(app, csrf, do='poll', secret=h['secret'])['state'] == 'wait', 'poll waits before Google is done')
    ok(api(app, csrf, do='poll', secret='0' * 48)['state'] == 'gone', 'a wrong secret is refused')

    b, link = browser_flow(h['url'], 'happy1')
    ok(link is not None and link.startswith('expenseflow://auth?h=' + h['id'] + '&p='), 'Android: the browser is sent back with the deep link: ' + str(link))
    proof = parse_qs(urlparse(link).query)['p'][0]
    ok(api(app, csrf, do='poll', secret=h['secret'])['state'] == 'approved', 'poll shows approved, but poll never signs in')
    ok(requests.get(A + '/dashboard.php', allow_redirects=False).status_code == 302, 'still nobody signed in before the proof is used')
    other = requests.Session(); ocsrf = re.search(r'data-csrf="([0-9a-f]+)"', other.get(A + '/auth/login.php', headers=APPUA).text).group(1)
    ok(api(other, ocsrf, do='redeem', id=h['id'], secret='1' * 48, proof=proof)['state'] == 'gone', 'proof + wrong secret (someone else\'s app): refused')
    ok(api(other, ocsrf, do='redeem', id=h['id'], secret=h['secret'], proof='2' * 48)['state'] == 'gone', 'right secret + wrong proof: refused')
    p = api(app, csrf, do='redeem', id=h['id'], secret=h['secret'], proof=proof)
    ok(p['state'] == 'ok' and p['url'].endswith('/auth/google-password.php'), 'the app signs in with id + secret + proof (new account: password offer)')
    ok('login.php' not in app.get(A + '/auth/google-password.php', allow_redirects=False).headers.get('Location', ''), 'the app session is signed in')
    ok(api(requests.Session(), csrf, do='redeem', id=h['id'], secret=h['secret'], proof=proof)['state'] in ('gone', 'error'), 'a used proof cannot be used again')

    # Windows: the browser returns to the local port the app gave
    app2 = requests.Session(); csrf2 = re.search(r'data-csrf="([0-9a-f]+)"', app2.get(A + '/auth/login.php', headers=APPUA).text).group(1)
    w = api(app2, csrf2, do='start', ret='51234')
    _, wl = browser_flow(w['url'], 'win1')
    ok(wl is not None and wl.startswith('http://127.0.0.1:51234/done?h=' + w['id'] + '&p='), 'Windows: the browser is sent back to the local port: ' + str(wl))
    ok(api(app2, csrf2, do='redeem', id=w['id'], secret=w['secret'], proof=parse_qs(urlparse(wl).query)['p'][0])['state'] == 'ok', 'Windows app signs in with the proof')

    # a port outside the allowed range is not accepted as a way back
    app4 = requests.Session(); csrf4 = re.search(r'data-csrf="([0-9a-f]+)"', app4.get(A + '/auth/login.php', headers=APPUA).text).group(1)
    bad = api(app4, csrf4, do='start', ret='80')
    _, bl = browser_flow(bad['url'], 'bad1')
    ok(bl is None, 'a privileged / invalid port is never used as a return address')

    app3 = requests.Session(); csrf3 = re.search(r'data-csrf="([0-9a-f]+)"', app3.get(A + '/auth/login.php', headers=APPUA).text).group(1)
    h3 = api(app3, csrf3, do='start', ret='android')
    requests.get(A + '/auth/google-app-return.php')
    r = requests.get(h['url'], allow_redirects=False)
    ok('login.php' in r.headers.get('Location', ''), 'a used hand-off link cannot start another Google flow')
    r = requests.get(A + '/auth/google.php?app=' + 'f' * 32, allow_redirects=False)
    ok('login.php' in r.headers.get('Location', ''), 'unknown hand-off id refused')
    r = requests.get(h3['url'], allow_redirects=False, headers=APPUA)
    ok('login.php' in r.headers.get('Location', ''), 'the Google step is refused inside the app itself')
finally:
    for pr in procs: pr.terminate()
    if os.path.exists(CFG): os.remove(CFG)
    if os.path.exists(CFG + '.bak'): os.rename(CFG + '.bak', CFG)
print('google_app_handoff_test: ALL PASSED' if not fails else f'{fails} FAILED')
sys.exit(1 if fails else 0)
