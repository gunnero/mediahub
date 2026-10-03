import { useState } from "react";
import { apiRequest } from "../../lib/api.js";
import { Feedback, ResourceState, localDateTime, useAsyncAction, useResource } from "./shared.jsx";

export function WatchHistoryEditor({ detail, apiClient = apiRequest, onChanged, onSessionExpired }) {
  const [page, setPage] = useState(1);
  const [editing, setEditing] = useState(null);
  const [date, setDate] = useState("");
  const [undoId, setUndoId] = useState(null);
  const resource = useResource(`/api/v1/history/${detail.kind}/${detail.id}?page=${page}`, apiClient, onSessionExpired);
  const action = useAsyncAction(onSessionExpired);
  const items = resource.data?.items || (page === 1 ? detail.watchHistory || [] : []);
  function change(watch, remove = false) {
    if (remove && !window.confirm(`Remove this ${watch.count > 1 ? `imported record containing ${watch.count} watches` : "watch"}? You can undo for 30 minutes.`)) return;
    action.run({ save: () => apiClient(`/api/v1/history/${detail.kind}/${watch.id}`, { method: remove ? "DELETE" : "PATCH", ...(remove ? {} : { body: { watched_at: new Date(date).toISOString() } }) }),
      onSaved: data => { setUndoId(data.undoId); setEditing(null); },
      refresh: async () => { await resource.refresh(); await onChanged?.(); }, success: remove ? "Watch removed. Undo is available for 30 minutes." : "Watch date updated." });
  }
  return <section className="detail-section v2-panel"><div className="section-heading"><h3>Watch history</h3><span>{resource.data?.total || 0} records</span></div>
    <ResourceState resource={resource} /><Feedback action={action} />
    {undoId && <button className="secondary-action" disabled={action.pending} onClick={() => action.run({ save: () => apiClient(`/api/v1/history/undo/${undoId}`, { method: "POST" }), onSaved: () => setUndoId(null), refresh: async () => { await resource.refresh(); await onChanged?.(); }, success: "Change undone." })} type="button">Undo last change</button>}
    {items.map(watch => <article className="v2-watch-row" key={watch.id}><div><strong>{new Date(watch.watchedAt).toLocaleString()}</strong><small>{watch.count > 1 ? `${watch.count} watches in one imported record · ` : ""}{watch.source === "manual" ? "Manual entry" : watch.source}</small></div>
      {editing === watch.id ? <form className="v2-inline" onSubmit={event => { event.preventDefault(); change(watch); }}><label>Watch date<input aria-label="Correct watch date" type="datetime-local" required max={localDateTime(new Date())} value={date} onChange={event => setDate(event.target.value)} /></label><button className="primary-action" disabled={action.pending} type="submit">Save date</button><button className="text-action" onClick={() => setEditing(null)} type="button">Cancel</button></form> : <div className="v2-inline"><button className="text-action" disabled={action.pending} onClick={() => { setEditing(watch.id); setDate(localDateTime(watch.watchedAt)); }} type="button">Edit date</button><button className="text-action danger" disabled={action.pending} onClick={() => change(watch, true)} type="button">Remove watch</button></div>}
    </article>)}
    {!resource.loading && !items.length && <p>No watches recorded yet.</p>}
    {(resource.data?.pages > 1) && <div className="pagination-controls"><button className="text-action" disabled={page <= 1 || resource.loading} onClick={() => setPage(page - 1)}>Previous</button><span>Page {page} of {resource.data.pages}</span><button className="text-action" disabled={page >= resource.data.pages || resource.loading} onClick={() => setPage(page + 1)}>Next</button></div>}
  </section>;
}
