import { SaveOfflinePage } from "./OfflineLibrary.jsx";
import { useState } from "react";
import { apiRequest } from "../../lib/api.js";
import { Feedback, ResourceState, useAsyncAction, useResource } from "./shared.jsx";

export function LibraryTools({ type, items, filters, onFilters, selected, onSelected, apiClient = apiRequest, onChanged, onSessionExpired }) {
  const views = useResource("/api/v1/library/views", apiClient, onSessionExpired);
  const lists = useResource("/api/v1/lists", apiClient, onSessionExpired);
  const action = useAsyncAction(onSessionExpired);
  const [name, setName] = useState("");
  const [bulk, setBulk] = useState("watchlist");
  const [tag, setTag] = useState("");
  const [list, setList] = useState("");
  const setFilter = (key, value) => onFilters({ ...filters, [key]: value, page: 1 });
  const saved = (views.data?.views || []).filter(view => view.media_type === type);
  return <details className="v2-panel library-tools"><summary>Library tools · filters, saved views, and bulk actions</summary><SaveOfflinePage label={`${type === "movie" ? "Movies" : "Shows"} · page ${filters.page || 1}`} items={items} apiClient={apiClient} />
    <div className="v2-form-grid"><label>Personal status<select value={filters.personal_status || ""} onChange={e => setFilter("personal_status", e.target.value)}><option value="">All</option><option value="active">Active</option><option value="paused">Paused</option><option value="dropped">Dropped</option></select></label><label>Tag<input value={filters.tag || ""} onChange={e => setFilter("tag", e.target.value)} maxLength={40} /></label><label>Genre<input value={filters.genre || ""} onChange={e => setFilter("genre", e.target.value)} placeholder="Thriller" /></label><label>Maximum minutes<input type="number" min="1" max="1440" value={filters.max_runtime || ""} onChange={e => setFilter("max_runtime", e.target.value)} /></label></div>
    <form className="v2-inline" onSubmit={e => { e.preventDefault(); const clean = Object.fromEntries(Object.entries(filters).filter(([key, value]) => !["page", "per_page"].includes(key) && value !== "").map(([key, value]) => [key, String(value)])); action.run({ save: () => apiClient("/api/v1/library/views", { method: "POST", body: { name, media_type: type, filters: clean } }), onSaved: () => setName(""), refresh: views.refresh, success: "View saved." }); }}><label>Save current filters<input required value={name} maxLength={80} placeholder="My weekend movies" onChange={e => setName(e.target.value)} /></label><button className="secondary-action" disabled={action.pending}>Save view</button></form>
    <ResourceState resource={views} />{saved.map(view => <div className="v2-inline" key={view.id}><button className="text-action" type="button" onClick={() => { onFilters({ ...view.filters, page: 1, per_page: 24 }); onSelected([]); }}>{view.name}</button><button className="text-action danger" aria-label={`Delete view ${view.name}`} disabled={action.pending} onClick={() => action.run({ save: () => apiClient(`/api/v1/library/views/${view.id}`, { method: "DELETE" }), refresh: views.refresh })} type="button">Delete</button></div>)}
    <div className="v2-inline"><button className="text-action" type="button" disabled={!items.length} onClick={() => onSelected(items.map(item => item.movieId || item.showId || item.id))}>Select this page</button><button className="text-action" type="button" onClick={() => onSelected([])}>Clear selection</button><span>{selected.length} selected</span></div>
    <form className="v2-inline" onSubmit={e => { e.preventDefault(); action.run({ save: () => apiClient("/api/v1/library/bulk", { method: "POST", body: { type, ids: selected, action: bulk, ...(["tag", "untag"].includes(bulk) ? { tag } : {}), ...(bulk === "list" ? { list_id: Number(list) } : {}) } }), onSaved: () => onSelected([]), refresh: onChanged, success: "Selected titles updated." }); }}>
      <label>Action<select value={bulk} onChange={e => setBulk(e.target.value)}>{[["watchlist", "Add to watchlist"], ["remove_watchlist", "Remove from watchlist"], ["tag", "Add tag"], ["untag", "Remove tag"], ["list", "Add to list"], ["pin", "Pin"], ["unpin", "Unpin"], ["active", "Set active"], ["paused", "Pause"], ["dropped", "Drop"]].map(([value, label]) => <option value={value} key={value}>{label}</option>)}</select></label>
      {["tag", "untag"].includes(bulk) && <label>Tag to change<input required value={tag} maxLength={40} onChange={e => setTag(e.target.value)} /></label>}
      {bulk === "list" && <label>Destination list<select required value={list} onChange={e => setList(e.target.value)}><option value="">Choose list</option>{(lists.data?.lists || []).filter(item => !item.rules).map(item => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>}
      <button className="primary-action" disabled={!selected.length || action.pending}>Apply to {selected.length} titles</button>
    </form><Feedback action={action} />
  </details>;
}

export function PersonalQueue({ apiClient = apiRequest, onOpen, onSessionExpired, onChanged }) {
  const [minutes, setMinutes] = useState("");
  const resource = useResource(`/api/v1/queue?minutes=${minutes || 0}`, apiClient, onSessionExpired);
  const action = useAsyncAction(onSessionExpired);
  const update = (item, body) => action.run({ save: () => apiClient(`/api/v1/preferences/show/${item.showId}`, { method: "PATCH", body }), refresh: async () => { await resource.refresh(); await onChanged?.(); } });
  return <section className="home-section v2-panel"><div className="section-heading"><div><span className="eyebrow">Make time for a story</span><h2>Your next episodes</h2></div><label>Time available<select value={minutes} onChange={e => setMinutes(e.target.value)}><option value="">Any length</option><option value="30">30 minutes</option><option value="45">45 minutes</option><option value="60">60 minutes</option></select></label></div><ResourceState resource={resource} /><Feedback action={action} />
    <div className="v2-queue">{(resource.data?.items || []).map(item => <article key={item.showId}><button type="button" className="v2-title-button" onClick={() => onOpen?.(item)}>{item.poster && <img src={item.poster} alt="" loading="lazy" />}<span><strong>{item.title}</strong><small>{item.subtitle}</small><small>{item.runtime ? `${item.runtime} min · ` : ""}{item.remaining} episodes left{item.catchUpMinutes > 0 ? ` · ${item.estimated ? "about " : ""}${Math.ceil(item.catchUpMinutes / 60)}h to catch up` : ""}</small></span></button><div className="v2-inline"><button className="text-action" type="button" disabled={action.pending} onClick={() => update(item, { pinned: !item.pinned })}>{item.pinned ? "Unpin" : "Pin show"}</button><button className="text-action" type="button" disabled={action.pending} onClick={() => update(item, { status: "paused" })}>Pause show</button></div></article>)}</div>
    {!resource.loading && !resource.error && !resource.data?.items?.length && <p>No aired episodes fit right now. Follow a show or resume paused shows from Library tools.</p>}
  </section>;
}
