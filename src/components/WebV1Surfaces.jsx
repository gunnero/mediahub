import { useEffect, useMemo, useState } from "react";
import {
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

export function DiscoverSection({ apiClient = apiRequest, initialType = "all", navigationKey = 0, onLibraryChanged, onOpen, onSessionExpired }) {
  const [query, setQuery] = useState("");
  const [mode, setMode] = useState("discover");
  const [type, setType] = useState(initialType);
  const [category, setCategory] = useState("trending");
  const [state, setState] = useState({ loading: true, error: "", items: [] });
  const [adding, setAdding] = useState("");
  const categories = [
    ["trending", "Trending"],
    ["popular", "Popular"],
    ["now_playing", "Now Playing"],
    ["upcoming", "Upcoming"],
    ["top_rated", "Top Rated"],
  ];

  useEffect(() => {
    setType(initialType);
  }, [initialType, navigationKey]);

  useEffect(() => {
    const safeQuery = query.trim();
    if (mode === "library" && safeQuery.length < 2) {
      setState({ loading: false, error: "", items: [] });
      return undefined;
    }
    const timer = window.setTimeout(async () => {
      setState((current) => ({ ...current, loading: true, error: "" }));
      try {
        const endpoint = mode === "discover"
          ? safeQuery.length >= 2
            ? `/api/v1/discover/search?${encodeQuery({ query: safeQuery, type, page: 1 })}`
            : `/api/v1/discover/browse?${encodeQuery({ category, type, page: 1 })}`
          : `/api/v1/library/search?${encodeQuery({ query: safeQuery, type, limit: 30 })}`;
        const payload = await apiClient(endpoint);
        const items = mode === "discover" ? payload.items || [] : [
          ...(payload.movies || []),
          ...(payload.shows || []),
          ...(payload.episodes || []),
        ];
        setState({ loading: false, error: payload.status === "disabled" ? "Discovery is unavailable until TMDB is enabled." : "", items });
      } catch (error) {
        if (error instanceof SessionExpiredError) onSessionExpired?.();
        else setState({ loading: false, error: error.message || "Search is temporarily unavailable.", items: [] });
      }
    }, 350);
    return () => window.clearTimeout(timer);
  }, [apiClient, category, mode, onSessionExpired, query, type]);

  async function add(item, action) {
    setAdding(`${item.media_type}-${item.tmdb_id}-${action}`);
    setState((current) => ({ ...current, error: "" }));
    try {
      const plural = item.media_type === "show" ? "shows" : "movies";
      const payload = await apiClient(`/api/v1/discover/${plural}/${item.tmdb_id}/add`, { method: "POST", body: { action } });
      setState((current) => ({ ...current, items: current.items.map((candidate) => candidate.tmdb_id === item.tmdb_id && candidate.media_type === item.media_type ? { ...candidate, already_in_library: true, existing_library_id: payload.item?.id } : candidate) }));
      await onLibraryChanged?.();
    } catch (error) {
      if (error instanceof SessionExpiredError) onSessionExpired?.();
      else setState((current) => ({ ...current, error: error.message || "This title could not be added." }));
    } finally { setAdding(""); }
  }

  return <section className="web-v1-screen discover-screen">
    <header className="screen-intro"><span className="eyebrow">Find your next story</span><h2>Discover</h2><p>Search your entertainment memory or explore movies and shows beyond your library.</p></header>
    <div className="segmented-control" aria-label="Search source"><button className={mode === "library" ? "active" : ""} onClick={() => setMode("library")} type="button">My Library</button><button className={mode === "discover" ? "active" : ""} onClick={() => setMode("discover")} type="button">Discover</button></div>
    <div className="discovery-search-row"><label><MagnifyingGlass size={20} /><input aria-label="Search movies and shows" autoFocus onChange={(event) => setQuery(event.target.value)} placeholder={mode === "discover" ? "Search TMDB movies and shows" : "Search your movies, shows, and episodes"} type="search" value={query} /></label><select aria-label="Media type" onChange={(event) => setType(event.target.value)} value={type}><option value="all">Movies and shows</option><option value="movie">Movies</option><option value="show">Shows</option>{mode === "library" ? <option value="episode">Episodes</option> : null}</select></div>
    {mode === "discover" && query.trim().length < 2 ? <div className="discovery-categories" role="tablist" aria-label="Discovery categories">{categories.map(([id, label]) => <button aria-selected={category === id} className={category === id ? "active" : ""} key={id} onClick={() => setCategory(id)} role="tab" type="button">{label}</button>)}</div> : null}
    {state.error ? <div className="detail-error">{state.error}</div> : null}
    {state.loading ? <div className="empty-strip compact">Searching...</div> : null}
    {!state.loading && mode === "library" && query.trim().length < 2 ? <div className="empty-strip compact">Type at least two characters to search your library</div> : null}
    {!state.loading && mode === "discover" && query.trim().length < 2 && !state.items.length && !state.error ? <div className="empty-strip compact">No titles are available in this category right now</div> : null}
    {!state.loading && query.trim().length >= 2 && !state.items.length && !state.error ? <div className="empty-strip compact">No results found</div> : null}
    <div className="discovery-results-grid">{state.items.map((item) => {
      const mediaType = item.media_type || item.kind;
      const canonicalItem = { ...item, kind: mediaType, movieId: mediaType === "movie" ? (item.existing_library_id || item.movieId || item.id) : undefined, showId: mediaType === "show" ? (item.existing_library_id || item.showId || item.id) : undefined, episodeId: mediaType === "episode" ? (item.episodeId || item.id) : undefined };
      return <article className="discovery-result" key={`${mediaType}-${item.tmdb_id || item.id}`}><button className="discovery-art" onClick={() => (mode === "library" || item.already_in_library) && onOpen?.(canonicalItem)} type="button"><Artwork item={item} /></button><div><span className="eyebrow">{mediaType}</span><h3>{item.title}</h3><small>{item.year || item.releaseYear || "Year unavailable"}</small><p>{item.overview || item.subtitle || "No overview is available yet."}</p>{mode === "discover" ? <div className="result-actions">{item.already_in_library ? <button className="secondary-action" onClick={() => onOpen?.(canonicalItem)} type="button"><CheckCircle size={17} /> Already in Library</button> : <><button className="primary-action" disabled={Boolean(adding)} onClick={() => add(item, "library")} type="button">Add to Library</button><button className="secondary-action" disabled={Boolean(adding)} onClick={() => add(item, "watchlist")} type="button">Add to Watchlist</button>{mediaType === "movie" ? <button className="text-action" disabled={Boolean(adding)} onClick={() => add(item, "watched")} type="button">Mark Watched</button> : null}</>}</div> : <button className="secondary-action" onClick={() => onOpen?.(canonicalItem)} type="button">Open details</button>}</div></article>;
    })}</div>
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
    <header className="screen-intro"><span className="eyebrow">What is coming</span><h2>Release calendar</h2><p>Upcoming episodes from followed shows and movie releases from your watchlist.</p></header>
    <div className="calendar-toolbar"><div><button aria-label="Previous period" className="icon-action" onClick={() => move(-1)} type="button"><CaretLeft /></button><strong>{range.from.toLocaleDateString("en-US", { month: "long", year: "numeric" })}</strong><button aria-label="Next period" className="icon-action" onClick={() => move(1)} type="button"><CaretRight /></button><button className="text-action" onClick={() => setCursor(new Date())} type="button">Today</button></div><div className="segmented-control">{["day", "week", "month"].map((option) => <button className={view === option ? "active" : ""} key={option} onClick={() => setView(option)} type="button">{option[0].toUpperCase() + option.slice(1)}</button>)}</div><select aria-label="Calendar media type" onChange={(event) => setType(event.target.value)} value={type}><option value="all">All releases</option><option value="movies">Movies</option><option value="episodes">Episodes</option></select></div>
    {state.data?.range?.timezone ? <p className="calendar-timezone">Dates use {state.data.range.timezone}.</p> : null}
    {state.error ? <div className="detail-error">{state.error}</div> : null}
    {state.loading ? <div className="empty-strip compact">Loading calendar...</div> : null}
    {isEmpty ? <div className="empty-strip compact">No releases are scheduled here yet. Follow a show or add a movie to your watchlist and upcoming dates will appear automatically.</div> : null}
    {!state.loading ? <div className={`calendar-grid ${view}`}>{days.map((day) => { const key = isoDate(day); const items = state.data?.days?.[key] || []; return <section className={`calendar-day ${key === isoDate(new Date()) ? "today" : ""}`} key={key}><header><span>{day.toLocaleDateString("en-US", { weekday: "short" })}</span><strong>{day.getDate()}</strong></header>{items.map((item) => <button key={item.id} onClick={() => onOpen?.(item)} type="button"><i className={item.releaseKind || item.kind} /><span><strong>{item.title}</strong><small>{item.subtitle}</small></span></button>)}</section>; })}</div> : null}
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

export function AlertsSection({ apiClient = apiRequest, onOpen, onSessionExpired }) {
  const [reload, setReload] = useState(0);
  const [filter, setFilter] = useState("all");
  const [state] = useSafeLoad(() => apiClient("/api/v1/alerts"), [apiClient, reload], onSessionExpired);
  const items = (state.data?.alerts || []).filter((item) => alertMatchesFilter(item, filter));
  async function read(alert) { await apiClient(`/api/v1/alerts/${alert.id}/read`, { method: "POST" }); setReload((value) => value + 1); }
  async function readAll() { await apiClient("/api/v1/alerts/read-all", { method: "POST" }); setReload((value) => value + 1); }
  return <section className="web-v1-screen alerts-screen"><header className="screen-intro"><span className="eyebrow">Stay current</span><h2>Alerts</h2><p>Release reminders and library issues that deserve your attention.</p></header><div className="alerts-toolbar"><div className="segmented-control">{[["all", "All"], ["new-episodes", "New episodes"], ["upcoming", "Upcoming"], ["movies", "Movies"], ["reminders", "Reminders"]].map(([id, label]) => <button className={filter === id ? "active" : ""} key={id} onClick={() => setFilter(id)} type="button">{label}</button>)}</div><button className="text-action" onClick={readAll} type="button">Mark all read</button></div>{state.error ? <div className="detail-error">{state.error}</div> : null}{state.loading ? <div className="empty-strip compact">Loading alerts...</div> : null}<div className="alerts-list">{items.map((alert) => <article className={alert.unread ? "unread" : ""} key={alert.id}><button onClick={() => onOpen?.({ ...alert, ...alert.payload })} type="button"><Bell size={22} /><span><strong>{alert.title}</strong><small>{alert.subtitle}</small></span><em>{alert.dueText}</em></button>{alert.unread ? <button aria-label={`Mark ${alert.title} read`} className="text-action" onClick={() => read(alert)} type="button">Mark read</button> : null}</article>)}</div>{!state.loading && !items.length ? <div className="empty-strip compact">No alerts in this view</div> : null}</section>;
}

export function StatsSection({ apiClient = apiRequest, onSessionExpired }) {
  const [state] = useSafeLoad(() => apiClient("/api/v1/stats"), [apiClient], onSessionExpired);
  const data = state.data || { summary: {}, monthlyActivity: [], yearlyActivity: [], genres: [], ratings: [], topShows: [], topMovies: [] };
  const maxMonthly = Math.max(1, ...data.monthlyActivity.map((item) => item.minutes));
  const maxYearly = Math.max(1, ...data.yearlyActivity.map((item) => item.minutes));

  return (
    <section className="web-v1-screen stats-screen">
      <header className="screen-intro">
        <span className="eyebrow">Your viewing life</span>
        <h2>Stats</h2>
        <p>Numbers calculated from your canonical watch history, never from browser estimates.</p>
      </header>
      {state.error ? <div className="detail-error">{state.error}</div> : null}
      {state.loading ? <div className="empty-strip compact">Calculating your stats...</div> : (
        <>
          <div className="stat-summary-grid">
            {[["Movies", data.summary.moviesWatched], ["Episodes", data.summary.episodesWatched], ["Shows completed", data.summary.showsCompleted], ["Hours watched", data.summary.totalWatchHours], ["Rewatches", data.summary.rewatchCount], ["Longest streak", `${data.summary.longestStreakDays || 0} days`]].map(([label, value]) => (
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
  const [error, setError] = useState("");
  const [state] = useSafeLoad(() => apiClient("/api/v1/lists"), [apiClient, reload], onSessionExpired);
  const lists = state.data?.lists || [];
  const visibleLists = lists.filter((list) => list.name.toLowerCase().includes(listQuery.trim().toLowerCase()));
  const selected = lists.find((list) => list.id === selectedId) || lists[0] || null;

  useEffect(() => { if (!selectedId && lists[0]) setSelectedId(lists[0].id); }, [lists, selectedId]);
  useEffect(() => { setRename(selected?.name || ""); setRenaming(false); }, [selected?.id, selected?.name]);

  async function create(event) { event.preventDefault(); if (!name.trim()) return; await apiClient("/api/v1/lists", { method: "POST", body: { name: name.trim() } }); setName(""); setReload((value) => value + 1); }
  async function removeList() { if (!selected) return; await apiClient(`/api/v1/lists/${selected.id}`, { method: "DELETE" }); setSelectedId(null); setReload((value) => value + 1); }
  async function renameList(event) { event.preventDefault(); if (!selected || !rename.trim()) return; await apiClient(`/api/v1/lists/${selected.id}`, { method: "PATCH", body: { name: rename.trim() } }); setRenaming(false); setReload((value) => value + 1); }
  async function searchLibrary(event) { event.preventDefault(); if (query.trim().length < 2) return; try { const payload = await apiClient(`/api/v1/library/search?${encodeQuery({ query: query.trim(), type: "all", limit: 20 })}`); setSearch([...(payload.movies || []), ...(payload.shows || [])]); } catch (searchError) { setError(searchError.message || "Library search failed."); } }
  async function add(item) { if (!selected) return; const type = item.kind || item.media_type; await apiClient(`/api/v1/lists/${selected.id}/items`, { method: "POST", body: { media_type: type, media_id: type === "movie" ? (item.movieId || item.id) : (item.showId || item.id) } }); setReload((value) => value + 1); }
  async function remove(item) { await apiClient(`/api/v1/lists/${selected.id}/items/${item.id}`, { method: "DELETE" }); setReload((value) => value + 1); }
  async function move(item, direction) { const items = [...selected.items]; const index = items.findIndex((candidate) => candidate.id === item.id); const target = index + direction; if (target < 0 || target >= items.length) return; [items[index], items[target]] = [items[target], items[index]]; await apiClient(`/api/v1/lists/${selected.id}/reorder`, { method: "PATCH", body: { item_ids: items.map((candidate) => candidate.id) } }); setReload((value) => value + 1); }

  return <section className="web-v1-screen lists-screen"><header className="screen-intro"><span className="eyebrow">Your collections</span><h2>Your Lists</h2><p>Private, hand-built collections for the stories you want to remember together.</p></header>{error || state.error ? <div className="detail-error">{error || state.error}</div> : null}<div className="lists-layout"><aside><form onSubmit={create}><label><span>Create List</span><input aria-label="New list name" onChange={(event) => setName(event.target.value)} placeholder="Watch with family" value={name} /></label><button aria-label="Create list" className="icon-action" type="submit"><Plus /></button></form><label className="list-filter"><span>Search Lists</span><input aria-label="Search lists" onChange={(event) => setListQuery(event.target.value)} placeholder="Find a list" type="search" value={listQuery} /></label>{visibleLists.map((list) => <button className={selected?.id === list.id ? "active" : ""} key={list.id} onClick={() => setSelectedId(list.id)} type="button"><ListBullets /><span><strong>{list.name}</strong><small>{list.itemsCount} items · Private</small></span></button>)}{lists.length > 0 && visibleLists.length === 0 ? <div className="empty-strip compact">No lists match your search</div> : null}</aside><div className="list-detail">{selected ? <><header><div><span className="eyebrow">Private list</span>{renaming ? <form className="list-rename-form" onSubmit={renameList}><input aria-label="Rename list" autoFocus onChange={(event) => setRename(event.target.value)} value={rename} /><button className="secondary-action" type="submit">Save</button><button className="text-action" onClick={() => setRenaming(false)} type="button">Cancel</button></form> : <h3>{selected.name}</h3>}</div><div className="list-header-actions"><button aria-label="Rename list" className="icon-action" onClick={() => setRenaming(true)} type="button"><PencilSimple /></button><button aria-label="Delete list" className="icon-action danger" onClick={removeList} type="button"><Trash /></button></div></header><div className="list-items">{selected.items.map((item, index) => <article key={item.id}><button className="list-item-main" onClick={() => onOpen?.({ ...item, kind: item.mediaType, movieId: item.mediaType === "movie" ? item.mediaId : undefined, showId: item.mediaType === "show" ? item.mediaId : undefined })} type="button"><span className="list-position">{index + 1}</span><span className="list-art"><Artwork item={item} /></span><span><strong>{item.title}</strong><small>{item.mediaType} · {item.year || "Year unavailable"}</small></span></button><div><button aria-label={`Move ${item.title} up`} className="icon-action" disabled={index === 0} onClick={() => move(item, -1)} type="button"><CaretLeft /></button><button aria-label={`Move ${item.title} down`} className="icon-action" disabled={index === selected.items.length - 1} onClick={() => move(item, 1)} type="button"><CaretRight /></button><button aria-label={`Remove ${item.title}`} className="icon-action danger" onClick={() => remove(item)} type="button"><Trash /></button></div></article>)}</div><form className="list-search" onSubmit={searchLibrary}><label><MagnifyingGlass /><input aria-label="Search library for list" onChange={(event) => setQuery(event.target.value)} placeholder="Find a movie or show" value={query} /></label><button className="secondary-action" type="submit">Search library</button></form><div className="list-search-results">{search.map((item) => <button key={`${item.kind}-${item.id}`} onClick={() => add(item)} type="button"><Plus /><span><strong>{item.title}</strong><small>{item.kind}</small></span></button>)}</div></> : <div className="empty-strip compact">Create your first private list</div>}</div></div></section>;
}

export function WebSettingsSection({ apiClient = apiRequest, initialSection = "profile", onSessionExpired }) {
  const [section, setSection] = useState(initialSection);
  const [reload, setReload] = useState(0);
  const [status, setStatus] = useState("");
  const [settingsState] = useSafeLoad(() => apiClient("/api/v1/settings"), [apiClient], onSessionExpired);
  const [preferencesState] = useSafeLoad(() => apiClient("/api/v1/notification-preferences"), [apiClient, reload], onSessionExpired);
  const settings = settingsState.data || {};
  const preferences = preferencesState.data?.preferences || {};
  const sections = [["profile", "Profile"], ["privacy", "Privacy"], ["notifications", "Notifications"], ["import-export", "Import & Export"], ["metadata", "Metadata"], ["account", "Account"], ["about", "About"]];
  useEffect(() => setSection(initialSection), [initialSection]);
  async function toggle(key) { setStatus("Saving..."); await apiClient("/api/v1/notification-preferences", { method: "PATCH", body: { [key]: !preferences[key === "new_episodes" ? "newEpisodes" : key === "movie_releases" ? "movieReleases" : key === "in_app_enabled" ? "inAppEnabled" : key === "email_enabled" ? "emailEnabled" : key] } }); setStatus("Preferences saved."); setReload((value) => value + 1); }

  return <section className="web-v1-screen settings-screen"><header className="screen-intro"><span className="eyebrow">Your MediaHub</span><h2>Settings</h2><p>Manage your private account, notifications, metadata, and data ownership.</p></header><nav className="settings-nav" aria-label="Settings sections">{sections.map(([id, label]) => <button className={section === id ? "active" : ""} key={id} onClick={() => setSection(id)} type="button">{label}</button>)}</nav>{settingsState.error || preferencesState.error ? <div className="detail-error">{settingsState.error || preferencesState.error}</div> : null}{status ? <div className="settings-status">{status}</div> : null}{section === "profile" ? <div className="settings-editorial"><h3>Profile</h3><dl><div><dt>Name</dt><dd>{settings.profile?.name}</dd></div><div><dt>Email</dt><dd>{settings.profile?.email}</dd></div><div><dt>Account</dt><dd>{settings.profile?.role}</dd></div></dl></div> : null}{section === "privacy" ? <PrivacyControls apiClient={apiClient} onSessionExpired={onSessionExpired} /> : null}{section === "notifications" ? <div className="settings-editorial preference-list"><h3>Notifications</h3><p>In-app alerts are enabled by default. Email remains off until you choose otherwise.</p>{[["new_episodes", "New episodes", preferences.newEpisodes], ["movie_releases", "Movie releases", preferences.movieReleases], ["reminders", "Unfinished show reminders", preferences.reminders], ["in_app_enabled", "In-app notifications", preferences.inAppEnabled], ["email_enabled", "Email notifications", preferences.emailEnabled]].map(([key, label, checked]) => <label className="toggle-row" key={key}><span>{label}</span><input checked={Boolean(checked)} onChange={() => toggle(key)} type="checkbox" /></label>)}</div> : null}{section === "import-export" ? <div className="settings-editorial"><h3>Import & Export</h3><p>TV Time imports remain available through the private import workflow. Exported files contain your library and tracking data, never playback credentials, locators, or application secrets.</p><dl><div><dt>Last TV Time import</dt><dd>{settings.import?.lastImportAt ? new Date(settings.import.lastImportAt).toLocaleString() : "No import recorded"}</dd></div><div><dt>Import mode</dt><dd>Private assisted import</dd></div></dl><div className="export-actions"><a className="primary-action" href="/api/v1/exports/json"><DownloadSimple /> Download full JSON</a>{(settings.export?.csvDatasets || []).map((dataset) => <a className="text-action" href={`/api/v1/exports/csv/${dataset}`} key={dataset}>CSV: {dataset.replaceAll("-", " ")}</a>)}</div></div> : null}{section === "metadata" ? <div className="settings-editorial"><h3>Metadata</h3><p>TMDB is the primary metadata provider. IMDb IDs are preserved as secondary references.</p><dl>{["movies", "shows", "episodes"].map((type) => <div key={type}><dt>{type}</dt><dd>{settings.metadata?.[type]?.enriched || 0} / {settings.metadata?.[type]?.total || 0} enriched</dd></div>)}</dl></div> : null}{section === "account" ? <div className="settings-editorial"><h3>Account</h3><p>Export your data before requesting account deletion. Self-service deletion is not enabled in V1.</p><div className="data-warning">Deleting an account is permanent and should only happen after a verified export.</div></div> : null}{section === "about" ? <div className="settings-editorial"><h3>About MediaHub</h3><p>Your entertainment memory for discovery, tracking, ratings, notes, history, and collections.</p><dl><div><dt>Version</dt><dd>{settings.version || "1.0.0"}</dd></div><div><dt>Metadata</dt><dd>TMDB primary · IMDb secondary</dd></div></dl></div> : null}</section>;
}
