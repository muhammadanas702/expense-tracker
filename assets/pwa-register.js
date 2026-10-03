(() => {
  const script = document.currentScript;
  const appBase = script?.dataset.appBase;
  if (!appBase || !("serviceWorker" in navigator)) return;

  const normalizedBase = `${appBase.replace(/\/+$/, "")}/`;
  const serviceWorkerUrl = new URL("sw.js", normalizedBase);

  navigator.serviceWorker.register(serviceWorkerUrl.href, { scope: normalizedBase })
    .catch(() => {
      // The website remains usable if service workers are unavailable.
    });
})();
