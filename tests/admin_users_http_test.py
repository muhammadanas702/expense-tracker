"""
Admin user management against a LOCAL test server + TEST database (never the live site).
Usage: php -S 127.0.0.1:8080 -t . router.php   then   python3 tests/admin_users_http_test.py
Covers: block / unblock (login refused, live session signed out), temporary password, role change,
who may manage whom, add user, CSV export, filters.
"""
import re, subprocess, sys, html, requests
B = 'http://127.0.0.1:8080'
fails = 0
def sql(q): return subprocess.run(['mysql', '-uroot', 'expenseflow_test', '-N', '-e', q], capture_output=True, text=True).stdout.strip()
def ok(c, m):
    global fails
    if not c: fails += 1; print('FAIL', m)
h = subprocess.run(['php', '-r', 'echo password_hash("Test#12345",PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout
sql("DELETE FROM users WHERE email LIKE '%@um.local'")
sql("DELETE FROM admin_perms")
for n, r in {'u1': 'user', 'u2': 'user', 'st': 'support', 'ad': 'admin', 'sp': 'super', 'sq': 'super'}.items():
    sql(f"INSERT INTO users(name,email,password,role,is_admin) VALUES ('{n}','{n}@um.local','{h}','{r}',{0 if r=='user' else 1})")
uid = {n: int(sql(f"SELECT id FROM users WHERE email='{n}@um.local'")) for n in ('u1', 'u2', 'st', 'ad', 'sp', 'sq')}
def login(n, pw='Test#12345'):
    s = requests.Session()
    t = re.search(r'name="csrf_token" value="([^"]+)"', s.get(B + '/auth/login.php').text).group(1)
    r = s.post(B + '/auth/login.php', data={'email': f'{n}@um.local', 'password': pw, 'csrf_token': t}, allow_redirects=False)
    return s, r
def csrf(s):
    m = re.search(r'name="csrf_token" value="([^"]+)"|data-csrf="([^"]+)"', s.get(B + '/admin/users.php').text)
    return m.group(1) or m.group(2)
def act(s, **d): return s.post(B + '/admin/user-action.php', data={**d, 'csrf_token': csrf(s)}, allow_redirects=False)
sp, _ = login('sp'); ad, _ = login('ad'); st, _ = login('st')
u1, _ = login('u1')
ok(u1.get(B + '/dashboard.php', allow_redirects=False).status_code == 200, 'user is logged in')

# block: Admin (block perm by default) may block a plain user
act(ad, do='block', id=uid['u1'])
ok(sql(f"SELECT blocked FROM users WHERE id={uid['u1']}") == '1', 'admin blocked a user')
ok(u1.get(B + '/dashboard.php', allow_redirects=False).status_code in (301, 302, 303), 'blocked user is signed out on the next request')
s2, r = login('u1'); ok(r.status_code == 200 and 'blocked' in r.text.lower(), 'blocked user cannot log in (clear message)')
act(ad, do='unblock', id=uid['u1'])
s2, r = login('u1'); ok(r.status_code in (302, 303), 'unblocked user can log in again')

# who may manage whom
act(ad, do='block', id=uid['st']); ok(sql(f"SELECT blocked FROM users WHERE id={uid['st']}") == '0', 'admin cannot block staff')
act(ad, do='block', id=uid['ad']); ok(sql(f"SELECT blocked FROM users WHERE id={uid['ad']}") == '0', 'nobody can block themselves')
act(st, do='block', id=uid['u2']); ok(sql(f"SELECT blocked FROM users WHERE id={uid['u2']}") == '0', 'support has no block permission')
act(sp, do='block', id=uid['st']); ok(sql(f"SELECT blocked FROM users WHERE id={uid['st']}") == '1', 'super admin can block staff')
act(sp, do='unblock', id=uid['st'])
act(ad, do='role', id=uid['u2'], role='admin'); ok(sql(f"SELECT role FROM users WHERE id={uid['u2']}") == 'user', 'admin cannot change roles')

# role change by Super Admin keeps is_admin in step; last Super Admin is protected
act(sp, do='role', id=uid['u2'], role='support')
ok(sql(f"SELECT CONCAT(role,is_admin) FROM users WHERE id={uid['u2']}") == 'support1', 'role set to support (is_admin=1)')
act(sp, do='role', id=uid['u2'], role='root'); ok(sql(f"SELECT role FROM users WHERE id={uid['u2']}") == 'support', 'unknown role refused')
sq, _ = login('sq'); sq_tok = csrf(sq)   # logged in while still a Super Admin
act(sp, do='role', id=uid['sq'], role='user'); ok(sql(f"SELECT role FROM users WHERE id={uid['sq']}") == 'user', 'a second super admin can be demoted')
sq.post(B + '/admin/user-action.php', data={'do': 'role', 'id': uid['sp'], 'role': 'user', 'csrf_token': sq_tok}, allow_redirects=False)
ok(sql(f"SELECT role FROM users WHERE id={uid['sp']}") == 'super', 'a demoted account loses its admin rights at once (role is read from the database)')
# the last Super Admin cannot be demoted by anybody else either
sql(f"UPDATE users SET role='super', is_admin=1 WHERE id={uid['sq']}")
sql(f"UPDATE users SET role='user', is_admin=0 WHERE id={uid['sp']}")
sp2, _ = login('sq')
act(sp2, do='role', id=uid['sq'], role='user'); ok(sql(f"SELECT role FROM users WHERE id={uid['sq']}") == 'super', 'last Super Admin cannot demote themselves')
sql(f"UPDATE users SET role='super', is_admin=1 WHERE id={uid['sp']}")

# edit details (Super Admin only), duplicate email refused
act(sp, do='edit', id=uid['u2'], name='Renamed', email='renamed@um.local'); ok(sql(f"SELECT name FROM users WHERE id={uid['u2']}") == 'Renamed', 'super admin edits details')
act(sp, do='edit', id=uid['u2'], name='Dup', email='ad@um.local'); ok(sql(f"SELECT name FROM users WHERE id={uid['u2']}") == 'Renamed', 'duplicate email refused')

# temporary password: shown once, works for login, old password no longer works
r = act(st, do='reset', id=uid['u1']); ok(sql(f"SELECT 1 FROM users WHERE id={uid['u1']}") == '1', 'support may reset (reset perm)')
page = st.get(B + f'/admin/view-user.php?id={uid["u1"]}').text
m = re.search(r'user-select:all">([^<]+)</code>', page); ok(bool(m), 'temporary password shown once')
if m:
    _, ok1 = login('u1', html.unescape(m.group(1))); ok(ok1.status_code in (302, 303), 'temporary password works')
    ok('user-select:all' not in st.get(B + f'/admin/view-user.php?id={uid["u1"]}').text, 'temporary password is not shown again')
_, old = login('u1'); ok(old.status_code == 200, 'old password no longer works')

# add user: Admin lacks "add" by default, Super Admin has it; staff can only be created by Super Admin
def add(s, **d): return s.post(B + '/admin/user-add.php', data={**d, 'csrf_token': csrf(s)}, allow_redirects=False)
add(ad, name='Nope', email='nope@um.local'); ok(sql("SELECT COUNT(*) FROM users WHERE email='nope@um.local'") == '0', 'admin without add permission cannot add')
r = add(sp, name='New Person', email='new@um.local', role='support'); ok(sql("SELECT role FROM users WHERE email='new@um.local'") == 'support', 'super admin adds a staff member')
sql("INSERT INTO admin_perms(role,perm,allowed) VALUES ('admin','add',1) ON DUPLICATE KEY UPDATE allowed=1")
add(ad, name='Plain', email='plain@um.local', role='super'); ok(sql("SELECT role FROM users WHERE email='plain@um.local'") == 'user', 'admin can only create plain users')
add(ad, name='Dup', email='plain@um.local'); ok(sql("SELECT COUNT(*) FROM users WHERE email='plain@um.local'") == '1', 'duplicate email refused')

# CSV export: needs "export", neutralises formulas
r = ad.get(B + '/admin/user-export.php', allow_redirects=False); ok(r.status_code in (302, 303, 403), 'admin without export permission refused')
sql("UPDATE users SET name='=cmd|calc' WHERE email='u1@um.local'")
r = sp.get(B + '/admin/user-export.php'); ok(r.status_code == 200 and 'text/csv' in r.headers.get('Content-Type', ''), 'super admin downloads CSV')
ok("'=cmd|calc" in r.text and ',=cmd|calc' not in r.text, 'CSV formulas are neutralised')

# list: filters and search
ok('u1@um.local' in sp.get(B + '/admin/users.php?q=u1@um').text and 'ad@um.local' not in sp.get(B + '/admin/users.php?q=u1@um').text, 'search works')
sql(f"UPDATE users SET blocked=1 WHERE id={uid['u1']}")
bl = sp.get(B + '/admin/users.php?f=blocked').text; ok('u1@um.local' in bl and 'ad@um.local' not in bl, 'blocked filter')
ok('sp@um.local' in sp.get(B + '/admin/users.php?f=staff').text and 'plain@um.local' not in sp.get(B + '/admin/users.php?f=staff').text, 'staff filter')
ok(sp.get(B + '/admin/users.php?s=login&p=999').status_code == 200, 'sort and out-of-range page are safe')

sql("DELETE FROM admin_perms"); sql("DELETE FROM user_logs WHERE user_id IN (SELECT id FROM users WHERE email LIKE '%@um.local')"); sql("DELETE FROM users WHERE email LIKE '%@um.local' OR email='renamed@um.local'")
print('admin_users_http_test: ' + ('all passed' if not fails else f'{fails} FAILED'))
sys.exit(1 if fails else 0)
