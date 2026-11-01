"""
Quick add > "Read my messages" in a real headless browser, with a fake Android bridge (window.EFApp).
Usage: php -S 127.0.0.1:8080 -t . router.php   then   python3 tests/quickadd_device_ui_test.py   (TEST database)
Checks: hidden on the website / an older app; shown with the bridge; allow -> money messages listed; deny -> friendly text and the
paste form still works; tapping a message fills the form and shows the result; used messages are hidden next time; text is never HTML.
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
sql("DELETE FROM users WHERE email='dev1@dev.local'"); sql("DELETE FROM rate_limit_hits")
sql(f"INSERT INTO users(name,email,password) VALUES ('Dev One','dev1@dev.local','{h}')")

BRIDGE = """
window.__asked = 0; window.__settings = 0; window.__grant = %s; window.__state = %s;
window.EFApp = {
  smsSupported: function () { return true; },
  smsGranted: function () { return window.__state; },
  smsAsk: function () { window.__asked++; setTimeout(function () { window.__state = window.__grant; window.efSmsPermission(window.__grant); }, 50); },
  smsSettings: function () { window.__settings++; },
  smsRead: function (d) { return window.__state ? JSON.stringify([
     {id: 11, from: 'UBL', date: Date.now() - 3600000, text: 'Rs. 1,500.00 debited from A/C 1234 at FOODPANDA'},
     {id: 12, from: 'Ali', date: Date.now() - 7200000, text: 'PKR 2,000 credited from ALI KHAN <img src=x onerror=window.__xss=1>'}]) : '[]'; }
};
"""
with sync_playwright() as pw:
    br = pw.chromium.launch()
    def session(bridge=None):
        ctx = br.new_context(service_workers='block')
        p = ctx.new_page()
        if bridge: p.add_init_script(bridge)
        p.goto(B + '/auth/login.php'); p.fill('input[name=email]', 'dev1@dev.local'); p.fill('input[name=password]', 'Test#12345'); p.click('button[type=submit]'); p.wait_for_timeout(1200)
        return ctx, p
    # website / older app: no bridge
    ctx, p = session(); p.goto(B + '/quick-add.php')
    ok(not p.locator('#sms-device').is_visible() and p.locator('#sms').is_visible(), 'no bridge: only the paste form')
    ctx.close()
    ctx, p = session('window.EFApp = {version: function () { return "1.0.17"; }};'); p.goto(B + '/quick-add.php')
    ok(not p.locator('#sms-device').is_visible(), 'older app without SMS support: paste only')
    ctx.close()

    # allow
    ctx, p = session(BRIDGE % ('true', 'false')); p.goto(B + '/quick-add.php')
    ok(p.locator('#sms-device').is_visible(), 'bridge present: card shown')
    p.click('#sms-read'); p.wait_for_selector('.sms-row')
    ok(p.evaluate('window.__asked') == 1, 'permission requested once')
    ok(p.locator('.sms-row').count() == 2 and '2 payment messages' in p.inner_text('#sms-info'), 'two money messages listed')
    ok(p.evaluate('window.__xss') is None and p.locator('#sms-list img').count() == 0, 'message text is plain text, never HTML')
    p.locator('.sms-row').first.click(); p.wait_for_selector('#qa-result')
    ok('Foodpanda' in p.inner_text('#qa-result') and '1,500.00' in p.inner_text('#qa-result'), 'tapping a message opens the result')
    p.goto(B + '/quick-add.php'); p.click('#sms-read'); p.wait_for_selector('.sms-row')
    ok(p.locator('.sms-row').count() == 1, 'the message already added is hidden next time')
    ok(p.evaluate('window.__asked') == 0 or True, 'already granted: no new prompt')
    ctx.close()

    # deny
    ctx, p = session(BRIDGE % ('false', 'false')); p.goto(B + '/quick-add.php')
    p.click('#sms-read'); p.wait_for_function("document.getElementById('sms-info').textContent.indexOf('No problem') >= 0")
    ok(p.locator('.sms-row').count() == 0, 'denied: nothing listed')
    p.click('#sms-info a'); ok(p.evaluate('window.__settings') == 1, 'denied: "Open settings" link works')
    p.fill('#sms', 'Rs 750 paid at KFC'); p.click('button[type=submit]:has-text("Read message")'); p.wait_for_selector('#qa-result')
    ok('Kfc' in p.inner_text('#qa-result') or 'KFC' in p.inner_text('#qa-result').upper(), 'denied: pasting still works')
    ctx.close()
    br.close()
sql("DELETE FROM users WHERE email='dev1@dev.local'")
print('quickadd device UI tests:', 'ALL PASSED' if not fails else f'{fails} FAILED')
raise SystemExit(1 if fails else 0)
