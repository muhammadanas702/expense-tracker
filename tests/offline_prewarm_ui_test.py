"""
Part 57: with the service worker ON, the Add Expense page is saved while online, opens offline, the entry is queued and sent later;
only ONE offline bar is shown; the net balance number is never left at 0. LOCAL server + TEST database.
Usage: php -S 127.0.0.1:8080 -t . router.php (EXPENSEFLOW_2FA_OFF=1)   then   python3 tests/offline_prewarm_ui_test.py
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
sql("DELETE FROM users WHERE email='pw@pw.local'")
sql(f"INSERT INTO users(name,email,password) VALUES ('Pw','pw@pw.local','{h}')")
uid = sql("SELECT id FROM users WHERE email='pw@pw.local'")
sql(f"INSERT INTO income (user_id,title,amount,currency,amount_pkr,transaction_date) VALUES ({uid},'Pay',5000,'PKR',5000,NOW())")
sql(f"INSERT INTO expenses (user_id,title,amount,currency,amount_pkr,category,transaction_date) VALUES ({uid},'Food',4300,'PKR',4300,'Food',NOW())")
def n(): return int(sql(f"SELECT COUNT(*) FROM expenses WHERE user_id={uid}"))
with sync_playwright() as pw:
    br = pw.chromium.launch(); ctx = br.new_context(viewport={'width': 390, 'height': 800}); p = ctx.new_page()
    p.goto(B + '/auth/login.php'); p.fill('input[name=email]', 'pw@pw.local'); p.fill('input[name=password]', 'Test#12345'); p.click('button[type=submit]'); p.wait_for_timeout(1500)
    p.reload(); p.wait_for_function("navigator.serviceWorker.controller !== null", timeout=15000)
    p.evaluate("localStorage.removeItem('ef_prewarm')"); p.reload(); p.wait_for_timeout(5500)  # prewarm runs 3 s after load
    saved = p.evaluate("caches.open('expenseflow-pages-v1').then(c => c.keys()).then(k => k.map(r => r.url))")
    ok(any('add-expense.php' in u for u in saved) and any('add-income.php' in u for u in saved), f'add pages were saved while online {saved}')
    # net balance is never left at 0
    p.wait_for_timeout(2500)
    ok('700.00' in p.inner_text('.hero .v'), 'net balance shows 700.00 after the animation')
    # offline: the add page opens from the saved copy and takes the entry
    ctx.set_offline(True)
    p.goto(B + '/expense/add-expense.php'); p.wait_for_timeout(1500)
    ok(p.locator('form[data-offline-queue=expense]').count() == 1, 'the Add Expense form opens offline')
    ok(p.locator('#ef-net').count() == 1, 'one offline bar from the net status')
    ok('Changes cannot be saved until you are back online' not in p.inner_text('body'), 'the old, wrong offline bar is gone')
    p.fill('#amount', '120'); p.fill('input[name=title]', 'Offline roti'); p.click('form[data-offline-queue] button[type=submit]'); p.wait_for_timeout(600)
    ok(p.evaluate("window.efOfflineQueue.count()") == 1 and n() == 1, 'entry queued on the phone (server still has only the first expense)')
    ctx.set_offline(False); p.evaluate("window.dispatchEvent(new Event('online'))"); p.wait_for_timeout(5000)
    ok(n() == 2 and sql(f"SELECT COUNT(*) FROM expenses WHERE user_id={uid} AND title='Offline roti'") == '1', 'entry was added after reconnect')
    br.close()
sql(f"DELETE FROM expenses WHERE user_id={uid}"); sql(f"DELETE FROM income WHERE user_id={uid}"); sql("DELETE FROM users WHERE email='pw@pw.local'")
print('offline prewarm tests:', 'ALL PASSED' if not fails else f'{fails} FAILED')
