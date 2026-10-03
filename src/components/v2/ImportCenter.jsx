import { useState } from "react";
import { apiRequest } from "../../lib/api.js";
import { Feedback, ResourceState, useAsyncAction, useResource } from "./shared.jsx";

export function ImportCenter({ apiClient = apiRequest, onSessionExpired }) {
  const [file, setFile] = useState(null);
  const [preview, setPreview] = useState(null);
  const [confirmed, setConfirmed] = useState(false);
  const resource = useResource("/api/v1/imports", apiClient, onSessionExpired);
  const action = useAsyncAction(onSessionExpired);
  function upload(event) {
    event.preventDefault();
    if (!file) return;
    const body = new FormData(); body.append("file", file);
    action.run({ pending: "Uploading and checking your archive…", save: () => apiClient("/api/v1/imports/preview", { method: "POST", body }), onSaved: data => { setPreview(data.batch); setConfirmed(false); }, refresh: resource.refresh, success: "Preview ready. Your library has not changed." });
  }
  return <section className="v2-panel"><h3>Import and recovery</h3><p>Merge a MediaHub JSON export or a supported TV Time JSON/SQLite archive. Existing ratings, notes, and lists are preserved. Maximum file size: 10 MB.</p><form className="v2-inline" onSubmit={upload}><label>Archive<input type="file" accept=".json,.sqlite,.db" required onChange={e => { setFile(e.target.files?.[0] || null); setPreview(null); }} /></label><button className="secondary-action" disabled={action.pending || !file}>Preview import</button></form><Feedback action={action} />
    {preview?.status === "preview" && <div className="v2-panel"><h4>Review your merge</h4><p>{preview.summary.movies} movies · {preview.summary.shows} shows · {preview.summary.watches} watch records</p><p>{preview.summary.existingTitles} titles already exist and will keep their current details. {preview.summary.duplicateWatches || 0} matching watch records will be skipped during the merge.</p><label><input type="checkbox" checked={confirmed} onChange={e => setConfirmed(e.target.checked)} />Merge these records into my library</label><button className="primary-action" type="button" disabled={!confirmed || action.pending} onClick={() => action.run({ pending: "Verifying recovery snapshot and merging…", save: () => apiClient(`/api/v1/imports/${preview.id}/apply`, { method: "POST", body: { confirm: true } }), onSaved: data => setPreview(data.batch), refresh: resource.refresh, success: "Import completed. Your library is ready." })}>Merge archive</button></div>}
    <ResourceState resource={resource} /><h4>Recent imports</h4>{(resource.data?.batches || []).map(batch => <article className="v2-watch-row" key={batch.id}><strong>{new Date(batch.created_at).toLocaleString()} · {batch.status}</strong><p>{batch.summary.movies} movies · {batch.summary.shows} shows · {batch.summary.watches} watch records{batch.summary.duplicateWatchesSkipped != null ? ` · ${batch.summary.duplicateWatchesSkipped} duplicate watches skipped` : ""}</p>{batch.summary.created && <p>Added: {Object.entries(batch.summary.created).map(([key, count]) => `${count} ${key.replaceAll("_", " ")}`).join(", ") || "No new records"}</p>}{batch.status === "completed" && <button className="text-action danger" type="button" disabled={action.pending} onClick={() => { if (window.confirm("Undo this import? Recovery proceeds only if your library has not changed since the merge.")) action.run({ save: () => apiClient(`/api/v1/imports/${batch.id}/recover`, { method: "POST", body: { confirm: true } }), refresh: resource.refresh, success: "The pre-import library was restored and verified." }); }}>Undo this import</button>}</article>)}
  </section>;
}
