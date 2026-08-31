// @vitest-environment jsdom
import "@testing-library/jest-dom/vitest";
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { readFileSync } from "node:fs";
import { afterEach, describe, expect, it, vi } from "vitest";
import { AlertsSection, CalendarSection, DiscoverSection, DiscoveryPreviewModal, ListsSection, StatsSection, WebSettingsSection } from "./WebV1Surfaces.jsx";

afterEach(() => cleanup());

describe("MediaHub Web V1 surfaces", () => {
  it("shows synchronized actions near the preview title and after its details", () => {
    const actions = <div className="modal-actions"><button className="primary-action" type="button">Add to Library</button><button className="secondary-action" type="button">Add to Watchlist</button></div>;
    render(<DiscoveryPreviewModal actions={actions} onClose={vi.fn()} preview={{ media_type: "movie", title: "Heat", overview: "Crime saga.", production: { companies: ["Forward Pass"] } }} />);

    const dialog = screen.getByRole("dialog", { name: /heat discovery preview/i });
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

  it("keeps discovery preview artwork in its column so details stay visible", () => {
    const css = readFileSync(`${process.cwd()}/src/styles.css`, "utf8");
    const mobileBlockStart = css.lastIndexOf("@media (max-width: 820px)");
    const mobileBlockOpen = css.indexOf("{", mobileBlockStart);
    let mobileBlockEnd = mobileBlockOpen;
    let mobileBlockDepth = 0;
    for (; mobileBlockEnd < css.length; mobileBlockEnd += 1) {
      if (css[mobileBlockEnd] === "{") mobileBlockDepth += 1;
      if (css[mobileBlockEnd] === "}") mobileBlockDepth -= 1;
      if (mobileBlockDepth === 0 && mobileBlockEnd > mobileBlockOpen) break;
    }
    const finalMobileBlock = css.slice(mobileBlockOpen + 1, mobileBlockEnd);

    expect(css).toMatch(/\.discovery-preview-expanded\s*\{[^}]*overflow-y:\s*hidden/s);
    expect(css).toMatch(/\.discovery-preview \.modal-close\s*\{[^}]*position:\s*absolute/s);
    expect(css).toMatch(/\.discovery-preview-art\s*\{[^}]*grid-column:\s*1;[^}]*grid-row:\s*1;/s);
    expect(css).toMatch(/\.discovery-preview-content\s*\{[^}]*grid-column:\s*2;[^}]*grid-row:\s*1;/s);
    expect(css).toMatch(/\.discovery-preview-content\s*\{[^}]*max-height:/s);
    expect(css).toMatch(/\.discovery-preview-content\s*\{[^}]*overflow-y:\s*auto/s);
    expect(css).toMatch(/\.discovery-preview-art\s*\{[^}]*max-height:/s);
    expect(css).toMatch(/\.discovery-preview-expanded\s+\.discovery-preview-art\s*\{[^}]*position:\s*sticky/s);
    expect(css).toMatch(/@media \(max-width:\s*560px\)[\s\S]*\.discovery-preview-expanded\s*\{[^}]*grid-template-rows:\s*max-content max-content/s);
    expect(css).toMatch(/@media \(max-width:\s*560px\)[\s\S]*\.discovery-preview-expanded \.discovery-preview-art\s*\{[^}]*position:\s*relative/s);
    expect(css).toMatch(/\.discovery-preview \.production-section > \.metadata-strip\s*\{[^}]*margin:\s*0 0 22px/s);
    expect(css).toMatch(/\.discovery-preview-actions \.modal-actions\s*\{[^}]*grid-template-columns:\s*repeat\(2, minmax\(0, 1fr\)\)/s);
    expect(css).toMatch(/\.discovery-preview-actions \.modal-actions > \.text-action\s*\{[^}]*grid-column:\s*1 \/ -1/s);
    expect(css).toMatch(/@media \(max-width:\s*359px\)[\s\S]*\.discovery-preview-actions \.modal-actions\s*\{[^}]*grid-template-columns:\s*minmax\(0, 1fr\)/s);
    expect(finalMobileBlock).toMatch(/\.discovery-preview \.discovery-detail-section \.people-grid\s*\{[^}]*grid-template-columns:\s*minmax\(0, 1fr\)/s);
    expect(finalMobileBlock).toMatch(/\.discovery-preview \.people-grid article\s*\{[^}]*width:\s*100%;[^}]*min-height:\s*48px/s);
    expect(css).not.toMatch(/\.discovery-preview-expanded\s*\{[^}]*width:\s*calc\(100vw - 20px\)/s);
    expect(css).not.toMatch(/\.discovery-preview-expanded\s*\{[^}]*max-height:\s*calc\(100vh - 20px\)/s);
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

  it("shows the calendar empty state before the month grid", async () => {
    const apiClient = vi.fn().mockResolvedValue({ items: [], days: {}, range: { timezone: "UTC" } });
    const { container } = render(<CalendarSection apiClient={apiClient} />);
    const emptyState = await screen.findByText(/no releases are scheduled here yet/i);
    const grid = container.querySelector(".calendar-grid");

    expect(grid).toBeInTheDocument();
    expect(emptyState.compareDocumentPosition(grid) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
  });

  it("renders database-backed stats", async () => {
    const apiClient = vi.fn().mockResolvedValue({ summary: { moviesWatched: 12, episodesWatched: 80, showsCompleted: 3, totalWatchHours: 71.5, rewatchCount: 2, longestStreakDays: 5 }, monthlyActivity: [], yearlyActivity: [{ period: "2026", watches: 92, minutes: 4290 }], genres: [{ genre: "Drama", count: 8 }], ratings: [{ rating: 9, count: 4 }], topShows: [], topMovies: [] });
    render(<StatsSection apiClient={apiClient} />);
    expect(await screen.findByText("71.5")).toBeInTheDocument();
    expect(screen.getByText("Drama")).toBeInTheDocument();
    expect(screen.getByText("2026")).toBeInTheDocument();
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
