"""
Part 61: the live-site rules against a local server by sending the production Host header. Usage: python3 tests/redirect_http_test.py
(php -S 127.0.0.1:8080 -t . router.php must be running)
"""
import requests
B = 'http://127.0.0.1:8080'
H = {'Host': 'expenseflow.rf.gd'}
fails = 0
def ok(c, m):
    global fails
    if not c: fails += 1; print('FAIL', m)
def get(p, headers=None, **kw): return requests.get(B + p, headers={**H, **(headers or {})}, allow_redirects=False, **kw)
r = get('/about.php')
ok(r.status_code == 301 and r.headers.get('Location') == 'https://expenseflow.rf.gd/about.php?ef_https=1', f'plain http is sent to https ({r.status_code} {r.headers.get("Location")})')
r = get('/about.php', {'X-Forwarded-Proto': 'https'})
ok(r.status_code == 200, f'https is served ({r.status_code})')
r = get('/about.php?ef_https=1')
ok(r.status_code == 200 and 'ef_s=1' in r.headers.get('Set-Cookie', '') and 'Secure' in r.headers.get('Set-Cookie', ''), 'after the redirect the page is served and a Secure cookie remembers it (no loop)')
r = get('/about.php', {'Cookie': 'ef_s=1'})
ok(r.status_code == 200, 'a browser with the cookie is not redirected again')
r = get('/about.php', {'Host': 'www.expenseflow.rf.gd', 'X-Forwarded-Proto': 'https'})
ok(r.status_code == 301 and r.headers.get('Location') == 'https://expenseflow.rf.gd/about.php', 'another host name goes to the one address')
r = requests.post(B + '/auth/login.php', headers=H, data={'email': 'a@b.c'}, allow_redirects=False)
ok(r.status_code != 301, 'POST is never redirected')
r = get('/health.php'); ok(r.status_code == 200, 'health check is never redirected')
r = requests.get(B + '/about.php', allow_redirects=False); ok(r.status_code == 200, 'localhost is never redirected')
r = requests.get(B + '/', headers=H, allow_redirects=False); ok(r.status_code == 301, 'landing page: http -> https')
print('redirect http tests:', 'ALL PASSED' if not fails else f'{fails} FAILED')
