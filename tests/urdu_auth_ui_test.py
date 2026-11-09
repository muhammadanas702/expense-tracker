"""
Part 60: Urdu on sign-up, forgot password and the error messages (real browser, phone width). LOCAL server + TEST database.
Usage: php -S 127.0.0.1:8080 -t . router.php (EXPENSEFLOW_2FA_OFF=1)   then   python3 tests/urdu_auth_ui_test.py
"""
import subprocess
from playwright.sync_api import sync_playwright
B = 'http://127.0.0.1:8080'
fails = 0
def sql(q): return subprocess.run(['mysql', '--socket=/run/mysqld/mysqld.sock', '-uroot', 'expenseflow_test', '-N', '-e', q], capture_output=True, text=True).stdout.strip()
def ok(c, m):
    global fails
    if not c: fails += 1; print('FAIL', m)
sql("DELETE FROM rate_limit_hits")
with sync_playwright() as pw:
    br = pw.chromium.launch(); ctx = br.new_context(viewport={'width': 390, 'height': 800}, is_mobile=True, has_touch=True, service_workers='block'); p = ctx.new_page()
    p.goto(B + '/auth/register.php?lang=ur'); p.wait_for_timeout(400)
    t = p.inner_text('body')
    ok(p.evaluate("document.documentElement.dir") == 'rtl', 'register is right-to-left')
    for w in ['اپنا اکاؤنٹ بنائیں', 'پورا نام', 'پاس ورڈ کی تصدیق', 'تصدیقی کوڈ بھیجیں', 'استعمال کی شرائط', 'پرائیویسی پالیسی']: ok(w in t, 'register: ' + w)
    ok(p.get_attribute('#f_name', 'placeholder') == 'آپ کا نام', 'placeholder is Urdu')
    ok(not p.evaluate("document.documentElement.scrollWidth > innerWidth + 1"), 'register: no sideways scrolling')
    ok(p.evaluate("getComputedStyle(document.querySelector('#f_email')).direction") == 'ltr', 'e-mail box stays left-to-right')
    p.screenshot(path='/tmp/claude-0/shots/ur-register.png', full_page=True)
    # a wrong sign-in shows an Urdu message
    p.goto(B + '/auth/login.php'); p.fill('input[name=email]', 'nobody@x.local'); p.fill('input[name=password]', 'Wrong#1234'); p.click('button[type=submit]'); p.wait_for_timeout(800)
    ok('ای میل یا پاس ورڈ غلط ہے۔' in p.inner_text('body'), 'wrong sign-in message is Urdu')
    p.goto(B + '/auth/forgot-password.php'); t = p.inner_text('body')
    ok('پاس ورڈ بھول گئے' in t and 'کوڈ بھیجیں' in t and 'لاگ ان پر واپس' in t, 'forgot password is Urdu')
    p.goto(B + '/auth/forgot-password.php?lang=en'); t = p.inner_text('body')
    ok('Forgot password?' in t and p.evaluate("document.documentElement.dir") == 'ltr', 'English unchanged')
    p.goto(B + '/auth/register.php'); ok('Create your account' in p.inner_text('body') and p.get_attribute('#f_name', 'placeholder') == 'Your name', 'English register unchanged')
    br.close()
print('urdu auth ui tests:', 'ALL PASSED' if not fails else f'{fails} FAILED')
