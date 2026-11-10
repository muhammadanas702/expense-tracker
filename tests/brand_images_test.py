"""Branding pictures (Part 100c): landing screenshots and the share-preview image replaced from Admin > Branding.
Usage: PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8080 -t . router.php   then   python3 tests/brand_images_test.py"""
import re, io, os, glob, subprocess, requests
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
def png(w, h, col=(220, 40, 40), fmt='PNG'):
    b = io.BytesIO(); Image.new('RGB', (w, h), col).save(b, fmt); return b.getvalue()
clean()
h = subprocess.run(['php', '-r', 'echo password_hash("Test#12345",PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout
requests.get(B + '/auth/login.php')
sql("DELETE FROM users WHERE email LIKE '%@bi.local'"); sql("DELETE FROM rate_limit_hits")
for n, r in {'sup': 'super', 'u1': 'user'}.items():
    sql(f"INSERT INTO users(name,email,password,role,is_admin) VALUES ('{n}name','{n}@bi.local','{h}','{r}',{0 if r=='user' else 1})")
def login(n):
    s = requests.Session()
    t = re.search(r'name="csrf_token" value="([^"]+)"', s.get(B + '/auth/login.php').text).group(1)
    s.post(B + '/auth/login.php', data={'email': f'{n}@bi.local', 'password': 'Test#12345', 'csrf_token': t}, allow_redirects=False)
    return s
sup, u1 = login('sup'), login('u1')
A = B + '/admin/branding-action.php'
def tok(): return re.search(r'name="csrf_token" value="([^"]+)"', sup.get(B + '/admin/branding.php').text).group(1)
def up(slot, data, name='p.png', ctype='image/png', do='image', csrf=None):
    return sup.post(A, data={'do': do, 'slot': slot, 'csrf_token': csrf or tok()}, files={'img': (name, data, ctype)} if data is not None else None, allow_redirects=True)
def flash(r): return r.text
home = lambda: requests.get(B + '/').text

r = sup.get(B + '/admin/branding.php')
ok('id="pictures"' in r.text and 'Landing screenshot 1' in r.text and 'Share-preview image' in r.text, 'Branding page lists the three pictures')
ok('assets/landing/app-dashboard.webp' in home() and 'brand-image.php' not in home(), 'before any upload the built-in pictures are used')
# rules
ok(u1.post(A, data={'do': 'image', 'slot': 'dash'}, allow_redirects=False).status_code == 403, 'plain user cannot upload')
r = sup.post(A, data={'do': 'image', 'slot': 'dash'}, files={'img': ('p.png', png(500, 900), 'image/png')}, allow_redirects=False)
ok(r.status_code in (400, 403) or not glob.glob(ROOT + '/storage/brand-img-dash.*'), 'upload without the CSRF token is refused')
r = up('dash', b'not a picture', 'x.png'); ok('PNG, JPG or WebP' in flash(r) and not glob.glob(ROOT + '/storage/brand-img-dash.*'), 'a non-picture is refused')
r = up('dash', png(100, 100)); ok('too small' in flash(r), 'a tiny picture is refused')
r = up('bogus', png(500, 900)); ok('Unknown picture' in flash(r), 'unknown slot is refused')
r = up('dash', png(500, 900) + b'\0' * 4100000); ok('too big' in flash(r) or 'could not be read' in flash(r) or 'PNG' in flash(r), 'a picture over 4 MB is refused')
# dash
r = up('dash', png(800, 1000)); ok('Picture saved' in flash(r), 'dashboard screenshot uploads')
t = home()
ok(re.search(r'brand-image\.php\?dash=\d+', t) and 'app-dashboard.webp' not in t, 'home page now shows the uploaded dashboard picture')
ok('app-group.webp' in t, 'the other screenshot is untouched')
m = re.search(r'brand-image\.php\?dash=\d+', t).group(0)
i = requests.get(B + '/' + m); im = Image.open(io.BytesIO(i.content))
ok(i.headers['Content-Type'] == 'image/webp' and im.size == (720, 1440), 'served as WebP cut to 720 x 1440 (%s %s)' % (i.headers['Content-Type'], im.size))
ok('immutable' in i.headers.get('Cache-Control', ''), 'uploaded picture is cached by version')
ok('brand-image.php?dash=' in requests.get(B + '/auth/login.php').text, 'sign-in side panel uses it too')
# group + share
up('group', png(700, 1400, (30, 160, 60))); ok('brand-image.php?group=' in requests.get(B + '/auth/register.php').text, 'create-account side panel shows the group picture')
up('share', png(1600, 900, (40, 60, 200)), 's.jpg', 'image/jpeg')
t = home()
ok(re.search(r'og:image" content="[^"]*brand-image\.php\?share=\d+"', t) and 'og-image.png' not in t, 'home page share image (og:image) is replaced')
ok(re.search(r'twitter:image" content="[^"]*brand-image\.php\?share=\d+"', t), 'twitter:image is replaced')
t2 = requests.get(B + '/guides/').text
ok('brand-image.php?share=' in t2, 'guide pages use the share image too')
m = re.search(r'brand-image\.php\?share=\d+', t).group(0); i = requests.get(B + '/' + m); im = Image.open(io.BytesIO(i.content))
ok(i.headers['Content-Type'] == 'image/jpeg' and im.size == (1200, 630), 'share image served as JPEG 1200 x 630')
ok(im.getpixel((600, 300))[2] > 150, 'the uploaded picture is what is served')
ok(requests.get(B + '/brand-image.php').status_code == 404 and requests.get(B + '/brand-image.php?evil=../../x').status_code == 404, 'no slot or an unknown slot gives 404')
# admin page shows own pictures
r = sup.get(B + '/admin/branding.php'); ok(r.text.count('Your picture') == 3 and 'Use built-in' in r.text, 'Branding shows the three uploaded pictures')
# replace again leaves one file
up('dash', png(600, 1200, (0, 0, 0))); ok(len(glob.glob(ROOT + '/storage/brand-img-dash.*')) == 1, 'a second upload replaces the first (one file)')
# remove one
r = up('dash', None, do='image_remove'); ok('Built-in picture restored' in flash(r), 'one picture can go back to the built-in one')
ok('app-dashboard.webp' in home() and 'brand-image.php?group=' in home(), 'only that picture reverted')
# reset
t = tok(); sup.post(A, data={'do': 'reset', 'csrf_token': t})
ok(not glob.glob(ROOT + '/storage/brand-img-*') and 'og-image.png' in home() and 'app-group.webp' in home(), 'Reset to built-in removes all uploaded pictures')
ok(sql("SELECT COUNT(*) FROM admin_audit_log WHERE action='brand_change' AND details LIKE '%replaced%'") != '0', 'uploads are written to the audit log')
clean(); sql("DELETE FROM users WHERE email LIKE '%@bi.local'")
print('brand_images_test: ' + ('ALL PASSED' if not fails else f'{fails} FAILED')); raise SystemExit(1 if fails else 0)
