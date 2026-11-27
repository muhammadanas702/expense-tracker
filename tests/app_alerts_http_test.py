"""Part 130a: /app-alerts.php over HTTP. Run: python3 tests/app_alerts_http_test.py (server on 127.0.0.1:8080)"""
import re, subprocess, requests
B = 'http://127.0.0.1:8080'
def sql(q): return subprocess.run(['mysql', '-N', 'expenseflow_test', '-e', q], capture_output=True, text=True).stdout.strip()
fails = 0
def ok(c, m):
    global fails
    print(('ok   ' if c else 'FAIL ') + m); fails += 0 if c else 1
h = subprocess.run(['php', '-r', 'echo password_hash("Test#12345",PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout
sql("DELETE FROM users WHERE email='aah@t.local'")
sql(f"INSERT INTO users(name,email,password,role,is_admin) VALUES ('AAH','aah@t.local','{h}','user',0)")
uid = sql("SELECT id FROM users WHERE email='aah@t.local'")
sql(f"INSERT INTO user_settings (user_id, savings_goal, notifications, setup_done) VALUES ({uid}, 20, 1, 1)")
sql("DELETE FROM rate_limit_hits")
s = requests.Session()
r = s.get(B + '/auth/login.php'); tok = re.search(r'name="csrf_token" value="([^"]+)"', r.text)
d = {'email': 'aah@t.local', 'password': 'Test#12345'}
if tok: d['csrf_token'] = tok.group(1)
s.post(B + '/auth/login.php', data=d)
anon = requests.post(B + '/app-alerts.php', json={'action': 'poll', 'token': 'a' * 64})
ok(anon.status_code == 401, 'an unknown token is refused')
ok(requests.get(B + '/app-alerts.php').status_code == 401, 'no session and no token is refused')
g = s.get(B + '/app-alerts.php'); csrf = g.json().get('csrf')
ok(bool(csrf), 'a signed-in page gets a csrf token')
ok(s.post(B + '/app-alerts.php', json={'action': 'register', 'csrf': 'wrong'}).status_code == 403, 'register needs the csrf token')
t = s.post(B + '/app-alerts.php', json={'action': 'register', 'csrf': csrf}).json().get('token', '')
ok(re.fullmatch('[0-9a-f]{64}', t) is not None, 'register returns a token')
ok(requests.post(B + '/app-alerts.php', json={'action': 'poll', 'token': t}).json() == {'items': []}, 'poll with the token (no cookie) works and is empty')
sql(f"INSERT INTO app_inbox (user_id,title,body,url) VALUES ({uid},'Hi','there','dashboard.php')")
items = requests.post(B + '/app-alerts.php', json={'action': 'poll', 'token': t}).json()['items']
ok(len(items) == 1 and items[0]['title'] == 'Hi', 'a queued alert is handed to the phone')
ok(requests.post(B + '/app-alerts.php', json={'action': 'poll', 'token': t}).json()['items'] == [], 'and only once')
ok(requests.post(B + '/app-alerts.php', json={'action': 'register', 'csrf': 'x', 'token': t}).status_code == 401, 'a phone token cannot register more phones')
sql("DELETE FROM users WHERE email='aah@t.local'")
print('app_alerts_http_test: ' + ('ALL PASSED' if not fails else f'{fails} FAILED')); raise SystemExit(1 if fails else 0)
