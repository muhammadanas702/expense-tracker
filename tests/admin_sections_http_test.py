"""Admin sections + plain-words descriptions + "Where do I go?" (Part 101a).
Usage: PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8080 -t . router.php   then   python3 tests/admin_sections_http_test.py"""
import re, subprocess, requests
B = 'http://127.0.0.1:8080'
fails = 0
def sql(q): return subprocess.run(['mysql', '--socket=/run/mysqld/mysqld.sock', '-uroot', 'expenseflow_test', '-N', '-r', '-e', q], capture_output=True, text=True).stdout.strip()
def ok(c, m):
    global fails
    print(('ok   ' if c else 'FAIL ') + m)
    if not c: fails += 1
h = subprocess.run(['php', '-r', 'echo password_hash("Test#12345",PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout
requests.get(B + '/auth/login.php')
sql("DELETE FROM users WHERE email LIKE '%@as.local'"); sql("DELETE FROM rate_limit_hits")
for n, r in {'sup': 'super', 'help': 'support', 'u1': 'user'}.items():
    sql(f"INSERT INTO users(name,email,password,role,is_admin) VALUES ('{n}name','{n}@as.local','{h}','{r}',{0 if r=='user' else 1})")
def login(n):
    s = requests.Session()
    t = re.search(r'name="csrf_token" value="([^"]+)"', s.get(B + '/auth/login.php').text).group(1)
    s.post(B + '/auth/login.php', data={'email': f'{n}@as.local', 'password': 'Test#12345', 'csrf_token': t}, allow_redirects=False)
    return s
sup, hp, u1 = login('sup'), login('help'), login('u1')
sections = ['Overview', 'People', 'Turn things on / off', 'Look and content', 'Health and fixing', 'Safety']
r = sup.get(B + '/admin/index.php').text
side = r[r.index('<nav class="sbs"'):r.index('</nav>')]
pos = [side.index('>' + x + '<') for x in sections]
ok(pos == sorted(pos), 'sidebar has the six sections in order')
ok('title="Everyone who has an account.' in side and 'title="A part of the app is not working?' in side, 'sidebar links carry a plain-words tooltip')
for label in ['Dashboard', 'Where do I go?', 'Users', 'Support', 'Announcements', 'Roles &amp; permissions', 'Features on/off', 'Settings', 'Branding', 'Categories', 'Currencies', 'Groups', 'Website health', 'Speed check', 'Email log', 'Backups', 'Security', 'Audit log', 'User logs']:
    ok(label in side, 'menu has ' + label)
# every admin page in the menu has a description
php = subprocess.run(['php', '-r', "require 'modules/admin/layout.php'; foreach (admin_menu() as $s=>$it) foreach ($it as $i) echo (isset($i[5]) && strlen($i[5])>25 ? '' : 'MISSING '.$i[0]).' ';"], capture_output=True, text=True, cwd='.').stdout
ok('MISSING' not in php, 'every menu item has a description of its own (' + php.strip()[:60] + ')')
# dashboard hub
ok('What do you want to do?' in r and 'class="ahub"' in r and r.count('class="ahs"') == 5 and 'ahs ahs-tip' in r, 'dashboard has the "What do you want to do?" hub with 5 sections and a "Not sure" tile')
ok('Not sure? Where do I go?' in r, 'dashboard links to the problem finder')
ok('Switched off right now' not in r, 'no "switched off" notice when everything is on')
# all pages page
m = sup.get(B + '/admin/menu.php').text
ok('Everyone who has an account.' in m and 'Pause the whole website for maintenance' in m, 'All pages lists descriptions')
# help page
hpg = sup.get(B + '/admin/help.php'); t = hpg.text
ok(hpg.status_code == 200 and 'Something is wrong? Find it here.' in t, 'Where do I go? opens')
ok(t.count('class="hp-q"') >= 14, 'it lists many problems (%d)' % t.count('class="hp-q"'))
ok('People say the login code never arrives' in t and 'admin/mail.php' in t and 'admin/features.php' in t, 'login-code problem points to Email log and Features')
ok('admin/health.php' in t and 'admin/speed.php' in t and 'admin/branding.php' in t and 'admin/backups.php' in t, 'other problems point to Health, Speed, Branding, Backups')
ok('id="hp-q"' in t and 'hp-none' in t, 'it has a search box')
# support-role: links only to pages the role can open; others say ask the owner
t2 = hp.get(B + '/admin/help.php').text
ok('admin/support.php' in t2 and 'admin/health.php' not in t2 and '(ask the owner)' in t2, 'a support member sees only links they may open, the rest say "ask the owner"')
ok('admin/index.php' in hp.get(B + '/admin/index.php').text and 'Switched off right now' not in hp.get(B + '/admin/index.php').text, 'support dashboard still works')
# plain user is kept out
ok(u1.get(B + '/admin/help.php', allow_redirects=False).status_code in (302, 303, 403), 'plain user cannot open Where do I go?')
ok(requests.get(B + '/admin/help.php', allow_redirects=False).status_code in (302, 303, 403), 'signed-out visitor cannot open it')
# off notice
sup_t = re.search(r'name="csrf_token" value="([^"]+)"', sup.get(B + '/admin/features.php').text).group(1)
sup.post(B + '/admin/features-action.php', data={'do': 'off', 'id': 'budgets', 'csrf_token': sup_t})
r = sup.get(B + '/admin/index.php').text
ok('Switched off right now:</b> Budgets' in r, 'dashboard warns when something is switched off')
sup.post(B + '/admin/features-action.php', data={'do': 'all_on', 'csrf_token': sup_t})
ok('Switched off right now' not in sup.get(B + '/admin/index.php').text, 'notice goes away when everything is on again')
# phone bottom bar unchanged: first three items + More
bn = r[r.index('<nav class="g bn">'):]
bn = bn[:bn.index('</nav>')]
ok('Where' not in bn and 'More' in bn and 'Dashboard' in bn, 'phone bottom bar keeps Dashboard ... More (help is not squeezed in)')
sql("DELETE FROM users WHERE email LIKE '%@as.local'")
print('admin_sections_http_test: ' + ('ALL PASSED' if not fails else f'{fails} FAILED')); raise SystemExit(1 if fails else 0)
