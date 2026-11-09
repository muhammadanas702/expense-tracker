"""E-mail log: failures are recorded with a reason and shown to staff only. LOCAL test server + TEST database only.
Usage: php -S 127.0.0.1:8080 -t . router.php   then   python3 tests/mail_log_http_test.py   (server must have NO SMTP secrets)"""
import re, subprocess, sys, requests
B = 'http://127.0.0.1:8080'
fails = 0
def sql(q): return subprocess.run(['mysql', '-uroot', 'expenseflow_test', '-N', '-e', q], capture_output=True, text=True).stdout.strip()
def ok(c, m):
    global fails
    print(('ok   ' if c else 'FAIL ') + m)
    if not c: fails += 1
h = subprocess.run(['php', '-r', 'echo password_hash("Test#12345",PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout
sql("DELETE FROM users WHERE email LIKE '%@maillog.local'")
sql(f"INSERT INTO users(name,email,password,role,is_admin) VALUES ('mu','mu@maillog.local','{h}','user',0),('ms','ms@maillog.local','{h}','super',1)")
def login(n):
    s = requests.Session()
    t = re.search(r'name="csrf_token" value="([^"]+)"', s.get(B + '/auth/login.php').text).group(1)
    s.post(B + '/auth/login.php', data={'email': f'{n}@maillog.local', 'password': 'Test#12345', 'csrf_token': t}, allow_redirects=False)
    return s
U, S = login('mu'), login('ms')
ok(U.get(B + '/admin/mail.php', allow_redirects=False).status_code in (302, 303, 403), 'normal user cannot open the e-mail log')
ok(requests.get(B + '/admin/mail.php', allow_redirects=False).status_code in (302, 303, 403), 'signed-out visitor cannot open it')
r = S.get(B + '/admin/mail.php'); ok(r.status_code == 200 and 'Email log' in r.text, 'super admin can open it')

# a reset request for an address nobody uses is logged for staff, and the visitor sees the usual answer
v = requests.Session()
t = re.search(r'name="csrf_token" value="([^"]+)"', v.get(B + '/auth/forgot-password.php').text).group(1)
rp = v.post(B + '/auth/send-otp.php', data={'email': 'nobody.here@maillog.local', 'csrf_token': t}, allow_redirects=True)
ok('If an account exists' in rp.text, 'visitor gets the same answer for an unknown address')
ok(sql("SELECT COUNT(*) FROM mail_log WHERE ok=0 AND error LIKE 'Not sent: no account%' AND to_mask LIKE 'no•••%'") != '0', 'unknown address is logged as "no account" with a hidden address')
ok('nobody.here@maillog.local' not in S.get(B + '/admin/mail.php').text, 'the full address is never shown in the log')

# test mail without any SMTP set up fails with a clear reason
tok = re.search(r'name="csrf_token" value="([^"]+)"', S.get(B + '/admin/mail.php').text).group(1)
S.post(B + '/admin/mail-action.php', data={'to': 'ms@maillog.local', 'csrf_token': tok}, allow_redirects=True)
page = S.get(B + '/admin/mail.php').text
ok('Not sent' in page and ('not set up' in page or 'SMTP' in page or 'connect' in page.lower()), 'a failed test mail shows "Not sent" with the reason')
ok(U.post(B + '/admin/mail-action.php', data={'to': 'x@y.com', 'csrf_token': 'x'}, allow_redirects=False).status_code in (302, 303, 403), 'normal user cannot send a test mail')
sql("DELETE FROM users WHERE email LIKE '%@maillog.local'")
sql("DELETE FROM mail_log WHERE to_mask LIKE 'no•••%' OR to_mask LIKE 'ms•••%'")
print('mail_log_http_test: ' + ('ALL PASSED' if not fails else f'{fails} FAILED'))
sys.exit(1 if fails else 0)
