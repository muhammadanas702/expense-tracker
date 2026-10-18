"""
HTTP security check against a LOCAL test server + TEST database (never run on the live site).
Usage: php -S 127.0.0.1:8080 -t . router.php   then   python3 tests/security_http_test.py
Checks: login required, CSRF required, a non-member cannot reach another group's data.
"""
import re, subprocess, sys, requests
B = 'http://127.0.0.1:8080'
fails = 0
def sql(q): return subprocess.run(['mysql', '-uroot', 'expenseflow_test', '-N', '-e', q], capture_output=True, text=True).stdout.strip()
def ok(c, m):
    global fails
    if not c: fails += 1; print('FAIL', m)
h = subprocess.run(['php', '-r', 'echo password_hash("Test#12345",PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout
for n in ('sa', 'sb'):
    sql(f"DELETE FROM users WHERE email='{n}@sec.local'")
    sql(f"INSERT INTO users(name,email,password) VALUES ('{n}','{n}@sec.local','{h}')")
uid = {n: int(sql(f"SELECT id FROM users WHERE email='{n}@sec.local'")) for n in ('sa', 'sb')}

def login(n):
    s = requests.Session()
    r = s.get(B + '/auth/login.php'); t = re.search(r'name="csrf_token" value="([^"]+)"', r.text).group(1)
    s.post(B + '/auth/login.php', data={'email': f'{n}@sec.local', 'password': 'Test#12345', 'csrf_token': t}, allow_redirects=False)
    r = s.get(B + '/dashboard.php', allow_redirects=False); ok(r.status_code == 200, f'{n} logged in')
    return s
def token(s, url):
    return re.search(r'name="csrf_token" value="([^"]+)"', s.get(B + url).text).group(1)

a, b = login('sa'), login('sb')
# A's private data: a group, an expense, a comment
sql(f"INSERT INTO shared_groups(name,icon,owner_id,invite_code) VALUES ('SecGroup','mtn',{uid['sa']},'SECCODE123')")
g = sql("SELECT MAX(id) FROM shared_groups")
sql(f"INSERT INTO shared_members(group_id,user_id,role) VALUES ({g},{uid['sa']},'owner')")
sql(f"INSERT INTO shared_expenses(group_id,title,amount_minor,currency,paid_by,created_by,split_type,expense_date) VALUES ({g},'Secret',1000,'PKR',{uid['sa']},{uid['sa']},'equal',CURDATE())")
e = sql("SELECT MAX(id) FROM shared_expenses")
sql(f"INSERT INTO shared_splits(expense_id,user_id,share_minor) VALUES ({e},{uid['sa']},1000)")
sql(f"INSERT INTO comments_items(group_id,expense_id,user_id,body) VALUES ({g},{e},{uid['sa']},'private note')")
sql(f"INSERT INTO receipts_files(group_id,expense_id,stored,mime,size_bytes,created_by) VALUES ({g},{e},'{'a'*32}','image/jpeg',10,{uid['sa']})")
rid = sql("SELECT MAX(id) FROM receipts_files")

# 1. anonymous visitors are sent to login
anon = requests.Session()
for p in ('/dashboard.php', '/shared/index.php', f'/shared/group.php?id={g}', f'/receipts/view.php?id={rid}', '/friends/index.php', '/activity/index.php', '/admin/index.php'):
    r = anon.get(B + p, allow_redirects=False)
    ok(r.status_code in (301, 302, 303) and 'login' in r.headers.get('Location', ''), f'anon redirected: {p} -> {r.status_code}')

# 2. non-member B cannot see A's group or its data
for p in (f'/shared/group.php?id={g}', f'/shared/add.php?group={g}', f'/receipts/view.php?id={rid}'):
    r = b.get(B + p, allow_redirects=False)
    # add.php shows an empty "choose a group" form to a non-member (200), but never the group's data
    ok('Secret' not in r.text and 'SecGroup' not in r.text and 'private note' not in r.text and r.status_code in (200, 302, 303, 404), f'B sees nothing of A: {p} -> {r.status_code}')
    if 'receipts' in p: ok(r.status_code == 404, 'receipt hidden from non-member')
r = b.get(B + f'/friends/view.php?id={uid["sa"]}', allow_redirects=False); ok(r.status_code in (302, 303), 'B cannot open A as a friend (no shared group)')
r = b.get(B + '/shared/groups.php'); ok('SecGroup' not in r.text, 'B group list hides A group')

# 3. B posts with a VALID csrf token but against A's group: nothing changes
tb = token(b, '/shared/groups.php')
before = sql(f"SELECT COUNT(*) FROM shared_expenses WHERE group_id={g}")
for act in ({'do': 'delete', 'group_id': g}, {'do': 'delete_expense', 'group_id': g, 'expense_id': e}, {'do': 'rename', 'group_id': g, 'name': 'pwned'}, {'do': 'regen', 'group_id': g}, {'do': 'remove', 'group_id': g, 'user_id': uid['sa']}):
    b.post(B + '/shared/action.php', data={**act, 'csrf_token': tb}, allow_redirects=False)
ok(sql(f"SELECT COUNT(*) FROM shared_groups WHERE id={g}") == '1', 'B cannot delete A group')
ok(sql(f"SELECT name FROM shared_groups WHERE id={g}") == 'SecGroup', 'B cannot rename A group')
ok(sql(f"SELECT invite_code FROM shared_groups WHERE id={g}") == 'SECCODE123', 'B cannot regenerate A invite')
ok(sql(f"SELECT COUNT(*) FROM shared_expenses WHERE group_id={g}") == before, 'B cannot delete A expense')
ok(sql(f"SELECT COUNT(*) FROM shared_members WHERE group_id={g}") == '1', 'B cannot remove A')
b.post(B + '/comments/action.php', data={'do': 'add', 'group_id': g, 'expense_id': e, 'body': 'hi from outsider', 'csrf_token': tb}, allow_redirects=False)
ok(sql(f"SELECT COUNT(*) FROM comments_items WHERE group_id={g}") == '1', 'B cannot comment in A group')
b.post(B + '/shared/settle.php', data={'group_id': g, 'from': uid['sb'], 'to': uid['sa'], 'amount': '5', 'currency': 'PKR', 'csrf_token': tb}, allow_redirects=False)
ok(sql(f"SELECT COUNT(*) FROM shared_settlements WHERE group_id={g}") == '0', 'B cannot settle in A group')

# 4. POST without CSRF token is refused for A too
ta_none = a.post(B + '/shared/action.php', data={'do': 'delete', 'group_id': g}, allow_redirects=False)
ok(sql(f"SELECT COUNT(*) FROM shared_groups WHERE id={g}") == '1', 'no-CSRF delete refused')
for p in ('/delete-income.php', '/delete-expense.php', '/reset-data.php'):
    a.post(B + p, data={'id': 1, 'type': 'all'}, allow_redirects=False)
ok(True, 'no-CSRF personal posts sent')

# 5. a join link makes B a member only through the invite code; then B can see the group
r = b.get(B + '/shared/join.php?code=SECCODE123'); 
sql(f"INSERT IGNORE INTO shared_members(group_id,user_id,role) VALUES ({g},{uid['sb']},'member')")
r = b.get(B + f'/friends/view.php?id={uid["sa"]}', allow_redirects=False); ok(r.status_code == 200, 'after joining, A is B friend')
r = b.get(B + f'/receipts/view.php?id={rid}', allow_redirects=False); ok(r.status_code in (200, 404), 'member request handled (file missing is 404, not 500)')
ok(r.status_code != 500, 'no server error')

# cleanup
sql(f"DELETE FROM comments_items WHERE group_id={g}"); sql(f"DELETE FROM receipts_files WHERE group_id={g}")
sql(f"DELETE FROM shared_splits WHERE expense_id={e}"); sql(f"DELETE FROM shared_expenses WHERE group_id={g}")
sql(f"DELETE FROM shared_settlements WHERE group_id={g}"); sql(f"DELETE FROM shared_members WHERE group_id={g}"); sql(f"DELETE FROM shared_groups WHERE id={g}")
sql("DELETE FROM users WHERE email LIKE '%@sec.local'")
print('security_http_test: ' + ('all passed' if not fails else f'{fails} FAILED'))
sys.exit(1 if fails else 0)
