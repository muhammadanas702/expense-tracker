"""
Savings goals page: create, add / take out money, progress, deadline hint, ownership.
Usage: php -S 127.0.0.1:8080 -t . router.php   then   python3 tests/goals_http_test.py   (TEST database)
"""
import re, subprocess, requests, datetime
B = 'http://127.0.0.1:8080'
fails = 0
def sql(q): return subprocess.run(['mysql', '--socket=/run/mysqld/mysqld.sock', '-uroot', 'expenseflow_test', '-N', '-e', q], capture_output=True, text=True).stdout.strip()
def ok(c, m):
    global fails
    if not c: fails += 1; print('FAIL', m)
def token(html): return re.search(r'name="csrf_token" value="([^"]+)"', html).group(1)
h = subprocess.run(['php', '-r', 'echo password_hash("Test#12345",PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout
requests.get(B + '/auth/login.php')
sql("DELETE FROM users WHERE email IN ('gl1@g.local','gl2@g.local')"); sql("DELETE FROM rate_limit_hits")
for n in (1, 2): sql(f"INSERT INTO users(name,email,password) VALUES ('Goal {n}','gl{n}@g.local','{h}')")
uid = sql("SELECT id FROM users WHERE email='gl1@g.local'")
def login(n):
    s = requests.Session()
    s.post(B + '/auth/login.php', data={'email': f'gl{n}@g.local', 'password': 'Test#12345', 'csrf_token': token(s.get(B + '/auth/login.php').text)})
    return s

r = requests.get(B + '/goals.php', allow_redirects=False)
ok(r.status_code == 302 and 'login.php' in r.headers.get('Location', ''), 'signed-out visitor sent to login')
s = login(1)
page = s.get(B + '/goals.php').text
ok('No goals yet' in page and 'New goal' in page, 'empty state shown')
ok('goals.php' in s.get(B + '/dashboard.php').text, 'menu links to Goals')
t = token(page)
r = s.post(B + '/goals.php', data={'do': 'create', 'name': 'Laptop', 'target': '1000', 'currency': 'PKR', 'csrf_token': 'bad'}, allow_redirects=False)
ok(sql(f"SELECT COUNT(*) FROM savings_goals WHERE user_id={uid}") == '0', 'bad CSRF creates nothing')
r = s.post(B + '/goals.php', data={'do': 'create', 'name': 'Laptop', 'target': '0', 'currency': 'PKR', 'csrf_token': t})
ok('target above zero' in r.text, 'zero target refused with message')
r = s.post(B + '/goals.php', data={'do': 'create', 'name': 'Laptop', 'target': '1000', 'currency': 'PKR', 'deadline': '2020-01-01', 'csrf_token': t})
ok('future date' in r.text and sql(f"SELECT COUNT(*) FROM savings_goals WHERE user_id={uid}") == '0', 'past deadline refused')
dl = (datetime.date.today() + datetime.timedelta(days=95)).isoformat()
r = s.post(B + '/goals.php', data={'do': 'create', 'name': '<b>Laptop</b>', 'target': '1000', 'currency': 'PKR', 'deadline': dl, 'csrf_token': t}, allow_redirects=False)
ok(r.status_code == 302 and sql(f"SELECT COUNT(*) FROM savings_goals WHERE user_id={uid}") == '1', 'goal created, redirect after POST')
page = s.get(B + '/goals.php').text
ok('Goal created' in page and '&lt;b&gt;Laptop' in page and '<b>Laptop</b>' not in page, 'success shown, name escaped')
gid = sql(f"SELECT id FROM savings_goals WHERE user_id={uid}")
ok('save about' in page and 'a month' in page, 'per-month hint shown')

t = token(page)
s.post(B + '/goals.php', data={'do': 'add', 'id': gid, 'amount': '250', 'csrf_token': t})
page = s.get(B + '/goals.php').text
ok('PKR 250.00 of 1,000.00' in page and '25%' in page and '750.00 to go' in page, 'progress 25% after adding 250')
r = s.post(B + '/goals.php', data={'do': 'take', 'id': gid, 'amount': '999', 'csrf_token': t})
ok('only have 250.00' in r.text and sql(f"SELECT SUM(amount) FROM goal_entries WHERE goal_id={gid}") == '250.00', 'cannot take out more than saved')
s.post(B + '/goals.php', data={'do': 'take', 'id': gid, 'amount': '50', 'csrf_token': t})
ok(sql(f"SELECT SUM(amount) FROM goal_entries WHERE goal_id={gid}") == '200.00', 'take out works')
s.post(B + '/goals.php', data={'do': 'add', 'id': gid, 'amount': '800', 'csrf_token': t})
page = s.get(B + '/goals.php').text
ok('Reached' in page and 'data-done="1"' in page and 'Goal complete' in page, 'goal shown as reached')

o = login(2)
ok('Laptop' not in o.get(B + '/goals.php').text, "other person does not see my goal")
o.post(B + '/goals.php', data={'do': 'add', 'id': gid, 'amount': '5', 'csrf_token': token(o.get(B + '/goals.php').text)})
o.post(B + '/goals.php', data={'do': 'delete', 'id': gid, 'csrf_token': token(o.get(B + '/goals.php').text)})
ok(sql(f"SELECT COUNT(*) FROM savings_goals WHERE id={gid}") == '1' and sql(f"SELECT COUNT(*) FROM goal_entries WHERE goal_id={gid}") == '3', "other person cannot add to or delete my goal")
s.post(B + '/goals.php', data={'do': 'delete', 'id': gid, 'csrf_token': token(s.get(B + '/goals.php').text)})
ok(sql(f"SELECT COUNT(*) FROM savings_goals WHERE id={gid}") == '0' and sql(f"SELECT COUNT(*) FROM goal_entries WHERE goal_id={gid}") == '0', 'owner can remove goal and history')

sql("DELETE FROM users WHERE email IN ('gl1@g.local','gl2@g.local')")
print('goals http tests:', 'ALL PASSED' if not fails else f'{fails} FAILED')
raise SystemExit(1 if fails else 0)
