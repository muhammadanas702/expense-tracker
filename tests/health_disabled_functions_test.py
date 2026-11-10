"""Website health must still open when the host disables functions (free hosts do): own test server on :8091 with
disk_free_space / disk_total_space disabled. In PHP 8 a disabled function is a fatal Error that @ cannot silence.
Usage: python3 tests/health_disabled_functions_test.py   (needs mysqld running with the expenseflow_test database)"""
import re, os, time, subprocess, requests
B = 'http://127.0.0.1:8091'
fails = 0
def sql(q): return subprocess.run(['mysql', '--socket=/run/mysqld/mysqld.sock', '-uroot', 'expenseflow_test', '-N', '-r', '-e', q], capture_output=True, text=True).stdout.strip()
def ok(c, m):
    global fails
    print(('ok   ' if c else 'FAIL ') + m)
    if not c: fails += 1
root = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
env = dict(os.environ, EXPENSEFLOW_2FA_OFF='1', PHP_CLI_SERVER_WORKERS='4')
srv = subprocess.Popen(['php', '-d', 'disable_functions=disk_free_space,disk_total_space', '-S', '127.0.0.1:8091', '-t', root, os.path.join(root, 'router.php')], cwd=root, env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
try:
    for _ in range(40):
        try: requests.get(B + '/health.php', timeout=1); break
        except Exception: time.sleep(0.25)
    h = subprocess.run(['php', '-r', 'echo password_hash("Test#12345",PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout
    sql("DELETE FROM users WHERE email='dis@hl.local'"); sql("DELETE FROM rate_limit_hits")
    sql(f"INSERT INTO users(name,email,password,role,is_admin) VALUES ('disname','dis@hl.local','{h}','super',1)")
    s = requests.Session()
    t = re.search(r'name="csrf_token" value="([^"]+)"', s.get(B + '/auth/login.php').text).group(1)
    s.post(B + '/auth/login.php', data={'email': 'dis@hl.local', 'password': 'Test#12345', 'csrf_token': t}, allow_redirects=False)
    r = s.get(B + '/admin/health.php')
    ok(r.status_code == 200, 'health page opens although disk functions are disabled')
    ok('The host does not report it' in r.text, 'disk row says the host does not report it')
    ok('Live checks' in r.text and 'Storage folders' in r.text, 'the other checks still show')
    ok(requests.get(B + '/health.php?db=1').status_code == 200, 'public health address still answers')
    t = re.search(r'name="csrf_token" value="([^"]+)"', r.text).group(1)
    ok(s.post(B + '/admin/health-action.php', data={'do': 'record', 'csrf_token': t}, allow_redirects=False).status_code in (302, 303), 'manual check works too')
    sql("DELETE FROM users WHERE email='dis@hl.local'")
finally:
    srv.terminate()
print('health_disabled_functions_test: ' + ('ALL PASSED' if not fails else f'{fails} FAILED'))
raise SystemExit(1 if fails else 0)
