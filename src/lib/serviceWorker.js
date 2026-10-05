export function registerServiceWorker() {
  const version = document.querySelector('meta[name="mediahub-build"]')?.content;
  const url = /^[a-f0-9]{16}$/.test(version || "") ? `/sw.js?build=${version}` : "/sw.js";
  return navigator.serviceWorker.register(url, { updateViaCache: "none" });
}
