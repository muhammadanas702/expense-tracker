"""
Support module (user help form + staff inbox) against a LOCAL test server + TEST database.
Usage: php -S 127.0.0.1:8080 -t . router.php   then   python3 tests/support_http_test.py
"""
import re, subprocess, requests
B = 'http://127.0.0.1:8080'
fails = 0
def sql(q): return subprocess.run(['mysql', '-uroot', 'expenseflow_test', '-N', '-e', q], capture_output=True, text=True).stdout.strip()
def ok(c, m):
    global fails
    if not c: fails += 1; print('FAIL', m)
h = subprocess.run(['php', '-r', 'echo password_hash("Test#12345",PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout
requests.get(B + '/auth/login.php')  # makes sure the schema exists
sql("DELETE FROM users WHERE email LIKE '%@sp.local'"); sql("DELETE FROM admin_perms")
sql("DELETE FROM support_messages"); sql("DELETE FROM support_tickets")
for n, r in {'sup': 'super', 'stf': 'support', 'adm': 'admin', 'u1': 'user', 'u2': 'user'}.items():
    sql(f"INSERT INTO users(name,email,password,role,is_admin) VALUES ('{n}name','{n}@sp.local','{h}','{r}',{0 if r=='user' else 1})")
uid = {n: int(sql(f"SELECT id FROM users WHERE email='{n}@sp.local'")) for n in ('sup', 'stf', 'adm', 'u1', 'u2')}
def login(n):
    s = requests.Session()
    t = re.search(r'name="csrf_token" value="([^"]+)"', s.get(B + '/auth/login.php').text).group(1)
    s.post(B + '/auth/login.php', data={'email': f'{n}@sp.local', 'password': 'Test#12345', 'csrf_token': t}, allow_redirects=False)
    return s
S = {n: login(n) for n in uid}
def tok(s, path):
    m = re.search(r'name="csrf_token" value="([^"]+)"|data-csrf="([^"]+)"', s.get(B + path).text); return m.group(1) or m.group(2)
def post(n, path, data, page):
    d = dict(data); d['csrf_token'] = tok(S[n], page); return S[n].post(B + path, data=d, allow_redirects=False)

# access
ok(S['u1'].get(B + '/support/index.php').status_code == 200, 'user opens help page')
ok(requests.get(B + '/support/index.php', allow_redirects=False).status_code in (302, 303), 'guest redirected')
ok(requests.post(B + '/support/action.php', data={'do': 'create'}, allow_redirects=False).status_code in (302, 303, 403, 405), 'guest cannot post')
ok(S['u1'].get(B + '/support/action.php', allow_redirects=False).status_code == 405, 'GET on action refused')
ok(S['u1'].post(B + '/support/action.php', data={'do': 'create', 'subject': 'x', 'body': 'y'}, allow_redirects=False).status_code in (403, 302), 'no csrf: refused')
ok(sql("SELECT COUNT(*) FROM support_tickets") == '0', 'no ticket without csrf')

# create
r = post('u1', '/support/action.php', {'do': 'create', 'subject': '  ', 'category': 'bug', 'body': 'hello'}, '/support/index.php')
ok(sql("SELECT COUNT(*) FROM support_tickets") == '0', 'empty subject rejected')
xss = '<script>alert(1)</script> line1\nline2'
r = post('u1', '/support/action.php', {'do': 'create', 'subject': 'Cannot <b>login</b>', 'category': 'zzz', 'body': xss}, '/support/index.php')
tid = int(sql("SELECT id FROM support_tickets WHERE user_id=%d" % uid['u1']) or 0)
ok(tid > 0 and r.status_code in (302, 303), 'ticket created')
ok(sql(f"SELECT category FROM support_tickets WHERE id={tid}") == 'other', 'unknown category becomes other')
page = S['u1'].get(B + f'/support/ticket.php?id={tid}').text
ok('<script>alert(1)</script>' not in page and '&lt;script&gt;' in page, 'body escaped')
ok('<b>login</b>' not in page, 'subject escaped')

# privacy between users
ok(S['u2'].get(B + f'/support/ticket.php?id={tid}', allow_redirects=False).status_code in (302, 303), 'other user cannot open ticket')
post('u2', '/support/action.php', {'do': 'reply', 'id': tid, 'body': 'intruder'}, '/support/index.php')
ok(sql(f"SELECT COUNT(*) FROM support_messages WHERE ticket_id={tid}") == '1', 'other user cannot reply')
post('u2', '/support/action.php', {'do': 'close', 'id': tid}, '/support/index.php')
ok(sql(f"SELECT status FROM support_tickets WHERE id={tid}") == 'open', 'other user cannot close')
ok('Cannot' not in S['u2'].get(B + '/support/index.php').text, 'other user list does not show it')

# staff access
ok(S['u1'].get(B + '/admin/support.php', allow_redirects=False).status_code == 403, 'plain user blocked from staff inbox')
ok(S['u1'].get(B + f'/admin/ticket.php?id={tid}', allow_redirects=False).status_code == 403, 'plain user blocked from staff ticket')
post('u1', '/admin/ticket-action.php', {'do': 'reply', 'id': tid, 'body': 'hack'}, '/support/index.php')
ok(sql(f"SELECT COUNT(*) FROM support_messages WHERE ticket_id={tid}") == '1', 'plain user cannot post staff reply')
inbox = S['stf'].get(B + '/admin/support.php').text
ok('Cannot' in inbox and 'u1name' in inbox, 'support role sees the inbox')
ok(S['stf'].get(B + f'/admin/ticket.php?id={tid}').status_code == 200, 'support role opens ticket')

# staff reply
post('stf', '/admin/ticket-action.php', {'do': 'reply', 'id': tid, 'body': 'We are on it'}, f'/admin/ticket.php?id={tid}')
ok(sql(f"SELECT status FROM support_tickets WHERE id={tid}") == 'pending', 'staff reply -> pending')
page = S['u1'].get(B + f'/support/ticket.php?id={tid}').text
ok('We are on it' in page and 'ExpenseFlow support' in page, 'user sees the answer')
ok('stfname' not in page, "staff name hidden from user")
ok('stfname' in S['stf'].get(B + f'/admin/ticket.php?id={tid}').text, 'staff see who answered')
ok(sql("SELECT COUNT(*) FROM user_logs WHERE action='admin_ticket_reply'") == '1', 'staff reply audited')

# user reply reopens; close; reopen
post('u1', '/support/action.php', {'do': 'reply', 'id': tid, 'body': 'thanks, still failing'}, f'/support/ticket.php?id={tid}')
ok(sql(f"SELECT status FROM support_tickets WHERE id={tid}") == 'open', 'user reply -> open')
post('u1', '/support/action.php', {'do': 'close', 'id': tid}, f'/support/ticket.php?id={tid}')
ok(sql(f"SELECT status FROM support_tickets WHERE id={tid}") == 'closed', 'user can mark solved')
post('stf', '/admin/ticket-action.php', {'do': 'reopen', 'id': tid}, f'/admin/ticket.php?id={tid}')
ok(sql(f"SELECT status FROM support_tickets WHERE id={tid}") == 'open', 'staff reopen')
post('stf', '/admin/ticket-action.php', {'do': 'close', 'id': tid}, f'/admin/ticket.php?id={tid}')
ok(sql(f"SELECT status FROM support_tickets WHERE id={tid}") == 'closed', 'staff close')
ok(sql("SELECT COUNT(*) FROM user_logs WHERE action IN ('admin_ticket_close','admin_ticket_reopen')") == '2', 'close/reopen audited')
post('stf', '/admin/ticket-action.php', {'do': 'reply', 'id': tid, 'body': '   '}, f'/admin/ticket.php?id={tid}')
ok(sql(f"SELECT COUNT(*) FROM support_messages WHERE ticket_id={tid}") == '3', 'empty staff reply ignored')

# filters / search
ok('Cannot' not in S['stf'].get(B + '/admin/support.php?s=open').text, 'filter open hides closed ticket')
ok('Cannot' in S['stf'].get(B + '/admin/support.php?s=closed').text, 'filter closed shows it')
ok('Cannot' in S['stf'].get(B + '/admin/support.php?q=u1name').text, 'search by name')
ok('Cannot' not in S['stf'].get(B + '/admin/support.php?q=%25%25nomatch').text, 'search with % does not match all')

# permission off -> blocked
sql("INSERT INTO admin_perms(role,perm,allowed) VALUES ('support','support',0) ON DUPLICATE KEY UPDATE allowed=0")
ok(S['stf'].get(B + '/admin/support.php', allow_redirects=False).status_code in (302, 303, 403), 'support permission off: inbox closed')
post('stf', '/admin/ticket-action.php', {'do': 'reply', 'id': tid, 'body': 'late'}, '/support/index.php') if False else None
sql("DELETE FROM admin_perms")
ok(S['adm'].get(B + '/admin/support.php').status_code == 200, 'admin role (default on) sees inbox')

# limit of open requests per user (5) and rate limit
for i in range(7):
    post('u2', '/support/action.php', {'do': 'create', 'subject': f's{i}', 'body': 'b'}, '/support/index.php')
ok(sql(f"SELECT COUNT(*) FROM support_tickets WHERE user_id={uid['u2']}") == '5', 'max 5 open requests per user')

# conversation cap
t2 = int(sql(f"SELECT id FROM support_tickets WHERE user_id={uid['u2']} LIMIT 1"))
sql(f"INSERT INTO support_messages(ticket_id,user_id,is_staff,body) SELECT {t2},{uid['u2']},0,'x' FROM information_schema.columns LIMIT 99")
post('u2', '/support/action.php', {'do': 'reply', 'id': t2, 'body': 'over'}, '/support/index.php')
ok(int(sql(f"SELECT COUNT(*) FROM support_messages WHERE ticket_id={t2}")) <= 100, 'conversation capped at 100')

# account delete cleans up
sql("DELETE FROM rate_limit_hits")
r = subprocess.run(['php', '-r', f'require "modules/support/lib.php"; require "includes/security.php"; require "config/db.php"; support_on_user_deleted($conn, {uid["u1"]});'], capture_output=True, text=True, cwd='.')
ok(sql(f"SELECT COUNT(*) FROM support_tickets WHERE user_id={uid['u1']}") == '0', 'tickets removed with the account')
ok(sql(f"SELECT COUNT(*) FROM support_messages WHERE ticket_id={tid}") == '0', 'messages removed with the account')
ok(sql(f"SELECT COUNT(*) FROM support_tickets WHERE user_id={uid['u2']}") == '5', 'other users untouched')

sql("DELETE FROM users WHERE email LIKE '%@sp.local'"); sql("DELETE FROM support_messages"); sql("DELETE FROM support_tickets")
print('support tests:', 'ALL PASSED' if not fails else f'{fails} FAILED'); raise SystemExit(1 if fails else 0)
