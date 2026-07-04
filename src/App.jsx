import { useEffect, useMemo, useState } from "react";
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
  ListBullets,
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
  { id: "player", label: "Player", icon: Play },
  { id: "alerts", label: "Alerts", icon: Bell },
  { id: "stats", label: "Stats", icon: ChartBar },
  { id: "lists", label: "Lists", icon: ListBullets },
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

function imageFor(item) {
  return item?.poster || item?.backdrop || fallbackPoster;
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
            Open details
          </button>
          <button className="secondary-action" onClick={() => onOpen(item)} type="button">
            Details
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

function DetailModal({ item, onClose }) {
  if (!item) {
    return null;
  }

  const isAlert = "category" in item;
  return (
    <div className="modal-layer" role="presentation" onMouseDown={onClose}>
      <section
        className="detail-modal"
        role="dialog"
        aria-modal="true"
        aria-label={`${item.title} details`}
        onMouseDown={(event) => event.stopPropagation()}
      >
        <button className="modal-close" onClick={onClose} type="button" aria-label="Close">
          <X size={20} />
        </button>
        {!isAlert ? (
          <img className="modal-art" src={imageFor(item)} alt="" />
        ) : (
          <div className="modal-alert-art">
            <Bell size={48} weight="duotone" />
          </div>
        )}
        <div className="modal-copy">
          <span className="eyebrow">{isAlert ? item.category : item.kind}</span>
          <h2>{item.title}</h2>
          <p>{isAlert ? item.subtitle : item.meta}</p>
          {!isAlert ? (
            <dl>
              <div>
                <dt>Status</dt>
                <dd>{item.badge || "saved"}</dd>
              </div>
              <div>
                <dt>Progress</dt>
                <dd>{item.progress || 0}%</dd>
              </div>
              <div>
                <dt>Watched</dt>
                <dd>{shortDate(item.watchedAt) || "From archive"}</dd>
              </div>
            </dl>
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

function PlayerSection({ player }) {
  const safePlayer = player || fallbackData.player;

  if (!safePlayer.enabled) {
    return (
      <div className="focus-block quiet-note">
        <Play size={34} weight="duotone" />
        <h2>Player</h2>
        <p>{safePlayer.emptyState}</p>
      </div>
    );
  }

  const continueWatching = safePlayer.continueWatching || [];
  const sourceItems = safePlayer.sourceItems || [];
  const linkedItems = safePlayer.linkedItems || [];
  const unlinkedItems = safePlayer.unlinkedItems || [];

  return (
    <div className="focus-block player-board">
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
          <h2>Source items</h2>
          <span>{sourceItems.length} available</span>
        </div>
        <div className="player-list">
          {sourceItems.slice(0, 8).map((item) => (
            <button className="player-row" key={item.id} type="button">
              <FilmSlate size={22} />
              <span>
                <strong>{item.title}</strong>
                <small>{item.linked ? "linked" : "unlinked"} · {item.sourceName}</small>
              </span>
            </button>
          ))}
        </div>
      </section>
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
    </div>
  );
}

function FocusSection({ activeSection, activity, collections, stats, alerts, player, onOpen }) {
  if (activeSection === "shows") {
    return (
      <div className="focus-block">
        <Shelf title="Top watched shows" items={collections.topShows} onOpen={onOpen} />
        <Shelf
          compact
          title="Followed shows with available episodes"
          items={collections.followedNewEpisodes}
          onOpen={onOpen}
        />
      </div>
    );
  }

  if (activeSection === "movies") {
    return (
      <div className="focus-block">
        <Shelf title="Movies to check out" items={collections.moviesToCheckOut} onOpen={onOpen} />
      </div>
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
    return <PlayerSection player={player} />;
  }

  if (activeSection === "stats") {
    return (
      <div className="focus-block metrics-board">
        <StatsStrip stats={stats} />
        <ActivityChart activity={activity} />
      </div>
    );
  }

  if (activeSection === "lists" || activeSection === "settings") {
    return (
      <div className="focus-block quiet-note">
        <CalendarDots size={34} weight="duotone" />
        <h2>{activeSection === "lists" ? "Lists are ready for import polish" : "Private by default"}</h2>
        <p>
          {activeSection === "lists"
            ? "The GDPR export includes list rows, and this first dashboard keeps the space ready for full list browsing."
            : "Raw exports, generated JSON, SQLite, and poster assets are ignored locally so the sensitive archive is not accidentally committed."}
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
    setAppState("login");
    setLoadState("guest");
  }

  function expireSession() {
    setAuthUser(null);
    setDashboard(fallbackData);
    setReadAlerts(new Set());
    setSelectedItem(null);
    setAuthError("Session expired. Sign in again.");
    setAppState("login");
    setLoadState("guest");
  }

  async function openItem(item) {
    setSelectedItem(item);
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
    }
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
                activity={dashboard.activity}
                collections={collections}
                onOpen={openItem}
                player={dashboard.player}
                stats={stats}
              />
            )}
          </div>
          <aside className="insight-column">
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
      <DetailModal item={selectedItem} onClose={() => setSelectedItem(null)} />
    </div>
  );
}
