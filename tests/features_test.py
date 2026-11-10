"""Feature switches (Part 101a): Admin > Features turns optional parts off/on; off parts vanish from menus and their pages say "switched off";
nothing else breaks (no 500) with any single feature off.
Usage: PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8080 -t . router.php   then   EXPENSEFLOW_2FA_OFF=1 python3 tests/features_test.py"""
import re, os, json, subprocess, requests
B = 'http://127.0.0.1:8080'
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
FILE = ROOT + '/storage/features.json'
fails = 0
def sql(q): return subprocess.run(['mysql', '--socket=/run/mysqld/mysqld.sock', '-uroot', 'expenseflow_test', '-N', '-r', '-e', q], capture_output=True, text=True).stdout.strip()
def ok(c, m):
    global fails
    print(('ok   ' if c else 'FAIL ') + m)
    if not c: fails += 1
def clean():
    if os.path.exists(FILE): os.remove(FILE)
clean()
h = subprocess.run(['php', '-r', 'echo password_hash("Test#12345",PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout
requests.get(B + '/auth/login.php')
sql("DELETE FROM users WHERE email LIKE '%@ft.local'"); sql("DELETE FROM rate_limit_hits")
for n, r in {'sup': 'super', 'u1': 'user'}.items():
    sql(f"INSERT INTO users(name,email,password,role,is_admin) VALUES ('{n}name','{n}@ft.local','{h}','{r}',{0 if r=='user' else 1})")
def login(n):
    s = requests.Session()
    t = re.search(r'name="csrf_token" value="([^"]+)"', s.get(B + '/auth/login.php').text).group(1)
    s.post(B + '/auth/login.php', data={'email': f'{n}@ft.local', 'password': 'Test#12345', 'csrf_token': t}, allow_redirects=False)
    return s
sup, u1 = login('sup'), login('u1')
P = B + '/admin/features.php'; A = B + '/admin/features-action.php'
tok = lambda: re.search(r'name="csrf_token" value="([^"]+)"', sup.get(P).text).group(1)
def sw(do, fid=None):
    d = {'do': do, 'csrf_token': tok()}
    if fid: d['id'] = fid
    return sup.post(A, data=d, allow_redirects=True)
def off_list(): return json.load(open(FILE))['off'] if os.path.exists(FILE) else []

# access + page content
ok(u1.get(P, allow_redirects=False).status_code == 403, 'plain user cannot open Features')
ok(requests.get(P, allow_redirects=False).status_code in (302, 303), 'guest is sent to login')
r = sup.get(P); ok(r.status_code == 200, 'super admin opens Features')
ids = ['budgets', 'goals', 'reminders', 'quickadd', 'search', 'gettingstarted', 'push', 'shared', 'friends', 'activity', 'comments', 'receipts', 'signup', 'oauth', 'twofactor', 'support', 'announce', 'reports', 'rates', 'language', 'downloads', 'guides', 'contact']
ok(all(f'id="f-{i}"' in r.text for i in ids), 'all 23 switchable features are listed')
ok(r.text.count('When off:') == 23 and 'Savings goals' in r.text and 'Something not working properly?' in r.text, 'each has a plain description and a "When off" line')
ok(r.text.count('role="switch"') == 23, 'each has a switch')
ok('admin/features.php' in sup.get(B + '/admin/index.php').text, 'menu links to Features on/off')
# rules
ok(u1.post(A, data={'do': 'off', 'id': 'goals'}, allow_redirects=False).status_code == 403, 'plain user cannot switch')
sup.post(A, data={'do': 'off', 'id': 'goals'}, allow_redirects=False); ok(off_list() == [], 'switching without the CSRF token does nothing')
ok('Unknown feature' in sw('off', 'nope').text and off_list() == [], 'unknown feature is refused')
ok('Unknown feature' in sw('off', '../../x').text, 'junk id is refused')

# pages: (path, feature that blocks it or None)
PAGES = [('dashboard.php', None), ('profile.php', None), ('income/add-income.php', None), ('expense/add-expense.php', None), ('reports/view-report.php', 'reports'),
         ('budgets.php', 'budgets'), ('goals.php', 'goals'), ('reminders.php', 'reminders'), ('quick-add.php', 'quickadd'), ('search.php', 'search'),
         ('shared/index.php', 'shared'), ('shared/groups.php', 'shared'), ('shared/add.php', 'shared'),
         ('friends/index.php', 'friends'), ('activity/index.php', 'activity'), ('activity/notifications.php', 'activity'), ('support/index.php', 'support'), ('guides/index.php', 'guides'), ('guides/track-monthly-expenses.php', 'guides'),
         ('auth/login.php', None), ('auth/register.php', 'signup'), ('contact.php', 'contact'), ('index.php', None), ('admin/index.php', None), ('admin/features.php', None), ('admin/support.php', None)]
def blocked(page, off):
    for p, f in PAGES:
        if p == page and f:
            cur = f
            while cur:
                if cur in off: return True
                cur = {'friends': 'shared', 'activity': 'shared'}.get(cur)
    return False
def check(off, label):
    bad = []
    for p, f in PAGES:
        s = sup if p.startswith('admin/') or True else None
        r = s.get(B + '/' + p, allow_redirects=False)
        want_block = blocked(p, off)
        if want_block and not (r.status_code == 503 and 'switched off for now' in r.text): bad.append(f'{p}: wanted switched-off page, got {r.status_code}')
        if not want_block and r.status_code not in (200, 302, 303): bad.append(f'{p}: got {r.status_code}')
    ok(not bad, label + (' -> ' + '; '.join(bad[:3]) if bad else ''))
check([], 'with everything on all pages work')
for fid in ids:
    sw('off', fid); ok(fid in off_list(), f'{fid}: switched off is saved')
    check(off_list(), f'{fid}: off -> its pages say switched off, nothing else breaks')
    sw('on', fid); ok(off_list() == [], f'{fid}: switched on again')

# menus and links disappear
dash = lambda: sup.get(B + '/dashboard.php').text
t = dash(); ok('budgets.php' in t and 'goals.php' in t and 'reminders.php' in t and 'quick-add.php' in t, 'menu shows the optional pages while on')
for fid, needle in [('budgets', 'budgets.php'), ('goals', 'goals.php'), ('reminders', 'reminders.php'), ('quickadd', 'quick-add.php')]:
    sw('off', fid); ok(needle not in dash(), f'{fid} off: its menu link is gone'); sw('on', fid)
sw('off', 'search'); ok('search.php' not in dash(), 'search off: the search box is gone'); sw('on', 'search')
sup.get(B + '/shared/index.php'); t = sup.get(B + '/shared/index.php').text
ok('shared/groups.php' in t and 'friends/index.php' in t, 'Shared menu shows Groups and Friends while on')
sw('off', 'friends'); ok('friends/index.php' not in sup.get(B + '/shared/index.php').text, 'friends off: its menu link is gone'); sw('on', 'friends')
sw('off', 'shared'); t = dash(); ok('shared/' not in t.replace('shared-', ''), 'shared off: no Shared links remain on the dashboard (%s)' % ('ok' if 'shared/' not in t.replace('shared-', '') else [m for m in re.findall(r'href="[^"]*shared/[^"]*"', t)][:2])); sw('on', 'shared')
ok(requests.get(B + '/auth/login.php').status_code == 200, 'login page works for guests')
sw('off', 'signup'); r = requests.get(B + '/auth/register.php'); ok(r.status_code == 503 and 'This part is switched off for now' in r.text, 'guests see the friendly message on the sign-up page')
ok(requests.get(B + '/auth/login.php').status_code == 200, 'login still works with sign-ups off'); sw('on', 'signup')

# Part 101b: extra switches - links disappear, language, downloads, rates
guest = lambda path='/': requests.get(B + path).text
sw('off', 'contact'); t = guest('/'); ok('contact.php' not in t and 'Still stuck?' not in t, 'contact off: footer and FAQ links to Contact are gone')
ok('contact.php' not in guest('/about.php'), 'contact off: also gone from other public pages'); sw('on', 'contact')
sw('off', 'guides'); t = guest('/'); ok('guides/' not in t and 'aria-label="Learn"' not in t, 'guides off: the Learn footer links are gone'); sw('on', 'guides')
ok('guides/' in guest('/') and 'contact.php' in guest('/'), 'guides and contact back on: links are back')
ok('Download APK' in guest('/') and 'Download for Windows' in guest('/'), 'download buttons show while on')
sw('off', 'downloads'); t = guest('/'); ok('Download APK' not in t and 'Download for Windows' not in t and 'Open in browser' in t, 'downloads off: Android/Windows buttons gone, browser card stays')
ok('Android app' not in sup.get(B + '/settings/apps.php').text, 'downloads off: also gone from Settings > Apps'); sw('on', 'downloads')
ok('Android app' in sup.get(B + '/settings/apps.php').text, 'downloads on: back in Settings')
ok('lang=ur' in guest('/') or 'hreflang="ur"' in guest('/'), 'language switch shows while on')
sw('off', 'language'); t = guest('/'); ok('hreflang="ur"' not in t and 'lang=ur' not in t, 'language off: the Urdu switch is gone')
r = requests.get(B + '/?lang=ur'); ok('dir="rtl"' not in r.text and 'lang="ur"' not in r.text.split('>')[0] + r.text[:300], 'language off: even ?lang=ur shows English')
sw('on', 'language'); ok('dir="rtl"' in requests.get(B + '/?lang=ur').text, 'language on: Urdu works again')
sw('off', 'reports'); t = dash(); ok('reports/view-report.php' not in t, 'reports off: Full Report links gone from the dashboard and menu')
ok('Full report' not in sup.get(B + '/settings/data.php').text, 'reports off: gone from Settings > Your data'); sw('on', 'reports')
ok('reports/view-report.php' in dash() and 'Full report' in sup.get(B + '/settings/data.php').text, 'reports on: back')
sw('off', 'rates'); ok(sup.get(B + '/dashboard.php').status_code == 200, 'rates off: dashboard still opens'); sw('on', 'rates')
# everything off at once, then all_on
for fid in ids: sw('off', fid)
ok(len(off_list()) == 23, 'all 23 can be off at the same time')
check(off_list(), 'with everything off: pages still work or show the message (no 500)')
r = sw('all_on'); ok(off_list() == [] and 'switched on again' in r.text and not os.path.exists(FILE), 'Turn everything back on restores all (file removed)')
ok(sql("SELECT COUNT(*) FROM admin_audit_log WHERE action='feature_change'") != '0', 'switches are written to the audit log')
clean(); sql("DELETE FROM users WHERE email LIKE '%@ft.local'")
print('features_test: ' + ('ALL PASSED' if not fails else f'{fails} FAILED')); raise SystemExit(1 if fails else 0)
