"""
Part 109a: UI measurement audit. Opens every page of the signed-in app (and the admin panel with --admin) at 360, 390, 768 and 1366 px and MEASURES it with getBoundingClientRect:
  edges     cards/blocks in the page column start and end on the same line (+-1px)
  gutter    distance from the screen edge to the content is the same on every page
  gap       space between blocks is on the 4px scale and equal to the page's usual gap
  padding   cards use the same inner padding as the other cards of their kind
  btn       buttons of one size have one height; the label sits in the vertical middle of the button
  icon      icons are centred on the text next to them
  rows      list rows: titles start on one line and amounts end on one line inside a card
  clip      text is not cut off or pushed outside its box; no two controls overlap
  nav       sidebar, bottom bar and the Personal/Shared switch: equal item sizes, icon and label centred
  radius    corner radius of cards and buttons is the same everywhere
Usage: (EXPENSEFLOW_2FA_OFF=1 php -S 127.0.0.1:8080 -t . router.php)  python3 tests/ui_measure.py [--admin] [--dark] [--max=60] [--json=file] [--fail-over=N] [--widths=360,390,768,1366] [--verbose]
Uses the TEST database; creates and removes one audit user. Exit code 1 only with --fail-over.
"""
import re, subprocess, sys, json, os, collections
from playwright.sync_api import sync_playwright
B = 'http://127.0.0.1:8080'
admin = '--admin' in sys.argv; dark = '--dark' in sys.argv; verbose = '--verbose' in sys.argv
maxp = next((int(a[6:]) for a in sys.argv if a.startswith('--max=')), 60)
jf = next((a[7:] for a in sys.argv if a.startswith('--json=')), None)
limit = next((int(a[12:]) for a in sys.argv if a.startswith('--fail-over=')), None)
widths = [int(x) for x in next((a[9:] for a in sys.argv if a.startswith('--widths=')), '360,390,768,1366').split(',')]
def sql(q): return subprocess.run(['mysql', '--socket=/run/mysqld/mysqld.sock', '-uroot', 'expenseflow_test', '-N', '-e', q], capture_output=True, text=True).stdout.strip()
h = subprocess.run(['php', '-r', 'echo password_hash("Test#12345",PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout
email = 'measure@audit.local'
sql(f"DELETE FROM users WHERE email='{email}'"); sql("DELETE FROM rate_limit_hits")
role = "'super',1" if admin else "'user',0"
sql(f"INSERT INTO users(name,email,password,role,is_admin) VALUES ('Measure User','{email}','{h}',{role})")
uid = sql(f"SELECT id FROM users WHERE email='{email}'")
sql(f"INSERT INTO user_settings (user_id, savings_goal, notifications, setup_done) VALUES ({uid}, 20, 1, 1)")
subprocess.run(['php', os.path.join(os.path.dirname(os.path.abspath(__file__)), 'ui_seed_shared.php'), uid], capture_output=True)
sql(f"INSERT INTO income (user_id,title,amount,currency,amount_pkr,payment_method,transaction_date) VALUES ({uid},'Salary',50000,'PKR',50000,'Bank',NOW())")
sql(f"INSERT INTO expenses (user_id,title,amount,currency,amount_pkr,category,payment_method,transaction_date) VALUES ({uid},'Lunch',450,'PKR',450,'Food','Cash',NOW())")
SKIP = re.compile(r'logout|delete|export|download|-action|remove|\.pdf|\.csv|\.apk|\.exe|mailto:|tel:|^#|javascript:|github\.com|google|login-code|backup|reset|stop-recur|/tests|/docs|offline')

MEASURE = r"""() => {
  const R = {issues: [], stat: {}};
  const add = (k, m) => R.issues.push([k, m]);
  const r1 = n => Math.round(n * 10) / 10;
  const vis = e => { const c = getComputedStyle(e), b = e.getBoundingClientRect(); if (c.display === 'none' || c.visibility === 'hidden' || +c.opacity === 0 || b.width < 1 || b.height < 1) return false; if (e.closest('[hidden],details:not([open]):not(summary)')) { if (!e.closest('summary')) return false; } return true; };
  const fixed = e => { for (let p = e; p && p !== document.body; p = p.parentElement) { const c = getComputedStyle(p).position; if (c === 'fixed' || c === 'sticky') return true; } return false; };
  const nm = e => e.tagName.toLowerCase() + (typeof e.className === 'string' && e.className.trim() ? '.' + e.className.trim().split(/\s+/).slice(0, 2).join('.') : '') + (e.id ? '#' + e.id : '');
  const txt = e => (e.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 24);
  const W = innerWidth;
  // ---- edges, gutter, gap -------------------------------------------------------------
  const main = document.querySelector('.view') || document.querySelector('main') || document.querySelector('.wrap');
  const col = document.querySelector('.mn') || main;
  R.stat.gutterL = null; R.stat.gaps = [];
  if (col) {
    const cb = col.getBoundingClientRect();
    R.stat.gutterL = r1(cb.left); R.stat.gutterR = r1(W - cb.right);
    if (main) {
      const mb = main.getBoundingClientRect();
      const kids = [...main.children].filter(e => vis(e) && !fixed(e) && getComputedStyle(e).position !== 'absolute');
      kids.forEach(k => {
        const b = k.getBoundingClientRect(), c = getComputedStyle(k);
        if (c.display === 'inline' || c.display === 'inline-block' || c.display === 'inline-flex') return;
        if (k.classList.contains('msg')) return; // flash messages animate in
        const ml = parseFloat(c.marginLeft) || 0, mr = parseFloat(c.marginRight) || 0;
        const dl = b.left - ml - mb.left, dr = b.right + mr - mb.right; // margins count as part of the block
        const full = b.width > mb.width * 0.9;
        if (full && (Math.abs(dl) > 1 || Math.abs(dr) > 1)) add('edges', `${nm(k)} is off the column edge (left ${r1(dl)}, right ${r1(dr)})`);
        else if (!full && Math.abs(dl) > 1 && Math.abs(dr) > 1) add('edges', `${nm(k)} floats inside the column (left ${r1(dl)}, right ${r1(dr)})`);
      });
      for (let i = 1; i < kids.length; i++) {
        const g = r1(kids[i].getBoundingClientRect().top - kids[i - 1].getBoundingClientRect().bottom);
        R.stat.gaps.push(g);
        if (g < -1) add('gap', `${nm(kids[i])} overlaps the block above by ${-g}px`);
      }
    }
  }
  // ---- card padding and radius -------------------------------------------------------
  R.stat.pad = []; R.stat.radius = [];
  document.querySelectorAll('.card:not(.hero):not(.gc), .g.card:not(.hero):not(.gc)').forEach(e => {
    if (!vis(e) || fixed(e)) return;
    const c = getComputedStyle(e);
    R.stat.pad.push([nm(e), r1(parseFloat(c.paddingTop)), r1(parseFloat(c.paddingRight)), r1(parseFloat(c.paddingBottom)), r1(parseFloat(c.paddingLeft))]);
    const rad = r1(parseFloat(c.borderTopLeftRadius)); if (rad < 100 && !(e.parentElement && e.parentElement.classList.contains('g'))) R.stat.radius.push(['card', rad]); // pill-shaped search bars are intentional
  });
  // ---- buttons ----------------------------------------------------------------------
  R.stat.btn = [];
  document.querySelectorAll('.btn, button.btn, a.btn, input.btn').forEach(e => {
    if (!vis(e) || fixed(e)) return;
    const b = e.getBoundingClientRect(), c = getComputedStyle(e);
    const sig = (e.classList.contains('sm') ? 'sm' : 'md') + (e.closest('.sp-bar,#dash-search') ? '-pill' : '');
    R.stat.btn.push([sig, r1(b.height), nm(e)]);
    R.stat.radius.push(['btn-' + sig, r1(parseFloat(c.borderTopLeftRadius))]);
    // label centred in the button
    const tn = [...e.childNodes].find(n => n.nodeType === 3 && n.nodeValue.trim());
    if (tn) { const rg = document.createRange(); rg.selectNodeContents(tn); const rb = rg.getBoundingClientRect(); const d = (rb.top + rb.height / 2) - (b.top + b.height / 2); if (Math.abs(d) > 2.5) add('btn', `label of ${nm(e)} "${txt(e)}" is ${r1(d)}px off the vertical middle`); }
    if (e.scrollWidth > e.clientWidth + 1 && c.overflow !== 'visible') add('clip', `${nm(e)} "${txt(e)}" is wider than its button`);
  });
  // ---- icon next to text --------------------------------------------------------------
  document.querySelectorAll('a, button, .pl, .t, label, summary, .badge, .chip, .row > span, .k').forEach(e => {
    if (!vis(e) || fixed(e)) return;
    const ic = [...e.children].find(x => x.matches && x.matches('svg.i'));
    if (!ic) return;
    const tn = [...e.childNodes].find(n => n.nodeType === 3 && n.nodeValue.trim());
    if (!tn || e.querySelector('small, div, p') || e.getBoundingClientRect().height > 44) return; // two-line blocks centre the icon on both lines on purpose
    const rg = document.createRange(); rg.selectNodeContents(tn); const tr = rg.getClientRects()[0], ib = ic.getBoundingClientRect();
    if (!tr || tr.height < 1) return;
    const d = (ib.top + ib.height / 2) - (tr.top + tr.height / 2);
    if (Math.abs(d) > 2.5) add('icon', `icon in ${nm(e)} "${txt(e)}" is ${r1(d)}px off the text middle`);
  });
  // ---- list rows --------------------------------------------------------------------
  document.querySelectorAll('.card, .g.card').forEach(card => {
    if (!vis(card)) return;
    const rows = [...card.querySelectorAll(':scope > .row, :scope > a.row')].filter(vis);
    if (rows.length < 2) return;
    const lefts = rows.map(r => { const s = [...r.children].find(c => c.tagName === 'SPAN' && vis(c)); return s ? s.getBoundingClientRect().left : null; }).filter(x => x !== null);
    if (lefts.length > 1 && Math.max(...lefts) - Math.min(...lefts) > 1.5) add('rows', `titles in ${nm(card)} start on different lines (${r1(Math.min(...lefts))}..${r1(Math.max(...lefts))})`);
    const rights = rows.map(r => { const b = [...r.children].find(c => c.tagName === 'B' && vis(c)); return b ? b.getBoundingClientRect().right : null; }).filter(x => x !== null);
    if (rights.length > 1 && Math.max(...rights) - Math.min(...rights) > 1.5) add('rows', `amounts in ${nm(card)} end on different lines (${r1(Math.min(...rights))}..${r1(Math.max(...rights))})`);
  });
  // ---- clipping and overflow of text ------------------------------------------------
  let clipN = 0;
  document.querySelectorAll('body *').forEach(e => {
    if (clipN > 6 || !vis(e) || fixed(e)) return;
    const c = getComputedStyle(e);
    if (!e.childNodes.length || ![...e.childNodes].some(n => n.nodeType === 3 && n.nodeValue.trim())) return;
    if ((c.overflow === 'hidden' || c.overflowX === 'hidden' || c.overflowX === 'clip') && e.scrollWidth > e.clientWidth + 1 && c.textOverflow !== 'ellipsis' && c.display !== 'inline') { add('clip', `text of ${nm(e)} "${txt(e)}" is cut off (${e.scrollWidth} > ${e.clientWidth})`); clipN++; }
    const p = e.parentElement; if (!p || p === document.body) return;
    const pc = getComputedStyle(p);
    if (pc.overflowX === 'visible' && c.display !== 'inline' && !['svg', 'path'].includes(e.tagName.toLowerCase())) {
      const b = e.getBoundingClientRect(), pb = p.getBoundingClientRect();
      if (pb.width > 20 && (b.right > pb.right + 1.5 || b.left < pb.left - 1.5) && !p.closest('.bn,.msheet,table,.tbl')) { add('clip', `${nm(e)} "${txt(e)}" sticks out of ${nm(p)} (${r1(b.left - pb.left)}/${r1(b.right - pb.right)})`); clipN++; }
    }
  });
  // ---- overlap of controls ----------------------------------------------------------
  const ctl = [...document.querySelectorAll('a[href],button,input:not([type=hidden]),select,textarea')].filter(e => vis(e) && !fixed(e) && !e.closest('.acts,.chips,.seg,.tbl'));
  let ov = 0;
  for (let i = 0; i < ctl.length && ov < 4; i++) for (let j = i + 1; j < ctl.length && ov < 4; j++) {
    const a = ctl[i], b = ctl[j]; if (a.contains(b) || b.contains(a)) continue;
    const x = a.getBoundingClientRect(), y = b.getBoundingClientRect();
    const w = Math.min(x.right, y.right) - Math.max(x.left, y.left), hh = Math.min(x.bottom, y.bottom) - Math.max(x.top, y.top);
    if (w > 3 && hh > 3) { add('clip', `${nm(a)} "${txt(a)}" overlaps ${nm(b)} "${txt(b)}"`); ov++; }
  }
  // ---- navigation -------------------------------------------------------------------
  const same = (els, what, dim) => { const v = els.map(e => e.getBoundingClientRect()[dim]); if (v.length > 1 && Math.max(...v) - Math.min(...v) > 1.5) add('nav', `${what} have different ${dim} (${r1(Math.min(...v))}..${r1(Math.max(...v))})`); };
  const sb = [...document.querySelectorAll('.sb .nv')].filter(vis);
  if (sb.length) {
    same(sb, 'sidebar items', 'height'); same(sb, 'sidebar items', 'left'); same(sb, 'sidebar items', 'width');
    const ix = sb.map(e => { const s = e.querySelector('svg'); return s ? s.getBoundingClientRect().left : null; }).filter(x => x !== null);
    if (ix.length > 1 && Math.max(...ix) - Math.min(...ix) > 1) add('nav', 'sidebar icons are not on one line');
    sb.forEach(e => { const s = e.querySelector('svg'); if (!s) return; const d = (s.getBoundingClientRect().top + s.getBoundingClientRect().height / 2) - (e.getBoundingClientRect().top + e.getBoundingClientRect().height / 2); if (Math.abs(d) > 1.5) add('nav', `sidebar icon "${txt(e)}" is ${r1(d)}px off centre`); });
  }
  const bn = [...document.querySelectorAll('.bn > a, .bn > button')].filter(vis);
  if (bn.length) {
    same(bn, 'bottom bar items', 'width'); same(bn, 'bottom bar items', 'height');
    bn.forEach(e => { const s = e.querySelector('svg'); if (!s) return; const eb = e.getBoundingClientRect(), sbb = s.getBoundingClientRect(); const d = (sbb.left + sbb.width / 2) - (eb.left + eb.width / 2); if (Math.abs(d) > 1.5) add('nav', `bottom bar icon "${txt(e)}" is ${r1(d)}px off centre`);
      const tn = [...e.childNodes].find(n => n.nodeType === 3 && n.nodeValue.trim()); if (tn) { const rg = document.createRange(); rg.selectNodeContents(tn); const tb = rg.getBoundingClientRect(); const d2 = (tb.left + tb.width / 2) - (eb.left + eb.width / 2); if (Math.abs(d2) > 2) add('nav', `bottom bar label "${txt(e)}" is ${r1(d2)}px off centre`); } });
    const barB = document.querySelector('.bn').getBoundingClientRect(); const first = bn[0].getBoundingClientRect(), last = bn[bn.length - 1].getBoundingClientRect();
    if (Math.abs((first.left - barB.left) - (barB.right - last.right)) > 2) add('nav', 'bottom bar items are not centred in the bar');
  }
  const seg = [...document.querySelectorAll('.seg a')].filter(vis);
  if (seg.length > 1) { same(seg, 'Personal/Shared switch buttons', 'height'); same(seg, 'Personal/Shared switch buttons', 'width'); }
  const tb = document.querySelector('.tb');
  if (tb && vis(tb)) {
    const tbb = tb.getBoundingClientRect();
    const items = [...tb.querySelectorAll(':scope > .ic, :scope > .me, :scope > .seg')].filter(vis);
    items.forEach(e => { const b = e.getBoundingClientRect(); if (b.right > tbb.right + 1 || b.left < tbb.left - 1) add('nav', `top bar item ${nm(e)} sticks out of the bar`); });
    const rowsY = new Set(items.filter(e => !e.matches('.seg') || W > 768).map(e => Math.round(e.getBoundingClientRect().top + e.getBoundingClientRect().height / 2)));
    const tt = tb.querySelector('.tt');
    if (W > 768 && items.length) { const cs = items.map(e => e.getBoundingClientRect().top + e.getBoundingClientRect().height / 2); if (Math.max(...cs) - Math.min(...cs) > 2) add('nav', 'top bar items are not on one centre line (' + r1(Math.min(...cs)) + '..' + r1(Math.max(...cs)) + ')'); }
  }
  // ---- page title / heading sizes ---------------------------------------------------
  const h1 = document.querySelector('h1'); if (h1) R.stat.h1 = r1(parseFloat(getComputedStyle(h1).fontSize));
  R.stat.t = [...document.querySelectorAll('.card > .t, .g.card > .t')].filter(vis).map(e => r1(parseFloat(getComputedStyle(e).fontSize)));
  R.stat.links = [...document.querySelectorAll('a[href]')].map(a => a.getAttribute('href'));
  return R;
}"""

rep = {}; stats = collections.defaultdict(lambda: collections.defaultdict(list))
with sync_playwright() as pw:
    br = pw.chromium.launch()
    for w in widths:
        mob = w < 700
        ctx = br.new_context(viewport={'width': w, 'height': 800}, service_workers='block', is_mobile=mob, device_scale_factor=1)
        if dark: ctx.add_cookies([{'name': 'ef_theme', 'value': 'dark', 'url': B}])
        p = ctx.new_page(); p.route('**/health.php*', lambda r: r.abort())
        p.goto(B + '/auth/login.php'); p.fill('input[name=email]', email); p.fill('input[name=password]', 'Test#12345'); p.click('button[type=submit]'); p.wait_for_load_state(); p.wait_for_timeout(800)
        queue = ['/dashboard.php', '/profile.php'] + (['/admin/index.php'] if admin else []); seen = set(); n = 0
        while queue and n < maxp:
            path = queue.pop(0)
            if path in seen: continue
            seen.add(path); n += 1
            try: p.goto(B + path, wait_until='load'); p.wait_for_timeout(450)
            except Exception: continue
            if '/auth/login' in p.url: continue
            res = None
            for _ in range(3):
                try: res = p.evaluate(MEASURE); break
                except Exception: p.wait_for_timeout(600)
            if not res: continue
            key = path.split('?')[0]
            rep.setdefault(key, {})[w] = {'issues': [f'{k}: {m}' for k, m in res['issues']], 'stat': res['stat']}
            for k, v in res['stat'].items(): stats[w][k].append((key, v))
            for l in res['stat']['links']:
                m = re.match(r'^(?:https?://127\.0\.0\.1:8080)?(/[^?#]*)', l) or re.match(r'^([\w./-]+\.php)', l)
                if not m: continue
                t = m.group(1)
                if not t.startswith('/'): t = os.path.normpath(os.path.join(os.path.dirname(path), t)); t = t if t.startswith('/') else '/' + t
                if SKIP.search(t) or t in seen or t in queue: continue
                queue.append(t)
        ctx.close()
    br.close()
sql("DELETE FROM users WHERE email LIKE 'seed%@shot.local'"); sql(f"DELETE FROM users WHERE email='{email}'")

# ---- consistency across pages (needs all pages) --------------------------------------
def mode(vals):
    c = collections.Counter(vals); return c.most_common(1)[0][0] if c else None
for w in widths:
    s = stats[w]
    gl = [(pg, v) for pg, v in s['gutterL'] if v is not None]; m = mode([v for _, v in gl])
    for pg, v in gl:
        if m is not None and abs(v - m) > 1: rep[pg][w]['issues'].append(f'gutter: content starts {v}px from the screen edge, other pages {m}px')
    for pg, v in s['gutterR']:
        mr = mode([x for _, x in s['gutterR'] if x is not None])
        if v is not None and mr is not None and abs(v - mr) > 1: rep[pg][w]['issues'].append(f'gutter: content ends {v}px from the right edge, other pages {mr}px')
    allgaps = [g for _, gs in s['gaps'] for g in gs]; mg = mode(allgaps)
    for pg, gs in s['gaps']:
        odd = sorted({g for g in gs if g != mg and g >= 0 and abs(g - mg) > 1})
        if odd: rep[pg][w]['issues'].append(f'gap: blocks are {odd}px apart, the usual gap is {mg}px')
    pads = [tuple(x[1:]) for _, ps in s['pad'] for x in ps]; mp = mode(pads)
    for pg, ps in s['pad']:
        bad = collections.Counter(f"{x[0]} {x[1:]}" for x in ps if tuple(x[1:]) != mp)
        for k, c in list(bad.items())[:2]: rep[pg][w]['issues'].append(f'padding: {c}x {k} (usual card padding {list(mp)})')
    byb = collections.defaultdict(list)
    for pg, bs in s['btn']:
        for sig, hgt, name in bs: byb[sig].append((pg, hgt, name))
    for sig, items in byb.items():
        mh = mode([hh for _, hh, _ in items])
        for pg, hh, name in items:
            if abs(hh - mh) > 1.5: rep[pg][w]['issues'].append(f'btn: {name} is {hh}px high, other "{sig}" buttons {mh}px')
    byr = collections.defaultdict(list)
    for pg, rs in s['radius']:
        for kind, rv in rs: byr[kind].append((pg, rv))
    for kind, items in byr.items():
        mr = mode([v for _, v in items])
        for pg, v in items:
            if abs(v - mr) > 1.5 and kind != 'btn-md' and kind != 'btn-sm': rep[pg][w]['issues'].append(f'radius: a {kind} has radius {v}px, usual {mr}px')
    hs = mode([v for _, v in s['h1'] if v is not None]) if s['h1'] else None
    for pg, v in s['h1']:
        if hs is not None and abs(v - hs) > 0.5: rep[pg][w]['issues'].append(f'title: page heading is {v}px, other pages {hs}px')
    ts = mode([x for _, v in s['t'] for x in v])
    for pg, v in s['t']:
        bad = sorted({x for x in v if ts is not None and abs(x - ts) > 0.5})
        if bad: rep[pg][w]['issues'].append(f'title: card titles are {bad}px, usual {ts}px')

total = 0; cats = collections.Counter(); out = []
for pg in sorted(rep):
    for w in widths:
        d = rep[pg].get(w)
        if not d: continue
        uniq = list(dict.fromkeys(d['issues']))
        for i in uniq:
            total += 1; cats[i.split(':')[0]] += 1; out.append(f'{pg} [{w}] {i}')
for line in out: print(line)
print('\nBy kind: ' + ', '.join(f'{k} {v}' for k, v in cats.most_common()))
print(f'{len(rep)} pages x {len(widths)} widths measured, {total} findings')
if jf: json.dump({pg: {str(w): {'issues': v['issues']} for w, v in ws.items()} for pg, ws in rep.items()}, open(jf, 'w'), indent=1)
if limit is not None and total > limit: raise SystemExit(1)
