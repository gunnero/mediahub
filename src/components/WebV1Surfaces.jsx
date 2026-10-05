import { OfflineSettings } from "./v2/OfflineLibrary.jsx";
import { ReleasePlanner } from "./v2/ReleasePlanner.jsx";
import { ImportCenter } from "./v2/ImportCenter.jsx";
import { ViewingStory } from "./v2/ViewingStory.jsx";
import { CollectionTools } from "./v2/Collections.jsx";
import { useEffect, useMemo, useRef, useState } from "react";
import {
  ArrowLeft,
  Bell,
  CalendarDots,
  CaretLeft,
  CaretRight,
  CheckCircle,
  DownloadSimple,
  FilmSlate,
  ListBullets,
  MagnifyingGlass,
  PencilSimple,
  Plus,
  Trash,
} from "@phosphor-icons/react";
import { apiRequest, SessionExpiredError } from "../lib/api.js";
import { discoveryFilters } from "../lib/discovery.js";
import { useAsyncAction } from "../lib/useAsyncAction.js";
import { PrivacyControls } from "./ProfileSurfaces.jsx";

function encodeQuery(params) {
  const query = new URLSearchParams();
  Object.entries(params).forEach(([key, value]) => {
    if (value !== "" && value !== null && value !== undefined) query.set(key, String(value));
  });
  return query.toString();
}

function initials(title) {
  return String(title || "Media").split(/\s+/).filter(Boolean).slice(0, 2).map((word) => word[0]).join("").toUpperCase();
}

function Artwork({ item }) {
  return item?.poster ? <img alt="" loading="lazy" src={item.poster} /> : <span className="neutral-poster" role="img" aria-label={`No poster for ${item?.title || "title"}`}><span>{initials(item?.title)}</span></span>;
}

export function DiscoveryDetailPage({ actions, error = "", feedback, loading = false, onClose, preview }) {
  const headingRef = useRef(null);
  useEffect(() => {
    if (!preview) return undefined;
    const previousFocus = document.activeElement;
    headingRef.current?.focus({ preventScroll: true });
    return () => { if (previousFocus?.isConnected) previousFocus.focus({ preventScroll: true }); };
  }, [preview?.media_type, preview?.tmdb_id]);
  if (!preview) return null;
  const cast = preview.people?.cast || [];
  const creators = preview.people?.directors || [];
  const production = [
    ...(preview.production?.companies || []),
    ...(preview.production?.countries || []),
    ...(preview.production?.languages || []),
  ];
  const facts = [
    preview.year,
    preview.runtime ? `${preview.runtime} min` : null,
    preview.season_count ? `${preview.season_count} season${preview.season_count === 1 ? "" : "s"}` : null,
    preview.episode_count ? `${preview.episode_count} episodes` : null,
    preview.status,
    preview.vote_average ? `TMDB ${preview.vote_average}/10` : null,
    ...(preview.genres || []),
  ].filter(Boolean);

  return <section className="discovery-preview discovery-detail-page" aria-label={`${preview.title} discovery preview`}>
    {preview.backdrop ? <div className="discovery-preview-backdrop" aria-hidden="true"><img alt="" src={preview.backdrop} /></div> : null}
    <button className="page-back" onClick={onClose} type="button"><ArrowLeft size={18} /> Back to results</button>
    <div className="discovery-preview-art"><Artwork item={preview} /></div>
    <div className="discovery-preview-content">
      <span className="eyebrow">{preview.media_type}</span>
      <h1 ref={headingRef} tabIndex={-1}>{preview.title}</h1>
      {preview.original_title && preview.original_title !== preview.title ? <small className="detail-original-title">Original title: {preview.original_title}</small> : null}
      {preview.tagline ? <p className="detail-tagline">“{preview.tagline}”</p> : null}
      <div className="metadata-strip">{facts.map((tag) => <span key={tag}>{tag}</span>)}</div>
      {actions ? <div className="discovery-preview-actions discovery-preview-actions--top" role="group" aria-label="Quick actions">{actions}</div> : null}
      {preview.watched ? <p className="discovery-memory-state"><CheckCircle size={17} weight="fill" /> You watched this{preview.watched_count > 1 ? ` ${preview.watched_count} times` : ""}.</p> : null}
      <section className="discovery-detail-section"><strong>Plot</strong><p>{preview.overview || "No overview is available yet."}</p></section>
      {loading ? <div className="empty-strip compact">Loading complete details...</div> : null}
      {error ? <div className="detail-error">{error}</div> : null}
      {feedback}
      {cast.length || creators.length ? <section className="discovery-detail-section people-section"><div className="detail-section-heading"><strong>Cast & creators</strong><span>{cast.length + creators.length} people</span></div><div className="people-grid">{[...creators, ...cast].map((person, index) => <article key={`${person.id || person.name}-${index}`}>{person.image ? <img alt="" loading="lazy" src={person.image} /> : <span className="person-fallback">{initials(person.name)}</span>}<span><strong>{person.name}</strong><small>{person.role || "Cast"}</small></span></article>)}</div></section> : null}
      {production.length ? <section className="discovery-detail-section production-section"><div className="detail-section-heading"><strong>Production</strong></div><div className="metadata-strip">{production.map((fact) => <span key={fact}>{fact}</span>)}</div></section> : null}
      {actions ? <div className="discovery-preview-actions discovery-preview-actions--bottom" role="group" aria-label="Actions after details">{actions}</div> : null}
      {!preview.already_in_library ? <p className="discovery-action-help"><strong>Library</strong> saves the title to your permanent collection. <strong>Watchlist</strong> saves it and marks it as something you plan to watch.</p> : null}
    </div>
  </section>;
}

function ActionFeedback({ action }) {
  return <>{action.error ? <div className="detail-error" role="alert">{action.error} <button className="text-action" disabled={action.pending} onClick={action.retry} type="button">{action.refreshOnly ? "Retry refresh" : "Try again"}</button></div> : null}{action.status ? <div className="settings-status" role="status">{action.status}</div> : null}</>;
}

function useSafeLoad(loader, dependencies, onSessionExpired) {
  const [state, setState] = useState({ loading: true, error: "", data: null });
  useEffect(() => {
    let cancelled = false;
    setState((current) => ({ ...current, loading: true, error: "" }));
    loader().then((data) => {
      if (!cancelled) setState({ loading: false, error: "", data });
    }).catch((error) => {
      if (cancelled) return;
      if (error instanceof SessionExpiredError) onSessionExpired?.();
      else setState({ loading: false, error: error.message || "This section could not be loaded.", data: null });
    });
    return () => { cancelled = true; };
  }, dependencies);
  return [state, setState];
}

export function DiscoverSection({ apiClient = apiRequest, filters, onFiltersChange, previewTarget, onPreview, onClosePreview, initialType = "all", navigationKey = 0, onLibraryChanged, onOpen, onSessionExpired }) {
  const [localFilters, setLocalFilters] = useState(() => discoveryFilters({ type: initialType }));
  const currentFilters = filters || localFilters;
  const { query, mode, type, category, page, genre, year, language, max_runtime, min_rating, hide_watched } = currentFilters;
  const advanced = Boolean(genre || year || language || max_runtime || min_rating || hide_watched || category === "recommended");
  const [reload, setReload] = useState(0);
  const [state, setState] = useState({ loading: true, error: "", items: [], totalPages: 0 });
  const action = useAsyncAction(onSessionExpired);
  const adding = action.pending;
  const [preview, setPreview] = useState(null);
  const [previewError, setPreviewError] = useState("");
  const [previewLoading, setPreviewLoading] = useState(false);
  const previewRequest = useRef(null);
  const sessionExpired = useRef(onSessionExpired);
  sessionExpired.current = onSessionExpired;

  function changeFilters(changes, options) {
    const next = discoveryFilters({ ...currentFilters, page: 1, ...changes });
    closePreview();
    action.clear();
    if (onFiltersChange) onFiltersChange(next, options);
    else setLocalFilters(next);
  }

  useEffect(() => () => { previewRequest.current?.abort(); previewRequest.current = null; }, []);
  useEffect(() => {
    if (previewTarget) openPreview(previewTarget);
    else closePreview();
  }, [previewTarget?.media_type, previewTarget?.tmdb_id]);
  const categories = [
    ["recommended", "For you"],
    ["trending", "Trending"],
    ["popular", "Popular"],
    ["now_playing", "Now Playing"],
    ["upcoming", "Upcoming"],
    ["top_rated", "Top Rated"],
  ];

  useEffect(() => {
    if (!filters) setLocalFilters(discoveryFilters({ type: initialType }));
  }, [initialType, navigationKey]);

  useEffect(() => {
    const safeQuery = query.trim();
    if (mode === "library" && safeQuery.length < 2) {
      setState({ loading: false, error: "", items: [], totalPages: 0 });
      return undefined;
    }
    const controller = new AbortController();
    setState((current) => ({ ...current, loading: true, error: "" }));
    const timer = window.setTimeout(async () => {
      try {
        const endpoint = mode === "discover"
          ? safeQuery.length >= 2
            ? `/api/v1/discover/search?${encodeQuery({ query: safeQuery, type, page })}`
            : `/api/v1/discover/${advanced ? "curated" : "browse"}?${encodeQuery({ category, type, page, ...(advanced ? { genre, year, language, max_runtime, min_rating, hide_watched } : {}) })}`
          : `/api/v1/library/search?${encodeQuery({ query: safeQuery, type, limit: 30 })}`;
        const payload = await apiClient(endpoint, { signal: controller.signal });
        if (controller.signal.aborted) return;
        const items = mode === "discover" ? payload.items || [] : [
          ...(payload.movies || []),
          ...(payload.shows || []),
          ...(payload.episodes || []),
        ];
        const error = payload.status === "disabled" ? "Discovery is unavailable until TMDB is enabled." : payload.status === "unavailable" ? "Discovery is temporarily unavailable. Try again." : "";
        setState({ loading: false, error, items, totalPages: Math.min(500, Math.max(0, Number(payload.pagination?.totalPages) || 0)) });
      } catch (error) {
        if (controller.signal.aborted || error?.name === "AbortError") return;
        if (error instanceof SessionExpiredError) sessionExpired.current?.();
        else setState((current) => ({ ...current, loading: false, error: error.message || "Search is temporarily unavailable." }));
      }
    }, 350);
    return () => {
      window.clearTimeout(timer);
      controller.abort();
    };
  }, [apiClient, category, mode, page, query, reload, type, genre, year, language, max_runtime, min_rating, hide_watched, advanced]);

  function add(item, kind) {
    if (!item) return;
    const plural = item.media_type === "show" ? "shows" : "movies";
    return action.run({
      save: () => apiClient(`/api/v1/discover/${plural}/${item.tmdb_id}/add`, { method: "POST", body: { action: kind } }),
      onSaved: (payload) => {
        const updated = { already_in_library: true, existing_library_id: payload.item?.id };
        setState((current) => ({ ...current, items: current.items.map((candidate) => candidate.tmdb_id === item.tmdb_id && candidate.media_type === item.media_type ? { ...candidate, ...updated } : candidate) }));
        setPreview((current) => current?.tmdb_id === item.tmdb_id && current?.media_type === item.media_type ? { ...current, ...updated } : current);
      },
      refresh: () => onLibraryChanged?.(),
      success: "Title added.",
    });
  }

  function closePreview() {
    previewRequest.current?.abort();
    previewRequest.current = null;
    setPreview(null);
    setPreviewLoading(false);
    setPreviewError("");
  }

  async function openPreview(item) {
    previewRequest.current?.abort();
    const controller = new AbortController();
    previewRequest.current = controller;
    setPreview(item);
    setPreviewLoading(true);
    setPreviewError("");
    try {
      const payload = await apiClient(`/api/v1/discover/${item.media_type}/${item.tmdb_id}`, { signal: controller.signal });
      if (previewRequest.current !== controller) return;
      if (payload?.item) setPreview((current) => ({ ...current, ...payload.item, already_in_library: current.already_in_library || payload.item.already_in_library, existing_library_id: current.existing_library_id || payload.item.existing_library_id }));
      else setPreviewError("Complete details are temporarily unavailable.");
    } catch (error) {
      if (previewRequest.current !== controller || error?.name === "AbortError") return;
      if (error instanceof SessionExpiredError) sessionExpired.current?.();
      else setPreviewError("Complete details are temporarily unavailable. The basic title information is still shown.");
    } finally {
      if (previewRequest.current === controller) setPreviewLoading(false);
    }
  }

  function openResult(item, canonicalItem) {
    if (mode === "library" || item.already_in_library) {
      closePreview();
      onOpen?.(canonicalItem);
      return;
    }

    if (onPreview) onPreview(item);
    else openPreview(item);
  }

  return <section className="web-v1-screen discover-screen">
    <div hidden={Boolean(preview || previewTarget)} className="discover-results-content">
    <header className="screen-intro"><span className="eyebrow">Find your next story</span><h1>Discover</h1><p>Your next favorite is out there. Find it here.</p></header>
    <div className="discovery-controls"><div className="segmented-control" aria-label="Search source"><button className={mode === "library" ? "active" : ""} onClick={() => changeFilters({ mode: "library" })} type="button">My Library</button><button className={mode === "discover" ? "active" : ""} onClick={() => changeFilters({ mode: "discover" })} type="button">Discover</button></div>
    <div className="discovery-search-row"><label><MagnifyingGlass size={20} /><input aria-label="Search movies and shows" maxLength={120} onChange={(event) => changeFilters({ query: event.target.value }, { replace: true })} placeholder={mode === "discover" ? "Search TMDB movies and shows" : "Search your movies, shows, and episodes"} type="search" value={query} /></label><select aria-label="Media type" onChange={(event) => changeFilters({ type: event.target.value })} value={type}><option value="all">Movies and shows</option><option value="movie">Movies</option><option value="show">Shows</option>{mode === "library" ? <option value="episode">Episodes</option> : null}</select></div></div>
    {mode === "discover" && query.trim().length < 2 ? <div className="discovery-categories" role="tablist" aria-label="Discovery categories">{categories.map(([id, label]) => <button aria-selected={category === id} className={category === id ? "active" : ""} key={id} onClick={() => changeFilters({ category: id })} role="tab" type="button">{label}</button>)}</div> : null}
    {mode === "discover" && <details className="v2-panel"><summary>Refine discovery</summary><p>These filters apply while browsing. Clear the title search to use them.</p><fieldset disabled={query.trim().length >= 2} className="v2-form-grid"><label>Genre<select value={genre || ""} onChange={e => changeFilters({ genre: e.target.value })}><option value="">Any genre</option>{["Drama", "Comedy", "Crime", "Mystery", "Documentary", "Animation", "Family", "Horror", "Thriller", "Science Fiction", "Sci-Fi & Fantasy"].map(value => <option key={value}>{value}</option>)}</select></label><label>Release year<input type="number" min="1888" max="2100" value={year || ""} onChange={e => changeFilters({ year: e.target.value }, { replace: true })} /></label><label>Original language<select value={language || ""} onChange={e => changeFilters({ language: e.target.value })}><option value="">Any language</option>{[["en", "English"], ["mk", "Macedonian"], ["es", "Spanish"], ["fr", "French"], ["de", "German"], ["ja", "Japanese"], ["ko", "Korean"]].map(([id, name]) => <option value={id} key={id}>{name}</option>)}</select></label><label>Maximum minutes<input type="number" min="1" max="1440" value={max_runtime || ""} onChange={e => changeFilters({ max_runtime: e.target.value }, { replace: true })} /></label><label>Minimum rating<select value={min_rating || ""} onChange={e => changeFilters({ min_rating: e.target.value })}><option value="">Any rating</option>{[5,6,7,8,9].map(value => <option key={value}>{value}</option>)}</select></label><label><input type="checkbox" checked={hide_watched === "1"} onChange={e => changeFilters({ hide_watched: e.target.checked ? "1" : "" })} />Hide watched titles</label></fieldset><button className="text-action" disabled={adding} type="button" onClick={() => action.run({ save: () => apiClient("/api/v1/discover/dismiss", { method: "DELETE" }), refresh: async () => setReload(value => value + 1), success: "Dismissed suggestions restored." })}>Restore dismissed suggestions</button></details>}
    {state.error ? <div className="detail-error" role="alert">{state.error} <button className="text-action" disabled={state.loading} onClick={() => setReload((value) => value + 1)} type="button">Retry search</button></div> : null}
    {!preview ? <ActionFeedback action={action} /> : null}
    {state.loading ? <div className="empty-strip compact">Searching...</div> : null}
    {!state.loading && mode === "library" && query.trim().length < 2 ? <div className="empty-strip compact">Type at least two characters to search your library</div> : null}
    {!state.loading && mode === "discover" && query.trim().length < 2 && !state.items.length && !state.error ? <div className="empty-strip compact">No titles are available in this category right now</div> : null}
    {!state.loading && query.trim().length >= 2 && !state.items.length && !state.error ? <div className="empty-strip compact">No results found</div> : null}
    <p className="discovery-action-help"><strong>Library</strong> keeps a title in your permanent collection. <strong>Watchlist</strong> also marks it as something you plan to watch.</p>
    <div className="discovery-results-grid">{state.items.map((item) => {
      const mediaType = item.media_type || item.kind;
      const canonicalItem = { ...item, kind: mediaType, movieId: mediaType === "movie" ? (item.existing_library_id || item.movieId || item.id) : undefined, showId: mediaType === "show" ? (item.existing_library_id || item.showId || item.id) : undefined, episodeId: mediaType === "episode" ? (item.episodeId || item.id) : undefined };
      return <article className="discovery-result" key={`${mediaType}-${item.tmdb_id || item.id}`}><button className="discovery-art" aria-label={`View ${item.title}`} onClick={() => openResult(item, canonicalItem)} type="button"><Artwork item={item} /></button><div><span className="eyebrow">{mediaType}</span><button className="discovery-title" aria-label={`Open ${item.title} details`} onClick={() => openResult(item, canonicalItem)} type="button"><h3>{item.title}</h3></button><small className="discovery-year">{item.year || item.releaseYear || "Year unavailable"}</small>{item.reason && <p className="settings-status">{item.reason}</p>}{mode === "discover" && item.tmdb_id && <button className="text-action" type="button" disabled={adding} onClick={() => action.run({ save: () => apiClient("/api/v1/discover/dismiss", { method: "POST", body: { type: mediaType, tmdb_id: item.tmdb_id } }), onSaved: () => setState(current => ({ ...current, items: current.items.filter(candidate => candidate.tmdb_id !== item.tmdb_id || candidate.media_type !== mediaType) })), success: "Suggestion dismissed." })}>Not interested</button>}{item.watched ? <b className="discovery-watched"><CheckCircle size={15} weight="fill" /> {item.watched_count > 1 ? `Watched ${item.watched_count} times` : "Watched"}</b> : null}<p>{item.overview || item.subtitle || "No overview is available yet."}</p>{mode === "discover" ? <div className="result-actions">{item.already_in_library ? <button className="secondary-action" onClick={() => onOpen?.(canonicalItem)} type="button"><CheckCircle size={17} /> Already in Library</button> : <><button className="primary-action" disabled={Boolean(adding)} onClick={() => add(item, "library")} type="button">Add to Library</button><button className="secondary-action" disabled={Boolean(adding)} onClick={() => add(item, "watchlist")} type="button">Add to Watchlist</button>{mediaType === "movie" ? <button className="text-action" disabled={Boolean(adding)} onClick={() => add(item, "watched")} type="button">Mark Watched</button> : null}</>}</div> : <button className="secondary-action" onClick={() => onOpen?.(canonicalItem)} type="button">Open details</button>}</div></article>;
    })}</div>
    {mode === "discover" && (state.totalPages > 1 || page > 1) ? <nav className="library-pagination discovery-pagination" aria-label="Discovery pages"><button className="secondary-action" disabled={state.loading || page <= 1} onClick={() => changeFilters({ page: page - 1 })} aria-label="Previous page" type="button">Previous</button><span aria-live="polite">Page {page}{state.totalPages ? ` of ${state.totalPages}` : ""}</span><button className="secondary-action" disabled={state.loading || Boolean(state.error) || page >= state.totalPages} onClick={() => changeFilters({ page: page + 1 })} aria-label="Next page" type="button">Next</button></nav> : null}
    </div>
    <DiscoveryDetailPage feedback={<ActionFeedback action={action} />} actions={<div className="modal-actions">{preview?.already_in_library ? <button className="primary-action" onClick={() => openResult(preview, { ...preview, kind: preview.media_type, movieId: preview.media_type === "movie" ? preview.existing_library_id : undefined, showId: preview.media_type === "show" ? preview.existing_library_id : undefined })} type="button">Open in My Library</button> : <><button className="primary-action" disabled={Boolean(adding)} onClick={() => add(preview, "library")} type="button">Add to Library</button><button className="secondary-action" disabled={Boolean(adding)} onClick={() => add(preview, "watchlist")} type="button">Add to Watchlist</button></>}</div>} error={previewError} loading={previewLoading} onClose={() => { closePreview(); onClosePreview?.(); }} preview={preview} />
  </section>;
}

function rangeFor(date, view) {
  const value = new Date(date);
  if (view === "day") return { from: value, to: value };
  if (view === "week") {
    const day = value.getDay() || 7;
    const from = new Date(value); from.setDate(value.getDate() - day + 1);
    const to = new Date(from); to.setDate(from.getDate() + 6);
    return { from, to };
  }
  return { from: new Date(value.getFullYear(), value.getMonth(), 1), to: new Date(value.getFullYear(), value.getMonth() + 1, 0) };
}

function isoDate(date) {
  const year = date.getFullYear();
  return `${year}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
}

export function CalendarSection({ apiClient = apiRequest, onOpen, onSessionExpired }) {
  const [cursor, setCursor] = useState(() => new Date());
  const [view, setView] = useState("month");
  const [type, setType] = useState("all");
  const range = useMemo(() => rangeFor(cursor, view), [cursor, view]);
  const endpoint = `/api/v1/calendar?${encodeQuery({ date_from: isoDate(range.from), date_to: isoDate(range.to), type })}`;
  const [state] = useSafeLoad(() => apiClient(endpoint), [apiClient, endpoint], onSessionExpired);

  function move(direction) {
    setCursor((current) => {
      const next = new Date(current);
      if (view === "month") next.setMonth(next.getMonth() + direction);
      else next.setDate(next.getDate() + direction * (view === "week" ? 7 : 1));
      return next;
    });
  }

  const days = [];
  for (let day = new Date(range.from); day <= range.to; day = new Date(day.getFullYear(), day.getMonth(), day.getDate() + 1)) days.push(new Date(day));

  const isEmpty = !state.loading && !(state.data?.items || []).length;

  return <section className="web-v1-screen calendar-screen">
    <header className="screen-intro"><span className="eyebrow">What is coming</span><h1>Release calendar</h1><p>Upcoming episodes from followed shows and movie releases from your watchlist.</p></header>
    <ReleasePlanner apiClient={apiClient} onSessionExpired={onSessionExpired} />
    <div className="calendar-toolbar"><div><button aria-label="Previous period" className="icon-action" onClick={() => move(-1)} type="button"><CaretLeft /></button><strong>{range.from.toLocaleDateString("en-US", { month: "long", year: "numeric" })}</strong><button aria-label="Next period" className="icon-action" onClick={() => move(1)} type="button"><CaretRight /></button><button className="text-action" onClick={() => setCursor(new Date())} type="button">Today</button></div><div className="segmented-control">{["day", "week", "month"].map((option) => <button className={view === option ? "active" : ""} key={option} onClick={() => setView(option)} type="button">{option[0].toUpperCase() + option.slice(1)}</button>)}</div><select aria-label="Calendar media type" onChange={(event) => setType(event.target.value)} value={type}><option value="all">All releases</option><option value="movies">Movies</option><option value="episodes">Episodes</option></select></div>
    {state.data?.range?.timezone ? <p className="calendar-timezone">Dates use {state.data.range.timezone}.</p> : null}
    {state.error ? <div className="detail-error">{state.error}</div> : null}
    {state.loading ? <div className="empty-strip compact">Loading calendar...</div> : null}
    {isEmpty ? <div className="empty-strip compact">No releases are scheduled here yet. Follow a show or add a movie to your watchlist and upcoming dates will appear automatically.</div> : null}
    {!state.loading && !isEmpty ? <div className={`calendar-grid ${view}`}>{days.map((day) => { const key = isoDate(day); const items = state.data?.days?.[key] || []; return <section className={`calendar-day ${items.length ? "has-releases" : "no-releases"} ${key === isoDate(new Date()) ? "today" : ""}`} key={key}><header><span>{day.toLocaleDateString("en-US", { weekday: "short" })}</span><strong>{day.getDate()}</strong></header>{items.map((item) => <button key={item.id} onClick={() => onOpen?.(item)} type="button"><i className={item.releaseKind || item.kind} /><span><strong>{item.title}</strong><small>{item.subtitle}</small></span></button>)}</section>; })}</div> : null}
  </section>;
}

function alertMatchesFilter(alert, filter) {
  if (filter === "all") return true;
  const type = alert.payload?.alert_type;
  if (filter === "upcoming") return alert.category === "upcoming" || ["upcoming_episode", "upcoming_movie"].includes(type);
  if (filter === "movies") return alert.category === "movies" || ["upcoming_movie", "watchlist_release"].includes(type);
  if (filter === "new-episodes") return alert.category === "new-episodes" || type === "new_episode";
  if (filter === "reminders") return alert.category === "reminders" || type === "continue_watching";
  return alert.category === filter;
}

export function AlertsSection({ apiClient = apiRequest, onAlertsChanged, onOpen, onSessionExpired }) {
  const [reload, setReload] = useState(0);
  const [filter, setFilter] = useState("all");
  const [state, setState] = useSafeLoad(() => apiClient("/api/v1/alerts"), [apiClient, reload], onSessionExpired);
  const items = (state.data?.alerts || []).filter((item) => alertMatchesFilter(item, filter));
  const action = useAsyncAction(onSessionExpired);
  async function refresh() {
    const data = await apiClient("/api/v1/alerts");
    setState({ loading: false, error: "", data });
    await onAlertsChanged?.();
  }
  function markRead(alert) {
    return action.run({
      save: () => apiClient(alert ? `/api/v1/alerts/${alert.id}/read` : "/api/v1/alerts/read-all", { method: "POST" }),
      onSaved: () => setState((current) => ({ ...current, data: { ...current.data, alerts: (current.data?.alerts || []).map((item) => !alert || item.id === alert.id ? { ...item, unread: false } : item) } })),
      refresh,
      success: alert ? "Alert marked read." : "All alerts marked read.",
    });
  }
  return <section className="web-v1-screen alerts-screen"><header className="screen-intro"><span className="eyebrow">Stay current</span><h1>Alerts</h1><p>Release reminders and library issues that deserve your attention.</p></header><div className="alerts-toolbar"><div className="segmented-control">{[["all", "All"], ["new-episodes", "New episodes"], ["upcoming", "Upcoming"], ["movies", "Movies"], ["reminders", "Reminders"]].map(([id, label]) => <button className={filter === id ? "active" : ""} key={id} onClick={() => setFilter(id)} type="button">{label}</button>)}</div><button className="text-action" disabled={action.pending || state.loading} onClick={() => markRead(null)} type="button">Mark all read</button></div><ActionFeedback action={action} />{state.error ? <div className="detail-error" role="alert">{state.error} <button className="text-action" onClick={() => setReload((value) => value + 1)} type="button">Retry loading alerts</button></div> : null}{state.loading ? <div className="empty-strip compact">Loading alerts...</div> : null}<div className="alerts-list">{items.map((alert) => <article className={alert.unread ? "unread" : ""} key={alert.id}><button onClick={() => onOpen?.({ ...alert, ...alert.payload })} type="button"><Bell size={22} /><span><strong>{alert.title}</strong><small>{alert.subtitle}</small></span><em>{alert.dueText}</em></button>{alert.unread ? <button aria-label={`Mark ${alert.title} read`} className="text-action" disabled={action.pending} onClick={() => markRead(alert)} type="button">Mark read</button> : null}</article>)}</div>{!state.loading && !items.length ? <div className="empty-strip compact">No alerts in this view</div> : null}</section>;
}

export function StatsSection({ apiClient = apiRequest, onSessionExpired }) {
  const [range, setRange] = useState({ from: "", to: "" });
  const [state] = useSafeLoad(() => apiClient(`/api/v1/stats?${encodeQuery(range)}`), [apiClient, range.from, range.to], onSessionExpired);
  const data = state.data || { summary: {}, monthlyActivity: [], yearlyActivity: [], genres: [], ratings: [], topShows: [], topMovies: [] };
  const maxMonthly = Math.max(1, ...data.monthlyActivity.map((item) => item.minutes));
  const maxYearly = Math.max(1, ...data.yearlyActivity.map((item) => item.minutes));

  return (
    <section className="web-v1-screen stats-screen">
      <header className="screen-intro">
        <span className="eyebrow">Your viewing life</span>
        <h1>Stats</h1>
        <p>The stories you spend time with, and the habits that make them yours.</p>
      </header>
      <ViewingStory range={range} onRange={setRange} data={data} apiClient={apiClient} onSessionExpired={onSessionExpired} />
      {state.error ? <div className="detail-error">{state.error}</div> : null}
      {state.loading ? <div className="empty-strip compact">Calculating your stats...</div> : (
        <>
          <div className="stat-summary-grid">
            {[["Movies", data.summary.moviesWatched], ["Episodes", data.summary.episodesWatched], ["Shows caught up (all time)", data.summary.showsCompleted], ["Hours watched", data.summary.totalWatchHours], ["Rewatches", data.summary.rewatchCount], ["Longest streak", `${data.summary.longestStreakDays || 0} days`]].map(([label, value]) => (
              <article key={label}><span>{label}</span><strong>{value || 0}</strong></article>
            ))}
          </div>
          <div className="stats-panels">
            <section>
              <div className="section-heading"><h3>Monthly activity</h3></div>
              {data.monthlyActivity.length ? <div className="monthly-bars">{data.monthlyActivity.slice(-12).map((item) => <div key={item.period}><span style={{ height: `${Math.max(5, (item.minutes / maxMonthly) * 100)}%` }} /><small>{item.period.slice(5)}</small><em>{Math.round(item.minutes / 60)}h</em></div>)}</div> : <div className="empty-strip compact">No monthly activity yet</div>}
            </section>
            <section>
              <div className="section-heading"><h3>Yearly activity</h3></div>
              {data.yearlyActivity.length ? <div className="yearly-bars">{data.yearlyActivity.map((item) => <div key={item.period}><span><i style={{ width: `${Math.max(4, (item.minutes / maxYearly) * 100)}%` }} /></span><strong>{item.period}</strong><em>{Math.round(item.minutes / 60)} hours · {item.watches} watches</em></div>)}</div> : <div className="empty-strip compact">No yearly activity yet</div>}
            </section>
            <section>
              <div className="section-heading"><h3>Genres</h3></div>
              <div className="ranked-list">{data.genres.map((item, index) => <div key={item.genre}><b>{index + 1}</b><span>{item.genre}</span><em>{item.count}</em></div>)}</div>
            </section>
            <section>
              <div className="section-heading"><h3>Ratings</h3></div>
              {data.ratings.length ? <div className="rating-distribution">{data.ratings.map((item) => <div key={item.rating}><strong>{item.rating}/10</strong><span><i style={{ width: `${Math.max(6, (item.count / Math.max(1, ...data.ratings.map((rating) => rating.count))) * 100)}%` }} /></span><em>{item.count}</em></div>)}</div> : <div className="empty-strip compact">Your rating distribution will appear here</div>}
            </section>
            <section>
              <div className="section-heading"><h3>Top shows</h3></div>
              <div className="ranked-list">{data.topShows.map((item, index) => <div key={item.id}><b>{index + 1}</b><span>{item.title}</span><em>{item.episodes} episodes</em></div>)}</div>
            </section>
            <section>
              <div className="section-heading"><h3>Top movies</h3></div>
              <div className="ranked-list">{data.topMovies.map((item, index) => <div key={item.id}><b>{index + 1}</b><span>{item.title}</span><em>{item.watches} watches</em></div>)}</div>
            </section>
          </div>
        </>
      )}
    </section>
  );
}

export function ListsSection({ apiClient = apiRequest, onOpen, onSessionExpired }) {
  const [reload, setReload] = useState(0);
  const [selectedId, setSelectedId] = useState(null);
  const [name, setName] = useState("");
  const [rename, setRename] = useState("");
  const [renaming, setRenaming] = useState(false);
  const [query, setQuery] = useState("");
  const [listQuery, setListQuery] = useState("");
  const [search, setSearch] = useState([]);
  const [state, setState] = useSafeLoad(() => apiClient("/api/v1/lists"), [apiClient, reload], onSessionExpired);
  const lists = state.data?.lists || [];
  const visibleLists = lists.filter((list) => list.name.toLowerCase().includes(listQuery.trim().toLowerCase()));
  const selected = lists.find((list) => list.id === selectedId) || lists[0] || null;

  useEffect(() => { if (!selectedId && lists[0]) setSelectedId(lists[0].id); }, [lists, selectedId]);
  useEffect(() => { setRename(selected?.name || ""); setRenaming(false); }, [selected?.id, selected?.name]);

  const action = useAsyncAction(onSessionExpired);
  const searchAction = useAsyncAction(onSessionExpired);
  async function refresh() {
    const data = await apiClient("/api/v1/lists");
    setState({ loading: false, error: "", data });
  }
  function save(path, method, body, onSaved, success) {
    return action.run({ save: () => apiClient(path, { method, ...(body ? { body } : {}) }), onSaved, refresh, success });
  }
  function create(event) {
    event.preventDefault(); if (!name.trim()) return;
    return save("/api/v1/lists", "POST", { name: name.trim() }, (payload) => { setName(""); if (payload.list?.id) setSelectedId(payload.list.id); }, "List created.");
  }
  function removeList() {
    if (!selected) return;
    const id = selected.id;
    return save(`/api/v1/lists/${id}`, "DELETE", null, () => {
      setSelectedId(null);
      setState((current) => ({ ...current, data: { ...current.data, lists: current.data.lists.filter((list) => list.id !== id) } }));
    }, "List deleted.");
  }
  function renameList(event) {
    event.preventDefault(); if (!selected || !rename.trim()) return;
    return save(`/api/v1/lists/${selected.id}`, "PATCH", { name: rename.trim() }, () => setRenaming(false), "List renamed.");
  }
  function searchLibrary(event) {
    event.preventDefault(); if (query.trim().length < 2) return;
    return searchAction.run({ pending: "Searching…", save: () => apiClient(`/api/v1/library/search?${encodeQuery({ query: query.trim(), type: "all", limit: 20 })}`), onSaved: (payload) => setSearch([...(payload.movies || []), ...(payload.shows || [])]), success: "Search complete." });
  }
  function add(item) {
    if (!selected) return;
    const type = item.kind || item.media_type;
    return save(`/api/v1/lists/${selected.id}/items`, "POST", { media_type: type, media_id: type === "movie" ? (item.movieId || item.id) : (item.showId || item.id) }, null, "Title added to list.");
  }
  function remove(item) { return save(`/api/v1/lists/${selected.id}/items/${item.id}`, "DELETE", null, null, "Title removed from list."); }
  function move(item, direction) {
    const items = [...selected.items]; const index = items.findIndex((candidate) => candidate.id === item.id); const target = index + direction;
    if (target < 0 || target >= items.length) return;
    [items[index], items[target]] = [items[target], items[index]];
    return save(`/api/v1/lists/${selected.id}/reorder`, "PATCH", { item_ids: items.map((candidate) => candidate.id) }, null, "List order saved.");
  }

  return <section className="web-v1-screen lists-screen"><header className="screen-intro"><span className="eyebrow">Your collections</span><h1>Your Lists</h1><p>Personal collections, saved rules, and stories worth sharing.</p></header><CollectionTools apiClient={apiClient} onChanged={refresh} onSessionExpired={onSessionExpired} /><ActionFeedback action={action} /><ActionFeedback action={searchAction} />{state.error ? <div className="detail-error" role="alert">{state.error} <button className="text-action" onClick={() => setReload((value) => value + 1)} type="button">Retry loading lists</button></div> : null}<fieldset className="action-fieldset" disabled={state.loading || action.pending || Boolean(action.error && action.refreshOnly)}><div className="lists-layout"><aside><form onSubmit={create}><label><span>Create List</span><input aria-label="New list name" onChange={(event) => { action.clear(); setName(event.target.value); }} placeholder="Watch with family" value={name} /></label><button aria-label="Create list" className="icon-action" type="submit"><Plus /></button></form><label className="list-filter"><span>Search Lists</span><input aria-label="Search lists" onChange={(event) => setListQuery(event.target.value)} placeholder="Find a list" type="search" value={listQuery} /></label>{visibleLists.map((list) => <button className={selected?.id === list.id ? "active" : ""} key={list.id} onClick={() => { action.clear(); setSelectedId(list.id); }} type="button"><ListBullets /><span><strong>{list.name}</strong><small>{list.itemsCount} items · {list.rules ? "Smart" : "Manual"}{list.shared ? " · Shared" : " · Private"}</small></span></button>)}{lists.length > 0 && visibleLists.length === 0 ? <div className="empty-strip compact">No lists match your search</div> : null}</aside><div className="list-detail">{selected ? <><CollectionTools key={selected.id} selected={selected} apiClient={apiClient} onChanged={refresh} onSessionExpired={onSessionExpired} /><header><div><span className="eyebrow">{selected.rules ? "Smart collection" : "Manual collection"}</span>{renaming ? <form className="list-rename-form" onSubmit={renameList}><input aria-label="Rename list" autoFocus onChange={(event) => { action.clear(); setRename(event.target.value); }} value={rename} /><button className="secondary-action" type="submit">Save</button><button className="text-action" onClick={() => setRenaming(false)} type="button">Cancel</button></form> : <h3>{selected.name}</h3>}</div><div className="list-header-actions"><button aria-label="Rename list" className="icon-action" onClick={() => setRenaming(true)} type="button"><PencilSimple /></button><button aria-label="Delete list" className="icon-action danger" onClick={removeList} type="button"><Trash /></button></div></header><p>{selected.description}{selected.truncated ? " · Showing the first 100 matching titles." : ""}</p><div className="list-items">{selected.items.map((item, index) => <article key={item.id}><button className="list-item-main" onClick={() => onOpen?.({ ...item, kind: item.mediaType, movieId: item.mediaType === "movie" ? item.mediaId : undefined, showId: item.mediaType === "show" ? item.mediaId : undefined })} type="button"><span className="list-position">{index + 1}</span><span className="list-art"><Artwork item={item} /></span><span><strong>{item.title}</strong><small>{item.mediaType} · {item.year || "Year unavailable"}</small></span></button><div hidden={Boolean(selected.rules)}><button aria-label={`Move ${item.title} up`} className="icon-action" disabled={index === 0} onClick={() => move(item, -1)} type="button"><CaretLeft /></button><button aria-label={`Move ${item.title} down`} className="icon-action" disabled={index === selected.items.length - 1} onClick={() => move(item, 1)} type="button"><CaretRight /></button><button aria-label={`Remove ${item.title}`} className="icon-action danger" onClick={() => remove(item)} type="button"><Trash /></button></div></article>)}</div><form hidden={Boolean(selected.rules)} className="list-search" onSubmit={searchLibrary}><label><MagnifyingGlass /><input aria-label="Search library for list" onChange={(event) => setQuery(event.target.value)} placeholder="Find a movie or show" value={query} /></label><button className="secondary-action" disabled={searchAction.pending} type="submit">Search library</button></form><div hidden={Boolean(selected.rules)} className="list-search-results">{search.map((item) => <button key={`${item.kind}-${item.id}`} onClick={() => add(item)} type="button"><Plus /><span><strong>{item.title}</strong><small>{item.kind}</small></span></button>)}</div></> : <div className="empty-strip compact">Create your first private list</div>}</div></div></fieldset></section>;
}

export function WebSettingsSection({ apiClient = apiRequest, initialSection = "profile", onSessionExpired }) {
  const [section, setSection] = useState(initialSection);
  const [reload, setReload] = useState(0);
  const action = useAsyncAction(onSessionExpired);
  const [settingsState] = useSafeLoad(() => apiClient("/api/v1/settings"), [apiClient], onSessionExpired);
  const [preferencesState, setPreferencesState] = useSafeLoad(() => apiClient("/api/v1/notification-preferences"), [apiClient, reload], onSessionExpired);
  const settings = settingsState.data || {};
  const preferences = preferencesState.data?.preferences || {};
  const sections = [["profile", "Profile"], ["privacy", "Privacy"], ["notifications", "Notifications"], ["import-export", "Import & Export"], ["metadata", "Metadata"], ["account", "Account"], ["about", "About"]];
  useEffect(() => setSection(initialSection), [initialSection]);
  function toggle(key) {
    const field = { new_episodes: "newEpisodes", movie_releases: "movieReleases", in_app_enabled: "inAppEnabled", email_enabled: "emailEnabled" }[key] || key;
    const checked = !preferences[field];
    return action.run({
      save: () => apiClient("/api/v1/notification-preferences", { method: "PATCH", body: { [key]: checked } }),
      onSaved: () => setPreferencesState((current) => ({ ...current, data: { ...current.data, preferences: { ...current.data?.preferences, [field]: checked } } })),
      refresh: async () => { const data = await apiClient("/api/v1/notification-preferences"); setPreferencesState({ loading: false, error: "", data }); },
      success: "Preferences saved.",
    });
  }

  return <section className="web-v1-screen settings-screen"><header className="screen-intro"><span className="eyebrow">Your MediaHub</span><h1>Settings</h1><p>Manage your private account, notifications, metadata, and data ownership.</p></header><nav className="settings-nav" aria-label="Settings sections">{sections.map(([id, label]) => <button className={section === id ? "active" : ""} key={id} onClick={() => setSection(id)} type="button">{label}</button>)}</nav>{settingsState.error || preferencesState.error ? <div className="detail-error">{settingsState.error || preferencesState.error}{preferencesState.error ? <button className="text-action" onClick={() => setReload((value) => value + 1)} type="button">Retry loading preferences</button> : null}</div> : null}<ActionFeedback action={action} />{section === "profile" ? <div className="settings-editorial"><h3>Profile</h3><dl><div><dt>Name</dt><dd>{settings.profile?.name}</dd></div><div><dt>Email</dt><dd>{settings.profile?.email}</dd></div><div><dt>Account</dt><dd>{settings.profile?.role}</dd></div></dl></div> : null}{section === "privacy" ? <PrivacyControls apiClient={apiClient} onSessionExpired={onSessionExpired} /> : null}{section === "notifications" ? <div className="settings-editorial preference-list"><h3>Notifications</h3><p>In-app alerts are enabled by default. Email remains off until you choose otherwise.</p>{[["new_episodes", "New episodes", preferences.newEpisodes], ["movie_releases", "Movie releases", preferences.movieReleases], ["reminders", "Unfinished show reminders", preferences.reminders], ["in_app_enabled", "In-app notifications", preferences.inAppEnabled], ["email_enabled", "Email notifications", preferences.emailEnabled]].map(([key, label, checked]) => <label className="toggle-row" key={key}><span>{label}</span><input disabled={action.pending || preferencesState.loading || Boolean(preferencesState.error) || Boolean(action.error && action.refreshOnly)} checked={Boolean(checked)} onChange={() => toggle(key)} type="checkbox" /></label>)}</div> : null}{section === "import-export" ? <div className="settings-editorial"><h3>Import & Export</h3><ImportCenter apiClient={apiClient} onSessionExpired={onSessionExpired} /><OfflineSettings /><p>Download your data whenever you need it. Exported files contain your library and tracking data, never playback credentials, locators, or application secrets.</p><dl><div><dt>Last TV Time import</dt><dd>{settings.import?.lastImportAt ? new Date(settings.import.lastImportAt).toLocaleString() : "No import recorded"}</dd></div><div><dt>Import mode</dt><dd>Preview and merge</dd></div></dl><div className="export-actions"><a className="primary-action" href="/api/v1/exports/json"><DownloadSimple /> Download full JSON</a>{(settings.export?.csvDatasets || []).map((dataset) => <a className="text-action" href={`/api/v1/exports/csv/${dataset}`} key={dataset}>CSV: {dataset.replaceAll("-", " ")}</a>)}</div></div> : null}{section === "metadata" ? <div className="settings-editorial"><h3>Metadata</h3><p>TMDB is the primary metadata provider. IMDb IDs are preserved as secondary references.</p><dl>{["movies", "shows", "episodes"].map((type) => <div key={type}><dt>{type}</dt><dd>{settings.metadata?.[type]?.enriched || 0} / {settings.metadata?.[type]?.total || 0} enriched</dd></div>)}</dl></div> : null}{section === "account" ? <div className="settings-editorial"><h3>Account</h3><p>Export your data before requesting account deletion. Self-service deletion is not enabled in V1.</p><div className="data-warning">Deleting an account is permanent and should only happen after a verified export.</div></div> : null}{section === "about" ? <div className="settings-editorial"><h3>About MediaHub</h3><p>Your entertainment memory for discovery, tracking, ratings, notes, history, and collections.</p><dl><div><dt>Version</dt><dd>{settings.version || "1.0.0"}</dd></div><div><dt>Metadata</dt><dd>TMDB primary · IMDb secondary</dd></div></dl></div> : null}</section>;
}
