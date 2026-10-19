"""
Roles & permissions page and Audit log against a LOCAL test server + TEST database.
Usage: php -S 127.0.0.1:8080 -t . router.php   then   python3 tests/admin_audit_http_test.py
"""
import re, subprocess, sys, requests
B = 'http://127.0.0.1:8080'
fails = 0
def sql(q): return subprocess.run(['mysql', '-uroot', 'expenseflow_test', '-N', '-e', q], capture_output=True, text=True).stdout.strip()
def ok(c, m):
    global fails
    if not c: fails += 1; print('FAIL', m)
h = subprocess.run(['php', '-r', 'echo password_hash("Test#12345",PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout
sql("DELETE FROM users WHERE email LIKE '%@au.local'"); sql("DELETE FROM admin_perms")
for n, r in {'sp': 'super', 'ad': 'admin', 'st': 'support', 'us': 'user'}.items():
    sql(f"INSERT INTO users(name,email,password,role,is_admin) VALUES ('{n}','{n}@au.local','{h}','{r}',{0 if r=='user' else 1})")
uid = {n: int(sql(f"SELECT id FROM users WHERE email='{n}@au.local'")) for n in ('sp', 'ad', 'st', 'us')}
def login(n):
    s = requests.Session()
    t = re.search(r'name="csrf_token" value="([^"]+)"', s.get(B + '/auth/login.php').text).group(1)
    s.post(B + '/auth/login.php', data={'email': f'{n}@au.local', 'password': 'Test#12345', 'csrf_token': t}, allow_redirects=False)
    return s
S = {n: login(n) for n in uid}
def tok(s, path='/admin/roles.php'):
    m = re.search(r'name="csrf_token" value="([^"]+)"|data-csrf="([^"]+)"', s.get(B + path).text); return m.group(1) or m.group(2)

# roles page: Super Admin only
ok(S['sp'].get(B + '/admin/roles.php').status_code == 200, 'super admin opens roles page')
for n in ('ad', 'st', 'us'):
    ok(S[n].get(B + '/admin/roles.php', allow_redirects=False).status_code in (302, 303, 403), f'{n} cannot open roles page')
t = tok(S['ad'], '/admin/users.php')
S['ad'].post(B + '/admin/roles.php', data={'csrf_token': t, 'p[admin][del]': '1'}, allow_redirects=False)
ok(sql("SELECT COUNT(*) FROM admin_perms") == '0', 'admin cannot save permissions')

# save: only boxes that are ticked are on; unticked defaults go off
page = S['sp'].get(B + '/admin/roles.php').text
ok(re.search(r'name="p\[admin\]\[view\]"[^>]*checked', page) is not None, 'default: admin may view users')
ok(re.search(r'name="p\[admin\]\[del\]"[^>]*checked', page) is None, 'default: admin may not delete')
t = tok(S['sp'])
S['sp'].post(B + '/admin/roles.php', data={'csrf_token': t, 'p[admin][view]': '1', 'p[admin][del]': '1', 'p[support][view]': '1', 'p[support][support]': '1'}, allow_redirects=False)
ok(sql("SELECT allowed FROM admin_perms WHERE role='admin' AND perm='del'") == '1', 'admin delete switched on')
ok(sql("SELECT allowed FROM admin_perms WHERE role='admin' AND perm='block'") == '0', 'admin block switched off (unticked)')
ok(sql("SELECT allowed FROM admin_perms WHERE role='support' AND perm='reset'") == '0', 'support reset switched off')
ok(sql("SELECT COUNT(*) FROM admin_perms WHERE role NOT IN ('admin','support')") == '0', 'only admin and support rows exist')
ok(sql(f"SELECT COUNT(*) FROM user_logs WHERE user_id={uid['sp']} AND action='admin_set_perms'") == '1', 'change written to the audit log')
ok('Delete users on' in sql("SELECT details FROM user_logs WHERE action='admin_set_perms' ORDER BY id DESC LIMIT 1"), 'audit line says what changed')
S['sp'].post(B + '/admin/roles.php', data={'csrf_token': t, 'p[admin][view]': '1', 'p[admin][del]': '1', 'p[support][view]': '1', 'p[support][support]': '1'}, allow_redirects=False)
ok(sql(f"SELECT COUNT(*) FROM user_logs WHERE user_id={uid['sp']} AND action='admin_set_perms'") == '1', 'saving without changes writes no audit line')
r = S['sp'].post(B + '/admin/roles.php', data={'p[admin][view]': '1'}, allow_redirects=False)
ok(sql("SELECT allowed FROM admin_perms WHERE role='admin' AND perm='del'") == '1' and 'session expired' in r.text, 'missing CSRF token refused (nothing saved)')

# the new permissions really apply
ok(S['ad'].get(B + '/admin/index.php').status_code == 200, 'admin still enters the panel')
ok(S['ad'].get(B + '/admin/audit.php', allow_redirects=False).status_code in (302, 303, 403), 'admin lost the log permission')
ok(S['st'].get(B + '/admin/users.php').status_code == 200, 'support keeps view')
t = tok(S['ad'], '/admin/users.php')
S['ad'].post(B + '/admin/delete-user.php', data={'id': uid['us'], 'csrf_token': t}, allow_redirects=False)
ok(sql(f"SELECT COUNT(*) FROM users WHERE id={uid['us']}") == '0', 'admin with the new delete permission can delete a user')

# audit log: permission, content, filters
for n in ('ad', 'st'):
    ok(S[n].get(B + '/admin/audit.php', allow_redirects=False).status_code in (302, 303, 403), f'{n} without log permission cannot read the audit log')
a = S['sp'].get(B + '/admin/audit.php').text
ok('Changed permissions' in a and 'Deleted user' in a, 'audit shows permission change and user deletion')
ok('Deleted user' not in S['sp'].get(B + '/admin/audit.php?g=roles').text and 'Changed permissions' in S['sp'].get(B + '/admin/audit.php?g=roles').text, 'roles filter')
ok('Deleted user' in S['sp'].get(B + '/admin/audit.php?g=users').text, 'users filter')
sql(f"INSERT INTO user_logs(user_id,action,details,ip_address,created_at) VALUES ({uid['sp']},'login','plain login','1.1.1.1',NOW())")
ok('plain login' not in S['sp'].get(B + '/admin/audit.php').text, 'normal user actions are not in the audit log')
ok('plain login' in S['sp'].get(B + '/admin/logs.php').text, 'but they are in the user logs')
ok('Deleted user' in S['sp'].get(B + '/admin/audit.php?q=Deleted').text or 'Deleted user' in S['sp'].get(B + '/admin/audit.php?q=ID').text, 'search works')
ok(S['sp'].get(B + '/admin/audit.php?from=2020-01-01&to=bad&p=99&g=zzz').status_code == 200, 'bad filter values are safe')
ok('<script>' not in S['sp'].get(B + '/admin/audit.php?q=%3Cscript%3E').text.replace('<script src', ''), 'search text is escaped')

sql("DELETE FROM admin_perms"); sql("DELETE FROM user_logs WHERE user_id IN (SELECT id FROM users WHERE email LIKE '%@au.local')"); sql("DELETE FROM users WHERE email LIKE '%@au.local'")
print('admin_audit_http_test: ' + ('all passed' if not fails else f'{fails} FAILED'))
sys.exit(1 if fails else 0)
