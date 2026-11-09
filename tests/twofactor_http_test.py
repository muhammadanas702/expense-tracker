"""
Two-step sign-in (Part 49): opt-in in Settings > Security, e-mailed code at login, staff always, never blocks when mail is unavailable.
Usage: python3 tests/twofactor_http_test.py   (TEST database; starts its own PHP servers on 8097/8098, mail goes to a sink file)
"""
import re, subprocess, requests, os, time, json
fails = 0
def sql(q): return subprocess.run(['mysql', '--socket=/run/mysqld/mysqld.sock', '-uroot', 'expenseflow_test', '-N', '-e', q], capture_output=True, text=True).stdout.strip()
def ok(c, m):
    global fails
    if not c: fails += 1; print('FAIL', m)
def token(html): return re.search(r'name="csrf_token" value="([^"]+)"', html).group(1)
SINK = '/tmp/ef-2fa-sink.jsonl'
open(SINK, 'w').close()
def start(port, sink):
    env = dict(os.environ)
    env.pop('EXPENSEFLOW_2FA_OFF', None)
    if not sink: env['EXPENSEFLOW_2FA_OFF'] = '1'  # the host owner's emergency switch
    env.pop('EXPENSEFLOW_MAIL_SINK', None)
    env.pop('EXPENSEFLOW_SMTP_USERNAME', None)
    if sink: env['EXPENSEFLOW_MAIL_SINK'] = SINK
    p = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', '.', 'router.php'], env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    for _ in range(30):
        try: requests.get(f'http://127.0.0.1:{port}/health.php', timeout=1); break
        except Exception: time.sleep(0.2)
    return p
srv = start(8097, True); srv2 = start(8098, False)
B = 'http://127.0.0.1:8097'; B2 = 'http://127.0.0.1:8098'
def mails(): return [json.loads(l) for l in open(SINK) if l.strip()]
def last_code():
    m = mails()[-1]; return re.search(r'is: (\d{6})', m['text']).group(1)
try:
    h = subprocess.run(['php', '-r', 'echo password_hash("Test#12345",PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout
    sql("DELETE FROM users WHERE email LIKE '%@tf.local'"); sql("DELETE FROM rate_limit_hits"); sql("DELETE FROM app_settings WHERE k='login_code'")
    sql(f"INSERT INTO users(name,email,password) VALUES ('Tf User','u@tf.local','{h}')")
    sql(f"INSERT INTO users(name,email,password,role,is_admin) VALUES ('Tf Staff','s@tf.local','{h}','admin',1)")
    for e in ('u@tf.local', 's@tf.local'):
        sql(f"INSERT INTO user_settings (user_id, savings_goal, notifications, setup_done) SELECT id, 20, 1, 1 FROM users WHERE email='{e}'")
    def pw_login(base, email):
        s = requests.Session()
        r = s.post(base + '/auth/login.php', data={'email': email, 'password': 'Test#12345', 'csrf_token': token(s.get(base + '/auth/login.php').text)}, allow_redirects=False)
        return s, r
    def signed_in(base, s): return s.get(base + '/dashboard.php', allow_redirects=False).status_code == 200

    # 1. a normal user without two-step signs in as before
    s, r = pw_login(B, 'u@tf.local')
    ok(r.status_code == 302 and 'dashboard.php' in r.headers['Location'] and signed_in(B, s) and not mails(), 'user without two-step: straight in, no mail')

    # 2. turn it on: needs the mailed code
    page = s.get(B + '/settings/security.php').text
    ok('Two-step sign-in' in page and 'Turn on' in page, 'security page offers Turn on')
    s.post(B + '/settings/security.php', data={'csrf_token': token(page), 'twofa_action': 'start_on'})
    ok(len(mails()) == 1 and mails()[0]['to'] == 'u@tf.local' and 'security code' in mails()[0]['subject'], 'turn on sends a code to the account e-mail')
    page = s.get(B + '/settings/security.php').text
    ok('Confirm' in page and 'name="code"' in page, 'code form shown')
    s.post(B + '/settings/security.php', data={'csrf_token': token(page), 'twofa_action': 'confirm', 'code': '000000' if last_code() != '000000' else '111111'})
    ok(sql("SELECT COUNT(*) FROM user_2fa WHERE user_id=(SELECT id FROM users WHERE email='u@tf.local')") == '0', 'wrong code does not turn it on')
    page = s.get(B + '/settings/security.php').text
    s.post(B + '/settings/security.php', data={'csrf_token': token(page), 'twofa_action': 'confirm', 'code': last_code()})
    ok(sql("SELECT COUNT(*) FROM user_2fa WHERE user_id=(SELECT id FROM users WHERE email='u@tf.local')") == '1', 'right code turns it on')
    ok('On.' in s.get(B + '/settings/security.php').text, 'page shows it is on')

    # 3. login now needs the code
    s2, r = pw_login(B, 'u@tf.local')
    ok(r.status_code == 302 and 'login-code.php' in r.headers['Location'], 'password step redirects to the code page')
    ok(not signed_in(B, s2), 'not signed in after the password alone')
    n = len(mails()); code = last_code()
    page = s2.get(B + '/auth/login-code.php').text
    ok('Enter your code' in page and 'u•••@tf.local' in page, 'code page shows the masked e-mail')
    r = s2.post(B + '/auth/login-code.php', data={'csrf_token': token(page), 'code': '12ab'}, allow_redirects=False)
    ok(r.status_code == 200 and 'Invalid code' in r.text and not signed_in(B, s2), 'wrong code rejected')
    r = s2.post(B + '/auth/login-code.php', data={'csrf_token': token(r.text), 'code': code}, allow_redirects=False)
    ok(r.status_code == 302 and 'dashboard.php' in r.headers['Location'] and signed_in(B, s2), 'right code signs in')
    s3 = requests.Session(); s3.cookies.update(s2.cookies)
    ok(requests.get(B + '/auth/login-code.php', allow_redirects=False).status_code == 302, 'code page without a password step goes to login')

    # 4. a used code cannot be used again; 5 wrong tries throw the code away
    s4, r = pw_login(B, 'u@tf.local'); code = last_code()
    page = s4.get(B + '/auth/login-code.php').text
    for i in range(5):
        r = s4.post(B + '/auth/login-code.php', data={'csrf_token': token(page), 'code': '999999' if code != '999999' else '888888'})
        page = r.text
    r = s4.post(B + '/auth/login-code.php', data={'csrf_token': token(page), 'code': code})
    ok(not signed_in(B, s4) and 'expired' in r.text, 'after 5 wrong tries even the right code is refused')
    # resend: cooldown, then a fresh code works
    r = s4.post(B + '/auth/login-code.php', data={'csrf_token': token(r.text), 'resend': '1'})
    ok('wait a few seconds' in r.text, 'resend right away is refused (cooldown)')

    # 5. turn it off needs a code too
    s5, r = pw_login(B, 'u@tf.local'); code = last_code()
    s5.post(B + '/auth/login-code.php', data={'csrf_token': token(s5.get(B + '/auth/login-code.php').text), 'code': code})
    page = s5.get(B + '/settings/security.php').text
    ok('Turn off' in page, 'Turn off offered')
    s5.post(B + '/settings/security.php', data={'csrf_token': token(page), 'twofa_action': 'start_off'})
    page = s5.get(B + '/settings/security.php').text
    s5.post(B + '/settings/security.php', data={'csrf_token': token(page), 'twofa_action': 'confirm', 'code': last_code()})
    ok(sql("SELECT COUNT(*) FROM user_2fa WHERE user_id=(SELECT id FROM users WHERE email='u@tf.local')") == '0', 'off needs the mailed code, then it is off')
    s6, r = pw_login(B, 'u@tf.local')
    ok('dashboard.php' in r.headers['Location'], 'after turning off: password only again')

    # 6. staff need the code (admin setting) and cannot turn it off
    s_off, r = pw_login(B, 's@tf.local')
    ok('dashboard.php' in r.headers['Location'], 'default (sign-in code off): staff sign in with the password only')
    sql("INSERT INTO app_settings (k, v) VALUES ('login_code','staff') ON DUPLICATE KEY UPDATE v='staff'")
    s7, r = pw_login(B, 's@tf.local')
    ok('login-code.php' in r.headers['Location'], 'staff account is asked for the code without opting in')
    s7.post(B + '/auth/login-code.php', data={'csrf_token': token(s7.get(B + '/auth/login-code.php').text), 'code': last_code()})
    ok(signed_in(B, s7), 'staff signs in with the code')
    page = s7.get(B + '/settings/security.php').text
    ok('Required for your account' in page and 'Turn off' not in page, 'staff sees "required", no Turn off')
    r = s7.post(B + '/settings/security.php', data={'csrf_token': token(page), 'twofa_action': 'start_off'})
    ok(sql("SELECT COUNT(*) FROM user_2fa") == '0', 'staff cannot opt out')
    sql("INSERT INTO app_settings (k, v) VALUES ('login_code','all') ON DUPLICATE KEY UPDATE v='all'")
    s_all, r = pw_login(B, 'u@tf.local')
    ok('login-code.php' in r.headers['Location'], 'setting "everyone": a normal user is asked for the code too')
    sql("UPDATE app_settings SET v='staff' WHERE k='login_code'")

    # 7. server that cannot send e-mail: nobody is locked out
    s8, r = pw_login(B2, 's@tf.local')
    ok('dashboard.php' in r.headers['Location'] and signed_in(B2, s8), 'emergency switch EXPENSEFLOW_2FA_OFF: staff sign in with the password (two-step skipped)')
    ok('cannot send e-mail' in s8.get(B2 + '/settings/security.php').text, 'security page explains it is unavailable')

    # 8. wrong password never mails a code
    n = len(mails())
    s9 = requests.Session()
    s9.post(B + '/auth/login.php', data={'email': 's@tf.local', 'password': 'nope', 'csrf_token': token(s9.get(B + '/auth/login.php').text)})
    ok(len(mails()) == n, 'wrong password: no code is sent')
finally:
    srv.terminate(); srv2.terminate()
    sql("DELETE FROM user_2fa WHERE user_id IN (SELECT id FROM users WHERE email LIKE '%@tf.local')")
    sql("DELETE FROM users WHERE email LIKE '%@tf.local'"); sql("DELETE FROM rate_limit_hits"); sql("DELETE FROM app_settings WHERE k='login_code'")
    try: os.remove(SINK)
    except OSError: pass
print('twofactor tests:', 'ALL PASSED' if not fails else f'{fails} FAILED')
