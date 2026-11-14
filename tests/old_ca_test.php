<?php
/** Part 106c: a certificate the server cannot verify (old CA list) still lets the live check see the site; a dead port still fails.
 *  Usage: php tests/old_ca_test.php   (needs openssl + python3) */
require __DIR__ . '/../modules/site/host.php';
$d = sys_get_temp_dir() . '/oldca' . getmypid(); mkdir($d);
exec("openssl req -x509 -newkey rsa:2048 -nodes -keyout $d/k.pem -out $d/c.pem -days 1 -subj /CN=localhost 2>/dev/null");
file_put_contents("$d/s.py", "import http.server,ssl\nclass H(http.server.BaseHTTPRequestHandler):\n  def do_GET(s):\n    s.send_response(200);s.end_headers();s.wfile.write(b'{\"ok\":true}')\n  def log_message(*a):pass\nx=http.server.HTTPServer(('127.0.0.1',8493),H)\nc=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);c.load_cert_chain('$d/c.pem','$d/k.pem')\nx.socket=c.wrap_socket(x.socket,server_side=True)\nx.serve_forever()\n");
$p = proc_open("python3 $d/s.py", [], $pipes); usleep(800000);
$fails = 0; $ok = function ($c, $m) use (&$fails) { echo ($c ? 'ok   ' : 'FAIL ') . $m . "\n"; if (!$c) $fails++; };
[$raw, $code, $err, $old] = site_probe('https://127.0.0.1:8493/health.php', 3, 5);
$ok($raw !== false && $code === 200 && $old === true, 'untrusted certificate: answers, flagged as old CA list');
[$raw, $code, $err, $old] = site_probe('https://127.0.0.1:8499/health.php', 2, 3);
$ok($raw === false && $old === false, 'closed port still fails (no false pass)');
[$raw, $code] = site_probe('https://127.0.0.1:8493/health.php', 3, 5); $ok($code === 200, 'healthy guard path gets the same answer');
proc_terminate($p); exec("rm -rf $d");
echo 'old_ca_test: ' . ($fails ? "$fails FAILED" : 'ALL PASSED') . "\n"; exit($fails ? 1 : 0);
