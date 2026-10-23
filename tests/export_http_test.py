"""Exports: branded Excel/PDF/CSV from the report page and the admin user list.
Needs the dev server + seeded users (a@ = user, s@ = super admin, password Test#12345)."""
import io, re, zipfile, requests
B = 'http://127.0.0.1:8080'
fails = 0
def ok(c, m):
    global fails
    if not c: fails += 1; print('FAIL', m)
def login(em):
    s = requests.Session(); t = re.search(r'name="csrf_token" value="([^"]+)"', s.get(B + '/auth/login.php').text).group(1)
    s.post(B + '/auth/login.php', data={'email': em, 'password': 'Test#12345', 'csrf_token': t}); return s
q = '?from_date=2020-01-01&to_date=2030-12-31&type=all'
u = login('a@crawl.test')
x = u.get(B + '/reports/view-report.php' + q + '&export=excel')
ok(x.status_code == 200 and x.content[:2] == b'PK' and '.xlsx' in x.headers.get('Content-Disposition', ''), 'xlsx download')
z = zipfile.ZipFile(io.BytesIO(x.content)); names = z.namelist()
ok(any('media/' in n for n in names), 'xlsx carries the logo image')
blob = ''.join(z.read(n).decode('utf8', 'ignore') for n in names if n.endswith('.xml'))
ok('ExpenseFlow' in blob and 'Financial Report' in blob, 'xlsx has brand + title')
ok('=cmd' not in re.sub(r"'=cmd", '', blob), 'formula-looking title is neutralised')
p = u.get(B + '/reports/view-report.php' + q + '&export=pdf')
ok(p.status_code == 200 and p.content[:5] == b'%PDF-' and '.pdf' in p.headers.get('Content-Disposition', ''), 'pdf download')
c = u.get(B + '/reports/view-report.php' + q + '&export=csv')
ok(c.status_code == 200 and 'ExpenseFlow-Report' in c.headers.get('Content-Disposition', ''), 'csv branded name')
ok(requests.get(B + '/reports/view-report.php' + q + '&export=pdf', allow_redirects=False).status_code in (301, 302), 'anonymous cannot export')
s = login('s@crawl.test')
for f, mg in (('xlsx', b'PK'), ('pdf', b'%PDF-'), ('csv', b'\xef\xbb\xbf')):
    r = s.get(B + '/admin/user-export.php?format=' + f)
    ok(r.status_code == 200 and r.content[:len(mg)] == mg, 'admin users ' + f)
print('export tests: ALL PASSED' if not fails else f'export tests: {fails} FAILED')
