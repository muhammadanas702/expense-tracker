"""
Part 52: encrypted download + "no recent backup" notice. LOCAL server + TEST database.
Usage: php -S 127.0.0.1:8080 -t . router.php   then   python3 tests/backup_offsite_http_test.py
"""
import re, subprocess, os, glob, time, requests
B = 'http://127.0.0.1:8080'
fails = 0
def sql(q): return subprocess.run(['mysql', '--socket=/run/mysqld/mysqld.sock', '-uroot', 'expenseflow_test', '-N', '-e', q], capture_output=True, text=True).stdout.strip()
def ok(c, m):
    global fails
    if not c: fails += 1; print('FAIL', m)
h = subprocess.run(['php', '-r', 'echo password_hash("Test#12345",PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout
for f in glob.glob('storage/backups/backup-*'): os.remove(f)
sql("DELETE FROM users WHERE email='bo@bo.local'")
sql(f"INSERT INTO users(name,email,password,role,is_admin) VALUES ('bo','bo@bo.local','{h}','super',1)")
s = requests.Session()
def tok(p): return re.search(r'name="csrf_token" value="([^"]+)"', s.get(B + p).text).group(1)
s.post(B + '/auth/login.php', data={'email': 'bo@bo.local', 'password': 'Test#12345', 'csrf_token': tok('/auth/login.php')})
BP, BA = '/admin/backups.php', '/admin/backup-action.php'
ok('Off-site copy needed' in s.get(B + BP).text and 'No backup exists yet' in s.get(B + BP).text, 'notice when there is no backup')
s.post(B + BA, data={'do': 'create', 'csrf_token': tok(BP)})
files = glob.glob('storage/backups/backup-*'); ok(len(files) == 1, 'backup created')
name = os.path.basename(files[0])
ok('Off-site copy needed' not in s.get(B + BP).text, 'no notice for a fresh backup')
old = time.time() - 9 * 86400; os.utime(files[0], (old, old))
ok('9 days old' in s.get(B + BP).text, 'notice when the newest backup is 9 days old')
ok('Download encrypted' in s.get(B + BP).text, 'page offers the encrypted download')
r = s.post(B + BA, data={'do': 'download_enc', 'name': name, 'pass': 'short', 'csrf_token': tok(BP)}, allow_redirects=False)
ok(r.status_code in (302, 303) and 'attachment' not in r.headers.get('Content-Disposition', ''), 'short passphrase gives no file')
r = s.post(B + BA, data={'do': 'download_enc', 'name': name, 'pass': 'long enough pass', 'csrf_token': tok(BP)})
ok(r.status_code == 200 and name + '.enc' in r.headers.get('Content-Disposition', '') and r.content[:5] == b'EFBK1', 'encrypted file downloads')
open('/tmp/_bk.enc', 'wb').write(r.content)
d = subprocess.run(['php', '-r', 'require "modules/backup/lib.php"; $p=backup_decrypt(file_get_contents("/tmp/_bk.enc"),"long enough pass"); echo $p===null?"NULL":md5($p);'], capture_output=True, text=True).stdout
ok(d == __import__('hashlib').md5(open(files[0], 'rb').read()).hexdigest(), 'decrypts to exactly the stored backup')
r = s.post(B + BA, data={'do': 'download_enc', 'name': '../../config/db.php', 'pass': 'long enough pass', 'csrf_token': tok(BP)}, allow_redirects=False)
ok('EFBK1' not in r.text, 'path tricks refused')
ok(requests.get(B + '/tools/decrypt-backup.php').status_code == 404, 'the decrypt tool is closed to the web')
for f in glob.glob('storage/backups/backup-*'): os.remove(f)
sql("DELETE FROM users WHERE email='bo@bo.local'"); os.remove('/tmp/_bk.enc')
print('backup offsite http tests:', 'ALL PASSED' if not fails else f'{fails} FAILED')
