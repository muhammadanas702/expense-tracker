"""
Google sign-in on the website, end to end against LOCAL servers and a fake Google token endpoint:
first sign-in -> password offer (set or skip), existing e-mail account is signed into (no duplicate),
second sign-in goes straight in, a password set after Google really works for e-mail sign-in.
Usage: python3 tests/google_password_http_test.py   (starts its own PHP servers on 8081/8082; needs the test database)
"""
import json, os, re, subprocess, sys, tempfile, time, requests
from urllib.parse import urlparse, parse_qs
ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..'))
CFG = os.path.join(ROOT, 'config', 'google.local.php')
CLAIMS = os.path.join(tempfile.gettempdir(), 'mock-google-claims.json')
A = 'http://127.0.0.1:8082'
fails = 0
def ok(c, m):
    global fails
    if not c: fails += 1; print('FAIL', m)
def claims(email, sub, name='Pw Tester'):
    json.dump({'iss': 'https://accounts.google.com', 'aud': 'test-id.apps.googleusercontent.com', 'exp': int(time.time()) + 3600, 'sub': sub, 'email': email, 'email_verified': True, 'name': name}, open(CLAIMS, 'w'))
def google_signin(s, email, sub):
    """Runs the website flow in session s. Returns the first Location after Google."""
    claims(email, sub)
    r = s.get(A + '/auth/google.php', allow_redirects=False)
    state = parse_qs(urlparse(r.headers['Location']).query)['state'][0]
    r = s.get(A + '/auth/google-callback.php?state=' + state + '&code=abc', allow_redirects=False)
    return r.headers.get('Location', '')
def token(html): return re.search(r'name="csrf_token" value="([0-9a-f]+)"', html).group(1)
def db(sql):
    return subprocess.run(['mysql', '--socket=/run/mysqld/mysqld.sock', '-uroot', 'expenseflow_test', '-N', '-e', sql], capture_output=True, text=True).stdout.strip()

db("DELETE FROM users WHERE email LIKE '%@pwtest.local'")
db("DELETE FROM rate_limit_hits WHERE bucket LIKE 'google\\_%'") # earlier runs must not hit the limits
env = dict(os.environ, EXPENSEFLOW_GOOGLE_TOKEN_URL='http://127.0.0.1:8081/mock_google_token.php', MOCK_GOOGLE_CLAIMS=CLAIMS)
procs = [subprocess.Popen(['php', '-S', '127.0.0.1:8081', '-t', os.path.join(ROOT, 'tests')], env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL),
         subprocess.Popen(['php', '-S', '127.0.0.1:8082', '-t', ROOT, 'router.php'], cwd=ROOT, env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)]
time.sleep(1.5)
if os.path.exists(CFG): os.rename(CFG, CFG + '.bak')
try:
    open(CFG, 'w').write("<?php return ['client_id'=>'test-id.apps.googleusercontent.com','client_secret'=>'x'];")
    ok(requests.get(A + '/auth/google-password.php', allow_redirects=False).headers.get('Location', '').endswith('login.php'), 'password page needs a signed-in person')

    # 1. brand-new person
    s = requests.Session()
    loc = google_signin(s, 'newbie@pwtest.local', 'pw-sub-1')
    ok(loc.endswith('/auth/google-password.php'), 'first Google sign-in lands on the password offer: ' + loc)
    page = s.get(A + '/auth/google-password.php').text
    ok('Set a password?' in page and 'Skip for now' in page, 'offer shows set + skip')
    t = token(page)
    r = s.post(A + '/auth/google-password.php', data={'csrf_token': 'bad', 'password': 'Abcdef#123', 'confirm_password': 'Abcdef#123'})
    ok('session expired' in r.text, 'wrong CSRF token refused')
    r = s.post(A + '/auth/google-password.php', data={'csrf_token': t, 'password': 'Abcdef#123', 'confirm_password': 'Different#1'})
    ok('do not match' in r.text, 'mismatch refused')
    r = s.post(A + '/auth/google-password.php', data={'csrf_token': token(r.text), 'password': 'short', 'confirm_password': 'short'})
    ok('Password must' in r.text, 'weak password refused')
    r = s.post(A + '/auth/google-password.php', data={'csrf_token': token(r.text), 'skip': '1'}, allow_redirects=False)
    ok(r.headers.get('Location', '').endswith('dashboard.php'), 'skip goes to the dashboard')
    ok(db("SELECT MAX(pw_state) FROM user_identities i JOIN users u ON u.id=i.user_id WHERE u.email='newbie@pwtest.local'") == '2', 'skip is remembered')
    prof = s.get(A + '/profile.php').text
    ok('google-password.php' in prof and 'Set a password' in prof and 'Current password' not in prof, 'Profile keeps offering a password (no "current password" box)')
    ok('Set a password' in s.get(A + '/auth/delete-account.php').text, 'delete-account points to the same page')

    # 2. signing in with Google again: straight in
    s2 = requests.Session()
    loc = google_signin(s2, 'newbie@pwtest.local', 'pw-sub-1')
    ok(loc.endswith('/dashboard.php'), 'second Google sign-in goes to the dashboard: ' + loc)
    ok(db("SELECT COUNT(*) FROM users WHERE email='newbie@pwtest.local'") == '1', 'still one account')

    # 3. set a password later from Profile, then use e-mail + password
    page = s2.get(A + '/auth/google-password.php').text
    r = s2.post(A + '/auth/google-password.php', data={'csrf_token': token(page), 'password': 'Abcdef#123', 'confirm_password': 'Abcdef#123'}, allow_redirects=False)
    ok(r.headers.get('Location', '').endswith('dashboard.php'), 'valid password saved')
    ok(db("SELECT MAX(pw_state) FROM user_identities i JOIN users u ON u.id=i.user_id WHERE u.email='newbie@pwtest.local'") == '0', 'state is "has a password"')
    ok('Current password' in s2.get(A + '/profile.php').text, 'Profile now shows the normal change-password form')
    ok(s2.get(A + '/auth/google-password.php', allow_redirects=False).headers.get('Location', '').endswith('dashboard.php'), 'password page is closed once a password exists')
    s3 = requests.Session()
    lp = s3.get(A + '/auth/login.php').text
    r = s3.post(A + '/auth/login.php', data={'csrf_token': token(lp), 'email': 'newbie@pwtest.local', 'password': 'Abcdef#123'}, allow_redirects=False)
    ok('dashboard' in r.headers.get('Location', '') or 'setup' in r.headers.get('Location', ''), 'e-mail + the new password signs in: ' + r.headers.get('Location', r.text[:80]))

    # 4. someone who already has an e-mail account with a password
    db("INSERT INTO users(name,email,password) VALUES('Old Hand','oldhand@pwtest.local','" + subprocess.run(['php', '-r', 'echo password_hash("Test#12345", PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout + "')")
    s4 = requests.Session()
    loc = google_signin(s4, 'oldhand@pwtest.local', 'pw-sub-2')
    ok(loc.endswith('/dashboard.php'), 'existing account: straight to the dashboard (no password offer): ' + loc)
    ok(db("SELECT COUNT(*) FROM users WHERE email='oldhand@pwtest.local'") == '1', 'no duplicate account for the same e-mail')
    ok(db("SELECT COUNT(*) FROM user_identities i JOIN users u ON u.id=i.user_id WHERE u.email='oldhand@pwtest.local'") == '1', 'Google linked to the existing account')
    ok(s4.get(A + '/auth/google-password.php', allow_redirects=False).headers.get('Location', '').endswith('dashboard.php'), 'no password offer for someone who has one')
finally:
    for pr in procs: pr.terminate()
    db("DELETE FROM users WHERE email LIKE '%@pwtest.local'")
    if os.path.exists(CFG): os.remove(CFG)
    if os.path.exists(CFG + '.bak'): os.rename(CFG + '.bak', CFG)
print('google_password_http_test: ALL PASSED' if not fails else f'{fails} FAILED')
sys.exit(1 if fails else 0)
