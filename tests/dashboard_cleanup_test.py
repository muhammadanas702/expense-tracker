"""Dashboard cleanup (Part 108b): no duplicate savings/income-vs-expense info, no empty "Repeating" card, no Reset/Delete card; page still works on phone and desktop.
Usage: (EXPENSEFLOW_2FA_OFF=1 php -S 127.0.0.1:8080 -t . router.php)  python3 tests/dashboard_cleanup_test.py"""
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
sql("DELETE FROM users WHERE email='dc@dc.local'"); sql("DELETE FROM rate_limit_hits")
sql(f"INSERT INTO users(name,email,password,role,is_admin) VALUES ('DC','dc@dc.local','{h}','user',0)")
uid = sql("SELECT id FROM users WHERE email='dc@dc.local'")
sql(f"INSERT INTO user_settings (user_id, savings_goal, notifications, setup_done) VALUES ({uid}, 20, 1, 1)")
sql(f"INSERT INTO income (user_id,title,amount,currency,amount_pkr,payment_method,transaction_date) VALUES ({uid},'Salary',50000,'PKR',50000,'Bank',NOW())")
sql(f"INSERT INTO expenses (user_id,title,amount,currency,amount_pkr,category,payment_method,transaction_date) VALUES ({uid},'Lunch',450,'PKR',450,'Food','',NOW())")
with sync_playwright() as p:
    b = p.chromium.launch()
    for w, mob in [(360, True), (1366, False)]:
        pg = b.new_context(viewport={'width': w, 'height': 800}, is_mobile=mob).new_page()
        errs = []; pg.on('pageerror', lambda e: errs.append(str(e)))
        pg.goto(B + '/auth/login.php'); pg.fill('input[name=email]', 'dc@dc.local'); pg.fill('input[name=password]', 'Test#12345'); pg.click('button[type=submit]'); pg.wait_for_load_state(); pg.wait_for_timeout(500)
        pg.goto(B + '/dashboard.php'); pg.wait_for_timeout(900)
        t = pg.inner_text('body')
        ok(t.count('% saved') == 0 and t.count('Savings rate') == 1, f'{w}px: savings rate shown once')
        ok(pg.locator('#flowChart').count() == 0 and 'Income vs expense' not in t, f'{w}px: no second income-vs-expense summary')
        ok('Repeating every month' not in t, f'{w}px: empty "Repeating" card is hidden')
        ok('Reset data or delete account' not in t, f'{w}px: Reset/Delete card removed (it is in Profile)')
        ok('Where your money goes' in t and 'Smart insights' in t and 'Base currency' in t, f'{w}px: real cards still there')
        ok('Biggest expense' in t and 'Biggest income' in t and 'Entries in this period' in t, f'{w}px: insight tiles fill the card')
        ok(pg.evaluate("[...document.querySelectorAll('.ig .ins')].map(e=>e.innerText.replace(/\\n/g,'|'))").count('2|Entries in this period') == 1, f'{w}px: entry count is correct (2)')
        ok(pg.evaluate('document.documentElement.scrollWidth-innerWidth') <= 1, f'{w}px: no sideways scroll')
        ok(not errs, f'{w}px: no script errors {errs}')
    b.close()
sql("DELETE FROM users WHERE email='dc@dc.local'")
print('dashboard_cleanup_test: ' + ('ALL PASSED' if not fails else f'{fails} FAILED')); raise SystemExit(1 if fails else 0)
