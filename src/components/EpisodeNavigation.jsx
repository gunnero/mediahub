import { useEffect, useState } from "react";
import { ArrowLeft, CaretLeft, CaretRight } from "@phosphor-icons/react";
import { SessionExpiredError } from "../lib/api.js";

// Load the catalog through the same user-scoped endpoint as the show page.
// A keyed result and abort guard prevent another show's controls flashing during navigation.
export function EpisodeNavigation({ detail, apiClient, onOpen, onSessionExpired }) {
  const [catalog, setCatalog] = useState(null);
  const [retry, setRetry] = useState(0);
  const showId = detail?.kind === "episode" ? detail.showId : null;
  useEffect(() => {
    if (!showId) return undefined;
    const controller = new AbortController();
    setCatalog(null);
    apiClient(`/api/v1/library/shows/${showId}`, { signal: controller.signal }).then(payload => {
      if (!controller.signal.aborted) setCatalog({ showId, show: payload.item || payload });
    }).catch(error => {
      if (controller.signal.aborted) return;
      if (error instanceof SessionExpiredError) onSessionExpired?.();
      else setCatalog({ showId, error: true });
    });
    return () => controller.abort();
  }, [apiClient, showId, retry]);

  if (!showId) return null;
  const current = String(catalog?.showId) === String(showId) ? catalog : null;
  const episodes = (current?.show?.seasons || []).flatMap(season => (season.episodes || []).map(episode => ({ ...episode, seasonNumber: season.seasonNumber })))
    .sort((a, b) => a.seasonNumber - b.seasonNumber || a.episodeNumber - b.episodeNumber);
  const index = episodes.findIndex(episode => String(episode.episodeId || episode.id) === String(detail.episodeId || detail.id));
  const selected = episodes[index];
  const subtitleSeason = detail.subtitle?.match(/^S(\d+)\s*E\d+/)?.[1];
  const seasonNumber = selected?.seasonNumber ?? detail.seasonNumber ?? (subtitleSeason === undefined ? undefined : Number(subtitleSeason));
  function open(episode) {
    if (episode) onOpen?.({ ...episode, kind: "episode", episodeId: episode.episodeId || episode.id, showId });
  }
  return <nav className="episode-navigation" aria-label="Episode navigation">
    <button className="episode-show-link" type="button" onClick={() => onOpen?.({ kind: "show", showId, id: showId, title: detail.showTitle || current?.show?.title || "Show", initialTab: "episodes", initialSeason: seasonNumber })}>
      <ArrowLeft size={18} /><span><small>Back to show</small><strong>{detail.showTitle || current?.show?.title || "All episodes"}</strong></span>
    </button>
    {current?.error ? <div className="episode-navigation-error" role="status">Episode navigation could not load. <button className="text-action" type="button" onClick={() => setRetry(value => value + 1)}>Retry</button></div> : !current ? <span role="status">Loading episodes…</span> : episodes.length && index >= 0 ? <div className="episode-switcher">
      <button className="secondary-action" type="button" disabled={index <= 0} onClick={() => open(episodes[index - 1])} aria-label="Previous episode"><CaretLeft size={18} /><span>Previous</span></button>
      <label><span className="episode-picker-label">Choose episode</span><select aria-label="Choose episode" value={selected.episodeId || selected.id} onChange={event => open(episodes.find(episode => String(episode.episodeId || episode.id) === event.target.value))}>
        {(current.show.seasons || []).map(season => <optgroup key={season.seasonNumber} label={season.seasonNumber === 0 ? "Specials" : `Season ${season.seasonNumber}`}>
          {(season.episodes || []).map(episode => <option key={episode.id} value={episode.episodeId || episode.id}>{episode.code} · {episode.title}</option>)}
        </optgroup>)}
      </select></label>
      <button className="secondary-action" type="button" disabled={index === episodes.length - 1} onClick={() => open(episodes[index + 1])} aria-label="Next episode"><span>Next</span><CaretRight size={18} /></button>
    </div> : <span className="muted">No other episodes available</span>}
  </nav>;
}
