"""Report insight (Part 108d): the Smart insight sentence no longer repeats the savings-rate number that the Savings rate card already shows.
Usage: (EXPENSEFLOW_2FA_OFF=1 php -S 127.0.0.1:8080 -t . router.php)  python3 tests/report_insight_test.py"""
import subprocess, requests, re
B = 'http://127.0.0.1:8080'
fails = 0
def sql(q): return subprocess.run(['mysql', '--socket=/run/mysqld/mysqld.sock', '-uroot', 'expenseflow_test', '-N', '-e', q], capture_output=True, text=True).stdout.strip()
def ok(c, m):
    global fails
    print(('ok   ' if c else 'FAIL ') + m)
    if not c: fails += 1
h = subprocess.run(['php', '-r', 'echo password_hash("Test#12345",PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout
sql("DELETE FROM users WHERE email='ri@ri.local'"); sql("DELETE FROM rate_limit_hits")
sql(f"INSERT INTO users(name,email,password,role,is_admin) VALUES ('RI','ri@ri.local','{h}','user',0)")
uid = sql("SELECT id FROM users WHERE email='ri@ri.local'")
sql(f"INSERT INTO user_settings (user_id, savings_goal, notifications, setup_done) VALUES ({uid}, 20, 1, 1)")
s = requests.Session()
r = s.get(B + '/auth/login.php'); tok = re.search(r'name="csrf_token" value="([^"]+)"', r.text)
d = {'email': 'ri@ri.local', 'password': 'Test#12345'}
if tok: d['csrf_token'] = tok.group(1)
s.post(B + '/auth/login.php', data=d)
for inc, exp, word in ((1000, 100, 'Excellent'), (1000, 850, 'Good job'), (1000, 950, 'low'), (100, 1000, 'exceed')):
    sql(f"DELETE FROM income WHERE user_id={uid}"); sql(f"DELETE FROM expenses WHERE user_id={uid}")
    sql(f"INSERT INTO income (user_id,title,amount,currency,amount_pkr,payment_method,transaction_date) VALUES ({uid},'I',{inc},'PKR',{inc},'Bank',NOW())")
    sql(f"INSERT INTO expenses (user_id,title,amount,currency,amount_pkr,category,payment_method,transaction_date) VALUES ({uid},'E',{exp},'PKR',{exp},'Food','',NOW())")
    t = s.get(B + '/reports/view-report.php?from_date=2020-01-01&to_date=2030-01-01').text
    m = re.search(r'Smart insight</div><div[^>]*>(.*?)</div>', t, re.S)
    txt = m.group(1) if m else ''
    ok(word in txt, f'insight text for {word!r}: {txt[:60]}')
    ok('%' not in txt.replace('more than 30%', '').replace('30% of your income', ''), f'{word!r}: no repeated savings number')
sql("DELETE FROM users WHERE email='ri@ri.local'")
print('report_insight_test: ' + ('ALL PASSED' if not fails else f'{fails} FAILED')); raise SystemExit(1 if fails else 0)
