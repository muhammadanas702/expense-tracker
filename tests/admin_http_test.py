"""
Admin access check against a LOCAL test server + TEST database (never the live site).
Usage: php -S 127.0.0.1:8080 -t . router.php   then   python3 tests/admin_http_test.py
"""
import re, subprocess, sys, requests
B = 'http://127.0.0.1:8080'
fails = 0
def sql(q): return subprocess.run(['mysql', '-uroot', 'expenseflow_test', '-N', '-e', q], capture_output=True, text=True).stdout.strip()
def ok(c, m):
    global fails
    if not c: fails += 1; print('FAIL', m)
h = subprocess.run(['php', '-r', 'echo password_hash("Test#12345",PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout
roles = {'ru': 'user', 'rs': 'support', 'ra': 'admin', 'rz': 'super'}
sql("DELETE FROM users WHERE email LIKE '%@adm.local'")
for n, r in roles.items():
    sql(f"INSERT INTO users(name,email,password,role,is_admin) VALUES ('{n}','{n}@adm.local','{h}','{r}',{0 if r=='user' else 1})")
uid = {n: int(sql(f"SELECT id FROM users WHERE email='{n}@adm.local'")) for n in roles}
sql("DELETE FROM admin_perms")

def login(n):
    s = requests.Session()
    t = re.search(r'name="csrf_token" value="([^"]+)"', s.get(B + '/auth/login.php').text).group(1)
    s.post(B + '/auth/login.php', data={'email': f'{n}@adm.local', 'password': 'Test#12345', 'csrf_token': t}, allow_redirects=False)
    return s
S = {n: login(n) for n in roles}
def code(n, path, **kw): return S[n].get(B + path, allow_redirects=False, **kw).status_code
def csrf(n): return re.search(r'data-csrf="([^"]+)"', S[n].get(B + '/admin/users.php').text).group(1)

anon = requests.Session()
for p in ('/admin/index.php', '/admin/users.php'):
    r = anon.get(B + p, allow_redirects=False); ok(r.status_code in (301, 302, 303), f'anon redirected {p}')
for p in ('/admin/index.php', '/admin/users.php', '/admin/view-user.php?id=1', '/admin/logs.php'):
    ok(code('ru', p) == 403, f'plain user blocked from {p}')

ok(code('rs', '/admin/index.php') == 200, 'support sees overview')
ok(code('rs', '/admin/users.php') == 200, 'support can list users (view)')
ok(code('rs', '/admin/logs.php') in (302, 303, 403), 'support has no log permission')
t0 = csrf('rs'); S['rs'].post(B + '/admin/user-action.php', data={'do': 'edit', 'id': uid['ru'], 'name': 'X', 'email': 'x@adm.local', 'csrf_token': t0}, allow_redirects=False)
ok(sql(f"SELECT name FROM users WHERE id={uid['ru']}") == 'ru', 'support cannot edit users')
ok(code('ra', '/admin/logs.php') == 200, 'admin can view logs (log)')
t0 = csrf('ra'); S['ra'].post(B + '/admin/user-action.php', data={'do': 'edit', 'id': uid['ru'], 'name': 'X', 'email': 'x@adm.local', 'csrf_token': t0}, allow_redirects=False)
ok(sql(f"SELECT name FROM users WHERE id={uid['ru']}") == 'ru', 'admin cannot edit users (SA only)')
ok(code('rz', f'/admin/view-user.php?id={uid["ru"]}') == 200, 'super can open a user profile')

# delete: needs the "del" permission
t = csrf('ra')
S['ra'].post(B + '/admin/delete-user.php', data={'id': uid['ru'], 'csrf_token': t}, allow_redirects=False)
ok(sql(f"SELECT COUNT(*) FROM users WHERE id={uid['ru']}") == '1', 'admin without del cannot delete')
t = csrf('rs')
S['rs'].post(B + '/admin/delete-user.php', data={'id': uid['ru'], 'csrf_token': t}, allow_redirects=False)
ok(sql(f"SELECT COUNT(*) FROM users WHERE id={uid['ru']}") == '1', 'support cannot delete')
sql("INSERT INTO admin_perms(role,perm,allowed) VALUES ('admin','del',1) ON DUPLICATE KEY UPDATE allowed=1")
t = csrf('ra')
S['ra'].post(B + '/admin/delete-user.php', data={'id': uid['rz'], 'csrf_token': t}, allow_redirects=False)
ok(sql(f"SELECT COUNT(*) FROM users WHERE id={uid['rz']}") == '1', 'admin cannot delete a Super Admin')
S['ra'].post(B + '/admin/delete-user.php', data={'id': uid['ru'], 'csrf_token': t}, allow_redirects=False)
ok(sql(f"SELECT COUNT(*) FROM users WHERE id={uid['ru']}") == '0', 'admin with del permission can delete a user')
S['ra'].post(B + '/admin/delete-user.php', data={'id': uid['rs']}, allow_redirects=False)
ok(sql(f"SELECT COUNT(*) FROM users WHERE id={uid['rs']}") == '1', 'delete without CSRF refused')

# menu only shows what the role may use
html = S['rs'].get(B + '/admin/index.php').text
ok('Roles &amp; permissions' not in html and 'Users' in html, 'support menu hides Super-only pages')

sql("DELETE FROM admin_perms"); sql("DELETE FROM users WHERE email LIKE '%@adm.local'")
print('admin_http_test: ' + ('all passed' if not fails else f'{fails} FAILED'))
sys.exit(1 if fails else 0)
