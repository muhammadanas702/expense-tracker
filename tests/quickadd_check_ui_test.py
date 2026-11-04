"""
Part 56: "Check my SMS setup" panel with a fake Android bridge + the since_days action. LOCAL server + TEST database.
Usage: php -S 127.0.0.1:8080 -t . router.php (EXPENSEFLOW_2FA_OFF=1)   then   python3 tests/quickadd_check_ui_test.py
"""
import subprocess
from playwright.sync_api import sync_playwright
B = 'http://127.0.0.1:8080'
fails = 0
def sql(q): return subprocess.run(['mysql', '--socket=/run/mysqld/mysqld.sock', '-uroot', 'expenseflow_test', '-N', '-e', q], capture_output=True, text=True).stdout.strip()
def ok(c, m):
    global fails
    if not c: fails += 1; print('FAIL', m)
h = subprocess.run(['php', '-r', 'echo password_hash("Test#12345",PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout
sql("DELETE FROM users WHERE email='ck@ck.local'"); sql("DELETE FROM rate_limit_hits")
sql(f"INSERT INTO users(name,email,password) VALUES ('Ck','ck@ck.local','{h}')")
uid = sql("SELECT id FROM users WHERE email='ck@ck.local'")
BR = """
window.EFApp = { smsSupported: function(){return true;}, version: function(){return '1.0.19';}, smsGranted: function(){return %(read)s;},
  smsBackgroundReady: function(){return %(recv)s;}, smsHasToken: function(){return %(token)s;}, smsAsk: function(){}, smsSettings: function(){},
  smsNotifGranted: function(){return true;}, smsRead: function(){return '[]';}, smsSetToken: function(){}, smsClearToken: function(){},
  smsProbe: function(d, s){ return JSON.stringify(%(probe)s); } };
"""
def page(br, **kw):
    ctx = br.new_context(service_workers='block'); p = ctx.new_page()
    p.add_init_script(BR % kw)
    p.goto(B + '/auth/login.php'); p.fill('input[name=email]', 'ck@ck.local'); p.fill('input[name=password]', 'Test#12345'); p.click('button[type=submit]'); p.wait_for_timeout(1200)
    p.goto(B + '/quick-add.php'); p.wait_for_timeout(800)
    return ctx, p
def check(p):
    p.click('#sms-check-btn'); p.wait_for_timeout(1200); return p.inner_text('#sms-check-list')
with sync_playwright() as pw:
    br = pw.chromium.launch()
    # 1. auto off, no permission
    ctx, p = page(br, read='false', recv='false', token='false', probe='{error:"no_permission"}')
    t = check(p)
    ok('Auto-add is OFF' in t, 'says auto-add is off')
    ok('NOT allowed' in t and 'restricted settings' in t, 'says reading is not allowed and how to fix it')
    ok('not registered' in t, 'says the phone is not registered')
    ctx.close()
    # 2. auto on (server), everything allowed, money messages older than "since"
    sql(f"INSERT INTO sms_prefs (user_id,auto,since) VALUES ({uid},1,NOW()) ON DUPLICATE KEY UPDATE auto=1, since=NOW()")
    ctx, p = page(br, read='true', recv='true', token='true', probe='{total:42, money:3, moneyNew:0, newest:Date.now(), senders:["UBL","JazzCash"]}')
    t = check(p)
    ok('Auto-add is ON' in t and 'allowed' in t, 'auto on and permissions allowed')
    ok('42 messages' in t and '3 of them look like payments' in t and 'UBL, JazzCash' in t, 'inbox counts and senders are shown')
    ok('older than the moment auto-add was turned on' in t, 'explains why old messages are not added')
    ok(p.locator('#sms-since').is_visible(), 'offers to include the last few days')
    # use it
    p.select_option('#sms-since select', '7'); p.click('#sms-since button'); p.wait_for_timeout(1200)
    ok(sql(f"SELECT since < NOW() - INTERVAL 6 DAY FROM sms_prefs WHERE user_id={uid}") == '1', 'since moved back about 7 days')
    ok('last 7 days' in p.inner_text('body'), 'confirmation message shown')
    ctx.close()
    # 3. empty inbox
    ctx, p = page(br, read='true', recv='true', token='true', probe='{total:0, money:0, moneyNew:0, newest:0, senders:[]}')
    t = check(p); ok('0 messages' in t and 'Chat features' in t, 'empty inbox explained (RCS)')
    ctx.close()
    # 4. old app without smsProbe
    ctx = br.new_context(service_workers='block'); p = ctx.new_page()
    p.add_init_script("window.EFApp={smsSupported:function(){return true;},version:function(){return '1.0.18';},smsGranted:function(){return true;},smsBackgroundReady:function(){return true;},smsHasToken:function(){return false;},smsRead:function(){return '[]';}};")
    p.goto(B + '/auth/login.php'); p.fill('input[name=email]', 'ck@ck.local'); p.fill('input[name=password]', 'Test#12345'); p.click('button[type=submit]'); p.wait_for_timeout(1200)
    p.goto(B + '/quick-add.php'); p.wait_for_timeout(800); t = check(p)
    ok('needs a newer app' in t, 'older app: told the counter needs the new APK')
    ctx.close(); br.close()
# server: status carries the extra fields, since_days is bounded
import requests, re
s = requests.Session()
t = re.search(r'name="csrf_token" value="([^"]+)"', s.get(B + '/auth/login.php').text).group(1)
s.post(B + '/auth/login.php', data={'email': 'ck@ck.local', 'password': 'Test#12345', 'csrf_token': t})
st = s.get(B + '/quick-add-import.php').json()
ok(all(k in st for k in ('devices', 'last_sync', 'counts', 'now')) and st['counts'] == {'added': 0, 'review': 0, 'ignored': 0}, f'status has the new fields {st}')
tk = re.search(r'name="csrf_token" value="([^"]+)"', s.get(B + '/quick-add.php').text).group(1)
s.post(B + '/quick-add.php', data={'csrf_token': tk, 'qa_action': 'since_days', 'days': '9999'})
ok(sql(f"SELECT since > NOW() - INTERVAL 31 DAY FROM sms_prefs WHERE user_id={uid}") == '1', 'days are limited to 30')
sql("DELETE FROM users WHERE email='ck@ck.local'")
print('quickadd check tests:', 'ALL PASSED' if not fails else f'{fails} FAILED')
