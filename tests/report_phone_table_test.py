"""Report > All transactions on a phone (Part 103c): no sideways scrolling, the amount is on screen, rows are cards; on a desktop it stays a 6-column table.
Usage: (EXPENSEFLOW_2FA_OFF=1 php -S 127.0.0.1:8080 -t . router.php)  python3 tests/report_phone_table_test.py"""
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
sql("DELETE FROM users WHERE email='rt@rt.local'"); sql("DELETE FROM rate_limit_hits")
sql(f"INSERT INTO users(name,email,password,role,is_admin) VALUES ('RT','rt@rt.local','{h}','user',0)")
uid = sql("SELECT id FROM users WHERE email='rt@rt.local'")
sql(f"INSERT INTO user_settings (user_id, savings_goal, notifications, setup_done) VALUES ({uid}, 20, 1, 1)")
sql(f"INSERT INTO income (user_id,title,amount,currency,amount_pkr,payment_method,transaction_date) VALUES ({uid},'Salary with a rather long title to wrap onto a second line',50000,'PKR',50000,'Bank',NOW())")
sql(f"INSERT INTO expenses (user_id,title,amount,currency,amount_pkr,category,payment_method,transaction_date) VALUES ({uid},'Lunch',450,'USD',126000,'Food','',NOW())")
with sync_playwright() as p:
    b = p.chromium.launch()
    for w, mob in [(360, True), (390, True), (1366, False)]:
        pg = b.new_context(viewport={'width': w, 'height': 800}, is_mobile=mob).new_page()
        pg.goto(B + '/auth/login.php'); pg.fill('input[name=email]', 'rt@rt.local'); pg.fill('input[name=password]', 'Test#12345'); pg.click('button[type=submit]'); pg.wait_for_load_state(); pg.wait_for_timeout(400)
        pg.goto(B + '/reports/view-report.php?from_date=2020-01-01&to_date=2030-01-01'); pg.wait_for_timeout(400)
        ok(pg.evaluate('document.documentElement.scrollWidth-innerWidth') <= 1, f'{w}px: page does not scroll sideways')
        wrap = pg.evaluate("(()=>{const w=document.querySelector('table.rtx').parentElement;return w.scrollWidth-w.clientWidth})()")
        amt = pg.evaluate("[...document.querySelectorAll('table.rtx td.ra')].map(e=>{const r=e.getBoundingClientRect();return [r.left>=0 && r.right<=innerWidth+1, r.width>0]})")
        if mob:
            ok(wrap <= 1, f'{w}px: the table itself does not scroll sideways')
            ok(len(amt) == 2 and all(a[0] and a[1] for a in amt), f'{w}px: every amount is fully on screen')
            ok(pg.evaluate("getComputedStyle(document.querySelector('table.rtx thead')).display") == 'none', f'{w}px: header row is replaced by the card layout')
            ok(pg.evaluate("document.querySelectorAll('table.rtx td.rm').length") == 2 and pg.evaluate("[...document.querySelectorAll('table.rtx td.rm')].some(e=>getComputedStyle(e).display==='none')"), f'{w}px: an empty payment method is not shown')
        else:
            ok(pg.evaluate("document.querySelectorAll('table.rtx thead th').length") == 6 and pg.evaluate("getComputedStyle(document.querySelector('table.rtx thead')).display") != 'none', f'{w}px: desktop keeps the 6-column table')
            ok(pg.evaluate("getComputedStyle(document.querySelector('table.rtx td.rt')).fontWeight") in ('400', 'normal'), f'{w}px: desktop title is not restyled')
    b.close()
sql("DELETE FROM users WHERE email='rt@rt.local'")
print('report_phone_table_test: ' + ('ALL PASSED' if not fails else f'{fails} FAILED')); raise SystemExit(1 if fails else 0)
