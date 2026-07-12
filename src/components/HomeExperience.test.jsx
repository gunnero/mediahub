// @vitest-environment jsdom
import "@testing-library/jest-dom/vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { HomeExperience } from "./HomeExperience.jsx";

const dashboard = {
  profile: { displayName: "Gunner" },
  recentShow: { id: "recent-show-8", kind: "show", showId: 8, title: "Severance", progress: 40 },
  moviesToCheckOut: [{ id: "watchlist-42", kind: "movie", movieId: 42, title: "Arrival", runtime: 116 }],
  stats: { moviesWatched: 12, showsFollowed: 4, hoursWatched: 80 },
  timeline: {
    recent: [{ id: 1, eventType: "movie.watched", title: "Watched Arrival", subtitle: "Movie night", source: "manual", occurredAt: "2026-07-12T18:00:00Z" }],
    thisWeekSummary: { total: 1 },
  },
};

function apiFixture(overrides = {}) {
  return vi.fn(async (path, options = {}) => {
    if (path.startsWith("/api/v1/library/continue-watching")) return { items: [{ id: 81, episodeId: 81, showId: 8, kind: "episode", title: "Hello, Ms. Cobel", showTitle: "Severance", code: "S02E01", runtime: 48, poster: "/severance.jpg", backdrop: "/severance-wide.jpg", progress: 40 }] };
    if (path.startsWith("/api/v1/library/movies?sort=newest_added")) return { items: [{ id: 42, movieId: 42, kind: "movie", title: "Arrival", runtime: 116, poster: "/arrival.jpg", meta: "2016 · 116 min" }], pagination: { hasMore: false } };
    if (path.startsWith("/api/v1/library/shows?sort=newest_added")) return { items: [{ id: 8, showId: 8, kind: "show", title: "Severance", poster: "/severance.jpg", meta: "8/19 watched" }], pagination: { hasMore: false } };
    if (path.startsWith("/api/v1/calendar?")) return { items: [{ id: "episode-91", kind: "episode", episodeId: 91, showId: 8, date: new Date().toISOString().slice(0, 10), title: "Severance", subtitle: "S02E02 · Goodbye" }] };
    if (path === "/api/v1/friends") return { friends: [{ friendshipId: 2, profile: { slug: "helly", displayName: "Helly" } }] };
    if (path === "/api/v1/profiles/helly") return { content: { favoriteMovies: [{ id: 77, movieId: 77, title: "Moon", poster: "/moon.jpg" }], publicLists: [{ id: 4, name: "Quiet sci-fi", itemsCount: 7 }] } };
    if (path === "/api/v1/lists") return { lists: [{ id: 4, name: "Quiet sci-fi", itemsCount: 7, updatedAt: "2026-07-12T10:00:00Z" }] };
    if (path === "/api/v1/profile") return { profile: { favoriteMovieIds: [42], favoriteShowIds: [8], featuredListIds: [4] } };
    if (path === "/api/v1/library/episodes/81/watch" && options.method === "POST") return { watch: { id: 1 } };
    if (overrides[path]) return overrides[path];
    throw new Error(`Unexpected request: ${path}`);
  });
}

class ImmediateIntersectionObserver {
  constructor(callback) { this.callback = callback; }
  observe(target) { this.callback([{ isIntersecting: true, target }]); }
  disconnect() {}
}

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe("HomeExperience", () => {
  it("composes the daily Home from existing user-scoped data", async () => {
    vi.stubGlobal("IntersectionObserver", ImmediateIntersectionObserver);
    const apiClient = apiFixture();
    const onOpen = vi.fn();
    render(<HomeExperience apiClient={apiClient} dashboard={dashboard} onNavigate={vi.fn()} onOpen={onOpen} onRefreshDashboard={vi.fn()} />);

    expect(screen.getByRole("heading", { name: "Welcome back, Gunner" })).toBeInTheDocument();
    expect(await screen.findByRole("heading", { name: "Severance" })).toBeInTheDocument();
    expect(screen.getByText("48 min episode")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "View episode" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Resume" })).not.toBeInTheDocument();
    expect(screen.getByRole("heading", { name: "Tonight" })).toBeInTheDocument();
    expect(screen.getByText("Because it is already in your watchlist and fits a shorter evening.")).toBeInTheDocument();
    expect(screen.getByRole("heading", { name: "Recently Added" })).toBeInTheDocument();
    expect(screen.getByRole("heading", { name: "Upcoming" })).toBeInTheDocument();
    expect(await screen.findByRole("heading", { name: "Entertainment Diary" })).toBeInTheDocument();
    expect(await screen.findByText("Moon")).toBeInTheDocument();
    expect(screen.getAllByText("Quiet sci-fi").length).toBeGreaterThan(0);
    expect(screen.getByRole("heading", { name: "Pinned Lists" })).toBeInTheDocument();
    expect(screen.getByRole("heading", { name: "Quick Actions" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Calendar" })).toBeInTheDocument();
    expect(apiClient).toHaveBeenCalledWith("/api/v1/library/continue-watching?limit=3&candidate_limit=30");
    expect(apiClient.mock.calls.some(([path]) => /^\/api\/v1\/library\/shows\/\d+$/.test(path))).toBe(false);
    expect(apiClient).not.toHaveBeenCalledWith("/api/v1/stats");
    expect(screen.queryByText(/provider_url|stream_url|private watch history/i)).not.toBeInTheDocument();
  });

  it("marks the next episode finished through canonical watch history", async () => {
    const apiClient = apiFixture();
    const refresh = vi.fn();
    render(<HomeExperience apiClient={apiClient} dashboard={dashboard} onNavigate={vi.fn()} onOpen={vi.fn()} onRefreshDashboard={refresh} />);

    fireEvent.click(await screen.findByRole("button", { name: /mark finished/i }));
    await waitFor(() => expect(apiClient).toHaveBeenCalledWith("/api/v1/library/episodes/81/watch", { method: "POST" }));
    expect(refresh).toHaveBeenCalled();
  });

  it("opens History when the full diary is requested", async () => {
    vi.stubGlobal("IntersectionObserver", ImmediateIntersectionObserver);
    const apiClient = apiFixture();
    const onNavigate = vi.fn();
    render(<HomeExperience apiClient={apiClient} dashboard={dashboard} onNavigate={onNavigate} onOpen={vi.fn()} />);

    fireEvent.click(await screen.findByRole("button", { name: /view full diary/i }));
    expect(onNavigate).toHaveBeenCalledWith("history");
    expect(apiClient).not.toHaveBeenCalledWith("/api/v1/media-events/recent");
  });

  it("does not request lower Home data before its viewport boundary", async () => {
    const callbacks = [];
    class ControlledObserver {
      constructor(callback) { callbacks.push(callback); }
      observe() {}
      disconnect() {}
    }
    vi.stubGlobal("IntersectionObserver", ControlledObserver);
    const apiClient = apiFixture();
    render(<HomeExperience apiClient={apiClient} dashboard={dashboard} onNavigate={vi.fn()} onOpen={vi.fn()} />);

    await screen.findByRole("heading", { name: "Recently Added" });
    expect(apiClient).not.toHaveBeenCalledWith("/api/v1/friends");
    expect(apiClient).not.toHaveBeenCalledWith("/api/v1/lists");
    expect(apiClient).not.toHaveBeenCalledWith("/api/v1/profile");
    expect(callbacks.length).toBeGreaterThan(0);
  });

  it("gives every empty section a useful next step", async () => {
    vi.stubGlobal("IntersectionObserver", ImmediateIntersectionObserver);
    const emptyDashboard = { ...dashboard, recentShow: null, moviesToCheckOut: [], stats: {}, timeline: { recent: [] } };
    const apiClient = apiFixture({
      "/api/v1/friends": { friends: [] },
      "/api/v1/lists": { lists: [] },
      "/api/v1/profile": { profile: { favoriteMovieIds: [], favoriteShowIds: [], featuredListIds: [] } },
    });
    apiClient.mockImplementation(async (path, options = {}) => {
      if (path.startsWith("/api/v1/library/continue-watching")) return { items: [] };
      if (path.startsWith("/api/v1/library/movies?sort=newest_added")) return { items: [], pagination: {} };
      if (path.startsWith("/api/v1/library/shows?sort=newest_added")) return { items: [], pagination: {} };
      if (path.startsWith("/api/v1/calendar?")) return { items: [] };
      if (path === "/api/v1/friends") return { friends: [] };
      if (path === "/api/v1/lists") return { lists: [] };
      if (path === "/api/v1/profile") return { profile: { favoriteMovieIds: [], favoriteShowIds: [], featuredListIds: [] } };
      throw new Error(`Unexpected request: ${path} ${options.method || "GET"}`);
    });
    render(<HomeExperience apiClient={apiClient} dashboard={emptyDashboard} onNavigate={vi.fn()} onOpen={vi.fn()} />);

    expect(await screen.findByRole("heading", { name: "Nothing waiting for you" })).toBeInTheDocument();
    expect(await screen.findByText("No movies yet")).toBeInTheDocument();
    expect(screen.getByText("No shows yet")).toBeInTheDocument();
    expect(screen.getByText("Nothing scheduled this week")).toBeInTheDocument();
    expect(await screen.findByText("No lists pinned yet")).toBeInTheDocument();
    expect(screen.queryByRole("heading", { name: "From Friends" })).not.toBeInTheDocument();
  });

  it("renders multiple continuation items in a keyboard-scrollable rail", async () => {
    const apiClient = apiFixture();
    apiClient.mockImplementation(async (path) => {
      if (path.startsWith("/api/v1/library/continue-watching")) return { items: [
        { episodeId: 91, showId: 9, kind: "episode", title: "Violet", showTitle: "The Bear", code: "S03E04", runtime: 27, progress: 65, latestWatchedAt: "2026-07-13T10:00:00Z" },
        { episodeId: 81, showId: 8, kind: "episode", title: "Hello, Ms. Cobel", showTitle: "Severance", code: "S02E01", runtime: 48, progress: 40 },
      ] };
      return apiFixture()(path);
    });

    render(<HomeExperience apiClient={apiClient} dashboard={dashboard} onNavigate={vi.fn()} onOpen={vi.fn()} />);

    expect(await screen.findByRole("heading", { name: "The Bear" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Previous continue item" })).toBeInTheDocument();
    expect(screen.getByRole("region", { name: "Continue Watching items" })).toHaveAttribute("tabindex", "0");
  });
});
