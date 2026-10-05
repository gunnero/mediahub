// @vitest-environment jsdom
import "@testing-library/jest-dom/vitest";
import { act, cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { EpisodeNavigation } from "./EpisodeNavigation.jsx";
import { SessionExpiredError } from "../lib/api.js";

afterEach(cleanup);
const catalog = { item: { title: "A series", seasons: [
  { seasonNumber: 1, episodes: [{ id: 11, episodeNumber: 1, code: "S01E01", title: "Beginning" }, { id: 12, episodeNumber: 2, code: "S01E02", title: "Finale" }] },
  { seasonNumber: 2, episodes: [{ id: 21, episodeNumber: 1, code: "S02E01", title: "Return" }] },
] } };
const episode = { kind: "episode", id: 12, showId: 1, showTitle: "A series" };

describe("Episode page navigation", () => {
  it("moves in episode order across season boundaries, selects episodes, and returns to the current season", async () => {
    const onOpen = vi.fn(); const apiClient = vi.fn().mockResolvedValue(catalog);
    const { rerender } = render(<EpisodeNavigation detail={episode} apiClient={apiClient} onOpen={onOpen} />);
    expect(await screen.findByRole("combobox", { name: "Choose episode" })).toHaveValue("12");
    fireEvent.click(screen.getByRole("button", { name: "Previous episode" }));
    expect(onOpen).toHaveBeenLastCalledWith(expect.objectContaining({ kind: "episode", episodeId: 11, showId: 1 }));
    fireEvent.click(screen.getByRole("button", { name: "Next episode" }));
    expect(onOpen).toHaveBeenLastCalledWith(expect.objectContaining({ episodeId: 21, seasonNumber: 2 }));
    rerender(<EpisodeNavigation detail={{ ...episode, id: 21 }} apiClient={apiClient} onOpen={onOpen} />);
    expect(screen.getByRole("button", { name: "Next episode" })).toBeDisabled();
    fireEvent.click(screen.getByRole("button", { name: /Back to show/ }));
    expect(onOpen).toHaveBeenLastCalledWith(expect.objectContaining({ kind: "show", showId: 1, initialTab: "episodes", initialSeason: 2 }));
    fireEvent.change(screen.getByLabelText("Choose episode"), { target: { value: "11" } });
    expect(onOpen).toHaveBeenLastCalledWith(expect.objectContaining({ episodeId: 11 }));
    rerender(<EpisodeNavigation detail={{ ...episode, id: 11 }} apiClient={apiClient} onOpen={onOpen} />);
    expect(screen.getByRole("button", { name: "Previous episode" })).toBeDisabled();
    expect(apiClient).toHaveBeenCalledTimes(1);
  });

  it("ignores a late catalog for a different show and aborts on unmount", async () => {
    let resolveOld; let oldSignal; let newSignal;
    const apiClient = vi.fn((path, { signal }) => {
      if (path.endsWith("/1")) { oldSignal = signal; return new Promise(resolve => { resolveOld = resolve; }); }
      newSignal = signal; return Promise.resolve({ item: { title: "Other show", seasons: [] } });
    });
    const { rerender, unmount } = render(<EpisodeNavigation detail={episode} apiClient={apiClient} />);
    rerender(<EpisodeNavigation detail={{ ...episode, showId: 2 }} apiClient={apiClient} />);
    expect(oldSignal.aborted).toBe(true);
    await screen.findByText("No other episodes available");
    await act(async () => resolveOld(catalog));
    expect(screen.queryByLabelText("Choose episode")).not.toBeInTheDocument();
    unmount(); expect(newSignal.aborted).toBe(true);
  });

  it("keeps Back to show available on failure and retries the catalog", async () => {
    const apiClient = vi.fn().mockRejectedValueOnce(new Error("Offline")).mockResolvedValue(catalog);
    render(<EpisodeNavigation detail={episode} apiClient={apiClient} />);
    fireEvent.click(await screen.findByRole("button", { name: "Retry" }));
    expect(screen.getByRole("button", { name: /Back to show/ })).toBeEnabled();
    expect(await screen.findByLabelText("Choose episode")).toHaveValue("12");
  });

  it("returns to the episode's season while the catalog is still loading", () => {
    const onOpen = vi.fn();
    render(<EpisodeNavigation detail={{ ...episode, subtitle: "S2 E1" }} apiClient={() => new Promise(() => {})} onOpen={onOpen} />);
    fireEvent.click(screen.getByRole("button", { name: /Back to show/ }));
    expect(onOpen).toHaveBeenCalledWith(expect.objectContaining({ initialTab: "episodes", initialSeason: 2 }));
  });

  it("handles an expired session without treating it as an empty catalog", async () => {
    const onSessionExpired = vi.fn();
    render(<EpisodeNavigation detail={episode} apiClient={vi.fn().mockRejectedValue(new SessionExpiredError())} onSessionExpired={onSessionExpired} />);
    await waitFor(() => expect(onSessionExpired).toHaveBeenCalledOnce());
    expect(screen.queryByText("No other episodes available")).not.toBeInTheDocument();
  });
});
