// @vitest-environment jsdom
import "@testing-library/jest-dom/vitest";
import { act, cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { readFileSync } from "node:fs";
import { afterEach, describe, expect, it, vi } from "vitest";
import { AlertsSection, CalendarSection, DiscoverSection, DiscoveryDetailPage, ListsSection, StatsSection, WebSettingsSection } from "./WebV1Surfaces.jsx";
import { SessionExpiredError } from "../lib/api.js";

afterEach(() => cleanup());

describe("MediaHub Web V1 surfaces", () => {
  it("shows synchronized actions near the preview title and after its details", () => {
    const actions = <div className="modal-actions"><button className="primary-action" type="button">Add to Library</button><button className="secondary-action" type="button">Add to Watchlist</button></div>;
    render(<DiscoveryDetailPage actions={actions} onClose={vi.fn()} preview={{ media_type: "movie", title: "Heat", overview: "Crime saga.", production: { companies: ["Forward Pass"] } }} />);

    const dialog = screen.getByRole("region", { name: /heat discovery preview/i });
    const quickActions = within(dialog).getByRole("group", { name: /quick actions/i });
    const actionsAfterDetails = within(dialog).getByRole("group", { name: /actions after details/i });
    const metadata = dialog.querySelector(".discovery-preview-content > .metadata-strip");
    const plot = within(dialog).getByText("Plot").closest("section");
    const production = within(dialog).getByText("Production").closest("section");
    const contentChildren = [...dialog.querySelector(".discovery-preview-content").children];

    for (const group of [quickActions, actionsAfterDetails]) {
      expect(within(group).getByRole("button", { name: /add to library/i })).toBeInTheDocument();
      expect(within(group).getByRole("button", { name: /add to watchlist/i })).toBeInTheDocument();
    }
    expect(contentChildren.indexOf(quickActions)).toBe(contentChildren.indexOf(metadata) + 1);
    expect(contentChildren.indexOf(quickActions)).toBeLessThan(contentChildren.indexOf(plot));
    expect(contentChildren.indexOf(actionsAfterDetails)).toBeGreaterThan(contentChildren.indexOf(production));
  });

  it("discovers a movie and adds it without exposing TMDB configuration", async () => {
    const apiClient = vi.fn(async (path, options = {}) => {
      if (path.startsWith("/api/v1/discover/browse")) return { status: "ready", items: [] };
      if (path.startsWith("/api/v1/discover/search")) return { status: "ready", items: [{ media_type: "movie", tmdb_id: 949, title: "Heat", year: "1995", overview: "Crime saga.", poster: "" }] };
      if (path === "/api/v1/discover/movies/949/add" && options.method === "POST") return { item: { id: 42 } };
      throw new Error(`Unexpected request: ${path}`);
    });
    render(<DiscoverSection apiClient={apiClient} onLibraryChanged={vi.fn()} />);
    fireEvent.change(screen.getByLabelText(/search movies and shows/i), { target: { value: "heat" } });
    expect(await screen.findByText("Heat", {}, { timeout: 2000 })).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: /add to watchlist/i }));
    await waitFor(() => expect(apiClient).toHaveBeenCalledWith("/api/v1/discover/movies/949/add", { method: "POST", body: { action: "watchlist" } }));
    expect(document.body.textContent).not.toMatch(/api[_ -]?key/i);
  });

  it("opens discovery details from both artwork and title and explains library versus watchlist", async () => {
    const onOpen = vi.fn();
    const apiClient = vi.fn(async (path) => {
      if (path.startsWith("/api/v1/discover/browse")) return { status: "ready", items: [] };
      if (path.startsWith("/api/v1/discover/search")) return { status: "ready", items: [{ media_type: "movie", tmdb_id: 949, title: "Heat", overview: "Crime saga.", already_in_library: true, existing_library_id: 42, watched: true, watched_count: 2 }] };
      throw new Error(`Unexpected request: ${path}`);
    });
    render(<DiscoverSection apiClient={apiClient} onOpen={onOpen} />);
    fireEvent.change(screen.getByLabelText(/search movies and shows/i), { target: { value: "heat" } });

    const title = await screen.findByRole("button", { name: "Open Heat details" });
    expect(screen.getByText("Watched 2 times")).toBeInTheDocument();
    fireEvent.click(title);
    expect(onOpen).toHaveBeenCalledWith(expect.objectContaining({ movieId: 42 }));
    expect(screen.getByText((_, element) => element?.classList.contains("discovery-action-help") && element.textContent.includes("keeps a title"))).toBeInTheDocument();
  });

  it("loads complete cast, plot, runtime, and production for a discovery title", async () => {
    const apiClient = vi.fn(async (path) => {
      if (path.startsWith("/api/v1/discover/browse")) return { status: "ready", items: [{ media_type: "movie", tmdb_id: 949, title: "Heat", overview: "Search overview.", already_in_library: false }] };
      if (path === "/api/v1/discover/movie/949") return { status: "ready", item: { media_type: "movie", tmdb_id: 949, title: "Heat", runtime: 170, status: "Released", overview: "Complete crime saga plot.", people: { cast: [{ id: 1, name: "Lead Actor", role: "Detective", image: "" }], directors: [{ id: 2, name: "Film Director", role: "Director", image: "" }] }, production: { companies: ["Forward Pass"], countries: ["United States"], languages: ["English"] }, already_in_library: false } };
      throw new Error(`Unexpected request: ${path}`);
    });
    render(<DiscoverSection apiClient={apiClient} />);

    fireEvent.click(await screen.findByRole("button", { name: "Open Heat details" }));

    expect(await screen.findByText("Lead Actor")).toBeInTheDocument();
    expect(screen.getByText("Film Director")).toBeInTheDocument();
    expect(screen.getByText("Complete crime saga plot.")).toBeInTheDocument();
    expect(screen.getByText("170 min")).toBeInTheDocument();
    expect(screen.getByText("Forward Pass")).toBeInTheDocument();
    expect(screen.getByText("Production").closest(".detail-section-heading")).toBeInTheDocument();
  });

  it("uses a full page with a focused title and explicit Back navigation", () => {
    const onClose = vi.fn();
    render(<DiscoveryDetailPage onClose={onClose} preview={{ media_type: "movie", tmdb_id: 949, title: "Heat" }} />);
    expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
    expect(screen.getByRole("heading", { level: 1, name: "Heat" })).toHaveFocus();
    fireEvent.keyDown(document, { key: "Escape" });
    expect(onClose).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole("button", { name: "Back to results" }));
    expect(onClose).toHaveBeenCalledOnce();
  });

  it("prevents iOS form focus from carrying a zoomed viewport into media details", () => {
    const css = readFileSync(`${process.cwd()}/src/styles.css`, "utf8");
    const html = readFileSync(`${process.cwd()}/index.html`, "utf8");
    const mobileZoomGuard = css.slice(css.indexOf("/* Prevent iOS form-focus zoom"));

    expect(mobileZoomGuard).toContain('@media (max-width: 1024px)');
    expect(mobileZoomGuard).toMatch(/\.app-shell input:not\(\[type="checkbox"\]\):not\(\[type="radio"\]\),[\s\S]*font-size:\s*16px;/);
    expect(mobileZoomGuard).toMatch(/button,[\s\S]*\[role="button"\]\s*\{[^}]*touch-action:\s*manipulation;/);
    expect(mobileZoomGuard).toMatch(/\.cinematic-episode > span:nth-child\(2\)[\s\S]*min-width:\s*0;[\s\S]*max-width:\s*100%;/);
    expect(html).toContain('content="width=device-width, initial-scale=1.0"');
    expect(html).not.toMatch(/maximum-scale|user-scalable/i);
  });

  it("browses trending, popular, now playing, upcoming, and top rated without a hero", async () => {
    const apiClient = vi.fn(async (path) => ({
      status: "ready",
      category: new URLSearchParams(path.split("?")[1]).get("category"),
      items: [{ media_type: "show", tmdb_id: 1, title: "Current Show", overview: "Public metadata." }],
    }));
    const { container } = render(<DiscoverSection apiClient={apiClient} />);

    expect(await screen.findByText("Current Show")).toBeInTheDocument();
    expect(container.querySelector(".hero-panel")).not.toBeInTheDocument();
    for (const label of ["Trending", "Popular", "Now Playing", "Upcoming", "Top Rated"]) {
      expect(screen.getByRole("tab", { name: label })).toBeInTheDocument();
    }
    fireEvent.click(screen.getByRole("tab", { name: "Upcoming" }));
    await waitFor(() => expect(apiClient).toHaveBeenCalledWith(expect.stringContaining("category=upcoming"), expect.objectContaining({ signal: expect.any(AbortSignal) })));
  });

  it("renders the release calendar and opens an episode", async () => {
    const onOpen = vi.fn();
    const today = new Date();
    const key = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, "0")}-${String(today.getDate()).padStart(2, "0")}`;
    const apiClient = vi.fn().mockResolvedValue({ items: [{ id: "episode-1" }], days: { [key]: [{ id: "episode-1", kind: "episode", episodeId: 1, showId: 2, title: "Severance", subtitle: "S02E03 · Return", date: key }] } });
    render(<CalendarSection apiClient={apiClient} onOpen={onOpen} />);
    expect(await screen.findByText("Severance")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: /severance/i }));
    expect(onOpen).toHaveBeenCalledWith(expect.objectContaining({ episodeId: 1 }));
  });

  it("shows one calendar empty state without a grid of empty days", async () => {
    const apiClient = vi.fn().mockResolvedValue({ items: [], days: {}, range: { timezone: "UTC" } });
    const { container } = render(<CalendarSection apiClient={apiClient} />);
    const emptyState = await screen.findByText(/no releases are scheduled here yet/i);
    const grid = container.querySelector(".calendar-grid");

    expect(emptyState).toBeVisible();
    expect(grid).not.toBeInTheDocument();
  });

  it("renders database-backed stats", async () => {
    const apiClient = vi.fn().mockResolvedValue({ summary: { moviesWatched: 12, episodesWatched: 80, showsCompleted: 3, totalWatchHours: 71.5, rewatchCount: 2, longestStreakDays: 5 }, monthlyActivity: [], yearlyActivity: [{ period: "2026", watches: 92, minutes: 4290 }], genres: [{ genre: "Drama", count: 8 }], ratings: [{ rating: 9, count: 4 }], topShows: [], topMovies: [] });
    render(<StatsSection apiClient={apiClient} />);
    expect((await screen.findAllByText("71.5")).length).toBeGreaterThan(0);
    expect(screen.getByText("Drama")).toBeInTheDocument();
    expect(screen.getAllByText("2026").length).toBeGreaterThan(0);
    expect(screen.getByText("9/10")).toBeInTheDocument();
  });

  it("creates a private list", async () => {
    let lists = [];
    const apiClient = vi.fn(async (path, options = {}) => {
      if (path === "/api/v1/lists" && !options.method) return { lists };
      if (path === "/api/v1/lists" && options.method === "POST") { lists = [{ id: 1, name: options.body.name, visibility: "private", itemsCount: 0, items: [] }]; return { list: lists[0] }; }
      if (path === "/api/v1/lists/1" && options.method === "PATCH") { lists = [{ ...lists[0], name: options.body.name }]; return { list: lists[0] }; }
      throw new Error(`Unexpected request: ${path}`);
    });
    render(<ListsSection apiClient={apiClient} />);
    await waitFor(() => expect(screen.getByRole("button", { name: "Create list", exact: true })).toBeEnabled());
    fireEvent.change(screen.getByLabelText(/new list name/i), { target: { value: "Favorites" } });
    fireEvent.click(screen.getByRole("button", { name: /create list/i }));
    expect((await screen.findAllByText("Favorites")).length).toBeGreaterThan(0);
    expect(screen.getAllByText(/private/i).length).toBeGreaterThan(0);
    fireEvent.click(screen.getByRole("button", { name: /rename list/i }));
    fireEvent.change(screen.getByRole("textbox", { name: /rename list/i }), { target: { value: "Best thrillers" } });
    fireEvent.click(screen.getByRole("button", { name: /^save$/i }));
    expect((await screen.findAllByText("Best thrillers")).length).toBeGreaterThan(0);
  });

  it("renders useful alerts and marks one read", async () => {
    let unread = true;
    const onAlertsChanged = vi.fn();
    const apiClient = vi.fn(async (path, options = {}) => {
      if (path === "/api/v1/alerts") return { alerts: [{ id: 1, category: "upcoming", title: "Episode coming soon", subtitle: "Show · S01E02", dueText: "In 2 days", unread }], unread: unread ? 1 : 0 };
      if (path === "/api/v1/alerts/1/read" && options.method === "POST") { unread = false; return { alert: { id: 1, unread: false } }; }
      if (path === "/api/v1/alerts/read-all" && options.method === "POST") { unread = false; return { read: 0 }; }
      throw new Error(`Unexpected request: ${path}`);
    });
    render(<AlertsSection apiClient={apiClient} onAlertsChanged={onAlertsChanged} />);
    expect(await screen.findByText("Episode coming soon")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: /mark episode coming soon read/i }));
    await waitFor(() => expect(apiClient).toHaveBeenCalledWith("/api/v1/alerts/1/read", { method: "POST" }));
    expect(onAlertsChanged).toHaveBeenCalledTimes(1);

    fireEvent.click(screen.getByRole("button", { name: /mark all read/i }));
    await waitFor(() => expect(apiClient).toHaveBeenCalledWith("/api/v1/alerts/read-all", { method: "POST" }));
    expect(onAlertsChanged).toHaveBeenCalledTimes(2);
  });

  it("shows upcoming movie alerts in the Upcoming tab", async () => {
    const apiClient = vi.fn().mockResolvedValue({ alerts: [
      { id: 1, category: "movies", title: "Released movie", payload: { alert_type: "watchlist_release" }, unread: false },
      { id: 2, category: "upcoming", title: "Upcoming movie release", payload: { alert_type: "upcoming_movie" }, unread: true },
    ] });
    render(<AlertsSection apiClient={apiClient} />);
    await screen.findByText("Upcoming movie release");
    fireEvent.click(screen.getByRole("button", { name: "Upcoming" }));
    expect(screen.getByText("Upcoming movie release")).toBeInTheDocument();
    expect(screen.queryByText("Released movie")).not.toBeInTheDocument();
  });

  it("shows final web settings without provider setup", async () => {
    const apiClient = vi.fn(async (path) => {
      if (path === "/api/v1/settings") return { profile: { name: "Gunner", email: "member@example.test", role: "member" }, metadata: { movies: { enriched: 3, total: 4 }, shows: { enriched: 2, total: 2 }, episodes: { enriched: 10, total: 12 } }, import: {}, export: { csvDatasets: ["movies"] }, version: "1.0.0" };
      if (path === "/api/v1/notification-preferences") return { preferences: { newEpisodes: true, movieReleases: true, reminders: true, inAppEnabled: true, emailEnabled: false } };
      throw new Error(`Unexpected request: ${path}`);
    });
    render(<WebSettingsSection apiClient={apiClient} />);
    expect(await screen.findByText("Gunner")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /^providers$/i })).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: /import & export/i }));
    expect(screen.getByRole("link", { name: /download full json/i })).toHaveAttribute("href", "/api/v1/exports/json");
  });
});

function deferred() {
  let resolve; let reject;
  const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
  return { promise, resolve, reject };
}

const discoverMovie = { media_type: "movie", tmdb_id: 7, title: "First story" };
const discoverShow = { media_type: "show", tmdb_id: 7, title: "Second story" };

describe("Discovery reliability", () => {
  it("paginates browse and search, resets filters to page one, and stops at the last page", async () => {
    const apiClient = vi.fn(async (path) => ({ status: "ready", items: [discoverMovie], pagination: { totalPages: 2 } }));
    render(<DiscoverSection apiClient={apiClient} />);
    await screen.findByText("First story");
    expect(screen.getByRole("button", { name: "Previous page" })).toBeDisabled();
    fireEvent.click(screen.getByRole("button", { name: "Next page" }));
    await waitFor(() => expect(apiClient).toHaveBeenCalledWith(expect.stringContaining("page=2"), expect.any(Object)));
    expect(screen.getByText("Page 2 of 2")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Next page" })).toBeDisabled();
    fireEvent.change(screen.getByLabelText("Media type"), { target: { value: "show" } });
    await waitFor(() => expect(apiClient).toHaveBeenCalledWith(expect.stringContaining("type=show&page=1"), expect.any(Object)));
    fireEvent.change(screen.getByLabelText("Search movies and shows"), { target: { value: "story" } });
    await waitFor(() => expect(apiClient).toHaveBeenCalledWith(expect.stringContaining("discover/search?query=story&type=show&page=1"), expect.any(Object)));
    fireEvent.click(screen.getByRole("button", { name: "Next page" }));
    await waitFor(() => expect(apiClient).toHaveBeenCalledWith(expect.stringContaining("discover/search?query=story&type=show&page=2"), expect.any(Object)));
  });

  it("ignores an old search response even when the client ignores cancellation", async () => {
    const old = deferred();
    const apiClient = vi.fn((path) => path.includes("category=trending") ? old.promise : Promise.resolve({ items: [discoverShow] }));
    render(<DiscoverSection apiClient={apiClient} />);
    await waitFor(() => expect(apiClient).toHaveBeenCalled());
    fireEvent.click(screen.getByRole("tab", { name: "Upcoming" }));
    await screen.findByText("Second story");
    await act(async () => old.resolve({ items: [discoverMovie] }));
    expect(screen.queryByText("First story")).not.toBeInTheDocument();
  });

  it.each(["success", "failure", "expired"])("ignores late preview %s for a different media type with the same TMDB ID", async (outcome) => {
    const old = deferred(); const current = deferred(); const expired = vi.fn(); let firstSignal;
    const apiClient = vi.fn((path, options) => {
      if (path.includes("/browse?")) return Promise.resolve({ items: [discoverMovie, discoverShow] });
      if (path.endsWith("/movie/7")) { firstSignal = options.signal; return old.promise; }
      return current.promise;
    });
    render(<DiscoverSection apiClient={apiClient} onSessionExpired={expired} />);
    const first = await screen.findByRole("button", { name: "Open First story details" });
    first.focus();
    fireEvent.click(first);
    fireEvent.click(screen.getByRole("button", { name: "Back to results" }));
    expect(screen.queryByRole("region")).not.toBeInTheDocument();
    expect(first).toHaveFocus();
    expect(firstSignal.aborted).toBe(true);
    fireEvent.click(screen.getByRole("button", { name: "Open Second story details" }));
    await act(async () => outcome === "success" ? old.resolve({ item: { ...discoverMovie, overview: "Wrong plot" } }) : old.reject(outcome === "expired" ? new SessionExpiredError() : new Error("Old failure")));
    expect(screen.getByRole("region")).toHaveAccessibleName("Second story discovery preview");
    expect(screen.getByText("Loading complete details...")).toBeInTheDocument();
    expect(screen.queryByText("Wrong plot")).not.toBeInTheDocument();
    expect(expired).not.toHaveBeenCalled();
    await act(async () => current.resolve({ item: { ...discoverShow, overview: "Correct plot" } }));
    expect(screen.getByText("Correct plot")).toBeInTheDocument();
  });

  it("shows unavailable discovery as an error and allows retry", async () => {
    const apiClient = vi.fn().mockResolvedValueOnce({ status: "unavailable", items: [] }).mockResolvedValue({ items: [discoverMovie] });
    render(<DiscoverSection apiClient={apiClient} />);
    expect(await screen.findByRole("alert")).toHaveTextContent("temporarily unavailable");
    expect(screen.queryByText("No titles are available in this category right now")).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Retry search" }));
    await screen.findByText("First story");
  });
});

describe("Mutation feedback and retries", () => {
  it("prevents duplicate list creation, preserves a failed draft, and retries the save", async () => {
    const pending = deferred(); let writes = 0; let lists = [];
    const apiClient = vi.fn(async (path, options = {}) => {
      if (options.method === "POST") {
        writes += 1;
        if (writes === 1) return pending.promise;
        lists = [{ id: 1, name: options.body.name, items: [], itemsCount: 0 }];
        return { list: lists[0] };
      }
      return { lists };
    });
    render(<ListsSection apiClient={apiClient} />);
    await waitFor(() => expect(screen.getByRole("button", { name: "Create list", exact: true })).toBeEnabled());
    fireEvent.change(screen.getByLabelText("New list name"), { target: { value: "Weekend" } });
    const create = screen.getByRole("button", { name: "Create list" });
    fireEvent.click(create); fireEvent.click(create);
    expect(writes).toBe(1);
    expect(create).toBeDisabled();
    await act(async () => pending.reject(new Error("Save failed")));
    expect(screen.getByRole("alert")).toHaveTextContent("Save failed");
    expect(screen.getByLabelText("New list name")).toHaveValue("Weekend");
    fireEvent.click(screen.getByRole("button", { name: "Try again" }));
    await screen.findByRole("heading", { name: "Weekend" });
    expect(writes).toBe(2);
  });

  it("retries only the read after list creation succeeds but refresh fails", async () => {
    let created = false; let failRefresh = true; let writes = 0;
    const apiClient = vi.fn(async (path, options = {}) => {
      if (options.method === "POST") { created = true; writes += 1; return { list: { id: 1 } }; }
      if (created && failRefresh) throw new Error("Read failed");
      return { lists: created ? [{ id: 1, name: "Weekend", items: [], itemsCount: 0 }] : [] };
    });
    render(<ListsSection apiClient={apiClient} />);
    await waitFor(() => expect(screen.getByRole("button", { name: "Create list", exact: true })).toBeEnabled());
    fireEvent.change(screen.getByLabelText("New list name"), { target: { value: "Weekend" } });
    fireEvent.click(screen.getByRole("button", { name: "Create list" }));
    expect(await screen.findByRole("alert")).toHaveTextContent("Saved, but the view could not refresh");
    failRefresh = false;
    fireEvent.click(screen.getByRole("button", { name: "Retry refresh" }));
    await screen.findByRole("heading", { name: "Weekend" });
    expect(writes).toBe(1);
  });

  it("reports alert failures, blocks duplicate writes, and refreshes the badge after retry", async () => {
    const pending = deferred(); let writes = 0; const onAlertsChanged = vi.fn();
    const apiClient = vi.fn(async (path, options = {}) => {
      if (!options.method) return { alerts: [{ id: 1, title: "New episode", unread: writes < 2 }] };
      writes += 1; if (writes === 1) return pending.promise; return {};
    });
    render(<AlertsSection apiClient={apiClient} onAlertsChanged={onAlertsChanged} />);
    const button = await screen.findByRole("button", { name: "Mark New episode read" });
    fireEvent.click(button); fireEvent.click(button);
    expect(writes).toBe(1);
    await act(async () => pending.reject(new Error("Could not mark read")));
    expect(await screen.findByRole("alert")).toHaveTextContent("Could not mark read");
    fireEvent.click(screen.getByRole("button", { name: "Try again" }));
    await waitFor(() => expect(onAlertsChanged).toHaveBeenCalledTimes(1));
    expect(screen.queryByRole("button", { name: "Mark New episode read" })).not.toBeInTheDocument();
  });

  it("keeps a failed preference unchanged, then saves without duplicate patches", async () => {
    let preferences = { reminders: true }; let writes = 0; const pending = deferred();
    const apiClient = vi.fn(async (path, options = {}) => {
      if (options.method === "PATCH") { writes += 1; if (writes === 1) return pending.promise; preferences = { reminders: false }; }
      return path.endsWith("settings") ? {} : { preferences };
    });
    render(<WebSettingsSection initialSection="notifications" apiClient={apiClient} />);
    const checkbox = screen.getByRole("checkbox", { name: "Unfinished show reminders" });
    await waitFor(() => expect(checkbox).toBeChecked());
    fireEvent.click(checkbox); fireEvent.click(checkbox);
    expect(writes).toBe(1);
    expect(checkbox).toBeDisabled();
    await act(async () => pending.reject(new Error("Preferences unavailable")));
    expect(checkbox).toBeChecked();
    expect(screen.getByRole("alert")).toHaveTextContent("Preferences unavailable");
    fireEvent.click(screen.getByRole("button", { name: "Try again" }));
    await waitFor(() => expect(checkbox).not.toBeChecked());
    expect(writes).toBe(2);
  });

  it("handles expired sessions during a list mutation", async () => {
    const expired = vi.fn();
    const apiClient = vi.fn(async (path, options = {}) => { if (options.method) throw new SessionExpiredError(); return { lists: [] }; });
    render(<ListsSection apiClient={apiClient} onSessionExpired={expired} />);
    await waitFor(() => expect(screen.getByRole("button", { name: "Create list", exact: true })).toBeEnabled());
    fireEvent.change(screen.getByLabelText("New list name"), { target: { value: "Weekend" } });
    fireEvent.click(screen.getByRole("button", { name: "Create list" }));
    await waitFor(() => expect(expired).toHaveBeenCalledTimes(1));
    expect(screen.queryByText("Saving…")).not.toBeInTheDocument();
  });
});
