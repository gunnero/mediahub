import { useState } from "react";
import { apiRequest } from "../../lib/api.js";
import { registerServiceWorker } from "../../lib/serviceWorker.js";
import { Feedback, ResourceState, useAsyncAction, useResource } from "./shared.jsx";

export function ReleasePlanner({ apiClient = apiRequest, onSessionExpired }) {
  const settings = useResource("/api/v1/experience-settings", apiClient, onSessionExpired);
  const reminders = useResource("/api/v1/reminders", apiClient, onSessionExpired);
  const action = useAsyncAction(onSessionExpired);
  const [query, setQuery] = useState(""); const [results, setResults] = useState([]); const [selected, setSelected] = useState(null);
  const [date, setDate] = useState(""); const [url, setUrl] = useState(""); const [draft, setDraft] = useState(null);
  const prefs = draft || settings.data?.settings || {};
  async function push(enable) {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) throw new Error("This browser does not support push. On iPhone, install MediaHub on your Home Screen first.");
    const registration = await registerServiceWorker();
    await navigator.serviceWorker.ready;
    let subscription = await registration.pushManager.getSubscription();
    if (!enable) {
      if (subscription) { await apiClient('/api/v1/push/subscription', { method: 'DELETE', body: subscription.toJSON() }); await subscription.unsubscribe(); }
      return;
    }
    const permission = await Notification.requestPermission();
    if (permission !== 'granted') throw new Error("Notifications were not enabled. You can change this in your browser settings.");
    const raw = settings.data.pushPublicKey.replace(/-/g, '+').replace(/_/g, '/');
    const key = Uint8Array.from(atob(raw.padEnd(Math.ceil(raw.length / 4) * 4, '=')), char => char.charCodeAt(0));
    subscription ||= await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key });
    await apiClient('/api/v1/push/subscription', { method: 'POST', body: subscription.toJSON() });
  }
  return <details className="v2-panel"><summary>Plan reminders and notifications</summary><ResourceState resource={settings} /><Feedback action={action} />
    <form className="v2-inline" onSubmit={e => { e.preventDefault(); action.run({ pending: "Searching…", save: () => apiClient(`/api/v1/library/search?query=${encodeURIComponent(query)}&type=all&limit=15`), onSaved: data => setResults([...(data.movies || []), ...(data.shows || [])]), success: "Choose a title below." }); }}><label>Find a title<input required minLength={2} value={query} onChange={e => setQuery(e.target.value)} /></label><button className="secondary-action" disabled={action.pending}>Search library</button></form>
    <div className="v2-inline">{results.map(item => <button className="text-action" type="button" key={`${item.kind}-${item.id}`} onClick={() => setSelected(item)}>{item.title}</button>)}</div>
    {selected && <form className="v2-inline" onSubmit={e => { e.preventDefault(); action.run({ save: () => apiClient('/api/v1/reminders', { method: 'POST', body: { media_type: selected.kind, media_id: selected.movieId || selected.showId || selected.id, remind_at: new Date(date).toISOString() } }), onSaved: () => { setSelected(null); setDate(''); }, refresh: reminders.refresh, success: 'Reminder scheduled.' }); }}><label>Remind me about {selected.title}<input type="datetime-local" required value={date} onChange={e => setDate(e.target.value)} /></label><button className="primary-action" disabled={action.pending}>Schedule reminder</button></form>}
    <ResourceState resource={reminders} />{(reminders.data?.reminders || []).map(reminder => <article className="v2-watch-row" key={reminder.id}><strong>{reminder.title}</strong><p>{new Date(reminder.remind_at).toLocaleString()} · {reminder.delivered_at ? 'Delivered' : 'Scheduled'}</p><div className="v2-inline"><button className="text-action" disabled={action.pending} type="button" onClick={() => action.run({ save: () => apiClient(`/api/v1/reminders/${reminder.id}`, { method: 'PATCH', body: { remind_at: new Date(Date.now() + 86400000).toISOString() } }), refresh: reminders.refresh, success: 'Snoozed for one day.' })}>Snooze one day</button><button className="text-action danger" disabled={action.pending} type="button" onClick={() => action.run({ save: () => apiClient(`/api/v1/reminders/${reminder.id}`, { method: 'DELETE' }), refresh: reminders.refresh })}>Remove</button></div></article>)}
    <form onSubmit={e => { e.preventDefault(); action.run({ save: () => apiClient('/api/v1/experience-settings', { method: 'PATCH', body: { timezone: prefs.timezone || 'UTC', quiet_start: prefs.quiet_start || null, quiet_end: prefs.quiet_end || null, weekly_digest: Boolean(prefs.weekly_digest) } }), onSaved: () => setDraft(null), refresh: settings.refresh, success: 'Delivery preferences saved.' }); }}><h4>Delivery preferences</h4><div className="v2-form-grid"><label>Timezone<select value={prefs.timezone || 'UTC'} onChange={e => setDraft({ ...prefs, timezone: e.target.value })}>{[...new Set(['UTC', prefs.timezone, ...(Intl.supportedValuesOf?.('timeZone') || ['Europe/Skopje'])].filter(Boolean))].map(zone => <option key={zone}>{zone}</option>)}</select></label><label>Quiet hours from<input type="time" value={prefs.quiet_start || ''} onChange={e => setDraft({ ...prefs, quiet_start: e.target.value })} /></label><label>Quiet hours until<input type="time" value={prefs.quiet_end || ''} onChange={e => setDraft({ ...prefs, quiet_end: e.target.value })} /></label><label><input type="checkbox" checked={Boolean(prefs.weekly_digest)} onChange={e => setDraft({ ...prefs, weekly_digest: e.target.checked })} />Weekly release digest in Alerts</label></div><button className="secondary-action" disabled={action.pending || settings.loading}>Save delivery preferences</button></form>
    <h4>Calendar subscription</h4><p>The private subscription link shares your release schedule with anyone who has it. Replacing or revoking it disables the old link.</p><div className="v2-inline"><button className="secondary-action" disabled={action.pending} type="button" onClick={() => action.run({ save: () => apiClient('/api/v1/calendar/subscription', { method: 'POST' }), onSaved: data => setUrl(data.url), refresh: settings.refresh })}>Create subscription link</button>{settings.data?.calendarActive && <button className="text-action danger" disabled={action.pending} type="button" onClick={() => action.run({ save: () => apiClient('/api/v1/calendar/subscription', { method: 'DELETE' }), onSaved: () => setUrl(''), refresh: settings.refresh })}>Revoke subscription</button>}</div>{url && <label>Calendar URL<input readOnly value={url} onFocus={e => e.target.select()} /></label>}
    <h4>Device notifications</h4>{settings.data?.pushAvailable ? <div className="v2-inline"><button className="secondary-action" type="button" disabled={action.pending} onClick={() => action.run({ save: () => push(true), success: 'Notifications enabled on this device.' })}>Enable notifications</button><button className="text-action" type="button" disabled={action.pending} onClick={() => action.run({ save: () => push(false), success: 'Notifications disabled on this device.' })}>Disable notifications</button></div> : <p>Device notifications are not available on this server yet. Reminders and digests appear in Alerts.</p>}
  </details>;
}
