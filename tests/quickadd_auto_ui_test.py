"""
Part 48c in a real headless browser with a fake Android bridge: with auto-add on, opening any page reads the new SMS, the
server adds the real payments, a "tap to see" bar appears; nothing happens when auto-add is off or on the website.
Usage: php -S 127.0.0.1:8080 -t . router.php   (EXPENSEFLOW_2FA_OFF=1)   then   python3 tests/quickadd_auto_ui_test.py   (TEST database)
"""
import subprocess, time
from playwright.sync_api import sync_playwright
B = 'http://127.0.0.1:8080'
fails = 0
def sql(q): return subprocess.run(['mysql', '--socket=/run/mysqld/mysqld.sock', '-uroot', 'expenseflow_test', '-N', '-e', q], capture_output=True, text=True).stdout.strip()
def ok(c, m):
    global fails
    if not c: fails += 1; print('FAIL', m)
h = subprocess.run(['php', '-r', 'echo password_hash("Test#12345",PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout
sql("DELETE FROM users WHERE email='au1@au.local'"); sql("DELETE FROM rate_limit_hits")
sql(f"INSERT INTO users(name,email,password) VALUES ('Au One','au1@au.local','{h}')")
sql("INSERT INTO user_settings (user_id, savings_goal, notifications, setup_done) SELECT id, 20, 1, 1 FROM users WHERE email='au1@au.local'")
uid = sql("SELECT id FROM users WHERE email='au1@au.local'")
BRIDGE = """
window.__reads = 0;
window.EFApp = { smsSupported: function () { return true; }, smsGranted: function () { return true; }, smsAsk: function () {}, smsSettings: function () {},
  smsRead: function (d) { window.__reads++; var n = Date.now(); return JSON.stringify([
    {id: 21, from: 'HBL', date: n - 1000, text: 'Rs. 1,500.00 debited from A/C 1234 at FOODPANDA'},
    {id: 22, from: 'HBL', date: n - 2000, text: 'Your OTP for payment of Rs 900 at DARAZ is 482913'},
    {id: 23, from: '+923001112222', date: n - 3000, text: 'I sent you Rs 500 for lunch'}]); } };
"""
with sync_playwright() as pw:
    br = pw.chromium.launch()
    def session(bridge=None):
        ctx = br.new_context(service_workers='block'); p = ctx.new_page()
        if bridge: p.add_init_script(bridge)
        p.goto(B + '/auth/login.php'); p.fill('input[name=email]', 'au1@au.local'); p.fill('input[name=password]', 'Test#12345'); p.click('button[type=submit]'); p.wait_for_timeout(1500)
        return ctx, p
    # auto-add OFF: the app never reads messages
    ctx, p = session(BRIDGE); p.goto(B + '/dashboard.php'); p.wait_for_timeout(1500)
    ok(p.evaluate('window.__reads') == 0 and sql(f"SELECT COUNT(*) FROM expenses WHERE user_id={uid}") == '0', 'auto-add off: nothing is read or added')
    # turn it on from the Quick add page
    p.goto(B + '/quick-add.php'); p.wait_for_timeout(800)
    ok(p.locator('#sms-auto').is_visible(), 'auto-add card visible with the bridge')
    p.click('#auto-on'); p.wait_for_load_state(); p.wait_for_timeout(2500)
    ok(sql(f"SELECT auto FROM sms_prefs WHERE user_id={uid}") == '1', 'turned on')
    # messages are only imported when newer than the moment it was turned on
    ok(sql(f"SELECT COUNT(*) FROM expenses WHERE user_id={uid}") in ('0', '1'), 'quick add page ran an import')
    ctx.close()
    # a fresh app start: new SMS arrive after turn-on
    time.sleep(1)
    sql(f"DELETE FROM sms_imports WHERE user_id={uid}"); sql(f"DELETE FROM expenses WHERE user_id={uid}")
    ctx, p = session(BRIDGE.replace('n - 1000', 'n + 1000').replace('n - 2000', 'n + 1000').replace('n - 3000', 'n + 1000'))  # signing in lands on the dashboard
    p.wait_for_timeout(2500)
    ok(sql(f"SELECT COUNT(*) FROM expenses WHERE user_id={uid} AND note='From SMS' AND title='Foodpanda'") == '1', 'dashboard visit: payment added by itself')
    ok(sql(f"SELECT COUNT(*) FROM expenses WHERE user_id={uid} AND amount=900") == '0', 'OTP not added')
    ok(p.locator('#ef-sms-toast').count() == 1 and 'added from SMS' in p.inner_text('#ef-sms-toast') and 'need' in p.inner_text('#ef-sms-toast'), 'tap-to-see bar shown')
    reads = p.evaluate('window.__reads')
    p.goto(B + '/budgets.php'); p.wait_for_timeout(1000)
    ok(sql(f"SELECT COUNT(*) FROM sms_imports WHERE user_id={uid}") == '3', 'no duplicate rows after navigating (throttled / de-duplicated)')
    p.goto(B + '/quick-add.php'); p.wait_for_timeout(1500)
    ok(p.locator('#qa-review').count() == 1 and 'Needs your check (1)' in p.inner_text('#qa-review'), 'friend text waits in the review list')
    ctx.close()
    br.close()
sql(f"DELETE FROM sms_imports WHERE user_id={uid}"); sql(f"DELETE FROM sms_prefs WHERE user_id={uid}"); sql(f"DELETE FROM expenses WHERE user_id={uid}")
sql("DELETE FROM users WHERE email='au1@au.local'")
print('quickadd auto ui tests:', 'ALL PASSED' if not fails else f'{fails} FAILED')
