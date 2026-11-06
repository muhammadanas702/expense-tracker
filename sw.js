const CACHE_NAME = "expenseflow-static-v12";
const PAGES = "expenseflow-pages-v1"; // signed-in pages kept for offline viewing; wiped on logout / when the login form is served
const APP_SCOPE = self.registration.scope;
const OFFLINE_URL = new URL("offline.html", APP_SCOPE).href;
const NAV_WAIT = 4000;   // a page that has a saved copy waits at most this long for the server, then shows the saved copy
const NAV_GIVE_UP = 20000; // a page without a saved copy waits this long, then shows the offline page (which keeps retrying)
const STATIC_URLS = [
  "offline.html",
  "assets/icons/icon-192.png",
  "assets/icons/icon-512.png",
  "assets/icons/favicon-32.png",
  "assets/icons/apple-touch-icon.png",
  "favicon.ico",
  "assets/fonts/inter-latin.woff2",
  "assets/fonts/plus-jakarta-sans-latin.woff2",
  "assets/fonts/noto-nastaliq-urdu-400.woff2",
  "assets/fonts/noto-nastaliq-urdu-700.woff2"
].map((path) => new URL(path, APP_SCOPE).href);

self.addEventListener("install", (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(STATIC_URLS))
  );
  self.skipWaiting();
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(
        keys
          .filter((key) => key.startsWith("expenseflow-static-") && key !== CACHE_NAME)
          .map((key) => caches.delete(key))
      ))
      .then(() => self.clients.claim())
  );
});

const dash = () => new URL("dashboard.php", APP_SCOPE).href;
const isLogin = (u) => /\/auth\/login\.php$/.test(u.pathname);
// Never saved: sign-in/out and other auth steps, JSON endpoints, pop-ups and the health check.
const skipCache = (u) => /\/(auth\/|api\/)|\/health\.php$/.test(u.pathname) || u.searchParams.has("popup");

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/** Asks the server and keeps a copy of good pages. Resolves with the response (or rejects when the network fails). */
async function fromNetwork(request, url) {
  const res = await fetch(request);
  if (isLogin(url) && res.ok) {
    await caches.delete(PAGES); // login form served = not signed in: drop saved private pages
  } else if (res.ok && !res.redirected && !skipCache(url) && (res.headers.get("content-type") || "").includes("text/html")) {
    const cache = await caches.open(PAGES);
    await cache.put(request.url, res.clone());
  }
  return res;
}

/** A saved page with a small bar on top telling the person it is a saved copy (the page script reloads when the server is back). */
async function withStaleBar(saved) {
  try {
    const html = await saved.clone().text();
    const bar = '<div id="ef-stale" role="status" style="position:sticky;top:0;z-index:99999;background:#f59e0b;color:#1f1300;font:600 13px system-ui,sans-serif;padding:8px 12px;text-align:center">'
      + 'Showing a saved copy because the connection or server is slow. It will refresh by itself when it is back. <a href="" style="color:inherit;text-decoration:underline" onclick="location.reload();return false">Reload now</a></div>';
    const body = html.replace(/<body[^>]*>/i, (m) => m + bar);
    const headers = new Headers(saved.headers);
    headers.delete("content-length");
    headers.set("X-EF-Saved-Copy", "1");
    return new Response(body, { status: 200, statusText: "OK", headers });
  } catch (e) {
    return saved;
  }
}

async function offlinePage() {
  return (await caches.match(OFFLINE_URL)) || Response.error();
}

async function pageRequest(request, url, event) {
  const cache = await caches.open(PAGES);
  let saved = await cache.match(request.url);
  if (!saved && isLogin(url)) saved = await cache.match(dash()); // already signed in: open on the saved dashboard

  const net = fromNetwork(request, url);
  net.catch(() => {}); // a late failure must not become an unhandled rejection
  event.waitUntil(net.then(() => {}, () => {})); // lets the background refresh finish after we answered with the saved copy

  const failed = Symbol("failed");
  const answer = await Promise.race([
    net.catch(() => failed),
    wait(saved ? NAV_WAIT : NAV_GIVE_UP).then(() => failed),
  ]);
  if (answer !== failed) return answer;
  if (saved) return withStaleBar(saved);
  return offlinePage();
}

// The page asks (while online) to keep the Add Expense / Add Income pages, so they also open offline and the offline queue can take entries.
self.addEventListener("message", (event) => {
  const d = event.data;
  if (!d || d.type !== "prewarm" || !Array.isArray(d.urls)) return;
  event.waitUntil((async () => {
    const cache = await caches.open(PAGES);
    for (const u of d.urls.slice(0, 6)) {
      try {
        const url = new URL(u, APP_SCOPE);
        if (url.origin !== self.location.origin || skipCache(url) || isLogin(url)) continue;
        const res = await fetch(url.href, { credentials: "same-origin", cache: "no-store" });
        if (res.ok && !res.redirected && (res.headers.get("content-type") || "").includes("text/html")) await cache.put(url.href, res);
      } catch (e) { /* offline or slow: try again next time */ }
    }
  })());
});

self.addEventListener("fetch", (event) => {
  const request = event.request;
  if (request.method === "POST" && new URL(request.url).pathname.endsWith("/auth/logout.php")) {
    event.waitUntil(caches.delete(PAGES)); // logging out removes the saved pages (fetch continues normally)
    return;
  }
  if (request.method !== "GET") return;

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;
  if (/\/health\.php$/.test(url.pathname)) return; // always asked live

  if (request.mode === "navigate") {
    event.respondWith(pageRequest(request, url, event));
    return;
  }

  // CSS/JS carry ?v=<timestamp>, so a cached copy is always the right one: serve it instantly.
  if (/\.(css|js)$/.test(url.pathname) && url.searchParams.has("v")) {
    event.respondWith(
      caches.open(CACHE_NAME).then((cache) =>
        cache.match(request).then((hit) => hit || fetch(request).then((res) => {
          if (res.ok) cache.put(request, res.clone());
          return res;
        }))
      )
    );
    return;
  }

  // Other static files (libraries, icons, fonts, the manifest): show the saved copy at once and refresh it quietly.
  if (/\.(css|js|png|jpg|jpeg|svg|webp|ico|woff2?|json)$/.test(url.pathname) && !/\/(admin|auth|api)\//.test(url.pathname)) {
    event.respondWith(
      caches.open(CACHE_NAME).then(async (cache) => {
        const hit = await cache.match(request);
        const refresh = fetch(request).then((res) => {
          if (res.ok) cache.put(request, res.clone());
          return res;
        });
        refresh.catch(() => {});
        event.waitUntil(refresh.then(() => {}, () => {}));
        return hit || refresh;
      })
    );
    return;
  }

  if (STATIC_URLS.some((assetUrl) => new URL(assetUrl).pathname === url.pathname)) {
    event.respondWith(
      caches.match(request).then((response) => response || fetch(request))
    );
  }
});
