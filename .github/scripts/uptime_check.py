#!/usr/bin/env python3
"""ExpenseFlow uptime probe (Part 105). Calls <site>/health.php?db=1 up to 3 times and prints one JSON result:
{"state": "up"|"down", "code": 200, "ms": 123, "detail": "...", "checked_at": "..."}.
UP  = HTTP 200 and (health JSON with db not false, or the host's own bot-check page: the host is answering).
DOWN = no answer, timeout, HTTP error, health JSON saying the database is down, or a "suspended" page.
Usage: uptime_check.py <site-url> [--out result.json]   (also writes `state=` to $GITHUB_OUTPUT when set)"""
import json, os, re, sys, time, datetime, urllib.request, urllib.error


def classify(code, body):
    """-> (state, detail) for one answer."""
    if code != 200:
        return 'down', 'The website answered with HTTP %d.' % code
    try:
        data = json.loads(body)
    except ValueError:
        data = None
    if isinstance(data, dict) and 'ok' in data:
        if data.get('db') is False:
            return 'down', 'The website answers but its database does not.'
        return 'up', 'Website and database answer.'
    if re.search(r'suspend', body, re.I):
        return 'down', 'The host shows a "suspended" page instead of the website.'
    return 'up', 'The host answers with its own page (bot check), so the site is reachable.'


def probe(url, timeout=20):
    req = urllib.request.Request(url, headers={'User-Agent': 'ExpenseFlow-monitor/1.0', 'Cache-Control': 'no-cache'})
    t0 = time.time()
    try:
        with urllib.request.urlopen(req, timeout=timeout) as r:
            code, body = r.status, r.read(65536).decode('utf-8', 'replace')
    except urllib.error.HTTPError as e:
        code, body = e.code, ''
    except Exception as e:  # DNS, refused, timeout, TLS
        return None, '', int((time.time() - t0) * 1000), 'No answer: %s' % (str(getattr(e, 'reason', e)) or e.__class__.__name__)
    return code, body, int((time.time() - t0) * 1000), ''


def check(site, attempts=3, timeout=20, pause=10, sleep=time.sleep):
    url = site.rstrip('/') + '/health.php?db=1'
    res = {}
    for i in range(attempts):
        code, body, ms, err = probe(url, timeout)
        if code is None:
            state, detail = 'down', err
        else:
            state, detail = classify(code, body)
        res = {'state': state, 'code': code, 'ms': ms, 'detail': detail, 'url': site,
               'checked_at': datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')}
        if state == 'up':
            break
        if i < attempts - 1:
            sleep(pause)
    return res


if __name__ == '__main__':
    args = [a for a in sys.argv[1:] if not a.startswith('--')]
    out = sys.argv[sys.argv.index('--out') + 1] if '--out' in sys.argv else None
    if out and out in args:
        args.remove(out)
    if not args:
        sys.exit('usage: uptime_check.py <site-url> [--out result.json]')
    result = check(args[0])
    print(json.dumps(result))
    if out:
        open(out, 'w').write(json.dumps(result))
    if os.environ.get('GITHUB_OUTPUT'):
        with open(os.environ['GITHUB_OUTPUT'], 'a') as f:
            f.write('state=%s\n' % result['state'])
