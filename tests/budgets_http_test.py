"""
Budgets page, alerts after adding an expense, dashboard card.
Usage: php -S 127.0.0.1:8080 -t . router.php   then   python3 tests/budgets_http_test.py   (TEST database)
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
sql("DELETE FROM users WHERE email='bud2@bud.local'"); sql("DELETE FROM rate_limit_hits")
sql(f"INSERT INTO users(name,email,password) VALUES ('Bud Two','bud2@bud.local','{h}')")
uid = sql("SELECT id FROM users WHERE email='bud2@bud.local'")

r = requests.get(B + '/budgets.php', allow_redirects=False)
ok(r.status_code == 302 and 'login.php' in r.headers.get('Location', ''), 'signed-out visitor sent to login')

s = requests.Session()
s.post(B + '/auth/login.php', data={'email': 'bud2@bud.local', 'password': 'Test#12345', 'csrf_token': token(s.get(B + '/auth/login.php').text)})
page = s.get(B + '/budgets.php').text
ok('Set a budget' in page and 'No budgets yet' in page, 'empty state shown')
ok('href="/budgets.php"' in s.get(B + '/dashboard.php').text.replace(B, '') or 'budgets.php' in s.get(B + '/dashboard.php').text, 'menu links to Budgets')

t = token(page)
r = s.post(B + '/budgets.php', data={'category': 'Food', 'amount': '1000', 'currency': 'PKR', 'csrf_token': 'bad'}, allow_redirects=False)
ok(sql(f"SELECT COUNT(*) FROM budgets WHERE user_id={uid}") == '0', 'bad CSRF saves nothing')
r = s.post(B + '/budgets.php', data={'category': 'Food', 'amount': '-3', 'currency': 'PKR', 'csrf_token': t})
ok('limit above zero' in r.text and sql(f"SELECT COUNT(*) FROM budgets WHERE user_id={uid}") == '0', 'negative amount refused with message')
r = s.post(B + '/budgets.php', data={'category': 'Hacker', 'amount': '10', 'currency': 'PKR', 'csrf_token': t})
ok('choose a category' in r.text, 'unknown category refused')
r = s.post(B + '/budgets.php', data={'category': 'Food', 'amount': '1000', 'currency': 'PKR', 'csrf_token': t}, allow_redirects=False)
ok(r.status_code == 302 and sql(f"SELECT amount FROM budgets WHERE user_id={uid}") == '1000.00', 'budget saved, redirect after POST')
ok('Budget saved' in s.get(B + '/budgets.php').text, 'success message shown')

# add an expense through the real form: crosses 80%
add = s.get(B + '/expense/add-expense.php').text
s.post(B + '/expense/add-expense.php', data={'amount': '850', 'currency': 'PKR', 'title': 'Dinner', 'category': 'Food', 'payment_method': 'cash', 'csrf_token': token(add)}, allow_redirects=False)
ok(sql(f"SELECT COUNT(*) FROM expenses WHERE user_id={uid}") == '1', 'expense added')
ok(sql(f"SELECT COUNT(*) FROM budget_alerts WHERE user_id={uid} AND level=80") == '1', '80% alert recorded')
notes = s.get(B + '/activity/notifications.php').text
ok('has used 85% of its monthly budget' in notes, 'bell notification text shown')
ok('Budgets' in notes, 'notification labelled Budgets, not Group')

page = s.get(B + '/budgets.php').text
ok('85% ·' in page and 'Close to the limit' in page and 'data-level="warn"' in page, 'page shows 85% warning')
dash = s.get(B + '/dashboard.php').text
ok('id="budget-card"' in dash and 'Food' in dash, 'dashboard card shown')

add = s.get(B + '/expense/add-expense.php').text
s.post(B + '/expense/add-expense.php', data={'amount': '300', 'currency': 'PKR', 'title': 'More', 'category': 'Food', 'payment_method': 'cash', 'csrf_token': token(add)}, allow_redirects=False)
ok(sql(f"SELECT COUNT(*) FROM budget_alerts WHERE user_id={uid} AND level=100") == '1', '100% alert recorded')
ok('went over its monthly budget' in s.get(B + '/activity/notifications.php').text and 'Over budget' in s.get(B + '/budgets.php').text, 'over-budget shown')

# update limit
t = token(s.get(B + '/budgets.php').text)
s.post(B + '/budgets.php', data={'category': 'Food', 'amount': '5000', 'currency': 'PKR', 'csrf_token': t})
ok(sql(f"SELECT COUNT(*) FROM budgets WHERE user_id={uid}") == '1' and sql(f"SELECT amount FROM budgets WHERE user_id={uid}") == '5000.00', 'same category updates the limit')

# another person cannot delete my budget
bid = sql(f"SELECT id FROM budgets WHERE user_id={uid}")
sql("DELETE FROM users WHERE email='bud3@bud.local'")
sql(f"INSERT INTO users(name,email,password) VALUES ('Bud Three','bud3@bud.local','{h}')")
o = requests.Session()
o.post(B + '/auth/login.php', data={'email': 'bud3@bud.local', 'password': 'Test#12345', 'csrf_token': token(o.get(B + '/auth/login.php').text)})
o.post(B + '/budgets.php', data={'do': 'delete', 'id': bid, 'csrf_token': token(o.get(B + '/budgets.php').text)})
ok(sql(f"SELECT COUNT(*) FROM budgets WHERE id={bid}") == '1', "someone else's delete does nothing")
s.post(B + '/budgets.php', data={'do': 'delete', 'id': bid, 'csrf_token': token(s.get(B + '/budgets.php').text)})
ok(sql(f"SELECT COUNT(*) FROM budgets WHERE id={bid}") == '0', 'owner can remove')

sql(f"DELETE FROM expenses WHERE user_id={uid}"); sql(f"DELETE FROM budget_alerts WHERE user_id={uid}")
sql("DELETE FROM users WHERE email IN ('bud2@bud.local','bud3@bud.local')")
print('budgets http tests:', 'ALL PASSED' if not fails else f'{fails} FAILED')
raise SystemExit(1 if fails else 0)
