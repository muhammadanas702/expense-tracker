"""Admin home "Needs your attention" list (Part 102): each problem shows in plain words with a button to the page that fixes it, only for staff who may open that page.
Usage: PHP_CLI_SERVER_WORKERS=4 EXPENSEFLOW_2FA_OFF=1 php -S 127.0.0.1:8080 -t . router.php   then   python3 tests/admin_attention_test.py"""
import re, os, glob, subprocess, requests
B = 'http://127.0.0.1:8080'
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
FEAT = ROOT + '/storage/features.json'
fails = 0
def sql(q): return subprocess.run(['mysql', '--socket=/run/mysqld/mysqld.sock', '-uroot', 'expenseflow_test', '-N', '-r', '-e', q], capture_output=True, text=True).stdout.strip()
def ok(c, m):
    global fails
    print(('ok   ' if c else 'FAIL ') + m)
    if not c: fails += 1
def backups():
    for f in glob.glob(ROOT + '/storage/backups/*'): os.remove(f)
def fresh_backup():
    subprocess.run(['php', '-r', "$_SERVER['HTTP_HOST']='localhost';require '" + ROOT + "/includes/security.php';require '" + ROOT + "/config/db.php';require '" + ROOT + "/modules/backup/lib.php';backup_create($conn);"], capture_output=True)
def reset():
    if os.path.exists(FEAT): os.remove(FEAT)
    sql("DELETE FROM app_settings WHERE k='maintenance'")
    sql("DELETE FROM mail_log"); sql("DELETE FROM support_tickets"); sql("DELETE FROM security_events")
reset(); backups()
h = subprocess.run(['php', '-r', 'echo password_hash("Test#12345",PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout
requests.get(B + '/auth/login.php')
sql("DELETE FROM users WHERE email LIKE '%@at.local'"); sql("DELETE FROM rate_limit_hits")
for n, r in {'sup': 'super', 'help': 'support', 'u1': 'user'}.items():
    sql(f"INSERT INTO users(name,email,password,role,is_admin) VALUES ('{n}name','{n}@at.local','{h}','{r}',{0 if r == 'user' else 1})")
uid = sql("SELECT id FROM users WHERE email='u1@at.local'")
def login(n):
    s = requests.Session()
    t = re.search(r'name="csrf_token" value="([^"]+)"', s.get(B + '/auth/login.php').text).group(1)
    s.post(B + '/auth/login.php', data={'email': f'{n}@at.local', 'password': 'Test#12345', 'csrf_token': t}, allow_redirects=False)
    return s
sup, help_ = login('sup'), login('help')
def card(s):
    r = s.get(B + '/admin/index.php'); m = re.search(r'id="attention".*?</div>\s*(?=<style>)', r.text, re.S)
    return r.status_code, (m.group(0) if m else '')
def rows(c): return re.findall(r'class="attn-row (\w+)">.*?<b>(.*?)</b><small>(.*?)</small>.*?href="([^"]+)"', c, re.S)

st, c = card(sup)
ok(st == 200 and 'id="attention"' in c, 'admin home shows the attention card')
ok('waiting for an answer' not in c and 'could not be sent' not in c and 'switched off' not in c and 'backup' not in c.lower().replace('backups', ''), 'with nothing wrong there are no support, mail, switch or backup rows')
# support tickets
sql(f"INSERT INTO support_tickets (user_id, subject, category, status) VALUES ({uid}, 'Help me', 'other', 'open'), ({uid}, 'Again', 'other', 'open'), ({uid}, 'Old', 'other', 'closed')")
st, c = card(sup)
rr = [r for r in rows(c) if 'waiting for an answer' in r[1]]
ok(len(rr) == 1 and rr[0][1].startswith('2 users are waiting') and rr[0][3].endswith('admin/support.php?s=open') and rr[0][0] == 'warn', 'open support tickets: "2 users are waiting for an answer" + link to the open tickets')
# mail failures
for i in range(3): sql("INSERT INTO mail_log (created_at,to_mask,subject,ok,error,ms) VALUES (NOW(),'a***@x.com','Your code',0,'SMTP down',5)")
sql("INSERT INTO mail_log (created_at,to_mask,subject,ok,error,ms) VALUES (NOW() - INTERVAL 3 DAY,'a***@x.com','Old',0,'old',5)")
st, c = card(sup); rr = [r for r in rows(c) if 'could not be sent' in r[1]]
ok(len(rr) == 1 and rr[0][1].startswith('3 e-mails') and rr[0][0] == 'bad' and rr[0][3].endswith('admin/mail.php'), 'three failed e-mails in 24h (the old one is ignored): red row + link to the Email log')
# switched off
open(FEAT, 'w').write('{"off":["goals","search"]}')
st, c = card(sup); rr = [r for r in rows(c) if 'switched off' in r[1]]
ok(len(rr) == 1 and rr[0][1].startswith('2 options are') and 'Savings goals' in rr[0][2] and rr[0][3].endswith('admin/features.php') and rr[0][0] == 'info', 'switched-off options are listed by name with a link to Features')
# maintenance
sql("REPLACE INTO app_settings (k,v) VALUES ('maintenance','1')")
st, c = card(sup); rr = [r for r in rows(c) if 'paused' in r[1]]
ok(len(rr) == 1 and rr[0][0] == 'bad' and rr[0][3].endswith('admin/settings.php'), 'maintenance mode shows a red "website is paused" row')
sql("DELETE FROM app_settings WHERE k='maintenance'")
# backup: opening the admin home makes the automatic daily backup, so a missing backup fixes itself and no backup row is shown
backups(); st, c = card(sup)
ok(not [r for r in rows(c) if ('no backup' in r[1].lower() or 'days old' in r[1])] and len(glob.glob(ROOT + '/storage/backups/*')) >= 1, 'a missing backup is made automatically on opening the admin home, so no backup row')
# security
for i in range(25): sql("INSERT INTO security_events (kind, ip, who, created_at) VALUES ('login_fail','1.2.3.4','x@y.z',NOW())")
st, c = card(sup); rr = [r for r in rows(c) if 'failed sign-ins' in r[1]]
ok(len(rr) == 1 and rr[0][1].startswith('25 failed') and rr[0][3].endswith('admin/security.php'), 'many failed sign-ins: row with a link to Security')
# ordering
lv = [r[0] for r in rows(sup.get(B + '/admin/index.php').text and card(sup)[1])]
ok(lv == sorted(lv, key=lambda x: {'bad': 0, 'warn': 1, 'info': 2}[x]), 'worst problems come first (%s)' % ','.join(lv))
ok('Needs your attention' in card(sup)[1], 'title says "Needs your attention"')
# a support-role staff member sees only what they may open
st, c = card(help_)
ok(st == 200 and 'waiting for an answer' in c and 'could not be sent' not in c and 'switched off' not in c and 'failed sign-ins' not in c and 'paused' not in c, 'support staff see the support row only, not mail / features / security rows')
# cleared -> only environment checks may remain; all our rows gone
reset(); fresh_backup(); sql("DELETE FROM security_events")
st, c = card(sup)
ok(all(k not in c for k in ['waiting for an answer', 'could not be sent', 'switched off', 'failed sign-ins', 'paused']), 'after fixing everything our rows are gone')
# page works on phone width without sideways scroll
from playwright.sync_api import sync_playwright
open(FEAT, 'w').write('{"off":["goals"]}'); sql(f"INSERT INTO support_tickets (user_id, subject, category, status) VALUES ({uid}, 'Help me', 'other', 'open')")
with sync_playwright() as p:
    b = p.chromium.launch()
    for w, m in [(360, True), (1280, False)]:
        pg = b.new_context(viewport={'width': w, 'height': 800}, is_mobile=m).new_page()
        pg.goto(B + '/auth/login.php'); pg.fill('input[name=email]', 'sup@at.local'); pg.fill('input[name=password]', 'Test#12345'); pg.click('button[type=submit]'); pg.wait_for_load_state()
        pg.goto(B + '/admin/index.php'); pg.wait_for_selector('#attention')
        ok(pg.evaluate('document.documentElement.scrollWidth <= innerWidth'), f'no sideways scroll at {w}px')
        ok(pg.evaluate("[...document.querySelectorAll('#attention .btn')].every(b => b.getBoundingClientRect().height >= 30)"), f'buttons are tap-sized at {w}px')
        if w == 360: pg.locator('#attention').screenshot(path='/tmp/attn_m.png')
        else: pg.locator('#attention').screenshot(path='/tmp/attn_d.png')
    b.close()
reset(); backups(); sql("DELETE FROM users WHERE email LIKE '%@at.local'")
print('admin_attention_test: ' + ('ALL PASSED' if not fails else f'{fails} FAILED')); raise SystemExit(1 if fails else 0)
