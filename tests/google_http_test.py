"""
Google sign-in pages against a LOCAL test server (no calls to Google).
Usage: php -S 127.0.0.1:8080 -t . router.php   then   python3 tests/google_http_test.py
Checks: button hidden until configured and hidden inside the app shells, redirect to Google carries state + PKCE,
bad/missing/replayed state is refused, Google error is handled, callback never signs anyone in without Google.
"""
import os, re, requests, sys
from urllib.parse import urlparse, parse_qs
B = 'http://127.0.0.1:8080'
CFG = os.path.join(os.path.dirname(__file__), '..', 'config', 'google.local.php')
fails = 0
def ok(c, m):
    global fails
    if not c: fails += 1; print('FAIL', m)
def get(s, p, **kw): return s.get(B + p, allow_redirects=False, **kw)

if os.path.exists(CFG): os.rename(CFG, CFG + '.bak')
try:
    s = requests.Session()
    ok('gbtn' not in get(s, '/auth/login.php').text, 'no button before it is configured')
    r = get(s, '/auth/google.php'); ok(r.status_code == 302 and 'login.php' in r.headers['Location'], 'start without config goes back to login')

    open(CFG, 'w').write("<?php return ['client_id'=>'test-id.apps.googleusercontent.com','client_secret'=>'x'];")
    s = requests.Session()
    t = get(s, '/auth/login.php').text
    ok('Continue with Google' in t and 'auth/google.php' in t, 'login shows the button')
    t = get(s, '/auth/register.php').text
    ok('Sign up with Google' in t and 'intent=signup' in t and 'Privacy Policy' in t, 'register shows the button with terms note')
    for ua in ('Mozilla/5.0 ExpenseFlowApp/1.0.15', 'Mozilla/5.0 ExpenseFlowDesktop/1.0.15', 'Mozilla/5.0 (Linux; Android 14; Pixel; wv) AppleWebKit'):
        ok('gbtn' not in get(requests.Session(), '/auth/login.php', headers={'User-Agent': ua}).text, 'hidden in app shell: ' + ua[-22:])

    r = get(s, '/auth/google.php?intent=signup')
    loc = r.headers.get('Location', '')
    q = parse_qs(urlparse(loc).query)
    ok(r.status_code == 302 and loc.startswith('https://accounts.google.com/o/oauth2/v2/auth?'), 'start redirects to Google')
    ok(q.get('client_id') == ['test-id.apps.googleusercontent.com'] and q.get('code_challenge_method') == ['S256'], 'client id + PKCE')
    ok(q.get('redirect_uri', [''])[0].endswith('/auth/google-callback.php') and q.get('response_type') == ['code'], 'redirect uri + code flow')
    state = q['state'][0]
    ok(re.fullmatch(r'[0-9a-f]{32}', state) is not None, 'random state')

    r = get(s, '/auth/google-callback.php?state=wrong&code=abc')
    ok(r.status_code == 302 and 'login.php' in r.headers['Location'], 'wrong state refused')
    r = get(s, '/auth/google-callback.php?state=' + state + '&code=abc')
    ok('login.php' in r.headers.get('Location', ''), 'state already used (single use)')
    ok('expired' in get(s, '/auth/login.php').text.lower() or 'try again' in get(s, '/auth/login.php').text.lower(), 'login page shows a message')

    s2 = requests.Session(); get(s2, '/auth/google.php')
    st2 = parse_qs(urlparse(get(s2, '/auth/google.php').headers['Location']).query)['state'][0]
    r = get(s2, '/auth/google-callback.php?state=' + st2 + '&error=access_denied')
    ok('login.php' in r.headers.get('Location', ''), 'Google error handled')
    r = get(s2, '/dashboard.php'); ok(r.status_code == 302 and 'login.php' in r.headers['Location'], 'still not signed in after all that')

    s3 = requests.Session()
    r = get(s3, '/auth/google-callback.php?code=abc'); ok('login.php' in r.headers.get('Location', '') and 'dashboard' not in r.headers.get('Location', ''), 'callback without state refused')
finally:
    if os.path.exists(CFG): os.remove(CFG)
    if os.path.exists(CFG + '.bak'): os.rename(CFG + '.bak', CFG)
print('google_http_test: ALL PASSED' if not fails else f'{fails} FAILED')
sys.exit(1 if fails else 0)
