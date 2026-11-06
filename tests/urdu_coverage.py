"""
Part 62: which English text is still left on the pages when the language is Urdu? Crawls the signed-in app (links from the dashboard, settings hub and
menu, two levels) in a real browser and lists every visible text / placeholder / title that still has English words.
Usage: php -S 127.0.0.1:8080 -t . router.php (EXPENSEFLOW_2FA_OFF=1)  then  python3 tests/urdu_coverage.py [--admin] [--max=120] [--fail-over=N]
Uses the TEST database. Prints one line per page and the strings; exit code 1 when more than --fail-over strings are left.
"""
import re, subprocess, sys, json
from playwright.sync_api import sync_playwright
B = 'http://127.0.0.1:8080'
admin = '--admin' in sys.argv
maxp = next((int(a[6:]) for a in sys.argv if a.startswith('--max=')), 120)
limit = next((int(a[12:]) for a in sys.argv if a.startswith('--fail-over=')), None)
def sql(q): return subprocess.run(['mysql', '--socket=/run/mysqld/mysqld.sock', '-uroot', 'expenseflow_test', '-N', '-e', q], capture_output=True, text=True).stdout.strip()
h = subprocess.run(['php', '-r', 'echo password_hash("Test#12345",PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout
email = 'cov@cov.local'
sql(f"DELETE FROM users WHERE email='{email}'"); sql("DELETE FROM rate_limit_hits")
role = "'super',1" if admin else "'user',0"
sql(f"INSERT INTO users(name,email,password,role,is_admin) VALUES ('Cov User','{email}','{h}',{role})")
uid = sql(f"SELECT id FROM users WHERE email='{email}'")
sql(f"INSERT INTO user_settings (user_id, savings_goal, notifications, setup_done) VALUES ({uid}, 20, 1, 1)")
sql(f"INSERT INTO incomes (user_id,title,amount,currency,category,payment_method,transaction_date,year_month) VALUES ({uid},'Salary',50000,'PKR','Salary','Bank',NOW(),DATE_FORMAT(NOW(),'%Y-%m'))")
sql(f"INSERT INTO expenses (user_id,title,amount,currency,category,payment_method,transaction_date,year_month) VALUES ({uid},'Lunch',450,'PKR','Food','Cash',NOW(),DATE_FORMAT(NOW(),'%Y-%m'))")
SKIP = re.compile(r'logout|delete|export|download|action|remove|\.pdf|\.csv|\.apk|\.exe|mailto:|tel:|^#|javascript:|auth/|github\.com|google|login-code|backup', re.I)
JS = """() => {
  const out = [];
  const seen = new Set();
  const add = (s, kind) => { s = (s || '').replace(/\\s+/g, ' ').trim(); if (s && !seen.has(kind + s)) { seen.add(kind + s); out.push([kind, s]); } };
  const w = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  let n;
  while ((n = w.nextNode())) {
    const el = n.parentElement; if (!el || ['SCRIPT','STYLE','NOSCRIPT','TEMPLATE'].includes(el.tagName)) continue;
    const cs = getComputedStyle(el); if (cs.display === 'none' || cs.visibility === 'hidden') continue;
    add(n.nodeValue, 'text');
  }
  document.querySelectorAll('[placeholder],[title],[aria-label],input[type=submit],input[type=button]').forEach(e => {
    add(e.getAttribute('placeholder'), 'placeholder'); add(e.getAttribute('title'), 'title'); add(e.getAttribute('aria-label'), 'aria');
    if (e.tagName === 'INPUT' && /submit|button/.test(e.type)) add(e.value, 'button');
  });
  document.querySelectorAll('select option').forEach(o => add(o.textContent, 'option'));
  return out;
}"""
IGNORE = re.compile(r'^(ExpenseFlow|PKR|USD|EUR|GBP|AED|SAR|INR|Rs\.?|v?\d[\d.,\s]*|[A-Z]{2,5}|[^A-Za-z]*)$')
def english(s):
    s2 = re.sub(r'https?://\S+|[\w.+-]+@[\w-]+\.[\w.]+|ExpenseFlow|Cov User|Salary|Lunch|PKR|USD|[A-Z]{3}\b', ' ', s)
    return len(re.findall(r'[A-Za-z]{2,}', s2)) > 0 and not IGNORE.match(s)
left = {}
with sync_playwright() as pw:
    br = pw.chromium.launch(); ctx = br.new_context(viewport={'width': 1100, 'height': 900}, service_workers='block'); p = ctx.new_page()
    p.goto(B + '/auth/login.php?lang=ur'); p.fill('input[name=email]', email); p.fill('input[name=password]', 'Test#12345'); p.click('button[type=submit]'); p.wait_for_load_state(); p.wait_for_timeout(800)
    queue = ['/dashboard.php', '/settings/index.php', '/more.php'] + (['/admin/index.php'] if admin else [])
    seen, done = set(), 0
    while queue and done < maxp:
        path = queue.pop(0)
        if path in seen: continue
        seen.add(path)
        try:
            r = p.goto(B + path, wait_until='load'); p.wait_for_timeout(250)
        except Exception as e:
            continue
        if not r or r.status >= 400 or '/auth/login' in p.url: continue
        done += 1
        items = [x for x in p.evaluate(JS) if english(x[1])]
        if items: left[path] = items
        for href in p.evaluate("Array.from(document.querySelectorAll('a[href]')).map(a => a.getAttribute('href'))"):
            if not href or SKIP.search(href): continue
            u = re.sub(r'#.*$', '', href)
            if u.startswith('http'):
                if not u.startswith(B): continue
                u = u[len(B):]
            elif not u.startswith('/'):
                base = p.url[len(B):].rsplit('/', 1)[0]
                u = (base + '/' + u)
                while '/../' in u: u = re.sub(r'/[^/]+/\.\./', '/', u, count=1)
                u = u.replace('/./', '/')
            u = re.sub(r'\?.*$', '', u) if 'id=' not in u else u
            if u.endswith('.php') and u not in seen and u not in queue: queue.append(u)
    br.close()
sql(f"DELETE FROM users WHERE email='{email}'")
total = {}
for path, items in left.items():
    for kind, s in items: total.setdefault(s, []).append(path)
if '--json' in sys.argv:
    print(json.dumps(total, ensure_ascii=False, indent=0))
else:
    print(f'pages visited: {done}; pages with English left: {len(left)}; different texts left: {len(total)}')
    for s, ps in sorted(total.items(), key=lambda x: -len(x[1]))[:400]:
        print(f'{len(ps):3d}  {s[:110]}   [{ps[0]}]')
if limit is not None and len(total) > limit: sys.exit(1)
