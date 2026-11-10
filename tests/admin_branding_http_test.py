"""Branding admin page (Part 100a): access, name/tagline/colours/logo, validation, public pages, reset.
Usage: php -S 127.0.0.1:8080 -t . router.php   then   python3 tests/admin_branding_http_test.py"""
import re, os, io, subprocess, requests
from PIL import Image
B = 'http://127.0.0.1:8080'
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
fails = 0
def sql(q, db='expenseflow_test'): return subprocess.run(['mysql', '--socket=/run/mysqld/mysqld.sock', '-uroot', db, '-N', '-r', '-e', q], capture_output=True, text=True).stdout.strip()
def ok(c, m):
    global fails
    print(('ok   ' if c else 'FAIL ') + m)
    if not c: fails += 1
def clean():
    for f in ('storage/branding.json', 'storage/brand-logo.png'):
        try: os.remove(os.path.join(ROOT, f))
        except FileNotFoundError: pass
clean()
h = subprocess.run(['php', '-r', 'echo password_hash("Test#12345",PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout
requests.get(B + '/auth/login.php')
sql("DELETE FROM users WHERE email LIKE '%@br.local'"); sql("DELETE FROM rate_limit_hits")
for n, r in {'sup': 'super', 'u1': 'user'}.items():
    sql(f"INSERT INTO users(name,email,password,role,is_admin) VALUES ('{n}name','{n}@br.local','{h}','{r}',{0 if r=='user' else 1})")
def login(n):
    s = requests.Session()
    t = re.search(r'name="csrf_token" value="([^"]+)"', s.get(B + '/auth/login.php').text).group(1)
    s.post(B + '/auth/login.php', data={'email': f'{n}@br.local', 'password': 'Test#12345', 'csrf_token': t}, allow_redirects=False)
    return s
sup, u1 = login('sup'), login('u1')
P = '/admin/branding.php'
ok(u1.get(B + P, allow_redirects=False).status_code == 403, 'plain user blocked')
ok(requests.get(B + P, allow_redirects=False).status_code in (302, 303), 'guest redirected')
r = sup.get(B + P); ok(r.status_code == 200 and 'Site name' in r.text and 'Main colour' in r.text and 'Logo' in r.text, 'super admin opens the page')
ok('admin/branding.php' in sup.get(B + '/admin/index.php').text, 'menu links to Branding')
def tok(): return re.search(r'name="csrf_token" value="([^"]+)"', sup.get(B + P).text).group(1)
def save(files=None, **kw):
    d = {'csrf_token': tok(), 'do': 'save', 'name': '', 'tagline': '', 'a1': '', 'a2': ''}
    d.update(kw)
    sup.post(B + '/admin/branding-action.php', data=d, files=files, allow_redirects=False)
    return sup.get(B + P).text
ok(requests.post(B + '/admin/branding-action.php', data={'name': 'X'}, allow_redirects=False).status_code in (302, 303, 403), 'guest cannot save')
r = sup.post(B + '/admin/branding-action.php', data={'name': 'X', 'do': 'save'}, allow_redirects=False)
ok(r.status_code == 403, 'save without CSRF token is refused (got %s)' % r.status_code)
# default: public pages untouched
home = requests.get(B + '/').text
ok('ExpenseFlow' in home and 'brand-colours' not in home, 'default look: pages untouched')
# validation
t = save(name='<b>x</b>'); ok('site name needs' in t, 'bad name refused')
t = save(name='Spendly', a1='#ffff00'); ok('too light' in t, 'light colour refused')
t = save(name='Spendly', a1='red'); ok('must look like' in t, 'colour that is not #rrggbb refused')
ok(not os.path.exists(os.path.join(ROOT, 'storage/branding.json')), 'nothing saved after refused forms')
# save name, tagline, colours
t = save(name='Spendly', tagline='Spend smart, split fair.', a1='#b91c1c', a2='#7c2d12'); ok('Branding saved' in t, 'branding saved')
home = requests.get(B + '/').text
ok('Spendly' in home and 'ExpenseFlow' not in re.sub(r'<script.*?</script>|<style.*?</style>|<[^>]+>', ' ', home, flags=re.S), 'landing shows the new name (and the old one is gone from the text)')
ok('Spend smart, split fair.' in home, 'footer shows the new tagline')
ok('id="brand-colours"' in home and '--a1:#b91c1c' in home and '--a2:#7c2d12' in home, 'landing carries the new colours')
login_html = requests.get(B + '/auth/login.php').text
ok('Spendly' in login_html and '--a1:#b91c1c' in login_html, 'sign-in page is branded too')
ok('<title>' in login_html and 'ExpenseFlow' not in re.search(r'<title>.*?</title>', login_html, re.S).group(0), 'page title uses the new name')
ok('ExpenseFlow.apk' in home, 'download links keep their file names (only visible text is renamed)')
mail = subprocess.run(['php', '-r', "require 'modules/branding/lib.php'; echo brand_text('Welcome to ExpenseFlow');"], capture_output=True, text=True, cwd=ROOT).stdout
ok(mail == 'Welcome to Spendly', 'e-mail text uses the new name')
ok(sql("SELECT COUNT(*) FROM user_logs WHERE action='admin_brand_change'") != '0', 'change is written to the audit log')
# logo
buf = io.BytesIO(); Image.new('RGB', (500, 300), (10, 120, 200)).save(buf, 'PNG')
t = save(files={'logo': ('mylogo.png', buf.getvalue(), 'image/png')}, name='Spendly', a1='#b91c1c', a2='#7c2d12'); ok('Branding saved' in t, 'logo uploaded')
lr = requests.get(B + '/brand-logo.php')
im = Image.open(io.BytesIO(lr.content))
ok(lr.headers.get('Content-Type') == 'image/png' and im.size == (256, 256), 'logo is served as a 256x256 PNG')
home = requests.get(B + '/').text
ok('brand-logo.php?v=' in home and 'logo-96.webp' not in home, 'pages use the uploaded logo')
t = save(files={'logo': ('fake.png', b'this is not a picture', 'image/png')}, name='Spendly'); ok('PNG, JPG or WebP' in t, 'a file that is not a picture is refused')
tiny = io.BytesIO(); Image.new('RGB', (20, 20)).save(tiny, 'PNG')
t = save(files={'logo': ('tiny.png', tiny.getvalue(), 'image/png')}, name='Spendly'); ok('64 and 4096' in t, 'a tiny picture is refused')
ok(os.path.exists(os.path.join(ROOT, 'storage/brand-logo.png')), 'refused uploads keep the old logo')
t = save(name='Spendly', a1='#b91c1c', a2='#7c2d12', remove_logo='1')
ok(not os.path.exists(os.path.join(ROOT, 'storage/brand-logo.png')) and 'logo-96.webp' in requests.get(B + '/').text, 'logo can be removed')
# Urdu pages still work with a custom name
ur = requests.get(B + '/auth/login.php?lang=ur').text
ok('Spendly' in ur, 'Urdu page shows the new name')
# reset
sup.post(B + '/admin/branding-action.php', data={'csrf_token': tok(), 'do': 'reset'}, allow_redirects=False)
home = requests.get(B + '/').text
ok('ExpenseFlow' in home and 'brand-colours' not in home and 'Spendly' not in home, 'reset restores the built-in look')
clean()
sql("DELETE FROM users WHERE email LIKE '%@br.local'")
print('admin_branding_http_test:', 'ALL PASSED' if not fails else f'{fails} FAILED')
