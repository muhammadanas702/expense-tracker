<?php
/** Part 106d: the real visitor IP comes from Cloudflare only when the request really comes from Cloudflare. Usage: php tests/client_ip_test.php */
require __DIR__ . '/../includes/security.php';
$fails = 0; $ok = function ($c, $m) use (&$fails) { echo ($c ? 'ok   ' : 'FAIL ') . $m . "\n"; if (!$c) $fails++; };
$ip = function (string $remote, ?string $cf) { $_SERVER['REMOTE_ADDR'] = $remote; unset($_SERVER['HTTP_CF_CONNECTING_IP']); if ($cf !== null) $_SERVER['HTTP_CF_CONNECTING_IP'] = $cf; return client_ip(); };
$ok($ip('104.21.5.9', '203.0.113.7') === '203.0.113.7', 'Cloudflare IPv4 edge: visitor IP from CF-Connecting-IP');
$ok($ip('172.67.1.1', '198.51.100.2') === '198.51.100.2', 'Cloudflare 172.64/13 edge works');
$ok($ip('2606:4700::1111', '203.0.113.9') === '203.0.113.9', 'Cloudflare IPv6 edge works');
$ok($ip('198.51.100.50', '1.2.3.4') === '198.51.100.50', 'a direct visitor cannot fake the header');
$ok($ip('104.21.5.9', null) === '104.21.5.9', 'Cloudflare edge without the header: edge IP');
$ok($ip('104.21.5.9', 'not-an-ip') === '104.21.5.9', 'garbage header ignored');
$ok($ip('104.15.255.255', '9.9.9.9') === '104.15.255.255', 'just below 104.16.0.0/13 is not Cloudflare');
$ok($ip('104.23.255.255', '9.9.9.9') === '9.9.9.9', 'top of 104.16.0.0/13 is Cloudflare');
$ok($ip('bad', '9.9.9.9') === 'unknown', 'invalid REMOTE_ADDR is unknown');
echo 'client_ip_test: ' . ($fails ? "$fails FAILED" : 'ALL PASSED') . "\n"; exit($fails ? 1 : 0);
