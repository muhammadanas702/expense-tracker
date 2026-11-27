"""Part 130b: the page side of app alerts, with a pretend Android bridge. Run: python3 tests/app_alerts_ui_test.py (server on 127.0.0.1:8080)"""
import subprocess
from playwright.sync_api import sync_playwright
B = 'http://127.0.0.1:8080'
def sql(q): return subprocess.run(['mysql', '-N', 'expenseflow_test', '-e', q], capture_output=True, text=True).stdout.strip()
fails = 0
def ok(c, m):
    global fails
    print(('ok   ' if c else 'FAIL ') + m); fails += 0 if c else 1
h = subprocess.run(['php', '-r', 'echo password_hash("Test#12345",PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout
sql("DELETE FROM users WHERE email='aau@t.local'")
sql(f"INSERT INTO users(name,email,password,role,is_admin) VALUES ('AAU','aau@t.local','{h}','user',0)")
uid = sql("SELECT id FROM users WHERE email='aau@t.local'")
sql(f"INSERT INTO user_settings (user_id, savings_goal, notifications, setup_done) VALUES ({uid}, 20, 1, 1)")
sql("DELETE FROM rate_limit_hits")
BRIDGE = """
(function () {
  var S = sessionStorage, g = (k, d) => S.getItem('m_' + k) === null ? d : JSON.parse(S.getItem('m_' + k)), w = (k, v) => S.setItem('m_' + k, JSON.stringify(v));
  window.__calls = g('calls', []);
  var log = c => { var a = g('calls', []); a.push(c); w('calls', a); window.__calls = a; };
  Object.defineProperty(window, '__tok', { get: () => g('tok', '') });
  window.EFApp = { alertsSupported: () => true, alertsHasToken: () => !!g('tok', ''), alertsNotifGranted: () => g('granted', false),
    alertsAsk: () => { log('ask'); w('granted', true); }, alertsSetToken: (t, b) => { w('tok', t); log('set:' + b); },
    alertsClearToken: () => { w('tok', ''); log('clear'); }, smsSupported: () => false, version: () => '1.0.0' };
})();
"""
with sync_playwright() as p:
    br = p.chromium.launch(); ctx = br.new_context(); ctx.add_init_script(BRIDGE); pg = ctx.new_page()
    pg.goto(B + '/auth/login.php'); pg.fill('input[name=email]', 'aau@t.local'); pg.fill('input[name=password]', 'Test#12345'); pg.keyboard.press('Enter'); pg.wait_for_timeout(1500)
    pg.goto(B + '/dashboard.php'); pg.wait_for_timeout(2000)
    calls = pg.evaluate('window.__calls')
    ok('ask' in calls, 'the app is asked for the notification permission')
    ok(any(c.startswith('set:') for c in calls) and len(pg.evaluate('window.__tok')) == 64, 'the phone gets its secret automatically (alerts on by default)')
    ok(sql(f"SELECT COUNT(*) FROM app_devices WHERE user_id={uid}") == '1', 'one phone is registered on the server')
    pg.goto(B + '/dashboard.php'); pg.wait_for_timeout(1500)
    ok(sql(f"SELECT COUNT(*) FROM app_devices WHERE user_id={uid}") == '1', 'opening more pages does not register more phones')
    pg.goto(B + '/settings/notifications.php'); pg.wait_for_timeout(1500)
    t = pg.inner_text('#push-box')
    ok('On for this phone' in t, 'Settings shows alerts are on for this phone')
    pg.click('[data-push-btn]'); pg.wait_for_timeout(500)
    ok('clear' in pg.evaluate('window.__calls') and 'Off on this phone' in pg.inner_text('#push-box'), 'the person can turn it off')
    pg.goto(B + '/dashboard.php'); pg.wait_for_timeout(1500)
    ok(pg.evaluate('window.__tok') == '', 'once turned off it is not switched on again by itself')
    pg.goto(B + '/settings/notifications.php'); pg.wait_for_timeout(1000); pg.click('[data-push-btn]'); pg.wait_for_timeout(1500)
    ok(len(pg.evaluate('window.__tok')) == 64, 'and the person can turn it on again')
    br.close()
sql("DELETE FROM users WHERE email='aau@t.local'")
print('app_alerts_ui_test: ' + ('ALL PASSED' if not fails else f'{fails} FAILED')); raise SystemExit(1 if fails else 0)
