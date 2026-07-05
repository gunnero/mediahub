// @vitest-environment jsdom
import "@testing-library/jest-dom/vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { DetailModal, PlayerSection } from "./App.jsx";

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
    expect(screen.getByText("Private note")).toBeInTheDocument();
    expect(screen.getByText("9/10")).toBeInTheDocument();
    expect(screen.getByText("Linked to 1 provider item")).toBeInTheDocument();
    expect(screen.getByText(/manual/i)).toBeInTheDocument();
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

    fireEvent.click(screen.getByRole("button", { name: /mark watched/i }));
    expect(unwatchedProps.onMarkWatched).toHaveBeenCalledWith(unwatchedDetail);

    unwatchedProps.unmount();

    const watchedProps = renderDetail();
    fireEvent.click(screen.getByRole("button", { name: /mark unwatched/i }));
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
  ],
  linkedItems: [],
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
    fireEvent.click(screen.getByLabelText(/i own or am allowed/i));
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

    expect(await screen.findByText(/progress is saved only to this source until linked/i)).toBeInTheDocument();
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
