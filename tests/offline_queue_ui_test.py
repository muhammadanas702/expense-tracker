"""
Part 54: offline expense queue in a real browser + double-send protection. LOCAL server + TEST database.
Usage: php -S 127.0.0.1:8080 -t . router.php (EXPENSEFLOW_2FA_OFF=1)   then   python3 tests/offline_queue_ui_test.py
"""
import re, subprocess, time, requests
from playwright.sync_api import sync_playwright
B = 'http://127.0.0.1:8080'
fails = 0
def sql(q): return subprocess.run(['mysql', '--socket=/run/mysqld/mysqld.sock', '-uroot', 'expenseflow_test', '-N', '-e', q], capture_output=True, text=True).stdout.strip()
def ok(c, m):
    global fails
    if not c: fails += 1; print('FAIL', m)
h = subprocess.run(['php', '-r', 'echo password_hash("Test#12345",PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout
sql("DELETE FROM users WHERE email='oq@oq.local'")
sql(f"INSERT INTO users(name,email,password) VALUES ('Oq','oq@oq.local','{h}')")
uid = sql("SELECT id FROM users WHERE email='oq@oq.local'")
def n(): return int(sql(f"SELECT COUNT(*) FROM expenses WHERE user_id={uid}"))
# --- double send of the same client id = one entry
s = requests.Session()
t = re.search(r'name="csrf_token" value="([^"]+)"', s.get(B + '/auth/login.php').text).group(1)
s.post(B + '/auth/login.php', data={'email': 'oq@oq.local', 'password': 'Test#12345', 'csrf_token': t})
def add(cid, ts, title='Dup'):
    tk = re.search(r'name="csrf_token" value="([^"]+)"', s.get(B + '/expense/add-expense.php').text).group(1)
    return s.post(B + '/expense/add-expense.php', data={'csrf_token': tk, 'title': title, 'amount': '10', 'currency': 'PKR', 'category': 'Food', 'payment_method': 'Cash', 'cid': cid, 'ts': ts})
c1 = 'a1' * 12
add(c1, ''); add(c1, '')
ok(n() == 1, f'same cid twice -> one row (got {n()})')
add('b2' * 12, str(int(time.time()) - 3 * 86400), 'Old')
ok(sql(f"SELECT DATE(transaction_date)=DATE(NOW() - INTERVAL 3 DAY) FROM expenses WHERE user_id={uid} AND title='Old'") == '1', 'the phone time (3 days ago) is kept')
add('c3' * 12, str(int(time.time()) + 9 * 86400), 'Future')
ok(sql(f"SELECT DATE(transaction_date)=CURDATE() FROM expenses WHERE user_id={uid} AND title='Future'") == '1', 'a future time is ignored')
sql(f"DELETE FROM expenses WHERE user_id={uid}")
# --- browser: go offline, save, come back
with sync_playwright() as pw:
    br = pw.chromium.launch(); ctx = br.new_context(viewport={'width': 390, 'height': 800}, service_workers='block'); p = ctx.new_page()
    p.goto(B + '/auth/login.php'); p.fill('input[name=email]', 'oq@oq.local'); p.fill('input[name=password]', 'Test#12345'); p.click('button[type=submit]'); p.wait_for_timeout(1500)
    p.goto(B + '/expense/add-expense.php'); p.wait_for_timeout(500)
    ok(p.locator('form[data-offline-queue=expense]').count() == 1, 'the add form is queue-enabled')
    ctx.set_offline(True)
    p.fill('#amount', '250'); p.fill('input[name=title]', 'Offline chai'); p.click('form[data-offline-queue] button[type=submit]'); p.wait_for_timeout(500)
    ok(p.evaluate("window.efOfflineQueue.count()") == 1, 'one entry waits on the phone')
    ok('Saved on this phone' in p.inner_text('#ef-offq-toast'), 'the person is told it is saved')
    ok(n() == 0, 'nothing reached the server yet')
    ctx.set_offline(False); p.evaluate("window.dispatchEvent(new Event('online'))"); p.wait_for_timeout(4000)
    ok(n() == 1 and sql(f"SELECT title FROM expenses WHERE user_id={uid}") == 'Offline chai', f'entry added after reconnect (rows {n()})')
    ok(p.evaluate("window.efOfflineQueue.count()") == 0, 'the queue is empty again')
    # an invalid entry is dropped, not retried forever
    p.evaluate("localStorage.setItem('ef_offq', JSON.stringify([{path:'/expense/add-expense.php',fields:{title:'',amount:'-5',currency:'PKR',category:'Food'}}]))")
    p.evaluate("window.efOfflineQueue.flush()"); p.wait_for_timeout(3000)
    ok(p.evaluate("window.efOfflineQueue.count()") == 0 and n() == 1, 'an invalid entry is dropped and nothing is added')
    # logged-out phone keeps the entry
    p.evaluate("localStorage.setItem('ef_offq', JSON.stringify([{path:'/expense/add-expense.php',fields:{title:'Keep',amount:'5',currency:'PKR',category:'Food'}}]))")
    ctx.clear_cookies(); p.evaluate("window.efOfflineQueue.flush()"); p.wait_for_timeout(3000)
    ok(p.evaluate("window.efOfflineQueue.count()") == 1 and n() == 1, 'when signed out the entry is kept for later')
    br.close()
sql(f"DELETE FROM expenses WHERE user_id={uid}"); sql("DELETE FROM users WHERE email='oq@oq.local'")
print('offline queue ui tests:', 'ALL PASSED' if not fails else f'{fails} FAILED')
