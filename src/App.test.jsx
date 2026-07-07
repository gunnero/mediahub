// @vitest-environment jsdom
import "@testing-library/jest-dom/vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import {
  DetailModal,
  GlobalSearchPanel,
  HistorySection,
  MovieLibrary,
  PlayerSection,
  ShowLibrary,
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
});

describe("DetailModal", () => {
  it("renders media detail, rating, notes, history, and provider status", () => {
    renderDetail();

    expect(screen.getByRole("dialog", { name: /heat details/i })).toBeInTheDocument();
    expect(screen.getByText("Private memory")).toBeInTheDocument();
    expect(screen.getByText("Entertainment diary")).toBeInTheDocument();
    expect(screen.getByText("Watched Heat")).toBeInTheDocument();
    expect(screen.getByText("9/10")).toBeInTheDocument();
    expect(screen.getByText("Linked to your source")).toBeInTheDocument();
    expect(screen.getByText(/manual entry/i)).toBeInTheDocument();
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

    expect(container.querySelector(".modal-art")).toHaveAttribute("src", "https://image.tmdb.org/t/p/w500/heat-poster.jpg");
    expect(screen.getByText("Crime")).toBeInTheDocument();
    expect(screen.getByText("Drama")).toBeInTheDocument();
    expect(screen.getByText("1995")).toBeInTheDocument();
    expect(screen.getByText("TMDB #949")).toBeInTheDocument();
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
                latestWatchedAt: "2026-07-01T12:00:00Z",
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
        ],
      },
      onOpenEpisode,
    });

    expect(screen.getByText("Season 1")).toBeInTheDocument();
    expect(screen.getByText("1/2 watched")).toBeInTheDocument();
    expect(screen.getByText("Good News About Hell")).toBeInTheDocument();
    expect(screen.getByText("Half Loop")).toBeInTheDocument();

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

    fireEvent.click(screen.getByRole("button", { name: "8" }));
    expect(props.onSaveRating).toHaveBeenCalledWith(movieDetail, 8);

    fireEvent.click(screen.getByRole("button", { name: /clear rating/i }));
    expect(props.onClearRating).toHaveBeenCalledWith(movieDetail);
  });

  it("saves and deletes private notes", () => {
    const props = renderDetail();
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

  it("triggers manual watched and unwatched actions", () => {
    const unwatchedDetail = { ...movieDetail, watched: false, watchHistory: [] };
    const unwatchedProps = renderDetail({ detail: unwatchedDetail });

    fireEvent.click(screen.getByRole("button", { name: /add to watch history/i }));
    expect(unwatchedProps.onMarkWatched).toHaveBeenCalledWith(unwatchedDetail);

    unwatchedProps.unmount();

    const watchedProps = renderDetail();
    fireEvent.click(screen.getByRole("button", { name: /remove manual watch/i }));
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

const playerPayload = {
  enabled: true,
  emptyState: null,
  sourceItems: [
    {
      id: 10,
      sourceId: 3,
      sourceName: "NAS",
      sourceStatus: "active",
      kind: "movie",
      title: "Heat Source",
      status: "available",
      linked: false,
      link: null,
    },
    {
      id: 12,
      sourceId: 3,
      sourceName: "NAS",
      sourceStatus: "active",
      kind: "movie",
      title: "Linked Heat Source",
      status: "available",
      linked: true,
      link: { id: 22, movieId: 42, canonicalTitle: "Heat" },
    },
  ],
  linkedItems: [{ id: 12 }],
  unlinkedItems: [{ id: 10 }],
  continueWatching: [],
};

function renderPlayer({ apiClient = vi.fn(), player = playerPayload, onRefreshDashboard = vi.fn() } = {}) {
  const utils = render(
    <PlayerSection
      apiClient={apiClient}
      onRefreshDashboard={onRefreshDashboard}
      player={player}
    />,
  );

  return { apiClient, onRefreshDashboard, ...utils };
}

describe("PlayerSection", () => {
  it("renders provider attach form and creates a user-owned provider", async () => {
    const apiClient = vi.fn()
      .mockResolvedValueOnce({ sources: [] })
      .mockResolvedValueOnce({ items: [] })
      .mockResolvedValueOnce({ source: { id: 7, name: "My NAS", providerType: "manual", status: "active", itemsCount: 0 } })
      .mockResolvedValueOnce({ sources: [{ id: 7, name: "My NAS", providerType: "manual", status: "active", itemsCount: 0 }] })
      .mockResolvedValueOnce({ items: [] });

    renderPlayer({ apiClient, player: { ...playerPayload, enabled: false, sourceItems: [] } });

    fireEvent.change(screen.getByLabelText(/source name/i), { target: { value: "My NAS" } });
    fireEvent.click(screen.getByLabelText(/this is my private source/i));
    fireEvent.click(screen.getByRole("button", { name: /attach source/i }));

    await waitFor(() => {
      expect(apiClient).toHaveBeenCalledWith("/api/v1/player/sources", {
        method: "POST",
        body: {
          name: "My NAS",
          provider_type: "manual",
          legal_confirmed: true,
        },
      });
    });
  });

  it("renders provider items and creates a manual source item without exposing URLs in the list", async () => {
    const apiClient = vi.fn()
      .mockResolvedValueOnce({ sources: [{ id: 3, name: "NAS", providerType: "manual", status: "active", itemsCount: 1 }] })
      .mockResolvedValueOnce({ items: playerPayload.sourceItems })
      .mockResolvedValueOnce({ item: { ...playerPayload.sourceItems[0], id: 11, title: "Private File" } })
      .mockResolvedValueOnce({ sources: [{ id: 3, name: "NAS", providerType: "manual", status: "active", itemsCount: 2 }] })
      .mockResolvedValueOnce({ items: [{ ...playerPayload.sourceItems[0], id: 11, title: "Private File" }] });

    renderPlayer({ apiClient });

    expect(await screen.findByText("Heat Source")).toBeInTheDocument();
    expect(screen.getByRole("heading", { name: /linked to library/i })).toBeInTheDocument();
    expect(screen.getByRole("heading", { name: /needs linking/i })).toBeInTheDocument();
    expect(screen.getByText(/linking connects playback to permanent watch history/i)).toBeInTheDocument();
    expect(screen.queryByText(/private.example.test/i)).not.toBeInTheDocument();

    fireEvent.change(screen.getByLabelText(/item title/i), { target: { value: "Private File" } });
    fireEvent.change(screen.getByLabelText(/stream or file URL/i), { target: { value: "https://private.example.test/movie.m3u8" } });
    fireEvent.click(screen.getByRole("button", { name: /add source item/i }));

    await waitFor(() => {
      expect(apiClient).toHaveBeenCalledWith("/api/v1/player/sources/3/items", {
        method: "POST",
        body: {
          title: "Private File",
          kind: "movie",
          stream_url: "https://private.example.test/movie.m3u8",
        },
      });
    });
  });

  it("links and unlinks a source item with manual confirmation", async () => {
    const apiClient = vi.fn()
      .mockResolvedValueOnce({ sources: [{ id: 3, name: "NAS", providerType: "manual", status: "active", itemsCount: 1 }] })
      .mockResolvedValueOnce({ items: playerPayload.sourceItems })
      .mockResolvedValueOnce({ targets: [{ type: "movie", id: 42, title: "Heat", subtitle: "Movie", meta: "170 min" }] })
      .mockResolvedValueOnce({ link: { id: 9, movie_id: 42 } })
      .mockResolvedValueOnce({ sources: [{ id: 3, name: "NAS", providerType: "manual", status: "active", itemsCount: 1 }] })
      .mockResolvedValueOnce({ items: [{ ...playerPayload.sourceItems[0], linked: true, link: { id: 9, movieId: 42, canonicalTitle: "Heat" } }] })
      .mockResolvedValueOnce(null)
      .mockResolvedValueOnce({ sources: [{ id: 3, name: "NAS", providerType: "manual", status: "active", itemsCount: 1 }] })
      .mockResolvedValueOnce({ items: playerPayload.sourceItems });

    renderPlayer({ apiClient });

    fireEvent.click(await screen.findByRole("button", { name: /link heat source/i }));
    fireEvent.change(screen.getByLabelText(/search your library/i), { target: { value: "Heat" } });
    fireEvent.click(screen.getByRole("button", { name: /search library/i }));

    await screen.findByRole("button", { name: /heat movie 170 min/i });
    fireEvent.click(screen.getByRole("button", { name: /heat movie 170 min/i }));
    fireEvent.click(screen.getByLabelText(/i confirm this source item matches/i));
    fireEvent.click(screen.getByRole("button", { name: /^link item$/i }));

    await waitFor(() => {
      expect(apiClient).toHaveBeenCalledWith("/api/v1/player/items/10/link", {
        method: "POST",
        body: {
          movie_id: 42,
          confirm: true,
        },
      });
    });

    await waitFor(() => expect(screen.getByRole("button", { name: /unlink heat source/i })).toBeInTheDocument());
    fireEvent.click(screen.getByRole("button", { name: /unlink heat source/i }));

    await waitFor(() => {
      expect(apiClient).toHaveBeenCalledWith("/api/v1/player/items/10/link", { method: "DELETE" });
    });
  });

  it("starts playback and saves progress for an unlinked source item", async () => {
    const apiClient = vi.fn()
      .mockResolvedValueOnce({ sources: [{ id: 3, name: "NAS", providerType: "manual", status: "active", itemsCount: 1 }] })
      .mockResolvedValueOnce({ items: playerPayload.sourceItems })
      .mockResolvedValueOnce({ session: { id: 99, sourceItemId: 10, status: "playing" }, playbackUrl: "https://private.example.test/movie.m3u8" })
      .mockResolvedValueOnce({ session: { id: 99, status: "playing", positionSeconds: 120, durationSeconds: 600 } })
      .mockResolvedValueOnce({ session: { id: 99, status: "completed", positionSeconds: 600, durationSeconds: 600 } });

    renderPlayer({ apiClient });

    fireEvent.click(await screen.findByRole("button", { name: /play heat source/i }));

    expect(await screen.findByText(/progress is saved only to this private source until linked/i)).toBeInTheDocument();
    expect(screen.getByTestId("provider-video")).toHaveAttribute("src", "https://private.example.test/movie.m3u8");

    fireEvent.change(screen.getByLabelText(/position seconds/i), { target: { value: "120" } });
    fireEvent.change(screen.getByLabelText(/duration seconds/i), { target: { value: "600" } });
    fireEvent.click(screen.getByRole("button", { name: /save progress/i }));

    await waitFor(() => {
      expect(apiClient).toHaveBeenCalledWith("/api/v1/player/sessions/99", {
        method: "PATCH",
        body: {
          position_seconds: 120,
          duration_seconds: 600,
          completed: false,
        },
      });
    });

    fireEvent.click(screen.getByRole("button", { name: /mark complete/i }));

    await waitFor(() => {
      expect(apiClient).toHaveBeenCalledWith("/api/v1/player/sessions/99", {
        method: "PATCH",
        body: {
          position_seconds: 120,
          duration_seconds: 600,
          completed: true,
        },
      });
    });
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
      watchedEpisodes: 2,
      airedEpisodes: 9,
    },
  ],
  pagination: { page: 1, perPage: 24, total: 1, hasMore: false },
};

describe("Library browser", () => {
  it("renders a searchable movie browser with ratings, notes, watched state, and safe metadata", async () => {
    const apiClient = vi.fn().mockResolvedValue(movieLibraryPayload);
    const onOpen = vi.fn();

    render(<MovieLibrary apiClient={apiClient} onOpen={onOpen} />);

    expect(await screen.findByRole("heading", { name: /movies/i })).toBeInTheDocument();
    expect(screen.getByText("Heat")).toBeInTheDocument();
    expect(screen.getByText("9/10")).toBeInTheDocument();
    expect(screen.getByText("Private note")).toBeInTheDocument();
    expect(screen.getByText("Linked source")).toBeInTheDocument();
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
    expect(screen.getByText("Severance")).toBeInTheDocument();
    expect(screen.getByText("2/9 watched")).toBeInTheDocument();
    expect(screen.getByText("10/10")).toBeInTheDocument();

    fireEvent.change(screen.getByLabelText(/show status/i), { target: { value: "in_progress" } });

    await waitFor(() => {
      expect(apiClient).toHaveBeenCalledWith(expect.stringContaining("status=in_progress"));
    });

    fireEvent.click(screen.getByRole("button", { name: /open severance/i }));
    expect(onOpen).toHaveBeenCalledWith(showLibraryPayload.items[0]);
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

    expect(await screen.findByText("Canonical search")).toBeInTheDocument();
    expect(screen.getByText("Heat")).toBeInTheDocument();
    expect(screen.getByText("Severance")).toBeInTheDocument();
    expect(screen.getByText("Good News About Hell")).toBeInTheDocument();
    expect(screen.queryByText(/provider/i)).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: /open good news about hell/i }));
    expect(onOpen).toHaveBeenCalledWith(expect.objectContaining({ episodeId: 101 }));
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
    expect(screen.getByText("Player")).toBeInTheDocument();
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
