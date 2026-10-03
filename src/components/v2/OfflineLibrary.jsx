import { useEffect, useState } from "react";
import { apiRequest } from "../../lib/api.js";
import { cacheOfflinePage, clearOffline, queueOfflineWatch, readOffline, removeOfflineEntry, syncOffline } from "../../lib/offline.js";
import { Feedback, localDateTime, useAsyncAction } from "./shared.jsx";

export function useOfflineState() {
  const [data, setData] = useState(readOffline);
  const [online, setOnline] = useState(navigator.onLine);
  useEffect(() => {
    const update = () => { setData(readOffline()); setOnline(navigator.onLine); };
    for (const name of ['online', 'offline', 'storage', 'mediahub-offline-changed']) window.addEventListener(name, update);
    return () => { for (const name of ['online', 'offline', 'storage', 'mediahub-offline-changed']) window.removeEventListener(name, update); };
  }, []);
  return { data, online };
}
export function OfflineStatus() {
  const { data, online } = useOfflineState();
  const [error, setError] = useState('');
  useEffect(() => {
    if (online && data.entries.length) syncOffline().then(() => setError('')).catch(error => setError(error.message));
  }, [online]);
  return data.owner ? <aside className="v2-offline-status"><a href="/offline">Offline library</a><span>{data.entries.length} pending watches</span>{error && <span role="status">{error}</span>}</aside> : null;
}
export function OfflineSettings() {
  const { data } = useOfflineState();
  const [install, setInstall] = useState(null);
  useEffect(() => { const handler = event => { event.preventDefault(); setInstall(event); }; window.addEventListener('beforeinstallprompt', handler); return () => window.removeEventListener('beforeinstallprompt', handler); }, []);
  return <section className="v2-panel"><h3>Mobile and offline</h3><p>Save selected Movies, Shows, or History pages from their library controls. Saved titles and pending watches remain on this device until you clear them or sign out. Use this on a device you trust.</p><div className="v2-inline"><a className="secondary-action" href="/offline">Open offline library</a>{install ? <button className="secondary-action" type="button" onClick={async () => { await install.prompt(); setInstall(null); }}>Install MediaHub</button> : <p>Use your browser’s install option, or Share → Add to Home Screen on iPhone.</p>}<button className="text-action danger" type="button" onClick={() => { if (window.confirm(`Clear offline pages and ${data.entries.length} pending watches from this device?`)) clearOffline(); }}>Clear this device’s data</button></div></section>;
}
export function OfflineLibrary({ apiClient = apiRequest }) {
  const { data, online } = useOfflineState(); const action = useAsyncAction();
  const [selected, setSelected] = useState(null); const [date, setDate] = useState(localDateTime(new Date()));
  useEffect(() => { if (online && readOffline().entries.length) action.run({ save: () => syncOffline(apiClient), success: 'Pending watches synchronized.' }); }, [online]);
  return <main className="v2-offline-page"><a href="/">MediaHub · Back to app</a><h1>Your offline library</h1><p>{online ? 'Connected' : 'Offline'} · {data.entries.length} {data.entries.length === 1 ? "watch" : "watches"} waiting to sync</p><Feedback action={action} />
    <button className="primary-action" disabled={!online || !data.entries.length || action.pending} type="button" onClick={() => action.run({ pending: 'Syncing…', save: () => syncOffline(apiClient), success: 'Pending watches synchronized.' })}>Sync pending watches</button>
    {!data.pages.length && <p>No pages saved yet. Connect, sign in, and choose “Save this page offline” in Movies, Shows, or History.</p>}
    {data.pages.map(page => <section className="v2-panel" key={page.label}><h2>{page.label}</h2><small>Saved {new Date(page.savedAt).toLocaleString()}</small>{page.items.map((item, index) => <article className="v2-watch-row" key={`${item.id}-${index}`}><strong>{item.title}</strong><small>{item.subtitle}{item.watchedAt ? ` · ${new Date(item.watchedAt).toLocaleDateString()}` : ''}</small>{['movie', 'episode'].includes(item.kind) && <button className="text-action" type="button" onClick={() => { setSelected(item); setDate(localDateTime(new Date())); }}>Log a watch</button>}</article>)}</section>)}
    {selected && <section className="v2-panel"><form onSubmit={e => { e.preventDefault(); action.run({ save: async () => queueOfflineWatch(selected, new Date(date).toISOString()), onSaved: () => setSelected(null), success: 'Watch saved on this device. It will sync when you reconnect.' }); }}><h2>Log {selected.title}</h2><label>Watched at<input type="datetime-local" required max={localDateTime(new Date())} value={date} onChange={e => setDate(e.target.value)} /></label><div className="v2-inline"><button className="primary-action" disabled={action.pending}>Save pending watch</button><button className="text-action" type="button" onClick={() => setSelected(null)}>Cancel</button></div></form></section>}
    <section className="v2-panel"><h2>Pending watches</h2>{data.entries.map(entry => <article className="v2-watch-row" key={entry.id}><strong>{entry.title}</strong><p>{new Date(entry.watched_at).toLocaleString()}</p><button className="text-action danger" type="button" disabled={action.pending} onClick={() => removeOfflineEntry(entry.id)}>Remove pending entry</button></article>)}</section><OfflineSettings />
  </main>;
}

export function SaveOfflinePage({ label, items, apiClient = apiRequest }) {
  const action = useAsyncAction();
  return <div className="v2-inline"><button className="text-action" disabled={action.pending || !items.length} type="button" onClick={() => action.run({ save: () => cacheOfflinePage(label, items, apiClient), success: 'This page is saved on this device. Open Offline library from Settings.' })}>Save this page offline</button><small>Saves these private titles on this device.</small><Feedback action={action} /></div>;
}
