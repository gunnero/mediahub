import { useEffect, useMemo, useRef, useState } from "react";
import {
  Bell,
  CalendarDots,
  ChartBar,
  CheckCircle,
  Clock,
  Compass,
  FilmSlate,
  GearSix,
  House,
  ListBullets,
  MagnifyingGlass,
  Play,
  TelevisionSimple,
  X,
} from "@phosphor-icons/react";
import { getUnreadCount } from "./lib/dashboard.js";
import { apiRequest, SessionExpiredError } from "./lib/api.js";
import { PlayerSection, SettingsSection } from "./components/MediaHubSurfaces.jsx";
import { HomeExperience } from "./components/HomeExperience.jsx";
import {
  AlertsSection,
  CalendarSection,
  DiscoverSection,
  ListsSection,
  StatsSection,
  WebSettingsSection,
} from "./components/WebV1Surfaces.jsx";
import {
  AccountMenu,
  FriendInviteLandingPage,
  FriendsSection,
  InviteFriendsSection,
  OwnProfileSection,
  PublicProfilePage,
} from "./components/ProfileSurfaces.jsx";

export { PlayerSection, SettingsSection } from "./components/MediaHubSurfaces.jsx";

const generatedPosterPattern = /\/assets\/generated\/movie-poster-\d+\.png(?:[?#].*)?$/;

function resolvePublicRoute(pathname) {
  const profileMatch = pathname.match(/^\/u\/([^/]+)\/?$/);
  if (profileMatch) return { type: "profile", value: safeDecodeRouteValue(profileMatch[1]) };
  const inviteMatch = pathname.match(/^\/invite\/([^/]+)\/?$/);
  if (inviteMatch) return { type: "invite", value: safeDecodeRouteValue(inviteMatch[1]) };
  return null;
}

function safeDecodeRouteValue(value) {
  try {
    return decodeURIComponent(value);
  } catch {
    return value;
  }
}

const navItems = [
  { id: "home", label: "Home", icon: House },
  { id: "discover", label: "Discover", icon: Compass },
  { id: "movies", label: "Movies", icon: FilmSlate },
  { id: "shows", label: "Shows", icon: TelevisionSimple },
  { id: "history", label: "History", icon: Clock },
  { id: "calendar", label: "Calendar", icon: CalendarDots },
  { id: "alerts", label: "Alerts", icon: Bell },
  { id: "stats", label: "Stats", icon: ChartBar },
  { id: "lists", label: "Lists", icon: ListBullets },
  { id: "settings", label: "Settings", icon: GearSix },
  { id: "player", label: "Player", icon: Play, feature: "webPlayerEnabled" },
];

const fallbackData = {
  features: {
    webPlayerEnabled: false,
    webProvidersEnabled: false,
  },
  profile: { name: "gunner", avatar: "", image: "", cover: "" },
  stats: {
    episodesWatched: 0,
    moviesWatched: 0,
    hoursWatched: 0,
    showsFollowed: 0,
    alertsUnread: 0,
  },
  hero: {
    title: "Your library is ready",
    subtitle: "Your private entertainment memory",
    meta: "Import or add titles to begin",
    poster: "",
    backdrop: "",
    progress: 0,
    kind: "library",
    eyebrow: "MediaHub",
  },
  alerts: [],
  recentlyWatched: [],
  followedNewEpisodes: [],
  moviesToCheckOut: [],
  recentShow: null,
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
    player: "Automatic tracking",
    provider: "Connected source",
    system: "System",
  };

  if (!source) {
    return "MediaHub";
  }

  return labels[source] || source.replace(/[-_]/g, " ").replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function usableArtwork(value) {
  if (typeof value !== "string" || value.trim() === "") {
    return "";
  }

  const nextValue = value.trim();

  return generatedPosterPattern.test(nextValue) ? "" : nextValue;
}

function imageFor(item) {
  return usableArtwork(item?.poster) || usableArtwork(item?.backdrop);
}

function backdropFor(item) {
  return usableArtwork(item?.backdrop) || usableArtwork(item?.poster);
}

function posterInitials(title) {
  const words = String(title || "")
    .replace(/[^a-z0-9\s]/gi, " ")
    .trim()
    .split(/\s+/)
    .filter(Boolean);

  if (words.length >= 2) {
    return `${words[0][0]}${words[1][0]}`.toUpperCase();
  }

  if (words.length === 1) {
    return words[0].slice(0, 2).toUpperCase();
  }

  return "";
}

function PosterArtwork({ item, className = "" }) {
  const image = imageFor(item);
  const title = item?.title || "this title";

  if (image) {
    return <img className={className} src={image} alt="" />;
  }

  return (
    <span className={`neutral-poster ${className}`.trim()} role="img" aria-label={`No poster for ${title}`}>
      <span>{posterInitials(title) || "No poster"}</span>
    </span>
  );
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
        <strong>MediaHub</strong>
        <span>Entertainment Memory</span>
      </div>
    </div>
  );
}

export function Sidebar({ activeSection, alertsCount, features = {}, onSelect }) {
  return (
    <aside className="sidebar">
      <Logo />
      <nav className="main-nav" aria-label="Main navigation">
        {navItems.filter((item) => !item.feature || features[item.feature]).map((item) => {
          const Icon = item.icon;
          const active = activeSection === item.id;
          return (
            <button
              aria-label={item.label}
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
        <span>Private entertainment memory</span>
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

function Topbar({ onAccountAction, profile, query, onQueryChange, onLogout, searchInputRef, showSearch = true }) {
  return (
    <header className="topbar">
      {showSearch ? <label className="search-box">
        <MagnifyingGlass size={22} />
        <input
          ref={searchInputRef}
          value={query}
          onChange={(event) => onQueryChange(event.target.value)}
          placeholder="Search shows, movies, episodes..."
        />
      </label> : <div className="topbar-search-spacer" />}
      <div className="topbar-actions">
        <AccountMenu onLogout={onLogout} onNavigate={onAccountAction} profile={profile} />
      </div>
    </header>
  );
}

function Hero({ item, onOpen }) {
  const background = backdropFor(item);
  return (
    <section className="hero-panel">
      {background ? (
        <img className="hero-backdrop" src={background} alt="" />
      ) : (
        <div className="hero-backdrop hero-backdrop-neutral" />
      )}
      <div className="hero-shade" />
      <div className="hero-poster-wrap">
        <PosterArtwork item={item} className="hero-poster" />
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
            {item.primaryActionLabel || "Open memory"}
          </button>
          <button className="secondary-action" onClick={() => onOpen(item)} type="button">
            {item.secondaryActionLabel || "View details"}
          </button>
        </div>
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

function LibraryCard({ item, onOpen, showProviderStatus = false }) {
  const badges = [
    item.watched ? (item.kind === "show" ? `${item.watchedEpisodes || 0} episodes watched` : (item.watchedCount > 1 ? `Watched ${item.watchedCount} times` : "Watched")) : null,
    ratingLabel(item.rating),
    item.hasNote ? "Private note" : null,
    showProviderStatus && item.providerLinked ? "Linked source" : null,
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
        <PosterArtwork item={item} />
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
  initialSort = "latest_watched",
  initialStatus = "all",
  navigationKey = 0,
  onOpen,
  onSessionExpired,
  showProviderStatus = false,
}) {
  const [searchDraft, setSearchDraft] = useState(initialSearch);
  const [filters, setFilters] = useState({
    search: initialSearch,
    status: initialStatus,
    sort: initialSort,
    page: 1,
    per_page: 24,
  });
  const [payload, setPayload] = useState({ items: [], pagination: { page: 1, total: 0, hasMore: false } });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  useEffect(() => {
    setSearchDraft(initialSearch);
    setFilters((current) => ({
      ...current,
      search: initialSearch,
      status: initialStatus,
      sort: initialSort,
      page: 1,
    }));
  }, [initialSearch, initialSort, initialStatus, navigationKey]);

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
          { value: "newest_added", label: "Newest added" },
          { value: "title", label: "Title" },
          { value: "rating", label: "Rating" },
          { value: "year", label: "Year" },
        ]}
      />
      <LibraryState error={error} loading={loading}>
        {payload.items.length ? (
          <div className="library-grid">
            {payload.items.map((item) => (
              <LibraryCard item={item} key={item.id} onOpen={onOpen} showProviderStatus={showProviderStatus} />
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
  showProviderStatus = false,
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
              <LibraryCard item={item} key={item.id} onOpen={onOpen} showProviderStatus={showProviderStatus} />
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
  initialType = "all",
  navigationKey = 0,
  onOpen,
  onSessionExpired,
}) {
  const [searchDraft, setSearchDraft] = useState("");
  const [filters, setFilters] = useState({ type: initialType, search: "", page: 1, per_page: 30 });
  const [payload, setPayload] = useState({ items: [], pagination: { page: 1, total: 0, hasMore: false } });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  useEffect(() => {
    setSearchDraft("");
    setFilters({ type: initialType, search: "", page: 1, per_page: 30 });
  }, [initialType, navigationKey]);

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
                <PosterArtwork item={item} />
                <span>
                  <strong>{item.title}</strong>
                  <small>{item.subtitle}{item.showTitle ? ` · ${item.showTitle}` : ""}</small>
                </span>
                <em>{shortDate(item.watchedAt)}</em>
                <b>{item.watchCount > 1 ? `Watched ${item.watchCount} times` : sourceLabel(item.source)}</b>
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
  onLibraryChanged,
  onOpen,
  onSessionExpired,
  query = "",
}) {
  const [mode, setMode] = useState("discover");
  const [payload, setPayload] = useState({ movies: [], shows: [], episodes: [] });
  const [discovery, setDiscovery] = useState({ status: "ready", items: [], pagination: {} });
  const [preview, setPreview] = useState(null);
  const [adding, setAdding] = useState("");
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const safeQuery = query.trim();

  useEffect(() => {
    let cancelled = false;

    async function searchMedia() {
      if (safeQuery.length < 2) {
        setPayload({ movies: [], shows: [], episodes: [] });
        setDiscovery({ status: "ready", items: [], pagination: {} });
        setError("");
        return;
      }

      setLoading(true);
      setError("");

      try {
        const path = mode === "discover"
          ? `/api/v1/discover/search?${buildQueryString({ query: safeQuery, type: "all", page: 1 })}`
          : `/api/v1/library/search?${buildQueryString({ query: safeQuery })}`;
        const nextPayload = await apiClient(path);

        if (!cancelled) {
          if (mode === "discover") {
            setDiscovery(nextPayload);
          } else {
            setPayload(nextPayload);
          }
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

    searchMedia();

    return () => {
      cancelled = true;
    };
  }, [apiClient, mode, onSessionExpired, safeQuery]);

  if (safeQuery.length < 2) {
    return null;
  }

  const groups = mode === "discover"
    ? [
        { id: "movies", title: "Movies", items: (discovery.items || []).filter((item) => item.media_type === "movie") },
        { id: "shows", title: "Shows", items: (discovery.items || []).filter((item) => item.media_type === "show") },
      ]
    : [
        { id: "movies", title: "Movies", items: payload.movies || [] },
        { id: "shows", title: "Shows", items: payload.shows || [] },
        { id: "episodes", title: "Episodes", items: payload.episodes || [] },
      ];
  const totalResults = groups.reduce((total, group) => total + group.items.length, 0);

  async function addDiscovered(item, action) {
    const key = `${item.media_type}-${item.tmdb_id}-${action}`;
    setAdding(key);
    setError("");

    try {
      const response = await apiClient(`/api/v1/discover/${item.media_type === "show" ? "shows" : "movies"}/${item.tmdb_id}/add`, {
        method: "POST",
        body: { action },
      });
      const libraryItem = response?.item;
      const existingId = libraryItem?.movieId || libraryItem?.showId || libraryItem?.id;
      setDiscovery((current) => ({
        ...current,
        items: current.items.map((candidate) => (
          candidate.media_type === item.media_type && candidate.tmdb_id === item.tmdb_id
            ? { ...candidate, already_in_library: true, existing_library_id: existingId }
            : candidate
        )),
      }));
      setPreview((current) => current ? { ...current, already_in_library: true, existing_library_id: existingId } : current);
      await onLibraryChanged?.();
    } catch (addError) {
      if (addError instanceof SessionExpiredError) {
        onSessionExpired?.();
        return;
      }
      setError(addError.message || "Could not add this title.");
    } finally {
      setAdding("");
    }
  }

  function openExisting(item) {
    if (!item.existing_library_id) {
      return;
    }

    onOpen?.({
      id: item.existing_library_id,
      kind: item.media_type,
      title: item.title,
      movieId: item.media_type === "movie" ? item.existing_library_id : undefined,
      showId: item.media_type === "show" ? item.existing_library_id : undefined,
      poster: item.poster,
      backdrop: item.backdrop,
    });
  }

  return (
    <section className="global-search-panel">
      <div className="section-heading">
        <div>
          <span>{mode === "discover" ? "Discovery search" : "Canonical search"}</span>
          <h2>Results for “{safeQuery}”</h2>
        </div>
        <em>{loading ? "Searching..." : `${totalResults} matches`}</em>
      </div>
      <div className="search-mode-tabs" role="tablist" aria-label="Search mode">
        <button aria-selected={mode === "library"} className={mode === "library" ? "active" : ""} onClick={() => setMode("library")} role="tab" type="button">
          My Library
        </button>
        <button aria-selected={mode === "discover"} className={mode === "discover" ? "active" : ""} onClick={() => setMode("discover")} role="tab" type="button">
          Discover
        </button>
      </div>
      {error ? <div className="detail-error">{error}</div> : null}
      {mode === "discover" && discovery.status === "disabled" ? (
        <div className="empty-strip compact">Discovery is unavailable until TMDB is enabled.</div>
      ) : null}
      {groups.map((group) => (
        group.items.length ? (
          <div className="search-result-group" key={group.id}>
            <h3>{group.title}</h3>
            <div>
              {group.items.map((item) => (
                <button
                  aria-label={`Open ${item.title}`}
                  className="search-result-row"
                  key={`${group.id}-${item.id || item.tmdb_id}`}
                  onClick={() => mode === "discover" ? setPreview(item) : onOpen(item)}
                  type="button"
                >
                  <PosterArtwork item={item} />
                  <span>
                    <strong>{item.title}</strong>
                    <small>{item.meta || item.subtitle || [item.year, item.media_type].filter(Boolean).join(" · ")}</small>
                  </span>
                  {mode === "discover" ? (
                    <b>{item.watched ? (item.watched_count > 1 ? `Watched ${item.watched_count} times` : "Watched") : item.already_in_library ? "In Library" : "Preview"}</b>
                  ) : null}
                </button>
              ))}
            </div>
          </div>
        ) : null
      ))}
      {!loading && !error && totalResults === 0 ? (
        <div className="empty-strip compact">{mode === "discover" ? "No discovery matches" : "No canonical matches yet"}</div>
      ) : null}
      {preview ? (
        <div className="discovery-preview" role="dialog" aria-modal="true" aria-label={`${preview.title} discovery preview`}>
          <button className="modal-close" onClick={() => setPreview(null)} type="button" aria-label="Close discovery preview">
            <X size={18} />
          </button>
          <div className="discovery-preview-art">
            <PosterArtwork item={preview} />
          </div>
          <div>
            <span className="eyebrow">{preview.media_type}</span>
            <h3>{preview.title}</h3>
            <p>{preview.overview || "No overview is available yet."}</p>
            {preview.watched ? <p className="discovery-memory-state"><CheckCircle size={17} weight="fill" /> You watched this{preview.watched_count > 1 ? ` ${preview.watched_count} times` : ""}.</p> : null}
            <div className="metadata-strip">
              {[preview.year, ...(preview.genres || [])].filter(Boolean).map((tag) => <span key={tag}>{tag}</span>)}
            </div>
            <div className="modal-actions">
              {preview.already_in_library ? (
                <button className="primary-action" onClick={() => openExisting(preview)} type="button">Open in My Library</button>
              ) : (
                <>
                  <button className="primary-action" disabled={Boolean(adding)} onClick={() => addDiscovered(preview, "library")} type="button">Add to Library</button>
                  <button className="secondary-action" disabled={Boolean(adding)} onClick={() => addDiscovered(preview, "watchlist")} type="button">Add to Watchlist</button>
                  {preview.media_type === "movie" ? (
                    <button className="text-action" disabled={Boolean(adding)} onClick={() => addDiscovered(preview, "watched")} type="button">Mark watched</button>
                  ) : null}
                </>
              )}
            </div>
            {!preview.already_in_library ? <p className="discovery-action-help"><strong>Library</strong> saves the title to your permanent collection. <strong>Watchlist</strong> saves it and marks it as something you plan to watch.</p> : null}
          </div>
        </div>
      ) : null}
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
  playback = null,
  playerEnabled = false,
  onClose,
  onSaveRating,
  onClearRating,
  onSaveNote,
  onDeleteNote,
  onMarkWatched,
  onMarkUnwatched,
  onToggleWatchlist,
  onPlay,
  onOpenEpisode,
  onMarkSeasonWatched,
  onMarkSeasonUnwatched,
}) {
  const [activeTab, setActiveTab] = useState("overview");
  const [selectedSeason, setSelectedSeason] = useState(null);
  const [noteBody, setNoteBody] = useState("");
  const closeButtonRef = useRef(null);

  useEffect(() => {
    setActiveTab("overview");
    setSelectedSeason(detail?.seasons?.[0]?.seasonNumber ?? null);
    setNoteBody(detail?.notes?.[0]?.body || "");
  }, [detail?.id, detail?.kind, detail?.notes, detail?.seasons]);

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
    return () => document.removeEventListener("keydown", handleKeyDown);
  }, [onClose]);

  if (!item) {
    return null;
  }

  const isAlert = "category" in item;
  const view = detail || item;
  const primaryNote = detail?.notes?.[0] || null;
  const rating = detail?.rating?.rating || null;
  const metadata = detail?.metadata || view?.metadata || {};
  const hasManualWatch = (detail?.watchHistory || []).some((watch) => watch.source === "manual");
  const canManualWatch = detail?.kind === "movie" || detail?.kind === "episode";
  const detailTimeline = detail?.timeline || [];
  const selectedSeasonData = detail?.seasons?.find((season) => season.seasonNumber === selectedSeason) || detail?.seasons?.[0] || null;
  const tabs = detail?.kind === "show"
    ? [
        ["overview", "Overview"],
        ["episodes", "Episodes"],
        ["activity", "Activity"],
        ["notes", "Notes & Rating"],
        ...(playerEnabled ? [["provider", "Provider"]] : []),
      ]
    : [
        ["overview", "Overview"],
        ["activity", "Your Activity"],
        ["notes", "Notes & Rating"],
        ["history", "Watch History"],
        ...(playerEnabled ? [["provider", "Provider / Playback"]] : []),
      ];
  const publicTags = [
    metadata.releaseYear,
    metadata.runtime ? `${metadata.runtime} min` : null,
    ...(metadata.genres || []),
  ].filter(Boolean);
  const cast = detail?.people?.cast || [];
  const directors = detail?.people?.directors || [];
  const productionFacts = [
    ...(detail?.production?.companies || []),
    ...(detail?.production?.countries || []),
    ...(detail?.production?.languages || []),
  ];

  function submitNote(event) {
    event.preventDefault();
    onSaveNote?.(detail, noteBody, primaryNote);
  }

  if (isAlert) {
    return (
      <div className="modal-layer" role="presentation" onMouseDown={onClose}>
        <section className="detail-modal alert-detail-modal" role="dialog" aria-modal="true" aria-label={`${item.title} details`} onMouseDown={(event) => event.stopPropagation()}>
          <button ref={closeButtonRef} className="modal-close" onClick={onClose} type="button" aria-label="Close"><X size={20} /></button>
          <div className="modal-alert-art"><Bell size={48} weight="duotone" /></div>
          <div className="modal-copy"><span className="eyebrow">{item.category}</span><h2>{item.title}</h2><p>{item.subtitle}</p><strong>{item.dueText}</strong></div>
        </section>
      </div>
    );
  }

  return (
    <div className="modal-layer cinematic-layer" role="presentation" onMouseDown={onClose}>
      <section className="cinematic-detail" role="dialog" aria-modal="true" aria-label={`${view.title} details`} onMouseDown={(event) => event.stopPropagation()}>
        <button ref={closeButtonRef} className="modal-close cinematic-close" onClick={onClose} type="button" aria-label="Close"><X size={20} /></button>
        <header className="cinematic-header">
          {backdropFor(view) ? <img className="cinematic-backdrop" src={backdropFor(view)} alt="" /> : <div className="cinematic-backdrop neutral" />}
          <div className="cinematic-shade" />
          <div className="cinematic-poster"><PosterArtwork item={view} /></div>
          <div className="cinematic-title">
            <span className="eyebrow">{view.kind || "media"}</span>
            <h2>{view.title}</h2>
            {detail?.tagline ? <blockquote>{detail.tagline}</blockquote> : null}
            <div className="cinematic-meta">{publicTags.map((tag) => <span key={tag}>{tag}</span>)}</div>
            <p>{view.overview || "This title is part of your permanent MediaHub library."}</p>
            <div className="cinematic-actions">
              {playerEnabled && detail?.provider?.playableItemId ? (
                <button className="primary-action" onClick={() => onPlay?.(detail)} type="button"><Play size={18} weight="fill" /> Play</button>
              ) : null}
              {canManualWatch ? (
                <button className="secondary-action" disabled={actionPending} onClick={() => onMarkWatched?.(detail)} type="button">
                  <CheckCircle size={18} weight="fill" /> {detail.watched ? "Mark watched again" : "Mark watched"}
                </button>
              ) : null}
              {canManualWatch && hasManualWatch ? (
                <button className="text-action danger" disabled={actionPending} onClick={() => onMarkUnwatched?.(detail)} type="button">
                  Remove latest manual watch
                </button>
              ) : null}
              {detail?.kind === "movie" || detail?.kind === "show" ? (
                <button className="text-action" disabled={actionPending} onClick={() => onToggleWatchlist?.(detail, !detail.watchlist)} type="button">
                  {detail.watchlist ? "Remove Watchlist" : "Add to Watchlist"}
                </button>
              ) : null}
            </div>
          </div>
        </header>

        {detailLoading ? <div className="detail-state">Loading details...</div> : null}
        {detailError ? <div className="detail-error">{detailError}</div> : null}
        {actionError ? <div className="detail-error">{actionError}</div> : null}

        {detail ? (
          <div className="cinematic-body">
            <nav className="detail-tabs" aria-label="Detail sections" role="tablist">
              {tabs.map(([id, label]) => (
                <button aria-selected={activeTab === id} className={activeTab === id ? "active" : ""} key={id} onClick={() => setActiveTab(id)} role="tab" type="button">{label}</button>
              ))}
            </nav>

            <div className="detail-tab-panel" role="tabpanel">
              {activeTab === "overview" ? (
                <div className="overview-layout">
                  <section className="detail-section overview-copy">
                    <span className="eyebrow">Story</span>
                    <h3>{detail.title}</h3>
                    <p>{detail.overview || "No overview is available yet."}</p>
                  </section>
                  <section className="detail-facts">
                    <div><span>Watched</span><strong>{detail.kind === "show" ? (detail.watched ? `${detail.watchedEpisodes || 0} episodes` : "Not started") : (detail.watched ? `${detail.watchedCount || 1} ${(detail.watchedCount || 1) === 1 ? "time" : "times"}` : "Not yet")}</strong></div>
                    <div><span>Your rating</span><strong>{rating ? `${rating}/10` : "Not rated"}</strong></div>
                    {playerEnabled ? <div><span>Provider</span><strong>{detail.provider?.linked ? "Linked" : "Manual only"}</strong></div> : null}
                    {detail.kind === "show" ? <div><span>Progress</span><strong>{detail.meta}</strong></div> : null}
                  </section>
                  {detail.kind === "show" && detail.showState ? (
                    <section className={`show-state-card ${detail.showState.code}`}>
                      <span className="eyebrow">Series status</span>
                      <h3>{detail.showState.title}</h3>
                      <p>{detail.showState.description}</p>
                    </section>
                  ) : null}
                  {cast.length || directors.length ? (
                    <section className="detail-section people-section">
                      <div className="detail-section-heading"><strong>Cast & creators</strong><span>{cast.length + directors.length} people</span></div>
                      <div className="people-grid">
                        {[...directors, ...cast].map((person, index) => <article key={`${person.id || person.name}-${index}`}>
                          {person.image ? <img alt="" loading="lazy" src={person.image} /> : <span className="person-placeholder">{posterInitials(person.name)}</span>}
                          <span><strong>{person.name}</strong><small>{person.role || "Cast"}</small></span>
                        </article>)}
                      </div>
                    </section>
                  ) : null}
                  {productionFacts.length ? (
                    <section className="detail-section production-section">
                      <div className="detail-section-heading"><strong>Production</strong></div>
                      <div className="metadata-strip">{productionFacts.map((fact) => <span key={fact}>{fact}</span>)}</div>
                    </section>
                  ) : null}
                  {detail.kind === "show" && detail.nextUnwatchedEpisode ? (
                    <button className="next-episode-card" onClick={() => onOpenEpisode?.({ ...detail.nextUnwatchedEpisode, kind: "episode", subtitle: detail.nextUnwatchedEpisode.code, meta: `${detail.title} · ${detail.nextUnwatchedEpisode.code}` })} type="button">
                      <Play size={20} weight="fill" /><span><small>Continue next</small><strong>{detail.nextUnwatchedEpisode.title}</strong><em>{detail.nextUnwatchedEpisode.code}</em></span>
                    </button>
                  ) : null}
                  {detail.kind === "show" && detail.latestEpisode ? (
                    <button className="next-episode-card latest-episode-card" onClick={() => onOpenEpisode?.({ ...detail.latestEpisode, kind: "episode", subtitle: detail.latestEpisode.code, meta: `${detail.title} · ${detail.latestEpisode.code}` })} type="button">
                      <Clock size={20} /><span><small>Jump to latest watched</small><strong>{detail.latestEpisode.title}</strong><em>{detail.latestEpisode.code}{detail.latestEpisode.watchedAt ? ` · ${shortDate(detail.latestEpisode.watchedAt)}` : ""}</em></span>
                    </button>
                  ) : null}
                </div>
              ) : null}

              {activeTab === "episodes" && detail.kind === "show" ? (
                <section className="episode-browser-panel">
                  <div className="season-controls">
                    <label><span>Season</span><select aria-label="Season" onChange={(event) => setSelectedSeason(Number(event.target.value))} value={selectedSeason ?? ""}>
                      {(detail.seasons || []).map((season) => <option key={season.seasonNumber} value={season.seasonNumber}>{season.seasonNumber === 0 ? "Specials" : `Season ${season.seasonNumber}`}</option>)}
                    </select></label>
                    {selectedSeasonData ? <span>{selectedSeasonData.watchedEpisodes}/{selectedSeasonData.totalEpisodes || selectedSeasonData.episodesCount} watched</span> : null}
                    <div>
                      <button className="text-action" onClick={() => onMarkSeasonWatched?.(detail, selectedSeason)} type="button">Mark season watched</button>
                      <button className="text-action danger" onClick={() => onMarkSeasonUnwatched?.(detail, selectedSeason)} type="button">Mark season unwatched</button>
                    </div>
                  </div>
                  {selectedSeasonData ? (
                    <div className="cinematic-episode-list">
                      {selectedSeasonData.episodes.map((episode) => (
                        <button aria-label={`Open ${episode.title}`} className={`cinematic-episode ${episode.watched ? "watched" : ""}`} key={episode.id} onClick={() => onOpenEpisode?.({ episodeId: episode.episodeId || episode.id, showId: detail.showId, kind: "episode", title: episode.title, subtitle: episode.code, meta: `${detail.title} - ${episode.code}` })} type="button">
                          <span className="episode-still"><PosterArtwork item={episode} /></span>
                          <span><small>{episode.code}{episode.airDate ? ` · ${shortDate(episode.airDate)}` : ""}</small><strong>{episode.title}</strong><em>{[episode.runtime ? `${episode.runtime} min` : "", episode.watchedAt ? `Watched ${shortDate(episode.watchedAt)}` : "", episode.rating ? `Rated ${episode.rating}/10` : "", episode.hasNote ? "Private note" : ""].filter(Boolean).join(" · ")}</em></span>
                          <b>{episode.watched ? "Watched" : playerEnabled && episode.playableItemId ? "Play" : "Not watched"}</b>
                        </button>
                      ))}
                    </div>
                  ) : <div className="empty-strip compact">No seasons available</div>}
                </section>
              ) : null}

              {activeTab === "activity" ? (
                <section className="detail-section">
                  <div className="detail-section-heading"><strong>Entertainment diary</strong><span>{detailTimeline.length} moments</span></div>
                  <div className="detail-timeline">
                    {detailTimeline.length ? detailTimeline.map((event) => <div key={event.id}><span><strong>{event.title}</strong><small>{event.subtitle || sourceLabel(event.source)}</small></span><em>{shortDate(event.occurredAt)}</em></div>) : <em>Meaningful moments for this title will appear here.</em>}
                  </div>
                </section>
              ) : null}

              {activeTab === "notes" ? (
                <div className="notes-rating-grid">
                  <section className="detail-section">
                    <div className="detail-section-heading"><strong>Your rating</strong><span>{rating ? `${rating}/10` : "Not rated"}</span></div>
                    <div className="rating-control" aria-label="Your rating">{Array.from({ length: 10 }, (_, index) => index + 1).map((value) => <button aria-pressed={rating === value} className={rating === value ? "active" : ""} disabled={actionPending} key={value} onClick={() => onSaveRating?.(detail, value)} type="button">{value}</button>)}</div>
                    <button className="text-action" disabled={actionPending || !rating} onClick={() => onClearRating?.(detail)} type="button">Clear rating</button>
                  </section>
                  <section className="detail-section">
                    <div className="detail-section-heading"><strong>Private memory</strong><span>{primaryNote ? "Saved" : "Only you can see this"}</span></div>
                    <form className="note-form compact-note-form" onSubmit={submitNote}><label><span>Note</span><textarea aria-label="Private note" disabled={actionPending} onChange={(event) => setNoteBody(event.target.value)} value={noteBody} /></label><div className="modal-actions"><button className="secondary-action" disabled={actionPending || !noteBody.trim()} type="submit">Save note</button>{primaryNote ? <button className="text-action danger" disabled={actionPending} onClick={() => onDeleteNote?.(detail, primaryNote)} type="button">Delete note</button> : null}</div></form>
                  </section>
                </div>
              ) : null}

              {activeTab === "history" ? (
                <section className="detail-section"><div className="detail-section-heading"><strong>Watch history</strong><span>{detail.watchedCount || detail.watchHistory?.length || 0} watches</span></div><div className="watch-history compact-history">{detail.watchHistory?.length ? detail.watchHistory.map((watch) => <div key={watch.id}><span><b>{watch.watchNumber ? `Watch #${watch.watchNumber}` : "Watch"}</b>{shortDate(watch.watchedAt) || "Unknown date"}</span><strong>{sourceLabel(watch.source)}</strong></div>) : <em>No watch history yet</em>}</div></section>
              ) : null}

              {playerEnabled && activeTab === "provider" ? (
                <section className="provider-detail-panel"><div><span className="eyebrow">Private playback</span><h3>{detail.provider?.linked ? "Ready from your source" : "No linked source"}</h3><p>{detail.provider?.linked ? `${detail.provider.linkedItemsCount} private source item${detail.provider.linkedItemsCount === 1 ? "" : "s"} linked. Playback history remains permanent even if the provider changes.` : "You can keep tracking manually or link an item from a provider you own."}</p></div>{detail.provider?.playableItemId ? <button className="primary-action" onClick={() => onPlay?.(detail)} type="button"><Play size={18} weight="fill" /> Play from private source</button> : null}{playback ? <video className="provider-video cinematic-video" controls src={playback.playbackUrl} /> : null}</section>
              ) : null}

              <details className="metadata-details"><summary>Metadata</summary><dl><div><dt>Source</dt><dd>{metadata.metadataStatus || "local"}</dd></div>{metadata.tmdbId ? <div><dt>TMDB</dt><dd>{metadata.tmdbId}</dd></div> : null}{metadata.imdbId ? <div><dt>IMDb</dt><dd>{metadata.imdbId}</dd></div> : null}{metadata.tvdbId ? <div><dt>TVDB</dt><dd>{metadata.tvdbId}</dd></div> : null}</dl></details>
            </div>
          </div>
        ) : null}
      </section>
    </div>
  );
}

function playerTargetPayload(target, options = {}) {
  if (!target) {
    return {};
  }

  return {
    [`${target.type}_id`]: target.id,
    confirm: true,
    ...(options.aiSuggestion ? { ai_suggestion: true } : {}),
  };
}

function aiCandidateToTarget(candidate) {
  if (!candidate?.type || !candidate?.id) {
    return null;
  }

  return {
    type: candidate.type,
    id: candidate.id,
    title: candidate.title || candidate.showTitle || "Suggested item",
    subtitle: candidate.type === "episode"
      ? candidate.showTitle || "Episode"
      : candidate.type === "show" ? "Show" : "Movie",
    meta: candidate.type === "episode"
      ? `S${candidate.seasonNumber || "?"} E${candidate.episodeNumber || "?"}`
      : candidate.year || "",
  };
}

function FocusSection({
  activeSection,
  apiClient,
  discoverIntent,
  features,
  globalQuery,
  onOpen,
  onAccountAction,
  onPlayerRefresh,
  onSelectSection,
  onSessionExpired,
  player,
  profileMode,
  historyIntent,
  movieIntent,
  settingsInitialSection,
}) {
  if (activeSection === "profile") {
    return <OwnProfileSection apiClient={apiClient} editInitially={profileMode === "edit"} onOpenPrivacy={() => onAccountAction("privacy")} onSessionExpired={onSessionExpired} />;
  }

  if (activeSection === "friends") {
    return <FriendsSection apiClient={apiClient} onSessionExpired={onSessionExpired} />;
  }

  if (activeSection === "invite-friends") {
    return <InviteFriendsSection apiClient={apiClient} onSessionExpired={onSessionExpired} />;
  }

  if (activeSection === "discover") {
    return <DiscoverSection apiClient={apiClient} initialType={discoverIntent.type} navigationKey={discoverIntent.key} onLibraryChanged={onPlayerRefresh} onOpen={onOpen} onSessionExpired={onSessionExpired} />;
  }

  if (activeSection === "shows") {
    return (
      <ShowLibrary
        apiClient={apiClient}
        initialSearch={globalQuery}
        onOpen={onOpen}
        onSessionExpired={onSessionExpired}
        showProviderStatus={Boolean(features?.webPlayerEnabled)}
      />
    );
  }

  if (activeSection === "movies") {
    return (
      <MovieLibrary
        apiClient={apiClient}
        initialSearch={globalQuery}
        initialSort={movieIntent.sort}
        initialStatus={movieIntent.status}
        navigationKey={movieIntent.key}
        onOpen={onOpen}
        onSessionExpired={onSessionExpired}
        showProviderStatus={Boolean(features?.webPlayerEnabled)}
      />
    );
  }

  if (activeSection === "history") {
    return (
      <HistorySection
        apiClient={apiClient}
        initialType={historyIntent.type}
        navigationKey={historyIntent.key}
        onOpen={onOpen}
        onSessionExpired={onSessionExpired}
      />
    );
  }

  if (activeSection === "calendar") {
    return <CalendarSection apiClient={apiClient} onOpen={onOpen} onSessionExpired={onSessionExpired} />;
  }

  if (activeSection === "alerts") {
    return <AlertsSection apiClient={apiClient} onOpen={onOpen} onSessionExpired={onSessionExpired} />;
  }

  if (activeSection === "player" && features?.webPlayerEnabled) {
    return (
      <PlayerSection
        apiClient={apiClient}
        onOpenSettings={() => onSelectSection("settings")}
        onRefreshDashboard={onPlayerRefresh}
        onSessionExpired={onSessionExpired}
        player={player}
      />
    );
  }

  if (activeSection === "stats") {
    return <StatsSection apiClient={apiClient} onSessionExpired={onSessionExpired} />;
  }

  if (activeSection === "lists") {
    return <ListsSection apiClient={apiClient} onOpen={onOpen} onSessionExpired={onSessionExpired} />;
  }

  if (activeSection === "settings") {
    return features?.webProvidersEnabled ? (
      <SettingsSection
        apiClient={apiClient}
        initialSection={settingsInitialSection}
        onOpenPlayer={() => onSelectSection("player")}
        onSessionExpired={onSessionExpired}
        providersEnabled={Boolean(features?.webProvidersEnabled)}
      />
    ) : <WebSettingsSection apiClient={apiClient} initialSection={settingsInitialSection} onSessionExpired={onSessionExpired} />;
  }

  return null;
}

export function App() {
  const publicRoute = resolvePublicRoute(window.location.pathname);
  const pendingFriendInvite = new URLSearchParams(window.location.search).get("friend-invite");
  const [dashboard, setDashboard] = useState(fallbackData);
  const [authUser, setAuthUser] = useState(null);
  const [appState, setAppState] = useState("checking");
  const [query, setQuery] = useState("");
  const [activeSection, setActiveSection] = useState("home");
  const [selectedItem, setSelectedItem] = useState(null);
  const [selectedDetail, setSelectedDetail] = useState(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [detailError, setDetailError] = useState("");
  const [detailActionError, setDetailActionError] = useState("");
  const [detailActionPending, setDetailActionPending] = useState(false);
  const [detailPlayback, setDetailPlayback] = useState(null);
  const [readAlerts, setReadAlerts] = useState(() => new Set());
  const [loadState, setLoadState] = useState("loading");
  const [authError, setAuthError] = useState("");
  const [apiError, setApiError] = useState("");
  const [submittingLogin, setSubmittingLogin] = useState(false);
  const [profileMode, setProfileMode] = useState("view");
  const [settingsInitialSection, setSettingsInitialSection] = useState("profile");
  const [historyIntent, setHistoryIntent] = useState({ type: "all", key: 0 });
  const [movieIntent, setMovieIntent] = useState({ status: "all", sort: "latest_watched", key: 0 });
  const [discoverIntent, setDiscoverIntent] = useState({ type: "all", key: 0 });
  const searchInputRef = useRef(null);

  useEffect(() => {
    if (publicRoute) return undefined;
    let cancelled = false;

    async function loadAuthenticatedDashboard() {
      try {
        const session = await apiRequest("/api/v1/auth/session");
        if (!session.authenticated) {
          if (!cancelled) {
            setAuthUser(null);
            setAppState("login");
            setLoadState("guest");
          }
          return;
        }
        const payload = await apiRequest("/api/v1/dashboard");

        if (cancelled) {
          return;
        }

        if (pendingFriendInvite) {
          window.location.replace(`/invite/${encodeURIComponent(pendingFriendInvite)}`);
          return;
        }

        setAuthUser(session.user);
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
  }, [pendingFriendInvite, publicRoute?.type, publicRoute?.value]);

  const alerts = useMemo(
    () =>
      dashboard.alerts.map((alert) => ({
        ...alert,
        unread: alert.unread && !readAlerts.has(alert.id),
      })),
    [dashboard.alerts, readAlerts],
  );

  const unreadCount = getUnreadCount(alerts);
  const stats = { ...dashboard.stats, alertsUnread: unreadCount };
  const isEmptyLibrary =
    loadState === "ready" &&
    stats.episodesWatched === 0 &&
    stats.moviesWatched === 0 &&
    stats.showsFollowed === 0;

  async function loadDashboardAfterLogin() {
    const session = await apiRequest("/api/v1/auth/session");
    if (!session.authenticated) throw new SessionExpiredError();
    const payload = await apiRequest("/api/v1/dashboard");

    setAuthUser(session.user);
    setDashboard(payload);
    setReadAlerts(new Set());
    setAppState("ready");
    setLoadState("ready");
    if (pendingFriendInvite) window.location.assign(`/invite/${encodeURIComponent(pendingFriendInvite)}`);
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
    setDetailPlayback(null);

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

  function handleAccountAction(action) {
    if (action === "view-profile" || action === "edit-profile") {
      setProfileMode(action === "edit-profile" ? "edit" : "view");
      setActiveSection("profile");
      return;
    }
    if (action === "privacy") {
      setSettingsInitialSection("privacy");
      setActiveSection("settings");
      return;
    }
    if (action === "settings") {
      setSettingsInitialSection("profile");
      setActiveSection("settings");
      return;
    }
    if (["friends", "invite-friends"].includes(action)) setActiveSection(action);
  }

  function selectSection(section) {
    if (section === "settings") setSettingsInitialSection("profile");
    if (section === "discover") {
      setDiscoverIntent((current) => ({ type: "all", key: current.key + 1 }));
    }
    if (section === "movies") {
      setQuery("");
      setMovieIntent((current) => ({ status: "all", sort: "latest_watched", key: current.key + 1 }));
    }
    if (section === "history") {
      setHistoryIntent((current) => ({ type: "all", key: current.key + 1 }));
    }
    setActiveSection(section);
  }

  function handleHomeNavigation(action) {
    if (action === "search") {
      searchInputRef.current?.focus();
      return;
    }
    if (action === "add-movie" || action === "add-show") {
      setDiscoverIntent((current) => ({ type: action === "add-movie" ? "movie" : "show", key: current.key + 1 }));
      setActiveSection("discover");
      return;
    }
    if (action === "import" || action === "export") {
      setSettingsInitialSection("import-export");
      setActiveSection("settings");
      return;
    }
    if (action === "profile") {
      setProfileMode("edit");
      setActiveSection("profile");
      return;
    }
    selectSection(action);
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

  async function handleToggleWatchlist(detail, enabled) {
    await runDetailAction(async () => {
      await apiRequest(`${mediaBasePath(detail)}/watchlist`, { method: enabled ? "POST" : "DELETE" });
      await refreshMediaDetail(detail);
      await refreshDashboard();
    });
  }

  async function handlePlayDetail(detail) {
    if (!detail?.provider?.playableItemId) return;

    await runDetailAction(async () => {
      const payload = await apiRequest(`/api/v1/player/items/${detail.provider.playableItemId}/play`, { method: "POST" });
      setDetailPlayback(payload);
    });
  }

  async function handleMarkSeason(detail, season, watched) {
    if (!detail?.showId || season === null || season === undefined) return;

    await runDetailAction(async () => {
      await apiRequest(`/api/v1/library/shows/${detail.showId}/seasons/${season}/watch`, { method: watched ? "POST" : "DELETE" });
      await refreshMediaDetail(detail);
      await refreshDashboard();
    });
  }

  if (publicRoute?.type === "profile") {
    return <PublicProfilePage preview={new URLSearchParams(window.location.search).get("preview") === "public"} slug={publicRoute.value} />;
  }

  if (publicRoute?.type === "invite") {
    return <FriendInviteLandingPage token={publicRoute.value} />;
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

  const socialSection = ["profile", "friends", "invite-friends"].includes(activeSection);
  const settingsSection = activeSection === "settings";
  const homeSection = activeSection === "home";
  const singleColumnSection = !homeSection;

  return (
    <div className="app-shell">
      <Sidebar
        activeSection={activeSection}
        alertsCount={unreadCount}
        features={dashboard.features}
        onSelect={selectSection}
      />
      <main className="dashboard-shell">
        <Topbar
          onAccountAction={handleAccountAction}
          profile={{ ...dashboard.profile, name: dashboard.profile.name || authUser?.name }}
          query={query}
          onLogout={handleLogout}
          onQueryChange={setQuery}
          searchInputRef={searchInputRef}
          showSearch={activeSection !== "movies"}
        />
        {isEmptyLibrary ? (
          <div className="data-warning">Your library is empty.</div>
        ) : null}
        <div className={`dashboard-grid${singleColumnSection ? " content-dashboard-grid" : ""}${homeSection ? " home-dashboard-grid" : ""}${socialSection ? " social-dashboard-grid" : ""}${settingsSection ? " settings-dashboard-grid" : ""}`}>
          <div className="primary-column">
            {activeSection === "shows" && dashboard.recentShow ? <Hero item={dashboard.recentShow} onOpen={openItem} /> : null}
            {query.trim().length >= 2 && activeSection === "home" ? (
              <GlobalSearchPanel
                apiClient={apiRequest}
                onLibraryChanged={refreshDashboard}
                onOpen={openItem}
                onSessionExpired={expireSession}
                query={query}
              />
            ) : null}
            {activeSection === "home" ? (
              <HomeExperience
                apiClient={apiRequest}
                dashboard={dashboard}
                onNavigate={handleHomeNavigation}
                onOpen={openItem}
                onRefreshDashboard={refreshDashboard}
                onSessionExpired={expireSession}
              />
            ) : (
              <FocusSection
                activeSection={activeSection}
                apiClient={apiRequest}
                discoverIntent={discoverIntent}
                features={dashboard.features}
                globalQuery={query}
                onAccountAction={handleAccountAction}
                onOpen={openItem}
                onPlayerRefresh={refreshDashboard}
                onSelectSection={selectSection}
                onSessionExpired={expireSession}
                player={dashboard.player}
                profileMode={profileMode}
                historyIntent={historyIntent}
                movieIntent={movieIntent}
                settingsInitialSection={settingsInitialSection}
              />
            )}
          </div>
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
          setDetailPlayback(null);
        }}
        onDeleteNote={handleDeleteNote}
        onMarkUnwatched={handleMarkUnwatched}
        onMarkWatched={handleMarkWatched}
        onMarkSeasonUnwatched={(detail, season) => handleMarkSeason(detail, season, false)}
        onMarkSeasonWatched={(detail, season) => handleMarkSeason(detail, season, true)}
        onOpenEpisode={openItem}
        onPlay={handlePlayDetail}
        onSaveNote={handleSaveNote}
        onSaveRating={handleSaveRating}
        onToggleWatchlist={handleToggleWatchlist}
        playback={detailPlayback}
        playerEnabled={Boolean(dashboard.features?.webPlayerEnabled)}
      />
    </div>
  );
}
