"""
Reminders page: create, bell notification, dashboard card, paid (+expense), ownership.
Usage: php -S 127.0.0.1:8080 -t . router.php   then   python3 tests/reminders_http_test.py   (TEST database)
"""
import re, subprocess, requests, datetime
B = 'http://127.0.0.1:8080'
fails = 0
def sql(q): return subprocess.run(['mysql', '--socket=/run/mysqld/mysqld.sock', '-uroot', 'expenseflow_test', '-N', '-e', q], capture_output=True, text=True).stdout.strip()
def ok(c, m):
    global fails
    if not c: fails += 1; print('FAIL', m)
def token(html): return re.search(r'name="csrf_token" value="([^"]+)"', html).group(1)
h = subprocess.run(['php', '-r', 'echo password_hash("Test#12345",PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout
requests.get(B + '/auth/login.php')
sql("DELETE FROM users WHERE email IN ('rm1@r.local','rm2@r.local')"); sql("DELETE FROM rate_limit_hits")
for n in (1, 2): sql(f"INSERT INTO users(name,email,password) VALUES ('Rem {n}','rm{n}@r.local','{h}')")
uid = sql("SELECT id FROM users WHERE email='rm1@r.local'")
def login(n):
    s = requests.Session()
    s.post(B + '/auth/login.php', data={'email': f'rm{n}@r.local', 'password': 'Test#12345', 'csrf_token': token(s.get(B + '/auth/login.php').text)})
    return s
from zoneinfo import ZoneInfo
_today = datetime.datetime.now(ZoneInfo('Asia/Karachi')).date()  # the app runs in Karachi time
iso = lambda d: (_today + datetime.timedelta(days=d)).isoformat()

r = requests.get(B + '/reminders.php', allow_redirects=False)
ok(r.status_code == 302 and 'login.php' in r.headers.get('Location', ''), 'signed-out visitor sent to login')
s = login(1)
page = s.get(B + '/reminders.php').text
ok('No reminders yet' in page and 'New reminder' in page, 'empty state')
ok('reminders.php' in s.get(B + '/dashboard.php').text, 'menu links to Reminders')
t = token(page)
s.post(B + '/reminders.php', data={'title': 'Rent', 'due_date': iso(2), 'currency': 'PKR', 'csrf_token': 'bad'})
ok(sql(f"SELECT COUNT(*) FROM reminders WHERE user_id={uid}") == '0', 'bad CSRF creates nothing')
r = s.post(B + '/reminders.php', data={'title': '', 'due_date': iso(2), 'currency': 'PKR', 'csrf_token': t})
ok('Give the reminder a name' in r.text, 'empty name refused')
r = s.post(B + '/reminders.php', data={'title': '<i>Rent</i>', 'due_date': iso(2), 'amount': '25000', 'currency': 'PKR', 'monthly': '1', 'csrf_token': t}, allow_redirects=False)
ok(r.status_code == 302 and sql(f"SELECT COUNT(*) FROM reminders WHERE user_id={uid}") == '1', 'created, redirect after POST')
page = s.get(B + '/reminders.php').text
ok('Reminder saved' in page and '&lt;i&gt;Rent' in page and '<i>Rent</i>' not in page, 'message shown, title escaped')
ok('Due in 2 days' in page and 'every month' in page and 'PKR 25,000.00' in page, 'due text, monthly and amount shown')
ok('is due in 2 days' in s.get(B + '/activity/notifications.php').text, 'bell notification (due soon)')
ok('Reminders' in s.get(B + '/activity/notifications.php').text, 'notification labelled Reminders')
ok('id="reminder-card"' in s.get(B + '/dashboard.php').text, 'dashboard card shown')
s.get(B + '/reminders.php'); s.get(B + '/dashboard.php')
ok(sql(f"SELECT COUNT(*) FROM activity_notifications n JOIN activity_events e ON e.id=n.event_id WHERE n.user_id={uid} AND e.kind='reminder_soon'") == '1', 'notification sent only once')

rid = sql(f"SELECT id FROM reminders WHERE user_id={uid}")
o = login(2)
ok('&lt;i&gt;Rent' not in o.get(B + '/reminders.php').text, 'other person does not see it')
o.post(B + '/reminders.php', data={'do': 'paid', 'id': rid, 'record': '1', 'csrf_token': token(o.get(B + '/reminders.php').text)})
o.post(B + '/reminders.php', data={'do': 'delete', 'id': rid, 'csrf_token': token(o.get(B + '/reminders.php').text)})
ok(sql(f"SELECT due_date FROM reminders WHERE id={rid}") == iso(2) and sql(f"SELECT COUNT(*) FROM expenses WHERE user_id={uid}") == '0', 'other person cannot pay or delete it')

t = token(s.get(B + '/reminders.php').text)
s.post(B + '/reminders.php', data={'do': 'paid', 'id': rid, 'record': '1', 'csrf_token': t})
due = sql(f"SELECT due_date FROM reminders WHERE id={rid}")
ok(due > iso(2) and sql(f"SELECT COUNT(*) FROM expenses WHERE user_id={uid} AND title='<i>Rent</i>' AND category='Bills'") == '1', 'paid: next month set and expense saved: ' + due)
s.post(B + '/reminders.php', data={'do': 'paid', 'id': rid, 'csrf_token': t})
ok(sql(f"SELECT COUNT(*) FROM expenses WHERE user_id={uid}") == '1', 'paid without the box does not add another expense')
s.post(B + '/reminders.php', data={'do': 'delete', 'id': rid, 'csrf_token': t})
ok(sql(f"SELECT COUNT(*) FROM reminders WHERE id={rid}") == '0', 'owner can remove')

sql(f"DELETE FROM expenses WHERE user_id={uid}")
sql("DELETE FROM users WHERE email IN ('rm1@r.local','rm2@r.local')")
print('reminders http tests:', 'ALL PASSED' if not fails else f'{fails} FAILED')
raise SystemExit(1 if fails else 0)
