"""Shared search (Part 108a): finds expenses/payments of the person's own groups only, filters work, and the Personal/Shared switch is on every main page (not on Profile/Notifications).
Usage: (EXPENSEFLOW_2FA_OFF=1 php -S 127.0.0.1:8080 -t . router.php)  python3 tests/shared_search_test.py"""
import subprocess, requests, re
B = 'http://127.0.0.1:8080'
fails = 0
def sql(q): return subprocess.run(['mysql', '--socket=/run/mysqld/mysqld.sock', '-uroot', 'expenseflow_test', '-N', '-e', q], capture_output=True, text=True).stdout.strip()
def ok(c, m):
    global fails
    print(('ok   ' if c else 'FAIL ') + m)
    if not c: fails += 1
h = subprocess.run(['php', '-r', 'echo password_hash("Test#12345",PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout
for e in ('ss1@ss.local', 'ss2@ss.local'): sql(f"DELETE FROM users WHERE email='{e}'")
sql("DELETE FROM rate_limit_hits")
for n, e in (('SS One', 'ss1@ss.local'), ('SS Two', 'ss2@ss.local')):
    sql(f"INSERT INTO users(name,email,password,role,is_admin) VALUES ('{n}','{e}','{h}','user',0)")
u1 = sql("SELECT id FROM users WHERE email='ss1@ss.local'"); u2 = sql("SELECT id FROM users WHERE email='ss2@ss.local'")
for u in (u1, u2): sql(f"INSERT INTO user_settings (user_id, savings_goal, notifications, setup_done) VALUES ({u}, 20, 1, 1)")
def group(owner, name, title, note):
    sql(f"INSERT INTO shared_groups(name,icon,owner_id,invite_code) VALUES ('{name}','users',{owner},MD5(RAND()))")
    g = sql("SELECT MAX(id) FROM shared_groups")
    sql(f"INSERT INTO shared_members(group_id,user_id,role) VALUES ({g},{owner},'owner')")
    sql(f"INSERT INTO shared_expenses(group_id,title,amount_minor,currency,paid_by,created_by,expense_date,note) VALUES ({g},'{title}',250000,'PKR',{owner},{owner},CURDATE(),'{note}')")
    return g
g1 = group(u1, 'SSTrip', 'Zebra Hotel', 'night one')
g2 = group(u2, 'SSOther', 'Zebra Secret', 'hidden')
sql(f"INSERT INTO shared_settlements(group_id,from_user,to_user,amount_minor,currency,settled_on,note,created_by) VALUES ({g1},{u1},{u1},1000,'PKR',CURDATE(),'cash zebra',{u1})")
s = requests.Session()
r = s.get(B + '/auth/login.php'); tok = re.search(r'name="csrf_token" value="([^"]+)"', r.text)
d = {'email': 'ss1@ss.local', 'password': 'Test#12345'}
if tok: d['csrf_token'] = tok.group(1)
s.post(B + '/auth/login.php', data=d)
def get(p): return s.get(B + p).text
t = get('/shared/search.php?q=zebra')
ok('Zebra Hotel' in t, 'finds an expense by title')
ok('Zebra Secret' not in t, "never shows another person's group")
ok('cash zebra' in t, 'finds a settle-up payment by note')
ok('2 results' in t, 'counts both matches')
t = get('/shared/search.php?q=zebra&type=expense'); ok('1 result' in t and 'Zebra Hotel' in t, 'type filter: expenses only')
t = get(f'/shared/search.php?q=zebra&group={g2}'); ok('Zebra Secret' not in t and '0 results' in t, 'group filter cannot reach a group you are not in')
t = get('/shared/search.php?q=nothingmatches'); ok('Nothing matches' in t, 'empty result message')
t = get('/shared/search.php?q=%27%20OR%201%3D1%20--'); ok('Nothing matches' in t, 'quotes in the query are harmless')
t = get('/shared/index.php'); ok('shared/search.php' in t, 'search bar on the Shared overview')
for p in ('/dashboard.php', '/shared/index.php', '/shared/groups.php', '/friends/index.php', '/activity/index.php', '/shared/search.php', f'/shared/group.php?id={g1}', '/shared/add.php'):
    ok('class="seg"' in get(p), f'switch visible on {p}')
for p in ('/profile.php', '/activity/notifications.php'):
    ok('class="seg"' not in get(p), f'no switch on neutral page {p}')
for e in ('ss1@ss.local', 'ss2@ss.local'): sql(f"DELETE FROM users WHERE email='{e}'")
sql(f"DELETE FROM shared_expenses WHERE group_id IN ({g1},{g2})"); sql(f"DELETE FROM shared_settlements WHERE group_id={g1}"); sql(f"DELETE FROM shared_members WHERE group_id IN ({g1},{g2})"); sql(f"DELETE FROM shared_groups WHERE id IN ({g1},{g2})")
print('shared_search_test: ' + ('ALL PASSED' if not fails else f'{fails} FAILED')); raise SystemExit(1 if fails else 0)
