"""
Part 50: Getting started checklist on the Dashboard + "Remind to settle up" between friends.
Usage: php -S 127.0.0.1:8080 -t . router.php   (EXPENSEFLOW_2FA_OFF=1)   then   python3 tests/part50_http_test.py   (TEST database)
"""
import re, subprocess, requests
B = 'http://127.0.0.1:8080'
fails = 0
def sql(q): return subprocess.run(['mysql', '--socket=/run/mysqld/mysqld.sock', '-uroot', 'expenseflow_test', '-N', '-e', q], capture_output=True, text=True).stdout.strip()
def ok(c, m):
    global fails
    if not c: fails += 1; print('FAIL', m)
def token(html): return re.search(r'name="csrf_token" value="([^"]+)"', html).group(1)
h = subprocess.run(['php', '-r', 'echo password_hash("Test#12345",PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout
requests.get(B + '/auth/login.php')
sql("DELETE FROM users WHERE email LIKE '%@p50.local'"); sql("DELETE FROM rate_limit_hits")
sql("DELETE FROM activity_notifications WHERE event_id IN (SELECT id FROM activity_events WHERE kind='settle_nudge')"); sql("DELETE FROM activity_events WHERE kind='settle_nudge'")
sql("DELETE FROM shared_groups WHERE invite_code='" + 'a' * 32 + "'")
for n in ('me', 'ali', 'old'):
    sql(f"INSERT INTO users(name,email,password) VALUES ('{n.title()}','{n}@p50.local','{h}')")
    sql(f"INSERT INTO user_settings (user_id, savings_goal, notifications, setup_done) SELECT id, 20, 1, 1 FROM users WHERE email='{n}@p50.local'")
sql("UPDATE users SET created_at = NOW() - INTERVAL 90 DAY WHERE email='old@p50.local'")
uid = {n: sql(f"SELECT id FROM users WHERE email='{n}@p50.local'") for n in ('me', 'ali', 'old')}
def login(n):
    s = requests.Session()
    s.post(B + '/auth/login.php', data={'email': f'{n}@p50.local', 'password': 'Test#12345', 'csrf_token': token(s.get(B + '/auth/login.php').text)})
    return s

# --- Getting started checklist
s = login('me'); d = s.get(B + '/dashboard.php').text
ok('id="gs-card"' in d and '0 of' in d and 'Add your first expense' in d, 'new account sees the checklist with nothing done')
sql(f"INSERT INTO expenses(user_id,title,amount,currency,amount_pkr,category,transaction_date) VALUES ({uid['me']},'Tea',50,'PKR',50,'Food',NOW())")
d = s.get(B + '/dashboard.php').text
ok('1 of' in d and 'line-through' in d, 'adding an expense ticks the first step')
ok('Set a monthly budget' in d and 'Create a savings goal' in d and 'Add a bill reminder' in d, 'optional module steps are listed')
so = login('old'); ok('id="gs-card"' not in so.get(B + '/dashboard.php').text, 'an account older than 45 days never sees it')
sql(f"INSERT INTO income(user_id,title,amount,currency,amount_pkr,transaction_date) VALUES ({uid['me']},'Pay',500,'PKR',500,NOW())")
d = s.get(B + '/dashboard.php').text
ok('2 of' in d, 'income ticks the second step')

# --- settle-up reminder: Ali owes Me
sql(f"INSERT INTO shared_groups(name,icon,owner_id,invite_code) VALUES ('Trip','users',{uid['me']},'{'a'*32}')")
gid = sql("SELECT id FROM shared_groups WHERE invite_code='" + 'a' * 32 + "'")
for n in ('me', 'ali'): sql(f"INSERT INTO shared_members(group_id,user_id,role) VALUES ({gid},{uid[n]},'member')")
sql(f"INSERT INTO shared_expenses(group_id,title,amount_minor,currency,paid_by,expense_date,created_by,split_type) VALUES ({gid},'Dinner',20000,'PKR',{uid['me']},CURDATE(),{uid['me']},'equal')")
eid = sql("SELECT MAX(id) FROM shared_expenses")
for n in ('me', 'ali'): sql(f"INSERT INTO shared_splits(expense_id,user_id,share_minor) VALUES ({eid},{uid[n]},10000)")
v = s.get(B + f"/friends/view.php?id={uid['ali']}").text
ok('Remind Ali to settle up' in v, 'creditor sees the Remind button')
sa = login('ali')
ok('Remind Me to settle up' not in sa.get(B + f"/friends/view.php?id={uid['me']}").text, 'debtor does not see a Remind button')
r = s.post(B + '/friends/action.php', data={'csrf_token': token(v), 'do': 'nudge', 'id': uid['ali']}, allow_redirects=True)
ok('Reminder sent to Ali' in r.text, 'reminder sent')
ok(sql(f"SELECT COUNT(*) FROM activity_notifications n JOIN activity_events e ON e.id=n.event_id WHERE n.user_id={uid['ali']} AND e.kind='settle_nudge' AND e.actor_id={uid['me']}") == '1', 'Ali has one new notification')
ok(sql(f"SELECT COUNT(*) FROM activity_notifications n JOIN activity_events e ON e.id=n.event_id WHERE n.user_id={uid['me']} AND e.kind='settle_nudge'") == '0', 'the sender gets no notification')
ok(sql(f"SELECT amount_minor FROM activity_events WHERE kind='settle_nudge' ORDER BY id DESC LIMIT 1") == '10000', 'the reminder carries the amount owed')
v = s.get(B + f"/friends/view.php?id={uid['ali']}").text
r = s.post(B + '/friends/action.php', data={'csrf_token': token(v), 'do': 'nudge', 'id': uid['ali']}, allow_redirects=True)
ok('already reminded' in r.text and sql(f"SELECT COUNT(*) FROM activity_events WHERE kind='settle_nudge'") == '1', 'second reminder within 3 days is refused')
# someone who owes nothing / not a friend
r = sa.post(B + '/friends/action.php', data={'csrf_token': token(sa.get(B + '/settings/security.php').text), 'do': 'nudge', 'id': uid['me']}, allow_redirects=True)
ok('does not owe you' in r.text and sql(f"SELECT COUNT(*) FROM activity_events WHERE kind='settle_nudge'") == '1', 'the debtor cannot "remind" the creditor')
r = s.post(B + '/friends/action.php', data={'csrf_token': token(v), 'do': 'nudge', 'id': uid['old']}, allow_redirects=True)
ok('does not owe you' in r.text or 'not found' in r.text.lower(), 'a stranger cannot be reminded')
ok(s.post(B + '/friends/action.php', data={'do': 'nudge', 'id': uid['ali']}, allow_redirects=False).status_code in (302, 403, 400), 'no csrf token: refused')
ok(sql(f"SELECT COUNT(*) FROM activity_events WHERE kind='settle_nudge'") == '1', 'still one event')

# cleanup
sql(f"DELETE FROM shared_splits WHERE expense_id={eid}"); sql(f"DELETE FROM shared_expenses WHERE group_id={gid}"); sql(f"DELETE FROM shared_members WHERE group_id={gid}"); sql(f"DELETE FROM shared_groups WHERE id={gid}")
sql("DELETE FROM activity_notifications WHERE event_id IN (SELECT id FROM activity_events WHERE kind='settle_nudge')"); sql("DELETE FROM activity_events WHERE kind='settle_nudge'")
sql(f"DELETE FROM expenses WHERE user_id={uid['me']}"); sql(f"DELETE FROM income WHERE user_id={uid['me']}")
sql("DELETE FROM users WHERE email LIKE '%@p50.local'"); sql("DELETE FROM rate_limit_hits")
print('part 50 tests:', 'ALL PASSED' if not fails else f'{fails} FAILED')
