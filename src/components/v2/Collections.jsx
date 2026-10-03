import { useState } from "react";
import { apiRequest } from "../../lib/api.js";
import { Feedback, ResourceState, useAsyncAction, useResource } from "./shared.jsx";

export function CollectionTools({ selected, apiClient = apiRequest, onChanged, onSessionExpired }) {
  const [name, setName] = useState(selected?.name || "");
  const [description, setDescription] = useState(selected?.description || "");
  const [cover, setCover] = useState(selected?.coverStyle || "gold");
  const [smart, setSmart] = useState(Boolean(selected?.rules));
  const [rules, setRules] = useState(selected?.rules || { type: "movie", unwatched: true });
  const [url, setUrl] = useState("");
  const action = useAsyncAction(onSessionExpired);
  const updateRule = (key, value) => setRules(current => ({ ...current, [key]: value }));
  function save(event) {
    event.preventDefault();
    const clean = Object.fromEntries(Object.entries(rules).filter(([,value]) => value !== "" && value != null));
    action.run({ save: () => apiClient(selected ? `/api/v1/lists/${selected.id}` : "/api/v1/lists", { method: selected ? "PATCH" : "POST", body: { name, description, cover_style: cover, rules: smart ? clean : null } }), refresh: onChanged, success: selected ? "Collection updated." : "Collection created." });
  }
  return <details className={`v2-panel collection-cover-${cover}`}><summary>{selected ? "Collection settings and sharing" : "Create a smart collection"}</summary><form onSubmit={save}><div className="v2-form-grid"><label>Name<input required maxLength={120} value={name} onChange={e => setName(e.target.value)} /></label><label>Cover accent<select value={cover} onChange={e => setCover(e.target.value)}>{["gold", "blue", "green", "rose"].map(color => <option key={color}>{color}</option>)}</select></label></div><label>Description<textarea maxLength={1000} value={description} onChange={e => setDescription(e.target.value)} /></label><div className="v2-inline"><label><input type="checkbox" checked={smart} onChange={e => setSmart(e.target.checked)} />Update automatically using rules</label></div>
    {smart && <div className="v2-form-grid"><label>Type<select value={rules.type} onChange={e => updateRule("type", e.target.value)}><option value="movie">Movies</option><option value="show">Shows</option></select></label><label>Genre<input value={rules.genre || ""} onChange={e => updateRule("genre", e.target.value)} placeholder="Thriller" /></label><label>Maximum runtime<input type="number" min="1" max="1440" value={rules.max_runtime || ""} onChange={e => updateRule("max_runtime", e.target.value)} /></label><label>Tag<input value={rules.tag || ""} onChange={e => updateRule("tag", e.target.value)} /></label>{rules.type === "show" && <label>Maximum episodes left<input type="number" min="1" max="1000" value={rules.max_remaining || ""} onChange={e => updateRule("max_remaining", e.target.value)} /></label>}<label><input type="checkbox" checked={Boolean(rules.unwatched)} onChange={e => updateRule("unwatched", e.target.checked)} />Only unwatched titles</label></div>}
    <button className="primary-action" disabled={action.pending} type="submit">{selected ? "Save collection" : "Create collection"}</button></form>
    {selected && <div><p>{selected.shared ? "A share link is active. Anyone with it can see this collection’s titles and description." : "Only you can see this collection until you create a share link."}</p><div className="v2-inline"><button className="secondary-action" type="button" disabled={action.pending} onClick={() => action.run({ save: () => apiClient(`/api/v1/lists/${selected.id}/share`, { method: "POST" }), onSaved: data => setUrl(data.url), refresh: onChanged, success: "Share link created. Any previous link has been revoked." })}>{selected.shared ? "Replace share link" : "Create share link"}</button>{selected.shared && <button className="text-action danger" type="button" disabled={action.pending} onClick={() => action.run({ save: () => apiClient(`/api/v1/lists/${selected.id}/share`, { method: "DELETE" }), onSaved: () => setUrl(""), refresh: onChanged, success: "Sharing revoked." })}>Revoke sharing</button>}</div>{url && <label>Share link<input readOnly value={url} onFocus={e => e.target.select()} /></label>}</div>}
    <Feedback action={action} />
  </details>;
}

export function PublicCollection({ token, apiClient = apiRequest }) {
  const resource = useResource(`/api/v1/collections/${encodeURIComponent(token)}`, apiClient);
  const data = resource.data;
  return <main className="public-profile v2-panel"><a href="/">MediaHub</a><ResourceState resource={resource} />{data && <><span className="eyebrow">Shared collection</span><h1>{data.name}</h1><p>{data.description}</p><p>{data.itemsCount} titles{data.truncated ? ` · showing the first ${data.items.length} matches` : ""}</p><div className="v2-queue">{data.items.map((item, index) => <article key={index}>{item.poster && <img alt="" src={item.poster} width="100" loading="lazy" />}<h2>{item.title}</h2><span>{item.year}</span></article>)}</div></>}</main>;
}
