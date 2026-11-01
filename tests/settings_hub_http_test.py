"""
Profile & Settings hub: every section is its own page, each saves what it should, and nothing needs a sign-in it should not.
Usage: php -S 127.0.0.1:8080 -t . router.php   then   python3 tests/settings_hub_http_test.py   (TEST database)
"""
import re, subprocess, requests, sys
B = 'http://127.0.0.1:8080'
fails = 0
def sql(q): return subprocess.run(['mysql', '--socket=/run/mysqld/mysqld.sock', '-uroot', 'expenseflow_test', '-N', '-e', q], capture_output=True, text=True).stdout.strip()
def ok(c, m):
    global fails
    if not c: fails += 1; print('FAIL', m)
def token(html): return re.search(r'name="csrf_token" value="([^"]+)"', html).group(1)
h = subprocess.run(['php', '-r', 'echo password_hash("Test#12345",PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout
requests.get(B + '/auth/login.php')
sql("DELETE FROM users WHERE email='hub1@hub.local'"); sql("DELETE FROM rate_limit_hits")
sql(f"INSERT INTO users(name,email,password) VALUES ('Hub One','hub1@hub.local','{h}')")
uid = sql("SELECT id FROM users WHERE email='hub1@hub.local'")
SECTIONS = ['account', 'security', 'preferences', 'notifications', 'apps', 'data']

anon = requests.Session()
for p in ['/profile.php'] + ['/settings/%s.php' % k for k in SECTIONS]:
    r = anon.get(B + p, allow_redirects=False)
    ok(r.status_code == 302 and 'login.php' in r.headers.get('Location', ''), 'signed-out visitor is sent to login: ' + p)

s = requests.Session()
r = s.post(B + '/auth/login.php', data={'email': 'hub1@hub.local', 'password': 'Test#12345', 'csrf_token': token(s.get(B + '/auth/login.php').text)}, allow_redirects=False)
ok(r.status_code in (302, 303), 'login works')

hub = s.get(B + '/profile.php').text
for k in SECTIONS:
    ok('/settings/%s.php' % k in hub, 'hub links to ' + k)
ok('Help &amp; support' in hub and 'Log out' in hub and 'Hub One' in hub, 'hub shows name, help and log out')
ok('Change password' not in hub and 'Danger zone' not in hub and 'name="savings_goal"' not in hub, 'the hub itself is not a wall of forms')
ok('Admin panel' not in hub, 'no admin card for a normal user')

for k in SECTIONS:
    r = s.get(B + '/settings/%s.php' % k)
    ok(r.status_code == 200 and 'class="crumb"' in r.text and 'snl on' in r.text, 'section page opens with breadcrumb + menu: ' + k)
    ok(r.text.count('<h1') <= 1, 'one heading: ' + k)

# account
t = token(s.get(B + '/settings/account.php').text)
r = s.post(B + '/settings/account.php', data={'name': 'Hub Renamed', 'csrf_token': t}, allow_redirects=False)
ok(r.status_code == 302 and sql(f"SELECT name FROM users WHERE id={uid}") == 'Hub Renamed', 'account: name saved, redirect after POST')
ok('Profile updated' in s.get(B + '/settings/account.php').text, 'account: success message shown after the redirect')
r = s.post(B + '/settings/account.php', data={'name': 'x', 'csrf_token': 'bad'}, allow_redirects=False)
ok(sql(f"SELECT name FROM users WHERE id={uid}") == 'Hub Renamed', 'account: bad CSRF changes nothing')
s.post(B + '/settings/account.php', data={'name': '   ', 'csrf_token': t})
ok(sql(f"SELECT name FROM users WHERE id={uid}") == 'Hub Renamed', 'account: empty name refused')

# preferences + notifications are independent
t = token(s.get(B + '/settings/preferences.php').text)
s.post(B + '/settings/preferences.php', data={'savings_goal': '35', 'csrf_token': t})
ok(sql(f"SELECT savings_goal FROM user_settings WHERE user_id={uid}") == '35', 'preferences: savings goal saved')
s.post(B + '/settings/preferences.php', data={'savings_goal': '500', 'csrf_token': t})
ok(sql(f"SELECT savings_goal FROM user_settings WHERE user_id={uid}") == '35', 'preferences: out of range refused')
t = token(s.get(B + '/settings/notifications.php').text)
s.post(B + '/settings/notifications.php', data={'csrf_token': t})  # switch off (unchecked box sends nothing)
ok(sql(f"SELECT notifications FROM user_settings WHERE user_id={uid}") == '0' and sql(f"SELECT savings_goal FROM user_settings WHERE user_id={uid}") == '35', 'notifications: off, goal untouched')
s.post(B + '/settings/notifications.php', data={'notifications': '1', 'csrf_token': t})
ok(sql(f"SELECT notifications FROM user_settings WHERE user_id={uid}") == '1', 'notifications: on again')

# security
t = token(s.get(B + '/settings/security.php').text)
s.post(B + '/settings/security.php', data={'update_password': '1', 'current_password': 'wrong', 'new_password': 'Newpass#1234', 'confirm_password': 'Newpass#1234', 'csrf_token': t}, allow_redirects=False)
ok('Current password is incorrect' in s.get(B + '/settings/security.php').text, 'security: wrong current password explained')
t = token(s.get(B + '/settings/security.php').text)
s.post(B + '/settings/security.php', data={'update_password': '1', 'current_password': 'Test#12345', 'new_password': 'weak', 'confirm_password': 'weak', 'csrf_token': t}, allow_redirects=False)
ok('Password must' in s.get(B + '/settings/security.php').text, 'security: weak password explained')
t = token(s.get(B + '/settings/security.php').text)
s.post(B + '/settings/security.php', data={'update_password': '1', 'current_password': 'Test#12345', 'new_password': 'Newpass#1234', 'confirm_password': 'Newpass#1234', 'csrf_token': t}, allow_redirects=False)
ok('Password changed successfully' in s.get(B + '/settings/security.php').text, 'security: password changed')
s2 = requests.Session()
r = s2.post(B + '/auth/login.php', data={'email': 'hub1@hub.local', 'password': 'Newpass#1234', 'csrf_token': token(s2.get(B + '/auth/login.php').text)}, allow_redirects=False)
ok(r.status_code in (302, 303) and 'login.php' not in r.headers.get('Location', ''), 'security: the new password signs in')

# data + apps
d = s.get(B + '/settings/data.php').text
ok('Reset all data' in d and 'Delete account' in d and 'view-report.php' in d, 'data: export, reset and delete are together')
a = s.get(B + '/settings/apps.php').text
ok('id="install-row"' in a and 'id="app-dl"' in a and 'id="app-win"' in a, 'apps: install and download rows')

# admin card for an admin
sql(f"UPDATE users SET is_admin=1 WHERE id={uid}")
ok('Admin panel' in s.get(B + '/profile.php').text, 'admin sees the Admin panel card')
sql("DELETE FROM users WHERE email='hub1@hub.local'")
print('settings_hub_http_test: ALL PASSED' if not fails else f'{fails} FAILED')
sys.exit(1 if fails else 0)
