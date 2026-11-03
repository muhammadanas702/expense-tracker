"""
Part 48d: the phone sends new SMS in the background with its own key (no cookie/session).
Usage: php -S 127.0.0.1:8080 -t . router.php   (EXPENSEFLOW_2FA_OFF=1)   then   python3 tests/quickadd_device_token_http_test.py   (TEST database)
"""
import re, subprocess, requests, time
B = 'http://127.0.0.1:8080'
fails = 0
def sql(q): return subprocess.run(['mysql', '--socket=/run/mysqld/mysqld.sock', '-uroot', 'expenseflow_test', '-N', '-e', q], capture_output=True, text=True).stdout.strip()
def ok(c, m):
    global fails
    if not c: fails += 1; print('FAIL', m)
def token(html): return re.search(r'name="csrf_token" value="([^"]+)"', html).group(1)
h = subprocess.run(['php', '-r', 'echo password_hash("Test#12345",PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout
requests.get(B + '/auth/login.php')
sql("DELETE FROM users WHERE email='dt1@d.local'"); sql("DELETE FROM rate_limit_hits")
sql(f"INSERT INTO users(name,email,password) VALUES ('Dt 1','dt1@d.local','{h}')")
sql("INSERT INTO user_settings (user_id, savings_goal, notifications, setup_done) SELECT id, 20, 1, 1 FROM users WHERE email='dt1@d.local'")
uid = sql("SELECT id FROM users WHERE email='dt1@d.local'")
s = requests.Session()
s.post(B + '/auth/login.php', data={'email': 'dt1@d.local', 'password': 'Test#12345', 'csrf_token': token(s.get(B + '/auth/login.php').text)})
st = lambda: s.get(B + '/quick-add-import.php').json()
now = int(time.time() * 1000)
M = lambda i, text, frm='HBL', dt=None: {'id': i, 'from': frm, 'text': text, 'date': dt or now}

r = s.post(B + '/quick-add-import.php', json={'csrf': st()['csrf'], 'action': 'device_token'})
ok(r.status_code == 400, 'no key while auto-add is off')
page = s.get(B + '/quick-add.php').text
s.post(B + '/quick-add.php', data={'csrf_token': token(page), 'qa_action': 'auto_on'})
r = s.post(B + '/quick-add-import.php', json={'csrf': 'bad', 'action': 'device_token'}); ok(r.status_code == 403, 'key needs the csrf token')
tok = s.post(B + '/quick-add-import.php', json={'csrf': st()['csrf'], 'action': 'device_token'}).json().get('token', '')
ok(re.fullmatch(r'[0-9a-f]{64}', tok) is not None, 'phone gets a 64-hex key')
ok(sql(f"SELECT COUNT(*) FROM sms_devices WHERE user_id={uid} AND token_hash='{tok}'") == '0' and sql(f"SELECT COUNT(*) FROM sms_devices WHERE user_id={uid}") == '1', 'only a hash is stored, never the key')

# the phone posts with no cookies at all
msgs = [M(1, 'Rs. 1,500.00 debited from A/C 1234 at FOODPANDA'), M(2, 'Your OTP for payment of Rs 900 at DARAZ is 482913'), M(3, 'PKR 2,000 has been credited to your account from ALI KHAN')]
r = requests.post(B + '/quick-add-import.php', json={'token': tok, 'action': 'import', 'messages': msgs})
j = r.json()
ok(r.status_code == 200 and j['added'] == 2 and j['ignored'] == 1 and len(j['items']) == 2, f'background import: {j}')
ok('set-cookie' not in {k.lower() for k in r.headers}, 'no session cookie is created for the phone')
ok(j['items'][0]['title'] and j['items'][0]['currency'] == 'PKR', 'result lists what was added (for the notification)')
# same message again through the signed-in page: never twice
j = s.post(B + '/quick-add-import.php', json={'csrf': st()['csrf'], 'action': 'import', 'messages': msgs}).json()
ok(j['added'] == 0 and j['duplicate'] == 3, f'same messages from the page are duplicates: {j}')
ok(sql(f"SELECT COUNT(*) FROM expenses WHERE user_id={uid} AND note='From SMS'") == '1', 'one expense only')

# bad / wrong keys
ok(requests.post(B + '/quick-add-import.php', json={'token': 'a' * 64, 'action': 'import', 'messages': []}).status_code == 401, 'unknown key refused')
ok(requests.post(B + '/quick-add-import.php', json={'token': 'xyz', 'action': 'import', 'messages': []}).status_code == 401, 'malformed key refused')
ok(requests.post(B + '/quick-add-import.php', json={'token': tok, 'action': 'device_token'}).status_code == 401, 'the phone key cannot create more keys or do anything else')
ok(requests.get(B + '/quick-add-import.php?token=' + tok).status_code == 401, 'the key is not accepted in a URL')

# at most 5 phones
for _ in range(6): s.post(B + '/quick-add-import.php', json={'csrf': st()['csrf'], 'action': 'device_token'})
ok(sql(f"SELECT COUNT(*) FROM sms_devices WHERE user_id={uid}") == '5', 'at most 5 phone keys per account')

# turning auto-add off cancels every key
tok2 = s.post(B + '/quick-add-import.php', json={'csrf': st()['csrf'], 'action': 'device_token'}).json()['token']
s.post(B + '/quick-add.php', data={'csrf_token': token(s.get(B + '/quick-add.php').text), 'qa_action': 'auto_off'})
ok(sql(f"SELECT COUNT(*) FROM sms_devices WHERE user_id={uid}") == '0', 'auto-add off removes the keys')
ok(requests.post(B + '/quick-add-import.php', json={'token': tok2, 'action': 'import', 'messages': msgs}).status_code == 401, 'old key refused after turning off')

sql(f"DELETE FROM users WHERE id={uid}")
for t in ('sms_imports', 'sms_prefs', 'sms_rules', 'sms_devices', 'expenses', 'income'): sql(f"DELETE FROM {t} WHERE user_id={uid}")
print('quickadd device token tests:', 'ALL PASSED' if not fails else f'{fails} FAILED')
