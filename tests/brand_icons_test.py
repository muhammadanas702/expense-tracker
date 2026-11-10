"""Branding icons (Part 100d): the uploaded logo becomes the favicon, home-screen icon, in-app logo and web-app manifest icons; the site name/colour go into the manifest.
Usage: PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8080 -t . router.php   then   python3 tests/brand_icons_test.py"""
import re, io, os, glob, json, subprocess, requests
from PIL import Image
B = 'http://127.0.0.1:8080'
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
fails = 0
def sql(q): return subprocess.run(['mysql', '--socket=/run/mysqld/mysqld.sock', '-uroot', 'expenseflow_test', '-N', '-r', '-e', q], capture_output=True, text=True).stdout.strip()
def ok(c, m):
    global fails
    print(('ok   ' if c else 'FAIL ') + m)
    if not c: fails += 1
def clean():
    for f in glob.glob(ROOT + '/storage/brand-img-*') + [ROOT + '/storage/branding.json', ROOT + '/storage/brand-logo.png', ROOT + '/storage/brand-texts.json']:
        if os.path.exists(f): os.remove(f)
def logo_png():
    im = Image.new('RGBA', (300, 300), (0, 0, 0, 0))
    for x in range(60, 240):
        for y in range(60, 240): im.putpixel((x, y), (200, 30, 120, 255))
    b = io.BytesIO(); im.save(b, 'PNG'); return b.getvalue()
clean()
h = subprocess.run(['php', '-r', 'echo password_hash("Test#12345",PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout
requests.get(B + '/auth/login.php')
sql("DELETE FROM users WHERE email LIKE '%@bic.local'"); sql("DELETE FROM rate_limit_hits")
sql(f"INSERT INTO users(name,email,password,role,is_admin) VALUES ('supname','sup@bic.local','{h}','super',1)")
sup = requests.Session()
t = re.search(r'name="csrf_token" value="([^"]+)"', sup.get(B + '/auth/login.php').text).group(1)
sup.post(B + '/auth/login.php', data={'email': 'sup@bic.local', 'password': 'Test#12345', 'csrf_token': t}, allow_redirects=False)
tok = lambda: re.search(r'name="csrf_token" value="([^"]+)"', sup.get(B + '/admin/branding.php').text).group(1)
home = lambda: requests.get(B + '/').text

t0 = home()
ok('assets/icons/favicon-32.png' in t0 and 'brand-icon.php' not in t0, 'built-in icons before any branding')
r = requests.get(B + '/brand-manifest.php'); m0 = r.json()
ok(m0['name'] == 'ExpenseFlow' and m0['icons'][0]['src'].startswith('assets/icons/'), 'manifest is the built-in one before branding')
ok(requests.get(B + '/brand-icon.php?i=192').status_code == 200 and requests.get(B + '/brand-icon.php?i=abc').status_code == 404 and requests.get(B + '/brand-icon.php?i=99999').status_code in (200, 404), 'icon address: built-in fallback, junk refused')
# upload logo + name + colour
sup.post(B + '/admin/branding-action.php', data={'do': 'save', 'name': 'Acme Cash', 'tagline': '', 'a1': '#aa2255', 'a2': '#2244aa', 'csrf_token': tok()}, files={'logo': ('l.png', logo_png(), 'image/png')})
t1 = home()
ok(re.search(r'rel="icon"[^>]*brand-icon\.php\?i=32\.\d+', t1) and re.search(r'rel="icon"[^>]*brand-icon\.php\?i=192\.\d+', t1), 'favicon links use the uploaded logo')
ok(re.search(r'rel="apple-touch-icon"[^>]*brand-icon\.php\?i=180\.\d+', t1), 'apple touch icon uses the uploaded logo')
ok(re.search(r'rel="shortcut icon"[^>]*brand-icon\.php\?i=96\.\d+', t1), 'favicon.ico address uses the uploaded logo')
ok('assets/icons/favicon' not in t1 and 'favicon.ico' not in t1, 'no built-in favicon address is left')
ok('brand-manifest.php?v=' in t1 and '/manifest.json' not in t1, 'page points to the branded manifest')
for code, size in [('32', 32), ('180', 180), ('192', 192), ('512', 512), ('96', 96)]:
    r = requests.get(B + '/brand-icon.php?i=' + code); im = Image.open(io.BytesIO(r.content))
    ok(r.headers['Content-Type'] == 'image/png' and im.size == (size, size), f'icon {code} is a {size}x{size} PNG')
r = requests.get(B + '/brand-icon.php?i=192.5'); im = Image.open(io.BytesIO(r.content)).convert('RGBA')
ok(im.getpixel((96, 96))[:3] == (200, 30, 120) and im.getpixel((2, 2))[3] == 0 and 'immutable' in r.headers['Cache-Control'], 'icon shows the logo on a transparent background, cached by version')
im = Image.open(io.BytesIO(requests.get(B + '/brand-icon.php?i=m512').content)).convert('RGBA')
ok(im.size == (512, 512) and im.getpixel((5, 5)) == (255, 255, 255, 255) and im.getpixel((256, 256))[:3] == (200, 30, 120) and im.getpixel((60, 256))[:3] == (255, 255, 255), 'maskable icon: logo with a white margin (safe zone)')
m = requests.get(B + '/brand-manifest.php').json()
ok(m['name'] == 'Acme Cash' and m['theme_color'] == '#aa2255', 'manifest has the new name and colour')
ok(len(m['icons']) == 4 and all(i['src'].startswith('brand-icon.php?i=') for i in m['icons']) and {i['purpose'] for i in m['icons']} == {'any', 'maskable'}, 'manifest icons come from the logo (any + maskable)')
ok(m['start_url'] == './' and m['display'] == 'standalone', 'rest of the manifest is unchanged')
# in-app pages and sign-in
ok('brand-icon.php?i=96.' in sup.get(B + '/admin/index.php').text, 'staff panel logo uses the uploaded logo')
ok('brand-icon.php?i=32.' in requests.get(B + '/auth/login.php').text, 'sign-in page favicon uses the uploaded logo')
# reset
sup.post(B + '/admin/branding-action.php', data={'do': 'reset', 'csrf_token': tok()})
t2 = home()
ok('brand-icon.php' not in t2 and 'assets/icons/favicon-32.png' in t2, 'reset brings the built-in icons back')
ok(requests.get(B + '/brand-manifest.php').json()['name'] == 'ExpenseFlow', 'manifest is built-in again')
clean(); sql("DELETE FROM users WHERE email LIKE '%@bic.local'")
print('brand_icons_test: ' + ('ALL PASSED' if not fails else f'{fails} FAILED')); raise SystemExit(1 if fails else 0)
