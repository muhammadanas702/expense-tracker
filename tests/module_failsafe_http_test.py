"""A broken optional-module table must never give the dashboard or its own page a 500.
Usage: php -S 127.0.0.1:8080 -t . router.php   then   python3 tests/module_failsafe_http_test.py   (TEST database)"""
import re, subprocess, requests
B='http://127.0.0.1:8080'
def sql(q): return subprocess.run(['mysql','--socket=/run/mysqld/mysqld.sock','-uroot','expenseflow_test','-N','-e',q],capture_output=True,text=True).stdout.strip()
def tok(h): return re.search(r'name="csrf_token" value="([^"]+)"',h).group(1)
h=subprocess.run(['php','-r','echo password_hash("Test#12345",PASSWORD_DEFAULT);'],capture_output=True,text=True).stdout
sql("DELETE FROM users WHERE email='brk@b.local'"); sql("DELETE FROM rate_limit_hits")
sql(f"INSERT INTO users(name,email,password,is_admin) VALUES ('Brk','brk@b.local','{h}',1)")
s=requests.Session(); s.post(B+'/auth/login.php',data={'email':'brk@b.local','password':'Test#12345','csrf_token':tok(s.get(B+'/auth/login.php').text)})
s.get(B+'/budgets.php'); s.get(B+'/goals.php')   # marks the schema checks as done
sql("DROP TABLE budgets"); sql("DROP TABLE savings_goals")
fails=0
for p,msg in (('/dashboard.php',False),('/budgets.php',True),('/goals.php',True)):
    r=s.get(B+p); good=r.status_code==200 and 'ERROR 500' not in r.text and ('could not load' in r.text)==msg
    if not good: fails+=1; print('FAIL',p,r.status_code)
sql("DELETE FROM users WHERE email='brk@b.local'")

print('module failsafe tests:', 'ALL PASSED' if not fails else f'{fails} FAILED')
raise SystemExit(1 if fails else 0)
