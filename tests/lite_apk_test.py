"""
Part 58: Lite APK (no SMS permission). Static checks of the Android project + release workflow, and Quick add with a fake Lite bridge.
LOCAL server + TEST database. Usage: python3 tests/lite_apk_test.py
"""
import re, subprocess, requests, yaml
from playwright.sync_api import sync_playwright
B = 'http://127.0.0.1:8080'
fails = 0
def sql(q): return subprocess.run(['mysql', '--socket=/run/mysqld/mysqld.sock', '-uroot', 'expenseflow_test', '-N', '-e', q], capture_output=True, text=True).stdout.strip()
def ok(c, m):
    global fails
    if not c: fails += 1; print('FAIL', m)
g = open('apps/android/app/build.gradle').read()
ok("flavorDimensions 'sms'" in g and 'full {' in g and 'lite {' in g, 'gradle has the full and lite flavors')
lm = open('apps/android/app/src/lite/AndroidManifest.xml').read()
ok(all(x in lm for x in ('READ_SMS', 'RECEIVE_SMS', '.SmsReceiver')) and lm.count('tools:node="remove"') == 3, 'lite manifest removes both SMS permissions and the receiver')
mm = open('apps/android/app/src/main/AndroidManifest.xml').read()
ok('READ_SMS' in mm and 'SmsReceiver' in mm, 'the main (full) manifest keeps SMS')
r = open('.github/workflows/release.yml').read(); y = yaml.safe_load(r)
ok('assembleFullRelease assembleLiteRelease' in r and 'ExpenseFlow-Lite.apk' in r and 'app-lite-release.apk' in r, 'release workflow builds and publishes both APKs')
jv = open('apps/android/app/src/main/java/pk/expenseflow/app/MainActivity.java').read()
ok('smsSupported() { return declaresSms(); }' in jv and 'contains("lite") == !declaresSms()' in jv, 'bridge and updater know the Lite build')
for f in ('assets/quick-add.js', 'assets/sms-auto.js', 'assets/sms-check.js'):
    ok('smsSupported()' in open(f).read() and 'false' in open(f).read(), f + ' respects a Lite app')
# links
h = subprocess.run(['php', '-r', 'echo password_hash("Test#12345",PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout
sql("DELETE FROM users WHERE email='lt@lt.local'"); sql(f"INSERT INTO users(name,email,password) VALUES ('Lt','lt@lt.local','{h}')")
ok('ExpenseFlow-Lite.apk' in requests.get(B + '/').text, 'landing page offers the Lite APK')
s = requests.Session()
t = re.search(r'name="csrf_token" value="([^"]+)"', s.get(B + '/auth/login.php').text).group(1)
s.post(B + '/auth/login.php', data={'email': 'lt@lt.local', 'password': 'Test#12345', 'csrf_token': t})
ok('ExpenseFlow-Lite.apk' in s.get(B + '/settings/apps.php').text, 'Settings > Apps offers the Lite APK')
with sync_playwright() as pw:
    br = pw.chromium.launch(); ctx = br.new_context(service_workers='block'); p = ctx.new_page()
    p.add_init_script("window.EFApp={smsSupported:function(){return false;},version:function(){return '1.0.23';}};")
    p.goto(B + '/auth/login.php'); p.fill('input[name=email]', 'lt@lt.local'); p.fill('input[name=password]', 'Test#12345'); p.click('button[type=submit]'); p.wait_for_timeout(1200)
    p.goto(B + '/quick-add.php'); p.wait_for_timeout(600)
    ok(not p.locator('#sms-device').is_visible(), 'Lite: no SMS-reading card')
    ok('Lite app' in p.inner_text('#sms-nodevice') and 'Share' in p.inner_text('#sms-nodevice'), 'Lite: explains Share and paste')
    ok(p.locator('#sms').is_visible(), 'Lite: the paste form works')
    br.close()
sql("DELETE FROM users WHERE email='lt@lt.local'")
print('lite apk tests:', 'ALL PASSED' if not fails else f'{fails} FAILED')
