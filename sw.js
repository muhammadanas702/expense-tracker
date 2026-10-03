const CACHE_NAME = "expenseflow-static-v1";
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

self.addEventListener("fetch", (event) => {
  const request = event.request;
  if (request.method !== "GET") return;

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;

  if (request.mode === "navigate") {
    event.respondWith(
      fetch(request).catch(() => caches.match(OFFLINE_URL).then((response) => response || Response.error()))
    );
    return;
  }

  if (STATIC_URLS.some((assetUrl) => new URL(assetUrl).pathname === url.pathname)) {
    event.respondWith(
      caches.match(request).then((response) => response || fetch(request))
    );
  }
});
