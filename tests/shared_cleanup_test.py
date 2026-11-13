"""Shared cleanup (Part 108c): Shared overview has no duplicate Net balance card; Friends search lives inside the friends list and still filters.
Usage: (EXPENSEFLOW_2FA_OFF=1 php -S 127.0.0.1:8080 -t . router.php)  python3 tests/shared_cleanup_test.py"""
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
sql("DELETE FROM users WHERE email='sc@sc.local'"); sql("DELETE FROM users WHERE email LIKE 'seed%@shot.local'"); sql("DELETE FROM rate_limit_hits")
sql(f"INSERT INTO users(name,email,password,role,is_admin) VALUES ('SC','sc@sc.local','{h}','user',0)")
uid = sql("SELECT id FROM users WHERE email='sc@sc.local'")
sql(f"INSERT INTO user_settings (user_id, savings_goal, notifications, setup_done) VALUES ({uid}, 20, 1, 1)")
subprocess.run(['php', 'tests/ui_seed_shared.php', uid], capture_output=True)
with sync_playwright() as p:
    b = p.chromium.launch()
    for w, mob in [(360, True), (1366, False)]:
        pg = b.new_context(viewport={'width': w, 'height': 800}, is_mobile=mob).new_page()
        errs = []; pg.on('pageerror', lambda e: errs.append(str(e)))
        pg.goto(B + '/auth/login.php'); pg.fill('input[name=email]', 'sc@sc.local'); pg.fill('input[name=password]', 'Test#12345'); pg.click('button[type=submit]'); pg.wait_for_load_state(); pg.wait_for_timeout(500)
        pg.goto(B + '/shared/index.php'); pg.wait_for_timeout(500); t = pg.inner_text('body')
        ok('Net balance' not in t, f'{w}px: no duplicate Net balance card')
        ok('You are owed' in t and 'You owe' in t and 'Your groups' in t, f'{w}px: owed / owe / groups still shown')
        ok(pg.evaluate('document.documentElement.scrollWidth-innerWidth') <= 1, f'{w}px: overview does not scroll sideways')
        pg.goto(B + '/friends/index.php'); pg.wait_for_timeout(500); t = pg.inner_text('body')
        ok('Find in your friends' not in t and 'Type to filter' not in t, f'{w}px: separate search card is gone')
        ok(pg.locator('#frq').count() == 1 and pg.evaluate("document.querySelector('#frq').closest('.card').innerText.includes('Your friends')"), f'{w}px: search is inside the friends list')
        pg.fill('#frq', 'zzzz'); pg.wait_for_timeout(200)
        ok(pg.evaluate("[...document.querySelectorAll('.frrow')].every(r=>r.style.display==='none')"), f'{w}px: typing filters the list')
        ok(pg.evaluate('document.documentElement.scrollWidth-innerWidth') <= 1, f'{w}px: friends does not scroll sideways')
        ok(not errs, f'{w}px: no script errors {errs}')
    b.close()
sql("DELETE FROM users WHERE email='sc@sc.local'"); sql("DELETE FROM users WHERE email LIKE 'seed%@shot.local'")
print('shared_cleanup_test: ' + ('ALL PASSED' if not fails else f'{fails} FAILED')); raise SystemExit(1 if fails else 0)
