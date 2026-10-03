import { apiRequest } from "./api.js";
const KEY = "mediahub-offline-v2";
export function readOffline() {
  try { const data = JSON.parse(localStorage.getItem(KEY)); return data?.version === 2 ? data : { version: 2, owner: null, pages: [], entries: [] }; }
  catch { return { version: 2, owner: null, pages: [], entries: [] }; }
}
function writeOffline(data) { localStorage.setItem(KEY, JSON.stringify(data)); window.dispatchEvent(new Event("mediahub-offline-changed")); }
export function clearOffline() { localStorage.removeItem(KEY); window.dispatchEvent(new Event("mediahub-offline-changed")); }
export async function cacheOfflinePage(label, items, apiClient = apiRequest) {
  const { user } = await apiClient("/api/v1/me");
  let current = readOffline();
  if (current.owner && current.owner !== user.id) throw new Error("Clear the previous account’s offline data before saving this account.");
  const safe = items.map(item => ({ id: item.id, kind: item.kind, movieId: item.movieId, showId: item.showId, episodeId: item.episodeId, title: item.title, subtitle: item.subtitle, watchedAt: item.watchedAt }));
  if (!navigator.serviceWorker) throw new Error("Offline pages require a browser with service worker support.");
  await navigator.serviceWorker.register("/sw.js");
  await navigator.serviceWorker.ready;
  // Preserve watches or pages saved while the application shell was installing.
  current = readOffline();
  if (current.owner && current.owner !== user.id) throw new Error("The offline account changed. Save this page again after signing in.");
  const pages = [...current.pages.filter(page => page.label !== label), { label, items: safe, savedAt: new Date().toISOString() }].slice(-20);
  writeOffline({ ...current, owner: user.id, pages });
}
export function queueOfflineWatch(item, date = new Date().toISOString()) {
  const current = readOffline();
  if (!current.owner) throw new Error("Save a library page for offline use first.");
  if (!["movie", "episode"].includes(item.kind)) throw new Error("Choose a movie or an individual episode to record a watch.");
  if (current.entries.length >= 100) throw new Error("Sync pending entries before adding more.");
  const watched = new Date(date);
  if (!Number.isFinite(watched.getTime()) || watched > new Date()) throw new Error("Choose a valid watch date that is not in the future.");
  const entry = { id: crypto.randomUUID(), type: item.kind, media_id: Number(item.movieId || item.episodeId || item.id), watched_at: watched.toISOString(), title: item.title };
  writeOffline({ ...current, entries: [...current.entries, entry] });
  return entry;
}
export function removeOfflineEntry(id) { const data = readOffline(); writeOffline({ ...data, entries: data.entries.filter(entry => entry.id !== id) }); }
let syncing = null;
export function syncOffline(apiClient = apiRequest) {
  if (syncing) return syncing;
  syncing = (async () => {
    const current = readOffline();
    if (!current.entries.length) return 0;
    const { user } = await apiClient("/api/v1/me");
    if (current.owner !== user.id) throw new Error("Sign in to the account that saved these offline entries before syncing.");
    const data = await apiClient("/api/v1/offline/watches", { method: "POST", body: { entries: current.entries.map(({ title, ...entry }) => entry) } });
    const latest = readOffline();
    if (latest.owner === user.id) writeOffline({ ...latest, entries: latest.entries.filter(entry => !data.accepted.includes(entry.id)) });
    return data.accepted.length;
  })().finally(() => { syncing = null; });
  return syncing;
}
