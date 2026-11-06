"""
Part 64: public pages (landing, about, contact, guides index, sign-in) are fully Urdu in Urdu mode, and the larger Nastaleeq size is applied.
LOCAL server. Usage: php -S 127.0.0.1:8080 -t . router.php   then   python3 tests/urdu_public_test.py
"""
import re, html, requests
B = 'http://127.0.0.1:8080'
fails = 0
def ok(c, m):
    global fails
    if not c: fails += 1; print('FAIL', m)
ALLOWED = re.compile(r'^(ExpenseFlow|English|expenseflowteam@gmail\.com|you@example\.com)$|^[A-Za-z]+ (ka|ke|ko|se|mein|kaise)\b|\bjo har mahine\b|^[A-Za-z ,.]*\b(kaise|baantein|bachayein|tareeqa|hisab|asaan|jo|karte)\b', re.I)  # brand, e-mail, and the Roman-Urdu guide titles
s = requests.Session(); s.cookies.set('ef_lang', 'ur')
for u in ['/', '/about.php', '/contact.php', '/guides/', '/auth/login.php']:
    r = s.get(B + u); ok(r.status_code == 200, u + ' loads')
    t = re.sub(r'<(script|style)[^>]*>.*?</\1>', '', r.text, flags=re.S)
    parts = re.split(r'(<[^>]+>)', t); texts = []
    for p in parts:
        if p.startswith('<'):
            texts += [html.unescape(m) for m in re.findall(r'(?:placeholder|title|aria-label|alt)="([^"]+)"', p)]
        else:
            texts.append(html.unescape(re.sub(r'\s+', ' ', p)).strip())
    left = [x for x in texts if re.search(r'[A-Za-z]{3}', x) and not re.search(r'[؀-ۿ]', x) and not ALLOWED.search(x)]
    ok(not left, f'{u}: English left: {left[:5]}')
    ok('dir="rtl"' in r.text, u + ' is right-to-left')
css = s.get(B + '/assets/rtl.css').text
ok('html[dir=rtl]{font-size:110%}' in css and 'Noto Nastaliq Urdu' in css, 'Nastaleeq font and size in rtl.css')
ok(s.get(B + '/assets/fonts/noto-nastaliq-urdu-400.woff2').status_code == 200, 'font file is served')
r = requests.get(B + '/'); ok('dir="rtl"' not in r.text, 'English landing page unchanged')
off = open('offline.html', encoding='utf8').read()
ok('ef_lang=ur' in off and 'rtl.css' in off, 'offline page switches to Urdu')
print('urdu public tests: ' + ('ALL PASSED' if not fails else f'{fails} FAILED'))
raise SystemExit(1 if fails else 0)
