"""
Phone bottom bar in a real headless browser (iPhone-sized): 4 main items + More, nothing overflows, More sheet opens/closes,
desktop keeps its full sidebar, Dashboard has the search box. Usage: php -S 127.0.0.1:8080 -t . router.php   then   python3 tests/mobile_nav_ui_test.py
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
sql("DELETE FROM users WHERE email='mn1@mn.local'"); sql("DELETE FROM rate_limit_hits")
sql(f"INSERT INTO users(name,email,password) VALUES ('Mob One','mn1@mn.local','{h}')")
with sync_playwright() as pw:
    br = pw.chromium.launch()
    for scheme in ('light', 'dark'):
        ctx = br.new_context(viewport={'width': 390, 'height': 800}, color_scheme=scheme, service_workers='block', is_mobile=True, has_touch=True)
        p = ctx.new_page()
        p.goto(B + '/auth/login.php'); p.fill('input[name=email]', 'mn1@mn.local'); p.fill('input[name=password]', 'Test#12345'); p.click('button[type=submit]'); p.wait_for_timeout(1200)
        p.goto(B + '/dashboard.php')
        items = p.locator('nav.bn > a, nav.bn > button')
        ok(items.count() == 5, f'{scheme}: 4 main items + More (got {items.count()})')
        names = [t.strip() for t in p.locator('nav.bn > a, nav.bn > button').all_inner_texts()]
        ok(names == ['Dashboard', 'Income', 'Expense', 'Report', 'More'], f'{scheme}: bar labels {names}')
        bar = p.locator('nav.bn').bounding_box()
        ok(bar['x'] >= 0 and bar['x'] + bar['width'] <= 390.5, f'{scheme}: bar fits the screen')
        ok(all(i.bounding_box()['width'] >= 48 for i in [items.nth(k) for k in range(5)]), f'{scheme}: every item is wide enough to tap')
        ok(p.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'), f'{scheme}: no sideways scrolling')
        ok(p.locator('#more-sheet').is_hidden(), f'{scheme}: sheet closed at first')
        p.click('#more-btn')
        ok(p.locator('#more-sheet').is_visible() and p.get_attribute('#more-btn', 'aria-expanded') == 'true', f'{scheme}: More opens the sheet')
        sheet = [t.strip() for t in p.locator('#more-sheet a').all_inner_texts()]
        ok(sheet == ['Budgets', 'Goals', 'Reminders', 'Quick add'], f'{scheme}: sheet items {sheet}')
        sb = p.locator('#more-sheet').bounding_box(); nb = p.locator('nav.bn').bounding_box()
        ok(sb['y'] + sb['height'] <= nb['y'] + 1 and sb['x'] >= 0 and sb['x'] + sb['width'] <= 390.5, f'{scheme}: sheet sits above the bar, inside the screen')
        p.keyboard.press('Escape'); ok(p.locator('#more-sheet').is_hidden(), f'{scheme}: Escape closes it')
        p.click('#more-btn'); p.mouse.click(200, 150); ok(p.locator('#more-sheet').is_hidden(), f'{scheme}: tapping elsewhere closes it')
        p.click('#more-btn'); p.click('#more-sheet a:has-text("Budgets")'); p.wait_for_load_state()
        ok('/budgets.php' in p.url, f'{scheme}: sheet link works')
        ok('on' in (p.get_attribute('#more-btn', 'class') or ''), f'{scheme}: More is highlighted while on a page from the sheet')
        p.goto(B + '/dashboard.php')
        ok(p.locator('#dash-search input[name=q]').is_visible(), f'{scheme}: Dashboard search box visible')
        p.fill('#dash-search input[name=q]', 'coffee'); p.click('#dash-search button'); p.wait_for_load_state()
        ok('/search.php' in p.url and 'q=coffee' in p.url, f'{scheme}: search box opens the search page')
        ctx.close()
    ctx = br.new_context(viewport={'width': 1280, 'height': 800}, service_workers='block'); p = ctx.new_page()
    p.goto(B + '/auth/login.php'); p.fill('input[name=email]', 'mn1@mn.local'); p.fill('input[name=password]', 'Test#12345'); p.click('button[type=submit]'); p.wait_for_timeout(1200)
    p.goto(B + '/dashboard.php')
    ok(p.locator('aside.sb a.nv').count() >= 8 and p.locator('nav.bn').is_hidden(), 'desktop: full sidebar, no bottom bar')
    ok(not any('Search' == t.strip() for t in p.locator('aside.sb a.nv').all_inner_texts()), 'desktop: no Search entry in the sidebar')
    br.close()
sql("DELETE FROM users WHERE email='mn1@mn.local'")
print('mobile nav tests:', 'ALL PASSED' if not fails else f'{fails} FAILED')
raise SystemExit(1 if fails else 0)
