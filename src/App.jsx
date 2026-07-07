import { useEffect, useMemo, useRef, useState } from "react";
import {
  Bell,
  CalendarDots,
  CaretDown,
  ChartBar,
  CheckCircle,
  Clock,
  FilmSlate,
  GearSix,
  House,
  MagnifyingGlass,
  Play,
  SignOut,
  SquaresFour,
  TelevisionSimple,
  UserCircle,
  X,
} from "@phosphor-icons/react";
import {
  buildActivityBars,
  filterCollections,
  getUnreadCount,
} from "./lib/dashboard.js";
import { apiRequest, SessionExpiredError } from "./lib/api.js";

const fallbackPoster = "/assets/generated/movie-poster-1.png";

const navItems = [
  { id: "home", label: "Home", icon: House },
  { id: "shows", label: "Shows", icon: TelevisionSimple },
  { id: "movies", label: "Movies", icon: FilmSlate },
  { id: "history", label: "History", icon: Clock },
  { id: "player", label: "Player", icon: Play },
  { id: "alerts", label: "Alerts", icon: Bell },
  { id: "stats", label: "Stats", icon: ChartBar },
  { id: "settings", label: "Settings", icon: GearSix },
];

const alertTabs = [
  { id: "all", label: "All" },
  { id: "new-episodes", label: "Episodes" },
  { id: "upcoming", label: "Upcoming" },
  { id: "movies", label: "Movies" },
];

const fallbackData = {
  profile: { name: "gunner", image: "", cover: fallbackPoster },
  stats: {
    episodesWatched: 0,
    moviesWatched: 0,
    hoursWatched: 0,
    showsFollowed: 0,
    alertsUnread: 0,
  },
  hero: {
    title: "TV Time import ready",
    subtitle: "Private local dashboard",
    meta: "Run the importer to load your history",
    poster: fallbackPoster,
    backdrop: fallbackPoster,
    progress: 0,
    kind: "library",
    eyebrow: "Local archive",
  },
  alerts: [],
  recentlyWatched: [],
  followedNewEpisodes: [],
  moviesToCheckOut: [],
  topShows: [],
  activity: [],
  timeline: {
    recent: [],
    todaySummary: { total: 0 },
    thisWeekSummary: { total: 0 },
  },
  player: {
    enabled: false,
    emptyState: "Attach your own source to enable playback and automatic tracking.",
    sourceItems: [],
    linkedItems: [],
    unlinkedItems: [],
    continueWatching: [],
  },
};

function formatNumber(value) {
  return new Intl.NumberFormat("en-US").format(value || 0);
}

function shortDate(value) {
  if (!value) {
    return "";
  }
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return value;
  }
  return new Intl.DateTimeFormat("en-US", {
    month: "short",
    day: "numeric",
    year: "numeric",
  }).format(date);
}

function shortTime(value) {
  if (!value) {
    return "";
  }

  const date = new Date(value);

  if (Number.isNaN(date.getTime())) {
    return "";
  }

  return new Intl.DateTimeFormat("en-US", {
    hour: "numeric",
    minute: "2-digit",
  }).format(date);
}

function sourceLabel(source) {
  const labels = {
    import: "Import",
    manual: "Manual entry",
    metadata: "Metadata",
    player: "Player",
    provider: "Provider",
    system: "System",
  };

  if (!source) {
    return "MediaHub";
  }

  return labels[source] || source.replace(/[-_]/g, " ").replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function imageFor(item) {
  return item?.poster || item?.backdrop || fallbackPoster;
}

function mediaDetailPath(item) {
  if (item?.episodeId) {
    return `/api/v1/library/episodes/${item.episodeId}`;
  }
  if (item?.movieId) {
    return `/api/v1/library/movies/${item.movieId}`;
  }
  if (item?.showId) {
    return `/api/v1/library/shows/${item.showId}`;
  }
  return "";
}

function mediaBasePath(detail) {
  if (detail?.kind === "episode" && detail.episodeId) {
    return `/api/v1/library/episodes/${detail.episodeId}`;
  }
  if (detail?.kind === "movie" && detail.movieId) {
    return `/api/v1/library/movies/${detail.movieId}`;
  }
  if (detail?.kind === "show" && detail.showId) {
    return `/api/v1/library/shows/${detail.showId}`;
  }
  return "";
}

function buildQueryString(params) {
  const search = new URLSearchParams();

  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== null && String(value).trim() !== "") {
      search.set(key, String(value));
    }
  });

  return search.toString();
}

function ratingLabel(value) {
  return value ? `${value}/10` : "";
}

function libraryEndpoint(path, filters) {
  const query = buildQueryString(filters);
  return query ? `${path}?${query}` : path;
}

function Logo() {
  return (
    <div className="brand-lockup">
      <div className="brand-mark">
        <FilmSlate size={24} weight="fill" />
      </div>
      <div>
        <strong>Cinema</strong>
        <span>Command Center</span>
      </div>
    </div>
  );
}

function Sidebar({ activeSection, alertsCount, onSelect }) {
  return (
    <aside className="sidebar">
      <Logo />
      <nav className="main-nav" aria-label="Main navigation">
        {navItems.map((item) => {
          const Icon = item.icon;
          const active = activeSection === item.id;
          return (
            <button
              className={`nav-item ${active ? "active" : ""}`}
              key={item.id}
              onClick={() => onSelect(item.id)}
              type="button"
            >
              <Icon size={24} />
              <span>{item.label}</span>
              {item.id === "alerts" && alertsCount > 0 ? (
                <b>{alertsCount}</b>
              ) : null}
            </button>
          );
        })}
      </nav>
      <div className="sidebar-footer">
        <span>Private local archive</span>
        <strong>v1.0.0</strong>
      </div>
    </aside>
  );
}

function LoadingScreen({ message = "Loading dashboard..." }) {
  return (
    <div className="login-shell">
      <div className="login-panel compact">
        <Logo />
        <p>{message}</p>
      </div>
    </div>
  );
}

function LoginScreen({ error, onLogin, submitting }) {
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");

  function submit(event) {
    event.preventDefault();
    onLogin({ email, password });
  }

  return (
    <div className="login-shell">
      <form className="login-panel" onSubmit={submit}>
        <Logo />
        <label>
          <span>Email</span>
          <input
            autoComplete="email"
            onChange={(event) => setEmail(event.target.value)}
            required
            type="email"
            value={email}
          />
        </label>
        <label>
          <span>Password</span>
          <input
            autoComplete="current-password"
            onChange={(event) => setPassword(event.target.value)}
            required
            type="password"
            value={password}
          />
        </label>
        {error ? <div className="login-error">{error}</div> : null}
        <button className="primary-action" disabled={submitting} type="submit">
          {submitting ? "Signing in..." : "Sign in"}
        </button>
      </form>
    </div>
  );
}

function Topbar({ profile, query, onQueryChange, onLogout }) {
  return (
    <header className="topbar">
      <label className="search-box">
        <MagnifyingGlass size={22} />
        <input
          value={query}
          onChange={(event) => onQueryChange(event.target.value)}
          placeholder="Search shows, movies, episodes..."
        />
      </label>
      <div className="topbar-actions">
        <button className="profile-menu" type="button">
          {profile.image ? (
            <img src={profile.image} alt="" />
          ) : (
            <UserCircle size={38} weight="duotone" />
          )}
          <span>{profile.name || "gunner"}</span>
          <CaretDown size={16} />
        </button>
        <button className="logout-button" onClick={onLogout} type="button">
          <SignOut size={18} />
          <span>Logout</span>
        </button>
      </div>
    </header>
  );
}

function Hero({ item, onOpen }) {
  const background = item.backdrop || item.poster || fallbackPoster;
  return (
    <section className="hero-panel">
      <img className="hero-backdrop" src={background} alt="" />
      <div className="hero-shade" />
      <div className="hero-poster-wrap">
        <img src={imageFor(item)} alt="" className="hero-poster" />
      </div>
      <div className="hero-copy">
        <span className="eyebrow">{item.eyebrow || "Continue watching"}</span>
        <h1>{item.title}</h1>
        <p>
          <strong>{item.subtitle}</strong>
          <span>{item.meta}</span>
        </p>
        <div className="hero-progress" aria-label={`${item.progress || 0}% complete`}>
          <span style={{ width: `${Math.min(100, item.progress || 0)}%` }} />
        </div>
        <div className="hero-actions">
          <button className="primary-action" onClick={() => onOpen(item)} type="button">
            <Play size={18} weight="fill" />
            Open memory
          </button>
          <button className="secondary-action" onClick={() => onOpen(item)} type="button">
            View details
          </button>
        </div>
      </div>
    </section>
  );
}

function AlertCenter({ alerts, activeTab, onTabChange, onOpen, onMarkAllRead }) {
  const visibleAlerts =
    activeTab === "all"
      ? alerts
      : alerts.filter((alert) => alert.category === activeTab);
  const unread = getUnreadCount(alerts);

  return (
    <section className="alerts-panel">
      <div className="panel-heading">
        <div>
          <span>Alerts</span>
          <strong>{unread} unread</strong>
        </div>
        <button onClick={onMarkAllRead} type="button">
          Mark read
        </button>
      </div>
      <div className="alert-tabs" role="tablist" aria-label="Alert filters">
        {alertTabs.map((tab) => (
          <button
            className={activeTab === tab.id ? "active" : ""}
            key={tab.id}
            onClick={() => onTabChange(tab.id)}
            type="button"
          >
            {tab.label}
          </button>
        ))}
      </div>
      <div className="alert-list">
        {visibleAlerts.slice(0, 6).map((alert) => (
          <button
            className={`alert-row ${alert.unread ? "unread" : ""}`}
            key={alert.id}
            onClick={() => onOpen(alert)}
            type="button"
          >
            <span className="alert-dot" />
            <span>
              <strong>{alert.title}</strong>
              <small>{alert.subtitle}</small>
            </span>
            <em>{alert.dueText}</em>
          </button>
        ))}
      </div>
    </section>
  );
}

function PosterCard({ item, onOpen, compact = false }) {
  return (
    <button
      className={`poster-card ${compact ? "compact" : ""}`}
      onClick={() => onOpen(item)}
      title={item.title}
      type="button"
    >
      <span className="poster-frame">
        <img src={imageFor(item)} alt="" />
        {item.badge ? <b className={`badge ${item.badge}`}>{item.badge}</b> : null}
      </span>
      <span className="poster-copy">
        <strong>{item.title}</strong>
        <small>{item.subtitle}</small>
      </span>
      <span className="mini-progress">
        <i style={{ width: `${Math.min(100, item.progress || 0)}%` }} />
      </span>
    </button>
  );
}

function Shelf({ title, items, onOpen, compact = false }) {
  return (
    <section className="shelf">
      <div className="section-heading">
        <h2>{title}</h2>
        <button type="button">View all</button>
      </div>
      <div className={`poster-strip ${compact ? "compact" : ""}`}>
        {items.length ? (
          items.map((item) => (
            <PosterCard compact={compact} item={item} key={item.id} onOpen={onOpen} />
          ))
        ) : (
          <div className="empty-strip">No matching titles</div>
        )}
      </div>
    </section>
  );
}

function LibraryToolbar({
  searchLabel,
  searchPlaceholder,
  searchValue,
  onSearchChange,
  onSearchSubmit,
  statusLabel,
  statusValue,
  onStatusChange,
  statusOptions,
  sortLabel,
  sortValue,
  onSortChange,
  sortOptions,
}) {
  return (
    <form className="library-toolbar" onSubmit={onSearchSubmit}>
      <label>
        <span>{searchLabel}</span>
        <input
          onChange={(event) => onSearchChange(event.target.value)}
          placeholder={searchPlaceholder}
          type="search"
          value={searchValue}
        />
      </label>
      <label>
        <span>{statusLabel}</span>
        <select onChange={(event) => onStatusChange(event.target.value)} value={statusValue}>
          {statusOptions.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </select>
      </label>
      <label>
        <span>{sortLabel}</span>
        <select onChange={(event) => onSortChange(event.target.value)} value={sortValue}>
          {sortOptions.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </select>
      </label>
      <button className="secondary-action" type="submit">
        Search
      </button>
    </form>
  );
}

function LibraryCard({ item, onOpen }) {
  const badges = [
    item.watched ? "Watched" : null,
    ratingLabel(item.rating),
    item.hasNote ? "Private note" : null,
    item.providerLinked ? "Linked source" : null,
    item.metadataStatus,
  ].filter(Boolean);

  return (
    <button
      aria-label={`Open ${item.title}`}
      className="library-card"
      onClick={() => onOpen(item)}
      type="button"
    >
      <span className="library-card-art">
        <img src={imageFor(item)} alt="" />
      </span>
      <span className="library-card-copy">
        <strong>{item.title}</strong>
        <small>{item.meta || item.subtitle}</small>
        <span className="library-card-badges">
          {badges.map((badge) => (
            <b key={badge}>{badge}</b>
          ))}
        </span>
      </span>
      <span className="mini-progress">
        <i style={{ width: `${Math.min(100, item.progress || 0)}%` }} />
      </span>
    </button>
  );
}

function LibraryState({ error, loading, children }) {
  if (error) {
    return <div className="detail-error">{error}</div>;
  }

  if (loading) {
    return <div className="empty-strip compact">Loading library...</div>;
  }

  return children;
}

export function MovieLibrary({
  apiClient = apiRequest,
  initialSearch = "",
  onOpen,
  onSessionExpired,
}) {
  const [searchDraft, setSearchDraft] = useState(initialSearch);
  const [filters, setFilters] = useState({
    search: initialSearch,
    status: "all",
    sort: "latest_watched",
    page: 1,
    per_page: 24,
  });
  const [payload, setPayload] = useState({ items: [], pagination: { page: 1, total: 0, hasMore: false } });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  useEffect(() => {
    setSearchDraft(initialSearch);
    setFilters((current) => (
      current.search === initialSearch ? current : { ...current, search: initialSearch, page: 1 }
    ));
  }, [initialSearch]);

  useEffect(() => {
    let cancelled = false;

    async function loadMovies() {
      setLoading(true);
      setError("");

      try {
        const nextPayload = await apiClient(libraryEndpoint("/api/v1/library/movies", filters));

        if (!cancelled) {
          setPayload(nextPayload);
        }
      } catch (loadError) {
        if (cancelled) {
          return;
        }

        if (loadError instanceof SessionExpiredError) {
          onSessionExpired?.();
          return;
        }

        setError(loadError.message || "Could not load movies.");
      } finally {
        if (!cancelled) {
          setLoading(false);
        }
      }
    }

    loadMovies();

    return () => {
      cancelled = true;
    };
  }, [apiClient, filters, onSessionExpired]);

  function submitSearch(event) {
    event.preventDefault();
    setFilters((current) => ({ ...current, search: searchDraft.trim(), page: 1 }));
  }

  return (
    <section className="library-browser">
      <div className="section-heading">
        <div>
          <h2>Movies</h2>
          <p>Browse your permanent movie memory, independent from any provider.</p>
        </div>
        <span>{formatNumber(payload.pagination?.total || payload.items.length)} movies</span>
      </div>
      <LibraryToolbar
        searchLabel="Search movies"
        searchPlaceholder="Search your movies"
        searchValue={searchDraft}
        onSearchChange={setSearchDraft}
        onSearchSubmit={submitSearch}
        statusLabel="Movie status"
        statusValue={filters.status}
        onStatusChange={(status) => setFilters((current) => ({ ...current, status, page: 1 }))}
        statusOptions={[
          { value: "all", label: "All movies" },
          { value: "watched", label: "Watched" },
          { value: "watchlist", label: "Watchlist" },
          { value: "rated", label: "Rated" },
          { value: "notes", label: "With notes" },
        ]}
        sortLabel="Sort movies"
        sortValue={filters.sort}
        onSortChange={(sort) => setFilters((current) => ({ ...current, sort, page: 1 }))}
        sortOptions={[
          { value: "latest_watched", label: "Latest watched" },
          { value: "title", label: "Title" },
          { value: "rating", label: "Rating" },
          { value: "year", label: "Year" },
        ]}
      />
      <LibraryState error={error} loading={loading}>
        {payload.items.length ? (
          <div className="library-grid">
            {payload.items.map((item) => (
              <LibraryCard item={item} key={item.id} onOpen={onOpen} />
            ))}
          </div>
        ) : (
          <div className="empty-strip compact">No movies match these filters</div>
        )}
      </LibraryState>
      <PaginationControls
        pagination={payload.pagination}
        onPage={(page) => setFilters((current) => ({ ...current, page }))}
      />
    </section>
  );
}

export function ShowLibrary({
  apiClient = apiRequest,
  initialSearch = "",
  onOpen,
  onSessionExpired,
}) {
  const [searchDraft, setSearchDraft] = useState(initialSearch);
  const [filters, setFilters] = useState({
    search: initialSearch,
    status: "all",
    sort: "latest_watched",
    page: 1,
    per_page: 24,
  });
  const [payload, setPayload] = useState({ items: [], pagination: { page: 1, total: 0, hasMore: false } });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  useEffect(() => {
    setSearchDraft(initialSearch);
    setFilters((current) => (
      current.search === initialSearch ? current : { ...current, search: initialSearch, page: 1 }
    ));
  }, [initialSearch]);

  useEffect(() => {
    let cancelled = false;

    async function loadShows() {
      setLoading(true);
      setError("");

      try {
        const nextPayload = await apiClient(libraryEndpoint("/api/v1/library/shows", filters));

        if (!cancelled) {
          setPayload(nextPayload);
        }
      } catch (loadError) {
        if (cancelled) {
          return;
        }

        if (loadError instanceof SessionExpiredError) {
          onSessionExpired?.();
          return;
        }

        setError(loadError.message || "Could not load shows.");
      } finally {
        if (!cancelled) {
          setLoading(false);
        }
      }
    }

    loadShows();

    return () => {
      cancelled = true;
    };
  }, [apiClient, filters, onSessionExpired]);

  function submitSearch(event) {
    event.preventDefault();
    setFilters((current) => ({ ...current, search: searchDraft.trim(), page: 1 }));
  }

  return (
    <section className="library-browser">
      <div className="section-heading">
        <div>
          <h2>Shows</h2>
          <p>Browse followed series, watched progress, and canonical episodes.</p>
        </div>
        <span>{formatNumber(payload.pagination?.total || payload.items.length)} shows</span>
      </div>
      <LibraryToolbar
        searchLabel="Search shows"
        searchPlaceholder="Search your shows"
        searchValue={searchDraft}
        onSearchChange={setSearchDraft}
        onSearchSubmit={submitSearch}
        statusLabel="Show status"
        statusValue={filters.status}
        onStatusChange={(status) => setFilters((current) => ({ ...current, status, page: 1 }))}
        statusOptions={[
          { value: "all", label: "All shows" },
          { value: "followed", label: "Followed" },
          { value: "in_progress", label: "In progress" },
          { value: "completed", label: "Completed" },
          { value: "new_episodes", label: "New episodes" },
          { value: "rated", label: "Rated" },
          { value: "notes", label: "With notes" },
        ]}
        sortLabel="Sort shows"
        sortValue={filters.sort}
        onSortChange={(sort) => setFilters((current) => ({ ...current, sort, page: 1 }))}
        sortOptions={[
          { value: "latest_watched", label: "Latest watched" },
          { value: "title", label: "Title" },
          { value: "rating", label: "Rating" },
          { value: "progress", label: "Progress" },
        ]}
      />
      <LibraryState error={error} loading={loading}>
        {payload.items.length ? (
          <div className="library-grid">
            {payload.items.map((item) => (
              <LibraryCard item={item} key={item.id} onOpen={onOpen} />
            ))}
          </div>
        ) : (
          <div className="empty-strip compact">No shows match these filters</div>
        )}
      </LibraryState>
      <PaginationControls
        pagination={payload.pagination}
        onPage={(page) => setFilters((current) => ({ ...current, page }))}
      />
    </section>
  );
}

function PaginationControls({ pagination, onPage }) {
  if (!pagination || (pagination.page <= 1 && !pagination.hasMore)) {
    return null;
  }

  return (
    <div className="pagination-controls">
      <button
        className="text-action"
        disabled={pagination.page <= 1}
        onClick={() => onPage(Math.max(1, pagination.page - 1))}
        type="button"
      >
        Previous
      </button>
      <span>Page {pagination.page}</span>
      <button
        className="text-action"
        disabled={!pagination.hasMore}
        onClick={() => onPage(pagination.page + 1)}
        type="button"
      >
        Next
      </button>
    </div>
  );
}

export function HistorySection({
  apiClient = apiRequest,
  onOpen,
  onSessionExpired,
}) {
  const [searchDraft, setSearchDraft] = useState("");
  const [filters, setFilters] = useState({ type: "all", search: "", page: 1, per_page: 30 });
  const [payload, setPayload] = useState({ items: [], pagination: { page: 1, total: 0, hasMore: false } });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  useEffect(() => {
    let cancelled = false;

    async function loadHistory() {
      setLoading(true);
      setError("");

      try {
        const nextPayload = await apiClient(libraryEndpoint("/api/v1/library/history", filters));

        if (!cancelled) {
          setPayload(nextPayload);
        }
      } catch (loadError) {
        if (cancelled) {
          return;
        }

        if (loadError instanceof SessionExpiredError) {
          onSessionExpired?.();
          return;
        }

        setError(loadError.message || "Could not load watch history.");
      } finally {
        if (!cancelled) {
          setLoading(false);
        }
      }
    }

    loadHistory();

    return () => {
      cancelled = true;
    };
  }, [apiClient, filters, onSessionExpired]);

  function submitSearch(event) {
    event.preventDefault();
    setFilters((current) => ({ ...current, search: searchDraft.trim(), page: 1 }));
  }

  return (
    <section className="library-browser history-browser">
      <div className="section-heading">
        <div>
          <h2>Watch history</h2>
          <p>Your permanent viewing record stays here even when providers change.</p>
        </div>
        <span>{formatNumber(payload.pagination?.total || payload.items.length)} watches</span>
      </div>
      <form className="library-toolbar history-toolbar" onSubmit={submitSearch}>
        <label>
          <span>Search history</span>
          <input
            onChange={(event) => setSearchDraft(event.target.value)}
            placeholder="Search watched titles"
            type="search"
            value={searchDraft}
          />
        </label>
        <label>
          <span>History type</span>
          <select
            onChange={(event) => setFilters((current) => ({ ...current, type: event.target.value, page: 1 }))}
            value={filters.type}
          >
            <option value="all">Movies and episodes</option>
            <option value="movie">Movies</option>
            <option value="episode">Episodes</option>
          </select>
        </label>
        <button className="secondary-action" type="submit">
          Search
        </button>
      </form>
      <LibraryState error={error} loading={loading}>
        {payload.items.length ? (
          <div className="history-list">
            {payload.items.map((item) => (
              <button
                aria-label={`Open ${item.title} history`}
                className="history-row"
                key={item.id}
                onClick={() => onOpen(item)}
                type="button"
              >
                <img src={imageFor(item)} alt="" />
                <span>
                  <strong>{item.title}</strong>
                  <small>{item.subtitle}{item.showTitle ? ` · ${item.showTitle}` : ""}</small>
                </span>
                <em>{shortDate(item.watchedAt)}</em>
                <b>{sourceLabel(item.source)}</b>
              </button>
            ))}
          </div>
        ) : (
          <div className="empty-strip compact">No watch history matches these filters</div>
        )}
      </LibraryState>
      <PaginationControls
        pagination={payload.pagination}
        onPage={(page) => setFilters((current) => ({ ...current, page }))}
      />
    </section>
  );
}

export function GlobalSearchPanel({
  apiClient = apiRequest,
  onOpen,
  onSessionExpired,
  query = "",
}) {
  const [payload, setPayload] = useState({ movies: [], shows: [], episodes: [] });
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const safeQuery = query.trim();

  useEffect(() => {
    let cancelled = false;

    async function searchLibrary() {
      if (safeQuery.length < 2) {
        setPayload({ movies: [], shows: [], episodes: [] });
        setError("");
        return;
      }

      setLoading(true);
      setError("");

      try {
        const nextPayload = await apiClient(`/api/v1/library/search?${buildQueryString({ query: safeQuery })}`);

        if (!cancelled) {
          setPayload(nextPayload);
        }
      } catch (searchError) {
        if (cancelled) {
          return;
        }

        if (searchError instanceof SessionExpiredError) {
          onSessionExpired?.();
          return;
        }

        setError(searchError.message || "Search failed.");
      } finally {
        if (!cancelled) {
          setLoading(false);
        }
      }
    }

    searchLibrary();

    return () => {
      cancelled = true;
    };
  }, [apiClient, onSessionExpired, safeQuery]);

  if (safeQuery.length < 2) {
    return null;
  }

  const groups = [
    { id: "movies", title: "Movies", items: payload.movies || [] },
    { id: "shows", title: "Shows", items: payload.shows || [] },
    { id: "episodes", title: "Episodes", items: payload.episodes || [] },
  ];
  const totalResults = groups.reduce((total, group) => total + group.items.length, 0);

  return (
    <section className="global-search-panel">
      <div className="section-heading">
        <div>
          <span>Canonical search</span>
          <h2>Results for “{safeQuery}”</h2>
        </div>
        <em>{loading ? "Searching..." : `${totalResults} matches`}</em>
      </div>
      {error ? <div className="detail-error">{error}</div> : null}
      {groups.map((group) => (
        group.items.length ? (
          <div className="search-result-group" key={group.id}>
            <h3>{group.title}</h3>
            <div>
              {group.items.map((item) => (
                <button
                  aria-label={`Open ${item.title}`}
                  className="search-result-row"
                  key={`${group.id}-${item.id}`}
                  onClick={() => onOpen(item)}
                  type="button"
                >
                  <img src={imageFor(item)} alt="" />
                  <span>
                    <strong>{item.title}</strong>
                    <small>{item.meta || item.subtitle}</small>
                  </span>
                </button>
              ))}
            </div>
          </div>
        ) : null
      ))}
      {!loading && !error && totalResults === 0 ? (
        <div className="empty-strip compact">No canonical matches yet</div>
      ) : null}
    </section>
  );
}

function StatsStrip({ stats }) {
  const cards = [
    {
      label: "Episodes watched",
      value: stats.episodesWatched,
      icon: TelevisionSimple,
    },
    { label: "Movies watched", value: stats.moviesWatched, icon: FilmSlate },
    { label: "Hours watched", value: stats.hoursWatched, icon: Clock },
    { label: "Shows followed", value: stats.showsFollowed, icon: SquaresFour },
  ];

  return (
    <section className="stats-strip">
      {cards.map((card) => {
        const Icon = card.icon;
        return (
          <div className="stat-card" key={card.label}>
            <Icon size={26} />
            <strong>{formatNumber(card.value)}</strong>
            <span>{card.label}</span>
          </div>
        );
      })}
    </section>
  );
}

function ActivityChart({ activity }) {
  const bars = buildActivityBars(activity);
  return (
    <section className="activity-panel">
      <div className="section-heading">
        <h2>Watching activity</h2>
        <span>Last 7 days</span>
      </div>
      <div className="chart-grid">
        {bars.map((bar) => (
          <div className="chart-day" key={bar.date || bar.day}>
            <span>{bar.hours}</span>
            <i style={{ height: `${bar.height}%` }} />
            <small>{bar.day}</small>
          </div>
        ))}
      </div>
    </section>
  );
}

export function TimelinePanel({ timeline }) {
  const events = timeline?.recent || [];
  const grouped = events.reduce((groups, event) => {
    const group = event.group || "Earlier";
    return {
      ...groups,
      [group]: [...(groups[group] || []), event],
    };
  }, {});
  const defaultGroups = ["Today", "Yesterday", "This week", "Earlier"];
  const orderedGroups = [
    ...defaultGroups.filter((group) => grouped[group]?.length),
    ...Object.keys(grouped).filter((group) => !defaultGroups.includes(group)),
  ];

  return (
    <section className="timeline-panel">
      <div className="section-heading">
        <div>
          <h2>Entertainment diary</h2>
          <p>A private memory of what you watch, rate, and save.</p>
        </div>
        <span>{formatNumber(timeline?.thisWeekSummary?.total || 0)} memories this week</span>
      </div>
      {events.length ? (
        <div className="timeline-list">
          {orderedGroups.map((group) => (
            <div className="timeline-group" key={group}>
              <span>{group}</span>
              {grouped[group].map((event) => (
                <article className="timeline-row" key={event.id} aria-label={event.title}>
                  <i />
                  <div>
                    <strong>{event.title}</strong>
                    <small>{event.subtitle}</small>
                  </div>
                  <em>{shortTime(event.occurredAt)}</em>
                  <b>{sourceLabel(event.source)}</b>
                </article>
              ))}
            </div>
          ))}
        </div>
      ) : (
        <div className="timeline-empty">Your entertainment diary is quiet for now. Watch, rate, note, or link media and the meaningful moments will appear here.</div>
      )}
    </section>
  );
}

export function DetailModal({
  item,
  detail,
  detailError = "",
  detailLoading = false,
  actionError = "",
  actionPending = false,
  onClose,
  onSaveRating,
  onClearRating,
  onSaveNote,
  onDeleteNote,
  onMarkWatched,
  onMarkUnwatched,
  onOpenEpisode,
}) {
  const [noteBody, setNoteBody] = useState("");
  const closeButtonRef = useRef(null);

  useEffect(() => {
    setNoteBody(detail?.notes?.[0]?.body || "");
  }, [detail?.id, detail?.kind, detail?.notes]);

  useEffect(() => {
    closeButtonRef.current?.focus();
  }, [item?.id]);

  useEffect(() => {
    function handleKeyDown(event) {
      if (event.key === "Escape") {
        onClose?.();
      }
    }

    document.addEventListener("keydown", handleKeyDown);

    return () => {
      document.removeEventListener("keydown", handleKeyDown);
    };
  }, [onClose]);

  if (!item) {
    return null;
  }

  const isAlert = "category" in item;
  const view = detail || item;
  const primaryNote = detail?.notes?.[0] || null;
  const rating = detail?.rating?.rating || null;
  const canManualWatch = detail?.kind === "movie" || detail?.kind === "episode";
  const hasManualWatch = (detail?.watchHistory || []).some((watch) => watch.source === "manual");
  const metadata = detail?.metadata || view?.metadata || {};
  const metadataTags = [
    ...(metadata.genres || []),
    metadata.releaseYear,
    metadata.runtime ? `${metadata.runtime} min` : null,
    metadata.tmdbId ? `TMDB #${metadata.tmdbId}` : null,
    metadata.imdbId ? `IMDb ${metadata.imdbId}` : null,
    metadata.tvdbId ? `TVDB ${metadata.tvdbId}` : null,
    metadata.metadataStatus,
  ].filter(Boolean);
  const providerLabel = detail?.provider?.linked
    ? "Linked to your source"
    : "Manual tracking only";
  const detailTimeline = detail?.timeline || [];

  function submitNote(event) {
    event.preventDefault();
    onSaveNote?.(detail, noteBody, primaryNote);
  }

  return (
    <div className="modal-layer" role="presentation" onMouseDown={onClose}>
      <section
        className="detail-modal"
        role="dialog"
        aria-modal="true"
        aria-label={`${view.title} details`}
        onMouseDown={(event) => event.stopPropagation()}
      >
        <button ref={closeButtonRef} className="modal-close" onClick={onClose} type="button" aria-label="Close">
          <X size={20} />
        </button>
        {!isAlert ? (
          <img className="modal-art" src={imageFor(view)} alt="" />
        ) : (
          <div className="modal-alert-art">
            <Bell size={48} weight="duotone" />
          </div>
        )}
        <div className="modal-copy">
          <span className="eyebrow">{isAlert ? item.category : view.kind}</span>
          <h2>{view.title}</h2>
          <p>{isAlert ? item.subtitle : view.meta}</p>
          {!isAlert ? (
            <>
              <dl>
                <div>
                  <dt>Status</dt>
                  <dd>{view.status || item.badge || "saved"}</dd>
                </div>
                <div>
                  <dt>Progress</dt>
                  <dd>{view.progress || (view.watched ? 100 : 0)}%</dd>
                </div>
                <div>
                  <dt>Watched</dt>
                  <dd>{view.watched ? "Yes" : shortDate(view.watchedAt) || "Not yet"}</dd>
                </div>
              </dl>

              {metadataTags.length ? (
                <div className="metadata-strip" aria-label="Metadata">
                  {metadataTags.map((tag) => (
                    <span key={tag}>{tag}</span>
                  ))}
                </div>
              ) : null}

              {detailLoading ? <div className="detail-state">Loading details...</div> : null}
              {detailError ? <div className="detail-error">{detailError}</div> : null}
              {actionError ? <div className="detail-error">{actionError}</div> : null}

              {detail ? (
                <div className="manual-library-panel">
                  <section className="detail-section">
                    <div className="detail-section-heading">
                      <strong>Your rating</strong>
                      <span>{rating ? `${rating}/10` : "Not rated"}</span>
                    </div>
                    <div className="rating-control" aria-label="Your rating">
                      {Array.from({ length: 10 }, (_, index) => index + 1).map((value) => (
                        <button
                          aria-pressed={rating === value}
                          className={rating === value ? "active" : ""}
                          disabled={actionPending}
                          key={value}
                          onClick={() => onSaveRating?.(detail, value)}
                          type="button"
                        >
                          {value}
                        </button>
                      ))}
                    </div>
                    <button
                      className="text-action"
                      disabled={actionPending || !rating}
                      onClick={() => onClearRating?.(detail)}
                      type="button"
                    >
                      Clear rating
                    </button>
                  </section>

                  <section className="detail-section">
                    <div className="detail-section-heading">
                      <strong>Private memory</strong>
                      <span>{primaryNote ? "Saved" : "Only you can see this"}</span>
                    </div>
                    <form className="note-form" onSubmit={submitNote}>
                      <label>
                        <span>Note</span>
                        <textarea
                          aria-label="Private note"
                          disabled={actionPending}
                          onChange={(event) => setNoteBody(event.target.value)}
                          value={noteBody}
                        />
                      </label>
                      <div className="modal-actions">
                        <button
                          className="secondary-action"
                          disabled={actionPending || !noteBody.trim()}
                          type="submit"
                        >
                          Save note
                        </button>
                        {primaryNote ? (
                          <button
                            className="text-action danger"
                            disabled={actionPending}
                            onClick={() => onDeleteNote?.(detail, primaryNote)}
                            type="button"
                          >
                            Delete note
                          </button>
                        ) : null}
                      </div>
                    </form>
                  </section>

                  <section className="detail-section">
                    <div className="detail-section-heading">
                      <strong>Watch history</strong>
                      <span>{providerLabel}</span>
                    </div>
                    {canManualWatch ? (
                      <button
                        className="primary-action compact-action"
                        disabled={actionPending}
                        onClick={() =>
                          hasManualWatch
                            ? onMarkUnwatched?.(detail)
                            : onMarkWatched?.(detail)
                        }
                        type="button"
                      >
                        <CheckCircle size={18} weight="fill" />
                        {hasManualWatch ? "Remove manual watch" : "Add to watch history"}
                      </button>
                    ) : null}
                    <div className="watch-history">
                      {detail.watchHistory?.length ? (
                        detail.watchHistory.map((watch) => (
                          <div key={watch.id}>
                            <span>{shortDate(watch.watchedAt) || "Unknown date"}</span>
                            <strong>{sourceLabel(watch.source)}</strong>
                          </div>
                        ))
                      ) : (
                        <em>No watch history yet</em>
                      )}
                    </div>
                  </section>

                  {detail.kind === "show" && detail.seasons?.length ? (
                    <section className="detail-section">
                      <div className="detail-section-heading">
                        <strong>Episodes</strong>
                        <span>{detail.seasons.length} seasons</span>
                      </div>
                      <div className="season-browser">
                        {detail.seasons.map((season) => (
                          <div className="season-group" key={season.seasonNumber}>
                            <div className="season-heading">
                              <strong>Season {season.seasonNumber}</strong>
                              <span>{season.watchedEpisodes}/{season.totalEpisodes} watched</span>
                            </div>
                            <div className="episode-list">
                              {season.episodes.map((episode) => (
                                <button
                                  aria-label={`Open ${episode.title}`}
                                  className={`episode-row ${episode.watched ? "watched" : ""}`}
                                  key={episode.id}
                                  onClick={() => onOpenEpisode?.({
                                    episodeId: episode.episodeId || episode.id,
                                    showId: episode.showId || detail.showId,
                                    kind: "episode",
                                    title: episode.title,
                                    subtitle: episode.code,
                                    meta: `${detail.title} - ${episode.code}`,
                                  })}
                                  type="button"
                                >
                                  <span>
                                    <strong>{episode.title}</strong>
                                    <small>{episode.code}</small>
                                  </span>
                                  <em>{episode.watched ? "Watched" : "Not watched"}</em>
                                </button>
                              ))}
                            </div>
                          </div>
                        ))}
                      </div>
                    </section>
                  ) : null}

                  <section className="detail-section">
                    <div className="detail-section-heading">
                      <strong>Entertainment diary</strong>
                      <span>{detailTimeline.length ? `${detailTimeline.length} moments` : "No moments yet"}</span>
                    </div>
                    <div className="detail-timeline">
                      {detailTimeline.length ? (
                        detailTimeline.slice(0, 3).map((event) => (
                          <div key={event.id}>
                            <span>
                              <strong>{event.title}</strong>
                              <small>{event.subtitle || sourceLabel(event.source)}</small>
                            </span>
                            <em>{shortDate(event.occurredAt) || sourceLabel(event.source)}</em>
                          </div>
                        ))
                      ) : (
                        <em>Meaningful moments for this title will appear here.</em>
                      )}
                    </div>
                  </section>
                </div>
              ) : null}
            </>
          ) : (
            <dl>
              <div>
                <dt>Alert</dt>
                <dd>{item.dueText}</dd>
              </div>
              <div>
                <dt>Delivery</dt>
                <dd>Site only</dd>
              </div>
            </dl>
          )}
          <button className="primary-action" type="button" onClick={onClose}>
            <CheckCircle size={18} weight="fill" />
            Done
          </button>
        </div>
      </section>
    </div>
  );
}

function playerTargetPayload(target) {
  if (!target) {
    return {};
  }

  return {
    [`${target.type}_id`]: target.id,
    confirm: true,
  };
}

export function PlayerSection({
  apiClient = apiRequest,
  onRefreshDashboard,
  onSessionExpired,
  player,
}) {
  const safePlayer = player || fallbackData.player;
  const [sources, setSources] = useState([]);
  const [items, setItems] = useState(safePlayer.sourceItems || []);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState("");
  const [itemQuery, setItemQuery] = useState("");
  const [sourceForm, setSourceForm] = useState({
    name: "",
    providerType: "manual",
    legalConfirmed: false,
  });
  const [itemForm, setItemForm] = useState({
    sourceId: "",
    title: "",
    kind: "movie",
    streamUrl: "",
  });
  const [linkingItem, setLinkingItem] = useState(null);
  const [targetQuery, setTargetQuery] = useState("");
  const [targetType, setTargetType] = useState("");
  const [targets, setTargets] = useState([]);
  const [selectedTarget, setSelectedTarget] = useState(null);
  const [confirmLink, setConfirmLink] = useState(false);
  const [playback, setPlayback] = useState(null);
  const [progressForm, setProgressForm] = useState({
    positionSeconds: "0",
    durationSeconds: "0",
  });
  const videoRef = useRef(null);

  const continueWatching = safePlayer.continueWatching || [];
  const sourceItems = items.length ? items : safePlayer.sourceItems || [];
  const linkedItems = sourceItems.filter((item) => item.linked);
  const unlinkedItems = sourceItems.filter((item) => !item.linked);
  const activeSources = sources.filter((source) => source.status === "active");
  const selectedSourceId = itemForm.sourceId || activeSources[0]?.id || sources[0]?.id || "";

  useEffect(() => {
    let cancelled = false;

    async function loadInitialPlayerData() {
      setLoading(true);
      setError("");

      try {
        const [sourcePayload, itemPayload] = await Promise.all([
          apiClient("/api/v1/player/sources"),
          apiClient("/api/v1/player/items"),
        ]);

        if (cancelled) {
          return;
        }

        const nextSources = sourcePayload?.sources || [];

        setSources(nextSources);
        setItems(itemPayload?.items || []);

        if (!itemForm.sourceId && nextSources[0]?.id) {
          setItemForm((current) => ({ ...current, sourceId: String(nextSources[0].id) }));
        }
      } catch (loadError) {
        if (cancelled) {
          return;
        }

        if (loadError instanceof SessionExpiredError) {
          onSessionExpired?.();
          return;
        }

        setError(loadError.message || "Could not load player sources.");
      } finally {
        if (!cancelled) {
          setLoading(false);
        }
      }
    }

    loadInitialPlayerData();

    return () => {
      cancelled = true;
    };
  }, [apiClient, onSessionExpired]);

  useEffect(() => {
    const video = videoRef.current;
    const playbackUrl = playback?.playbackUrl || "";

    if (!video || !playbackUrl.includes(".m3u8") || video.canPlayType("application/vnd.apple.mpegurl")) {
      return undefined;
    }

    let hls = null;
    let cancelled = false;

    import("hls.js/light").then(({ default: Hls }) => {
      if (cancelled || !Hls.isSupported()) {
        return;
      }

      hls = new Hls();
      hls.loadSource(playbackUrl);
      hls.attachMedia(video);
    });

    return () => {
      cancelled = true;
      hls?.destroy();
    };
  }, [playback?.playbackUrl]);

  async function loadPlayerData(filters = {}) {
    const params = new URLSearchParams();

    if (filters.q) {
      params.set("q", filters.q);
    }

    const itemPath = params.toString()
      ? `/api/v1/player/items?${params.toString()}`
      : "/api/v1/player/items";

    const [sourcePayload, itemPayload] = await Promise.all([
      apiClient("/api/v1/player/sources"),
      apiClient(itemPath),
    ]);

    const nextSources = sourcePayload?.sources || [];

    setSources(nextSources);
    setItems(itemPayload?.items || []);

    if (!itemForm.sourceId && nextSources[0]?.id) {
      setItemForm((current) => ({ ...current, sourceId: String(nextSources[0].id) }));
    }
  }

  async function runPlayerAction(action, pendingLabel = "Saving") {
    setBusy(pendingLabel);
    setError("");

    try {
      await action();
    } catch (actionError) {
      if (actionError instanceof SessionExpiredError) {
        onSessionExpired?.();
        return;
      }

      setError(actionError.message || "Player action failed.");
    } finally {
      setBusy("");
    }
  }

  async function handleCreateSource(event) {
    event.preventDefault();

    await runPlayerAction(async () => {
      await apiClient("/api/v1/player/sources", {
        method: "POST",
        body: {
          name: sourceForm.name.trim(),
          provider_type: sourceForm.providerType,
          legal_confirmed: sourceForm.legalConfirmed,
        },
      });

      setSourceForm({ name: "", providerType: "manual", legalConfirmed: false });
      await loadPlayerData({ q: itemQuery });
      await onRefreshDashboard?.();
    }, "Attaching");
  }

  async function handleSourceStatus(source, status) {
    await runPlayerAction(async () => {
      await apiClient(`/api/v1/player/sources/${source.id}`, {
        method: "PATCH",
        body: { status },
      });
      await loadPlayerData({ q: itemQuery });
      await onRefreshDashboard?.();
    }, status === "disabled" ? "Disabling" : "Enabling");
  }

  async function handleDeleteSource(source) {
    await runPlayerAction(async () => {
      await apiClient(`/api/v1/player/sources/${source.id}`, { method: "DELETE" });
      await loadPlayerData({ q: itemQuery });
      await onRefreshDashboard?.();
    }, "Deleting");
  }

  async function handleCreateItem(event) {
    event.preventDefault();

    await runPlayerAction(async () => {
      await apiClient(`/api/v1/player/sources/${selectedSourceId}/items`, {
        method: "POST",
        body: {
          title: itemForm.title.trim(),
          kind: itemForm.kind,
          stream_url: itemForm.streamUrl.trim(),
        },
      });

      setItemForm((current) => ({ ...current, title: "", streamUrl: "" }));
      await loadPlayerData({ q: itemQuery });
      await onRefreshDashboard?.();
    }, "Adding");
  }

  async function handleSearchItems(event) {
    event.preventDefault();

    await runPlayerAction(async () => {
      await loadPlayerData({ q: itemQuery.trim() });
    }, "Searching");
  }

  function openLinkModal(item) {
    setLinkingItem(item);
    setTargetQuery(item.title || "");
    setTargetType(item.kind === "show" ? "show" : item.kind === "episode" ? "episode" : "movie");
    setTargets([]);
    setSelectedTarget(null);
    setConfirmLink(false);
    setError("");
  }

  async function handleTargetSearch(event) {
    event.preventDefault();

    await runPlayerAction(async () => {
      const params = new URLSearchParams();
      if (targetQuery.trim()) {
        params.set("q", targetQuery.trim());
      }
      if (targetType) {
        params.set("type", targetType);
      }

      const payload = await apiClient(`/api/v1/player/link-targets?${params.toString()}`);
      setTargets(payload?.targets || []);
    }, "Searching");
  }

  async function handleLinkItem(event) {
    event.preventDefault();

    await runPlayerAction(async () => {
      await apiClient(`/api/v1/player/items/${linkingItem.id}/link`, {
        method: "POST",
        body: playerTargetPayload(selectedTarget),
      });

      setLinkingItem(null);
      setTargets([]);
      setSelectedTarget(null);
      setConfirmLink(false);
      await loadPlayerData({ q: itemQuery });
      await onRefreshDashboard?.();
    }, "Linking");
  }

  async function handleUnlinkItem(item) {
    await runPlayerAction(async () => {
      await apiClient(`/api/v1/player/items/${item.id}/link`, { method: "DELETE" });
      await loadPlayerData({ q: itemQuery });
      await onRefreshDashboard?.();
    }, "Unlinking");
  }

  async function handlePlayItem(item) {
    await runPlayerAction(async () => {
      const payload = await apiClient(`/api/v1/player/items/${item.id}/play`, { method: "POST" });

      setPlayback({
        item,
        session: payload.session,
        playbackUrl: payload.playbackUrl,
      });
      setProgressForm({
        positionSeconds: "0",
        durationSeconds: "",
      });
    }, "Starting");
  }

  async function handleSaveProgress(completed = false) {
    if (!playback?.session?.id) {
      return;
    }

    const position = Number(progressForm.positionSeconds || 0);
    const duration = Number(progressForm.durationSeconds || 0);

    await runPlayerAction(async () => {
      const payload = await apiClient(`/api/v1/player/sessions/${playback.session.id}`, {
        method: "PATCH",
        body: {
          position_seconds: position,
          duration_seconds: duration,
          completed,
        },
      });

      setPlayback((current) => current ? { ...current, session: payload.session } : current);
      await onRefreshDashboard?.();
    }, completed ? "Completing" : "Saving");
  }

  function renderSourceItem(item) {
    return (
      <div className="player-row player-item-row" key={item.id}>
        <FilmSlate size={22} />
        <span>
          <strong>{item.title}</strong>
          <small>
            {item.linked
              ? `linked to ${item.link?.canonicalTitle || "library item"}`
              : "needs linking"} · {item.sourceName}
          </small>
        </span>
        <div className="player-row-actions">
          <button className="text-action" onClick={() => handlePlayItem(item)} type="button">
            Play {item.title}
          </button>
          {item.linked ? (
            <button className="text-action danger" onClick={() => handleUnlinkItem(item)} type="button">
              Unlink {item.title}
            </button>
          ) : (
            <button className="text-action" onClick={() => openLinkModal(item)} type="button">
              Link {item.title}
            </button>
          )}
        </div>
      </div>
    );
  }

  return (
    <div className="focus-block player-board">
      {!safePlayer.enabled && (
        <div className="quiet-note player-empty-state">
          <Play size={34} weight="duotone" />
          <h2>Player</h2>
          <p>{safePlayer.emptyState}</p>
        </div>
      )}

      {error ? <div className="detail-error">{error}</div> : null}

      <section className="player-panel provider-panel">
        <div className="section-heading">
          <div>
            <h2>Your private sources</h2>
            <p>Playback is available only from sources attached to this account.</p>
          </div>
          <span>{sources.length} attached</span>
        </div>
        <form className="player-form" onSubmit={handleCreateSource}>
          <label>
            <span>Source name</span>
            <input
              onChange={(event) => setSourceForm((current) => ({ ...current, name: event.target.value }))}
              placeholder="My NAS"
              required
              type="text"
              value={sourceForm.name}
            />
          </label>
          <label>
            <span>Provider type</span>
            <select
              onChange={(event) => setSourceForm((current) => ({ ...current, providerType: event.target.value }))}
              value={sourceForm.providerType}
            >
              <option value="manual">Manual source</option>
              <option value="plex">Plex</option>
              <option value="jellyfin">Jellyfin</option>
              <option value="emby">Emby</option>
              <option value="smb">SMB share</option>
              <option value="nas">NAS</option>
              <option value="local">Local folder</option>
            </select>
          </label>
          <label className="check-row">
            <input
              checked={sourceForm.legalConfirmed}
              onChange={(event) => setSourceForm((current) => ({ ...current, legalConfirmed: event.target.checked }))}
              required
              type="checkbox"
            />
            <span>This is my private source, and I am allowed to use it.</span>
          </label>
          <button className="primary-action" disabled={busy === "Attaching"} type="submit">
            Attach source
          </button>
        </form>
        <div className="provider-list">
          {loading ? <div className="empty-strip compact">Loading providers...</div> : null}
          {sources.map((source) => (
            <div className="provider-row" key={source.id}>
              <span>
                <strong>{source.name}</strong>
                <small>{source.providerType} · {source.status} · {source.itemsCount || 0} items</small>
              </span>
              <div>
                {source.status === "active" ? (
                  <button className="text-action" onClick={() => handleSourceStatus(source, "disabled")} type="button">
                    Disable
                  </button>
                ) : (
                  <button className="text-action" onClick={() => handleSourceStatus(source, "active")} type="button">
                    Enable
                  </button>
                )}
                <button className="text-action danger" onClick={() => handleDeleteSource(source)} type="button">
                  Delete
                </button>
              </div>
            </div>
          ))}
          {!loading && sources.length === 0 ? <div className="empty-strip compact">No providers attached</div> : null}
        </div>
      </section>

      <section className="player-panel">
        <div className="section-heading">
          <div>
            <h2>Add source item</h2>
            <p>URLs stay private and are never shown in library lists.</p>
          </div>
          <span>Manual first</span>
        </div>
        <form className="player-form source-item-form" onSubmit={handleCreateItem}>
          <label>
            <span>Source</span>
            <select
              disabled={!sources.length}
              onChange={(event) => setItemForm((current) => ({ ...current, sourceId: event.target.value }))}
              value={String(selectedSourceId)}
            >
              {sources.map((source) => (
                <option key={source.id} value={source.id}>
                  {source.name}
                </option>
              ))}
            </select>
          </label>
          <label>
            <span>Item title</span>
            <input
              disabled={!sources.length}
              onChange={(event) => setItemForm((current) => ({ ...current, title: event.target.value }))}
              placeholder="Movie or episode file"
              required
              type="text"
              value={itemForm.title}
            />
          </label>
          <label>
            <span>Kind</span>
            <select
              disabled={!sources.length}
              onChange={(event) => setItemForm((current) => ({ ...current, kind: event.target.value }))}
              value={itemForm.kind}
            >
              <option value="movie">Movie</option>
              <option value="show">Show</option>
              <option value="episode">Episode</option>
            </select>
          </label>
          <label>
            <span>Stream or file URL</span>
            <input
              disabled={!sources.length}
              onChange={(event) => setItemForm((current) => ({ ...current, streamUrl: event.target.value }))}
              placeholder="https://..."
              required
              type="url"
              value={itemForm.streamUrl}
            />
          </label>
          <button className="secondary-action" disabled={!sources.length || busy === "Adding"} type="submit">
            Add source item
          </button>
        </form>
      </section>

      <section className="player-panel">
        <div className="section-heading">
          <h2>Continue watching</h2>
          <span>{continueWatching.length} active</span>
        </div>
        <div className="player-list">
          {continueWatching.length ? (
            continueWatching.map((item) => (
              <button className="player-row" key={item.id} type="button">
                <Play size={22} weight="fill" />
                <span>
                  <strong>{item.title || "Untitled item"}</strong>
                  <small>{item.kind || "source"} · {item.positionSeconds || 0}s</small>
                </span>
              </button>
            ))
          ) : (
            <div className="empty-strip compact">Nothing in progress</div>
          )}
        </div>
      </section>
      <section className="player-panel">
        <div className="section-heading">
          <div>
            <h2>Source items</h2>
            <p>Linking connects playback to permanent watch history.</p>
          </div>
          <span>{sourceItems.length} available</span>
        </div>
        <form className="player-search" onSubmit={handleSearchItems}>
          <label>
            <span>Filter source items</span>
            <input
              onChange={(event) => setItemQuery(event.target.value)}
              placeholder="Search source items"
              type="search"
              value={itemQuery}
            />
          </label>
          <button className="secondary-action" type="submit">Search</button>
        </form>
        <div className="player-list source-item-groups">
          {linkedItems.length ? (
            <section className="source-item-group">
              <div className="source-item-group-heading">
                <h3>Linked to library</h3>
                <span>{linkedItems.length}</span>
              </div>
              {linkedItems.slice(0, 20).map(renderSourceItem)}
            </section>
          ) : null}
          {unlinkedItems.length ? (
            <section className="source-item-group">
              <div className="source-item-group-heading">
                <h3>Needs linking</h3>
                <span>{unlinkedItems.length}</span>
              </div>
              <p className="source-item-hint">Unlinked playback keeps progress on the source item only until you connect it to your library.</p>
              {unlinkedItems.slice(0, 20).map(renderSourceItem)}
            </section>
          ) : null}
          {!sourceItems.length ? <div className="empty-strip compact">No source items yet</div> : null}
        </div>
      </section>

      {playback ? (
        <section className="player-panel playback-panel">
          <div className="section-heading">
            <h2>Now playing</h2>
            <span>{playback.session?.status || "playing"}</span>
          </div>
          <strong>{playback.item.title}</strong>
          {!playback.item.linked ? (
            <div className="data-warning">Progress is saved only to this private source until linked.</div>
          ) : null}
          <video
            className="provider-video"
            controls
            data-testid="provider-video"
            ref={videoRef}
            src={playback.playbackUrl}
          />
          <div className="progress-controls">
            <label>
              <span>Position seconds</span>
              <input
                min="0"
                onChange={(event) => setProgressForm((current) => ({ ...current, positionSeconds: event.target.value }))}
                type="number"
                value={progressForm.positionSeconds}
              />
            </label>
            <label>
              <span>Duration seconds</span>
              <input
                min="0"
                onChange={(event) => setProgressForm((current) => ({ ...current, durationSeconds: event.target.value }))}
                type="number"
                value={progressForm.durationSeconds}
              />
            </label>
            <button className="secondary-action" onClick={() => handleSaveProgress(false)} type="button">
              Save progress
            </button>
            <button className="primary-action" onClick={() => handleSaveProgress(true)} type="button">
              Mark complete
            </button>
          </div>
        </section>
      ) : null}

      <section className="player-summary-grid">
        <div>
          <strong>{linkedItems.length}</strong>
          <span>Linked</span>
        </div>
        <div>
          <strong>{unlinkedItems.length}</strong>
          <span>Unlinked</span>
        </div>
      </section>

      {linkingItem ? (
        <div className="modal-layer" role="presentation" onMouseDown={() => setLinkingItem(null)}>
          <section
            aria-label={`Link ${linkingItem.title}`}
            aria-modal="true"
            className="link-modal"
            onMouseDown={(event) => event.stopPropagation()}
            role="dialog"
          >
            <button className="modal-close" onClick={() => setLinkingItem(null)} type="button" aria-label="Close">
              <X size={18} />
            </button>
            <div className="section-heading">
              <h2>Link source item</h2>
              <span>{linkingItem.title}</span>
            </div>
            <form className="player-form" onSubmit={handleTargetSearch}>
              <label>
                <span>Search your library</span>
                <input
                  onChange={(event) => setTargetQuery(event.target.value)}
                  type="search"
                  value={targetQuery}
                />
              </label>
              <label>
                <span>Target type</span>
                <select onChange={(event) => setTargetType(event.target.value)} value={targetType}>
                  <option value="">Any</option>
                  <option value="movie">Movie</option>
                  <option value="show">Show</option>
                  <option value="episode">Episode</option>
                </select>
              </label>
              <button className="secondary-action" type="submit">Search library</button>
            </form>
            <div className="target-list">
              {targets.map((target) => (
                <button
                  aria-label={`${target.title} ${target.subtitle}${target.meta ? ` ${target.meta}` : ""}`}
                  className={selectedTarget?.type === target.type && selectedTarget?.id === target.id ? "target-row active" : "target-row"}
                  key={`${target.type}-${target.id}`}
                  onClick={() => setSelectedTarget(target)}
                  type="button"
                >
                  <strong>{target.title}</strong>
                  <small>{target.subtitle}{target.meta ? ` ${target.meta}` : ""}</small>
                </button>
              ))}
              {!targets.length ? <div className="empty-strip compact">Search to find a matching library item</div> : null}
            </div>
            <form className="player-form" onSubmit={handleLinkItem}>
              <label className="check-row">
                <input
                  checked={confirmLink}
                  onChange={(event) => setConfirmLink(event.target.checked)}
                  required
                  type="checkbox"
                />
                <span>I confirm this source item matches the selected library item.</span>
              </label>
              <button className="primary-action" disabled={!selectedTarget || !confirmLink || busy === "Linking"} type="submit">
                Link item
              </button>
            </form>
          </section>
        </div>
      ) : null}
    </div>
  );
}

function FocusSection({
  activeSection,
  activity,
  alerts,
  apiClient,
  collections,
  globalQuery,
  onOpen,
  onPlayerRefresh,
  onSessionExpired,
  player,
  stats,
}) {
  if (activeSection === "shows") {
    return (
      <ShowLibrary
        apiClient={apiClient}
        initialSearch={globalQuery}
        onOpen={onOpen}
        onSessionExpired={onSessionExpired}
      />
    );
  }

  if (activeSection === "movies") {
    return (
      <MovieLibrary
        apiClient={apiClient}
        initialSearch={globalQuery}
        onOpen={onOpen}
        onSessionExpired={onSessionExpired}
      />
    );
  }

  if (activeSection === "history") {
    return (
      <HistorySection
        apiClient={apiClient}
        onOpen={onOpen}
        onSessionExpired={onSessionExpired}
      />
    );
  }

  if (activeSection === "alerts") {
    return (
      <div className="focus-block alert-board">
        {alerts.map((alert) => (
          <button className="wide-alert" key={alert.id} onClick={() => onOpen(alert)} type="button">
            <Bell size={24} />
            <span>
              <strong>{alert.title}</strong>
              <small>{alert.subtitle}</small>
            </span>
            <em>{alert.dueText}</em>
          </button>
        ))}
      </div>
    );
  }

  if (activeSection === "player") {
    return (
      <PlayerSection
        apiClient={apiClient}
        onRefreshDashboard={onPlayerRefresh}
        onSessionExpired={onSessionExpired}
        player={player}
      />
    );
  }

  if (activeSection === "stats") {
    return (
      <div className="focus-block metrics-board">
        <StatsStrip stats={stats} />
        <ActivityChart activity={activity} />
      </div>
    );
  }

  if (activeSection === "settings") {
    return (
      <div className="focus-block quiet-note">
        <CalendarDots size={34} weight="duotone" />
        <h2>Private by default</h2>
        <p>
          Raw exports, generated JSON, SQLite, and poster assets are ignored locally so the sensitive archive is not accidentally committed.
        </p>
      </div>
    );
  }

  return null;
}

export function App() {
  const [dashboard, setDashboard] = useState(fallbackData);
  const [authUser, setAuthUser] = useState(null);
  const [appState, setAppState] = useState("checking");
  const [query, setQuery] = useState("");
  const [activeSection, setActiveSection] = useState("home");
  const [activeAlertTab, setActiveAlertTab] = useState("all");
  const [selectedItem, setSelectedItem] = useState(null);
  const [selectedDetail, setSelectedDetail] = useState(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [detailError, setDetailError] = useState("");
  const [detailActionError, setDetailActionError] = useState("");
  const [detailActionPending, setDetailActionPending] = useState(false);
  const [readAlerts, setReadAlerts] = useState(() => new Set());
  const [loadState, setLoadState] = useState("loading");
  const [authError, setAuthError] = useState("");
  const [apiError, setApiError] = useState("");
  const [submittingLogin, setSubmittingLogin] = useState(false);

  useEffect(() => {
    let cancelled = false;

    async function loadAuthenticatedDashboard() {
      try {
        const me = await apiRequest("/api/v1/me");
        const payload = await apiRequest("/api/v1/dashboard");

        if (cancelled) {
          return;
        }

        setAuthUser(me.user);
        setDashboard(payload);
        setAppState("ready");
        setLoadState("ready");
      } catch (error) {
        if (!cancelled) {
          if (error instanceof SessionExpiredError) {
            setAppState("login");
            setLoadState("guest");
          } else {
            setApiError(error.message || "Dashboard API is unavailable.");
            setAppState("error");
            setLoadState("error");
          }
        }
      }
    }

    loadAuthenticatedDashboard();

    return () => {
      cancelled = true;
    };
  }, []);

  const alerts = useMemo(
    () =>
      dashboard.alerts.map((alert) => ({
        ...alert,
        unread: alert.unread && !readAlerts.has(alert.id),
      })),
    [dashboard.alerts, readAlerts],
  );

  const collections = useMemo(
    () => filterCollections(dashboard, query),
    [dashboard, query],
  );

  const unreadCount = getUnreadCount(alerts);
  const stats = { ...dashboard.stats, alertsUnread: unreadCount };
  const isEmptyLibrary =
    loadState === "ready" &&
    stats.episodesWatched === 0 &&
    stats.moviesWatched === 0 &&
    stats.showsFollowed === 0;

  async function loadDashboardAfterLogin() {
    const me = await apiRequest("/api/v1/me");
    const payload = await apiRequest("/api/v1/dashboard");

    setAuthUser(me.user);
    setDashboard(payload);
    setReadAlerts(new Set());
    setAppState("ready");
    setLoadState("ready");
  }

  async function refreshDashboard() {
    const payload = await apiRequest("/api/v1/dashboard");
    setDashboard(payload);
  }

  async function loadMediaDetail(item) {
    const path = mediaDetailPath(item);

    setSelectedDetail(null);
    setDetailError("");
    setDetailActionError("");

    if (!path) {
      setDetailLoading(false);
      return;
    }

    setDetailLoading(true);

    try {
      const payload = await apiRequest(path);
      setSelectedDetail(payload.item);
    } catch (error) {
      if (error instanceof SessionExpiredError) {
        expireSession();
        return;
      }
      setDetailError(error.message || "Could not load details.");
    } finally {
      setDetailLoading(false);
    }
  }

  async function refreshMediaDetail(detail) {
    const path = mediaDetailPath(detail);

    if (!path) {
      return;
    }

    const payload = await apiRequest(path);
    setSelectedDetail(payload.item);
  }

  async function handleLogin(credentials) {
    setAuthError("");
    setSubmittingLogin(true);

    try {
      await apiRequest("/api/v1/auth/login", {
        method: "POST",
        body: credentials,
      });
      await loadDashboardAfterLogin();
    } catch (error) {
      setAuthError(error.message || "Sign in failed.");
      setAppState("login");
    } finally {
      setSubmittingLogin(false);
    }
  }

  async function handleLogout() {
    try {
      await apiRequest("/api/v1/auth/logout", { method: "POST" });
    } catch (error) {
      if (!(error instanceof SessionExpiredError)) {
        setApiError(error.message || "Logout failed.");
      }
    }

    setAuthUser(null);
    setDashboard(fallbackData);
    setReadAlerts(new Set());
    setSelectedItem(null);
    setSelectedDetail(null);
    setAppState("login");
    setLoadState("guest");
  }

  function expireSession() {
    setAuthUser(null);
    setDashboard(fallbackData);
    setReadAlerts(new Set());
    setSelectedItem(null);
    setSelectedDetail(null);
    setAuthError("Session expired. Sign in again.");
    setAppState("login");
    setLoadState("guest");
  }

  async function openItem(item) {
    setSelectedItem(item);
    setSelectedDetail(null);
    setDetailError("");
    setDetailActionError("");

    if (item?.id && "category" in item) {
      setReadAlerts((current) => new Set([...current, item.id]));
      setDashboard((current) => ({
        ...current,
        alerts: current.alerts.map((alert) =>
          alert.id === item.id ? { ...alert, unread: false } : alert,
        ),
      }));

      try {
        await apiRequest(`/api/v1/alerts/${item.id}/read`, { method: "POST" });
      } catch (error) {
        if (error instanceof SessionExpiredError) {
          expireSession();
        }
      }

      return;
    }

    await loadMediaDetail(item);
  }

  async function runDetailAction(action) {
    setDetailActionPending(true);
    setDetailActionError("");

    try {
      await action();
    } catch (error) {
      if (error instanceof SessionExpiredError) {
        expireSession();
        return;
      }
      setDetailActionError(error.message || "Could not save change.");
    } finally {
      setDetailActionPending(false);
    }
  }

  async function handleSaveRating(detail, rating) {
    await runDetailAction(async () => {
      const path = mediaBasePath(detail);
      await apiRequest(`${path}/rating`, {
        method: "POST",
        body: { rating },
      });
      await refreshMediaDetail(detail);
      await refreshDashboard();
    });
  }

  async function handleClearRating(detail) {
    await runDetailAction(async () => {
      const path = mediaBasePath(detail);
      await apiRequest(`${path}/rating`, { method: "DELETE" });
      await refreshMediaDetail(detail);
      await refreshDashboard();
    });
  }

  async function handleSaveNote(detail, body, note) {
    if (!body.trim()) {
      setDetailActionError("Note cannot be empty.");
      return;
    }

    await runDetailAction(async () => {
      const path = note?.id ? `/api/v1/library/notes/${note.id}` : `${mediaBasePath(detail)}/notes`;
      await apiRequest(path, {
        method: note?.id ? "PATCH" : "POST",
        body: { body: body.trim() },
      });
      await refreshMediaDetail(detail);
      await refreshDashboard();
    });
  }

  async function handleDeleteNote(detail, note) {
    await runDetailAction(async () => {
      await apiRequest(`/api/v1/library/notes/${note.id}`, { method: "DELETE" });
      await refreshMediaDetail(detail);
      await refreshDashboard();
    });
  }

  async function handleMarkWatched(detail) {
    await runDetailAction(async () => {
      await apiRequest(`${mediaBasePath(detail)}/watch`, { method: "POST" });
      await refreshMediaDetail(detail);
      await refreshDashboard();
    });
  }

  async function handleMarkUnwatched(detail) {
    await runDetailAction(async () => {
      await apiRequest(`${mediaBasePath(detail)}/watch`, { method: "DELETE" });
      await refreshMediaDetail(detail);
      await refreshDashboard();
    });
  }

  async function markAllRead() {
    setReadAlerts(new Set(alerts.map((alert) => alert.id)));
    setDashboard((current) => ({
      ...current,
      alerts: current.alerts.map((alert) => ({ ...alert, unread: false })),
    }));

    try {
      await apiRequest("/api/v1/alerts/read-all", { method: "POST" });
    } catch (error) {
      if (error instanceof SessionExpiredError) {
        expireSession();
      }
    }
  }

  if (appState === "checking") {
    return <LoadingScreen />;
  }

  if (appState === "login") {
    return (
      <LoginScreen
        error={authError}
        onLogin={handleLogin}
        submitting={submittingLogin}
      />
    );
  }

  if (appState === "error") {
    return (
      <div className="login-shell">
        <div className="login-panel compact">
          <Logo />
          <div className="login-error">{apiError}</div>
          <button className="primary-action" onClick={() => window.location.reload()} type="button">
            Retry
          </button>
        </div>
      </div>
    );
  }

  return (
    <div className="app-shell">
      <Sidebar
        activeSection={activeSection}
        alertsCount={unreadCount}
        onSelect={setActiveSection}
      />
      <main className="dashboard-shell">
        <Topbar
          profile={{ ...dashboard.profile, name: dashboard.profile.name || authUser?.name }}
          query={query}
          onLogout={handleLogout}
          onQueryChange={setQuery}
        />
        {isEmptyLibrary ? (
          <div className="data-warning">Your library is empty.</div>
        ) : null}
        <div className="dashboard-grid">
          <div className="primary-column">
            <Hero item={dashboard.hero} onOpen={openItem} />
            {query.trim().length >= 2 && activeSection === "home" ? (
              <GlobalSearchPanel
                apiClient={apiRequest}
                onOpen={openItem}
                onSessionExpired={expireSession}
                query={query}
              />
            ) : null}
            {activeSection === "home" ? (
              <>
                <Shelf
                  title="Recently watched"
                  items={collections.recentlyWatched}
                  onOpen={openItem}
                />
                <Shelf
                  compact
                  title="Movies to check out"
                  items={collections.moviesToCheckOut}
                  onOpen={openItem}
                />
              </>
            ) : (
              <FocusSection
                activeSection={activeSection}
                alerts={alerts}
                apiClient={apiRequest}
                activity={dashboard.activity}
                collections={collections}
                globalQuery={query}
                onOpen={openItem}
                onPlayerRefresh={refreshDashboard}
                onSessionExpired={expireSession}
                player={dashboard.player}
                stats={stats}
              />
            )}
          </div>
          <aside className="insight-column">
            <TimelinePanel timeline={dashboard.timeline} />
            <AlertCenter
              activeTab={activeAlertTab}
              alerts={alerts}
              onMarkAllRead={markAllRead}
              onOpen={openItem}
              onTabChange={setActiveAlertTab}
            />
            <Shelf
              compact
              title="Followed shows with new episodes"
              items={collections.followedNewEpisodes.slice(0, 6)}
              onOpen={openItem}
            />
            <StatsStrip stats={stats} />
            <ActivityChart activity={dashboard.activity} />
          </aside>
        </div>
      </main>
      <DetailModal
        actionError={detailActionError}
        actionPending={detailActionPending}
        detail={selectedDetail}
        detailError={detailError}
        detailLoading={detailLoading}
        item={selectedItem}
        onClearRating={handleClearRating}
        onClose={() => {
          setSelectedItem(null);
          setSelectedDetail(null);
          setDetailError("");
          setDetailActionError("");
        }}
        onDeleteNote={handleDeleteNote}
        onMarkUnwatched={handleMarkUnwatched}
        onMarkWatched={handleMarkWatched}
        onOpenEpisode={openItem}
        onSaveNote={handleSaveNote}
        onSaveRating={handleSaveRating}
      />
    </div>
  );
}
