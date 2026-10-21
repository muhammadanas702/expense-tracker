"""Public pages: reachable, one h1, canonical, valid JSON-LD, listed in the sitemap, private areas blocked in robots.txt."""
import re, json, requests
B = 'http://127.0.0.1:8080'
fails = 0
def ok(c, m):
    global fails
    if not c: fails += 1; print('FAIL', m)
sm = requests.get(B + '/sitemap.xml').text
locs = re.findall(r'<loc>https://expenseflow\.rf\.gd/([^<]*)</loc>', sm)
ok(len(locs) >= 9, 'sitemap lists the public pages')
for path in locs:
    r = requests.get(B + '/' + path); t = r.text
    ok(r.status_code == 200, f'{path or "/"} reachable')
    ok(t.count('<h1') == 1, f'{path or "/"} has exactly one h1')
    ok('rel="canonical"' in t and 'name="description"' in t, f'{path or "/"} canonical + description')
    ok('og:image' in t and 'twitter:card' in t, f'{path or "/"} social tags')
    good = True
    for x in re.findall(r'<script type="application/ld\+json">(.*?)</script>', t, re.S):
        try: json.loads(x)
        except Exception: good = False
    ok(good, f'{path or "/"} JSON-LD is valid')
    ok('noindex' not in t, f'{path or "/"} is indexable')
home = requests.get(B + '/').text
for w in ('about.php', 'privacy.php', 'terms.php', 'contact.php', 'guides/'):
    ok(w in home, f'home links to {w}')
ok('"@type":"FAQPage"' in home.replace(' ', ''), 'home has FAQ schema')
rb = requests.get(B + '/robots.txt').text
for d in ('/admin/', '/dashboard.php', '/shared/', '/support/'):
    ok('Disallow: ' + d in rb, f'robots blocks {d}')
ok('Sitemap:' in rb, 'robots points to the sitemap')
ok('noindex' in requests.get(B + '/auth/login.php').text, 'login page is noindex')
print('seo tests:', 'ALL PASSED' if not fails else f'{fails} FAILED')
