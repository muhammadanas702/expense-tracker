"""
Part 65: fonts. Self-hosted English (Inter + Plus Jakarta Sans) and ONE Urdu font (Nastaleeq) that is already loaded at the first paint.
LOCAL server. Usage: php -S 127.0.0.1:8080 -t . router.php   then   python3 tests/fonts_test.py
"""
import re, requests
from playwright.sync_api import sync_playwright
B = 'http://127.0.0.1:8080'
fails = 0
def ok(c, m):
    global fails
    if not c: fails += 1; print('FAIL', m)
for f in ('inter-latin', 'plus-jakarta-sans-latin', 'noto-nastaliq-urdu-400', 'noto-nastaliq-urdu-700'):
    r = requests.get(f'{B}/assets/fonts/{f}.woff2'); ok(r.status_code == 200 and len(r.content) > 20000, f + ' is served')
for f in ('assets/theme.css', 'assets/app.css', 'assets/fonts.css', 'assets/rtl.css'):
    ok('googleapis' not in open(f).read(), f + ' does not call Google Fonts')
sw = open('sw.js').read()
ok(all(x in sw for x in ('inter-latin.woff2', 'noto-nastaliq-urdu-400.woff2', 'plus-jakarta-sans-latin.woff2')), 'service worker keeps the fonts for offline use')
for lang, must, must_not in (('en', ('plus-jakarta-sans-latin.woff2',), ('noto-nastaliq',)), ('ur', ('noto-nastaliq-urdu-400.woff2',), ('plus-jakarta',))):
    for u in ('/', '/auth/login.php'):
        t = requests.get(B + u, cookies={'ef_lang': lang}).text
        pre = re.findall(r'<link rel="preload" href="([^"]+)" as="font"', t)
        ok(all(any(m in p for p in pre) for m in must), f'{u} ({lang}) preloads {must}')
        ok(not any(m in p for p in pre for m in must_not), f'{u} ({lang}) does not preload {must_not}')
        ok(all('?' not in p for p in pre), f'{u} ({lang}) preload URL matches the @font-face URL (no ?v=)')
with sync_playwright() as pw:
    br = pw.chromium.launch()
    ctx = br.new_context(viewport={'width': 390, 'height': 800}, service_workers='block'); ctx.add_cookies([{'name': 'ef_lang', 'value': 'ur', 'url': B}])
    p = ctx.new_page()
    p.add_init_script("new PerformanceObserver(l=>{for(const e of l.getEntries()) if(e.name==='first-contentful-paint') window.__fcp=[...document.fonts].map(f=>f.family+':'+f.status)}).observe({type:'paint',buffered:true});")
    p.goto(B + '/auth/login.php'); p.wait_for_timeout(1200)
    st = p.evaluate('window.__fcp') or []
    ok(st and not any(x.startswith('Noto Nastaliq Urdu') and not x.endswith('loaded') for x in st), f'Nastaleeq is already loaded at the first paint: {st}')
    fams = p.evaluate("[...new Set([...document.querySelectorAll('h1,p,label,button,a,input')].map(e=>getComputedStyle(e).fontFamily.split(',')[0]))]")
    ok(fams == ['"Noto Nastaliq Urdu"'], f'one Urdu font for every element: {fams}')
    ctx2 = br.new_context(viewport={'width': 390, 'height': 800}, service_workers='block'); p2 = ctx2.new_page()
    p2.goto(B + '/'); p2.wait_for_timeout(600)
    ok('Plus Jakarta Sans' in p2.evaluate("getComputedStyle(document.querySelector('h1')).fontFamily"), 'English headings use Plus Jakarta Sans')
    ok(p2.evaluate("getComputedStyle(document.querySelector('p')).fontFamily").startswith('Inter'), 'English text uses Inter')
    br.close()
print('fonts tests: ' + ('ALL PASSED' if not fails else f'{fails} FAILED'))
raise SystemExit(1 if fails else 0)
