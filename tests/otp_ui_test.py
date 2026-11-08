"""Code-entry animation: wrong code -> shake + message, right code -> tick, then the next page. Needs the dev server on 127.0.0.1:8080."""
import sys
from playwright.sync_api import sync_playwright
B = 'http://127.0.0.1:8080'
bad = []
def ok(c, m):
    print(('ok   ' if c else 'FAIL ') + m)
    if not c: bad.append(m)
with sync_playwright() as p:
    b = p.chromium.launch()
    pg = b.new_context(viewport={'width': 1366, 'height': 800}).new_page()
    pg.goto(B + '/tests/ui_seed_otp.php'); pg.wait_for_selector('input.otp')
    pg.fill('input.otp', '000000')
    pg.wait_for_selector('.otps.chk', timeout=3000); ok(True, 'checking state shows')
    pg.screenshot(path='/tmp/otp_chk.png')
    pg.wait_for_selector('.otps.bad', timeout=5000); ok(True, 'wrong code shows the red state')
    ok(pg.input_value('input.otp') == '', 'wrong code clears the box')
    ok(len(pg.inner_text('.otps.bad').strip()) > 3, 'wrong code shows a message: ' + pg.inner_text('.otps.bad').strip())
    pg.screenshot(path='/tmp/otp_bad.png')
    pg.wait_for_timeout(700)
    pg.fill('input.otp', '123456')
    pg.wait_for_selector('.otps.ok', timeout=5000); ok(True, 'right code shows the green tick')
    pg.screenshot(path='/tmp/otp_ok.png')
    pg.wait_for_url('**/set-new-password.php', timeout=5000); ok(True, 'then continues to the new-password page')
    pg.goto(B + '/tests/ui_seed_otp.php?cleanup=1')
    b.close()
print('otp_ui_test: ' + ('ALL PASSED' if not bad else str(len(bad)) + ' FAILED'))
sys.exit(1 if bad else 0)
