"""
Emails sent by staff actions (Part 13): temporary password -> user, support reply -> user.
Local server + TEST database + local SMTP sink (pip install aiosmtpd). Writes config/mail.local.php for the run.
"""
import re, subprocess, os, requests
from email import message_from_bytes
from aiosmtpd.controller import Controller
from aiosmtpd.smtp import AuthResult
B = 'http://127.0.0.1:8080'
fails = 0
def sql(q): return subprocess.run(['mysql', '-uroot', 'expenseflow_test', '-N', '-e', q], capture_output=True, text=True).stdout.strip()
def ok(c, m):
    global fails
    if not c: fails += 1; print('FAIL', m)
inbox = []
class H:
    async def handle_DATA(self, server, session, envelope):
        m = message_from_bytes(envelope.content)
        body = ''.join(p.get_payload(decode=True).decode('utf8', 'ignore') for p in m.walk() if p.get_content_type() in ('text/plain', 'text/html'))
        inbox.append({'to': envelope.rcpt_tos[0], 'subject': m['Subject'], 'body': body})
        return '250 OK'
def auth(server, session, envelope, mechanism, auth_data): return AuthResult(success=True)
ctl = Controller(H(), hostname='127.0.0.1', port=1025, authenticator=auth, auth_required=False, auth_require_tls=False); ctl.start()
cfg = 'config/mail.local.php'
had = open(cfg).read() if os.path.exists(cfg) else None
open(cfg, 'w').write("<?php return ['host'=>'127.0.0.1','port'=>1025,'username'=>'u','password'=>'p','encryption'=>'none','from_email'=>'noreply@expenseflow.test','from_name'=>'ExpenseFlow'];")
try:
    requests.get(B + '/auth/login.php')
    h = subprocess.run(['php', '-r', 'echo password_hash("Test#12345",PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout
    sql("DELETE FROM users WHERE email LIKE '%@sm.local'"); sql("DELETE FROM admin_perms"); sql("DELETE FROM rate_limit_hits")
    for n, r in {'sup': 'super', 'u1': 'user'}.items():
        sql(f"INSERT INTO users(name,email,password,role,is_admin) VALUES ('{n}name','{n}@sm.local','{h}','{r}',{0 if r=='user' else 1})")
    uid = int(sql("SELECT id FROM users WHERE email='u1@sm.local'"))
    def login(n):
        s = requests.Session(); t = re.search(r'name="csrf_token" value="([^"]+)"', s.get(B + '/auth/login.php').text).group(1)
        s.post(B + '/auth/login.php', data={'email': f'{n}@sm.local', 'password': 'Test#12345', 'csrf_token': t}, allow_redirects=False); return s
    S = {n: login(n) for n in ('sup', 'u1')}
    def tok(s, path):
        m = re.search(r'name="csrf_token" value="([^"]+)"|data-csrf="([^"]+)"', s.get(B + path).text); return m.group(1) or m.group(2)
    def post(n, path, data, page):
        d = dict(data); d['csrf_token'] = tok(S[n], page); return S[n].post(B + path, data=d, allow_redirects=False)

    # temporary password
    post('sup', '/admin/user-action.php', {'do': 'reset', 'id': uid}, f'/admin/view-user.php?id={uid}')
    ok(len(inbox) == 1 and inbox[0]['to'] == 'u1@sm.local', 'temp password mail went to the user')
    pw = re.search(r'font-family:Consolas,Menlo,monospace">([^<]+)<', inbox[0]['body']) if inbox else None
    ok(pw is not None and len(pw.group(1)) >= 12, 'mail contains the password')
    ok('temporary' in (inbox[0]['subject'] or '').lower(), 'subject')
    if pw:
        s = requests.Session(); t = re.search(r'name="csrf_token" value="([^"]+)"', s.get(B + '/auth/login.php').text).group(1)
        r = s.post(B + '/auth/login.php', data={'email': 'u1@sm.local', 'password': pw.group(1), 'csrf_token': t}, allow_redirects=False)
        ok(r.status_code in (302, 303) and 'dashboard' in r.headers.get('Location', ''), 'emailed password really logs in')

    # support reply
    inbox.clear()
    post('u1', '/support/action.php', {'do': 'create', 'subject': 'Cannot add expense', 'category': 'bug', 'body': 'It fails'}, '/support/index.php')
    tid = sql("SELECT id FROM support_tickets ORDER BY id DESC LIMIT 1")
    ok(tid != '', 'ticket created')
    ok(len(inbox) == 0, 'no mail when the user writes')
    page = S['sup'].get(B + '/admin/support.php').text
    ok('#reply' in page and '>Reply<' in page, 'list has a Reply button')
    post('sup', '/admin/ticket-action.php', {'do': 'reply', 'id': tid, 'body': 'Please update the app and try again.'}, f'/admin/ticket.php?id={tid}')
    ok(len(inbox) == 1 and inbox[0]['to'] == 'u1@sm.local', 'reply mail went to the user')
    ok(inbox and 'Please update the app' in inbox[0]['body'] and f'support/ticket.php?id={tid}' in inbox[0]['body'], 'reply text and link inside')
    ok(inbox and 'Cannot add expense' in (inbox[0]['subject'] or ''), 'subject mentions the request')
    ok('Please update the app' in S['u1'].get(B + f'/support/ticket.php?id={tid}').text, 'user also sees it in Help')
finally:
    ctl.stop()
    if had is None: os.remove(cfg)
    else: open(cfg, 'w').write(had)
    sql("DELETE FROM users WHERE email LIKE '%@sm.local'")
print('staff mail tests:', 'ALL PASSED' if not fails else f'{fails} FAILED')
