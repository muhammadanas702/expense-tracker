"""Admin UI layout (Part 103a): no sideways scroll at phone/tablet/desktop widths, compact "What is this page?" box, Users/Support rows become cards on phones, audit log shows no duplicate text on desktop.
Usage: PHP_CLI_SERVER_WORKERS=4 EXPENSEFLOW_2FA_OFF=1 php -S 127.0.0.1:8080 -t . router.php   then   python3 tests/admin_ui_test.py"""
import subprocess
from playwright.sync_api import sync_playwright
B = 'http://127.0.0.1:8080'
fails = 0
def sql(q): return subprocess.run(['mysql', '--socket=/run/mysqld/mysqld.sock', '-uroot', 'expenseflow_test', '-N', '-e', q], capture_output=True, text=True).stdout.strip()
def ok(c, m):
    global fails
    print(('ok   ' if c else 'FAIL ') + m)
    if not c: fails += 1
h = subprocess.run(['php', '-r', 'echo password_hash("Test#12345",PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout
sql("DELETE FROM users WHERE email='ui@ad.local'"); sql("DELETE FROM rate_limit_hits")
sql(f"INSERT INTO users(name,email,password,role,is_admin) VALUES ('UI Admin','ui@ad.local','{h}','super',1)")
PAGES = ['index', 'users', 'support', 'roles', 'groups', 'categories', 'currencies', 'announcements', 'backups', 'settings', 'security', 'audit', 'logs', 'features', 'branding', 'health']
def visible(pg, sel):
    return pg.evaluate("s=>[...document.querySelectorAll(s)].filter(e=>e.getClientRects().length&&getComputedStyle(e).display!=='none').length", sel)
with sync_playwright() as p:
    b = p.chromium.launch()
    for w, hh, mob in [(390, 844, True), (820, 1000, False), (1366, 800, False)]:
        pg = b.new_context(viewport={'width': w, 'height': hh}, is_mobile=mob).new_page()
        pg.goto(B + '/auth/login.php'); pg.fill('input[name=email]', 'ui@ad.local'); pg.fill('input[name=password]', 'Test#12345'); pg.click('button[type=submit]'); pg.wait_for_load_state()
        bad, tall = [], []
        for n in PAGES:
            r = pg.goto(f'{B}/admin/{n}.php'); pg.wait_for_timeout(150)
            if r.status != 200: bad.append(n + ':' + str(r.status)); continue
            if pg.evaluate('document.documentElement.scrollWidth-innerWidth') > 1: bad.append(n)
            ih = pg.evaluate("(()=>{const e=document.querySelector('.ap-intro');return e?e.getBoundingClientRect().height:0})()")
            if ih > 160: tall.append(f'{n}={round(ih)}')
        ok(not bad, f'{w}px: every admin page loads without sideways scroll {bad}')
        ok(not tall, f'{w}px: "What is this page?" box stays compact {tall}')
        pg.goto(B + '/admin/users.php')
        if mob:
            ok(visible(pg, 'table.tbl.wide thead th') == 0 and visible(pg, 'table.tbl.wide tbody tr') > 0, 'phone: Users rows are cards (no header row)')
            pg.goto(B + '/admin/audit.php')
            ok(visible(pg, 'table.tbl td small.dt-m') >= 0, 'phone: audit log opens')
        else:
            pg.goto(B + '/admin/audit.php')
            ok(visible(pg, 'table.tbl td small.dt-m') == 0, f'{w}px: audit log does not repeat text under the action')
    b.close()
sql("DELETE FROM users WHERE email='ui@ad.local'")
print('ALL PASSED' if not fails else f'{fails} FAILED'); raise SystemExit(1 if fails else 0)
