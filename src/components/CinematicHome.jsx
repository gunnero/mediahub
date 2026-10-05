import { useEffect, useState } from "react";
import { ArrowRight, FilmSlate, TelevisionSimple } from "@phosphor-icons/react";
import { SessionExpiredError } from "../lib/api.js";

function artwork(item, wide) {
  const sources = wide ? [item?.backdrop, item?.poster] : [item?.poster, item?.backdrop];
  return sources.filter(source => typeof source === "string" && source.trim() && !/\/assets\/generated\/movie-poster-\d+\.png/.test(source));
}

export function CinemaArtwork({ item, wide = false, eager = false }) {
  const [failed, setFailed] = useState([]);
  const source = artwork(item, wide).find(url => !failed.includes(url));
  if (source) return <img src={source} alt="" loading={eager ? "eager" : "lazy"} fetchPriority={eager ? "high" : "auto"} decoding="async" onError={() => setFailed(current => [...current, source])} />;
  const Icon = item?.kind === "movie" ? FilmSlate : TelevisionSimple;
  return <span className="cinema-art-fallback" role="img" aria-label={`Artwork unavailable for ${item?.title || "this title"}`}><Icon size={48} weight="thin" /></span>;
}

function watchProgress(item) {
  const watched = Number(item?.watchedEpisodes);
  const total = Number(item?.airedEpisodes);
  const hasCounts = item?.watchedEpisodes != null && item?.airedEpisodes != null && Number.isFinite(watched) && total > 0;
  const percent = Math.max(0, Math.min(100, Math.round(Number(item?.progress) || 0)));
  return { percent, remaining: hasCounts ? Math.max(0, total - watched) : null, label: hasCounts ? watched >= total ? "All aired episodes watched" : `${watched} of ${total} episodes watched` : item?.meta || (percent ? `${percent}% watched` : "In your library") };
}

function Progress({ item, labelled = true }) {
  const { percent } = watchProgress(item);
  return <div className="cinema-progress"><div role="progressbar" aria-label={`${item.title} watch progress`} aria-valuemin={0} aria-valuemax={100} aria-valuenow={percent}><span style={{ width: `${percent}%` }} /></div>{labelled ? <span aria-hidden="true">{percent}%</span> : null}</div>;
}

function runtime(movie) {
  const minutes = Number(movie.runtime);
  if (!minutes) return movie.meta || "On your watchlist";
  return [minutes >= 60 ? `${Math.floor(minutes / 60)}h` : "", minutes % 60 ? `${minutes % 60}m` : ""].filter(Boolean).join(" ");
}

export function CinematicHome({ apiClient, dashboard, continueItems, onNavigate, onOpen, onSessionExpired }) {
  const [state, setState] = useState({ items: [], loading: true, error: "" });
  const [retry, setRetry] = useState(0);
  useEffect(() => {
    let active = true;
    setState(current => ({ ...current, loading: true, error: "" }));
    apiClient("/api/v1/library/shows?sort=latest_watched&per_page=6").then(payload => {
      if (active) setState({ items: payload.items || [], loading: false, error: "" });
    }).catch(error => {
      if (!active) return;
      if (error instanceof SessionExpiredError) onSessionExpired?.();
      else setState(current => ({ ...current, loading: false, error: "Your shows could not be loaded." }));
    });
    return () => { active = false; };
  }, [apiClient, dashboard.recentShow, onSessionExpired, retry]);

  const next = continueItems[0];
  const recent = dashboard.recentShow;
  const featured = (next && (state.items.find(show => show.showId === next.showId) || { id: next.showId, showId: next.showId, kind: "show", title: next.showTitle, poster: next.poster, backdrop: next.backdrop, progress: next.progress }))
    || state.items.find(show => show.showId === recent?.showId) || recent || state.items[0];
  const progress = watchProgress(featured);
  const shows = state.items.slice(0, 3);
  const movies = (dashboard.moviesToCheckOut || []).slice(0, 4);

  return <div className="cinema-home-primary">
    <section className={`cinema-hero${featured ? "" : " cinema-hero-empty"}`} aria-label="Featured show">
      {featured ? <>
        <div className="cinema-hero-art"><CinemaArtwork item={featured} wide eager /></div>
        <div className="cinema-hero-copy">
          <span className="eyebrow">{progress.remaining === 0 ? "Your entertainment memory" : "Pick up where you left off"}</span>
          <h1>{featured.title}</h1>
          <p className="cinema-hero-meta">{progress.label}</p>
          <Progress item={featured} />
          {progress.remaining > 0 ? <p className="cinema-remaining">{progress.remaining} episode{progress.remaining === 1 ? "" : "s"} remaining</p> : null}
          <div className="cinema-hero-actions">
            <button className="primary-action" onClick={() => onOpen({ ...featured, initialTab: "episodes" })} type="button">Open episodes<ArrowRight size={19} /></button>
            <button className="secondary-action" onClick={() => onOpen({ ...featured, initialTab: "overview" })} type="button">View show</button>
          </div>
        </div>
      </> : <div className="cinema-hero-copy"><span className="eyebrow">Your personal cinema</span><h1>Your next story starts here.</h1><p>Keep the movies you love and the shows you follow in one place.</p><button className="primary-action" onClick={() => onNavigate("discover")} type="button">Discover something<ArrowRight size={19} /></button></div>}
    </section>
    <section className="cinema-shelf cinema-shows" aria-labelledby="cinema-shows-heading">
      <div className="cinema-section-heading"><h2 id="cinema-shows-heading">Your shows</h2><button className="text-action" onClick={() => onNavigate("shows")} type="button">Browse all shows<ArrowRight size={17} /></button></div>
      {state.loading && !shows.length ? <div className="cinema-shows-grid cinema-loading" role="status" aria-label="Loading your shows"><span /><span /><span /></div> : shows.length ? <div className="cinema-shows-grid">{shows.map(show => <button className="cinema-show-card" key={show.showId || show.id} onClick={() => onOpen(show)} type="button"><span className="cinema-show-art"><CinemaArtwork item={show} wide /></span><strong>{show.title}</strong><small>{watchProgress(show).label}</small><Progress item={show} /></button>)}</div> : !state.error ? <div className="cinema-empty"><TelevisionSimple size={26} /><p>Follow a show to make yourself at home.</p><button className="text-action" onClick={() => onNavigate("add-show")} type="button">Find a show<ArrowRight size={17} /></button></div> : null}
      {state.error ? <div className="home-inline-error" role="alert">{state.error} <button className="text-action" onClick={() => setRetry(value => value + 1)} type="button">Try again</button></div> : null}
    </section>
    <section className="cinema-shelf cinema-watchlist" aria-labelledby="cinema-watchlist-heading">
      <div className="cinema-section-heading"><h2 id="cinema-watchlist-heading">From your watchlist</h2><button className="text-action" onClick={() => onNavigate("watchlist")} type="button">Browse your watchlist<ArrowRight size={17} /></button></div>
      {movies.length ? <div className="cinema-movie-grid">{movies.map(movie => <button className="cinema-movie-card" aria-label={`Open ${movie.title}`} key={movie.movieId || movie.id} onClick={() => onOpen(movie)} type="button"><span className="cinema-movie-art"><CinemaArtwork item={movie} /></span><span className="cinema-movie-copy"><strong>{movie.title}</strong><small>{runtime(movie)}</small></span></button>)}</div> : <div className="cinema-empty"><FilmSlate size={26} /><p>A good movie night starts with your watchlist.</p><button className="text-action" onClick={() => onNavigate("add-movie")} type="button">Find a movie<ArrowRight size={17} /></button></div>}
    </section>
  </div>;
}
