"""Feature switches (Part 101a): Admin > Features turns options off (hidden + blocked) and on again.
Checks the staff rules, every switch one by one (page blocked, links gone), the dependency (Shared off takes Friends/Activity/Comments/Receipts with it),
that EVERYTHING off still leaves every page working (no server errors), and that a broken switch file fails open.
Usage: PHP_CLI_SERVER_WORKERS=4 EXPENSEFLOW_2FA_OFF=1 php -S 127.0.0.1:8080 -t . router.php   then   python3 tests/admin_features_test.py"""
import re, os, subprocess, requests
B = 'http://127.0.0.1:8080'
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
FILE = ROOT + '/storage/features-off.json'
fails = 0
def sql(q): return subprocess.run(['mysql', '--socket=/run/mysqld/mysqld.sock', '-uroot', 'expenseflow_test', '-N', '-r', '-e', q], capture_output=True, text=True).stdout.strip()
def ok(c, m):
    global fails
    print(('ok   ' if c else 'FAIL ') + m)
    if not c: fails += 1
def reset():
    if os.path.exists(FILE): os.remove(FILE)
reset()
h = subprocess.run(['php', '-r', 'echo password_hash("Test#12345",PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout
requests.get(B + '/auth/login.php')
sql("DELETE FROM users WHERE email LIKE '%@ft.local'"); sql("DELETE FROM rate_limit_hits")
for n, r in {'sup': 'super', 'u1': 'user'}.items():
    sql(f"INSERT INTO users(name,email,password,role,is_admin) VALUES ('{n}name','{n}@ft.local','{h}','{r}',{0 if r == 'user' else 1})")
uid = sql("SELECT id FROM users WHERE email='u1@ft.local'")
sql(f"INSERT INTO user_settings (user_id, savings_goal, notifications, setup_done) VALUES ({uid}, 20, 1, 1)")
gid = subprocess.run(['php', ROOT + '/tests/ui_seed_shared.php', uid], capture_output=True, text=True).stdout.strip()
def login(n):
    s = requests.Session()
    t = re.search(r'name="csrf_token" value="([^"]+)"', s.get(B + '/auth/login.php').text).group(1)
    s.post(B + '/auth/login.php', data={'email': f'{n}@ft.local', 'password': 'Test#12345', 'csrf_token': t}, allow_redirects=False)
    return s
sup, u1 = login('sup'), login('u1')
def tok(): return re.search(r'name="csrf_token" value="([^"]+)"', sup.get(B + '/admin/features.php').text).group(1)
def sw(id, do, csrf=None): return sup.post(B + '/admin/features-action.php', data={'id': id, 'do': do, 'csrf_token': csrf or tok()})
get = lambda p, s=None: (s or u1).get(B + p)

# ---- the admin page and its rules
r = sup.get(B + '/admin/features.php')
ok(r.status_code == 200 and 'Features on / off' in r.text, 'staff can open Admin > Features')
ok(r.text.count('class="g card ft ') == 14, 'all 14 options are listed (%d)' % r.text.count('class="g card ft '))
ok('If you turn it off:' in r.text and 'Monthly spending limit' in r.text, 'every option has a plain description and an "if you turn it off" line')
ok('Features on / off' in sup.get(B + '/admin/index.php').text, 'it is in the admin menu')
ok(u1.get(B + '/admin/features.php', allow_redirects=False).status_code in (302, 403), 'a normal user cannot open it')
ok(u1.post(B + '/admin/features-action.php', data={'id': 'goals', 'do': 'off'}, allow_redirects=False).status_code in (302, 403) and not os.path.exists(FILE), 'a normal user cannot switch anything')
sup.post(B + '/admin/features-action.php', data={'id': 'goals', 'do': 'off'})
ok(not os.path.exists(FILE), 'a switch without the CSRF token is refused')
r = sw('bogus', 'off'); ok('Unknown option' in r.text and not os.path.exists(FILE), 'unknown option is refused')
r = sw('goals', 'maybe'); ok('Unknown option' in r.text, 'unknown action is refused')

# ---- baseline: all pages work and the markers exist
PAGES = ['/dashboard.php', '/income/add-income.php', '/expense/add-expense.php', '/reports/view-report.php', '/budgets.php', '/goals.php', '/reminders.php', '/quick-add.php', '/search.php?q=a', '/profile.php',
         '/settings/account.php', '/settings/apps.php', '/settings/data.php', '/settings/notifications.php', '/settings/preferences.php', '/settings/security.php',
         '/activity/notifications.php', '/activity/index.php', '/friends/index.php', '/shared/index.php', '/shared/groups.php', f'/shared/group.php?id={gid}', f'/shared/add.php?group={gid}',
         '/support/index.php', '/auth/login.php', '/auth/register.php', '/', '/about.php', '/contact.php', '/guides/']
def crawl(tag):
    bad = []
    for p in PAGES:
        r = get(p)
        body = r.text
        if r.status_code >= 500 and r.status_code != 503 or re.search(r'(Fatal error|Warning:|Notice:|Deprecated:|Uncaught)', body):
            bad.append(f'{p} -> {r.status_code}')
    ok(not bad, f'{tag}: no server error, warning or fatal on {len(PAGES)} pages ' + (str(bad[:4]) if bad else ''))
crawl('baseline')
base = {p: get(p).text for p in ['/dashboard.php', '/profile.php', f'/shared/group.php?id={gid}', f'/shared/add.php?group={gid}', '/shared/index.php', '/settings/notifications.php']}
# id -> (page, marker present when on, gated page or None)
CHECK = {
    'budgets': ('/dashboard.php', 'budgets.php', '/budgets.php'),
    'goals': ('/dashboard.php', 'goals.php', '/goals.php'),
    'reminders': ('/dashboard.php', 'reminders.php', '/reminders.php'),
    'quickadd': ('/dashboard.php', 'quick-add.php', '/quick-add.php'),
    'search': ('/dashboard.php', 'id="dash-search"', '/search.php?q=a'),
    'gettingstarted': ('/dashboard.php', 'id="gs-card"', None),
    'shared': ('/dashboard.php', '/shared/index.php', '/shared/index.php'),
    'friends': ('/shared/index.php', 'friends/index.php', '/friends/index.php'),
    'activity': ('/dashboard.php', 'activity/notifications.php', '/activity/index.php'),
    'comments': (f'/shared/group.php?id={gid}', 'comments/action.php', None),
    'receipts': (f'/shared/add.php?group={gid}', 'name="receipt"', None),
    'support': ('/profile.php', 'support/index.php', '/support/index.php'),
    'push': ('/settings/notifications.php', 'pushT', '/push-subscribe.php'),
    'oauth': ('/auth/login.php', 'google', '/auth/google.php'),
}
def page(p): return base.get(p) or get(p).text
for fid, (pg, marker, gated) in CHECK.items():
    reset()
    before = get(pg).text
    has = marker in before
    sw(fid, 'off')
    st = get(gated, u1) if gated else None
    after = get(pg).text
    msg = f'{fid}: '
    parts = []
    if gated:
        parts.append(st.status_code == 503 and 'switched off' in st.text and st.headers.get('Retry-After') == '600')
    if has:
        parts.append(marker not in after)
    ok(all(parts), msg + ('page blocked (503)' if gated else 'no page') + (', link/widget gone' if has else ', (marker not shown in this test setup)'))
    sw(fid, 'on')
    ok((get(gated).status_code == 200 if gated and fid != 'oauth' and fid != 'push' else True) and (marker in get(pg).text) == has, f'{fid}: turned on again, everything is back')
reset()

# ---- JSON/XHR callers get JSON, not a web page
sw('push', 'off'); r = u1.get(B + '/push-subscribe.php', headers={'X-Requested-With': 'XMLHttpRequest'})
ok(r.status_code == 503 and r.headers['Content-Type'].startswith('application/json') and r.json()['ok'] is False, 'script callers get a JSON answer while an option is off')
reset()
# ---- dependency
sw('shared', 'off')
r = sup.get(B + '/admin/features.php').text
ok(r.count('It is off because') == 4, 'Shared off: Friends, Activity, Comments and Receipts show "off because Shared is off"')
ok(all(get(p).status_code == 503 for p in ['/shared/groups.php', '/friends/index.php', '/activity/index.php', f'/shared/group.php?id={gid}']), 'Shared off: shared, friends and activity pages are blocked')
ok(get('/dashboard.php').status_code == 200 and '/shared/groups.php' not in get('/dashboard.php').text, 'Shared off: the personal side keeps working and the Shared menu is gone')
ok(get('/activity/notifications.php').status_code == 200, 'the bell list page still opens')
reset()
# ---- everything off
for fid in CHECK: sw(fid, 'off')
ok(len(__import__('json').load(open(FILE))) == 14, 'all 14 switches are saved')
crawl('EVERYTHING OFF')
ok('14 options are off' in sup.get(B + '/admin/features.php').text, 'the admin page says how many are off')
for p in ['/admin/index.php', '/admin/support.php', '/admin/groups.php', '/admin/branding.php', '/admin/health.php', '/admin/settings.php']:
    r = sup.get(B + p)
    ok(r.status_code == 200 and not re.search(r'(Fatal error|Warning:|Uncaught)', r.text), f'staff page {p} still works with everything off')
ok(get('/dashboard.php').status_code == 200 and get('/income/add-income.php').status_code == 200 and get('/expense/add-expense.php').status_code == 200, 'income, expense and dashboard work with everything off')
ok(requests.get(B + '/auth/login.php').status_code == 200, 'login still works with everything off')
# audit
ok(int(sql("SELECT COUNT(*) FROM user_logs WHERE action='admin_feature_change'") or 0) > 20, 'every switch is written to the audit log')
# fail open
open(FILE, 'w').write('{broken json')
ok(get('/goals.php').status_code == 200 and get('/dashboard.php').status_code == 200, 'a broken switch file fails open (everything stays on)')
open(FILE, 'w').write('["goals","nonsense",5]')
ok(get('/goals.php').status_code == 503 and get('/budgets.php').status_code == 200, 'unknown names in the file are ignored')
reset(); sql("DELETE FROM users WHERE email LIKE '%@ft.local'")
print('admin_features_test: ' + ('ALL PASSED' if not fails else f'{fails} FAILED')); raise SystemExit(1 if fails else 0)
