// @vitest-environment jsdom
import "@testing-library/jest-dom/vitest";
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import {
  App,
  DetailModal,
  GlobalSearchPanel,
  HistorySection,
  MovieLibrary,
  PlayerSection,
  SettingsSection,
  ShowLibrary,
  Sidebar,
  TimelinePanel,
} from "./App.jsx";

const movieItem = {
  id: "movie-watch-1",
  kind: "movie",
  movieId: 42,
  title: "Heat",
  meta: "170 min movie",
  progress: 100,
  badge: "watched",
};

const movieDetail = {
  id: 42,
  kind: "movie",
  movieId: 42,
  title: "Heat",
  subtitle: "Movie",
  meta: "170 min movie",
  status: "watched",
  watched: true,
  watchedCount: 1,
  rating: { id: 7, rating: 9 },
  notes: [{ id: 5, body: "Watch the diner scene again." }],
  watchHistory: [{ id: 11, watchedAt: "2026-07-01T12:00:00Z", runtime: 170, source: "manual" }],
  timeline: [
    {
      id: 21,
      title: "Watched Heat",
      subtitle: "Movie night",
      source: "manual",
      occurredAt: "2026-07-01T12:00:00Z",
    },
  ],
  provider: { linked: true, linkedItemsCount: 1 },
};

function renderDetail(overrides = {}) {
  const props = {
    item: movieItem,
    detail: movieDetail,
    detailError: "",
    detailLoading: false,
    actionError: "",
    actionPending: false,
    onClose: vi.fn(),
    onSaveRating: vi.fn(),
    onClearRating: vi.fn(),
    onSaveNote: vi.fn(),
    onDeleteNote: vi.fn(),
    onMarkWatched: vi.fn(),
    onMarkUnwatched: vi.fn(),
    ...overrides,
  };

  const utils = render(<DetailModal {...props} />);

  return { ...props, ...utils };
}

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  window.history.replaceState({}, "", "/");
});

const appDashboard = {
  features: { webPlayerEnabled: false, webProvidersEnabled: false },
  profile: { name: "Gunner", username: "gunner", displayName: "Gunner" },
  stats: { episodesWatched: 1, moviesWatched: 1, hoursWatched: 2, showsFollowed: 1 },
  hero: { title: "Continue watching", subtitle: "Private library", meta: "Ready", progress: 20, kind: "movie" },
  recentShow: { id: "recent-show-8", kind: "show", showId: 8, title: "Severance", subtitle: "S02 E03", meta: "3/10 watched", progress: 30, eyebrow: "Recent show", primaryActionLabel: "Continue watching", secondaryActionLabel: "View details" },
  alerts: [],
  recentlyWatched: [],
  followedNewEpisodes: [],
  moviesToCheckOut: [],
  topShows: [],
  activity: [],
  timeline: { recent: [], todaySummary: { total: 0 }, thisWeekSummary: { total: 0 } },
  player: { enabled: false, sourceItems: [], linkedItems: [], unlinkedItems: [], continueWatching: [] },
};

function jsonResponse(payload, status = 200) {
  return {
    headers: { get: () => "application/json" },
    json: async () => payload,
    ok: status >= 200 && status < 300,
    status,
  };
}

function stubAppApi() {
  vi.stubGlobal("fetch", vi.fn(async (input) => {
    const path = typeof input === "string" ? input : input.url;
    if (path === "/api/v1/auth/session") return jsonResponse({ authenticated: true, user: { name: "Gunner" } });
    if (path === "/api/v1/me") return jsonResponse({ user: { name: "Gunner" } });
    if (path === "/api/v1/dashboard") return jsonResponse(appDashboard);
    if (path === "/api/v1/settings") return jsonResponse({ profile: { name: "Gunner", email: "member@example.test", role: "member" }, metadata: {}, import: {}, export: { csvDatasets: [] }, version: "1.0.0" });
    if (path === "/api/v1/notification-preferences") return jsonResponse({ preferences: {} });
    if (path.startsWith("/api/v1/library/history")) return jsonResponse({ items: [], pagination: { page: 1, total: 0, hasMore: false } });
    if (path.startsWith("/api/v1/library/movies")) return jsonResponse({ items: [], pagination: { page: 1, total: 0, hasMore: false } });
    if (path.startsWith("/api/v1/library/shows")) return jsonResponse({ items: [], pagination: { page: 1, total: 0, hasMore: false } });
    if (path.startsWith("/api/v1/discover/browse")) return jsonResponse({ status: "ready", items: [], pagination: {} });
    throw new Error(`Unexpected request: ${path}`);
  }));
}

describe("Guest bootstrap", () => {
  it("uses the deliberate session probe without requesting a private endpoint or logging an expected 401", async () => {
    const consoleError = vi.spyOn(console, "error").mockImplementation(() => {});
    const fetch = vi.fn(async (input) => {
      const path = typeof input === "string" ? input : input.url;
      if (path === "/api/v1/auth/session") return jsonResponse({ authenticated: false, user: null });
      throw new Error(`Unexpected request: ${path}`);
    });
    vi.stubGlobal("fetch", fetch);

    render(<App />);

    expect(await screen.findByRole("button", { name: "Sign in" })).toBeInTheDocument();
    expect(fetch).toHaveBeenCalledTimes(1);
    expect(fetch).toHaveBeenCalledWith("/api/v1/auth/session", expect.any(Object));
    expect(consoleError).not.toHaveBeenCalled();
  });
});

describe("Settings page layout", () => {
  it("keeps the diary as a lazy Home section and removes it entirely from Settings", async () => {
    stubAppApi();
    const { container } = render(<App />);

    expect(await screen.findByText("Continue Watching")).toBeInTheDocument();
    expect(container.querySelectorAll(".lazy-home-section").length).toBeGreaterThan(0);
    fireEvent.click(screen.getByRole("button", { name: "Settings" }));

    expect(await screen.findByRole("heading", { name: "Settings" })).toBeInTheDocument();
    expect(container.querySelector(".lazy-home-section")).not.toBeInTheDocument();
    expect(container.querySelector(".dashboard-grid")).toHaveClass("settings-dashboard-grid");
    expect(container.querySelector(".insight-column")).not.toBeInTheDocument();
    expect(container.querySelector(".hero-panel")).not.toBeInTheDocument();
    expect(await screen.findByText("member@example.test")).toBeInTheDocument();
    expect(container.querySelector(".settings-editorial")).toBeInTheDocument();
  });

  it("keeps mobile Settings single-column without horizontal overflow or losing the account menu", async () => {
    vi.stubGlobal("innerWidth", 390);
    stubAppApi();
    const { container } = render(<App />);

    await screen.findByText("Continue Watching");
    fireEvent.click(screen.getByRole("button", { name: "Settings" }));
    await screen.findByRole("heading", { name: "Settings" });

    expect(container.querySelector(".dashboard-grid")).toHaveClass("settings-dashboard-grid");
    expect(container.querySelector(".insight-column")).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Open account menu" })).toBeInTheDocument();
    expect(document.documentElement.scrollWidth).toBeLessThanOrEqual(window.innerWidth);
  });
});

describe("Home page navigation and page-specific hero rules", () => {
  it("uses the existing app navigation from the new Home experience", async () => {
    stubAppApi();
    render(<App />);
    expect(await screen.findByRole("heading", { name: "Recently Added" })).toBeInTheDocument();
    expect(screen.getByRole("heading", { name: "Upcoming" })).toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: "History" }));
    expect(await screen.findByRole("heading", { name: "Watch history" })).toBeInTheDocument();
    expect(screen.getByLabelText("History type")).toHaveValue("all");

    fireEvent.click(screen.getByRole("button", { name: "Home" }));
    await screen.findByRole("heading", { name: "Recently Added" });
    fireEvent.click(screen.getByRole("navigation", { name: "Main navigation" }).querySelector('.nav-item[aria-label="Movies"]'));
    expect(await screen.findByRole("heading", { name: "Movies" })).toBeInTheDocument();
    expect(screen.getByLabelText("Movie status")).toHaveValue("all");
  });

  it("routes Home quick actions without inventing client-side business data", async () => {
    stubAppApi();
    render(<App />);
    await screen.findByRole("heading", { name: "Quick Actions" });

    fireEvent.click(screen.getByRole("button", { name: "Search" }));
    expect(screen.getByPlaceholderText("Search shows, movies, episodes...")).toHaveFocus();

    let quickActions = screen.getByRole("heading", { name: "Quick Actions" }).closest("section");
    fireEvent.click(within(quickActions).getByRole("button", { name: "Movies" }));
    expect(await screen.findByRole("heading", { name: "Movies" })).toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: "Home" }));
    await screen.findByRole("heading", { name: "Quick Actions" });
    quickActions = screen.getByRole("heading", { name: "Quick Actions" }).closest("section");
    fireEvent.click(within(quickActions).getByRole("button", { name: "Calendar" }));
    expect(await screen.findByRole("heading", { name: "Release calendar" })).toBeInTheDocument();
  });

  it("uses Continue Watching on Home, no hero on Discover, and a recent-show hero on Shows", async () => {
    stubAppApi();
    const { container } = render(<App />);
    await screen.findByText("Continue Watching");
    expect(container.querySelectorAll(".home-continue-section")).toHaveLength(1);
    expect(container.querySelectorAll(".hero-panel")).toHaveLength(0);

    fireEvent.click(container.querySelector('.nav-item[aria-label="Discover"]'));
    expect(await screen.findByRole("heading", { name: "Discover" })).toBeInTheDocument();
    expect(container.querySelector(".hero-panel")).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: "Shows" }));
    expect(await screen.findByRole("heading", { name: "Shows" })).toBeInTheDocument();
    expect(screen.getByText("Recent show")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Continue watching" })).toBeInTheDocument();
    expect(container.querySelectorAll(".hero-panel")).toHaveLength(1);
  });
});

describe("DetailModal", () => {
  it("renders media detail, rating, notes, history, and provider status", () => {
    renderDetail({ playerEnabled: true });

    expect(screen.getByRole("dialog", { name: /heat details/i })).toBeInTheDocument();
    expect(screen.getByText("9/10")).toBeInTheDocument();

    fireEvent.click(screen.getByRole("tab", { name: /notes & rating/i }));
    expect(screen.getByText("Private memory")).toBeInTheDocument();

    fireEvent.click(screen.getByRole("tab", { name: /your activity/i }));
    expect(screen.getByText("Entertainment diary")).toBeInTheDocument();
    expect(screen.getByText("Watched Heat")).toBeInTheDocument();

    fireEvent.click(screen.getByRole("tab", { name: /watch history/i }));
    expect(screen.getByText(/manual entry/i)).toBeInTheDocument();

    fireEvent.click(screen.getByRole("tab", { name: /provider \/ playback/i }));
    expect(screen.getByText("Ready from your source")).toBeInTheDocument();
  });

  it("hides provider and play surfaces when the web player is disabled", () => {
    renderDetail();

    expect(screen.queryByRole("tab", { name: /provider/i })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /^play/i })).not.toBeInTheDocument();
    expect(screen.queryByText("Linked source")).not.toBeInTheDocument();
  });

  it("renders cast, crew, production details, and completed show state", () => {
    renderDetail({
      item: { id: 8, showId: 8, kind: "show", title: "Completed Story" },
      detail: {
        id: 8,
        showId: 8,
        kind: "show",
        title: "Completed Story",
        watched: true,
        watchedEpisodes: 20,
        meta: "20/20 watched",
        showState: { code: "ended_completed", title: "SHOW ENDED", description: "You watched every aired episode." },
        people: { cast: [{ id: 1, name: "Lead Actor", role: "Lead", image: "" }], directors: [{ id: 2, name: "Series Creator", role: "Creator", image: "" }] },
        production: { companies: ["Studio One"], countries: ["North Macedonia"], languages: ["Macedonian"] },
        metadata: {},
        notes: [],
        seasons: [],
        timeline: [],
      },
    });

    expect(screen.getByText("SHOW ENDED")).toBeInTheDocument();
    expect(screen.getByText("20 episodes")).toBeInTheDocument();
    expect(screen.queryByText("Watched 20 times")).not.toBeInTheDocument();
    expect(screen.getByText("Lead Actor")).toBeInTheDocument();
    expect(screen.getByText("Series Creator")).toBeInTheDocument();
    expect(screen.getByText("Studio One")).toBeInTheDocument();
  });

  it("renders enriched metadata without breaking poster fallback", () => {
    const enrichedDetail = {
      ...movieDetail,
      poster: "https://image.tmdb.org/t/p/w500/heat-poster.jpg",
      backdrop: "https://image.tmdb.org/t/p/w780/heat-backdrop.jpg",
      metadata: {
        genres: ["Crime", "Drama"],
        releaseYear: "1995",
        runtime: 170,
        status: "Released",
        tmdbId: 949,
        imdbId: "tt0113277",
        metadataStatus: "enriched",
      },
    };
    const { container } = renderDetail({ detail: enrichedDetail });

    expect(container.querySelector(".cinematic-poster img")).toHaveAttribute("src", "https://image.tmdb.org/t/p/w500/heat-poster.jpg");
    expect(screen.getByText("Crime")).toBeInTheDocument();
    expect(screen.getByText("Drama")).toBeInTheDocument();
    expect(screen.getByText("1995")).toBeInTheDocument();
    expect(screen.getByText("949")).toBeInTheDocument();
    expect(screen.getByText("enriched")).toBeInTheDocument();
  });

  it("renders a season and episode browser for shows", () => {
    const onOpenEpisode = vi.fn();
    renderDetail({
      detail: {
        ...movieDetail,
        id: 8,
        kind: "show",
        showId: 8,
        title: "Severance",
        subtitle: "TV show",
        meta: "2/9 watched",
        watched: true,
        latestEpisode: {
          id: 101,
          episodeId: 101,
          showId: 8,
          title: "Good News About Hell",
          code: "S01E01",
          watchedAt: "2026-07-01T12:00:00Z",
        },
        seasons: [
          {
            seasonNumber: 1,
            watchedEpisodes: 1,
            totalEpisodes: 2,
            episodes: [
              {
                id: 101,
                episodeId: 101,
                showId: 8,
                title: "Good News About Hell",
                code: "S01E01",
                watched: true,
                watchedAt: "2026-07-01T12:00:00Z",
                rating: 9,
                hasNote: true,
              },
              {
                id: 102,
                episodeId: 102,
                showId: 8,
                title: "Half Loop",
                code: "S01E02",
                watched: false,
              },
            ],
          },
          {
            seasonNumber: 2,
            watchedEpisodes: 0,
            totalEpisodes: 1,
            episodes: [
              {
                id: 201,
                episodeId: 201,
                showId: 8,
                title: "Hello, Ms. Cobel",
                code: "S02E01",
                watched: false,
              },
            ],
          },
        ],
      },
      onOpenEpisode,
    });

    expect(screen.getByRole("button", { name: /jump to latest watched/i })).toBeInTheDocument();

    fireEvent.click(screen.getByRole("tab", { name: /episodes/i }));
    expect(screen.getByText(/rated 9\/10/i)).toBeInTheDocument();
    expect(screen.getByText(/private note/i)).toBeInTheDocument();
    expect(screen.getByText("Season 1")).toBeInTheDocument();
    expect(screen.getByText("1/2 watched")).toBeInTheDocument();
    expect(screen.getByText("Good News About Hell")).toBeInTheDocument();
    expect(screen.getByText("Half Loop")).toBeInTheDocument();
    expect(screen.queryByText("Hello, Ms. Cobel")).not.toBeInTheDocument();

    fireEvent.change(screen.getByLabelText("Season"), { target: { value: "2" } });
    expect(screen.getByText("Hello, Ms. Cobel")).toBeInTheDocument();
    expect(screen.queryByText("Half Loop")).not.toBeInTheDocument();
    fireEvent.change(screen.getByLabelText("Season"), { target: { value: "1" } });

    fireEvent.click(screen.getByRole("button", { name: /open half loop/i }));

    expect(onOpenEpisode).toHaveBeenCalledWith({
      episodeId: 102,
      showId: 8,
      kind: "episode",
      title: "Half Loop",
      subtitle: "S01E02",
      meta: "Severance - S01E02",
    });
  });

  it("saves and clears rating selections", () => {
    const props = renderDetail();

    fireEvent.click(screen.getByRole("tab", { name: /notes & rating/i }));
    fireEvent.click(screen.getByRole("button", { name: "8" }));
    expect(props.onSaveRating).toHaveBeenCalledWith(movieDetail, 8);

    fireEvent.click(screen.getByRole("button", { name: /clear rating/i }));
    expect(props.onClearRating).toHaveBeenCalledWith(movieDetail);
  });

  it("saves and deletes private notes", () => {
    const props = renderDetail();
    fireEvent.click(screen.getByRole("tab", { name: /notes & rating/i }));
    const note = screen.getByLabelText(/private note/i);

    fireEvent.change(note, { target: { value: "Updated note." } });
    fireEvent.click(screen.getByRole("button", { name: /save note/i }));

    expect(props.onSaveNote).toHaveBeenCalledWith(
      movieDetail,
      "Updated note.",
      movieDetail.notes[0],
    );

    fireEvent.click(screen.getByRole("button", { name: /delete note/i }));
    expect(props.onDeleteNote).toHaveBeenCalledWith(movieDetail, movieDetail.notes[0]);
  });

  it("creates rewatches and removes only the latest manual watch", () => {
    const unwatchedDetail = { ...movieDetail, watched: false, watchHistory: [] };
    const unwatchedProps = renderDetail({ detail: unwatchedDetail });

    fireEvent.click(screen.getByRole("button", { name: /^mark watched$/i }));
    expect(unwatchedProps.onMarkWatched).toHaveBeenCalledWith(unwatchedDetail);

    unwatchedProps.unmount();

    const watchedProps = renderDetail();
    fireEvent.click(screen.getByRole("button", { name: /mark watched again/i }));
    expect(watchedProps.onMarkWatched).toHaveBeenCalledWith(movieDetail);
    fireEvent.click(screen.getByRole("button", { name: /remove latest manual watch/i }));
    expect(watchedProps.onMarkUnwatched).toHaveBeenCalledWith(movieDetail);
  });

  it("shows loading and safe error states", () => {
    renderDetail({
      detail: null,
      detailError: "Could not load details.",
      detailLoading: true,
      actionError: "Could not save change.",
    });

    expect(screen.getByText("Loading details...")).toBeInTheDocument();
    expect(screen.getByText("Could not load details.")).toBeInTheDocument();
    expect(screen.getByText("Could not save change.")).toBeInTheDocument();
  });
});

describe("Web navigation feature flags", () => {
  it("hides Player by default and reveals it only when explicitly enabled", () => {
    const { rerender } = render(<Sidebar activeSection="home" alertsCount={0} onSelect={vi.fn()} />);

    expect(screen.queryByRole("button", { name: /player/i })).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: /home/i })).toHaveAttribute("aria-label", "Home");
    expect(screen.getByRole("button", { name: /lists/i })).toBeInTheDocument();

    rerender(<Sidebar activeSection="home" alertsCount={0} features={{ webPlayerEnabled: true }} onSelect={vi.fn()} />);
    expect(screen.getByRole("button", { name: /player/i })).toBeInTheDocument();
  });
});

const unlinkedCatalogItem = {
  id: 10,
  sourceId: 3,
  sourceName: "My Provider",
  kind: "movie",
  title: "Heat Source",
  category: "Drama",
  status: "available",
  matchStatus: "needs_review",
  playable: true,
  linked: false,
  favorite: false,
  link: null,
};

const linkedCatalogItem = {
  ...unlinkedCatalogItem,
  id: 12,
  title: "Linked Heat Source",
  linked: true,
  matchStatus: "linked",
  link: { id: 22, movieId: 42, canonicalTitle: "Heat" },
};

const providerSummary = {
  id: 3,
  name: "My Provider",
  providerType: "manual",
  enabled: true,
  itemsCount: 2,
  activeItemsCount: 2,
  syncStatus: "completed",
};

const homeCatalog = {
  view: "home",
  categories: [{ name: "Drama", count: 2 }],
  continueWatching: [unlinkedCatalogItem],
  recentMovies: [unlinkedCatalogItem, linkedCatalogItem],
  recentShows: [],
  recentlyWatched: [],
  linkedItems: [linkedCatalogItem],
  needsMatching: [unlinkedCatalogItem],
};

function createPlayerClient(overrides = {}) {
  return vi.fn(async (path, options = {}) => {
    if (overrides[path]) return overrides[path](options);
    const prefixOverride = Object.entries(overrides).find(([prefix]) => path.startsWith(prefix));
    if (prefixOverride) return prefixOverride[1](options);
    if (path === "/api/v1/providers") return { providers: [providerSummary] };
    if (path.startsWith("/api/v1/player/catalog")) {
      if (path.includes("view=movies")) return { view: "movies", categories: homeCatalog.categories, items: [unlinkedCatalogItem, linkedCatalogItem] };
      if (path.includes("view=shows")) return { view: "shows", categories: [], items: [] };
      if (path.includes("view=live")) return { view: "live", categories: [], items: [] };
      return homeCatalog;
    }
    throw new Error(`Unexpected API call: ${path}`);
  });
}

function renderPlayer({ apiClient = createPlayerClient(), player = { enabled: true }, onOpenSettings = vi.fn(), onRefreshDashboard = vi.fn() } = {}) {
  const utils = render(<PlayerSection apiClient={apiClient} onOpenSettings={onOpenSettings} onRefreshDashboard={onRefreshDashboard} player={player} />);
  return { apiClient, onOpenSettings, onRefreshDashboard, ...utils };
}

describe("PlayerSection", () => {
  it("points the empty state to Provider Settings without blocking manual tracking", async () => {
    const apiClient = createPlayerClient({
      "/api/v1/providers": async () => ({ providers: [] }),
    });
    const onOpenSettings = vi.fn();
    renderPlayer({ apiClient, onOpenSettings, player: { enabled: false } });

    expect(await screen.findByText(/connect your own provider in settings/i)).toBeInTheDocument();
    expect(screen.getByText(/manual library tracking remains available/i)).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: /open provider settings/i }));
    expect(onOpenSettings).toHaveBeenCalledOnce();
  });

  it("renders the private catalog home and media navigation without raw URLs", async () => {
    renderPlayer();

    expect(await screen.findByRole("heading", { name: /continue watching/i })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /movies/i })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /shows/i })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /live tv/i })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /tv guide/i })).toBeInTheDocument();
    expect(screen.getAllByText("Heat Source").length).toBeGreaterThan(0);
    expect(document.body.textContent).not.toMatch(/stream_url|playlist_url|provider_url/i);
  });

  it("loads a filtered provider movie catalog", async () => {
    const apiClient = createPlayerClient();
    renderPlayer({ apiClient });
    await screen.findByRole("heading", { name: /continue watching/i });

    fireEvent.click(screen.getByRole("button", { name: /^movies$/i }));
    expect(await screen.findByLabelText(/search catalog/i)).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText(/search catalog/i), { target: { value: "heat" } });
    fireEvent.change(screen.getByLabelText(/category/i), { target: { value: "Drama" } });
    fireEvent.click(screen.getAllByRole("button", { name: /^search$/i }).at(-1));

    await waitFor(() => expect(apiClient).toHaveBeenCalledWith(expect.stringMatching(/view=movies.*query=heat.*category=Drama/)));
  });

  it("links and unlinks catalog items with explicit confirmation", async () => {
    const apiClient = createPlayerClient({
      "/api/v1/player/link-targets": async () => ({ targets: [{ type: "movie", id: 42, title: "Heat", subtitle: "Movie", meta: "170 min" }] }),
      "/api/v1/player/items/10/link": async () => ({ link: { id: 9, movie_id: 42 } }),
      "/api/v1/player/items/12/link": async () => null,
    });
    renderPlayer({ apiClient });

    fireEvent.click((await screen.findAllByRole("button", { name: /link heat source/i }))[0]);
    fireEvent.click(screen.getByRole("button", { name: /search library/i }));
    await waitFor(() => expect(apiClient).toHaveBeenCalledWith(expect.stringContaining("/api/v1/player/link-targets?")));
    fireEvent.click((await screen.findByText("Heat")).closest("button"));
    fireEvent.click(screen.getByLabelText(/i confirm this source item matches/i));
    fireEvent.click(screen.getByRole("button", { name: /^link item$/i }));

    await waitFor(() => expect(apiClient).toHaveBeenCalledWith("/api/v1/player/items/10/link", { method: "POST", body: { movie_id: 42, confirm: true } }));
    fireEvent.click((await screen.findAllByRole("button", { name: /unlink linked heat source/i }))[0]);
    await waitFor(() => expect(apiClient).toHaveBeenCalledWith("/api/v1/player/items/12/link", { method: "DELETE" }));
  });

  it("keeps the existing optional Kalveri AI suggestion behind user confirmation", async () => {
    const apiClient = createPlayerClient({
      "/api/v1/player/items/10/ai-match": async () => ({ suggestion: { status: "suggested", mediaType: "movie", candidateId: 42, confidence: 0.82, reason: "Title aligns with the local candidate.", requiresConfirmation: true, candidate: { type: "movie", id: 42, title: "Heat" } } }),
      "/api/v1/player/items/10/link": async () => ({ link: { id: 9 } }),
    });
    renderPlayer({ apiClient });

    fireEvent.click((await screen.findAllByRole("button", { name: /link heat source/i }))[0]);
    fireEvent.click(screen.getByRole("button", { name: /ask kalveri ai/i }));
    expect(await screen.findByText("Suggested match")).toBeInTheDocument();
    fireEvent.click(screen.getByLabelText(/i confirm this source item matches/i));
    fireEvent.click(screen.getByRole("button", { name: /^link item$/i }));
    await waitFor(() => expect(apiClient).toHaveBeenCalledWith("/api/v1/player/items/10/link", { method: "POST", body: { movie_id: 42, confirm: true, ai_suggestion: true } }));
  });

  it("rejects an existing Kalveri AI suggestion safely", async () => {
    const apiClient = createPlayerClient({
      "/api/v1/player/items/10/ai-match": async () => ({ suggestion: { status: "suggested", mediaType: "movie", candidateId: 42, confidence: 0.75, reason: "Possible title match.", candidate: { type: "movie", id: 42, title: "Heat" } } }),
      "/api/v1/player/items/10/ai-match/reject": async () => null,
    });
    renderPlayer({ apiClient });

    fireEvent.click((await screen.findAllByRole("button", { name: /link heat source/i }))[0]);
    fireEvent.click(screen.getByRole("button", { name: /ask kalveri ai/i }));
    fireEvent.click(await screen.findByRole("button", { name: /reject suggestion/i }));
    await waitFor(() => expect(apiClient).toHaveBeenCalledWith("/api/v1/player/items/10/ai-match/reject", { method: "POST" }));
    expect(screen.queryByText("Possible title match.")).not.toBeInTheDocument();
  });

  it("starts playback, saves native progress, and reports safe playback errors", async () => {
    const playbackUrl = "https://media.invalid/owned-source.m3u8";
    const apiClient = createPlayerClient({
      "/api/v1/player/items/10/play": async () => ({ session: { id: 99, sourceItemId: 10, status: "playing" }, playbackUrl }),
      "/api/v1/player/sessions/99": async () => ({ session: { id: 99, status: "playing" } }),
    });
    renderPlayer({ apiClient });

    fireEvent.click((await screen.findAllByRole("button", { name: /play heat source/i }))[0]);
    expect(await screen.findByText(/progress is saved only to this source until linked/i)).toBeInTheDocument();
    const video = screen.getByTestId("provider-video");
    expect(video).toHaveAttribute("src", playbackUrl);
    Object.defineProperty(video, "currentTime", { configurable: true, value: 120 });
    Object.defineProperty(video, "duration", { configurable: true, value: 600 });
    fireEvent.pause(video);
    await waitFor(() => expect(apiClient).toHaveBeenCalledWith("/api/v1/player/sessions/99", { method: "PATCH", body: { position_seconds: 120, duration_seconds: 600, completed: false } }));
    fireEvent.error(video);
    expect(screen.getByText(/playback is unavailable/i)).toBeInTheDocument();
  });
});

describe("SettingsSection", () => {
  it("creates a user-owned provider and keeps sensitive values out of the provider list", async () => {
    let providers = [];
    const apiClient = vi.fn(async (path, options = {}) => {
      if (path === "/api/v1/providers" && !options.method) return { providers };
      if (path === "/api/v1/providers" && options.method === "POST") {
        providers = [{ ...providerSummary, id: 7, name: options.body.name }];
        return { provider: providers[0] };
      }
      throw new Error(`Unexpected API call: ${path}`);
    });
    render(<SettingsSection apiClient={apiClient} providersEnabled />);

    fireEvent.click(screen.getByRole("button", { name: /^providers$/i }));
    fireEvent.change(screen.getByLabelText(/provider display name/i), { target: { value: "My NAS" } });
    fireEvent.click(screen.getByLabelText(/i own or am authorized/i));
    fireEvent.click(screen.getByRole("button", { name: /add provider/i }));

    await waitFor(() => expect(apiClient).toHaveBeenCalledWith("/api/v1/providers", { method: "POST", body: expect.objectContaining({ name: "My NAS", provider_type: "manual", legal_confirmed: true }) }));
    expect((await screen.findAllByText("My NAS")).length).toBeGreaterThan(0);
    expect(document.body.textContent).not.toContain("media.invalid");
  });

  it("adds an item to a manual provider without displaying its private playback URL", async () => {
    const apiClient = vi.fn(async (path, options = {}) => {
      if (path === "/api/v1/providers") return { providers: [providerSummary] };
      if (path === "/api/v1/player/sources/3/items" && options.method === "POST") return { item: { id: 44, title: options.body.title } };
      throw new Error(`Unexpected API call: ${path}`);
    });
    render(<SettingsSection apiClient={apiClient} providersEnabled />);
    fireEvent.click(screen.getByRole("button", { name: /^providers$/i }));
    expect((await screen.findAllByText("My Provider")).length).toBeGreaterThan(0);
    fireEvent.change(screen.getByLabelText(/manual provider/i), { target: { value: "3" } });
    fireEvent.change(screen.getByLabelText(/item title/i), { target: { value: "Private Film" } });
    fireEvent.change(screen.getByLabelText(/private playback url/i), { target: { value: "https://media.invalid/private-film.mp4" } });
    fireEvent.click(screen.getByRole("button", { name: /add source item/i }));
    await waitFor(() => expect(apiClient).toHaveBeenCalledWith("/api/v1/player/sources/3/items", { method: "POST", body: { title: "Private Film", kind: "movie", stream_url: "https://media.invalid/private-film.mp4" } }));
    expect(document.body.textContent).not.toContain("https://media.invalid/private-film.mp4");
  });

  it("makes the first catalog refresh requirement explicit for a saved Xtream provider", async () => {
    let providers = [];
    const apiClient = vi.fn(async (path, options = {}) => {
      if (path === "/api/v1/providers" && !options.method) return { providers };
      if (path === "/api/v1/providers" && options.method === "POST") {
        providers = [{ ...providerSummary, id: 8, name: options.body.name, providerType: "xtream", itemsCount: 0, activeItemsCount: 0, syncStatus: "never_synced" }];
        return { provider: providers[0] };
      }
      throw new Error(`Unexpected API call: ${path}`);
    });
    render(<SettingsSection apiClient={apiClient} providersEnabled />);

    fireEvent.click(screen.getByRole("button", { name: /^providers$/i }));
    fireEvent.change(screen.getByLabelText(/provider display name/i), { target: { value: "Private TV" } });
    fireEvent.change(screen.getByLabelText(/provider type/i), { target: { value: "xtream" } });
    fireEvent.change(screen.getByLabelText(/server base url/i), { target: { value: "https://provider.example.test" } });
    fireEvent.change(screen.getByLabelText(/provider username/i), { target: { value: "private-user" } });
    fireEvent.change(screen.getByLabelText(/provider password/i), { target: { value: "private-password" } });
    fireEvent.click(screen.getByLabelText(/i own or am authorized/i));
    fireEvent.click(screen.getByRole("button", { name: /add provider/i }));

    expect(await screen.findByText("Provider connected. Refresh catalog to import content.")).toBeInTheDocument();
    expect(screen.getByText(/active items · catalog not imported/i)).toBeInTheDocument();
  });

  it("shows a failed refresh as a visible action state instead of idle", async () => {
    const failedProvider = { ...providerSummary, providerType: "xtream", itemsCount: 0, activeItemsCount: 0, syncStatus: "failed", lastSyncError: "provider_http_520", lastSyncedAt: "2026-07-11T00:00:00Z" };
    const apiClient = vi.fn(async (path) => {
      if (path === "/api/v1/providers") return { providers: [failedProvider] };
      throw new Error(`Unexpected API call: ${path}`);
    });
    render(<SettingsSection apiClient={apiClient} providersEnabled />);

    fireEvent.click(screen.getByRole("button", { name: /^providers$/i }));
    expect(await screen.findByText(/active items · catalog refresh failed/i)).toBeInTheDocument();
    expect(screen.getByText(/check the connection or provider availability/i)).toBeInTheDocument();
  });

  it("keeps provider settings hidden and avoids provider API calls by default", async () => {
    const apiClient = vi.fn();
    render(<SettingsSection apiClient={apiClient} />);

    expect(screen.queryByRole("button", { name: /^providers$/i })).not.toBeInTheDocument();
    await waitFor(() => expect(apiClient).not.toHaveBeenCalled());
  });
});

const movieLibraryPayload = {
  items: [
    {
      id: 42,
      movieId: 42,
      kind: "movie",
      title: "Heat",
      subtitle: "Movie",
      meta: "1995 · 170 min",
      year: "1995",
      runtime: 170,
      status: "watched",
      watched: true,
      rating: 9,
      hasNote: true,
      providerLinked: true,
      metadataStatus: "enriched",
      progress: 100,
    },
  ],
  pagination: { page: 1, perPage: 24, total: 1, hasMore: false },
};

const showLibraryPayload = {
  items: [
    {
      id: 8,
      showId: 8,
      kind: "show",
      title: "Severance",
      subtitle: "Followed show",
      meta: "2/9 watched",
      status: "followed",
      progress: 22,
      rating: 10,
      hasNote: false,
      providerLinked: false,
      metadataStatus: "enriched",
      watched: true,
      watchedEpisodes: 2,
      airedEpisodes: 9,
    },
  ],
  pagination: { page: 1, perPage: 24, total: 1, hasMore: false },
};

describe("Library browser", () => {
  it("uses TMDB poster artwork when a movie is enriched", async () => {
    const apiClient = vi.fn().mockResolvedValue({
      items: [
        {
          ...movieLibraryPayload.items[0],
          poster: "https://image.tmdb.org/t/p/w500/heat-poster.jpg",
        },
      ],
      pagination: { page: 1, perPage: 24, total: 1, hasMore: false },
    });
    const { container } = render(<MovieLibrary apiClient={apiClient} onOpen={vi.fn()} />);

    expect(await screen.findByText("Heat")).toBeInTheDocument();
    expect(container.querySelector(".library-card-art img")).toHaveAttribute(
      "src",
      "https://image.tmdb.org/t/p/w500/heat-poster.jpg",
    );
  });

  it("uses a neutral poster fallback instead of repeating generated artwork", async () => {
    const apiClient = vi.fn().mockResolvedValue({
      items: [
        {
          ...movieLibraryPayload.items[0],
          id: 42,
          title: "Heat",
          poster: "",
          backdrop: "",
        },
        {
          ...movieLibraryPayload.items[0],
          id: 43,
          movieId: 43,
          title: "Arrival",
          poster: "/assets/generated/movie-poster-1.png",
          backdrop: "",
        },
      ],
      pagination: { page: 1, perPage: 24, total: 2, hasMore: false },
    });
    const { container } = render(<MovieLibrary apiClient={apiClient} onOpen={vi.fn()} />);

    expect(await screen.findByText("Heat")).toBeInTheDocument();
    expect(await screen.findByText("Arrival")).toBeInTheDocument();
    expect(container.querySelectorAll('img[src="/assets/generated/movie-poster-1.png"]')).toHaveLength(0);
    expect(screen.getByLabelText("No poster for Heat")).toHaveTextContent("HE");
    expect(screen.getByLabelText("No poster for Arrival")).toHaveTextContent("AR");
  });

  it("renders a searchable movie browser with ratings, notes, watched state, and safe metadata", async () => {
    const apiClient = vi.fn().mockResolvedValue(movieLibraryPayload);
    const onOpen = vi.fn();

    render(<MovieLibrary apiClient={apiClient} onOpen={onOpen} />);

    expect(await screen.findByRole("heading", { name: /movies/i })).toBeInTheDocument();
    expect(screen.getByText("Heat")).toBeInTheDocument();
    expect(screen.getByText("9/10")).toBeInTheDocument();
    expect(screen.getByText("Private note")).toBeInTheDocument();
    expect(screen.queryByText("Linked source")).not.toBeInTheDocument();
    expect(screen.getByText("enriched")).toBeInTheDocument();

    fireEvent.change(screen.getByLabelText(/search movies/i), { target: { value: "arrival" } });
    fireEvent.click(screen.getByRole("button", { name: /^search$/i }));

    await waitFor(() => {
      expect(apiClient).toHaveBeenCalledWith(expect.stringContaining("/api/v1/library/movies?"));
      expect(apiClient).toHaveBeenCalledWith(expect.stringContaining("search=arrival"));
    });

    fireEvent.click(screen.getByRole("button", { name: /open heat/i }));
    expect(onOpen).toHaveBeenCalledWith(movieLibraryPayload.items[0]);
    expect(screen.queryByText(/stream_url/i)).not.toBeInTheDocument();
  });

  it("renders a show browser with progress filters and opens canonical show details", async () => {
    const apiClient = vi.fn().mockResolvedValue(showLibraryPayload);
    const onOpen = vi.fn();

    render(<ShowLibrary apiClient={apiClient} onOpen={onOpen} />);

    expect(await screen.findByRole("heading", { name: /shows/i })).toBeInTheDocument();
    expect(await screen.findByText("Severance")).toBeInTheDocument();
    expect(screen.getByText("2/9 watched")).toBeInTheDocument();
    expect(screen.getByText("2 episodes watched")).toBeInTheDocument();
    expect(screen.queryByText("Watched 2 times")).not.toBeInTheDocument();
    expect(screen.getByText("10/10")).toBeInTheDocument();

    fireEvent.change(screen.getByLabelText(/show status/i), { target: { value: "in_progress" } });

    await waitFor(() => {
      expect(apiClient).toHaveBeenCalledWith(expect.stringContaining("status=in_progress"));
    });

    fireEvent.click(screen.getByRole("button", { name: /open severance/i }));
    expect(onOpen).toHaveBeenCalledWith(showLibraryPayload.items[0]);
  });

  it("uses only the library search inside Movies", async () => {
    stubAppApi();
    render(<App />);
    await screen.findByRole("heading", { name: "Recently Added" });

    fireEvent.click(screen.getByRole("navigation", { name: "Main navigation" }).querySelector('.nav-item[aria-label="Movies"]'));

    expect(await screen.findByRole("heading", { name: "Movies" })).toBeInTheDocument();
    expect(screen.getByLabelText("Search movies")).toBeInTheDocument();
    expect(screen.queryByPlaceholderText("Search shows, movies, episodes...")).not.toBeInTheDocument();
  });
});

describe("HistorySection", () => {
  it("renders paginated watch history and filters by canonical item type", async () => {
    const apiClient = vi.fn().mockResolvedValue({
      items: [
        {
          id: "movie-42",
          kind: "movie",
          title: "Heat",
          subtitle: "Movie",
          meta: "manual",
          watchedAt: "2026-07-01T12:00:00Z",
          source: "manual",
          movieId: 42,
        },
      ],
      pagination: { page: 1, perPage: 30, total: 1, hasMore: false },
    });
    const onOpen = vi.fn();

    render(<HistorySection apiClient={apiClient} onOpen={onOpen} />);

    expect(await screen.findByRole("heading", { name: /watch history/i })).toBeInTheDocument();
    expect(screen.getByText("Heat")).toBeInTheDocument();
    expect(screen.getByText("Manual entry")).toBeInTheDocument();

    fireEvent.change(screen.getByLabelText(/history type/i), { target: { value: "movie" } });

    await waitFor(() => {
      expect(apiClient).toHaveBeenCalledWith(expect.stringContaining("type=movie"));
    });

    fireEvent.click(screen.getByRole("button", { name: /open heat history/i }));
    expect(onOpen).toHaveBeenCalled();
  });
});

describe("GlobalSearchPanel", () => {
  it("searches canonical movies, shows, and episodes without listing provider items", async () => {
    const apiClient = vi.fn().mockResolvedValue({
      movies: [{ ...movieLibraryPayload.items[0], title: "Heat" }],
      shows: [{ ...showLibraryPayload.items[0], title: "Severance" }],
      episodes: [
        {
          id: 101,
          episodeId: 101,
          showId: 8,
          kind: "episode",
          title: "Good News About Hell",
          subtitle: "S01E01",
          meta: "Severance - S01E01",
        },
      ],
    });
    const onOpen = vi.fn();

    render(<GlobalSearchPanel apiClient={apiClient} onOpen={onOpen} query="heat" />);
    fireEvent.click(screen.getByRole("tab", { name: /my library/i }));

    expect(await screen.findByText("Canonical search")).toBeInTheDocument();
    expect(screen.getByText("Heat")).toBeInTheDocument();
    expect(screen.getByText("Severance")).toBeInTheDocument();
    expect(screen.getByText("Good News About Hell")).toBeInTheDocument();
    expect(screen.queryByText(/provider/i)).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: /open good news about hell/i }));
    expect(onOpen).toHaveBeenCalledWith(expect.objectContaining({ episodeId: 101 }));
  });

  it("discovers external movies and shows, previews them, and adds them safely", async () => {
    const onLibraryChanged = vi.fn();
    const apiClient = vi.fn(async (path, options = {}) => {
      if (path.startsWith("/api/v1/library/search")) return { movies: [], shows: [], episodes: [] };
      if (path.startsWith("/api/v1/discover/search")) return {
        status: "ready",
        items: [
          { media_type: "movie", tmdb_id: 101, title: "Discovery Film", year: "2026", overview: "A newly discovered film.", genres: ["Drama"], already_in_library: false },
          { media_type: "show", tmdb_id: 202, title: "Discovery Show", year: "2025", overview: "A newly discovered show.", genres: ["Mystery"], already_in_library: false },
        ],
        pagination: { page: 1, totalPages: 1 },
      };
      if (path === "/api/v1/discover/movie/101") return {
        status: "ready",
        item: {
          media_type: "movie",
          tmdb_id: 101,
          title: "Discovery Film",
          original_title: "Discovery Film Original",
          year: 2026,
          runtime: 118,
          status: "Released",
          vote_average: 8.2,
          overview: "The complete plot for a newly discovered film.",
          tagline: "Every discovery changes you.",
          genres: ["Drama"],
          people: { cast: [{ id: 1, name: "Discovery Actor", role: "Lead", image: "" }], directors: [{ id: 2, name: "Discovery Director", role: "Director", image: "" }] },
          production: { companies: ["Discovery Studio"], countries: ["North Macedonia"], languages: ["Macedonian"] },
          already_in_library: false,
        },
      };
      if (path === "/api/v1/discover/movies/101/add" && options.method === "POST") return { item: { id: 77, movieId: 77, title: "Discovery Film" } };
      throw new Error(`Unexpected API call: ${path}`);
    });

    render(<GlobalSearchPanel apiClient={apiClient} onLibraryChanged={onLibraryChanged} onOpen={vi.fn()} query="discover" />);

    expect(await screen.findByText("Discovery Film")).toBeInTheDocument();
    expect(screen.getByText("Discovery Show")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: /open discovery film/i }));
    expect(screen.getByRole("dialog", { name: /discovery film discovery preview/i })).toBeInTheDocument();
    expect(await screen.findByText("Discovery Actor")).toBeInTheDocument();
    expect(screen.getByText("Discovery Director")).toBeInTheDocument();
    expect(screen.getByText("The complete plot for a newly discovered film.")).toBeInTheDocument();
    expect(screen.getByText("118 min")).toBeInTheDocument();
    expect(screen.getByText("Discovery Studio")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: /add to library/i }));

    await waitFor(() => expect(apiClient).toHaveBeenCalledWith("/api/v1/discover/movies/101/add", { method: "POST", body: { action: "library" } }));
    expect(onLibraryChanged).toHaveBeenCalledOnce();
    expect(screen.getByRole("button", { name: /open in my library/i })).toBeInTheDocument();
  });
});

describe("TimelinePanel", () => {
  it("renders grouped media events with titles, subtitles, time, and source labels", () => {
    render(
      <TimelinePanel
        timeline={{
          recent: [
            {
              id: 1,
              eventType: "movie.watched",
              title: "Watched Heat",
              subtitle: "Movie",
              source: "manual",
              occurredAt: new Date().toISOString(),
              group: "Today",
            },
            {
              id: 2,
              eventType: "rating.created",
              title: "Rated Severance 10/10",
              subtitle: "Show",
              source: "manual",
              occurredAt: new Date(Date.now() - 86400000).toISOString(),
              group: "Yesterday",
            },
          ],
          todaySummary: { total: 1 },
          thisWeekSummary: { total: 2 },
        }}
      />,
    );

    expect(screen.getByRole("heading", { name: /entertainment diary/i })).toBeInTheDocument();
    expect(screen.getByText("Today")).toBeInTheDocument();
    expect(screen.getByText("Yesterday")).toBeInTheDocument();
    expect(screen.getByText("Watched Heat")).toBeInTheDocument();
    expect(screen.getByText("Rated Severance 10/10")).toBeInTheDocument();
    expect(screen.getAllByText("Manual entry")).toHaveLength(2);
  });

  it("keeps this-week memories visible instead of dropping them into raw technical data", () => {
    render(
      <TimelinePanel
        timeline={{
          recent: [
            {
              id: 3,
              eventType: "note.created",
              title: "Added a private note",
              subtitle: "Movie",
              source: "player",
              occurredAt: new Date(Date.now() - 172800000).toISOString(),
              group: "This week",
            },
          ],
          todaySummary: { total: 0 },
          thisWeekSummary: { total: 1 },
        }}
      />,
    );

    expect(screen.getByText("This week")).toBeInTheDocument();
    expect(screen.getByText("Added a private note")).toBeInTheDocument();
    expect(screen.getByText("Automatic tracking")).toBeInTheDocument();
    expect(screen.queryByText("note.created")).not.toBeInTheDocument();
  });

  it("renders a quiet empty state when there are no events", () => {
    render(
      <TimelinePanel
        timeline={{
          recent: [],
          todaySummary: { total: 0 },
          thisWeekSummary: { total: 0 },
        }}
      />,
    );

    expect(screen.getByText(/your entertainment diary is quiet/i)).toBeInTheDocument();
  });
});
