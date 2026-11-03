"""
Admin overview on a phone: the six numbers sit in 2 columns (3 rows), compact, and nothing overflows sideways.
Usage: php -S 127.0.0.1:8080 -t . router.php   (EXPENSEFLOW_2FA_OFF=1)   then   python3 tests/admin_mobile_stats_ui_test.py   (TEST database)
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
sql("DELETE FROM users WHERE email='ams@am.local'")
sql(f"INSERT INTO users(name,email,password,role,is_admin) VALUES ('Am S','ams@am.local','{h}','super',1)")
sql("INSERT INTO user_settings (user_id, savings_goal, notifications, setup_done) SELECT id, 20, 1, 1 FROM users WHERE email='ams@am.local'")
with sync_playwright() as pw:
    br = pw.chromium.launch()
    for w, cols in ((360, 2), (412, 2), (1280, 3)):
        ctx = br.new_context(viewport={'width': w, 'height': 800}, service_workers='block'); p = ctx.new_page()
        p.goto(B + '/auth/login.php'); p.fill('input[name=email]', 'ams@am.local'); p.fill('input[name=password]', 'Test#12345'); p.click('button[type=submit]'); p.wait_for_timeout(1200)
        p.goto(B + '/admin/index.php'); p.wait_for_timeout(500)
        boxes = [p.locator('.G4a > .stat').nth(i).bounding_box() for i in range(6)]
        rows = sorted({round(b['y']) for b in boxes})
        ok(len(rows) == (3 if w < 700 else len(rows)) and (w >= 700 or len(rows) == 3), f'{w}px: stat cards in {len(rows)} rows')
        if w < 700:
            ok(abs(boxes[0]['y'] - boxes[1]['y']) < 2 and boxes[0]['x'] < boxes[1]['x'], f'{w}px: two cards side by side')
            ok(boxes[5]['y'] + boxes[5]['height'] < 560, f'{w}px: all six fit in the first screen ({boxes[5]["y"] + boxes[5]["height"]:.0f}px)')
            ok(max(b['height'] for b in boxes) < 110, f'{w}px: cards are compact ({max(b["height"] for b in boxes):.0f}px)')
        ok(p.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'), f'{w}px: no sideways scroll')
        if w == 360: p.screenshot(path='/tmp/claude-0/shots/admin360.png')
        ctx.close()
    br.close()
sql("DELETE FROM users WHERE email='ams@am.local'")
print('admin mobile stats tests:', 'ALL PASSED' if not fails else f'{fails} FAILED')
