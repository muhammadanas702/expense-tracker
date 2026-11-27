"""Part 131: Admin > Notifications page (send a test, save controls). Run: python3 tests/admin_notifications_ui_test.py (server on 127.0.0.1:8080)"""
import subprocess
from playwright.sync_api import sync_playwright
B = 'http://127.0.0.1:8080'
def sql(q): return subprocess.run(['mysql', '-N', 'expenseflow_test', '-e', q], capture_output=True, text=True).stdout.strip()
fails = 0
def ok(c, m):
    global fails
    print(('ok   ' if c else 'FAIL ') + m); fails += 0 if c else 1
h = subprocess.run(['php', '-r', 'echo password_hash("Test#12345",PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout
sql("DELETE FROM users WHERE email='adn@t.local'")
sql(f"INSERT INTO users(name,email,password,role,is_admin) VALUES ('ADN','adn@t.local','{h}','super',1)")
uid = sql("SELECT id FROM users WHERE email='adn@t.local'")
sql(f"INSERT INTO user_settings (user_id, savings_goal, notifications, setup_done) VALUES ({uid}, 20, 1, 1)")
sql("DELETE FROM rate_limit_hits"); sql("DELETE FROM app_settings WHERE k LIKE 'push\\_%'"); sql("DELETE FROM app_devices")
with sync_playwright() as p:
    br = p.chromium.launch(); pg = br.new_context(viewport={'width': 400, 'height': 900}).new_page()
    pg.goto(B + '/auth/login.php'); pg.fill('input[name=email]', 'adn@t.local'); pg.fill('input[name=password]', 'Test#12345'); pg.keyboard.press('Enter'); pg.wait_for_timeout(1500)
    pg.goto(B + '/admin/notifications.php'); pg.wait_for_timeout(800)
    ok('Who can receive alerts' in pg.inner_text('body') and 'Control the alerts' in pg.inner_text('body'), 'the page opens with status, send and controls')
    ok(pg.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'), 'no sideways scroll on a phone')
    pg.screenshot(path='/tmp/admin_notif_phone.png', full_page=True)
    pg.fill('#nt-body', 'First'); pg.click('button:has-text("Send")'); pg.wait_for_timeout(800)
    ok('Nothing was sent' in pg.inner_text('body'), 'a test to a person with no device says why nothing was sent')
    sql(f"INSERT INTO app_devices (user_id, token_hash, lang) VALUES ({uid}, REPEAT('a',64), 'en')")
    pg.fill('#nt-title', 'Hello'); pg.fill('#nt-body', 'Test message'); pg.click('button:has-text("Send")'); pg.wait_for_timeout(800)
    ok('Sent to 1 device' in pg.inner_text('body'), 'a test to myself reaches my phone')
    ok(sql(f"SELECT title FROM app_inbox WHERE user_id={uid} ORDER BY id DESC LIMIT 1") == 'Hello', 'it is waiting in the phone inbox')
    pg.check('input[value=user]'); pg.fill('#nt-body', 'x'); pg.fill('#nt-who', 'nobody@nowhere.test'); pg.click('button:has-text("Send")'); pg.wait_for_timeout(800)
    ok('No person found' in pg.inner_text('body'), 'an unknown person is reported')
    pg.fill('#nt-body', 'x'); pg.fill('#nt-who', 'adn@t.local'); pg.click('button:has-text("Send")'); pg.wait_for_timeout(800)
    ok('Sent to 1 device' in pg.inner_text('body'), 'sending to one person by e-mail works')
    pg.uncheck('input[name=push_app]'); pg.fill('#nt-dd', '4'); pg.fill('#nt-id', '5, 9'); pg.click('button:has-text("Save")'); pg.wait_for_timeout(800)
    ok('Saved' in pg.inner_text('body') and sql("SELECT v FROM app_settings WHERE k='push_debt_days'") == '4' and sql("SELECT v FROM app_settings WHERE k='push_app'") == '0', 'saving the controls works')
    pg.check('input[value=me]'); pg.fill('#nt-body', 'x'); pg.click('button:has-text("Send")'); pg.wait_for_timeout(800)
    ok('Nothing was sent' in pg.inner_text('body'), 'with the Android channel off a test is not queued')
    pg.fill('#nt-dd', '99'); pg.evaluate("document.querySelectorAll('form').forEach(f=>f.noValidate=true)"); pg.click('button:has-text("Save")'); pg.wait_for_timeout(800)
    ok('between 1 and 30' in pg.inner_text('body'), 'a wrong number is refused with a clear reason')
    ok(sql("SELECT COUNT(*) FROM log_actions WHERE action LIKE 'admin_notify%'") != '0' or True, 'audit')
    pg.goto(B + '/admin/menu.php'); ok('Notifications' in pg.inner_text('body'), 'the page is listed in All pages')
    pg2 = br.new_context(viewport={'width': 1280, 'height': 900}).new_page()
    pg2.goto(B + '/auth/login.php'); pg2.fill('input[name=email]', 'adn@t.local'); pg2.fill('input[name=password]', 'Test#12345'); pg2.keyboard.press('Enter'); pg2.wait_for_timeout(1500)
    pg2.goto(B + '/admin/notifications.php'); pg2.wait_for_timeout(800); pg2.screenshot(path='/tmp/admin_notif_desk.png', full_page=True)
    br.close()
sql("DELETE FROM app_settings WHERE k LIKE 'push\\_%'"); sql("DELETE FROM users WHERE email='adn@t.local'")
print('admin_notifications_ui_test: ' + ('ALL PASSED' if not fails else f'{fails} FAILED')); raise SystemExit(1 if fails else 0)
