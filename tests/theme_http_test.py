"""
Light / dark theme in a real headless browser. Usage: php -S 127.0.0.1:8080 -t . router.php   then   python3 tests/theme_http_test.py (TEST database)
Checks: every page family loads theme-dark.css + the head script; cookie dark/light/auto (with the device scheme) decide the
theme before paint; the Preferences buttons switch it live and remember it; dark text/background stay readable.
"""
import re, subprocess, requests, sys
from playwright.sync_api import sync_playwright
B = 'http://127.0.0.1:8080'
fails = 0
def sql(q): return subprocess.run(['mysql', '--socket=/run/mysqld/mysqld.sock', '-uroot', 'expenseflow_test', '-N', '-e', q], capture_output=True, text=True).stdout.strip()
def ok(c, m):
    global fails
    if not c: fails += 1; print('FAIL', m)
h = subprocess.run(['php', '-r', 'echo password_hash("Test#12345",PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout
sql("DELETE FROM users WHERE email='theme1@theme.local'"); sql("DELETE FROM rate_limit_hits")
sql(f"INSERT INTO users(name,email,password) VALUES ('Theme One','theme1@theme.local','{h}')")
for p in ('/auth/login.php', '/about.php', '/'):
    t = requests.get(B + p).text
    ok('theme-dark.css' in t and 'ef_theme' in t, 'theme loaded on ' + p)
css = requests.get(B + '/assets/theme-dark.css')
ok(css.status_code == 200 and 'data-theme=dark' in css.text, 'theme-dark.css is served')

def lum(rgb):
    r, g, b = [int(x) for x in re.findall(r'\d+', rgb)[:3]]
    return (0.299 * r + 0.587 * g + 0.114 * b)
with sync_playwright() as pw:
    br = pw.chromium.launch()
    def theme(page): return page.evaluate("document.documentElement.getAttribute('data-theme')")
    def new(scheme, cookie=None):
        ctx = br.new_context(color_scheme=scheme, service_workers='block')
        if cookie: ctx.add_cookies([{'name': 'ef_theme', 'value': cookie, 'url': B}])
        return ctx
    for scheme, cookie, want in (('dark', None, 'dark'), ('light', None, None), ('light', 'dark', 'dark'), ('dark', 'light', None), ('dark', 'auto', 'dark')):
        ctx = new(scheme, cookie); p = ctx.new_page(); p.goto(B + '/auth/login.php')
        ok(theme(p) == want, 'device=%s cookie=%s -> theme %s (got %s)' % (scheme, cookie, want, theme(p)))
        ctx.close()
    ctx = new('light'); p = ctx.new_page()
    p.goto(B + '/auth/login.php'); p.fill('input[name=email]', 'theme1@theme.local'); p.fill('input[name=password]', 'Test#12345'); p.click('button[type=submit]'); p.wait_for_timeout(1500)
    p.goto(B + '/settings/preferences.php')
    ok(theme(p) is None and p.locator('#thm button[data-t=auto]').get_attribute('aria-pressed') == 'true', 'starts on Auto, light device -> light')
    p.click('#thm button[data-t=dark]'); p.wait_for_timeout(200)
    ok(theme(p) == 'dark', 'clicking Dark switches at once')
    bg = p.evaluate("getComputedStyle(document.body).backgroundColor"); fg = p.evaluate("getComputedStyle(document.body).color")
    ok(lum(bg) < 60 and lum(fg) > 170, 'dark: dark background %s, light text %s' % (bg, fg))
    p.goto(B + '/dashboard.php')
    ok(theme(p) == 'dark', 'dark is kept on the next page (no flash: set before paint)')
    p.goto(B + '/settings/preferences.php'); p.click('#thm button[data-t=light]'); p.wait_for_timeout(200)
    ok(theme(p) is None, 'Light switches back')
    bg = p.evaluate("getComputedStyle(document.body).backgroundColor")
    ok(lum(bg) > 180, 'light background stays light: ' + bg)
    p.reload(); ok(p.locator('#thm button[data-t=light]').get_attribute('aria-pressed') == 'true', 'the choice is remembered after reload')
    br.close()
sql("DELETE FROM users WHERE email='theme1@theme.local'")
print('theme_http_test: ALL PASSED' if not fails else f'{fails} FAILED')
sys.exit(1 if fails else 0)
