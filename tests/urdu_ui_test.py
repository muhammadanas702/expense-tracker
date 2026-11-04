"""
Part 51: Urdu / right-to-left in a real headless browser.
Usage: php -S 127.0.0.1:8080 -t . router.php   (EXPENSEFLOW_2FA_OFF=1)   then   python3 tests/urdu_ui_test.py   (TEST database)
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
sql("DELETE FROM users WHERE email='ur1@ur.local'")
sql(f"INSERT INTO users(name,email,password) VALUES ('Ur One','ur1@ur.local','{h}')")
sql("INSERT INTO user_settings (user_id, savings_goal, notifications, setup_done) SELECT id, 20, 1, 1 FROM users WHERE email='ur1@ur.local'")
dirof = "document.documentElement.getAttribute('dir')"
with sync_playwright() as pw:
    br = pw.chromium.launch()
    ctx = br.new_context(viewport={'width': 1200, 'height': 800}, service_workers='block'); p = ctx.new_page()
    p.goto(B + '/auth/login.php')
    ok(p.evaluate(dirof) == 'ltr' and 'Welcome back' in p.inner_text('.fc h2'), 'English login by default')
    ok(p.locator('a.lang-sw').inner_text() == 'اردو', 'a link offers Urdu')
    p.click('a.lang-sw'); p.wait_for_load_state()
    ok(p.evaluate(dirof) == 'rtl' and p.evaluate("document.documentElement.lang") == 'ur', 'Urdu login is right-to-left')
    ok('خوش آمدید' in p.inner_text('.fc h2') and 'لاگ ان' in p.inner_text('button[type=submit]'), 'login texts are Urdu')
    ok(p.locator('a.lang-sw').inner_text() == 'English', 'the link now offers English')
    ok(any(c['name'] == 'ef_lang' and c['value'] == 'ur' for c in ctx.cookies()), 'the choice is kept in a cookie')
    p.screenshot(path='/tmp/claude-0/shots/ur-login.png')
    p.goto(B + '/auth/login.php?lang=xx'); ok(p.evaluate(dirof) == 'rtl', 'an unknown language is ignored (stays Urdu)')
    # sign in (still Urdu)
    p.fill('input[name=email]', 'ur1@ur.local'); p.fill('input[name=password]', 'Test#12345'); p.click('button[type=submit]'); p.wait_for_timeout(1500)
    ok('/dashboard.php' in p.url and p.evaluate(dirof) == 'rtl', 'signed in, dashboard is right-to-left')
    nav = p.inner_text('aside.sb')
    ok('ڈیش بورڈ' in nav and 'لاگ آؤٹ' in nav and 'پروفائل' in nav, 'side menu in Urdu')
    ok('کل آمدنی' in p.inner_text('body') and 'کل خرچ' in p.inner_text('body'), 'dashboard cards in Urdu')
    box = p.evaluate("(() => { const r = document.querySelector('aside.sb').getBoundingClientRect(); return [r.left, r.right, innerWidth]; })()")
    ok(box[1] > box[2] / 2 + 100 and box[0] > box[2] / 2, f'desktop: the menu sits on the RIGHT in Urdu {box}')
    p.screenshot(path='/tmp/claude-0/shots/ur-dash.png')
    p.goto(B + '/expense/add-expense.php'); p.wait_for_timeout(300)
    t = p.inner_text('form')
    ok('رقم' in t and 'کھانا' in t and 'نقد' in t and 'خرچ محفوظ کریں' in t, 'add-expense form, categories and payment methods in Urdu')
    ok(p.evaluate("getComputedStyle(document.querySelector('#amount')).direction") == 'ltr', 'the amount box stays left-to-right')
    # settings: language card
    p.goto(B + '/settings/preferences.php')
    ok(p.locator('#lang-card').count() == 1 and 'زبان' in p.inner_text('#lang-card'), 'settings > preferences has the language card')
    p.click('#lang-card a[hreflang=en]'); p.wait_for_load_state()
    ok(p.evaluate(dirof) == 'ltr' and 'Language' in p.inner_text('#lang-card'), 'back to English from settings')
    ctx.close()
    # phone, Urdu
    ctx = br.new_context(viewport={'width': 390, 'height': 800}, is_mobile=True, has_touch=True, service_workers='block'); p = ctx.new_page()
    p.goto(B + '/auth/login.php?lang=ur'); p.fill('input[name=email]', 'ur1@ur.local'); p.fill('input[name=password]', 'Test#12345'); p.click('button[type=submit]'); p.wait_for_timeout(1500)
    ok(not p.evaluate("document.documentElement.scrollWidth > innerWidth + 1"), 'phone, Urdu: no sideways scrolling')
    ok('مزید' in p.inner_text('nav.bn') or 'مزید' in p.inner_text('body'), 'phone bottom bar in Urdu')
    p.screenshot(path='/tmp/claude-0/shots/ur-phone.png')
    ctx.close(); br.close()
sql("DELETE FROM users WHERE email='ur1@ur.local'")
print('urdu ui tests:', 'ALL PASSED' if not fails else f'{fails} FAILED')
