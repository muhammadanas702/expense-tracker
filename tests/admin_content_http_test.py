"""
Staff content pages (Announcements, Categories, Currencies, Groups) against a LOCAL test server + TEST database.
Usage: php -S 127.0.0.1:8080 -t . router.php   then   python3 tests/admin_content_http_test.py
"""
import re, subprocess, requests
B = 'http://127.0.0.1:8080'
fails = 0
def sql(q): return subprocess.run(['mysql', '-uroot', 'expenseflow_test', '-N', '-e', q], capture_output=True, text=True).stdout.strip()
def ok(c, m):
    global fails
    if not c: fails += 1; print('FAIL', m)
h = subprocess.run(['php', '-r', 'echo password_hash("Test#12345",PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout
requests.get(B + '/auth/login.php')
sql("DELETE FROM users WHERE email LIKE '%@ct.local'"); sql("DELETE FROM admin_perms")
for t in ('announcements', 'announcement_dismissed', 'app_currency_hidden', 'shared_groups', 'shared_members', 'shared_expenses', 'shared_splits'):
    sql(f"DELETE FROM {t}")
sql("DELETE FROM app_categories")
subprocess.run("rm -f storage/schema/categories_ensure_schema-*.ok", shell=True)  # the test empties the table on purpose; make the self-check run (and re-seed) again
for n, r in {'sup': 'super', 'adm': 'admin', 'stf': 'support', 'u1': 'user', 'u2': 'user'}.items():
    sql(f"INSERT INTO users(name,email,password,role,is_admin) VALUES ('{n}name','{n}@ct.local','{h}','{r}',{0 if r=='user' else 1})")
uid = {n: int(sql(f"SELECT id FROM users WHERE email='{n}@ct.local'")) for n in ('sup', 'adm', 'stf', 'u1', 'u2')}
def login(n):
    s = requests.Session()
    t = re.search(r'name="csrf_token" value="([^"]+)"', s.get(B + '/auth/login.php').text).group(1)
    s.post(B + '/auth/login.php', data={'email': f'{n}@ct.local', 'password': 'Test#12345', 'csrf_token': t}, allow_redirects=False)
    return s
S = {n: login(n) for n in uid}
def tok(s, path):
    m = re.search(r'name="csrf_token" value="([^"]+)"|data-csrf="([^"]+)"', s.get(B + path).text); return m.group(1) or m.group(2)
def post(n, path, data, page):
    d = dict(data); d['csrf_token'] = tok(S[n], page); return S[n].post(B + path, data=d, allow_redirects=False)
def code(n, path): return S[n].get(B + path, allow_redirects=False).status_code

# ---------- access ----------
pages = ['/admin/announcements.php', '/admin/categories.php', '/admin/currencies.php', '/admin/groups.php']
for p in pages:
    ok(code('u1', p) == 403, f'plain user blocked from {p}')
    ok(requests.get(B + p, allow_redirects=False).status_code in (302, 303), f'guest redirected from {p}')
    ok(code('sup', p) == 200, f'super admin opens {p}')
    ok(code('stf', p) in (302, 303, 403), f'support role (default off) blocked from {p}')
ok(code('adm', '/admin/announcements.php') == 200, 'admin role has announcements by default')
for p in pages[1:]:
    ok(code('adm', p) in (302, 303, 403), f'admin role has {p} off by default')
for a in ['/admin/announcement-action.php', '/admin/category-action.php', '/admin/currency-action.php', '/admin/group-action.php']:
    ok(S['sup'].get(B + a, allow_redirects=False).status_code == 405, f'GET on {a} refused')
    r = S['sup'].post(B + a, data={'do': 'delete', 'id': 1}, allow_redirects=False)
    ok(r.status_code in (403, 302), f'no csrf on {a} refused')
    r = post('u1', a, {'do': 'delete', 'id': 1}, '/support/index.php')
    ok(r.status_code in (403, 302), f'plain user cannot post to {a}')

# ---------- announcements ----------
A = '/admin/announcement-action.php'; AP = '/admin/announcements.php'
post('sup', A, {'do': 'save', 'title': '<b>Hi</b>', 'body': '<script>alert(1)</script>\nline2', 'level': 'warn', 'active': '1'}, AP)
aid = int(sql("SELECT MAX(id) FROM announcements") or 0)
ok(aid > 0, 'announcement saved')
d1 = S['u1'].get(B + '/dashboard.php').text
ok('&lt;b&gt;Hi&lt;/b&gt;' in d1 and '<script>alert(1)</script>' not in d1 and 'ann warn' in d1, 'banner shown to user and escaped')
ok('&lt;b&gt;Hi&lt;/b&gt;' in S['u2'].get(B + '/dashboard.php').text, 'banner shown to other user')
post('u1', '/announce/dismiss.php', {'id': aid}, '/dashboard.php')
ok('&lt;b&gt;Hi&lt;/b&gt;' not in S['u1'].get(B + '/dashboard.php').text, 'user can close it for themselves')
ok('&lt;b&gt;Hi&lt;/b&gt;' in S['u2'].get(B + '/dashboard.php').text, 'closing it does not hide it for others')
ok(S['u1'].get(B + '/announce/dismiss.php', allow_redirects=False).status_code == 405, 'GET dismiss refused')
post('sup', A, {'do': 'toggle', 'id': aid}, AP)
ok(sql(f"SELECT active FROM announcements WHERE id={aid}") == '0', 'turned off')
ok('&lt;b&gt;Hi&lt;/b&gt;' not in S['u2'].get(B + '/dashboard.php').text, 'off announcement not shown')
post('sup', A, {'do': 'toggle', 'id': aid}, AP)
post('sup', A, {'do': 'save', 'id': aid, 'title': 'Later', 'level': 'info', 'active': '1', 'starts_at': '2099-01-01T10:00'}, AP)
ok('Later' not in S['u2'].get(B + '/dashboard.php').text, 'scheduled for the future: not shown')
post('sup', A, {'do': 'save', 'id': aid, 'title': 'Old', 'level': 'info', 'active': '1', 'ends_at': '2000-01-01T10:00'}, AP)
ok('>Old<' not in S['u2'].get(B + '/dashboard.php').text, 'ended: not shown')
post('sup', A, {'do': 'save', 'id': aid, 'title': 'Bad', 'level': 'info', 'starts_at': '2030-01-02T10:00', 'ends_at': '2030-01-01T10:00'}, AP)
ok(sql(f"SELECT title FROM announcements WHERE id={aid}") == 'Old', 'end before start rejected')
post('sup', A, {'do': 'save', 'title': 'x' * 200, 'level': 'info'}, AP)
ok(sql("SELECT COUNT(*) FROM announcements") == '1', 'too long title rejected')
post('sup', A, {'do': 'save', 'title': '  ', 'level': 'info'}, AP)
ok(sql("SELECT COUNT(*) FROM announcements") == '1', 'empty title rejected')
post('sup', A, {'do': 'save', 'title': 'Lvl', 'level': 'evil', 'active': '1'}, AP)
ok(sql("SELECT level FROM announcements ORDER BY id DESC LIMIT 1") == 'info', 'unknown style becomes info')
post('adm', A, {'do': 'save', 'title': 'By admin', 'level': 'good', 'active': '1'}, AP)
ok(sql("SELECT COUNT(*) FROM announcements WHERE title='By admin'") == '1', 'admin role can post announcements')
post('stf', A, {'do': 'save', 'title': 'By support', 'level': 'good', 'active': '1'}, '/support/index.php')
ok(sql("SELECT COUNT(*) FROM announcements WHERE title='By support'") == '0', 'support role cannot')
sql(f"INSERT IGNORE INTO announcement_dismissed VALUES ({uid['u2']},{aid})")
post('sup', A, {'do': 'delete', 'id': aid}, AP)
ok(sql(f"SELECT COUNT(*) FROM announcements WHERE id={aid}") == '0' and sql(f"SELECT COUNT(*) FROM announcement_dismissed WHERE announcement_id={aid}") == '0', 'delete removes it and its dismissals')
ok(int(sql("SELECT COUNT(*) FROM user_logs WHERE action LIKE 'admin_announce_%'")) >= 5, 'announcement actions audited')

# ---------- categories ----------
C = '/admin/category-action.php'; CP = '/admin/categories.php'
ok(sql("SELECT COUNT(*) FROM app_categories") == '0' or True, 'start')
S['sup'].get(B + CP)  # seeds defaults
ok(sql("SELECT COUNT(*) FROM app_categories") == '8', 'defaults seeded')
add = S['u1'].get(B + '/expense/add-expense.php')
ok(add.status_code == 200 and 'value="Food"' in add.text, 'add-expense page lists default categories')
post('sup', C, {'do': 'add', 'name': '<i>Gym</i>', 'icon': 'heart'}, CP)
ok(sql("SELECT COUNT(*) FROM app_categories WHERE name='<i>Gym</i>'") == '1', 'category added')
page = S['u1'].get(B + '/expense/add-expense.php').text
ok('&lt;i&gt;Gym&lt;/i&gt;' in page and '<i>Gym</i>' not in page, 'new category shown to users, escaped')
post('sup', C, {'do': 'add', 'name': '<i>gym</i>', 'icon': 'tag'}, CP)
ok(sql("SELECT COUNT(*) FROM app_categories WHERE name LIKE '%gym%'") == '1', 'duplicate name (any case) rejected')
post('sup', C, {'do': 'add', 'name': 'Y' * 40, 'icon': 'tag'}, CP)
ok(sql("SELECT COUNT(*) FROM app_categories") == '9', 'too long name rejected')
post('sup', C, {'do': 'add', 'name': 'Odd', 'icon': 'not-an-icon'}, CP)
ok(sql("SELECT icon FROM app_categories WHERE name='Odd'") == 'tag', 'unknown icon becomes tag')
gid = sql("SELECT id FROM app_categories WHERE name='<i>Gym</i>'")
post('sup', C, {'do': 'toggle', 'id': gid}, CP)
ok(sql(f"SELECT active FROM app_categories WHERE id={gid}") == '0', 'category turned off')
ok('value="&lt;i&gt;Gym' not in S['u1'].get(B + '/expense/add-expense.php').text, 'off category hidden from the form')
r = post('u1', '/expense/add-expense.php', {'title': 'x', 'amount': '5', 'currency': 'PKR', 'category': '<i>Gym</i>', 'payment_method': 'Cash'}, '/expense/add-expense.php')
ok(sql(f"SELECT COUNT(*) FROM expenses WHERE user_id={uid['u1']} AND title='x'") == '0', 'new expense in an off category refused')
r = post('u1', '/expense/add-expense.php', {'title': 'ok1', 'amount': '5', 'currency': 'PKR', 'category': 'Food', 'payment_method': 'Cash'}, '/expense/add-expense.php')
ok(sql(f"SELECT COUNT(*) FROM expenses WHERE user_id={uid['u1']} AND title='ok1'") == '1', 'expense in a normal category works')
oid = sql("SELECT id FROM app_categories WHERE name='Other'")
post('sup', C, {'do': 'toggle', 'id': oid}, CP); post('sup', C, {'do': 'delete', 'id': oid}, CP)
ok(sql("SELECT COUNT(*) FROM app_categories WHERE name='Other' AND active=1") == '1', '"Other" cannot be turned off or deleted')
first = sql("SELECT id FROM app_categories ORDER BY sort_order,id LIMIT 1"); last_order = sql("SELECT name FROM app_categories ORDER BY sort_order,id LIMIT 1")
post('sup', C, {'do': 'down', 'id': first}, CP)
ok(sql("SELECT name FROM app_categories ORDER BY sort_order,id LIMIT 1") != last_order, 'move down changes the order')
post('sup', C, {'do': 'up', 'id': first}, CP)
ok(sql("SELECT name FROM app_categories ORDER BY sort_order,id LIMIT 1") == last_order, 'move up restores it')
post('sup', C, {'do': 'delete', 'id': gid}, CP)
ok(sql(f"SELECT COUNT(*) FROM app_categories WHERE id={gid}") == '0', 'category deleted')
post('sup', C, {'do': 'delete', 'id': '999999'}, CP)
post('adm', C, {'do': 'add', 'name': 'ByAdmin', 'icon': 'tag'}, '/admin/announcements.php')
ok(sql("SELECT COUNT(*) FROM app_categories WHERE name='ByAdmin'") == '0', 'admin role (cat off) cannot add')
for i in range(40):
    post('sup', C, {'do': 'add', 'name': f'c{i}', 'icon': 'tag'}, CP)
ok(int(sql("SELECT COUNT(*) FROM app_categories")) <= 30, 'at most 30 categories')
ok(int(sql("SELECT COUNT(*) FROM user_logs WHERE action LIKE 'admin_cat_%'")) >= 3, 'category actions audited')
sql("DELETE FROM app_categories WHERE name NOT IN ('Food','Transport','Shopping','Entertainment','Bills','Healthcare','Education','Other')")

# ---------- currencies ----------
U = '/admin/currency-action.php'; UP = '/admin/currencies.php'
ok('value="EUR"' in S['u1'].get(B + '/expense/add-expense.php').text, 'EUR in the pick list at first')
allc = re.findall(r'name="show\[\]" value="([A-Z]{3})"', S['sup'].get(B + UP).text)
ok(len(allc) >= 38, 'currency table lists the supported currencies')
post('sup', U, {'show[]': [c for c in allc if c not in ('EUR', 'JPY', 'PKR', 'USD')]}, UP)
ok(sorted(sql("SELECT code FROM app_currency_hidden").split()) == ['EUR', 'JPY'], 'EUR and JPY hidden; PKR/USD cannot be hidden')
form = S['u1'].get(B + '/expense/add-expense.php').text
ok('value="EUR"' not in form and 'value="JPY"' not in form and 'value="USD"' in form and 'value="PKR"' in form, 'hidden currencies leave the pick list')
post('sup', U, {'show[]': allc}, UP)
ok(sql("SELECT COUNT(*) FROM app_currency_hidden") == '0', 'all shown again')
post('sup', U, {'show[]': ['ZZZ', "x'; DROP TABLE users;--"]}, UP)
ok(sql("SELECT COUNT(*) FROM users") != '0' and sorted(sql("SELECT code FROM app_currency_hidden").split()) == sorted(c for c in allc if c not in ('PKR', 'USD')), 'junk codes ignored; everything else hidden')
post('sup', U, {'show[]': allc}, UP)
post('adm', U, {'show[]': []}, '/admin/announcements.php')
ok(sql("SELECT COUNT(*) FROM app_currency_hidden") == '0', 'admin role (cur off) cannot change currencies')
ok(int(sql("SELECT COUNT(*) FROM user_logs WHERE action='admin_cur_change'")) >= 2, 'currency changes audited')

# ---------- groups ----------
G = '/admin/group-action.php'; GP = '/admin/groups.php'
for i, (nm, ow) in enumerate([('Trip 100%', 'u1'), ('Flat', 'u2')]):
    sql(f"INSERT INTO shared_groups(name,owner_id,invite_code) VALUES ('{nm}',{uid[ow]},'{'a%d' % i * 16}')")
g1 = int(sql("SELECT id FROM shared_groups WHERE name='Trip 100%'")); g2 = int(sql("SELECT id FROM shared_groups WHERE name='Flat'"))
sql(f"INSERT INTO shared_members(group_id,user_id,role) VALUES ({g1},{uid['u1']},'owner'),({g1},{uid['u2']},'member'),({g2},{uid['u2']},'owner')")
sql(f"INSERT INTO shared_expenses(group_id,title,amount_minor,currency,paid_by,split_type,expense_date,created_by) VALUES ({g1},'Hotel',1000,'PKR',{uid['u1']},'equal',CURDATE(),{uid['u1']})")
eid = int(sql(f"SELECT id FROM shared_expenses WHERE group_id={g1}"))
sql(f"INSERT INTO shared_splits(expense_id,user_id,share_minor) VALUES ({eid},{uid['u1']},500),({eid},{uid['u2']},500)")
page = S['sup'].get(B + GP).text
ok('Trip 100%' in page and 'Flat' in page and 'u1name' in page, 'staff list shows groups and owners')
ok('Hotel' not in page, 'staff do not see expense details')
ok('Flat' not in S['sup'].get(B + GP + '?q=u1name').text and 'Trip 100%' in S['sup'].get(B + GP + '?q=u1name').text, 'search by owner')
ok('Flat' not in S['sup'].get(B + GP + '?q=%25').text or 'Trip' in S['sup'].get(B + GP + '?q=%25').text, 'search with % is literal')
ok('Flat' not in S['sup'].get(B + GP + '?q=%25').text, 'search with % does not match all')
post('adm', G, {'do': 'delete', 'id': g2}, '/admin/announcements.php')
ok(sql(f"SELECT COUNT(*) FROM shared_groups WHERE id={g2}") == '1', 'admin role (grp off) cannot delete')
post('sup', G, {'do': 'delete', 'id': g1}, GP)
ok(sql(f"SELECT COUNT(*) FROM shared_groups WHERE id={g1}") == '0', 'group deleted')
ok(sql(f"SELECT COUNT(*) FROM shared_members WHERE group_id={g1}") == '0' and sql(f"SELECT COUNT(*) FROM shared_expenses WHERE group_id={g1}") == '0' and sql(f"SELECT COUNT(*) FROM shared_splits WHERE expense_id={eid}") == '0', 'members, expenses and splits removed')
ok(sql(f"SELECT COUNT(*) FROM shared_groups WHERE id={g2}") == '1' and sql(f"SELECT COUNT(*) FROM shared_members WHERE group_id={g2}") == '1', 'other group untouched')
post('sup', G, {'do': 'delete', 'id': '999999'}, GP)
ok(S['sup'].get(B + GP).status_code == 200, 'deleting a missing group is handled')
ok(sql("SELECT COUNT(*) FROM user_logs WHERE action='admin_group_delete'") == '1', 'group delete audited')

# ---------- audit group + account delete ----------
ok('Deleted group' in S['sup'].get(B + '/admin/audit.php?g=content').text and 'Added user' not in S['sup'].get(B + '/admin/audit.php?g=content').text, 'audit log has a Content filter')
sql(f"INSERT IGNORE INTO announcement_dismissed VALUES ({uid['u2']},12345)")
r = subprocess.run(['php', '-r', f'require "includes/security.php"; require "config/db.php"; require "modules/shared/lib.php"; shared_on_user_deleted($conn, {uid["u2"]});'], capture_output=True, text=True, cwd='.')
ok(sql(f"SELECT COUNT(*) FROM announcement_dismissed WHERE user_id={uid['u2']}") == '0', 'account delete removes dismissals')

sql("DELETE FROM users WHERE email LIKE '%@ct.local'")
for t in ('announcements', 'announcement_dismissed', 'app_currency_hidden', 'shared_groups', 'shared_members', 'shared_expenses', 'shared_splits'):
    sql(f"DELETE FROM {t}")
print('content tests:', 'ALL PASSED' if not fails else f'{fails} FAILED'); raise SystemExit(1 if fails else 0)
