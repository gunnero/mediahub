import { useEffect, useRef, useState } from "react";
import { Play, Star, TelevisionSimple, X } from "@phosphor-icons/react";
import { apiRequest, SessionExpiredError } from "../lib/api.js";

function queryString(values) {
  const params = new URLSearchParams();
  Object.entries(values).forEach(([key, value]) => {
    if (value !== undefined && value !== null && String(value).trim() !== "") params.set(key, String(value));
  });
  return params.toString();
}

function dateLabel(value) {
  if (!value) return "";
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? "" : new Intl.DateTimeFormat("en-US", { month: "short", day: "numeric", year: "numeric" }).format(date);
}

function providerSyncLabel(status) {
  return {
    idle: "Catalog not imported",
    never_synced: "Catalog not imported",
    syncing: "Syncing catalog",
    ready: "Catalog ready",
    completed: "Catalog ready",
    completed_with_warnings: "Catalog ready with warnings",
    failed: "Catalog refresh failed",
  }[status] || "Catalog status unavailable";
}

function providerSyncHint(provider) {
  if (provider.syncStatus === "failed") return "Check the connection or provider availability, then refresh again.";
  if (["idle", "never_synced"].includes(provider.syncStatus) && provider.providerType !== "manual") return "Refresh catalog to import content.";
  return "";
}

function initials(title) {
  return String(title || "").split(/\s+/).filter(Boolean).slice(0, 2).map((word) => word[0]).join("").toUpperCase() || "MH";
}

function CatalogCard({ item, onFavorite, onLink, onPlay, onUnlink }) {
  return (
    <article className="catalog-card">
      <div className="catalog-art"><span className="neutral-poster" role="img" aria-label={`Private catalog artwork for ${item.title}`}><span>{initials(item.title)}</span></span>{item.favorite ? <b>Favorite</b> : null}</div>
      <div className="catalog-copy"><small>{[item.category, item.releaseYear].filter(Boolean).join(" · ") || item.kind}</small><strong>{item.title}</strong><span>{item.linked ? `Linked to ${item.link?.canonicalTitle || "library"}` : item.matchStatus === "suggested" ? "Suggested match" : "Needs matching"}</span></div>
      <div className="catalog-actions">
        {item.playable ? <button aria-label={`Play ${item.title}`} className="primary-action compact-action" onClick={() => onPlay(item)} type="button"><Play size={16} weight="fill" /> Play</button> : null}
        {item.kind === "live" ? <button aria-label={`${item.favorite ? "Remove" : "Add"} ${item.title} ${item.favorite ? "from" : "to"} favorites`} className="text-action" onClick={() => onFavorite(item)} type="button"><Star size={16} weight={item.favorite ? "fill" : "regular"} /> {item.favorite ? "Favorited" : "Favorite"}</button> : null}
        {!item.linked && item.kind !== "live" ? <button aria-label={`Link ${item.title}`} className="text-action" onClick={() => onLink(item)} type="button">Link</button> : null}
        {item.linked && item.kind !== "live" ? <button aria-label={`Unlink ${item.title}`} className="text-action danger" onClick={() => onUnlink(item)} type="button">Unlink</button> : null}
      </div>
    </article>
  );
}

function linkPayload(target, aiSuggestion) {
  return target ? { [`${target.type}_id`]: target.id, confirm: true, ...(aiSuggestion ? { ai_suggestion: true } : {}) } : {};
}

export function PlayerSection({ apiClient = apiRequest, onOpenSettings, onRefreshDashboard, onSessionExpired, player }) {
  const [providers, setProviders] = useState([]);
  const [view, setView] = useState("home");
  const [catalog, setCatalog] = useState({ view: "home", categories: [] });
  const [query, setQuery] = useState("");
  const [category, setCategory] = useState("");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState("");
  const [playback, setPlayback] = useState(null);
  const [linkingItem, setLinkingItem] = useState(null);
  const [targetQuery, setTargetQuery] = useState("");
  const [targetType, setTargetType] = useState("movie");
  const [targets, setTargets] = useState([]);
  const [selectedTarget, setSelectedTarget] = useState(null);
  const [confirmLink, setConfirmLink] = useState(false);
  const [aiSuggestion, setAiSuggestion] = useState(null);
  const videoRef = useRef(null);
  const lastProgressSave = useRef(0);
  const providerEnabled = providers.some((provider) => provider.enabled) || Boolean(player?.enabled);

  async function loadCatalog(nextView = view, nextQuery = query, nextCategory = category) {
    setLoading(true); setError("");
    try {
      const [providerPayload, catalogPayload] = await Promise.all([
        apiClient("/api/v1/providers"),
        apiClient(`/api/v1/player/catalog?${queryString({ view: nextView, query: nextQuery, category: nextCategory, per_page: 48 })}`),
      ]);
      setProviders(providerPayload?.providers || []);
      setCatalog(catalogPayload || { view: nextView, categories: [] });
    } catch (loadError) {
      if (loadError instanceof SessionExpiredError) onSessionExpired?.();
      else setError(loadError.message || "Could not load your private player catalog.");
    } finally { setLoading(false); }
  }

  useEffect(() => { loadCatalog(view, query, category); }, [view]);

  useEffect(() => {
    const video = videoRef.current;
    const url = playback?.playbackUrl || "";
    if (!video || !url.includes(".m3u8") || video.canPlayType("application/vnd.apple.mpegurl")) return undefined;
    let hls = null; let cancelled = false;
    import("hls.js/light").then(({ default: Hls }) => { if (!cancelled && Hls.isSupported()) { hls = new Hls(); hls.loadSource(url); hls.attachMedia(video); } });
    return () => { cancelled = true; hls?.destroy(); };
  }, [playback?.playbackUrl]);

  async function act(label, callback) {
    setBusy(label); setError("");
    try { await callback(); }
    catch (actionError) { if (actionError instanceof SessionExpiredError) onSessionExpired?.(); else setError(actionError.message || "Player action failed safely."); }
    finally { setBusy(""); }
  }

  async function playItem(item) {
    await act("play", async () => {
      const payload = await apiClient(`/api/v1/player/items/${item.id}/play`, { method: "POST" });
      setPlayback({ item, session: payload.session, playbackUrl: payload.playbackUrl });
    });
  }

  async function unlinkItem(item) {
    await act("unlink", async () => {
      await apiClient(`/api/v1/player/items/${item.id}/link`, { method: "DELETE" });
      await loadCatalog();
      await onRefreshDashboard?.();
    });
  }

  async function toggleFavorite(item) {
    await act("favorite", async () => {
      await apiClient(`/api/v1/player/items/${item.id}/favorite`, { method: "PATCH", body: { favorite: !item.favorite } });
      await loadCatalog();
    });
  }

  async function saveProgress(completed = false) {
    const video = videoRef.current;
    if (!playback?.session?.id || !video) return;
    await apiClient(`/api/v1/player/sessions/${playback.session.id}`, { method: "PATCH", body: { position_seconds: Math.max(0, Math.floor(video.currentTime || 0)), duration_seconds: Math.max(0, Math.floor(video.duration || 0)), completed } });
    if (completed) await onRefreshDashboard?.();
  }

  function timeUpdate() {
    const now = Date.now();
    if (now - lastProgressSave.current < 15000) return;
    lastProgressSave.current = now;
    saveProgress(false).catch(() => setError("Playback progress could not be saved. Playback can continue."));
  }

  function openLink(item) {
    setLinkingItem(item); setTargetQuery(item.title || ""); setTargetType(item.kind === "episode" ? "episode" : item.kind === "show" ? "show" : "movie"); setTargets([]); setSelectedTarget(null); setConfirmLink(false); setAiSuggestion(null);
    if (item.suggestedMatch) setSelectedTarget({ type: item.suggestedMatch.media_type, id: item.suggestedMatch.candidate_id, title: item.suggestedMatch.title });
  }

  async function askKalveriAI() {
    await act("ai", async () => {
      const payload = await apiClient(`/api/v1/player/items/${linkingItem.id}/ai-match`, { method: "POST" });
      const suggestion = payload?.suggestion || null;
      setAiSuggestion(suggestion);
      if (suggestion?.candidateId) setSelectedTarget({ type: suggestion.mediaType, id: suggestion.candidateId, title: suggestion.candidate?.title || "Suggested item" });
    });
  }

  async function rejectKalveriAISuggestion() {
    await act("ai-reject", async () => {
      await apiClient(`/api/v1/player/items/${linkingItem.id}/ai-match/reject`, { method: "POST" });
      setAiSuggestion(null);
      setSelectedTarget(null);
      setError("");
      setBusy("");
    });
  }

  async function searchTargets(event) {
    event.preventDefault();
    await act("search", async () => { const payload = await apiClient(`/api/v1/player/link-targets?${queryString({ q: targetQuery, type: targetType })}`); setTargets(payload?.targets || []); });
  }

  async function confirmTarget(event) {
    event.preventDefault();
    await act("link", async () => {
      const usesAI = aiSuggestion?.candidateId === selectedTarget?.id && aiSuggestion?.mediaType === selectedTarget?.type;
      await apiClient(`/api/v1/player/items/${linkingItem.id}/link`, { method: "POST", body: linkPayload(selectedTarget, usesAI) });
      setLinkingItem(null); await loadCatalog(); await onRefreshDashboard?.();
    });
  }

  function shelf(title, items = []) {
    return <section className="catalog-shelf"><div className="section-heading"><h2>{title}</h2><span>{items.length}</span></div>{items.length ? <div className="catalog-grid">{items.map((item) => <CatalogCard item={item} key={item.id} onFavorite={toggleFavorite} onLink={openLink} onPlay={playItem} onUnlink={unlinkItem} />)}</div> : <div className="empty-strip compact">Nothing here yet</div>}</section>;
  }

  return (
    <div className="focus-block player-catalog">
      {!providerEnabled && !loading ? <div className="player-connect-state"><Play size={42} weight="duotone" /><span className="eyebrow">Private playback</span><h2>Connect your own provider in Settings to enable private playback.</h2><p>MediaHub never supplies streams. Manual library tracking remains available without a provider.</p><button className="primary-action" onClick={onOpenSettings} type="button">Open Provider Settings</button></div> : null}
      {providerEnabled ? <><header className="player-catalog-header"><div><span className="eyebrow">Your private catalog</span><h2>Player</h2><p>Playback from providers attached only to this account.</p></div><button className="text-action" onClick={onOpenSettings} type="button">Manage providers</button></header><nav className="player-nav" aria-label="Player navigation">{[["home", "Home"], ["movies", "Movies"], ["shows", "Shows"], ["live", "Live TV"], ["guide", "TV Guide"], ["search", "Search"]].map(([id, label]) => <button className={view === id ? "active" : ""} key={id} onClick={() => { setView(id); setCategory(""); }} type="button">{label}</button>)}</nav>
        {view !== "home" ? <form className="catalog-toolbar" onSubmit={(event) => { event.preventDefault(); loadCatalog(); }}><label><span>Search catalog</span><input onChange={(event) => setQuery(event.target.value)} placeholder="Search your provider catalog" type="search" value={query} /></label><label><span>Category</span><select onChange={(event) => setCategory(event.target.value)} value={category}><option value="">All categories</option>{(catalog.categories || []).map((entry) => <option key={entry.name} value={entry.name}>{entry.name} ({entry.count})</option>)}</select></label><button className="secondary-action" type="submit">Search</button></form> : null}
        {error ? <div className="detail-error">{error}</div> : null}{loading ? <div className="empty-strip compact">Loading private catalog...</div> : null}
        {!loading && view === "home" ? <div className="player-home-sections">{shelf("Continue Watching", catalog.continueWatching)}{shelf("Recently Added Movies", catalog.recentMovies)}{shelf("Recently Added Shows", catalog.recentShows)}{shelf("Recently Watched", catalog.recentlyWatched)}{shelf("Linked Library Items", catalog.linkedItems)}{shelf("Needs Matching", catalog.needsMatching)}<section className="category-ribbon"><div className="section-heading"><h2>Categories</h2></div><div>{(catalog.categories || []).map((entry) => <button key={entry.name} onClick={() => { setCategory(entry.name); setView("search"); }} type="button"><strong>{entry.name}</strong><span>{entry.count} items</span></button>)}</div></section></div> : null}
        {!loading && ["movies", "shows", "live", "search"].includes(view) ? ((catalog.items || []).length ? <div className="catalog-grid catalog-browser-grid">{catalog.items.map((item) => <CatalogCard item={item} key={item.id} onFavorite={toggleFavorite} onLink={openLink} onPlay={playItem} onUnlink={unlinkItem} />)}</div> : <div className="empty-strip compact">No catalog items match this view</div>) : null}
        {!loading && view === "guide" ? ((catalog.items || []).length ? <div className="tv-guide-list">{catalog.items.map((item) => <article key={item.id}><strong>{item.title}</strong><span>{item.epg?.current?.title || "No current program"}</span><em>{item.epg?.next?.title ? `Next: ${item.epg.next.title}` : "Schedule unavailable"}</em>{item.playable ? <button className="text-action" onClick={() => playItem(item)} type="button">Watch live</button> : null}</article>)}</div> : <div className="guide-empty"><TelevisionSimple size={34} weight="duotone" /><h3>No TV guide data yet</h3><p>Add XMLTV details in Provider Settings, then refresh the catalog.</p></div>) : null}
      </> : null}
      {playback ? <section className="player-panel playback-panel cinematic-player-panel"><div className="section-heading"><div><span className="eyebrow">Now playing</span><h2>{playback.item.title}</h2></div><span>{playback.item.linked ? "Tracking library history" : "Source-only progress"}</span></div>{!playback.item.linked && playback.item.kind !== "live" ? <div className="data-warning">Progress is saved only to this source until linked.</div> : null}<video className="provider-video" controls data-testid="provider-video" onEnded={() => saveProgress(true).catch(() => setError("Playback completion could not be saved."))} onError={() => setError("Playback is unavailable. Check the provider status in Settings.")} onPause={() => saveProgress(false).catch(() => setError("Playback progress could not be saved. Playback can continue."))} onTimeUpdate={timeUpdate} ref={videoRef} src={playback.playbackUrl} /></section> : null}
      {linkingItem ? <div className="modal-layer" role="presentation" onMouseDown={() => setLinkingItem(null)}><section aria-label={`Link ${linkingItem.title}`} aria-modal="true" className="link-modal" onMouseDown={(event) => event.stopPropagation()} role="dialog"><button className="modal-close" onClick={() => setLinkingItem(null)} type="button" aria-label="Close"><X size={18} /></button><div className="section-heading"><h2>Link to My Library</h2><span>{linkingItem.title}</span></div>{linkingItem.suggestedMatch ? <button className="suggested-target" onClick={() => setSelectedTarget({ type: linkingItem.suggestedMatch.media_type, id: linkingItem.suggestedMatch.candidate_id, title: linkingItem.suggestedMatch.title })} type="button"><strong>Suggested match</strong><span>{linkingItem.suggestedMatch.title}</span></button> : null}<div className="ai-match-panel"><div><strong>Kalveri AI match assist</strong><small>Existing optional fallback. You still confirm the match.</small></div><button className="secondary-action" disabled={busy === "ai"} onClick={askKalveriAI} type="button">Ask Kalveri AI</button></div>{aiSuggestion ? <div className="ai-suggestion ready"><strong>{aiSuggestion.status === "suggested" ? "Suggested match" : "No confident AI match"}</strong><small>{aiSuggestion.reason}</small><button className="text-action danger" onClick={rejectKalveriAISuggestion} type="button">Reject suggestion</button></div> : null}<form className="player-form" onSubmit={searchTargets}><label><span>Search your library</span><input onChange={(event) => setTargetQuery(event.target.value)} value={targetQuery} /></label><label><span>Target type</span><select onChange={(event) => setTargetType(event.target.value)} value={targetType}><option value="movie">Movie</option><option value="show">Show</option><option value="episode">Episode</option></select></label><button className="secondary-action" type="submit">Search library</button></form><div className="target-list">{targets.map((target) => <button className={selectedTarget?.id === target.id && selectedTarget?.type === target.type ? "target-row active" : "target-row"} key={`${target.type}-${target.id}`} onClick={() => setSelectedTarget(target)} type="button"><strong>{target.title}</strong><small>{target.subtitle} {target.meta}</small></button>)}</div><form className="player-form" onSubmit={confirmTarget}><label className="check-row"><input checked={confirmLink} onChange={(event) => setConfirmLink(event.target.checked)} required type="checkbox" /><span>I confirm this source item matches the selected library item.</span></label><button className="primary-action" disabled={!selectedTarget || !confirmLink || busy === "link"} type="submit">Link item</button></form></section></div> : null}
    </div>
  );
}

const emptyProviderForm = { name: "", providerType: "manual", baseUrl: "", username: "", password: "", playlistUrl: "", xmltvUrl: "", epgTimeShift: "0", refreshFrequency: "manual", enabled: true, legalConfirmed: false };

export function SettingsSection({ apiClient = apiRequest, onOpenPlayer, onSessionExpired }) {
  const [section, setSection] = useState("profile");
  const [providers, setProviders] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [status, setStatus] = useState("");
  const [editingId, setEditingId] = useState(null);
  const [form, setForm] = useState(emptyProviderForm);
  const [manualItem, setManualItem] = useState({ providerId: "", title: "", kind: "movie", streamUrl: "" });

  async function loadProviders() {
    setLoading(true);
    try { const payload = await apiClient("/api/v1/providers"); setProviders(payload?.providers || []); }
    catch (loadError) { if (loadError instanceof SessionExpiredError) onSessionExpired?.(); else setError(loadError.message || "Could not load providers."); }
    finally { setLoading(false); }
  }
  useEffect(() => { loadProviders(); }, []);

  function body(includeLegal = false) {
    return { name: form.name.trim(), provider_type: form.providerType, base_url: form.baseUrl.trim() || null, username: form.username.trim() || null, password: form.password || null, playlist_url: form.playlistUrl.trim() || null, xmltv_url: form.xmltvUrl.trim() || null, epg_time_shift: Number(form.epgTimeShift || 0), refresh_frequency: form.refreshFrequency, enabled: form.enabled, ...(includeLegal ? { legal_confirmed: form.legalConfirmed } : {}) };
  }

  async function save(event) {
    event.preventDefault(); setError(""); setStatus("Saving provider...");
    try { const wasEditing = Boolean(editingId); const providerType = form.providerType; await apiClient(editingId ? `/api/v1/providers/${editingId}` : "/api/v1/providers", { method: editingId ? "PATCH" : "POST", body: body(!editingId) }); setEditingId(null); setForm(emptyProviderForm); setStatus(!wasEditing && providerType !== "manual" ? "Provider connected. Refresh catalog to import content." : "Provider saved. Credentials remain encrypted and private."); await loadProviders(); }
    catch (saveError) { setError(saveError.message || "Could not save provider."); setStatus(""); }
  }

  async function testConnection() {
    setError(""); setStatus("Testing provider safely...");
    try { const result = await apiClient("/api/v1/providers/test", { method: "POST", body: { ...body(false), provider_id: editingId } }); setStatus(result.authenticated && (result.catalogAvailable || form.providerType === "manual") ? "Connection verified. Catalog access is ready." : `Connection check: ${result.errorCode || "catalog unavailable"}.`); }
    catch (testError) { setError(testError.message || "Provider test failed safely."); setStatus(""); }
  }

  function edit(provider) {
    setEditingId(provider.id); setSection("providers"); setForm({ ...emptyProviderForm, name: provider.name, providerType: provider.providerType, epgTimeShift: String(provider.epgTimeShift || 0), refreshFrequency: provider.refreshFrequency || "manual", enabled: provider.enabled, legalConfirmed: true }); setStatus("Sensitive values are saved. Enter a new value only when replacing it.");
  }

  async function action(provider, kind) {
    setError(""); setStatus("");
    try { if (kind === "delete") await apiClient(`/api/v1/providers/${provider.id}`, { method: "DELETE" }); if (kind === "toggle") await apiClient(`/api/v1/providers/${provider.id}`, { method: "PATCH", body: { enabled: !provider.enabled } }); if (kind === "refresh") { setStatus("Refreshing your private catalog..."); const response = await apiClient(`/api/v1/providers/${provider.id}/refresh`, { method: "POST" }); setStatus(`Catalog refreshed: ${response.summary?.created || 0} new, ${response.summary?.updated || 0} updated.`); } await loadProviders(); }
    catch (actionError) { await loadProviders(); setStatus(""); setError(actionError.message || "Provider action failed safely."); }
  }

  async function addManualItem(event) {
    event.preventDefault(); setError(""); setStatus("Adding private source item...");
    try {
      await apiClient(`/api/v1/player/sources/${manualItem.providerId}/items`, {
        method: "POST",
        body: { title: manualItem.title.trim(), kind: manualItem.kind, stream_url: manualItem.streamUrl.trim() },
      });
      setManualItem({ providerId: "", title: "", kind: "movie", streamUrl: "" });
      setStatus("Private source item added. Its playback URL remains encrypted.");
      await loadProviders();
    } catch (itemError) { setError(itemError.message || "Could not add private source item."); setStatus(""); }
  }

  const sections = [["profile", "Profile"], ["privacy", "Privacy"], ["library", "Library"], ["providers", "Providers"], ["backups", "Backups"], ["metadata", "Metadata"], ["about", "About"]];
  return (
    <section className="settings-screen"><header><span className="eyebrow">Your MediaHub</span><h2>Settings</h2><p>Control your private library, providers, metadata, and backups.</p></header><nav className="settings-nav" aria-label="Settings sections">{sections.map(([id, label]) => <button className={section === id ? "active" : ""} key={id} onClick={() => setSection(id)} type="button">{label}</button>)}</nav>{error ? <div className="detail-error">{error}</div> : null}{status ? <div className="settings-status">{status}</div> : null}
      {section === "profile" ? <div className="settings-editorial"><h3>Profile</h3><p>Your account identity is used only inside this private MediaHub installation.</p><dl><div><dt>Account</dt><dd>Authenticated member</dd></div><div><dt>Visibility</dt><dd>Private</dd></div></dl></div> : null}
      {section === "privacy" ? <div className="settings-editorial"><h3>Privacy</h3><p>Watch history, ratings, notes, provider catalogs, and playback progress are scoped to your account.</p><dl><div><dt>Provider credentials</dt><dd>Encrypted at rest</dd></div><div><dt>Stream URLs</dt><dd>Owner playback only</dd></div></dl></div> : null}
      {section === "library" ? <div className="settings-editorial"><h3>Library</h3><p>Your canonical library, ratings, notes, and watch history survive provider changes.</p></div> : null}
      {section === "backups" ? <div className="settings-editorial"><h3>Backups</h3><p>Private user backups exclude stream URLs, provider credentials, tokens, and API keys.</p></div> : null}
      {section === "metadata" ? <div className="settings-editorial"><h3>Metadata</h3><p>TMDB enriches canonical titles when enabled. MediaHub still works when it is unavailable.</p></div> : null}
      {section === "about" ? <div className="settings-editorial"><h3>About MediaHub</h3><p>Your entertainment memory: provider-independent history with optional user-owned playback.</p></div> : null}
      {section === "providers" ? <div className="provider-settings-layout"><section className="provider-settings-form"><div className="section-heading"><div><h3>{editingId ? "Edit Provider" : "Add Provider"}</h3><p>Only connect a source you own or are authorized to use.</p></div></div><form className="settings-provider-form" onSubmit={save}><label><span>Provider display name</span><input aria-label="Provider display name" onChange={(event) => setForm((current) => ({ ...current, name: event.target.value }))} required value={form.name} /></label><label><span>Provider type</span><select aria-label="Provider type" disabled={Boolean(editingId)} onChange={(event) => setForm((current) => ({ ...current, providerType: event.target.value }))} value={form.providerType}><option value="manual">Manual source</option><option value="xtream">Xtream-compatible API</option><option value="m3u">M3U playlist</option><option value="xmltv">XMLTV EPG</option><option disabled>Plex (coming later)</option><option disabled>Jellyfin (coming later)</option><option disabled>Emby (coming later)</option></select></label>
        {form.providerType === "xtream" ? <><label><span>Server / base URL</span><input aria-label="Server base URL" onChange={(event) => setForm((current) => ({ ...current, baseUrl: event.target.value }))} placeholder={editingId ? "Saved - enter only to replace" : "https://provider.example"} required={!editingId} type="url" value={form.baseUrl} /></label><label><span>Username</span><input aria-label="Provider username" autoComplete="off" onChange={(event) => setForm((current) => ({ ...current, username: event.target.value }))} placeholder={editingId ? "Saved - enter only to replace" : "Username"} required={!editingId} value={form.username} /></label><label><span>Password</span><input aria-label="Provider password" autoComplete="new-password" onChange={(event) => setForm((current) => ({ ...current, password: event.target.value }))} placeholder={editingId ? "Saved password" : "Password"} required={!editingId} type="password" value={form.password} /></label></> : null}
        {form.providerType === "m3u" ? <label><span>M3U playlist URL</span><input aria-label="M3U playlist URL" onChange={(event) => setForm((current) => ({ ...current, playlistUrl: event.target.value }))} placeholder={editingId ? "Saved - enter only to replace" : "https://provider.example/playlist.m3u"} required={!editingId} type="url" value={form.playlistUrl} /></label> : null}
        {form.providerType !== "manual" ? <label><span>Optional XMLTV URL</span><input aria-label="XMLTV URL" onChange={(event) => setForm((current) => ({ ...current, xmltvUrl: event.target.value }))} placeholder={editingId ? "Saved - enter only to replace" : "https://provider.example/guide.xml"} type="url" value={form.xmltvUrl} /></label> : null}
        <label><span>EPG time shift</span><input aria-label="EPG time shift" max="24" min="-24" onChange={(event) => setForm((current) => ({ ...current, epgTimeShift: event.target.value }))} type="number" value={form.epgTimeShift} /></label><label><span>Refresh frequency</span><select aria-label="Refresh frequency" onChange={(event) => setForm((current) => ({ ...current, refreshFrequency: event.target.value }))} value={form.refreshFrequency}><option value="manual">Manual</option><option value="6h">Every 6 hours</option><option value="12h">Every 12 hours</option><option value="daily">Daily</option></select></label>{!editingId ? <label className="check-row"><input checked={form.legalConfirmed} onChange={(event) => setForm((current) => ({ ...current, legalConfirmed: event.target.checked }))} required type="checkbox" /><span>I own or am authorized to use this provider.</span></label> : null}<div className="modal-actions"><button className="secondary-action" onClick={testConnection} type="button">Test connection</button><button className="primary-action" type="submit">{editingId ? "Save changes" : "Add Provider"}</button>{editingId ? <button className="text-action" onClick={() => setEditingId(null)} type="button">Cancel edit</button> : null}</div></form></section>
        <section className="provider-settings-list"><div className="section-heading"><div><h3>Your Providers</h3><p>Raw URLs and passwords are never displayed.</p></div><span>{providers.length}</span></div>{loading ? <div className="empty-strip compact">Loading providers...</div> : providers.map((provider) => <article className="settings-provider-row" key={provider.id}><div><strong>{provider.name}</strong><span>{provider.providerType} · {provider.enabled ? "Enabled" : "Disabled"}</span><small>{provider.activeItemsCount}/{provider.itemsCount} active items · {providerSyncLabel(provider.syncStatus)}{provider.lastSyncedAt ? ` · last attempt ${dateLabel(provider.lastSyncedAt)}` : ""}</small>{providerSyncHint(provider) ? <small>{providerSyncHint(provider)}</small> : null}<em>{provider.credentialsConfigured ? "Credentials saved" : "No credentials"}{provider.epgAvailable ? " · EPG ready" : ""}</em></div><div><button className="text-action" onClick={() => edit(provider)} type="button">Edit</button><button className="text-action" onClick={() => action(provider, "toggle")} type="button">{provider.enabled ? "Disable" : "Enable"}</button><button className="text-action" disabled={!provider.enabled || provider.syncStatus === "syncing"} onClick={() => action(provider, "refresh")} type="button">{provider.syncStatus === "syncing" ? "Refreshing..." : "Refresh Catalog"}</button><button className="text-action danger" onClick={() => action(provider, "delete")} type="button">Delete</button></div></article>)}{!loading && providers.length === 0 ? <div className="empty-strip compact">No providers connected</div> : null}
          {!loading && providers.some((provider) => provider.providerType === "manual") ? <form className="manual-item-form settings-provider-form" onSubmit={addManualItem}><div className="section-heading"><div><h3>Add Manual Source Item</h3><p>Use this only for a private source you are authorized to play.</p></div></div><label><span>Manual provider</span><select aria-label="Manual provider" onChange={(event) => setManualItem((current) => ({ ...current, providerId: event.target.value }))} required value={manualItem.providerId}><option value="">Select provider</option>{providers.filter((provider) => provider.providerType === "manual").map((provider) => <option key={provider.id} value={provider.id}>{provider.name}</option>)}</select></label><label><span>Item title</span><input aria-label="Item title" onChange={(event) => setManualItem((current) => ({ ...current, title: event.target.value }))} required value={manualItem.title} /></label><label><span>Media type</span><select aria-label="Media type" onChange={(event) => setManualItem((current) => ({ ...current, kind: event.target.value }))} value={manualItem.kind}><option value="movie">Movie</option><option value="show">Show</option><option value="episode">Episode</option><option value="live">Live channel</option></select></label><label><span>Private playback URL</span><input aria-label="Private playback URL" onChange={(event) => setManualItem((current) => ({ ...current, streamUrl: event.target.value }))} required type="url" value={manualItem.streamUrl} /></label><button className="secondary-action" type="submit">Add Source Item</button></form> : null}
          <button className="secondary-action" onClick={onOpenPlayer} type="button">Open Player</button></section></div> : null}
    </section>
  );
}
