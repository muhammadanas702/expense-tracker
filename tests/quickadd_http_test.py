"""
Quick add: paste an SMS, get the Add form pre-filled, nothing stored, sign-in and CSRF needed.
Usage: php -S 127.0.0.1:8080 -t . router.php   then   python3 tests/quickadd_http_test.py   (TEST database)
"""
import re, subprocess, requests, html
from urllib.parse import urlparse, parse_qs
B = 'http://127.0.0.1:8080'
fails = 0
def sql(q): return subprocess.run(['mysql', '--socket=/run/mysqld/mysqld.sock', '-uroot', 'expenseflow_test', '-N', '-e', q], capture_output=True, text=True).stdout.strip()
def ok(c, m):
    global fails
    if not c: fails += 1; print('FAIL', m)
def token(h): return re.search(r'name="csrf_token" value="([^"]+)"', h).group(1)
h = subprocess.run(['php', '-r', 'echo password_hash("Test#12345",PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout
requests.get(B + '/auth/login.php')
sql("DELETE FROM users WHERE email='qa1@q.local'"); sql("DELETE FROM rate_limit_hits")
sql(f"INSERT INTO users(name,email,password) VALUES ('QA One','qa1@q.local','{h}')")
uid = sql("SELECT id FROM users WHERE email='qa1@q.local'")

r = requests.get(B + '/quick-add.php', allow_redirects=False)
ok(r.status_code == 302 and 'login.php' in r.headers.get('Location', ''), 'signed-out visitor sent to login')
s = requests.Session()
s.post(B + '/auth/login.php', data={'email': 'qa1@q.local', 'password': 'Test#12345', 'csrf_token': token(s.get(B + '/auth/login.php').text)})
page = s.get(B + '/quick-add.php').text
ok('Read message' in page and 'quick-add.php' in s.get(B + '/dashboard.php').text, 'page and menu link')
t = token(page)
r = s.post(B + '/quick-add.php', data={'sms': 'Rs 1 debited at X', 'csrf_token': 'bad'})
ok('qa-result' not in r.text and 'session expired' in r.text, 'bad CSRF reads nothing')
r = s.post(B + '/quick-add.php', data={'sms': 'Your OTP is 1234', 'csrf_token': t})
ok('could not find an amount' in r.text and 'qa-result' not in r.text, 'no amount: friendly message')
r = s.post(B + '/quick-add.php', data={'sms': 'Rs. 1,500.00 debited from A/C 1234 at FOODPANDA on 01-11-26', 'csrf_token': t})
ok('id="qa-result"' in r.text and 'PKR 1,500.00' in r.text and 'Foodpanda' in r.text and 'Food' in r.text and 'Review and save' in r.text, 'result card shown')
link = html.unescape(re.search(r'href="([^"]*add-expense\.php\?[^"]+)"', r.text).group(1))
q = parse_qs(urlparse(link).query)
ok(q['amount'] == ['1500.00'] and q['title'] == ['Foodpanda'] and q['category'] == ['Food'], 'link carries the values: ' + link)
form = s.get(link).text
ok('value="1500.00"' in form and 'value="Foodpanda"' in form and re.search(r'value="Food"\s+checked', form), 'Add Expense form is pre-filled')
ok(sql(f"SELECT COUNT(*) FROM expenses WHERE user_id={uid}") == '0', 'nothing saved yet')
form_t = token(form)
s.post(link.split('?')[0].replace(B, '') and B + '/expense/add-expense.php', data={'amount': q['amount'][0], 'currency': 'PKR', 'title': q['title'][0], 'category': 'Food', 'payment_method': 'Cash', 'note': 'From SMS', 'csrf_token': form_t})
ok(sql(f"SELECT COUNT(*) FROM expenses WHERE user_id={uid} AND title='Foodpanda' AND note='From SMS'") == '1', 'saving the pre-filled form works')

r = s.post(B + '/quick-add.php', data={'sms': 'PKR 2,000 has been credited to your account from ALI KHAN', 'csrf_token': t})
ok('Income (money received)' in r.text and 'add-income.php?' in r.text, 'credit goes to the income form')
r = s.post(B + '/quick-add.php', data={'sms': 'Rs 500 at the shop', 'csrf_token': t})
ok('Save as expense' in r.text and 'Save as income' in r.text, 'unsure: both choices offered')
r = s.post(B + '/quick-add.php', data={'sms': 'Rs 99 paid at <script>alert(1)</script>', 'csrf_token': t})
ok('<script>alert(1)' not in r.text, 'markup in the SMS is escaped')
# crafted prefill links cannot inject
bad = s.get(B + '/expense/add-expense.php?title=%22%3E%3Cscript%3Ealert(1)%3C/script%3E&amount=abc&currency=ZZZ&category=Hack').text
ok('<script>alert(1)' not in bad and 'value="abc"' in bad and re.search(r'value="Food"\s+checked', bad), 'crafted link: escaped, unknown category ignored')
ok(sql(f"SELECT COUNT(*) FROM expenses WHERE user_id={uid}") == '1', 'only the one saved expense exists')

sql(f"DELETE FROM expenses WHERE user_id={uid}"); sql("DELETE FROM users WHERE email='qa1@q.local'")
print('quickadd http tests:', 'ALL PASSED' if not fails else f'{fails} FAILED')
raise SystemExit(1 if fails else 0)
