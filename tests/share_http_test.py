"""
Part 55: "Share to ExpenseFlow". quick-add.php fills the box from ?sms= (nothing saved), and the Android project declares the share target.
LOCAL server + TEST database. Usage: python3 tests/share_http_test.py
"""
import re, subprocess, requests, urllib.parse
B = 'http://127.0.0.1:8080'
fails = 0
def sql(q): return subprocess.run(['mysql', '--socket=/run/mysqld/mysqld.sock', '-uroot', 'expenseflow_test', '-N', '-e', q], capture_output=True, text=True).stdout.strip()
def ok(c, m):
    global fails
    if not c: fails += 1; print('FAIL', m)
h = subprocess.run(['php', '-r', 'echo password_hash("Test#12345",PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout
sql("DELETE FROM users WHERE email='sh@sh.local'")
sql(f"INSERT INTO users(name,email,password) VALUES ('Sh','sh@sh.local','{h}')")
uid = sql("SELECT id FROM users WHERE email='sh@sh.local'")
s = requests.Session()
t = re.search(r'name="csrf_token" value="([^"]+)"', s.get(B + '/auth/login.php').text).group(1)
s.post(B + '/auth/login.php', data={'email': 'sh@sh.local', 'password': 'Test#12345', 'csrf_token': t})
msg = 'Rs. 1,500.00 debited from A/C 1234 at FOODPANDA <script>alert(1)</script>'
r = s.get(B + '/quick-add.php?sms=' + urllib.parse.quote(msg))
ok(r.status_code == 200 and 'Rs. 1,500.00 debited from A/C 1234 at FOODPANDA' in r.text, 'the shared text fills the box')
ok('<script>alert(1)</script>' not in r.text, 'the shared text is escaped')
ok('Rs. 1,500.00' in re.search(r'<textarea[^>]*>(.*?)</textarea>', r.text, re.S).group(1), 'it is inside the textarea')
ok(sql(f"SELECT COUNT(*) FROM expenses WHERE user_id={uid}") == '0', 'nothing is saved by just opening the link')
long = s.get(B + '/quick-add.php?sms=' + 'a' * 5000).text
ok(len(re.search(r'<textarea[^>]*>(.*?)</textarea>', long, re.S).group(1)) <= 600, 'long text is cut to 600 characters')
ok(s.get(B + '/quick-add.php?sms[]=x').status_code == 200, 'an array value is ignored safely')
man = open('apps/android/app/src/main/AndroidManifest.xml').read()
ok('android.intent.action.SEND' in man and 'android:mimeType="text/plain"' in man, 'Android manifest declares the share target')
jv = open('apps/android/app/src/main/java/pk/expenseflow/app/MainActivity.java').read()
ok('shareUrl(getIntent())' in jv and 'shareUrl(i)' in jv and '/quick-add.php?sms=' in jv, 'MainActivity handles a shared text at start and when already open')
sql("DELETE FROM users WHERE email='sh@sh.local'")
print('share tests:', 'ALL PASSED' if not fails else f'{fails} FAILED')
