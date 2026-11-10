"""Edit any text from Admin > Branding (Part 100b): storage, filter (English + Urdu), staff-only, escaping, and a real click in edit mode.
Usage: php -S 127.0.0.1:8080 -t . router.php   then   python3 tests/brand_texts_test.py"""
import re, os, subprocess, requests
from playwright.sync_api import sync_playwright
B = 'http://127.0.0.1:8080'
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
FILE = os.path.join(ROOT, 'storage/brand-texts.json')
fails = 0
def sql(q, db='expenseflow_test'): return subprocess.run(['mysql', '--socket=/run/mysqld/mysqld.sock', '-uroot', db, '-N', '-r', '-e', q], capture_output=True, text=True).stdout.strip()
def ok(c, m):
    global fails
    print(('ok   ' if c else 'FAIL ') + m)
    if not c: fails += 1
def clean():
    try: os.remove(FILE)
    except FileNotFoundError: pass
clean()
h = subprocess.run(['php', '-r', 'echo password_hash("Test#12345",PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout
requests.get(B + '/auth/login.php')
sql("DELETE FROM users WHERE email LIKE '%@bt.local'"); sql("DELETE FROM rate_limit_hits")
for n, r in {'sup': 'super', 'u1': 'user'}.items():
    sql(f"INSERT INTO users(name,email,password,role,is_admin) VALUES ('{n}name','{n}@bt.local','{h}','{r}',{0 if r=='user' else 1})")
def login(n):
    s = requests.Session()
    t = re.search(r'name="csrf_token" value="([^"]+)"', s.get(B + '/auth/login.php').text).group(1)
    s.post(B + '/auth/login.php', data={'email': f'{n}@bt.local', 'password': 'Test#12345', 'csrf_token': t}, allow_redirects=False)
    return s
sup, u1 = login('sup'), login('u1')
A = B + '/admin/brand-text-action.php'
def tok(s, p='/admin/branding.php'): return re.search(r'name="csrf_token" value="([^"]+)"', s.get(B + p).text).group(1)
def act(s, **d):
    d.setdefault('csrf_token', tok(s)); d['json'] = '1'
    return s.post(A, data=d, allow_redirects=False)
def anon(p): return requests.get(B + p).text
def visible(html): return re.sub(r'<script.*?</script>|<style.*?</style>|<[^>]+>', '\n', html, flags=re.S)

ok(u1.post(A, data={'do': 'set', 'cur': 'Log in', 'new': 'X', 'csrf_token': 'x'}, allow_redirects=False).status_code in (302, 303, 403), 'plain user cannot edit texts')
ok(sup.post(A, data={'do': 'set', 'cur': 'Log in', 'new': 'X'}, allow_redirects=False).status_code == 403, 'no CSRF token: refused')
ok('See what' in anon('/') and 'brand-edit.js' not in anon('/'), 'default: pages untouched, no editor')

# English edit
r = act(sup, do='set', lang='en', cur="See what's inside", new='Explore the app')
ok(r.status_code == 200 and r.json().get('ok'), 'text saved')
land = anon('/')
ok('Explore the app' in visible(land) and "See what's inside" not in visible(land), 'visitors see the new English text')
# Urdu edit of a text that has a translation
ur_old = subprocess.run(['php', '-r', '$d=require "includes/lang/ur.php"; echo $d["Log in"];'], capture_output=True, text=True, cwd=ROOT).stdout
r = act(sup, do='set', lang='ur', cur=ur_old, new='داخل ہوں')
ok(r.status_code == 200 and r.json().get('key') == 'Log in', 'Urdu edit finds the English original')
ur = anon('/auth/login.php?lang=ur')
ok('داخل ہوں' in ur, 'Urdu page shows the new Urdu text')
ok(re.search(r'>\s*Log in\s*<', anon('/auth/login.php')) is not None, 'English page keeps its own text')
# escaping
act(sup, do='set', lang='en', cur='Features', new='<script>alert(1)</script>')
nav = anon('/')
ok('<script>alert(1)</script>' not in nav and '&lt;script&gt;alert(1)&lt;/script&gt;' in nav, 'typed HTML is shown as text, never run')
# limits
ok(act(sup, do='set', lang='en', cur='Features', new='x' * 301).status_code == 422, 'over-long text refused')
ok(act(sup, do='set', lang='en', cur='', new='x').status_code == 422, 'empty selection refused')
# restore
act(sup, do='set', lang='en', cur='<script>alert(1)</script>', new='')
ok('Features' in visible(anon('/')), 'empty text restores the original')
act(sup, do='set', lang='en', cur='Explore the app', new="See what's inside")
ok("See what's inside" in visible(anon('/')) and 'Explore the app' not in anon('/'), 'typing the original words clears the edit')
# list + restore from the page
page = sup.get(B + '/admin/branding.php').text
ok('edited text' in page and 'داخل ہوں' in page, 'Branding page lists edited texts')
sup.post(A, data={'csrf_token': tok(sup), 'do': 'clear', 'key': 'Log in'}, allow_redirects=False)
ok('داخل ہوں' not in anon('/auth/login.php?lang=ur') and not os.path.exists(FILE), 'Restore removes the edit (file is gone when nothing is left)')
# edit mode on/off
sup.post(A, data={'csrf_token': tok(sup), 'do': 'edit_on', 'json': '1'})
ok('brand-edit.js' in sup.get(B + '/').text, 'staff in edit mode get the editor on the website')
ok('brand-edit.js' not in sup.get(B + '/admin/index.php').text, 'the staff panel never gets the editor')
ok('brand-edit.js' not in anon('/'), 'visitors never get the editor')
ok('brand-edit.js' not in u1.get(B + '/').text, 'other signed-in users never get the editor')
ok('brand-edit.js' not in requests.get(B + '/', cookies={'ef_be': '1.9999999999.' + '0' * 64}).text, 'a forged edit-mode cookie does nothing')
ok(requests.get(A + '?do=token', allow_redirects=False).status_code in (302, 303, 403), 'the token address is closed to visitors')
sup.post(A, data={'csrf_token': tok(sup), 'do': 'edit_off', 'json': '1'})
ok('brand-edit.js' not in sup.get(B + '/').text, 'edit mode can be turned off')

# a real click
with sync_playwright() as p:
    b = p.chromium.launch(); c = b.new_context(viewport={'width': 1280, 'height': 800}); pg = c.new_page()
    pg.goto(B + '/auth/login.php'); pg.fill('input[name=email]', 'sup@bt.local'); pg.fill('input[name=password]', 'Test#12345'); pg.click('button[type=submit]'); pg.wait_for_load_state()
    pg.goto(B + '/admin/branding.php'); pg.click('button[value=edit_on]'); pg.wait_for_load_state(); pg.wait_for_selector('.bt-bar')
    ok(pg.url.rstrip('/').endswith('index.php?lang=en') or '/index.php' in pg.url, 'Start editing opens the website')
    box = pg.locator("text=See what's inside").first.bounding_box()
    pg.mouse.move(box['x'] + 10, box['y'] + box['height'] / 2); pg.wait_for_timeout(200)
    ok(pg.evaluate("getComputedStyle(document.querySelector('.bt-hl')).display") == 'block', 'hovering a text outlines it')
    before = pg.url
    pg.mouse.click(box['x'] + 10, box['y'] + box['height'] / 2); pg.wait_for_selector('.bt-pop textarea')
    ok(pg.url == before, 'clicking a link in edit mode does not leave the page')
    ok("See what's inside" in pg.input_value('.bt-pop textarea'), 'the box starts with the current text')
    pg.fill('.bt-pop textarea', 'Take a look'); pg.keyboard.press('Enter'); pg.wait_for_selector('.bt-pop', state='detached')
    ok(pg.locator('text=Take a look').count() >= 1, 'the page shows the change at once')
    pg.reload(); pg.wait_for_selector('.bt-bar')
    ok(pg.locator('text=Take a look').count() >= 1, 'it is still there after a reload')
    pg.click('.bt-bar .go'); pg.wait_for_url('**/admin/branding.php'); 
    ok(pg.url.endswith('/admin/branding.php') and pg.locator('text=Start editing texts').count() == 1, 'Done returns to Branding and edit mode is off')
    b.close()
ok('Take a look' in visible(anon('/')), 'visitors see the text edited in the browser')
clean()
sql("DELETE FROM users WHERE email LIKE '%@bt.local'")
print('brand_texts_test:', 'ALL PASSED' if not fails else f'{fails} FAILED')
