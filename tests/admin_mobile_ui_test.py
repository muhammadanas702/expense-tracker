"""
Admin overview on phones: the six number cards sit in 2 columns (3 rows) and stay small; on a wide screen they use more columns.
Usage: php -S 127.0.0.1:8080 -t . router.php   (EXPENSEFLOW_2FA_OFF=1)   then   python3 tests/admin_mobile_ui_test.py   (TEST database)
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
sql("DELETE FROM users WHERE email='am2@am.local'")
sql(f"INSERT INTO users(name,email,password,role,is_admin) VALUES ('Ad Two','am2@am.local','{h}','super',1)")
sql("INSERT INTO user_settings (user_id, savings_goal, notifications, setup_done) SELECT id, 20, 1, 1 FROM users WHERE email='am2@am.local'")
with sync_playwright() as pw:
    br = pw.chromium.launch()
    for w, mobile in ((320, True), (360, True), (412, True), (600, True), (1200, False)):
        ctx = br.new_context(viewport={'width': w, 'height': 900}, is_mobile=mobile, has_touch=mobile, service_workers='block'); p = ctx.new_page()
        p.goto(B + '/auth/login.php'); p.fill('input[name=email]', 'am2@am.local'); p.fill('input[name=password]', 'Test#12345'); p.click('button[type=submit]'); p.wait_for_timeout(1200)
        p.goto(B + '/admin/index.php'); p.wait_for_timeout(500)
        cols = p.evaluate("getComputedStyle(document.querySelector('.G4a')).gridTemplateColumns.split(' ').length")
        boxes = p.evaluate("[...document.querySelectorAll('.G4a > .stat')].map(e => { const r = e.getBoundingClientRect(); return [Math.round(r.top), Math.round(r.height), Math.round(r.right)]; })")
        rows = len({b[0] for b in boxes})
        over = p.evaluate("document.documentElement.scrollWidth > window.innerWidth + 1")
        if mobile and w <= 600:
            ok(cols == 2 and rows == 3, f'{w}px: 2 columns, 3 rows (got {cols} cols, {rows} rows)')
            ok(max(b[1] for b in boxes) < 110, f'{w}px: cards stay small (tallest {max(b[1] for b in boxes)}px)')
        else:
            ok(cols >= 3, f'{w}px: wide screen uses {cols} columns')
        ok(not over, f'{w}px: no sideways scrolling')
        ctx.close()
    br.close()
sql("DELETE FROM users WHERE email='am2@am.local'")
print('admin mobile tests:', 'ALL PASSED' if not fails else f'{fails} FAILED')
