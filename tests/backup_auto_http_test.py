"""
Part 52b: automatic daily backup when staff open the admin overview. LOCAL server + TEST database.
Usage: php -S 127.0.0.1:8080 -t . router.php   then   python3 tests/backup_auto_http_test.py
"""
import re, subprocess, os, glob, time, requests
B = 'http://127.0.0.1:8080'
fails = 0
def sql(q): return subprocess.run(['mysql', '--socket=/run/mysqld/mysqld.sock', '-uroot', 'expenseflow_test', '-N', '-e', q], capture_output=True, text=True).stdout.strip()
def ok(c, m):
    global fails
    if not c: fails += 1; print('FAIL', m)
def files(): return sorted(glob.glob('storage/backups/backup-*'))
h = subprocess.run(['php', '-r', 'echo password_hash("Test#12345",PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout
for f in files(): os.remove(f)
sql("DELETE FROM users WHERE email IN ('ba@ba.local','bb@bb.local')")
sql(f"INSERT INTO users(name,email,password,role,is_admin) VALUES ('ba','ba@ba.local','{h}','super',1),('bb','bb@bb.local','{h}','user',0)")
def login(email):
    s = requests.Session()
    t = re.search(r'name="csrf_token" value="([^"]+)"', s.get(B + '/auth/login.php').text).group(1)
    s.post(B + '/auth/login.php', data={'email': email, 'password': 'Test#12345', 'csrf_token': t}); return s
s = login('ba@ba.local')
s.get(B + '/admin/index.php')
ok(len(files()) <= 1, 'sanity')
for f in files(): os.remove(f)
ok(s.get(B + '/admin/index.php').status_code == 200 and len(files()) == 1, 'opening the overview with no backup creates one')
first = files()[0]
s.get(B + '/admin/index.php'); ok(files() == [first], 'a fresh backup is not repeated')
old = time.time() - 30 * 3600; os.utime(first, (old, old))
s.get(B + '/admin/index.php'); ok(len(files()) == 2, 'a backup older than a day is replaced by a new one')
ok(sql("SELECT COUNT(*) FROM user_logs WHERE details LIKE 'Automatic daily database backup%'") != '0', 'the automatic backup is written to the audit log')
for f in files(): os.remove(f)
u = login('bb@bb.local'); u.get(B + '/admin/index.php'); u.get(B + '/dashboard.php')
ok(files() == [], 'ordinary users never trigger a backup')
for f in files(): os.remove(f)
sql("DELETE FROM users WHERE email IN ('ba@ba.local','bb@bb.local')")
print('backup auto http tests:', 'ALL PASSED' if not fails else f'{fails} FAILED')
