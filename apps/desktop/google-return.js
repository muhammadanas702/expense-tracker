// "Continue with Google" in the Windows app: the Google step happens in the normal browser, which then comes back to
// this computer on http://127.0.0.1:<port>/done?h=<id>&p=<proof>. Only a program on this same computer can receive that,
// which is what makes the sign-in safe without comparing any code. Plain Node (no Electron) so it can be tested alone.
const http = require('http');

const WAIT_MS = 6 * 60 * 1000; // a little longer than the 5 minutes the server allows
const OK = /^[0-9a-f]{32}$/, PROOF = /^[0-9a-f]{48}$/;

/**
 * Starts a one-use listener. Resolves to { port, close } once it is listening.
 * onDone(id, proof) is called once, when the browser arrives with a well-formed address.
 */
function listen(onDone) {
  return new Promise((resolve, reject) => {
    let finished = false;
    const server = http.createServer((req, res) => {
      let u;
      try { u = new URL(req.url, 'http://127.0.0.1'); } catch (e) { res.writeHead(400).end(); return; }
      const h = u.searchParams.get('h'), p = u.searchParams.get('p');
      res.setHeader('Cache-Control', 'no-store');
      if (req.method !== 'GET' || u.pathname !== '/done' || !OK.test(h || '') || !PROOF.test(p || '')) {
        res.writeHead(404).end('Not found');
        return;
      }
      res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', Connection: 'close' });
      res.end('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ExpenseFlow</title>'
        + '<body style="font-family:system-ui,sans-serif;text-align:center;padding:48px 20px;color:#0b2545"><h2>You are signed in</h2><p>You can close this tab and go back to the ExpenseFlow app.</p></body>');
      if (!finished) {
        finished = true;
        res.on('finish', () => { close(); if (server.closeAllConnections) server.closeAllConnections(); });
        onDone(h, p);
      }
    });
    const timer = setTimeout(() => close(), WAIT_MS);
    function close() { clearTimeout(timer); try { server.close(); } catch (e) {} }
    server.on('error', reject);
    server.listen(0, '127.0.0.1', () => resolve({ port: server.address().port, close }));
  });
}

module.exports = { listen };
