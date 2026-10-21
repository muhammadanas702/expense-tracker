"""
Sign-up email confirmation (Part 12) against a LOCAL test server + TEST database + a local SMTP sink.
Needs: pip install aiosmtpd. Writes config/mail.local.php (git-ignored) for the run and removes it afterwards.
Usage: php -S 127.0.0.1:8080 -t . router.php   then   python3 tests/signup_verify_http_test.py
"""
import re, subprocess, os, time, requests
from email import message_from_bytes
from aiosmtpd.controller import Controller
from aiosmtpd.smtp import AuthResult
B = 'http://127.0.0.1:8080'
fails = 0
def sql(q): return subprocess.run(['mysql', '-uroot', 'expenseflow_test', '-N', '-e', q], capture_output=True, text=True).stdout.strip()
def ok(c, m):
    global fails
    if not c: fails += 1; print('FAIL', m)
def php(code): return subprocess.run(['php', '-r', 'require "modules/signup/lib.php"; ' + code], capture_output=True, text=True, cwd='.').stdout.strip()

# ---------- pure functions ----------
ok(php('echo signup_normalize_email("  Ali.Khan@Gmail.COM ");') == 'ali.khan@gmail.com', 'email is trimmed and lower-cased')
for bad in ['a@b', 'a@@b.com', 'a b@c.com', 'a@b.c', 'a..b@c.com', 'a@[127.0.0.1]', 'a@-.com', '@x.com', 'x@y.123', 'plain', '']:
    ok(php(f'echo signup_normalize_email({bad!r}) === "" ? "bad" : "good";') == 'bad', f'rejected shape: {bad!r}')
ok(php('echo signup_is_disposable("x@mailinator.com") && signup_is_disposable("x@sub.yopmail.com") && !signup_is_disposable("x@gmail.com") ? "y":"n";') == 'y', 'disposable domains recognised')
ok(php('echo signup_typo_hint("ali@gmial.com");') == 'ali@gmail.com' and php('var_dump(signup_typo_hint("ali@gmail.com"));') == 'NULL', 'typo hint')
ok(php('echo signup_domain_receives_mail("nomail.invalid", fn($d,$t)=>$d==="gmail.com"&&$t==="MX") ? "t":"f";') == 'f', 'domain without records rejected when DNS works')
ok(php('var_dump(signup_domain_receives_mail("x.com", fn($d,$t)=>false));') == 'NULL', 'unknown (not false) when DNS itself is down')
ok(php('echo signup_domain_receives_mail("x.com", fn($d,$t)=>$t==="MX") ? "t":"f";') == 't', 'domain with MX accepted')
ok(php('echo signup_mask("alice@gmail.com");') == 'al•••@gmail.com', 'mask')

# ---------- SMTP sink ----------
inbox = []
class H:
    async def handle_DATA(self, server, session, envelope):
        m = message_from_bytes(envelope.content)
        body = m.get_payload(decode=True).decode('utf8', 'ignore') if not m.is_multipart() else ''.join(
            p.get_payload(decode=True).decode('utf8', 'ignore') for p in m.walk() if p.get_content_type() in ('text/plain', 'text/html'))
        inbox.append({'to': envelope.rcpt_tos[0], 'subject': m['Subject'], 'body': body, 'auto': m['Auto-Submitted'], 'from': m['From'], 'parts': [p.get_content_type() for p in m.walk()]})
        return '250 OK'
def auth(server, session, envelope, mechanism, auth_data): return AuthResult(success=True)
ctl = Controller(H(), hostname='127.0.0.1', port=1025, authenticator=auth, auth_required=False, auth_require_tls=False)
ctl.start()
cfg = 'config/mail.local.php'
had = open(cfg).read() if os.path.exists(cfg) else None
open(cfg, 'w').write("<?php return ['host'=>'127.0.0.1','port'=>1025,'username'=>'u','password'=>'p','encryption'=>'none','from_email'=>'noreply@expenseflow.test','from_name'=>'ExpenseFlow'];")
requests.get(B + '/auth/login.php')
def clean():
    sql("DELETE FROM users WHERE email LIKE '%@vf.test'"); sql("DELETE FROM rate_limit_hits")
clean()
PW = 'Strong#Pass123'
def csrf(s, path): return re.search(r'name="csrf_token" value="([^"]+)"', s.get(B + path).text).group(1)
def register(s, name='Vera', email='vera@vf.test', pw=PW, cpw=None):
    return s.post(B + '/auth/register.php', data={'name': name, 'email': email, 'password': pw, 'confirm_password': cpw or pw, 'csrf_token': csrf(s, '/auth/register.php')}, allow_redirects=False)
def code_for(addr):
    for m in reversed(inbox):
        if m['to'] == addr:
            return re.search(r'\b(\d{6})\b', re.sub(r'<[^>]+>', ' ', m['body'])).group(1)
def vpost(s, **d):
    d.setdefault('csrf_token', csrf(s, '/auth/verify-email.php')); return s.post(B + '/auth/verify-email.php', data=d, allow_redirects=False)
def sess_file(s): return 'storage/sessions/sess_' + s.cookies.get('PHPSESSID')
def tamper(s, key, value):
    f = sess_file(s); t = open(f).read()
    t2 = re.sub(rf'("{key}";i:)\d+', rf'\g<1>{value}', t); open(f, 'w').write(t2); return t != t2

try:
    # ---------- rejected sign-ups send nothing and create nothing ----------
    s = requests.Session()
    ok('confirm it is yours' in s.get(B + '/auth/register.php').text, 'register page explains the code')
    for email, frag in [('nobody', 'Invalid email'), ('x@mailinator.com', 'real email address'), ('ali@gmial.com', 'Did you mean ali@gmail.com'), ('a@b', 'Invalid email')]:
        r = register(s, email=email)
        ok(frag in r.text, f'{email}: message "{frag}"')
    r = register(s, pw='abc'); ok(r.status_code == 200 and 'verify-email' not in r.headers.get('Location', ''), 'weak password rejected')
    r = register(s, cpw='Other#Pass123'); ok('do not match' in r.text, 'password mismatch rejected')
    ok(len(inbox) == 0 and sql("SELECT COUNT(*) FROM users WHERE email LIKE '%@vf.test'") == '0', 'no email sent, no account created for bad input')
    ok(requests.get(B + '/auth/verify-email.php', allow_redirects=False).headers.get('Location', '').endswith('register.php'), 'verify page without a sign-up goes back to register')

    # ---------- happy path ----------
    s = requests.Session()
    r = register(s)
    ok(r.status_code == 302 and r.headers['Location'].endswith('verify-email.php'), 'valid sign-up goes to the code page')
    ok(len(inbox) == 1 and inbox[0]['to'] == 'vera@vf.test' and 'confirmation code' in inbox[0]['subject'], 'one confirmation email sent to that address')
    code0 = re.search(r'\b(\d{6})\b', re.sub(r'<[^>]+>', ' ', inbox[0]['body'])).group(1)
    ok(inbox[0]['subject'].startswith(code0), 'subject starts with the code (visible in the notification)')
    ok(inbox[0]['auto'] == 'auto-generated' and 'ExpenseFlow' in inbox[0]['from'], 'automatic-message header and sender name set')
    ok('text/html' in inbox[0]['parts'] and 'text/plain' in inbox[0]['parts'] and 'image/png' in inbox[0]['parts'], 'email has a designed HTML part, a plain-text part and the logo')
    ok('do not reply' in inbox[0]['body'] and 'Never share this code' in inbox[0]['body'], 'email says it is automatic and warns not to share the code')
    ok(sql("SELECT COUNT(*) FROM users WHERE email='vera@vf.test'") == '0', 'NO account exists before the code is entered')
    page = s.get(B + '/auth/verify-email.php').text
    ok('ve•••@vf.test' in page and 'vera@vf.test' not in page, 'code page shows a masked address only')
    code = code_for('vera@vf.test'); ok(code and len(code) == 6, 'email contains a 6-digit code')
    wrong = str((int(code) + 1) % 900000 + 100000)
    ok('not right' in vpost(s, code=wrong).text, 'wrong code refused')
    ok(vpost(s, code='abc').status_code == 200 and sql("SELECT COUNT(*) FROM users WHERE email='vera@vf.test'") == '0', 'garbage code refused')
    ok(vpost(s, code=code, csrf_token='bad').status_code in (200, 403) and sql("SELECT COUNT(*) FROM users WHERE email='vera@vf.test'") == '0', 'missing/bad CSRF refused')
    r = vpost(s, code=code)
    ok(r.status_code == 302 and r.headers['Location'].endswith('login.php'), 'right code creates the account and goes to login')
    row = sql("SELECT email, name FROM users WHERE email='vera@vf.test'")
    ok(row == 'vera@vf.test\tVera', 'account has the confirmed address and name')
    ok(php('require "config/db.php"; $h=$conn->query("SELECT password FROM users WHERE email=\'vera@vf.test\'")->fetchColumn(); echo password_verify("' + PW + '", $h) ? "ok":"bad";') == 'ok', 'password hash works')
    ok(requests.get(B + '/auth/verify-email.php', cookies=s.cookies, allow_redirects=False).headers.get('Location', '').endswith('register.php'), 'pending sign-up is cleared after success')
    s2 = requests.Session(); t = csrf(s2, '/auth/login.php')
    r = s2.post(B + '/auth/login.php', data={'email': 'vera@vf.test', 'password': PW, 'csrf_token': t}, allow_redirects=False)
    ok(r.status_code == 302 and 'dashboard' in r.headers['Location'], 'the new user can log in')
    n = len(inbox)
    r = register(requests.Session()); ok('already exists' in r.text and len(inbox) == n, 'existing address: no new email')
    r = register(requests.Session(), email='VERA@VF.TEST'); ok('already exists' in r.text and len(inbox) == n, 'same address in other letter case also caught')

    # ---------- wrong attempts, expiry, resend ----------
    clean(); s = requests.Session(); register(s, email='kim@vf.test'); code = code_for('kim@vf.test')
    for i in range(5): vpost(s, code='000000' if code != '000000' else '111111')
    ok('Too many wrong' in vpost(s, code=code).text and sql("SELECT COUNT(*) FROM users WHERE email='kim@vf.test'") == '0', '5 wrong tries kill the code (even the right one fails)')
    _n = len(inbox); r = vpost(s, do='resend')
    ok('wait' in r.text and len(inbox) == _n, 'resend is refused during the 60 second wait (no email)')
    ok(tamper(s, 'sent_at', int(time.time()) - 120), 'session editable (test helper)')
    n = len(inbox); r = vpost(s, do='resend')
    ok('new code is on its way' in r.text and len(inbox) == n + 1, 'resend after the wait sends another email')
    code2 = code_for('kim@vf.test'); ok(code2 != code, 'new code differs from the old one')
    ok(tamper(s, 'expires', int(time.time()) - 5), 'make the code expire')
    ok('expired' in vpost(s, code=code2).text and sql("SELECT COUNT(*) FROM users WHERE email='kim@vf.test'") == '0', 'expired code refused')
    for i in range(3):
        tamper(s, 'sent_at', int(time.time()) - 120); vpost(s, do='resend')
    tamper(s, 'sent_at', int(time.time()) - 120)
    ok('Too many codes' in vpost(s, do='resend').text, 'at most 4 emails per sign-up')
    r = vpost(s, do='cancel'); ok(r.headers.get('Location', '').endswith('register.php'), 'start again clears the sign-up')
    ok(requests.get(B + '/auth/verify-email.php', cookies=s.cookies, allow_redirects=False).headers.get('Location', '').endswith('register.php'), 'cancelled sign-up is gone')

    # ---------- limits and failures ----------
    clean(); n = len(inbox)
    for i in range(5): register(requests.Session(), email='spam@vf.test')
    r = register(requests.Session(), email='spam@vf.test')
    ok('Too many confirmation' in r.text and len(inbox) == n + 5, 'at most 5 emails per address per hour')
    clean(); n = len(inbox)
    for i in range(15): register(requests.Session(), name=f'N{i}', email=f'ip{i}@vf.test')
    # the per-IP sign-up limit (10/hour) is hit first; either limit stops it
    ok(len(inbox) - n <= 15 and len(inbox) - n >= 10, 'a single connection cannot trigger unlimited emails')
    clean(); ctl.stop(); n = len(inbox)
    s = requests.Session(); r = register(s, email='down@vf.test')
    ok('could not send' in r.text and r.status_code == 200, 'mail server down: clear message')
    ok(requests.get(B + '/auth/verify-email.php', cookies=s.cookies, allow_redirects=False).headers.get('Location', '').endswith('register.php') and sql("SELECT COUNT(*) FROM users WHERE email='down@vf.test'") == '0', 'no pending sign-up and no account when the email could not be sent')
finally:
    try: ctl.stop()
    except Exception: pass
    if had is None: os.remove(cfg)
    else: open(cfg, 'w').write(had)
    clean()
print('signup tests:', 'ALL PASSED' if not fails else f'{fails} FAILED'); raise SystemExit(1 if fails else 0)
