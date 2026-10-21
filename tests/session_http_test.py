"""
Login lasts while the account is used: 3 days idle -> signed out with a message; any visit restarts the 3 days.
Local server + TEST database. Edits the session file in storage/sessions to fake the passing of time.
"""
import re, subprocess, requests, time, glob, os
B = 'http://127.0.0.1:8080'
fails = 0
def sql(q): return subprocess.run(['mysql', '-uroot', 'expenseflow_test', '-N', '-e', q], capture_output=True, text=True).stdout.strip()
def ok(c, m):
    global fails
    if not c: fails += 1; print('FAIL', m)
h = subprocess.run(['php', '-r', 'echo password_hash("Test#12345",PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout
requests.get(B + '/auth/login.php')
sql("DELETE FROM users WHERE email='s1@ses.local'"); sql("DELETE FROM rate_limit_hits")
sql(f"INSERT INTO users(name,email,password) VALUES ('ses','s1@ses.local','{h}')")
s = requests.Session()
t = re.search(r'name="csrf_token" value="([^"]+)"', s.get(B + '/auth/login.php').text).group(1)
r = s.post(B + '/auth/login.php', data={'email': 's1@ses.local', 'password': 'Test#12345', 'csrf_token': t}, allow_redirects=False)
ok(r.status_code in (302, 303), 'login works')
sc = r.headers.get('Set-Cookie', '') + s.get(B + '/profile.php', allow_redirects=False).headers.get('Set-Cookie', '')
m = re.search(r'expires=([^;]+)', sc, re.I)
ok(m is not None, 'cookie has an end date (survives closing the browser)')
if m:
    from email.utils import parsedate_to_datetime
    days = (parsedate_to_datetime(m.group(1)).timestamp() - time.time()) / 86400
    ok(days > 20, f'cookie lasts long (got {days:.1f} days)')
sid = s.cookies.get('PHPSESSID')
f = f'storage/sessions/sess_{sid}'
ok(os.path.exists(f), 'session stored in our own folder')
def age(days):
    data = open(f).read()
    new = re.sub(r'last_activity\|i:\d+;', f'last_activity|i:{int(time.time() - days * 86400)};', data)
    ok(new != data or days == 0, 'session file edited')
    open(f, 'w').write(new)
ok(s.get(B + '/profile.php', allow_redirects=False).status_code == 200, 'signed in')
age(2.5)   # unused for 2.5 days: still signed in, and the 3 days start again
ok(s.get(B + '/profile.php', allow_redirects=False).status_code == 200, 'after 2.5 idle days: still signed in')
age(2.5)
ok(s.get(B + '/profile.php', allow_redirects=False).status_code == 200, 'visit reset the clock: another 2.5 days is fine')
age(3.2)
r = s.get(B + '/profile.php', allow_redirects=False)
ok(r.status_code in (302, 303) and 'login' in r.headers.get('Location', ''), 'after 3.2 idle days: sent to login')
page = s.get(B + '/auth/login.php').text
ok('not used for 3 days' in page, 'login page explains why')
ok(s.get(B + '/profile.php', allow_redirects=False).status_code in (302, 303), 'really signed out')
sql("DELETE FROM users WHERE email='s1@ses.local'")
print('session tests:', 'ALL PASSED' if not fails else f'{fails} FAILED')
