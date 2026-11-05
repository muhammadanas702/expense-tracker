"""
Part 59: Urdu on Budgets, Goals and Reminders (real browser, phone width). LOCAL server + TEST database.
Usage: php -S 127.0.0.1:8080 -t . router.php (EXPENSEFLOW_2FA_OFF=1)   then   python3 tests/urdu_pages_ui_test.py
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
sql("DELETE FROM users WHERE email='up@up.local'"); sql(f"INSERT INTO users(name,email,password) VALUES ('Up','up@up.local','{h}')")
uid = sql("SELECT id FROM users WHERE email='up@up.local'")
def seed():
    sql(f"INSERT INTO budgets (user_id,category,amount,currency) VALUES ({uid},'Food',1000,'PKR')")
    sql(f"INSERT INTO savings_goals (user_id,name,target,currency,deadline) VALUES ({uid},'Laptop',10000,'PKR',DATE_ADD(CURDATE(), INTERVAL 60 DAY))")
    gid = sql(f"SELECT id FROM savings_goals WHERE user_id={uid}")
    sql(f"INSERT INTO goal_entries (goal_id,user_id,amount) VALUES ({gid},{uid},2500)")
    sql(f"INSERT INTO reminders (user_id,title,amount,currency,due_date,monthly) VALUES ({uid},'Rent',500,'PKR',DATE_ADD(CURDATE(), INTERVAL 1 DAY),1)")
with sync_playwright() as pw:
    br = pw.chromium.launch(); ctx = br.new_context(viewport={'width': 390, 'height': 800}, is_mobile=True, has_touch=True, service_workers='block'); p = ctx.new_page()
    p.goto(B + '/auth/login.php?lang=ur'); p.fill('input[name=email]', 'up@up.local'); p.fill('input[name=password]', 'Test#12345'); p.click('button[type=submit]'); p.wait_for_timeout(1500)
    for u in ('/budgets.php', '/goals.php', '/reminders.php'): p.goto(B + u)  # creates the tables
    seed()
    for url, words, shot in [('/budgets.php', ['بجٹ مقرر کریں', 'ماہانہ حد', 'بجٹ محفوظ کریں', 'کھانا', 'میں سے'], 'bud'),
                             ('/goals.php', ['نیا ہدف', 'مطلوبہ رقم', 'ہدف بنائیں', 'نکالیں', 'باقی'], 'goal'),
                             ('/reminders.php', ['نئی یاد دہانی', 'ادا ہو گیا', 'کل واجب الادا', 'ہر مہینے', 'یاد دہانی محفوظ کریں'], 'rem')]:
        p.goto(B + url); p.wait_for_timeout(500)
        t = p.inner_text('body')
        ok(p.evaluate("document.documentElement.dir") == 'rtl', url + ' is right-to-left')
        for w in words: ok(w in t, f'{url}: "{w}" in Urdu')
        ok(not p.evaluate("document.documentElement.scrollWidth > innerWidth + 1"), url + ': no sideways scrolling')
        ok(p.evaluate("getComputedStyle(document.querySelector('input[type=number]')).direction") == 'ltr', url + ': number boxes stay left-to-right')
        p.screenshot(path=f'/tmp/claude-0/shots/ur-{shot}.png', full_page=True)
    # English is untouched
    p.goto(B + '/budgets.php?lang=en'); t = p.inner_text('body')
    ok('Set a budget' in t and p.evaluate("document.documentElement.dir") == 'ltr', 'English budgets page unchanged')
    br.close()
for tb in ('budgets', 'savings_goals', 'goal_entries', 'reminders'): sql(f"DELETE FROM {tb} WHERE user_id={uid}")
sql("DELETE FROM users WHERE email='up@up.local'")
print('urdu pages ui tests:', 'ALL PASSED' if not fails else f'{fails} FAILED')
