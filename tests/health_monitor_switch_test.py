"""Website health: the "Outside monitor" warning can be switched off (Part 103b-fix).
Usage: PHP_CLI_SERVER_WORKERS=4 EXPENSEFLOW_2FA_OFF=1 php -S 127.0.0.1:8080 -t . router.php   then   python3 tests/health_monitor_switch_test.py"""
import re, os, subprocess, requests
B = 'http://127.0.0.1:8080'
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
FLAG = ROOT + '/storage/health-monitor-off'
fails = 0
def sql(q): return subprocess.run(['mysql', '--socket=/run/mysqld/mysqld.sock', '-uroot', 'expenseflow_test', '-N', '-e', q], capture_output=True, text=True).stdout.strip()
def ok(c, m):
    global fails
    print(('ok   ' if c else 'FAIL ') + m)
    if not c: fails += 1
if os.path.exists(FLAG): os.remove(FLAG)
h = subprocess.run(['php', '-r', 'echo password_hash("Test#12345",PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout
requests.get(B + '/auth/login.php')
sql("DELETE FROM users WHERE email LIKE '%@hm.local'"); sql("DELETE FROM rate_limit_hits")
sql(f"INSERT INTO users(name,email,password,role,is_admin) VALUES ('hm','sup@hm.local','{h}','super',1)")
s = requests.Session()
t = re.search(r'name="csrf_token" value="([^"]+)"', s.get(B + '/auth/login.php').text).group(1)
s.post(B + '/auth/login.php', data={'email': 'sup@hm.local', 'password': 'Test#12345', 'csrf_token': t}, allow_redirects=False)
sql("DELETE FROM health_log WHERE source='ping'")
sql("INSERT INTO health_log(created_at,kind,source,ok,db_ms) VALUES (NOW() - INTERVAL 6 HOUR,'check','ping',1,1.0)")
def row(html):
    m = re.search(r'<div class="row" data-state="(\w+)"><span>Outside monitor.*?</div>', html, re.S)
    return (m.group(1), m.group(0)) if m else (None, '')
def csrf(html): return re.search(r'name="csrf_token" value="([^"]+)"', html).group(1)
r = s.get(B + '/admin/health.php')
st, txt = row(r.text)
ok(st == 'warn' and 'Last ping 6' in txt, 'stale ping shows a warning')
ok('stopped or cannot reach the site' in txt, 'warning explains the cause and the way out')
ok('stop warning me' in r.text, 'switch-off button is shown')
r2 = s.post(B + '/admin/health-action.php', data={'do': 'monitor_off', 'csrf_token': csrf(r.text)}, allow_redirects=True)
st, txt = row(r2.text)
ok(st == 'ok' and 'Not used' in txt, 'after switching off the row is no longer a warning')
ok(os.path.exists(FLAG) and 'Switch the monitor check on again' in r2.text, 'choice is saved and the button flips')
ok(sql("SELECT COUNT(*) FROM user_logs WHERE action='admin_health_monitor'") != '0', 'change is written to the audit log')
r3 = s.post(B + '/admin/health-action.php', data={'do': 'monitor_on', 'csrf_token': csrf(r2.text)}, allow_redirects=True)
ok(row(r3.text)[0] == 'warn' and not os.path.exists(FLAG), 'switching on again brings the warning back')
ok(requests.post(B + '/admin/health-action.php', data={'do': 'monitor_off'}, allow_redirects=False).status_code in (302, 401, 403), 'not allowed without signing in')
ok(not os.path.exists(FLAG), 'no flag written by the signed-out request')
sql("DELETE FROM health_log WHERE source='ping'"); sql("DELETE FROM users WHERE email LIKE '%@hm.local'")
print('ALL PASSED' if not fails else f'{fails} FAILED'); raise SystemExit(1 if fails else 0)
