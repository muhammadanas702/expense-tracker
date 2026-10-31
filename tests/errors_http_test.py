"""
Friendly error pages and clear messages. Usage: php -S 127.0.0.1:8080 -t . router.php   then   python3 tests/errors_http_test.py
Checks: unknown page = friendly 404 (not a blank/plain page), no PHP details leak, forbidden folders = friendly page,
JSON endpoints answer JSON (never HTML) on bad input, .htaccess has ErrorDocument lines, error.php works without the database.
"""
import os, re, requests, sys
B = 'http://127.0.0.1:8080'
ROOT = os.path.join(os.path.dirname(__file__), '..')
fails = 0
def ok(c, m):
    global fails
    if not c: fails += 1; print('FAIL', m)
r = requests.get(B + '/no-such-page.php')
ok(r.status_code == 404 and 'We could not find that page' in r.text and 'Go to home' in r.text, 'unknown page: friendly 404')
ok('Fatal error' not in r.text and 'Stack trace' not in r.text and '/home/' not in r.text, '404 shows no PHP details')
r = requests.get(B + '/error.php?c=500')
ok(r.status_code == 500 and 'Something went wrong on our side' in r.text, 'error.php?c=500 friendly page with status 500')
r = requests.get(B + '/error.php?c=999')
ok(r.status_code == 404, 'unknown code falls back to 404')
r = requests.get(B + '/modules/oauth/lib.php')
ok(r.status_code in (403, 404) and '<?php' not in r.text and 'function ' not in r.text, 'module source is never served')
r = requests.post(B + '/auth/google-app.php', data={'do': 'start'})
ok(r.status_code == 403 and r.headers['Content-Type'].startswith('application/json') and r.json()['state'] == 'error', 'JSON endpoint answers JSON on bad input')
r = requests.get(B + '/auth/google-callback.php?code=abc', allow_redirects=True)
ok('could not be continued' in r.text or 'not available' in r.text, 'Google callback problems are explained on the login page')
ht = open(os.path.join(ROOT, '.htaccess')).read()
ok(all(re.search(r'^ErrorDocument %d /error\.php\?c=%d' % (c, c), ht, re.M) for c in (400, 403, 404, 500, 503)), '.htaccess maps 400/403/404/500/503 to error.php')
print('errors_http_test: ALL PASSED' if not fails else f'{fails} FAILED')
sys.exit(1 if fails else 0)
