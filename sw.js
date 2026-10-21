const CACHE_NAME = "expenseflow-static-v4";
const PAGES = "expenseflow-pages-v1"; // signed-in pages kept for offline viewing; wiped on logout / when the login form is served
const APP_SCOPE = self.registration.scope;
const OFFLINE_URL = new URL("offline.html", APP_SCOPE).href;
const STATIC_URLS = [
  "offline.html",
  "assets/icons/icon-192.png",
  "assets/icons/icon-512.png",
  "assets/icons/favicon-32.png",
  "assets/icons/apple-touch-icon.png",
  "favicon.ico"
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
const skipCache = (u) => /\/(auth\/|admin\/|api\/)/.test(u.pathname) || u.searchParams.has("popup");

async function pageRequest(request, url) {
  try {
    const res = await fetch(request);
    if (isLogin(url) && res.ok) {
      await caches.delete(PAGES); // login form served = not signed in: drop saved private pages
    } else if (res.ok && !res.redirected && !skipCache(url) && (res.headers.get("content-type") || "").includes("text/html")) {
      const cache = await caches.open(PAGES);
      await cache.put(request.url, res.clone());
    }
    return res;
  } catch (e) {
    const cache = await caches.open(PAGES);
    let hit = await cache.match(request.url);
    if (!hit && isLogin(url)) hit = await cache.match(dash()); // already signed in: open on saved dashboard
    return hit || (await caches.match(OFFLINE_URL)) || Response.error();
  }
}

self.addEventListener("fetch", (event) => {
  const request = event.request;
  if (request.method === "POST" && new URL(request.url).pathname.endsWith("/auth/logout.php")) {
    event.waitUntil(caches.delete(PAGES)); // logging out removes the saved pages (fetch continues normally)
    return;
  }
  if (request.method !== "GET") return;

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;

  if (request.mode === "navigate") {
    event.respondWith(pageRequest(request, url));
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

  if (STATIC_URLS.some((assetUrl) => new URL(assetUrl).pathname === url.pathname)) {
    event.respondWith(
      caches.match(request).then((response) => response || fetch(request))
    );
  }
});
