// Windows app: the local listener the browser returns to after Google sign-in. Usage: node tests/desktop_google_return_test.js
const http = require('http');
const { listen } = require('../apps/desktop/google-return');
let fails = 0;
const ok = (c, m) => { if (!c) { fails++; console.log('FAIL', m); } };
const get = (port, path, method = 'GET') => new Promise((res, rej) => {
  const r = http.request({ host: '127.0.0.1', port, path, method }, (x) => { let b = ''; x.on('data', (d) => b += d); x.on('end', () => res({ status: x.statusCode, body: b })); });
  r.on('error', rej); r.end();
});
(async () => {
  const id = 'a'.repeat(32), proof = 'b'.repeat(48);
  let got = null, calls = 0;
  const l = await listen((h, p) => { calls++; got = [h, p]; });
  ok(l.port >= 1024 && l.port <= 65535, 'listens on a high local port: ' + l.port);
  ok((await get(l.port, '/')).status === 404, 'root is 404');
  ok((await get(l.port, '/done?h=short&p=short')).status === 404, 'malformed id/proof refused');
  ok((await get(l.port, '/done?h=' + id + '&p=' + proof, 'POST')).status === 404, 'POST refused');
  ok((await get(l.port, '/done?h=' + 'G'.repeat(32) + '&p=' + proof)).status === 404, 'non-hex id refused');
  ok(calls === 0, 'nothing delivered for bad requests');
  const r = await get(l.port, '/done?h=' + id + '&p=' + proof);
  ok(r.status === 200 && r.body.includes('You are signed in'), 'good request shows the friendly page');
  ok(calls === 1 && got[0] === id && got[1] === proof, 'the id and proof are delivered once');
  let refused = false;
  try { await get(l.port, '/done?h=' + id + '&p=' + proof); } catch (e) { refused = true; }
  ok(refused && calls === 1, 'the listener is closed after one use');
  console.log(fails ? fails + ' FAILED' : 'desktop_google_return_test: ALL PASSED');
  process.exit(fails ? 1 : 0);
})();
