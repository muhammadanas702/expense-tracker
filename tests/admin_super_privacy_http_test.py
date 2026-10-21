"""Only Super Admins see Super Admin activity (audit log, user logs, overview, profile, user list)."""
import re, subprocess, requests
B = 'http://127.0.0.1:8080'
fails = 0
def sql(q): return subprocess.run(['mysql', '-uroot', 'expenseflow_test', '-N', '-e', q], capture_output=True, text=True).stdout.strip()
def ok(c, m):
    global fails
    if not c: fails += 1; print('FAIL', m)
h = subprocess.run(['php', '-r', 'echo password_hash("Test#12345",PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout
requests.get(B + '/auth/login.php')
sql("DELETE FROM users WHERE email LIKE '%@pv.local'"); sql("DELETE FROM admin_perms"); sql("DELETE FROM rate_limit_hits")
for n, r in {'boss': 'super', 'boss2': 'super', 'adm': 'admin'}.items():
    sql(f"INSERT INTO users(name,email,password,role,is_admin) VALUES ('{n}name','{n}@pv.local','{h}','{r}',1)")
bid = int(sql("SELECT id FROM users WHERE email='boss@pv.local'"))
sql(f"INSERT INTO user_logs(user_id,action,details,ip_address,created_at) VALUES ({bid},'admin_secret_move','SUPERONLYMARKER-audit','1.1.1.1',NOW()),({bid},'login','SUPERONLYMARKER-login','1.1.1.1',NOW())")
def login(n):
    s = requests.Session(); t = re.search(r'name="csrf_token" value="([^"]+)"', s.get(B + '/auth/login.php').text).group(1)
    s.post(B + '/auth/login.php', data={'email': f'{n}@pv.local', 'password': 'Test#12345', 'csrf_token': t}, allow_redirects=False); return s
sup, adm = login('boss2'), login('adm')
ok('SUPERONLYMARKER-audit' in sup.get(B + '/admin/audit.php').text, 'super sees super activity in the audit log')
ok('SUPERONLYMARKER-audit' not in adm.get(B + '/admin/audit.php').text, 'admin does not see it in the audit log')
ok('SUPERONLYMARKER-login' in sup.get(B + '/admin/logs.php').text, 'super sees it in user logs')
ok('SUPERONLYMARKER-login' not in adm.get(B + '/admin/logs.php').text, 'admin does not see it in user logs')
ok('SUPERONLYMARKER-audit' not in adm.get(B + '/admin/index.php').text, 'admin overview hides it')
ok('bossname' not in adm.get(B + '/admin/logs.php').text.split('<table')[-1] if '<table' in adm.get(B + '/admin/logs.php').text else True, 'no super rows for admin')
r = adm.get(B + f'/admin/view-user.php?id={bid}')
ok('SUPERONLYMARKER' not in r.text, 'admin does not see the super profile activity')
ok('SUPERONLYMARKER-login' in sup.get(B + f'/admin/view-user.php?id={bid}').text, 'super sees super profile activity')
sql("DELETE FROM user_logs WHERE details LIKE 'SUPERONLYMARKER%'"); sql("DELETE FROM users WHERE email LIKE '%@pv.local'")
print('super privacy tests:', 'ALL PASSED' if not fails else f'{fails} FAILED')
