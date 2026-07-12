import { useEffect, useMemo, useRef, useState } from "react";
import {
  ArrowRight,
  CalendarDots,
  CaretLeft,
  CaretRight,
  CheckCircle,
  Clock,
  FilmSlate,
  ListBullets,
  MagnifyingGlass,
  Play,
  Sparkle,
  TelevisionSimple,
  UsersThree,
} from "@phosphor-icons/react";
import { SessionExpiredError } from "../lib/api.js";

const generatedPosterPattern = /\/assets\/generated\/movie-poster-\d+\.png(?:[?#].*)?$/;
const meaningfulDiaryPrefixes = ["movie.watched", "episode.watched", "rating.", "note."];

function usableArtwork(value) {
  if (typeof value !== "string" || value.trim() === "") return "";
  const path = value.trim();
  return generatedPosterPattern.test(path) ? "" : path;
}

function initials(title) {
  return String(title || "")
    .replace(/[^a-z0-9\s]/gi, " ")
    .trim()
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((word) => word[0])
    .join("")
    .toUpperCase() || "MH";
}

function HomeArtwork({ item, backdrop = false, eager = false }) {
  const source = backdrop
    ? usableArtwork(item?.backdrop) || usableArtwork(item?.poster)
    : usableArtwork(item?.poster) || usableArtwork(item?.backdrop);

  if (source) {
    return <img alt="" loading={eager ? "eager" : "lazy"} src={source} />;
  }

  return <span className="neutral-poster" role="img" aria-label={`No poster for ${item?.title || "title"}`}><span>{initials(item?.title)}</span></span>;
}

function isoDate(date) {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  return `${year}-${month}-${day}`;
}

function addDays(date, amount) {
  const next = new Date(date);
  next.setDate(next.getDate() + amount);
  return next;
}

function formatWelcomeDate(date = new Date()) {
  return new Intl.DateTimeFormat("en-US", {
    weekday: "long",
    month: "long",
    day: "numeric",
  }).format(date);
}

function formatEventTime(value) {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return "";
  return new Intl.DateTimeFormat("en-US", { month: "short", day: "numeric", hour: "numeric", minute: "2-digit" }).format(date);
}

function releaseLabel(value) {
  const date = new Date(`${value}T12:00:00`);
  if (Number.isNaN(date.getTime())) return { relative: "Upcoming", day: "–", weekday: "" };
  const today = isoDate(new Date());
  const tomorrow = isoDate(addDays(new Date(), 1));
  return {
    relative: value === today ? "Today" : value === tomorrow ? "Tomorrow" : new Intl.DateTimeFormat("en-US", { weekday: "long" }).format(date),
    day: new Intl.DateTimeFormat("en-US", { day: "numeric" }).format(date),
    month: new Intl.DateTimeFormat("en-US", { month: "short" }).format(date),
  };
}

function handleLoadError(error, onSessionExpired, fallback) {
  if (error instanceof SessionExpiredError) {
    onSessionExpired?.();
    return "";
  }
  return error?.message || fallback;
}

function isMeaningfulDiaryEvent(event) {
  if (event?.eventType) return meaningfulDiaryPrefixes.some((prefix) => event.eventType.startsWith(prefix));
  return /^(watched|finished|rated|added private note|updated private note)/i.test(event?.title || "");
}

function SectionHeading({ eyebrow, title, description, action, compact = false }) {
  return (
    <header className={`home-section-heading${compact ? " compact" : ""}`}>
      <div>
        {eyebrow ? <span className="eyebrow">{eyebrow}</span> : null}
        <h2>{title}</h2>
        {description ? <p>{description}</p> : null}
      </div>
      {action}
    </header>
  );
}

function HomeEmptyState({ icon: Icon, title, body, actionLabel, onAction }) {
  return (
    <div className="home-empty-state">
      <Icon aria-hidden="true" size={24} />
      <div><h3>{title}</h3><p>{body}</p></div>
      {actionLabel ? <button className="text-action" onClick={onAction} type="button">{actionLabel}<ArrowRight size={16} /></button> : null}
    </div>
  );
}

function LazyHomeSection({ children, label, minHeight = 260 }) {
  const target = useRef(null);
  const [ready, setReady] = useState(() => typeof IntersectionObserver === "undefined");

  useEffect(() => {
    if (ready || typeof IntersectionObserver === "undefined" || !target.current) return undefined;
    const observer = new IntersectionObserver(([entry]) => {
      if (entry.isIntersecting) {
        setReady(true);
        observer.disconnect();
      }
    }, { rootMargin: "360px 0px" });
    observer.observe(target.current);
    return () => observer.disconnect();
  }, [ready]);

  return (
    <div className="lazy-home-section" ref={target} style={{ minHeight: ready ? undefined : minHeight }}>
      {ready ? children : <div aria-label={`Loading ${label}`} className="home-section-skeleton" role="status"><span /><span /><span /></div>}
    </div>
  );
}

function HomeWelcome({ profile }) {
  const displayName = profile?.displayName || profile?.name || profile?.username || "there";
  const now = new Date();
  return (
    <header className="home-welcome">
      <time dateTime={isoDate(now)}>{formatWelcomeDate(now)}</time>
      <h1>Welcome back, {displayName}</h1>
    </header>
  );
}

function ContinueWatching({ apiClient, onItemsLoaded, onNavigate, onOpen, onRefreshDashboard, onSessionExpired, playbackEnabled }) {
  const railRef = useRef(null);
  const [version, setVersion] = useState(0);
  const [state, setState] = useState({ loading: true, error: "", items: [], finishingId: null });

  useEffect(() => {
    let cancelled = false;

    async function load() {
      setState((current) => ({ ...current, loading: true, error: "" }));
      try {
        const payload = await apiClient("/api/v1/library/continue-watching?limit=3&candidate_limit=30");
        const items = payload.items || [];
        if (!cancelled) {
          setState({ loading: false, error: "", items, finishingId: null });
          onItemsLoaded?.(items);
        }
      } catch (error) {
        if (!cancelled) setState({ loading: false, error: handleLoadError(error, onSessionExpired, "Continue Watching could not be loaded."), items: [], finishingId: null });
      }
    }

    load();
    return () => { cancelled = true; };
  }, [apiClient, onItemsLoaded, onSessionExpired, version]);

  async function finish(item) {
    if (!item?.episodeId || state.finishingId) return;
    setState((current) => ({ ...current, finishingId: item.episodeId, error: "" }));
    try {
      await apiClient(`/api/v1/library/episodes/${item.episodeId}/watch`, { method: "POST" });
      await onRefreshDashboard?.();
      setVersion((value) => value + 1);
    } catch (error) {
      setState((current) => ({ ...current, finishingId: null, error: handleLoadError(error, onSessionExpired, "Could not mark this episode finished.") }));
    }
  }

  function move(direction) {
    railRef.current?.scrollBy({ left: direction * Math.max(320, railRef.current.clientWidth * 0.78), behavior: "smooth" });
  }

  function handleRailKeyDown(event) {
    if (event.key === "ArrowLeft" || event.key === "ArrowRight") {
      event.preventDefault();
      move(event.key === "ArrowLeft" ? -1 : 1);
    }
  }

  return (
    <section className="home-section home-continue-section" aria-label="Continue Watching">
      <SectionHeading
        compact
        eyebrow="Pick up where you left off"
        title="Continue Watching"
        action={state.items.length > 1 ? <div className="home-carousel-controls"><button aria-label="Previous continue item" onClick={() => move(-1)} type="button"><CaretLeft /></button><button aria-label="Next continue item" onClick={() => move(1)} type="button"><CaretRight /></button></div> : null}
      />
      {state.loading ? <div aria-label="Loading Continue Watching" className="home-continue-skeleton" role="status"><span /><span /></div> : null}
      {!state.loading && state.items.length ? <div className={`home-continue-rail${state.items.length === 1 ? " single" : ""}`} onKeyDown={handleRailKeyDown} ref={railRef} role="region" tabIndex="0" aria-label="Continue Watching items">{state.items.map((item) => <article className="home-continue-card" key={item.id}>
        <div className="home-continue-art">
          <HomeArtwork backdrop eager item={item} />
          <span className="home-continue-poster"><HomeArtwork eager item={item} /></span>
        </div>
        <div className="home-continue-copy">
          <span className="eyebrow">Continue</span>
          <h3>{item.showTitle}</h3>
          <p><strong>{item.code}</strong><span>{item.title}</span></p>
          <div className="home-progress" role="progressbar" aria-label={`${item.showTitle} progress`} aria-valuemin="0" aria-valuemax="100" aria-valuenow={Math.min(100, item.progress || 0)}><span style={{ width: `${Math.min(100, item.progress || 0)}%` }} /></div>
          <small>{item.runtime ? `${item.runtime} min episode` : "Next episode ready"}</small>
          <div className="home-continue-actions">
            <button className="primary-action" onClick={() => onOpen(item)} type="button">{playbackEnabled ? <Play size={18} weight="fill" /> : null}{playbackEnabled ? "Resume" : "View episode"}</button>
            <button className="secondary-action" disabled={state.finishingId === item.episodeId} onClick={() => finish(item)} type="button"><CheckCircle size={18} />{state.finishingId === item.episodeId ? "Saving..." : "Mark finished"}</button>
          </div>
        </div>
      </article>)}</div> : null}
      {!state.loading && !state.items.length ? <HomeEmptyState icon={Play} title="Nothing waiting for you" body="Start a show and your next unwatched episode will appear here." actionLabel="Browse your shows" onAction={() => onNavigate("shows")} /> : null}
      {state.error ? <div className="home-inline-error">{state.error}</div> : null}
    </section>
  );
}

function TonightSection({ continueItems, movies, upcoming, onNavigate, onOpen }) {
  const shortMovie = movies.find((movie) => movie.runtime > 0 && movie.runtime <= 120);
  const continuation = continueItems[0];
  const release = upcoming[0];
  const choice = shortMovie
    ? { ...shortMovie, eyebrow: "From your watchlist", heading: shortMovie.title, meta: `${shortMovie.runtime} min · Under two hours`, reason: "Because it is already in your watchlist and fits a shorter evening.", action: "View movie" }
    : continuation
      ? { ...continuation, eyebrow: "Your next episode", heading: `Continue ${continuation.showTitle}`, meta: `${continuation.code}${continuation.runtime ? ` · ${continuation.runtime} min` : ""}`, reason: "Because this is the next unfinished episode in your library.", action: "Resume episode" }
      : release
        ? { ...release, eyebrow: "Coming up", heading: release.title, meta: release.subtitle || "From your release calendar", reason: "Because it belongs to a followed show or a movie on your watchlist.", action: "View details" }
        : null;

  return <section className="home-section tonight-section"><SectionHeading compact eyebrow="For this evening" title="Tonight" />{choice ? <article className="tonight-feature"><div className="tonight-feature-copy"><span className="eyebrow">{choice.eyebrow}</span><h3>{choice.heading}</h3><p className="tonight-meta">{choice.meta}</p><p>{choice.reason}</p><button className="primary-action" onClick={() => onOpen(choice)} type="button">{choice.action}<ArrowRight size={17} /></button></div><button aria-label={`Open ${choice.heading}`} className="tonight-feature-art" onClick={() => onOpen(choice)} type="button"><HomeArtwork backdrop item={choice} /></button></article> : <HomeEmptyState icon={Sparkle} title="Tonight is open" body="Add a movie to your watchlist or start a show to make this space useful." actionLabel="Discover something" onAction={() => onNavigate("discover")} />}</section>;
}

function HomePosterRow({ items, onOpen }) {
  return <div className="home-poster-row">{items.map((item) => <button aria-label={`Open ${item.title}`} className="home-poster-card" key={`${item.kind}-${item.id}`} onClick={() => onOpen(item)} type="button"><span><HomeArtwork item={item} /></span><strong>{item.title}</strong><small>{item.meta || item.subtitle || item.year || "In your library"}</small></button>)}</div>;
}

function PosterRowsSkeleton() {
  return <div aria-label="Loading recently added titles" className="home-poster-skeleton" role="status"><div>{Array.from({ length: 6 }, (_, index) => <span key={index} />)}</div><div>{Array.from({ length: 6 }, (_, index) => <span key={index} />)}</div></div>;
}

function RecentlyAdded({ apiClient, onNavigate, onOpen, onSessionExpired }) {
  const [state, setState] = useState({ loading: true, error: "", movies: [], shows: [] });

  useEffect(() => {
    let cancelled = false;
    Promise.all([
      apiClient("/api/v1/library/movies?sort=newest_added&per_page=8"),
      apiClient("/api/v1/library/shows?sort=newest_added&per_page=8"),
    ]).then(([movies, shows]) => {
      if (!cancelled) setState({ loading: false, error: "", movies: movies.items || [], shows: shows.items || [] });
    }).catch((error) => {
      if (!cancelled) setState({ loading: false, error: handleLoadError(error, onSessionExpired, "Recently added titles could not be loaded."), movies: [], shows: [] });
    });
    return () => { cancelled = true; };
  }, [apiClient, onSessionExpired]);

  return <section className="home-section recently-added-section"><SectionHeading eyebrow="Your library" title="Recently Added" description="The newest movies and shows in your entertainment memory." />{state.error ? <div className="home-inline-error">{state.error}</div> : null}{state.loading ? <PosterRowsSkeleton /> : <div className="recently-added-rows"><div><div className="home-row-heading"><h3>Movies</h3><button className="text-action" onClick={() => onNavigate("movies")} type="button">View movies<ArrowRight /></button></div>{state.movies.length ? <HomePosterRow items={state.movies} onOpen={onOpen} /> : <HomeEmptyState icon={FilmSlate} title="No movies yet" body="Movies you add will collect here." actionLabel="Discover movies" onAction={() => onNavigate("discover")} />}</div><div><div className="home-row-heading"><h3>Shows</h3><button className="text-action" onClick={() => onNavigate("shows")} type="button">View shows<ArrowRight /></button></div>{state.shows.length ? <HomePosterRow items={state.shows} onOpen={onOpen} /> : <HomeEmptyState icon={TelevisionSimple} title="No shows yet" body="Shows you add will collect here." actionLabel="Discover shows" onAction={() => onNavigate("discover")} />}</div></div>}</section>;
}

function UpcomingSection({ apiClient, onNavigate, onOpen, onSessionExpired, onLoaded }) {
  const [state, setState] = useState({ loading: true, error: "", items: [] });

  useEffect(() => {
    let cancelled = false;
    const today = new Date();
    const from = isoDate(today);
    const to = isoDate(addDays(today, 7));
    apiClient(`/api/v1/calendar?date_from=${from}&date_to=${to}&type=all`).then((payload) => {
      if (cancelled) return;
      const items = payload.items || [];
      setState({ loading: false, error: "", items });
      onLoaded?.(items);
    }).catch((error) => {
      if (!cancelled) setState({ loading: false, error: handleLoadError(error, onSessionExpired, "Upcoming releases could not be loaded."), items: [] });
    });
    return () => { cancelled = true; };
  }, [apiClient, onLoaded, onSessionExpired]);

  return <section className="home-section upcoming-home-section"><SectionHeading eyebrow="Release calendar" title="Upcoming" description="The next releases from your followed shows and movie watchlist." action={<button className="text-action" onClick={() => onNavigate("calendar")} type="button">Open calendar<ArrowRight /></button>} />{state.error ? <div className="home-inline-error">{state.error}</div> : null}{state.loading ? <div aria-label="Loading upcoming releases" className="upcoming-timeline-skeleton" role="status">{Array.from({ length: 4 }, (_, index) => <span key={index} />)}</div> : state.items.length ? <div className="upcoming-timeline">{state.items.slice(0, 8).map((item) => { const when = releaseLabel(item.date); return <button aria-label={`Open ${item.title}, ${when.relative}`} key={item.id} onClick={() => onOpen(item)} type="button"><span className="upcoming-date"><small>{when.relative}</small><strong>{when.day}</strong><em>{when.month}</em></span><span className="upcoming-art"><HomeArtwork item={item} /></span><span className="upcoming-copy"><strong>{item.title}</strong><small>{item.subtitle || (item.kind === "movie" ? "Movie release" : "New episode")}</small></span><ArrowRight /></button>; })}</div> : <HomeEmptyState icon={CalendarDots} title="Nothing scheduled this week" body="Follow a show or add an upcoming movie to your watchlist and releases will appear here." actionLabel="Open calendar" onAction={() => onNavigate("calendar")} />}</section>;
}

function DiaryPreview({ timeline, onNavigate }) {
  const events = (timeline?.recent || []).filter(isMeaningfulDiaryEvent).slice(0, 5);
  return <section className="home-section diary-preview-section"><SectionHeading eyebrow="Your memories" title="Entertainment Diary" description="Recent highlights from your private entertainment history." action={<button className="text-action" onClick={() => onNavigate("history")} type="button">View Full Diary<ArrowRight /></button>} />{events.length ? <div className="home-diary-list">{events.map((event) => <article key={event.id}><i /><div><strong>{event.title}</strong><small>{event.subtitle || "Part of your entertainment memory"}</small></div><time dateTime={event.occurredAt}>{formatEventTime(event.occurredAt)}</time></article>)}</div> : <HomeEmptyState icon={Clock} title="Your diary is quiet" body="Watch, rate, or note something and the meaningful moment will appear here." />}</section>;
}

function FriendsHomeSection({ apiClient, onNavigate, onSessionExpired }) {
  const [state, setState] = useState({ loading: true, error: "", friends: [], favorites: [], lists: [] });

  useEffect(() => {
    let cancelled = false;
    async function load() {
      try {
        const payload = await apiClient("/api/v1/friends");
        const friends = payload.friends || [];
        if (!friends.length) {
          if (!cancelled) setState({ loading: false, error: "", friends: [], favorites: [], lists: [] });
          return;
        }
        const publicProfiles = await Promise.all(friends.slice(0, 3).map(async (friend) => {
          try {
            const profile = await apiClient(`/api/v1/profiles/${encodeURIComponent(friend.profile.slug)}`);
            return { friend: friend.profile, content: profile.content || {} };
          } catch (error) {
            if (error instanceof SessionExpiredError) throw error;
            return { friend: friend.profile, content: {} };
          }
        }));
        const favorites = publicProfiles.flatMap(({ friend, content }) => [
          ...(content.favoriteMovies || []).map((item) => ({ ...item, kind: "movie", sharedBy: friend.displayName })),
          ...(content.favoriteShows || []).map((item) => ({ ...item, kind: "show", sharedBy: friend.displayName })),
        ]).slice(0, 3);
        const lists = publicProfiles.flatMap(({ friend, content }) => (content.publicLists || []).map((item) => ({ ...item, sharedBy: friend.displayName }))).slice(0, 3);
        if (!cancelled) setState({ loading: false, error: "", friends, favorites, lists });
      } catch (error) {
        if (!cancelled) setState({ loading: false, error: handleLoadError(error, onSessionExpired, "Friends could not be loaded."), friends: [], favorites: [], lists: [] });
      }
    }
    load();
    return () => { cancelled = true; };
  }, [apiClient, onSessionExpired]);

  if (!state.loading && !state.friends.length) return null;
  return <section className="home-section friends-home-section"><SectionHeading eyebrow="People you choose" title="From Friends" description="Only favorites and lists your friends explicitly made visible." action={<button className="text-action" onClick={() => onNavigate("friends")} type="button">Open friends<ArrowRight /></button>} />{state.error ? <div className="home-inline-error">{state.error}</div> : null}{state.loading ? <div className="home-row-loading">Checking shared favorites...</div> : state.favorites.length || state.lists.length ? <div className="friends-shared-grid">{state.favorites.map((item) => <article key={`favorite-${item.sharedBy}-${item.id}`}><span className="friend-share-art"><HomeArtwork item={item} /></span><div><small>Public favorite · {item.sharedBy}</small><strong>{item.title}</strong><button className="text-action" onClick={() => onNavigate("friends")} type="button">View friend profile<ArrowRight /></button></div></article>)}{state.lists.map((list) => <article key={`list-${list.sharedBy}-${list.id}`}><span className="friend-share-icon"><ListBullets /></span><div><small>Public list · {list.sharedBy}</small><strong>{list.name || list.title}</strong><button className="text-action" onClick={() => onNavigate("friends")} type="button">View list<ArrowRight /></button></div></article>)}</div> : <HomeEmptyState icon={UsersThree} title="Nothing shared yet" body="Your friends have not published favorites or lists." actionLabel="Open Friends" onAction={() => onNavigate("friends")} />}</section>;
}

function PinnedListsSection({ apiClient, onNavigate, onSessionExpired }) {
  const [state, setState] = useState({ loading: true, error: "", lists: [], profile: null });

  useEffect(() => {
    let cancelled = false;
    Promise.all([apiClient("/api/v1/lists"), apiClient("/api/v1/profile")]).then(([lists, profile]) => {
      if (!cancelled) setState({ loading: false, error: "", lists: lists.lists || [], profile: profile.profile || {} });
    }).catch((error) => {
      if (!cancelled) setState({ loading: false, error: handleLoadError(error, onSessionExpired, "Lists could not be loaded."), lists: [], profile: null });
    });
    return () => { cancelled = true; };
  }, [apiClient, onSessionExpired]);

  const featuredIds = state.profile?.featuredListIds || [];
  const ordered = [...state.lists].sort((left, right) => {
    const featuredDifference = Number(featuredIds.includes(right.id)) - Number(featuredIds.includes(left.id));
    if (featuredDifference) return featuredDifference;
    return String(right.updatedAt || "").localeCompare(String(left.updatedAt || ""));
  }).slice(0, 5);

  return <section className="home-section pinned-lists-section"><SectionHeading eyebrow="Keep close" title="Pinned Lists" description="Favorite lists first, followed by the collections you updated most recently." action={<button className="text-action" onClick={() => onNavigate("lists")} type="button">View all lists<ArrowRight /></button>} />{state.error ? <div className="home-inline-error">{state.error}</div> : null}{state.loading ? <div className="home-row-loading">Gathering your lists...</div> : ordered.length ? <div className="pinned-list-row">{ordered.map((list) => <button key={list.id} onClick={() => onNavigate("lists")} type="button"><ListBullets size={22} /><span><small>{featuredIds.includes(list.id) ? "Pinned" : "Recently updated"}</small><strong>{list.name}</strong><em>{list.itemsCount || 0} titles</em></span><ArrowRight /></button>)}</div> : <HomeEmptyState icon={ListBullets} title="No lists pinned yet" body="Create a list or feature one from your profile to keep it close." actionLabel="Open Lists" onAction={() => onNavigate("lists")} />}</section>;
}

function QuickActions({ onNavigate }) {
  const actions = [
    ["search", "Search", MagnifyingGlass],
    ["discover", "Discover", Sparkle],
    ["movies", "Movies", FilmSlate],
    ["shows", "Shows", TelevisionSimple],
    ["calendar", "Calendar", CalendarDots],
    ["lists", "Lists", ListBullets],
  ];
  return <section className="home-section quick-actions-section"><SectionHeading eyebrow="Go directly" title="Quick Actions" description="Move to the part of your entertainment home you need." /><div className="quick-action-grid">{actions.map(([id, label, Icon]) => <button key={id} onClick={() => onNavigate(id)} type="button"><Icon size={22} /><span>{label}</span><ArrowRight size={17} /></button>)}</div></section>;
}

export function HomeExperience({ apiClient, dashboard, onNavigate, onOpen, onRefreshDashboard, onSessionExpired }) {
  const [upcoming, setUpcoming] = useState([]);
  const [continueItems, setContinueItems] = useState([]);
  const tonightMovies = useMemo(() => dashboard.moviesToCheckOut || [], [dashboard.moviesToCheckOut]);

  return (
    <div className="home-experience">
      <HomeWelcome profile={dashboard.profile} />
      <ContinueWatching
        apiClient={apiClient}
        onItemsLoaded={setContinueItems}
        onNavigate={onNavigate}
        onOpen={onOpen}
        onRefreshDashboard={onRefreshDashboard}
        onSessionExpired={onSessionExpired}
        playbackEnabled={Boolean(dashboard.features?.webPlayerEnabled)}
      />
      <TonightSection continueItems={continueItems} movies={tonightMovies} onNavigate={onNavigate} onOpen={onOpen} upcoming={upcoming} />
      <RecentlyAdded apiClient={apiClient} onNavigate={onNavigate} onOpen={onOpen} onSessionExpired={onSessionExpired} />
      <UpcomingSection apiClient={apiClient} onLoaded={setUpcoming} onNavigate={onNavigate} onOpen={onOpen} onSessionExpired={onSessionExpired} />
      <LazyHomeSection label="Entertainment Diary"><DiaryPreview onNavigate={onNavigate} timeline={dashboard.timeline} /></LazyHomeSection>
      <LazyHomeSection label="Pinned Lists"><PinnedListsSection apiClient={apiClient} onNavigate={onNavigate} onSessionExpired={onSessionExpired} /></LazyHomeSection>
      <LazyHomeSection label="Friends"><FriendsHomeSection apiClient={apiClient} onNavigate={onNavigate} onSessionExpired={onSessionExpired} /></LazyHomeSection>
      <LazyHomeSection label="Quick Actions" minHeight={180}><QuickActions onNavigate={onNavigate} /></LazyHomeSection>
    </div>
  );
}
