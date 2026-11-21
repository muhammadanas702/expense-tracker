"""Part 110a: the group page puts the person's own balance first, hides detail behind taps, and keeps every action working.
Usage: (EXPENSEFLOW_2FA_OFF=1 php -S 127.0.0.1:8080 -t . router.php)  python3 tests/shared_group_clarity_test.py"""
import subprocess, requests, re
B = 'http://127.0.0.1:8080'
fails = 0
def sql(q): return subprocess.run(['mysql', '--socket=/run/mysqld/mysqld.sock', '-uroot', 'expenseflow_test', '-N', '-e', q], capture_output=True, text=True).stdout.strip()
def ok(c, m):
    global fails
    print(('ok   ' if c else 'FAIL ') + m)
    if not c: fails += 1
h = subprocess.run(['php', '-r', 'echo password_hash("Test#12345",PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout
for e in ('gc1@gc.local', 'gc2@gc.local'): sql(f"DELETE FROM users WHERE email='{e}'")
sql("DELETE FROM rate_limit_hits")
for n, e in (('GC One', 'gc1@gc.local'), ('GC Two', 'gc2@gc.local')):
    sql(f"INSERT INTO users(name,email,password,role,is_admin) VALUES ('{n}','{e}','{h}','user',0)")
u1 = sql("SELECT id FROM users WHERE email='gc1@gc.local'"); u2 = sql("SELECT id FROM users WHERE email='gc2@gc.local'")
for u in (u1, u2): sql(f"INSERT INTO user_settings (user_id, savings_goal, notifications, setup_done) VALUES ({u}, 20, 1, 1)")
sql(f"INSERT INTO shared_groups(name,icon,owner_id,invite_code) VALUES ('GCTrip','users',{u1},MD5(RAND()))")
g = sql("SELECT MAX(id) FROM shared_groups")
for u, r in ((u1, 'owner'), (u2, 'member')): sql(f"INSERT INTO shared_members(group_id,user_id,role) VALUES ({g},{u},'{r}')")
sql(f"INSERT INTO shared_expenses(group_id,title,amount_minor,currency,paid_by,created_by,expense_date,split_type) VALUES ({g},'Hotel',200000,'PKR',{u2},{u2},CURDATE(),'equal')")
x = sql("SELECT MAX(id) FROM shared_expenses")
for u in (u1, u2): sql(f"INSERT INTO shared_splits(expense_id,user_id,share_minor) VALUES ({x},{u},100000)")
def login(email):
    s = requests.Session()
    r = s.get(B + '/auth/login.php'); tok = re.search(r'name="csrf_token" value="([^"]+)"', r.text)
    d = {'email': email, 'password': 'Test#12345'}
    if tok: d['csrf_token'] = tok.group(1)
    s.post(B + '/auth/login.php', data=d); return s
s1 = login('gc1@gc.local'); s2 = login('gc2@gc.local')
t = s1.get(f'{B}/shared/group.php?id={g}').text
ok('You owe' in t and '1,000.00' in t, 'debtor sees "You owe <amount>" at the top')
ok(t.index('sg-me') < t.index('Settle up') < t.index('Expenses'), 'order: balance, settle up, expenses')
ok('Who pays whom' not in t and 'Net per person' not in t, 'old confusing headings are gone')
ok('Add expense' in t and 'Record a payment' in t, 'both main actions are at the top')
ok('<summary>Details' in t and '>Edit<' not in t.split('<summary>Details')[0], 'expense actions sit inside Details')
ok('Edit</a>' in t and 'Delete</a>' in t, 'Edit and Delete have words, not only icons')
ok('<summary>Everyone\'s balance' in t and '<summary>Group settings' in t, 'balance table and settings are collapsed cards')
ok('Delete group' in t, 'owner still finds Delete group (inside settings)')
t2 = s2.get(f'{B}/shared/group.php?id={g}').text
ok('You get' in t2, 'the person who paid sees "You get <amount>"')
ok('<summary>Leave group' in t2 and 'Group settings' not in t2, 'member sees Leave group, not owner settings')
# actions still work
r = s1.get(f'{B}/shared/group.php?id={g}'); tok = re.search(r'data-csrf="([^"]+)"', r.text).group(1)
r = s1.post(f'{B}/shared/action.php', data={'do': 'delete_expense', 'group_id': g, 'expense_id': x, 'csrf_token': tok}, allow_redirects=True)
ok(sql(f"SELECT COUNT(*) FROM shared_expenses WHERE id={x}") == '0', 'the Delete action still deletes the expense')
t = s1.get(f'{B}/shared/group.php?id={g}').text
ok('All settled up' in t or 'No expenses yet' in t, 'empty state wording after the delete')
o = s1.get(f'{B}/shared/index.php').text
ok('You will get' in o and 'You have to pay' in o and 'Debts stay person' not in o, 'overview uses plain wording')
for e in ('gc1@gc.local', 'gc2@gc.local'): sql(f"DELETE FROM users WHERE email='{e}'")
sql(f"DELETE FROM shared_splits WHERE expense_id={x}"); sql(f"DELETE FROM shared_expenses WHERE group_id={g}"); sql(f"DELETE FROM shared_members WHERE group_id={g}"); sql(f"DELETE FROM shared_groups WHERE id={g}")
print('shared_group_clarity_test: ' + ('ALL PASSED' if not fails else f'{fails} FAILED')); raise SystemExit(1 if fails else 0)
