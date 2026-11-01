"""
Tags in the add/edit form and the Search page.
Usage: php -S 127.0.0.1:8080 -t . router.php   then   python3 tests/search_http_test.py   (TEST database)
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
sql("DELETE FROM users WHERE email IN ('sh1@s.local','sh2@s.local')"); sql("DELETE FROM rate_limit_hits")
for n in (1, 2): sql(f"INSERT INTO users(name,email,password) VALUES ('Srch {n}','sh{n}@s.local','{h}')")
uid = sql("SELECT id FROM users WHERE email='sh1@s.local'")
def login(n):
    s = requests.Session()
    s.post(B + '/auth/login.php', data={'email': f'sh{n}@s.local', 'password': 'Test#12345', 'csrf_token': token(s.get(B + '/auth/login.php').text)})
    return s

r = requests.get(B + '/search.php', allow_redirects=False)
ok(r.status_code == 302 and 'login.php' in r.headers.get('Location', ''), 'signed-out visitor sent to login')
s = login(1)
ok('search.php' in s.get(B + '/dashboard.php').text, 'menu links to Search')
form = s.get(B + '/expense/add-expense.php').text
ok('name="tags"' in form, 'tags field on the expense form')
ok('name="tags"' in s.get(B + '/income/add-income.php').text, 'tags field on the income form')

def add(kind, title, amount, tags, extra=None):
    page = s.get(B + f'/{kind}/add-{kind}.php').text
    d = {'amount': amount, 'currency': 'PKR', 'title': title, 'payment_method': 'Cash', 'tags': tags, 'csrf_token': token(page)}
    if kind == 'expense': d['category'] = 'Food'
    return s.post(B + f'/{kind}/add-{kind}.php', data=d, allow_redirects=False)
r = add('expense', 'Bad tags', '10', 'a,b,c,d,e,f')
ok(sql(f"SELECT COUNT(*) FROM expenses WHERE user_id={uid}") == '0', 'six tags refused, nothing saved')
r = s.post(B + '/expense/add-expense.php', data={'amount': '10', 'currency': 'PKR', 'title': 'Bad', 'category': 'Food', 'payment_method': 'Cash', 'tags': 'a,b,c,d,e,f', 'csrf_token': token(form)})
ok('Tags: up to 5' in r.text and 'a,b,c,d,e,f' in r.text, 'message shown and the typed value kept')
add('expense', 'Hotel Murree', '20000', 'Trip, Family')
add('expense', 'Lunch', '800', '#trip')
add('expense', 'Plain', '50', '')
add('income', 'Trip refund', '1500', 'trip')
ok(sql(f"SELECT tags FROM expenses WHERE user_id={uid} AND title='Hotel Murree'") == ',trip,family,', 'tags stored cleaned')
ok(sql(f"SELECT tags FROM expenses WHERE user_id={uid} AND title='Plain'") == 'NULL', 'no tags stored as empty')

eid = sql(f"SELECT id FROM expenses WHERE user_id={uid} AND title='Lunch'")
edit = s.get(B + f'/edit-expense.php?id={eid}').text
ok('value="trip"' in edit, 'edit form shows saved tags')
s.post(B + f'/edit-expense.php?id={eid}', data={'amount': '800', 'currency': 'PKR', 'title': 'Lunch', 'category': 'Food', 'payment_method': 'Cash', 'tags': 'trip, office', 'csrf_token': token(edit)}, allow_redirects=False)
ok(sql(f"SELECT tags FROM expenses WHERE id={eid}") == ',trip,office,', 'edit saves tags')

page = s.get(B + '/search.php').text
ok('Search' in page and 'id="results"' not in page and '#trip' in page and '#office' in page, 'page opens without results, shows my tags')
res = s.get(B + '/search.php?tag=trip').text
ok('3 results' in res and 'Hotel Murree' in res and 'Trip refund' in res and 'Plain' not in res, 'tag filter: 3 results (2 expenses + 1 income)')
res = s.get(B + '/search.php?q=hotel').text
ok('1 result' in res and 'Hotel Murree' in res, 'text search')
res = s.get(B + '/search.php?q=family&type=expense').text
ok('1 result' in res, 'text finds tags, type filter')
res = s.get(B + '/search.php?type=income').text
ok('Trip refund' in res and 'Hotel Murree' not in res, 'income only')
res = s.get(B + '/search.php?q=nothing-matches-this').text
ok('0 results' in res and 'Nothing matches' in res, 'empty result message')
ok('edit-expense.php?id=' + eid in s.get(B + '/search.php?q=lunch').text, 'result links to edit page')
res = s.get(B + "/search.php?q=<script>alert(1)</script>&from=zzz&min=-3").text
ok('<script>alert(1)</script>' not in res and 'Nothing matches' in res, 'odd input is escaped, bad filters ignored')
res = s.get(B + "/search.php?q='%20OR%201=1--").text
ok('0 results' in res, 'SQL-looking input is just text')
o = login(2)
ok('Hotel Murree' not in o.get(B + '/search.php?tag=trip').text and '0 results' in o.get(B + '/search.php?tag=trip').text, 'other person finds nothing of mine')
ok('#trip' not in o.get(B + '/search.php').text, "other person does not see my tags")

sql(f"DELETE FROM expenses WHERE user_id={uid}"); sql(f"DELETE FROM income WHERE user_id={uid}")
sql("DELETE FROM users WHERE email IN ('sh1@s.local','sh2@s.local')")
print('search http tests:', 'ALL PASSED' if not fails else f'{fails} FAILED')
raise SystemExit(1 if fails else 0)
