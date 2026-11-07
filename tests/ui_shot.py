"""Screenshot chosen signed-in pages so a person can LOOK at them (the audit tool only checks rules).
Usage: python3 tests/ui_shot.py /search.php /dashboard.php [--dark] [--urdu] [--widths=1280,390] [--out=/tmp/shots]
Needs the same local server and test database as ui_audit.py."""
import subprocess, sys, os, re
from playwright.sync_api import sync_playwright
B = 'http://127.0.0.1:8080'
paths = [a for a in sys.argv[1:] if a.startswith('/')]
dark = '--dark' in sys.argv; urdu = '--urdu' in sys.argv
widths = next((a[9:] for a in sys.argv if a.startswith('--widths=')), '1280,390').split(',')
out = next((a[6:] for a in sys.argv if a.startswith('--out=')), '/tmp/shots')
def sql(q): return subprocess.run(['mysql', '--socket=/run/mysqld/mysqld.sock', '-uroot', 'expenseflow_test', '-N', '-e', q], capture_output=True, text=True).stdout.strip()
h = subprocess.run(['php', '-r', 'echo password_hash("Test#12345",PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout
email = 'shot@audit.local'
sql(f"DELETE FROM users WHERE email='{email}'"); sql("DELETE FROM rate_limit_hits")
sql(f"INSERT INTO users(name,email,password,role,is_admin) VALUES ('Muhammad Anas','{email}','{h}','user',0)")
uid = sql(f"SELECT id FROM users WHERE email='{email}'")
sql(f"INSERT INTO user_settings (user_id, savings_goal, notifications, setup_done) VALUES ({uid}, 20, 1, 1)")
sql(f"INSERT INTO income (user_id,title,amount,currency,amount_pkr,payment_method,transaction_date) VALUES ({uid},'Salary',50000,'PKR',50000,'Bank',NOW())")
for t, a, c in (('Lunch', 450, 'Food'), ('Uber', 900, 'Transport')):
    sql(f"INSERT INTO expenses (user_id,title,amount,currency,amount_pkr,category,payment_method,transaction_date) VALUES ({uid},'{t}',{a},'PKR',{a},'{c}','Cash',NOW())")
os.makedirs(out, exist_ok=True)
with sync_playwright() as p:
    br = p.chromium.launch()
    for w in widths:
        ctx = br.new_context(viewport={'width': int(w), 'height': 820}, service_workers='block', is_mobile=int(w) < 600)
        if dark: ctx.add_cookies([{'name': 'ef_theme', 'value': 'dark', 'url': B}])
        if urdu: ctx.add_cookies([{'name': 'ef_lang', 'value': 'ur', 'url': B}])
        pg = ctx.new_page()
        pg.goto(B + '/auth/login.php'); pg.fill('input[name=email]', email); pg.fill('input[name=password]', 'Test#12345'); pg.click('button[type=submit]'); pg.wait_for_load_state()
        for path in paths:
            pg.goto(B + path); pg.wait_for_timeout(1500)
            pg.screenshot(path=f"{out}/{re.sub(r'[^a-z0-9]+', '-', path.lower()).strip('-')}-{w}.png")
    br.close()
sql(f"DELETE FROM users WHERE email='{email}'")
print('saved to', out)
