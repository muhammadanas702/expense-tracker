"""
Part 67: Phone alerts endpoint and settings card. LOCAL server + TEST database (EXPENSEFLOW_PUSH_SINK is not set here, so no real push is sent:
the endpoints used are fake and the test only checks the HTTP behaviour).
Usage: python3 tests/push_http_test.py
"""
import re, subprocess, requests
B = 'http://127.0.0.1:8080'
fails = 0
def sql(q): return subprocess.run(['mysql', '--socket=/run/mysqld/mysqld.sock', '-uroot', 'expenseflow_test', '-N', '-e', q], capture_output=True, text=True).stdout.strip()
def ok(c, m):
    global fails
    if not c: fails += 1; print('FAIL', m)
h = subprocess.run(['php', '-r', 'echo password_hash("Test#12345",PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout
sql("DELETE FROM users WHERE email IN ('ph@ph.local','ph2@ph.local')")
for n, e in (('Ph', 'ph@ph.local'), ('Ph2', 'ph2@ph.local')):
    sql(f"INSERT INTO users(name,email,password) VALUES ('{n}','{e}','{h}')")
uid = sql("SELECT id FROM users WHERE email='ph@ph.local'")
def login(email):
    s = requests.Session()
    t = re.search(r'name="csrf_token" value="([^"]+)"', s.get(B + '/auth/login.php').text).group(1)
    s.post(B + '/auth/login.php', data={'email': email, 'password': 'Test#12345', 'csrf_token': t})
    return s
ok(requests.get(B + '/push-subscribe.php').status_code == 401, 'signed-out visitors are refused')
s = login('ph@ph.local')
g = s.get(B + '/push-subscribe.php'); j = g.json()
ok(g.status_code == 200 and len(j.get('key', '')) == 87 and j.get('csrf'), 'GET gives the public key (87 chars) and a csrf token')
sub = {'endpoint': 'https://push.example.com/send/http-test', 'keys': {'p256dh': j['key'], 'auth': 'BTBZMqHH6r4Tts7J_aSIgg'}}
ok(s.post(B + '/push-subscribe.php', json={'action': 'subscribe', 'subscription': sub}).status_code == 403, 'no csrf token: refused')
r = s.post(B + '/push-subscribe.php', json={'csrf': j['csrf'], 'action': 'subscribe', 'subscription': sub})
ok(r.status_code == 200 and r.json().get('devices') == 1, 'subscribe works')
ok(s.post(B + '/push-subscribe.php', json={'csrf': j['csrf'], 'action': 'subscribe', 'subscription': dict(sub, endpoint='http://x.example.com/a')}).status_code == 422, 'an http endpoint is refused')
ok(s.post(B + '/push-subscribe.php', json={'csrf': j['csrf'], 'action': 'nope'}).status_code == 400, 'unknown action refused')
s2 = login('ph2@ph.local'); j2 = s2.get(B + '/push-subscribe.php').json()
s2.post(B + '/push-subscribe.php', json={'csrf': j2['csrf'], 'action': 'unsubscribe', 'subscription': sub})
ok(sql(f"SELECT COUNT(*) FROM push_subscriptions WHERE user_id={uid}") == '1', "another user cannot remove my device")
r = s.post(B + '/push-subscribe.php', json={'csrf': j['csrf'], 'action': 'unsubscribe', 'subscription': sub})
ok(r.json().get('devices') == 0, 'unsubscribe works')
page = s.get(B + '/settings/notifications.php').text
ok('id="push-box"' in page and 'assets/push.js' in page and 'data-push-btn' in page, 'the settings card and script are on the page')
ok(requests.get(B + '/assets/push.js').status_code == 200, 'push.js is served')
sw = open('sw.js').read()
ok('addEventListener("push"' in sw and 'notificationclick' in sw and 'wanted.href.startsWith(APP_SCOPE)' in sw, 'service worker shows pushes and only opens pages of this app')
s.cookies.set('ef_lang', 'ur')
ok('فون الرٹس' in s.get(B + '/settings/notifications.php').text, 'card is in Urdu')
sql("DELETE FROM users WHERE email IN ('ph@ph.local','ph2@ph.local')")
print('push http tests:', 'ALL PASSED' if not fails else f'{fails} FAILED')
raise SystemExit(1 if fails else 0)
