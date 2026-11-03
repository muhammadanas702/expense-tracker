"""
Part 48c: automatic SMS import with the classifier (endpoint quick-add-import.php + Quick add page).
Usage: php -S 127.0.0.1:8080 -t . router.php   (start with EXPENSEFLOW_2FA_OFF=1)   then   python3 tests/quickadd_import_http_test.py   (TEST database)
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
sql("DELETE FROM users WHERE email IN ('qi1@q.local','qi2@q.local')"); sql("DELETE FROM rate_limit_hits")
for n in (1, 2):
    sql(f"INSERT INTO users(name,email,password) VALUES ('Qi {n}','qi{n}@q.local','{h}')")
    sql(f"INSERT INTO user_settings (user_id, savings_goal, notifications, setup_done) SELECT id, 20, 1, 1 FROM users WHERE email='qi{n}@q.local'")
uid = sql("SELECT id FROM users WHERE email='qi1@q.local'"); uid2 = sql("SELECT id FROM users WHERE email='qi2@q.local'")
def login(n):
    s = requests.Session()
    s.post(B + '/auth/login.php', data={'email': f'qi{n}@q.local', 'password': 'Test#12345', 'csrf_token': token(s.get(B + '/auth/login.php').text)})
    return s
def state(s): return s.get(B + '/quick-add-import.php').json()
def push(s, msgs, csrf=None):
    st = state(s)
    return s.post(B + '/quick-add-import.php', json={'csrf': csrf or st['csrf'], 'action': 'import', 'messages': msgs})
now = int(time.time() * 1000)
M = lambda i, frm, text, dt=None: {'id': i, 'from': frm, 'text': text, 'date': dt or now}

ok(requests.get(B + '/quick-add-import.php').status_code == 401, 'signed-out visitor refused')
s = login(1)
st = state(s)
ok(st['auto'] is False and st['csrf'], 'auto-add is off by default')
r = push(s, [M(1, 'HBL', 'Rs 100 debited at SHOP')]).json()
ok(r.get('off') is True and sql(f"SELECT COUNT(*) FROM expenses WHERE user_id={uid}") == '0', 'importing while off does nothing')
ok(push(s, [], csrf='bad').status_code == 403, 'bad csrf refused')

# earlier manual expense teaches the category for a title
page = s.get(B + '/quick-add.php').text
ok('Turn auto-add on' in page and 'Add payments automatically' in page, 'quick add page offers auto-add')
s.post(B + '/quick-add.php', data={'csrf_token': token(page), 'qa_action': 'auto_on'})
ok(state(s)['auto'] is True and state(s)['since'] > 0, 'auto-add on, "since" is now')
sql(f"INSERT INTO expenses(user_id,title,amount,currency,amount_pkr,category,transaction_date) VALUES ({uid},'Foodpanda',10,'PKR',10,'Shopping',NOW())")

batch = [
    M(11, 'HBL', 'Rs. 1,500.00 debited from A/C 1234 at FOODPANDA on 01-11-26. Avl Bal Rs 20,000'),
    M(12, 'MEEZAN', 'PKR 2,000 has been credited to your account from ALI KHAN. Ref 123'),
    M(13, 'HBL', 'Your OTP for payment of Rs 1,500 at DARAZ is 482913. Do not share it.'),
    M(14, 'Jazz', 'Win Rs 50,000 in our lucky draw! Dial *123# now'),
    M(15, 'HBL', 'Transaction of Rs 2,000 at DARAZ declined due to insufficient balance'),
    M(16, '+923001234567', 'I sent you Rs 500 for lunch'),
    M(17, 'UBL', 'Purchase of PKR 899.50 at CAREEM on your card ending 4321'),
    M(18, 'HBL', 'Rs 700 debited at SHOP', now - 5 * 86400 * 1000),
]
r = push(s, batch).json()
ok({k: v for k, v in r.items() if k != 'items'} == {'off': False, 'added': 3, 'review': 1, 'ignored': 3, 'duplicate': 0, 'old': 1}, f'counts: {r}')
rows = sql(f"SELECT title,amount,category FROM expenses WHERE user_id={uid} AND note='From SMS' ORDER BY id").split('\n')
ok(any(x.startswith('Foodpanda\t1500.00\tShopping') for x in rows), f'category learned from the last Foodpanda entry: {rows}')
ok(any(x.startswith('Careem\t899.50\tTransport') for x in rows), 'keyword category guess for a new merchant')
ok(sql(f"SELECT COUNT(*) FROM income WHERE user_id={uid} AND title='Ali Khan' AND amount=2000 AND note='From SMS'") == '1', 'income added')
ok(sql(f"SELECT COUNT(*) FROM expenses WHERE user_id={uid} AND amount IN (1500.00) AND title LIKE '%Daraz%'") == '0', 'OTP / declined never became expenses')
ok(sql(f"SELECT COUNT(*) FROM sms_imports WHERE user_id={uid} AND status='ignored' AND body IS NOT NULL") == '0', 'ignored messages keep no text')

# the same messages again: nothing is added twice
r = push(s, batch).json()
ok(r['added'] == 0 and r['duplicate'] == 7, f'same batch twice: {r}')
ok(sql(f"SELECT COUNT(*) FROM expenses WHERE user_id={uid} AND note='From SMS'") == '2', 'no duplicate expenses')

# review list
page = s.get(B + '/quick-add.php').text
ok('Needs your check (1)' in page and 'I sent you Rs 500 for lunch' in page and 'From a phone number' in page, 'review list shows the friend text')
ok('Added from SMS' in page and 'Undo' in page, 'recent list with Undo')
rid = sql(f"SELECT id FROM sms_imports WHERE user_id={uid} AND status='review'")
s2 = login(2)
s2.post(B + '/quick-add.php', data={'csrf_token': token(s2.get(B + '/quick-add.php').text), 'qa_action': 'review_add', 'id': rid, 'kind': 'expense'})
ok(sql(f"SELECT status FROM sms_imports WHERE id={rid}") == 'review', "another user cannot resolve my message")
s.post(B + '/quick-add.php', data={'csrf_token': token(page), 'qa_action': 'review_add', 'id': rid, 'kind': 'income'})
ok(sql(f"SELECT COUNT(*) FROM income WHERE user_id={uid} AND amount=500 AND note='From SMS'") == '1' and sql(f"SELECT status FROM sms_imports WHERE id={rid}") == 'added', 'review: add as income')
ok(sql(f"SELECT COUNT(*) FROM sms_imports WHERE id={rid} AND body IS NOT NULL") == '0', 'text removed once handled')

# ignore + always ignore sender
push(s, [M(31, 'PROMOBANK', 'Rs 300 sent to you? maybe'), M(32, 'DEALS', 'Rs 100 credited bonus offer')])
r = push(s, [M(33, '+923335550000', 'Sent you Rs 80')]).json()
ok(r['review'] == 1, 'second friend message goes to review')
rid2 = sql(f"SELECT id FROM sms_imports WHERE user_id={uid} AND status='review' ORDER BY id DESC LIMIT 1")
page = s.get(B + '/quick-add.php').text
s.post(B + '/quick-add.php', data={'csrf_token': token(page), 'qa_action': 'review_ignore', 'id': rid2, 'always': '1'})
ok(sql(f"SELECT COUNT(*) FROM sms_rules WHERE user_id={uid} AND sender_key='+923335550000'") == '1', 'always-ignore remembered')
r = push(s, [M(34, '+923335550000', 'Rs 90 received from me')]).json()
ok(r['ignored'] == 1 and r['review'] == 0, f'ignored sender stays ignored: {r}')

# undo
sid = sql(f"SELECT id FROM sms_imports WHERE user_id={uid} AND title='Careem' AND status='added'")
page = s.get(B + '/quick-add.php').text
s.post(B + '/quick-add.php', data={'csrf_token': token(page), 'qa_action': 'undo', 'id': sid})
ok(sql(f"SELECT COUNT(*) FROM expenses WHERE user_id={uid} AND title='Careem'") == '0', 'undo deletes the entry')
r = push(s, [M(17, 'UBL', 'Purchase of PKR 899.50 at CAREEM on your card ending 4321')]).json()
ok(r['added'] == 0 and sql(f"SELECT COUNT(*) FROM expenses WHERE user_id={uid} AND title='Careem'") == '0', 'an undone message is not added again')
sql(f"INSERT INTO expenses(user_id,title,amount,currency,amount_pkr,category,transaction_date,note) VALUES ({uid2},'Mine',5,'PKR',5,'Food',NOW(),'From SMS')")
s2.post(B + '/quick-add.php', data={'csrf_token': token(s2.get(B + '/quick-add.php').text), 'qa_action': 'undo', 'id': sid})
ok(sql(f"SELECT COUNT(*) FROM expenses WHERE user_id={uid2}") == '1', "undo of someone else's message touches nothing")

# turn off
page = s.get(B + '/quick-add.php').text
s.post(B + '/quick-add.php', data={'csrf_token': token(page), 'qa_action': 'auto_off'})
ok(state(s)['auto'] is False and push(s, [M(99, 'HBL', 'Rs 5 debited at X')]).json().get('off') is True, 'turn off stops imports')
# account deletion cleanup
sql(f"DELETE FROM users WHERE id IN ({uid},{uid2})")
sql(f"DELETE FROM sms_imports WHERE user_id IN ({uid},{uid2})"); sql(f"DELETE FROM sms_prefs WHERE user_id IN ({uid},{uid2})"); sql(f"DELETE FROM sms_rules WHERE user_id IN ({uid},{uid2})")
sql(f"DELETE FROM expenses WHERE user_id IN ({uid},{uid2})"); sql(f"DELETE FROM income WHERE user_id IN ({uid},{uid2})")
print('quickadd import tests:', 'ALL PASSED' if not fails else f'{fails} FAILED')
