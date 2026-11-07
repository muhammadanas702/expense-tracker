"""
Part 68: UI audit. Crawls the signed-in app (and the admin panel with --admin) plus the public pages in a real browser at phone and desktop width and reports
what looks or behaves wrong: page errors, failed requests, sideways scrolling, broken images, small tap targets, fields without a label, tiny text, a missing or
double h1, fonts outside the design system. Screenshots go to /tmp/audit (or --out=DIR).
Usage: (EXPENSEFLOW_2FA_OFF=1 php -S 127.0.0.1:8080 -t . router.php)  python3 tests/ui_audit.py [--admin] [--max=80] [--json=file] [--shots] [--fail-over=N] [--dark] [--urdu]
Uses the TEST database; creates and removes one audit user.
"""
import re, subprocess, sys, json, os
from playwright.sync_api import sync_playwright
B = 'http://127.0.0.1:8080'
admin = '--admin' in sys.argv; shots = '--shots' in sys.argv
maxp = next((int(a[6:]) for a in sys.argv if a.startswith('--max=')), 80)
out = next((a[6:] for a in sys.argv if a.startswith('--out=')), '/tmp/audit')
jf = next((a[7:] for a in sys.argv if a.startswith('--json=')), None)
dark = '--dark' in sys.argv; urdu = '--urdu' in sys.argv
limit = next((int(a[12:]) for a in sys.argv if a.startswith('--fail-over=')), None)
def sql(q): return subprocess.run(['mysql', '--socket=/run/mysqld/mysqld.sock', '-uroot', 'expenseflow_test', '-N', '-e', q], capture_output=True, text=True).stdout.strip()
h = subprocess.run(['php', '-r', 'echo password_hash("Test#12345",PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout
email = 'audit@audit.local'
sql(f"DELETE FROM users WHERE email='{email}'"); sql("DELETE FROM rate_limit_hits")
role = "'super',1" if admin else "'user',0"
sql(f"INSERT INTO users(name,email,password,role,is_admin) VALUES ('Audit User','{email}','{h}',{role})")
uid = sql(f"SELECT id FROM users WHERE email='{email}'")
sql(f"INSERT INTO user_settings (user_id, savings_goal, notifications, setup_done) VALUES ({uid}, 20, 1, 1)")
sql(f"INSERT INTO income (user_id,title,amount,currency,amount_pkr,payment_method,transaction_date) VALUES ({uid},'Salary',50000,'PKR',50000,'Bank',NOW())")
sql(f"INSERT INTO expenses (user_id,title,amount,currency,amount_pkr,category,payment_method,transaction_date) VALUES ({uid},'Lunch',450,'PKR',450,'Food','Cash',NOW())")
SKIP = re.compile(r'logout|delete|export|download|-action|remove|\.pdf|\.csv|\.apk|\.exe|mailto:|tel:|^#|javascript:|github\.com|google|login-code|backup|reset|stop-recurring|https-action', re.I)
PUBLIC = ['/', '/about.php', '/contact.php', '/privacy.php', '/terms.php', '/guides/', '/auth/login.php', '/auth/register.php', '/auth/forgot-password.php', '/offline.html']
CHECK = """() => {
  const r = {};
  const vis = e => { const c = getComputedStyle(e), b = e.getBoundingClientRect(); return c.display !== 'none' && c.visibility !== 'hidden' && b.width > 0 && b.height > 0; };
  r.overflowX = document.documentElement.scrollWidth - innerWidth;
  r.overflowEls = [];
  if (r.overflowX > 1) document.querySelectorAll('body *').forEach(e => { const b = e.getBoundingClientRect(); if (vis(e) && b.right > innerWidth + 1 && r.overflowEls.length < 4) r.overflowEls.push(e.tagName.toLowerCase() + (e.className && typeof e.className === 'string' ? '.' + e.className.trim().split(/\\s+/)[0] : '')); });
  r.gaps = []; r.narrow = [];
  const main = document.querySelector('.view, .mn');
  if (main) {
    const kids = [...main.children].filter(vis), mb = main.getBoundingClientRect();
    const nm = e => e.tagName.toLowerCase() + (e.className && typeof e.className === 'string' ? '.' + e.className.trim().split(" ")[0] : '');
    for (let i = 1; i < kids.length; i++) { const g = Math.round(kids[i].getBoundingClientRect().top - kids[i - 1].getBoundingClientRect().bottom); if (g > 48) r.gaps.push(nm(kids[i - 1]) + '->' + nm(kids[i]) + ' ' + g + 'px'); }
    if (innerWidth >= 1000) kids.forEach(k => { const b = k.getBoundingClientRect(); if (b.height > 120 && b.width < mb.width * 0.55) r.narrow.push(nm(k) + ' ' + Math.round(b.width) + 'px of ' + Math.round(mb.width)); });
  }
  r.brokenImg = [...document.images].filter(i => i.complete && i.naturalWidth === 0 && vis(i)).map(i => i.getAttribute('src')).slice(0, 5);
  r.small = [...document.querySelectorAll('a[href],button,input:not([type=hidden]):not([type=checkbox]):not([type=radio]),select,textarea,[role=button]')].filter(e => { if (!vis(e)) return false; const b = e.getBoundingClientRect(); return (b.height < 32 || b.width < 32) && !(e.closest('p,li,small,td,.mu,footer') && e.tagName === 'A' && b.height >= 18); }).map(e => (e.tagName.toLowerCase() + ':' + ((e.innerText || e.getAttribute('aria-label') || e.getAttribute('name') || '').trim().slice(0, 18)) + ' ' + Math.round(e.getBoundingClientRect().width) + 'x' + Math.round(e.getBoundingClientRect().height))).slice(0, 6);
  r.unlabeled = [...document.querySelectorAll('input:not([type=hidden]):not([type=submit]):not([type=button]),select,textarea')].filter(e => { if (!vis(e) && !e.classList.contains('swc')) return false; const id = e.id; return !(e.getAttribute('aria-label') || e.getAttribute('aria-labelledby') || e.getAttribute('title') || (id && document.querySelector('label[for="' + id + '"]')) || e.closest('label') || e.getAttribute('placeholder')); }).map(e => e.tagName.toLowerCase() + '[' + (e.name || e.type) + ']').slice(0, 5);
  r.tiny = [...document.querySelectorAll('body *')].filter(e => { if (!vis(e) || !e.childNodes.length) return false; const t = [...e.childNodes].filter(n => n.nodeType === 3 && n.nodeValue.trim().length > 3); if (!t.length) return false; return parseFloat(getComputedStyle(e).fontSize) < 11.5; }).map(e => e.tagName.toLowerCase() + '.' + String(e.className).split(' ')[0] + ':' + parseFloat(getComputedStyle(e).fontSize).toFixed(1)).slice(0, 4);
  r.h1 = document.querySelectorAll('h1').length;
  r.title = document.title;
  const fams = new Set(); document.querySelectorAll('body *').forEach(e => { if (vis(e) && [...e.childNodes].some(n => n.nodeType === 3 && n.nodeValue.trim())) fams.add(getComputedStyle(e).fontFamily.split(',')[0].replace(/["']/g, '').trim()); });
  r.fonts = [...fams].filter(f => !/^(Inter|Plus Jakarta Sans|Noto Nastaliq Urdu|ui-monospace|monospace|SFMono-Regular|Consolas|Menlo|Courier New)$/i.test(f));
  r.links = [...document.querySelectorAll('a[href]')].map(a => a.getAttribute('href'));
  r.noMeta = !document.querySelector('meta[name=viewport]');
  return r;
}"""
rep = {}
def slug(p): return re.sub(r'[^a-z0-9]+', '-', p.lower()).strip('-') or 'home'
with sync_playwright() as pw:
    br = pw.chromium.launch()
    for vp, (w, hh) in (('m', (390, 800)), ('d', (1280, 800))):
        ctx = br.new_context(viewport={'width': w, 'height': hh}, service_workers='block', is_mobile=(vp == 'm'), device_scale_factor=1)
        if dark: ctx.add_cookies([{'name': 'ef_theme', 'value': 'dark', 'url': B}])
        if urdu: ctx.add_cookies([{'name': 'ef_lang', 'value': 'ur', 'url': B}])
        p = ctx.new_page()
        errs = []
        p.route('**/health.php*', lambda r: r.abort())  # keeps offline.html from reloading itself
        p.on('pageerror', lambda e: errs.append('js: ' + str(e)[:120]))
        p.on('console', lambda m: errs.append('console: ' + m.text[:120]) if m.type == 'error' else None)
        p.on('requestfailed', lambda r: errs.append('failed: ' + r.url.replace(B, '')[:90]) if B in r.url else None)
        p.on('response', lambda r: errs.append(f'http {r.status}: ' + r.url.replace(B, '')[:90]) if B in r.url and r.status >= 400 and r.request.resource_type in ('script', 'stylesheet', 'image', 'font', 'fetch', 'xhr') else None)
        queue = list(PUBLIC)
        def visit(path, tag):
            errs.clear()
            try:
                r = p.goto(B + path, wait_until='load'); p.wait_for_timeout(350)
            except Exception as e:
                rep.setdefault(path, {})[vp] = {'issues': ['navigation failed: ' + str(e)[:80]]}; return None
            st = r.status if r else 0
            issues = []
            if st >= 400: issues.append(f'HTTP {st}')
            if '/auth/login' in p.url and not path.startswith('/auth'): issues.append('redirected to login'); return None
            c = None
            for _ in range(3):
                try: c = p.evaluate(CHECK); break
                except Exception: p.wait_for_timeout(600)
            if c is None: rep.setdefault(path, {})[vp] = {'issues': ['page keeps reloading or navigating']}; return None
            if c['overflowX'] > 1: issues.append(f"sideways scroll +{c['overflowX']}px ({', '.join(c['overflowEls'])})")
            if c['gaps']: issues.append('empty gap: ' + '; '.join(c['gaps'][:3]))
            if c['narrow']: issues.append('narrow column: ' + '; '.join(c['narrow'][:3]))
            if c['brokenImg']: issues.append('broken image ' + ', '.join(c['brokenImg']))
            if vp == 'm' and c['small']: issues.append('small tap targets: ' + '; '.join(c['small']))
            if c['unlabeled']: issues.append('field without label: ' + ', '.join(c['unlabeled']))
            if c['tiny']: issues.append('tiny text: ' + ', '.join(c['tiny']))
            if c['h1'] != 1 and not path.endswith('offline.html'): issues.append(f"{c['h1']} h1 headings")
            if not c['title'] or len(c['title']) < 4: issues.append('missing page title')
            if c['fonts']: issues.append('font outside the system: ' + ', '.join(c['fonts']))
            if c['noMeta']: issues.append('no viewport meta')
            issues += sorted(set(errs))[:5]
            rep.setdefault(path, {})[vp] = {'issues': issues, 'status': st}
            if shots:
                os.makedirs(out, exist_ok=True); p.screenshot(path=f'{out}/{slug(path)}-{vp}.png', full_page=False)
            return c['links']
        for path in PUBLIC: visit(path, 'public')
        p.goto(B + '/auth/login.php'); p.fill('input[name=email]', email); p.fill('input[name=password]', 'Test#12345'); p.click('button[type=submit]'); p.wait_for_load_state(); p.wait_for_timeout(800)
        queue = ['/dashboard.php', '/profile.php'] + (['/admin/index.php'] if admin else [])
        seen, n = set(PUBLIC), 0
        while queue and n < maxp:
            path = queue.pop(0)
            if path in seen: continue
            seen.add(path); n += 1
            links = visit(path, 'app')
            for l in links or []:
                m = re.match(r'^(?:https?://127\.0\.0\.1:8080)?(/[^?#]*(?:\?[^#]*)?)$', l) or (re.match(r'^([\w./-]+\.php(?:\?[^#]*)?)$', l) and re.match(r'^([\w./-]+\.php(?:\?[^#]*)?)$', l))
                if not m: continue
                t = m.group(1)
                if not t.startswith('/'):
                    base = os.path.dirname(path); t = os.path.normpath(os.path.join(base, t))
                    if not t.startswith('/'): t = '/' + t
                if SKIP.search(t) or t in seen or t in queue or t.startswith('/tests') or t.startswith('/docs'): continue
                queue.append(t)
        ctx.close()
    br.close()
sql(f"DELETE FROM users WHERE email='{email}'")
total = 0
for path, vs in rep.items():
    for vp, d in vs.items():
        for i in d['issues']:
            total += 1; print(f'{path} [{vp}] {i}')
print(f'\n{len(rep)} pages checked, {total} findings')
if jf: json.dump(rep, open(jf, 'w'), indent=1)
if limit is not None and total > limit: raise SystemExit(1)
